# Lucy Express // ሉሲ ኤክስፕሬስ — Bus Reservation System

A complete, no-login (guest) intercity bus reservation platform for a fictional Ethiopian
carrier operating across **15 stations**. Built natively with **HTML5, CSS3, vanilla
JavaScript and server-side PHP** on a local **XAMPP** (Apache + MySQL/MariaDB) stack — no
frameworks, no build step, no Composer, no npm.

The interface is a **bright "Ethiopian sunrise" theme**: warm ivory paper, white cards, the
**Ethiopian tricolour** (green / yellow / red) driving every state change, telsem-inspired
lattice patterns printed as a soft watermark, bilingual **English + Amharic** labelling, and
**real bus photographs** used as the brand logo mark and the hero image.

---

## 1. What is included

| Area | Highlights |
|---|---|
| **No auth** | No registration, no login, no session gating. Open the site → search → pick a seat → enter name + phone → book. |
| **Routing matrix** | 15 station vector: Addis Ababa, Adama, Hawassa, Bahir Dar, Gondar, Mekelle, Dire Dawa, Harar, Jimma, Dessie, Arba Minch, Sodo, Shashemene, Nekemte, Jigjiga. |
| **Storefront** (`index.php`) | Origin/Destination dropdowns, date picker locked to today-or-later, one-click **Swap Stations**, inline schedule board with live seat availability. |
| **Seat selector** (`select-seats.php`) | CSS-Grid bus floor plan (2 seats │ aisle │ 2 seats), driver steering-wheel block + boarding door anchor, seat states **green = available**, **amber pulsing = selected**, **grey/pointer-events-none = booked** (or **grey/disabled = booked**), plus a live-polling `fetch()` sync loop. |
| **Guest checkout** | Panel appears the moment 1 seat is active, live total cost counter, only **Full Name** + **Mobile Phone**, and a **Confirm Ticket Allocation** button that posts an atomic transaction. |
| **Ticket** (`ticket-success.php`) | Stylised perforated boarding ticket with passenger metadata, seat list, fare math, confirmation code + barcode strip, print stylesheet. |
| **Admin console** (`admin/dashboard.php`) | KPIs (tickets sold, revenue, average fare, low-occupancy warnings), live capacity tracker matrix with occupancy progress bars and click-to-expand occupied-seat mini maps + passenger manifest, fast trip creator, trip deletion and single-berth release. |
| **Race-condition safety** | One InnoDB transaction per checkout: `SELECT … FOR UPDATE` on the trip + its bookings, plus a `UNIQUE (trip_id, seat_number)` key as the database-level backstop. Double bookings return HTTP **409** with the conflicting seats. |

---

## 2. Folder structure

```
xampp/htdocs/lucy-express/
├── config/
│   └── db.php                  # PDO connection (port 3306) + 15-city vector + helpers + layout chrome
├── assets/
│   └── img/
│       ├── bus-mark.jpg        # REAL bus photo, square crop -> circular brand logo emblem
│       ├── bus-coach.jpg       # Ethiopian intercity coach on a highland road -> hero photograph
│       └── hero-bus.jpg        # Addis Ababa coach, wide crop -> fleet card + admin photo band
├── css/
│   └── style.css               # Unified BRIGHT design system (ivory paper / white / flag green + yellow + red)
├── js/
│   └── app.js                  # Vanilla JS: station swap, date guard, seat clicks, pricing, async fetch
├── api/
│   ├── get_booked_seats.php    # GET  ?trip_id=  -> JSON array of occupied seats
│   └── finalize_booking.php    # POST {trip_id, seats[], passenger_name, passenger_phone}
├── admin/
│   └── dashboard.php           # Capacity manager, analytics, trip creator CRUD
├── index.php                   # Public search engine + schedule board
├── select-seats.php            # Interactive seat map + guest checkout panel
├── ticket-success.php          # Confirmation ticket renderer
├── lucy_express.sql            # Database schema + fleet + trip + booking seeds
├── CREDITS.md                  # Image licences + attribution for the bus photography
└── README.md                   # This file
```

---

## 3. Database schema (`lucy_express`)

```
buses     (id PK AI, bus_number VARCHAR(20) UNIQUE, total_seats INT,
           bus_type ENUM('Luxury AC','Standard Non-AC'))

trips     (id PK AI, bus_id FK -> buses.id ON DELETE CASCADE, source VARCHAR(60),
           destination VARCHAR(60), departure_time TIME, arrival_time TIME,
           ticket_price DECIMAL(10,2), travel_date DATE)

bookings  (id PK AI, trip_id FK -> trips.id ON DELETE CASCADE, seat_number INT,
           passenger_name VARCHAR(120), passenger_phone VARCHAR(30),
           confirmation_code VARCHAR(20), booked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
           UNIQUE KEY (trip_id, seat_number))
```

Seeded content:

* **3 fleet assets** — `LUCY-101` (32 seats, Luxury AC), `LUCY-102` (28 seats, Luxury AC), `LUCY-103` (28 seats, Standard Non-AC).
* **24 upcoming departures**, generated relative to `CURDATE()` with `DATE_ADD(CURDATE(), INTERVAL n DAY)`, so they are always in the future whenever you import the dump. Addis Ababa links to Hawassa, Bahir Dar, Gondar, Dire Dawa, Adama, Jimma, Mekelle, Harar, Dessie, Jigjiga, Arba Minch, Nekemte, Sodo and Shashemene, with return legs too.
* **10 sample bookings** across 6 trips, so the dashboard opens with real occupancy and revenue figures.

---

## 4. Local deployment workflow (XAMPP)

### Step 1 — Install and start the stack
1. Install **XAMPP** (PHP 8.0+ recommended; the code targets PHP 8.0–8.3).
2. Open the **XAMPP Control Panel** and press **Start** on **Apache** and **MySQL**. Both rows must turn green.
3. If Apache refuses to start, something else owns port 80/443: change *Config → httpd.conf* (`Listen 80` → `Listen 8080`) and use `http://localhost:8080/lucy-express/` afterwards.

### Step 2 — Drop the project into htdocs
Copy the whole `lucy-express` folder to the web root:

* Windows: `C:\xampp\htdocs\lucy-express\`
* macOS: `/Applications/XAMPP/xamppfiles/htdocs/lucy-express/`
* Linux: `/opt/lampp/htdocs/lucy-express/`

The final path to `index.php` must be `htdocs/lucy-express/index.php`.

### Step 3 — Import the database
1. Visit **http://localhost/phpmyadmin**.
2. Click the **Import** tab (do **not** pre-create the database — the dump creates it).
3. **Choose File** → `lucy_express.sql` → scroll down → **Import**.
4. Confirm the left sidebar now shows `lucy_express` with tables `bookings`, `buses`, `trips`.

CLI alternative:

```bash
# Windows
cd C:\xampp\mysql\bin
mysql -u root < "C:\xampp\htdocs\lucy-express\lucy_express.sql"

# macOS / Linux
/opt/lampp/bin/mysql -u root < /opt/lampp/htdocs/lucy-express/lucy_express.sql
```

### Step 4 — Verify the credentials
`config/db.php` already targets the XAMPP defaults:

```php
DB_HOST = 127.0.0.1   // localhost
DB_PORT = 3306
DB_NAME = lucy_express
DB_USER = root
DB_PASS = ''          // XAMPP's MySQL root password is empty by default
```

If you set a MySQL root password, either edit those constants or export
`LUCY_DB_PASS` before Apache starts. `config/db.php` also reads
`LUCY_DB_HOST`, `LUCY_DB_PORT`, `LUCY_DB_NAME` and `LUCY_DB_USER`.

### Step 5 — Open the application
| Screen | URL |
|---|---|
| Public storefront | `http://localhost/lucy-express/index.php` |
| Seat selector (direct) | `http://localhost/lucy-express/select-seats.php?trip_id=1` |
| Ticket lookup | `http://localhost/lucy-express/ticket-success.php?code=LUCY-A1B2C3` |
| Admin dashboard | `http://localhost/lucy-express/admin/dashboard.php` |
| Seat JSON feed | `http://localhost/lucy-express/api/get_booked_seats.php?trip_id=1` |

### Step 6 — End-to-end smoke test
1. On the storefront pick **Addis Ababa → Hawassa**, choose tomorrow's date, hit **Search Schedules**.
2. Press **⇄ Swap Stations** — the two dropdowns must flip instantly.
3. Click **Choose Seats**, then tap two green seats: the checkout panel slides in, the total counter updates live.
4. Enter a name and a phone such as `0912345678`, press **Confirm Ticket Allocation** → you are redirected to the printed ticket with a `LUCY-XXXXXX` code.
5. Open the **Admin Portal**: tickets sold and revenue must have increased, and the trip row's progress bar must reflect the new seats.
6. Expand the trip row → the mini seat map paints the occupied berths in grey, and the passenger manifest lists your booking.

### Step 7 — Debugging locally
| Symptom | Fix |
|---|---|
| Yellow "MySQL not reachable" banner | MySQL service stopped, or the dump was not imported, or the credentials in `config/db.php` are wrong. |
| `Access denied for user 'root'@'localhost'` | A root password exists — update `DB_PASS` or reset it via `mysqladmin -u root password ''`. |
| `Unknown database 'lucy_express'` | Re-run the import in phpMyAdmin (Step 3). |
| `Port 3306 already in use` | Another MySQL/MariaDB instance is running; stop it or change `DB_PORT` **and** MySQL's `my.ini` port together. |
| Blank white page | Enable PHP errors: in `php.ini` set `display_errors = On` and `error_reporting = E_ALL`, restart Apache, reload. |
| Seat map shows everything as available after a booking | Browser cache — hard reload (`Ctrl/Cmd + Shift + R`); the JSON feed is sent with `Cache-Control: no-store`. |
| Amharic text shows as `????` | Confirm the database collation is `utf8mb4_unicode_ci` (the dump sets it) and that `php.ini` has `default_charset = "UTF-8"`. |
| Booking returns "reserved moments ago" | Working as designed — the race-condition guard blocked a duplicate seat (HTTP 409). Pick another seat. |

Handy checks:

```bash
# Does Apache see your file?
curl -I http://localhost/lucy-express/index.php

# Raw JSON seat feed
curl "http://localhost/lucy-express/api/get_booked_seats.php?trip_id=1"

# Simulate a checkout exactly like the browser does
curl -X POST http://localhost/lucy-express/api/finalize_booking.php \
     -H "Content-Type: application/json" \
     -d '{"trip_id":1,"seats":[20,21],"passenger_name":"Test Guest","passenger_phone":"0911223344"}'
```

---

## 5. How the double-booking guard works

1. `Confirm Ticket Allocation` sends a JSON payload to `api/finalize_booking.php`.
2. The endpoint opens **one** InnoDB transaction.
3. `SELECT … FROM trips … FOR UPDATE` locks the trip row.
4. `SELECT seat_number FROM bookings WHERE trip_id = ? FOR UPDATE` locks every existing berth of that trip, so two simultaneous requests serialise instead of interleaving.
5. Requested seats are diffed against the locked set. Any overlap returns **HTTP 409** with a `conflicts` array; `js/app.js` immediately greys those seats out, releases them from your selection and toasts a warning.
6. If two processes somehow slip past the lock, `UNIQUE KEY uniq_trip_seat (trip_id, seat_number)` rejects the INSERT with SQLSTATE `23000`, which is caught and reported as a 409 as well.

Seat occupancy is re-polled every 15 seconds (`js/app.js`), so abandoned tabs do not sell stale seats.

---

## 6. Security notes for this build

The brief explicitly asks for **no login and no account restrictions**, so every screen —
including `/admin/dashboard.php` — is open by design. Before putting this on a public
network, add at minimum:

1. HTTP Basic or directory auth in front of `admin/` (`.htaccess` + `htpasswd`).
2. CSRF tokens on the dashboard POST forms.
3. Rate limiting on `api/finalize_booking.php` (phone-number based throttle).
4. HTTPS termination and a real payment/deposit flow.
5. Move the DB credentials out of `config/db.php` into environment variables.

---

## 7. Visual identity, theming and image credits

### Palette (all driven by CSS custom properties in `css/style.css`)

| Token | Value | Used for |
|---|---|---|
| Flag green | `#078930` (interactive `#0BA84A`) | Brand, available seats, success, active nav |
| Flag yellow | `#FCDD09` / `#F5C518` | Selected seats, primary buttons, KPI accents |
| Flag red | `#DA121A` | Sold-out, validation errors, danger actions |
| Ivory paper | `#FBF8F1` (`#FFFDF8`) | Page background |
| Pure white | `#FFFFFF` | Every card, table and panel body |
| Warm ink | `#221E17` / `#6B6152` | Body and secondary text |

### Ethiopian design language

* **Tricolour stripe** under the header and along the bottom of the hero photo (`linear-gradient`
  in three exact thirds — green / yellow / red).
* **Telsem-inspired diamond lattice** printed as an inline SVG data-URI watermark on the page body
  and on the `.pattern-band` section separators.
* **Bilingual labelling** throughout — `Lucy Express // ሉሲ ኤክስፕሬስ`, all 15 stations in English
  and Amharic, `ሰላም!` greeting, `እንኳን ደህና መጡ` welcome, and `ጉዞ ደህና` (safe journey) on the seat
  selector and the printed ticket.
* **Real bus photography** — a genuine Ethiopian intercity coach on a highland road as the hero
  image, and a real Addis Ababa coach cropped to a circle as the brand logo in the header and footer.
* **Cultural detail** — a *buna* (coffee) reference on the long highland routes, in the feature band
  and on the ticket.

### Image credits

Both photographs are real, freely licensed and shipped **locally** in `assets/img/` (the app makes
no external requests and works fully offline). Full details are in [CREDITS.md](CREDITS.md):

| File | Source | Author | Licence |
|---|---|---|---|
| `bus-mark.jpg`, `hero-bus.jpg` | [Bus addis abeba](https://commons.wikimedia.org/wiki/File:Bus_addis_abeba.jpg) | Shagada | **Public domain** (PD-self) |
| `bus-coach.jpg` | [Selam bus Ethiopia](https://commons.wikimedia.org/wiki/File:Selam_bus_Ethiopia.jpg) | Radosław Botev / Fundacja Nomos | **CC BY 3.0 PL** — attribution required and already rendered in the site footer |

To swap in your own photography, replace the three files in `assets/img/` keeping the same names —
see CREDITS.md for the recommended dimensions.

---

## 8. Tech notes

* **PHP 8.0+** (typed helpers, arrow functions, `match`-free but modern syntax). No Composer packages.
* **PDO with `ATTR_EMULATE_PREPARES = false`** — every write and read is a genuine server-side prepared statement.
* **`mbstring`** is used for name length validation — enabled by default in XAMPP.
* The storefront degrades gracefully: if MySQL is down, the header renders a setup banner instead of a fatal error.
* Front-end is dependency-free: no jQuery, no Bootstrap, no CDN calls. Works fully offline.
# bus_reservation_system
