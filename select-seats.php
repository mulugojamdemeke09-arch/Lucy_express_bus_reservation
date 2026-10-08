<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — select-seats.php
 *  ---------------------------------------------------------------------------
 *  Interactive visual seat selector + instant guest checkout panel.
 *
 *    - CSS Grid floor plan: 2 seats | walking aisle | 2 seats per row.
 *    - Front-row steering-wheel driver block and boarding door anchors.
 *    - Seat states: available (green), selected (amber, pulsing), booked
 *      (grey, native pointer-events disabled).
 *    - Checkout panel renders itself as soon as one seat is active, keeps a
 *      live cost counter in JavaScript and posts an atomic booking request.
 * ============================================================================
 */

require_once __DIR__ . '/config/db.php';

$db = lucy_db_safe();

/* --------------------------------------------------------- 1. load trip --- */
$tripId = (int) ($_GET['trip_id'] ?? 0);
$trip   = null;
$booked = [];

if ($db && $tripId > 0) {
    try {
        $trip = lucy_find_trip($db, $tripId);
        if ($trip) {
            $booked = lucy_booked_seats($db, $tripId);
        }
    } catch (Throwable $e) {
        $GLOBALS['LUCY_DB_ERROR'] = $e->getMessage();
    }
}

if (!$trip) {
    lucy_header('Trip not found', 'seats');
    ?>
    <div class="card mt-3">
        <p class="card__eyebrow">Seat map unavailable</p>
        <h2>That departure could not be loaded</h2>
        <p class="card__muted">
            <?= $tripId > 0 ? 'Trip #' . (int) $tripId . ' does not exist.' : 'No trip_id was supplied in the address.' ?>
            Head back to the search board and pick a coach from the schedule.
        </p>
        <a class="btn btn--green" href="<?= h(BASE_URL) ?>/index.php">← Back to the schedule board</a>
    </div>
    <?php
    lucy_footer();
    exit;
}

$totalSeats = (int) $trip['total_seats'];
$fare       = (float) $trip['ticket_price'];
$rows       = (int) ceil($totalSeats / 4);
$seatsFree  = max(0, $totalSeats - count($booked));
$occupancy  = lucy_occupancy_pct($trip);

lucy_header('Choose Seats · ' . $trip['source'] . ' → ' . $trip['destination'], 'seats');
?>

<div class="row-between mt-3">
    <div>
        <p class="card__eyebrow">Step 2 — Pick your seats</p>
        <h1><?= h($trip['source']) ?> <span class="trip__arrow">→</span> <?= h($trip['destination']) ?></h1>
        <p class="page__lede">
            <?= h(lucy_date_label($trip['travel_date'])) ?> &middot;
            departs <?= h(lucy_time($trip['departure_time'])) ?> &middot; arrives <?= h(lucy_time($trip['arrival_time'])) ?>
            (<?= h(lucy_duration($trip['departure_time'], $trip['arrival_time'])) ?>)
        </p>
    </div>
    <a class="btn btn--ghost" href="<?= h(BASE_URL) ?>/index.php">← New search</a>
</div>

<section class="booking-layout">
    <!-- ===================== BUS FLOOR PLAN ===================== -->
    <div class="bus-shell">
        <div class="card__head">
            <div>
                <p class="card__eyebrow">Bus <?= h($trip['bus_number']) ?></p>
                <h2><?= h($trip['bus_type']) ?></h2>
                <p class="card__muted">
                    <?= (int) $totalSeats ?> seats &middot;
                    <strong><?= $seatsFree ?></strong> available &middot;
                    <?= count($booked) ?> occupied (<?= $occupancy ?>% full)
                </p>
            </div>
            <button type="button" class="btn btn--ghost btn--sm" id="refresh-seats">↻ Refresh seat map</button>
        </div>

        <div class="bus-shell__frame">
            <div class="driver-strip" aria-hidden="true">
                <div class="driver-seat">
                    <svg viewBox="0 0 24 24" width="20" height="20">
                        <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/>
                        <circle cx="12" cy="12" r="3.2" fill="none" stroke="currentColor" stroke-width="1.6"/>
                        <path d="M12 3v5.5M12 15.5V21M3 12h5.5M15.5 12H21" stroke="currentColor" stroke-width="1.6"/>
                    </svg>
                    Driver
                </div>
                <div class="door-marker">Boarding door ↗</div>
            </div>

            <div class="seat-map" id="seat-map" role="group" aria-label="Bus seat map">
                <?php
                $seatNo = 1;
                for ($r = 0; $r < $rows; $r++):
                    echo '<div class="seat-row">';
                    for ($i = 0; $i < 4; $i++):
                        if ($i === 2):
                            echo '<span class="aisle" aria-hidden="true"></span>';
                        endif;

                        if ($seatNo <= $totalSeats):
                            $isBooked = in_array($seatNo, $booked, true);
                            ?>
                            <button type="button"
                                    class="seat <?= $isBooked ? 'seat--booked' : 'seat--available' ?>"
                                    data-seat="<?= $seatNo ?>"
                                    data-status="<?= $isBooked ? 'booked' : 'available' ?>"
                                    aria-label="Seat <?= $seatNo ?><?= $isBooked ? ', already booked' : ', available' ?>"
                                    <?= $isBooked ? 'disabled aria-disabled="true"' : '' ?>>
                                <?= $seatNo ?>
                            </button>
                            <?php
                            $seatNo++;
                        else:
                            echo '<span class="seat seat--empty" aria-hidden="true"></span>';
                        endif;
                    endfor;
                    echo '</div>';
                endfor;
                ?>
            </div>
        </div>

        <div class="legend">
            <span class="legend__item"><span class="legend__swatch legend__swatch--available"></span> Available</span>
            <span class="legend__item"><span class="legend__swatch legend__swatch--selected"></span> Selected by you</span>
            <span class="legend__item"><span class="legend__swatch legend__swatch--booked"></span> Already booked</span>
            <span class="legend__item text-muted">Tip: tap a green seat to activate the checkout panel.</span>
            <span class="legend__item selam" lang="am">ጉዞ ደህና &middot; safe journey</span>
        </div>
    </div>

    <!-- ===================== CHECKOUT PANEL ===================== -->
    <aside>
        <div class="empty-hint" id="checkout-hint">
            No seat selected yet.<br>
            Tap any <strong style="color:var(--green)">green</strong> seat on the floor plan and the
            instant checkout panel appears right here.
        </div>

        <div class="checkout" id="checkout-panel" hidden>
            <div class="card__head">
                <div>
                    <p class="card__eyebrow">Step 3 — Guest checkout &middot; <span lang="am">የጉዞ ትኬት</span></p>
                    <h3 style="margin:0">Confirm your allocation</h3>
                </div>
                <span class="badge badge--live">Live</span>
            </div>

            <p class="text-muted" style="font-size:0.83rem;margin:0">Selected seats</p>
            <div class="checkout__seats" id="selected-chips"></div>

            <div class="checkout__lines">
                <div class="checkout__line"><span>Seats</span><strong id="line-seats">—</strong></div>
                <div class="checkout__line"><span>Fare breakdown</span><strong id="line-count">0 × 0.00 ETB</strong></div>
                <div class="checkout__line"><span>Station</span><strong><?= h($trip['source']) ?> → <?= h($trip['destination']) ?></strong></div>
                <div class="checkout__line"><span>Departure</span><strong><?= h(lucy_time($trip['departure_time'])) ?> &middot; <?= h(lucy_date_label($trip['travel_date'])) ?></strong></div>
            </div>

            <div class="checkout__total">
                <span>Total payable</span>
                <strong id="checkout-total-value">0.00 <?= h(LUCY_CURRENCY) ?></strong>
            </div>

            <div class="field">
                <label for="passenger-name">Full Name</label>
                <input type="text" id="passenger-name" name="passenger_name"
                       placeholder="e.g. Abebe Bekele" autocomplete="name" maxlength="120">
            </div>
            <div class="field mt-2">
                <label for="passenger-phone">Mobile Phone Number</label>
                <input type="tel" id="passenger-phone" name="passenger_phone"
                       placeholder="0912345678" autocomplete="tel" maxlength="30">
                <span class="field__hint">Used for the boarding SMS. No account, e-mail or password required.</span>
            </div>

            <button type="button" class="btn btn--primary btn--block mt-2" id="confirm-booking" disabled>
                Confirm Ticket Allocation
            </button>
            <div id="checkout-feedback" class="mt-2"></div>
            <p class="text-muted mt-2" style="font-size:0.78rem;margin-bottom:0">
                Payment is settled with the conductor on boarding. Your confirmation code reserves the seat.
            </p>
        </div>

        <div class="card card--tint mt-2">
            <p class="card__eyebrow">Journey summary</p>
            <div class="checkout__line"><span>Bus</span><strong><?= h($trip['bus_number']) ?></strong></div>
            <div class="checkout__line"><span>Class</span><strong><?= h($trip['bus_type']) ?></strong></div>
            <div class="checkout__line"><span>Fare per seat</span><strong><?= h(lucy_money($fare)) ?></strong></div>
            <div class="checkout__line"><span>Occupancy</span><strong><?= (int) count($booked) ?> / <?= (int) $totalSeats ?></strong></div>
        </div>
    </aside>
</section>

<script>
    /* Server-injected runtime configuration consumed by js/app.js */
    window.LUCY = {
        base: <?= json_encode(BASE_URL) ?>,
        page: 'seats',
        tripId: <?= (int) $trip['id'] ?>,
        fare: <?= json_encode($fare) ?>,
        currency: <?= json_encode(LUCY_CURRENCY) ?>,
        totalSeats: <?= (int) $totalSeats ?>,
        poll: true,
        successUrl: <?= json_encode(BASE_URL . '/ticket-success.php') ?>,
        api: {
            booked: <?= json_encode(BASE_URL . '/api/get_booked_seats.php') ?>,
            finalize: <?= json_encode(BASE_URL . '/api/finalize_booking.php') ?>
        }
    };
</script>

<?php lucy_footer(); ?>
