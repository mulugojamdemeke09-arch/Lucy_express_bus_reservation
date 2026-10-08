<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — api/finalize_booking.php
 *  ---------------------------------------------------------------------------
 *  Guest checkout transaction engine.
 *
 *  POST /api/finalize_booking.php
 *  Content-Type: application/json
 *  {
 *    "trip_id": 7,
 *    "seats": [12, 13],
 *    "passenger_name": "Abebe Bekele",
 *    "passenger_phone": "0912345678"
 *  }
 *
 *  DOUBLE-BOOKING PROTECTION (race conditions)
 *    1. The whole write is wrapped in one InnoDB transaction.
 *    2. The trip row is read back with SELECT ... FOR UPDATE, locking it.
 *    3. Occupied seats are read with SELECT ... FOR UPDATE, so two concurrent
 *       requests targeting the same trip serialise instead of overlapping.
 *    4. The UNIQUE KEY (trip_id, seat_number) on `bookings` is the hard,
 *       database-level backstop — even if two processes slip through, MySQL
 *       rejects the duplicate row with error 23000 which we catch and report.
 *
 *  Responses
 *    200 { ok:true, confirmation_code, seats, total, trip:{...} }
 *    409 { ok:false, conflicts:[8,9], message }   <- seats just taken
 *    4xx/5xx { ok:false, message }
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/** Uniform JSON failure response (rolls back any open transaction first). */
function json_fail(int $status, string $message, array $extra = []): void
{
    if (isset($GLOBALS['LUCY_TX']) && $GLOBALS['LUCY_TX'] instanceof PDO && $GLOBALS['LUCY_TX']->inTransaction()) {
        $GLOBALS['LUCY_TX']->rollBack();
    }
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* -------------------------------------------------- 1. request + payload -- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_fail(405, 'This endpoint only accepts POST requests.');
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);
if (!is_array($payload)) {
    // fall back to a classic form post so <noscript> clients still work
    $payload = $_POST;
}
if (!is_array($payload) || !$payload) {
    json_fail(400, 'An empty or malformed JSON payload was received.');
}

$tripId = (int) ($payload['trip_id'] ?? 0);
$name   = trim((string) ($payload['passenger_name'] ?? ''));
$phone  = trim((string) ($payload['passenger_phone'] ?? ''));

$seats = array_values(array_unique(array_map('intval', (array) ($payload['seats'] ?? []))));
$seats = array_values(array_filter($seats, static fn (int $s): bool => $s > 0));

/* ------------------------------------------------------ 2. validation ----- */
if ($tripId <= 0) {
    json_fail(422, 'trip_id is required.');
}
if (!$seats) {
    json_fail(422, 'Select at least one seat before confirming.');
}
if (count($seats) > 6) {
    json_fail(422, 'A maximum of 6 seats can be allocated in one guest transaction.');
}
if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
    json_fail(422, 'Passenger full name must be between 3 and 120 characters.');
}

$phoneClean = preg_replace('/[\s\-()]/', '', $phone) ?? '';
if (!preg_match('/^(?:\+251|251|0)?9\d{8}$/', $phoneClean) && !preg_match('/^\+?\d{9,15}$/', $phoneClean)) {
    json_fail(422, 'Passenger mobile phone number is not valid (e.g. 0912345678 or +251912345678).');
}
if (mb_strlen($phoneClean) > 30) {
    json_fail(422, 'Passenger mobile phone number is too long.');
}

$db = lucy_db_safe();
if (!$db) {
    json_fail(503, 'MySQL engine unavailable — start MySQL in the XAMPP control panel.');
}

/* ------------------------------------------- 3. atomic booking transaction */
$GLOBALS['LUCY_TX'] = $db;

try {
    $db->beginTransaction();

    /* 3a. lock the trip row and re-read its authoritative data */
    $lockTrip = $db->prepare(
        'SELECT t.id, t.source, t.destination, t.departure_time, t.arrival_time,
                t.ticket_price, t.travel_date, b.bus_number, b.bus_type, b.total_seats
           FROM trips t
           JOIN buses b ON b.id = t.bus_id
          WHERE t.id = :id
          FOR UPDATE'
    );
    $lockTrip->execute([':id' => $tripId]);
    $trip = $lockTrip->fetch();

    if (!$trip) {
        json_fail(404, 'Trip #' . $tripId . ' no longer exists.');
    }
    if ($trip['travel_date'] < date('Y-m-d')) {
        json_fail(410, 'This trip has already departed and can no longer be booked.');
    }

    /* 3b. seat range check against the assigned vehicle */
    $totalSeats = (int) $trip['total_seats'];
    foreach ($seats as $seat) {
        if ($seat < 1 || $seat > $totalSeats) {
            json_fail(422, 'Seat ' . $seat . ' is outside the 1–' . $totalSeats . ' range of bus ' . $trip['bus_number'] . '.');
        }
    }

    /* 3c. lock every existing booking row of this trip and diff the seats */
    $lockSeats = $db->prepare('SELECT seat_number FROM bookings WHERE trip_id = :id FOR UPDATE');
    $lockSeats->execute([':id' => $tripId]);
    $taken = array_map('intval', array_column($lockSeats->fetchAll(), 'seat_number'));

    $conflicts = array_values(array_intersect($seats, $taken));
    if ($conflicts) {
        json_fail(409, 'Those seats were reserved by another passenger moments ago.', ['conflicts' => $conflicts]);
    }

    /* 3d. one confirmation code per guest transaction, shared by every seat */
    $code = '';
    $codeExists = $db->prepare('SELECT 1 FROM bookings WHERE confirmation_code = :c LIMIT 1');
    for ($attempt = 0; $attempt < 6; $attempt++) {
        $candidate = lucy_generate_code();
        $codeExists->execute([':c' => $candidate]);
        if (!$codeExists->fetchColumn()) {
            $code = $candidate;
            break;
        }
    }
    if ($code === '') {
        json_fail(500, 'Could not mint a unique confirmation code. Please retry.');
    }

    /* 3e. insert one row per seat */
    $insert = $db->prepare(
        'INSERT INTO bookings (trip_id, seat_number, passenger_name, passenger_phone, confirmation_code, booked_at)
         VALUES (:trip_id, :seat_number, :passenger_name, :passenger_phone, :code, NOW())'
    );

    $seatCost = (float) $trip['ticket_price'];
    foreach ($seats as $seat) {
        $insert->execute([
            ':trip_id'         => $tripId,
            ':seat_number'     => $seat,
            ':passenger_name'  => $name,
            ':passenger_phone' => $phoneClean,
            ':code'            => $code,
        ]);
    }

    $db->commit();

    sort($seats);

    echo json_encode([
        'ok'                => true,
        'message'           => 'Ticket allocated successfully.',
        'confirmation_code' => $code,
        'seats'             => $seats,
        'total'             => round($seatCost * count($seats), 2),
        'currency'          => LUCY_CURRENCY,
        'trip'              => [
            'id'             => (int) $trip['id'],
            'source'         => $trip['source'],
            'destination'    => $trip['destination'],
            'travel_date'    => $trip['travel_date'],
            'departure_time' => substr((string) $trip['departure_time'], 0, 5),
            'bus_number'     => $trip['bus_number'],
            'bus_type'       => $trip['bus_type'],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // 23000 = integrity constraint violation -> the unique seat guard fired
    if ($e->getCode() === '23000') {
        json_fail(409, 'One of the selected seats was booked simultaneously by another passenger. Please pick another seat.', [
            'conflicts' => $seats,
        ]);
    }
    json_fail(500, 'Database error while saving the reservation: ' . $e->getMessage());
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    json_fail(500, 'Unexpected error while saving the reservation: ' . $e->getMessage());
}
