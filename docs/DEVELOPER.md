# Developer Notes — WP SIMKU (Sistem Management Keuangan)

This document is intended for contributors who want to extend or refactor the plugin.

## Architecture (current)

- Main plugin bootstrap + logic lives in: `simku-keuangan.php`.
- Admin views are being moved to templates:
  - `templates/admin/dashboard.php`
  - `templates/admin/settings.php`

The long-term goal is to continue extracting other large admin pages into `templates/` and split core logic into `includes/` (DB layer, datasource layer, notifiers, export, etc.).

### Incremental refactor (non-breaking)

As of `v0.8.2` refactor builds, some large helper groups have been moved into traits under `includes/traits/` and loaded via `includes/bootstrap.php`.

- `includes/bootstrap.php` loads all modularized code.
- `includes/traits/trait-admin-pages.php` contains admin page handlers & template glue.
- `includes/traits/trait-datasource.php` contains internal/external datasource helpers.
- `includes/traits/trait-notify.php` contains notification helpers (Telegram/Email/WA webhook).
- `includes/traits/trait-reports.php` contains reporting + export handlers (PDF/CSV).
- `includes/traits/trait-charts.php` contains chart shortcodes + AJAX payload + helpers.
- `includes/traits/trait-pdf.php` contains low-level PDF helpers.
- `includes/traits/trait-csv.php` contains CSV parsing & bulk import helpers.

The main class (`SIMAK_App_Simak`) mixes these traits via `use ...;` statements so behavior remains unchanged.

## Datasource model

WP SIMKU supports two datasource modes:

1. **Internal (WP DB)**
   - Uses WordPress database connection (`$wpdb`).
   - Tables are auto-created on activation (and can be created manually in Settings).

2. **External MySQL/MariaDB**
   - Uses a separate `wpdb` instance.
   - Can be **read-only** unless the option **Allow write to external** is enabled.

### Column compatibility

The plugin supports both the “new” column names and a set of legacy column names for backward compatibility.

## Database schema (high level)

The plugin uses (at minimum) the following tables depending on datasource settings:

- **Transactions** (lines grouped by `transaction_id`)
- **Savings**
- **Payment reminders**
- **Logs**

You can see the external table example DDL in the Settings page.

## Scheduled jobs (WP-Cron)

Two main scheduled checks are used:

- **Spending limits**: daily/weekly/monthly check and send notifications when over limit.
- **Payment reminders**: upcoming due reminders based on offsets (fixed offsets by design: 7, 5, 3 days).

## Notifications

Currently supported channels:

- Telegram
- Email
- WhatsApp webhook (HTTP POST)

Templates are configurable in Settings.

## Security guidelines

### Sanitization (input)

- Always sanitize `$_GET/$_POST/$_REQUEST`:
  - `sanitize_text_field()` for plain strings
  - `esc_url_raw()` for URLs
  - `absint()` / `(int)` for integers
  - `(float)` for amounts

### Escaping (output)

When rendering anything that can be influenced by user input or stored data:

- Use `esc_html()` for text
- Use `esc_attr()` for attributes
- Use `esc_url()` for URLs
- Use `wp_kses_post()` for rich HTML content you explicitly allow

Avoid printing raw DB values directly.

### Nonces

All admin POST actions should be protected by a nonce:

- `wp_nonce_field()` in the form
- `check_admin_referer()` in the handler

### Capabilities

Do not rely on menu visibility only. Enforce capability checks inside each page handler.

## Internationalization (i18n)

- Text Domain: `wp-simku`
- Domain Path: `/languages`

Translations are loaded on `plugins_loaded`.

When adding new UI strings, wrap them with `__()`, `esc_html__()`, etc:

```php
__('Dashboard', 'wp-simku');
esc_html__('Save Settings', 'wp-simku');
```

## Templates

Use `$this->render_template()` to keep markup out of the main logic:

```php
$this->render_template('admin/dashboard.php', [
  'from' => $from,
  'to' => $to,
]);
```

Templates are included within class scope, so `$this` is available.

