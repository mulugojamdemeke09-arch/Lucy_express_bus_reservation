<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — api/get_booked_seats.php
 *  ---------------------------------------------------------------------------
 *  Read-only asynchronous engine. Returns the JSON array of occupied seat
 *  numbers for one trip so the seat grid can repaint itself in real time
 *  without a page reload.
 *
 *  GET  /api/get_booked_seats.php?trip_id=7
 *
 *  Response 200
 *  {
 *    "ok": true,
 *    "trip_id": 7,
 *    "total_seats": 32,
 *    "seats_booked": 3,
 *    "seats_available": 29,
 *    "booked": [3, 4, 17],
 *    "updated_at": "2026-10-08 12:31:04"
 *  }
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/** Uniform JSON failure response. */
function json_fail(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_fail(405, 'Only GET requests are accepted on this endpoint.');
}

$tripId = (int) ($_GET['trip_id'] ?? 0);
if ($tripId <= 0) {
    json_fail(422, 'A positive integer trip_id is required.');
}

$db = lucy_db_safe();
if (!$db) {
    json_fail(503, 'MySQL engine unavailable — start MySQL in the XAMPP control panel.');
}

$trip = lucy_find_trip($db, $tripId);
if (!$trip) {
    json_fail(404, 'Trip #' . $tripId . ' does not exist.');
}

$booked = lucy_booked_seats($db, $tripId);
$total  = (int) $trip['total_seats'];

echo json_encode([
    'ok'              => true,
    'trip_id'         => $tripId,
    'route'           => $trip['source'] . ' → ' . $trip['destination'],
    'total_seats'     => $total,
    'seats_booked'    => count($booked),
    'seats_available' => max(0, $total - count($booked)),
    'booked'          => $booked,
    'updated_at'      => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE);
