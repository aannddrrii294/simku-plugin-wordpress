<?php
/**
 * Plugin Name: WP SIMKU (Sistem Management Keuangan)
 * Description: WP SIMKU (Sistem Management Keuangan) — financial management for WordPress: track income/expenses, savings/investments, payment reminders, dashboards (ECharts), reports, spending limits & notifications. Supports n8n and external databases.
 * Version: 0.9.31.5
 * Author: HONET
 * Author URI: https://honet.web.id
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-simku
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) { exit; }

// Plugin path helper constants (used by templates and traits).
// plugin_dir_path() already includes a trailing slash.
if (!defined('SIMKU_PLUGIN_DIR')) {
  define('SIMKU_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('SIMKU_PLUGIN_URL')) {
  define('SIMKU_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once __DIR__ . '/includes/bootstrap.php';


final class SIMAK_App_Simak {
  const VERSION = '0.9.31.5';

  // Maintainability: helper groups are split into traits under /includes.
  use SIMKU_Trait_PDF;
  use SIMKU_Trait_CSV;
  use SIMKU_Trait_Datasource;
  use SIMKU_Trait_Saving_Attachments;
  use SIMKU_Trait_Notify;
  use SIMKU_Trait_Admin_Pages;
  use SIMKU_Trait_Reports;
  use SIMKU_Trait_Charts;
  use SIMKU_Trait_Budgets;
  use SIMKU_Trait_GDrive;
  use SIMKU_Trait_Integrations;

    const TEXT_DOMAIN = 'wp-simku';
    const PLUGIN_SHORT_NAME = 'WP SIMKU';
    const PLUGIN_LONG_NAME  = 'WP SIMKU (Sistem Management Keuangan)';

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
    const CAP_MANAGE_BUDGETS = 'simak_manage_budgets';
    const CAP_MANAGE_SETTINGS = 'simak_manage_settings';
    const CAP_VIEW_LOGS = 'simak_view_logs';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);

        // Show activation errors (if any) without breaking the site.
        add_action('admin_notices', [$this, 'admin_notice_activation_error']);

        // Seed default charts/dashboard if missing (helps after updates/migrations).
        add_action('admin_init', [$this, 'maybe_seed_defaults']);

        // Ensure transactions schema stays compatible after plugin updates.
        // (Activation hook may not run on update, so we migrate in admin_init as well.)
        add_action('admin_init', [$this, 'maybe_migrate_transactions_schema']);
        add_action('admin_init', [$this, 'maybe_migrate_logs_schema']);

        // Ensure budgets table exists after updates.
        add_action('admin_init', [$this, 'maybe_ensure_budgets_table']);

        // Ensure savings attachments table exists after updates.
        add_action('admin_init', [$this, 'maybe_ensure_saving_attachments_table']);

        // Ensure new capabilities are added after updates (activation hook may not run).
        add_action('admin_init', [$this, 'ensure_role_caps']);

        // Handle form submissions early (before admin page output) to avoid
        // "Cannot modify header information" warnings when redirecting.
        add_action('admin_init', [$this, 'handle_add_saving_post']);

        // CSV template export for reminders (runs early, before page output).
        add_action('admin_init', [$this, 'handle_export_reminder_template']);

        // CSV template export for transactions (runs early, before page output).
        add_action('admin_init', [$this, 'handle_export_transaction_template']);

        // PDF export endpoints (must run outside admin.php rendering).
        add_action('admin_post_simku_export_report_pdf', [$this, 'handle_export_report_pdf']);
        add_action('admin_post_simku_export_report_csv', [$this, 'handle_export_report_csv']);

        // Budget Target (Goals) actions must run before admin page output.
        add_action('admin_post_simku_save_goal', [$this, 'handle_admin_post_save_goal']);
        add_action('admin_post_simku_delete_goal', [$this, 'handle_admin_post_delete_goal']);

        // Private proxy for Google Drive receipt images (admin session + nonce).
        add_action('admin_post_simku_receipt_media', [$this, 'handle_admin_post_simku_receipt_media']);

        add_action('wp_ajax_simak_chart_data', [$this, 'ajax_chart_data']);
        
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

        // REST API (transactions, budgets, telegram inbound)
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        add_action('wp_dashboard_setup', [$this, 'register_dashboard_widget']);

        // Logs: login/logout
        add_action('wp_login', [$this, 'on_login'], 10, 2);
        add_action('wp_logout', [$this, 'on_logout']);

        // Cron
        add_action('fl_check_limits_hourly', [$this, 'cron_check_limits']);
        add_action('simak_check_payment_reminders_hourly', [$this, 'cron_check_payment_reminders']);
    }

    /**
     * Process Add/Edit Saving form submissions on admin_init.
     *
     * Why:
     * - WordPress admin pages (admin.php) start output before running the page callback.
     * - Redirecting inside the page callback can trigger "headers already sent" warnings.
     */
    public function handle_add_saving_post() {
        if (!is_admin()) return;
        if (!current_user_can(self::CAP_MANAGE_TX)) return;

        // Only handle the Savings form.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'fl-add-saving') return;

        if (empty($_POST['fl_add_saving'])) return;

        // Nonce
        check_admin_referer('fl_add_saving');

        $db = $this->savings_db();
        if (!($db instanceof wpdb)) {
            wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=db'));
            exit;
        }

        $table = $this->savings_table();
        $date_col = $this->savings_date_column($db, $table);
        $is_ext = $this->savings_is_external();
        $user = wp_get_current_user();

        $return_page = !empty($_GET['return_page']) ? sanitize_text_field(wp_unslash($_GET['return_page'])) : ( !empty($_POST['return_page']) ? sanitize_text_field(wp_unslash($_POST['return_page'])) : 'fl-savings' );
        $return_from = !empty($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : ( !empty($_POST['return_from']) ? sanitize_text_field(wp_unslash($_POST['return_from'])) : '' );
        $return_to = !empty($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : ( !empty($_POST['return_to']) ? sanitize_text_field(wp_unslash($_POST['return_to'])) : '' );

        $mode = isset($_POST['fl_mode']) ? sanitize_text_field(wp_unslash($_POST['fl_mode'])) : 'create';
        $mode = ($mode === 'edit') ? 'edit' : 'create';

        $line_id = isset($_POST['line_id']) ? sanitize_text_field(wp_unslash($_POST['line_id'])) : '';
        $saving_id = isset($_POST['saving_id']) ? sanitize_text_field(wp_unslash($_POST['saving_id'])) : '';
        $account_name = isset($_POST['account_name']) ? sanitize_text_field(wp_unslash($_POST['account_name'])) : '';
        $amount = isset($_POST['amount']) ? (int) sanitize_text_field(wp_unslash($_POST['amount'])) : 0;
        $institution = isset($_POST['institution']) ? sanitize_text_field(wp_unslash($_POST['institution'])) : '';
        $notes = isset($_POST['notes']) ? wp_kses_post(wp_unslash($_POST['notes'])) : '';
        $saved_at = isset($_POST['saved_at']) ? sanitize_text_field(wp_unslash($_POST['saved_at'])) : '';

        $budget_goal_id = isset($_POST['budget_goal_id']) ? (int) sanitize_text_field(wp_unslash($_POST['budget_goal_id'])) : 0;

        // datetime-local does NOT include timezone.
        // We treat it as WordPress site timezone (Settings → General) and store it in the same timezone.
        // This prevents hour shifts when server / DB timezone differs.
        $saved_at = $saved_at ? $this->mysql_from_ui_datetime($saved_at) : '';
        if (!$saved_at) $saved_at = current_time('mysql');

        // Validate budget target allocation (only for Saving-based targets, and only if user_scope allows it).
        $budget_goal_id = (int)$budget_goal_id;
        if ($budget_goal_id > 0 && method_exists($this, 'simku_goal_get_by_id')) {
            $g = $this->simku_goal_get_by_id($budget_goal_id);
            $g_basis = is_array($g) ? (string)($g['basis'] ?? '') : '';
            $g_scope = is_array($g) ? (string)($g['user_scope'] ?? 'all') : 'all';
            $u_login = $user && !empty($user->user_login) ? (string)$user->user_login : '';
            $scope_ok = ($g_scope === 'all') || ($u_login && strcasecmp($g_scope, $u_login) === 0);

            if ($g_basis !== 'saving' || !$scope_ok) {
                $budget_goal_id = 0;
            }
        } else {
            if ($budget_goal_id > 0) $budget_goal_id = 0;
        }

        // Ensure redirect range includes the edited row.
        // Otherwise the UI can look like data "disappeared" after update when saved_at moves
        // outside the current From/To filter (often due to timezone boundaries).
        $saved_date = substr($saved_at, 0, 10);
        if ($saved_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $saved_date)) {
            if (!$return_from) $return_from = $saved_date;
            if (!$return_to) $return_to = $saved_date;
            if ($return_from && $saved_date < $return_from) $return_from = $saved_date;
            if ($return_to && $saved_date > $return_to) $return_to = $saved_date;
        }
        if ($mode === 'create') {
            if (!$line_id) $line_id = 'ln_' . wp_generate_uuid4();
            if (!$saving_id) $saving_id = 'sv_' . substr(md5($line_id), 0, 10);
        }

        // Basic validation
        if (!$account_name || $amount <= 0) {
            wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=required'));
            exit;
        }

        // External table write protection
        if ($is_ext && !$this->ds_allow_write_external()) {
            wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=readonly'));
            exit;
        }

        if ($mode === 'edit') {
            if (!$line_id) {
                wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=missing_id'));
                exit;
            }

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

                // Apply Budget Target allocation (optional)
                if (method_exists($this, 'simku_goal_alloc_upsert') && method_exists($this, 'simku_goal_alloc_delete_by_saving_line')) {
                    if ((int)$budget_goal_id > 0) {
                        $this->simku_goal_alloc_upsert($line_id, (int)$budget_goal_id, (int)$amount, $saved_at, $user && !empty($user->user_login) ? $user->user_login : '');
                    } else {
                        $this->simku_goal_alloc_delete_by_saving_line($line_id);
                    }
                }

                // Savings attachments (multiple images)
                // Supports:
                // - upload multiple images
                // - remove selected existing images (edit mode)
                // - replace all existing images (edit mode)
                if (method_exists($this, 'handle_multi_image_uploads') && method_exists($this, 'simku_saving_attachments_upsert_set')) {
                    $uploaded_urls = (array)$this->handle_multi_image_uploads('saving_images');

                    $remove = isset($_POST['saving_images_remove']) ? (array)wp_unslash($_POST['saving_images_remove']) : [];
                    $remove = array_values(array_filter(array_map(function($u){
                        $u = trim((string)$u);
                        return $u !== '' ? esc_url_raw($u) : '';
                    }, $remove)));

                    $replace_all = !empty($_POST['saving_images_replace_all']);

                    $current = method_exists($this, 'simku_saving_attachments_get_urls') ? (array)$this->simku_saving_attachments_get_urls($line_id) : [];

                    if ($replace_all) {
                        $next = $uploaded_urls;
                    } else {
                        $next = $current;

                        if (!empty($remove)) {
                            $rm = array_flip($remove);
                            $next = array_values(array_filter($next, function($u) use ($rm) {
                                $u = (string)$u;
                                return $u !== '' && !isset($rm[$u]);
                            }));
                        }

                        if (!empty($uploaded_urls)) {
                            $next = array_values(array_unique(array_merge($next, $uploaded_urls)));
                        }
                    }

                    if (empty($next)) {
                        if (method_exists($this, 'simku_saving_attachments_delete_by_saving_line')) {
                            $this->simku_saving_attachments_delete_by_saving_line($line_id);
                        }
                    } else {
                        $this->simku_saving_attachments_upsert_set($line_id, $next);
                    }
                }

                $redir_args = [
                    'page' => $return_page ?: 'fl-savings',
                    'updated' => 1,
                ];
                if ($return_from) $redir_args['from'] = $return_from;
                if ($return_to) $redir_args['to'] = $return_to;

                wp_safe_redirect(add_query_arg($redir_args, admin_url('admin.php')));
                exit;
            }

            wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=update_failed'));
            exit;
        }

        // Create
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

                // Apply Budget Target allocation (optional)
                if (method_exists($this, 'simku_goal_alloc_upsert') && method_exists($this, 'simku_goal_alloc_delete_by_saving_line')) {
                    if ((int)$budget_goal_id > 0) {
                        $this->simku_goal_alloc_upsert($line_id, (int)$budget_goal_id, (int)$amount, $saved_at, $user && !empty($user->user_login) ? $user->user_login : '');
                    } else {
                        $this->simku_goal_alloc_delete_by_saving_line($line_id);
                    }
                }

                // Savings attachments (multiple images)
                // Supports:
                // - upload multiple images
                // - remove selected existing images (edit mode)
                // - replace all existing images (edit mode)
                if (method_exists($this, 'handle_multi_image_uploads') && method_exists($this, 'simku_saving_attachments_upsert_set')) {
                    $uploaded_urls = (array)$this->handle_multi_image_uploads('saving_images');

                    $remove = isset($_POST['saving_images_remove']) ? (array)wp_unslash($_POST['saving_images_remove']) : [];
                    $remove = array_values(array_filter(array_map(function($u){
                        $u = trim((string)$u);
                        return $u !== '' ? esc_url_raw($u) : '';
                    }, $remove)));

                    $replace_all = !empty($_POST['saving_images_replace_all']);

                    $current = method_exists($this, 'simku_saving_attachments_get_urls') ? (array)$this->simku_saving_attachments_get_urls($line_id) : [];

                    if ($replace_all) {
                        $next = $uploaded_urls;
                    } else {
                        $next = $current;

                        if (!empty($remove)) {
                            $rm = array_flip($remove);
                            $next = array_values(array_filter($next, function($u) use ($rm) {
                                $u = (string)$u;
                                return $u !== '' && !isset($rm[$u]);
                            }));
                        }

                        if (!empty($uploaded_urls)) {
                            $next = array_values(array_unique(array_merge($next, $uploaded_urls)));
                        }
                    }

                    if (empty($next)) {
                        if (method_exists($this, 'simku_saving_attachments_delete_by_saving_line')) {
                            $this->simku_saving_attachments_delete_by_saving_line($line_id);
                        }
                    } else {
                        $this->simku_saving_attachments_upsert_set($line_id, $next);
                    }
                }

            wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&created=1'));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=fl-add-saving&err=save_failed'));
        exit;
    }

    /**
     * Export a CSV template for reminders import.
     *
     * Triggered from the Add Reminder page via:
     * admin.php?page=fl-add-reminder&fl_export_reminder_template=1&_wpnonce=...
     */
    public function handle_export_reminder_template() {
        if (!is_admin()) return;
        if (!current_user_can(self::CAP_MANAGE_TX)) return;

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== 'fl-add-reminder') return;

        if (empty($_GET['fl_export_reminder_template'])) return;

        check_admin_referer('fl_export_reminder_template');

        if (headers_sent()) return;

        $filename = 'simku-reminders-template.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if (!$out) exit;

        // Headers supported by the importer.
        fputcsv($out, [
            'payment_name',
            'installment_amount',
            'installments_total',
            'due_date',
            'schedule_mode',
            'due_day',
            'payee',
            'notes',
            'status',
            'notify_telegram',
            'notify_whatsapp',
            'notify_email'
        ]);

        // Example row.
        fputcsv($out, [
            'Motor Installment',
            '1500000',
            '12',
            wp_date('Y-m-d'),
            'manual',
            '15',
            'Leasing / Bank',
            'Optional notes',
            'belum',
            '1',
            '0',
            '0'
        ]);

        fclose($out);
        exit;
    }

function handle_export_transaction_template() {
    if (!is_admin()) return;
    if (empty($_GET['fl_export_transaction_template'])) return;
    if (!current_user_can(self::CAP_MANAGE_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

    // Output CSV template for bulk transaction import.
    $filename = 'simku-transaction-template.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');

    $out = fopen('php://output', 'w');
    // Header row (supported headers)
    fputcsv($out, [
        'user','line_id','transaction_id','nama_toko','items','quantity','harga','kategori',
        'tanggal_input','purchase_date','receive_date','tanggal_struk','gambar_url','description'
    ]);

    // Example row (edit/remove as needed)
    fputcsv($out, [
        'administrator','','','FamilyMart','Coffee',1,25000,'expense',
        '2026-02-15 10:15:00','2026-02-15','','','https://example.com/receipt.jpg','Example transaction'
    ]);

    fclose($out);
    exit;
}


    /* -------------------- Activation / Deactivation -------------------- */

    public static function activate() {
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

    private static function do_activate() {
        // Add role + caps
        add_role('finance_manager', 'Finance Manager', [
            'read' => true,
            self::CAP_VIEW_TX => true,
            self::CAP_MANAGE_TX => true,
            self::CAP_VIEW_REPORTS => true,
            self::CAP_MANAGE_BUDGETS => true,
            self::CAP_MANAGE_SETTINGS => true,
            self::CAP_VIEW_LOGS => true,
        ]);

        $admin = get_role('administrator');
        if ($admin) {
            foreach ([self::CAP_VIEW_TX, self::CAP_MANAGE_TX, self::CAP_VIEW_REPORTS, self::CAP_MANAGE_BUDGETS, self::CAP_MANAGE_SETTINGS, self::CAP_VIEW_LOGS] as $cap) {
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
    request_id VARCHAR(40) NULL,
    user_agent VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY created_at (created_at),
    KEY action (action),
    KEY object_type (object_type),
    KEY object_id (object_id),
    KEY user_id (user_id),
    KEY request_id (request_id)
) {$charset};";
$use_dbdelta ? dbDelta($sql) : $wpdb->query($sql);

        
// Create internal transactions table
$tx_table = $wpdb->prefix . 'fl_transactions';
$tx_sql = "CREATE TABLE {$tx_table} (
    line_id VARCHAR(80) NOT NULL,
    transaction_id VARCHAR(64) NOT NULL,
    split_group VARCHAR(64) NULL,
    tags TEXT NULL,
    counterparty VARCHAR(255) NULL,
    items VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    price BIGINT NOT NULL,
    category VARCHAR(20) NULL,
    entry_date DATETIME NOT NULL,
    receipt_date DATE NULL,
    purchase_date DATE NULL,
    receive_date DATE NULL,
    image_url TEXT NULL,
    description LONGTEXT NULL,
    wp_user_id BIGINT UNSIGNED NULL,
    wp_user_login VARCHAR(60) NULL,
    PRIMARY KEY (line_id),
    KEY transaction_id (transaction_id),
    KEY split_group (split_group),
    KEY category (category),
    KEY receipt_date (receipt_date),
    KEY purchase_date (purchase_date),
    KEY receive_date (receive_date)
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

    public static function deactivate() {
        wp_clear_scheduled_hook('fl_check_limits_hourly');
        wp_clear_scheduled_hook('simak_check_payment_reminders_hourly');
    }

    /**
     * If activation failed (but we caught the error to avoid white-screen), show the reason.
     * This helps users fix missing dependencies / permissions quickly.
     */
    public function admin_notice_activation_error() {
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
        echo '<p><strong>WP SIMKU:</strong> The plugin encountered an error during activation. Some features may be incomplete until this is fixed.</p>';
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

    private static function default_settings() {
        return [
            // Default to Internal (WP DB) so the plugin works out-of-the-box.
            // External mode requires a pre-created table and DB credentials.
            'datasource_mode' => 'internal', // internal|external
            'external' => [
                'host' => '127.0.0.1',
                'db' => '',
                'user' => '',
                'pass' => '',
                // Keep external table default aligned with internal base name.
                // (Users may still set this to any external table name.)
                'table' => 'fl_transactions',
                'allow_write' => 0,
            ],
            // Savings (Tabungan) datasource.
            // - same: follow Transactions datasource (external/internal)
            // - internal: always use WP DB table (wp_fl_savings)
            // - external: use the same external connection, but a different table
            'savings' => [
                'mode' => 'same',
                // Recommended external table name (no WP prefix). Older versions used finance_savings.
                'external_table' => 'fl_savings',
            ],

            // Payment reminders datasource.
            // - same: follow Transactions datasource (external/internal)
            // - internal: always use WP DB table (wp_fl_payment_reminders)
            // - external: use the same external connection, but a different table
            'reminders' => [
                'mode' => 'same',
                // Recommended external table name (no WP prefix). Older versions used finance_payment_reminders.
                'external_table' => 'fl_payment_reminders',
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
                'email_from' => '',
                'email_from_name' => '',
                // Recipient for *new transaction* emails: settings|user|both
                'email_new_to_mode' => 'settings',
                'email_notify_new_tx_default' => 0,
                // Templates for "new transaction" notifications
                // Supported placeholders: {user}, {category}, {counterparty}, {item}, {qty}, {price}, {total},
                // {entry_date}, {purchase_date}, {transaction_id}, {line_id}, {receipt_url}, {description}
                'email_new_subject_tpl' => 'New transaction: {item} (Rp {total})',
                'email_new_body_tpl' => "New transaction created\n"
                    . "User: {user}\n"
                    . "Category: {category}\n"
                    . "Counterparty: {counterparty}\n"
                    . "Item: {item}\n"
                    . "Qty: {qty}\n"
                    . "Price: {price}\n"
                    . "Total: Rp {total}\n"
                    . "Entry date: {entry_date}\n"
                    . "transaction_id: {transaction_id}\n"
                    . "line_id: {line_id}\n"
                    . "Image: {receipt_url}\n"
                    . "Description: {description}\n",
                'telegram_enabled' => 0,
                'telegram_bot_token' => '',
                'telegram_chat_id' => '',
                'telegram_allow_insecure_tls' => 0,
                'telegram_notify_new_tx_default' => 1,
                'telegram_new_tpl' => "✅ <b>New transaction</b>\n"
                    . "User: <b>{user}</b>\n"
                    . "Category: <b>{category}</b>\n"
                    . "Counterparty: {counterparty}\n"
                    . "Item: {item}\n"
                    . "Qty: {qty}\n"
                    . "Price: {price}\n"
                    . "Total: <b>Rp {total}</b>\n"
                    . "Entry date: {entry_date}\n"
                    . "{receipt_url}",
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

            'integrations' => [
                // REST API key: sent via header X-SIMKU-KEY or query api_key
                'rest_api_key' => '',

                // Security toggles
                'allow_query_api_key' => 0,
                'allow_http_webhooks' => 0,

                // Secure inbound webhook (HMAC signed)
                'inbound_enabled' => 0,
                'inbound_secret' => '',
                'inbound_tolerance_sec' => 300,
                'inbound_rate_limit_per_min' => 60,
                // HTTPS only (recommended). Keep HTTP disabled unless explicitly needed.
                'inbound_allow_http' => 0,

                // Outbound webhooks
                'webhooks_enabled' => 0,
                'webhook_secret' => '',
                'webhook_urls' => '',
                'google_sheets_webhook_url' => '',
                'webhook_timeout' => 12,

                // Event filters (1=enabled, 0=disabled)
                'webhook_events' => [
                    'transaction.created' => 1,
                    'transaction.updated' => 1,
                    'transaction.deleted' => 1,
                    'budget.upserted' => 1,
                    'budget.deleted' => 1,
                ],
            ],

            
'receipts' => [
    // Where to store receipt images for Transactions:
    // - uploads: wp-content/uploads (public URL)
    // - gdrive : Google Drive folder (private; streamed via WP proxy)
    'storage' => 'uploads',
    'gdrive_folder_id' => '',
    // Store full Service Account JSON (private_key included).
    // Consider defining it via wp-config.php constant and leaving this blank.
    'gdrive_service_account_json' => '',
    'delete_local_after_upload' => 1,
    'max_upload_mb' => 8,
],

'chat' => [
                // Telegram inbound chat to create transactions
                'telegram_in_enabled' => 0,
                'telegram_webhook_secret' => '',
                'telegram_allow_query_secret' => 0,
                'telegram_allowed_chat_ids' => '',
                'telegram_bot_token' => '',
            ],
        ];
    }

    /**
     * Very small template engine for notification messages.
     * - Replaces {placeholders} using provided context.
     * - Removes unreplaced placeholders.
     */
    private function render_tpl($tpl, $ctx) {
        $out = $tpl;
        // Support "\\n" sequences entered in textarea as new lines.
        $out = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $out);
// Backward-compatible placeholder aliases (ID -> EN).
// Existing installations may still use Indonesian placeholders in templates.
$aliases = [
    'kategori' => 'category',
    'toko' => 'counterparty',
    'harga' => 'price',
    'tanggal_input' => 'entry_date',
    'tanggal_struk' => 'purchase_date',
    'gambar_url' => 'receipt_url',
];
foreach ($aliases as $id => $en) {
    if (!array_key_exists($en, $ctx) && array_key_exists($id, $ctx)) {
        $ctx[$en] = $ctx[$id];
    }
}

        foreach ($ctx as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        // Remove any leftover {token}
        $out = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $out);
        // Normalize newlines
        $out = preg_replace("/\r\n?/", "\n", (string)$out);
        return trim((string)$out);
    }
    /* -------------------- Admin UI -------------------- */

    
    public function admin_menu() {
        // Make SIMKU visible for all logged-in users (Subscriber) so they can access Charts.
        // Other menus remain protected by their respective capabilities.
        $menu_title = __(self::PLUGIN_LONG_NAME, self::TEXT_DOMAIN);
        $menu_name  = __(self::PLUGIN_SHORT_NAME, self::TEXT_DOMAIN);

        add_menu_page(
            $menu_title,
            $menu_name,
            'read',
            'simku-keuangan',
            [$this, 'page_entry'],
            'dashicons-chart-line',
            56
        );

        // Finance dashboard & management menus (Finance Manager / Admin)
        add_submenu_page('simku-keuangan', __('Dashboard', self::TEXT_DOMAIN), __('Dashboard', self::TEXT_DOMAIN), self::CAP_VIEW_TX, 'fl-dashboard', [$this, 'page_dashboard']);
        add_submenu_page('simku-keuangan', __('Transactions', self::TEXT_DOMAIN), __('Transactions', self::TEXT_DOMAIN), self::CAP_VIEW_TX, 'fl-transactions', [$this, 'page_transactions']);
        add_submenu_page('simku-keuangan', __('Add Transaction', self::TEXT_DOMAIN), __('Add Transaction', self::TEXT_DOMAIN), self::CAP_MANAGE_TX, 'fl-add-transaction', [$this, 'page_add_transaction']);
        add_submenu_page('simku-keuangan', __('Scan Receipt', self::TEXT_DOMAIN), __('Scan Receipt', self::TEXT_DOMAIN), self::CAP_MANAGE_TX, 'fl-scan-struk', [$this, 'page_scan_struk']);

        // Savings (Tabungan)
        add_submenu_page('simku-keuangan', __('Savings', self::TEXT_DOMAIN), __('Savings', self::TEXT_DOMAIN), self::CAP_VIEW_TX, 'fl-savings', [$this, 'page_savings']);
        add_submenu_page('simku-keuangan', __('Add Saving', self::TEXT_DOMAIN), __('Add Saving', self::TEXT_DOMAIN), self::CAP_MANAGE_TX, 'fl-add-saving', [$this, 'page_add_saving']);

        // Payment Reminders (Installments/Billing)
        add_submenu_page('simku-keuangan', __('Reminders', self::TEXT_DOMAIN), __('Reminders', self::TEXT_DOMAIN), self::CAP_VIEW_TX, 'fl-reminders', [$this, 'page_reminders']);
        add_submenu_page('simku-keuangan', __('Add Reminder', self::TEXT_DOMAIN), __('Add Reminder', self::TEXT_DOMAIN), self::CAP_MANAGE_TX, 'fl-add-reminder', [$this, 'page_add_reminder']);

        add_submenu_page('simku-keuangan', __('Reports', self::TEXT_DOMAIN), __('Reports', self::TEXT_DOMAIN), self::CAP_VIEW_REPORTS, 'fl-reports', [$this, 'page_reports']);

        // Budgets (Budget vs Actual)
        add_submenu_page('simku-keuangan', __('Budgets', self::TEXT_DOMAIN), __('Budgets', self::TEXT_DOMAIN), self::CAP_VIEW_REPORTS, 'fl-budgets', [$this, 'page_budgets']);

        // Budget Target (Targets/Goals)
        add_submenu_page('simku-keuangan', __('Budget Target', self::TEXT_DOMAIN), __('Budget Target', self::TEXT_DOMAIN), self::CAP_VIEW_REPORTS, 'fl-budget-goals', [$this, 'page_budget_goals']);

        // Hidden page (no sidebar menu item) for Add/Edit Budget Target.
        // Using parent_slug = null registers the page so it remains accessible via URL,
        // without showing it in the sidebar.
        add_submenu_page('simku-keuangan', __('Add Budget Target', self::TEXT_DOMAIN), __('Add Budget Target', self::TEXT_DOMAIN), self::CAP_VIEW_REPORTS, 'fl-add-budgeting', [$this, 'page_add_budgeting']);

        // Charts: available to all logged-in users (Subscriber). "Public" charts are templates; data stays scoped to the current user.
        add_submenu_page('simku-keuangan', __('Charts', self::TEXT_DOMAIN), __('Charts', self::TEXT_DOMAIN), 'read', 'fl-charts', [$this, 'page_charts_list']);
        add_submenu_page('simku-keuangan', __('Add Chart', self::TEXT_DOMAIN), __('Add Chart', self::TEXT_DOMAIN), 'read', 'fl-add-chart', [$this, 'page_add_chart']);

        add_submenu_page('simku-keuangan', __('Logs', self::TEXT_DOMAIN), __('Logs', self::TEXT_DOMAIN), self::CAP_VIEW_LOGS, 'fl-logs', [$this, 'page_logs']);
        add_submenu_page('simku-keuangan', __('Settings', self::TEXT_DOMAIN), __('Settings', self::TEXT_DOMAIN), self::CAP_MANAGE_SETTINGS, 'fl-settings', [$this, 'page_settings']);

        // Remove the duplicate top-level submenu item (WordPress adds it automatically).
        remove_submenu_page('simku-keuangan', 'simku-keuangan');
        // Keep Add Budget Target registered so it is accessible via URL (needed for admin.php?page=fl-add-budgeting).
        // We hide it from the sidebar via CSS instead (see admin.css) to avoid WordPress parent-page resolution issues.

    }
     /**
      * Load plugin translations.
     */
    public function load_textdomain() {
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function admin_assets($hook) {
        if (strpos($hook, 'simku-keuangan') === false && strpos($hook, 'fl-') === false) return;

        wp_enqueue_style('fl-admin', plugins_url('assets/css/admin.css', __FILE__), [], self::VERSION);

        // Shared admin UI helpers (tooltips, etc.)
        wp_enqueue_script('fl-admin-ui', plugins_url('assets/js/admin-ui.js', __FILE__), ['jquery'], self::VERSION, true);

        // Extra layout helpers for Budgeting pages.
        if (strpos($hook, 'fl-budgets') !== false || strpos($hook, 'fl-budget-goals') !== false || strpos($hook, 'fl-add-budgeting') !== false) {
            wp_enqueue_style('fl-admin-budgeting', plugins_url('assets/css/budgeting.css', __FILE__), ['fl-admin'], self::VERSION);
        }

        wp_enqueue_script('echarts', 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js', [], self::VERSION, true);
        wp_enqueue_script('fl-admin-charts', plugins_url('assets/js/admin-charts.js', __FILE__), ['jquery','echarts'], self::VERSION, true);

        // Budget Target list: tiny % used bars (ECharts)
        if (strpos($hook, 'fl-budget-goals') !== false) {
            wp_enqueue_script('fl-admin-budget-goals', plugins_url('assets/js/admin-budget-goals.js', __FILE__), ['jquery','echarts'], self::VERSION, true);
        }

        // Budgets page: inline edit + tag toggle
        if (strpos($hook, 'fl-budgets') !== false) {
            wp_enqueue_script('fl-admin-budgets', plugins_url('assets/js/admin-budgets.js', __FILE__), ['jquery'], self::VERSION, true);
        }

        // Reminders page: notes modal
        if (strpos($hook, 'fl-reminders') !== false) {
            wp_enqueue_script('fl-admin-reminders', plugins_url('assets/js/admin-reminders.js', __FILE__), ['jquery'], self::VERSION, true);
        }

        // Reports page: export helpers + PDF layout controls
        if (strpos($hook, 'fl-reports') !== false) {
            // Needed for picking a PDF logo from Media Library
            if (function_exists('wp_enqueue_media')) { wp_enqueue_media(); }
            wp_enqueue_script('fl-admin-reports', plugins_url('assets/js/admin-reports.js', __FILE__), ['jquery'], self::VERSION, true);
        }

        
        // Scan Receipt page: date rules + line-items UI
        if (strpos($hook, 'fl-scan-struk') !== false) {
            wp_enqueue_script('fl-admin-scan', plugins_url('assets/js/admin-scan.js', __FILE__), ['jquery'], self::VERSION, true);
        }

// Savings pages: attachments modal + trend chart
        if (strpos($hook, 'fl-savings') !== false || strpos($hook, 'fl-add-saving') !== false) {
            wp_enqueue_script('fl-admin-savings', plugins_url('assets/js/admin-savings.js', __FILE__), ['jquery','echarts'], self::VERSION, true);
        }

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

    public function frontend_assets() {
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

        // Shared UI helpers
        wp_enqueue_script('fl-admin-ui', plugins_url('assets/js/admin-ui.js', __FILE__), ['jquery'], self::VERSION, true);

        wp_enqueue_script('echarts', 'https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js', [], self::VERSION, true);
        wp_enqueue_script('fl-frontend', plugins_url('assets/js/frontend-charts.js', __FILE__), ['jquery','echarts'], self::VERSION, true);
        wp_localize_script('fl-frontend', 'SIMAK_AJAX', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fl_nonce'),
        ]);
    }

    private function settings() {
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


        // Backward compatible: ensure integrations/chat keys exist.
        $def_integ = (array)(self::default_settings()['integrations'] ?? []);
        if (!isset($s['integrations']) || !is_array($s['integrations'])) {
            $s['integrations'] = $def_integ;
        } else {
            foreach ($def_integ as $k=>$v) {
                if (!array_key_exists($k, $s['integrations'])) $s['integrations'][$k] = $v;
            }
        }
        // Deep-merge webhook_events
        if (!isset($s['integrations']['webhook_events']) || !is_array($s['integrations']['webhook_events'])) {
            $s['integrations']['webhook_events'] = (array)($def_integ['webhook_events'] ?? []);
        } else {
            foreach ((array)($def_integ['webhook_events'] ?? []) as $ek=>$ev) {
                if (!array_key_exists($ek, $s['integrations']['webhook_events'])) {
                    $s['integrations']['webhook_events'][$ek] = $ev;
                }
            }
        }

        // If URLs are set but enabled flag is still off, auto-enable (common expectation).
        $urls_raw = trim((string)($s['integrations']['webhook_urls'] ?? ''));
        $gs_raw = trim((string)($s['integrations']['google_sheets_webhook_url'] ?? ''));
        if (empty($s['integrations']['webhooks_enabled']) && ($urls_raw !== '' || $gs_raw !== '')) {
            $s['integrations']['webhooks_enabled'] = 1;
        }

        // Backward compatible: ensure notify keys exist.
        $def_notify = (array)(self::default_settings()['notify'] ?? []);
        if (!isset($s['notify']) || !is_array($s['notify'])) {
            $s['notify'] = $def_notify;
        } else {
            foreach ($def_notify as $k=>$v) {
                if (!array_key_exists($k, $s['notify'])) $s['notify'][$k] = $v;
            }
        }

        // Backward compatible: ensure receipts keys exist.
        $def_receipts = (array)(self::default_settings()['receipts'] ?? []);
        if (!isset($s['receipts']) || !is_array($s['receipts'])) {
            $s['receipts'] = $def_receipts;
        } else {
            foreach ($def_receipts as $k=>$v) {
                if (!array_key_exists($k, $s['receipts'])) $s['receipts'][$k] = $v;
            }
        }

        $def_chat = (array)(self::default_settings()['chat'] ?? []);
        if (!isset($s['chat']) || !is_array($s['chat'])) {
            $s['chat'] = $def_chat;
        } else {
            foreach ($def_chat as $k=>$v) {
                if (!array_key_exists($k, $s['chat'])) $s['chat'][$k] = $v;
            }
        }

        return $s;
    }

    private function update_settings($s) {
        update_option(self::OPT_SETTINGS, $s, false);
    }


/**
 * Enforce HTTPS for outbound endpoints by default.
 * - Allows https:// always
 * - Allows http:// only if Settings → Integrations → "Allow HTTP webhook URLs" is enabled
 */
private function simku_url_allows_http($url) {
    $url = trim((string)$url);
    if ($url === '') return false;

    $p = wp_parse_url($url);
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme === 'https') return true;
    if ($scheme === 'http') {
        $s = $this->settings();
        return !empty(($s['integrations']['allow_http_webhooks'] ?? 0));
    }
    return false;
}



    /* -------------------- Category helpers -------------------- */

    private function normalize_category($cat) {
        $cat = strtolower(trim($cat));
        // v0.5.38: rename outcome -> expense (keep backward compatibility)
        if ($cat === 'outcome') return 'expense';
        return $cat;
    }

    /**
     * Expand filters so selecting "expense" still matches legacy "outcome" rows.
     */
    private function expand_category_filter($cats) {
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

    private function category_label($cat) {
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
    private function pretty_dim_label($v) {
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
    private function simku_storage_tz() {
        return wp_timezone();
    }

    private function simku_display_tz() {
        // Follow WordPress site timezone for display as well.
        // (datetime-local input also represents local time in this timezone.)
        $tz = wp_timezone();
        return $tz ?: new \DateTimeZone('UTC');
    }

    private function fmt_mysql_dt_display($dt, $format = 'Y-m-d H:i:s') {
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

    private function dtlocal_value_from_mysql($dt) {
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
    private function mysql_from_ui_datetime($val) {
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
     /**
      * Ensure default charts exist after upgrades / option resets.
      * This prevents dashboard "Request failed" when chart defs are missing.
     */
    public function maybe_seed_defaults() {
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
     * Ensure required role capabilities exist after plugin updates.
     * Activation hook may not run on update, so we add missing caps here (idempotent).
     */
    public function ensure_role_caps() {
        if (!function_exists('get_role')) return;

        $caps = [
            self::CAP_VIEW_TX,
            self::CAP_MANAGE_TX,
            self::CAP_VIEW_REPORTS,
            self::CAP_MANAGE_BUDGETS,
            self::CAP_MANAGE_SETTINGS,
            self::CAP_VIEW_LOGS,
        ];

        $admin = get_role('administrator');
        if ($admin) {
            foreach ($caps as $cap) {
                $admin->add_cap($cap);
            }
        }

        // Custom finance manager role (created on activation, but keep it consistent).
        $mgr = get_role('finance_manager');
        if (!$mgr && function_exists('add_role')) {
            $mgr = add_role('finance_manager', 'Finance Manager', ['read' => true]);
        }
        if ($mgr) {
            foreach ($caps as $cap) {
                $mgr->add_cap($cap);
            }
        }
    }





/**
 * Add optional image column to INTERNAL payment reminders table.
 * Runs on admin loads via maybe_seed_defaults so updates don't require deactivate/activate.
 */
private function maybe_add_internal_reminders_image_column() {
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

    /* -------------------- Transactions schema migrations -------------------- */

    /**
     * Keep the internal Transactions table schema compatible after plugin updates.
     * - Adds purchase_date / receive_date columns (DATE NULL)
     * - Normalizes legacy category name outcome -> expense
     * - Backfills new date columns from tanggal_struk
     *
     * This runs on admin loads because plugin updates may not trigger activation hooks.
     */
    public function maybe_migrate_transactions_schema() {
    // Only internal WP DB tables are modified.
    if ($this->ds_is_external()) return;

    // Capabilities: allow finance managers / admins who can manage transactions.
    if (!current_user_can(self::CAP_MANAGE_TX) && !current_user_can(self::CAP_MANAGE_SETTINGS)) return;

    global $wpdb;

    // Date migration (legacy) is heavy and should run only once.
    $opt_dates = 'simku_migrated_tx_dates_v1';
    $dates_done = (get_option($opt_dates, '') === '1');

    // Candidate internal tables.
    $s = $this->settings();
    $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($s['external']['table'] ?? ''));
    $candidates = array_values(array_unique(array_filter([
        $t,
        $t ? ($wpdb->prefix . $t) : '',
        $wpdb->prefix . 'finance_transactions',
        $wpdb->prefix . 'fl_transactions',
    ])));

    foreach ($candidates as $table) {
        if (!$table) continue;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) continue;

        // Detect schema (legacy Indonesian vs English) per-table.
        $cat_col     = $this->tx_col('kategori', $wpdb, $table);
        $receipt_col = $this->tx_col('tanggal_struk', $wpdb, $table);
        $has_cat     = $this->ds_column_exists($cat_col, $wpdb, $table);
        $has_receipt = $this->ds_column_exists($receipt_col, $wpdb, $table);

        // Always ensure required columns exist (do NOT gate by $dates_done).
        $alter_parts = [];
        $has_purchase = $this->ds_column_exists('purchase_date', $wpdb, $table);
        $has_receive  = $this->ds_column_exists('receive_date', $wpdb, $table);
        $has_tags     = $this->ds_column_exists($this->tx_col('tags', $wpdb, $table), $wpdb, $table);
        $has_split    = $this->ds_column_exists($this->tx_col('split_group', $wpdb, $table), $wpdb, $table);

        if (!$has_purchase) $alter_parts[] = "ADD COLUMN `purchase_date` DATE NULL";
        if (!$has_receive)  $alter_parts[] = "ADD COLUMN `receive_date` DATE NULL";
        if (!$has_tags)     $alter_parts[] = "ADD COLUMN `tags` TEXT NULL";
        if (!$has_split)    $alter_parts[] = "ADD COLUMN `split_group` VARCHAR(64) NULL";

        if (!empty($alter_parts)) {
            // Place new columns after receipt date if possible (keeps schema tidy).
            $after = $has_receipt ? " AFTER `{$receipt_col}`" : '';

            $first = array_shift($alter_parts);
            $sql = "ALTER TABLE `{$table}` {$first}" . ($after ? $after : '');
            if (!empty($alter_parts)) {
                $sql .= ', ' . implode(', ', $alter_parts);
            }
            $wpdb->query($sql);
        }

        // Ensure split_group index (best-effort).
        if ($this->ds_column_exists('split_group', $wpdb, $table)) {
            $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", 'split_group'));
            if (!$idx) {
                $wpdb->query("ALTER TABLE `{$table}` ADD KEY `split_group` (`split_group`)");
            }
        }

        // Backfill split_group = transaction_id (best-effort) if both columns exist.
        if ($this->ds_column_exists('split_group', $wpdb, $table) && $this->ds_column_exists('transaction_id', $wpdb, $table)) {
            $wpdb->query("UPDATE `{$table}` SET `split_group` = `transaction_id` WHERE (`split_group` IS NULL OR `split_group` = '') AND `transaction_id` IS NOT NULL AND `transaction_id` <> ''");
        }

        // Heavy legacy date migration (run once).
        if (!$dates_done) {
            // Normalize legacy outcome -> expense.
            if ($has_cat) {
                $wpdb->query("UPDATE `{$table}` SET `{$cat_col}` = 'expense' WHERE TRIM(LOWER(`{$cat_col}`)) = 'outcome'");
            }

            // Backfill new date columns from receipt date.
            $has_purchase = $this->ds_column_exists('purchase_date', $wpdb, $table);
            $has_receive  = $this->ds_column_exists('receive_date', $wpdb, $table);
            if ($has_receipt && $has_cat) {
                // - Expense rows: purchase_date = receipt
                // - Income rows : receive_date  = receipt
                if ($has_purchase) {
                    $wpdb->query("UPDATE `{$table}` SET `purchase_date` = `{$receipt_col}` WHERE `purchase_date` IS NULL AND `{$receipt_col}` IS NOT NULL AND `{$receipt_col}` <> '0000-00-00' AND TRIM(LOWER(`{$cat_col}`)) = 'expense'");
                }
                if ($has_receive) {
                    $wpdb->query("UPDATE `{$table}` SET `receive_date` = `{$receipt_col}` WHERE `receive_date` IS NULL AND `{$receipt_col}` IS NOT NULL AND `{$receipt_col}` <> '0000-00-00' AND TRIM(LOWER(`{$cat_col}`)) = 'income'");
                }

                // Keep receipt date consistent going forward.
                if ($has_purchase) {
                    $wpdb->query("UPDATE `{$table}` SET `{$receipt_col}` = `purchase_date` WHERE (`{$receipt_col}` IS NULL OR `{$receipt_col}` = '' OR `{$receipt_col}` = '0000-00-00') AND `purchase_date` IS NOT NULL AND TRIM(LOWER(`{$cat_col}`)) = 'expense'");
                }
                if ($has_receive) {
                    $wpdb->query("UPDATE `{$table}` SET `{$receipt_col}` = `receive_date` WHERE (`{$receipt_col}` IS NULL OR `{$receipt_col}` = '' OR `{$receipt_col}` = '0000-00-00') AND `receive_date` IS NOT NULL AND TRIM(LOWER(`{$cat_col}`)) = 'income'");
                }
            }
        }
    }

    // Mark date migration done (safe even if no candidates found; avoids repeated heavy work).
    if (!$dates_done) {
        update_option($opt_dates, '1', false);
    }
}


    /* -------------------- Logs schema migrations -------------------- */

    /**
     * Keep internal Logs table schema compatible after updates.
     * Adds request_id + user_agent columns and indexes (if missing).
     */
    public function maybe_migrate_logs_schema() {
        // Only admins / settings managers should run this.
        if (!current_user_can(self::CAP_MANAGE_SETTINGS) && !current_user_can(self::CAP_VIEW_LOGS)) return;

        $opt_key = 'simku_migrated_logs_v2';
        if (get_option($opt_key, '') === '1') return;

        global $wpdb;
        if (!($wpdb instanceof wpdb)) return;

        $table = $wpdb->prefix . 'fl_logs';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { update_option($opt_key, '1', false); return; }

        $has_request = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'request_id'));
        $has_ua      = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'user_agent'));
        $has_user_id = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'user_id'));

        $alter = [];
        if (!$has_request) $alter[] = "ADD COLUMN `request_id` VARCHAR(40) NULL";
        if (!$has_ua)      $alter[] = "ADD COLUMN `user_agent` VARCHAR(255) NULL";

        if (!empty($alter)) {
            // Put after ip if possible.
            $after = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ip')) ? " AFTER `ip`" : '';
            $first = array_shift($alter);
            $sql = "ALTER TABLE `{$table}` {$first}" . $after;
            if (!empty($alter)) $sql .= ', ' . implode(', ', $alter);
            $wpdb->query($sql);
        }

        // Add indexes if missing.
        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $idx_names = [];
        foreach ((array)$indexes as $ix) {
            if (!empty($ix['Key_name'])) $idx_names[$ix['Key_name']] = true;
        }
        if ($has_user_id && empty($idx_names['user_id'])) {
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY `user_id` (`user_id`)");
        }
        if (!empty($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'request_id'))) && empty($idx_names['request_id'])) {
            $wpdb->query("ALTER TABLE `{$table}` ADD KEY `request_id` (`request_id`)");
        }

        update_option($opt_key, '1', false);
    }

    /**
     * Internal reminders table was initially created with line_id as PRIMARY KEY.
     * Newer versions use an auto-increment numeric id as PRIMARY KEY (and keep line_id UNIQUE).
     *
     * This migrates ONLY the internal WP DB table. External reminders tables are not modified.
     */
    private function maybe_migrate_internal_reminders_schema() {
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
/**
 * Column mapping for transactions table.
 * Keeps legacy (Indonesian) keys for UI, but supports English DB schemas by aliasing/mapping.
 */
private function tx_schema($db = null, $table = null) {
    if (!($db instanceof wpdb)) $db = $this->ds_db();
    if (!($db instanceof wpdb)) return [];
    if (!$table) $table = $this->ds_table();

    static $schema_cache = [];
    $cache_key = (function_exists('spl_object_hash') ? spl_object_hash($db) : ((string)$db->dbname)) . '|' . (string)$table;
    if (isset($schema_cache[$cache_key]) && is_array($schema_cache[$cache_key])) return $schema_cache[$cache_key];

    $candidates = [
        // canonical => [preferred legacy, english alternatives...]
        'nama_toko'     => ['nama_toko','counterparty','merchant','payee','store_name'],
        'kategori'      => ['kategori','category','type'],
        'harga'         => ['harga','price','amount','unit_price'],
        'tanggal_input' => ['tanggal_input','entry_date','created_at','input_date'],
        'tanggal_struk' => ['tanggal_struk','receipt_date','transaction_date','receiptDate'],
        'gambar_url'    => ['gambar_url','image_url','image','photo_url'],
        'tags'          => ['tags','tag','labels','label'],
        'split_group'   => ['split_group','group_id','split_id','split'],
        // commonly stable, but keep a fallback
        'items'         => ['items','item','product'],
        'quantity'      => ['quantity','qty'],
    ];

    $out = [];
    foreach ($candidates as $canonical => $opts) {
        $picked = $opts[0];
        foreach ($opts as $cand) {
            $cand = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$cand);
            if ($cand && $this->ds_column_exists($cand, $db, $table)) { $picked = $cand; break; }
        }
        $out[$canonical] = $picked;
    }

    $schema_cache[$cache_key] = $out;
    return $out;
}

private function tx_col($canonical, $db = null, $table = null) {
    $schema = $this->tx_schema($db, $table);
    $col = $schema[$canonical] ?? $canonical;
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
    return $col ?: $canonical;
}

private function tx_desc_col($db = null, $table = null) {
    if (!($db instanceof wpdb)) $db = $this->ds_db();
    if (!($db instanceof wpdb)) return null;
    if (!$table) $table = $this->ds_table();

    foreach (['description','note','desc'] as $cand) {
        if ($this->ds_column_exists($cand, $db, $table)) return $cand;
    }
    return null;
}

/**
 * Map canonical (legacy) transaction keys into the actual DB column names for INSERT/UPDATE.
 * Preserves array key order so the corresponding $formats array remains valid.
 */
private function tx_map_write_data($data, $db = null, $table = null) {
    if (!($db instanceof wpdb)) $db = $this->ds_db();
    if (!($db instanceof wpdb)) return $data;
    if (!$table) $table = $this->ds_table();

    $map = [
        'nama_toko'     => $this->tx_col('nama_toko', $db, $table),
        'kategori'      => $this->tx_col('kategori', $db, $table),
        'harga'         => $this->tx_col('harga', $db, $table),
        'tanggal_input' => $this->tx_col('tanggal_input', $db, $table),
        'tanggal_struk' => $this->tx_col('tanggal_struk', $db, $table),
        'gambar_url'    => $this->tx_col('gambar_url', $db, $table),
        'items'         => $this->tx_col('items', $db, $table),
        'quantity'      => $this->tx_col('quantity', $db, $table),
    ];

    // Optional columns (older installs may not have them yet).
    $tags_col = $this->tx_col('tags', $db, $table);
    if ($tags_col && $this->ds_column_exists($tags_col, $db, $table)) {
        $map['tags'] = $tags_col;
    }
    $split_col = $this->tx_col('split_group', $db, $table);
    if ($split_col && $this->ds_column_exists($split_col, $db, $table)) {
        $map['split_group'] = $split_col;
    }

    $desc_col = $this->tx_desc_col($db, $table);
    if ($desc_col) $map['description'] = $desc_col;

    $out = [];
    foreach ($data as $k => $v) {
        // Skip optional fields if the target column does not exist (safety).
        if ($k === 'tags' && empty($map['tags'])) continue;
        if ($k === 'split_group' && empty($map['split_group'])) continue;

        $new_key = $map[$k] ?? $k;
        $out[$new_key] = $v;
    }
    return $out;
}

/**
 * Build wpdb formats array for INSERT/UPDATE aligned with $write_data key order.
 * Avoids type/column mismatches (especially after schema changes).
 */
private function tx_build_write_formats($write_data) {
    if (!is_array($write_data)) return [];
    $int_keys = ['quantity','qty','harga','price','wp_user_id','user_id'];
    $formats = [];
    foreach ($write_data as $k => $v) {
        if (in_array($k, $int_keys, true)) {
            $formats[] = '%d';
        } else {
            $formats[] = '%s';
        }
    }
    return $formats;
}




/**
 * Map canonical (legacy) transaction keys into the actual DB column names for INSERT/UPDATE.
 * Preserves array key order so the corresponding $formats array remains valid.
 */

private function tx_get_row_for_ui($db, $table, $line_id) {
    if (!($db instanceof wpdb)) return null;

    $party_col   = $this->tx_col('nama_toko', $db, $table);
    $cat_col     = $this->tx_col('kategori', $db, $table);
    $price_col   = $this->tx_col('harga', $db, $table);
    $entry_col   = $this->tx_col('tanggal_input', $db, $table);
    $receipt_col = $this->tx_col('tanggal_struk', $db, $table);
    $img_col     = $this->tx_col('gambar_url', $db, $table);
    $items_col   = $this->tx_col('items', $db, $table);
    $qty_col     = $this->tx_col('quantity', $db, $table);
    $desc_col    = $this->tx_desc_col($db, $table);

    $receipt_sel = ($receipt_col && $this->ds_column_exists($receipt_col, $db, $table)) ? "`{$receipt_col}` AS tanggal_struk" : "NULL AS tanggal_struk";
    $img_sel     = ($img_col && $this->ds_column_exists($img_col, $db, $table)) ? "`{$img_col}` AS gambar_url" : "NULL AS gambar_url";
    $desc_sel    = $desc_col ? "`{$desc_col}` AS description" : "NULL AS description";
    $purchase_sel = $this->ds_column_exists('purchase_date', $db, $table) ? "purchase_date AS purchase_date" : "NULL AS purchase_date";
    $receive_sel  = $this->ds_column_exists('receive_date', $db, $table) ? "receive_date AS receive_date" : "NULL AS receive_date";
    $uid_sel      = $this->ds_column_exists('wp_user_id', $db, $table) ? "wp_user_id AS wp_user_id" : "NULL AS wp_user_id";
    $ulog_sel     = $this->ds_column_exists('wp_user_login', $db, $table) ? "wp_user_login AS wp_user_login" : "NULL AS wp_user_login";

    $tags_sel     = $this->ds_column_exists('tags', $db, $table) ? "tags AS tags" : "NULL AS tags";
    $split_sel    = $this->ds_column_exists('split_group', $db, $table) ? "split_group AS split_group" : "NULL AS split_group";

    $sql = "SELECT line_id, transaction_id,
                   `{$party_col}` AS nama_toko,
                   `{$items_col}` AS items,
                   `{$qty_col}` AS quantity,
                   `{$price_col}` AS harga,
                   `{$cat_col}` AS kategori,
                   `{$entry_col}` AS tanggal_input,
                   {$receipt_sel},
                   {$purchase_sel},
                   {$receive_sel},
                   {$img_sel},
                   {$desc_sel},
                   {$uid_sel},
                   {$ulog_sel},
                   {$tags_sel},
                   {$split_sel}
            FROM `{$table}` WHERE line_id = %s LIMIT 1";

    return $db->get_row($db->prepare($sql, $line_id), ARRAY_A);
}
private function reminders_column_exists($col) {
    global $wpdb;
    $table = $this->reminders_table();
    $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
    $row = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'", ARRAY_A);
    return !empty($row);
}

    private function ext_column_exists($col) {
        $db = $this->ext_db();
        if (!$db) return false;
        $table = $this->ds_table();
        $col = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
        $row = $db->get_row($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $col));
        return !empty($row);
    }

    private function ensure_external_user_columns() {
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
    private function handle_tx_image_upload($field_name) {
        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['tmp_name'])) {
            return ['ok' => false, 'url' => '', 'error' => ''];
        }

        $file = $_FILES[$field_name];
        if (!empty($file['error'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Upload gagal (kode error: '.$file['error'].').'];
        }

        if (!empty($file['size'])) {
            $max = method_exists($this, 'receipts_max_upload_bytes') ? (int)$this->receipts_max_upload_bytes() : (8 * 1024 * 1024);
            if ((int)$file['size'] > $max) {
                return ['ok' => false, 'url' => '', 'error' => 'File terlalu besar. Maksimal ' . (int)($max/1024/1024) . 'MB.'];
            }
        }

        // Only allow images.
        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);
        if (empty($check['type'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Tipe file tidak valid. Hanya gambar (JPG/PNG/GIF/WebP) yang diizinkan.'];
        }

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
private function normalize_images_field($val) {
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
 * Receipt media struct (URLs + Google Drive items).
 *
 * Backward compatible with older storage:
 * - '' or null      => ['urls'=>[],'gdrive'=>[]]
 * - 'https://...'   => urls=[...]
 * - JSON array      => urls=[...]
 * - JSON object     => {urls:[...], gdrive:[{id,view,mime}]}
 */
private function receipt_media_from_db_value($val) {
    $out = ['urls' => [], 'gdrive' => []];

    if (is_array($val)) {
        // Might be list of urls/tokens from form.
        foreach ($val as $it) {
            if (!is_string($it)) continue;
            $it = trim($it);
            if ($it === '') continue;
            if (stripos($it, 'gdrive:') === 0) {
                $id = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($it, 7));
                if ($id) $out['gdrive'][] = ['id'=>$id];
            } else {
                $out['urls'][] = $it;
            }
        }
        $out['urls'] = $this->normalize_images_field($out['urls']);
        return $out;
    }

    $s = trim((string)$val);
    if ($s === '') return $out;

    if ($s !== '' && ($s[0] === '[' || $s[0] === '{')) {
        $decoded = json_decode($s, true);
        if (is_array($decoded)) {
            if (isset($decoded['urls']) || isset($decoded['gdrive'])) {
                $out['urls'] = $this->normalize_images_field($decoded['urls'] ?? []);
                $gd = $decoded['gdrive'] ?? [];
                if (is_array($gd)) {
                    foreach ($gd as $g) {
                        if (!is_array($g)) continue;
                        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($g['id'] ?? ''));
                        if (!$id) continue;
                        $out['gdrive'][] = [
                            'id' => $id,
                            'view' => (string)($g['view'] ?? ''),
                            'mime' => (string)($g['mime'] ?? ''),
                        ];
                    }
                }
                return $out;
            }

            // plain list
            $out['urls'] = $this->normalize_images_field($decoded);
            return $out;
        }
    }

    // fallback: single url / csv
    $out['urls'] = $this->normalize_images_field($s);
    return $out;
}

private function receipt_media_to_db_value($media) {
    $m = is_array($media) ? $media : [];
    $urls = $this->normalize_images_field($m['urls'] ?? []);
    $gdrive = is_array($m['gdrive'] ?? null) ? $m['gdrive'] : [];
    $g2 = [];
    foreach ($gdrive as $g) {
        if (!is_array($g)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($g['id'] ?? ''));
        if (!$id) continue;
        $g2[] = [
            'id' => $id,
            'view' => (string)($g['view'] ?? ''),
            'mime' => (string)($g['mime'] ?? ''),
        ];
    }

    if (empty($g2)) {
        // Preserve legacy compact encoding for URL-only mode.
        return $this->images_to_db_value($urls);
    }

    return wp_json_encode(['urls' => $urls, 'gdrive' => $g2]);
}

/**
 * Merge receipt media for Transactions.
 *
 * - $keep_tokens: list of urls and/or "gdrive:<id>" from form (safety net)
 * - $remove_tokens: list of urls and/or "gdrive:<id>" to remove
 * - $add_urls: urls to add
 * - $add_gdrive: gdrive entries to add
 */
private function receipt_media_merge($db_value, $keep_tokens, $remove_tokens, $add_urls, $add_gdrive) {
    $m = $this->receipt_media_from_db_value($db_value);

    // Safety net for edits
    if (empty($m['urls']) && empty($m['gdrive']) && !empty($keep_tokens)) {
        $m = $this->receipt_media_from_db_value($keep_tokens);
    }

    $remove_tokens = is_array($remove_tokens) ? $remove_tokens : [];
    foreach ($remove_tokens as $t) {
        $t = is_string($t) ? trim($t) : '';
        if ($t === '') continue;
        if (stripos($t, 'gdrive:') === 0) {
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($t, 7));
            if (!$id) continue;
            $m['gdrive'] = array_values(array_filter((array)$m['gdrive'], function($g) use ($id) {
                return is_array($g) && (($g['id'] ?? '') !== $id);
            }));
        } else {
            $u = esc_url_raw($t);
            $m['urls'] = array_values(array_filter((array)$m['urls'], function($x) use ($u) { return $x !== $u; }));
        }
    }

    $m['urls'] = array_values(array_unique(array_merge($m['urls'], $this->normalize_images_field($add_urls))));

    // Append gdrive
    $add_gdrive = is_array($add_gdrive) ? $add_gdrive : [];
    foreach ($add_gdrive as $g) {
        if (!is_array($g)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($g['id'] ?? ''));
        if (!$id) continue;
        $exists = false;
        foreach ((array)$m['gdrive'] as $eg) {
            if (is_array($eg) && (($eg['id'] ?? '') === $id)) { $exists = true; break; }
        }
        if (!$exists) $m['gdrive'][] = $g;
    }

    return $m;
}

private function receipt_gdrive_ids($db_value) {
    $m = $this->receipt_media_from_db_value($db_value);
    $ids = [];
    foreach ((array)$m['gdrive'] as $g) {
        if (!is_array($g)) continue;
        $id = (string)($g['id'] ?? '');
        if ($id !== '') $ids[] = $id;
    }
    return array_values(array_unique($ids));
}

private function receipt_urls_only($db_value) {
    $m = $this->receipt_media_from_db_value($db_value);
    return $this->normalize_images_field($m['urls'] ?? []);
}

private function receipt_primary_url_for_notification($db_value) {
    $urls = $this->receipt_urls_only($db_value);
    return $urls ? (string)$urls[0] : '';
}

/**
 * Merge images from DB + user input.
 *
 * Keeps existing images by default, appends new uploads/manual URLs, and applies removals.
 * Also uses a form-provided keep list as a safety net to prevent accidental replacement
 * when editing (e.g., if DB row fetch fails or legacy data is empty).
 */
private function merge_images($db_value, $keep_urls, $uploaded_urls, $manual_urls, $remove_urls) {
    $existing = $this->normalize_images_field($db_value);

    // Safety net: if DB value is empty/unreadable but the form provided existing URLs,
    // keep them (helps prevent accidental replacement on edit reminders).
    if (empty($existing) && !empty($keep_urls)) {
        $existing = array_values(array_filter(array_map('esc_url_raw', $keep_urls)));
    }

    $all = array_merge($existing, $uploaded_urls, $manual_urls);
    $all = array_values(array_unique(array_filter($all)));

    if (!empty($remove_urls)) {
        $remove_urls = array_map('esc_url_raw', $remove_urls);
        $all = array_values(array_filter($all, function ($u) use ($remove_urls) {
            return !in_array($u, $remove_urls, true);
        }));
    }

    return $all;
}

/**
 * Encode image URL list back into DB field value.
 *
 * Backward compatible:
 * - 0 images  => ''
 * - 1 image   => single URL string
 * - 2+ images => JSON array
 */
private function images_to_db_value($urls) {
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
 * Decode DB images field into array of URLs.
 *
 * Backward compatible:
 * - ''        => []
 * - 'url'     => ['url']
 * - JSON list => [...]
 *
 * @param mixed $val
 * @return array
 */
private function images_from_db_value($val) {
    return $this->normalize_images_field($val);
}

/**
 * Parse textarea input containing image URLs (1 per line).
 */
private function parse_image_urls_textarea($raw) {
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
private function handle_multi_image_upload($field_name) {
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

private function optimize_uploaded_image_file($file_path, $max_width = 1920, $quality = 80) {
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
    private function test_connection_from_settings($s) {
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
        // Default external table name should be consistent with the plugin's internal base table.
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($ext['table'] ?? 'fl_transactions'));
        if (!$host || !$dbn || !$user) {
            return [false, 'External DB not configured (host/db/user required).'];
        }
        if (!$table) $table = 'fl_transactions';

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

            // Helpful hint: many users select External but actually want to use WP's internal tables.
            $hint = '';
            global $wpdb;
            if ($wpdb instanceof wpdb) {
                $internal_table = $wpdb->prefix . 'fl_transactions';
                $has_internal = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $internal_table)) === $internal_table);
                if ($has_internal) {
                    $hint = ' Tip: Your WP internal table exists (' . $internal_table . '). If you want to use it, switch Mode to “Internal (WP DB)” or set the external Table to ' . $internal_table . '.';
                }
            }

            return [false, "Connected, but cannot read table `{$table}`: ".$err.$hint];
        }

        $have = [];
        foreach ($cols as $c) $have[] = (string)($c['Field'] ?? '');
$notes = [];

// Accept both legacy (Indonesian) and English column names.
$required_groups = [
    ['line_id'],
    ['transaction_id'],
    ['items','item','product'],
    ['quantity','qty'],
    ['nama_toko','counterparty','merchant','payee','store_name'],
    ['kategori','category','type'],
    ['harga','price','amount','unit_price'],
    ['tanggal_input','entry_date','created_at','input_date'],
];

$missing_groups = [];
foreach ($required_groups as $g) {
    $ok = false;
    foreach ($g as $cand) {
        if (in_array($cand, $have, true)) { $ok = true; break; }
    }
    if (!$ok) {
        $missing_groups[] = $g[0] . (count($g) > 1 ? ' (or ' . implode('/', array_slice($g, 1)) . ')' : '');
    }
}

if ($missing_groups) {
    return [false, 'Connected, but table schema mismatch. Missing required columns: ' . implode(', ', $missing_groups)];
}

// Recommended/optional groups
if (!array_intersect(['tanggal_struk','receipt_date','transaction_date','receiptDate'], $have)) {
    $notes[] = 'Receipt date column missing (tanggal_struk / receipt_date). The plugin will fallback to entry date for reports.';
}
if (!array_intersect(['gambar_url','image_url','image','photo_url'], $have)) {
    $notes[] = 'Image column missing (gambar_url / image_url). Attachments will be disabled.';
}
if (!array_intersect(['description','note','desc'], $have)) {
    $notes[] = 'Description column missing (description). Notes will be disabled.';
}

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
            // Recommended external name: fl_savings (older versions used finance_savings).
            $sv_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($s['savings']['external_table'] ?? 'fl_savings'));
            if (!$sv_table) $sv_table = 'fl_savings';
            $sv_cols = $wpdb_ext->get_results("DESCRIBE `{$sv_table}`", ARRAY_A);
            if (!$sv_cols || !is_array($sv_cols)) {
                // Hint legacy name if it exists.
                $legacy = 'finance_savings';
                $legacy_cols = $wpdb_ext->get_results("DESCRIBE `{$legacy}`", ARRAY_A);
                $legacy_hint = ($legacy_cols && is_array($legacy_cols)) ? " Tip: A legacy table `{$legacy}` exists. Set External savings table to `{$legacy}` or rename it to `fl_savings`." : '';
                $err = $wpdb_ext->last_error ?: 'Table not found or no privileges';
                return [false, "Connected, but cannot read savings table `{$sv_table}`: ".$err.$legacy_hint];
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

        // Optional: check External Payment Reminders table (if Reminders mode is External, or Same-as-Transactions while Transactions mode is External)
        $rem_mode = (string)($s['reminders']['mode'] ?? 'same');
        $rem_is_ext = ($rem_mode === 'external' || ($rem_mode === 'same' && ($s['datasource_mode'] ?? 'external') === 'external'));
        if ($rem_is_ext) {
            // Recommended external name: fl_payment_reminders (older versions used finance_payment_reminders).
            $rm_table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($s['reminders']['external_table'] ?? 'fl_payment_reminders'));
            if (!$rm_table) $rm_table = 'fl_payment_reminders';

            $rm_cols = $wpdb_ext->get_results("DESCRIBE `{$rm_table}`", ARRAY_A);
            if (!$rm_cols || !is_array($rm_cols)) {
                $legacy = 'finance_payment_reminders';
                $legacy_cols = $wpdb_ext->get_results("DESCRIBE `{$legacy}`", ARRAY_A);
                $legacy_hint = ($legacy_cols && is_array($legacy_cols)) ? " Tip: A legacy table `{$legacy}` exists. Set External reminders table to `{$legacy}` or rename it to `fl_payment_reminders`." : '';
                $err = $wpdb_ext->last_error ?: 'Table not found or no privileges';
                return [false, "Connected, but cannot read reminders table `{$rm_table}`: ".$err.$legacy_hint];
            }

            $rm_have = [];
            foreach ($rm_cols as $c) $rm_have[] = (string)($c['Field'] ?? '');

            // Required columns used by the plugin (cron + add/edit).
            $rm_required = [
                'line_id','reminder_id','payment_name',
                'installment_amount','installments_total','installments_paid',
                'schedule_mode','due_day','due_date','status',
                'notify_telegram','notify_whatsapp','notify_email',
                'notified_for_due','notified_offsets','last_notified_at',
                'created_at','updated_at',
            ];
            $rm_missing = array_values(array_diff($rm_required, $rm_have));
            if ($rm_missing) {
                return [false, 'Connected, but reminders table schema mismatch. Missing columns: '.implode(', ', array_unique($rm_missing))];
            }

            // Optional user columns (nice-to-have)
            $rm_optional_missing = [];
            foreach (['wp_user_id','wp_user_login'] as $oc) {
                if (!in_array($oc, $rm_have, true)) $rm_optional_missing[] = $oc;
            }
            if ($rm_optional_missing) {
                $notes[] = 'Reminders table missing optional user columns: '.implode(', ', $rm_optional_missing);
            }
        }

        if ($notes) {
            return [true, 'External datasource OK. Notes: '.implode(' | ', $notes)];
        }

        return [true, 'External datasource OK (connected + table schema valid).'];
    }

    private function create_internal_transactions_table() {
        // returns [ok, message]
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'fl_transactions';
        $charset = $wpdb->get_charset_collate();

        // If legacy columns exist (Indonesian) and English columns are missing, rename in-place.
        $exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        if ($exists) {
            $alters = [];
            if ($this->ds_column_exists('nama_toko', $wpdb, $table) && !$this->ds_column_exists('counterparty', $wpdb, $table)) {
                $alters[] = "CHANGE `nama_toko` `counterparty` VARCHAR(255) NULL";
            }
            if ($this->ds_column_exists('harga', $wpdb, $table) && !$this->ds_column_exists('price', $wpdb, $table)) {
                $alters[] = "CHANGE `harga` `price` BIGINT NOT NULL";
            }
            if ($this->ds_column_exists('kategori', $wpdb, $table) && !$this->ds_column_exists('category', $wpdb, $table)) {
                $alters[] = "CHANGE `kategori` `category` VARCHAR(20) NULL";
            }
            if ($this->ds_column_exists('tanggal_input', $wpdb, $table) && !$this->ds_column_exists('entry_date', $wpdb, $table)) {
                $alters[] = "CHANGE `tanggal_input` `entry_date` DATETIME NOT NULL";
            }
            if ($this->ds_column_exists('tanggal_struk', $wpdb, $table) && !$this->ds_column_exists('receipt_date', $wpdb, $table)) {
                $alters[] = "CHANGE `tanggal_struk` `receipt_date` DATE NULL";
            }
            if ($this->ds_column_exists('gambar_url', $wpdb, $table) && !$this->ds_column_exists('image_url', $wpdb, $table)) {
                $alters[] = "CHANGE `gambar_url` `image_url` TEXT NULL";
            }
            if ($alters) {
                $wpdb->query("ALTER TABLE `{$table}` " . implode(', ', $alters));
            }
        }
        $sql = "CREATE TABLE {$table} (
            line_id VARCHAR(80) NOT NULL,
            transaction_id VARCHAR(64) NOT NULL,
            split_group VARCHAR(64) NULL,
            tags TEXT NULL,
            counterparty VARCHAR(255) NULL,
            items VARCHAR(255) NOT NULL,
            quantity INT NOT NULL,
            price BIGINT NOT NULL,
            category VARCHAR(20) NULL,
            entry_date DATETIME NOT NULL,
            receipt_date DATE NULL,
            purchase_date DATE NULL,
            receive_date DATE NULL,
            image_url TEXT NULL,
            description LONGTEXT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            wp_user_login VARCHAR(60) NULL,
            PRIMARY KEY (line_id),
            KEY transaction_id (transaction_id),
            KEY split_group (split_group),
            KEY category (category),
            KEY receipt_date (receipt_date),
            KEY purchase_date (purchase_date),
            KEY receive_date (receive_date)
        ) {$charset};";
        dbDelta($sql);

        $ok = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table);
        return $ok ? [true, 'Internal table created/updated.'] : [false, 'Failed to create internal table. Check DB privileges.'];
    }


    /* -------------------- Logging -------------------- */

    private function simku_request_id() {
        static $rid = null;
        if ($rid) return $rid;
        $rid = substr(wp_generate_password(24, false, false), 0, 24) . '_' . substr(md5((string)microtime(true)), 0, 8);
        return $rid;
    }

    private function simku_client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff) {
            $parts = explode(',', $xff);
            $cand = trim((string)($parts[0] ?? ''));
            if ($cand) $ip = $cand;
        }
        return $ip ?: null;
    }


    
private function log_event($action, $object_type, $object_id = null, $details = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'fl_logs';
    $user = wp_get_current_user();

    // Detect optional columns once per request.
    static $has_req = null;
    static $has_ua = null;
    if ($has_req === null) {
        $has_req = (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'request_id'));
        $has_ua  = (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'user_agent'));
    }

    $ip = $this->simku_client_ip();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $rid = $this->simku_request_id();

    $data = [
        'created_at' => current_time('mysql'),
        'user_id' => $user && $user->ID ? $user->ID : null,
        'user_login' => $user && $user->user_login ? $user->user_login : null,
        'action' => $action,
        'object_type' => $object_type,
        'object_id' => $object_id,
        'details' => is_string($details) ? $details : ($details ? wp_json_encode($details) : null),
        'ip' => $ip,
    ];
    $formats = ['%s','%d','%s','%s','%s','%s','%s','%s'];

    if ($has_req) { $data['request_id'] = $rid; $formats[] = '%s'; }
    if ($has_ua)  { $data['user_agent'] = $ua; $formats[] = '%s'; }

    $wpdb->insert($table, $data, $formats);
}

    public function on_login($user_login, $user) {
        // $user can be WP_User or string in older hooks; guard.
        $this->log_event('login', 'user', is_object($user) ? (string)$user->ID : null, ['user_login' => $user_login]);
    }

    public function on_logout() {
        $user = wp_get_current_user();
        $this->log_event('logout', 'user', $user && $user->ID ? (string)$user->ID : null, ['user_login' => $user->user_login ?? null]);
    }
    private function get_expense_categories() {
        $s = $this->settings();
        $cats = $s['limits']['expense_categories'] ?? ['expense','saving','invest'];
        $cats = array_values(array_filter(array_map('strval', (array)$cats)));
        $cats = $this->expand_category_filter($cats);
        return $cats ?: ['expense','outcome','saving','invest'];
    }

    private function sanitize_date_basis($basis) {
        $basis = strtolower(trim($basis));
        return in_array($basis, ['input','receipt','purchase','receive'], true) ? $basis : 'input';
    }

    /**
     * Return SQL expression for picking the date basis.
     * - input  : tanggal_input (DATETIME)
     * - receipt : tanggal_struk if valid, else DATE(tanggal_input)
     */
    
private function date_basis_expr($basis) {
    $basis = $this->sanitize_date_basis($basis);

    $db = $this->ds_db();
    if (!($db instanceof wpdb)) {
        // Best effort fallback
        if ($basis === 'receipt') { return "COALESCE(NULLIF(tanggal_struk,'0000-00-00'), DATE(tanggal_input))"; }
        if ($basis === 'purchase') { return "COALESCE(NULLIF(purchase_date,'0000-00-00'), DATE(tanggal_input))"; }
        if ($basis === 'receive') { return "COALESCE(NULLIF(receive_date,'0000-00-00'), DATE(tanggal_input))"; }
        return 'tanggal_input';
    }
    $table = $this->ds_table();

    $entry_col = $this->tx_col('tanggal_input', $db, $table);
    $receipt_col = $this->tx_col('tanggal_struk', $db, $table);
    $purchase_col = $this->tx_col('purchase_date', $db, $table);
    $receive_col  = $this->tx_col('receive_date', $db, $table);

    // If requested date basis column doesn't exist, fall back to entry date.
    if ($basis === 'receipt') {
        if ($receipt_col && $this->ds_column_exists($receipt_col, $db, $table)) {
            return "COALESCE(NULLIF(`{$receipt_col}`,'0000-00-00'), DATE(`{$entry_col}`))";
        }
        return "DATE(`{$entry_col}`)";
    }

    if ($basis === 'purchase') {
        if ($purchase_col && $this->ds_column_exists($purchase_col, $db, $table)) {
            return "COALESCE(NULLIF(`{$purchase_col}`,'0000-00-00'), DATE(`{$entry_col}`))";
        }
        return "DATE(`{$entry_col}`)";
    }

    if ($basis === 'receive') {
        if ($receive_col && $this->ds_column_exists($receive_col, $db, $table)) {
            return "COALESCE(NULLIF(`{$receive_col}`,'0000-00-00'), DATE(`{$entry_col}`))";
        }
        return "DATE(`{$entry_col}`)";
    }

    return "`{$entry_col}`";
}

/**
 * Resolve the user/login column name for the active datasource.
 * Internal table uses `wp_user_login`, while some external tables use `user`/`username`.
 */
private function tx_user_col() {
    foreach (['wp_user_login','user_login','user','username'] as $c) {
        if ($this->ds_column_exists($c)) return $c;
    }
    return '';
}

private function calc_totals_between($start_dt, $end_dt, $date_basis = 'input', $user_login = null) {
        // start_dt/end_dt are inclusive/exclusive.
        // - input: use 'Y-m-d H:i:s'
        // - receipt: use 'Y-m-d'
        $db = $this->ds_db();
        if (!$db) return ['income' => 0, 'expense' => 0, 'by_cat' => []];

        $table = $this->ds_table();

        $cat_col   = $this->tx_col('kategori', $db, $table);
        $price_col = $this->tx_col('harga', $db, $table);
        $qty_col   = $this->tx_col('quantity', $db, $table);
        
        $date_expr = $this->date_basis_expr($date_basis);
        $where_sql = "{$date_expr} >= %s AND {$date_expr} < %s";

        // NOTE: placeholder order matters (left-to-right) — category placeholders appear
        // in the SELECT before the date placeholders in the WHERE.
        $params = [ $start_dt, $end_dt ];

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

        $sql = "SELECT TRIM(LOWER(`{$cat_col}`)) AS kategori,
               SUM(CASE WHEN TRIM(LOWER(`{$cat_col}`)) = 'income' THEN (`{$price_col}`*`{$qty_col}`) ELSE 0 END) AS income_total,
               SUM(CASE WHEN TRIM(LOWER(`{$cat_col}`)) <> 'income' THEN (`{$price_col}`*`{$qty_col}`) ELSE 0 END) AS expense_total,
               SUM(`{$price_col}`*`{$qty_col}`) AS amount_total
        FROM `{$table}`
        WHERE {$where_sql}
        GROUP BY TRIM(LOWER(`{$cat_col}`))";


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

    private function calc_savings_total() {
        $db = $this->savings_db();
        if (!$db) return 0.0;
        $table = $this->savings_table();
        $sql = "SELECT COALESCE(SUM(amount),0) FROM `{$table}`";
        $v = $db->get_var($sql);
        if ($db->last_error) return 0.0;
        return (float)$v;
    }

    public function cron_check_limits() {
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
    private function normalize_status_label($status) {
        $s = strtolower(trim($status));
        if ($s === 'lunas' || $s === 'paid' || $s === 'done') return 'Paid';
        return 'Unpaid';
    }

    private function add_month_preserve_day($ymd, $day_of_month) {
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

    private function compute_next_due_date($mode, $manual_due_date, $due_day, $first_due_date = null) {
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

    private function reminder_ctx($row, $days_left) {
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

    public function cron_check_payment_reminders() {
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

    private function reminder_mark_paid($line_id) {
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
private function export_pdf_transactions($rows, $filters) {
        // Minimal PDF (text-only) with nicer formatting. Outputs download and exits.
        if (headers_sent()) return;

        $title = "WP SIMKU Transactions Report";
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
  private function receipt_ocr_script_path() {
        return plugin_dir_path(__FILE__) . 'ocr/receipt_ocr.py';
    }


    private function get_n8n_scan_config() {
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


    private function receipt_ocr_run($image_path) {
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

    private function receipt_ocr_run_n8n($image_path, $url, $api_key = '', $timeout = 90) {
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

    private function receipt_ocr_run_python($image_path) {
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
/**
     * Normalize date input from browser/datepicker into ISO Y-m-d.
     * Accepts: Y-m-d, m/d/Y, d/m/Y, d-m-Y, m-d-Y, etc.
     *
     * Heuristic for dd/mm vs mm/dd:
     * - if first part > 12 => day/month
     * - else if second part > 12 => month/day
     * - else default month/day (matches most browser locale outputs like 01/14/2026)
     */
    private function normalize_date_ymd($raw) {
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
    private function normalize_month_ym($raw) {
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
 function shortcode_box_html($shortcode, $alt = '', $title = 'Shortcode', $help = '') {
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
     /**
      * Pagination UI used across SIMKU list pages.
      * Keeps pagination usable on desktop/mobile (bigger click targets + centered).
     */
    private function render_pagination($page_slug, $current, $total_pages, $query_args = []) {
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


    private function simku_shortcode_pages() {
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

    public function shortcode_simku($atts = [], $content = null, $tag = 'simku') {
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

    public function shortcode_simku_dashboard($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'dashboard']), $content, 'simku_dashboard');
    }
    public function shortcode_simku_transactions($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'transactions']), $content, 'simku_transactions');
    }
    public function shortcode_simku_add_transaction($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-transaction']), $content, 'simku_add_transaction');
    }
    public function shortcode_simku_savings($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'savings']), $content, 'simku_savings');
    }
    public function shortcode_simku_add_saving($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-saving']), $content, 'simku_add_saving');
    }
    public function shortcode_simku_reminders($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'reminders']), $content, 'simku_reminders');
    }
    public function shortcode_simku_add_reminder($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-reminder']), $content, 'simku_add_reminder');
    }
    public function shortcode_simku_scan_struk($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'scan-struk']), $content, 'simku_scan_struk');
    }
   public function shortcode_simku_settings($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'settings']), $content, 'simku_settings');
    }
    public function shortcode_simku_logs($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'logs']), $content, 'simku_logs');
    }

    public function register_dashboard_widget() {
        if (!current_user_can(self::CAP_VIEW_TX)) return;
        wp_add_dashboard_widget('fl_dashboard_widget', 'WP SIMKU Charts', [$this, 'dashboard_widget']);
    }

    public function dashboard_widget() {
        $dash = get_option(self::OPT_DASH_CHARTS, []);
        if (!is_array($dash) || !$dash) {
            echo '<div class="fl-muted">No charts selected for dashboard. Go to WP SIMKU → Charts and enable “Show on dashboard”.</div>';
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
  private function dim_expr($dim, $date_basis = 'input') {
        // Uses MySQL functions.
        $d = $this->date_basis_expr($date_basis);

        $db = $this->ds_db();
        $table = $this->ds_table();

        $party_col = $this->tx_col('nama_toko', $db, $table);
        $cat_col   = $this->tx_col('kategori', $db, $table);
        $items_col = $this->tx_col('items', $db, $table);

        switch ($dim) {
            case 'day':   return "DATE({$d})";
            case 'week':  return "DATE_FORMAT({$d}, '%%x-W%%v')";
            case 'month': return "DATE_FORMAT({$d}, '%%Y-%%m')";
            case 'year':  return "DATE_FORMAT({$d}, '%%Y')";
            case 'dow':   return "DATE_FORMAT({$d}, '%%W')";
            case 'nama_toko':
                return "COALESCE(NULLIF(`{$party_col}`,''), '(unknown)')";
            case 'kategori':
                return "COALESCE(NULLIF(CASE WHEN `{$cat_col}`='outcome' THEN 'expense' ELSE `{$cat_col}` END,''), '(uncategorized)')";
            case 'items':
                return "COALESCE(NULLIF(`{$items_col}`,''), '(unknown)')";
            default:
                return "DATE({$d})";
        }
    }

    /**
     * Metric expression (row-level) used by builder charts.
     * Aggregation (SUM/AVG/COUNT/...) is applied by the caller.
     */
    private function metric_expr($metric) {
        $db = $this->ds_db();
        $table = $this->ds_table();

        $cat_col   = $this->tx_col('kategori', $db, $table);
        $price_col = $this->tx_col('harga', $db, $table);
        $qty_col   = $this->tx_col('quantity', $db, $table);

        switch ($metric) {
            case 'quantity_total':
                return "`{$qty_col}`";
            case 'count_rows':
                // Caller should use COUNT(), we return a constant so COUNT(1) works.
                return "1";
            case 'avg_price':
                // Caller should use AVG().
                return "`{$price_col}`";
            case 'income_total':
                return "CASE WHEN `{$cat_col}`='income' THEN (`{$price_col}`*`{$qty_col}`) ELSE 0 END";
            case 'expense_total':
                $cats = $this->get_expense_categories();
                $in = "'" . implode("','", array_map('esc_sql', $cats)) . "'";
                return "CASE WHEN `{$cat_col}` IN ({$in}) THEN (`{$price_col}`*`{$qty_col}`) ELSE 0 END";
            case 'amount_total':
            default:
                return "(`{$price_col}`*`{$qty_col}`)";
        }
    }

    private function metric_label($metric) {

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
    /* -------------------- Chart containers JS helpers -------------------- */

}

/* -------------------- Bootstrap -------------------- */

add_action('plugins_loaded', function() {
    SIMAK_App_Simak::instance();
});

register_activation_hook(__FILE__, ['SIMAK_App_Simak', 'activate']);
register_deactivation_hook(__FILE__, ['SIMAK_App_Simak', 'deactivate']);
