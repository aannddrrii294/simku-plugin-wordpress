<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Logs_Page {
public function page_logs() {
    if (!current_user_can(self::CAP_VIEW_LOGS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
    global $wpdb;
    $table = $wpdb->prefix.'fl_logs';

    $has_request_id = (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'request_id'));
    $has_user_agent = (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'user_agent'));

    // Purge (admin/settings only)
    if (!empty($_POST['fl_purge_logs']) && current_user_can(self::CAP_MANAGE_SETTINGS)) {
        check_admin_referer('fl_purge_logs');
        $days = (int)($_POST['purge_days'] ?? 90);
        if ($days < 1) $days = 1;
        if ($days > 3650) $days = 3650;
        $cut = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        $res = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cut));
        $this->log_event('purge', 'logs', null, ['days'=>$days,'deleted'=>(int)$res]);
        echo '<div class="notice notice-success"><p>Purged logs older than '.esc_html((string)$days).' days. Deleted: <b>'.esc_html((string)((int)$res)).'</b>.</p></div>';
    }

    $q = [
        's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
        'action' => isset($_GET['action']) ? sanitize_text_field(wp_unslash($_GET['action'])) : '',
        'object_type' => isset($_GET['object_type']) ? sanitize_text_field(wp_unslash($_GET['object_type'])) : '',
        'user' => isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : '',
        'from' => isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '',
        'to' => isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : '',
    ];

    $where = '1=1';
    $params = [];

    if ($q['action'] && $q['action'] !== 'all') {
        $where .= ' AND action = %s';
        $params[] = $q['action'];
    }
    if ($q['object_type'] && $q['object_type'] !== 'all') {
        $where .= ' AND object_type = %s';
        $params[] = $q['object_type'];
    }
    if ($q['user'] && $q['user'] !== 'all') {
        $where .= ' AND user_login = %s';
        $params[] = $q['user'];
    }

    if ($q['from']) {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['from']) ? ($q['from'].' 00:00:00') : $q['from'];
        $where .= ' AND created_at >= %s';
        $params[] = $from;
    }
    if ($q['to']) {
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['to']) ? ($q['to'].' 23:59:59') : $q['to'];
        $where .= ' AND created_at <= %s';
        $params[] = $to;
    }

    if ($q['s']) {
        $like = '%' . $wpdb->esc_like($q['s']) . '%';
        $clauses = [
            'action LIKE %s',
            'object_type LIKE %s',
            'object_id LIKE %s',
            'user_login LIKE %s',
            'details LIKE %s',
            'ip LIKE %s',
        ];
        $params = array_merge($params, [$like,$like,$like,$like,$like,$like]);
        if ($has_request_id) {
            $clauses[] = 'request_id LIKE %s';
            $params[] = $like;
        }
        if ($has_user_agent) {
            $clauses[] = 'user_agent LIKE %s';
            $params[] = $like;
        }
        $where .= ' AND (' . implode(' OR ', $clauses) . ')';
    }

    $page = max(1, (int)($_GET['paged'] ?? 1));
    $per = 30;
    $offset = ($page-1)*$per;

    $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $total = $params ? (int)$wpdb->get_var($wpdb->prepare($count_sql, $params)) : (int)$wpdb->get_var($count_sql);
    $total_pages = max(1, (int)ceil($total / $per));
    $pagination_html = $this->render_pagination('fl-logs', $page, $total_pages, $q);

    $select_cols = '*';
    $sql = "SELECT {$select_cols} FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $params2 = $params ? array_merge($params, [$per, $offset]) : [$per, $offset];
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params2), ARRAY_A);

    // Distinct values for dropdowns
    $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$table} ORDER BY action ASC LIMIT 200");
    $types = $wpdb->get_col("SELECT DISTINCT object_type FROM {$table} ORDER BY object_type ASC LIMIT 200");
    $users = $wpdb->get_col("SELECT DISTINCT user_login FROM {$table} WHERE user_login IS NOT NULL AND user_login<>'' ORDER BY user_login ASC LIMIT 200");

    echo '<div class="wrap fl-wrap">';
    echo $this->page_header_html('Activity Logs', '[simku_logs]', '[simku page="logs"]');

    echo '<form method="get" class="fl-filters fl-card">';
    echo '<input type="hidden" name="page" value="fl-logs" />';
    echo '<div class="fl-filters-grid">';

    echo '<div class="fl-field fl-field-search"><label>Search</label><input type="search" name="s" value="'.esc_attr($q['s']).'" placeholder="Search…" /></div>';

    echo '<div class="fl-field"><label>Action</label><select name="action"><option value="">All</option>';
    foreach ((array)$actions as $a) {
        $a = (string)$a;
        if ($a==='') continue;
        echo '<option value="'.esc_attr($a).'" '.selected($q['action'],$a,false).'>'.esc_html($a).'</option>';
    }
    echo '</select></div>';

    echo '<div class="fl-field"><label>Object type</label><select name="object_type"><option value="">All</option>';
    foreach ((array)$types as $t) {
        $t = (string)$t;
        if ($t==='') continue;
        echo '<option value="'.esc_attr($t).'" '.selected($q['object_type'],$t,false).'>'.esc_html($t).'</option>';
    }
    echo '</select></div>';

    echo '<div class="fl-field"><label>User</label><select name="user"><option value="">All</option>';
    foreach ((array)$users as $u) {
        $u = (string)$u;
        if ($u==='') continue;
        echo '<option value="'.esc_attr($u).'" '.selected($q['user'],$u,false).'>'.esc_html($u).'</option>';
    }
    echo '</select></div>';

    echo '<div class="fl-field"><label>From</label><input type="date" name="from" value="'.esc_attr($q['from']).'" /></div>';
    echo '<div class="fl-field"><label>To</label><input type="date" name="to" value="'.esc_attr($q['to']).'" /></div>';

    echo '<div class="fl-field fl-filter-actions"><label>&nbsp;</label><button class="button button-primary" type="submit">Filter</button></div>';

    echo '</div></form>';

    if (current_user_can(self::CAP_MANAGE_SETTINGS)) {
        echo '<form method="post" class="fl-card" style="margin-top:12px;">';
        wp_nonce_field('fl_purge_logs');
        echo '<input type="hidden" name="fl_purge_logs" value="1" />';
        echo '<div class="fl-actions" style="gap:10px;align-items:center;">';
        echo '<div><b>Purge logs</b> older than</div>';
        echo '<input type="number" name="purge_days" min="1" max="3650" value="90" style="width:90px;" /> <div>days</div>';
        echo '<button class="button" type="submit">Purge</button>';
        echo '</div></form>';
    }

    echo '<div class="fl-table-wrap"><table class="widefat striped simku-table"><thead><tr>';
    $log_cols = [
        'created_at' => 'Created',
        'user_login' => 'User',
        'action' => 'Action',
        'object_type' => 'Object Type',
        'object_id' => 'Object ID',
        'ip' => 'IP',
    ];
    if ($has_request_id) $log_cols['request_id'] = 'Request ID';
    if ($has_user_agent) $log_cols['user_agent'] = 'User Agent';
    $log_cols['details'] = 'Details';

    foreach ($log_cols as $k=>$label) echo '<th>'.esc_html($label).'</th>';
    echo '</tr></thead><tbody>';

    if (!$rows) {
        echo '<tr><td colspan="'.esc_attr((string)count($log_cols)).'">No logs.</td></tr>';
    } else {
        foreach ((array)$rows as $r) {
            echo '<tr>';
            echo '<td>'.esc_html($r['created_at']).'</td>';
            echo '<td>'.esc_html($r['user_login']).'</td>';
            echo '<td>'.esc_html($r['action']).'</td>';
            echo '<td>'.esc_html($r['object_type']).'</td>';
            echo '<td><code>'.esc_html($r['object_id']).'</code></td>';
            echo '<td>'.esc_html($r['ip']).'</td>';
            if ($has_request_id) echo '<td><code>'.esc_html((string)($r['request_id'] ?? '')).'</code></td>';
            if ($has_user_agent) echo '<td style="max-width:260px;word-break:break-word;">'.esc_html((string)($r['user_agent'] ?? '')).'</td>';

            $d = (string)($r['details'] ?? '');
            $pretty = $d;
            $jd = json_decode($d, true);
            if (is_array($jd)) $pretty = wp_json_encode($jd, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            echo '<td class="fl-logs-details"><pre style="margin:0;white-space:pre-wrap;word-break:break-word;">'.esc_html($pretty).'</pre></td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table></div>';
    echo $pagination_html;
    echo '</div>';
}


}
