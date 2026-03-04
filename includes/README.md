# Includes

This directory contains modular code split out of the main `simku-keuangan.php` file to improve maintainability.

- `bootstrap.php` — loads all modular code used by the plugin.
- `traits/` — traits that are mixed into the main `SIMAK_App_Simak` class:
  - `trait-admin-pages.php` — admin page handlers & template rendering glue
  - `trait-datasource.php` — internal/external datasource helpers & table mappings
  - `trait-notify.php` — notification helpers (Telegram/Email/WA webhook)
  - `trait-reports.php` — reporting & export handlers (PDF/CSV)
  - `trait-charts.php` — charts (shortcode + AJAX payload + helpers)
  - `trait-pdf.php` — low-level PDF utilities
  - `trait-csv.php` — CSV parsing & bulk import utilities

The goal is to refactor in small, non-breaking steps.
