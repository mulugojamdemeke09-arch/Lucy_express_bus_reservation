<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — index.php
 *  ---------------------------------------------------------------------------
 *  Public storefront and routing search engine.
 *
 *    - Two <select> dropdowns seeded with the 15-city network vector.
 *    - Date picker restricted to today or later (enforced in JS + PHP).
 *    - "Swap Stations" utility that flips origin/destination instantly.
 *    - Inline schedule results board: bus, vehicle class, hours, fare and a
 *      "Choose Seats" call to action.
 *
 *  NO AUTHENTICATION: anyone can search and reserve — no account, no login.
 * ============================================================================
 */

require_once __DIR__ . '/config/db.php';

$db = lucy_db_safe();
$cities = lucy_cities();
$cityList = lucy_city_list();

/* ------------------------------------------------------ 1. read the query -- */
$source      = isset($_GET['source']) ? trim((string) $_GET['source']) : '';
$destination = isset($_GET['destination']) ? trim((string) $_GET['destination']) : '';
$travelDate  = isset($_GET['travel_date']) ? trim((string) $_GET['travel_date']) : '';
$submitted   = isset($_GET['search']);

$errors  = [];
$results = [];

/* --------------------------------------------------- 2. server validation -- */
if ($submitted) {
    if (!lucy_is_city($source) || !lucy_is_city($destination)) {
        $errors[] = 'Choose both an origin and a destination station from the 15-city network.';
    } elseif ($source === $destination) {
        $errors[] = 'Origin and destination stations must be different.';
    }

    if ($travelDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
        $errors[] = 'Pick a valid travel date (YYYY-MM-DD).';
    } elseif ($travelDate < date('Y-m-d')) {
        $errors[] = 'Travel date cannot be in the past — Lucy Express only sells upcoming departures.';
    } elseif ($travelDate > date('Y-m-d', strtotime('+120 days'))) {
        $errors[] = 'The booking window currently opens 120 days ahead.';
    }

    if (!$errors && $db) {
        $results = lucy_search_trips($db, $source, $destination, $travelDate);
    }
}

/* ------------------------------------------- 3. teaser + network metrics -- */
$featured = [];
$metrics  = ['departures' => 0, 'seats_available' => 0, 'cities' => count($cityList), 'sold' => 0];

if ($db) {
    try {
        $featured = lucy_upcoming_trips($db, 6);

        $metrics['departures'] = (int) $db->query(
            'SELECT COUNT(*) FROM trips WHERE travel_date >= CURDATE()'
        )->fetchColumn();

        $metrics['sold'] = (int) $db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();

        $capacity = $db->query(
            'SELECT COALESCE(SUM(b.total_seats), 0) AS capacity
               FROM trips t JOIN buses b ON b.id = t.bus_id
              WHERE t.travel_date >= CURDATE()'
        )->fetchColumn();
        $metrics['seats_available'] = max(0, (int) $capacity - (int) $metrics['sold']);
    } catch (Throwable $e) {
        $errors[] = 'Database query failed: ' . $e->getMessage();
    }
}

lucy_header('Book a Ticket', 'book');
?>

<section class="hero">
    <div class="hero__photo">
        <!-- Real photograph: Lucy Express coach on an Ethiopian highland road -->
        <img src="<?= h(lucy_asset('img/bus-coach.jpg')) ?>"
             alt="A green Lucy Express intercity coach on a mountain road in the Ethiopian highlands"
             width="1000" height="750">

        <div class="hero__panel">
            <span class="hero__tag">Selam! &middot; <span lang="am">እንኳን ደህና መጡ</span> &middot; no account required</span>
            <h1>Reserve your seat across <span>Ethiopia</span> in under a minute.</h1>
            <p class="page__lede">
                <strong><?= h(LUCY_BRAND_EN) ?></strong> // <span lang="am"><?= h(LUCY_BRAND_AM) ?></span>
                runs a modern fleet between the country's busiest stations — from <em>Addis Ababa</em> to
                Hawassa, Bahir Dar and Gondar. Search the routing matrix, pick your exact seat on the live
                floor plan, and board with a printable ticket under a single confirmation code.
            </p>

        <div class="hero__facts">
            <div class="fact">
                <div class="fact__value"><?= (int) $metrics['departures'] ?></div>
                <div class="fact__label">Upcoming departures</div>
            </div>
            <div class="fact">
                <div class="fact__value"><?= (int) $metrics['seats_available'] ?></div>
                <div class="fact__label">Seats open</div>
            </div>
            <div class="fact">
                <div class="fact__value"><?= (int) $metrics['cities'] ?></div>
                <div class="fact__label">Network stations</div>
            </div>
            <div class="fact">
                <div class="fact__value"><?= (int) $metrics['sold'] ?></div>
                <div class="fact__label">Tickets issued</div>
            </div>
            </div>
        </div><!-- /hero__panel -->
    </div><!-- /hero__photo -->

    <div class="hero__aside">
        <div class="card">
            <div class="card__head">
                <div>
                    <p class="card__eyebrow">Operating network</p>
                    <h3>15 intercity stations</h3>
                </div>
                <span class="selam" lang="am">ሰላም!</span>
            </div>
            <ul class="network-list">
                <?php foreach ($cities as $en => $am): ?>
                    <li><?= h($en) ?> &middot; <span lang="am"><?= h($am) ?></span></li>
                <?php endforeach; ?>
            </ul>
            <p class="card__muted mt-2" style="margin-bottom:0">
                One ticket counter, fifteen cities — the same reservation engine serves every station.
            </p>
        </div>

        <div class="card">
            <p class="card__eyebrow">Fleet &amp; comfort</p>
            <h3>Luxury AC &amp; Standard Non-AC</h3>
            <img class="bus-thumb"
                 src="<?= h(lucy_asset('img/hero-bus.jpg')) ?>"
                 alt="A Lucy Express city coach photographed in Addis Ababa"
                 width="1600" height="675">
            <p class="card__muted mt-2">
                Every vehicle in the fleet is seat-mapped. <strong>Luxury AC</strong> coaches carry 32
                reclining seats, <strong>Standard Non-AC</strong> coaches run 28 berths at a friendlier
                fare. Buna (coffee) stops on long highland routes.
            </p>
            <a class="btn btn--green btn--block" href="#schedule">Browse the schedule board</a>
        </div>
    </div>
</section>

<!-- ============================ SEARCH ENGINE ============================ -->
<section class="card" id="search">
    <div class="card__head">
        <div>
            <p class="card__eyebrow">Step 1 — Search</p>
            <h2>Find a departure</h2>
            <p class="card__muted">Pick your stations and travel date. Fares shown are per seat, in ETB.</p>
        </div>
        <span class="badge badge--live">Guest checkout</span>
    </div>

    <?php if ($errors): ?>
        <div class="notice notice--error" role="alert">
            <strong>Please review your search:</strong>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form id="search-form" method="get" action="<?= h(BASE_URL) ?>/index.php" novalidate>
        <input type="hidden" name="search" value="1">
        <div class="form-grid">
            <div class="field">
                <label for="source">Origin Station</label>
                <select id="source" name="source" required>
                    <option value="">— Select origin —</option>
                    <?php foreach ($cities as $en => $am): ?>
                        <option value="<?= h($en) ?>" <?= $source === $en ? 'selected' : '' ?>>
                            <?= h($en) ?> — <?= h($am) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="destination">Destination Station</label>
                <select id="destination" name="destination" required>
                    <option value="">— Select destination —</option>
                    <?php foreach ($cities as $en => $am): ?>
                        <option value="<?= h($en) ?>" <?= $destination === $en ? 'selected' : '' ?>>
                            <?= h($en) ?> — <?= h($am) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="travel_date">Travel Date</label>
                <input type="date" id="travel_date" name="travel_date"
                       value="<?= h($travelDate) ?>" min="<?= h(date('Y-m-d')) ?>" required>
                <span class="field__hint">Today or any future date.</span>
            </div>

            <div class="swap-row">
                <button type="button" class="btn btn--ghost" id="swap-stations"
                        title="Swap the origin and destination stations">
                    ⇄ Swap Stations
                </button>
                <button type="submit" class="btn btn--primary">Search Schedules</button>
            </div>
        </div>
    </form>
</section>

<!-- ============================ SCHEDULE BOARD ============================ -->
<section class="board" id="schedule">
    <?php if ($submitted && !$errors): ?>
        <div class="card">
            <div class="card__head">
                <div>
                    <p class="card__eyebrow">Step 2 — Choose a bus</p>
                    <h2><?= h($source) ?> <span class="trip__arrow">→</span> <?= h($destination) ?></h2>
                    <p class="card__muted">
                        <?= h(lucy_date_label($travelDate)) ?> &middot;
                        <?= count($results) ?> departure<?= count($results) === 1 ? '' : 's' ?> found
                    </p>
                </div>
                <a class="btn btn--ghost btn--sm" href="<?= h(BASE_URL) ?>/index.php">Reset search</a>
            </div>

            <?php if (!$results): ?>
                <div class="notice notice--warn">
                    No <?= h(LUCY_BRAND_EN) ?> coach is scheduled on this route for that date.
                    Try the reverse direction, a neighbouring date, or browse the upcoming departures below.
                </div>
            <?php else: ?>
                <div class="board__list">
                    <?php foreach ($results as $trip): ?>
                        <?php $pct = lucy_occupancy_pct($trip); $free = (int) $trip['total_seats'] - (int) $trip['seats_booked']; ?>
                        <article class="trip <?= $free <= 0 ? 'trip--full' : ($pct >= 70 ? 'trip--soon' : '') ?>">
                            <div>
                                <div class="trip__route">
                                    <?= h($trip['source']) ?>
                                    <span class="trip__arrow">→</span>
                                    <?= h($trip['destination']) ?>
                                    <small><?= h(lucy_date_label($trip['travel_date'])) ?> &middot; Bus <?= h($trip['bus_number']) ?></small>
                                </div>
                                <div class="row mt-1">
                                    <span class="badge <?= $trip['bus_type'] === 'Luxury AC' ? 'badge--luxury' : 'badge--standard' ?>">
                                        <?= h($trip['bus_type']) ?>
                                    </span>
                                    <span class="badge"><?= (int) $trip['total_seats'] ?> seats</span>
                                </div>
                            </div>

                            <div class="trip__meta">
                                <span class="trip__time"><?= h(lucy_time($trip['departure_time'])) ?> → <?= h(lucy_time($trip['arrival_time'])) ?></span>
                                <span>Journey <?= h(lucy_duration($trip['departure_time'], $trip['arrival_time'])) ?></span>
                            </div>

                            <div>
                                <div class="trip__price">
                                    <?= h(lucy_money($trip['ticket_price'])) ?>
                                    <small>per seat</small>
                                </div>
                                <span class="seat-availability <?= $free <= 0 ? 'seat-availability--out' : ($pct >= 70 ? 'seat-availability--low' : 'seat-availability--ok') ?>">
                                    <?= $free <= 0 ? 'Sold out' : $free . ' of ' . (int) $trip['total_seats'] . ' seats free' ?>
                                </span>
                            </div>

                            <div class="trip__action">
                                <?php if ($free <= 0): ?>
                                    <span class="btn btn--primary is-disabled" aria-disabled="true">Fully Booked</span>
                                <?php else: ?>
                                    <a class="btn btn--green"
                                       href="<?= h(BASE_URL) ?>/select-seats.php?trip_id=<?= (int) $trip['id'] ?>">
                                        Choose Seats →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card__head">
            <div>
                <p class="card__eyebrow"><?= $submitted ? 'Or browse' : 'Live board' ?> — Upcoming network departures</p>
                <h2>Next coaches leaving the stations</h2>
                <p class="card__muted">Any of these can be booked right now — no registration, no login.</p>
            </div>
        </div>

        <?php if (!$featured): ?>
            <div class="notice notice--warn">
                No upcoming departures are loaded yet. Run the <code>lucy_express.sql</code> import in
                phpMyAdmin (it seeds the fleet plus a full month of trips), or create schedules from the
                <a href="<?= h(BASE_URL) ?>/admin/dashboard.php">Admin Portal</a>.
            </div>
        <?php else: ?>
            <div class="board__list">
                <?php foreach ($featured as $trip): ?>
                    <?php $free = (int) $trip['total_seats'] - (int) $trip['seats_booked']; ?>
                    <article class="trip <?= $free <= 0 ? 'trip--full' : '' ?>">
                        <div>
                            <div class="trip__route">
                                <?= h($trip['source']) ?>
                                <span class="trip__arrow">→</span>
                                <?= h($trip['destination']) ?>
                                <small><?= h(lucy_date_label($trip['travel_date'])) ?> &middot; Bus <?= h($trip['bus_number']) ?></small>
                            </div>
                            <div class="row mt-1">
                                <span class="badge <?= $trip['bus_type'] === 'Luxury AC' ? 'badge--luxury' : 'badge--standard' ?>">
                                    <?= h($trip['bus_type']) ?>
                                </span>
                            </div>
                        </div>

                        <div class="trip__meta">
                            <span class="trip__time"><?= h(lucy_time($trip['departure_time'])) ?> → <?= h(lucy_time($trip['arrival_time'])) ?></span>
                            <span>Journey <?= h(lucy_duration($trip['departure_time'], $trip['arrival_time'])) ?></span>
                        </div>

                        <div>
                            <div class="trip__price">
                                <?= h(lucy_money($trip['ticket_price'])) ?>
                                <small>per seat</small>
                            </div>
                            <span class="seat-availability <?= $free <= 0 ? 'seat-availability--out' : 'seat-availability--ok' ?>">
                                <?= $free <= 0 ? 'Sold out' : $free . ' free' ?>
                            </span>
                        </div>

                        <div class="trip__action">
                            <?php if ($free <= 0): ?>
                                <span class="btn btn--primary is-disabled" aria-disabled="true">Fully Booked</span>
                            <?php else: ?>
                                <a class="btn btn--green" href="<?= h(BASE_URL) ?>/select-seats.php?trip_id=<?= (int) $trip['id'] ?>">Choose Seats →</a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="pattern-band" aria-hidden="true"></div>

<!-- ======================= WHY TRAVEL WITH LUCY ======================= -->
<section class="feature-grid">
    <article class="feature">
        <h3><span class="feature__icon" aria-hidden="true">☕</span> Buna on the long routes</h3>
        <p>
            Ethiopia gave the world coffee. On the Addis Ababa–Hawassa and northern highland services the
            coach pauses at a roadside bunna bet, so you arrive refreshed, not rattled.
        </p>
    </article>
    <article class="feature">
        <h3><span class="feature__icon" aria-hidden="true">🪑</span> Choose your exact berth</h3>
        <p>
            Our floor plans mirror the real cabin — two seats, a walking aisle, two more. Green seats are
            open, yellow are yours, grey are already taken. No guesswork at the terminal.
        </p>
    </article>
    <article class="feature">
        <h3><span class="feature__icon" aria-hidden="true">🎫</span> One code, no account</h3>
        <p>
            Enter your name and mobile number and you are done. Your <code>LUCY-XXXXXX</code> confirmation
            code covers every seat in the transaction — show it printed or on screen.
        </p>
    </article>
    <article class="feature">
        <h3><span class="feature__icon" aria-hidden="true">🇪🇹</span> Fifteen Ethiopian cities</h3>
        <p>
            Addis Ababa, Adama, Hawassa, Bahir Dar, Gondar, Mekelle, Dire Dawa, Harar, Jimma, Dessie,
            Arba Minch, Sodo, Shashemene, Nekemte and Jigjiga — with return legs on every trunk route.
        </p>
    </article>
</section>

<div class="notice notice--ok mt-3" style="text-align:center">
    <strong lang="am">ጉዞ ደህና!</strong> &nbsp;Have a safe journey — Lucy Express // ሉሲ ኤክስፕሬስ,
    <span class="text-muted">running the Ethiopian intercity network since the days of the first Lucy fossil dig at Hadar.</span>
</div>

<?php lucy_footer(); ?>
