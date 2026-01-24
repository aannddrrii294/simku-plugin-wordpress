
---

## README — `SIMKU Keuangan` (updated for v0.5.89.3)

```md
# SIMKU Keuangan (WordPress Plugin)

**SIMKU Keuangan** is a WordPress plugin for lightweight financial management:
track **income/expenses**, manage **savings**, schedule **payment reminders**, and visualize insights with **charts** and **reports**.

This plugin supports:
- **Internal storage** (WordPress database tables), or
- **External MySQL storage** (connect to a separate database/table)

It also includes optional receipt scanning via:
- **n8n webhook (AI parsing)**, or
- **local Python OCR** (requires server support and an OCR script)

---

## Key Features

### Transactions
- Track income/expense/saving/invest entries
- Multi-item transaction flow: each item is stored as a separate row, grouped by `transaction_id`
- Attach **multiple images** (stored in `gambar_url`)
- Bulk import transactions from **CSV**
- Export transaction list to **PDF**

### Savings
- Record savings/investments per account
- Separate datasource mode (same/internal/external)

### Payment Reminders (Installments/Billing)
- Create reminders with due dates and installment tracking
- Optional scheduled reminders (hourly cron)
- Bulk import reminders from **CSV**

### Charts (ECharts)
- Chart builder (no-code) with metrics, filters, ranges
- Optional SQL-based chart mode (advanced)
- Charts are available for all logged-in users
- “Public chart templates” exist, but data remains scoped per user when user columns are available

### Reports
- Built-in report views
- Export reports to **PDF**

### Spending Limits & Notifications
- Daily/Weekly/Monthly limit checks via cron
- Notifications:
  - Telegram bot
  - Email
  - Optional WhatsApp webhook (generic JSON POST)

### Audit Logs
- Logs important actions (create/update/delete), notifications, login/logout, etc.

### Frontend Embedding (Shortcodes)
All major pages can be embedded on frontend pages via shortcodes (login required).

---

## Requirements

- WordPress **5.3+** (uses modern date/time helpers)
- PHP **7.0+**
- MySQL/MariaDB (internal WP DB or external DB)
- Outbound HTTP access for:
  - ECharts CDN (loaded from `https://cdn.jsdelivr.net/...`)
  - Telegram API (if enabled)
  - n8n webhook (if enabled)

---

## Installation

### Option A — Install from WordPress Admin
1. Go to **Plugins → Add New → Upload Plugin**
2. Upload the plugin ZIP
3. Activate **SIMKU Keuangan**

### Option B — Manual install
1. Extract the plugin folder to:

```bash
wp-content/plugins/simku-keuangan/
