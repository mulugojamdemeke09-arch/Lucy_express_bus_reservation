<?php
/**
 * ============================================================================
 *  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — Bus Reservation System
 *  config/db.php
 *  ---------------------------------------------------------------------------
 *  Single shared bootstrap for the whole application. Responsibilities:
 *
 *    1. PDO (MySQL) connection -> local XAMPP engine on port 3306.
 *    2. The 15-city Ethiopian network vector (used for routing + validation).
 *    3. Small reusable helpers: escaping, money/time formatting, trip search,
 *       occupancy maths, booked-seat lookups, confirmation-code generation.
 *    4. The universal utility header / footer chrome.
 *
 *  Every other screen just does:  require_once __DIR__ . '/config/db.php';
 * ============================================================================
 */

/* ---------------------------------------------------------------------------
 * 1. DATABASE CREDENTIALS
 *    XAMPP defaults are pre-wired: host 127.0.0.1, port 3306, user "root" and
 *    an empty password. Export real environment variables to override these
 *    values on any other stack (Docker, MAMP, production VPS...).
 * ------------------------------------------------------------------------- */
define('DB_HOST', getenv('LUCY_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('LUCY_DB_PORT') ?: 3306));
define('DB_NAME', getenv('LUCY_DB_NAME') ?: 'lucy_express');
define('DB_USER', getenv('LUCY_DB_USER') ?: 'root');
define('DB_PASS', getenv('LUCY_DB_PASS') === false ? '' : (string) getenv('LUCY_DB_PASS'));

/* ---------------------------------------------------------------------------
 * 2. BRAND CONSTANTS
 * ------------------------------------------------------------------------- */
define('LUCY_BRAND_EN', 'Lucy Express');
define('LUCY_BRAND_AM', 'ሉሲ ኤክስፕሬስ');
define('LUCY_CURRENCY', 'ETB');

/**
 * Absolute URL base of the project, e.g. "/lucy-express".
 * Computed from the real filesystem location (project root vs. document root)
 * so the same value stays correct for scripts in the root AND in admin/.
 */
function lucy_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $root = str_replace('\\', '/', (string) realpath(__DIR__ . '/..'));
    $doc  = str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));

    if ($doc !== '' && strpos($root, $doc) === 0) {
        $base = rtrim(substr($root, strlen($doc)), '/');
    } else {
        $base = ''; // project dropped straight into htdocs root
    }
    return $base;
}
define('BASE_URL', lucy_base_url());

/* Brand assets (real bus photography shipped inside the project) */
define('LUCY_LOGO_IMG',   '/assets/img/bus-mark.jpg');   // square crop -> header emblem
define('LUCY_HERO_IMG',   '/assets/img/bus-coach.jpg');  // Ethiopian intercity coach on a highland road
define('LUCY_FLEET_IMG',  '/assets/img/hero-bus.jpg');   // Addis Ababa city bus, wide band

/** Absolute URL of a project asset, e.g. "/lucy-express/assets/img/bus-mark.jpg". */
function lucy_asset(string $relative): string
{
    return BASE_URL . '/assets/' . ltrim($relative, '/');
}

/* ---------------------------------------------------------------------------
 * 3. PDO CONNECTION (singleton)
 * ------------------------------------------------------------------------- */

/**
 * The one and only PDO handle for this request. Throws PDOException when the
 * MySQL engine is unreachable or the schema has not been imported yet.
 */
function lucy_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // all errors become exceptions
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // associative arrays everywhere
        PDO::ATTR_EMULATE_PREPARES   => false,                  // genuine server-side prepares
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $pdo;
}

/**
 * Soft connection helper: returns the PDO handle or NULL when the database is
 * unavailable, storing the driver message in $GLOBALS['LUCY_DB_ERROR'] so the
 * layout can render a friendly setup banner instead of a white screen.
 * The public storefront therefore never crashes even before the SQL import.
 */
function lucy_db_safe(): ?PDO
{
    static $attempted = false;
    static $pdo = null;

    if ($attempted) {
        return $pdo;
    }
    $attempted = true;

    try {
        $pdo = lucy_db();
    } catch (Throwable $e) {
        $pdo = null;
        $GLOBALS['LUCY_DB_ERROR'] = $e->getMessage();
    }
    return $pdo;
}

/* ---------------------------------------------------------------------------
 * 4. THE 15-CITY ETHIOPIAN NETWORK
 *    English station name => Amharic label. Used by every dropdown, by the
 *    routing matrix and by server-side validation of posted cities.
 * ------------------------------------------------------------------------- */
function lucy_cities(): array
{
    return [
        'Addis Ababa' => 'አዲስ አበባ',
        'Adama'       => 'አዳማ',
        'Hawassa'     => 'ሀዋሳ',
        'Bahir Dar'   => 'ባህር ዳር',
        'Gondar'      => 'ጎንደር',
        'Mekelle'     => 'መቀለ',
        'Dire Dawa'   => 'ድሬዳዋ',
        'Harar'       => 'ሐረር',
        'Jimma'       => 'ጅማ',
        'Dessie'      => 'ደሴ',
        'Arba Minch'  => 'አርባ ምንጭ',
        'Sodo'        => 'ሶዶ',
        'Shashemene'  => 'ሻሸመኔ',
        'Nekemte'     => 'ነቀምት',
        'Jigjiga'     => 'ጅጅጋ',
    ];
}

/** Flat list of the 15 valid station names (English). */
function lucy_city_list(): array
{
    return array_keys(lucy_cities());
}

/** Human label for a station, e.g. "Hawassa / ሀዋሳ". */
function lucy_city_label(string $city): string
{
    $map = lucy_cities();
    return isset($map[$city]) ? $city . ' / ' . $map[$city] : $city;
}

/** True when the given string is one of the 15 network stations. */
function lucy_is_city(string $city): bool
{
    return array_key_exists($city, lucy_cities());
}

/* ---------------------------------------------------------------------------
 * 5. SMALL FORMAT / SAFETY HELPERS
 * ------------------------------------------------------------------------- */

/** Escape anything that goes back out to the browser. */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 1850.5 => "1,850.50 ETB" */
function lucy_money($amount): string
{
    return number_format((float) $amount, 2) . ' ' . LUCY_CURRENCY;
}

/** "06:30:00" => "06:30" */
function lucy_time(?string $time): string
{
    if (!$time) {
        return '--:--';
    }
    return substr($time, 0, 5);
}

/** "2026-10-12" => "Mon 12 Oct 2026" */
function lucy_date_label(?string $date): string
{
    if (!$date) {
        return '--';
    }
    $ts = strtotime($date);
    return $ts ? date('D d M Y', $ts) : $date;
}

/** "3 h 45 m" travel duration between two TIME strings. */
function lucy_duration(?string $depart, ?string $arrive): string
{
    if (!$depart || !$arrive) {
        return '--';
    }
    $a = strtotime('1970-01-01 ' . $depart);
    $b = strtotime('1970-01-01 ' . $arrive);
    if ($a === false || $b === false) {
        return '--';
    }
    if ($b < $a) {
        $b += 86400; // overnight arrival
    }
    $mins = (int) round(($b - $a) / 60);
    return intdiv($mins, 60) . ' h ' . str_pad((string) ($mins % 60), 2, '0', STR_PAD_LEFT) . ' m';
}

/** Human confirmation code, e.g. "LUCY-7F3A9C". */
function lucy_generate_code(string $prefix = 'LUCY'): string
{
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/* ---------------------------------------------------------------------------
 * 6. TRIP QUERIES
 * ------------------------------------------------------------------------- */

/**
 * Base SELECT shared by every trip listing. The derived table counts sold
 * seats per trip in one pass, so the storefront can show live availability
 * without N+1 queries.
 */
function lucy_trip_select_sql(): string
{
    return "SELECT t.id, t.bus_id, t.source, t.destination, t.departure_time,
                   t.arrival_time, t.ticket_price, t.travel_date,
                   b.bus_number, b.bus_type, b.total_seats,
                   COALESCE(sold.seat_count, 0) AS seats_booked
              FROM trips t
              JOIN buses b ON b.id = t.bus_id
              LEFT JOIN (
                    SELECT trip_id, COUNT(*) AS seat_count
                      FROM bookings
                     GROUP BY trip_id
              ) sold ON sold.trip_id = t.id";
}

/** Search the routing matrix for one source -> destination on one date. */
function lucy_search_trips(PDO $db, string $source, string $destination, string $date): array
{
    $sql = lucy_trip_select_sql()
         . ' WHERE t.source = :src AND t.destination = :dst AND t.travel_date = :dte'
         . ' ORDER BY t.departure_time ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute([':src' => $source, ':dst' => $destination, ':dte' => $date]);
    return $stmt->fetchAll();
}

/** Next upcoming departures across the whole network (storefront teaser). */
function lucy_upcoming_trips(PDO $db, int $limit = 6): array
{
    $sql = lucy_trip_select_sql()
         . ' WHERE t.travel_date >= CURDATE()'
         . ' ORDER BY t.travel_date ASC, t.departure_time ASC'
         . ' LIMIT ' . max(1, $limit);
    return $db->query($sql)->fetchAll();
}

/** One single trip (with bus data + live seat count) or NULL. */
function lucy_find_trip(PDO $db, int $tripId): ?array
{
    $stmt = $db->prepare(lucy_trip_select_sql() . ' WHERE t.id = :id LIMIT 1');
    $stmt->execute([':id' => $tripId]);
    $trip = $stmt->fetch();
    return $trip ?: null;
}

/** All occupied seat numbers of one trip, ascending. */
function lucy_booked_seats(PDO $db, int $tripId): array
{
    $stmt = $db->prepare('SELECT seat_number FROM bookings WHERE trip_id = :id ORDER BY seat_number ASC');
    $stmt->execute([':id' => $tripId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'seat_number'));
}

/** Occupancy percentage (0-100, integer) for a trip row that carries total_seats. */
function lucy_occupancy_pct(array $trip): int
{
    $total = (int) ($trip['total_seats'] ?? 0);
    if ($total <= 0) {
        return 0;
    }
    return (int) round(min(100, ((int) $trip['seats_booked'] / $total) * 100));
}

/* ---------------------------------------------------------------------------
 * 7. UNIVERSAL UTILITY HEADER / FOOTER CHROME
 * ------------------------------------------------------------------------- */

/**
 * Renders <head>, the branding utility header with the nav ribbon, and the
 * opening <main> shell.
 *
 * @param string $title     Browser title suffix.
 * @param string $active    Active nav key: book | seats | success | admin
 * @param string $subtitle  Optional line of context under the brand.
 */
function lucy_header(string $title = 'Book a Ticket', string $active = 'book', string $subtitle = ''): void
{
    $base = BASE_URL;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> &middot; <?= h(LUCY_BRAND_EN) ?> <?= h(LUCY_BRAND_AM) ?></title>
    <meta name="description" content="Lucy Express / ሉሲ ኤክስፕሬስ — Ethiopian intercity bus reservation across 15 stations. Instant guest booking, live seat maps.">
    <link rel="stylesheet" href="<?= h($base) ?>/css/style.css">
</head>
<body>
<header class="util-header">
    <div class="shell util-header__inner">
        <a class="brand" href="<?= h($base) ?>/index.php">
            <!-- Real bus photograph used as the brand logo mark -->
            <span class="brand__mark">
                <img src="<?= h($base . LUCY_LOGO_IMG) ?>"
                     alt="Lucy Express bus" width="54" height="54">
            </span>
            <span class="brand__text">
                <strong><?= h(LUCY_BRAND_EN) ?> // <span lang="am"><?= h(LUCY_BRAND_AM) ?></span></strong>
                <span class="brand__tagline">Intercity reservations &middot; ኢትዮጵያ</span>
            </span>
        </a>

        <nav class="nav-ribbon" aria-label="Primary">
            <a class="nav-ribbon__link <?= $active === 'book' ? 'is-active' : '' ?>" href="<?= h($base) ?>/index.php">Book a Ticket</a>
            <a class="nav-ribbon__link <?= $active === 'seats' ? 'is-active' : '' ?>" href="<?= h($base) ?>/index.php#schedule">Schedules</a>
            <a class="nav-ribbon__link nav-ribbon__link--admin <?= $active === 'admin' ? 'is-active' : '' ?>" href="<?= h($base) ?>/admin/dashboard.php">Admin Portal</a>
        </nav>
    </div>
</header>
<!-- Ethiopian tricolour separator -->
<div class="flag-stripe" aria-hidden="true"></div>

<?php if (!empty($GLOBALS['LUCY_DB_ERROR'])): ?>
    <div class="shell">
        <div class="notice notice--warn" role="alert">
            <strong>MySQL not reachable.</strong>
            The application booted without a database connection. Import
            <code>lucy_express.sql</code> in phpMyAdmin and confirm Apache + MySQL
            are running. Driver said: <code><?= h($GLOBALS['LUCY_DB_ERROR']) ?></code>
        </div>
    </div>
<?php endif; ?>

<main class="shell page">
<?php
    if ($subtitle !== '') {
        echo '<p class="page__lede">' . h($subtitle) . '</p>';
    }
}

/** Closes the page shell, prints the footer and loads the behaviour script. */
function lucy_footer(): void
{
    $base = BASE_URL;
    ?>
</main>

<footer class="site-footer">
    <div class="shell">
        <div class="site-footer__inner">
            <p class="footer-brand">
                <img src="<?= h($base . LUCY_LOGO_IMG) ?>" alt="Lucy Express bus logo" width="34" height="34">
                <span>
                    <strong><?= h(LUCY_BRAND_EN) ?></strong> // <span lang="am"><?= h(LUCY_BRAND_AM) ?></span><br>
                    Intercity reservations across 15 Ethiopian stations &middot; <span lang="am">ጉዞ ደህና</span>
                </span>
            </p>
            <p class="site-footer__meta">
                Local XAMPP build &middot; PHP <?= h(PHP_VERSION) ?> &middot;
                <a href="<?= h($base) ?>/admin/dashboard.php">Operations console</a>
            </p>
        </div>
        <p class="credit-line">
            Bus photography: “Bus addis abeba” by Shagada (public domain) and “Selam bus Ethiopia” by
            Radosław Botev / Fundacja Nomos (CC BY 3.0 PL), both via
            <a href="https://commons.wikimedia.org/wiki/Category:Buses_in_Ethiopia" target="_blank" rel="noopener">Wikimedia Commons</a>.
            See <code>CREDITS.md</code> for full attributions.
        </p>
    </div>
</footer>

<script src="<?= h($base) ?>/js/app.js"></script>
</body>
</html>
<?php
}
