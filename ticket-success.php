<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — ticket-success.php
 *  ---------------------------------------------------------------------------
 *  Renders the stylised boarding ticket for a completed guest transaction.
 *  All booking rows sharing one confirmation_code are merged back into the
 *  single ticket, so a 3-seat purchase produces one code and three berths.
 *
 *  GET /ticket-success.php?code=LUCY-7F3A9C
 * ============================================================================
 */

require_once __DIR__ . '/config/db.php';

$db   = lucy_db_safe();
$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$rows = [];

if ($db && $code !== '' && preg_match('/^[A-Z0-9\-]{4,24}$/', $code)) {
    try {
        // NOTE: the collation of confirmation_code is *_ci, so the uppercase
        // comparison below is case-insensitive by design.
        $stmt = $db->prepare(
            'SELECT bk.id, bk.seat_number, bk.passenger_name, bk.passenger_phone,
                    bk.confirmation_code, bk.booked_at,
                    t.id AS trip_id, t.source, t.destination, t.departure_time,
                    t.arrival_time, t.ticket_price, t.travel_date,
                    b.bus_number, b.bus_type, b.total_seats
               FROM bookings bk
               JOIN trips t ON t.id = bk.trip_id
               JOIN buses b ON b.id = t.bus_id
              WHERE bk.confirmation_code = :code
              ORDER BY bk.seat_number ASC'
        );
        $stmt->execute([':code' => $code]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        $GLOBALS['LUCY_DB_ERROR'] = $e->getMessage();
    }
}

lucy_header('Ticket confirmation', 'success');

if (!$rows):
    ?>
    <div class="card mt-3">
        <p class="card__eyebrow">Nothing found</p>
        <h2>No ticket matches that confirmation code</h2>
        <p class="card__muted">
            <?= $code === '' ? 'No confirmation code was supplied in the address.' : 'The code <code>' . h($code) . '</code> is unknown.' ?>
            Tickets live in the local MySQL database — if you have just imported the SQL dump, start a new
            reservation from the search board.
        </p>
        <a class="btn btn--green" href="<?= h(BASE_URL) ?>/index.php">Book another journey</a>
    </div>
    <?php
    lucy_footer();
    exit;
endif;

$first      = $rows[0];
$seatList   = array_map(static fn (array $r): int => (int) $r['seat_number'], $rows);
$seatCount  = count($seatList);
$totalCost  = (float) $first['ticket_price'] * $seatCount;
$bookedAt   = date('D d M Y, H:i', strtotime((string) $first['booked_at']));
?>

<div class="ticket-wrap">
    <div class="row-between no-print" style="margin-bottom:1rem">
        <div>
            <p class="card__eyebrow">Step 4 — Confirmed</p>
            <h2 style="margin:0">Your seat<?= $seatCount > 1 ? 's are' : ' is' ?> locked in.</h2>
        </div>
        <div class="row">
            <button type="button" class="btn btn--ghost" id="print-ticket">🖨 Print ticket</button>
            <a class="btn btn--green" href="<?= h(BASE_URL) ?>/index.php">Book another journey</a>
        </div>
    </div>

    <div class="notice notice--ok no-print">
        Present this ticket (printed or on screen) at the <?= h($first['source']) ?> terminal desk at least
        30 minutes before departure. Payment is collected on boarding.
    </div>

    <article class="ticket">
        <header class="ticket__head">
            <div class="footer-brand">
                <!-- real bus photo mark, matching the site header -->
                <img src="<?= h(BASE_URL . LUCY_LOGO_IMG) ?>" alt="Lucy Express bus logo" width="34" height="34">
                <span>
                    <strong><?= h(LUCY_BRAND_EN) ?> // <span lang="am"><?= h(LUCY_BRAND_AM) ?></span></strong>
                    <p style="margin:0;font-size:0.85rem">Official boarding ticket &middot; issued <?= h($bookedAt) ?></p>
                </span>
            </div>
            <span><?= $seatCount > 1 ? $seatCount . ' seats' : 'Single seat' ?></span>
        </header>

        <div class="ticket__body">
            <dl class="ticket__cell">
                <dt>Passenger</dt>
                <dd><?= h($first['passenger_name']) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Mobile</dt>
                <dd><?= h($first['passenger_phone']) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Route</dt>
                <dd><?= h($first['source']) ?> → <?= h($first['destination']) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Travel date</dt>
                <dd><?= h(lucy_date_label($first['travel_date'])) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Departure / arrival</dt>
                <dd><?= h(lucy_time($first['departure_time'])) ?> → <?= h(lucy_time($first['arrival_time'])) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Journey time</dt>
                <dd><?= h(lucy_duration($first['departure_time'], $first['arrival_time'])) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Coach</dt>
                <dd><?= h($first['bus_number']) ?> &middot; <?= h($first['bus_type']) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Seat number<?= $seatCount > 1 ? 's' : '' ?></dt>
                <dd><?= h(implode(', ', $seatList)) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Fare per seat</dt>
                <dd><?= h(lucy_money($first['ticket_price'])) ?></dd>
            </dl>
            <dl class="ticket__cell">
                <dt>Total payable</dt>
                <dd><?= h(lucy_money($totalCost)) ?></dd>
            </dl>
        </div>

        <div class="ticket__perforation" aria-hidden="true"></div>

        <div class="ticket__foot">
            <div class="code-plate">
                <small>Confirmation code</small>
                <strong><?= h($first['confirmation_code']) ?></strong>
            </div>
            <div style="min-width:190px">
                <div class="barcode" role="img" aria-label="Barcode for <?= h($first['confirmation_code']) ?>"></div>
                <p class="text-muted" style="font-size:0.72rem;margin:0.4rem 0 0;letter-spacing:0.08em">
                    <?= h($first['confirmation_code']) ?> &middot; <?= (int) $seatCount ?> BERTH<?= $seatCount > 1 ? 'S' : '' ?>
                </p>
            </div>
        </div>
    </article>

    <p class="ticket-note no-print">
        <strong lang="am">ጉዞ ደህና!</strong> Have a safe journey — please arrive 30 minutes before departure.
        Long highland routes pause for a <em>buna</em> stop.
    </p>

    <div class="card card--tint mt-3 no-print">
        <p class="card__eyebrow">Need to change something?</p>
        <p class="card__muted" style="margin-bottom:0.75rem">
            Cancellations and trip changes are handled at the terminal desk using your confirmation code.
            Operations staff can also inspect this ticket from the administrative console.
        </p>
        <a class="btn btn--ghost" href="<?= h(BASE_URL) ?>/admin/dashboard.php">Open the Admin Portal</a>
    </div>
</div>

<?php lucy_footer(); ?>
