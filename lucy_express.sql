-- ============================================================================
--  LUCY EXPRESS // ሉሲ ኤክስፕሬስ — Bus Reservation System
--  lucy_express.sql  |  plug-and-play schema + seed data for phpMyAdmin
--  ---------------------------------------------------------------------------
--  Import it either way:
--    (A) phpMyAdmin -> Import -> Choose file -> lucy_express.sql -> Import
--    (B) XAMPP shell:  cd C:\xampp\mysql\bin
--                      mysql -u root -p < "C:\xampp\htdocs\lucy-express\lucy_express.sql"
--
--  Charset is utf8mb4 so the Amharic station labels render correctly.
--  Seed trips are generated relative to CURDATE(), so the day you import this
--  file you immediately have a full month of bookable, upcoming departures.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;

CREATE DATABASE IF NOT EXISTS `lucy_express`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `lucy_express`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `bookings`;
DROP TABLE IF EXISTS `trips`;
DROP TABLE IF EXISTS `buses`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Table: buses  (fleet assets)
-- ---------------------------------------------------------------------------
CREATE TABLE `buses` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `bus_number` VARCHAR(20)  NOT NULL,
    `total_seats` INT(11)     NOT NULL,
    `bus_type`   ENUM('Luxury AC','Standard Non-AC') NOT NULL DEFAULT 'Luxury AC',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bus_number` (`bus_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: trips  (one scheduled departure)
-- ---------------------------------------------------------------------------
CREATE TABLE `trips` (
    `id`             INT(11)        NOT NULL AUTO_INCREMENT,
    `bus_id`         INT(11)        NOT NULL,
    `source`         VARCHAR(60)    NOT NULL,
    `destination`    VARCHAR(60)    NOT NULL,
    `departure_time` TIME           NOT NULL,
    `arrival_time`   TIME           NOT NULL,
    `ticket_price`   DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `travel_date`    DATE           NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_trips_bus` (`bus_id`),
    KEY `idx_trips_route` (`source`, `destination`, `travel_date`),
    KEY `idx_trips_date` (`travel_date`),
    CONSTRAINT `fk_trips_bus`
        FOREIGN KEY (`bus_id`) REFERENCES `buses` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Table: bookings  (one row = one allocated berth)
--   uniq_trip_seat is the hard database-level guarantee against
--   double-booking the same seat on the same trip.
-- ---------------------------------------------------------------------------
CREATE TABLE `bookings` (
    `id`                INT(11)      NOT NULL AUTO_INCREMENT,
    `trip_id`           INT(11)      NOT NULL,
    `seat_number`       INT(11)      NOT NULL,
    `passenger_name`    VARCHAR(120) NOT NULL,
    `passenger_phone`   VARCHAR(30)  NOT NULL,
    `confirmation_code` VARCHAR(20)  NOT NULL,
    `booked_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_trip_seat` (`trip_id`, `seat_number`),
    KEY `idx_bookings_code` (`confirmation_code`),
    KEY `idx_bookings_trip` (`trip_id`),
    CONSTRAINT `fk_bookings_trip`
        FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================================================================
--  SEED: 3 baseline fleet assets
-- ===========================================================================
INSERT INTO `buses` (`id`, `bus_number`, `total_seats`, `bus_type`) VALUES
(1, 'LUCY-101', 32, 'Luxury AC'),
(2, 'LUCY-102', 28, 'Luxury AC'),
(3, 'LUCY-103', 28, 'Standard Non-AC');

-- ===========================================================================
--  SEED: upcoming departures on the 15-city network
--  (Addis Ababa links to Hawassa, Bahir Dar, Gondar, Dire Dawa, Adama, Jimma,
--   Mekelle, Harar, Dessie, Jigjiga, Arba Minch, Nekemte, Sodo, Shashemene)
-- ===========================================================================
INSERT INTO `trips`
    (`id`, `bus_id`, `source`, `destination`, `departure_time`, `arrival_time`, `ticket_price`, `travel_date`) VALUES
(1,  1, 'Addis Ababa', 'Hawassa',     '06:30:00', '11:30:00',  850.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(2,  1, 'Hawassa',     'Addis Ababa', '07:00:00', '12:00:00',  850.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(3,  2, 'Addis Ababa', 'Bahir Dar',   '05:45:00', '14:15:00', 1450.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(4,  2, 'Bahir Dar',   'Addis Ababa', '06:00:00', '14:30:00', 1450.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(5,  3, 'Addis Ababa', 'Gondar',      '05:30:00', '15:30:00', 1650.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(6,  3, 'Gondar',      'Addis Ababa', '06:15:00', '16:15:00', 1650.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(7,  1, 'Addis Ababa', 'Dire Dawa',   '06:45:00', '15:00:00', 1150.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(8,  1, 'Dire Dawa',   'Addis Ababa', '07:30:00', '15:45:00', 1150.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(9,  3, 'Addis Ababa', 'Adama',       '08:00:00', '09:30:00',  350.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(10, 3, 'Adama',       'Addis Ababa', '10:00:00', '11:30:00',  350.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(11, 2, 'Addis Ababa', 'Jimma',       '06:00:00', '13:00:00', 1250.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(12, 2, 'Addis Ababa', 'Mekelle',     '05:00:00', '17:00:00', 2100.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(13, 1, 'Addis Ababa', 'Harar',       '07:00:00', '15:30:00', 1050.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
(14, 3, 'Addis Ababa', 'Dessie',      '07:15:00', '13:00:00',  900.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(15, 2, 'Addis Ababa', 'Jigjiga',     '06:30:00', '16:30:00', 1500.00, DATE_ADD(CURDATE(), INTERVAL 5 DAY)),
(16, 3, 'Addis Ababa', 'Arba Minch',  '06:15:00', '13:45:00', 1100.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(17, 1, 'Addis Ababa', 'Nekemte',     '07:00:00', '13:30:00',  950.00, DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
(18, 2, 'Addis Ababa', 'Sodo',        '05:45:00', '13:15:00', 1050.00, DATE_ADD(CURDATE(), INTERVAL 7 DAY)),
(19, 3, 'Addis Ababa', 'Shashemene',  '09:00:00', '12:30:00',  550.00, DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
(20, 1, 'Bahir Dar',   'Gondar',      '09:30:00', '12:30:00',  600.00, DATE_ADD(CURDATE(), INTERVAL 8 DAY)),
(21, 2, 'Hawassa',     'Shashemene',  '08:30:00', '09:45:00',  250.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY)),
(22, 1, 'Addis Ababa', 'Bahir Dar',   '05:45:00', '14:15:00', 1450.00, DATE_ADD(CURDATE(), INTERVAL 9 DAY)),
(23, 3, 'Adama',       'Hawassa',     '07:30:00', '09:15:00',  400.00, DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
(24, 2, 'Mekelle',     'Addis Ababa', '06:30:00', '18:30:00', 2100.00, DATE_ADD(CURDATE(), INTERVAL 12 DAY));

-- ===========================================================================
--  SEED: a handful of live reservations so the dashboard has real occupancy
--  (each confirmation code groups the seats of one guest transaction)
-- ===========================================================================
INSERT INTO `bookings`
    (`trip_id`, `seat_number`, `passenger_name`, `passenger_phone`, `confirmation_code`, `booked_at`) VALUES
(1,  3,  'Abebe Bekele',    '0911234567', 'LUCY-A1B2C3', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  4,  'Sara Tesfaye',    '0912345678', 'LUCY-A1B2C3', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(1,  17, 'Dawit Haile',     '0923456789', 'LUCY-D4E5F6', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(3,  5,  'Hanna Girma',     '0913456789', 'LUCY-7A8B9C', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(3,  6,  'Yonas Alemu',     '0914567890', 'LUCY-7A8B9C', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(5,  9,  'Meron Tadesse',   '0915678901', 'LUCY-C1D2E3', DATE_SUB(NOW(), INTERVAL 20 HOUR)),
(9,  1,  'Kalkidan Bekele', '0916789012', 'LUCY-F4A5B6', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(9,  2,  'Bereket Assefa',  '0917890123', 'LUCY-F4A5B6', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(21, 12, 'Tigist Mengistu', '0918901234', 'LUCY-9E8D7C', DATE_SUB(NOW(), INTERVAL 90 MINUTE)),
(13, 20, 'Fikru Solomon',   '0919012345', 'LUCY-2B3C4D', DATE_SUB(NOW(), INTERVAL 45 MINUTE));

-- ===========================================================================
--  Verification queries (optional — run these to sanity-check the import)
-- ===========================================================================
-- SELECT COUNT(*) AS buses FROM buses;
-- SELECT COUNT(*) AS upcoming_trips FROM trips WHERE travel_date >= CURDATE();
-- SELECT t.id, t.source, t.destination, t.travel_date, COUNT(bk.id) AS seats_booked
--   FROM trips t LEFT JOIN bookings bk ON bk.trip_id = t.id
--  GROUP BY t.id ORDER BY t.travel_date;
-- SELECT source, destination, travel_date, departure_time, ticket_price FROM trips
--  WHERE source = 'Addis Ababa' AND destination = 'Hawassa' AND travel_date >= CURDATE();

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
