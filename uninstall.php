<?php
/**
 * Uninstall handler for WP SIMKU.
 *
 * NOTE:
 * - By default we only remove plugin options and scheduled events.
 * - Data tables are preserved to avoid accidental data loss.
 * - To remove tables on uninstall, define in wp-config.php:
 *     define('SIMKU_UNINSTALL_REMOVE_TABLES', true);
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Clear scheduled events (avoid orphaned cron).
if (function_exists('wp_clear_scheduled_hook')) {
    wp_clear_scheduled_hook('fl_check_limits_hourly');
    wp_clear_scheduled_hook('simak_check_payment_reminders_hourly');
}

// Remove options created by the plugin.
$opt_keys = [
    'simak_settings_v1',
    'simak_charts_v1',
    'simak_dashboard_charts_v1',
    'simak_limit_notified_v1',
    'simak_activation_error_v1',
    'simak_payment_reminder_notified_v1',
];

if (function_exists('delete_option')) {
    foreach ($opt_keys as $k) {
        delete_option($k);
    }
}

// Optional: also remove DB tables if the site owner explicitly opts in.
if (defined('SIMKU_UNINSTALL_REMOVE_TABLES') && SIMKU_UNINSTALL_REMOVE_TABLES) {
    global $wpdb;
    if ($wpdb instanceof wpdb) {
        $tables = [
            $wpdb->prefix . 'fl_transactions',
            $wpdb->prefix . 'fl_savings',
            $wpdb->prefix . 'fl_payment_reminders',
            $wpdb->prefix . 'fl_logs',
            $wpdb->prefix . 'fl_budgets',
            $wpdb->prefix . 'fl_budget_goals',
            $wpdb->prefix . 'fl_budget_goal_allocations',
            $wpdb->prefix . 'fl_saving_attachments',
        ];
        foreach ($tables as $t) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
        }
    }
}
