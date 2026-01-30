<?php
/**
 * Plugin Name: SIMKU (Finance Manager)
 * Description: Financial management: track income/expenses, savings/investments, dashboards (ECharts), reports, spending limits & notifications. Supports n8n and external databases.
 * Version: 0.5.90.1
 * Author: SIMKU
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) { exit; }

final class SIMAK_App_Simak {
  const VERSION = '0.5.90.1';

    const OPT_SETTINGS = 'simak_settings_v1';
    const OPT_CHARTS   = 'simak_charts_v1';
    const OPT_DASH_CHARTS = 'simak_dashboard_charts_v1';
    const OPT_NOTIFIED = 'simak_limit_notified_v1';

    // Stores activation errors (if activation failed). Shown as admin notice.
    const OPT_ACTIVATION_ERROR = 'simak_activation_error_v1';

    // Payment reminders anti-duplicate state.
    const OPT_REMINDER_NOTIFIED = 'simak_payment_reminder_notified_v1';

    const CAP_VIEW_TX  = 'simak_view_transactions';
    const CAP_MANAGE_TX = 'simak_manage_transactions';
    const CAP_VIEW_REPORTS = 'simak_view_reports';
    const CAP_MANAGE_SETTINGS = 'simak_manage_settings';
    const CAP_VIEW_LOGS = 'simak_view_logs';

    private static $instance = null;

    public static function instance() : self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        // Show activation errors (if any) without breaking the site.
        add_action('admin_notices', [$this, 'admin_notice_activation_error']);

        // Seed default charts/dashboard if missing (helps after updates/migrations).
        add_action('admin_init', [$this, 'maybe_seed_defaults']);

        // PDF export endpoints (must run outside admin.php rendering).
        add_action('admin_post_simku_export_report_pdf', [$this, 'handle_export_report_pdf']);
        add_action('admin_post_simku_export_report_csv', [$this, 'handle_export_report_csv']);

        add_action('wp_ajax_simak_chart_data', [$this, 'ajax_chart_data']);
        add_action('wp_ajax_nopriv_simak_chart_data', [$this, 'ajax_chart_data']);

        add_shortcode('fl_chart', [$this, 'shortcode_chart']);


        // SIMKU shortcodes (untuk dipasang di Page/Post via shortcode)
        add_shortcode('simku', [$this, 'shortcode_simku']);
        add_shortcode('simku_dashboard', [$this, 'shortcode_simku_dashboard']);
        add_shortcode('simku_transactions', [$this, 'shortcode_simku_transactions']);
        add_shortcode('simku_add_transaction', [$this, 'shortcode_simku_add_transaction']);
        add_shortcode('simku_savings', [$this, 'shortcode_simku_savings']);
        add_shortcode('simku_add_saving', [$this, 'shortcode_simku_add_saving']);
        add_shortcode('simku_reminders', [$this, 'shortcode_simku_reminders']);
        add_shortcode('simku_add_reminder', [$this, 'shortcode_simku_add_reminder']);
        add_shortcode('simku_scan_struk', [$this, 'shortcode_simku_scan_struk']);
        add_shortcode('simku_reports', [$this, 'shortcode_simku_reports']);
        add_shortcode('simku_charts', [$this, 'shortcode_simku_charts']);
        add_shortcode('simku_add_chart', [$this, 'shortcode_simku_add_chart']);
        add_shortcode('simku_settings', [$this, 'shortcode_simku_settings']);
        add_shortcode('simku_logs', [$this, 'shortcode_simku_logs']);

        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);

        // Logs: login/logout
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_logout']);

        // Cron
        add_action('fl_check_limits_hourly', [$this, 'cron_check_limits']);
        add_action('simak_check_payment_reminders_hourly', [$this, 'cron_check_payment_reminders']);
    }

    /* -------------------- Activation / Deactivation -------------------- */

    public static function activate() : void {
        // Activation hook must never white-screen the site.
        // If something goes wrong (missing permission / SELinux / weird WP setup), store the error
        // and show it as an admin notice after activation.
        try {
            self::do_activate();
            delete_option('simak_activation_error_v1');
        } catch (\Throwable $e) {
            $time = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
            update_option('simak_activation_error_v1', [
                'time' => $time,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], false);
        }
    }

    private static function do_activate() : void {
        // Add role + caps
        add_role('finance_manager', 'Finance Manager', [
            'read' => true,
            self::CAP_VIEW_TX => true,
            self::CAP_MANAGE_TX => true,
            self::CAP_VIEW_REPORTS => true,
            self::CAP_MANAGE_SETTINGS => true,
            self::CAP_VIEW_LOGS => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            foreach ([self::CAP_VIEW_TX, self::CAP_MANAGE_TX, self::CAP_VIEW_REPORTS, self::CAP_MANAGE_SETTINGS, self::CAP_VIEW_LOGS] as $cap) {
                $admin->add_cap($cap);
            }
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
        if (file_exists($upgrade)) {
            require_once $upgrade;
        }
        $use_dbdelta = function_exists('dbDelta');

        // Create internal log table
        $table = $wpdb->prefix . 'fl_logs';
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            user_login VARCHAR(60) NULL,
            action VARCHAR(50) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id VARCHAR(191) NULL,
            details LONGTEXT NULL,
            ip VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at),
            KEY action (action),
            KEY object_type (object_type),
            KEY object_id (object_id)
        ) {$charset};";
        $use_dbdelta ? dbDelta($sql) : $wpdb->query($sql);

        // Create internal transactions table
        $tx_table = $wpdb->prefix . 'fl_transactions';
        $tx_sql = "CREATE TABLE {$tx_table} (
            line_id VARCHAR(80) NOT NULL,
            transaction_id VARCHAR(64) NOT NULL,
            nama_toko VARCHAR(255) NULL,
            items VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            harga BIGINT NOT NULL,
            kategori VARCHAR(20) NULL,
            tanggal_input DATETIME NOT NULL,
            tanggal_struk DATE NULL,
            gambar_url TEXT NULL,
            description LONGTEXT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            PRIMARY KEY (line_id),
            KEY transaction_id (transaction_id),
            KEY kategori (kategori),
            KEY tanggal_struk (tanggal_struk)
        ) {$charset};";
        $use_dbdelta ? dbDelta($tx_sql) : $wpdb->query($tx_sql);

        // Savings table
        $sv_table = $wpdb->prefix . 'fl_savings';
        $sv_sql = "CREATE TABLE {$sv_table} (
            line_id VARCHAR(80) NOT NULL,
            saving_id VARCHAR(64) NOT NULL,
            account_name VARCHAR(255) NOT NULL,
            amount BIGINT NOT NULL,
            institution VARCHAR(255) NULL,
            notes LONGTEXT NULL,
            saved_at DATETIME NOT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            PRIMARY KEY (line_id),
            KEY saving_id (saving_id),
            KEY saved_at (saved_at)
        ) {$charset};";
        $use_dbdelta ? dbDelta($sv_sql) : $wpdb->query($sv_sql);

        // Payment reminders table
        $rm_table = $wpdb->prefix . 'fl_payment_reminders';
        $rm_sql = "CREATE TABLE {$rm_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            line_id VARCHAR(80) NOT NULL,
            reminder_id VARCHAR(64) NOT NULL,
            payment_name VARCHAR(255) NOT NULL,
            total_amount BIGINT NULL,
            installment_amount BIGINT NOT NULL,
            installments_total INT NOT NULL DEFAULT 1,
            installments_paid INT NOT NULL DEFAULT 0,
            schedule_mode VARCHAR(10) NOT NULL DEFAULT 'manual',
            due_day TINYINT UNSIGNED NULL,
            due_date DATE NOT NULL,
            payee VARCHAR(255) NULL,
            notes LONGTEXT NULL,
            gambar_url LONGTEXT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'belum',
            notify_telegram TINYINT UNSIGNED NOT NULL DEFAULT 1,
            notify_whatsapp TINYINT UNSIGNED NOT NULL DEFAULT 0,
            notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0,
            notified_for_due DATE NULL,
            notified_offsets VARCHAR(32) NULL,
            last_notified_at DATETIME NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY line_id (line_id),
            KEY reminder_id (reminder_id),
            KEY due_date (due_date),
            KEY status (status)
        ) {$charset};";
        $use_dbdelta ? dbDelta($rm_sql) : $wpdb->query($rm_sql);

        // Defaults
        if (!get_option(self::OPT_SETTINGS)) {
            add_option(self::OPT_SETTINGS, self::default_settings());
        }
        if (!get_option(self::OPT_CHARTS)) {
            add_option(self::OPT_CHARTS, self::default_charts());
        }
        if (!get_option(self::OPT_DASH_CHARTS)) {
            add_option(self::OPT_DASH_CHARTS, []);
        }
        if (!wp_next_scheduled('fl_check_limits_hourly')) {
            wp_schedule_event(time() + 300, 'hourly', 'fl_check_limits_hourly');
        }
        if (!wp_next_scheduled('simak_check_payment_reminders_hourly')) {
            wp_schedule_event(time() + 600, 'hourly', 'simak_check_payment_reminders_hourly');
        }
    }

    public static function deactivate() : void {
        wp_clear_scheduled_hook('fl_check_limits_hourly');
        wp_clear_scheduled_hook('simak_check_payment_reminders_hourly');
    }

    /**
     * If activation failed (but we caught the error to avoid white-screen), show the reason.
     * This helps users fix missing dependencies / permissions quickly.
     */
    public function admin_notice_activation_error() : void {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;

        $err = get_option(self::OPT_ACTIVATION_ERROR);
        if (!$err || !is_array($err)) return;

        $time = isset($err['time']) ? (string)$err['time'] : '';
        $msg  = isset($err['message']) ? (string)$err['message'] : '';
        $file = isset($err['file']) ? (string)$err['file'] : '';
        $line = isset($err['line']) ? (int)$err['line'] : 0;

        // Basic escape (avoid printing raw paths in HTML without escaping).
        $time_e = esc_html($time);
        $msg_e  = esc_html($msg);
        $file_e = esc_html($file);

        echo '<div class="notice notice-error">';
        echo '<p><strong>SIMKU:</strong> The plugin encountered an error during activation. Some features may be incomplete until this is fixed.</p>';
        echo '<p><code>' . $msg_e . '</code></p>';
        if ($file_e) {
            echo '<p>Location: <code>' . $file_e . ':' . (int)$line . '</code></p>';
        }
        if ($time_e) {
            echo '<p>Time: <code>' . $time_e . '</code></p>';
        }
        echo '<p>Also check <code>wp-content/debug.log</code> or your server error log for more details.</p>';
        echo '</div>';
    }

    private static function default_settings() : array {
        return [
            'datasource_mode' => 'external', // internal|external
            'external' => [
                'host' => '127.0.0.1',
                'db' => '',
                'user' => '',
                'pass' => '',
                'table' => 'finance_transactions',
                'allow_write' => 0,
            ],
            // Savings (Tabungan) datasource.
            // - same: follow Transactions datasource (external/internal)
            // - internal: always use WP DB table (wp_fl_savings)
            // - external: use the same external connection, but a different table
            'savings' => [
                'mode' => 'same',
                'external_table' => 'finance_savings',
            ],

            // Payment reminders datasource.
            // - same: follow Transactions datasource (external/internal)
            // - internal: always use WP DB table (wp_fl_payment_reminders)
            // - external: use the same external connection, but a different table
            'reminders' => [
                'mode' => 'same',
                'external_table' => 'finance_payment_reminders',
            ],
            'limits' => [
                'daily' => 0,
                'weekly' => 0,
                'monthly' => 0,
                'expense_categories' => ['expense','saving','invest'],
            ],
            'notify' => [
                'email_enabled' => 0,
                'email_to' => get_option('admin_email'),
                'email_notify_new_tx_default' => 0,
                // Templates for "new transaction" notifications
                // Supported placeholders: {user}, {kategori}, {toko}, {item}, {qty}, {harga}, {total},
                // {tanggal_input}, {tanggal_struk}, {transaction_id}, {line_id}, {gambar_url}, {description}
                'email_new_subject_tpl' => 'New transaction: {item} (Rp {total})',
                'email_new_body_tpl' => "New transaction created\n"
                    . "User: {user}\n"
                    . "Category: {kategori}\n"
                    . "Counterparty: {toko}\n"
                    . "Item: {item}\n"
                    . "Qty: {qty}\n"
                    . "Price: {harga}\n"
                    . "Total: Rp {total}\n"
                    . "Entry date: {tanggal_input}\n"
                    . "transaction_id: {transaction_id}\n"
                    . "line_id: {line_id}\n"
                    . "Image: {gambar_url}\n"
                    . "Description: {description}\n",
                'telegram_enabled' => 0,
                'telegram_bot_token' => '',
                'telegram_chat_id' => '',
                'telegram_notify_new_tx_default' => 1,
                'telegram_new_tpl' => "✅ <b>New transaction</b>\n"
                    . "User: <b>{user}</b>\n"
                    . "Category: <b>{kategori}</b>\n"
                    . "Counterparty: {toko}\n"
                    . "Item: {item}\n"
                    . "Qty: {qty}\n"
                    . "Price: {harga}\n"
                    . "Total: <b>Rp {total}</b>\n"
                    . "Entry date: {tanggal_input}\n"
                    . "{gambar_url}",
                'whatsapp_webhook' => '',
                'notify_on_limit' => 1,

                // Payment reminder notifications (D-7, D-5, D-3)
                'reminder_offsets' => [7,5,3],
                'reminder_telegram_tpl' => "⏰ <b>PAYMENT REMINDER</b>\n"
                    . "Payment: <b>{payment_name}</b>\n"
                    . "Due date: {due_date} (D-{days_left})\n"
                    . "Amount: <b>Rp {installment_amount}</b>\n"
                    . "Installments: {installments_paid}/{installments_total}\n"
                    . "Payee: {payee}\n"
                    . "Status: {status}\n"
                    . "Notes: {notes}",
                'reminder_email_subject_tpl' => 'Payment reminder: {payment_name} (D-{days_left})',
                'reminder_email_body_tpl' => "PAYMENT REMINDER\n"
                    . "Payment: {payment_name}\n"
                    . "Due date: {due_date} (D-{days_left})\n"
                    . "Amount: Rp {installment_amount}\n"
                    . "Installments: {installments_paid}/{installments_total}\n"
                    . "Payee: {payee}\n"
                    . "Status: {status}\n"
                    . "Notes: {notes}\n",
            ],
            'n8n' => [
                'webhook_url' => '',
                'api_key' => '',
                'timeout' => 90,
            ],
        ];
    }

    /**
     * Very small template engine for notification messages.
     * - Replaces {placeholders} using provided context.
     * - Removes unreplaced placeholders.
     */
    private function render_tpl(string $tpl, array $ctx) : string {
        $out = $tpl;
        // Support "\\n" sequences entered in textarea as new lines.
        $out = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $out);
        foreach ($ctx as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        // Remove any leftover {token}
        $out = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $out);
        // Normalize newlines
        $out = preg_replace("/\r\n?/", "\n", (string)$out);
        return trim((string)$out);
    }

    private static function default_charts() : array {
        // A few ready-to-use charts. Public = template visible for all logged-in users.
        $charts = [
            [
                'id' => 'income_vs_outcome_day_7',
                'title' => 'Income vs Expense by Day (Last 7 days)',
                'chart_type' => 'line',
                'x' => 'day',
                'series' => 'kategori',
                'metrics' => [['metric' => 'amount_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 7],
                'filter' => ['kategori' => ['income','expense','outcome']],
                'show_on_dashboard' => 1,
            ],
            [
                'id' => 'by_category_7',
                'title' => 'By Category (Last 7 days)',
                'chart_type' => 'pie',
                'x' => 'kategori',
                'series' => '',
                'metrics' => [['metric' => 'amount_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 7],
                'filter' => ['kategori' => []],
                'show_on_dashboard' => 1,
            ],
            [
                'id' => 'expense_by_day_30',
                'title' => 'Expense by Day (Last 30 days)',
                'chart_type' => 'line',
                'x' => 'day',
                'series' => '',
                'metrics' => [['metric' => 'expense_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 30],
                'filter' => ['kategori' => ['expense','outcome','saving','invest']],
                'show_on_dashboard' => 1,
            ],
            [
                'id' => 'income_by_day_30',
                'title' => 'Income by Day (Last 30 days)',
                'chart_type' => 'area',
                'x' => 'day',
                'series' => '',
                'metrics' => [['metric' => 'income_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 30],
                'filter' => ['kategori' => ['income']],
                'show_on_dashboard' => 0,
            ],
            [
                'id' => 'expense_by_category_30',
                'title' => 'Expense by Category (Last 30 days)',
                'chart_type' => 'donut',
                'x' => 'kategori',
                'series' => '',
                'metrics' => [['metric' => 'amount_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 30],
                'filter' => ['kategori' => ['expense','outcome','saving','invest']],
                'show_on_dashboard' => 1,
            ],
            [
                'id' => 'top_stores_expense_30',
                'title' => 'Top Stores by Expense (Last 30 days)',
                'chart_type' => 'bar',
                'x' => 'nama_toko',
                'series' => '',
                'metrics' => [['metric' => 'amount_total', 'agg' => 'SUM']],
                'range' => ['mode' => 'last_days', 'days' => 30],
                'filter' => ['kategori' => ['expense','outcome','saving','invest'], 'top_n' => 10],
                'show_on_dashboard' => 0,
            ],
        ];

        foreach ($charts as &$c) {
            $c['data_source_mode'] = 'builder';
            $c['is_public'] = 1;
            $c['created_by'] = 0;
            $c['date_basis'] = 'input';
            $c['sql_query'] = '';
            $c['custom_option_json'] = '';
        }
        return $charts;
    }

    /* -------------------- Admin UI -------------------- */

    
    public function admin_menu() : void {
        // Make SIMKU visible for all logged-in users (Subscriber) so they can access Charts.
        // Other menus remain protected by their respective capabilities.
        add_menu_page(
            'SIMKU',
            'SIMKU',
            'read',
            'simku-keuangan',
            [$this, 'page_entry'],
            'dashicons-chart-line',
            56
        );

        // Finance dashboard & management menus (Finance Manager / Admin)
        add_submenu_page('simku-keuangan', 'Dashboard', 'Dashboard', self::CAP_VIEW_TX, 'fl-dashboard', [$this, 'page_dashboard']);
        add_submenu_page('simku-keuangan', 'Transactions', 'Transactions', self::CAP_VIEW_TX, 'fl-transactions', [$this, 'page_transactions']);
        add_submenu_page('simku-keuangan', 'Add Transaction', 'Add Transaction', self::CAP_MANAGE_TX, 'fl-add-transaction', [$this, 'page_add_transaction']);
        add_submenu_page('simku-keuangan', 'Scan Receipt', 'Scan Receipt', self::CAP_MANAGE_TX, 'fl-scan-struk', [$this, 'page_scan_struk']);

        // Savings (Tabungan)
        add_submenu_page('simku-keuangan', 'Savings', 'Savings', self::CAP_VIEW_TX, 'fl-savings', [$this, 'page_savings']);
        add_submenu_page('simku-keuangan', 'Add Saving', 'Add Saving', self::CAP_MANAGE_TX, 'fl-add-saving', [$this, 'page_add_saving']);

        // Payment Reminders (Installments/Billing)
        add_submenu_page('simku-keuangan', 'Reminders', 'Reminders', self::CAP_VIEW_TX, 'fl-reminders', [$this, 'page_reminders']);
        add_submenu_page('simku-keuangan', 'Add Reminder', 'Add Reminder', self::CAP_MANAGE_TX, 'fl-add-reminder', [$this, 'page_add_reminder']);

        add_submenu_page('simku-keuangan', 'Reports', 'Reports', self::CAP_VIEW_REPORTS, 'fl-reports', [$this, 'page_reports']);

        // Charts: available to all logged-in users (Subscriber). "Public" charts are templates; data stays scoped to the current user.
        add_submenu_page('simku-keuangan', 'Charts', 'Charts', 'read', 'fl-charts', [$this, 'page_charts_list']);
        add_submenu_page('simku-keuangan', 'Add Chart', 'Add Chart', 'read', 'fl-add-chart', [$this, 'page_add_chart']);

        add_submenu_page('simku-keuangan', 'Logs', 'Logs', self::CAP_VIEW_LOGS, 'fl-logs', [$this, 'page_logs']);
        add_submenu_page('simku-keuangan', 'Settings', 'Settings', self::CAP_MANAGE_SETTINGS, 'fl-settings', [$this, 'page_settings']);

        // Remove the duplicate top-level submenu item (WordPress adds it automatically).
        remove_submenu_page('simku-keuangan', 'simku-keuangan');

    }
    /**
     * Entry page for the top-level SIMKU menu.
     * - Finance roles: go to Dashboard
     * - Other logged-in roles (Subscriber): go to Charts
     */
    public function page_entry() : void {
        if (!is_user_logged_in()) {
            wp_die('Please login.');
        }
        if (current_user_can(self::CAP_VIEW_TX)) {
            $this->page_dashboard();
            return;
        }
        $this->page_charts_list();
    }

    public function admin_assets($hook) : void {
        if (strpos($hook, 'simku-keuangan') === false && strpos($hook, 'fl-') === false) return;

        wp_enqueue_style('fl-admin', plugins_url('assets/css/admin.css', __FILE__), [], self::VERSION);

        wp_enqueue_script('echarts', 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js', [], self::VERSION, true);
        wp_enqueue_script('fl-admin-charts', plugins_url('assets/js/admin-charts.js', __FILE__), ['jquery','echarts'], self::VERSION, true);

        // Client-side image compression BEFORE upload (prevents nginx 413 when server body size is small).
        wp_enqueue_script('fl-admin-upload', plugins_url('assets/js/admin-upload.js', __FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('fl-admin-upload', 'SIMAK_UPLOAD', [
            // Target under 1.4MB per image by default (can still fail if server limit is extremely small).
            'max_bytes' => 1400 * 1024,
            // Resize long edge to this max.
            'max_dim'   => 1600,
            // Starting quality for JPEG/WebP.
            'quality'   => 0.78,
        ]);

        wp_localize_script('fl-admin-charts', 'SIMAK_AJAX', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fl_nonce'),
        ]);
    }

    public function frontend_assets() : void {
        if (is_admin()) return;

        // Best-effort: hanya load asset kalau halaman ini memang memakai shortcode SIMKU.
        $should = false;
        global $post;
        if ($post instanceof WP_Post) {
            $content = (string)($post->post_content ?? '');
            $tags = [
                'simku','simku_dashboard','simku_transactions','simku_add_transaction',
                'simku_savings','simku_add_saving','simku_reminders','simku_add_reminder',
                'simku_scan_struk','simku_reports','simku_charts','simku_settings','simku_logs',
                'fl_chart'
            ];
            foreach ($tags as $t) {
                if (has_shortcode($content, $t)) { $should = true; break; }
            }
        }
        if (!$should) return;

        // Reuse admin UI styles for frontend embedding.
        wp_enqueue_style('fl-shared', plugins_url('assets/css/admin.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('fl-frontend', plugins_url('assets/css/frontend.css', __FILE__), ['fl-shared'], self::VERSION);

        wp_enqueue_script('echarts', 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js', [], self::VERSION, true);
        wp_enqueue_script('fl-frontend', plugins_url('assets/js/frontend-charts.js', __FILE__), ['jquery','echarts'], self::VERSION, true);
        wp_localize_script('fl-frontend', 'SIMAK_AJAX', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fl_nonce'),
        ]);
    }

    private function settings() : array {
        $s = get_option(self::OPT_SETTINGS, self::default_settings());
        if (!is_array($s)) $s = self::default_settings();

        // Backward compatible: ensure new keys exist.
        $def_n8n = (array)(self::default_settings()['n8n'] ?? ['webhook_url'=>'','api_key'=>'','timeout'=>90]);
        if (!isset($s['n8n']) || !is_array($s['n8n'])) {
            $s['n8n'] = $def_n8n;
        } else {
            foreach ($def_n8n as $k=>$v) {
                if (!array_key_exists($k, $s['n8n'])) $s['n8n'][$k] = $v;
            }
        }

        return $s;
    }

    private function update_settings(array $s) : void {
        update_option(self::OPT_SETTINGS, $s, false);
    }



    /* -------------------- Category helpers -------------------- */

    private function normalize_category(string $cat) : string {
        $cat = strtolower(trim($cat));
        // v0.5.38: rename outcome -> expense (keep backward compatibility)
        if ($cat === 'outcome') return 'expense';
        return $cat;
    }

    /**
     * Expand filters so selecting "expense" still matches legacy "outcome" rows.
     */
    private function expand_category_filter(array $cats) : array {
        $out = [];
        foreach ((array)$cats as $c) {
            $c = $this->normalize_category((string)$c);
            if ($c === '') continue;
            if ($c === 'expense') {
                $out[] = 'expense';
                $out[] = 'outcome';
            } else {
                $out[] = $c;
            }
        }
        $out = array_values(array_unique($out));
        return $out;
    }

    private function category_label(string $cat) : string {
        $cat = $this->normalize_category($cat);
        switch ($cat) {
            case 'income': return 'Income';
            case 'expense': return 'Expense';
            case 'saving': return 'Saving';
            case 'invest': return 'Invest';
            default:
                return $cat !== '' ? ucfirst($cat) : '';
        }
	}


    /**
     * Prettify dimension values for chart display (without changing stored data).
     * Currently used to display category codes as friendly labels.
     */
    private function pretty_dim_label(string $v) : string {
        $raw = trim($v);
        if ($raw === '') return $v;

        $l = strtolower($raw);
        if ($l === 'outcome') $l = 'expense';

        if (in_array($l, ['income','expense','saving','invest'], true)) {
            return $this->category_label($l);
        }

        if ($l === '(uncategorized)') return '(Uncategorized)';

        return $v;
    }

    

    /* -------------------- Date/Time helpers -------------------- */

    /**
     * Storage timezone follows WordPress site setting (Settings → General → Timezone).
     * Display timezone normally follows the same setting, but for Indonesian locale sites
     * that are still set to UTC, we fall back to Asia/Jakarta to match user expectations.
     */
    private function simku_storage_tz() : \DateTimeZone {
        return wp_timezone();
    }

    private function simku_display_tz() : \DateTimeZone {
        $tz = wp_timezone();
        $name = $tz ? $tz->getName() : 'UTC';

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (is_string($locale) && stripos($locale, 'id') === 0) {
            if (in_array($name, ['UTC','Etc/UTC','GMT','Etc/GMT','Etc/GMT+0','Etc/GMT-0'], true)) {
                try { return new \DateTimeZone('Asia/Jakarta'); } catch (\Exception $e) {}
            }
        }

        return $tz ?: new \DateTimeZone('UTC');
    }

    private function fmt_mysql_dt_display(string $dt, string $format = 'Y-m-d H:i:s') : string {
        $dt = trim($dt);
        if ($dt === '' || $dt === '0000-00-00 00:00:00') return '';
        try {
            $storage = $this->simku_storage_tz();
            $display = $this->simku_display_tz();

            $d = \DateTime::createFromFormat('Y-m-d H:i:s', $dt, $storage);
            if (!$d) $d = new \DateTime($dt, $storage);
            $d->setTimezone($display);
            return $d->format($format);
        } catch (\Exception $e) {
            return $dt;
        }
    }

    private function dtlocal_value_from_mysql(string $dt) : string {
        $dt = trim($dt);
        if ($dt === '' || $dt === '0000-00-00 00:00:00') return '';
        try {
            $storage = $this->simku_storage_tz();
            $display = $this->simku_display_tz();

            $d = \DateTime::createFromFormat('Y-m-d H:i:s', $dt, $storage);
            if (!$d) $d = new \DateTime($dt, $storage);
            $d->setTimezone($display);
            // datetime-local expects 2026-01-03T20:50
            return $d->format('Y-m-d\TH:i');
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Parse a datetime string coming from UI (datetime-local) which is assumed to be in display timezone,
     * then convert it to storage timezone for DB storage.
     */
    private function mysql_from_ui_datetime(string $val) : string {
        $val = trim((string)$val);
        if ($val === '') return '';

        $display = $this->simku_display_tz();
        $storage = $this->simku_storage_tz();

        $formats = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'];
        foreach ($formats as $f) {
            $d = \DateTime::createFromFormat($f, $val, $display);
            if ($d instanceof \DateTime) {
                $d->setTimezone($storage);
                return $d->format('Y-m-d H:i:s');
            }
        }

        // Fallback: strtotime (best-effort)
        $ts = strtotime($val);
        if ($ts) {
            return wp_date('Y-m-d H:i:s', $ts, $storage);
        }

        return sanitize_text_field($val);
    }


    /* -------------------- Datasource helpers -------------------- */

    private function ds_table() : string {
        $s = $this->settings();
        // Single "Table" field used for both internal & external.
        // Default to the plugin internal table name.
        $t = $s['external']['table'] ?? 'fl_transactions';
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$t);
        if (!$t) {
            global $wpdb;
            return $wpdb->prefix . 'fl_transactions';
        }

        // Internal mode: users often input table name without WP prefix.
        // Auto-resolve and fallback to known internal tables if setting is wrong.
        if (!$this->ds_is_external()) {
            global $wpdb;

            $candidates = array_values(array_unique([
                $t,
                $wpdb->prefix . $t,
                $wpdb->prefix . 'fl_transactions',
                $wpdb->prefix . 'finance_transactions',
            ]));

            foreach ($candidates as $cand) {
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cand));
                if ($exists) return $cand;
            }

            // Ultimate fallback (most common)
            return $wpdb->prefix . 'fl_transactions';
        }

        return $t;
    }

    /* -------------------- Savings datasource helpers -------------------- */

    private function savings_mode() : string {
        $s = $this->settings();
        $m = (string)($s['savings']['mode'] ?? 'same');
        if (!in_array($m, ['same','internal','external'], true)) $m = 'same';
        return $m;
    }

    private function savings_is_external() : bool {
        $m = $this->savings_mode();
        if ($m === 'external') return true;
        if ($m === 'internal') return false;
        // same
        return $this->ds_is_external();
    }

    private function savings_db() {
        if ($this->savings_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function savings_table() : string {
        $s = $this->settings();
        if ($this->savings_is_external()) {
            $t = (string)($s['savings']['external_table'] ?? 'finance_savings');
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
            if (!$t) $t = 'finance_savings';
            return $t;
        }
        global $wpdb;
        return $wpdb->prefix . 'fl_savings';
    }

    private function savings_date_column($db = null, ?string $table = null) : string {
        // Backward compatibility: some older versions created `tanggal_input` instead of `saved_at`.
        static $cache = [];
        if (!$db) $db = $this->savings_db();
        if (!($db instanceof wpdb)) return 'saved_at';
        if (!$table) $table = $this->savings_table();
        $key = spl_object_hash($db) . '|' . $table;
        if (isset($cache[$key])) return $cache[$key];
        $has_saved_at = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'saved_at'));
        if ($has_saved_at) return $cache[$key] = 'saved_at';
        $has_tanggal = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'tanggal_input'));
        if ($has_tanggal) return $cache[$key] = 'tanggal_input';
        return $cache[$key] = 'saved_at';
    }

    /* -------------------- Payment reminders datasource helpers -------------------- */

    private function reminders_mode() : string {
        $s = $this->settings();
        $m = (string)($s['reminders']['mode'] ?? 'same');
        if (!in_array($m, ['same','internal','external'], true)) $m = 'same';
        return $m;
    }

    private function reminders_is_external() : bool {
        $m = $this->reminders_mode();
        if ($m === 'external') return true;
        if ($m === 'internal') return false;
        return $this->ds_is_external();
    }

    private function reminders_db() {
        if ($this->reminders_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function reminders_table() : string {
        $s = $this->settings();
        if ($this->reminders_is_external()) {
            $t = (string)($s['reminders']['external_table'] ?? 'finance_payment_reminders');
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
            if (!$t) $t = 'finance_payment_reminders';
            return $t;
        }
        global $wpdb;
        return $wpdb->prefix . 'fl_payment_reminders';
    }

    /**
     * Ensure default charts exist after upgrades / option resets.
     * This prevents dashboard "Request failed" when chart defs are missing.
     */
    public function maybe_seed_defaults() : void {
        if (!current_user_can(self::CAP_MANAGE_SETTINGS)) return;

        // Upgrade internal reminders schema if upgrading from older versions.
        $this->maybe_migrate_internal_reminders_schema();
        $this->maybe_add_internal_reminders_image_column();

        // Ensure internal Savings table exists even after plugin updates (activation hook may not run).
        // Safe to run repeatedly.
        global $wpdb;
        // Always ensure the *internal* WP table exists, regardless of Savings datasource mode.
        $sv_table = $wpdb->prefix . 'fl_savings';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sv_table));
        if ($exists !== $sv_table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $sv_sql = "CREATE TABLE {$sv_table} (
                line_id VARCHAR(80) NOT NULL,
                saving_id VARCHAR(64) NOT NULL,
                account_name VARCHAR(255) NOT NULL,
                amount BIGINT NOT NULL DEFAULT 0,
                institution VARCHAR(255) NULL,
                notes LONGTEXT NULL,
                saved_at DATETIME NOT NULL,
                wp_user_id BIGINT UNSIGNED NULL,
                wp_user_login VARCHAR(60) NULL,
                PRIMARY KEY  (line_id),
                KEY saved_at (saved_at),
                KEY wp_user_id (wp_user_id)
            ) {$charset};";
            dbDelta($sv_sql);
        }

        // Ensure internal Payment Reminders table exists (updates may skip activation hook).
        $rm_table = $wpdb->prefix . 'fl_payment_reminders';
        $rm_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $rm_table));
        if ($rm_exists !== $rm_table) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $charset = $wpdb->get_charset_collate();
            $rm_sql = "CREATE TABLE {$rm_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                line_id VARCHAR(80) NOT NULL,
                reminder_id VARCHAR(64) NOT NULL,
                payment_name VARCHAR(255) NOT NULL,
                total_amount BIGINT NULL,
                installment_amount BIGINT NOT NULL,
                installments_total INT NOT NULL DEFAULT 1,
                installments_paid INT NOT NULL DEFAULT 0,
                schedule_mode VARCHAR(10) NOT NULL DEFAULT 'manual',
                due_day TINYINT UNSIGNED NULL,
                due_date DATE NOT NULL,
                payee VARCHAR(255) NULL,
                notes LONGTEXT NULL,
                gambar_url LONGTEXT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'belum',
                notify_telegram TINYINT UNSIGNED NOT NULL DEFAULT 1,
                notify_whatsapp TINYINT UNSIGNED NOT NULL DEFAULT 0,
                notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0,
                notified_for_due DATE NULL,
                notified_offsets VARCHAR(32) NULL,
                last_notified_at DATETIME NULL,
                wp_user_id BIGINT UNSIGNED NULL,
                wp_user_login VARCHAR(60) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY line_id (line_id),
                KEY reminder_id (reminder_id),
                KEY due_date (due_date),
                KEY status (status)
            ) {$charset};";
            dbDelta($rm_sql);
        }

        // Ensure reminder cron is scheduled.
        if (!wp_next_scheduled('simak_check_payment_reminders_hourly')) {
            wp_schedule_event(time() + 600, 'hourly', 'simak_check_payment_reminders_hourly');
        }

        $charts = get_option(self::OPT_CHARTS, null);
        if (!is_array($charts) || count($charts) === 0) {
            $charts = self::default_charts();
            update_option(self::OPT_CHARTS, $charts, false);
        }

        // Migrate legacy chart titles (Outcome -> Expense) for existing installs.
        if (is_array($charts) && $charts) {
            $changed = false;
            foreach ($charts as &$c) {
                if (!empty($c['title'])) {
                    $t = (string)$c['title'];
                    $t2 = str_ireplace('Outcome', 'Expense', $t);
                    if ($t2 !== $t) { $c['title'] = $t2; $changed = true; }
                }
            }
            unset($c);
            if ($changed) update_option(self::OPT_CHARTS, $charts, false);
        }


        // Seed dashboard list from charts flagged "show_on_dashboard".
        $dash = get_option(self::OPT_DASH_CHARTS, null);
        if (!is_array($dash) || count($dash) === 0) {
            $dash_ids = [];
            foreach ($charts as $c) {
                if (!empty($c['show_on_dashboard']) && !empty($c['id'])) $dash_ids[] = (string)$c['id'];
            }
            if ($dash_ids) update_option(self::OPT_DASH_CHARTS, $dash_ids, false);
        }
    }



/**
 * Add optional image column to INTERNAL payment reminders table.
 * Runs on admin loads via maybe_seed_defaults so updates don't require deactivate/activate.
 */
private function maybe_add_internal_reminders_image_column() : void {
    if ($this->reminders_is_external()) return;
    global $wpdb;
    $table = $wpdb->prefix . 'fl_payment_reminders';
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return;
    $has = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'gambar_url'));
    if ($has) return;
    // Add after notes for readability.
    $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `gambar_url` LONGTEXT NULL AFTER `notes`");
}

    /**
     * Internal reminders table was initially created with line_id as PRIMARY KEY.
     * Newer versions use an auto-increment numeric id as PRIMARY KEY (and keep line_id UNIQUE).
     *
     * This migrates ONLY the internal WP DB table. External reminders tables are not modified.
     */
    private function maybe_migrate_internal_reminders_schema() : void {
        if ($this->ds_is_external()) return;

        global $wpdb;
        $table = $wpdb->prefix . 'fl_payment_reminders';
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) return;

        // If already has `id` column, assume schema is up-to-date.
        $has_id = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'id'));
        if ($has_id) return;

        // Create new table with desired schema.
        $charset = $wpdb->get_charset_collate();
        $tmp = $table . '_new';

        // Drop any stale tmp table.
        $wpdb->query("DROP TABLE IF EXISTS `{$tmp}`");

        $create = "CREATE TABLE `{$tmp}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            line_id VARCHAR(80) NOT NULL,
            reminder_id VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            total_installments INT NOT NULL DEFAULT 1,
            installment_index INT NOT NULL DEFAULT 0,
            nominal BIGINT NOT NULL DEFAULT 0,
            due_date DATE NOT NULL,
            notes TEXT NULL,
            auto_send TINYINT(1) NOT NULL DEFAULT 1,
            notify_telegram TINYINT(1) NOT NULL DEFAULT 0,
            notify_email TINYINT(1) NOT NULL DEFAULT 0,
            notify_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'Unpaid',
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY line_id (line_id),
            KEY reminder_id (reminder_id),
            KEY due_date (due_date),
            KEY status (status)
        ) {$charset};";

        $ok = $wpdb->query($create);
        if ($ok === false) return;

        // Copy data from old table. Old schema doesn't have `id`, so it will auto-generate.
        // Some sites might already have optional user columns; copy them if present.
        $has_user_id = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_id'));
        $has_user_login = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_login'));

        $select_user_id = $has_user_id ? 'wp_user_id' : 'NULL AS wp_user_id';
        $select_user_login = $has_user_login ? 'wp_user_login' : 'NULL AS wp_user_login';

        $copy = "INSERT INTO `{$tmp}` (
            line_id, reminder_id, name, total_installments, installment_index, nominal, due_date, notes,
            auto_send, notify_telegram, notify_email, notify_whatsapp, status,
            wp_user_id, wp_user_login, created_at, updated_at
        )
        SELECT
            line_id, reminder_id, name, total_installments, installment_index, nominal, due_date, notes,
            auto_send, notify_telegram, notify_email, notify_whatsapp, status,
            {$select_user_id}, {$select_user_login}, created_at, updated_at
        FROM `{$table}`;";
        $wpdb->query($copy);

        // Atomically swap tables (keep a backup).
        $backup = $table . '_bak_' . gmdate('Ymd_His');
        $wpdb->query("RENAME TABLE `{$table}` TO `{$backup}`, `{$tmp}` TO `{$table}`");
    }

    private function ds_is_external() : bool {
        $s = $this->settings();
        return (($s['datasource_mode'] ?? 'external') === 'external');
    }

    private function ds_allow_write_external() : bool {
        $s = $this->settings();
        return !empty($s['external']['allow_write']);
    }

    private function ext_db() {
        $s = $this->settings();
        $ext = $s['external'] ?? [];
        $host = $ext['host'] ?? '';
        $db   = $ext['db'] ?? '';
        $user = $ext['user'] ?? '';
        $pass = $ext['pass'] ?? '';
        if (!$host || !$db || !$user) return null;
        $wpdb_ext = new wpdb($user, $pass, $db, $host);
        $wpdb_ext->set_prefix(''); // no prefix
        $wpdb_ext->show_errors(false);
        return $wpdb_ext;
    }

    private function ds_db() {
        if ($this->ds_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function ds_ready() : bool {
        $db = $this->ds_db();
        return $db instanceof wpdb;
    }

    /**
     * Check whether a column exists on the current datasource table.
     * Works for both internal (wpdb) and external (ext_db) modes.
     */
    private function ds_column_exists($col) : bool {
        $db = $this->ds_db();
        if (!($db instanceof wpdb)) return false;
        $table = $this->ds_table();
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
        if (!$col) return false;
        $row = $db->get_row($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $col));
        return !empty($row);
    }


private function reminders_column_exists($col) : bool {
    global $wpdb;
    $table = $this->reminders_table();
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
    $row = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'", ARRAY_A);
    return !empty($row);
}

    private function ext_column_exists($col) : bool {
        $db = $this->ext_db();
        if (!$db) return false;
        $table = $this->ds_table();
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        $row = $db->get_row($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $col));
        return !empty($row);
    }

    private function ensure_external_user_columns() : array {
        // returns [ok(bool), messages(array)]
        $msgs = [];
        if (!$this->ds_is_external()) return [true, $msgs];

        $db = $this->ext_db();
        if (!$db) return [false, ['External DB not configured.']];

        $table = $this->ds_table();
        $need = [];
        if (!$this->ext_column_exists('wp_user_id')) $need[] = "ADD COLUMN `wp_user_id` BIGINT UNSIGNED NULL";
        if (!$this->ext_column_exists('wp_user_login')) $need[] = "ADD COLUMN `wp_user_login` VARCHAR(60) NULL";
        if (!$need) return [true, ['User columns already exist.']];

        if (!$this->ds_allow_write_external()) {
            return [false, ['External datasource is read-only. Enable "Allow write to external" to run migration.']];
        }

        $sql = "ALTER TABLE `{$table}` " . implode(", ", $need);
        $res = $db->query($sql);
        if ($res === false) {
            $msgs[] = 'Failed to alter table. Check privileges and table name.';
            return [false, $msgs];
        }
        $msgs[] = 'Migration applied: added wp_user_id/wp_user_login.';
        return [true, $msgs];
    }

    /**
     * Upload gambar struk (receipt) dari form Add/Edit Transaction.
     *
     * Return:
     * - ['ok' => true,  'url' => 'https://...', 'error' => ''] jika upload berhasil
     * - ['ok' => false, 'url' => '',          'error' => ''] jika tidak ada file
     * - ['ok' => false, 'url' => '',          'error' => '...'] jika ada error
     */
    private function handle_tx_image_upload(string $field_name) : array {
        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['tmp_name'])) {
            return ['ok' => false, 'url' => '', 'error' => ''];
        }

        $file = $_FILES[$field_name];
        if (!empty($file['error'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Upload gagal (kode error: '.$file['error'].').'];
        }

        // Only allow images.
        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = [
            'test_form' => false,
            'mimes' => $mimes,
        ];

        $upload = wp_handle_upload($file, $overrides);
        if (empty($upload) || !empty($upload['error'])) {
            $err = is_array($upload) ? ($upload['error'] ?? 'Unknown error') : 'Unknown error';
            return ['ok' => false, 'url' => '', 'error' => 'Upload gambar gagal: '.$err];
        }

        if (empty($upload['url'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Upload gambar gagal: URL kosong.'];
        }

        // Optimize image to reduce disk usage.
        if (!empty($upload['file'])) {
            $this->optimize_uploaded_image_file((string)$upload['file']);
        }

        return ['ok' => true, 'url' => esc_url_raw((string)$upload['url']), 'error' => ''];
    }

    /**
     * Optimize uploaded image on server (resize + compress) before it is used/stored.
     *
     * - Resize to max width to reduce disk/transfer
     * - Apply JPEG/WebP quality
     * - Keeps the same file path (overwrite)
     *
     * Notes:
     * - We skip GIF to avoid breaking animations.
     * - If server lacks GD/Imagick, this will silently no-op.
     */
    
/**
 * Normalize stored images field (single URL, JSON array, or comma/newline separated list) into array of URLs.
 *
 * @param mixed $val
 * @return array
 */
private function normalize_images_field($val) : array
{
    if (is_array($val)) {
        $items = $val;
    } else {
        $val = trim((string)$val);
        if ($val === '') {
            return [];
        }

        $items = null;

        // JSON array support (new format).
        if ($val !== '' && ($val[0] === '[' || $val[0] === '{')) {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                if (isset($decoded['urls']) && is_array($decoded['urls'])) {
                    $items = $decoded['urls'];
                } else {
                    $items = $decoded;
                }
            }
        }

        // Fallback: comma/newline separated.
        if ($items === null) {
            $items = preg_split('/[\r\n,]+/', $val);
        }
    }

    $out = [];
    foreach ((array)$items as $u) {
        if (!is_string($u)) {
            continue;
        }
        $u = trim($u);
        if ($u === '') {
            continue;
        }

        // Allow absolute URLs; keep relative URLs as-is.
        if (strpos($u, 'http://') === 0 || strpos($u, 'https://') === 0) {
            $u = esc_url_raw($u);
        }

        if (!empty($u)) {
            $out[] = $u;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Encode image URL list back into DB field value.
 *
 * Backward compatible:
 * - 0 images  => ''
 * - 1 image   => single URL string
 * - 2+ images => JSON array
 */
private function images_to_db_value(array $urls) : string
{
    $urls = $this->normalize_images_field($urls);
    $n = count($urls);
    if ($n === 0) {
        return '';
    }
    if ($n === 1) {
        return $urls[0];
    }
    return wp_json_encode($urls);
}

/**
 * Parse textarea input containing image URLs (1 per line).
 */
private function parse_image_urls_textarea($raw) : array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $out = [];
    foreach ($lines as $l) {
        $l = trim((string) $l);
        if ($l !== '') {
            $out[] = $l;
        }
    }
    return $this->normalize_images_field($out);
}

/**
 * Handle multi-image upload and return uploaded URLs.
 *
 * @param string $field_name
 * @return array{ok:bool, urls:array, error:string}
 */
private function handle_multi_image_upload(string $field_name) : array
{
    if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
        return ['ok' => false, 'urls' => [], 'error' => ''];
    }

    // If it's a single upload (non-multiple), reuse single handler.
    if (!is_array($_FILES[$field_name]['name'])) {
        $single = $this->handle_tx_image_upload($field_name);
        if (!empty($single['ok'])) {
            return ['ok' => true, 'urls' => [$single['url']], 'error' => ''];
        }
        return ['ok' => false, 'urls' => [], 'error' => $single['error'] ?? ''];
    }

    $urls = [];
    $errors = [];
    $count = count($_FILES[$field_name]['name']);

    for ($i = 0; $i < $count; $i++) {
        if (empty($_FILES[$field_name]['tmp_name'][$i])) {
            continue;
        }

        $file = [
            'name'     => $_FILES[$field_name]['name'][$i],
            'type'     => $_FILES[$field_name]['type'][$i],
            'tmp_name' => $_FILES[$field_name]['tmp_name'][$i],
            'error'    => $_FILES[$field_name]['error'][$i],
            'size'     => $_FILES[$field_name]['size'][$i],
        ];

        if (!empty($file['error'])) {
            $errors[] = $file['name'] . ' (error: ' . $file['error'] . ')';
            continue;
        }

        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];
        $check = wp_check_filetype($file['name'], $mimes);
        if (empty($check['type'])) {
            $errors[] = $file['name'] . ' (file bukan gambar)';
            continue;
        }

        $upload = wp_handle_upload($file, ['test_form' => false]);
        if (!is_array($upload) || !empty($upload['error'])) {
            $errors[] = $file['name'] . ' (' . ($upload['error'] ?? 'upload gagal') . ')';
            continue;
        }

        // Optimize if possible.
        $this->optimize_uploaded_image_file($upload['file']);

        $url = esc_url_raw($upload['url']);
        if (!empty($url)) {
            $urls[] = $url;
        }
    }

    $urls = array_values(array_unique($urls));
    $err = $errors ? implode('; ', $errors) : '';

    return ['ok' => !empty($urls), 'urls' => $urls, 'error' => $err];
}

private function optimize_uploaded_image_file(string $file_path, int $max_width = 1920, int $quality = 80) : void {
        if (!$file_path || !is_string($file_path) || !file_exists($file_path)) {
            return;
        }

        // Skip tiny files to save CPU.
        $fs = @filesize($file_path);
        if ($fs !== false && (int)$fs > 0 && (int)$fs < 200 * 1024) {
            return;
        }

        // Detect mime type.
        $ft = wp_check_filetype_and_ext($file_path, basename($file_path));
        $mime = (string)($ft['type'] ?? '');

        // Skip unsupported or risky formats.
        if (!$mime || $mime === 'image/gif') {
            return;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }

        // Ensure image editor functions are available.
        if (!function_exists('wp_get_image_editor')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor($file_path);
        if (is_wp_error($editor) || !$editor) {
            return;
        }

        $size = $editor->get_size();
        $w = is_array($size) ? (int)($size['width'] ?? 0) : 0;
        if ($w > 0 && $w > $max_width) {
            // Keep aspect ratio.
            $editor->resize($max_width, null, false);
        }

        // Apply quality (works for JPEG/WebP; PNG may ignore or map to compression internally).
        if ($quality >= 1 && $quality <= 100) {
            $editor->set_quality($quality);
        }

        // Overwrite the same file path.
        $editor->save($file_path);
    }




    /**
     * Test datasource connectivity and basic schema.
     * Returns [ok(bool), message(string)]
     */
    private function test_connection_from_settings(array $s) : array {
        // Internal WP DB
        if (($s['datasource_mode'] ?? 'external') === 'internal') {
            global $wpdb;
            $table = $wpdb->prefix . 'fl_transactions';
            $ok = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
            if (!$ok) {
                return [false, 'Internal table not found: '.$table.'. Click “Create Internal Table”.'];
            }
            $ping = $wpdb->get_var("SELECT 1");
            if ((string)$ping !== '1') {
                return [false, 'Internal DB connection check failed.'];
            }
            return [true, 'Internal datasource OK (WP DB + table exists).'];
        }

        // External
        $ext = $s['external'] ?? [];
        $host = trim((string)($ext['host'] ?? ''));
        $dbn  = trim((string)($ext['db'] ?? ''));
        $user = trim((string)($ext['user'] ?? ''));
        $pass = (string)($ext['pass'] ?? '');
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($ext['table'] ?? 'finance_transactions'));
        if (!$host || !$dbn || !$user) {
            return [false, 'External DB not configured (host/db/user required).'];
        }
        if (!$table) $table = 'finance_transactions';

        $wpdb_ext = new wpdb($user, $pass, $dbn, $host);
        $wpdb_ext->set_prefix('');
        $wpdb_ext->show_errors(false);
        $wpdb_ext->suppress_errors(true);

        $ping = $wpdb_ext->get_var("SELECT 1");
        if ((string)$ping !== '1') {
            $err = $wpdb_ext->last_error ?: 'Unknown error';
            return [false, 'External DB connection failed: '.$err];
        }

        // Check table + columns
        $cols = $wpdb_ext->get_results("DESCRIBE `{$table}`", ARRAY_A);
        if (!$cols || !is_array($cols)) {
            $err = $wpdb_ext->last_error ?: 'Table not found or no privileges';
            return [false, "Connected, but cannot read table `{$table}`: ".$err];
        }

        $have = [];
        foreach ($cols as $c) $have[] = (string)($c['Field'] ?? '');
        $required = ['line_id','transaction_id','nama_toko','items','quantity','harga','kategori','tanggal_input','tanggal_struk','gambar_url','description'];
        $missing = array_values(array_diff($required, $have));
        if ($missing) {
            return [false, 'Connected, but table schema mismatch. Missing columns: '.implode(', ', $missing)];
        }

        $notes = [];

        $notes = [];

        $optional_missing = [];
        foreach (['wp_user_id','wp_user_login'] as $oc) {
            if (!in_array($oc, $have, true)) $optional_missing[] = $oc;
        }
        if ($optional_missing) {
            $notes[] = 'Transactions table missing optional user columns (use “Run Migration”): '.implode(', ', $optional_missing);
        }

        // Optional: check External Savings table (if Savings mode is External, or Same-as-Transactions while Transactions mode is External)
        $savings_mode = (string)($s['savings']['mode'] ?? 'same');
        $savings_is_ext = ($savings_mode === 'external' || ($savings_mode === 'same' && ($s['datasource_mode'] ?? 'external') === 'external'));
        if ($savings_is_ext) {
            $sv_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)(($s['savings']['external_table'] ?? '') ?: 'finance_savings'));
            if (!$sv_table) $sv_table = 'finance_savings';
            $sv_cols = $wpdb_ext->get_results("DESCRIBE `{$sv_table}`", ARRAY_A);
            if (!$sv_cols || !is_array($sv_cols)) {
                $err = $wpdb_ext->last_error ?: 'Table not found or no privileges';
                return [false, "Connected, but cannot read savings table `{$sv_table}`: ".$err];
            }
            $sv_have = [];
            foreach ($sv_cols as $c) $sv_have[] = (string)($c['Field'] ?? '');
            $sv_required = ['line_id','saving_id','account_name','amount','institution','notes'];
            $sv_missing = array_values(array_diff($sv_required, $sv_have));
            if (!in_array('saved_at', $sv_have, true) && !in_array('tanggal_input', $sv_have, true)) {
                $sv_missing[] = 'saved_at (or tanggal_input)';
            }
            if ($sv_missing) {
                return [false, 'Connected, but savings table schema mismatch. Missing columns: '.implode(', ', array_unique($sv_missing))];
            }

            $sv_optional_missing = [];
            foreach (['wp_user_id','wp_user_login'] as $oc) {
                if (!in_array($oc, $sv_have, true)) $sv_optional_missing[] = $oc;
            }
            if ($sv_optional_missing) {
                $notes[] = 'Savings table missing optional user columns: '.implode(', ', $sv_optional_missing);
            }
        }

        if ($notes) {
            return [true, 'External datasource OK. Notes: '.implode(' | ', $notes)];
        }

        return [true, 'External datasource OK (connected + table schema valid).'];
    }

    private function create_internal_transactions_table() : array {
        // returns [ok, message]
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'fl_transactions';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            line_id VARCHAR(80) NOT NULL,
            transaction_id VARCHAR(64) NOT NULL,
            nama_toko VARCHAR(255) NULL,
            items VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            harga BIGINT NOT NULL,
            kategori VARCHAR(20) NULL,
            tanggal_input DATETIME NOT NULL,
            tanggal_struk DATE NULL,
            gambar_url TEXT NULL,
            description LONGTEXT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            PRIMARY KEY (line_id),
            KEY transaction_id (transaction_id),
            KEY kategori (kategori),
            KEY tanggal_struk (tanggal_struk)
        ) {$charset};";
        dbDelta($sql);

        $ok = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        return $ok ? [true, 'Internal table created/updated.'] : [false, 'Failed to create internal table. Check DB privileges.'];
    }


    /* -------------------- Logging -------------------- */

    private function log_event(string $action, string $object_type, ?string $object_id = null, $details = null) : void {
        global $wpdb;
        $table = $wpdb->prefix . 'fl_logs';
        $user = wp_get_current_user();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $wpdb->insert($table, [
            'created_at' => current_time('mysql'),
            'user_id' => $user && $user->ID ? $user->ID : null,
            'user_login' => $user && $user->user_login ? $user->user_login : null,
            'action' => $action,
            'object_type' => $object_type,
            'object_id' => $object_id,
            'details' => is_string($details) ? $details : ($details ? wp_json_encode($details) : null),
            'ip' => $ip,
        ], ['%s','%d','%s','%s','%s','%s','%s','%s']);
    }

    public function on_login($user_login, $user) : void {
        // $user can be WP_User or string in older hooks; guard.
        $this->log_event('login', 'user', is_object($user) ? (string)$user->ID : null, ['user_login' => $user_login]);
    }

    public function on_logout() : void {
        $user = wp_get_current_user();
        $this->log_event('logout', 'user', $user && $user->ID ? (string)$user->ID : null, ['user_login' => $user->user_login ?? null]);
    }

    /* -------------------- Notifications -------------------- */

    private function telegram_send(string $message) : bool {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        if (empty($n['telegram_enabled']) || empty($n['telegram_bot_token']) || empty($n['telegram_chat_id'])) return false;

        $token = trim($n['telegram_bot_token']);
        $chat  = trim($n['telegram_chat_id']);

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $resp = wp_remote_post($url, [
            'timeout' => 12,
            // Some shared hostings have incomplete CA bundles; allow Telegram to work.
            'sslverify' => false,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'chat_id' => $chat,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);
        if (is_wp_error($resp)) {
            $this->log_event('notify_fail', 'telegram', null, ['error' => $resp->get_error_message()]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            $this->log_event('notify_fail', 'telegram', null, ['http_code' => $code, 'body' => wp_remote_retrieve_body($resp)]);
            return false;
        }
        $this->log_event('notify_ok', 'telegram', null, ['chat_id' => $chat]);
        return true;
    }

    private function whatsapp_webhook_send(array $payload) : bool {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        $url = trim($n['whatsapp_webhook'] ?? '');
        if (!$url) return false;

        $resp = wp_remote_post($url, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        return !is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) < 300;
    }

    private function email_send(string $subject, string $message) : bool {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        if (empty($n['email_enabled']) || empty($n['email_to'])) return false;
        $to = trim($n['email_to']);
        $ok = (bool) wp_mail($to, $subject, $message);
        if ($ok) {
            $this->log_event('notify_ok', 'email', null, ['to' => $to, 'subject' => $subject]);
        } else {
            $this->log_event('notify_fail', 'email', null, ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }

private function send_telegram_new_tx(array $ctx) : bool {
    $s = $this->settings();
    $tpl = (string)($s['notify']['telegram_new_tpl'] ?? '');
    if (!$tpl) $tpl = self::default_settings()['notify']['telegram_new_tpl'];
    $msg = $this->render_tpl($tpl, $ctx);
    return $this->telegram_send($msg);
}

private function send_email_new_tx(array $ctx) : bool {
    $s = $this->settings();
    $subj_tpl = (string)($s['notify']['email_new_subject_tpl'] ?? '');
    $body_tpl = (string)($s['notify']['email_new_body_tpl'] ?? '');
    if (!$subj_tpl) $subj_tpl = self::default_settings()['notify']['email_new_subject_tpl'];
    if (!$body_tpl) $body_tpl = self::default_settings()['notify']['email_new_body_tpl'];
    $subject = $this->render_tpl($subj_tpl, $ctx);
    $body = $this->render_tpl($body_tpl, $ctx);
    return $this->email_send($subject, $body);
}


    /* -------------------- Limit checks -------------------- */

    private function get_expense_categories() : array {
        $s = $this->settings();
        $cats = $s['limits']['expense_categories'] ?? ['expense','saving','invest'];
        $cats = array_values(array_filter(array_map('strval', (array)$cats)));
        $cats = $this->expand_category_filter($cats);
        return $cats ?: ['expense','outcome','saving','invest'];
    }

    private function sanitize_date_basis(string $basis) : string {
        $basis = strtolower(trim($basis));
        return in_array($basis, ['input','receipt'], true) ? $basis : 'input';
    }

    /**
     * Return SQL expression for picking the date basis.
     * - input  : tanggal_input (DATETIME)
     * - receipt : tanggal_struk if valid, else DATE(tanggal_input)
     */
    
private function date_basis_expr(string $basis) : string {
    $basis = $this->sanitize_date_basis($basis);
    if ($basis === 'receipt') {
        return "COALESCE(NULLIF(tanggal_struk,'0000-00-00'), DATE(tanggal_input))";
    }
    return 'tanggal_input';
}

/**
 * Resolve the user/login column name for the active datasource.
 * Internal table uses `wp_user_login`, while some external tables use `user`/`username`.
 */
private function tx_user_col() : string {
    foreach (['wp_user_login','user_login','user','username'] as $c) {
        if ($this->ds_column_exists($c)) return $c;
    }
    return '';
}

private function calc_totals_between(string $start_dt, string $end_dt, string $date_basis = 'input', ?string $user_login = null) : array {
        // start_dt/end_dt are inclusive/exclusive.
        // - input: use 'Y-m-d H:i:s'
        // - receipt: use 'Y-m-d'
        $db = $this->ds_db();
        if (!$db) return ['income' => 0, 'expense' => 0, 'by_cat' => []];

        $table = $this->ds_table();
        $expense_cats = $this->get_expense_categories();
        if (empty($expense_cats)) {
            // Safety fallback: avoid invalid SQL when no categories are configured.
            $expense_cats = ['expense'];
        }
        $cats_in = implode(',', array_fill(0, count($expense_cats), '%s'));

        $date_expr = $this->date_basis_expr($date_basis);
        $where_sql = "{$date_expr} >= %s AND {$date_expr} < %s";

        // NOTE: placeholder order matters (left-to-right) — category placeholders appear
        // in the SELECT before the date placeholders in the WHERE.
        $params = array_merge($expense_cats, [$start_dt, $end_dt]);

        // Optional user filter (matches Reports user dropdown).
        // NOTE: Some installs store user as a numeric WP user ID (wp_user_id)
        // instead of a login string. In that case, resolve login -> ID.
        $user_col = $this->tx_user_col();
        if ($user_login !== null && $user_login !== '' && $user_login !== 'all' && $user_login !== '0' && $user_col) {
            $u_login = strtolower(trim((string)$user_login));

            // Some environments submit a numeric WP user ID from the reports dropdown.
            // If our transactions table stores the user as a *login string*,
            // translate that ID back to user_login so filtering works.
            if (ctype_digit($u_login) && !($user_col === 'wp_user_id' || substr($user_col, -3) === '_id')) {
                $u_obj_by_id = get_user_by('id', (int) $u_login);
                if ($u_obj_by_id && !empty($u_obj_by_id->user_login)) {
                    $u_login = strtolower((string) $u_obj_by_id->user_login);
                }
            }

            // Numeric user id columns.
            $is_id_col = ($user_col === 'wp_user_id') || (substr($user_col, -3) === '_id');
            if ($is_id_col) {
                $u_obj = get_user_by('login', $u_login);
                $u_id = $u_obj ? (int) $u_obj->ID : 0;
                if ($u_id > 0) {
                    $where_sql .= " AND `{$user_col}` = %d";
                    $params[] = $u_id;
                } else {
                    // Unknown user -> force empty result.
                    $where_sql .= " AND 1=0";
                }
            } else {
                // String user columns (case-insensitive to be safe).
                $where_sql .= " AND LOWER(`{$user_col}`) = %s";
                $params[] = $u_login;
            }
        }

        $sql = "SELECT LOWER(kategori) AS kategori,
                       SUM(CASE WHEN LOWER(kategori) = 'income' THEN (harga*quantity) ELSE 0 END) AS income_total,
                       SUM(CASE WHEN LOWER(kategori) IN ($cats_in) THEN (harga*quantity) ELSE 0 END) AS expense_total,
                       SUM(harga*quantity) AS amount_total
                FROM `{$table}`
                WHERE {$where_sql}
                GROUP BY LOWER(kategori)";

        $prepared = $db->prepare($sql, $params);
        if (!$prepared) {
            wp_send_json_error(['message'=>'SQL prepare failed', 'sql'=>$sql, 'params'=>$params]);
        }
        $rows = $db->get_results($prepared, ARRAY_A);
        if ($db->last_error) {
            wp_send_json_error(['message'=>'DB error: '.$db->last_error]);
        }

        $income = 0; $expense = 0; $by_cat = [];
        foreach ((array)$rows as $r) {
            $cat = $this->normalize_category((string)($r['kategori'] ?? '(null)'));
            $inc = (float)($r['income_total'] ?? 0);
            $exp = (float)($r['expense_total'] ?? 0);
            $income += $inc;
            $expense += $exp;
            $by_cat[$cat] = (float)($by_cat[$cat] ?? 0) + (float)($r['amount_total'] ?? 0);
        }
        return ['income' => $income, 'expense' => $expense, 'by_cat' => $by_cat];
    }

    private function calc_savings_total() : float {
        $db = $this->savings_db();
        if (!$db) return 0.0;
        $table = $this->savings_table();
        $sql = "SELECT COALESCE(SUM(amount),0) FROM `{$table}`";
        $v = $db->get_var($sql);
        if ($db->last_error) return 0.0;
        return (float)$v;
    }

    public function cron_check_limits() : void {
        $s = $this->settings();
        $limits = $s['limits'] ?? [];
        $n = $s['notify'] ?? [];
        if (empty($n['notify_on_limit'])) return;

        $notified = get_option(self::OPT_NOTIFIED, []);
        if (!is_array($notified)) $notified = [];

        // Daily
        $daily_limit = (float)($limits['daily'] ?? 0);
        if ($daily_limit > 0) {
            $key = 'daily_' . wp_date('Y-m-d');
            if (empty($notified[$key])) {
                $start = wp_date('Y-m-d 00:00:00');
                $end   = wp_date('Y-m-d 00:00:00', strtotime('+1 day'));
                $tot = $this->calc_totals_between($start, $end);
                if ($tot['expense'] >= $daily_limit) {
                    $msg = "⚠️ <b>Daily limit reached</b>\nDate: " . wp_date('Y-m-d') . "\nExpense: " . number_format_i18n($tot['expense']) . "\nLimit: " . number_format_i18n($daily_limit);
                    $this->telegram_send($msg);
                    $this->email_send('Daily limit reached', wp_strip_all_tags($msg));
                    $this->whatsapp_webhook_send(['type'=>'limit','period'=>'daily','date'=>wp_date('Y-m-d'),'expense'=>$tot['expense'],'limit'=>$daily_limit]);
                    $notified[$key] = 1;
                }
            }
        }

        // Weekly (Mon-Sun, ISO week)
        $weekly_limit = (float)($limits['weekly'] ?? 0);
        if ($weekly_limit > 0) {
            $week = (int)wp_date('W');
            $year = (int)wp_date('o');
            $key = "weekly_{$year}_{$week}";
            if (empty($notified[$key])) {
                $monday = wp_date('Y-m-d 00:00:00', strtotime('monday this week'));
                $next_monday = wp_date('Y-m-d 00:00:00', strtotime('monday next week'));
                $tot = $this->calc_totals_between($monday, $next_monday);
                if ($tot['expense'] >= $weekly_limit) {
                    $msg = "⚠️ <b>Weekly limit reached</b>\nWeek: {$year}-W{$week}\nExpense: " . number_format_i18n($tot['expense']) . "\nLimit: " . number_format_i18n($weekly_limit);
                    $this->telegram_send($msg);
                    $this->email_send('Weekly limit reached', wp_strip_all_tags($msg));
                    $this->whatsapp_webhook_send(['type'=>'limit','period'=>'weekly','week'=>"$year-W$week",'expense'=>$tot['expense'],'limit'=>$weekly_limit]);
                    $notified[$key] = 1;
                }
            }
        }

        // Monthly
        $monthly_limit = (float)($limits['monthly'] ?? 0);
        if ($monthly_limit > 0) {
            $ym = wp_date('Y-m');
            $key = "monthly_{$ym}";
            if (empty($notified[$key])) {
                $start = wp_date('Y-m-01 00:00:00');
                $end = wp_date('Y-m-01 00:00:00', strtotime('+1 month'));
                $tot = $this->calc_totals_between($start, $end);
                if ($tot['expense'] >= $monthly_limit) {
                    $msg = "⚠️ <b>Monthly limit reached</b>\nMonth: {$ym}\nExpense: " . number_format_i18n($tot['expense']) . "\nLimit: " . number_format_i18n($monthly_limit);
                    $this->telegram_send($msg);
                    $this->email_send('Monthly limit reached', wp_strip_all_tags($msg));
                    $this->whatsapp_webhook_send(['type'=>'limit','period'=>'monthly','month'=>$ym,'expense'=>$tot['expense'],'limit'=>$monthly_limit]);
                    $notified[$key] = 1;
                }
            }
        }

        update_option(self::OPT_NOTIFIED, $notified, false);
    }

    /* -------------------- Pages -------------------- */

    public function page_dashboard() : void {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die('Forbidden');
        $charts = get_option(self::OPT_CHARTS, self::default_charts());
        $dash_ids = get_option(self::OPT_DASH_CHARTS, []);
        if (!is_array($dash_ids) || !$dash_ids) {
            // default: those marked show_on_dashboard
            $dash_ids = [];
            foreach ((array)$charts as $c) {
                if (!empty($c['show_on_dashboard'])) $dash_ids[] = $c['id'];
            }
            update_option(self::OPT_DASH_CHARTS, $dash_ids, false);
        }

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $date_basis = isset($_GET['date_basis']) ? sanitize_text_field(wp_unslash($_GET['date_basis'])) : 'input';
        $date_basis = $this->sanitize_date_basis($date_basis);
        $group = isset($_GET['group']) ? sanitize_text_field(wp_unslash($_GET['group'])) : 'daily';
        $group = in_array($group, ['daily','weekly','monthly'], true) ? $group : 'daily';
        if (!$to) $to = wp_date('Y-m-d');
        if (!$from) $from = wp_date('Y-m-d', strtotime($to . ' -6 days'));

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('SIMKU', '[simku_dashboard]', '[simku page="dashboard"]');
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-card"><div class="fl-card-head"><h2 style="margin:0">Quick Links</h2></div>'; 
        echo '<div class="fl-card-body"><div class="fl-btnrow">';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=fl-add-transaction')).'">Add Transaction</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">Transactions</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-reports')).'">Reports</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-charts')).'">Charts</a> ';
        echo '</div></div></div>';

        // Totals today
        $now_ts = current_time('timestamp');
        if ($date_basis === 'receipt') {
            $today_start = wp_date('Y-m-d', $now_ts);
            $tomorrow = wp_date('Y-m-d', $now_ts + DAY_IN_SECONDS);
        } else {
            $today_start = wp_date('Y-m-d 00:00:00', $now_ts);
            $tomorrow = wp_date('Y-m-d 00:00:00', $now_ts + DAY_IN_SECONDS);
        }
        $tot = $this->calc_totals_between($today_start, $tomorrow, $date_basis);

        // Backward-compatible: some installs still use category name 'expense'.
        $outcome_today = (float)($tot['by_cat']['outcome'] ?? ($tot['by_cat']['expense'] ?? ($tot['expense'] ?? 0)));
        $savings_total = $this->calc_savings_total();

        echo '<div class="fl-card">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Today</h2><div class="fl-muted">Basis: '.($date_basis==='receipt' ? 'Purchase date (fallback: entry date)' : 'Entry date').'</div></div>';
        echo '<div class="fl-card-body">';
        echo '<div class="fl-kpis">';
        echo '<div class="fl-kpi"><div class="fl-kpi-label">Income</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n($tot['income'])).'</div></div>';
        echo '<div class="fl-kpi"><div class="fl-kpi-label">Expense</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n($outcome_today)).'</div></div>';
        echo '<div class="fl-kpi"><div class="fl-kpi-label">Savings Total</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n($savings_total)).'</div></div>';
        echo '</div>';
        echo '</div></div>';
        echo '</div>';

        // Date range controls for dashboard charts (defaults last 7 days)
        echo '<div class="fl-card fl-mt simku-dashboard-filter-card">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Dashboard Charts</h2><span class="fl-muted">Filter range</span></div>';
        echo '<div class="fl-card-body">';
        echo '<form method="get" class="simku-report-filter simku-dashboard-filter">';
        echo '<input type="hidden" name="page" value="fl-dashboard" />';
        echo '<div class="simku-filter-field simku-filter-from"><label for="simku_report_from">From</label><input id="simku_report_from" type="date" name="from" value="'.esc_attr($from).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-to"><label for="simku_report_to">To</label><input id="simku_report_to" type="date" name="to" value="'.esc_attr($to).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-group"><label for="simku_dash_group">Group</label><select id="simku_dash_group" name="group">'
            .'<option value="daily"'.selected($group,'daily',false).'>Daily</option>'
            .'<option value="weekly"'.selected($group,'weekly',false).'>Weekly</option>'
            .'<option value="monthly"'.selected($group,'monthly',false).'>Monthly</option>'
            .'</select></div>';
        echo '<div class="simku-filter-field simku-filter-basis"><label for="simku_dash_basis">Basis</label><select id="simku_dash_basis" name="date_basis" class="fl-select">'
            .'<option value="input"'.selected($date_basis,'input',false).'>Entry date</option>'
            .'<option value="receipt"'.selected($date_basis,'receipt',false).'>Purchase date</option>'
            .'</select></div>';
        echo '<div class="simku-filter-actions"><button class="button button-primary">Apply</button></div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fl-grid fl-grid-2">';
        foreach ((array)$dash_ids as $cid) {
            $c = $this->find_chart($cid);
            if (!$c) continue;
            $c['date_basis'] = $date_basis;
            // For the new default 7d charts, make them follow the dashboard From/To filter.
            if (in_array($cid, ['income_vs_outcome_day_7','by_category_7'], true)) {
                $c['range'] = ['mode' => 'custom', 'from' => $from, 'to' => $to];
            }
            $shortcode = '[fl_chart id="'.esc_attr($c['id']).'"]';
            echo '<div class="fl-card">';
            echo '<div class="fl-card-head fl-card-head-between">';
            echo '<h3>'.esc_html($c['title']).'</h3>';
            echo '<div class="fl-head-actions">';
            echo '<button type="button" class="fl-kebab" aria-label="Chart actions" data-shortcode="'.esc_attr($shortcode).'"><span class="fl-kebab-dots">⋮</span></button>';
            echo '<div class="fl-menu" hidden>';
            echo '<button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="'.esc_attr($shortcode).'">Copy shortcode</button>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
            echo $this->render_chart_container_with_config($c['id'], $c, true);
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';
    }

    public function page_transactions() : void {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die('Forbidden');

        $db = $this->ds_db();
        if (!$db) {
            echo '<div class="wrap fl-wrap"><h1>Transactions</h1><div class="notice notice-error"><p>Datasource not configured.</p></div></div>';
            return;
        }

        $table = $this->ds_table();

        // View images for a transaction line (single URL or JSON-array stored in gambar_url)
        if (!empty($_GET['view_images']) && !empty($_GET['line_id'])) {
            $line_id = sanitize_text_field(wp_unslash($_GET['line_id']));
            $row = $db->get_row($db->prepare(
                "SELECT line_id, transaction_id, items, gambar_url FROM {$table} WHERE line_id = %s LIMIT 1",
                $line_id
            ));

            echo '<div class="wrap">';
            echo '<h1>Transaction Images</h1>'; 
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=fl-transactions')) . '">Back</a></p>';

            if (!$row) {
                echo '<div class="notice notice-error"><p>Transaction not found.</p></div>';
                echo '</div>';
                return;
            }

            $imgs = $this->normalize_images_field($row->gambar_url);
            if (!$imgs) {
                echo '<div class="notice notice-info"><p>No images attached.</p></div>';
                echo '</div>';
                return;
            }

            echo '<p><strong>Transaction ID:</strong> ' . esc_html($row->transaction_id) . '</p>';
            echo '<p><strong>Item:</strong> ' . esc_html($row->items) . '</p>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:12px;">';
            foreach ($imgs as $img) {
                $u = esc_url($img);
                if (!$u) continue;
                echo '<a href="' . $u . '" target="_blank" rel="noopener" style="display:block;border:1px solid #ddd;padding:6px;border-radius:8px;background:#fff;">';
                echo '<img src="' . $u . '" alt="" style="max-width:260px;height:auto;display:block;" />';
                echo '</a>';
            }
            echo '</div>';
            echo '</div>';
            return;
        }

        
        // View description for a transaction line
        if (!empty($_GET['view_desc']) && !empty($_GET['line_id'])) {
            $line_id = sanitize_text_field(wp_unslash($_GET['line_id']));
            $row = $db->get_row($db->prepare(
                "SELECT line_id, transaction_id, items, kategori, tanggal_input, tanggal_struk, description FROM {$table} WHERE line_id = %s LIMIT 1",
                $line_id
            ), ARRAY_A);

            echo '<div class="wrap">';
            echo '<h1>Transaction Description</h1>';
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=fl-transactions')) . '">Back</a></p>';

            if (!$row) {
                echo '<div class="notice notice-error"><p>Transaction not found.</p></div>';
                echo '</div>';
                return;
            }

            $cat_norm = $this->normalize_category((string)($row['kategori'] ?? ''));
            $date_label = ($cat_norm === 'income') ? 'Receive Date' : 'Purchase Date';
            $date_val = (string)($row['tanggal_struk'] ?? '');

            echo '<p><strong>Transaction ID:</strong> ' . esc_html($row['transaction_id'] ?? '') . '</p>';
            echo '<p><strong>Item:</strong> ' . esc_html($row['items'] ?? '') . '</p>';
            echo '<p><strong>Category:</strong> ' . esc_html($this->category_label((string)($row['kategori'] ?? ''))) . '</p>';
            echo '<p><strong>Entry Date:</strong> ' . esc_html($this->fmt_mysql_dt_display((string)($row['tanggal_input'] ?? ''))) . '</p>';
            echo '<p><strong>' . esc_html($date_label) . ':</strong> ' . esc_html($date_val !== '' ? $date_val : 'N/A') . '</p>';

            echo '<div class="simku-desc-box"><pre style="white-space:pre-wrap;margin:0;">' . esc_html((string)($row['description'] ?? '')) . '</pre></div>';

            echo '</div>';
            return;
        }

// Handle delete
        if (!empty($_GET['fl_action']) && $_GET['fl_action'] === 'delete' && current_user_can(self::CAP_MANAGE_TX)) {
            check_admin_referer('fl_delete_tx');
            $line_id = isset($_GET['line_id']) ? sanitize_text_field(wp_unslash($_GET['line_id'])) : '';
            if ($line_id) {
                if ($this->ds_is_external() && !$this->ds_allow_write_external()) {
                    echo '<div class="notice notice-error"><p>External datasource is read-only.</p></div>';
                } else {
                    $res = $db->delete($table, ['line_id' => $line_id], ['%s']);
                    if ($res !== false) {
                        $this->log_event('delete', 'transaction', $line_id, ['line_id'=>$line_id]);
                        echo '<div class="notice notice-success"><p>Deleted.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Delete failed.</p></div>';
                    }
                }
            }
        }

        // Filters
        $q = [
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'kategori' => isset($_GET['kategori']) ? sanitize_text_field(wp_unslash($_GET['kategori'])) : '',
            'user' => isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : '',
            // Date filter applies to the selected date field
            'date_field' => isset($_GET['date_field']) ? sanitize_text_field(wp_unslash($_GET['date_field'])) : 'entry',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
        ];


        // v0.5.38: normalize legacy category name
        if (!empty($q['kategori'])) $q['kategori'] = $this->normalize_category((string)$q['kategori']);
        // Normalize date field
        $q['date_field'] = $this->reports_sanitize_date_field((string)($q['date_field'] ?? 'entry'));


        $where = "1=1";
        $params = [];
        if ($q['s']) {
            // Search across multiple fields (IDs, party, item, category, price, qty, description)
            $where .= " AND (transaction_id LIKE %s OR line_id LIKE %s OR nama_toko LIKE %s OR items LIKE %s OR kategori LIKE %s OR CAST(harga AS CHAR) LIKE %s OR CAST(quantity AS CHAR) LIKE %s OR description LIKE %s)";
            $like = '%' . $db->esc_like($q['s']) . '%';
            $params += [$like, $like, $like, $like, $like, $like, $like, $like];
        }
        if ($q['kategori']) {
            if ($q['kategori'] === 'expense') {
                $where .= " AND kategori IN (%s,%s)";
                $params[] = 'expense';
                $params[] = 'outcome';
            } else {
                $where .= " AND kategori = %s";
                $params[] = $q['kategori'];
            }
        }
        // Date filtering (Entry / Purchase / Receive)
        $date_col = 'tanggal_input';
        if ($q['date_field'] === 'purchase' || $q['date_field'] === 'receive') {
            $date_col = 'tanggal_struk';
            // If user didn't explicitly filter by category, constrain by semantic date type.
            if (empty($q['kategori'])) {
                if ($q['date_field'] === 'purchase') {
                    $where .= " AND kategori IN (%s,%s)";
                    $params[] = 'expense';
                    $params[] = 'outcome';
                } elseif ($q['date_field'] === 'receive') {
                    $where .= " AND kategori = %s";
                    $params[] = 'income';
                }
            }
        }

        if ($q['date_from']) {
            $where .= " AND {$date_col} >= %s";
            $params[] = ($date_col === 'tanggal_input') ? ($q['date_from'] . ' 00:00:00') : $q['date_from'];
        }
        if ($q['date_to']) {
            $where .= " AND {$date_col} <= %s";
            $params[] = ($date_col === 'tanggal_input') ? ($q['date_to'] . ' 23:59:59') : $q['date_to'];
        }


        // User columns can differ per datasource (internal/external/legacy).
        $user_login_col = null; // backticked column name
        if ($this->ds_column_exists('wp_user_login')) {
            $user_login_col = '`wp_user_login`';
        } elseif ($this->ds_column_exists('user')) {
            // Legacy / custom schema
            $user_login_col = '`user`';
        } elseif ($this->ds_column_exists('username')) {
            $user_login_col = '`username`';
        }

        $user_id_col = $this->ds_column_exists('wp_user_id') ? '`wp_user_id`' : null;

        // Apply user filter (if the datasource supports it).
        if (!empty($q['user']) && $user_login_col) {
            $where .= " AND {$user_login_col} = %s";
            $params[] = $q['user'];
        }

        // Build user dropdown options from existing rows (fast + relevant).
        $user_options = [];
        if ($user_login_col) {
            $user_options = $db->get_col("SELECT DISTINCT {$user_login_col} AS u FROM `{$table}` WHERE {$user_login_col} IS NOT NULL AND {$user_login_col} <> '' ORDER BY u ASC LIMIT 200");
        }
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        $total = $params ? (int)$db->get_var($db->prepare($count_sql, $params)) : (int)$db->get_var($count_sql);

        // Pagination (used at top & bottom)
        $total_pages = max(1, (int)ceil($total / $per_page));
        $pagination_html = $this->render_pagination('fl-transactions', $page, $total_pages, $q);

        // User columns can differ per datasource (internal/external/legacy).
        $user_login_select = $user_login_col ? $user_login_col : 'NULL';
        $user_id_select = $user_id_col ? $user_id_col : 'NULL';


        $sql = "SELECT line_id, transaction_id, {$user_id_select} AS wp_user_id, {$user_login_select} AS wp_user_login, nama_toko, items, quantity, harga, kategori, tanggal_input, tanggal_struk, gambar_url, description
                FROM `{$table}`
                WHERE {$where}
                ORDER BY tanggal_input DESC
                LIMIT %d OFFSET %d";
        $params2 = $params ? array_merge($params, [$per_page, $offset]) : [$per_page, $offset];
        $rows = $db->get_results($db->prepare($sql, $params2), ARRAY_A);

        $base_url = admin_url('admin.php?page=fl-transactions');
        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Transactions', '[simku_transactions]', '[simku page="transactions"]');

        // Migration notice
        if ($this->ds_is_external() && (!$this->ext_column_exists('wp_user_id') || !$this->ext_column_exists('wp_user_login'))) {
            echo '<div class="notice notice-warning"><p><b>External table needs user columns.</b> Click "Run Migration" in Settings → Datasource to add <code>wp_user_id</code> and <code>wp_user_login</code>.</p></div>';
        }

        echo '<form method="get" class="fl-filters fl-card simku-tx-filters">';
        echo '<input type="hidden" name="page" value="fl-transactions" />';
        echo '<div class="fl-filters-grid">';

        echo '<div class="fl-field fl-field-search">';
        echo '<label>Search</label>';
        echo '<input type="search" name="s" value="'.esc_attr($q['s']).'" placeholder="Search…" />';
        echo '</div>';

        echo '<div class="fl-field fl-field-category">';
        echo '<label>Category</label>';
        echo '<select name="kategori"><option value="">All categories</option>';
        foreach (['expense','income','saving','invest'] as $cat) {
            printf('<option value="%s"%s>%s</option>', esc_attr($cat), selected($q['kategori'],$cat,false), esc_html($this->category_label($cat)));
        }
        echo '</select>';
        echo '</div>';

        if ($user_login_col) {
            echo '<div class="fl-field fl-field-user">';
            echo '<label>User</label>';
            echo '<select name="user"><option value="">All users</option>' ;
            foreach ((array)$user_options as $uopt) {
                $uopt = (string)$uopt;
                if ($uopt === '') { continue; }
                printf('<option value="%s"%s>%s</option>', esc_attr($uopt), selected($q['user'], $uopt, false), esc_html($uopt));
            }
            echo '</select>';
echo '</div>';
        }


        // Date Field selector
        echo '<div class="fl-field fl-field-date-field">';
        echo '<label>Date Field</label>';
        echo '<select name="date_field">';
        printf('<option value="entry"%s>Entry Date</option>', selected($q['date_field'], 'entry', false));
        printf('<option value="purchase"%s>Purchase Date</option>', selected($q['date_field'], 'purchase', false));
        printf('<option value="receive"%s>Receive Date</option>', selected($q['date_field'], 'receive', false));
        echo '</select>';
        echo '</div>';


        echo '<div class="fl-field fl-field-from">';
        echo '<label>From</label>';
        echo '<input type="date" name="date_from" value="'.esc_attr($q['date_from']).'" />';
        echo '</div>';

        echo '<div class="fl-field fl-field-to">';
        echo '<label>To</label>';
        echo '<input type="date" name="date_to" value="'.esc_attr($q['date_to']).'" />';
        echo '</div>';

        echo '<div class="fl-field fl-filter-actions">';
        echo '<label>&nbsp;</label>';
        echo '<button class="button button-primary" type="submit">Filter</button>';
        echo '</div>';

        echo '</div>';
        echo '</form>';

        // Use auto table layout for better responsive scrolling on mobile.
        echo '<div class="fl-table-wrap"><table class="widefat striped simku-table">';
        echo '<thead><tr>';
	        $cols = [
            'line_id' => 'Line ID',
            'transaction_id' => 'Transaction ID',
            'user' => 'User',
	            'nama_toko' => 'Counterparty',
            'items' => 'Item',
            'quantity' => 'Qty',
            'harga' => 'Price',
            'kategori' => 'Category',
            'tanggal_input' => 'Entry Date',
            'purchase_date' => 'Purchase Date',
            'receive_date' => 'Receive Date',
            'gambar_url' => 'Image',
            'description' => 'Description',
            'actions' => 'Actions',
        ];
        foreach ($cols as $k=>$label) {
            echo '<th>'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="'.count($cols).'">No data.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $line_id = (string)($r['line_id'] ?? '');
                $del_url = wp_nonce_url(add_query_arg([
                    'page' => 'fl-transactions',
                    'fl_action' => 'delete',
                    'line_id' => rawurlencode($line_id),
                ], admin_url('admin.php')), 'fl_delete_tx');

                $edit_url = add_query_arg(['page'=>'fl-add-transaction','edit'=>rawurlencode($line_id)], admin_url('admin.php'));

                echo '<tr>';
                echo '<td><code>'.esc_html($line_id).'</code></td>';
                echo '<td>'.esc_html($r['transaction_id'] ?? '').'</td>';
                $user_disp = (string)($r['wp_user_login'] ?? '');
                if ($user_disp === '' && !empty($r['wp_user_id'])) {
                    $u = get_user_by('id', (int)$r['wp_user_id']);
                    if ($u && !empty($u->user_login)) $user_disp = (string)$u->user_login;
                }
                echo '<td>'.esc_html($user_disp).'</td>';
                echo '<td>'.esc_html($r['nama_toko'] ?? '').'</td>';
                echo '<td>'.esc_html($r['items'] ?? '').'</td>';
                echo '<td>'.esc_html($r['quantity'] ?? '').'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((float)($r['harga'] ?? 0))).'</td>';
                echo '<td>'.esc_html($this->category_label((string)($r['kategori'] ?? ''))).'</td>';
                echo '<td>'.esc_html($this->fmt_mysql_dt_display((string)($r['tanggal_input'] ?? ''))).'</td>';
                $cat_norm_row = $this->normalize_category((string)($r['kategori'] ?? ''));
                $is_income = ($cat_norm_row === 'income');
                $is_expense = in_array($cat_norm_row, ['expense','outcome'], true);
                $struk = (string)($r['tanggal_struk'] ?? '');
                $purchase_disp = $is_expense ? ($struk !== '' ? $struk : 'N/A') : 'N/A';
                $receive_disp  = $is_income  ? ($struk !== '' ? $struk : 'N/A') : 'N/A';
                echo '<td>'.esc_html($purchase_disp).'</td>';
                echo '<td>'.esc_html($receive_disp).'</td>';
                $imgs = $this->normalize_images_field($r['gambar_url'] ?? '');
                if (!empty($imgs)) {
                    $label = (count($imgs) > 1) ? ('View (' . count($imgs) . ')') : 'View';
                    $view_imgs_url = admin_url('admin.php?page=fl-transactions&view_images=1&line_id=' . rawurlencode((string)$r['line_id']));
                    echo '<td><a class="button button-small" href="'.esc_url($view_imgs_url).'">'.esc_html($label).'</a></td>';
                } else {
                    echo '<td></td>';
                }
                $desc = trim((string)($r['description'] ?? ''));
                if ($desc !== '') {
                    $view_desc_url = admin_url('admin.php?page=fl-transactions&view_desc=1&line_id=' . rawurlencode((string)$r['line_id']));
                    echo '<td><a class="button button-small" href="'.esc_url($view_desc_url).'">View</a></td>';
                } else {
                    echo '<td></td>';
                }
                echo '<td class="fl-actions-col">';
                if (current_user_can(self::CAP_MANAGE_TX)) {
                    echo '<div class="simku-actions">';
                    echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a>';
                    echo '<a class="button button-small button-link-delete" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this row?\')">Delete</a>';
                    echo '</div>';
                } else {
                    echo '<span class="fl-muted">—</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';

        // Pagination
        echo $pagination_html;

        echo '<hr class="fl-hr">';
        echo '<form method="post">';
        wp_nonce_field('simak_export_pdf');
        echo '<input type="hidden" name="simak_export_pdf" value="1" />';
        echo '<button class="button">Export PDF (current filter)</button>';
        echo '</form>';

        // Export PDF action
        if (!empty($_POST['simak_export_pdf'])) {
            check_admin_referer('simak_export_pdf');
            $this->export_pdf_transactions($rows, $q);
        }

        echo '</div>';
    }

    /* -------------------- Savings (Tabungan) -------------------- */

    public function page_savings() : void {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die('Forbidden');
        $db = $this->savings_db();
        if (!($db instanceof wpdb)) {
            echo '<div class="wrap"><h1>Savings</h1><div class="notice notice-error"><p>Datasource error: savings DB is not available.</p></div></div>';
            return;
        }
        $table = $this->savings_table();
        $date_col = $this->savings_date_column($db, $table);
        $is_ext = $this->savings_is_external();

        // Handle delete
        if (!empty($_GET['fl_action']) && $_GET['fl_action'] === 'delete_saving' && current_user_can(self::CAP_MANAGE_TX)) {
            check_admin_referer('fl_delete_saving');
            $line_id = isset($_GET['line_id']) ? sanitize_text_field(wp_unslash($_GET['line_id'])) : '';
            if ($line_id) {
                if ($is_ext && !$this->ds_allow_write_external()) {
                    echo '<div class="notice notice-error"><p>External savings table is read-only. Enable “Allow write to external” in Settings to delete rows.</p></div>';
                } else {
                    $res = $db->delete($table, ['line_id' => $line_id], ['%s']);
                    if ($res !== false) {
                        $this->log_event('delete', 'saving', $line_id, ['line_id' => $line_id]);
                        echo '<div class="notice notice-success"><p>Deleted.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Delete failed.</p></div>';
                    }
                }
            }
        }

        // Filters – default last 12 months
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to   = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        if (!$from || !$to) {
            $to = wp_date('Y-m-d');
            $from = wp_date('Y-m-01', strtotime('-11 months'));
        }

        // Trend grouping
        $group = isset($_GET['group']) ? sanitize_text_field(wp_unslash($_GET['group'])) : 'monthly';
        if (!in_array($group, ['daily','weekly','monthly'], true)) { $group = 'monthly'; }

        $trend_unit = ($group === 'daily') ? 'Daily' : (($group === 'weekly') ? 'Weekly' : 'Monthly');

        $where = "`{$date_col}` >= %s AND `{$date_col}` <= %s";
        $p = [$from . ' 00:00:00', $to . ' 23:59:59'];

        $total_amount = (int) $db->get_var($db->prepare("SELECT COALESCE(SUM(amount),0) FROM {$table} WHERE {$where}", $p));

        // Trend aggregates (daily / weekly / monthly)
        $labels = [];
        $values = [];

        if ($group === 'daily') {
            $agg = $db->get_results(
                $db->prepare(
                    "SELECT DATE(`{$date_col}`) AS d, COALESCE(SUM(amount),0) AS total
                     FROM {$table}
                     WHERE {$where}
                     GROUP BY d
                     ORDER BY d ASC",
                    $p
                ),
                ARRAY_A
            );

            // Fill missing days with 0 for consistent chart width
            $map = [];
            try {
                $cur = new DateTimeImmutable($from);
                $end = new DateTimeImmutable($to);
                while ($cur <= $end) {
                    $map[$cur->format('Y-m-d')] = 0;
                    $cur = $cur->modify('+1 day');
                }
            } catch (Exception $e) {}

            foreach ((array)$agg as $r) {
                if (!empty($r['d'])) $map[$r['d']] = (int)$r['total'];
            }
            $labels = array_keys($map);
            $values = array_values($map);

        } elseif ($group === 'weekly') {
            $agg = $db->get_results(
                $db->prepare(
                    "SELECT YEARWEEK(`{$date_col}`, 3) AS yw, COALESCE(SUM(amount),0) AS total
                     FROM {$table}
                     WHERE {$where}
                     GROUP BY yw
                     ORDER BY yw ASC",
                    $p
                ),
                ARRAY_A
            );

            // Fill missing weeks with 0
            $map = [];
            try {
                $cur = (new DateTimeImmutable($from))->modify('monday this week');
                $end = (new DateTimeImmutable($to))->modify('monday this week');
                while ($cur <= $end) {
                    $map[$cur->format('o-\WW')] = 0;
                    $cur = $cur->modify('+1 week');
                }
            } catch (Exception $e) {}

            foreach ((array)$agg as $r) {
                $yw = isset($r['yw']) ? str_pad((string)$r['yw'], 6, '0', STR_PAD_LEFT) : '';
                if ($yw) {
                    $label = substr($yw, 0, 4) . '-W' . substr($yw, 4, 2);
                    $map[$label] = (int)$r['total'];
                }
            }
            $labels = array_keys($map);
            $values = array_values($map);

        } else { // monthly (default)
            $agg = $db->get_results(
                $db->prepare(
                    "SELECT DATE_FORMAT(`{$date_col}`,'%%Y-%%m') AS ym, COALESCE(SUM(amount),0) AS total
                     FROM {$table}
                     WHERE {$where}
                     GROUP BY ym
                     ORDER BY ym ASC",
                    $p
                ),
                ARRAY_A
            );

            // Fill missing months with 0
            $map = [];
            try {
                $cur = (new DateTimeImmutable($from))->modify('first day of this month');
                $end = (new DateTimeImmutable($to))->modify('first day of this month');
                while ($cur <= $end) {
                    $map[$cur->format('Y-m')] = 0;
                    $cur = $cur->modify('+1 month');
                }
            } catch (Exception $e) {}

            foreach ((array)$agg as $r) {
                if (!empty($r['ym'])) $map[$r['ym']] = (int)$r['total'];
            }
            $labels = array_keys($map);
            $values = array_values($map);
        }

        // List
        $rows = $db->get_results(
            $db->prepare(
                "SELECT line_id, saving_id, account_name, amount, institution, notes, `{$date_col}` AS saved_at, wp_user_login
                 FROM {$table}
                 WHERE {$where}
                 ORDER BY `{$date_col}` DESC
                 LIMIT 200",
                $p
            ),
            ARRAY_A
        );

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Savings', '[simku_savings]', '[simku page="savings"]');
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Saving updated successfully.</p></div>';
        }

        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-card"><div class="fl-card-head"><h2 style="margin:0">Quick Links</h2></div>';
        echo '<div class="fl-btnrow">';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=fl-add-saving')).'">Add Saving</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-savings')).'">Savings</a>';
        echo '</div></div>';

        echo '<div class="fl-card"><h2>Overview</h2>';
        echo '<div class="fl-kpis">';
        echo '<div class="fl-kpi"><div class="fl-kpi-label">Savings Total</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n($total_amount)).'</div></div>';
        echo '</div></div>';
        echo '</div>';

        echo '<div class="fl-card fl-mt">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Savings Trend</h2><span class="fl-muted">'.esc_html($trend_unit).'</span></div>';
        echo '<div class="fl-card-body">';
        // Use the same responsive filter layout as dashboard (2-column grid on mobile)
        echo '<form method="get" class="fl-inline fl-dashboard-filter fl-mt">';
        echo '<input type="hidden" name="page" value="fl-savings" />';
        echo '<div class="simku-filter-field simku-filter-from"><label for="simku_report_from">From</label><input id="simku_report_from" type="date" name="from" value="'.esc_attr($from).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-to"><label for="simku_report_to">To</label><input id="simku_report_to" type="date" name="to" value="'.esc_attr($to).'" /></div>';
        echo '<label>Group <select name="group">'
            .'<option value="daily"'.selected($group,'daily',false).'>Daily</option>'
            .'<option value="weekly"'.selected($group,'weekly',false).'>Weekly</option>'
            .'<option value="monthly"'.selected($group,'monthly',false).'>Monthly</option>'
            .'</select></label> ';
        echo '<button class="button">Apply</button>';
        echo '</form>';
        echo '<div class="fl-chart-box" id="fl-savings-trend" data-labels="'.esc_attr(wp_json_encode($labels)).'" data-values="'.esc_attr(wp_json_encode($values)).'" data-group="'.esc_attr($group).'"></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fl-card fl-mt">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Savings List</h2><span class="fl-muted">Max 200 rows</span></div>';
        // Use auto table layout for better responsive scrolling on mobile.
        echo '<div class="fl-table-wrap"><table class="widefat striped simku-table">';
        echo '<thead><tr>';
        $cols = [
            'saved_at' => 'Date',
            'saving_id' => 'Saving ID',
            'account_name' => 'Account Name',
            'amount' => 'Amount',
            'institution' => 'Stored at',
            'user' => 'User',
            'notes' => 'Notes',
            'actions' => 'Actions',
        ];
        foreach ($cols as $k=>$label) echo '<th>'.esc_html($label).'</th>';
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="'.count($cols).'">No data.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $line_id = (string)($r['line_id'] ?? '');
                $del_url = wp_nonce_url(add_query_arg([
                    'page' => 'fl-savings',
                    'fl_action' => 'delete_saving',
                    'line_id' => rawurlencode($line_id),
                    'from' => $from,
                    'to' => $to,
                ], admin_url('admin.php')), 'fl_delete_saving');
                $edit_url = add_query_arg([
                    'page' => 'fl-add-saving',
                    'edit' => '1',
                    'line_id' => $line_id,
                    'return_page' => 'fl-savings',
                    'from' => $from,
                    'to' => $to,
                ], admin_url('admin.php'));
                echo '<tr>';
                echo '<td>'.esc_html($r['saved_at'] ?? '').'</td>';
                echo '<td><code>'.esc_html($r['saving_id'] ?? '').'</code></td>';
                echo '<td>'.esc_html($r['account_name'] ?? '').'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((int)($r['amount'] ?? 0))).'</td>';
                echo '<td>'.esc_html($r['institution'] ?? '').'</td>';
                echo '<td>'.esc_html($r['wp_user_login'] ?? '').'</td>';
                echo '<td class="fl-cell-wrap">'.esc_html($r['notes'] ?? '').'</td>';
                echo '<td class="fl-actions-col">';
                if (current_user_can(self::CAP_MANAGE_TX)) {
                    echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> ';
                    echo '<a class="button button-small button-link-delete" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this saving?\')">Delete</a>';
                } else {
                    echo '<span class="fl-muted">—</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table></div>';
        echo '</div>';

        // Savings trend chart init
        // NOTE: Admin scripts (including echarts) are loaded in the footer, so we must init on window.load.
        echo '<script>(function(){
            function initSavingsTrend(){
                var el = document.getElementById("fl-savings-trend");
                if(!el) return true; // nothing to do
                if(!window.echarts) return false;

                var labels = [];
                var values = [];
                var group = (el.getAttribute("data-group")||"monthly").toLowerCase();
                try{
                    labels = JSON.parse(el.getAttribute("data-labels") || el.getAttribute("data-months") || "[]");
                    values = JSON.parse(el.getAttribute("data-values") || "[]");
                }catch(e){ labels = []; values = []; }
                if(!Array.isArray(labels)) labels = [];
                if(!Array.isArray(values)) values = [];
                values = values.map(function(v){ v = Number(v); return isFinite(v) ? v : 0; });

                function fmt(n){
                    n = (n===undefined||n===null||n==="") ? 0 : Number(n);
                    if(!isFinite(n)) n = 0;
                    try{ return new Intl.NumberFormat("id-ID",{maximumFractionDigits:0}).format(n); }
                    catch(e){ return String(Math.round(n)); }
                }

                var isMobile = !!(window.matchMedia && window.matchMedia("(max-width: 782px)").matches);

                // Mobile: keep charts readable by reducing label clutter.
                // (Bar labels like "Rp 0" repeated many times makes the axis look messy.)
                var rotate = isMobile ? ((labels.length > 6) ? 45 : 0) : ((labels.length > 12) ? 45 : 0);
                var showLabels = (!isMobile) && (values.length > 0 && values.length <= 31);
		            // Grid spacing: keep the plot area as wide as possible.
		            // We use a small base left padding and rely on `containLabel:true` to auto-fit the Y labels.
		            // (Previously we estimated label width *and* used containLabel, which made the left gap too large.)
		            var gridLeft = isMobile ? 2 : 8;
		            var gridRight = isMobile ? 8 : 18;
		            var gridTop = isMobile ? 18 : 30;
		            var gridBottom = isMobile ? (rotate ? 70 : 50) : (rotate ? 90 : 60);

                // Make X labels readable depending on granularity.
                var interval = "auto";
                if(group === "daily" && labels.length > 14) interval = Math.ceil(labels.length / 14);
                if(group === "weekly" && labels.length > 16) interval = Math.ceil(labels.length / 12);
                if(group === "monthly" && labels.length > 24) interval = Math.ceil(labels.length / 12);

                var chart = window.echarts.getInstanceByDom(el) || window.echarts.init(el);
                chart.setOption({
                    tooltip:{
                        show:true,
                        trigger:"axis",
                        axisPointer:{type:"shadow"},
                        renderMode:"html",
                        appendToBody:true,
                        extraCssText:"z-index:999999;",
                        formatter:function(params){
                            var p=(params&&params[0])?params[0]:null;
                            if(!p) return "";
                            var v = (p.data!==undefined) ? p.data : (p.value!==undefined ? p.value : 0);
                            return String(p.axisValue) + "<br/>Savings: <b>Rp " + fmt(v) + "</b>";
                        }
                    },
                    xAxis:{
                        type:"category",
                        data:labels,
                        axisTick:{alignWithLabel:true},
                        axisLabel:{rotate:rotate, interval:interval, hideOverlap:true, fontSize:(isMobile?10:12)}
                    },
		            yAxis:{
		                type:"value",
		                axisLabel:{
		                    formatter:function(v){return fmt(v);},
		                    // tighter label-to-axis spacing to reduce wasted left space
		                    margin:(isMobile?2:6),
		                    fontSize:(isMobile?10:12)
		                }
		            },
                    series:[{
                        name:"Savings",
                        type:"bar",
                        data:values,
                        barMaxWidth:(isMobile?18:28),
                        emphasis:{focus:"series"},
                        label:{
                            show:showLabels,
                            position:"top",
                            formatter:function(p){
                                var v = (p && p.value!==undefined) ? Number(p.value) : 0;
                                if(!isFinite(v) || v === 0) return "";
                                return "Rp "+fmt(v);
                            }
                        }
                    }],
                    // Give labels enough space so they are not clipped (prevents "00,000")
                    grid:{left:gridLeft, right:gridRight, top:gridTop, bottom:gridBottom, containLabel:true}
                }, true);

                window.addEventListener("resize", function(){ try{ chart.resize(); }catch(e){} });
                return true;
            }

            function onReady(){
                if(initSavingsTrend()) return;
                // If echarts is delayed, retry for a short period.
                var tries = 0;
                var t = setInterval(function(){
                    tries++;
                    if(initSavingsTrend() || tries > 20) clearInterval(t);
                }, 250);
            }

            if(document.readyState === "complete") onReady();
            else window.addEventListener("load", onReady);
        })();</script>';


        echo '</div>';
    }

    public function page_add_saving() : void {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die('Forbidden');
        $db = $this->savings_db();
        if (!($db instanceof wpdb)) {
            echo '<div class="wrap"><h1>Add Saving</h1><div class="notice notice-error"><p>Datasource error: savings DB is not available.</p></div></div>';
            return;
        }

        $table = $this->savings_table();
        $date_col = $this->savings_date_column($db, $table);
        $is_ext = $this->savings_is_external();
        $user = wp_get_current_user();

        $edit_mode = !empty($_GET['edit']) && !empty($_GET['line_id']);
        $edit_line_id = $edit_mode ? sanitize_text_field(wp_unslash($_GET['line_id'])) : '';
        $return_page = !empty($_GET['return_page']) ? sanitize_text_field(wp_unslash($_GET['return_page'])) : 'fl-savings';
        $return_from = !empty($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $return_to = !empty($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';

        // Build Back URL safely (prevents undefined variable warnings and PHP 8.1+ trim(null) deprecations)
        $back_args = [
            'page' => $return_page ?: 'fl-savings',
        ];
        if (!empty($return_from)) $back_args['from'] = $return_from;
        if (!empty($return_to)) $back_args['to'] = $return_to;
        $back_url = add_query_arg($back_args, admin_url('admin.php'));

        $existing = null;
        $edit_not_found = false;
        if ($edit_mode && $edit_line_id) {
            $existing = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s LIMIT 1", $edit_line_id), ARRAY_A);
            if (!$existing) {
                $edit_not_found = true;
                $edit_mode = false;
            }
        }

        $msg = '';
        if ($edit_not_found) {
            $msg = '<div class="notice notice-error"><p>Saving not found.</p></div>';
        }
        if (!empty($_GET['created'])) {
            $msg = '<div class="notice notice-success"><p>Saving added successfully. The form is ready for the next entry.</p></div>';
        }
        if (!empty($_GET['updated'])) {
            $msg = '<div class="notice notice-success"><p>Saving updated successfully.</p></div>';
        }
        if (!empty($_POST['fl_add_saving'])) {
            check_admin_referer('fl_add_saving');

            $mode = isset($_POST['fl_mode']) ? sanitize_text_field(wp_unslash($_POST['fl_mode'])) : 'create';
            $mode = ($mode === 'edit') ? 'edit' : 'create';

            $line_id = isset($_POST['line_id']) ? sanitize_text_field(wp_unslash($_POST['line_id'])) : '';
            $saving_id = isset($_POST['saving_id']) ? sanitize_text_field(wp_unslash($_POST['saving_id'])) : '';
            $account_name = isset($_POST['account_name']) ? sanitize_text_field(wp_unslash($_POST['account_name'])) : '';
            $amount = isset($_POST['amount']) ? (int) sanitize_text_field(wp_unslash($_POST['amount'])) : 0;
            $institution = isset($_POST['institution']) ? sanitize_text_field(wp_unslash($_POST['institution'])) : '';
            $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';
            $saved_at = isset($_POST['saved_at']) ? sanitize_text_field(wp_unslash($_POST['saved_at'])) : '';

            if (!$saved_at) $saved_at = current_time('mysql');
            if ($mode === 'create') {
                if (!$line_id) $line_id = (string) time() . '_' . wp_generate_password(6, false, false);
                if (!$saving_id) $saving_id = 'sv_' . substr(md5($line_id), 0, 10);
            }

            if (!$account_name || $amount <= 0) {
                $msg = '<div class="notice notice-error"><p>Account name and amount are required.</p></div>';
            } else {
                if ($is_ext && !$this->ds_allow_write_external()) {
                    $msg = '<div class="notice notice-error"><p>External savings table is read-only. Enable “Allow write to external” in Settings to add rows.</p></div>';
                } else {
                    if ($mode === 'edit') {
                        if (!$line_id) {
                            $msg = '<div class="notice notice-error"><p>Missing line ID.</p></div>';
                        } else {
                            $data = [
                                'account_name' => $account_name,
                                'amount' => $amount,
                                'institution' => $institution,
                                'notes' => $notes,
                                $date_col => $saved_at,
                            ];
                            $formats = ['%s','%d','%s','%s','%s'];

                            $updated = $db->update($table, $data, ['line_id' => $line_id], $formats, ['%s']);

                            if ($updated !== false) {
                                $this->log_event('update', 'saving', $line_id, [
                                    'line_id' => $line_id,
                                    'saving_id' => $saving_id,
                                    'amount' => $amount,
                                    'account_name' => $account_name,
                                ]);
                                $redir_args = [
                                    'page' => $return_page ?: 'fl-savings',
                                ];
                                if ($return_from) $redir_args['from'] = $return_from;
                                if ($return_to) $redir_args['to'] = $return_to;
                                $redir_args['updated'] = 1;
                                wp_safe_redirect(add_query_arg($redir_args, admin_url('admin.php')));
                                exit;
                            }
                            $msg = '<div class="notice notice-error"><p>Failed to update. Please check DB privileges.</p></div>';
                        }
                    } else {
                    $data = [
                        'line_id' => $line_id,
                        'saving_id' => $saving_id,
                        'account_name' => $account_name,
                        'amount' => $amount,
                        'institution' => $institution,
                        'notes' => $notes,
                        $date_col => $saved_at,
                    ];
                    $formats = ['%s','%s','%s','%d','%s','%s','%s'];

                    // Optional columns (present in our default schema)
                    $has_user_id = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_id'));
                    if ($has_user_id) {
                        $data['wp_user_id'] = $user && $user->ID ? $user->ID : null;
                        $formats[] = '%d';
                    }
                    $has_user_login = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_login'));
                    if ($has_user_login) {
                        $data['wp_user_login'] = $user && $user->user_login ? $user->user_login : null;
                        $formats[] = '%s';
                    }

                    $insert = $db->insert($table, $data, $formats);

                    if ($insert !== false) {
                    $this->log_event('create', 'saving', $line_id, [
                        'line_id' => $line_id,
                        'saving_id' => $saving_id,
                        'amount' => $amount,
                        'account_name' => $account_name,
                    ]);
                    wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&created=1'));
                    exit;
                }
                $msg = '<div class="notice notice-error"><p>Failed to save. Please check DB privileges.</p></div>';
                    }
                }
            }
        }

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html($edit_mode ? 'Edit Saving' : 'Add Saving', '[simku_add_saving]', '[simku page="add-saving"]');
        echo $msg;

        echo '<div class="fl-grid fl-grid-2">';

        echo '<div class="fl-card">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Saving</h2><span class="fl-muted">Savings / Storage</span></div>';
        echo '<form method="post" enctype="multipart/form-data" class="fl-form">';
        wp_nonce_field('fl_add_saving');
        echo '<input type="hidden" name="fl_add_saving" value="1" />';
        echo '<input type="hidden" name="fl_mode" value="'.esc_attr($edit_mode ? 'edit' : 'create').'" />';

        echo '<label>Line ID (PK) <input type="text" name="line_id" value="'.esc_attr($edit_mode ? ($existing['line_id'] ?? '') : '').'" '.($edit_mode ? 'readonly' : '').' placeholder="'.esc_attr($edit_mode ? '' : 'Auto generated if empty').'" /></label>';
        echo '<label>Saving ID <input type="text" name="saving_id" value="'.esc_attr($edit_mode ? ($existing['saving_id'] ?? '') : '').'" '.($edit_mode ? 'readonly' : '').' placeholder="'.esc_attr($edit_mode ? '' : 'Auto generated if empty').'" /></label>';
        echo '<label>Account name <input type="text" name="account_name" value="'.esc_attr($edit_mode ? ($existing['account_name'] ?? '') : '').'" placeholder="e.g. Emergency Fund" required /></label>';
        echo '<label>Amount (Rp) <input type="number" name="amount" value="'.esc_attr($edit_mode ? (string)($existing['amount'] ?? '') : '').'" min="0" step="1" required /></label>';
        echo '<label>Stored at <input type="text" name="institution" value="'.esc_attr($edit_mode ? ($existing['institution'] ?? '') : '').'" placeholder="e.g. Bank / E-Wallet / Cash" /></label>';
        $saved_at_val = $edit_mode ? ($existing[$date_col] ?? '') : '';
        $saved_at_dt = $saved_at_val ? wp_date('Y-m-d\TH:i', strtotime($saved_at_val)) : wp_date('Y-m-d\TH:i');
        echo '<label>Saved at <input type="datetime-local" name="saved_at" value="'.esc_attr($saved_at_dt).'" /></label>';
        echo '<label>Notes <textarea name="notes" rows="5" placeholder="Additional notes…">'.esc_textarea($edit_mode ? ($existing['notes'] ?? '') : '').'</textarea></label>';
        echo '<div class="fl-btnrow">';
        echo '<button class="button button-primary">'.esc_html($edit_mode ? 'Update' : 'Save').'</button> ';
        echo '<a class="button" href="'.esc_url($back_url).'">Back</a>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div class="fl-card">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Tips</h2></div>';
        echo '<p class="fl-muted">Use <b>Account name</b> for the savings account name (e.g., Emergency Fund, Education, Investments). Use <b>Stored at</b> for where it is kept (Bank / Wallet / Cash).</p>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    /* -------------------- Payment Reminders (Installments / Billing) -------------------- */

    private function normalize_status_label(string $status) : string {
        $s = strtolower(trim($status));
        if ($s === 'lunas' || $s === 'paid' || $s === 'done') return 'Paid';
        return 'Unpaid';
    }

    private function add_month_preserve_day(string $ymd, int $day_of_month) : string {
        try {
            $dt = new DateTime($ymd);
        } catch (Exception $e) {
            return $ymd;
        }
        $dt->modify('first day of next month');
        $year = (int)$dt->format('Y');
        $month = (int)$dt->format('m');
        $last = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $day = max(1, min($day_of_month, $last));
        $dt->setDate($year, $month, $day);
        return $dt->format('Y-m-d');
    }

    private function compute_next_due_date(string $mode, ?string $manual_due_date, ?int $due_day, ?string $first_due_date = null) : string {
        $today = wp_date('Y-m-d');
        if ($mode === 'auto') {
            $day = (int)($due_day ?: 1);
            $anchor = $first_due_date ? $first_due_date : $today;
            try { $dt = new DateTime($anchor); } catch (Exception $e) { $dt = new DateTime($today); }
            // Build candidate in same month
            $year = (int)$dt->format('Y');
            $month = (int)$dt->format('m');
            $last = (int)cal_days_in_month(CAL_GREGORIAN, $month, $year);
            $d = max(1, min($day, $last));
            $cand = sprintf('%04d-%02d-%02d', $year, $month, $d);
            if ($cand < $today) {
                // next month
                return $this->add_month_preserve_day($cand, $day);
            }
            return $cand;
        }
        // manual
        $due = trim((string)$manual_due_date);
        if ($due) return $due;
        return $today;
    }

    private function reminder_ctx(array $row, int $days_left) : array {
        $amt = (float)($row['installment_amount'] ?? 0);
        $tot = (float)($row['total_amount'] ?? 0);
        $status_label = $this->normalize_status_label((string)($row['status'] ?? 'belum'));
        return [
            'payment_name' => (string)($row['payment_name'] ?? ''),
            'due_date' => (string)($row['due_date'] ?? ''),
            'days_left' => (string)$days_left,
            'installment_amount' => number_format_i18n($amt),
            'total_amount' => $tot ? number_format_i18n($tot) : '',
            'installments_paid' => (string)($row['installments_paid'] ?? 0),
            'installments_total' => (string)($row['installments_total'] ?? 1),
            'payee' => (string)($row['payee'] ?? ''),
            'notes' => (string)($row['notes'] ?? ''),
            'status' => $status_label,
            'line_id' => (string)($row['line_id'] ?? ''),
            'reminder_id' => (string)($row['reminder_id'] ?? ''),
        ];
    }

    private function send_telegram_reminder(array $ctx) : bool {
        $s = $this->settings();
        $tpl = (string)($s['notify']['reminder_telegram_tpl'] ?? '');
        if (!$tpl) $tpl = self::default_settings()['notify']['reminder_telegram_tpl'];
        $msg = $this->render_tpl($tpl, $ctx);
        return $this->telegram_send($msg);
    }

    private function send_whatsapp_reminder(array $ctx) : bool {
        $s = $this->settings();
        $tpl = (string)($s['notify']['reminder_telegram_tpl'] ?? '');
        if (!$tpl) $tpl = self::default_settings()['notify']['reminder_telegram_tpl'];
        $msg = $this->render_tpl($tpl, $ctx);
        // WhatsApp: plain text, strip HTML
        $plain = wp_strip_all_tags($msg);
        return $this->whatsapp_webhook_send([
            'type' => 'payment_reminder',
            'text' => $plain,
            'context' => $ctx,
        ]);
    }

    private function send_email_reminder(array $ctx) : bool {
        $s = $this->settings();
        $subj_tpl = (string)($s['notify']['reminder_email_subject_tpl'] ?? '');
        $body_tpl = (string)($s['notify']['reminder_email_body_tpl'] ?? '');
        if (!$subj_tpl) $subj_tpl = self::default_settings()['notify']['reminder_email_subject_tpl'];
        if (!$body_tpl) $body_tpl = self::default_settings()['notify']['reminder_email_body_tpl'];
        $subject = $this->render_tpl($subj_tpl, $ctx);
        $body = $this->render_tpl($body_tpl, $ctx);
        return $this->email_send($subject, $body);
    }

    public function cron_check_payment_reminders() : void {
        $s = $this->settings();
        $notify = $s['notify'] ?? [];
        $offsets = $s['notify']['reminder_offsets'] ?? [7,5,3];
        $offsets = array_values(array_filter(array_map('intval', (array)$offsets)));
        if (!$offsets) $offsets = [7,5,3];

        $db = $this->reminders_db();
        if (!($db instanceof wpdb)) return;
        $table = $this->reminders_table();

        $today = wp_date('Y-m-d');
        $max = max($offsets);
        $end = wp_date('Y-m-d', strtotime("+{$max} day"));

        $sql = "SELECT * FROM `{$table}` WHERE status = %s AND due_date >= %s AND due_date <= %s";
        $rows = $db->get_results($db->prepare($sql, 'belum', $today, $end), ARRAY_A);
        if ($db->last_error) {
            $this->log_event('cron_fail', 'reminders', null, ['error' => $db->last_error]);
            return;
        }

        foreach ((array)$rows as $r) {
            $due = (string)($r['due_date'] ?? '');
            if (!$due) continue;
            $days_left = (int) round((strtotime($due) - strtotime($today)) / DAY_IN_SECONDS);
            if (!in_array($days_left, $offsets, true)) continue;

            // Reset offsets when due_date changes
            $not_for = (string)($r['notified_for_due'] ?? '');
            $sent = [];
            if ($not_for === $due) {
                $sent = array_values(array_filter(array_map('intval', explode(',', (string)($r['notified_offsets'] ?? '')))));
            }
            if (in_array($days_left, $sent, true)) continue;

            $ctx = $this->reminder_ctx($r, $days_left);

            $attempted_any = false;
            $ok_any = false;

            $tg_cfg_ok = !empty($notify['telegram_enabled']) && !empty($notify['telegram_bot_token']) && !empty($notify['telegram_chat_id']);
            $wa_cfg_ok = !empty($notify['whatsapp_webhook']);
            $em_cfg_ok = !empty($notify['email_enabled']) && !empty($notify['email_to']);

            if (!empty($r['notify_telegram']) && $tg_cfg_ok) {
                $attempted_any = true;
                $ok_any = $this->send_telegram_reminder($ctx) || $ok_any;
            }
            if (!empty($r['notify_whatsapp']) && $wa_cfg_ok) {
                $attempted_any = true;
                $ok_any = $this->send_whatsapp_reminder($ctx) || $ok_any;
            }
            if (!empty($r['notify_email']) && $em_cfg_ok) {
                $attempted_any = true;
                $ok_any = $this->send_email_reminder($ctx) || $ok_any;
            }

            // Only mark as sent when we actually attempted to notify on at least one channel.
            // This prevents "silent" consumption when the user hasn't configured any channel yet.
            if ($attempted_any) {
                $sent[] = $days_left;
                $sent = array_values(array_unique(array_filter(array_map('intval', $sent))));
                sort($sent);
                $db->update(
                    $table,
                    [
                        'notified_for_due' => $due,
                        'notified_offsets' => implode(',', $sent),
                        'last_notified_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql'),
                    ],
                    ['line_id' => (string)$r['line_id']],
                    ['%s','%s','%s','%s'],
                    ['%s']
                );
            }

            $this->log_event('reminder_notify', 'reminder', (string)$r['line_id'], [
                'due_date' => $due,
                'days_left' => $days_left,
                'ok_any' => $ok_any ? 1 : 0,
                'attempted_any' => $attempted_any ? 1 : 0,
                'channels' => [
                    'telegram' => !empty($r['notify_telegram']) && $tg_cfg_ok,
                    'whatsapp' => !empty($r['notify_whatsapp']) && $wa_cfg_ok,
                    'email' => !empty($r['notify_email']) && $em_cfg_ok,
                ],
            ]);
        }
    }

    private function reminder_mark_paid(string $line_id) : array {
        $db = $this->reminders_db();
        if (!($db instanceof wpdb)) return [false, 'Datasource error'];
        $is_ext = $this->reminders_is_external();
        if ($is_ext && !$this->ds_allow_write_external()) return [false, 'External reminders table is read-only. Enable “Allow write to external” in Settings.'];

        $table = $this->reminders_table();
        $row = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s", $line_id), ARRAY_A);
        if (!$row) return [false, 'Reminder not found'];

        $paid = (int)($row['installments_paid'] ?? 0);
        $total = (int)($row['installments_total'] ?? 1);
        $due_day = (int)($row['due_day'] ?? 0);
        if (!$due_day) {
            $due_day = (int)wp_date('j', strtotime((string)($row['due_date'] ?? wp_date('Y-m-d'))));
        }

        $new_paid = min($total, $paid + 1);
        if ($new_paid < $total) {
            $next_due = $this->add_month_preserve_day((string)$row['due_date'], $due_day);
            $upd = [
                'installments_paid' => $new_paid,
                'status' => 'belum',
                'due_date' => $next_due,
                'notified_for_due' => null,
                'notified_offsets' => null,
                'updated_at' => current_time('mysql'),
            ];
            $db->update($table, $upd, ['line_id' => $line_id]);
            $this->log_event('update', 'reminder', $line_id, ['action'=>'mark_paid','next_due'=>$next_due,'paid'=>$new_paid,'total'=>$total]);
            return [true, 'Marked as paid. Next due date generated: ' . $next_due];
        }

        $upd = [
            'installments_paid' => $new_paid,
            'status' => 'lunas',
            'updated_at' => current_time('mysql'),
        ];
        $db->update($table, $upd, ['line_id' => $line_id]);
        $this->log_event('update', 'reminder', $line_id, ['action'=>'mark_paid','paid'=>$new_paid,'total'=>$total,'completed'=>1]);
        return [true, 'Marked as paid (Paid).'];
    }

    public function page_reminders() : void {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die('Forbidden');

        $db = $this->reminders_db();
        if (!($db instanceof wpdb)) {
            echo '<div class="wrap"><h1>Reminders</h1><div class="notice notice-error"><p>Datasource error: reminders DB is not available.</p></div></div>';
            return;
        }
        $table = $this->reminders_table();


// View reminder images (supports multiple).
if (!empty($_GET['view_images'])) {
    $rid = sanitize_text_field(wp_unslash($_GET['view_images']));
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'fl_reminder_view_images_' . $rid)) {
        wp_die('Invalid nonce.');
    }

    $row = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s LIMIT 1", $rid), ARRAY_A);
    if (!$row) {
        wp_die('Reminder not found.');
    }

    $imgs = $this->normalize_images_field($row['gambar_url'] ?? '');

    echo '<div class="wrap fl-wrap">';
    echo $this->page_header_html('Reminder Images');
    echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=fl-reminders')).'">&larr; Back</a></p>';

    if (empty($imgs)) {
        echo '<div class="notice notice-info"><p>No images attached.</p></div>';
    } else {
        echo '<div class="fl-card">';
        foreach ($imgs as $u) {
            $u_safe = esc_url($u);
            echo '<div style="margin-bottom:14px">';
            echo '<a href="'.$u_safe.'" target="_blank" rel="noopener">'.$u_safe.'</a><br />';
            echo '<img src="'.$u_safe.'" style="max-width:520px; width:100%; height:auto; border:1px solid #e5e7eb; border-radius:8px; margin-top:6px" />';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '</div>';
    return;
}

        $notice = '';
        if (!empty($_GET['action']) && $_GET['action'] === 'mark_paid' && !empty($_GET['id'])) {
            $id = sanitize_text_field(wp_unslash($_GET['id']));
            if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'fl_reminder_mark_paid_' . $id)) {
                $notice = '<div class="notice notice-error"><p>Invalid nonce.</p></div>';
            } else {
                [$ok,$msg] = $this->reminder_mark_paid($id);
                $notice = '<div class="notice '.($ok?'notice-success':'notice-error').'"><p>'.esc_html($msg).'</p></div>';
            }
        }

        $status = sanitize_text_field(wp_unslash($_GET['status'] ?? 'belum'));
        if (!in_array($status, ['all','belum','lunas'], true)) $status = 'belum';
        $q = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));

        $where = '1=1';
        $params = [];
        if ($status !== 'all') {
            $where .= ' AND status = %s';
            $params[] = $status;
        }
        if ($q) {
            $where .= ' AND payment_name LIKE %s';
            $params[] = '%' . $db->esc_like($q) . '%';
        }

        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY status ASC, due_date ASC, updated_at DESC LIMIT 500";
        $rows = $params ? $db->get_results($db->prepare($sql, $params), ARRAY_A) : $db->get_results($sql, ARRAY_A);

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Reminders', '[simku_reminders]', '[simku page="reminders"]');
        echo $notice;

        echo '<div class="fl-actions" style="margin: 10px 0 16px">';
        echo '<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=fl-add-reminder')).'">Add Reminder</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-add-reminder#bulk')).'">Bulk CSV</a>';
        echo '</div>';

        echo '<div class="fl-card">';
        echo '<form method="get" class="fl-form">';
        echo '<input type="hidden" name="page" value="fl-reminders" />';
        echo '<div class="fl-grid fl-grid-3">';
        echo '<div class="fl-field"><label>Status</label><select name="status">';
        foreach (['belum'=>'Unpaid','lunas'=>'Paid','all'=>'All'] as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
        echo '<div class="fl-field"><label>Search</label><input type="search" name="s" value="'.esc_attr($q).'" placeholder="e.g. Installments Motor" /></div>';
        echo '<div class="fl-field" style="display:flex;align-items:flex-end;gap:10px">';
        echo '<button class="button button-primary" style="height:38px">Apply</button>';
        echo '<a class="button" style="height:38px;display:flex;align-items:center" href="'.esc_url(admin_url('admin.php?page=fl-reminders')).'">Reset</a>';
        echo '</div>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Summary cards
        $today = wp_date('Y-m-d');
        $in7 = wp_date('Y-m-d', strtotime('+7 day'));
        $upcoming = $db->get_results($db->prepare("SELECT due_date, installment_amount FROM `{$table}` WHERE status=%s AND due_date >= %s AND due_date <= %s", 'belum', $today, $in7), ARRAY_A);
        $cnt = count((array)$upcoming);
        $sum = 0.0; foreach ((array)$upcoming as $u) $sum += (float)($u['installment_amount'] ?? 0);
        echo '<div class="fl-grid fl-grid-3" style="margin-top:16px">';
        echo '<div class="fl-card"><div class="fl-kpi"><div class="fl-kpi-label">Upcoming (7 days)</div><div class="fl-kpi-value">'.esc_html((string)$cnt).'</div></div></div>';
        echo '<div class="fl-card"><div class="fl-kpi"><div class="fl-kpi-label">Total upcoming</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n($sum)).'</div></div></div>';
        echo '<div class="fl-card"><div class="fl-kpi"><div class="fl-kpi-label">Cron</div><div class="fl-kpi-value" style="font-size:14px">H-7 / H-5 / H-3</div></div></div>';
        echo '</div>';

        echo '<div class="fl-card" style="margin-top:16px">';
        echo '<div class="fl-card-head"><h2 style="margin:0">List</h2><span class="fl-muted">Up to 500 rows</span></div>';
        echo '<div class="fl-table-wrap"><table class="widefat striped fl-table">';
        echo '<thead><tr>';
        echo '<th style="width:72px">ID</th><th>Name</th><th>Due date</th><th>Nominal</th><th>Installments</th><th>Status</th><th>Notify</th><th>Image</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="9" class="fl-muted">No reminders found.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $id_val = isset($r['id']) ? (string)$r['id'] : (string)($r['line_id'] ?? '');
                $due = (string)($r['due_date'] ?? '');
                $days_left = $due ? (int) round((strtotime($due) - strtotime($today)) / DAY_IN_SECONDS) : 0;
                $days_badge = $due ? ('<span class="fl-badge '.($days_left<=3?'fl-badge-bad':'fl-badge-sub').'">H-'.esc_html((string)$days_left).'</span>') : '';
                $status_label = $this->normalize_status_label((string)($r['status'] ?? 'belum'));
                $status_badge = $status_label === 'Paid' ? '<span class="fl-badge fl-badge-ok">Paid</span>' : '<span class="fl-badge fl-badge-warn">Unpaid</span>';
                $notify = [];
                if (!empty($r['notify_telegram'])) $notify[] = 'TG';
                if (!empty($r['notify_whatsapp'])) $notify[] = 'WA';
                if (!empty($r['notify_email'])) $notify[] = 'Email';
                $notify_txt = $notify ? implode(', ', $notify) : '-';

                $edit_url = admin_url('admin.php?page=fl-add-reminder&edit=' . rawurlencode((string)$r['line_id']));
                $mark_url = wp_nonce_url(admin_url('admin.php?page=fl-reminders&action=mark_paid&id=' . rawurlencode((string)$r['line_id'])), 'fl_reminder_mark_paid_' . (string)$r['line_id']);

                echo '<tr>';
                echo '<td><code style="font-size:12px">'.esc_html($id_val).'</code></td>';
                echo '<td><div style="font-weight:600">'.esc_html((string)$r['payment_name']).'</div><div class="fl-muted" style="font-size:12px">'.esc_html((string)($r['payee'] ?? '')).'</div></td>';
                echo '<td>'.esc_html($due ?: '-').' '.$days_badge.'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((float)($r['installment_amount'] ?? 0))).'</td>';
                echo '<td>'.esc_html((string)($r['installments_paid'] ?? 0)).'/'.esc_html((string)($r['installments_total'] ?? 1)).'</td>';
                echo '<td>'.$status_badge.'</td>';
                echo '<td>'.esc_html($notify_txt).'</td>';

$imgs = $this->normalize_images_field($r['gambar_url'] ?? '');
if (!empty($imgs)) {
    $nonce = wp_create_nonce('fl_reminder_view_images_' . (string)$r['line_id']);
    $view_url = admin_url('admin.php?page=fl-reminders&view_images=' . rawurlencode((string)$r['line_id']) . '&_wpnonce=' . $nonce);
    $label = count($imgs) > 1 ? ('View (' . count($imgs) . ')') : 'View';
    $img_btn = '<a class="button button-small" href="'.esc_url($view_url).'">'.esc_html($label).'</a>';
} else {
    $img_btn = '<span class="fl-muted">-</span>';
}
echo '<td>' . $img_btn . '</td>';
echo '<td>';
                echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> ';
                if ($status_label !== 'Paid') {
                    echo '<a class="button button-small" href="'.esc_url($mark_url).'">Mark paid</a>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
        echo '</div>';

        echo '</div>';
    }

    public function page_add_reminder() : void {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die('Forbidden');

        $db = $this->reminders_db();
        if (!($db instanceof wpdb)) {
            echo '<div class="wrap"><h1>Add Reminder</h1><div class="notice notice-error"><p>Datasource error: reminders DB is not available.</p></div></div>';
            return;
        }
        $table = $this->reminders_table();
        $is_ext = $this->reminders_is_external();
        $user = wp_get_current_user();

        $edit_id = sanitize_text_field(wp_unslash($_GET['edit'] ?? ''));
        $editing = $edit_id ? true : false;
        $existing = null;
        if ($editing) {
            $existing = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s", $edit_id), ARRAY_A);
            if (!$existing) {
                $editing = false;
                $edit_id = '';
            }
        }

        $msg = '';

        // Success notice after PRG redirect (so user can add again quickly)
        if (!empty($_GET['created'])) {
            $last = sanitize_text_field(wp_unslash($_GET['last'] ?? ''));
            $link = $last ? (' <a href="' . esc_url(admin_url('admin.php?page=fl-add-reminder&edit=' . rawurlencode($last))) . '">Edit reminder ini</a>.') : '';
            $msg = '<div class="notice notice-success"><p>Reminder created.' . $link . ' The form is ready for the next entry.</p></div>';
        }

        // Bulk CSV import
        if (!empty($_POST['fl_import_reminders_csv'])) {
            check_admin_referer('fl_import_reminders_csv');
            if ($is_ext && !$this->ds_allow_write_external()) {
                $msg = '<div class="notice notice-error"><p>External reminders table is read-only. Enable “Allow write to external” in Settings to import.</p></div>';
            } elseif (empty($_FILES['reminders_csv']['name'])) {
                $msg = '<div class="notice notice-error"><p>Please choose a CSV file.</p></div>';
            } else {
                $file = $_FILES['reminders_csv'];
                if (!empty($file['error'])) {
                    $msg = '<div class="notice notice-error"><p>Upload failed (error code: '.esc_html((string)$file['error']).').</p></div>';
                } else {
                    $path = $file['tmp_name'] ?? '';
                    if (!$path || !is_uploaded_file($path)) {
                        $msg = '<div class="notice notice-error"><p>Invalid upload.</p></div>';
                    } else {
                        $fh = fopen($path, 'r');
                        if (!$fh) {
                            $msg = '<div class="notice notice-error"><p>Cannot read uploaded file.</p></div>';
                        } else {
                        $header = fgetcsv($fh);
                        $inserted = 0; $skipped = 0;
                        $map = [];
                        if (is_array($header)) {
                            foreach ($header as $i => $h) {
                                $key = strtolower(trim((string)$h));
                                $map[$key] = $i;
                            }
                        }
                        $col = function(array $row, array $keys) use ($map) {
                            foreach ($keys as $k) {
                                $k = strtolower($k);
                                if (isset($map[$k])) {
                                    return $row[$map[$k]] ?? '';
                                }
                            }
                            return '';
                        };

                        while (($row = fgetcsv($fh)) !== false) {
                            if (!is_array($row) || count($row) === 0) continue;
                            $payment_name = sanitize_text_field($col($row, ['payment_name','nama_pembayaran','nama','title']));
                            $installment_amount = (int) preg_replace('/[^0-9]/', '', (string)$col($row, ['installment_amount','nominal','amount']));
                            $installments_total = (int) preg_replace('/[^0-9]/', '', (string)$col($row, ['installments_total','cicilan','bulan','months']));
                            if ($installments_total < 1) $installments_total = 1;
                            if ($installments_total > 12) $installments_total = 12;
                            $due_date = $this->parse_csv_date($col($row, ['due_date','jatuh_tempo','tanggal_jatuh_tempo']));
                            $schedule_mode = strtolower(sanitize_text_field($col($row, ['schedule_mode','mode']))) === 'auto' ? 'auto' : 'manual';
                            $due_day = (int) preg_replace('/[^0-9]/', '', (string)$col($row, ['due_day','tanggal','day']));
                            $payee = sanitize_text_field($col($row, ['payee','dibayar_ke','dibayar_di','where']));
                            $notes = (string)$col($row, ['notes','keterangan','note']);
                            $status_in = strtolower(sanitize_text_field($col($row, ['status'])));
                            $status = ($status_in === 'lunas' || $status_in === 'paid') ? 'lunas' : 'belum';
                            $tg_raw = (string)$col($row, ['notify_telegram','telegram','tg']);
                            $wa_raw = (string)$col($row, ['notify_whatsapp','whatsapp','wa']);
                            $em_raw = (string)$col($row, ['notify_email','email']);

                            $notify_tg = trim($tg_raw) === '' ? 1 : ((int)preg_replace('/[^0-9]/', '', $tg_raw) ? 1 : 0);
                            $notify_wa = trim($wa_raw) === '' ? 0 : ((int)preg_replace('/[^0-9]/', '', $wa_raw) ? 1 : 0);
                            $notify_em = trim($em_raw) === '' ? 0 : ((int)preg_replace('/[^0-9]/', '', $em_raw) ? 1 : 0);

                            if (!$payment_name || $installment_amount <= 0) { $skipped++; continue; }

                            if ($schedule_mode === 'auto' && !$due_day) {
                                $due_day = (int)wp_date('j');
                            }
                            if (!$due_date) {
                                $due_date = $this->compute_next_due_date($schedule_mode, null, $due_day, null);
                            }
                            if (!$due_day) $due_day = (int)wp_date('j', strtotime($due_date));

                            $line_id = (string) time() . '_' . wp_generate_password(6, false, false);
                            $reminder_id = 'rm_' . substr(md5($line_id), 0, 10);

                            $data = [
                                'line_id' => $line_id,
                                'reminder_id' => $reminder_id,
                                'payment_name' => $payment_name,
                                'total_amount' => $installment_amount * $installments_total,
                                'installment_amount' => $installment_amount,
                                'installments_total' => $installments_total,
                                'installments_paid' => ($status === 'lunas') ? $installments_total : 0,
                                'schedule_mode' => $schedule_mode,
                                'due_day' => $due_day,
                                'due_date' => $due_date,
                                'payee' => $payee,
                                'notes' => $notes,
                                'status' => $status,
                                'notify_telegram' => $notify_tg,
                                'notify_whatsapp' => $notify_wa,
                                'notify_email' => $notify_em,
                                'created_at' => current_time('mysql'),
                                'updated_at' => current_time('mysql'),
                            ];

                            // Optional user columns
                            $has_user_id = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_id'));
                            if ($has_user_id) $data['wp_user_id'] = $user && $user->ID ? $user->ID : null;
                            $has_user_login = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_login'));
                            if ($has_user_login) $data['wp_user_login'] = $user && $user->user_login ? $user->user_login : null;

                            $ok = $db->insert($table, $data);
                            if ($ok !== false) { $inserted++; $this->log_event('create','reminder',$line_id,['bulk'=>1,'payment_name'=>$payment_name]); }
                            else { $skipped++; }
                        }
                            fclose($fh);
                            $msg = '<div class="notice notice-success"><p>Import finished. Inserted: '.esc_html((string)$inserted).', Skipped: '.esc_html((string)$skipped).'.</p></div>';
                        }
                    }
                }
            }
        }

        // Save add/edit
        if (!empty($_POST['fl_save_reminder'])) {
            check_admin_referer('fl_save_reminder');

            $existing_images = [];
            if ($editing && !empty($edit_row['gambar_url'])) {
                $existing_images = $this->normalize_images_field($edit_row['gambar_url']);
            }

            $remove_images = isset($_POST['remove_images']) ? (array)$_POST['remove_images'] : [];
            $remove_images = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', $remove_images))));

            if (!empty($remove_images)) {
                $existing_images = array_values(array_diff($existing_images, $remove_images));
            }

            // URLs pasted in the "Image URL" field (supports multiple URLs separated by newline/comma).
            $gambar_url_in = isset($_POST['gambar_url']) ? trim((string)$_POST['gambar_url']) : '';
            $url_images = $gambar_url_in ? $this->normalize_images_field($gambar_url_in) : [];

            // Upload images (supports multiple).
            $uploaded_images = [];
            if (!empty($_FILES['gambar_files']) && !empty($_FILES['gambar_files']['name'])) {
                $multi = $this->handle_multi_image_upload('gambar_files');
                if (!empty($multi['urls'])) {
                    $uploaded_images = $multi['urls'];
                } elseif (!empty($multi['error'])) {
                    wp_die('Upload image gagal: ' . esc_html($multi['error']));
                }
            }

            // Backward compatibility: old single field name.
            if (empty($uploaded_images) && !empty($_FILES['gambar_file']['name'])) {
                $single = $this->handle_tx_image_upload('gambar_file');
                if (!empty($single['ok'])) {
                    $uploaded_images = [$single['url']];
                } elseif (!empty($single['error'])) {
                    wp_die('Upload image gagal: ' . esc_html($single['error']));
                }
            }

            $all_images = array_values(array_unique(array_merge($existing_images, $uploaded_images, $url_images)));
            $gambar_url = !empty($all_images) ? wp_json_encode($all_images) : '';

            if ($is_ext && !$this->ds_allow_write_external()) {
                $msg = '<div class="notice notice-error"><p>External reminders table is read-only. Enable “Allow write to external” in Settings to add/edit.</p></div>';
            } else {
                $line_id = $editing ? $edit_id : sanitize_text_field(wp_unslash($_POST['line_id'] ?? ''));
                $reminder_id = sanitize_text_field(wp_unslash($_POST['reminder_id'] ?? ''));
                $payment_name = sanitize_text_field(wp_unslash($_POST['payment_name'] ?? ''));
                $installment_amount = (int) preg_replace('/[^0-9]/', '', (string)wp_unslash($_POST['installment_amount'] ?? '0'));
                $installments_total = (int) preg_replace('/[^0-9]/', '', (string)wp_unslash($_POST['installments_total'] ?? '1'));
                if ($installments_total < 1) $installments_total = 1;
                if ($installments_total > 12) $installments_total = 12;
                $schedule_mode = sanitize_text_field(wp_unslash($_POST['schedule_mode'] ?? 'manual'));
                if (!in_array($schedule_mode, ['manual','auto'], true)) $schedule_mode = 'manual';
                $due_date_manual = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));
                $due_day = (int) preg_replace('/[^0-9]/', '', (string)wp_unslash($_POST['due_day'] ?? ''));
                $first_due = sanitize_text_field(wp_unslash($_POST['first_due_date'] ?? ''));
                $payee = sanitize_text_field(wp_unslash($_POST['payee'] ?? ''));
                $notes = (string)wp_unslash($_POST['notes'] ?? '');
                $status = sanitize_text_field(wp_unslash($_POST['status'] ?? 'belum'));
                if (!in_array($status, ['belum','lunas'], true)) $status = 'belum';
                $notify_tg = !empty($_POST['notify_telegram']) ? 1 : 0;
                $notify_wa = !empty($_POST['notify_whatsapp']) ? 1 : 0;
                $notify_em = !empty($_POST['notify_email']) ? 1 : 0;

                if (!$payment_name || $installment_amount <= 0) {
                    $msg = '<div class="notice notice-error"><p>Payment name and amount are required.</p></div>';
                } else {
                    if (!$line_id) $line_id = (string) time() . '_' . wp_generate_password(6, false, false);
                    if (!$reminder_id) $reminder_id = 'rm_' . substr(md5($line_id), 0, 10);
                    if (!$due_day) {
                        $due_day = $schedule_mode === 'auto' ? (int)wp_date('j') : (int)wp_date('j', strtotime($due_date_manual ?: wp_date('Y-m-d')));
                    }
                    $due_date = $this->compute_next_due_date($schedule_mode, $due_date_manual ?: null, $due_day ?: null, $first_due ?: null);

                    $paid = $editing ? (int)($existing['installments_paid'] ?? 0) : 0;
                    $old_status = $editing ? (string)($existing['status'] ?? 'belum') : 'belum';

                    $data = [
                        'reminder_id' => $reminder_id,
                        'payment_name' => $payment_name,
                        'total_amount' => $installment_amount * $installments_total,
                        'installment_amount' => $installment_amount,
                        'installments_total' => $installments_total,
                        'installments_paid' => $paid,
                        'schedule_mode' => $schedule_mode,
                        'due_day' => $due_day,
                        'due_date' => $due_date,
                        'payee' => $payee,
                        'notes' => $notes,
                        'status' => $status,
                        'notify_telegram' => $notify_tg,
                        'notify_whatsapp' => $notify_wa,
                        'notify_email' => $notify_em,
                        'updated_at' => current_time('mysql'),
                    ];

                    
                    $has_gambar_col = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'gambar_url'));
                    if ($has_gambar_col) $data['gambar_url'] = $gambar_url;

                    if (!$editing) {
                        $data['line_id'] = $line_id;
                        $data['created_at'] = current_time('mysql');
                    }

                    // Optional user columns
                    $has_user_id = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_id'));
                    if ($has_user_id) $data['wp_user_id'] = $user && $user->ID ? $user->ID : null;
                    $has_user_login = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'wp_user_login'));
                    if ($has_user_login) $data['wp_user_login'] = $user && $user->user_login ? $user->user_login : null;

                    // When editing: if user marks as Paid and previously Unpaid, treat it as "paid this cycle".
                    $auto_notice = '';
                    if ($editing && $old_status !== 'lunas' && $status === 'lunas') {
                        $new_paid = min($installments_total, $paid + 1);
                        if ($new_paid < $installments_total) {
                            $next_due = $this->add_month_preserve_day($due_date, $due_day);
                            $data['installments_paid'] = $new_paid;
                            $data['status'] = 'belum';
                            $data['due_date'] = $next_due;
                            $data['notified_for_due'] = null;
                            $data['notified_offsets'] = null;
                            $auto_notice = ' Status automatically resets to Unpaid for the next installment (next due: ' . $next_due . ').';
                        } else {
                            $data['installments_paid'] = $new_paid;
                            $data['status'] = 'lunas';
                        }
                    }

                    if ($editing) {
                        $ok = $db->update($table, $data, ['line_id' => $edit_id]);
                        if ($ok !== false) {
                            $this->log_event('update', 'reminder', $edit_id, ['payment_name'=>$payment_name]);
                            $msg = '<div class="notice notice-success"><p>Saved.'.esc_html($auto_notice).'</p></div>';
                            $existing = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s", $edit_id), ARRAY_A);
                        } else {
                            $msg = '<div class="notice notice-error"><p>Failed to save. '.$db->last_error.'</p></div>';
                        }
                    } else {
                        $ok = $db->insert($table, $data);
                        if ($ok !== false) {
                            $this->log_event('create', 'reminder', $line_id, ['payment_name'=>$payment_name]);
                            wp_safe_redirect(admin_url('admin.php?page=fl-add-reminder&created=1&last=' . rawurlencode((string)$line_id)));
                            exit;
                        }
                        $msg = '<div class="notice notice-error"><p>Failed to save. '.$db->last_error.'</p></div>';
                    }
                }
            }
        }

        $val = function(string $k, $default = '') use ($existing) {
            if (!$existing) return $default;
            return $existing[$k] ?? $default;
        };

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html(($editing?'Edit Reminder':'Add Reminder'), '[simku_add_reminder]', '[simku page="add-reminder"]');
                echo $msg;

        echo '<div class="fl-grid fl-grid-2">';

        echo '<div class="fl-card">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Reminder</h2><span class="fl-muted">Installments / Billing</span></div>';
        echo '<form method="post" class="fl-form" enctype="multipart/form-data">';
        wp_nonce_field('fl_save_reminder');
        echo '<input type="hidden" name="fl_save_reminder" value="1" />';

        if (!$editing) {
            echo '<label>Line ID (PK) <input type="text" name="line_id" placeholder="Auto generated if empty" /></label>';
        } else {
            echo '<label>Line ID (PK) <input type="text" value="'.esc_attr((string)$edit_id).'" readonly /></label>';
        }
        echo '<label>Reminder ID <input type="text" name="reminder_id" value="'.esc_attr((string)$val('reminder_id','')).'" placeholder="Auto generated if empty" /></label>';
        echo '<label>Payment name <input type="text" name="payment_name" value="'.esc_attr((string)$val('payment_name','')).'" placeholder="e.g. Installments motor" required /></label>';
        echo '<label>Amount (Rp) <input type="number" name="installment_amount" min="0" step="1" value="'.esc_attr((string)$val('installment_amount','')).'" required /></label>';
        echo '<label>Total installments (months) <select name="installments_total">';
        $total_cur = (int)$val('installments_total', 1);
        for ($i=1;$i<=12;$i++) {
            echo '<option value="'.esc_attr((string)$i).'" '.selected($total_cur,$i,false).'>'.esc_html((string)$i).'</option>';
        }
        echo '</select></label>';

        $mode_cur = (string)$val('schedule_mode','manual');
        if (!in_array($mode_cur, ['manual','auto'], true)) $mode_cur = 'manual';
        echo '<label>Due date input <select name="schedule_mode">';
        echo '<option value="manual" '.selected($mode_cur,'manual',false).'>Manual (pick date)</option>';
        echo '<option value="auto" '.selected($mode_cur,'auto',false).'>Auto monthly (pick day)</option>';
        echo '</select></label>';

        // Due date (manual) + due day (auto)
        $due_cur = (string)$val('due_date', wp_date('Y-m-d'));
        echo '<label>Due date <input type="date" name="due_date" value="'.esc_attr($due_cur).'" /></label>';
        $due_day_cur = (int)$val('due_day', (int)wp_date('j', strtotime($due_cur)));
        echo '<label>Day of month (for Auto) <input type="number" name="due_day" min="1" max="31" value="'.esc_attr((string)$due_day_cur).'" placeholder="1-31" /></label>';
        echo '<label>Auto: first due date (optional) <input type="date" name="first_due_date" value="" placeholder="If empty: auto calculated" /></label>';

        
echo '<label>Payment recipient <input type="text" name="payee" value="'.esc_attr((string)$val('payee','')).'" placeholder="e.g. Leasing / Bank / Provider" /></label>';

// Images (supports multiple).
$existing_imgs = $this->normalize_images_field($val('gambar_url',''));

echo '<div class="fl-field">';
echo '<label style="display:block; margin-bottom:4px">Upload images (optional)</label>';
echo '<div class="fl-filepicker">';
echo '<input type="file" id="fl_reminder_images" class="fl-hidden-file" name="gambar_files[]" accept="image/*" multiple />';
echo '<button type="button" class="button fl-file-trigger" data-target="#fl_reminder_images">Choose files</button>';
echo '<span class="fl-file-names">No files chosen</span>';
echo '</div>';
echo '<div class="fl-muted" style="margin-top:4px">You can upload multiple images (e.g. bukti pembayaran cicilan).</div>';
echo '</div>';

echo '<label>Image URL(s) (optional) <textarea name="gambar_url" rows="2" placeholder="https://... (one per line)"></textarea></label>';

if (!empty($existing_imgs)) {
    echo '<div class="fl-field" style="margin-top:8px">';
    echo '<div class="fl-muted" style="margin-bottom:6px">Existing images:</div>';
    echo '<div class="fl-tags">';
    foreach ($existing_imgs as $u) {
        $u_safe = esc_url($u);
        $short = esc_html(wp_basename(parse_url($u_safe, PHP_URL_PATH) ?: $u_safe));
        echo '<span class="fl-tag">';
        echo '<a href="'.$u_safe.'" target="_blank" rel="noopener">View</a> ';
        echo '<span class="fl-muted">'.$short.'</span> ';
        echo '<label style="margin-left:6px"><input type="checkbox" name="remove_images[]" value="'.esc_attr($u).'"> remove</label>';
        echo '</span>';
    }
    echo '</div>';
    echo '</div>';
}
        $status_cur = (string)$val('status','belum');
        if (!in_array($status_cur, ['belum','lunas'], true)) $status_cur = 'belum';
        echo '<label>Status <select name="status">';
        echo '<option value="belum" '.selected($status_cur,'belum',false).'>Unpaid</option>';
        echo '<option value="lunas" '.selected($status_cur,'lunas',false).'>Paid</option>';
        echo '</select></label>';

        $ntg = (int)$val('notify_telegram', 1);
        $nwa = (int)$val('notify_whatsapp', 0);
        $nem = (int)$val('notify_email', 0);
        echo '<div class="fl-check-group" style="margin-top:6px">';
        echo '<label><input type="checkbox" name="notify_telegram" value="1" '.checked($ntg,1,false).' /> Telegram</label> ';
        echo '<label><input type="checkbox" name="notify_whatsapp" value="1" '.checked($nwa,1,false).' /> WhatsApp</label> ';
        echo '<label><input type="checkbox" name="notify_email" value="1" '.checked($nem,1,false).' /> Email</label>';
        echo '<div class="fl-help">Reminders are sent automatically on D-7, D-5, and D-3 before the due date (based on due_date).</div>';
        echo '</div>';

        echo '<label>Additional notes <textarea name="notes" rows="5" placeholder="Additional notes…">'.esc_textarea((string)$val('notes','')).'</textarea></label>';

        echo '<div class="fl-btnrow">';
        echo '<button class="button button-primary">Save</button> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-reminders')).'">Back</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>';

        echo '<div class="fl-card" id="bulk">';
        echo '<div class="fl-card-head"><h2 style="margin:0">Bulk import CSV</h2><span class="fl-muted">Upload multiple reminders at once</span></div>';
        echo '<form method="post" enctype="multipart/form-data" class="fl-form">';
        wp_nonce_field('fl_import_reminders_csv');
        echo '<input type="hidden" name="fl_import_reminders_csv" value="1" />';
        echo '<label>CSV file <input type="file" name="reminders_csv" accept=".csv,text/csv" /></label>';
        echo '<div class="fl-help">Supported headers: payment_name (alias: nama_pembayaran), installment_amount (alias: nominal), installments_total (alias: bulan), due_date (alias: jatuh_tempo), schedule_mode (manual|auto), due_day (1-31), payee, notes, status (belum|lunas), notify_telegram (1/0), notify_whatsapp (1/0), notify_email (1/0).</div>';
        echo '<button class="button">Import</button>';
        echo '</form>';
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }

    private function export_pdf_transactions(array $rows, array $filters) : void {
        // Minimal PDF (text-only) with nicer formatting. Outputs download and exits.
        if (headers_sent()) return;

        $title = "SIMKU Transactions Report";
        $generated = wp_date('Y-m-d H:i:s');

        $income = 0.0; $expense = 0.0;
        foreach ((array)$rows as $r) {
            $amt = (float)(($r['harga'] ?? 0) * ($r['quantity'] ?? 0));
            $cat = (string)($r['kategori'] ?? '');
            if ($cat === 'income') $income += $amt;
            else $expense += $amt;
        }
        // Balance is intentionally omitted from this PDF summary (Income/Expense only)

        $lines = [];
        $lines[] = $title;
        $lines[] = "Generated: {$generated}";
        $lines[] = "Range: " . (($filters['date_from'] ?? '') ?: '-') . " to " . (($filters['date_to'] ?? '') ?: '-');
        $lines[] = "Category: " . (($filters['kategori'] ?? '') ?: 'All') . " | Search: " . (($filters['s'] ?? '') ?: '-');
        $lines[] = str_repeat('-', 92);
        $lines[] = sprintf("Summary  | Income: Rp %s | Expense: Rp %s",
            number_format_i18n($income), number_format_i18n($expense)
        );
        $lines[] = str_repeat('-', 92);
        $lines[] = "Transactions";
        $lines[] = str_repeat('-', 92);

        foreach ((array)$rows as $r) {
            $dt = $this->fmt_mysql_dt_display((string)($r['tanggal_input'] ?? ''));
            $user = (string)($r['wp_user_login'] ?? '');
            $cat = (string)($r['kategori'] ?? '');
            $store = (string)($r['nama_toko'] ?? '');
            $item = (string)($r['items'] ?? '');
            $qty = (string)($r['quantity'] ?? '');
            $harga_num = (float)($r['harga'] ?? 0);
            $total = (float)(($r['harga'] ?? 0) * ($r['quantity'] ?? 0));
            $desc = trim((string)($r['description'] ?? ''));

            $lines[] = "• " . ($dt ?: '-') . " | " . ($cat ?: '-') . " | Rp " . number_format_i18n($total);
            if ($user)  $lines[] = "  User   : " . $user;
            if ($store) $lines[] = "  Store  : " . $store;
            if ($item)  $lines[] = "  Item   : " . $item;
            $lines[] = "  Qty    : " . ($qty ?: '-') . "  |  Price: Rp " . number_format_i18n($harga_num);
            if (!empty($r['transaction_id'])) $lines[] = "  Tx ID  : " . (string)$r['transaction_id'];
            if ($desc)  $lines[] = "  Note   : " . $desc;
            $lines[] = str_repeat('-', 92);
        }

        $text = implode("
", $lines);

        $pdf = $this->simple_text_pdf($text);

        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="simku-transactions.pdf"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

	    
private function export_pdf_report(string $title, array $tot, array $meta = []) : void {
        $generated = wp_date('Y-m-d H:i:s');
        $range_display = (string)($meta['range_display'] ?? ($meta['range'] ?? ''));
        $start_dt = (string)($meta['start_dt'] ?? '');
        $end_dt = (string)($meta['end_dt'] ?? '');
        $date_basis = $this->sanitize_date_basis((string)($meta['date_basis'] ?? 'input'));
        $user_login = (string)($meta['user_login'] ?? '');
        $tx_type = $this->reports_sanitize_tx_type((string)($meta['tx_type'] ?? 'all'));

        // Fetch detailed rows for the report table (per-item rows).
        $rows = [];
        $truncated = false;
        if ($start_dt !== '' && $end_dt !== '') {
            $limit = 2000;
            $rows = $this->fetch_report_detail_rows($start_dt, $end_dt, $date_basis, $limit + 1, ($user_login !== '' && $user_login !== 'all' && $user_login !== '0') ? $user_login : null, $tx_type);
            if (count($rows) > $limit) {
                $truncated = true;
                $rows = array_slice($rows, 0, $limit);
            }
        }

        $pages = $this->build_report_pdf_pages($title, $generated, $range_display, $tot, $rows, $truncated, $tx_type);
        $pdf = $this->simple_pdf_pages($pages, [
            'F1' => 'Helvetica',
            'F2' => 'Helvetica-Bold',
            'F3' => 'Helvetica-Oblique',
        ]);

        $filename = sanitize_file_name(strtolower(str_replace([' ',':'], '_', $title))).'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo $pdf;
        exit;
    }

    
private function fetch_report_detail_rows(string $start_dt, string $end_dt, string $date_basis, int $limit = 2000, ?string $user_login = null, string $tx_type = 'all') : array {
    $db = $this->ds_db();
    if (!($db instanceof wpdb)) return [];

    $table = $this->ds_table();
    $date_expr = $this->date_basis_expr($date_basis);

    $purchase_expr = $this->ds_column_exists('tanggal_struk') ? "DATE(tanggal_struk)" : "NULL";
    $entry_expr    = $this->ds_column_exists('tanggal_input') ? "tanggal_input" : "NULL";

    $user_col = $this->tx_user_col();
    $user_expr = ($user_col && $this->ds_column_exists($user_col)) ? "`{$user_col}`" : "NULL";
    $desc_expr = $this->ds_column_exists('description') ? "description" : "NULL";

    $select = "line_id, transaction_id, {$user_expr} AS tx_user, nama_toko, items, quantity, harga, kategori, {$purchase_expr} AS purchase_date, {$entry_expr} AS entry_date, {$desc_expr} AS description";

    $where = "{$date_expr} >= %s AND {$date_expr} < %s";
    $params = [$start_dt, $end_dt];

    // Optional user filter (kept flexible for both internal and external datasources)
    $user_col = $this->tx_user_col();
    if ($user_login !== null && $user_login !== '' && $user_login !== 'all' && $user_login !== '0' && $user_col) {
        $u_login = strtolower(trim((string)$user_login));

        // Handle case where the dropdown submits a numeric WP user ID.
        // When our transactions table stores the username (not an ID),
        // translate the ID into a login so the filter works.
        if (ctype_digit($u_login) && !($user_col === 'wp_user_id' || (strlen($user_col) >= 3 && substr($user_col, -3) === '_id'))) {
            $u_by_id = get_user_by('id', (int)$u_login);
            if ($u_by_id && isset($u_by_id->user_login)) {
                $u_login = strtolower((string)$u_by_id->user_login);
            }
        }
        if ($user_col === 'wp_user_id' || (strlen($user_col) >= 3 && substr($user_col, -3) === '_id')) {
            $u = get_user_by('login', $u_login);
            $uid = $u ? (int)$u->ID : 0;
            if ($uid > 0) {
                $where = "`{$user_col}` = %d AND {$where}";
                $params = array_merge([$uid], $params);
            } else {
                $where = "1=0 AND {$where}";
            }
        } else {
            $where = "LOWER(`{$user_col}`) = %s AND {$where}";
            $params = array_merge([$u_login], $params);
        }
    }

    
    // Optional transaction type filter for reports (All / Income / Expense).
    $tx_type = $this->reports_sanitize_tx_type((string)$tx_type);
    if ($tx_type === 'income') {
        $where .= " AND LOWER(kategori) = 'income'";
    } elseif ($tx_type === 'expense') {
        $expense_cats = $this->get_expense_categories();
        if (empty($expense_cats)) $expense_cats = ['expense'];
        $cats_in = implode(',', array_fill(0, count($expense_cats), '%s'));
        $where .= " AND LOWER(kategori) IN ($cats_in)";
        $params = array_merge($params, $expense_cats);
    }

$limit = max(1, (int)$limit);

    $sql = "SELECT {$select}
            FROM `{$table}`
            WHERE {$where}
            ORDER BY {$date_expr} DESC
            LIMIT {$limit}";

    $rows = $db->get_results($db->prepare($sql, ...$params), ARRAY_A);
    return is_array($rows) ? $rows : [];
}

    
private function build_report_pdf_pages(string $title, string $generated, string $range_display, array $tot, array $rows, bool $truncated, string $tx_type = 'all') : array {
        // Vector-PDF pages with basic text + grid table. Supports multi-page with repeating headers.
        $page_w = 595; $page_h = 842; // A4 points
        $left = 48; $right = 48;
        $top = 780; $bottom = 60;

        // Table layout
	    	$tx_type = $this->reports_sanitize_tx_type((string)$tx_type);
        if ($tx_type === 'income') {
            $headers = ['Receive Date','Source','Category','Item','Qty','Price'];
        } elseif ($tx_type === 'expense') {
            $headers = ['Purchase Date','Payee','Category','Item','Qty','Price'];
        } else {
            $headers = ['Date','Payee/Source','Category','Item','Qty','Price'];
        }
        // Make the Price column a bit wider so numbers don't stick to the right border.
        // Keep overall table width the same by taking a bit from the Item column.
        $col_w = [80, 100, 85, 140, 35, 55]; // total 495 (max content width is 595-48-48=499)
        // Ensure table fits available width
        $max_table_w = $page_w - $left - $right;
        $table_w = array_sum($col_w);
        if ($table_w > $max_table_w) {
            // scale down proportionally (keep readability)
            $scale = $max_table_w / $table_w;
            foreach ($col_w as &$cw) { $cw = floor($cw * $scale); }
            unset($cw);
        }

        // Wrap limits per column (characters per line). Heuristic for Helvetica at 9pt.
        $wrap_max = [16, 20, 16, 30, 5, 14];

        $font_size = 9;
        $line_h = 11;          // line height in points inside table rows
        $header_h = 24;        // header row height
        $row_base_h = 22;      // minimum row height (1 line)

        // Build printable rows: normalize keys + format values
        $print_rows = [];
        foreach ((array)$rows as $r) {
            $purchase_raw = (string)($r['purchase_date'] ?? $r['tanggal_struk'] ?? $r['tanggal_receipt'] ?? $r['purchaseDate'] ?? '');
            $entry_raw    = (string)($r['entry_date'] ?? $r['tanggal_input'] ?? $r['entryDate'] ?? '');
            $purchase_disp = $this->fmt_date_short($purchase_raw);
            if ($purchase_disp === '' && $entry_raw !== '') $purchase_disp = $this->fmt_date_short($entry_raw);

            $merchant = (string)($r['nama_toko'] ?? $r['merchant'] ?? '');
            $cat      = (string)($r['kategori'] ?? $r['category'] ?? '');
            $item     = (string)($r['items'] ?? $r['item'] ?? '');
            $qty      = (string)($r['quantity'] ?? $r['qty'] ?? '');
            $price    = (float)($r['harga'] ?? $r['price'] ?? 0);

            $print_rows[] = [
                $purchase_disp ?: '',
                $merchant ?: '',
                $cat ?: '',
                $item ?: '',
                $qty ?: '',
                'Rp ' . number_format_i18n($price),
            ];
        }

        // If no rows, still show an empty table skeleton with a few blank lines.
        if (empty($print_rows)) {
            for ($i=0;$i<5;$i++) $print_rows[] = ['', '', '', '', '', ''];
        }

        $income  = 'Rp ' . number_format_i18n((float)($tot['income'] ?? 0));
        $expense = 'Rp ' . number_format_i18n((float)($tot['expense'] ?? 0));
        // Balance is intentionally omitted from the report PDF (Income/Expense only)

        $pages = [];
        $idx = 0;
        $n = count($print_rows);
        $page_no = 1;

        while ($idx < $n) {
            $s = "";

            // Header block
            $y = $top;
            $s .= $this->pdf_text_cmd($left, $y, 18, $title, 'F2');
            $s .= $this->pdf_text_cmd($page_w - $right - 170, $y + 2, 10, "Generated: {$generated}", 'F3');
            $y -= 20;
            if ($range_display !== '') {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Date: {$range_display}", 'F1');
                $y -= 14;
            }

            $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
            $y -= 18;

            // Summary only on first page; page 2+ shows only the detail table.
            if ($page_no === 1) {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Summary Report:", 'F2');
                $y -= 14;
                if ($tx_type === 'income') {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                $y -= 12;
            } elseif ($tx_type === 'expense') {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                $y -= 12;
            } else {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                $y -= 12;
                $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                $y -= 12;
            }
                // Balance line removed

                $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
                $y -= 18;
            }

            $label = ($page_no === 1) ? "Detail transactions:" : "Detail transactions (continued):";
            $s .= $this->pdf_text_cmd($left, $y, 12, $label, 'F2');
            $y -= 12;

            if ($truncated && $idx === 0) {
                $s .= $this->pdf_text_cmd($left, $y, 10, "Note: showing first 2000 rows (filtered).", 'F3');
                $y -= 12;
            }

            // Table region
            $table_x = $left;
            $table_top = $y;
            $table_bottom = $bottom;
            $avail_h = $table_top - $table_bottom;

            // Decide how many rows fit on this page (variable row height due to wrapping)
            $row_heights = [$header_h];
            $page_row_wrapped = [];

            $used_h = $header_h;
            while ($idx < $n) {
                $row = $print_rows[$idx];

                $wrapped_cells = [];
                $max_lines = 1;
                foreach ($row as $cidx => $cell) {
                    $cell = (string)$cell;
                    $cell = $this->pdf_clean_text($cell);
                    $lines = $this->pdf_wrap_text($cell, (int)($wrap_max[$cidx] ?? 20));
                    $wrapped_cells[] = $lines;
                    $max_lines = max($max_lines, count($lines));
                }

                $rh = max($row_base_h, $row_base_h + (($max_lines - 1) * $line_h));
                // If this row doesn't fit, stop and render current page
                if (($used_h + $rh) > $avail_h) break;

                $row_heights[] = $rh;
                $page_row_wrapped[] = $wrapped_cells;
                $used_h += $rh;
                $idx++;
            }

            // Ensure at least one row to avoid infinite loop
            if (empty($page_row_wrapped)) {
                $wrapped_cells = [];
                foreach ($print_rows[$idx] as $cidx => $cell) {
                    $cell = $this->pdf_clean_text((string)$cell);
                    $wrapped_cells[] = $this->pdf_wrap_text($cell, (int)($wrap_max[$cidx] ?? 20));
                }
                $row_heights[] = $row_base_h;
                $page_row_wrapped[] = $wrapped_cells;
                $idx++;
            }

            // Draw grid
            $s .= $this->pdf_table_grid_var_cmd($table_x, $table_top, $col_w, $row_heights, 0.8);

            // Header text in table
            $x = $table_x;
            $header_y = $table_top - 16;
            foreach ($headers as $cidx => $h) {
                $s .= $this->pdf_text_cmd($x + 4, $header_y, $font_size, $h, 'F2');
                $x += $col_w[$cidx];
            }

            // Data rows
            $y_cursor_top = $table_top - $header_h; // top line of first data row
            foreach ($page_row_wrapped as $ridx => $wrapped_cells) {
                $rh = $row_heights[$ridx + 1];
                $row_top = $y_cursor_top; // top edge of row
                $first_baseline = $row_top - 14;

                $x = $table_x;
                foreach ($wrapped_cells as $cidx => $lines) {
                    $li = 0;
                    foreach ($lines as $line) {
                        $s .= $this->pdf_text_cmd($x + 4, $first_baseline - ($li * $line_h), $font_size, $line, 'F1');
                        $li++;
                        // soft cap to avoid spilling far beyond row height (shouldn't happen)
                        if (($li * $line_h) > ($rh - 12)) break;
                    }
                    $x += $col_w[$cidx];
                }

                $y_cursor_top -= $rh;
            }

            // Footer page number (centered)
            $footer_label = "Page {$page_no}";
            $approx_char_w = 4.6; // approx width for Helvetica 9pt
            $fx = (int)round(($page_w / 2) - (($this->pdf_strlen($footer_label) * $approx_char_w) / 2));
            $fy = 32;
            $s .= $this->pdf_text_cmd($fx, $fy, 9, $footer_label, 'F3');

            $pages[] = $s;
            $page_no++;
        }

        return $pages;
    }

    private function fmt_date_short(string $date) : string {
        $date = trim($date);
        if ($date === '') return '';
        // Accept YYYY-MM-DD or full datetime
        $ts = strtotime($date);
        if (!$ts) return '';
        return wp_date('d/m/Y', $ts);
    }

    private function pdf_clean_text(string $text) : string {
        // Normalize whitespace and avoid control chars that can break the PDF stream.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace("/\s+/u", " ", $text);
        return trim((string)$text);
    }

    private function pdf_strlen(string $text) : int {
        if (function_exists('mb_strlen')) return (int)mb_strlen($text, 'UTF-8');
        if ($text === '') return 0;
        return (int)preg_match_all('/./us', $text, $m);
    }

    private function pdf_substr(string $text, int $start, int $len) : string {
        if (function_exists('mb_substr')) return (string)mb_substr($text, $start, $len, 'UTF-8');
        if ($text === '' || $len <= 0) return '';
        preg_match_all('/./us', $text, $m);
        $chars = $m[0] ?? [];
        return implode('', array_slice($chars, $start, $len));
    }

    private function pdf_wrap_text(string $text, int $max_chars) : array {
        $text = $this->pdf_clean_text($text);
        if ($text === '') return [''];

        $max_chars = max(4, $max_chars);
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $w) {
            $w = (string)$w;
            if ($w === '') continue;

            // If a single "word" is longer than max, split it safely
            if ($this->pdf_strlen($w) > $max_chars) {
                if ($line !== '') { $lines[] = $line; $line = ''; }
                $pos = 0;
                $wlen = $this->pdf_strlen($w);
                while ($pos < $wlen) {
                    $chunk = $this->pdf_substr($w, $pos, $max_chars);
                    $lines[] = $chunk;
                    $pos += $max_chars;
                }
                continue;
            }

            $candidate = ($line === '') ? $w : ($line . ' ' . $w);
            if ($this->pdf_strlen($candidate) <= $max_chars) {
                $line = $candidate;
            } else {
                if ($line !== '') $lines[] = $line;
                $line = $w;
            }
        }

        if ($line !== '') $lines[] = $line;
        if (empty($lines)) $lines = [''];
        return $lines;
    }

    private function pdf_table_grid_var_cmd(float $x, float $y_top, array $col_w, array $row_h, float $w = 0.8) : string {
        $s = "";
        $total_w = array_sum($col_w);
        $total_h = array_sum($row_h);
        $y_bottom = $y_top - $total_h;

        // Horizontal lines (top + each row boundary)
        $yy = $y_top;
        $s .= $this->pdf_line_cmd($x, $yy, $x + $total_w, $yy, $w);
        foreach ($row_h as $h) {
            $yy -= (float)$h;
            $s .= $this->pdf_line_cmd($x, $yy, $x + $total_w, $yy, $w);
        }

        // Vertical lines (full height)
        $xx = $x;
        $s .= $this->pdf_line_cmd($xx, $y_top, $xx, $y_bottom, $w);
        foreach ($col_w as $cw) {
            $xx += (float)$cw;
            $s .= $this->pdf_line_cmd($xx, $y_top, $xx, $y_bottom, $w);
        }

        return $s;
    }

    private function pdf_truncate_text(string $text, int $max_chars) : string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($max_chars <= 0) return '';
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max_chars) return $text;
            return mb_substr($text, 0, max(0, $max_chars - 1), 'UTF-8').'…';
        }
        if (strlen($text) <= $max_chars) return $text;
        return substr($text, 0, max(0, $max_chars - 1)).'…';
    }

    private function pdf_escape_text(string $t) : string {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $t);
    }

    private function pdf_text_cmd(float $x, float $y, int $size, string $text, string $font = 'F1') : string {
        $text = $this->pdf_escape_text($text);
        $x = number_format($x, 2, '.', '');
        $y = number_format($y, 2, '.', '');
        $size = max(1, (int)$size);
        return "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$text}) Tj ET\n";
    }

    private function pdf_line_cmd(float $x1, float $y1, float $x2, float $y2, float $w = 0.8) : string {
        $x1 = number_format($x1, 2, '.', '');
        $y1 = number_format($y1, 2, '.', '');
        $x2 = number_format($x2, 2, '.', '');
        $y2 = number_format($y2, 2, '.', '');
        $w = number_format(max(0.1, (float)$w), 2, '.', '');
        return "{$w} w {$x1} {$y1} m {$x2} {$y2} l S\n";
    }

    private function pdf_table_grid_cmd(float $x, float $y_top, array $col_w, float $row_h, int $rows_count, float $w = 0.8) : string {
        $x0 = (float)$x;
        $x1 = $x0 + array_sum($col_w);
        $w = number_format(max(0.1, (float)$w), 2, '.', '');
        $s = "{$w} w\n";

        // Horizontal lines
        for ($i = 0; $i <= $rows_count; $i++) {
            $yy = $y_top - ($i * $row_h);
            $s .= number_format($x0, 2, '.', '').' '.number_format($yy, 2, '.', '').' m '
                .number_format($x1, 2, '.', '').' '.number_format($yy, 2, '.', '')." l S\n";
        }

        // Vertical lines
        $xx = $x0;
        $s .= number_format($xx, 2, '.', '').' '.number_format($y_top, 2, '.', '').' m '
            .number_format($xx, 2, '.', '').' '.number_format($y_top - ($rows_count * $row_h), 2, '.', '')." l S\n";

        foreach ($col_w as $cw) {
            $xx += (float)$cw;
            $s .= number_format($xx, 2, '.', '').' '.number_format($y_top, 2, '.', '').' m '
                .number_format($xx, 2, '.', '').' '.number_format($y_top - ($rows_count * $row_h), 2, '.', '')." l S\n";
        }

        return $s;
    }

    private function simple_pdf_pages(array $page_streams, array $fonts) : string {
        $n = count($page_streams);
        if ($n < 1) $page_streams = [""];

        // Object id layout:
        // 1: catalog
        // 2: pages
        // 3..(2n+2): page+content pairs
        // fonts start at (2n+3)
        $n = count($page_streams);
        $font_keys = array_keys($fonts);
        $font_ids = [];
        $first_font_id = 3 + (2 * $n);

        foreach ($font_keys as $i => $k) {
            $font_ids[$k] = $first_font_id + $i;
        }

        $objects = [];

        // 1 Catalog
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        // 2 Pages (Kids filled later)
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $page_id = 3 + ($i * 2);
            $kids[] = "{$page_id} 0 R";
        }
        $objects[2] = "<< /Type /Pages /Kids [ ".implode(' ', $kids)." ] /Count {$n} >>";

        // Page objects and content objects
        for ($i = 0; $i < $n; $i++) {
            $page_id = 3 + ($i * 2);
            $content_id = $page_id + 1;
            $stream = (string)$page_streams[$i];
            $len = strlen($stream);

            $font_dict_parts = [];
            foreach ($font_ids as $k => $fid) {
                $font_dict_parts[] = "/{$k} {$fid} 0 R";
            }
            $font_dict = implode(' ', $font_dict_parts);

            $objects[$page_id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << {$font_dict} >> >> /Contents {$content_id} 0 R >>";
            $objects[$content_id] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
        }

        // Font objects
        foreach ($fonts as $k => $base) {
            $fid = $font_ids[$k];
            $objects[$fid] = "<< /Type /Font /Subtype /Type1 /Name /{$k} /BaseFont /{$base} >>";
        }

        // Build final PDF with xref
        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        $max_id = max(array_keys($objects));
        for ($i = 1; $i <= $max_id; $i++) {
            $offsets[$i] = strlen($pdf);
            $obj = $objects[$i] ?? '';
            $pdf .= "{$i} 0 obj\n{$obj}\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 ".($max_id + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max_id; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size ".($max_id + 1)." /Root 1 0 R >>\nstartxref\n{$xref_offset}\n%%EOF";
        return $pdf;
    }

private function simple_text_pdf(string $text) : string {
        // Minimal PDF (single page if possible) with Helvetica + Helvetica-Bold.
        // Supports a larger bold title on the first line.
        $lines = preg_split("/\r\n|\n|\r/", $text);
        $maxw = 92;
        $wrapped = [];
        foreach ($lines as $i => $l) {
            $l = (string)$l;
            // keep empty lines
            if ($l === '') { $wrapped[] = ''; continue; }
            while (mb_strlen($l) > $maxw) {
                $wrapped[] = mb_substr($l, 0, $maxw);
                $l = mb_substr($l, $maxw);
            }
            $wrapped[] = $l;
        }

        $y = 810;
        $content = "BT\n";
        foreach ($wrapped as $idx => $l) {
            $l = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $l);
            // Title styling for first non-empty line
            if ($idx === 0) {
                $content .= "/F2 16 Tf\n";
            } elseif ($idx === 1 || $idx === 2) {
                $content .= "/F1 10 Tf\n";
            } else {
                $content .= "/F1 10 Tf\n";
            }
            $content .= sprintf("1 0 0 1 50 %d Tm (%s) Tj\n", $y, $l);
            $y -= 14;
            if ($y < 40) break; // keep single page for now
        }
        $content .= "ET\n";
        $len = strlen($content);

        $objects = [];
        $objects[] = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $objects[] = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $objects[] = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 6 0 R >> >> /Contents 5 0 R >> endobj\n";
        $objects[] = "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";
        $objects[] = "5 0 obj << /Length {$len} >> stream\n{$content}endstream endobj\n";
        $objects[] = "6 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> endobj\n";

        $pdf = "%PDF-1.4\n";
        $xref = [];
        $offset = strlen($pdf);
        foreach ($objects as $obj) {
            $xref[] = $offset;
            $pdf .= $obj;
            $offset = strlen($pdf);
        }
        $xref_pos = $offset;
        $pdf .= "xref\n0 ".(count($xref)+1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach ($xref as $o) {
            $pdf .= sprintf("%010d 00000 n \n", $o);
        }
        $pdf .= "trailer << /Size ".(count($xref)+1)." /Root 1 0 R >>\nstartxref\n{$xref_pos}\n%%EOF";
        return $pdf;
    }
            

    

    private function parse_csv_datetime($val) : string {
    $val = trim((string)$val);
    if ($val === '') return '';

    // datetime-local (YYYY-MM-DDTHH:MM)
    if (strpos($val, 'T') !== false) {
        return $this->mysql_from_ui_datetime($val);
    }

    $display = $this->simku_display_tz();
    $storage = $this->simku_storage_tz();

    // Handle common UI format: dd/mm/YYYY HH.mm or dd/mm/YYYY HH:ii
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2})[\.:](\d{2})(?::(\d{2}))?)?$#', $val, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        $hh = isset($m[4]) ? (int)$m[4] : 0;
        $ii = isset($m[5]) ? (int)$m[5] : 0;
        $ss = isset($m[6]) ? (int)$m[6] : 0;

        $raw = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $hh, $ii, $ss);
        try {
            $dt = new \DateTime($raw, $display);
            $dt->setTimezone($storage);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $raw;
        }
    }

    // Best-effort parse in display timezone then convert to storage timezone
    try {
        $dt = new \DateTime($val, $display);
        $dt->setTimezone($storage);
        return $dt->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
        // fallback
    }

    $ts = strtotime($val);
    if ($ts) return wp_date('Y-m-d H:i:s', $ts, $storage);

    return sanitize_text_field($val);
}



private function parse_csv_date($val) : string {
    $val = trim((string)$val);
    if ($val === '') return '';
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $val, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($val);
    if ($ts) return wp_date('Y-m-d', $ts);
    return sanitize_text_field($val);
}

private function handle_bulk_csv_import($db, string $table) : array {
    $current_user = wp_get_current_user();
    $user_login = $current_user && $current_user->user_login ? $current_user->user_login : 'system';

    if (empty($_FILES['fl_bulk_csv_file']) || empty($_FILES['fl_bulk_csv_file']['tmp_name'])) {
        return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => ['No file uploaded.']];
    }

    // Ensure user columns exist for external mode
    if ($this->ds_is_external()) {
        [$ok, $msgs] = $this->ensure_external_user_columns();
        if (!$ok) {
            return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => array_merge(['External datasource missing required columns.'], $msgs)];
        }
    }

    $tmp = $_FILES['fl_bulk_csv_file']['tmp_name'];
    $fh = fopen($tmp, 'r');
    if (!$fh) return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => ['Unable to read uploaded CSV file.']];

    // Detect delimiter (comma vs semicolon)
    $firstLine = fgets($fh);
    if ($firstLine === false) { fclose($fh); return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => ['CSV is empty.']]; }
    $comma = substr_count($firstLine, ',');
    $semi  = substr_count($firstLine, ';');
    $delim = ($semi > $comma) ? ';' : ',';
    rewind($fh);

    $header = fgetcsv($fh, 0, $delim);
    if (!$header || count($header) < 2) { fclose($fh); return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => ['CSV header is missing or invalid.']]; }

    $normalize = function($h) {
        $h = strtolower(trim((string)$h));
        $h = str_replace([' ', '-', '.'], '_', $h);
        return $h;
    };

    $map = [];
    foreach ($header as $i => $h) {
        $k = $normalize($h);
        // aliases
        if ($k === 'toko' || $k === 'nama_toko') $k = 'nama_toko';
        if ($k === 'item' || $k === 'items' || $k === 'produk') $k = 'items';
        if ($k === 'qty' || $k === 'quantity' || $k === 'jumlah') $k = 'quantity';
        if ($k === 'harga' || $k === 'price') $k = 'harga';
        if ($k === 'kategori' || $k === 'category' || $k === 'type') $k = 'kategori';
        if ($k === 'tanggal_input' || $k === 'date_input' || $k === 'created_at') $k = 'tanggal_input';
        if ($k === 'tanggal_struk' || $k === 'date_receipt' || $k === 'receipt_date') $k = 'tanggal_struk';
        if ($k === 'gambar_url' || $k === 'image_url' || $k === 'image') $k = 'gambar_url';
        if ($k === 'description' || $k === 'desc' || $k === 'note') $k = 'description';
        if ($k === 'user' || $k === 'user_login') $k = 'user_login';
        if ($k === 'line_id') $k = 'line_id';
        if ($k === 'transaction_id' || $k === 'tx_id') $k = 'transaction_id';

        $map[$i] = $k;
    }

    // Minimum fields required for meaningful import
    $required_any = ['nama_toko', 'items', 'kategori'];
    $has_required = false;
    foreach ($required_any as $r) { if (in_array($r, $map, true)) { $has_required = true; break; } }
    if (!$has_required) {
        fclose($fh);
        return ['ok' => false, 'inserted' => 0, 'skipped' => 0, 'errors' => ['CSV header must include at least one of: nama_toko, items, kategori.']];
    }

    $send_tg = !empty($_POST['fl_bulk_csv_notify_telegram']) ? 1 : 0;
    $send_email = !empty($_POST['fl_bulk_csv_notify_email']) ? 1 : 0;

    $inserted = 0; $skipped = 0; $errors = [];
    $rownum = 1;

    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $rownum++;
        if (!$row || (count($row) === 1 && trim((string)$row[0]) === '')) { $skipped++; continue; }

        $data = [
            'line_id' => '',
            'transaction_id' => '',
            'nama_toko' => '',
            'items' => '',
            'quantity' => 1,
            'harga' => 0,
            'kategori' => '',
            'tanggal_input' => current_time('mysql'),
            'tanggal_struk' => '',
            'gambar_url' => '',
            'description' => '',
            'wp_user_id' => (int)($current_user ? $current_user->ID : 0),
            'wp_user_login' => sanitize_text_field($user_login),
        ];

        foreach ($row as $i => $v) {
            $k = $map[$i] ?? '';
            $v = is_string($v) ? trim($v) : $v;

            switch ($k) {
                case 'line_id': $data['line_id'] = sanitize_text_field($v); break;
                case 'transaction_id': $data['transaction_id'] = sanitize_text_field($v); break;
                case 'nama_toko': $data['nama_toko'] = sanitize_text_field($v); break;
                case 'items': $data['items'] = sanitize_text_field($v); break;
                case 'quantity': $data['quantity'] = (int)$v; if ($data['quantity'] <= 0) $data['quantity'] = 1; break;
                case 'harga': $data['harga'] = (int)preg_replace('/[^0-9]/', '', (string)$v); break;
                case 'kategori': $data['kategori'] = sanitize_text_field($v); break;
                case 'tanggal_input': $data['tanggal_input'] = $this->parse_csv_datetime($v); break;
                case 'tanggal_struk': $data['tanggal_struk'] = $this->parse_csv_date($v); break;
                case 'gambar_url': $data['gambar_url'] = esc_url_raw($v); break;
                case 'description': $data['description'] = wp_kses_post($v); break;
                case 'user_login':
                    $data['wp_user_login'] = sanitize_text_field($v);
                    break;
            }
        }

        if (!$data['line_id']) {
            $base = (string) round(microtime(true) * 1000);
            $rand = substr(wp_generate_password(12, false, false), 0, 8);
            $data['line_id'] = $base . '_' . $rand . '-001';
        }
        if (!$data['transaction_id']) {
            $data['transaction_id'] = preg_replace('/-\d+$/', '', $data['line_id']);
        }
        if (!$data['tanggal_input']) $data['tanggal_input'] = current_time('mysql');

        $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%d','%s'];

        $res = $db->insert($table, $data, $formats);
        if ($res === false) {
            $skipped++;
            $errors[] = "Row {$rownum}: insert failed.";
            continue;
        }

        $inserted++;
        $this->log_event('bulk_create', 'transaction', $data['line_id'], ['row' => $rownum, 'data' => $data]);

        if ($send_tg || $send_email) {
            // Reuse per-transaction notifications (same as single add)
            $total = (float)$data['harga'] * (float)$data['quantity'];
            $ctx = [
                'user' => esc_html($data['wp_user_login']),
                'kategori' => esc_html($data['kategori'] ?? ''),
                'toko' => esc_html($data['nama_toko'] ?? ''),
                'item' => esc_html($data['items'] ?? ''),
                'qty' => esc_html((string)($data['quantity'] ?? '')),
                'harga' => esc_html(number_format_i18n((float)($data['harga'] ?? 0))),
                'total' => esc_html(number_format_i18n($total)),
                'tanggal_input' => esc_html($data['tanggal_input'] ?? ''),
                'tanggal_struk' => esc_html($data['tanggal_struk'] ?? ''),
                'transaction_id' => esc_html($data['transaction_id'] ?? ''),
                'line_id' => esc_html($data['line_id'] ?? ''),
                'gambar_url' => esc_html($data['gambar_url'] ?? ''),
                'description' => esc_html(wp_strip_all_tags((string)($data['description'] ?? ''))),
            ];
            if ($send_tg) $this->send_telegram_new_tx($ctx);
            if ($send_email) $this->send_email_new_tx($ctx);
        }
    }

    fclose($fh);

    return ['ok' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
}

public function page_add_transaction() : void {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die('Forbidden');

        $db = $this->ds_db();
        if (!$db) { echo '<div class="wrap fl-wrap"><h1>Add Transaction</h1><div class="notice notice-error"><p>Datasource not configured.</p></div></div>'; return; }

        $s = $this->settings();
        $notify_tg_default = !empty($s['notify']['telegram_notify_new_tx_default']);
        $notify_email_default = !empty($s['notify']['email_notify_new_tx_default']);

        $table = $this->ds_table();
        $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $is_edit = !empty($edit_id);


// Bulk CSV import
$bulk_result = null;
if (!empty($_POST['fl_bulk_csv_submit'])) {
    check_admin_referer('fl_bulk_csv', 'fl_bulk_csv_nonce');
    $bulk_result = $this->handle_bulk_csv_import($db, $table);
}

        $row = null;
        if ($is_edit) {
            $row = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s", $edit_id), ARRAY_A);
        }

        $current_user = wp_get_current_user();
        $user_login = $current_user ? $current_user->user_login : '';

        // Save
        if (!empty($_POST['fl_save_tx'])) {
            check_admin_referer('fl_save_tx');
            if ($this->ds_is_external() && !$this->ds_allow_write_external()) {
                echo '<div class="notice notice-error"><p>External datasource is read-only.</p></div>';
            } else {
                // Ensure user columns exist (option B)
                [$ok, $msgs] = $this->ensure_external_user_columns();
                foreach ($msgs as $m) {
                    echo '<div class="notice '.($ok?'notice-success':'notice-error').'"><p>'.esc_html($m).'</p></div>';
                }
                if ($ok) {
                    // Shared fields (apply to all line items)
                    $line_id_input = sanitize_text_field(wp_unslash($_POST['line_id'] ?? ''));
                    $transaction_id_input = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
                    $nama_toko = sanitize_text_field(wp_unslash($_POST['nama_toko'] ?? ''));
                    $kategori = $this->normalize_category(sanitize_text_field(wp_unslash($_POST['kategori'] ?? 'expense')));
                    $tanggal_input_raw = sanitize_text_field(wp_unslash($_POST['tanggal_input'] ?? current_time('mysql')));
                    $tanggal_input = $this->parse_csv_datetime($tanggal_input_raw);
                    if (!$tanggal_input) $tanggal_input = current_time('mysql');

                    $tanggal_struk_raw = sanitize_text_field(wp_unslash($_POST['tanggal_struk'] ?? ''));
                    $tanggal_struk = $this->parse_csv_date($tanggal_struk_raw);
                    $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));

                    // Images: support multiple uploads + multiple URLs (one per line).
                    $existing_imgs = $is_edit ? $this->normalize_images_field($row['gambar_url'] ?? '') : [];
                    $remove_imgs = $is_edit ? $this->normalize_images_field($_POST['remove_images'] ?? []) : [];
                    if (!empty($remove_imgs)) {
                        $existing_imgs = array_values(array_diff($existing_imgs, $remove_imgs));
                    }

                    $imgs = $existing_imgs;
                    $imgs = array_merge($imgs, $this->parse_image_urls_textarea(wp_unslash($_POST['gambar_url'] ?? '')));

                    // Multi image upload (returns ['ok'=>bool,'urls'=>[],'error'=>string])
                    $uploaded_imgs = [];
                    $multi = $this->handle_multi_image_upload('gambar_files');
                    if (!empty($multi['ok']) && !empty($multi['urls']) && is_array($multi['urls'])) {
                        $uploaded_imgs = array_map('strval', $multi['urls']);
                    } elseif (!empty($multi['error'])) {
                        echo '<div class="notice notice-error"><p>'.esc_html((string)$multi['error']).'</p></div>';
                    }
                    // Backward compat: older UI used single input name=gambar_file
                    $up = $this->handle_tx_image_upload('gambar_file');
                    if (!empty($up['ok']) && !empty($up['url'])) {
                        $uploaded_imgs[] = (string) $up['url'];
                    } elseif (!empty($up['error'])) {
                        echo '<div class="notice notice-error"><p>'.esc_html((string)$up['error']).'</p></div>';
                    }

                    $imgs = array_merge($imgs, $uploaded_imgs);
                    $gambar_url = $this->images_to_db_value($imgs);

                    // Normalize datetime-local (2026-01-03T20:50) to MySQL DATETIME (2026-01-03 20:50:00)
                    if (!empty($tanggal_input) && strpos($tanggal_input, 'T') !== false) {
                        $ts = strtotime((string)$tanggal_input);
                        if ($ts) $tanggal_input = wp_date('Y-m-d H:i:s', $ts);
                    }
                    if (!$tanggal_input) $tanggal_input = current_time('mysql');

                    $send_telegram_new = !empty($_POST['notify_telegram_new']) ? 1 : 0;
                    $send_email_new = !empty($_POST['notify_email_new']) ? 1 : 0;

                    $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%d','%s'];

                    // Multi-line items mode (only for Add, not Edit): items[] / quantity[] / harga[]
                    $items_post = $_POST['items'] ?? '';
                    $is_multi = (!$is_edit && is_array($items_post));

                    if ($is_multi) {
                        $qty_post = $_POST['quantity'] ?? [];
                        $harga_post = $_POST['harga'] ?? [];

                        $items_arr = is_array($items_post) ? $items_post : [];
                        $qty_arr = is_array($qty_post) ? $qty_post : [];
                        $harga_arr = is_array($harga_post) ? $harga_post : [];

                        $line_items = [];
                        $max = max(count($items_arr), count($qty_arr), count($harga_arr));
                        for ($i = 0; $i < $max; $i++) {
                            $it = isset($items_arr[$i]) ? sanitize_text_field(wp_unslash($items_arr[$i])) : '';
                            $qt = isset($qty_arr[$i]) ? (int) wp_unslash($qty_arr[$i]) : 0;
                            if ($qt <= 0) $qt = 1;
                            $hg_raw = isset($harga_arr[$i]) ? (string) wp_unslash($harga_arr[$i]) : '0';
                            $hg = (int) preg_replace('/[^0-9]/', '', $hg_raw);

                            if ($it === '' && $qt === 1 && $hg === 0) continue; // skip empty template row
                            if ($it === '') continue; // item name is required

                            $line_items[] = ['items' => $it, 'quantity' => $qt, 'harga' => $hg];
                        }

                        if (empty($line_items)) {
                            echo '<div class="notice notice-error"><p>At least 1 item is required.</p></div>';
                        } else {
                            // Determine base id for line_id generation
                            $base = '';
                            $start_n = 1;
                            if ($line_id_input) {
                                if (preg_match('/-(\d+)$/', $line_id_input, $m)) {
                                    $start_n = max(1, (int)$m[1]);
                                }
                                $base = preg_replace('/-\d+$/', '', $line_id_input);
                                if (!$base) $base = $line_id_input;
                            } elseif ($transaction_id_input) {
                                $base = $transaction_id_input;
                            } else {
                                $base = (string) round(microtime(true) * 1000) . '_' . substr(wp_generate_password(12, false, false), 0, 8);
                            }

                            $transaction_id = $transaction_id_input ?: $base;

                            $created = 0;
                            $failed = 0;
                            $first_line_id = '';
                            $fail_msgs = [];
                            $total_sum = 0.0;
                            $item_lines_txt = [];

                            foreach ($line_items as $idx => $li) {
                                $n = $start_n + $idx;
                                $seq = str_pad((string)$n, 3, '0', STR_PAD_LEFT);

                                // first row uses explicit line_id if user filled it; otherwise generate base-###
                                if ($idx === 0 && $line_id_input) {
                                    $line_id = $line_id_input;
                                } else {
                                    $line_id = $base . '-' . $seq;
                                }

                                $data = [
                                    'line_id' => $line_id,
                                    'transaction_id' => $transaction_id,
                                    'nama_toko' => $nama_toko,
                                    'items' => $li['items'],
                                    'quantity' => (int)$li['quantity'],
                                    'harga' => (int)$li['harga'],
                                    'kategori' => $kategori,
                                    'tanggal_input' => $tanggal_input,
                                    'tanggal_struk' => $tanggal_struk,
                                    'gambar_url' => $gambar_url,
                                    'description' => $description,
                                    'wp_user_id' => (int)($current_user ? $current_user->ID : 0),
                                    'wp_user_login' => sanitize_text_field($user_login),
                                ];

                                $res = $db->insert($table, $data, $formats);
                                if ($res === false) {
                                    $failed++;
                                    $fail_msgs[] = "Line {$seq}: insert failed (line_id may already exist).";
                                    continue;
                                }

                                if (!$first_line_id) $first_line_id = $line_id;
                                $created++;
                                $this->log_event('create', 'transaction', $line_id, $data);

                                $line_total = (float)$data['harga'] * (float)$data['quantity'];
                                $total_sum += $line_total;
                                $item_lines_txt[] = '• ' . $data['items'] . ' (' . $data['quantity'] . ' x ' . number_format_i18n((float)$data['harga']) . ' = ' . number_format_i18n($line_total) . ')';
                            }

                            if ($created > 0) {
                                $tx_url = esc_url(admin_url('admin.php?page=fl-transactions'));
                                $edit_url = $first_line_id ? esc_url(admin_url('admin.php?page=fl-add-transaction&edit=' . rawurlencode($first_line_id))) : $tx_url;

                                echo '<div class="notice notice-success"><p>Created <b>'.esc_html((string)$created).'</b> item(s) for transaction <code>'.esc_html($transaction_id).'</code>. <a href="'.$tx_url.'">View Transactions</a> | <a href="'.$edit_url.'">Edit first item</a>. The form has been reset so you can add another transaction.</p></div>';

                                if ($failed > 0) {
                                    echo '<div class="notice notice-warning"><p>Insert failed: <b>'.esc_html((string)$failed).'</b>. '.esc_html(implode(' ', array_slice($fail_msgs, 0, 3))).'</p></div>';
                                }

                                // Send single notification (summary) for multi-item transaction
                                if (($send_telegram_new || $send_email_new) && $created > 0) {
                                    $ctx = [
                                        'user' => esc_html($user_login),
                                        'kategori' => esc_html($kategori ?? ''),
                                        'toko' => esc_html($nama_toko ?? ''),
                                        'item' => esc_html(implode("\n", $item_lines_txt)),
                                        'qty' => esc_html(''),
                                        'harga' => esc_html(''),
                                        'total' => esc_html(number_format_i18n($total_sum)),
                                        'tanggal_input' => esc_html($tanggal_input ?? ''),
                                        'tanggal_struk' => esc_html($tanggal_struk ?? ''),
                                        'transaction_id' => esc_html($transaction_id ?? ''),
                                        'line_id' => esc_html($first_line_id ?? ''),
                                        'gambar_url' => esc_html($gambar_url ?? ''),
                                        'description' => esc_html(wp_strip_all_tags((string)($description ?? ''))),
                                    ];
                                    if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                                    if ($send_email_new) $this->send_email_new_tx($ctx);
                                }

                                // Check limits quickly
                                $this->cron_check_limits();

                                // reset form
                                $row = [];
                                $edit_id = '';
                            } else {
                                echo '<div class="notice notice-error"><p>Semua baris gagal di-insert. '.esc_html(implode(' ', array_slice($fail_msgs, 0, 3))).'</p></div>';
                            }
                        }
                    } else {
                        // Single item (existing behavior)
                        $data = [
                            'line_id' => sanitize_text_field(wp_unslash($_POST['line_id'] ?? '')),
                            'transaction_id' => sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? '')),
                            'nama_toko' => $nama_toko,
                            'items' => sanitize_text_field(wp_unslash($_POST['items'] ?? '')),
                            'quantity' => (int)($_POST['quantity'] ?? 0),
                            'harga' => (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga'] ?? 0)),
                            'kategori' => $kategori,
                            'tanggal_input' => $tanggal_input,
                            'tanggal_struk' => $tanggal_struk,
                            'gambar_url' => $gambar_url,
                            'description' => $description,
                            'wp_user_id' => (int)($current_user ? $current_user->ID : 0),
                            'wp_user_login' => sanitize_text_field($user_login),
                        ];

                        if ($data['quantity'] <= 0) $data['quantity'] = 1;

                        // Auto generate line_id if empty
                        if (!$data['line_id']) {
                            $base = (string) round(microtime(true) * 1000);
                            $rand = substr(wp_generate_password(12, false, false), 0, 8);
                            $data['line_id'] = $base . '_' . $rand . '-001';
                        }
                        if (!$data['transaction_id']) {
                            // default: strip -### suffix if exists
                            $data['transaction_id'] = preg_replace('/-\d+$/', '', $data['line_id']);
                        }

                        if ($is_edit) {
                            $line_id = $edit_id;
                            $data['line_id'] = $line_id; // do not allow changing PK
                            $res = $db->update($table, $data, ['line_id' => $line_id], $formats, ['%s']);
                            if ($res !== false) {
                                $this->log_event('update', 'transaction', $line_id, $data);
                                echo '<div class="notice notice-success"><p>Updated.</p></div>';
                            } else {
                                echo '<div class="notice notice-error"><p>Update failed.</p></div>';
                            }
                        } else {
                            $res = $db->insert($table, $data, $formats);
                            if ($res !== false) {
                                $this->log_event('create', 'transaction', $data['line_id'], $data);
                                echo '<div class="notice notice-success"><p>Created. <a href="'.esc_url(admin_url('admin.php?page=fl-add-transaction&edit=' . rawurlencode($data['line_id']))).'">Edit this transaction</a>. The form has been reset so you can add another transaction.</p></div>';

                                // Telegram / Email on new transaction (manual)
                                if ($send_telegram_new || $send_email_new) {
                                    $total = (float)$data['harga'] * (float)$data['quantity'];
                                    $ctx = [
                                        'user' => esc_html($user_login),
                                        'kategori' => esc_html($data['kategori'] ?? ''),
                                        'toko' => esc_html($data['nama_toko'] ?? ''),
                                        'item' => esc_html($data['items'] ?? ''),
                                        'qty' => esc_html((string)($data['quantity'] ?? '')),
                                        'harga' => esc_html(number_format_i18n((float)($data['harga'] ?? 0))),
                                        'total' => esc_html(number_format_i18n($total)),
                                        'tanggal_input' => esc_html($data['tanggal_input'] ?? ''),
                                        'tanggal_struk' => esc_html($data['tanggal_struk'] ?? ''),
                                        'transaction_id' => esc_html($data['transaction_id'] ?? ''),
                                        'line_id' => esc_html($data['line_id'] ?? ''),
                                        'gambar_url' => esc_html($data['gambar_url'] ?? ''),
                                        'description' => esc_html(wp_strip_all_tags((string)($data['description'] ?? ''))),
                                    ];
                                    if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                                    if ($send_email_new) $this->send_email_new_tx($ctx);
                                }

                                // Check limits quickly
                                $this->cron_check_limits();
                            } else {
                                echo '<div class="notice notice-error"><p>Insert failed (line_id may already exist).</p></div>';
                            }
                        }

                        // refresh row for edit form. For a new transaction, reset the form so user can add again.
                        if ($is_edit) {
                            $row = $db->get_row($db->prepare("SELECT * FROM `{$table}` WHERE line_id = %s", $data['line_id']), ARRAY_A);
                        } else {
                            $row = [];
                            $edit_id = '';
                        }
                    }
                }
            }
        }

        // Defaults for form
        $v = [
            'line_id' => $row['line_id'] ?? '',
            'transaction_id' => $row['transaction_id'] ?? '',
            'nama_toko' => $row['nama_toko'] ?? '',
            'items' => $row['items'] ?? '',
            'quantity' => $row['quantity'] ?? 1,
            'harga' => $row['harga'] ?? 0,
            'kategori' => $this->normalize_category((string)($row['kategori'] ?? 'expense')),
            'tanggal_input' => $row['tanggal_input'] ?? current_time('mysql'),
            'tanggal_struk' => $row['tanggal_struk'] ?? '',
            'gambar_url' => $row['gambar_url'] ?? '',
            'description' => $row['description'] ?? '',
            'wp_user_login' => $row['wp_user_login'] ?? $user_login,
        ];

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html(($is_edit?'Edit Transaction':'Add Transaction'), '[simku_add_transaction]', '[simku page="add-transaction"]');
        

// Bulk import result notice
if ($bulk_result !== null) {
    if (!empty($bulk_result['ok'])) {
        echo '<div class="notice notice-success"><p>CSV import finished. Inserted: <b>'.esc_html((string)$bulk_result['inserted']).'</b>, Skipped: <b>'.esc_html((string)$bulk_result['skipped']).'</b>.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>CSV import failed: '.esc_html(($bulk_result['errors'][0] ?? 'Unknown error')).'</p></div>';
    }
    if (!empty($bulk_result['errors']) && count($bulk_result['errors']) > 1) {
        echo '<div class="notice notice-warning"><p><b>Detail:</b><br>'.esc_html(implode("\n", array_slice($bulk_result['errors'], 0, 5))).'</p></div>';
    }
}

// Bulk CSV Import UI
echo '<div class="fl-card fl-card-split simku-bulk-import fl-mt">';
echo '<div class="fl-card-head"><h2 style="margin:0;">Bulk Import (CSV)</h2><div class="fl-help">Upload a CSV to add many transactions at once.</div></div>';
echo '<div class="fl-card-body">';
echo '<form method="post" enctype="multipart/form-data" class="fl-form fl-bulk-form">';
wp_nonce_field('fl_bulk_csv', 'fl_bulk_csv_nonce');
echo '<div class="fl-grid fl-grid-2">';
echo '<div class="fl-field"><label>CSV File</label><input type="file" name="fl_bulk_csv_file" accept=".csv,text/csv" required></div>';
echo '<div class="fl-field"><label>Options</label>';
echo '<div class="fl-check-group">';
echo '<label class="fl-check"><input type="checkbox" name="fl_bulk_csv_notify_telegram" value="1"> <span>Send Telegram (per row)</span></label>';
echo '<label class="fl-check"><input type="checkbox" name="fl_bulk_csv_notify_email" value="1"> <span>Send Email (per row)</span></label>';
echo '</div></div>';
echo '</div>';
echo '<div class="fl-help fl-bulk-help">';
echo '<div><b>Supported headers</b> (subset allowed): <code>user</code>, <code>line_id</code>, <code>transaction_id</code>, <code>nama_toko</code>, <code>items</code>, <code>quantity</code>, <code>harga</code>, <code>kategori</code>, <code>tanggal_input</code>, <code>tanggal_struk</code>, <code>gambar_url</code>, <code>description</code>.</div>';
echo '<div><b>Aliases</b>: <code>toko</code>, <code>item</code>, <code>qty</code>, <code>price</code>, <code>category</code>, <code>date_input</code>, <code>date_receipt</code>.</div>';
echo '<div><b>Date format</b>: <code>YYYY-mm-dd HH:ii:ss</code> or <code>dd/mm/YYYY HH.ii</code>.</div>';
echo '</div>';
echo '<div class="fl-actions"><button class="button button-primary" type="submit" name="fl_bulk_csv_submit" value="1">Import CSV</button></div>';
echo '</form>';
echo '</div></div>';


        if ($this->ds_is_external() && (!$this->ext_column_exists('wp_user_id') || !$this->ext_column_exists('wp_user_login'))) {
            echo '<div class="notice notice-warning"><p><b>External table needs user columns.</b> Go to <a href="'.esc_url(admin_url('admin.php?page=fl-settings#fl-datasource')).'">Settings → Datasource</a> and run migration.</p></div>';
        }

        echo '<form method="post" enctype="multipart/form-data" class="fl-form simku-addtx-form">';
        wp_nonce_field('fl_save_tx');
        echo '<input type="hidden" name="fl_save_tx" value="1" />';

        echo '<div class="fl-grid fl-grid-2">';
        // Left card
        echo '<div class="fl-card"><h2>Transaction</h2>';

        // User field (readonly)
        echo '<div class="fl-field"><label>User</label><input type="text" class="fl-input" value="'.esc_attr($user_login).'" readonly /></div>';

        if ($is_edit) {
            echo '<div class="fl-field"><label>Line ID (PK)</label><input type="text" class="fl-input" name="line_id" value="'.esc_attr($v['line_id']).'" readonly /></div>';
        } else {
            echo '<div class="fl-field"><label>Line ID (PK) <span class="fl-muted">(optional, auto)</span></label><input type="text" class="fl-input" name="line_id" value="'.esc_attr($v['line_id']).'" placeholder="Auto generated if empty" /></div>';
        }
        echo '<div class="fl-field"><label>Transaction ID</label><input type="text" class="fl-input" name="transaction_id" value="'.esc_attr($v['transaction_id']).'" placeholder="Auto from Line ID if empty" /></div>';
	        echo '<div class="fl-field"><label>Counterparty</label><input type="text" class="fl-input" name="nama_toko" value="'.esc_attr($v['nama_toko']).'" placeholder="Example: FamilyMart / Dana / Salary / Bank Transfer" /></div>';
        if ($is_edit) {
        echo '<div class="fl-field"><label>Item</label><input type="text" class="fl-input" name="items" value="'.esc_attr($v['items']).'" required /></div>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Qty</label><input type="number" class="fl-input" min="0" name="quantity" value="'.esc_attr($v['quantity']).'" required /></div>';
        echo '<div class="fl-field"><label>Price</label><input type="number" class="fl-input" min="0" name="harga" value="'.esc_attr($v['harga']).'" required /></div>';
        echo '</div>';
    } else {
        echo '<div class="fl-field"><label>Items</label><div class="fl-help">Click <b>Add Item</b> to add a new row (each row is saved as a different line_id, but shares the same <code>transaction_id</code>).</div>';
        echo '<div id="simak-line-items" class="simak-line-items">';
        echo '<div class="simak-line-item-head" aria-hidden="true"><span>Item</span><span>Qty</span><span>Harga</span><span></span></div>';
        echo '<div class="simak-line-item-row">';
        echo '<input type="text" class="fl-input" name="items[]" placeholder="Item name" required />';
        echo '<input type="number" class="fl-input" min="1" name="quantity[]" value="1" data-default="1" placeholder="Qty" required />';
        echo '<input type="number" class="fl-input" min="0" name="harga[]" value="0" data-default="0" placeholder="Harga" required />';
        echo '<button type="button" class="button simak-remove-row" aria-label="Remove row" title="Remove row">×</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="fl-actions" style="margin-top:10px;"><button type="button" class="button" id="simak-add-item-row">+ Add Item</button></div>';
        echo '</div>';
    }

        echo '<div class="fl-field"><label>Category</label><select id="simku_kategori" class="fl-input" name="kategori">';
        foreach (['expense','income','saving','invest'] as $cat) {
            echo '<option value="'.esc_attr($cat).'" '.selected($v['kategori'],$cat,false).'>'.esc_html($this->category_label($cat)).'</option>';
        }
        echo '</select></div>';

        echo '</div>'; // card left

        // Right card
        echo '<div class="fl-card"><h2>Dates & Attachments</h2>';
        // datetime-local expects 2026-01-03T20:50
        $ti_local = $this->dtlocal_value_from_mysql((string)($v['tanggal_input'] ?? ''));
        echo '<div class="fl-field"><label>Entry Date</label><input type="datetime-local" class="fl-input" name="tanggal_input" value="'.esc_attr($ti_local).'" /></div>';
        $receipt_label = ($this->normalize_category((string)($v['kategori'] ?? '')) === 'income') ? 'Receive Date' : 'Purchase Date';
        echo '<div class="fl-field"><label id="simku_receipt_date_label">'.esc_html($receipt_label).'</label><input type="date" class="fl-input" name="tanggal_struk" value="'.esc_attr($v['tanggal_struk']).'" /></div>';
        // Images (multi)
        echo '<div class="fl-field"><label>Upload Images</label>';
        echo '<div class="fl-filepicker">';
        echo '<input type="file" id="fl_tx_images" name="gambar_files[]" accept="image/*" multiple style="position:absolute;left:-9999px;" />';
        echo '<button type="button" class="button" data-fl-file-trigger="fl_tx_images">Pilih File</button> ';
        echo '<span class="fl-file-label" data-fl-file-label="fl_tx_images">Tidak ada file yang dipilih</span>';
        echo '</div>';
        echo '<div class="fl-help">Tip: gambar akan dikompres otomatis agar lebih kecil (target &lt; 1.37 MB per gambar). Anda bisa memilih beberapa file sekaligus.</div>';
        echo '</div>';

        $prev_imgs = $this->normalize_images_field($v['gambar_url'] ?? '');
        if (!empty($prev_imgs)) {
            echo '<div class="fl-field"><label>Existing Images</label>';
            echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;">';
            foreach ($prev_imgs as $img_url) {
                $img = esc_url($img_url);
                echo '<div style="width:140px;">';
                echo '<a href="'.$img.'" target="_blank" rel="noopener noreferrer"><img src="'.$img.'" alt="Preview" style="width:140px;height:auto;border:1px solid #d0d7de;border-radius:8px;" /></a>';
                if ($is_edit) {
                    echo '<label style="display:block;margin-top:6px;"><input type="checkbox" name="remove_images[]" value="'.$img.'"> Remove</label>';
                }
                echo '</div>';
            }
            echo '</div>';
            if ($is_edit) {
                echo '<div class="fl-help">Centang <b>Remove</b> lalu klik <b>Save Changes</b> untuk menghapus gambar.</div>';
            }
            echo '</div>';
        }

        echo '<div class="fl-field"><label>Image URL(s)</label><textarea class="fl-input" name="gambar_url" rows="3" placeholder="https://...\nhttps://..."></textarea><div class="fl-help">One URL per line. Jika upload file, URL akan otomatis ditambahkan saat disimpan.</div></div>';
        echo '<div class="fl-field"><label>Description</label><textarea class="fl-input" name="description" rows="5">'.esc_textarea($v['description']).'</textarea></div>';

        $s = $this->settings();
        $n = $s['notify'] ?? [];

        // New transaction notifications (optional)
        if (!empty($n['telegram_enabled']) && !empty($n['telegram_bot_token']) && !empty($n['telegram_chat_id'])) {
            $checked = $notify_tg_default ? 'checked' : '';
            echo '<div class="fl-field fl-check"><label><input type="checkbox" name="notify_telegram_new" value="1" '.$checked.' /> Send Telegram notification for new transaction</label></div>';
        } else {
            echo '<div class="fl-muted">Telegram notification is not configured (Settings → Notifications).</div>';
        }

        if (!empty($n['email_enabled']) && !empty($n['email_to'])) {
            $checked_email = $notify_email_default ? 'checked' : '';
            echo '<div class="fl-field fl-check"><label><input type="checkbox" name="notify_email_new" value="1" '.$checked_email.' /> Send Email notification for new transaction</label></div>';
        } else {
            echo '<div class="fl-muted">Email notification is not configured (Settings → Notifications).</div>';
        }

        echo '<div class="fl-actions">';
        echo '<button class="button button-primary">'.($is_edit?'Save Changes':'Add Transaction').'</button> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">Back</a>';
        echo '</div>';

        echo '</div>'; // card right

        echo '</div>'; // grid
        echo '</form>';
        if (!$is_edit) {
            echo '<script>
(function(){
  const wrap = document.getElementById("simak-line-items");
  const addBtn = document.getElementById("simak-add-item-row");
  if (!wrap || !addBtn) return;

  const template = wrap.querySelector(".simak-line-item-row");
  function renumber(){
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    rows.forEach((row) => {
      const rm = row.querySelector(".simak-remove-row");
      if (rm) rm.style.visibility = (rows.length > 1) ? "visible" : "hidden";
    });
  }

  addBtn.addEventListener("click", function(){
    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((inp) => {
      const def = inp.getAttribute("data-default");
      inp.value = (def !== null) ? def : "";
    });
    wrap.appendChild(clone);
    renumber();
  });

  wrap.addEventListener("click", function(e){
    const btn = e.target.closest(".simak-remove-row");
    if (!btn) return;
    const row = btn.closest(".simak-line-item-row");
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    if (row && rows.length > 1) {
      row.remove();
      renumber();
    }
  });

  renumber();
})();
</script>';
        }

                // UI: for Income category, rename "Purchase Date" label to "Receive Date".
                echo '<script>(function(){
        '
                    . 'function syncSimkuReceiptLabel(){
        '
                    . '  var sel = document.getElementById("simku_kategori") || document.querySelector("select[name=\"kategori\"]");
        '
                    . '  var lbl = document.getElementById("simku_receipt_date_label");
        '
                    . '  if(!sel || !lbl) return;
        '
                    . '  var v = String(sel.value || "").toLowerCase();
        '
                    . '  lbl.textContent = (v === "income") ? "Receive Date" : "Purchase Date";
        '
                    . '}
        '
                    . 'function bind(){
        '
                    . '  var sel = document.getElementById("simku_kategori") || document.querySelector("select[name=\"kategori\"]");
        '
                    . '  if(!sel) return;
        '
                    . '  sel.addEventListener("change", syncSimkuReceiptLabel);
        '
                    . '  sel.addEventListener("input", syncSimkuReceiptLabel);
        '
                    . '}
        '
                    . 'bind();
        '
                    . 'syncSimkuReceiptLabel();
        '
                    . '})();</script>';



        echo '</div>';
    }

    
    /* -------------------- Receipt OCR (Scan Receipt) -------------------- */

    private function receipt_ocr_script_path() : string {
        return plugin_dir_path(__FILE__) . 'ocr/receipt_ocr.py';
    }


    private function get_n8n_scan_config() : array {
        // Returns [url, api_key, timeout]
        $s = $this->settings();
        $cfg = is_array($s['n8n'] ?? null) ? $s['n8n'] : [];

        $url = defined('SIMKU_N8N_WEBHOOK_URL') ? trim((string)SIMKU_N8N_WEBHOOK_URL) : '';
        if ($url === '') $url = trim((string)($cfg['webhook_url'] ?? ''));

        $api_key = defined('SIMKU_N8N_API_KEY') ? trim((string)SIMKU_N8N_API_KEY) : '';
        if ($api_key === '') $api_key = trim((string)($cfg['api_key'] ?? ''));

        $timeout = defined('SIMKU_N8N_TIMEOUT') ? (int)SIMKU_N8N_TIMEOUT : 0;
        if ($timeout <= 0) $timeout = (int)($cfg['timeout'] ?? 90);
        if ($timeout < 10) $timeout = 10;
        if ($timeout > 180) $timeout = 180;

        if ($url === '') return ['', '', $timeout];
        return [$url, $api_key, $timeout];
    }


    private function receipt_ocr_run(string $image_path) : array {
        // If configured, prefer n8n webhook (Gemini/AI) instead of local python+tesseract.
        // Configure in wp-config.php:
        //   define('SIMKU_N8N_WEBHOOK_URL', 'https://<your-n8n>/webhook/<id>');
        //   define('SIMKU_N8N_API_KEY', 'optional_shared_secret');
        //   define('SIMKU_N8N_TIMEOUT', 90);
        [$n8n_url, $api_key, $timeout] = $this->get_n8n_scan_config();
        if ($n8n_url !== '') {
            return $this->receipt_ocr_run_n8n($image_path, $n8n_url, $api_key, $timeout);
        }

        // Fallback: legacy python OCR.
        return $this->receipt_ocr_run_python($image_path);
    }

    private function receipt_ocr_run_n8n(string $image_path, string $url, string $api_key = '', int $timeout = 90) : array {
        // Returns: ['ok'=>bool, 'data'=>array|null, 'error'=>string|null, 'raw'=>string]

        if (!function_exists('wp_remote_post')) {
            return ['ok'=>false, 'data'=>null, 'error'=>'wp_remote_post is not available on this site.', 'raw'=>''];
        }

        $bin = @file_get_contents($image_path);
        if ($bin === false) {
            return ['ok'=>false, 'data'=>null, 'error'=>'Failed to read uploaded image file.', 'raw'=>''];
        }

        $ft = function_exists('wp_check_filetype') ? wp_check_filetype($image_path) : ['type' => ''];
        $mime = (string)($ft['type'] ?? '');
        if ($mime === '') $mime = 'application/octet-stream';

        $payload = [
            'filename' => basename($image_path),
            'mime' => $mime,
            'image_base64' => base64_encode($bin),
            'mode' => 'preview',
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $api_key = trim((string)$api_key);
        if ($api_key !== '') {
            $headers['X-API-Key'] = $api_key;
        }

        $timeout = (int)$timeout;
        if ($timeout < 10) $timeout = 10;
        if ($timeout > 180) $timeout = 180;

        $resp = wp_remote_post($url, [
            'headers' => $headers,
            'timeout' => $timeout,
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'n8n request failed: ' . $resp->get_error_message(), 'raw'=>''];
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = trim((string) wp_remote_retrieve_body($resp));

        if ($code < 200 || $code >= 300) {
            $msg = 'n8n returned HTTP ' . (string)$code;
            if ($raw !== '') $msg .= ': ' . $raw;
            return ['ok'=>false, 'data'=>null, 'error'=>$msg, 'raw'=>$raw];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'n8n response is not valid JSON.', 'raw'=>$raw];
        }

        // Allow both formats:
        // 1) { ...data fields... }
        // 2) { ok: true, data: { ... } }
        $data = $json;
        if (isset($json['data']) && is_array($json['data'])) {
            $data = $json['data'];
        }
        if (!is_array($data)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'n8n response JSON has unexpected format.', 'raw'=>$raw];
        }
        if (!empty($data['error'])) {
            return ['ok'=>false, 'data'=>null, 'error'=>(string)$data['error'], 'raw'=>$raw];
        }

        return ['ok'=>true, 'data'=>$data, 'error'=>null, 'raw'=>$raw];
    }

    private function receipt_ocr_run_python(string $image_path) : array {
        // Returns: ['ok'=>bool, 'data'=>array|null, 'error'=>string|null, 'raw'=>string]
        $script = $this->receipt_ocr_script_path();
        if (!file_exists($script)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'OCR script not found: ' . basename($script), 'raw'=>''];
        }

        // Allow override via wp-config.php: define('SIMKU_OCR_PYTHON', 'python3');
        $python = defined('SIMKU_OCR_PYTHON') ? (string)SIMKU_OCR_PYTHON : 'python3';

        if (!function_exists('proc_open')) {
            return ['ok'=>false, 'data'=>null, 'error'=>'The server does not allow running external processes (proc_open is disabled).', 'raw'=>''];
        }

        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' --image ' . escapeshellarg($image_path) . ' --format json';
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'Failed to run the OCR process. Please make sure Python and dependencies are installed.', 'raw'=>''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($proc);

        $raw = trim((string)$stdout);
        if ($code !== 0) {
            $msg = 'OCR failed (exit code ' . (string)$code . '). ' . trim((string)$stderr);
            return ['ok'=>false, 'data'=>null, 'error'=>$msg, 'raw'=>$raw];
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['ok'=>false, 'data'=>null, 'error'=>'OCR output is not valid JSON. ' . trim((string)$stderr), 'raw'=>$raw];
        }

        return ['ok'=>true, 'data'=>$json, 'error'=>null, 'raw'=>$raw];
    }

    public function page_scan_struk() : void {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die('Forbidden');

        $db = $this->ds_db();
        if (!$db) { echo '<div class="wrap fl-wrap"><h1>Scan Receipt</h1><div class="notice notice-error"><p>Datasource not configured.</p></div></div>'; return; }

        $table = $this->ds_table();

        $s = $this->settings();
        $notify_tg_default = !empty($s['notify']['telegram_notify_new_tx_default']);
        $notify_email_default = !empty($s['notify']['email_notify_new_tx_default']);

        $scan_result = null;
        $scan_error = '';
        $uploaded = null; // ['file'=>path,'url'=>url,'type'=>mime]

        // Save scanned -> Transactions
        if (!empty($_POST['fl_save_tx_scan'])) {
            check_admin_referer('fl_save_tx_scan');

            if ($this->ds_is_external() && !$this->ds_allow_write_external()) {
                echo '<div class="notice notice-error"><p>External datasource is read-only.</p></div>';
            } else {
                // Ensure user columns exist (external mode)
                [$ok, $msgs] = $this->ensure_external_user_columns();
                if (!$ok) {
                    echo '<div class="notice notice-error"><p>External datasource missing required user columns. '.esc_html(implode(' ', $msgs)).'</p></div>';
                } else {
                    $current_user = wp_get_current_user();
                    $user_login = $current_user ? $current_user->user_login : '';

                    $nama_toko = sanitize_text_field(wp_unslash($_POST['nama_toko'] ?? ''));
                    $kategori = $this->normalize_category(sanitize_text_field(wp_unslash($_POST['kategori'] ?? 'expense')));
                    $tanggal_input_raw = sanitize_text_field(wp_unslash($_POST['tanggal_input'] ?? current_time('mysql')));
                    $tanggal_input = $this->parse_csv_datetime($tanggal_input_raw);
                    if (!$tanggal_input) $tanggal_input = current_time('mysql');
                    $tanggal_struk_raw = sanitize_text_field(wp_unslash($_POST['tanggal_struk'] ?? ''));
                    $tanggal_struk = $this->parse_csv_date($tanggal_struk_raw);
                    $gambar_url = esc_url_raw(wp_unslash($_POST['gambar_url'] ?? ''));
                    $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));

                    $send_telegram_new = !empty($_POST['send_telegram_new']);
                    $send_email_new = !empty($_POST['send_email_new']);

                    $line_id_input = sanitize_text_field(wp_unslash($_POST['line_id'] ?? ''));
                    $transaction_id_input = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));

                    $items_arr = (array)($_POST['items'] ?? []);
                    $qty_arr = (array)($_POST['quantity'] ?? []);
                    $harga_arr = (array)($_POST['harga'] ?? []);

                    // Normalize arrays (ensure same length)
                    $max = max(count($items_arr), count($qty_arr), count($harga_arr));
                    $lines = [];
                    for ($i=0; $i<$max; $i++) {
                        $it = isset($items_arr[$i]) ? sanitize_text_field(wp_unslash($items_arr[$i])) : '';
                        $qt = isset($qty_arr[$i]) ? (int)($qty_arr[$i]) : 1;
                        $hg = isset($harga_arr[$i]) ? (int)preg_replace('/[^0-9]/', '', (string)$harga_arr[$i]) : 0;
                        if (!$it) continue;
                        if ($qt <= 0) $qt = 1;
                        if ($hg < 0) $hg = 0;
                        $lines[] = ['items'=>$it,'quantity'=>$qt,'harga'=>$hg];
                    }

                    if (empty($lines)) {
                        echo '<div class="notice notice-error"><p>No items found. Add at least 1 item before saving.</p></div>';
                    } else {
                        $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%d','%s'];

                        // Base IDs
                        $base_id = '';
                        if ($line_id_input) {
                            $base_id = preg_replace('/-\d+$/', '', $line_id_input);
                        } else {
                            $base_id = (string) round(microtime(true) * 1000) . '_' . substr(wp_generate_password(12, false, false), 0, 8);
                        }

                        $transaction_id = $transaction_id_input ? $transaction_id_input : $base_id;

                        $created = 0; $failed = 0; $fail_msgs = [];
                        $total_sum = 0.0;
                        $item_lines_txt = [];
                        $first_line_id = '';

                        foreach ($lines as $idx=>$ln) {
                            $suffix = str_pad((string)($idx+1), 3, '0', STR_PAD_LEFT);
                            $line_id = $base_id . '-' . $suffix;

                            if (!$first_line_id) $first_line_id = $line_id;

                            $data = [
                                'line_id' => $line_id,
                                'transaction_id' => $transaction_id,
                                'nama_toko' => $nama_toko,
                                'items' => $ln['items'],
                                'quantity' => (int)$ln['quantity'],
                                'harga' => (int)$ln['harga'],
                                'kategori' => $kategori,
                                'tanggal_input' => $tanggal_input ? $tanggal_input : current_time('mysql'),
                                'tanggal_struk' => $tanggal_struk,
                                'gambar_url' => $gambar_url,
                                'description' => $description,
                                'wp_user_id' => (int)($current_user ? $current_user->ID : 0),
                                'wp_user_login' => sanitize_text_field($user_login),
                            ];

                            $res = $db->insert($table, $data, $formats);
                            if ($res === false) {
                                $failed++;
                                $fail_msgs[] = 'Failed to insert line ' . $line_id;
                                continue;
                            }
                            $created++;
                            $this->log_event('create', 'transaction', $line_id, $data);
                            $line_total = (float)$data['harga'] * (float)$data['quantity'];
                            $total_sum += $line_total;
                            $item_lines_txt[] = '• ' . $data['items'] . ' (' . $data['quantity'] . ' x ' . number_format_i18n((float)$data['harga']) . ' = ' . number_format_i18n($line_total) . ')';
                        }

                        if ($created > 0) {
                            echo '<div class="notice notice-success"><p>Created <b>'.esc_html((string)$created).'</b> item(s) for transaction <code>'.esc_html($transaction_id).'</code>. <a href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">View Transactions</a></p></div>';
                        }
                        if (($send_telegram_new || $send_email_new) && $created > 0) {
                            $ctx = [
                                'user' => esc_html($user_login),
                                'kategori' => esc_html($kategori ?? ''),
                                'toko' => esc_html($nama_toko ?? ''),
                                'item' => esc_html(implode("
", $item_lines_txt)),
                                'qty' => esc_html(''),
                                'harga' => esc_html(''),
                                'total' => esc_html(number_format_i18n($total_sum)),
                                'tanggal_input' => esc_html($tanggal_input ?? ''),
                                'tanggal_struk' => esc_html($tanggal_struk ?? ''),
                                'transaction_id' => esc_html($transaction_id ?? ''),
                                'line_id' => esc_html($first_line_id ?? ''),
                                'gambar_url' => esc_html($gambar_url ?? ''),
                                'description' => esc_html(wp_strip_all_tags((string)($description ?? ''))),
                            ];
                            if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                            if ($send_email_new) $this->send_email_new_tx($ctx);
                        }

                        $this->cron_check_limits();

                        // Reset scan preview after save
                        $scan_result = null;
                    }
                }
            }
        }

        // Scan upload & OCR
        if (!empty($_POST['fl_scan_receipt_submit'])) {
            check_admin_referer('fl_scan_receipt');

            if (empty($_FILES['receipt_image']) || empty($_FILES['receipt_image']['tmp_name'])) {
                $scan_error = 'No file uploaded.';
            } else {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $file = $_FILES['receipt_image'];

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];

                $mime = $file['type'] ?? '';
                if (!isset($allowed[$mime])) {
                    $scan_error = 'Unsupported file type. Use JPG/PNG/WEBP.';
                } else {
                    $overrides = ['test_form' => false, 'mimes' => ['jpg|jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']];
                    $movefile = wp_handle_upload($file, $overrides);

                    if (isset($movefile['error'])) {
                        $scan_error = 'Upload failed: ' . (string)$movefile['error'];
                    } else {
                        $uploaded = $movefile;

                        $ocr = $this->receipt_ocr_run($movefile['file']);

                        // Optimize after OCR so OCR reads original quality.
                        $this->optimize_uploaded_image_file((string)$movefile['file']);
                        if (!$ocr['ok']) {
                            $scan_error = (string)($ocr['error'] ?? 'OCR failed.');
                            $scan_result = ['raw_text' => $ocr['raw'] ?? ''];
                        } else {
                            $scan_result = $ocr['data'];
                        }
                    }
                }
            }
        }

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Scan Receipt', '[simku_scan_struk]', '[simku page="scan-struk"]');

        // Diagnostics
        [$n8n_url, $n8n_key, $n8n_timeout] = $this->get_n8n_scan_config();
        $use_n8n = ($n8n_url !== '');

        $script_ok = file_exists($this->receipt_ocr_script_path());
        $python = defined('SIMKU_OCR_PYTHON') ? (string)SIMKU_OCR_PYTHON : 'python3';
        $diag_py = function_exists('shell_exec') ? @shell_exec(escapeshellcmd($python).' --version 2>&1') : '';
        $diag_py = trim((string)$diag_py);

        if ($scan_error) {
            echo '<div class="notice notice-error"><p>'.esc_html($scan_error).'</p></div>';
        }

        // Layout wrapper (2-column on desktop, stacked on mobile)
        echo '<div class="simku-scan-layout">';

        echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-upload">';
        echo '<h2>Upload & Scan</h2>';

        echo '<form method="post" enctype="multipart/form-data" class="fl-form">';
        wp_nonce_field('fl_scan_receipt');
        echo '<input type="hidden" name="fl_scan_receipt_submit" value="1" />';
        // 3-column layout: info | file | action
        echo '<div class="simku-scan-upload-grid3">';

        echo '<div class="simku-scan-upload-info">';
        echo '<div class="fl-help">Upload a receipt photo. The system will run OCR (python) or AI parsing (n8n) and show a preview before saving to Transactions.</div>';
        if ($use_n8n) {
            $host = '';
            $p = wp_parse_url($n8n_url);
            if (is_array($p)) {
                $host = (string)($p['host'] ?? '');
                if (!empty($p['port'])) $host .= ':' . (string)$p['port'];
            }
            echo '<div class="fl-help"><b>Mode:</b> <span class="fl-badge fl-badge-ok">n8n</span> (configured)'.($host ? ' — <span class="fl-badge fl-badge-sub">'.esc_html($host).'</span>' : '').'</div>';
            $settings_url = admin_url('admin.php?page=fl-settings#fl-receipt-scanner');
            $src = defined('SIMKU_N8N_WEBHOOK_URL') ? 'wp-config.php' : 'Settings';
            echo '<div class="fl-help">Configure via <a href="'.esc_url($settings_url).'">Settings → Receipt Scanner (n8n)</a> or wp-config.php constants. <span class="fl-muted">(Source: '.esc_html($src).')</span></div>';
        } else {
            echo '<div class="fl-help"><b>Mode:</b> <span class="fl-badge fl-badge-sub">python OCR</span> (default)</div>';
            echo '<div class="fl-help"><b>Note:</b> OCR requires a server that can run <code>python3</code> + <code>tesseract</code>. Untuk override command python: set <code>SIMKU_OCR_PYTHON</code> di wp-config.php.</div>';
            echo '<div class="fl-help">Status: Script '.($script_ok?'<span class="fl-badge fl-badge-ok">OK</span>':'<span class="fl-badge fl-badge-bad">Not found</span>').' | Python: '.($diag_py?'<span class="fl-badge fl-badge-ok">'.esc_html($diag_py).'</span>':'<span class="fl-badge fl-badge-sub">unknown</span>').'</div>';
        }
        echo '<div class="fl-help"><b>Tip:</b> gambar akan dikompres otomatis agar lebih kecil (target &lt; 1.37 MB per gambar).</div>';
        echo '</div>';

        echo '<div class="simku-scan-upload-file">';
        echo '<div class="fl-field"><label>Receipt photo</label>';
        echo '<div class="fl-filepicker">'
            .'<button type="button" class="button" data-fl-file-trigger="simku_scan_receipt_image">Choose file</button>'
            .'<span class="fl-file-label fl-file-names">No file chosen</span>'
            .'<input id="simku_scan_receipt_image" class="fl-hidden-file" type="file" name="receipt_image" accept="image/*" required />'
            .'<div class="simak-upload-hint"></div>'
            .'</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="simku-scan-upload-actions"><button class="button button-primary" type="submit">Scan Receipt</button></div>';

        echo '</div>'; // upload grid3
        echo '</form>';
        echo '</div>';

        if ($uploaded && !empty($uploaded['url'])) {
            $img = esc_url($uploaded['url']);
            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-image"><h2>Preview Image</h2><a href="'.$img.'" target="_blank" rel="noopener noreferrer"><img class="simku-scan-preview-img" src="'.$img.'" alt="Receipt" /></a></div>';
        }

        if (is_array($scan_result)) {
            $merchant = sanitize_text_field((string)($scan_result['merchant'] ?? $scan_result['store'] ?? ''));
            $tanggal_struk = sanitize_text_field((string)($scan_result['date'] ?? $scan_result['tanggal_struk'] ?? ''));
            $datetime = sanitize_text_field((string)($scan_result['datetime'] ?? ''));

            // Prefer date part if datetime given
            if (!$tanggal_struk && $datetime) {
                $ts = strtotime($datetime);
                if ($ts) $tanggal_struk = wp_date('Y-m-d', $ts);
            }

            $items = $scan_result['items'] ?? [];
            if (!is_array($items)) $items = [];

            $kategori_default = $this->normalize_category(sanitize_text_field((string)($scan_result['kategori'] ?? ($scan_result['category'] ?? 'expense'))));
            if (!in_array($kategori_default, ['expense','income','saving','invest'], true)) $kategori_default = 'expense';
            $ocr_total = (int)($scan_result['total'] ?? 0);
            $ocr_warnings = $scan_result['warnings'] ?? [];
            if (!is_array($ocr_warnings)) $ocr_warnings = [];

            // Build defaults
            $default_tanggal_input = current_time('mysql');
            $ti_local = '';
            $ts2 = strtotime($default_tanggal_input);
            if ($ts2) $ti_local = wp_date('Y-m-d\\TH:i', $ts2);

            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-result"><h2>OCR Result (Preview)</h2>';
            echo '<div class="fl-help">Please review and edit the result. When it looks correct, click <b>Save to Transactions</b>.</div>';

            if (!empty($ocr_warnings)) {
                $w = array_map(function($x){ return esc_html((string)$x); }, $ocr_warnings);
                echo '<div class="notice notice-warning"><p><b>OCR Notes:</b><br/>'.implode('<br/>', $w).'</p></div>';
            }
            if (!empty($ocr_total)) {
                echo '<div class="fl-inline"><span class="fl-pill">Detected total: Rp '.esc_html(number_format_i18n((float)$ocr_total)).'</span></div>';
            }

            echo '<form method="post" class="fl-form">';
            wp_nonce_field('fl_save_tx_scan');
            echo '<input type="hidden" name="fl_save_tx_scan" value="1" />';

            // Hidden image url from upload
            if ($uploaded && !empty($uploaded['url'])) {
                echo '<input type="hidden" name="gambar_url" value="'.esc_attr((string)$uploaded['url']).'" />';
            } else {
                echo '<input type="hidden" name="gambar_url" value="'.esc_attr((string)($scan_result['gambar_url'] ?? '')).'" />';
            }

            echo '<div class="fl-grid fl-grid-2">';
	            echo '<div class="fl-field"><label>Counterparty</label><input class="fl-input" name="nama_toko" value="'.esc_attr($merchant).'" placeholder="Example: FamilyMart / Dana / Salary / Bank Transfer" /></div>';
            echo '<div class="fl-field"><label>Category</label><select class="fl-input" name="kategori">';
            foreach (['expense','income','saving','invest'] as $cat) {
                echo '<option value="'.esc_attr($cat).'" '.selected($kategori_default,$cat,false).'>'.esc_html($this->category_label($cat)).'</option>';
            }
            echo '</select></div>';

            echo '<div class="fl-field"><label>Entry Date</label><input type="datetime-local" class="fl-input" name="tanggal_input" value="'.esc_attr($ti_local).'" /></div>';
            echo '<div class="fl-field"><label>Purchase Date</label><input type="date" class="fl-input" name="tanggal_struk" value="'.esc_attr($tanggal_struk).'" /></div>';
            echo '</div>';

            echo '<div class="fl-field"><label>Items</label>';
            echo '<div id="simak-scan-line-items" class="simak-line-items">';
            echo '<div class="simak-line-item-head" aria-hidden="true"><span>Item</span><span>Qty</span><span>Harga</span><span></span></div>';
            if (empty($items)) {
                // one empty row
                $items = [['name'=>'','qty'=>1,'price'=>0]];
            }
            foreach ($items as $it) {
                $name = sanitize_text_field((string)($it['name'] ?? $it['item'] ?? ''));
                $qty = (int)($it['qty'] ?? $it['quantity'] ?? 1);
                $price = (int)($it['price'] ?? $it['harga'] ?? $it['amount'] ?? 0);
                if ($qty <= 0) $qty = 1;
                if ($price < 0) $price = 0;

                echo '<div class="simak-line-item-row">';
                echo '<input type="text" class="fl-input" name="items[]" placeholder="Item name" value="'.esc_attr($name).'" required />';
                echo '<input type="number" class="fl-input" min="1" name="quantity[]" value="'.esc_attr((string)$qty).'" data-default="1" required />';
                echo '<input type="number" class="fl-input" min="0" name="harga[]" value="'.esc_attr((string)$price).'" data-default="0" required />';
                echo '<button type="button" class="button simak-remove-row" aria-label="Remove row" title="Remove row">×</button>';
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="fl-actions" style="margin-top:10px;"><button type="button" class="button" id="simak-scan-add-item-row">+ Add Item</button></div>';
            echo '</div>';

            $raw_text = (string)($scan_result['raw_text'] ?? $scan_result['text'] ?? '');
            $desc_default = $raw_text ? ("OCR Raw:\n" . $raw_text) : '';
            echo '<div class="fl-field"><label>Description</label><textarea class="fl-input" name="description" rows="5">'.esc_textarea($desc_default).'</textarea><div class="fl-help">Optional: store the raw OCR text as notes.</div></div>';

            echo '<div class="fl-check-row" style="margin-top:8px;">';
            echo '<label><input type="checkbox" name="send_telegram_new" value="1" '.checked($notify_tg_default, true, false).' /> Send Telegram notification for new transaction</label>';
            echo '<label><input type="checkbox" name="send_email_new" value="1" '.checked($notify_email_default, true, false).' /> Send Email notification for new transaction</label>';
            echo '</div>';

            echo '<div class="fl-actions">';
            echo '<button class="button button-primary" type="submit">Save to Transactions</button> ';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">View Transactions</a>';
            echo '</div>';

            echo '</form>';

            echo '<script>
(function(){
  const wrap = document.getElementById("simak-scan-line-items");
  const addBtn = document.getElementById("simak-scan-add-item-row");
  if (!wrap || !addBtn) return;

  const template = wrap.querySelector(".simak-line-item-row");
  function renumber(){
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    rows.forEach((row) => {
      const rm = row.querySelector(".simak-remove-row");
      if (rm) rm.style.visibility = (rows.length > 1) ? "visible" : "hidden";
    });
  }

  addBtn.addEventListener("click", function(){
    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((inp) => {
      const def = inp.getAttribute("data-default");
      inp.value = (def !== null) ? def : "";
    });
    wrap.appendChild(clone);
    renumber();
  });

  wrap.addEventListener("click", function(e){
    const btn = e.target.closest(".simak-remove-row");
    if (!btn) return;
    const row = btn.closest(".simak-line-item-row");
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    if (row && rows.length > 1) {
      row.remove();
      renumber();
    }
  });

  renumber();
})();
</script>';

            echo '</div>';
        } else {
            // Placeholder card in the right column (before any scan result)
            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-result">'
                .'<h2>OCR Result (Preview)</h2>'
                .'<div class="fl-help">Upload a receipt photo and click <b>Scan Receipt</b>. The parsed result will appear here for review before saving.</div>'
                .'</div>';
        }

        echo '</div>'; // simku-scan-layout

        echo '</div>'; // wrap
    }



public function handle_export_report_pdf() : void {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die('Forbidden');
        check_admin_referer('simku_export_report_pdf');

        $tab = isset($_POST['report_tab']) ? sanitize_text_field(wp_unslash($_POST['report_tab'])) : 'daily';
        $tab = in_array($tab, ['daily','weekly','monthly'], true) ? $tab : 'daily';

        // For now reports use Entry date as basis (same as existing totals). Can be extended later.
        $date_basis = 'input';

        // User filter (default: all users). Non-admins are restricted to their own login.
        $user_login = isset($_POST['report_user']) ? sanitize_text_field(wp_unslash($_POST['report_user'])) : 'all';
        if ($user_login === '' || $user_login === '0') $user_login = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $user_login = $cu->user_login;
        }

        $tx_type = isset($_POST['report_tx_type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_POST['report_tx_type'])) ) : 'all';

        if ($tab === 'daily') {
            $date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash($_POST['report_date'])) : wp_date('Y-m-d');
            $start = $date . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
            $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

            $report_title = ($tx_type === 'income') ? "Daily income report: {$date}" : (($tx_type === 'expense') ? "Daily expense report: {$date}" : "Daily report: {$date}");

            $this->export_pdf_report($report_title, $tot, [
                'report_type'   => 'daily',
                'range_display' => $date,
                'start_dt'      => $start,
                'end_dt'        => $end,
                'date_basis'    => $date_basis,
                'user_login'    => $user_login,
                'tx_type'      => $tx_type,
            ]);
            return;
        }

        if ($tab === 'weekly') {
            $from = isset($_POST['report_from']) ? sanitize_text_field(wp_unslash($_POST['report_from'])) : wp_date('Y-m-d', strtotime('monday this week'));
            $to = isset($_POST['report_to']) ? sanitize_text_field(wp_unslash($_POST['report_to'])) : wp_date('Y-m-d', strtotime('sunday this week'));
            $start = $from . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
            $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

            $from_disp = wp_date('d/m/Y', strtotime($from));
            $to_disp   = wp_date('d/m/Y', strtotime($to));

            $report_title = ($tx_type === 'income') ? "Weekly income report" : (($tx_type === 'expense') ? "Weekly expense report" : "Weekly report");

            $this->export_pdf_report($report_title, $tot, [
                'report_type'   => 'weekly',
                'range_display' => "{$from_disp} - {$to_disp}",
                'start_dt'      => $start,
                'end_dt'        => $end,
                'date_basis'    => $date_basis,
                'user_login'    => $user_login,
                'tx_type'      => $tx_type,
            ]);
            return;
        }

        // monthly
        $month = isset($_POST['report_month']) ? sanitize_text_field(wp_unslash($_POST['report_month'])) : wp_date('Y-m');
        $start = $month . '-01 00:00:00';
        $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

        $report_title = ($tx_type === 'income') ? "Monthly income report" : (($tx_type === 'expense') ? "Monthly expense report" : "Monthly report");

        $this->export_pdf_report($report_title, $tot, [
            'report_type'   => 'monthly',
            'range_display' => $month,
            'start_dt'      => $start,
            'end_dt'        => $end,
            'date_basis'    => $date_basis,
            'user_login'    => $user_login,
                'tx_type'      => $tx_type,
        ]);
    
    }

    public function handle_export_report_csv() : void {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die('Forbidden');
        check_admin_referer('simku_export_report_csv');

        $tab = isset($_POST['report_tab']) ? sanitize_text_field(wp_unslash($_POST['report_tab'])) : 'daily';
        $tab = in_array($tab, ['daily','weekly','monthly'], true) ? $tab : 'daily';

        // Reports currently use Entry Date (tanggal_input) as the basis (same as PDF export).
        $date_basis = 'input';

        // User filter (default: all users). Non-admins are restricted to their own login.
        $user_login = isset($_POST['report_user']) ? sanitize_text_field(wp_unslash($_POST['report_user'])) : 'all';
        if ($user_login === '' || $user_login === '0') $user_login = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $user_login = $cu->user_login;
        }

        $tx_type = isset($_POST['report_tx_type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_POST['report_tx_type']))) : 'all';

        $start = ''; $end = ''; $range_display = '';
        if ($tab === 'daily') {
            $date = isset($_POST['report_date']) ? sanitize_text_field(wp_unslash($_POST['report_date'])) : wp_date('Y-m-d');
            $start = $date . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
            $range_display = $date;
        } elseif ($tab === 'weekly') {
            $from = isset($_POST['report_from']) ? sanitize_text_field(wp_unslash($_POST['report_from'])) : wp_date('Y-m-d', strtotime('monday this week'));
            $to = isset($_POST['report_to']) ? sanitize_text_field(wp_unslash($_POST['report_to'])) : wp_date('Y-m-d', strtotime('sunday this week'));
            $start = $from . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
            $range_display = "{$from} - {$to}";
        } else { // monthly
            $month = isset($_POST['report_month']) ? sanitize_text_field(wp_unslash($_POST['report_month'])) : wp_date('Y-m');
            $start = $month . '-01 00:00:00';
            $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
            $range_display = $month;
        }

        $limit = 200000; // safety cap for large exports
        $rows = $this->fetch_report_detail_rows(
            $start,
            $end,
            $date_basis,
            $limit,
            ($user_login !== '' && $user_login !== 'all' && $user_login !== '0') ? $user_login : null,
            $tx_type
        );

        $title = ($tx_type === 'income') ? "Report income {$tab} {$range_display}"
              : (($tx_type === 'expense') ? "Report expense {$tab} {$range_display}" : "Report {$tab} {$range_display}");

        $filename = sanitize_file_name(strtolower(str_replace([' ',':','/'], '_', $title))).'.csv';

        // Clean any accidental output before headers.
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');

        // CSV headers (Payee + Source are always present for easier Excel analysis).
        fputcsv($out, [
            'Entry Date',
            'Purchase Date',
            'Receive Date',
            'Payee',
            'Source',
            'Category',
            'Item',
            'Qty',
            'Price',
            'Total',
            'Description',
            'Transaction ID',
            'Line ID',
            'User',
        ]);

        foreach ((array)$rows as $r) {
            $cat_raw = strtolower(trim((string)($r['kategori'] ?? '')));
            $is_income = ($cat_raw === 'income');

            $counterparty = (string)($r['nama_toko'] ?? '');
            $payee = $is_income ? 'N/A' : $counterparty;
            $source = $is_income ? $counterparty : 'N/A';

            $purchase_date = '';
            $receive_date = '';
            $d = (string)($r['purchase_date'] ?? '');
            if ($d !== '' && $d !== '0000-00-00') {
                if ($is_income) $receive_date = $d;
                else $purchase_date = $d;
            }

            $entry_date = (string)($r['entry_date'] ?? '');
            $item = (string)($r['items'] ?? '');
            $qty = (string)($r['quantity'] ?? '');
            $price = (string)($r['harga'] ?? '');
            $total = '';
            if (is_numeric($qty) && is_numeric($price)) {
                $total = (string)((float)$qty * (float)$price);
            }

            $desc = (string)($r['description'] ?? '');
            $tx_id = (string)($r['transaction_id'] ?? '');
            $line_id = (string)($r['line_id'] ?? '');
            $user_val = (string)($r['tx_user'] ?? '');

            fputcsv($out, [
                $entry_date,
                $purchase_date !== '' ? $purchase_date : 'N/A',
                $receive_date !== '' ? $receive_date : 'N/A',
                $payee !== '' ? $payee : 'N/A',
                $source !== '' ? $source : 'N/A',
                (string)($r['kategori'] ?? ''),
                $item,
                $qty,
                $price,
                $total,
                $desc,
                $tx_id,
                $line_id,
                $user_val,
            ]);
        }

        fclose($out);
        exit;
    }



public function page_reports() : void {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die('Forbidden');

        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'daily';
        $user_param = isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : '';
        $type_param = isset($_GET['type']) ? sanitize_text_field(wp_unslash($_GET['type'])) : '';

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Reports', '[simku_reports]', '[simku page="reports"]');
        echo '<h2 class="nav-tab-wrapper">';
        foreach (['daily'=>'Daily','weekly'=>'Weekly (Custom)','monthly'=>'Monthly'] as $k=>$label) {
            $args = ['page'=>'fl-reports','tab'=>$k];
            if ($user_param !== '' && $user_param !== '0') $args['user'] = $user_param;
            if ($type_param !== '' && $type_param !== '0') $args['type'] = $type_param;
            $url = add_query_arg($args, admin_url('admin.php'));
            echo '<a class="nav-tab '.($tab===$k?'nav-tab-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</h2>';

        if ($tab === 'daily') $this->report_daily();
        elseif ($tab === 'weekly') $this->report_weekly_custom();
        else $this->report_monthly();

        echo '</div>';
    }

    /**
     * Read the reports user filter from GET/POST.
     * - Admins can choose "All users" or any user_login.
     * - Non-admins are restricted to their own user_login.
     */
    private function reports_get_user_filter(array $src, string $key = 'user') : string {
        $u = isset($src[$key]) ? sanitize_text_field(wp_unslash($src[$key])) : 'all';
        if ($u === '' || $u === '0') $u = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $u = $cu->user_login;
        }
        return $u;
    }

    private function reports_render_user_dropdown(string $selected) : void {
        // Non-admins are restricted to their own user only.
        $args = [
            'name' => 'user',
            'value_field' => 'user_login',
            'echo' => 0,
        ];

        if (current_user_can('manage_options')) {
            $args['show_option_all'] = 'All users';
            // wp_dropdown_users uses value "0" for the "all" option.
            $args['selected'] = ($selected === 'all') ? '0' : $selected;
        } else {
            $args['include'] = [get_current_user_id()];
            $args['selected'] = $selected;
        }

        $html = wp_dropdown_users($args);

        // Ensure predictable id/class for styling.
        if (strpos($html, 'id=') === false) {
            $html = str_replace("name='user'", "name='user' id='simku_reports_user'", $html);
            $html = str_replace('name="user"', 'name="user" id="simku_reports_user"', $html);
        }

        if (strpos($html, 'class=') === false) {
            $html = preg_replace('/<select([^>]*)>/', '<select$1 class="simku-input">', $html, 1);
        } else {
            $html = preg_replace('/<select([^>]*)class="([^"]*)"([^>]*)>/', '<select$1class="$2 simku-input"$3>', $html, 1);
        }

        echo '<div class="simku-filter-field simku-filter-user">';
        echo '<label for="simku_reports_user">User</label>';
        echo $html;
        echo '</div>';
    }

    /**
     * Reports "Category" filter (Income / Expense / All).
     * We keep the request parameter name as `type` for backward-compatibility.
     */
    private function reports_sanitize_tx_type(string $raw) : string {
        $v = strtolower(trim((string)$raw));
        if ($v === '' || $v === '0' || $v === 'all' || $v === 'all_categories' || $v === 'all categories') return 'all';
        if ($v === 'income' || $v === 'in') return 'income';
        if ($v === 'expense' || $v === 'expenses' || $v === 'exp') return 'expense';
        return 'all';
    }

    /**
     * Transactions date filter selector: Entry / Purchase / Receive.
     */
    private function reports_sanitize_date_field(string $raw) : string {
        $v = strtolower(trim((string)$raw));
        if ($v === 'purchase' || $v === 'receive' || $v === 'entry') return $v;
        return 'entry';
    }

    private function reports_render_type_dropdown(string $selected) : void {
        $selected = $this->reports_sanitize_tx_type($selected);

        echo '<div class="simku-filter-field simku-filter-type">';
        echo '<label for="simku_report_type">Category</label>';
        echo '<select id="simku_report_type" name="type" class="simku-input">';
        echo '<option value="all"'.selected($selected, 'all', false).'>All categories</option>';
        echo '<option value="income"'.selected($selected, 'income', false).'>Income</option>';
        echo '<option value="expense"'.selected($selected, 'expense', false).'>Expense</option>';
        echo '</select>';
        echo '</div>';
    }


    /**
     * Normalize date input from browser/datepicker into ISO Y-m-d.
     * Accepts: Y-m-d, m/d/Y, d/m/Y, d-m-Y, m-d-Y, etc.
     *
     * Heuristic for dd/mm vs mm/dd:
     * - if first part > 12 => day/month
     * - else if second part > 12 => month/day
     * - else default month/day (matches most browser locale outputs like 01/14/2026)
     */
    private function normalize_date_ymd(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return date('Y-m-d');
        }

        // Already ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        // Common slash or dash formats: 01/14/2026, 14/01/2026, 14-01-2026, etc.
        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $raw, $m)) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $y = (int)$m[3];
            if ($a > 12 && $b <= 12) {
                $d = $a; $mo = $b;
            } elseif ($b > 12 && $a <= 12) {
                $mo = $a; $d = $b;
            } else {
                // ambiguous (both <= 12) -> default to month/day
                $mo = $a; $d = $b;
            }
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        // Try strtotime fallback
        $ts = strtotime($raw);
        if ($ts !== false) {
            return date('Y-m-d', $ts);
        }

        // last resort
        return date('Y-m-d');
    }

    /** Normalize month input into ISO Y-m (accepts YYYY-MM or MM/YYYY). */
    private function normalize_month_ym(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return date('Y-m');
        }
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{1,2})[\/-](\d{4})$/', $raw, $m)) {
            $mo = (int)$m[1];
            $y = (int)$m[2];
            return sprintf('%04d-%02d', $y, $mo);
        }
        $ts = strtotime($raw . '-01');
        if ($ts !== false) {
            return date('Y-m', $ts);
        }
        return date('Y-m');
    }


    private function report_daily() : void {
        $date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : wp_date('Y-m-d');
        $user_login = $this->reports_get_user_filter($_GET, 'user');
        $tx_type = isset($_GET['type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_GET['type'])) ) : 'all';

        $start = $date . ' 00:00:00';
        $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        echo '<form method="get" class="fl-inline simku-report-filter">';
        echo '<input type="hidden" name="page" value="fl-reports" />';
        echo '<input type="hidden" name="tab" value="daily" />';
        echo '<div class="simku-filter-field simku-filter-date"><label for="simku_report_date">Date</label><input id="simku_report_date" type="date" name="date" value="'.esc_attr($date).'" /></div>';
        $this->reports_render_user_dropdown($user_login);
        $this->reports_render_type_dropdown($tx_type);
        echo '<div class="simku-filter-actions"><button class="button">Run</button></div>';
        echo '</form>';

        // Export PDF
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_pdf');
        echo '<input type="hidden" name="action" value="simku_export_report_pdf" />';
        echo '<input type="hidden" name="simku_export_report_pdf" value="1" />';
        echo '<input type="hidden" name="report_tab" value="daily" />';
        echo '<input type="hidden" name="report_date" value="'.esc_attr($date).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export PDF</button>';
        echo '</form>';

        // Export CSV
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_csv');
        echo '<input type="hidden" name="action" value="simku_export_report_csv" />';
        echo '<input type="hidden" name="simku_export_report_csv" value="1" />';
        echo '<input type="hidden" name="report_tab" value="daily" />';
        echo '<input type="hidden" name="report_date" value="'.esc_attr($date).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export CSV</button>';
        echo '</form>';

        $ui_title = ($tx_type === 'income') ? "Daily income report: {$date}" : (($tx_type === 'expense') ? "Daily expense report: {$date}" : "Daily report: {$date}");
        $this->render_report_summary($ui_title, $tot, $tx_type);
    }

    private function report_weekly_custom() : void {
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : wp_date('Y-m-d', strtotime('monday this week'));
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : wp_date('Y-m-d', strtotime('sunday this week'));
        $user_login = $this->reports_get_user_filter($_GET, 'user');

        // inclusive end date -> end dt +1 day
        $tx_type = isset($_GET['type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_GET['type'])) ) : 'all';

        $start = $from . ' 00:00:00';
        $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        echo '<form method="get" class="fl-inline simku-report-filter">';
        echo '<input type="hidden" name="page" value="fl-reports" />';
        echo '<input type="hidden" name="tab" value="weekly" />';
        echo '<div class="simku-filter-field simku-filter-from"><label for="simku_report_from">From</label><input id="simku_report_from" type="date" name="from" value="'.esc_attr($from).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-to"><label for="simku_report_to">To</label><input id="simku_report_to" type="date" name="to" value="'.esc_attr($to).'" /></div>';
        $this->reports_render_user_dropdown($user_login);
        $this->reports_render_type_dropdown($tx_type);
        echo '<div class=\"simku-filter-actions\"><button class="button">Run</button></div>';
        echo '</form>';

        // Export PDF
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_pdf');
        echo '<input type="hidden" name="action" value="simku_export_report_pdf" />';
        echo '<input type="hidden" name="simku_export_report_pdf" value="1" />';
        echo '<input type="hidden" name="report_tab" value="weekly" />';
        echo '<input type="hidden" name="report_from" value="'.esc_attr($from).'" />';
        echo '<input type="hidden" name="report_to" value="'.esc_attr($to).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export PDF</button>';
        echo '</form>';

        // Export CSV
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_csv');
        echo '<input type="hidden" name="action" value="simku_export_report_csv" />';
        echo '<input type="hidden" name="simku_export_report_csv" value="1" />';
        echo '<input type="hidden" name="report_tab" value="weekly" />';
        echo '<input type="hidden" name="report_from" value="'.esc_attr($from).'" />';
        echo '<input type="hidden" name="report_to" value="'.esc_attr($to).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export CSV</button>';
        echo '</form>';

        $ui_title = ($tx_type === 'income') ? "Weekly income report: {$from} → {$to}" : (($tx_type === 'expense') ? "Weekly expense report: {$from} → {$to}" : "Weekly report: {$from} → {$to}");
        $this->render_report_summary($ui_title, $tot, $tx_type);
    }

    private function report_monthly() : void {
        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : wp_date('Y-m');
        $user_login = $this->reports_get_user_filter($_GET, 'user');
        $tx_type = isset($_GET['type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_GET['type'])) ) : 'all';

        $start = $month . '-01 00:00:00';
        $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        echo '<form method="get" class="fl-inline simku-report-filter">';
        echo '<input type="hidden" name="page" value="fl-reports" />';
        echo '<input type="hidden" name="tab" value="monthly" />';
        echo '<div class="simku-filter-field simku-filter-month"><label for="simku_report_month">Month</label><input id="simku_report_month" type="month" name="month" value="'.esc_attr($month).'" /></div>';
        $this->reports_render_user_dropdown($user_login);
        $this->reports_render_type_dropdown($tx_type);
        echo '<div class=\"simku-filter-actions\"><button class="button">Run</button></div>';
        echo '</form>';

        // Export PDF
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_pdf');
        echo '<input type="hidden" name="action" value="simku_export_report_pdf" />';
        echo '<input type="hidden" name="simku_export_report_pdf" value="1" />';
        echo '<input type="hidden" name="report_tab" value="monthly" />';
        echo '<input type="hidden" name="report_month" value="'.esc_attr($month).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export PDF</button>';
        echo '</form>';

        // Export CSV
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="fl-inline fl-inline-secondary">';
        wp_nonce_field('simku_export_report_csv');
        echo '<input type="hidden" name="action" value="simku_export_report_csv" />';
        echo '<input type="hidden" name="simku_export_report_csv" value="1" />';
        echo '<input type="hidden" name="report_tab" value="monthly" />';
        echo '<input type="hidden" name="report_month" value="'.esc_attr($month).'" />';
        echo '<input type="hidden" name="report_user" value="'.esc_attr($user_login).'" />';
        echo '<input type="hidden" name="report_tx_type" value="'.esc_attr($tx_type).'" />';
        echo '<button class="button">Export CSV</button>';
        echo '</form>';

        $ui_title = ($tx_type === 'income') ? "Monthly income report: {$month}" : (($tx_type === 'expense') ? "Monthly expense report: {$month}" : "Monthly report: {$month}");
        $this->render_report_summary($ui_title, $tot, $tx_type);
    }

    private function render_report_summary(string $title, array $tot, string $tx_type = 'all') : void {
        $tx_type = $this->reports_sanitize_tx_type($tx_type);

        if ($tx_type === 'income') {
            echo '<div class="fl-grid fl-grid-2 fl-mt">';
            echo '<div class="fl-card"><div class="fl-kpi-label">'.$title.'</div><div class="fl-kpi-value">—</div></div>';
            echo '<div class="fl-card"><div class="fl-kpi-label">Income</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n((float)($tot['income'] ?? 0))).'</div></div>';
            echo '</div>';
            return;
        }

        if ($tx_type === 'expense') {
            echo '<div class="fl-grid fl-grid-2 fl-mt">';
            echo '<div class="fl-card"><div class="fl-kpi-label">'.$title.'</div><div class="fl-kpi-value">—</div></div>';
            echo '<div class="fl-card"><div class="fl-kpi-label">Expense</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n((float)($tot['expense'] ?? 0))).'</div></div>';
            echo '</div>';
            return;
        }

        // all
        echo '<div class="fl-grid fl-grid-3 fl-mt">';
        echo '<div class="fl-card"><div class="fl-kpi-label">'.$title.'</div><div class="fl-kpi-value">—</div></div>';
        echo '<div class="fl-card"><div class="fl-kpi-label">Income</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n((float)($tot['income'] ?? 0))).'</div></div>';
        echo '<div class="fl-card"><div class="fl-kpi-label">Expense</div><div class="fl-kpi-value">Rp '.esc_html(number_format_i18n((float)($tot['expense'] ?? 0))).'</div></div>';
        echo '</div>';
    }

    public function page_logs() : void {
        if (!current_user_can(self::CAP_VIEW_LOGS)) wp_die('Forbidden');
        global $wpdb;
        $table = $wpdb->prefix.'fl_logs';

        $page = max(1, (int)($_GET['paged'] ?? 1));
        $per = 30;
        $offset = ($page-1)*$per;

        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $total_pages = max(1, (int)ceil($total / $per));
        $pagination_html = $this->render_pagination('fl-logs', $page, $total_pages, []);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per, $offset), ARRAY_A);

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Activity Logs', '[simku_logs]', '[simku page="logs"]');
        // Use auto table layout for better responsive scrolling on mobile.
        echo '<div class="fl-table-wrap"><table class="widefat striped simku-table"><thead><tr>';
        $log_cols = [
            'created_at' => 'Created',
            'user_login' => 'User',
            'action' => 'Action',
            'object_type' => 'Object Type',
            'object_id' => 'Object ID',
            'ip' => 'IP',
            'details' => 'Details',
        ];
        foreach ($log_cols as $k=>$label) {
            echo '<th>'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="7">No logs.</td></tr>';
        foreach ((array)$rows as $r) {
            echo '<tr>';
            echo '<td>'.esc_html($r['created_at']).'</td>';
            echo '<td>'.esc_html($r['user_login']).'</td>';
            echo '<td>'.esc_html($r['action']).'</td>';
            echo '<td>'.esc_html($r['object_type']).'</td>';
            echo '<td><code>'.esc_html($r['object_id']).'</code></td>';
            echo '<td>'.esc_html($r['ip']).'</td>';
            $d = (string)($r['details'] ?? '');
            $pretty = $d;
            $jd = json_decode($d, true);
            if (is_array($jd)) $pretty = wp_json_encode($jd, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo '<td class="fl-logs-details"><pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'.esc_html($pretty).'</pre></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo $pagination_html;

        echo '</div>';
    }

    public function page_settings() : void {
        if (!current_user_can(self::CAP_MANAGE_SETTINGS)) wp_die('Forbidden');

        $s = $this->settings();
        $notices = []; // each: ['type'=>'success|error|warning','msg'=>string]

        // Helper to build temp settings from POST (for test/save)
        $build_from_post = function(array $base) : array {
            $base['datasource_mode'] = sanitize_text_field(wp_unslash($_POST['datasource_mode'] ?? ($base['datasource_mode'] ?? 'external')));

            $base['external']['host']  = sanitize_text_field(wp_unslash($_POST['ext_host'] ?? ($base['external']['host'] ?? '')));
            $base['external']['db']    = sanitize_text_field(wp_unslash($_POST['ext_db'] ?? ($base['external']['db'] ?? '')));
            $base['external']['user']  = sanitize_text_field(wp_unslash($_POST['ext_user'] ?? ($base['external']['user'] ?? '')));
            $base['external']['table'] = sanitize_text_field(wp_unslash($_POST['ext_table'] ?? ($base['external']['table'] ?? 'finance_transactions')));
            $base['external']['allow_write'] = !empty($_POST['ext_allow_write']) ? 1 : 0;

            // Savings datasource (optional)
            $base['savings']['mode'] = sanitize_text_field(wp_unslash($_POST['savings_mode'] ?? ($base['savings']['mode'] ?? 'same')));
            $base['savings']['external_table'] = sanitize_text_field(wp_unslash($_POST['savings_ext_table'] ?? ($base['savings']['external_table'] ?? 'finance_savings')));

            // Payment reminders datasource (optional)
            $base['reminders']['mode'] = sanitize_text_field(wp_unslash($_POST['reminders_mode'] ?? ($base['reminders']['mode'] ?? 'same')));
            $base['reminders']['external_table'] = sanitize_text_field(wp_unslash($_POST['reminders_ext_table'] ?? ($base['reminders']['external_table'] ?? 'finance_payment_reminders')));

            // Password: only update if user typed something (security best practice)
            $new_pass = (string)wp_unslash($_POST['ext_pass'] ?? '');
            if ($new_pass !== '') {
                $base['external']['pass'] = sanitize_text_field($new_pass);
            }

            $base['limits']['daily'] = (float)($_POST['limit_daily'] ?? ($base['limits']['daily'] ?? 0));
            $base['limits']['weekly'] = (float)($_POST['limit_weekly'] ?? ($base['limits']['weekly'] ?? 0));
            $base['limits']['monthly'] = (float)($_POST['limit_monthly'] ?? ($base['limits']['monthly'] ?? 0));
            $base['limits']['expense_categories'] = array_values(array_filter(array_map('sanitize_text_field', (array)($_POST['expense_categories'] ?? ($base['limits']['expense_categories'] ?? [])))));

            $base['notify']['email_enabled'] = !empty($_POST['email_enabled']) ? 1 : 0;
            $base['notify']['email_to'] = sanitize_text_field(wp_unslash($_POST['email_to'] ?? ($base['notify']['email_to'] ?? '')));
            $base['notify']['email_notify_new_tx_default'] = !empty($_POST['email_new_default']) ? 1 : 0;
            $base['notify']['email_new_subject_tpl'] = sanitize_text_field(wp_unslash($_POST['email_new_subject_tpl'] ?? ($base['notify']['email_new_subject_tpl'] ?? '')));
            // Body templates can contain newlines; keep as textarea-safe text.
            $base['notify']['email_new_body_tpl'] = (string) wp_unslash($_POST['email_new_body_tpl'] ?? ($base['notify']['email_new_body_tpl'] ?? ''));

            $base['notify']['telegram_enabled'] = !empty($_POST['telegram_enabled']) ? 1 : 0;
            $base['notify']['telegram_bot_token'] = sanitize_text_field(wp_unslash($_POST['telegram_bot_token'] ?? ($base['notify']['telegram_bot_token'] ?? '')));
            $base['notify']['telegram_chat_id'] = sanitize_text_field(wp_unslash($_POST['telegram_chat_id'] ?? ($base['notify']['telegram_chat_id'] ?? '')));
            $base['notify']['telegram_notify_new_tx_default'] = !empty($_POST['telegram_new_default']) ? 1 : 0;
            $base['notify']['telegram_new_tpl'] = (string) wp_unslash($_POST['telegram_new_tpl'] ?? ($base['notify']['telegram_new_tpl'] ?? ''));

            $base['notify']['whatsapp_webhook'] = esc_url_raw(wp_unslash($_POST['whatsapp_webhook'] ?? ($base['notify']['whatsapp_webhook'] ?? '')));
            $base['notify']['notify_on_limit'] = !empty($_POST['notify_on_limit']) ? 1 : 0;

            // Reminder templates
            $base['notify']['reminder_offsets'] = [7,5,3]; // fixed by design
            $base['notify']['reminder_telegram_tpl'] = (string) wp_unslash($_POST['reminder_telegram_tpl'] ?? ($base['notify']['reminder_telegram_tpl'] ?? ''));
            $base['notify']['reminder_email_subject_tpl'] = sanitize_text_field(wp_unslash($_POST['reminder_email_subject_tpl'] ?? ($base['notify']['reminder_email_subject_tpl'] ?? '')));
            $base['notify']['reminder_email_body_tpl'] = (string) wp_unslash($_POST['reminder_email_body_tpl'] ?? ($base['notify']['reminder_email_body_tpl'] ?? ''));

            // Receipt scanner (n8n)
            if (!is_array($base['n8n'] ?? null)) $base['n8n'] = self::default_settings()['n8n'];
            $base['n8n']['webhook_url'] = esc_url_raw(wp_unslash($_POST['n8n_webhook_url'] ?? ($base['n8n']['webhook_url'] ?? '')));
            $base['n8n']['timeout'] = (int)($_POST['n8n_timeout'] ?? ($base['n8n']['timeout'] ?? 90));
            if ($base['n8n']['timeout'] < 10) $base['n8n']['timeout'] = 10;
            if ($base['n8n']['timeout'] > 180) $base['n8n']['timeout'] = 180;

            if (!empty($_POST['n8n_clear_api_key'])) {
                $base['n8n']['api_key'] = '';
            } else {
                $new_key = (string) wp_unslash($_POST['n8n_api_key'] ?? '');
                if ($new_key !== '') {
                    $base['n8n']['api_key'] = sanitize_text_field($new_key);
                }
            }

            return $base;
        };

        // Create internal table
        if (!empty($_POST['fl_create_internal_table'])) {
            check_admin_referer('fl_create_internal_table', 'fl_create_internal_table_nonce');
            [$ok, $msg] = $this->create_internal_transactions_table();
            $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
        }

        // Test connection (does NOT save settings)
        elseif (!empty($_POST['fl_test_connection'])) {
            check_admin_referer('fl_test_connection', 'fl_test_connection_nonce');
            $temp = $build_from_post($s);
            [$ok, $msg] = $this->test_connection_from_settings($temp);
            $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
            // keep entered values in UI by using $s = $temp for rendering
            $s = $temp;
        }

        // Run migration (external user columns)
        elseif (!empty($_POST['fl_run_migration'])) {
            check_admin_referer('fl_run_migration', 'fl_run_migration_nonce');
            $temp = $build_from_post($s);
            // keep entered values for rendering
            $s = $temp;

            [$ok_conn, $msg_conn] = $this->test_connection_from_settings($temp);
            if (!$ok_conn) {
                $notices[] = ['type' => 'error', 'msg' => 'Cannot run migration: ' . $msg_conn];
            } else {
                // Save settings first (so migration uses same config)
                $this->update_settings($temp);
                [$ok, $msgs] = $this->ensure_external_user_columns();
                $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => implode(' ', $msgs)];
            }
        }

        // Save settings
        elseif (!empty($_POST['fl_save_settings'])) {
            check_admin_referer('fl_save_settings', 'fl_save_settings_nonce');
            $s = $build_from_post($s);
            $this->update_settings($s);

            [$ok, $msg] = $this->test_connection_from_settings($s);
            if ($ok) {
                $notices[] = ['type' => 'success', 'msg' => 'Settings saved. ' . $msg];
            } else {
                $notices[] = ['type' => 'error', 'msg' => 'Settings saved, but connection check failed: ' . $msg];
            }
        }

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Settings', '[simku_settings]', '[simku page="settings"]');

        foreach ($notices as $n) {
            $type = $n['type'] === 'error' ? 'notice-error' : ($n['type'] === 'warning' ? 'notice-warning' : 'notice-success');
            echo '<div class="notice '.$type.'"><p>'.esc_html($n['msg']).'</p></div>';
        }

        // Connection status badge (based on current saved settings)
        [$ok_now, $msg_now] = $this->test_connection_from_settings($this->settings());
        $badge = $ok_now ? '<span class="fl-badge fl-badge-ok">Connected</span>' : '<span class="fl-badge fl-badge-bad">Not connected</span>';

        echo '<form method="post" class="fl-form">';

        // Nonces (IMPORTANT: use different field names to avoid “link expired”)
        wp_nonce_field('fl_save_settings', 'fl_save_settings_nonce');
        wp_nonce_field('fl_test_connection', 'fl_test_connection_nonce');
        wp_nonce_field('fl_run_migration', 'fl_run_migration_nonce');
        wp_nonce_field('fl_create_internal_table', 'fl_create_internal_table_nonce');

        echo '<div id="fl-datasource" class="fl-card"><h2>Datasource '.$badge.'</h2>';
        echo '<p class="fl-muted">'.esc_html($msg_now).'</p>';

        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="datasource_mode">';
        foreach (['external'=>'External MySQL','internal'=>'Internal (WP DB)'] as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($s['datasource_mode'],$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';

        echo '<div class="fl-field"><label>Table</label><input name="ext_table" value="'.esc_attr($s['external']['table'] ?? 'finance_transactions').'" /></div>';
        echo '<div class="fl-field"><label>Host</label><input name="ext_host" value="'.esc_attr($s['external']['host'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>DB Name</label><input name="ext_db" value="'.esc_attr($s['external']['db'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>User</label><input name="ext_user" value="'.esc_attr($s['external']['user'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>Password</label><input type="password" name="ext_pass" value="" placeholder="Leave blank to keep existing" /></div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="ext_allow_write" value="1" '.checked(!empty($s['external']['allow_write']),true,false).' /> Allow write to external (required for Add/Edit/Delete & migration)</label></div>';
        echo '</div>';

        echo '<div class="fl-actions">';
        echo '<button class="button" name="fl_test_connection" value="1">Test Connection</button> ';
        echo '<button class="button button-primary" name="fl_save_settings" value="1">Save Settings</button>';
        echo '</div>';

        echo '<hr class="fl-hr">';
        echo '<h3>External schema (matches your table)</h3>';
        echo '<pre class="fl-code">'.esc_html("CREATE TABLE finance_transactions (
  line_id VARCHAR(80) NOT NULL PRIMARY KEY,
  transaction_id VARCHAR(64) NOT NULL,
  nama_toko VARCHAR(255) NULL,
  items VARCHAR(255) NOT NULL,
  quantity INT NOT NULL,
  harga BIGINT NOT NULL,
  kategori VARCHAR(20) NULL,
  tanggal_input DATETIME NOT NULL,
  tanggal_struk DATE NULL,
  gambar_url TEXT NULL,
  description LONGTEXT NULL,
  wp_user_id BIGINT UNSIGNED NULL,
  wp_user_login VARCHAR(60) NULL,
  KEY transaction_id (transaction_id),
  KEY kategori (kategori),
  KEY tanggal_struk (tanggal_struk)
);").'</pre>';

        echo '<div class="fl-actions">';
        echo '<button class="button" name="fl_run_migration" value="1">Run Migration (add user columns)</button> ';
        echo '<button class="button" name="fl_create_internal_table" value="1">Create Internal Table</button>';
        echo '</div>';

        echo '</div>'; // datasource card

        // Savings datasource card
        $savings_mode = (string)($s['savings']['mode'] ?? 'same');
        $savings_label_map = [
            'same' => 'Same as Transactions',
            'internal' => 'Internal (WP DB)',
            'external' => 'External (use same connection)',
        ];
        $badge_savings = '<span class="fl-badge fl-badge-sub">'.esc_html($savings_label_map[$savings_mode] ?? 'Same as Transactions').'</span>';
        echo '<div class="fl-card"><h2>Savings datasource '.$badge_savings.'</h2>';
        echo '<p class="fl-muted">Configure where Savings (Tabungan) data is stored. Default: same datasource as Transactions. If you choose External, it uses the same Host/DB/User/Password above and only changes the table name.</p>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="savings_mode">';
        foreach ($savings_label_map as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($savings_mode,$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
        echo '<div class="fl-field"><label>External savings table</label><input name="savings_ext_table" value="'.esc_attr($s['savings']['external_table'] ?? 'finance_savings').'" placeholder="finance_savings" />';
        echo '<div class="fl-help">Used when Savings mode is External or Same-as-Transactions (and Transactions mode is External).</div>';
        echo '</div>';
        echo '</div>';
        echo '<h3>External savings schema</h3>';
        echo '<pre class="fl-code">'.esc_html("CREATE TABLE finance_savings (
  line_id VARCHAR(80) NOT NULL PRIMARY KEY,
  saving_id VARCHAR(64) NOT NULL,
  account_name VARCHAR(120) NOT NULL,
  amount BIGINT NOT NULL,
  institution VARCHAR(120) NULL,
  notes LONGTEXT NULL,
  saved_at DATETIME NOT NULL,
  wp_user_id BIGINT UNSIGNED NULL,
  wp_user_login VARCHAR(60) NULL,
  KEY saving_id (saving_id),
  KEY saved_at (saved_at)
);").'</pre>';
        echo '</div>';

        // Payment Reminders datasource card
        $rem_mode = (string)($s['reminders']['mode'] ?? 'same');
        $rem_label_map = [
            'same' => 'Same as Transactions',
            'internal' => 'Internal (WP DB)',
            'external' => 'External (use same connection)',
        ];
        $badge_rem = '<span class="fl-badge fl-badge-sub">'.esc_html($rem_label_map[$rem_mode] ?? 'Same as Transactions').'</span>';
        echo '<div class="fl-card"><h2>Payment Reminders datasource '.$badge_rem.'</h2>';
        echo '<p class="fl-muted">Configure where Payment Reminders (cicilan/billing) data is stored. If you choose External, it uses the same Host/DB/User/Password above and only changes the table name.</p>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="reminders_mode">';
        foreach ($rem_label_map as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($rem_mode,$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
        echo '<div class="fl-field"><label>External reminders table</label><input name="reminders_ext_table" value="'.esc_attr($s['reminders']['external_table'] ?? 'finance_payment_reminders').'" placeholder="finance_payment_reminders" />';
        echo '<div class="fl-help">Used when Reminders mode is External or Same-as-Transactions (and Transactions mode is External).</div>';
        echo '</div>';
        echo '</div>';
        echo '<h3>External reminders schema</h3>';
        echo '<pre class="fl-code">'.esc_html("CREATE TABLE finance_payment_reminders (
  line_id VARCHAR(80) NOT NULL PRIMARY KEY,
  reminder_id VARCHAR(64) NOT NULL,
  payment_name VARCHAR(255) NOT NULL,
  total_amount BIGINT NULL,
  installment_amount BIGINT NOT NULL,
  installments_total INT NOT NULL DEFAULT 1,
  installments_paid INT NOT NULL DEFAULT 0,
  schedule_mode VARCHAR(10) NOT NULL DEFAULT 'manual',
  due_day TINYINT UNSIGNED NULL,
  due_date DATE NOT NULL,
  payee VARCHAR(255) NULL,
  notes LONGTEXT NULL,
  status VARCHAR(10) NOT NULL DEFAULT 'belum',
  notify_telegram TINYINT UNSIGNED NOT NULL DEFAULT 1,
  notify_whatsapp TINYINT UNSIGNED NOT NULL DEFAULT 0,
  notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0,
  notified_for_due DATE NULL,
  notified_offsets VARCHAR(32) NULL,
  last_notified_at DATETIME NULL,
  wp_user_id BIGINT UNSIGNED NULL,
  wp_user_login VARCHAR(60) NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY reminder_id (reminder_id),
  KEY due_date (due_date),
  KEY status (status)
);").'</pre>';
        echo '</div>';

        echo '<div class="fl-card"><h2>Limits</h2>';
        echo '<div class="fl-grid fl-grid-3">';
        echo '<div class="fl-field"><label>Daily (Rp)</label><input type="number" min="0" name="limit_daily" value="'.esc_attr($s['limits']['daily'] ?? 0).'" /></div>';
        echo '<div class="fl-field"><label>Weekly (Rp)</label><input type="number" min="0" name="limit_weekly" value="'.esc_attr($s['limits']['weekly'] ?? 0).'" /></div>';
        echo '<div class="fl-field"><label>Monthly (Rp)</label><input type="number" min="0" name="limit_monthly" value="'.esc_attr($s['limits']['monthly'] ?? 0).'" /></div>';
        echo '</div>';
        echo '<div class="fl-field"><label>Expense categories (counted to limits)</label><div class="fl-check-group">';
        foreach (['outcome','saving','invest'] as $cat) {
            $checked = in_array($cat, (array)($s['limits']['expense_categories'] ?? []), true);
            echo '<label><input type="checkbox" name="expense_categories[]" value="'.esc_attr($cat).'" '.checked($checked,true,false).' /> '.esc_html($cat).'</label> ';
        }
        echo '</div></div>';
        echo '</div>';

        // Receipt Scanner (n8n)
        $has_n8n_key = !empty($s['n8n']['api_key']);
        $n8n_key_badge = $has_n8n_key ? '<span class="fl-badge fl-badge-ok">Saved</span>' : '<span class="fl-badge fl-badge-sub">Not set</span>';
        echo '<div id="fl-receipt-scanner" class="fl-card"><h2>Receipt Scanner (n8n)</h2>';
        echo '<div class="fl-help">Optional: use an n8n webhook (AI) to parse receipt images. If Webhook URL is set, Scan Receipt will use n8n instead of python OCR.</div>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field fl-full"><label>Webhook URL</label><input name="n8n_webhook_url" value="'.esc_attr($s['n8n']['webhook_url'] ?? '').'" placeholder="https://.../webhook/simku-scan-receipt" />';
        echo '<div class="fl-help">Expected response: valid JSON. Mode should return preview data.</div>';
        echo '</div>';
        echo '<div class="fl-field"><label>Timeout (seconds)</label><input type="number" min="10" max="180" name="n8n_timeout" value="'.esc_attr((int)($s['n8n']['timeout'] ?? 90)).'" /></div>';
        echo '<div class="fl-field"><label>API Key (optional)</label><input type="password" name="n8n_api_key" value="" placeholder="Leave blank to keep existing" />';
        echo '<div class="fl-help">Sent as header <code>X-API-Key</code>. ' . $n8n_key_badge . '</div>';
        echo '</div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="n8n_clear_api_key" value="1" /> Clear saved API key</label></div>';
        echo '</div>';
        echo '<div class="fl-help">Advanced: you can also define <code>SIMKU_N8N_WEBHOOK_URL</code> / <code>SIMKU_N8N_API_KEY</code> / <code>SIMKU_N8N_TIMEOUT</code> in wp-config.php (will override these settings).</div>';
        echo '</div>';

        echo '<div class="fl-card"><h2>Notifications</h2>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="notify_on_limit" value="1" '.checked(!empty($s['notify']['notify_on_limit']),true,false).' /> Notify when limits reached</label></div>';
        echo '<div></div>';

        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="email_enabled" value="1" '.checked(!empty($s['notify']['email_enabled']),true,false).' /> Email enabled</label></div>';
        echo '<div class="fl-field"><label>Email to</label><input name="email_to" value="'.esc_attr($s['notify']['email_to'] ?? '').'" /></div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="email_new_default" value="1" '.checked(!empty($s['notify']['email_notify_new_tx_default']),true,false).' /> Default: notify Email on new transaction</label></div>';
        echo '<div></div>';

        echo '<div class="fl-field fl-full"><label>Email subject template <span class="fl-muted">(new transaction)</span></label>';
        echo '<input name="email_new_subject_tpl" value="'.esc_attr($s['notify']['email_new_subject_tpl'] ?? '').'" placeholder="New transaction: {item} (Rp {total})" />';
        echo '<div class="fl-help">Placeholders: {user}, {kategori}, {toko}, {item}, {qty}, {harga}, {total}, {tanggal_input}, {tanggal_struk}, {transaction_id}, {line_id}, {gambar_url}, {description}</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email body template <span class="fl-muted">(new transaction)</span></label>';
        echo '<textarea class="fl-template" name="email_new_body_tpl" rows="8" placeholder="User: {user}\nCategory: {kategori}\nTotal: Rp {total}">'.esc_textarea($s['notify']['email_new_body_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">You can use new lines. Unused placeholders will be removed automatically.</div>';
        echo '</div>';

        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="telegram_enabled" value="1" '.checked(!empty($s['notify']['telegram_enabled']),true,false).' /> Telegram enabled</label></div>';
        echo '<div></div>';

        echo '<div class="fl-field"><label>Telegram bot token</label><input name="telegram_bot_token" value="'.esc_attr($s['notify']['telegram_bot_token'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>Telegram chat id</label><input name="telegram_chat_id" value="'.esc_attr($s['notify']['telegram_chat_id'] ?? '').'" /></div>';

        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="telegram_new_default" value="1" '.checked(!empty($s['notify']['telegram_notify_new_tx_default']),true,false).' /> Default: notify Telegram on new transaction</label></div>';
        echo '<div class="fl-field"><label>WhatsApp webhook URL (optional)</label><input name="whatsapp_webhook" value="'.esc_attr($s['notify']['whatsapp_webhook'] ?? '').'" /></div>';

        echo '<div class="fl-field fl-full"><label>Telegram template <span class="fl-muted">(new transaction)</span></label>';
        echo '<textarea class="fl-template" name="telegram_new_tpl" rows="8" placeholder="✅ <b>New transaction</b>\nUser: <b>{user}</b>\nTotal: <b>Rp {total}</b>">'.esc_textarea($s['notify']['telegram_new_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">Telegram supports HTML (bold/italic). Tip: use {gambar_url} to include the receipt URL.</div>';
        echo '</div>';

        // Reminder templates
        echo '<div class="fl-field fl-full"><label>Telegram/WhatsApp template <span class="fl-muted">(payment reminder – H-7/H-5/H-3)</span></label>';
        echo '<textarea class="fl-template" name="reminder_telegram_tpl" rows="8" placeholder="⏰ <b>PAYMENT REMINDER</b>\nPayment: <b>{payment_name}</b>\nDue date: {due_date} (D-{days_left})">'.esc_textarea($s['notify']['reminder_telegram_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">Placeholders: {payment_name}, {due_date}, {days_left}, {installment_amount}, {total_amount}, {installments_paid}, {installments_total}, {payee}, {notes}, {status}. WhatsApp uses the same template (HTML will be stripped).</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email subject template <span class="fl-muted">(payment reminder)</span></label>';
        echo '<input name="reminder_email_subject_tpl" value="'.esc_attr($s['notify']['reminder_email_subject_tpl'] ?? '').'" placeholder="Payment reminder: {payment_name} (D-{days_left})" />';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email body template <span class="fl-muted">(payment reminder)</span></label>';
        echo '<textarea class="fl-template" name="reminder_email_body_tpl" rows="8" placeholder="PAYMENT REMINDER\nPayment: {payment_name}\nDue date: {due_date} (D-{days_left})\nAmount: Rp {installment_amount}">'.esc_textarea($s['notify']['reminder_email_body_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">You can use new lines. Unused placeholders will be removed automatically.</div>';
        echo '</div>';

        echo '</div>';
        echo '</div>';

        echo '</form></div>';
    }

    public function page_charts() : void {
        // Backward compatible: Charts page now shows the list.
        $this->page_charts_list();
    }

    private function normalize_chart(array $c) : array {
        $c['id'] = (string)($c['id'] ?? '');
        $c['title'] = (string)($c['title'] ?? '');
        $c['chart_type'] = (string)($c['chart_type'] ?? 'bar');

        $c['data_source_mode'] = (string)($c['data_source_mode'] ?? 'builder'); // builder|sql
        $c['sql_query'] = (string)($c['sql_query'] ?? '');
        $c['custom_option_json'] = (string)($c['custom_option_json'] ?? '');

        $c['is_public'] = !empty($c['is_public']) ? 1 : 0;
        $c['created_by'] = (int)($c['created_by'] ?? 0);

        $c['x'] = (string)($c['x'] ?? 'day');
        $c['series'] = (string)($c['series'] ?? '');
        $c['metrics'] = is_array($c['metrics'] ?? null) ? $c['metrics'] : [['metric'=>'amount_total','agg'=>'SUM']];

        $c['range'] = is_array($c['range'] ?? null) ? $c['range'] : ['mode'=>'last_days','days'=>30,'from'=>'','to'=>''];
        $c['filter'] = is_array($c['filter'] ?? null) ? $c['filter'] : ['kategori'=>[],'top_n'=>0];
        $c['show_on_dashboard'] = !empty($c['show_on_dashboard']) ? 1 : 0;

        // Date basis: input (tanggal_input) or receipt (tanggal_struk)
        $c['date_basis'] = $this->sanitize_date_basis((string)($c['date_basis'] ?? 'input'));

        return $c;
    }

    private function can_view_chart(array $chart, int $user_id) : bool {
        // Finance roles can view all charts.
        if (current_user_can(self::CAP_VIEW_TX)) return true;
        // Others can view public charts or their own.
        if (!empty($chart['is_public'])) return true;
        return (int)($chart['created_by'] ?? 0) === (int)$user_id;
    }

    private function can_edit_chart(array $chart, int $user_id) : bool {
        // Finance roles can edit all charts.
        if (current_user_can(self::CAP_VIEW_TX)) return true;
        return (int)($chart['created_by'] ?? 0) === (int)$user_id;
    }

    public function page_charts_list() : void {
        if (!is_user_logged_in() || !current_user_can('read')) wp_die('Forbidden');

        $charts = get_option(self::OPT_CHARTS, self::default_charts());
        if (!is_array($charts)) $charts = self::default_charts();
        $charts = array_map([$this, 'normalize_chart'], (array)$charts);

        $uid = (int)get_current_user_id();

        // Delete chart
        if (!empty($_POST['fl_delete_chart'])) {
            check_admin_referer('fl_delete_chart');
            $id = sanitize_text_field(wp_unslash($_POST['delete_id'] ?? ''));
            if ($id) {
                $existing = $this->find_chart($id);
                if (!$existing) {
                    echo '<div class="notice notice-warning"><p>Chart not found.</p></div>';
                } elseif (!$this->can_edit_chart($existing, $uid)) {
                    wp_die('Forbidden');
                } else {
                    $charts = array_values(array_filter($charts, fn($c) => (($c['id'] ?? '') !== $id)));
                    update_option(self::OPT_CHARTS, $charts, false);

                    // Remove from dashboard selection.
                    $dash = get_option(self::OPT_DASH_CHARTS, []);
                    if (!is_array($dash)) $dash = [];
                    $dash = array_values(array_diff($dash, [$id]));
                    update_option(self::OPT_DASH_CHARTS, $dash, false);

                    echo '<div class="notice notice-success"><p>Chart deleted.</p></div>';
                }
            }
        }

        // Filter visible charts
        $visible = [];
        foreach ($charts as $c) {
            if ($this->can_view_chart($c, $uid)) $visible[] = $c;
        }

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Charts', '[simku_charts]', '[simku page="charts"]');

        if (is_admin()) {
            $add_url = add_query_arg(['page'=>'fl-add-chart'], admin_url('admin.php'));
            echo '<div class="fl-actions fl-mt" style="justify-content:flex-start">';
            echo '<a class="button button-primary" href="'.esc_url($add_url).'">Add Chart</a>';
            echo '</div>';
        } else {
            echo '<div class="fl-muted fl-mt">Untuk membuat chart baru, buat halaman dengan shortcode <code>[simku_add_chart]</code>.</div>';
        }

        echo '<div class="fl-card fl-mt">';
        echo '<div class="fl-card-head"><h2>Saved Charts</h2><span class="fl-muted">Public chart = template yang bisa dipakai semua user login (data tetap milik masing-masing user)</span></div>';

        echo '<div class="fl-field"><input type="search" id="fl_saved_search" placeholder="Search charts…" /></div>';
        echo '<div class="fl-table-wrap"><table class="widefat striped simku-table" id="fl_saved_table"><thead><tr>';
        foreach (['Title','ID','Type','Source','Visibility','Shortcode','Actions'] as $c) echo '<th>'.esc_html($c).'</th>';
        echo '</tr></thead><tbody>';

        foreach ($visible as $c) {
            $id = $c['id'];
            $edit_url = add_query_arg(['page'=>'fl-add-chart','edit'=>$id], admin_url('admin.php'));
            $source = ($c['data_source_mode'] ?? 'builder') === 'sql' ? 'SQL' : 'Builder';
            $vis = !empty($c['is_public']) ? 'Public' : 'Private';

            echo '<tr data-title="'.esc_attr(strtolower($c['title'] ?? '')).'">';
            echo '<td><b>'.esc_html($c['title'] ?? '').'</b></td>';
            echo '<td><code>'.esc_html($id).'</code></td>';
            echo '<td>'.esc_html($c['chart_type'] ?? '').'</td>';
            echo '<td>'.esc_html($source).'</td>';
            echo '<td>'.esc_html($vis).'</td>';
            echo '<td><code>[fl_chart id="'.esc_html($id).'"]</code></td>';
            echo '<td>';
            echo '<button class="button button-small fl-preview-row" type="button" data-id="'.esc_attr($id).'">Preview</button> ';
            if (is_admin() && $this->can_edit_chart($c, $uid)) {
                echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a> ';
                echo '<form method="post" style="display:inline-block" onsubmit="return confirm(\'Delete this chart?\')">';
                wp_nonce_field('fl_delete_chart');
                echo '<input type="hidden" name="fl_delete_chart" value="1" />';
                echo '<input type="hidden" name="delete_id" value="'.esc_attr($id).'" />';
                echo '<button class="button button-small button-link-delete">Delete</button>';
                echo '</form>';
            } else {
                echo '<span class="fl-muted">Read only</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        echo '<div id="fl_row_preview_wrap" class="fl-row-preview fl-mt" style="display:none;"><h3 id="fl_row_preview_title"></h3><div id="fl_row_preview" class="fl-chart-box"></div></div>';
        echo '</div>'; // card
        echo '</div>'; // wrap
    }

    public function page_add_chart() : void {
        if (!is_user_logged_in() || !current_user_can('read')) wp_die('Forbidden');

        $charts = get_option(self::OPT_CHARTS, self::default_charts());
        if (!is_array($charts)) $charts = self::default_charts();
        $charts = array_map([$this, 'normalize_chart'], (array)$charts);

        $uid = (int)get_current_user_id();

        $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $edit_chart = $edit_id ? $this->find_chart($edit_id) : null;

        if ($edit_chart && !$this->can_view_chart($edit_chart, $uid)) {
            $edit_chart = null;
            echo '<div class="notice notice-error"><p>Chart not accessible.</p></div>';
        }

        if (!is_array($edit_chart)) $edit_chart = [];

        // Save chart
        if (!empty($_POST['fl_save_chart'])) {
            check_admin_referer('fl_save_chart');

            $incoming_id = sanitize_key($_POST['chart_id'] ?? '');
            $existing = $incoming_id ? $this->find_chart($incoming_id) : null;
            if ($existing && !$this->can_edit_chart($existing, $uid)) {
                wp_die('Forbidden');
            }

            $mode = sanitize_text_field(wp_unslash($_POST['data_source_mode'] ?? 'builder'));
            if (!in_array($mode, ['builder','sql'], true)) $mode = 'builder';

            $chart = [
                'id' => $incoming_id,
                'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? 'Untitled chart')),
                'chart_type' => sanitize_text_field(wp_unslash($_POST['chart_type'] ?? 'bar')),
                'data_source_mode' => $mode,
                'is_public' => !empty($_POST['is_public']) ? 1 : 0,
                'date_basis' => $this->sanitize_date_basis((string)($_POST['date_basis'] ?? 'input')),
                'range' => [
                    'mode' => sanitize_text_field(wp_unslash($_POST['range_mode'] ?? 'last_days')),
                    'days' => (int)($_POST['range_days'] ?? 30),
                    'from' => sanitize_text_field(wp_unslash($_POST['range_from'] ?? '')),
                    'to' => sanitize_text_field(wp_unslash($_POST['range_to'] ?? '')),
                ],
                // builder fields (ignored in SQL mode)
                'x' => sanitize_text_field(wp_unslash($_POST['x'] ?? 'day')),
                'series' => sanitize_text_field(wp_unslash($_POST['series'] ?? '')),
                'metrics' => [],
                'filter' => [
                    'kategori' => array_values(array_filter(array_map('sanitize_text_field', (array)($_POST['filter_kategori'] ?? [])))),
                    'top_n' => (int)($_POST['top_n'] ?? 0),
                ],
                'show_on_dashboard' => (!empty($_POST['show_on_dashboard']) && current_user_can(self::CAP_VIEW_TX)) ? 1 : 0,
                // SQL fields
                'sql_query' => trim((string)wp_unslash($_POST['sql_query'] ?? '')),
                'custom_option_json' => trim((string)wp_unslash($_POST['custom_option_json'] ?? '')),
            ];

            // Metrics: up to 3
            for ($i=1;$i<=3;$i++) {
                $m = sanitize_text_field(wp_unslash($_POST["metric_$i"] ?? ''));
                if (!$m) continue;
                $agg = sanitize_text_field(wp_unslash($_POST["agg_$i"] ?? 'SUM'));
                $chart['metrics'][] = ['metric' => $m, 'agg' => $agg];
            }
            if (!$chart['metrics']) $chart['metrics'][] = ['metric'=>'amount_total','agg'=>'SUM'];

            if (!$chart['id']) {
                $chart['id'] = 'chart_' . substr(wp_generate_password(12, false, false), 0, 10);
            }

            // created_by
            if ($existing) {
                $chart['created_by'] = (int)($existing['created_by'] ?? $uid);
            } else {
                $chart['created_by'] = $uid;
            }

            $chart = $this->normalize_chart($chart);

            // Upsert
            $found = false;
            foreach ($charts as &$c) {
                if (($c['id'] ?? '') === $chart['id']) {
                    $c = $chart; $found = true; break;
                }
            }
            if (!$found) $charts[] = $chart;

            update_option(self::OPT_CHARTS, $charts, false);

            // Update dashboard selection (finance roles only)
            if (current_user_can(self::CAP_VIEW_TX)) {
                $dash = get_option(self::OPT_DASH_CHARTS, []);
                if (!is_array($dash)) $dash = [];
                $dash = array_values(array_unique(array_map('strval', $dash)));
                if (!empty($chart['show_on_dashboard']) && !in_array($chart['id'], $dash, true)) $dash[] = $chart['id'];
                if (empty($chart['show_on_dashboard']) && in_array($chart['id'], $dash, true)) $dash = array_values(array_diff($dash, [$chart['id']]));
                update_option(self::OPT_DASH_CHARTS, $dash, false);
            }

            $edit_chart = $chart;
            echo '<div class="notice notice-success"><p>Chart saved.</p></div>';
        }

        $back_url = add_query_arg(['page'=>'fl-charts'], admin_url('admin.php'));

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Add Chart', '[simku_add_chart]', '[simku page="add-chart"]');
        if (is_admin()) {
            echo '<div class="fl-actions fl-mt" style="justify-content:flex-start">';
            echo '<a class="button" href="'.esc_url($back_url).'">← Back to Charts</a>';
            echo '</div>';
        }

        echo '<div class="fl-card fl-builder fl-mt">';
        echo '<div class="fl-card-head"><h2>Chart Builder</h2><span class="fl-muted">Mode: Builder or SQL</span></div>';

        echo '<form method="post" id="fl-chart-form">';
        wp_nonce_field('fl_save_chart');
        echo '<input type="hidden" name="fl_save_chart" value="1" />';
        echo '<input type="hidden" name="chart_id" id="fl_chart_id" value="'.esc_attr($edit_chart['id'] ?? '').'" />';

        echo '<div class="fl-grid fl-grid-3 fl-gap-md">';
        echo '<div class="fl-field"><label>Title</label><input name="title" id="fl_title" value="'.esc_attr($edit_chart['title'] ?? 'New chart').'" /></div>';

        echo '<div class="fl-field"><label>Chart Type</label><select name="chart_type" id="fl_chart_type">';
        $types = ['bar'=>'Bar','stacked_bar'=>'Stacked Bar','line'=>'Line','area'=>'Area','scatter'=>'Scatter','pie'=>'Pie','donut'=>'Donut'];
        foreach ($types as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected(($edit_chart['chart_type'] ?? 'bar'),$k,false).'>'.esc_html($label).'</option>';
        echo '</select></div>';

        echo '<div class="fl-field"><label>Data Source</label><select name="data_source_mode" id="fl_data_source_mode">';
        $modes = ['builder'=>'Builder (drag & drop)','sql'=>'SQL Query'];
        foreach ($modes as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected(($edit_chart['data_source_mode'] ?? 'builder'),$k,false).'>'.esc_html($label).'</option>';
        echo '</select></div>';
        echo '</div>';

        echo '<div class="fl-grid fl-grid-3 fl-gap-md fl-mt">';
        echo '<div class="fl-field"><label>Date Basis</label><select name="date_basis" id="fl_date_basis">';
        foreach (['input'=>'Entry Date','receipt'=>'Purchase Date'] as $k=>$label) echo '<option value="'.esc_attr($k).'" '.selected(($edit_chart['date_basis'] ?? 'input'),$k,false).'>'.esc_html($label).'</option>';
        echo '</select></div>';

        echo '<div class="fl-field fl-check"><label><input type="checkbox" id="fl_is_public" name="is_public" value="1" '.checked(!empty($edit_chart['is_public']),true,false).' /> Public (template for all logged-in users)</label></div>';

        if (current_user_can(self::CAP_VIEW_TX)) {
            echo '<div class="fl-field fl-check"><label><input type="checkbox" name="show_on_dashboard" value="1" '.checked(!empty($edit_chart['show_on_dashboard']),true,false).' /> Show on SIMKU dashboard</label></div>';
        } else {
            echo '<div class="fl-field"><span class="fl-muted">Dashboard: Finance Manager/Admin only</span></div>';
        }
        echo '</div>';

        // SQL panel
        echo '<div id="fl_sql_panel" class="fl-card fl-mini fl-sql-panel fl-mt" style="display:none;">';
        echo '<h3>SQL Query</h3>';
        echo '<div class="fl-field fl-full"><label>Query (SELECT only)</label>';
        echo '<textarea class="fl-template" name="sql_query" id="fl_sql_query" rows="10" placeholder="SELECT DATE(tanggal_input) AS label, SUM(harga*quantity) AS value\nFROM {{active}}\nWHERE tanggal_input >= {{from_dt}} AND tanggal_input <= {{to_dt}}\nGROUP BY DATE(tanggal_input)\nORDER BY label">'.esc_textarea($edit_chart['sql_query'] ?? '').'</textarea>';
        echo '<div class="fl-help">Wajib mengembalikan kolom: <code>label</code>, <code>value</code>, opsional <code>series</code> (multi-series). Placeholder tabel: <code>{{active}}</code>, <code>{{savings}}</code>, <code>{{reminders}}</code>. Placeholder range: <code>{{from}}</code>, <code>{{to}}</code>, <code>{{from_dt}}</code>, <code>{{to_dt}}</code>.</div>';
        echo '</div>';
        echo '<div class="fl-field fl-full"><label>Custom Option JSON (ECharts) <span class="fl-muted">(optional)</span></label>';
        echo '<textarea class="fl-template" name="custom_option_json" id="fl_custom_option_json" rows="6" placeholder="{\n  &quot;tooltip&quot;: {&quot;trigger&quot;: &quot;axis&quot;},\n  &quot;yAxis&quot;: {&quot;axisLabel&quot;: {&quot;formatter&quot;: &quot;Rp {value}&quot;}}\n}">'.esc_textarea($edit_chart['custom_option_json'] ?? '').'</textarea>';
        echo '<div class="fl-help">Jika JSON valid, akan di-merge ke option ECharts default.</div>';
        echo '</div>';
        echo '</div>';

        // Builder shell (existing)
        echo '<div class="fl-builder-shell fl-mt">';
        // Fields panel
        echo '<div class="fl-fields">';
        echo '<div class="fl-subtitle">Fields</div>';
        echo '<input class="fl-field-search" type="search" placeholder="Search fields…" id="fl_field_search" />';
        echo '<div class="fl-field-groups">';
        echo '<div class="fl-field-group"><div class="fl-field-group-title">Dimensions</div>';
        foreach (['day'=>'day (tanggal_input)','week'=>'week','month'=>'month','year'=>'year','dow'=>'day of week','nama_toko'=>'nama_toko','kategori'=>'kategori','items'=>'items'] as $k=>$label) {
            echo '<div class="fl-pill" draggable="true" data-field="'.esc_attr($k).'" data-kind="dim">'.esc_html($label).'</div>';
        }
        echo '</div>';
        echo '<div class="fl-field-group"><div class="fl-field-group-title">Metrics</div>';
        foreach (['amount_total'=>'amount (harga*qty)','quantity_total'=>'quantity','count_rows'=>'count rows','income_total'=>'income total','expense_total'=>'expense total','avg_price'=>'avg harga'] as $k=>$label) {
            echo '<div class="fl-pill fl-pill-metric" draggable="true" data-field="'.esc_attr($k).'" data-kind="metric">'.esc_html($label).'</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Dropzones panel
        echo '<div class="fl-zones">';
        echo '<div class="fl-zone-row">';
        echo '<div class="fl-zone"><div class="fl-zone-title">X Axis</div><div class="fl-drop" data-target="x"><span class="fl-drop-hint">Drop a dimension</span></div><input type="hidden" name="x" id="fl_x" value="'.esc_attr($edit_chart['x'] ?? 'day').'" /></div>';
        echo '<div class="fl-zone"><div class="fl-zone-title">Series (optional)</div><div class="fl-drop" data-target="series"><span class="fl-drop-hint">Drop a dimension</span></div><input type="hidden" name="series" id="fl_series" value="'.esc_attr($edit_chart['series'] ?? '').'" /></div>';
        echo '</div>';

        $metrics = $edit_chart['metrics'] ?? [['metric'=>'amount_total','agg'=>'SUM']];
        $m1 = $metrics[0]['metric'] ?? '';
        $a1 = $metrics[0]['agg'] ?? 'SUM';
        $m2 = $metrics[1]['metric'] ?? '';
        $a2 = $metrics[1]['agg'] ?? 'SUM';
        $m3 = $metrics[2]['metric'] ?? '';
        $a3 = $metrics[2]['agg'] ?? 'SUM';

        echo '<div class="fl-zone"><div class="fl-zone-title">Y Values (up to 3)</div>';
        echo '<div class="fl-y-grid">';
        for ($i=1;$i<=3;$i++) {
            $mi = ${"m$i"}; $ai = ${"a$i"};
            echo '<div class="fl-y-item">';
            echo '<div class="fl-drop fl-drop-metric" data-target="metric_'.$i.'"><span class="fl-drop-hint">Drop a metric</span></div>';
            echo '<input type="hidden" name="metric_'.$i.'" id="fl_metric_'.$i.'" value="'.esc_attr($mi).'" />';
            echo '<select name="agg_'.$i.'" id="fl_agg_'.$i.'">';
            foreach (['SUM','AVG','COUNT','MAX','MIN'] as $agg) {
                echo '<option value="'.esc_attr($agg).'" '.selected($ai,$agg,false).'>'.esc_html($agg).'</option>';
            }
            echo '</select>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        // Filters & Range
        $range = $edit_chart['range'] ?? ['mode'=>'last_days','days'=>30,'from'=>'','to'=>''];
        $filter = $edit_chart['filter'] ?? ['kategori'=>[],'top_n'=>0];

        echo '<div class="fl-grid fl-grid-2 fl-gap-md fl-mt">';
        echo '<div class="fl-card fl-mini"><h3>Date Range</h3>';
        echo '<div class="fl-field"><label>Mode</label><select name="range_mode" id="fl_range_mode">';
        foreach (['last_days'=>'Last N days','custom'=>'Custom (from/to)'] as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($range['mode'] ?? 'last_days',$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Days</label><input type="number" min="1" name="range_days" id="fl_range_days" value="'.esc_attr($range['days'] ?? 30).'" /></div>';
        echo '<div class="fl-field"><label>Top N</label><input type="number" min="0" name="top_n" value="'.esc_attr($filter['top_n'] ?? 0).'" /></div>';
        echo '</div>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>From</label><input type="date" name="range_from" id="fl_range_from" value="'.esc_attr($range['from'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>To</label><input type="date" name="range_to" id="fl_range_to" value="'.esc_attr($range['to'] ?? '').'" /></div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fl-card fl-mini"><h3>Filter kategori</h3><div class="fl-check-group">';
        foreach (['expense','income','saving','invest'] as $cat) {
            $checked = in_array($cat, (array)($filter['kategori'] ?? []), true);
            echo '<label><input type="checkbox" name="filter_kategori[]" value="'.esc_attr($cat).'" '.checked($checked,true,false).' /> '.esc_html($cat).'</label>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div class="fl-actions fl-mt">';
        echo '<button class="button button-primary">Save Chart</button> ';
        echo '<button type="button" class="button" id="fl_preview_btn">Preview</button> ';
        echo '<button type="button" class="button" id="fl_clear_btn">Clear</button>';
        echo '</div>';

        echo '<div class="fl-card fl-preview fl-mt"><h3>Preview</h3><div id="fl_chart_preview" class="fl-chart-box" data-chart-id="'.esc_attr($edit_chart['id'] ?? '').'"></div><div class="fl-muted">Shortcode: <code>[fl_chart id=&quot;<span id="fl_shortcode_id">'.esc_html($edit_chart['id'] ?? '...').'</span>&quot;]</code></div></div>';

        echo '</div>'; // zones
        echo '</div>'; // builder shell

        echo '</form>';
        echo '</div>'; // card
        echo '</div>'; // wrap
    }

    private function find_chart(string $id) {
        $charts = get_option(self::OPT_CHARTS, []);
        foreach ((array)$charts as $c) {
            if (($c['id'] ?? '') === $id) return $this->normalize_chart((array)$c);
        }
        return null;
    }

    private function render_chart_container(string $id, bool $admin=false) : string {
        $cls = $admin ? 'fl-chart-box fl-chart-box-admin' : 'fl-chart-box';
        return '<div class="'.esc_attr($cls).'" data-fl-chart="'.esc_attr($id).'"></div>';
    }

    private function render_chart_container_with_config(string $id, array $config, bool $admin=false) : string {
        $cls = $admin ? 'fl-chart-box fl-chart-box-admin' : 'fl-chart-box';
        // Pass config as JSON (frontend js will POST it to the ajax endpoint).
        $json = wp_json_encode($config);
        return '<div class="'.esc_attr($cls).'" data-fl-chart="'.esc_attr($id).'" data-fl-config="'.esc_attr($json).'"></div>';
    }

    public function shortcode_chart($atts) : string {
        $atts = shortcode_atts(['id' => ''], $atts, 'fl_chart');
        $id = sanitize_text_field($atts['id']);
        if (!$id) return '';
        return $this->render_chart_container($id, false);
    }

    private function shortcode_box_html(string $shortcode, string $alt = '', string $title = 'Shortcode', string $help = '') : string {
        $sid = 'simku_sc_' . substr(md5($shortcode . '|' . $alt), 0, 10);

        $alt_html = '';
        if ($alt) {
            $alt_html = '<div class="fl-help">Alternatif: <code>' . esc_html($alt) . '</code></div>';
        }

        $help_html = '';
        if ($help) {
            $help_html = '<div class="fl-help">' . esc_html($help) . '</div>';
        }

        return '<div class="fl-card fl-mt-sm fl-shortcode-box">'
            . '<div class="fl-card-head"><h2 style="margin:0">' . esc_html($title) . '</h2><span class="fl-muted">Salin & tempel ke Page/Post</span></div>'
            . '<div class="fl-card-body">'
            . '<div class="fl-inline" style="align-items:center">'
            . '<input id="' . esc_attr($sid) . '" type="text" class="fl-input fl-template" readonly value="' . esc_attr($shortcode) . '" onclick="this.select();" style="max-width:520px" />'
            . '<button type="button" class="button fl-copy-shortcode" data-shortcode="' . esc_attr($shortcode) . '">Copy</button>'
            . '</div>'
            . $alt_html
            . $help_html
            . '</div></div>';
    }

    private function page_header_html(string $title, string $shortcode = '', string $alt = '') : string {
        $actions = '';
        if ($shortcode) {
            $actions .= '<div class="fl-head-actions fl-sc-actions">';
            $actions .= '<button type="button" class="fl-kebab" aria-label="Shortcode actions"><span class="fl-kebab-dots">⋮</span></button>';
            $actions .= '<div class="fl-menu" hidden>';
            $actions .= '<button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="' . esc_attr($shortcode) . '">Copy shortcode</button>';
            if ($alt) {
                $actions .= '<button type="button" class="fl-menu-item fl-copy-shortcode" data-shortcode="' . esc_attr($alt) . '">Copy alternative</button>';
            }
            $actions .= '</div></div>';
        }
        return '<div class="fl-page-head"><h1>' . esc_html($title) . '</h1>' . $actions . '</div>';
    }

    /**
     * Pagination UI used across SIMKU list pages.
     * Keeps pagination usable on desktop/mobile (bigger click targets + centered).
     */
    private function render_pagination(string $page_slug, int $current, int $total_pages, array $query_args = []) : string {
        if ($total_pages <= 1) return '';

        // Remove any existing 'paged' from args; paginate_links() will inject it.
        if (isset($query_args['paged'])) unset($query_args['paged']);

        // Keep only non-empty args.
        $query_args = array_filter($query_args, function($v){
            return !($v === '' || $v === null);
        });

        $base = add_query_arg(array_merge(['page' => $page_slug, 'paged' => '%#%'], $query_args), admin_url('admin.php'));
        $links = paginate_links([
            'base'      => $base,
            'format'    => '',
            'current'   => max(1, $current),
            'total'     => max(1, $total_pages),
            'mid_size'  => 1,
            'end_size'  => 1,
            'prev_text' => '‹',
            'next_text' => '›',
            'type'      => 'array',
        ]);

        if (empty($links) || !is_array($links)) return '';

        return '<div class="tablenav fl-pagination"><div class="tablenav-pages">' . implode('', $links) . '</div></div>';
    }


    private function simku_shortcode_pages() : array {
        return [
            'dashboard'       => ['method' => 'page_dashboard',       'cap' => self::CAP_VIEW_TX],
            'transactions'    => ['method' => 'page_transactions',    'cap' => self::CAP_VIEW_TX],
            'add-transaction' => ['method' => 'page_add_transaction', 'cap' => self::CAP_MANAGE_TX],
            'savings'         => ['method' => 'page_savings',         'cap' => self::CAP_VIEW_TX],
            'add-saving'      => ['method' => 'page_add_saving',      'cap' => self::CAP_MANAGE_TX],
            'reminders'       => ['method' => 'page_reminders',       'cap' => self::CAP_VIEW_TX],
            'add-reminder'    => ['method' => 'page_add_reminder',    'cap' => self::CAP_MANAGE_TX],
            'scan-struk'      => ['method' => 'page_scan_struk',      'cap' => self::CAP_MANAGE_TX],
            'reports'         => ['method' => 'page_reports',         'cap' => self::CAP_VIEW_REPORTS],
            'charts'          => ['method' => 'page_charts_list',     'cap' => 'read'],
            'add-chart'       => ['method' => 'page_add_chart',       'cap' => 'read'],
            'settings'        => ['method' => 'page_settings',        'cap' => self::CAP_MANAGE_SETTINGS],
            'logs'            => ['method' => 'page_logs',            'cap' => self::CAP_VIEW_LOGS],
        ];
    }

    public function shortcode_simku($atts = [], $content = null, $tag = 'simku') : string {
        $atts = shortcode_atts(['page' => 'dashboard'], (array)$atts, 'simku');
        $page = strtolower(trim((string)($atts['page'] ?? 'dashboard')));

        $map = $this->simku_shortcode_pages();
        if (!isset($map[$page])) return '';

        if (!is_user_logged_in()) {
            return '<div class="fl-wrap"><div class="notice notice-error"><p>Please log in first.</p></div></div>';
        }

        $cap = (string)($map[$page]['cap'] ?? '');
        if ($cap && !current_user_can($cap)) {
            return '<div class="fl-wrap"><div class="notice notice-error"><p>Akses ditolak.</p></div></div>';
        }

        ob_start();
        call_user_func([$this, $map[$page]['method']]);
        return (string)ob_get_clean();
    }

    public function shortcode_simku_dashboard($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'dashboard']), $content, 'simku_dashboard');
    }
    public function shortcode_simku_transactions($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'transactions']), $content, 'simku_transactions');
    }
    public function shortcode_simku_add_transaction($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-transaction']), $content, 'simku_add_transaction');
    }
    public function shortcode_simku_savings($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'savings']), $content, 'simku_savings');
    }
    public function shortcode_simku_add_saving($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-saving']), $content, 'simku_add_saving');
    }
    public function shortcode_simku_reminders($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'reminders']), $content, 'simku_reminders');
    }
    public function shortcode_simku_add_reminder($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-reminder']), $content, 'simku_add_reminder');
    }
    public function shortcode_simku_scan_struk($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'scan-struk']), $content, 'simku_scan_struk');
    }
    public function shortcode_simku_reports($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'reports']), $content, 'simku_reports');
    }
    public function shortcode_simku_charts($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'charts']), $content, 'simku_charts');
    }

    public function shortcode_simku_add_chart($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-chart']), $content, 'simku_add_chart');
    }
    public function shortcode_simku_settings($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'settings']), $content, 'simku_settings');
    }
    public function shortcode_simku_logs($atts = [], $content = null) : string {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'logs']), $content, 'simku_logs');
    }

    public function register_dashboard_widget() : void {
        if (!current_user_can(self::CAP_VIEW_TX)) return;
        wp_add_dashboard_widget('fl_dashboard_widget', 'SIMKU Charts', [$this, 'dashboard_widget']);
    }

    public function dashboard_widget() : void {
        $dash = get_option(self::OPT_DASH_CHARTS, []);
        if (!is_array($dash) || !$dash) {
            echo '<div class="fl-muted">No charts selected for dashboard. Go to SIMKU → Charts and enable “Show on dashboard”.</div>';
            return;
        }
        foreach ($dash as $id) {
            $c = $this->find_chart($id);
            if (!$c) continue;
            echo '<div class="fl-dash-widget-block">';
            echo '<div class="fl-dash-widget-title">'.esc_html($c['title'] ?? $id).'</div>';
            echo $this->render_chart_container($id, true);
            echo '</div>';
        }
    }

    /* -------------------- Charts data -------------------- */

    public function ajax_chart_data() : void {
        check_ajax_referer('fl_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Please log in first.']);
        }

        $uid = (int)get_current_user_id();

        // Preview supports passing a full config (POST) to avoid URL length limits.
        $raw_config = isset($_REQUEST['config']) ? wp_unslash($_REQUEST['config']) : '';
        $chart = null;

        if ($raw_config) {
            $cfg = json_decode((string)$raw_config, true);
            if (is_array($cfg)) {
                $chart = $this->normalize_chart($cfg);
            }
        }

        $id = sanitize_text_field(wp_unslash($_REQUEST['id'] ?? ''));
        if (!$chart) {
            if (!$id) wp_send_json_error(['message' => 'Missing chart id']);
            $chart = $this->find_chart($id);
        }

        if (!$chart) wp_send_json_error(['message' => 'Chart not found']);

        if (!$this->can_view_chart($chart, $uid)) {
            wp_send_json_error(['message' => 'Access denied.']);
        }

        $chart_type = (string)($chart['chart_type'] ?? 'bar');
        $range = $chart['range'] ?? ['mode' => 'last_days', 'days' => 30];

        // Compute date range (used by builder charts AND placeholders for SQL charts).
        $today = current_time('Y-m-d');
        $start_date = '';
        $end_date_excl = '';

        if (($range['mode'] ?? 'last_days') === 'custom' && !empty($range['from']) && !empty($range['to'])) {
            $start_date = sanitize_text_field((string)$range['from']);
            $end_date_excl = date('Y-m-d', strtotime($range['to'] . ' +1 day'));
        } else {
            $days = max(1, (int)($range['days'] ?? 30));
            $start_date = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
            $end_date_excl = date('Y-m-d', strtotime($today . ' +1 day'));
        }

        if (($chart['data_source_mode'] ?? 'builder') === 'sql') {
            $out = $this->sql_chart_payload($chart, $uid, $start_date, $end_date_excl);
            wp_send_json_success($out);
        }

        // Builder mode (existing logic)
        $db = $this->ds_db();
        if (!($db instanceof wpdb)) {
            wp_send_json_error(['message' => 'Datasource is not ready.']);
        }
        $table = $this->ds_table();

        $x = $chart['x'] ?? 'day';
        $series_dim = (string)($chart['series'] ?? '');
        $metrics = $chart['metrics'] ?? [['metric' => 'amount_total', 'agg' => 'SUM']];
        $filter = $chart['filter'] ?? ['kategori' => [], 'top_n' => 0];

        $basis = $this->sanitize_date_basis((string)($chart['date_basis'] ?? 'input'));
        $date_expr = $this->date_basis_expr($basis);

        // If a series dimension is set, we only support a single metric (m0).
        $has_series = $series_dim !== '';
        if ($has_series && is_array($metrics)) {
            $metrics = array_slice($metrics, 0, 1);
        }

        $dim = $this->dim_expr($x, $basis);
        $series_expr = $has_series ? $this->dim_expr($series_dim, $basis) : "''";

        // select metrics
        $select_metrics = [];
        $metric_labels = [];
        foreach ($metrics as $i => $m) {
            $metric = $m['metric'] ?? '';
            $agg = strtoupper($m['agg'] ?? 'SUM');
            $expr = $this->metric_expr($metric);
            if (!$expr) continue;

            // Some metrics imply a specific aggregation.
            if ($metric === 'count_rows') $agg = 'COUNT';
            if ($metric === 'avg_price') $agg = 'AVG';

            if (!in_array($agg, ['SUM','AVG','COUNT','MAX','MIN'], true)) $agg = 'SUM';
            $alias = 'm' . $i;
            $select_metrics[] = "{$agg}({$expr}) AS {$alias}";
            $metric_labels[] = $this->metric_label((string)$metric);
        }
        if (!$select_metrics) {
            $select_metrics[] = "SUM(" . $this->metric_expr('amount_total') . ") AS m0";
            $metric_labels[] = $this->metric_label('amount_total');
        }

        // Date filtering: receipt basis is DATE, input basis is DATETIME.
        $where = "{$date_expr} >= %s AND {$date_expr} < %s";
        if ($basis === 'receipt') {
            $params = [$start_date, $end_date_excl];
        } else {
            $params = [$start_date . ' 00:00:00', $end_date_excl . ' 00:00:00'];
        }

        // Filter by current user for non-finance roles (subscriber).
        if (!$this->is_chart_privileged_user() && $this->ds_column_exists('wp_user_id')) {
            $where .= " AND wp_user_id = %d";
            $params[] = $uid;
        }

        // kategori filter
        $cats = (array)($filter['kategori'] ?? []);
        $cats = array_values(array_filter(array_map('sanitize_text_field', $cats)));
        $cats = $this->expand_category_filter($cats);
        if ($cats) {
            $in = implode(',', array_fill(0, count($cats), '%s'));
            $where .= " AND kategori IN ($in)";
            $params = array_merge($params, $cats);
        }

        $group = $has_series ? "GROUP BY xval, sval" : "GROUP BY xval";
        $order = "ORDER BY xval ASC";
        $limit = "";

        $top_n = (int)($filter['top_n'] ?? 0);
        if ($top_n > 0 && $x === 'nama_toko') {
            // Top N stores by first metric
            $limit = $db->prepare("LIMIT %d", $top_n);
            $order = "ORDER BY m0 DESC";
            $group = "GROUP BY xval";
            $series_expr = "''";
        }

        $sql = "SELECT {$dim} AS xval, {$series_expr} AS sval, " . implode(',', $select_metrics) . "
                FROM `{$table}`
                WHERE {$where}
                {$group}
                {$order}
                {$limit}";
        $prepared = $db->prepare($sql, $params);
        if ($prepared === null) $prepared = $sql;
        $rows = $db->get_results($prepared, ARRAY_A);
        if (!is_array($rows)) $rows = [];

        // Fill missing days so charts look historical (only for daily X axis).
        $xvals_override = [];
        if ($x === 'day') {
            $d = strtotime($start_date);
            $end = strtotime($end_date_excl);
            while ($d !== false && $d < $end) {
                $xvals_override[] = date('Y-m-d', $d);
                $d = strtotime('+1 day', $d);
            }
        }

        $out = $this->format_chart_series($chart_type, $rows, $has_series, $metric_labels, $xvals_override);

        // Allow custom option json (if stored)
        if (empty($out['x'])) {
            $out['message'] = 'No data for the selected date range (query returned 0 rows).';
        }

        $opt_json = trim((string)($chart['custom_option_json'] ?? ''));
        if ($opt_json) {
            $opt = json_decode($opt_json, true);
            if (is_array($opt)) $out['option'] = $opt;
        }

        wp_send_json_success($out);
    }

    private function is_chart_privileged_user() : bool {
        return current_user_can(self::CAP_VIEW_TX);
    }

    private function sql_chart_payload(array $chart, int $user_id, string $start_date, string $end_date_excl) : array {
        global $wpdb;

        $sql_raw = trim((string)($chart['sql_query'] ?? ''));
        $chart_type = (string)($chart['chart_type'] ?? 'bar');

        if ($sql_raw === '') {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL query is empty'];
        }

        // Allow trailing semicolon only
        $sql = rtrim($sql_raw);
        if (substr($sql, -1) === ';') $sql = rtrim(substr($sql, 0, -1));
        if (strpos($sql, ';') !== false) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL contains multiple statements'];
        }

        $lower = strtolower($sql);
        if (!preg_match('/^\s*select\b/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL must start with SELECT'];
        }

        // Block dangerous tokens even within SELECT.
        if (preg_match('/\b(insert|update|delete|drop|alter|create|truncate|replace|grant|revoke)\b/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Only SELECT queries are allowed'];
        }
        if (preg_match('/(--|\/\*|\*\/)/', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL comments are not allowed'];
        }
        if (preg_match('/\binto\s+outfile\b|\bload_file\b/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Forbidden SQL feature'];
        }

        // Require using at least one internal placeholder table.
        if (!preg_match('/\{\{\s*(active|savings|reminders)\s*\}\}/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Query must use {{active}}, {{savings}} or {{reminders}}'];
        }

        $priv = $this->is_chart_privileged_user();

        $tables = [
            'active' => $wpdb->prefix . 'fl_transactions',
            'savings' => $wpdb->prefix . 'fl_savings',
            'reminders' => $wpdb->prefix . 'fl_payment_reminders',
        ];

        $params = [];

        // Date placeholders
        $to_inc = date('Y-m-d', strtotime($end_date_excl . ' -1 day'));
        $replace = [
            '{{from}}' => "'" . esc_sql($start_date) . "'",
            '{{to}}' => "'" . esc_sql($to_inc) . "'",
            '{{from_dt}}' => "'" . esc_sql($start_date . ' 00:00:00') . "'",
            '{{to_dt}}' => "'" . esc_sql($to_inc . ' 23:59:59') . "'",
            '{{to_excl}}' => "'" . esc_sql($end_date_excl) . "'",
            '{{to_excl_dt}}' => "'" . esc_sql($end_date_excl . ' 00:00:00') . "'",
            '{{date_col}}' => (($chart['date_basis'] ?? 'input') === 'receipt' ? 'tanggal_struk' : 'tanggal_input'),
        ];

        foreach ($replace as $k => $v) {
            $sql = str_ireplace($k, $v, $sql);
        }

        // User placeholder
        if (stripos($sql, '{{user_id}}') !== false || stripos($sql, '{{current_user_id}}') !== false) {
            $sql = str_ireplace(['{{user_id}}','{{current_user_id}}'], '%d', $sql);
            $params[] = $user_id;
        }

        // Table placeholders
        foreach ($tables as $key => $tbl) {
            if (stripos($sql, '{{' . $key . '}}') === false) continue;

            if ($priv) {
                $sql = str_ireplace('{{' . $key . '}}', '`' . $tbl . '`', $sql);
            } else {
                $sql = str_ireplace('{{' . $key . '}}', '(SELECT * FROM `' . $tbl . '` WHERE wp_user_id = %d)', $sql);
                $params[] = $user_id;
            }
        }

        // Prepare if needed
        $prepared = $sql;
        if ($params) {
            $prepared = $wpdb->prepare($sql, $params);
        }

        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $sql_err = (string) $wpdb->last_error;
        if ($sql_err !== "") {
            return [
                "type" => $chart_type,
                "x" => [],
                "series" => [],
                "message" => "SQL error: " . $sql_err,
            ];
        }
        if (!is_array($rows)) $rows = [];
        if (count($rows) > 2000) $rows = array_slice($rows, 0, 2000);

        $labels = [];
        $series_map = [];

        foreach ($rows as $r) {
            $label = isset($r['label']) ? $this->pretty_dim_label((string)$r['label']) : '';
            if ($label === '') continue;

            if (!in_array($label, $labels, true)) $labels[] = $label;

            $sname = isset($r['series']) && $r['series'] !== '' ? $this->pretty_dim_label((string)$r['series']) : 'Value';
            $val = isset($r['value']) ? (float)$r['value'] : 0.0;

            if (!isset($series_map[$sname])) $series_map[$sname] = [];
            $series_map[$sname][$label] = ($series_map[$sname][$label] ?? 0) + $val;
        }

        // Sort label if looks like date
        $all_date = $labels && count(array_filter($labels, fn($l) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$l))) === count($labels);
        if ($all_date) sort($labels);

        $series = [];
        foreach ($series_map as $name => $vals) {
            $data = [];
            foreach ($labels as $lab) $data[] = $vals[$lab] ?? 0;
            $series[] = ['name' => $name, 'data' => $data];
        }

        if (!$series) $series = [['name' => 'Value', 'data' => []]];

        $out = ['type' => $chart_type, 'x' => $labels, 'series' => $series];

        $opt_json = trim((string)($chart['custom_option_json'] ?? ''));
        if ($opt_json) {
            $opt = json_decode($opt_json, true);
            if (is_array($opt)) $out['option'] = $opt;
        }

        return $out;
    }

    private function dim_expr(string $dim, string $date_basis = 'input') : string {
        // Uses MySQL functions.
        $d = $this->date_basis_expr($date_basis);
        switch ($dim) {
            case 'day':   return "DATE({$d})";
            case 'week':  return "DATE_FORMAT({$d}, '%%x-W%%v')";
            case 'month': return "DATE_FORMAT({$d}, '%%Y-%%m')";
            case 'year':  return "DATE_FORMAT({$d}, '%%Y')";
            case 'dow':   return "DATE_FORMAT({$d}, '%%W')";
            case 'nama_toko': return "COALESCE(NULLIF(nama_toko,''), '(unknown)')";
            case 'kategori':  return "COALESCE(NULLIF(CASE WHEN kategori='outcome' THEN 'expense' ELSE kategori END,''), '(uncategorized)')";
            case 'items':     return "COALESCE(NULLIF(items,''), '(unknown)')";
            default:
                return "DATE({$d})";
        }
    }

    /**
     * Metric expression (row-level) used by builder charts.
     * Aggregation (SUM/AVG/COUNT/...) is applied by the caller.
     */
    private function metric_expr(string $metric) : string {
        switch ($metric) {
            case 'quantity_total':
                return "quantity";
            case 'count_rows':
                // Caller should use COUNT(), we return a constant so COUNT(1) works.
                return "1";
            case 'avg_price':
                // Caller should use AVG().
                return "harga";
            case 'income_total':
                return "CASE WHEN kategori='income' THEN (harga*quantity) ELSE 0 END";
            case 'expense_total':
                $cats = $this->get_expense_categories();
                $in = "'" . implode("','", array_map('esc_sql', $cats)) . "'";
                return "CASE WHEN kategori IN ({$in}) THEN (harga*quantity) ELSE 0 END";
            case 'amount_total':
            default:
                return "(harga*quantity)";
        }
    }

    /**
     * Friendly labels for metrics so chart legends don't show "Value".
     */
    private function metric_label(string $metric) : string {
        switch ($metric) {
            case 'amount_total':   return 'Total';
            case 'income_total':   return 'Income';
            case 'expense_total':  return 'Expense';
            case 'quantity_total': return 'Quantity';
            case 'count_rows':     return 'Count';
            case 'avg_price':      return 'Avg Price';
            default:
                // fallback: Title Case from snake_case
                $metric = trim($metric);
                if ($metric === '') return 'Value';
                $metric = str_replace('_', ' ', $metric);
                return ucwords($metric);
        }
    }

    private function format_chart_series(string $chart_type, array $rows, bool $has_series, array $metric_labels, array $xvals_override = []) : array {
        // output: {x:[], series:[{name, data:[]}]}
        $xvals = $xvals_override ? array_values(array_unique(array_map('strval', $xvals_override))) : [];
        $series_map = []; // key -> [x => value]
        $series = [];

        // If there are no rows but we have an override x-axis, at least return the x-axis.
        if (!$rows) {
            if (!$xvals) return ['x'=>[], 'series'=>[], 'type'=>$chart_type];
            if ($has_series) return ['x'=>$xvals, 'series'=>[], 'type'=>$chart_type];
            // create empty series for each metric
            foreach ($metric_labels as $label) {
                $series[] = ['name'=>(string)$label, 'data'=>array_fill(0, count($xvals), 0.0)];
            }
            return ['x'=>$xvals, 'series'=>$series, 'type'=>$chart_type];
        }

        if ($has_series) {
            // single metric with series dimension
            foreach ($rows as $r) {
                $x = $this->pretty_dim_label((string)($r['xval'] ?? ''));
                $s = $this->pretty_dim_label((string)($r['sval'] ?? ''));
                if (!in_array($x, $xvals, true)) $xvals[] = $x;

                $key = $s ?: '(series)';
                if (!isset($series_map[$key])) $series_map[$key] = [];
                $series_map[$key][$x] = (float)($r['m0'] ?? 0);
            }
            foreach ($series_map as $name=>$points) {
                $data = [];
                foreach ($xvals as $x) $data[] = isset($points[$x]) ? (float)$points[$x] : 0.0;
                $series[] = ['name'=>$name, 'data'=>$data];
            }
        } else {
            // metrics without series dimension => each metric becomes its own series
            foreach ($rows as $r) {
                $x = $this->pretty_dim_label((string)($r['xval'] ?? ''));
                if (!in_array($x, $xvals, true)) $xvals[] = $x;

                for ($i=0; $i<count($metric_labels); $i++) {
                    $k = 'm'.$i;
                    if (!isset($series_map[$k])) $series_map[$k] = [];
                    $series_map[$k][$x] = (float)($r[$k] ?? 0);
                }
            }
            for ($i=0; $i<count($metric_labels); $i++) {
                $k = 'm'.$i;
                $label = $metric_labels[$i] ?? ('Metric '.($i+1));
                $points = $series_map[$k] ?? [];
                $data = [];
                foreach ($xvals as $x) $data[] = isset($points[$x]) ? (float)$points[$x] : 0.0;
                $series[] = ['name'=>$label, 'data'=>$data];
            }
        }

        return ['x'=>$xvals,'series'=>$series,'type'=>$chart_type];
    }

    /* -------------------- Chart containers JS helpers -------------------- */

}

/* -------------------- Bootstrap -------------------- */

add_action('plugins_loaded', function() {
    SIMAK_App_Simak::instance();
});

register_activation_hook(__FILE__, ['SIMAK_App_Simak', 'activate']);
register_deactivation_hook(__FILE__, ['SIMAK_App_Simak', 'deactivate']);
