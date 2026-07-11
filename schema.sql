-- AutoCare Hub — MySQL Database Schema
-- Run via install.php or import manually into MySQL/MariaDB

CREATE DATABASE IF NOT EXISTS autocare_hub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE autocare_hub;

-- ─── Users & Auth ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
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
CREATE TABLE IF NOT EXISTS subscription_plans (
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

CREATE TABLE IF NOT EXISTS user_subscriptions (
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
CREATE TABLE IF NOT EXISTS vehicles (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    owner_name      VARCHAR(120) NOT NULL,
    contact_no      VARCHAR(20)  NOT NULL,
    plate_no        VARCHAR(15)  NOT NULL UNIQUE,
    brand           VARCHAR(60)  NOT NULL,
    model_variant   VARCHAR(80)  NOT NULL,
    vehicle_type    VARCHAR(30)  NOT NULL DEFAULT 'Sedan',
    current_mileage INT          DEFAULT NULL,
    last_service_mileage INT     DEFAULT NULL,
    last_service_date DATE       DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Service Catalog ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS service_packages (
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
CREATE TABLE IF NOT EXISTS appointments (
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
CREATE TABLE IF NOT EXISTS service_history (
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
CREATE TABLE IF NOT EXISTS parts (
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

CREATE TABLE IF NOT EXISTS job_parts (
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

CREATE TABLE IF NOT EXISTS stock_movements (
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

CREATE TABLE IF NOT EXISTS blocked_slots (
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
CREATE TABLE IF NOT EXISTS receipts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    history_id      INT          NOT NULL,
    receipt_no      VARCHAR(30)  NOT NULL UNIQUE,
    requested_by    INT          NOT NULL,
    generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (history_id) REFERENCES service_history(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ─── Notifications ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
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
CREATE TABLE IF NOT EXISTS messages (
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
CREATE TABLE IF NOT EXISTS contact_submissions (
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
CREATE TABLE IF NOT EXISTS workshop_settings (
    setting_key     VARCHAR(60)  PRIMARY KEY,
    setting_value   VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

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
    ('mail_driver', 'mail')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Sample parts (fresh installs)
INSERT INTO parts (name, sku, category, unit_price, cost_price, stock_qty, min_stock, supplier) VALUES
    ('Engine Oil 5W-30 (4L)', 'OIL-5W30-4L', 'Lubricants', 95.00, 65.00, 40, 10, 'Petronas'),
    ('Oil Filter', 'FLT-OIL-UNI', 'Filters', 25.00, 12.00, 50, 10, 'Bosch'),
    ('Air Filter', 'FLT-AIR-UNI', 'Filters', 35.00, 18.00, 30, 8, 'Bosch'),
    ('Brake Pad Set (Front)', 'BRK-PAD-F', 'Brakes', 180.00, 110.00, 15, 4, 'Brembo'),
    ('Spark Plug (Iridium)', 'SPK-IRD', 'Ignition', 45.00, 28.00, 40, 8, 'NGK'),
    ('Coolant 1L', 'CLN-1L', 'Fluids', 22.00, 12.00, 25, 6, 'Prestone'),
    ('Wiper Blade 22"', 'WIP-22', 'Accessories', 30.00, 15.00, 20, 5, 'Bosch'),
    ('Battery 60Ah', 'BAT-60', 'Electrical', 320.00, 240.00, 8, 2, 'Century')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ─── Indexes ──────────────────────────────────────────────────
CREATE INDEX idx_appointments_date   ON appointments(appointment_date);
CREATE INDEX idx_appointments_status ON appointments(status);
CREATE INDEX idx_history_date        ON service_history(completion_date);
CREATE INDEX idx_notifications_user  ON notifications(user_id, is_read);
CREATE INDEX idx_messages_receiver   ON messages(receiver_id, is_read);