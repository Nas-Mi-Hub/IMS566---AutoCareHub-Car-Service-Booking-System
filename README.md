# AutoCare Hub — PHP Edition v2.1

**Vehicle Service Appointment & Customer Management System**

Full-stack PHP + MySQL workshop platform for the Malaysian market (independent bengkels & multi-bay shops). All pages are `.php` files — no standalone HTML.

---

## Requirements

- PHP 8.0+ (with PDO MySQL extension; `fileinfo` recommended for uploads)
- MySQL 5.7+ or MariaDB 10.3+
- Apache/Nginx web server (XAMPP, WAMP, Laragon recommended)
- Optional: Composer (PHPMailer for SMTP, mPDF for advanced PDFs)

---

## Quick Setup (XAMPP / Laragon)

1. Copy `AutoCare-Hub-PHP` folder to your web root:
   - XAMPP: `C:\xampp\htdocs\AutoCare-Hub-PHP`
   - Laragon: `C:\laragon\www\AutoCare-Hub-PHP`

2. Start **Apache** and **MySQL**

3. Edit database credentials if needed in `config/database.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'autocare_hub');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```

4. Open browser: `http://localhost/AutoCare-Hub-PHP/install.php`

5. After successful install, **delete `install.php`** for security

6. Go to: `http://localhost/AutoCare-Hub-PHP/index.php`

---

## Demo Accounts

| Role     | Username   | Password      |
|----------|------------|---------------|
| Admin    | `admin`    | `Admin@123`   |
| Mechanic | `mechanic1`| `Mechanic@123`|
| Customer | `ahmad`    | `Customer@123`|

---

## Features

### Public Pages
- `index.php` — Landing page
- `services.php` — Service catalog (browse packages)
- `contact.php` — Contact form (saved to database)

### Authentication
- `auth/login.php` — Login (admin / mechanic / customer)
- `auth/logout.php` — Session destroy
- CSRF protection on all POST forms

### Admin Dashboard (`admin/`)
- Dashboard with real-time Chart.js graphs + **parts vs labor** metrics
- Appointments queue (start/complete/cancel + **Job Card** link)
- **Parts & Inventory** (`admin/inventory.php`) — CRUD, stock adjust, low-stock alerts, margin
- Customers & Cars registry
- Service Catalog management
- Service History archive
- User Management (create admin/mechanic/customer)
- PDF Reports + CSV sheet download
- Notifications & Messages
- Contact inquiry inbox

### Customer Dashboard (`user/`)
- Personal dashboard with charts
- **Book Service** — Fixed package **or** Diagnostic Inspection
- Live time-slot capacity + FullCalendar date picker
- Appointments tracking + cancel
- Payment with bank-transfer proof / cash / QR (+ optional gateway path)
- My Vehicles registration
- Receipt request with popup modal + PDF (parts breakdown)
- Service History + star ratings
- Notifications & Messages

### Mechanic Dashboard (`mechanic/`)
- Workshop jobs queue
- **Digital Job Card** (`mechanic/job.php`) — notes, labor hours, photos, parts used
- Auto stock deduction on complete
- Commission tracking (15% default)
- Completed jobs history + messages

### Service layer (`includes/services/`)
- `AppointmentService` — booking with slot locks, job workflow
- `PaymentService` — payments, SST calc, gateway stub
- `InventoryService` — parts, stock movements, job consumption
- `NotificationService` — in-app + email + SMS fan-out

### API Endpoints (`api/`)
- `chart-data.php` — Real-time chart data (JSON)
- `receipt.php` — Receipt generation (JSON + popup data)
- `notifications.php` — Notification polling
- `slots.php` — Live slot availability

### Exports (`exports/`)
- `pdf-report.php` — Operations invoice voucher (print-to-PDF)
- `pdf-receipt.php` — Service receipt with **labor + parts + SST**
- `csv-sheet.php` — Excel-compatible CSV download

---

## v2.1 Changelog

| Area | What changed |
|------|----------------|
| Security | CSRF tokens on all POST forms; hardened photo uploads |
| Communication | `sendEmail()` (mail/PHPMailer), `sendSms()` (log/Twilio/HTTP), `notifyWithEmail()` |
| Inventory | `parts`, `job_parts`, `stock_movements` tables + admin UI |
| Jobs | Digital job card: notes, photos, parts, labor hours |
| Booking | Package vs Inspection types; race-safe slot booking (`FOR UPDATE`) |
| Scheduling | FullCalendar month picker on booking page |
| Payments | SST helper + Billplz/SenangPay config stub; manual proof remains default |
| Receipts | Parts breakdown on PDF receipt |
| Architecture | Service classes under `includes/services/` |

Schema upgrades apply **automatically** via `ensureSchemaUpdates()` on login/page load. Optional SQL: `database/schema_v2.1_migration.sql`.

### Optional production config

```
config/mail.php      # driver: mail | phpmailer + SMTP
config/sms.php       # driver: log | twilio | http
config/payment.php   # gateway + SST toggles
```

Environment variables (examples):
- `ACH_MAIL_DRIVER=phpmailer`, `ACH_SMTP_HOST`, `ACH_SMTP_USER`, `ACH_SMTP_PASS`
- `ACH_SMS_ENABLED=1`, `ACH_SMS_DRIVER=twilio`, `ACH_TWILIO_SID`, …
- `ACH_SST_ENABLED=1`, `ACH_SST_PERCENT=6`
- `ACH_GATEWAY_ENABLED=1`, `ACH_GATEWAY_PROVIDER=billplz`

### Composer (optional)

```bash
cd C:\xampp\htdocs\autocarhub
composer require phpmailer/phpmailer
# optional: composer require mpdf/mpdf
```

Then set `driver => 'phpmailer'` in `config/mail.php`.

---

## Database

Schema file: `schema.sql` (also mirrored in installer)

**Tables:**
- `users` — admin, mechanic, customer accounts
- `vehicles` — customer vehicle registry
- `service_packages` — service catalog
- `appointments` — booking queue (+ booking_type, job_notes, photos, quotes)
- `service_history` — settled archive (+ parts/labor totals)
- `parts` / `job_parts` / `stock_movements` — inventory
- `blocked_slots` — admin slot blocking (foundation)
- `receipts` — generated receipt records
- `notifications` — user notifications
- `messages` — internal messaging
- `subscription_plans` / `user_subscriptions` — upgrade system
- `contact_submissions` — contact form entries
- `workshop_settings` — workshop config

---

## Testing checklist (v2.1)

1. Login as each demo role (admin / mechanic1 / ahmad)
2. **CSRF**: submit booking/payment forms — should succeed; forged token should flash error
3. **Booking**: Fixed package → payment page; Inspection → appointments (no immediate pay redirect)
4. **Payment**: online banking with proof upload; cash/QR without proof
5. **Job card**: mechanic starts job with mileage → add notes/photos/parts → complete → stock decreases
6. **Inventory**: admin add part, adjust stock, see low-stock alert on dashboard
7. **Receipt PDF**: completed job shows labor + parts lines
8. **Email/SMS**: check `storage/logs/sms.log` when SMS driver=`log`
9. **Mobile**: tables scroll; buttons are touch-sized
10. **Slot race**: two browsers same slot near capacity — only max bookings accepted

---

## Laravel migration path (optional)

Keep pure PHP for now. When ready:
1. Map `users` / `appointments` / `parts` to Eloquent models
2. Move `includes/services/*` into Laravel service classes / actions
3. Replace session auth with Laravel Breeze/Sanctum
4. Use Laravel Mail + notifications for email/SMS
5. Queues for reminders; policies for roles

---

## Folder Structure

```
AutoCare-Hub-PHP/
├── config/database.php
├── database/schema.sql
├── includes/          (auth, functions, layout)
├── auth/              (login, logout)
├── admin/             (12 admin pages)
├── user/              (9 customer pages)
├── mechanic/          (4 mechanic pages)
├── api/               (JSON endpoints)
├── exports/           (PDF + CSV)
├── assets/css|js/
├── index.php
├── services.php
├── contact.php
├── install.php
└── README.md
```

---

## Presentation Demo Flow

1. **Login as admin** → Dashboard (live charts, capacity bars)
2. **Appointments** → Approve booking → Assign mechanic
3. **Login as mechanic** → Start job → Complete
4. **Login as customer (ahmad)** → Receipt request (popup) → Download PDF
5. **Upgrade Plan** → Switch to Pro tier
6. **PDF Reports** → Generate operations report
7. **Contact** → Submit inquiry → Admin views in Contact Inquiries

---

## Security Notes (Production)

- Delete `install.php` after setup
- Change all demo passwords
- Use HTTPS
- Set strong `DB_PASS`
- Add CSRF tokens to forms
- Restrict `exports/` and `api/` with proper auth (already role-checked)

---

**AutoCare Hub PHP Edition** — IMS566 Presentation Ready