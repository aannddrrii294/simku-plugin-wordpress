# SIMKU (Finance Manager) — WordPress Plugin

SIMKU is a WordPress plugin for personal/team financial tracking. It helps you record income/expenses, manage savings/investments, visualize dashboards with charts (Apache ECharts), generate reports, and set spending limits with automated notifications. It also supports using an **external MySQL/MariaDB database** as the data source.

**Version:** 0.5.69  
**License:** GPLv2 or later

---

## Features

- **Transactions**
  - Track income/expenses (supports legacy `outcome` category mapping)
  - Add/edit entries via WordPress admin pages
  - Attach receipt images (upload or URL)
  - Optional notifications on new transactions (Telegram / Email / WhatsApp webhook)

- **Savings / Investments**
  - Record savings entries and view them in one place
  - Can share the same datasource as transactions or use separate tables

- **Payment Reminders**
  - Track installments/bills with due dates
  - Automated reminder notifications (defaults: D-7, D-5, D-3)

- **Dashboards & Charts**
  - Built-in chart templates powered by **ECharts**
  - “Charts” can be accessible to all logged-in users (read-only templates, user-scoped data)

- **Spending Limits & Alerts**
  - Daily / weekly / monthly expense thresholds
  - Hourly cron checks and notifications when limits are exceeded

- **Flexible Datasource**
  - Use WordPress internal tables (auto-created on activation), or
  - Connect to an **external MySQL/MariaDB** database/table (read-only by default; can enable write)

---

## Requirements

- WordPress (admin access for setup)
- PHP (typical WordPress hosting environment)
- MySQL/MariaDB
- Cron must be working (WP-Cron or real cron hitting wp-cron.php) for limits/reminder notifications

### Optional (Receipt OCR)
- `python3` available on the server
- `proc_open()` enabled (some shared hostings disable it)
- OCR script file: `ocr/receipt_ocr.py` (not included in this ZIP)

---

## Installation

1. Copy the plugin folder into your WordPress plugins directory:
   - `wp-content/plugins/simku-keuangan/`
2. Ensure the main file exists:
   - `wp-content/plugins/simku-keuangan/simku-keuangan.php`
3. Activate the plugin from **WP Admin → Plugins**.

On activation, the plugin will:
- Create internal tables (if using internal datasource)
- Register cron schedules (hourly checks)
- Add a **Finance Manager** role and capabilities (and grant capabilities to Administrators)

---

## Roles & Permissions

The plugin adds a role: **Finance Manager** with permissions to access core finance features.

Capabilities used:
- `simak_view_transactions`
- `simak_manage_transactions`
- `simak_view_reports`
- `simak_manage_settings`
- `simak_view_logs`

Administrators automatically receive these capabilities on activation.

---

## Admin Menu

After activation, you’ll see a top-level menu: **SIMKU**.

Submenus include:
- Dashboard
- Transactions / Add Transaction / Scan Receipt
- Savings / Add Saving
- Reminders / Add Reminder
- Reports
- Charts / Add Chart
- Logs
- Settings

> By design, **Charts** can be accessible to *any logged-in user* (“read”), while other pages are restricted by capabilities above.

---

## Configuration (Settings)

Go to: **SIMKU → Settings**

Key configuration areas:

### 1) Datasource Mode
- **Internal**: Use WordPress DB tables (created automatically).
- **External**: Connect to another MySQL/MariaDB database + table.

External connection fields:
- Host
- Database name
- Username
- Password
- Table name (default: `finance_transactions`)
- **Allow write to external** (disabled by default)

> If external mode is used without write permission, SIMKU behaves as read-only for that datasource.

### 2) Savings & Reminders Datasource
Each can be set to:
- `same` (follow Transactions datasource)
- `internal` (force WP internal tables)
- `external` (use external DB with a separate table name)

Defaults:
- Savings external table: `finance_savings`
- Reminders external table: `finance_payment_reminders`

### 3) Notifications
Supported channels:
- **Telegram** (bot token + chat ID)
- **Email** (recipient + enable toggle)
- **WhatsApp** via **webhook URL** (plugin posts JSON payload)

You can also customize message templates using placeholders (e.g. `{user}`, `{item}`, `{total}`, etc.).

---

## Shortcodes (Frontend)

You can embed SIMKU pages into WordPress pages/posts using shortcodes:

### Main router shortcode
```txt
[simku page="dashboard"]
