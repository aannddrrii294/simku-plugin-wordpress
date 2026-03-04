<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Charts_Page {
    public function page_charts() {
        // Backward compatible: Charts page now shows the list.
        $this->page_charts_list();
    }

    

    public function page_charts_list() {
        if (!is_user_logged_in() || !current_user_can('read')) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        $charts = get_option(self::OPT_CHARTS, self::default_charts());
        if (!is_array($charts)) $charts = self::default_charts();
        $charts = array_map([$this, 'normalize_chart'], (array)$charts);

        $uid = (int)get_current_user_id();

        // Delete chart
        if (!empty($_REQUEST['fl_delete_chart'])) {
            check_admin_referer('fl_delete_chart');
            $id = sanitize_text_field(wp_unslash($_REQUEST['delete_id'] ?? ''));
            if ($id) {
                $existing = $this->find_chart($id);
                if (!$existing) {
                    echo '<div class="notice notice-warning"><p>Chart not found.</p></div>';
                } elseif (!$this->can_edit_chart($existing, $uid)) {
                    wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
                } else {
                    $charts = array_values(array_filter($charts, function($c) use ($id) { return (($c['id'] ?? '') !== $id); }));
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

        $this->render_template('admin/charts/list.php', [
            'visible' => $visible,
            'uid' => $uid,
        ]);
    }

    

    public function page_add_chart() {
        if (!is_user_logged_in() || !current_user_can('read')) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

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
        if (!empty($_REQUEST['fl_save_chart'])) {
            check_admin_referer('fl_save_chart');

            $incoming_id = sanitize_key($_REQUEST['chart_id'] ?? '');
            $existing = $incoming_id ? $this->find_chart($incoming_id) : null;
            if ($existing && !$this->can_edit_chart($existing, $uid)) {
                wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
            }

            $mode = sanitize_text_field(wp_unslash($_REQUEST['data_source_mode'] ?? 'builder'));
            if (!in_array($mode, ['builder','sql'], true)) $mode = 'builder';

            // Security: SQL mode is restricted to Finance/Admin.
            if ($mode === 'sql' && !(current_user_can(self::CAP_MANAGE_TX) || current_user_can(self::CAP_MANAGE_SETTINGS))) {
                $mode = 'builder';
            }

            $chart = [
                'id' => $incoming_id,
                'title' => sanitize_text_field(wp_unslash($_REQUEST['title'] ?? 'Untitled chart')),
                'chart_type' => sanitize_text_field(wp_unslash($_REQUEST['chart_type'] ?? 'bar')),
                'data_source_mode' => $mode,
                'is_public' => !empty($_REQUEST['is_public']) ? 1 : 0,
                'date_basis' => $this->sanitize_date_basis((string)($_REQUEST['date_basis'] ?? 'input')),
                'range' => [
                    'mode' => sanitize_text_field(wp_unslash($_REQUEST['range_mode'] ?? 'last_days')),
                    'days' => (int)($_REQUEST['range_days'] ?? 30),
                    'from' => sanitize_text_field(wp_unslash($_REQUEST['range_from'] ?? '')),
                    'to' => sanitize_text_field(wp_unslash($_REQUEST['range_to'] ?? '')),
                ],
                // builder fields (ignored in SQL mode)
                'x' => sanitize_text_field(wp_unslash($_REQUEST['x'] ?? 'day')),
                'series' => sanitize_text_field(wp_unslash($_REQUEST['series'] ?? '')),
                'metrics' => [],
                'filter' => [
                    'kategori' => array_values(array_filter(array_map('sanitize_text_field', (array)($_REQUEST['filter_kategori'] ?? [])))),
                    'top_n' => (int)($_REQUEST['top_n'] ?? 0),
                ],
                'show_on_dashboard' => (!empty($_REQUEST['show_on_dashboard']) && current_user_can(self::CAP_VIEW_TX)) ? 1 : 0,
                // SQL fields
                'sql_query' => trim((string)wp_unslash($_REQUEST['sql_query'] ?? '')),
                'custom_option_json' => trim((string)wp_unslash($_REQUEST['custom_option_json'] ?? '')),
            ];

            // Metrics: up to 3
            for ($i=1;$i<=3;$i++) {
                $m = sanitize_text_field(wp_unslash($_REQUEST["metric_$i"] ?? ''));
                if (!$m) continue;
                $agg = sanitize_text_field(wp_unslash($_REQUEST["agg_$i"] ?? 'SUM'));
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

                $types = [
            'bar' => 'Bar',
            'stacked_bar' => 'Stacked Bar',
            'line' => 'Line',
            'area' => 'Area',
            'scatter' => 'Scatter',
            'pie' => 'Pie',
            'donut' => 'Donut',
        ];
        $modes = [
            'builder' => 'Builder (drag & drop)',
            'sql' => 'SQL Query',
        ];
        if (!(current_user_can(self::CAP_MANAGE_TX) || current_user_can(self::CAP_MANAGE_SETTINGS))) {
            unset($modes['sql']);
        }

        $metrics = $edit_chart['metrics'] ?? [['metric'=>'amount_total','agg'=>'SUM']];
        $range = $edit_chart['range'] ?? ['mode'=>'last_days','days'=>30,'from'=>'','to'=>''];
        $filter = $edit_chart['filter'] ?? ['kategori'=>[],'top_n'=>0];
        $can_dash = current_user_can(self::CAP_VIEW_TX);

        // Render via template for maintainability (behavior unchanged).
        $this->render_template('admin/charts/add.php', [
            'edit_chart' => $edit_chart,
            'uid' => $uid,
            'back_url' => $back_url,
            'types' => $types,
            'modes' => $modes,
            'can_dash' => $can_dash,
            'range' => $range,
            'filter' => $filter,
            'metrics' => $metrics,
        ]);
    }

    

}
