<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Dashboard {
    public function page_dashboard() {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        $charts = get_option(self::OPT_CHARTS, self::default_charts());
        $dash_ids = get_option(self::OPT_DASH_CHARTS, []);
        // Keep dashboard tidy: hide legacy 30-day expense charts (users can still view them in Charts page).
        // Also ensures older installs with stored OPT_DASH_CHARTS get the updated layout.
        $deny_dashboard_ids = ['expense_by_day_30', 'expense_by_category_30'];
        if (!is_array($dash_ids) || !$dash_ids) {
            // default: those marked show_on_dashboard
            $dash_ids = [];
            foreach ((array)$charts as $c) {
                if (!empty($c['show_on_dashboard'])) $dash_ids[] = $c['id'];
            }
            update_option(self::OPT_DASH_CHARTS, $dash_ids, false);
        }
        $dash_ids = array_values(array_filter((array)$dash_ids, function($id) use ($deny_dashboard_ids) { return !in_array($id, $deny_dashboard_ids, true); }));

        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '';
        $date_basis = isset($_GET['date_basis']) ? sanitize_text_field(wp_unslash($_GET['date_basis'])) : 'input';
        $date_basis = $this->sanitize_date_basis($date_basis);
        $group = isset($_GET['group']) ? sanitize_text_field(wp_unslash($_GET['group'])) : 'daily';
        $group = in_array($group, ['daily','weekly','monthly'], true) ? $group : 'daily';
        // Category filter (income / expense / all)
        $tx_type = isset($_GET['tx_type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_GET['tx_type']))) : 'all';
        if (!$to) $to = wp_date('Y-m-d');
        if (!$from) $from = wp_date('Y-m-d', strtotime($to . ' -6 days'));        // Totals today (used for KPI cards).
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




        // Render view via template (easier to maintain for open-source contributors).
        $this->render_template('admin/dashboard.php', [
            'charts'        => $charts,
            'dash_ids'      => $dash_ids,
            'from'          => $from,
            'to'            => $to,
            'date_basis'    => $date_basis,
            'group'         => $group,
            'tx_type'       => $tx_type,
            'tot'           => $tot,
            'outcome_today' => $outcome_today,
            'savings_total' => $savings_total,
        ]);

    }

    

}
