# Recovery Iloilo Spa — Integrated System for Streamlining Operations and Analytics

A web-based spa management and e-commerce platform built for **Recovery Spa & Massage** (Molo, Iloilo City). The system unifies online booking, walk-in point-of-sale, product e-commerce, online payments, therapist management, and operational/financial reporting into a single role-based application.

> **Capstone Project Proposal** — PHINMA University of Iloilo, College of Information Technology Education, Data Informatics Track. Submitted in partial fulfillment of Capstone 1 and Research, Second Semester 2026–2027.

---

## Table of Contents

- [Overview](#overview)
- [Tech Stack](#tech-stack)
- [Features](#features)
- [User Roles](#user-roles)
- [Project Structure](#project-structure)
- [Database Schema](#database-schema)
- [Installation & Setup](#installation--setup)
- [Environment Variables](#environment-variables)
- [Payments (PayMongo)](#payments-paymongo)
- [Security](#security)
- [Operational Notes](#operational-notes)
- [Pre-Deployment Checklist](#pre-deployment-checklist)
- [Team](#team)

---

## Overview

The platform serves two front-end audiences and one back office:

- **Customers** browse services and products, book appointments, pay online (PayMongo) or on-site, and track their bookings.
- **Walk-in clients** are served through an in-store kiosk/POS flow operated by a receptionist.
- **Back office** (owner, IT, marketing, cashier) manages bookings, therapists, inventory, pricing, discounts, refunds, partners, and daily financial reporting.

Bookings, walk-ins, and product orders all flow through a shared `orders` / `order_items` backbone, so sales reporting is consistent across channels.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP (procedural + helper functions) |
| Database | MySQL / MariaDB (`mysqli`, prepared statements) |
| Server | Apache (developed on XAMPP) |
| Email | PHPMailer (SMTP) |
| Payments | PayMongo API (Payment Intents + Webhooks) |
| Front end | Server-rendered HTML, CSS, vanilla JS |
| Config | `.env` file loaded via `getenv()` (no hardcoded secrets) |

---

## Features

**Customer-facing**
- Account registration with OTP verification and login
- Service catalog, product catalog, and search
- Shopping cart (session + database synced)
- Appointment booking with availability checks
- Online checkout via PayMongo, plus pay-on-site option
- Email receipts
- Booking history, cancellation, and refund requests
- Profile management and feedback/ratings

**Back office (admin)**
- Dashboard and analytics
- Appointment management (assign therapist, approve, decline, check-in, complete, reschedule, cancel) with cashier-PIN authorization
- Walk-in POS / kiosk with on-the-spot payment
- Product and service CRUD, categories, and stock management
- Therapist management: profiles, specialties, specialty-to-service mapping, commission matrix, deductions, ratings
- **Therapist attendance** (time-in / time-out, duty date, break status, rotation order) — drives who is available for assignment
- Discount handling: voucher, senior (20%), PWD (20%), employee (50%), and influencer/marketing comps
- Refund request workflow with owner approval
- Partner / hotel rate management (special pricing tiers)
- Vouchers & discounts tracking page
- Gift certificate and unpaid (corporate) tracking
- Daily report with cash reconciliation, denomination breakdown, expenses, and Excel export
- Staff/account management with role-based account creation
- Notifications

---

## User Roles

There is **no separate `admin` table.** Administrators are rows in the `users` table where `role = 'admin'`, further differentiated by an `admin_role` sub-role.

| `admin_role` | Description | Typical access |
|---|---|---|
| `owner` | Business owner / superuser of operations | Full access |
| `it` | IT / developer (technical superuser) | Full access |
| `marketing` | Marketing & analytics | Reports, analytics, limited management |
| `cashier` | Receptionist / front desk | Walk-in, appointments, daily report (operational) |

Page-level access is centralized in `admin_access.php` (`admin_page_roles()` and `enforce_page_access()`). Customer accounts use `role = 'user'`; therapists are represented in the `therapists` table (and may have a linked `role = 'therapist'` user).

> **Note on naming:** the helper `redirect_if_not_owner()` actually checks `is_full_access()` (admin **and** not cashier), i.e. owner/IT/marketing — not owner alone. The name is historical and slated to be renamed `redirect_if_not_full_access()`.

---

## Project Structure

The application is served from a web root that uses **`admin/` and `user/` subdirectories** (the running code redirects to paths such as `admin/appointments.php` and `user/auth.php`). Shared files (`config.php`, `admin_access.php`, PHPMailer classes, `vendor/`) sit at the project root.

```
spa_ecommerce_system/
├── config.php                 # Loads .env, defines constants, session, CSRF, helpers
├── admin_access.php           # Role map + page-access enforcement
├── autoload.php               # PHPMailer autoloader
├── composer.json / .lock      # Dependencies (PHPMailer)
├── vendor/                    # Composer packages (generated)
├── .env                       # Secrets & config (NOT committed)
├── .gitignore
├── logs/                      # app_errors.log (writable; protected by .htaccess)
├── uploads/
│   ├── services/              # Service images (writable)
│   └── products/              # Product images (writable)
│
├── user/                      # Customer-facing pages
│   ├── index.php, auth.php, profile.php, search.php
│   ├── cart.php, checkout.php
│   ├── appointments.php, feedback.php
│   ├── payment_success.php, payment_cancel.php, payment_pending.php
│   └── send_receipt.php
│
├── admin/                     # Back-office pages
│   ├── index.php, analytics.php
│   ├── appointments.php, assign_therapist.php, availability.php, slots.php
│   ├── walkin.php, walkin_payment.php, walkin_payment_success.php, walkin_payment_cancel.php
│   ├── orders.php, products.php, services.php, categories.php
│   ├── Therapists.php, staff.php, users.php
│   ├── vouchers.php, refunds.php, partners.php
│   ├── daily_report.php, export_daily_report.php, export_sales.php, daily_report.php
│   ├── expenses_widget.php, receptionist_settings.php
│   ├── notify.php, admin_header.php, admin_footer.php, admin.css
│
├── paymongo_intent.php        # Create PayMongo payment intent
└── paymongo_webhook.php       # PayMongo webhook receiver
```

> Some exported copies may appear flat (all files in one directory). The deployed structure uses the `admin/` and `user/` subdirectories described above; `config.php` builds `BASE_URL` accordingly and supports an optional `APP_SUBFOLDER`.

---

## Database Schema

The database (`spa_ecommerce_db`) contains the following tables:

**Core commerce & booking**
- `users` — customers and admins (role / admin_role, OTP, session, cashier PIN)
- `services`, `products`, `categories`
- `cart`, `orders`, `order_items`
- `appointments` — 30 columns: full booking record incl. `rate_type` (regular/home/hotel/influencer), `partner_id`, `charged_price`, home-service fields, and a complete audit trail (`approved_by`, `completed_by`, `declined_by`, `cancelled_by`, `rescheduled_by`, etc.)
- `appointment_therapists`, `appointment_extra_services`

**Therapists**
- `therapists`, `therapist_specialties`, `therapist_specialty_services`
- `therapist_attendance` (time-in/out, duty date, break, rotation)
- `therapist_commission`, `therapist_deductions`, `therapist_ratings`

**Pricing & partners**
- `partners`, `partner_rates`

**Payments & finance**
- `refund_requests`
- `business_expenses`
- `daily_reports`, `daily_report_denominations`, `daily_product_sales`
- `gift_certificates`, `unpaids_corp`

**System**
- `feedback`, `notifications`, `receptionist_pins`, `system_settings`

> The `appointments` table is far richer than a basic booking record — it carries pricing context (rate type, partner, charged price) and a per-action audit trail used by the daily report and analytics.

---

## Installation & Setup

**Requirements:** PHP 7.4+ (8.x recommended), MySQL/MariaDB, Apache, Composer.

1. **Clone / copy** the project into your web root (e.g. `htdocs/spa_ecommerce_system`).

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Create the database** and import the schema:
   ```bash
   mysql -u root -p spa_ecommerce_db < spa_ecommerce_db.sql
   ```

4. **Configure environment** — copy the template and fill in real values:
   ```bash
   cp _env .env          # rename template to .env
   ```
   Edit `.env` (see [Environment Variables](#environment-variables)).

5. **Create writable directories** (if not present):
   ```bash
   mkdir -p logs uploads/services uploads/products
   # On Linux/macOS hosting:
   chmod 755 logs uploads/services uploads/products
   ```
   `logs/` should contain an `.htaccess` with `deny from all`.

6. **Set the app environment** in `.env`: `APP_ENV=production` for deployment (`development` shows stack traces — never use in production).

7. **Create your first admin account.** Admins are `users` rows with `role='admin'`. Seed one securely (e.g. via a one-off script using `password_hash()`), then use the in-app Staff page to create additional accounts. **Do not** ship the SQL dump with live password hashes — see the checklist.

---

## Environment Variables

All secrets and environment-specific values live in `.env` (loaded by `config.php` via `getenv()`):

```ini
# Application
APP_ENV=production              # production | development
APP_SECRET=                     # 32+ char random string (required)
APP_SUBFOLDER=                  # optional sub-path if not served at web root

# Database
DB_SERVER=localhost
DB_USERNAME=root
DB_PASSWORD=
DB_NAME=spa_ecommerce_db

# Mail (SMTP via PHPMailer)
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM=
MAIL_NAME=

# PayMongo
PAYMONGO_SECRET_KEY=
PAYMONGO_PUBLIC_KEY=
PAYMONGO_WEBHOOK_SECRET=
```

**`.env` must never be committed.** Ensure it is listed in `.gitignore`.

---

## Payments (PayMongo)

- `paymongo_intent.php` creates a Payment Intent and returns the client key for checkout.
- `paymongo_webhook.php` receives PayMongo webhook events and updates order/appointment status; it verifies the `PAYMONGO_WEBHOOK_SECRET`.
- On success/cancel/pending, customers are routed to `payment_success.php`, `payment_cancel.php`, or `payment_pending.php`.
- A feature flag `SHOW_GCASH_MAYA` (in `config.php`, currently `false`) controls whether direct GCash/Maya buttons are shown. When off, those payment options are hidden in the UI even though the backend supports them — flip this flag to enable them.

> The code distinguishes `payment_method` (channel chosen at checkout, e.g. `online`) from `paymongo_method` (the actual method PayMongo reports, e.g. `gcash`, `card`). Reports prefer `paymongo_method` when available.

---

## Security

- **No hardcoded secrets** — all credentials come from `.env`.
- **Prepared statements** are used throughout for user-supplied input.
- **CSRF protection** via `csrf_field()`, `verify_csrf_token()`, and `verify_csrf_token_ajax()` on state-changing actions.
- **Role-based access control** centralized in `admin_access.php`.
- **OTP verification** on registration; **cashier PIN** authorization for sensitive POS/approval actions.
- **Session management** with per-user session tokens.
- Error logging to `logs/app_errors.log`; stack traces are suppressed when `APP_ENV=production`.

**Known hygiene items (tracked, not blockers):**
- A few raw `query()` calls in `walkin.php` use an integer that is `intval()`-cast and validated upstream (no injection in practice) but should be converted to prepared statements for consistency.
- `walkin_payment_cancel.php` performs destructive cleanup from a `$_GET` parameter without a CSRF token; convert to POST + CSRF (the input is `intval()`-cast, so there is no SQL injection, but the action should be CSRF-guarded).

---

## Operational Notes

- **Sales recognition:** service sales are counted on the **appointment date** when an appointment is marked `completed`. **Cash received** is counted on the **date payment was taken**. These two figures intentionally differ for prepaid future bookings — the daily report lists "Upcoming Paid Appointments" to explain the gap. (The accrual-vs-cash basis is a business policy decision; confirm with management before changing.)
- **Therapist availability** is driven by attendance: a therapist is assignable only when they have timed in (`time_out IS NULL`) for the current duty date.
- **Receptionist attendance** is not tracked as time-in/out; the daily report records opening/closing cashier names manually.

---

## Pre-Deployment Checklist

**Blockers (must do):**
- [ ] Rename `_env` → `.env` and `_gitignore` → `.gitignore`
- [ ] Confirm `.env` is in `.gitignore` (never commit secrets)
- [ ] Set `APP_ENV=production` and a strong `APP_SECRET`
- [ ] Ensure `logs/`, `uploads/services/`, `uploads/products/` exist and are writable
- [ ] **Strip seeded password hashes** from the SQL dump before sharing the repo; create admin accounts via a secure seed step instead
- [ ] Move the runtime `ALTER TABLE` in `walkin.php` into a one-time migration script

**High priority:**
- [ ] Add CSRF (or convert to POST) for `walkin_payment_cancel.php`
- [ ] Convert remaining raw `query()` calls in `walkin.php` to prepared statements
- [ ] Configure and test the PayMongo webhook endpoint and secret in production

**Medium:**
- [ ] IP-level rate limiting on OTP requests
- [ ] Rename `redirect_if_not_owner()` → `redirect_if_not_full_access()`

---

## Team

**BSIT 4 — Data Informatics Track, PHINMA University of Iloilo**

- Alyanah Dale Estillore
- Kent Clarence Gonzalez
- Maria Fenny Guzman
- Angelo Somosierra
- Gabriel Candaganan
- Diancen Ernesto

*In partial fulfillment of the requirements in Capstone 1 and Research — Second Semester, 2026–2027.*