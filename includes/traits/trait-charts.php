<?php
/**
 * Charts module (shortcode + AJAX payload + helpers).
 *
 * Extracted from the main plugin file to improve maintainability.
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Charts {

    private static function default_charts() {
        // A few ready-to-use charts. Public = template visible for all logged-in users.
        $charts = [
            [
                'id' => 'income_vs_outcome_day_7',
                // Title should be range-agnostic (dashboard filter can change date window).
                'title' => 'Income vs Expense',
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
                'title' => 'By Category',
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
                // Keep available in Charts page, but hide from dashboard to reduce clutter.
                'show_on_dashboard' => 0,
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
                'show_on_dashboard' => 0,
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


	private function normalize_chart($c) {
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


    private function can_view_chart($chart, $user_id) {
        // Finance roles can view all charts.
        if (current_user_can(self::CAP_VIEW_TX)) return true;
        // Others can view public charts or their own.
        if (!empty($chart['is_public'])) return true;
        return (int)($chart['created_by'] ?? 0) === (int)$user_id;
    }


    private function can_edit_chart($chart, $user_id) {
        // Finance roles can edit all charts.
        if (current_user_can(self::CAP_VIEW_TX)) return true;
        return (int)($chart['created_by'] ?? 0) === (int)$user_id;
    }


    private function find_chart($id) {
        $charts = get_option(self::OPT_CHARTS, []);
        foreach ((array)$charts as $c) {
            if (($c['id'] ?? '') === $id) return $this->normalize_chart((array)$c);
        }
        return null;
    }


    private function render_chart_container($id, $admin=false) {
        $cls = $admin ? 'fl-chart-box fl-chart-box-admin' : 'fl-chart-box';
        return '<div class="'.esc_attr($cls).'" data-fl-chart="'.esc_attr($id).'"></div>';
    }


    private function render_chart_container_with_config($id, $config, $admin=false) {
        $cls = $admin ? 'fl-chart-box fl-chart-box-admin' : 'fl-chart-box';
        // Pass config as JSON (frontend js will POST it to the ajax endpoint).
        $json = wp_json_encode($config);
        return '<div class="'.esc_attr($cls).'" data-fl-chart="'.esc_attr($id).'" data-fl-config="'.esc_attr($json).'"></div>';
    }


    public function shortcode_chart($atts) {
        $atts = shortcode_atts(['id' => ''], $atts, 'fl_chart');
        $id = sanitize_text_field($atts['id']);
        if (!$id) return '';
        return $this->render_chart_container($id, false);
    }

    public function shortcode_simku_charts($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'charts']), $content, 'simku_charts');
    }


    public function shortcode_simku_add_chart($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'add-chart']), $content, 'simku_add_chart');
    }


    /* -------------------- Charts data -------------------- */

    public function ajax_chart_data() {
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

        $cat_col = $this->tx_col('kategori', $db, $table);

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
            $where .= " AND `{$cat_col}` IN ($in)";
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


    private function is_chart_privileged_user() {
        return current_user_can(self::CAP_MANAGE_TX) || current_user_can(self::CAP_MANAGE_SETTINGS);
    }


    private function sql_chart_payload($chart, $user_id, $start_date, $end_date_excl) {
        global $wpdb;

        $sql_raw = trim((string)($chart['sql_query'] ?? ''));
        $chart_type = (string)($chart['chart_type'] ?? 'bar');

        if ($sql_raw === '') {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL query is empty'];
        }

        // Restrict SQL charts to privileged users only (Finance/Admin).
        if (!$this->is_chart_privileged_user()) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'SQL charts are restricted to Finance/Admin users'];
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
        if (preg_match('/\bunion\b/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'UNION queries are not allowed'];
        }

        if (preg_match('/\binto\s+outfile\b|\bload_file\b/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Forbidden SQL feature'];
        }

        // Require using at least one internal placeholder table.
        if (!preg_match('/\{\{\s*(active|savings|reminders)\s*\}\}/i', $sql)) {
            return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Query must use {{active}}, {{savings}} or {{reminders}}'];
        }


// Extra sandbox: disallow referencing non-placeholder tables in FROM/JOIN.
// This closes data exfiltration via "..., wp_users" etc.
$sql_no_strings = preg_replace("/'[^']*'/", "''", $sql);
if (preg_match('/\b(information_schema|performance_schema|mysql)\b/i', $sql_no_strings)) {
    return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Query references forbidden schema'];
}
$scan = strtolower($sql_no_strings);
// Reject comma joins (hard to sandbox safely).
if (preg_match('/\bfrom\b[^;]*,/', $scan)) {
    return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Comma joins are not allowed in SQL mode'];
}
// Ensure each FROM/JOIN source starts with a placeholder or a subquery.
if (preg_match_all('/\b(from|join)\s+([^\s\(]+)/i', $sql_no_strings, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $one) {
        $tok = trim((string)($one[2] ?? ''));
        if ($tok === '') continue;
        // Allow placeholders and derived tables.
        if (stripos($tok, '{{') === 0 || stripos($tok, '`{{') === 0) continue;
        return ['type' => $chart_type, 'x' => [], 'series' => [], 'message' => 'Query references external tables; only {{active}}, {{savings}}, {{reminders}} are allowed'];
    }
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

        $db = $wpdb;
        $t_tx = $tables['active'] ?? '';
        // Optional filter: transaction category/type (income/expense) from chart config.
        $tx_type = '';
        if (isset($chart['filter']) && is_array($chart['filter'])) {
            $tx_type = strtolower((string)($chart['filter']['tx_type'] ?? ''));
        }
        if (in_array($tx_type, ['income','expense'], true)) {
            // Try to filter using the transactions table category column (kategori).
            $cat_col = 'kategori';
            if ($db instanceof wpdb) {
                $cat_col = $this->tx_col('kategori', $db, $t_tx);
            }
            $cond = "`{$cat_col}` = '" . esc_sql($tx_type) . "'";
            if (stripos($sql, ' where ') !== false) {
                $sql = preg_replace('/\bWHERE\b/i', 'WHERE ' . $cond . ' AND', $sql, 1);
            } else {
                $sql .= ' WHERE ' . $cond;
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
        $all_date = $labels && count(array_filter($labels, function($l) { return preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$l); })) === count($labels);
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


    private function format_chart_series($chart_type, $rows, $has_series, $metric_labels, $xvals_override = []) {
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

}
