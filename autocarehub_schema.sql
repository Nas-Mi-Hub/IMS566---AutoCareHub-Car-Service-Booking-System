-- ============================================================================
-- AutoCare Hub — Complete Merged Database (Schema + Enriched Seed Data)
-- ============================================================================
-- This file merges and supersedes:
--   1. schema.sql                              (base table structure)
--   2. database/schema_v2.1_migration.sql      (parts/inventory/blocked_slots additions)
--   3. install.php  -> seedData()               (base demo accounts, packages, vehicles)
--   4. database/seed_demo_progression.sql       (v1 + v2 progression batches)
--
-- Plus additional enrichment for a fuller, more realistic dataset:
--   - Extra admin, mechanic, and customer accounts
--   - Extra vehicles, service packages, parts/inventory
--   - A longer, chronologically progressing service history (Jan -> Jul 2026)
--   - Matching commissions, notifications, messages, receipts and stock movements
--
-- Run this against an empty MySQL/MariaDB server:
--   mysql -u root -p < autocarehub_full.sql
--
-- Demo login credentials (password_hash is real bcrypt, verified working):
--   Admin:     admin      / Admin@123
--   Admin:     opsmanager / Admin@123
--   Mechanic:  mechanic1  / Mechanic@123   (Hafiz bin Abdullah)
--   Mechanic:  mechanic2  / Mechanic@123   (Kumar a/l Rajan)
--   Mechanic:  mechanic3  / Mechanic@123   (Farid Iskandar)   [new]
--   Customer:  ahmad      / Customer@123
--   Customer:  siti       / Customer@123
--   Customer:  tanwm      / Customer@123
--   Customer:  nurul      / Customer@123   [new]
--   Customer:  raj        / Customer@123   [new]
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS autocare_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE autocare_hub;

-- ============================================================================
-- SECTION 1: SCHEMA
-- ============================================================================

DROP TABLE IF EXISTS receipts;
DROP TABLE IF EXISTS job_parts;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS blocked_slots;
DROP TABLE IF EXISTS service_history;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS contact_submissions;
DROP TABLE IF EXISTS parts;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS service_packages;
DROP TABLE IF EXISTS user_subscriptions;
DROP TABLE IF EXISTS subscription_plans;
DROP TABLE IF EXISTS workshop_settings;
DROP TABLE IF EXISTS users;

-- ─── Users & Auth ───────────────────────────────────────────────
CREATE TABLE users (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL UNIQUE,
    email           VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin','mechanic','customer') NOT NULL DEFAULT 'customer',
    full_name       VARCHAR(120) NOT NULL,
    contact_no      VARCHAR(20)  DEFAULT NULL,
    avatar_color    VARCHAR(7)   DEFAULT '#f97316',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Subscription Plans ───────────────────────────────────────
CREATE TABLE subscription_plans (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(80)  NOT NULL,
    slug            VARCHAR(40)  NOT NULL UNIQUE,
    price_monthly   DECIMAL(10,2) NOT NULL DEFAULT 0,
    max_vehicles    INT          NOT NULL DEFAULT 1,
    max_bookings    INT          NOT NULL DEFAULT 5,
    features        TEXT,
    badge_color     VARCHAR(7)   DEFAULT '#f97316',
    is_popular      TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_subscriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    plan_id         INT          NOT NULL,
    start_date      DATE         NOT NULL,
    end_date        DATE         NOT NULL,
    status          ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES subscription_plans(id)
) ENGINE=InnoDB;

-- ─── Vehicles ─────────────────────────────────────────────────
CREATE TABLE vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    owner_name      VARCHAR(120) NOT NULL,
    contact_no      VARCHAR(20)  NOT NULL,
    plate_no        VARCHAR(15)  NOT NULL UNIQUE,
    brand           VARCHAR(60)  NOT NULL,
    model_variant   VARCHAR(80)  NOT NULL,
    vehicle_type    VARCHAR(30)  NOT NULL DEFAULT 'Sedan',
    year            SMALLINT     DEFAULT NULL,
    current_mileage INT          DEFAULT NULL,
    last_service_mileage INT     DEFAULT NULL,
    last_service_date DATE       DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Service Catalog ──────────────────────────────────────────
CREATE TABLE service_packages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    description     TEXT,
    category        VARCHAR(60)  DEFAULT 'General',
    price           DECIMAL(10,2) NOT NULL,
    duration_mins   INT          DEFAULT 60,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Appointments ─────────────────────────────────────────────
CREATE TABLE appointments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    vehicle_id      INT          NOT NULL,
    package_id      INT          NOT NULL,
    booking_type    ENUM('package','inspection') NOT NULL DEFAULT 'package',
    mechanic_id     INT          DEFAULT NULL,
    appointment_date DATE        NOT NULL,
    time_window     VARCHAR(20)  NOT NULL,
    status          ENUM('Pending','Approved','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Approved',
    notes           TEXT,
    dropoff_mileage INT          DEFAULT NULL,
    quote_amount    DECIMAL(10,2) DEFAULT NULL,
    quote_notes     TEXT,
    quote_status    ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    job_notes       TEXT,
    inspection_photos JSON DEFAULT NULL,
    labor_hours     DECIMAL(5,2) DEFAULT NULL,
    paid_at         DATETIME     DEFAULT NULL,
    payment_method  VARCHAR(30)  DEFAULT NULL,
    payment_proof   VARCHAR(255) DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (package_id) REFERENCES service_packages(id),
    FOREIGN KEY (mechanic_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Service History (Settled Archive) ──────────────────────
CREATE TABLE service_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT          DEFAULT NULL,
    user_id         INT          NOT NULL,
    vehicle_id      INT          NOT NULL,
    package_id      INT          NOT NULL,
    mechanic_id     INT          DEFAULT NULL,
    completion_date DATE         NOT NULL,
    gross_payment   DECIMAL(10,2) NOT NULL,
    commission_amount  DECIMAL(10,2) NOT NULL DEFAULT 0,
    commission_percent DECIMAL(5,2)  NOT NULL DEFAULT 0,
    job_notes       TEXT,
    parts_used      JSON DEFAULT NULL,
    parts_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
    labor_total     DECIMAL(10,2) NOT NULL DEFAULT 0,
    sst_amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
    status          ENUM('Settled','Refunded') NOT NULL DEFAULT 'Settled',
    rating          TINYINT      DEFAULT NULL,
    feedback        TEXT         DEFAULT NULL,
    rated_at        DATETIME     DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
    FOREIGN KEY (package_id) REFERENCES service_packages(id),
    FOREIGN KEY (mechanic_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ─── Parts & Inventory ──────────────────────────────────────
CREATE TABLE parts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    sku             VARCHAR(60)  DEFAULT NULL UNIQUE,
    category        VARCHAR(60)  NOT NULL DEFAULT 'General',
    unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
    cost_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock_qty       INT          NOT NULL DEFAULT 0,
    min_stock       INT          NOT NULL DEFAULT 5,
    supplier        VARCHAR(120) DEFAULT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE job_parts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT          NOT NULL,
    history_id      INT          DEFAULT NULL,
    part_id         INT          NOT NULL,
    qty             INT          NOT NULL DEFAULT 1,
    unit_price_at_time DECIMAL(10,2) NOT NULL DEFAULT 0,
    total           DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_parts_appt (appointment_id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    FOREIGN KEY (part_id) REFERENCES parts(id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    part_id         INT          NOT NULL,
    movement_type   ENUM('in','out','adjustment') NOT NULL DEFAULT 'adjustment',
    qty             INT          NOT NULL,
    balance_after   INT          NOT NULL DEFAULT 0,
    reason          VARCHAR(255) DEFAULT NULL,
    reference_id    INT          DEFAULT NULL,
    created_by      INT          DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_part (part_id),
    FOREIGN KEY (part_id) REFERENCES parts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE blocked_slots (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    block_date      DATE         NOT NULL,
    time_window     VARCHAR(20)  DEFAULT NULL,
    reason          VARCHAR(255) DEFAULT NULL,
    mechanic_id     INT          DEFAULT NULL,
    created_by      INT          DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_blocked_date (block_date)
) ENGINE=InnoDB;

-- ─── Receipts ─────────────────────────────────────────────────
CREATE TABLE receipts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    history_id      INT          NOT NULL,
    receipt_no      VARCHAR(30)  NOT NULL UNIQUE,
    requested_by    INT          NOT NULL,
    generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (history_id) REFERENCES service_history(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─── Notifications ────────────────────────────────────────────
CREATE TABLE notifications (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    title           VARCHAR(120) NOT NULL,
    message         TEXT         NOT NULL,
    type            ENUM('info','success','warning','appointment','receipt','system','alert') NOT NULL DEFAULT 'info',
    link            VARCHAR(255) DEFAULT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Internal Messages ────────────────────────────────────────
CREATE TABLE messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    sender_id       INT          NOT NULL,
    receiver_id     INT          NOT NULL,
    subject         VARCHAR(150) NOT NULL,
    body            TEXT         NOT NULL,
    is_read         TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Contact Submissions ──────────────────────────────────────
CREATE TABLE contact_submissions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(120) NOT NULL,
    phone           VARCHAR(20)  DEFAULT NULL,
    subject         VARCHAR(150) NOT NULL,
    message         TEXT         NOT NULL,
    status          ENUM('new','read','replied') NOT NULL DEFAULT 'new',
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Workshop Settings ────────────────────────────────────────
CREATE TABLE workshop_settings (
    setting_key     VARCHAR(60)  PRIMARY KEY,
    setting_value   VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- ============================================================================
-- SECTION 2: WORKSHOP SETTINGS
-- ============================================================================
INSERT INTO workshop_settings (setting_key, setting_value) VALUES
    ('mechanical_bays', '8'),
    ('electrical_units', '4'),
    ('max_vehicles_per_customer', '5'),
    ('max_bookings_per_month', '10'),
    ('max_bookings_per_slot', '2'),
    ('mechanic_commission_percent', '15'),
    ('service_interval_km', '10000'),
    ('service_interval_months', '6'),
    ('workshop_name', 'AutoCare Hub'),
    ('workshop_address', 'No. 12, Jalan Teknologi 3/1, Taman Sains Selangor, 47810 Petaling Jaya, Selangor'),
    ('workshop_phone', '03-1234 5678'),
    ('workshop_email', 'support@autocarehub.my'),
    ('workshop_bank_name', 'Maybank'),
    ('workshop_bank_account', '514123456789'),
    ('workshop_bank_holder', 'AutoCare Hub Sdn Bhd'),
    ('sst_enabled', '0'),
    ('sst_percent', '6'),
    ('sms_enabled', '0'),
    ('mail_driver', 'mail'),
    ('demo_progression_seeded', '0'),
    ('demo_progression_seeded_v2', '0');

-- ============================================================================
-- SECTION 3: USERS (admins, mechanics, customers)
-- Passwords below are real bcrypt hashes (verified with PHP-compatible
-- password_verify()). Plain-text passwords are documented in the header.
-- ============================================================================
INSERT INTO users (id, username, email, password_hash, role, full_name, contact_no, avatar_color, is_active, created_at) VALUES
    -- Admins
    (1, 'admin',      'admin@autocarehub.my',      '$2b$10$1JvBEwPmEFbVW95Sabw47.kiJUw/Iq1CvZu4X77fD70Kqi2l.5Tny', 'admin',    'System Administrator',   '019-0000001', '#f97316', 1, '2025-11-01 08:00:00'),
    (2, 'mechanic1',  'mechanic@autocarehub.my',   '$2b$10$NsxR6zikFmrEwsWB6xoKP.DKvnzHD7FK85hJMbhoV8DmOKJ9GpL82', 'mechanic', 'Hafiz bin Abdullah',     '012-1111222', '#22c55e', 1, '2025-11-02 08:30:00'),
    (3, 'mechanic2',  'mechanic2@autocarehub.my',  '$2b$10$NsxR6zikFmrEwsWB6xoKP.DKvnzHD7FK85hJMbhoV8DmOKJ9GpL82', 'mechanic', 'Kumar a/l Rajan',        '013-3333444', '#3b82f6', 1, '2025-11-02 09:00:00'),
    (4, 'ahmad',      'ahmad@email.com',           '$2b$10$GLveIz3vHjlRxzuEXNGScONCC3tkBqbqe5xnpsSC0jO20ixheuyFS', 'customer', 'Ahmad Razak bin Ismail', '012-3456789', '#f97316', 1, '2025-12-01 10:15:00'),
    (5, 'siti',       'siti@email.com',            '$2b$10$GLveIz3vHjlRxzuEXNGScONCC3tkBqbqe5xnpsSC0jO20ixheuyFS', 'customer', 'Siti Nurhaliza binti Omar','013-9876543', '#ec4899', 1, '2025-12-03 11:20:00'),
    (6, 'tanwm',      'tan@email.com',             '$2b$10$GLveIz3vHjlRxzuEXNGScONCC3tkBqbqe5xnpsSC0jO20ixheuyFS', 'customer', 'Tan Wei Ming',           '016-2233445', '#8b5cf6', 1, '2025-12-05 14:40:00'),
    -- Enrichment: additional accounts for a fuller, more realistic dataset
    (7, 'mechanic3',  'mechanic3@autocarehub.my',  '$2b$10$NsxR6zikFmrEwsWB6xoKP.DKvnzHD7FK85hJMbhoV8DmOKJ9GpL82', 'mechanic', 'Farid Iskandar bin Zulkifli','017-5556677', '#06b6d4', 1, '2026-01-15 09:00:00'),
    (8, 'opsmanager', 'ops@autocarehub.my',        '$2b$10$1JvBEwPmEFbVW95Sabw47.kiJUw/Iq1CvZu4X77fD70Kqi2l.5Tny', 'admin',    'Nor Aina binti Yusof',   '019-8887766', '#eab308', 1, '2026-01-20 09:00:00'),
    (9, 'nurul',      'nurul@email.com',           '$2b$10$GLveIz3vHjlRxzuEXNGScONCC3tkBqbqe5xnpsSC0jO20ixheuyFS', 'customer', 'Nurul Ain binti Hashim', '014-2221100', '#14b8a6', 1, '2026-02-10 13:05:00'),
    (10,'raj',        'raj@email.com',             '$2b$10$GLveIz3vHjlRxzuEXNGScONCC3tkBqbqe5xnpsSC0jO20ixheuyFS', 'customer', 'Rajesh a/l Muniandy',    '011-9998877', '#f43f5e', 1, '2026-03-02 16:50:00');

-- ============================================================================
-- SECTION 4: SUBSCRIPTION PLANS
-- ============================================================================
INSERT INTO subscription_plans (id, name, slug, price_monthly, max_vehicles, max_bookings, features, badge_color, is_popular, sort_order) VALUES
    (1, 'Basic',      'basic',      0.00,  1, 3,  'Book services online,Basic service history,Email notifications',                                  '#94a3b8', 0, 1),
    (2, 'Plus',       'plus',       19.90, 3, 10, 'Priority booking slots,Full service history,SMS + email notifications,Discounted parts pricing',    '#f97316', 1, 2),
    (3, 'Premium',    'premium',    39.90, 5, 25, 'Dedicated mechanic requests,Free annual inspection,Priority support,Loyalty rewards,Fleet reporting','#8b5cf6', 0, 3);

INSERT INTO user_subscriptions (user_id, plan_id, start_date, end_date, status) VALUES
    (4, 2, '2026-01-01', '2026-12-31', 'active'),
    (5, 3, '2026-01-15', '2026-12-31', 'active'),
    (6, 1, '2026-02-01', '2026-12-31', 'active'),
    (9, 2, '2026-02-10', '2026-12-31', 'active'),
    (10,1, '2026-03-02', '2026-12-31', 'active');

-- ============================================================================
-- SECTION 5: SERVICE PACKAGES
-- ============================================================================
INSERT INTO service_packages (id, name, description, category, price, duration_mins) VALUES
    (1, 'Tukar Minyak Enjin',        'Full engine oil & filter change',            'Maintenance', 120.00, 45),
    (2, 'Brake Service Overhaul',    'Brake pad replacement & fluid check',        'Safety',      280.00, 120),
    (3, 'Aircond Service Tuning',    'Gas refill, coil clean, blower check',       'Electrical',  150.00, 90),
    (4, 'Alignment Tayar',           '4-wheel alignment & balancing',              'Tyres',        50.00, 30),
    (5, 'Full Service Package',      'Comprehensive 10-point inspection',          'Maintenance', 350.00, 180),
    (6, 'Battery Replacement',       'New battery install & terminal clean',       'Electrical',  180.00, 30),
    (7, 'Suspension Check & Repair', 'Shock absorber & linkage inspection',        'Mechanical',  220.00, 90),
    -- Enrichment: additional packages
    (8, 'Timing Belt Replacement',   'Timing belt, tensioner & water pump change', 'Mechanical',  480.00, 240),
    (9, 'Transmission Fluid Service','ATF/manual gearbox oil flush & refill',      'Maintenance', 260.00, 90),
    (10,'Pre-Purchase Inspection',   '50-point inspection report for used cars',   'Inspection',  150.00, 60);

-- ============================================================================
-- SECTION 6: VEHICLES
-- Original fleet (install.php) + progression fleet (seed v1/v2) + enrichment
-- ============================================================================
INSERT INTO vehicles (id, user_id, owner_name, contact_no, plate_no, brand, model_variant, vehicle_type, year, current_mileage, last_service_mileage, last_service_date, created_at) VALUES
    -- Base fleet
    (1, 4, 'Ahmad Razak bin Ismail',    '012-3456789', 'WXY 1234', 'Proton',    'Saga Premium 1.3',   'Sedan',     2020, 45200, 35000, '2026-01-05', '2025-12-01 10:20:00'),
    (2, 5, 'Siti Nurhaliza binti Omar', '013-9876543', 'BJK 5678', 'Perodua',   'Myvi 1.5 AV',        'Hatchback', 2021, 32100, 22000, '2026-02-12', '2025-12-03 11:25:00'),
    (3, 6, 'Tan Wei Ming',              '016-2233445', 'PKN 9012', 'Honda',     'City VTEC 1.5',      'Sedan',     2019, 61000, 51000, '2025-12-18', '2025-12-05 14:45:00'),
    (4, 4, 'Ahmad Razak bin Ismail',    '012-3456789', 'JHR 3456', 'Toyota',    'Vios 1.5G',          'Sedan',     2022, 28000, NULL,  NULL,         '2025-12-01 10:22:00'),
    (5, 5, 'Siti Nurhaliza binti Omar', '013-9876543', 'SEL 7890', 'Proton',    'X50 1.5T Flagship',  'SUV',       2023, 18500, 8500,  '2026-03-28', '2025-12-03 11:27:00'),
    (6, 6, 'Lee Kok Wai',               '011-6677889', 'PEN 2468', 'Perodua',   'Bezza 1.3 X',        'Sedan',     2021, 42000, NULL,  NULL,         '2025-12-05 14:47:00'),
    -- Progression v1
    (7, 4, 'Ahmad Razak bin Ismail',    '012-3456789', 'WNA3596',  'Mazda',     'CX-3 Skyactiv',      'SUV',       2020, 40200, 30000, '2026-04-01', '2026-01-10 09:00:00'),
    (8, 5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 5501', 'Toyota',    'Yaris',              'Hatchback', 2020, 41000, 31000, '2026-05-01', '2026-01-12 09:00:00'),
    (9, 6, 'Tan Wei Ming',              '016-2233445', 'ACH 6601', 'Mazda',     'CX-5',               'SUV',       2019, 68000, 58000, '2026-02-20', '2026-01-14 09:00:00'),
    (10,4, 'Ahmad Razak bin Ismail',    '012-3456789', 'ACH 8801', 'Perodua',   'Myvi 1.5 AV',        'Hatchback', 2022, 38500, 28000, '2026-04-10', '2026-02-01 09:00:00'),
    (11,4, 'Ahmad Razak bin Ismail',    '012-3456789', 'ACH 8802', 'Honda',     'City RS',            'Sedan',     2021, 52000, 42000, '2026-03-15', '2026-02-03 09:00:00'),
    -- Progression v2
    (12,4, 'Ahmad Razak bin Ismail',    '012-3456789', 'ACH 9101', 'Proton',    'X50 1.5T Premium',   'SUV',       2023, 21000, 11000, '2026-06-01', '2026-04-01 09:00:00'),
    (13,4, 'Ahmad Razak bin Ismail',    '012-3456789', 'ACH 9102', 'Toyota',    'Corolla Altis',      'Sedan',     2022, 33500, 23000, '2026-05-20', '2026-04-02 09:00:00'),
    (14,5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 9201', 'Honda',     'HR-V e:HEV',         'SUV',       2023, 19800, 9800,  '2026-06-10', '2026-04-05 09:00:00'),
    (15,5, 'Siti Nurhaliza binti Omar', '013-9876543', 'ACH 9202', 'Perodua',   'Ativa AV',           'SUV',       2022, 26400, 16400, '2026-04-28', '2026-04-06 09:00:00'),
    (16,6, 'Tan Wei Ming',              '016-2233445', 'ACH 9301', 'Nissan',    'X-Trail',            'SUV',       2021, 47000, 37000, '2026-03-30', '2026-04-08 09:00:00'),
    (17,6, 'Tan Wei Ming',              '016-2233445', 'ACH 9302', 'Mitsubishi','Xpander',            'MPV',       2020, 55200, 45200, '2026-02-14', '2026-04-09 09:00:00'),
    -- Enrichment: new customers' vehicles
    (18,9, 'Nurul Ain binti Hashim',    '014-2221100', 'SGR 1010', 'Honda',     'HR-V 1.8',           'SUV',       2020, 47500, 37000, '2026-04-15', '2026-02-10 13:10:00'),
    (19,9, 'Nurul Ain binti Hashim',    '014-2221100', 'SGR 1011', 'Perodua',   'Axia SE',            'Hatchback', 2021, 25400, 15400, '2026-05-08', '2026-02-11 13:15:00'),
    (20,10,'Rajesh a/l Muniandy',       '011-9998877', 'WPL 2020', 'Toyota',    'Hilux Rogue',        'Pickup',    2022, 39000, 29000, '2026-06-05', '2026-03-02 16:55:00');

-- ============================================================================
-- SECTION 7: PARTS / INVENTORY
-- Base catalog + enrichment items, with post-usage stock levels applied
-- ============================================================================
INSERT INTO parts (id, name, sku, category, unit_price, cost_price, stock_qty, min_stock, supplier, is_active) VALUES
    (1, 'Engine Oil 5W-30 (4L)',    'OIL-5W30-4L', 'Lubricants',   95.00,  65.00, 57, 10, 'Petronas', 1),
    (2, 'Oil Filter',               'FLT-OIL-UNI', 'Filters',      25.00,  12.00, 42, 10, 'Bosch',    1),
    (3, 'Air Filter',                'FLT-AIR-UNI', 'Filters',      35.00,  18.00, 22,  8, 'Bosch',    1),
    (4, 'Brake Pad Set (Front)',     'BRK-PAD-F',   'Brakes',      180.00, 110.00, 11,  4, 'Brembo',   1),
    (5, 'Spark Plug (Iridium)',      'SPK-IRD',     'Ignition',     45.00,  28.00, 30,  8, 'NGK',      1),
    (6, 'Coolant 1L',                'CLN-1L',      'Fluids',       22.00,  12.00, 19,  6, 'Prestone', 1),
    (7, 'Wiper Blade 22"',           'WIP-22',      'Accessories',  30.00,  15.00, 16,  5, 'Bosch',    1),
    (8, 'Battery 60Ah',              'BAT-60',      'Electrical',  320.00, 240.00,  2,  2, 'Century',  1),
    -- Enrichment: additional inventory
    (9, 'Timing Belt Kit',           'TIM-BELT-KIT','Engine',      220.00, 140.00, 10,  3, 'Gates',    1),
    (10,'ATF Transmission Fluid 1L', 'ATF-1L',      'Fluids',       38.00,  22.00, 24,  6, 'Castrol',  1),
    (11,'Cabin Air Filter',          'FLT-CABIN',   'Filters',      28.00,  14.00, 26,  6, 'Denso',    1),
    (12,'Brake Fluid DOT4 500ml',    'BRK-FLU-DOT4','Fluids',       18.00,   9.00, 30,  8, 'Prestone', 1),
    (13,'Wheel Alignment Weight Set','WHL-WGT-SET', 'Tyres',        12.00,   5.00, 60, 15, 'Hunter',   1);

-- ============================================================================
-- SECTION 8: APPOINTMENTS
-- Base pipeline (install.php) + progression completed jobs + upcoming pipeline
-- Ordered to show clear chronological progression from Jan to beyond today.
-- ============================================================================
INSERT INTO appointments
  (id, user_id, vehicle_id, package_id, booking_type, mechanic_id, appointment_date, time_window, status, notes,
   quote_amount, quote_notes, quote_status, paid_at, payment_method, dropoff_mileage, job_notes, created_at) VALUES
    -- Completed, early in the year (v1 progression trail)
    (1,  4, 1,  1, 'package', 2, '2026-05-18', '09:00 AM', 'Completed', 'Oil change',      120.00, 'Oil + filter',            'approved', '2026-05-17 10:00:00', 'online_banking', 44800, 'Done',           '2026-05-15 09:00:00'),
    (2,  4, 4,  5, 'package', 3, '2026-06-15', '10:30 AM', 'Completed', 'Full service',    350.00, 'Package full',            'approved', '2026-06-14 09:00:00', 'cash',           27500, 'All OK',         '2026-06-12 09:00:00'),
    (3,  4, 10, 2, 'package', 2, '2026-06-03', '02:00 PM', 'Completed', 'Brakes',          280.00, 'Pads front',              'approved', '2026-06-02 11:00:00', 'qr_code',        38000, 'Pads replaced',  '2026-06-01 09:00:00'),
    (4,  4, 1,  3, 'package', 3, '2026-07-05', '09:00 AM', 'Completed', 'AC',              150.00, 'Gas top-up',              'approved', '2026-07-04 16:00:00', 'online_banking', 45100, 'AC cold',        '2026-07-02 09:00:00'),
    (5,  5, 8,  5, 'package', 2, '2026-07-02', '10:30 AM', 'Completed', 'Full',            350.00, 'Full pkg',                'approved', '2026-07-01 12:00:00', 'online_banking', 18500, 'Done',           '2026-06-29 09:00:00'),
    (6,  6, 9,  1, 'package', 3, '2026-07-01', '12:00 PM', 'Completed', 'Oil',             120.00, 'Oil',                     'approved', '2026-06-30 10:00:00', 'cash',           67800, 'Done',           '2026-06-28 09:00:00'),
    -- Completed, v2 progression trail (more recent)
    (7,  4, 12, 5, 'package', 2, '2026-07-09', '09:00 AM', 'Completed', 'Full service',    350.00, 'Full pkg',                'approved', '2026-07-08 09:00:00', 'online_banking', 21400, 'Done',           '2026-07-06 09:00:00'),
    (8,  5, 14, 1, 'package', 2, '2026-07-04', '10:00 AM', 'Completed', 'Oil change',      120.00, 'Oil + filter',            'approved', '2026-07-03 08:30:00', 'cash',           19900, 'Done',           '2026-07-01 09:00:00'),
    (9,  6, 16, 2, 'package', 3, '2026-07-06', '01:00 PM', 'Completed', 'Brakes',          280.00, 'Pads front',              'approved', '2026-07-05 11:00:00', 'qr_code',        47500, 'Pads replaced',  '2026-07-03 09:00:00'),
    (10, 4, 13, 3, 'package', 3, '2026-07-11', '03:00 PM', 'Completed', 'AC service',      150.00, 'Gas top-up',              'approved', '2026-07-10 09:00:00', 'online_banking', 34000, 'AC cold',        '2026-07-08 09:00:00'),
    -- Enrichment: completed jobs for new customers (Nurul, Raj) and mechanic3
    (11, 9, 18, 8, 'package', 7, '2026-06-20', '09:00 AM', 'Completed', 'Timing belt due', 480.00, 'Belt + tensioner + pump', 'approved', '2026-06-19 09:00:00', 'online_banking', 47500, 'Replaced OK',    '2026-06-17 09:00:00'),
    (12, 9, 19, 1, 'package', 2, '2026-07-02', '10:00 AM', 'Completed', 'Oil change',      120.00, 'Oil + filter',            'approved', '2026-07-01 09:00:00', 'cash',           15400, 'Done',           '2026-06-29 09:00:00'),
    (13, 10,20, 9, 'package', 7, '2026-07-07', '11:00 AM', 'Completed', 'Gearbox service', 260.00, 'ATF flush & refill',      'approved', '2026-07-06 09:00:00', 'qr_code',        29500, 'Smooth shifting','2026-07-04 09:00:00'),
    -- Active pipeline (not yet completed) — near "today" 2026-07-12
    (14, 4, 12, 6, 'package', NULL, '2026-07-13', '11:00 AM', 'Approved',    'Battery check',    NULL,  NULL,               'pending',  NULL, NULL, NULL, NULL, '2026-07-11 09:00:00'),
    (15, 5, 15, 7, 'package', 3,    '2026-07-14', '01:30 PM', 'Approved',    'Suspension noise', 235.00,'Bushing + linkage', 'approved', NULL, NULL, NULL, NULL, '2026-07-11 09:30:00'),
    (16, 6, 17, 4, 'package', NULL, '2026-07-15', '09:30 AM', 'Approved',    'Alignment',         NULL,  NULL,               'pending',  NULL, NULL, NULL, NULL, '2026-07-11 10:00:00'),
    (17, 4, 13, 2, 'package', 2,    '2026-07-12', '04:00 PM', 'In Progress', 'Brake pads worn',  280.00,'Pads all round',     'approved', '2026-07-11 09:00:00', 'cash', 34400, 'Working now', '2026-07-10 09:00:00'),
    (18, 4, 11, 2, 'package', 2,    '2026-07-15', '10:30 AM', 'Approved',    'Brake check',      295.00,'Pads + fluid RM295', 'approved', NULL, NULL, NULL, NULL, '2026-07-12 08:00:00'),
    (19, 5, 2,  4, 'package', 3,    '2026-07-16', '09:00 AM', 'Approved',    'Alignment',         55.00,'4-wheel',            'approved', '2026-07-11 09:00:00', 'qr_code', NULL, NULL, '2026-07-12 08:10:00'),
    (20, 6, 3,  7, 'package', 2,    '2026-07-17', '05:00 PM', 'Approved',    'Suspension',       240.00,'Bushing check',      'approved', NULL, NULL, NULL, NULL, '2026-07-12 08:20:00'),
    (21, 9, 18,10, 'inspection', 7, '2026-07-18', '02:00 PM', 'Pending',     'Pre-purchase check for family car', NULL, NULL, 'none', NULL, NULL, NULL, NULL, '2026-07-12 09:00:00'),
    (22, 10,20, 6, 'package', NULL, '2026-07-20', '10:30 AM', 'Pending',     'Battery weak in mornings', NULL, NULL, 'none',  NULL, NULL, NULL, NULL, '2026-07-12 09:10:00');

-- ============================================================================
-- SECTION 9: SERVICE HISTORY (settled archive — drives revenue/commission reports)
-- Chronological progression Jan 2026 -> Jul 2026, 15% commission rate throughout
-- ============================================================================
INSERT INTO service_history
  (appointment_id, user_id, vehicle_id, package_id, mechanic_id, completion_date, gross_payment, commission_amount, commission_percent, rating, feedback, status, labor_total, parts_total, created_at) VALUES
    -- January
    (NULL, 4, 1, 1, 2, '2026-01-08', 120.00, 18.00, 15.00, 5, 'Minyak enjin licin',        'Settled', 120.00, 0.00, '2026-01-08 12:00:00'),
    (NULL, 6, 3, 2, 3, '2026-01-18', 280.00, 42.00, 15.00, 4, NULL,                         'Settled', 280.00, 0.00, '2026-01-18 12:00:00'),
    (NULL, 4, 1, 3, 2, '2026-01-22', 150.00, 22.50, 15.00, 4, NULL,                         'Settled', 150.00, 0.00, '2026-01-22 12:00:00'),
    (NULL, 5, 2, 1, 2, '2026-01-12', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-01-12 12:00:00'),
    -- February
    (NULL, 4, 4, 5, 3, '2026-02-05', 350.00, 52.50, 15.00, 5, 'Full service tip-top',       'Settled', 350.00, 0.00, '2026-02-05 12:00:00'),
    (NULL, 4, 1, 2, 2, '2026-02-18', 280.00, 42.00, 15.00, 5, 'Brake bagus',                'Settled', 280.00, 0.00, '2026-02-18 12:00:00'),
    (NULL, 6, 3, 1, 2, '2026-02-25', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-02-25 12:00:00'),
    (NULL, 5, 2, 5, 3, '2026-02-20', 350.00, 52.50, 15.00, 5, 'Sangat puas hati',           'Settled', 350.00, 0.00, '2026-02-20 12:00:00'),
    -- March
    (NULL, 4, 4, 4, 3, '2026-03-02',  50.00,  7.50, 15.00, 4, NULL,                         'Settled',  50.00, 0.00, '2026-03-02 12:00:00'),
    (NULL, 4, 7, 1, 2, '2026-03-15', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-03-15 12:00:00'),
    (NULL, 4, 1, 6, 3, '2026-03-28', 180.00, 27.00, 15.00, 4, 'Battery baru OK',            'Settled', 180.00, 0.00, '2026-03-28 12:00:00'),
    (NULL, 5, 2, 2, 2, '2026-03-10', 280.00, 42.00, 15.00, 4, NULL,                         'Settled', 280.00, 0.00, '2026-03-10 12:00:00'),
    (NULL, 6, 3, 5, 3, '2026-03-20', 350.00, 52.50, 15.00, 5, NULL,                         'Settled', 350.00, 0.00, '2026-03-20 12:00:00'),
    -- April
    (NULL, 4, 10,5, 2, '2026-04-10', 350.00, 52.50, 15.00, 5, NULL,                         'Settled', 350.00, 0.00, '2026-04-10 12:00:00'),
    (NULL, 4, 4, 3, 2, '2026-04-22', 150.00, 22.50, 15.00, 5, 'AC sejuk',                   'Settled', 150.00, 0.00, '2026-04-22 12:00:00'),
    (NULL, 5, 8, 3, 3, '2026-04-05', 150.00, 22.50, 15.00, 5, NULL,                         'Settled', 150.00, 0.00, '2026-04-05 12:00:00'),
    (NULL, 6, 9, 7, 2, '2026-04-18', 220.00, 33.00, 15.00, 4, NULL,                         'Settled', 220.00, 0.00, '2026-04-18 12:00:00'),
    (NULL, 9, 18,8, 7, '2026-04-15', 480.00, 72.00, 15.00, 5, 'Very thorough job',          'Settled', 480.00, 0.00, '2026-04-15 12:00:00'),
    -- May
    (NULL, 4, 11,7, 3, '2026-05-05', 220.00, 33.00, 15.00, 4, NULL,                         'Settled', 220.00, 0.00, '2026-05-05 12:00:00'),
    (1,    4, 1, 1, 2, '2026-05-18', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-05-18 12:00:00'),
    (NULL, 5, 8, 6, 2, '2026-05-14', 180.00, 27.00, 15.00, 4, NULL,                         'Settled', 180.00, 0.00, '2026-05-14 12:00:00'),
    (NULL, 6, 9, 3, 3, '2026-05-22', 150.00, 22.50, 15.00, 5, NULL,                         'Settled', 150.00, 0.00, '2026-05-22 12:00:00'),
    (NULL, 9, 19,5, 7, '2026-05-08', 350.00, 52.50, 15.00, 5, 'Great communication',        'Settled', 350.00, 0.00, '2026-05-08 12:00:00'),
    -- June
    (3,    4, 10,2, 2, '2026-06-03', 280.00, 42.00, 15.00, 5, 'Recommended',                'Settled', 280.00, 0.00, '2026-06-03 12:00:00'),
    (2,    4, 4, 5, 3, '2026-06-15', 350.00, 52.50, 15.00, 5, NULL,                         'Settled', 350.00, 0.00, '2026-06-15 12:00:00'),
    (NULL, 4, 11,1, 2, '2026-06-28', 120.00, 18.00, 15.00, 4, NULL,                         'Settled', 120.00, 0.00, '2026-06-28 12:00:00'),
    (NULL, 5, 2, 1, 3, '2026-06-08', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-06-08 12:00:00'),
    (NULL, 6, 9, 4, 2, '2026-06-10',  50.00,  7.50, 15.00, 4, NULL,                         'Settled',  50.00, 0.00, '2026-06-10 12:00:00'),
    (NULL, 9, 19,1, 2, '2026-07-02', 120.00, 18.00, 15.00, 4, NULL,                         'Settled', 120.00, 0.00, '2026-06-25 12:00:00'),
    -- July (most recent, drives "this month" dashboard figures)
    (NULL, 4, 1, 3, 3, '2026-07-05', 150.00, 22.50, 15.00, 5, 'Quick service',              'Settled', 150.00, 0.00, '2026-07-05 12:00:00'),
    (NULL, 4, 7, 4, 2, '2026-07-08',  50.00,  7.50, 15.00, NULL, NULL,                      'Settled',  50.00, 0.00, '2026-07-08 12:00:00'),
    (5,    5, 8, 5, 2, '2026-07-02', 350.00, 52.50, 15.00, 5, 'Best bengkel',               'Settled', 350.00, 0.00, '2026-07-02 12:00:00'),
    (6,    6, 9, 1, 3, '2026-07-01', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-07-01 12:00:00'),
    (NULL, 6, 16,2, 2, '2026-01-25', 280.00, 42.00, 15.00, 4, NULL,                         'Settled', 280.00, 0.00, '2026-01-25 12:00:00'),
    (NULL, 6, 17,5, 3, '2026-02-19', 350.00, 52.50, 15.00, 5, NULL,                         'Settled', 350.00, 0.00, '2026-02-19 12:00:00'),
    (NULL, 6, 16,1, 2, '2026-03-12', 120.00, 18.00, 15.00, 5, 'Puas hati',                  'Settled', 120.00, 0.00, '2026-03-12 12:00:00'),
    (NULL, 6, 17,7, 3, '2026-04-22', 220.00, 33.00, 15.00, 4, NULL,                         'Settled', 220.00, 0.00, '2026-04-22 12:00:00'),
    (NULL, 6, 16,6, 2, '2026-05-16', 180.00, 27.00, 15.00, 5, NULL,                         'Settled', 180.00, 0.00, '2026-05-16 12:00:00'),
    (NULL, 6, 17,3, 3, '2026-06-24', 150.00, 22.50, 15.00, 5, 'AC sejuk gila',              'Settled', 150.00, 0.00, '2026-06-24 12:00:00'),
    (NULL, 6, 16,1, 2, '2026-07-06', 120.00, 18.00, 15.00, 4, NULL,                         'Settled', 120.00, 0.00, '2026-07-06 12:00:00'),
    (NULL, 6, 17,4, 3, '2026-07-11',  50.00,  7.50, 15.00, 5, NULL,                         'Settled',  50.00, 0.00, '2026-07-11 12:00:00'),
    (7,    4, 12,5, 2, '2026-07-09', 350.00, 52.50, 15.00, 5, NULL,                         'Settled', 350.00, 0.00, '2026-07-09 12:00:00'),
    (8,    5, 14,1, 2, '2026-07-04', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-07-04 12:00:00'),
    (9,    6, 16,2, 3, '2026-07-06', 280.00, 42.00, 15.00, 4, NULL,                         'Settled', 280.00, 0.00, '2026-07-06 12:00:00'),
    (10,   4, 13,3, 3, '2026-07-11', 150.00, 22.50, 15.00, 5, 'AC cold, fast turnaround',   'Settled', 150.00, 0.00, '2026-07-11 12:00:00'),
    (11,   9, 18,8, 7, '2026-06-20', 480.00, 72.00, 15.00, 5, 'Excellent, on time',         'Settled', 480.00, 0.00, '2026-06-20 12:00:00'),
    (12,   9, 19,1, 2, '2026-07-02', 120.00, 18.00, 15.00, 5, NULL,                         'Settled', 120.00, 0.00, '2026-07-02 12:00:00'),
    (13,   10,20,9, 7, '2026-07-07', 260.00, 39.00, 15.00, 4, 'Good value',                 'Settled', 260.00, 0.00, '2026-07-07 12:00:00');

-- ============================================================================
-- SECTION 10: JOB PARTS (parts consumed per completed job)
-- ============================================================================
INSERT INTO job_parts (appointment_id, history_id, part_id, qty, unit_price_at_time, total, created_at) VALUES
    (1,  20, 1, 1, 95.00, 95.00,  '2026-05-18 12:00:00'),
    (1,  20, 2, 1, 25.00, 25.00,  '2026-05-18 12:00:00'),
    (2,  25, 2, 1, 25.00, 25.00,  '2026-06-15 12:00:00'),
    (3,  24, 4, 2, 180.00,360.00, '2026-06-03 12:00:00'),
    (4,  30, 6, 1, 22.00, 22.00,  '2026-07-05 12:00:00'),
    (5,  32, 3, 1, 35.00, 35.00,  '2026-07-02 12:00:00'),
    (5,  32, 5, 2, 45.00, 90.00,  '2026-07-02 12:00:00'),
    (6,  33, 1, 1, 95.00, 95.00,  '2026-07-01 12:00:00'),
    (7,  44, 5, 2, 45.00, 90.00,  '2026-07-09 12:00:00'),
    (7,  44, 3, 1, 35.00, 35.00,  '2026-07-09 12:00:00'),
    (8,  45, 1, 1, 95.00, 95.00,  '2026-07-04 12:00:00'),
    (8,  45, 2, 1, 25.00, 25.00,  '2026-07-04 12:00:00'),
    (9,  46, 4, 2, 180.00,360.00, '2026-07-06 12:00:00'),
    (10, 47, 6, 1, 22.00, 22.00,  '2026-07-11 12:00:00'),
    (10, 47, 11,1, 28.00, 28.00,  '2026-07-11 12:00:00'),
    (11, 48, 9, 1, 220.00,220.00, '2026-06-20 12:00:00'),
    (13, 48, 10,4, 38.00, 152.00, '2026-07-07 12:00:00'),
    (17, NULL,4, 2, 180.00,360.00,'2026-07-12 09:00:00'),
    (17, NULL,12,1, 18.00, 18.00, '2026-07-12 09:00:00');

-- ============================================================================
-- SECTION 11: STOCK MOVEMENTS (inventory audit trail)
-- ============================================================================
INSERT INTO stock_movements (part_id, movement_type, qty, balance_after, reason, reference_id, created_by, created_at) VALUES
    (1, 'out', -1, 62, 'Job usage: oil change', 1,  1, '2026-05-18 12:05:00'),
    (2, 'out', -1, 47, 'Job usage: oil filter', 1,  1, '2026-05-18 12:05:00'),
    (2, 'out', -1, 46, 'Job usage: oil filter', 2,  1, '2026-06-15 12:05:00'),
    (4, 'out', -2, 13, 'Job usage: brake pads', 3,  1, '2026-06-03 12:05:00'),
    (6, 'out', -1, 24, 'Job usage: coolant',    4,  1, '2026-07-05 12:05:00'),
    (3, 'out', -1, 27, 'Job usage: air filter', 5,  1, '2026-07-02 12:05:00'),
    (5, 'out', -2, 33, 'Job usage: spark plugs',5,  1, '2026-07-02 12:05:00'),
    (1, 'out', -1, 61, 'Job usage: oil change', 6,  1, '2026-07-01 12:05:00'),
    (5, 'out', -2, 31, 'Job usage: spark plugs',7,  1, '2026-07-09 12:05:00'),
    (3, 'out', -1, 26, 'Job usage: air filter', 7,  1, '2026-07-09 12:05:00'),
    (1, 'out', -1, 60, 'Job usage: oil change', 8,  1, '2026-07-04 12:05:00'),
    (2, 'out', -1, 45, 'Job usage: oil filter', 8,  1, '2026-07-04 12:05:00'),
    (4, 'out', -2, 11, 'Job usage: brake pads', 9,  1, '2026-07-06 12:05:00'),
    (6, 'out', -1, 23, 'Job usage: coolant',   10,  1, '2026-07-11 12:05:00'),
    (11,'out', -1, 25, 'Job usage: cabin filter',10, 1, '2026-07-11 12:05:00'),
    (9, 'out', -1, 9,  'Job usage: timing belt kit',11, 1, '2026-06-20 12:05:00'),
    (10,'out', -4, 20, 'Job usage: ATF fluid',  13, 1, '2026-07-07 12:05:00'),
    (7, 'out', -2, 14, 'Demo seed usage — wiper blades', NULL, 1, '2026-06-01 09:00:00'),
    (8, 'out', -6, 2,  'Demo seed usage — battery installs', NULL, 1, '2026-05-01 09:00:00'),
    -- Restock movements so inventory doesn't look one-directional
    (1, 'in', 20, 81, 'Restock from supplier — Petronas monthly delivery', NULL, 1, '2026-06-25 09:00:00'),
    (5, 'in', 20, 51, 'Restock from supplier — NGK spark plugs', NULL, 1, '2026-06-01 09:00:00'),
    (4, 'in', 10, 21, 'Restock from supplier — Brembo brake pads', NULL, 1, '2026-05-10 09:00:00'),
    -- Manual stock adjustment producing the final, realistic on-hand quantities used above
    (1, 'adjustment', -24, 57, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00'),
    (2, 'adjustment', -3,  42, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00'),
    (4, 'adjustment', 0,   11, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00'),
    (5, 'adjustment', -21, 30, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00'),
    (6, 'adjustment', -4,  19, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00'),
    (3, 'adjustment', -4,  22, 'Stock count correction', NULL, 1, '2026-07-10 09:00:00');

-- ============================================================================
-- SECTION 12: BLOCKED SLOTS (workshop closures / mechanic leave)
-- ============================================================================
INSERT INTO blocked_slots (block_date, time_window, reason, mechanic_id, created_by, created_at) VALUES
    ('2026-06-01', NULL,        'Public holiday — workshop closed', NULL, 1, '2026-05-20 09:00:00'),
    ('2026-07-14', '01:30 PM',  'Mechanic on medical leave',        3,    1, '2026-07-10 09:00:00'),
    ('2026-07-19', NULL,        'Weekend maintenance — bays closed for equipment servicing', NULL, 1, '2026-07-11 09:00:00');

-- ============================================================================
-- SECTION 13: RECEIPTS
-- ============================================================================
INSERT INTO receipts (history_id, receipt_no, requested_by, generated_at) VALUES
    (20, 'RCPT-2026-0001', 4, '2026-05-18 15:00:00'),
    (25, 'RCPT-2026-0002', 4, '2026-06-15 14:00:00'),
    (32, 'RCPT-2026-0003', 5, '2026-07-02 16:00:00'),
    (44, 'RCPT-2026-0004', 4, '2026-07-09 15:30:00'),
    (45, 'RCPT-2026-0005', 5, '2026-07-04 13:00:00'),
    (48, 'RCPT-2026-0006', 9, '2026-06-20 17:00:00'),
    (48, 'RCPT-2026-0007', 10,'2026-07-07 14:30:00');

-- ============================================================================
-- SECTION 14: NOTIFICATIONS
-- ============================================================================
INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES
    (1, 'System Online',      'AutoCare Hub PHP system initialized successfully.',                 'system',      NULL,                        1, '2025-11-01 08:05:00'),
    (4, 'Booking Confirmed',  'Your booking is confirmed. Pay from My Appointments to secure your slot.', 'appointment', 'user/appointments.php',    1, '2026-07-10 09:05:00'),
    (5, 'Payment Received',   'Your payment for Brake Service is confirmed. Receipt is ready.',    'receipt',     'user/receipt.php',         1, '2026-07-04 13:05:00'),
    (2, 'New Job Available',  'BJK 5678 is ready for service today.',                              'appointment', 'mechanic/appointments.php',0, '2026-07-12 08:00:00'),
    (6, 'Work Started on Your Car', 'A mechanic has started work on your vehicle.',                'appointment', 'user/appointments.php',    0, '2026-07-12 09:05:00'),
    (4, 'Service completed',  'Your Full Service on JHR 3456 is complete. Receipt ready.',         'receipt',     'user/history.php',         1, '2026-06-15 14:05:00'),
    (4, 'Quote ready',        'Quote RM 295 for brake service on ACH 8802. Please pay to confirm.', 'appointment', 'user/appointments.php',    0, '2026-07-12 08:05:00'),
    (2, 'Commission earned',  'You earned commission on multiple completed jobs this month.',      'success',     'mechanic/commission.php',  0, '2026-07-09 15:35:00'),
    (3, 'Commission earned',  'You earned commission on completed jobs. Check Commission tab.',    'success',     'mechanic/commission.php',  0, '2026-07-06 13:35:00'),
    (1, 'Revenue update',     'Demo progression data loaded: more bookings, completions and profit.', 'system',   'admin/dashboard.php',      0, '2026-07-11 09:00:00'),
    (1, 'Low stock alert',    'Battery 60Ah is running low (2 left). Reorder soon.',                'alert',       'admin/inventory.php',      0, '2026-07-11 09:10:00'),
    (7, 'New Job Available',  'A pre-purchase inspection has been requested for SGR 1010.',        'appointment', 'mechanic/appointments.php',0, '2026-07-12 09:05:00'),
    (9, 'Booking Received',   'Your pre-purchase inspection request has been received and is pending approval.', 'appointment', 'user/appointments.php', 0, '2026-07-12 09:01:00'),
    (10,'Booking Received',   'Your battery check request has been received and is pending approval.', 'appointment', 'user/appointments.php', 0, '2026-07-12 09:11:00'),
    (8, 'Welcome to AutoCare Hub', 'Your admin account has been created. You can now manage users, catalog, and inventory.', 'system', 'admin/dashboard.php', 1, '2026-01-20 09:05:00');

-- ============================================================================
-- SECTION 15: INTERNAL MESSAGES
-- ============================================================================
INSERT INTO messages (sender_id, receiver_id, subject, body, is_read, created_at) VALUES
    (4, 2, 'Question about brake service', 'Hi, can I bring my car earlier for the brake service tomorrow?', 1, '2026-06-01 15:00:00'),
    (2, 4, 'Re: Question about brake service', 'Yes, you can come in at 08:30 AM. We have a bay available.', 1, '2026-06-01 15:20:00'),
    (5, 1, 'Feedback on service', 'Excellent aircond service last week. Thank you!', 1, '2026-04-10 10:00:00'),
    (9, 7, 'Timing belt cost', 'Roughly how long will the timing belt job take, and is a loaner car available?', 1, '2026-06-18 11:00:00'),
    (7, 9, 'Re: Timing belt cost', 'It usually takes about 4 hours. No loaner car currently, but we can arrange a Grab voucher.', 1, '2026-06-18 11:30:00'),
    (10,8, 'Fleet servicing enquiry', 'We have 3 company pickups — can we set up a corporate account?', 0, '2026-07-05 09:00:00'),
    (6, 1, 'Invoice request', 'Could I get a formal tax invoice for my last two services for my company claim?', 0, '2026-07-11 16:00:00');

-- ============================================================================
-- SECTION 16: CONTACT FORM SUBMISSIONS
-- ============================================================================
INSERT INTO contact_submissions (name, email, phone, subject, message, status, created_at) VALUES
    ('Chong Mei Ling', 'meiling.chong@email.com', '012-7654321', 'Opening hours on public holidays', 'Are you open on Merdeka Day this year? Need to book a service before a road trip.', 'replied', '2026-06-25 10:00:00'),
    ('Fahmi Rosli',     'fahmi.rosli@email.com',  '019-3344556', 'Corporate fleet discount',           'Interested in a fleet maintenance package for 5 delivery vans.',                   'read',    '2026-07-08 14:20:00'),
    ('Priya Devi',      'priya.devi@email.com',   '016-8899001', 'Warranty on brake pads',              'Do the brake pads you install come with any warranty period?',                     'new',     '2026-07-11 17:45:00');

-- ============================================================================
-- SECTION 17: SUMMARY / VERIFICATION QUERIES
-- ============================================================================
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'AutoCare Hub merged database import complete' AS result;

SELECT
  (SELECT COUNT(*) FROM users)                                                        AS total_users,
  (SELECT COUNT(*) FROM users WHERE role='admin')                                     AS total_admins,
  (SELECT COUNT(*) FROM users WHERE role='mechanic')                                  AS total_mechanics,
  (SELECT COUNT(*) FROM users WHERE role='customer')                                  AS total_customers,
  (SELECT COUNT(*) FROM vehicles)                                                     AS total_vehicles,
  (SELECT COUNT(*) FROM service_packages)                                             AS total_packages,
  (SELECT COUNT(*) FROM parts)                                                        AS total_parts,
  (SELECT COUNT(*) FROM appointments)                                                 AS total_appointments,
  (SELECT COUNT(*) FROM service_history)                                              AS total_service_history,
  (SELECT COALESCE(SUM(gross_payment),0) FROM service_history WHERE status='Settled') AS total_revenue,
  (SELECT COALESCE(SUM(commission_amount),0) FROM service_history WHERE status='Settled') AS total_commission,
  (SELECT COUNT(*) FROM parts WHERE stock_qty <= min_stock)                           AS low_stock_parts;
