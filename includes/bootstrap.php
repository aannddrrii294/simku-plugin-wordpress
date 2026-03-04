<?php
/**
 * Bootstrap loader for WP SIMKU.
 *
 * Kept intentionally small so the main plugin file stays readable.
 */
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/traits/trait-datasource.php';
require_once __DIR__ . '/traits/trait-notify.php';
require_once __DIR__ . '/traits/trait-savings-attachments.php';

// Admin modules (must be loaded before the wrapper trait-admin-pages.php)
require_once __DIR__ . '/traits/trait-admin-core.php';
require_once __DIR__ . '/traits/trait-admin-dashboard.php';
require_once __DIR__ . '/traits/trait-admin-transactions.php';
require_once __DIR__ . '/traits/trait-admin-savings.php';
require_once __DIR__ . '/traits/trait-admin-reminders.php';
require_once __DIR__ . '/traits/trait-admin-scan.php';
require_once __DIR__ . '/traits/trait-admin-reports.php';
require_once __DIR__ . '/traits/trait-admin-logs.php';
require_once __DIR__ . '/traits/trait-admin-settings.php';
require_once __DIR__ . '/traits/trait-admin-charts-admin.php';
require_once __DIR__ . '/traits/trait-admin-pages.php';

require_once __DIR__ . '/traits/trait-reports.php';
require_once __DIR__ . '/traits/trait-charts.php';
require_once __DIR__ . '/traits/trait-pdf.php';
require_once __DIR__ . '/traits/trait-csv.php';
require_once __DIR__ . '/traits/trait-budgets.php';
require_once __DIR__ . '/traits/trait-gdrive.php';
require_once __DIR__ . '/traits/trait-integrations.php';
