<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — admin/dashboard.php
 *  ---------------------------------------------------------------------------
 *  Open (unmapped) operations console:
 *    1. Business intelligence KPIs — tickets sold, revenue, low occupancy.
 *    2. Live capacity tracker matrix with occupancy progress bars; clicking a
 *       row expands an inline graphical view of the occupied seats.
 *    3. Fast trip creator (CRUD) — bus + 15-city routing + times + fare.
 *    4. Trip deletion and single-seat cancelation.
 *
 *  There is intentionally NO authentication layer in this build — the whole
 *  application is a no-login guest system (see the project README for the
 *  hardening checklist to apply before exposing it on a public network).
 * ============================================================================
 */

require_once __DIR__ . '/../config/db.php';

session_start();
$db = lucy_db_safe();

/* ------------------------------------------------------- flash messaging -- */
function flash(string $type, string $message): void
{
    $_SESSION['lucy_flash'] = ['type' => $type, 'message' => $message];
}
function pull_flash(): ?array
{
    if (empty($_SESSION['lucy_flash'])) {
        return null;
    }
    $flash = $_SESSION['lucy_flash'];
    unset($_SESSION['lucy_flash']);
    return $flash;
}
function dashboard_url(array $params = []): string
{
    $query = http_build_query($params);
    return BASE_URL . '/admin/dashboard.php' . ($query !== '' ? '?' . $query : '');
}

/* =========================================================================
 * 1. WRITE ACTIONS (POST + redirect)
 * ====================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
    $action = (string) ($_POST['action'] ?? '');

    try {
        /* ------------------------------------------- 1a. create a schedule -- */
        if ($action === 'create_trip') {
            $busId     = (int) ($_POST['bus_id'] ?? 0);
            $source    = trim((string) ($_POST['source'] ?? ''));
            $dest      = trim((string) ($_POST['destination'] ?? ''));
            $departure = trim((string) ($_POST['departure_time'] ?? ''));
            $arrival   = trim((string) ($_POST['arrival_time'] ?? ''));
            $date      = trim((string) ($_POST['travel_date'] ?? ''));
            $price     = (float) ($_POST['ticket_price'] ?? 0);

            $problems = [];
            if ($busId <= 0) {
                $problems[] = 'Choose a fleet asset.';
            }
            if (!lucy_is_city($source) || !lucy_is_city($dest)) {
                $problems[] = 'Origin and destination must be two of the 15 network stations.';
            } elseif ($source === $dest) {
                $problems[] = 'Origin and destination must differ.';
            }
            if (!preg_match('/^\d{2}:\d{2}$/', $departure) || !preg_match('/^\d{2}:\d{2}$/', $arrival)) {
                $problems[] = 'Departure and arrival times must use the HH:MM format.';
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
                $problems[] = 'Travel date must be today or later.';
            }
            if ($price <= 0) {
                $problems[] = 'Ticket price must be greater than zero.';
            }

            /* does the bus already run a schedule at that exact moment? */
            if (!$problems) {
                $dup = $db->prepare(
                    'SELECT COUNT(*) FROM trips
                      WHERE bus_id = :bus AND travel_date = :dte AND departure_time = :dep'
                );
                $dup->execute([':bus' => $busId, ':dte' => $date, ':dep' => $departure . ':00']);
                if ((int) $dup->fetchColumn() > 0) {
                    $problems[] = 'That bus is already scheduled to depart at ' . $departure . ' on ' . $date . '.';
                }
            }

            if ($problems) {
                flash('error', implode(' ', $problems));
            } else {
                $insert = $db->prepare(
                    'INSERT INTO trips (bus_id, source, destination, departure_time, arrival_time, ticket_price, travel_date)
                     VALUES (:bus, :src, :dst, :dep, :arr, :price, :dte)'
                );
                $insert->execute([
                    ':bus'   => $busId,
                    ':src'   => $source,
                    ':dst'   => $dest,
                    ':dep'   => $departure . ':00',
                    ':arr'   => $arrival . ':00',
                    ':price' => $price,
                    ':dte'   => $date,
                ]);
                flash('ok', 'Schedule created: ' . $source . ' → ' . $dest . ' on ' . lucy_date_label($date) . ' at ' . $departure . '.');
            }
            header('Location: ' . dashboard_url());
            exit;
        }

        /* --------------------------------------------- 1b. delete a trip -- */
        if ($action === 'delete_trip') {
            $tripId = (int) ($_POST['trip_id'] ?? 0);
            $sold = $db->prepare('SELECT COUNT(*) FROM bookings WHERE trip_id = :id');
            $sold->execute([':id' => $tripId]);
            $count = (int) $sold->fetchColumn();

            $del = $db->prepare('DELETE FROM trips WHERE id = :id');
            $del->execute([':id' => $tripId]);

            flash('ok', 'Trip #' . $tripId . ' deleted along with ' . $count . ' booking row(s) (ON DELETE CASCADE).');
            header('Location: ' . dashboard_url());
            exit;
        }

        /* ------------------------------------- 1c. cancel a single berth -- */
        if ($action === 'cancel_booking') {
            $bookingId = (int) ($_POST['booking_id'] ?? 0);
            $info = $db->prepare(
                'SELECT bk.seat_number, bk.confirmation_code, t.source, t.destination, t.travel_date
                   FROM bookings bk JOIN trips t ON t.id = bk.trip_id
                  WHERE bk.id = :id'
            );
            $info->execute([':id' => $bookingId]);
            $row = $info->fetch();

            if (!$row) {
                flash('error', 'That booking row no longer exists.');
            } else {
                $db->prepare('DELETE FROM bookings WHERE id = :id')->execute([':id' => $bookingId]);
                flash('ok', 'Seat ' . (int) $row['seat_number'] . ' on ' . $row['source'] . ' → ' . $row['destination']
                    . ' (' . lucy_date_label($row['travel_date']) . ') released. Code ' . $row['confirmation_code'] . '.');
            }
            header('Location: ' . dashboard_url());
            exit;
        }

        flash('error', 'Unknown administrative action.');
        header('Location: ' . dashboard_url());
        exit;
    } catch (Throwable $e) {
        flash('error', 'Operation failed: ' . $e->getMessage());
        header('Location: ' . dashboard_url());
        exit;
    }
}

$flash = pull_flash();

/* =========================================================================
 * 2. READ MODELS
 * ====================================================================== */
$scope = (string) ($_GET['scope'] ?? 'upcoming');
$scope = in_array($scope, ['upcoming', 'today', 'all'], true) ? $scope : 'upcoming';
$filterDate = (string) ($_GET['date'] ?? '');
$filterDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate) ? $filterDate : '';
$q = trim((string) ($_GET['q'] ?? ''));

$trips = [];
$buses = [];
$seatMap = [];        // trip_id => [seat numbers]
$bookingsByTrip = []; // trip_id => booking rows
$kpi = [
    'tickets_all' => 0, 'revenue_all' => 0.0,
    'tickets_active' => 0, 'revenue_active' => 0.0,
    'departures_active' => 0, 'seats_active' => 0,
    'today_departures' => 0, 'low_occupancy' => [],
];

if ($db) {
    try {
        /* ---------- 2a. fleet assets for the trip creator ---------- */
        $buses = $db->query('SELECT id, bus_number, total_seats, bus_type FROM buses ORDER BY bus_number ASC')->fetchAll();

        /* ---------- 2b. aggregate KPIs ---------- */
        $kpi['tickets_all'] = (int) $db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
        $kpi['revenue_all'] = (float) $db->query(
            'SELECT COALESCE(SUM(t.ticket_price), 0) FROM bookings bk JOIN trips t ON t.id = bk.trip_id'
        )->fetchColumn();
        $kpi['tickets_active'] = (int) $db->query(
            'SELECT COUNT(*) FROM bookings bk JOIN trips t ON t.id = bk.trip_id WHERE t.travel_date >= CURDATE()'
        )->fetchColumn();
        $kpi['revenue_active'] = (float) $db->query(
            'SELECT COALESCE(SUM(t.ticket_price), 0) FROM bookings bk JOIN trips t ON t.id = bk.trip_id
              WHERE t.travel_date >= CURDATE()'
        )->fetchColumn();
        $kpi['departures_active'] = (int) $db->query(
            'SELECT COUNT(*) FROM trips WHERE travel_date >= CURDATE()'
        )->fetchColumn();
        $kpi['today_departures'] = (int) $db->query(
            'SELECT COUNT(*) FROM trips WHERE travel_date = CURDATE()'
        )->fetchColumn();
        $kpi['seats_active'] = (int) $db->query(
            'SELECT COALESCE(SUM(b.total_seats), 0) FROM trips t JOIN buses b ON b.id = t.bus_id
              WHERE t.travel_date >= CURDATE()'
        )->fetchColumn();

        /* ---------- 2c. filtered capacity matrix ---------- */
        $where = [];
        $params = [];

        if ($filterDate !== '') {
            $where[] = 't.travel_date = :fdate';
            $params[':fdate'] = $filterDate;
        } elseif ($scope === 'upcoming') {
            $where[] = 't.travel_date >= CURDATE()';
        } elseif ($scope === 'today') {
            $where[] = 't.travel_date = CURDATE()';
        }

        if ($q !== '') {
            $where[] = '(t.source LIKE :q OR t.destination LIKE :q OR b.bus_number LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $sql = lucy_trip_select_sql()
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY t.travel_date ASC, t.departure_time ASC
                 LIMIT 300';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trips = $stmt->fetchAll();

        /* ---------- 2d. bookings of the listed trips (seat maps + lists) - */
        if ($trips) {
            $ids = array_map(static fn (array $t): int => (int) $t['id'], $trips);
            $in  = implode(',', array_fill(0, count($ids), '?'));

            $bk = $db->prepare(
                'SELECT id, trip_id, seat_number, passenger_name, passenger_phone, confirmation_code, booked_at
                   FROM bookings
                  WHERE trip_id IN (' . $in . ')
                  ORDER BY trip_id ASC, seat_number ASC'
            );
            $bk->execute($ids);

            foreach ($bk->fetchAll() as $row) {
                $tid = (int) $row['trip_id'];
                $seatMap[$tid][] = (int) $row['seat_number'];
                $bookingsByTrip[$tid][] = $row;
            }
        }

        /* ---------- 2e. low occupancy warnings (< 30% sold) ---------- */
        $warn = $db->query(
            'SELECT t.id, t.source, t.destination, t.travel_date, t.departure_time, t.ticket_price,
                    b.bus_number, b.total_seats,
                    COALESCE((SELECT COUNT(*) FROM bookings WHERE trip_id = t.id), 0) AS seats_booked
               FROM trips t
               JOIN buses b ON b.id = t.bus_id
              WHERE t.travel_date >= CURDATE()
           ORDER BY t.travel_date ASC, t.departure_time ASC'
        )->fetchAll();

        foreach ($warn as $row) {
            if (lucy_occupancy_pct($row) < 30) {
                $kpi['low_occupancy'][] = $row;
            }
        }
    } catch (Throwable $e) {
        $GLOBALS['LUCY_DB_ERROR'] = $e->getMessage();
    }
}

$avgTicket = $kpi['tickets_all'] > 0 ? $kpi['revenue_all'] / $kpi['tickets_all'] : 0.0;
$fleetSeats = 0;
foreach ($buses as $bus) {
    $fleetSeats += (int) $bus['total_seats'];
}

lucy_header('Operations console', 'admin');
?>

<div class="row-between mt-3">
    <div>
        <p class="card__eyebrow">Administrative console &middot; open access</p>
        <h1>Lucy Express Operations Dashboard</h1>
        <p class="page__lede">
            Live capacity, revenue and scheduling control for the whole 15-station network.
            No login is required in this build — every panel below is directly actionable.
        </p>
    </div>
    <div class="row">
        <a class="btn btn--ghost" href="<?= h(BASE_URL) ?>/index.php">← Public storefront</a>
        <a class="btn btn--green" href="<?= h(BASE_URL) ?>/index.php#schedule">Live schedule board</a>
    </div>
</div>

<?php if ($flash): ?>
    <div class="notice <?= $flash['type'] === 'ok' ? 'notice--ok' : 'notice--error' ?>" role="status">
        <?= h($flash['message']) ?>
    </div>
<?php endif; ?>

<!-- Ethiopian photo band: the fleet this console manages -->
<div class="hero__photo" style="min-height:180px;margin:1.25rem 0 0">
    <img src="<?= h(lucy_asset('img/hero-bus.jpg')) ?>"
         alt="A Lucy Express coach photographed on the streets of Addis Ababa"
         width="1600" height="675">
    <div class="hero__panel" style="padding:1.25rem 1.75rem">
        <p class="card__eyebrow" style="margin-bottom:0.15rem">Operations &middot; <span lang="am">ሉሲ ኤክስፕሬስ</span></p>
        <h2 style="margin:0">Network control room</h2>
        <p class="card__muted" style="margin:0.35rem 0 0;font-size:0.9rem">
            Capacity, fares and schedules for all fifteen Ethiopian stations in one board.
        </p>
    </div>
</div>

<!-- ============================== KPI BLOCK ============================== -->
<section class="kpi-grid">
    <div class="kpi">
        <div class="kpi__label">Total active tickets sold</div>
        <div class="kpi__value kpi__value--green"><?= (int) $kpi['tickets_active'] ?></div>
        <div class="kpi__note"><?= (int) $kpi['tickets_all'] ?> issued all-time &middot; upcoming departures only counted as active</div>
    </div>
    <div class="kpi">
        <div class="kpi__label">Revenue generated (all-time)</div>
        <div class="kpi__value kpi__value--amber"><?= h(lucy_money($kpi['revenue_all'])) ?></div>
        <div class="kpi__note"><?= h(lucy_money($kpi['revenue_active'])) ?> booked against future departures</div>
    </div>
    <div class="kpi">
        <div class="kpi__label">Average ticket value</div>
        <div class="kpi__value"><?= h(lucy_money($avgTicket)) ?></div>
        <div class="kpi__note"><?= count($buses) ?> fleet assets &middot; <?= (int) $fleetSeats ?> mapped seats</div>
    </div>
    <div class="kpi">
        <div class="kpi__label">Upcoming departures</div>
        <div class="kpi__value"><?= (int) $kpi['departures_active'] ?></div>
        <div class="kpi__note"><?= (int) $kpi['today_departures'] ?> leaving today</div>
    </div>
    <div class="kpi">
        <div class="kpi__label">Low-occupancy warnings</div>
        <div class="kpi__value <?= count($kpi['low_occupancy']) ? 'kpi__value--amber' : 'kpi__value--green' ?>">
            <?= count($kpi['low_occupancy']) ?>
        </div>
        <div class="kpi__note">Upcoming coaches below 30% occupancy</div>
    </div>
</section>

<?php if ($kpi['low_occupancy']): ?>
    <div class="notice notice--warn">
        <strong>Under-filled departures — consider a promotional fare or coach downsizing:</strong>
        <ul>
            <?php foreach (array_slice($kpi['low_occupancy'], 0, 5) as $low): ?>
                <li>
                    <?= h($low['source']) ?> → <?= h($low['destination']) ?>
                    on <?= h(lucy_date_label($low['travel_date'])) ?>
                    at <?= h(lucy_time($low['departure_time'])) ?>
                    (bus <?= h($low['bus_number']) ?>)
                    — <?= (int) $low['seats_booked'] ?> / <?= (int) $low['total_seats'] ?> sold
                    (<?= lucy_occupancy_pct($low) ?>%)
                    &middot; fare <?= h(lucy_money($low['ticket_price'])) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- ========================= CAPACITY TRACKER ========================== -->
<section class="card card--tint">
    <div class="card__head">
        <div>
            <p class="card__eyebrow">Live capacity tracker matrix</p>
            <h2>Every scheduled trip at a glance</h2>
            <p class="card__muted">Click any row to expand the inline occupied-seat map and passenger manifest.</p>
        </div>
    </div>

    <form class="filter-bar" method="get" action="<?= h(BASE_URL) ?>/admin/dashboard.php">
        <div class="field">
            <label for="scope">Scope</label>
            <select id="scope" name="scope">
                <option value="upcoming" <?= $scope === 'upcoming' ? 'selected' : '' ?>>Upcoming departures</option>
                <option value="today" <?= $scope === 'today' ? 'selected' : '' ?>>Today only</option>
                <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Entire history</option>
            </select>
        </div>
        <div class="field">
            <label for="date">Exact date</label>
            <input type="date" id="date" name="date" value="<?= h($filterDate) ?>">
        </div>
        <div class="field">
            <label for="q">Route / bus filter</label>
            <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="Hawassa, Bahir Dar, LUCY-101…">
        </div>
        <button type="submit" class="btn btn--primary">Apply filters</button>
        <a class="btn btn--ghost" href="<?= h(BASE_URL) ?>/admin/dashboard.php">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="data data--dark" id="capacity-table">
            <thead>
                <tr>
                    <th>Route</th>
                    <th>Date &amp; time</th>
                    <th>Coach</th>
                    <th>Fare</th>
                    <th>Occupancy</th>
                    <th>Revenue</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$trips): ?>
                <tr>
                    <td colspan="7" class="text-muted">
                        No trips match these filters. Create one with the Fast Trip Creator below.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($trips as $trip): ?>
                <?php
                $tid       = (int) $trip['id'];
                $pct       = lucy_occupancy_pct($trip);
                $seatsDone = $seatMap[$tid] ?? [];
                $manifest  = $bookingsByTrip[$tid] ?? [];
                $tripRevenue = (float) $trip['ticket_price'] * count($seatsDone);
                $barClass  = $pct >= 85 ? 'progress__fill--high' : ($pct >= 30 ? '' : 'progress__fill--warn');
                ?>
                <tr class="trip-row"
                    data-trip-id="<?= $tid ?>"
                    data-total-seats="<?= (int) $trip['total_seats'] ?>"
                    data-booked="<?= h(json_encode($seatsDone)) ?>"
                    tabindex="0"
                    role="button"
                    aria-expanded="false"
                    aria-controls="trip-detail-<?= $tid ?>">
                    <td>
                        <span class="chev" aria-hidden="true">▶</span>
                        <strong><?= h($trip['source']) ?></strong> → <strong><?= h($trip['destination']) ?></strong>
                        <div class="text-muted" style="font-size:0.76rem">Trip #<?= $tid ?></div>
                    </td>
                    <td>
                        <?= h(lucy_date_label($trip['travel_date'])) ?><br>
                        <span class="text-muted"><?= h(lucy_time($trip['departure_time'])) ?> → <?= h(lucy_time($trip['arrival_time'])) ?></span>
                    </td>
                    <td>
                        <?= h($trip['bus_number']) ?><br>
                        <span class="badge <?= $trip['bus_type'] === 'Luxury AC' ? 'badge--luxury' : 'badge--standard' ?>"><?= h($trip['bus_type']) ?></span>
                    </td>
                    <td><?= h(lucy_money($trip['ticket_price'])) ?></td>
                    <td>
                        <div class="occupancy-cell">
                            <span><?= count($seatsDone) ?> / <?= (int) $trip['total_seats'] ?> Seats Booked</span>
                            <span class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Occupancy <?= $pct ?>%">
                                <span class="progress__fill <?= h($barClass) ?>" style="width: <?= $pct ?>%"></span>
                            </span>
                            <strong><?= $pct ?>%</strong>
                        </div>
                    </td>
                    <td><?= h(lucy_money($tripRevenue)) ?></td>
                    <td>
                        <form method="post" action="<?= h(BASE_URL) ?>/admin/dashboard.php" class="no-print"
                              onsubmit="return confirm('Delete trip #<?= $tid ?> (<?= h($trip['source']) ?> → <?= h($trip['destination']) ?>) and all of its bookings? This cannot be undone.');">
                            <input type="hidden" name="action" value="delete_trip">
                            <input type="hidden" name="trip_id" value="<?= $tid ?>">
                            <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                        </form>
                    </td>
                </tr>

                <tr class="expander" id="trip-detail-<?= $tid ?>" hidden>
                    <td colspan="7">
                        <div class="expander__inner">
                            <p class="expander__summary text-muted" style="margin:0"></p>
                            <div class="mini-map" aria-label="Occupied seat map for trip <?= $tid ?>"></div>

                            <?php if ($manifest): ?>
                                <div class="table-wrap">
                                    <table class="data">
                                        <thead>
                                            <tr>
                                                <th>Seat</th>
                                                <th>Passenger</th>
                                                <th>Mobile</th>
                                                <th>Confirmation</th>
                                                <th>Booked at</th>
                                                <th>Release</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($manifest as $booking): ?>
                                            <tr>
                                                <td><strong><?= (int) $booking['seat_number'] ?></strong></td>
                                                <td><?= h($booking['passenger_name']) ?></td>
                                                <td><?= h($booking['passenger_phone']) ?></td>
                                                <td><code><?= h($booking['confirmation_code']) ?></code></td>
                                                <td><?= h(date('d M Y H:i', strtotime((string) $booking['booked_at']))) ?></td>
                                                <td>
                                                    <form method="post" action="<?= h(BASE_URL) ?>/admin/dashboard.php"
                                                          onsubmit="return confirm('Release seat <?= (int) $booking['seat_number'] ?> for <?= h($booking['passenger_name']) ?>?');">
                                                        <input type="hidden" name="action" value="cancel_booking">
                                                        <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                                                        <button type="submit" class="btn btn--danger btn--sm">Cancel</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted" style="margin:0">No seats sold on this departure yet.</p>
                            <?php endif; ?>

                            <p style="margin:0">
                                <a class="btn btn--ghost btn--sm"
                                   href="<?= h(BASE_URL) ?>/select-seats.php?trip_id=<?= $tid ?>">
                                    Open public seat page for this trip →
                                </a>
                            </p>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ========================= FAST TRIP CREATOR ========================= -->
<section class="card">
    <div class="card__head">
        <div>
            <p class="card__eyebrow">Fast trip creator</p>
            <h2>Instantiate a new schedule</h2>
            <p class="card__muted">
                Pick a fleet asset, link two of the 15 network stations, set the hours, date and fare.
                The departure becomes bookable on the storefront the moment you submit.
            </p>
        </div>
        <span class="badge"><?= count($buses) ?> fleet assets available</span>
    </div>

    <?php if (!$buses): ?>
        <div class="notice notice--warn">
            No fleet assets found. Import <code>lucy_express.sql</code> to seed the three baseline buses,
            or insert rows into the <code>buses</code> table in phpMyAdmin.
        </div>
    <?php else: ?>
        <form method="post" action="<?= h(BASE_URL) ?>/admin/dashboard.php">
            <input type="hidden" name="action" value="create_trip">
            <div class="form-grid">
                <div class="field">
                    <label for="bus_id">Fleet asset</label>
                    <select id="bus_id" name="bus_id" required>
                        <?php foreach ($buses as $bus): ?>
                            <option value="<?= (int) $bus['id'] ?>">
                                <?= h($bus['bus_number']) ?> — <?= h($bus['bus_type']) ?> (<?= (int) $bus['total_seats'] ?> seats)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="source">Origin station</label>
                    <select id="source" name="source" required>
                        <?php foreach (lucy_cities() as $en => $am): ?>
                            <option value="<?= h($en) ?>" <?= $en === 'Addis Ababa' ? 'selected' : '' ?>><?= h($en) ?> — <?= h($am) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="destination">Destination station</label>
                    <select id="destination" name="destination" required>
                        <?php foreach (lucy_cities() as $en => $am): ?>
                            <option value="<?= h($en) ?>" <?= $en === 'Hawassa' ? 'selected' : '' ?>><?= h($en) ?> — <?= h($am) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="departure_time">Departure time</label>
                    <input type="time" id="departure_time" name="departure_time" value="06:30" required>
                </div>

                <div class="field">
                    <label for="arrival_time">Arrival time</label>
                    <input type="time" id="arrival_time" name="arrival_time" value="11:30" required>
                </div>

                <div class="field">
                    <label for="travel_date">Travel date</label>
                    <input type="date" id="travel_date" name="travel_date" value="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" required>
                </div>

                <div class="field">
                    <label for="ticket_price">Ticket price (ETB)</label>
                    <input type="number" id="ticket_price" name="ticket_price" min="1" step="0.01" value="850.00" required>
                    <span class="field__hint" id="trip-preview"></span>
                </div>

                <div class="field">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn--primary btn--block">+ Publish schedule</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<div class="card card--tint mt-2">
    <p class="card__eyebrow">Operational notes</p>
    <ul class="card__muted" style="margin:0;padding-left:1.1rem">
        <li>Deleting a trip cascades to its bookings — every released seat instantly returns to the public seat map.</li>
        <li>The capacity matrix polls nothing; reload the page to pull the freshest occupancy figures after a booking wave.</li>
        <li>All writes are executed through PDO prepared statements; seat uniqueness is guaranteed by a database-level UNIQUE key.</li>
    </ul>
</div>

<?php lucy_footer(); ?>
