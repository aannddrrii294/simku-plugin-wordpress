<?php
/**
 * Budgeting (Anggaran) + Budget vs Actual.
 *
 * Storage: internal WP DB table (always).
 * Actuals: computed from Transactions datasource.
 */

if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Budgets {

    private function budgets_table() {
        global $wpdb;
        return $wpdb->prefix . 'fl_budgets';
    }


// Budget goals (custom target until a date)
private function budget_goals_table() {
    global $wpdb;
    return $wpdb->prefix . 'fl_budget_goals';
}


// Budget goal allocations (map Savings entries to Saving-based Budget Targets)
private function budget_goal_allocations_table() {
    global $wpdb;
    return $wpdb->prefix . 'fl_budget_goal_allocations';
}


    /**
     * Ensure budgets table exists.
     * Safe to call on admin_init.
     */
    public function maybe_ensure_budgets_table() {
        if (!is_admin()) return;
        $this->ensure_budgets_table();
        $this->maybe_migrate_budgets_tags_schema();
        $this->ensure_budget_goals_table();
        $this->ensure_budget_goal_allocations_table();
    }

    private function ensure_budgets_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->budgets_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_ym VARCHAR(7) NOT NULL,
            category VARCHAR(20) NOT NULL,
            tag_filter VARCHAR(190) NOT NULL DEFAULT '',
            amount BIGINT NOT NULL DEFAULT 0,
            user_scope VARCHAR(60) NOT NULL DEFAULT 'all',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY period_cat_user_tag (period_ym, category, user_scope, tag_filter),
            KEY period_ym (period_ym),
            KEY category (category),
            KEY user_scope (user_scope)
        ) {$charset_collate};";

        dbDelta($sql);
    }


    /**
     * Migrate budgets table to support tag-based budgets.
     *
     * dbDelta() can add columns, but it cannot reliably drop/replace indexes.
     * Older versions had UNIQUE(period_ym, category, user_scope) which prevents
     * multiple budgets per category with different tag filters.
     */
    private function maybe_migrate_budgets_tags_schema() {
        global $wpdb;
        if (!($wpdb instanceof wpdb)) return;

        $table = $this->budgets_table();
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) return;

        // Add tag_filter column if missing
        $has_col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'tag_filter'));
        if (!$has_col) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `tag_filter` VARCHAR(190) NOT NULL DEFAULT '' AFTER `category`");
        }

        // Drop legacy unique index if present
        $has_old = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name=%s", 'period_cat_user'));
        if ($has_old) {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `period_cat_user`");
        }

        // Ensure new unique index exists
        $has_new = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name=%s", 'period_cat_user_tag'));
        if (!$has_new) {
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `period_cat_user_tag` (`period_ym`,`category`,`user_scope`,`tag_filter`)");
        }
    }

	    /**
	     * Ensure budget goals table exists.
	     * Stores custom targets (e.g., "Budget Lebaran" target Rp X until date Y).
	     */
	    private function ensure_budget_goals_table() {
	        global $wpdb;
	        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	        $table = $this->budget_goals_table();
	        $charset_collate = $wpdb->get_charset_collate();

	        // NOTE: dbDelta can add columns/indexes on update.
	        $sql = "CREATE TABLE {$table} (
	            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	            goal_name VARCHAR(190) NOT NULL,
	            basis VARCHAR(20) NOT NULL DEFAULT 'expense',
	            target_amount BIGINT NOT NULL DEFAULT 0,
	            target_date DATE NOT NULL,
	            start_date DATE NULL,
	            user_scope VARCHAR(60) NOT NULL DEFAULT 'all',
	            tag_filter TEXT NULL,
	            status VARCHAR(20) NOT NULL DEFAULT 'active',
	            created_at DATETIME NOT NULL,
	            updated_at DATETIME NOT NULL,
	            PRIMARY KEY (id),
	            UNIQUE KEY goal_unique (goal_name, basis, target_date, user_scope),
	            KEY target_date (target_date),
	            KEY user_scope (user_scope),
	            KEY basis (basis)
	        ) {$charset_collate};";

	        dbDelta($sql);
	    }


/**
 * Ensure budget goal allocations table exists.
 *
 * This table maps Savings entries (line_id) to a Saving-based Budget Target (goal_id),
 * so Saving progress can be calculated accurately.
 */
private function ensure_budget_goal_allocations_table() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = $this->budget_goal_allocations_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        saving_line_id VARCHAR(100) NOT NULL,
        goal_id BIGINT UNSIGNED NOT NULL,
        amount BIGINT NOT NULL DEFAULT 0,
        saved_at DATETIME NOT NULL,
        user_login VARCHAR(60) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY saving_line_id (saving_line_id),
        KEY goal_id (goal_id),
        KEY saved_at (saved_at),
        KEY user_login (user_login)
    ) {$charset_collate};";

    dbDelta($sql);
}


    private function simku_goal_get_by_id($id) {
	        global $wpdb;
	        $this->ensure_budget_goals_table();
	        $table = $this->budget_goals_table();
	        $row = $wpdb->get_row($wpdb->prepare(
	            "SELECT id, goal_name, basis, target_amount, target_date, start_date, user_scope, tag_filter, status, created_at, updated_at
	             FROM {$table} WHERE id=%d LIMIT 1",
	            (int)$id
	        ), ARRAY_A);
	        return is_array($row) ? $row : null;
	    }

	    private function simku_goals_list($user_scope = 'all') {
	        global $wpdb;
	        $this->ensure_budget_goals_table();
	        $table = $this->budget_goals_table();
	        $user_scope = $user_scope ? sanitize_text_field((string)$user_scope) : 'all';
	        if ($user_scope === '') $user_scope = 'all';

	        $rows = $wpdb->get_results($wpdb->prepare(
	            "SELECT id, goal_name, basis, target_amount, target_date, start_date, user_scope, tag_filter, status, created_at, updated_at
	             FROM {$table}
	             WHERE user_scope=%s
	             ORDER BY target_date ASC, id DESC",
	            $user_scope
	        ), ARRAY_A);
	        return is_array($rows) ? $rows : [];
	    }

	    	    private function simku_goal_upsert($id, $goal_name, $target_amount, $target_date_iso, $user_scope = 'all', $basis = 'expense', $start_date_iso = '', $tag_filter_csv = '') {
	        global $wpdb;
	        $this->ensure_budget_goals_table();

	        $table = $this->budget_goals_table();
	        $id = (int)$id;
	        $goal_name = sanitize_text_field((string)$goal_name);
	        $user_scope = $user_scope ? sanitize_text_field((string)$user_scope) : 'all';
	        if ($user_scope === '') $user_scope = 'all';

	        $basis = $this->normalize_category((string)$basis);
	        if (!in_array($basis, ['income','expense','saving','invest'], true)) {
	            $basis = 'expense';
	        }

	        

            // Optional tag filter (used for Income basis)
    $target_amount = (int)$target_amount;
	        $target_date_iso = sanitize_text_field((string)$target_date_iso);
	        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date_iso)) return false;
	        if ($goal_name === '') return false;

	        // Optional start_date (YYYY-MM-DD). If empty:
	        // - INSERT: default to today (WP timezone)
	        // - UPDATE: keep existing (do not change)
	        $start_date_iso = sanitize_text_field((string)$start_date_iso);
	        if ($start_date_iso !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date_iso)) {
	            return false;
	        }
	        if ($start_date_iso !== '' && strtotime($start_date_iso) > strtotime($target_date_iso)) {
	            // start_date cannot be after target_date
	            return false;
	        }

	        $now = current_time('mysql');

	        if ($id > 0) {
	            $data = [
	                'goal_name' => $goal_name,
	                'basis' => $basis,
	                'target_amount' => $target_amount,
	                'target_date' => $target_date_iso,
	                'user_scope' => $user_scope,
    'tag_filter' => $tag_filter_csv,
	                'status' => 'active',
	                'updated_at' => $now,
	            ];
	            $formats = ['%s','%s','%d','%s','%s','%s','%s','%s'];

	            if ($start_date_iso !== '') {
	                $data['start_date'] = $start_date_iso;
	                $formats[] = '%s';
	            }

	            $res = $wpdb->update($table, $data, ['id' => $id], $formats, ['%d']);
	            return $res !== false ? $id : false;
	        }

	        $start_date_db = ($start_date_iso !== '') ? $start_date_iso : wp_date('Y-m-d');

	        $res = $wpdb->insert($table, [
	            'goal_name' => $goal_name,
	            'basis' => $basis,
	            'target_amount' => $target_amount,
	            'target_date' => $target_date_iso,
	            'start_date' => $start_date_db,
	            'user_scope' => $user_scope,
            'tag_filter' => $tag_filter_csv,
            'status' => 'active',
	            'created_at' => $now,
	            'updated_at' => $now,
	        ], ['%s','%s','%d','%s','%s','%s','%s','%s','%s','%s']);

	        return $res !== false ? (int)$wpdb->insert_id : false;
	    }

private function simku_goal_delete($id) {
	        global $wpdb;
	        $this->ensure_budget_goals_table();
	        $table = $this->budget_goals_table();
	        // Clean allocations (if any)
	        if (method_exists($this, 'simku_goal_alloc_delete_by_goal')) { $this->simku_goal_alloc_delete_by_goal((int)$id); }
	        $res = $wpdb->delete($table, ['id' => (int)$id], ['%d']);
	        return $res !== false;
	    }

	    

// ===== Budget Target allocations (Savings -> Saving-based Budget Target) =====

private function simku_saving_goals_for_user($user_login = '') {
    $user_login = sanitize_text_field((string)$user_login);
    $this->ensure_budget_goals_table();

    global $wpdb;
    $table = $this->budget_goals_table();

    // Show Saving-based goals that the current user can allocate to.
    // - user_scope = 'all' OR user_scope matches user_login
    $params = [];
    $where = "basis = %s AND status = %s";
    $params[] = 'saving';
    $params[] = 'active';

    if ($user_login !== '') {
        $where .= " AND (user_scope = %s OR user_scope = %s)";
        $params[] = 'all';
        $params[] = $user_login;
    } else {
        $where .= " AND user_scope = %s";
        $params[] = 'all';
    }

    $sql = "SELECT id, goal_name, basis, target_amount, target_date, start_date, user_scope, tag_filter, status, created_at, updated_at
            FROM {$table}
            WHERE {$where}
            ORDER BY target_date ASC, id ASC";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    return is_array($rows) ? $rows : [];
}

private function simku_goal_alloc_get_by_saving_line($saving_line_id) {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();

    $table = $this->budget_goal_allocations_table();
    $saving_line_id = sanitize_text_field((string)$saving_line_id);
    if ($saving_line_id === '') return null;

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, saving_line_id, goal_id, amount, saved_at, user_login, created_at, updated_at
         FROM {$table} WHERE saving_line_id = %s LIMIT 1",
        $saving_line_id
    ), ARRAY_A);

    return is_array($row) ? $row : null;
}

private function simku_goal_alloc_upsert($saving_line_id, $goal_id, $amount, $saved_at_mysql, $user_login = '') {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();

    $table = $this->budget_goal_allocations_table();
    $saving_line_id = sanitize_text_field((string)$saving_line_id);
    $goal_id = (int)$goal_id;
    $amount = (int)$amount;
    $saved_at_mysql = sanitize_text_field((string)$saved_at_mysql);
    $user_login = sanitize_text_field((string)$user_login);

    if ($saving_line_id === '' || $goal_id <= 0) return false;
    if ($amount < 0) $amount = 0;
    if (!$saved_at_mysql) $saved_at_mysql = current_time('mysql');

    $now = current_time('mysql');

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$table} WHERE saving_line_id=%s LIMIT 1",
        $saving_line_id
    ), ARRAY_A);

    if ($existing && !empty($existing['id'])) {
        $res = $wpdb->update($table, [
            'goal_id' => $goal_id,
            'amount' => $amount,
            'saved_at' => $saved_at_mysql,
            'user_login' => $user_login ?: null,
            'updated_at' => $now,
        ], [
            'id' => (int)$existing['id'],
        ], ['%d','%d','%s','%s','%s'], ['%d']);

        return $res !== false;
    }

    $res = $wpdb->insert($table, [
        'saving_line_id' => $saving_line_id,
        'goal_id' => $goal_id,
        'amount' => $amount,
        'saved_at' => $saved_at_mysql,
        'user_login' => $user_login ?: null,
        'created_at' => $now,
        'updated_at' => $now,
    ], ['%s','%d','%d','%s','%s','%s','%s']);

    return $res !== false;
}

private function simku_goal_alloc_delete_by_saving_line($saving_line_id) {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();

    $table = $this->budget_goal_allocations_table();
    $saving_line_id = sanitize_text_field((string)$saving_line_id);
    if ($saving_line_id === '') return false;

    $res = $wpdb->delete($table, ['saving_line_id' => $saving_line_id], ['%s']);
    return $res !== false;
}

private function simku_goal_alloc_delete_by_goal($goal_id) {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();

    $table = $this->budget_goal_allocations_table();
    $goal_id = (int)$goal_id;
    if ($goal_id <= 0) return false;

    $res = $wpdb->delete($table, ['goal_id' => $goal_id], ['%d']);
    return $res !== false;
}

private function simku_goal_alloc_sum_for_goal($goal_id, $start_mysql, $end_mysql_excl) {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();

    $table = $this->budget_goal_allocations_table();
    $goal_id = (int)$goal_id;
    if ($goal_id <= 0) return 0;

    $start_mysql = sanitize_text_field((string)$start_mysql);
    $end_mysql_excl = sanitize_text_field((string)$end_mysql_excl);

    $sum = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) FROM {$table}
         WHERE goal_id=%d AND saved_at >= %s AND saved_at < %s",
        $goal_id, $start_mysql, $end_mysql_excl
    ));
    return (int)$sum;
}

private function simku_goal_alloc_map_for_saving_lines($line_ids) {
    global $wpdb;
    $this->ensure_budget_goal_allocations_table();
    $this->ensure_budget_goals_table();

    $ids = array_values(array_filter(array_map(function($v){
        $v = sanitize_text_field((string)$v);
        return $v !== '' ? $v : null;
    }, (array)$line_ids)));

    if (empty($ids)) return [];

    $alloc_table = $this->budget_goal_allocations_table();
    $goals_table = $this->budget_goals_table();

    // Build placeholders for IN
    $placeholders = implode(',', array_fill(0, count($ids), '%s'));

    $sql = "SELECT a.saving_line_id, g.goal_name
            FROM {$alloc_table} a
            LEFT JOIN {$goals_table} g ON g.id = a.goal_id
            WHERE a.saving_line_id IN ({$placeholders})";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);
    $map = [];
    foreach ((array)$rows as $r) {
        $lid = (string)($r['saving_line_id'] ?? '');
        if ($lid === '') continue;
        $map[$lid] = (string)($r['goal_name'] ?? '');
    }
    return $map;
}


private function simku_goal_with_progress($row) {
	        $g = is_array($row) ? $row : [];
	        $basis = $this->normalize_category((string)($g['basis'] ?? 'expense'));
	        if (!in_array($basis, ['income','expense','saving','invest'], true)) $basis = 'expense';

	        $target_amount = (int)($g['target_amount'] ?? 0);
	        $target_date = (string)($g['target_date'] ?? ''); // YYYY-MM-DD
	        $created_at = (string)($g['created_at'] ?? '');
	        $user_scope = (string)($g['user_scope'] ?? 'all');

	        $start_date = (string)($g['start_date'] ?? '');
	        if ($start_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
	            $start_date = '';
	        }
	        if ($start_date === '' && $created_at) {
	            $start_date = wp_date('Y-m-d', strtotime($created_at));
	        }
	        if (!$start_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
	            $start_date = wp_date('Y-m-d');
	        }

$today = wp_date('Y-m-d');
	        $progress_end = $today;
	        if ($target_date && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $target_date)) {
	            // Use the earlier date between today and target_date
	            $progress_end = (strtotime($target_date) < strtotime($today)) ? $target_date : $today;
	        }

	        if (strtotime($start_date) > strtotime($progress_end)) {
	            $start_date = $progress_end;
	        }

	        $end_excl = wp_date('Y-m-d', strtotime($progress_end . ' +1 day'));

	        $actual = 0.0;

        // Savings are stored in a separate table, so we use allocation records
        // (created when adding/editing a Saving entry) to compute progress for Saving-based targets.
        if ($basis === 'saving') {
            $start_mysql = $start_date . ' 00:00:00';
            $end_mysql_excl = $end_excl . ' 00:00:00';
            $actual = (float)$this->simku_goal_alloc_sum_for_goal((int)($g['id'] ?? 0), $start_mysql, $end_mysql_excl);
        } else if ($basis === 'income' && !empty($g['tag_filter']) && method_exists($this, 'calc_income_total_between_by_tags')) {
            $actual = (float)$this->calc_income_total_between_by_tags($start_date, $end_excl, $user_scope, (string)$g['tag_filter']);
        } else if (method_exists($this, 'calc_totals_between')) {
            // Use date basis that matches business meaning:
            // - Income: Receive Date
            // - Expense/Invest: Purchase Date
            $date_basis = 'receipt';
            if ($basis === 'income') $date_basis = 'receive';
            else if ($basis === 'expense' || $basis === 'invest') $date_basis = 'purchase';

            $calc = $this->calc_totals_between($start_date, $end_excl, $date_basis, $user_scope);
            if (is_array($calc) && isset($calc['by_cat']) && is_array($calc['by_cat'])) {
                $actual = (float)($calc['by_cat'][$basis] ?? 0);
            }
        }

	        $diff = $target_amount - (int)round($actual);
	        $pct = ($target_amount > 0) ? (($actual / $target_amount) * 100.0) : null;

	        return array_merge($g, [
	            'basis' => $basis,
	            'actual' => (int)round($actual),
	            'diff' => (int)$diff,
	            'pct' => $pct === null ? null : round($pct, 2),
	            'progress_start' => $start_date,
	            'progress_end' => $progress_end,
	        ]);
	    }

    private function simku_budget_upsert($ym, $category, $amount, $user_scope = 'all', $tag_filter_csv = '') {
        global $wpdb;
        $this->ensure_budgets_table();

        $table = $this->budgets_table();
        $ym = sanitize_text_field((string)$ym);
        $category = $this->normalize_category((string)$category);
        $user_scope = $user_scope ? sanitize_text_field((string)$user_scope) : 'all';
        if ($user_scope === '') $user_scope = 'all';

        $tag_filter_csv = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_filter_csv) : sanitize_text_field((string)$tag_filter_csv);

        $now = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE period_ym=%s AND category=%s AND user_scope=%s AND tag_filter=%s",
            $ym, $category, $user_scope, $tag_filter_csv
        ), ARRAY_A);

        if ($existing && !empty($existing['id'])) {
            $res = $wpdb->update($table, [
                'amount' => (int)$amount,
                'updated_at' => $now,
            ], [
                'id' => (int)$existing['id'],
            ], ['%d','%s'], ['%d']);
            return $res !== false;
        }

        $res = $wpdb->insert($table, [
            'period_ym' => $ym,
            'category' => $category,
            'tag_filter' => $tag_filter_csv,
            'amount' => (int)$amount,
            'user_scope' => $user_scope,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%d','%s','%s','%s']);
        return $res !== false;
    }

    private function simku_budget_delete($id) {
        global $wpdb;
        $this->ensure_budgets_table();
        $table = $this->budgets_table();
        $res = $wpdb->delete($table, ['id' => (int)$id], ['%d']);
        return $res !== false;
    }



private function simku_budget_get_by_id($id) {
    global $wpdb;
    $this->ensure_budgets_table();
    $table = $this->budgets_table();
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, period_ym, category, tag_filter, amount, user_scope, created_at, updated_at FROM {$table} WHERE id=%d LIMIT 1",
        (int)$id
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}


    private function simku_budgets_for_month($ym, $user_scope = 'all') {
        global $wpdb;
        $this->ensure_budgets_table();
        $table = $this->budgets_table();
        $ym = sanitize_text_field((string)$ym);
        $user_scope = $user_scope ? sanitize_text_field((string)$user_scope) : 'all';

        // If scope is 'all', return both 'all' scope AND per-user scope? No: keep simple.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, period_ym, category, tag_filter, amount, user_scope, created_at, updated_at
             FROM {$table}
             WHERE period_ym=%s AND user_scope=%s
             ORDER BY category ASC, tag_filter ASC, id DESC",
            $ym, $user_scope
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Return budgets with actual computed.
     * $user_scope: 'all' or a user_login.
     */
    private function simku_budgets_with_actual($ym, $user_scope = 'all') {
        $budgets = $this->simku_budgets_for_month($ym, $user_scope);

        // Compute actual totals for that month.
        $start = $ym . '-01';
        // Last day of month.
        $end = wp_date('Y-m-t', strtotime($start));

        $actuals = [
            'income' => 0.0,
            'expense' => 0.0,
            'saving' => 0.0,
            'invest' => 0.0,
        ];

        // Reuse existing total calculator (receipt basis).
        $end_excl = wp_date('Y-m-01', strtotime($start . ' +1 month'));
        $calc = $this->calc_totals_between($start, $end_excl, 'receipt', $user_scope);
        if (is_array($calc) && !empty($calc['by_cat']) && is_array($calc['by_cat'])) {
            foreach ($calc['by_cat'] as $cat => $val) {
                $cat = $this->normalize_category((string)$cat);
                if (!isset($actuals[$cat])) $actuals[$cat] = 0.0;
                $actuals[$cat] = (float)$val;
            }
        }

        $out = [];
        foreach ($budgets as $b) {
            $cat = $this->normalize_category((string)($b['category'] ?? ''));
            $tag_filter = (string)($b['tag_filter'] ?? '');
            $budget = (float)($b['amount'] ?? 0);

            // Actual: by category (default) or by tags (when tag_filter is set)
            $actual = 0.0;
            if ($tag_filter !== '' && in_array($cat, ['income','expense'], true) && method_exists($this, 'calc_category_total_between_by_tags')) {
                $actual = (float)$this->calc_category_total_between_by_tags($start, $end_excl, 'receipt', $user_scope, $cat, $tag_filter);
            } else {
                $actual = (float)($actuals[$cat] ?? 0);
            }
            $diff = $budget - $actual;
            $pct = ($budget > 0) ? (($actual / $budget) * 100.0) : null;
            $out[] = [
                'id' => (int)($b['id'] ?? 0),
                'ym' => (string)($b['period_ym'] ?? $ym),
                'category' => $cat,
                'tag_filter' => $tag_filter,
                'user' => (string)($b['user_scope'] ?? $user_scope),
                'budget' => (int)$budget,
                'actual' => (int)round($actual),
                'diff' => (int)round($diff),
                'pct' => $pct === null ? null : round($pct, 2),
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Admin page                                                          */
    /* ------------------------------------------------------------------ */

    private function simku_user_logins_list() {
        $users = get_users(['fields'=>['user_login']]);
        $user_logins = ['all'];
        foreach ((array)$users as $u) {
            if (!empty($u->user_login)) $user_logins[] = $u->user_login;
        }
        return $user_logins;
    }

    /**
     * Accept DD/MM/YYYY or YYYY-MM-DD, returns ISO YYYY-MM-DD or '' if invalid.
     */
    private function simku_parse_admin_date($raw) {
        $raw = sanitize_text_field((string)$raw);
        if ($raw === '') return '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $raw)) {
            $dt = \DateTime::createFromFormat('d/m/Y', $raw);
            if ($dt) return $dt->format('Y-m-d');
        }
        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Budget Target (Goals) form actions (admin-post.php)                 */
    /* ------------------------------------------------------------------ */

    /**
     * Save (create/update) a Budget Target.
     * Runs via admin-post.php to avoid "headers already sent" issues.
     */
    public function handle_admin_post_save_goal() {
        if (!current_user_can(self::CAP_MANAGE_BUDGETS) && !current_user_can(self::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        }

        check_admin_referer('simku_save_goal');

        $post_goal_id = (int)($_POST['goal_id'] ?? 0);
        $name_in = sanitize_text_field(wp_unslash($_POST['goal_name'] ?? ''));
        // Budget Targets are supported for Income and Savings only.
        // For legacy targets that were created earlier with a different basis, keep their original basis
        // when editing to avoid accidental changes.
        $allowed_basis = ['income','saving'];
        $basis_in = $this->normalize_category(sanitize_text_field(wp_unslash($_POST['goal_basis'] ?? 'saving')));
        if (!in_array($basis_in, $allowed_basis, true)) {
            if ($post_goal_id > 0) {
                $existing = $this->simku_goal_get_by_id($post_goal_id);
                $existing_basis = $this->normalize_category((string)($existing['basis'] ?? ''));
                if ($existing_basis !== '' && !in_array($existing_basis, $allowed_basis, true)) {
                    // Keep legacy basis for updates.
                    $basis_in = $existing_basis;
                } else {
                    $basis_in = 'saving';
                }
            } else {
                $basis_in = 'saving';
            }
        }
        $amount_in = (int)preg_replace('/[^0-9]/', '', (string)($_POST['goal_target_amount'] ?? 0));
        $date_iso = $this->simku_parse_admin_date(wp_unslash($_POST['goal_target_date'] ?? ''));
        $start_raw = sanitize_text_field(wp_unslash($_POST['goal_start_date'] ?? ''));
        $start_iso = '';
        if ($start_raw !== '') {
            $start_iso = $this->simku_parse_admin_date($start_raw);
        }
        $user_in = sanitize_text_field(wp_unslash($_POST['goal_user'] ?? 'all'));
        if ($user_in === '') $user_in = 'all';
        $income_tags_in = '';
        if ($basis_in === 'income') {
            $raw_tags = $_POST['goal_income_tags'] ?? '';
            if (is_array($raw_tags)) {
                $raw_tags = implode(',', $raw_tags);
            }
            $raw_tags = wp_unslash($raw_tags);
            $income_tags_in = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($raw_tags) : sanitize_text_field((string)$raw_tags);
        }

        // Validation
        $error_key = '';
        if ($name_in === '') {
            $error_key = 'name_required';
        } elseif ($date_iso === '') {
            $error_key = 'target_date_invalid';
        } elseif ($start_raw !== '' && $start_iso === '') {
            $error_key = 'start_date_invalid';
        } elseif ($start_iso !== '' && $date_iso !== '' && strtotime($start_iso) > strtotime($date_iso)) {
            $error_key = 'start_after_target';
        } elseif ($amount_in <= 0) {
            $error_key = 'amount_invalid';
        }

        if ($error_key !== '') {
            $redir = add_query_arg([
                'page' => 'fl-add-budgeting',
                'goal_id' => $post_goal_id > 0 ? $post_goal_id : null,
                'simku_notice' => 'goal_error_' . $error_key,
            ], admin_url('admin.php'));
            wp_safe_redirect($redir);
            exit;
        }

        $ok = $this->simku_goal_upsert($post_goal_id, $name_in, $amount_in, $date_iso, $user_in, $basis_in, $start_iso, $income_tags_in);
        if ($ok) {
            $saved_goal_id = (int)$ok;
            $saved_goal_row = $this->simku_goal_get_by_id($saved_goal_id);
            if (method_exists($this, 'simku_fire_webhooks')) {
                $payload = [
                    'id' => $saved_goal_id ?: null,
                    'name' => ($saved_goal_row['goal_name'] ?? $name_in),
                    'basis' => ($saved_goal_row['basis'] ?? $basis_in),
                    'target_amount' => (int)($saved_goal_row['target_amount'] ?? $amount_in),
                    'target_date' => ($saved_goal_row['target_date'] ?? $date_iso),
                    'start_date' => ($saved_goal_row['start_date'] ?? ($start_iso !== '' ? $start_iso : null)),
                    'user_scope' => ($saved_goal_row['user_scope'] ?? $user_in),
                    'tag_filter' => ($saved_goal_row['tag_filter'] ?? ''),
                    'status' => 'active',
                    'source' => 'admin',
                    'saved_at' => current_time('mysql'),
                ];
                $this->simku_fire_webhooks('budgeting.upserted', $payload);
            }

            $redir = add_query_arg([
                'page' => 'fl-budget-goals',
                'user' => ($saved_goal_row['user_scope'] ?? $user_in),
                'simku_notice' => 'goal_saved',
            ], admin_url('admin.php'));
            wp_safe_redirect($redir);
            exit;
        }

        // Duplicate / unknown failure
        $redir = add_query_arg([
            'page' => 'fl-add-budgeting',
            'goal_id' => $post_goal_id > 0 ? $post_goal_id : null,
            'simku_notice' => 'goal_error_save_failed',
        ], admin_url('admin.php'));
        wp_safe_redirect($redir);
        exit;
    }

    /** Delete a Budget Target. Runs via admin-post.php. */
    public function handle_admin_post_delete_goal() {
        if (!current_user_can(self::CAP_MANAGE_BUDGETS) && !current_user_can(self::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        }

        check_admin_referer('simku_delete_goal');
        $id = (int)($_POST['goal_id'] ?? 0);
        $user_scope = isset($_POST['user_scope']) ? sanitize_text_field(wp_unslash($_POST['user_scope'])) : 'all';
        if ($user_scope === '') $user_scope = 'all';

        $ok = false;
        if ($id > 0) {
            $row_before = $this->simku_goal_get_by_id($id);
            $ok = $this->simku_goal_delete($id);

            if ($ok && method_exists($this, 'simku_fire_webhooks')) {
                $payload = [
                    'id' => $id,
                    'name' => $row_before['goal_name'] ?? null,
                    'basis' => $row_before['basis'] ?? null,
                    'target_amount' => isset($row_before['target_amount']) ? (int)$row_before['target_amount'] : null,
                    'target_date' => $row_before['target_date'] ?? null,
                    'start_date' => $row_before['start_date'] ?? null,
                    'user_scope' => $row_before['user_scope'] ?? null,
                    'deleted_at' => current_time('mysql'),
                    'source' => 'admin',
                ];
                $this->simku_fire_webhooks('budgeting.deleted', $payload);
            }
        }

        $redir = add_query_arg([
            'page' => 'fl-budget-goals',
            'user' => $user_scope,
            'simku_notice' => $ok ? 'goal_deleted' : 'error',
        ], admin_url('admin.php'));
        wp_safe_redirect($redir);
        exit;
    }

    /**
     * Backward compatible (Budget vs Actual).
     */
    public function page_budgets() {
        $this->page_budget_vs_actual();
    }

    /**
     * Admin page: Budget vs Actual (monthly budgets).
     */
    public function page_budget_vs_actual() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        // Mutating actions require a stronger capability than viewing reports.
        if ((!empty($_POST['fl_save_budget']) || !empty($_POST['fl_delete_budget']))
            && !current_user_can(self::CAP_MANAGE_BUDGETS)
            && !current_user_can(self::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        }

        $ym = isset($_GET['ym']) ? sanitize_text_field(wp_unslash($_GET['ym'])) : wp_date('Y-m');
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = wp_date('Y-m');

        $user_scope = isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : 'all';
        if ($user_scope === '') $user_scope = 'all';

        $edit_budget_id = isset($_GET['edit_budget_id']) ? (int)$_GET['edit_budget_id'] : 0;
        $edit_budget = null;
        if ($edit_budget_id > 0 && method_exists($this, 'simku_budget_get_by_id')) {
            $edit_budget = $this->simku_budget_get_by_id($edit_budget_id);
        }

        $notices = [];

        if (!empty($_POST['fl_save_budget'])) {
            check_admin_referer('fl_save_budget');
            $ym_in = sanitize_text_field(wp_unslash($_POST['budget_ym'] ?? $ym));
            $cat_in = $this->normalize_category(sanitize_text_field(wp_unslash($_POST['budget_category'] ?? 'expense')));
            $amount_in = (int)preg_replace('/[^0-9]/', '', (string)($_POST['budget_amount'] ?? 0));
            $user_in = sanitize_text_field(wp_unslash($_POST['budget_user'] ?? 'all'));
            if ($user_in === '') $user_in = 'all';

            $edit_id = (int)($_POST['budget_id'] ?? 0);

            // Optional tag filter (budget by tags)
            $tags_sel = isset($_POST['budget_tags']) ? (array)wp_unslash($_POST['budget_tags']) : [];
            $tags_sel = array_values(array_filter(array_map('sanitize_text_field', $tags_sel)));
            $tags_manual = sanitize_text_field(wp_unslash($_POST['budget_tags_manual'] ?? ''));

            $tag_in = '';
            $tag_candidates = [];
            if (!empty($tags_sel)) {
                $tag_candidates = array_merge($tag_candidates, $tags_sel);
            }
            if ($tags_manual !== '') {
                $parts = preg_split('/\s*,\s*/', (string)$tags_manual);
                $parts = array_values(array_filter(array_map('sanitize_text_field', (array)$parts)));
                $tag_candidates = array_merge($tag_candidates, $parts);
            }
            if (!empty($tag_candidates)) {
                $tag_in = implode(',', $tag_candidates);
            }
            $tag_in = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_in) : sanitize_text_field((string)$tag_in);
            // Tags are supported only for Income/Expense budgets.
            if (!in_array($cat_in, ['income','expense'], true)) {
                $tag_in = '';
            }


            if (!preg_match('/^\d{4}-\d{2}$/', $ym_in)) {
                $notices[] = ['type'=>'error','msg'=>'Invalid month format.'];
            } elseif (!in_array($cat_in, ['income','expense','saving','invest'], true)) {
                $notices[] = ['type'=>'error','msg'=>'Invalid category.'];
            } else {
                // If editing an existing row and the unique key changed, delete the old row first
                if ($edit_id > 0 && method_exists($this, 'simku_budget_get_by_id')) {
                    $old = $this->simku_budget_get_by_id($edit_id);
                    if (is_array($old)) {
                        $old_ym = (string)($old['period_ym'] ?? '');
                        $old_cat = $this->normalize_category((string)($old['category'] ?? ''));
                        $old_user = (string)($old['user_scope'] ?? 'all');
                        $old_tag = (string)($old['tag_filter'] ?? '');
                        $old_tag = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($old_tag) : sanitize_text_field((string)$old_tag);
                        if ($old_ym !== $ym_in || $old_cat !== $cat_in || $old_user !== $user_in || $old_tag !== $tag_in) {
                            $this->simku_budget_delete($edit_id);
                        }
                    }
                }

                $ok = $this->simku_budget_upsert($ym_in, $cat_in, $amount_in, $user_in, $tag_in);
                if ($ok) {
                    $notices[] = ['type'=>'success','msg'=>'Budget saved.'];

                    // Fire webhooks (budget.upserted)
                    if (method_exists($this, 'simku_fire_webhooks')) {
                        $budget_row = null;
                        if (method_exists($this, 'simku_budgets_for_month')) {
                            $rows2 = $this->simku_budgets_for_month($ym_in, $user_in);
                            foreach ((array)$rows2 as $r) {
                                $rtag = (string)($r['tag_filter'] ?? '');
                                if (($r['category'] ?? '') === $cat_in && $rtag === $tag_in) { $budget_row = $r; break; }
                            }
                        }
                        $payload = [
                            'id' => $budget_row['id'] ?? null,
                            'ym' => $ym_in,
                            'category' => $cat_in,
                            'tag_filter' => $tag_in,
                            'amount' => (int)$amount_in,
                            'user_scope' => ($budget_row['user_scope'] ?? $user_in),
                            'user' => ($budget_row['user_scope'] ?? $user_in), // backward-compatible
                            'created_at' => $budget_row['created_at'] ?? null,
                            'updated_at' => $budget_row['updated_at'] ?? null,
                            'source' => 'admin',
                        ];
                        $this->simku_fire_webhooks('budget.upserted', $payload);
                    }

                    $ym = $ym_in;
                    $user_scope = $user_in;
                } else {
                    $notices[] = ['type'=>'error','msg'=>'Failed to save budget.'];
                }
            }
        }

        if (!empty($_POST['fl_delete_budget'])) {
            check_admin_referer('fl_delete_budget');
            $id = (int)($_POST['budget_id'] ?? 0);
            if ($id > 0) {
                $row_before = method_exists($this, 'simku_budget_get_by_id') ? $this->simku_budget_get_by_id($id) : null;
                $ok = $this->simku_budget_delete($id);
                $notices[] = ['type'=>$ok?'success':'error','msg'=>$ok?'Budget deleted.':'Failed to delete budget.'];
                if ($ok && method_exists($this, 'simku_fire_webhooks')) {
                    $payload = [
                        'id' => $id,
                        'ym' => $row_before['period_ym'] ?? null,
                        'category' => $row_before['category'] ?? null,
                        'amount' => isset($row_before['amount']) ? (int)$row_before['amount'] : null,
                        'user_scope' => $row_before['user_scope'] ?? null,
                        'user' => $row_before['user_scope'] ?? null, // backward-compatible
                        'deleted_at' => current_time('mysql'),
                        'source' => 'admin',
                    ];
                    $this->simku_fire_webhooks('budget.deleted', $payload);
                }
            }
        }

        $rows = $this->simku_budgets_with_actual($ym, $user_scope);
        $user_logins = $this->simku_user_logins_list();
        $tag_options = [];
        if (method_exists($this, 'list_transaction_tags')) {
            $tag_options = array_values(array_unique(array_merge(
                (array)$this->list_transaction_tags(20000, 'income'),
                (array)$this->list_transaction_tags(20000, 'expense')
            )));
            sort($tag_options, SORT_STRING);
        }


        include SIMKU_PLUGIN_DIR . 'templates/admin/budgets.php';
    }

    /**
     * Admin page: Budgeting Targets list.
     */
    public function page_budget_goals() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        // Mutating actions require a stronger capability than viewing reports.
        if ((!empty($_POST['fl_save_budget']) || !empty($_POST['fl_delete_budget']))
            && !current_user_can(self::CAP_MANAGE_BUDGETS)
            && !current_user_can(self::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        }

        $user_scope = isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : 'all';
        if ($user_scope === '') $user_scope = 'all';

        $notices = [];
        $notice_key = isset($_GET['simku_notice']) ? sanitize_text_field(wp_unslash($_GET['simku_notice'])) : '';
        if ($notice_key === 'goal_saved') {
            $notices[] = ['type'=>'success','msg'=>'Budget target saved.'];
        } elseif ($notice_key === 'goal_deleted') {
            $notices[] = ['type'=>'success','msg'=>'Budget target deleted.'];
        } elseif ($notice_key === 'error') {
            $notices[] = ['type'=>'error','msg'=>'Action failed. Please try again.'];
        }

        // Load goals
        $goals = [];
        $goal_rows = $this->simku_goals_list($user_scope);
        foreach ((array)$goal_rows as $gr) {
            $goals[] = $this->simku_goal_with_progress($gr);
        }
        $user_logins = $this->simku_user_logins_list();
        $tag_options = method_exists($this, 'list_transaction_tags') ? $this->list_transaction_tags() : [];

        include SIMKU_PLUGIN_DIR . 'templates/admin/budget-goals.php';
    }

    /**
     * Admin page: Add/Edit Budgeting Target.
     */
    public function page_add_budgeting() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        // Mutating actions require a stronger capability than viewing reports.
        if ((!empty($_POST['fl_save_budget']) || !empty($_POST['fl_delete_budget']))
            && !current_user_can(self::CAP_MANAGE_BUDGETS)
            && !current_user_can(self::CAP_MANAGE_SETTINGS)) {
            wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        }

        $goal_id = isset($_GET['goal_id']) ? (int)$_GET['goal_id'] : 0;
        $editing = $goal_id > 0;
        $goal = $editing ? $this->simku_goal_get_by_id($goal_id) : null;

        $notices = [];

        if ($editing && !is_array($goal)) {
            $notices[] = ['type'=>'error','msg'=>'Budget target not found.'];
            $editing = false;
            $goal_id = 0;
            $goal = null;
        }

        // Notice from admin-post redirect.
        if (isset($_GET['simku_notice'])) {
            $k = sanitize_text_field(wp_unslash($_GET['simku_notice']));
            if ($k === 'goal_error_name_required') {
                $notices[] = ['type'=>'error','msg'=>'Target Name is required.'];
            } elseif ($k === 'goal_error_target_date_invalid') {
                $notices[] = ['type'=>'error','msg'=>'Target Date is invalid.'];
            } elseif ($k === 'goal_error_start_date_invalid') {
                $notices[] = ['type'=>'error','msg'=>'Start Date is invalid.'];
            } elseif ($k === 'goal_error_start_after_target') {
                $notices[] = ['type'=>'error','msg'=>'Start Date must be on or before Target Date.'];
            } elseif ($k === 'goal_error_amount_invalid') {
                $notices[] = ['type'=>'error','msg'=>'Target Amount must be greater than 0.'];
            } elseif ($k === 'goal_error_save_failed') {
                $notices[] = ['type'=>'error','msg'=>'Failed to save target. Please try again.'];
            }
        }

        $user_logins = $this->simku_user_logins_list();
        $tag_options = method_exists($this, 'list_transaction_tags') ? $this->list_transaction_tags() : [];
        include SIMKU_PLUGIN_DIR . 'templates/admin/add-budget-goal.php';
    }


/**
 * Calculate Income total between dates (inclusive/exclusive) filtered by tags.
 * Dates are YYYY-MM-DD (start) and YYYY-MM-DD (end exclusive).
 * Tag filter is a CSV string (normalized) like "salary,bonus".
 */
private function calc_income_total_between_by_tags($start_date, $end_excl, $user_scope, $tag_filter_csv) {
    $db = $this->ds_db();
    if (!($db instanceof wpdb)) return 0.0;
    $table = $this->ds_table();

    $cat_col   = $this->tx_col('kategori', $db, $table);
    $price_col = $this->tx_col('harga', $db, $table);
    $qty_col   = $this->tx_col('quantity', $db, $table);

    if (!$cat_col || !$price_col || !$qty_col) return 0.0;

    // Normalize and cache by tag filter to avoid heavy repeated SUM queries on large tables.
    $tag_filter_csv = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_filter_csv) : sanitize_text_field((string)$tag_filter_csv);
    $memo_key = $start_date . '|' . $end_excl . '|receive|' . $user_scope . '|income|' . $tag_filter_csv;
    static $simku_memo = [];
    if (isset($simku_memo[$memo_key])) { return (float)$simku_memo[$memo_key]; }
    $tkey = 'simku_budget_actual_' . md5($memo_key);
    $cached = get_transient($tkey);
    if ($cached !== false && is_numeric($cached)) {
        $simku_memo[$memo_key] = (float)$cached;
        return (float)$cached;
    }


    $date_expr = $this->date_basis_expr('receive');
    $where_sql = "{$date_expr} >= %s AND {$date_expr} < %s";
    $params = [ $start_date, $end_excl ];

    // User scope filter (matches Reports/Budget Target user dropdown)
    $user_col = $this->tx_user_col();
    if ($user_scope !== null && $user_scope !== '' && $user_scope !== 'all') {
        if ($user_col === 'wp_user_id' && method_exists($this, 'ds_user_login_to_id')) {
            $uid = (int)$this->ds_user_login_to_id($user_scope);
            if ($uid > 0) {
                $where_sql .= " AND `{$user_col}` = %d";
                $params[] = $uid;
            }
        } else if ($this->ds_column_exists($user_col, $db, $table)) {
            $where_sql .= " AND `{$user_col}` = %s";
            $params[] = $user_scope;
        }
    }

    // Income only
    $where_sql .= " AND `{$cat_col}` = %s";
    $params[] = 'income';

    // Tag filter (requires 'tags' column)
    if ($tag_filter_csv && $this->ds_column_exists('tags', $db, $table)) {
        $tag_filter_csv = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_filter_csv) : sanitize_text_field((string)$tag_filter_csv);
        $tags = array_values(array_filter(array_map('trim', explode(',', $tag_filter_csv))));
        if ($tags) {
            $like_parts = [];
            foreach ($tags as $t) {
                $like_parts[] = "CONCAT(',', COALESCE(`tags`,''), ',') LIKE %s";
                $params[] = "%," . $t . ",%";
            }
            $where_sql .= " AND (" . implode(' OR ', $like_parts) . ")";
        }
    }

    $sql = "SELECT SUM(COALESCE(`{$price_col}`,0) * COALESCE(`{$qty_col}`,1)) AS total
            FROM `{$table}`
            WHERE {$where_sql}";

    $row = $db->get_row($db->prepare($sql, $params), ARRAY_A);
    $total = (float)($row['total'] ?? 0.0);

    $simku_memo[$memo_key] = $total;
    $ttl = defined('MINUTE_IN_SECONDS') ? (5 * MINUTE_IN_SECONDS) : 300;
    set_transient($tkey, $total, $ttl);

    return $total;
}



/**
 * Calculate category total between dates (inclusive/exclusive) filtered by tags.
 *
 * Used by tag-based budgets in the Budgets (Budget vs Actual) page.
 */
private function calc_category_total_between_by_tags($start_date, $end_excl, $date_basis, $user_scope, $category, $tag_filter_csv) {
    $db = $this->ds_db();
    if (!($db instanceof wpdb)) return 0.0;
    $table = $this->ds_table();

    $cat_col   = $this->tx_col('kategori', $db, $table);
    $price_col = $this->tx_col('harga', $db, $table);
    $qty_col   = $this->tx_col('quantity', $db, $table);

    if (!$cat_col || !$price_col || !$qty_col) return 0.0;

    $category = $this->normalize_category((string)$category);
    $date_basis = $this->sanitize_date_basis((string)$date_basis);

    // Normalize and cache by tag filter to avoid heavy repeated SUM queries on large tables.
    $tag_filter_csv = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_filter_csv) : sanitize_text_field((string)$tag_filter_csv);
    $memo_key = $start_date . '|' . $end_excl . '|' . $date_basis . '|' . $user_scope . '|' . $category . '|' . $tag_filter_csv;
    static $simku_memo = [];
    if (isset($simku_memo[$memo_key])) { return (float)$simku_memo[$memo_key]; }
    $tkey = 'simku_budget_actual_' . md5($memo_key);
    $cached = get_transient($tkey);
    if ($cached !== false && is_numeric($cached)) {
        $simku_memo[$memo_key] = (float)$cached;
        return (float)$cached;
    }

    $date_expr = $this->date_basis_expr($date_basis);
    $where_sql = "{$date_expr} >= %s AND {$date_expr} < %s";
    $params = [ $start_date, $end_excl ];

    // User scope filter
    $user_col = $this->tx_user_col();
    if ($user_scope !== null && $user_scope !== '' && $user_scope !== 'all') {
        if ($user_col === 'wp_user_id' && method_exists($this, 'ds_user_login_to_id')) {
            $uid = (int)$this->ds_user_login_to_id($user_scope);
            if ($uid > 0) {
                $where_sql .= " AND `{$user_col}` = %d";
                $params[] = $uid;
            }
        } else if ($this->ds_column_exists($user_col, $db, $table)) {
            $where_sql .= " AND `{$user_col}` = %s";
            $params[] = $user_scope;
        }
    }

    // Category
    $where_sql .= " AND `{$cat_col}` = %s";
    $params[] = $category;

    // Tag filter (requires 'tags' column)
    if ($tag_filter_csv && $this->ds_column_exists('tags', $db, $table)) {
        $tag_filter_csv = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tag_filter_csv) : sanitize_text_field((string)$tag_filter_csv);
        $tags = array_values(array_filter(array_map('trim', explode(',', $tag_filter_csv))));
        if ($tags) {
            $like_parts = [];
            foreach ($tags as $t) {
                $like_parts[] = "CONCAT(',', COALESCE(`tags`,''), ',') LIKE %s";
                $params[] = "%," . $t . ",%";
            }
            $where_sql .= " AND (" . implode(' OR ', $like_parts) . ")";
        }
    }

    $sql = "SELECT SUM(COALESCE(`{$price_col}`,0) * COALESCE(`{$qty_col}`,1)) AS total
            FROM `{$table}`
            WHERE {$where_sql}";

    $row = $db->get_row($db->prepare($sql, $params), ARRAY_A);
    $total = (float)($row['total'] ?? 0.0);

    $simku_memo[$memo_key] = $total;
    $ttl = defined('MINUTE_IN_SECONDS') ? (5 * MINUTE_IN_SECONDS) : 300;
    set_transient($tkey, $total, $ttl);

    return $total;
}

/**
 * Get distinct tags used in transactions.
 *
 * Notes:
 * - This scans up to $max_rows rows (ordered by newest first).
 * - Results are merged into a persistent tag index (option) so older tags
 *   won't "disappear" from the dropdown when the table is large.
 * - Returns sorted unique tags (lowercase).
 */
private function list_transaction_tags($max_rows = 5000, $category_filter = '') {
    $db = $this->ds_db();
    if (!($db instanceof wpdb)) return [];
    $table = $this->ds_table();
    if (!$this->ds_column_exists('tags', $db, $table)) return [];

    $max_rows = (int)$max_rows;
    if ($max_rows < 100) $max_rows = 100;
    if ($max_rows > 20000) $max_rows = 20000;

    $category_filter = $this->normalize_category((string)$category_filter);
    $cat_key = $category_filter !== '' ? $category_filter : 'all';

    // Persistent index (accumulative)
    $opt = 'simku_tags_index_v1';
    $idx = get_option($opt, []);
    if (!is_array($idx)) $idx = [];
    $saved = isset($idx[$cat_key]) && is_array($idx[$cat_key]) ? $idx[$cat_key] : [];
    $saved = array_values(array_filter(array_map(function($t){
        $t = strtolower(trim((string)$t));
        return $t !== '' ? preg_replace('/[^a-z0-9_-]/', '', $t) : '';
    }, $saved)));

    // Fast path: transient (per-datasource+category)
    $cache_key = 'simku_tx_tags_' . md5($table . '|' . $cat_key . '|' . $max_rows);
    $cached = get_transient($cache_key);
    if (is_array($cached) && !empty($cached)) {
        $merged = array_values(array_unique(array_merge($saved, $cached)));
        sort($merged, SORT_STRING);

        // Keep index up-to-date (accumulative)
        if (count($merged) !== count($saved)) {
            $idx[$cat_key] = $merged;
            update_option($opt, $idx, false);
        }
        return $merged;
    }

    $where = "`tags` IS NOT NULL AND `tags` <> ''";
    $params = [];

    $cat_col = $this->tx_col('kategori', $db, $table);
    if ($category_filter !== '' && $cat_col) {
        $where .= " AND `{$cat_col}` = %s";
        $params[] = $category_filter;
    }

    // Order by newest first (best effort)
    $order_expr = $this->date_basis_expr('input');
    $sql = "SELECT `tags` FROM `{$table}` WHERE {$where} ORDER BY {$order_expr} DESC LIMIT {$max_rows}";
    if ($params) {
        $sql = $db->prepare($sql, $params);
    }

    $rows = $db->get_results($sql, ARRAY_A);

    $found = [];
    foreach ((array)$rows as $r) {
        $v = (string)($r['tags'] ?? '');
        if ($v === '') continue;
        $v = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($v) : $v;
        foreach (explode(',', $v) as $t) {
            $t = trim((string)$t);
            if ($t !== '') $found[$t] = true;
        }
    }

    $fresh = array_keys($found);
    sort($fresh, SORT_STRING);

    // Merge into index so tags don't disappear later.
    $merged = array_values(array_unique(array_merge($saved, $fresh)));
    sort($merged, SORT_STRING);

    $idx[$cat_key] = $merged;
    update_option($opt, $idx, false);

    // Cache fresh scan result briefly (still merged with index on return).
    $ttl = defined('MINUTE_IN_SECONDS') ? (10 * MINUTE_IN_SECONDS) : 600;
    set_transient($cache_key, $fresh, $ttl);

    return $merged;
}

/**
 * Backward-compatible helper: Income tags.
 */
private function list_income_transaction_tags($max_rows = 5000) {
    return $this->list_transaction_tags($max_rows, 'income');
}


}
