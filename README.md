# SIMKU (Finance Manager) — WordPress Plugin

SIMKU is a WordPress plugin for simple financial management: track **income/expenses**, manage **savings**, set **payment reminders**, view **dashboards & charts (ECharts)**, generate **reports (PDF export)**, and configure **spending limits & notifications**.  
It supports both **WordPress internal tables** and an **external MySQL/MariaDB database**.

**Version:** 0.5.89.6  
**License:** MIT Licenses

---

## Features

### Transactions
- Record income/expense transactions (multi-item supported via `transaction_id`)
- Upload **multiple images** per transaction (images are automatically compressed to reduce size)
- Edit/update existing entries from WP Admin

### Savings / Investments
- Record savings entries (and optionally keep them in separate tables/datasource)
- Simple listing and management from WP Admin

### Payment Reminders
- Create installment/bill reminders with due dates and status
- Upload proof images (multiple files supported)
- **Bulk import reminders from CSV** (Admin → Add Reminder → Bulk CSV)

### Dashboards, Charts & Reports
- Dashboards and charts using **Apache ECharts**
- Charts menu is accessible for all logged-in users (capability: `read`)
- Export **reports to PDF** (daily / weekly / monthly)

### Spending Limits & Notifications
- Daily / weekly / monthly expense limit checks (runs on cron)
- Notifications via:
  - Telegram
  - Email
  - WhatsApp webhook (HTTP POST)

### Flexible Datasource
- **Internal**: uses WP database tables (auto-created on activation)
- **External**: connect to another MySQL/MariaDB database + table
- Optional “Allow write to external” mode (otherwise external datasource can be read-only)

### Receipt Scanning (Optional)
- Prefer **n8n webhook** (AI parsing) if configured
- Fallback to **local Python OCR** (requires server support + OCR script)

---

## Requirements

- WordPress admin access
- PHP 7.0+ (typical WordPress hosting)
- MySQL/MariaDB
- WP-Cron enabled (recommended) for reminders/limit checks

Optional:
- Telegram bot token & chat ID (for Telegram notifications)
- A webhook endpoint (for WhatsApp integration)
- n8n webhook (for AI receipt scan)
- Python3 + `proc_open()` enabled (for local OCR fallback)

---

## Installation

### Option A — WordPress Admin
1. Go to **Plugins → Add New → Upload Plugin**
2. Upload the SIMKU ZIP
3. Activate **SIMKU (Finance Manager)**

### Option B — Manual
1. Extract the plugin to:
   ```bash
   wp-content/plugins/simku-keuangan/
