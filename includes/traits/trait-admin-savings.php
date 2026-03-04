<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Savings {
    public function page_savings() {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
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
                $row_for_webhook = null;
                if (method_exists($this, 'tx_get_row_for_ui')) {
                    $row_for_webhook = $this->tx_get_row_for_ui($db, $table, $line_id);
                }
                if ($is_ext && !$this->ds_allow_write_external()) {
                    echo '<div class="notice notice-error"><p>External savings table is read-only. Enable “Allow write to external” in Settings to delete rows.</p></div>';
                } else {
                    $res = $db->delete($table, ['line_id' => $line_id], ['%s']);
                    if ($res !== false) {
                        $this->log_event('delete', 'saving', $line_id, ['line_id' => $line_id]);
                        if (method_exists($this, 'simku_goal_alloc_delete_by_saving_line')) {
                            $this->simku_goal_alloc_delete_by_saving_line($line_id);
                        }
                        if (method_exists($this, 'simku_saving_attachments_delete_by_saving_line')) {
                            $this->simku_saving_attachments_delete_by_saving_line($line_id);
                        }
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
        // Savings trend filter: use the same layout as Reports filter so fields + button align consistently.
        echo '<form method="get" class="simku-report-filter fl-mt">';
        echo '<input type="hidden" name="page" value="fl-savings" />';
        echo '<div class="simku-filter-field simku-filter-from"><label for="simku_savings_from">From</label><input id="simku_savings_from" type="date" name="from" value="'.esc_attr($from).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-to"><label for="simku_savings_to">To</label><input id="simku_savings_to" type="date" name="to" value="'.esc_attr($to).'" /></div>';
        echo '<div class="simku-filter-field simku-filter-group"><label for="simku_savings_group">Group</label><select id="simku_savings_group" name="group">'
            .'<option value="daily"'.selected($group,'daily',false).'>Daily</option>'
            .'<option value="weekly"'.selected($group,'weekly',false).'>Weekly</option>'
            .'<option value="monthly"'.selected($group,'monthly',false).'>Monthly</option>'
            .'</select></div>';
        echo '<div class="simku-filter-actions"><button class="button">Apply</button></div>';
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
            'budget_target' => 'Budget Target',
            'amount' => 'Amount',
            'institution' => 'Stored at',
            'user' => 'User',
            'notes' => 'Notes',
            'attachments' => 'Attachments',
            'actions' => 'Actions',
        ];
        foreach ($cols as $k=>$label) echo '<th>'.esc_html($label).'</th>';
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="'.count($cols).'">No data.</td></tr>';
        } else {
            $alloc_map = [];
            $att_map = [];
            $line_ids = [];
            foreach ($rows as $rr) { $line_ids[] = (string)($rr['line_id'] ?? ''); }

            if (method_exists($this, 'simku_goal_alloc_map_for_saving_lines')) {
                $alloc_map = $this->simku_goal_alloc_map_for_saving_lines($line_ids);
            }
            if (method_exists($this, 'simku_saving_attachments_map_for_lines')) {
                $att_map = $this->simku_saving_attachments_map_for_lines($line_ids);
            }

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
                $bt = $alloc_map[$line_id] ?? '';
                echo '<td>'.esc_html($bt).'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((int)($r['amount'] ?? 0))).'</td>';
                echo '<td>'.esc_html($r['institution'] ?? '').'</td>';
                echo '<td>'.esc_html($r['wp_user_login'] ?? '').'</td>';
                echo '<td class="fl-cell-wrap">'.esc_html($r['notes'] ?? '').'</td>';

                // Attachments (images)
                $imgs = isset($att_map[$line_id]) && is_array($att_map[$line_id]) ? $att_map[$line_id] : [];
                if (!empty($imgs)) {
                    $data_urls = esc_attr(wp_json_encode(array_values($imgs)));
                    echo '<td><button type="button" class="button button-small simku-sv-view-images" data-urls="'.$data_urls.'">View ('.count($imgs).')</button></td>';
                } else {
                    echo '<td><span class="fl-muted">—</span></td>';
                }

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


        // Modal for viewing saving attachments
        echo '<div id="simku-sv-img-modal" class="simku-modal" style="display:none;">'
            .'<div class="simku-modal-backdrop"></div>'
            .'<div class="simku-modal-dialog">'
            .'<div class="simku-modal-head"><strong>Saving Attachments</strong><button type="button" class="button-link simku-modal-close" aria-label="Close">✕</button></div>'
            .'<div class="simku-modal-body" id="simku-sv-img-body"></div>'
            .'</div></div>';

        // Savings trend chart init
        // NOTE: Admin scripts (including echarts) are loaded in the footer, so we must init on window.load.



        echo '</div>';
    }

    


    public function page_add_saving() {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        $db = $this->savings_db();
        if (!($db instanceof wpdb)) {
            echo '<div class="wrap"><h1>Add Saving</h1><div class="notice notice-error"><p>Datasource error: savings DB is not available.</p></div></div>';
            return;
        }

        $table = $this->savings_table();
        $date_col = $this->savings_date_column($db, $table);
        $is_ext = $this->savings_is_external();
        $user = wp_get_current_user();

        // Budget Target allocation (for Saving-based targets)
        $saving_goals = method_exists($this, 'simku_saving_goals_for_user') ? $this->simku_saving_goals_for_user($user && !empty($user->user_login) ? $user->user_login : '') : [];
        $existing_alloc = null;
        $existing_alloc_goal_id = 0;

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

        if ($edit_mode && $edit_line_id && method_exists($this, 'simku_goal_alloc_get_by_saving_line')) {
            $existing_alloc = $this->simku_goal_alloc_get_by_saving_line($edit_line_id);
            $existing_alloc_goal_id = (int)($existing_alloc['goal_id'] ?? 0);
        }

        // Existing saving attachments (images)
        $existing_images = [];
        if ($edit_mode && $edit_line_id && method_exists($this, 'simku_saving_attachments_get_urls')) {
            $existing_images = (array)$this->simku_saving_attachments_get_urls($edit_line_id);
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
        if (!empty($_GET['err'])) {
            $err = sanitize_text_field(wp_unslash($_GET['err']));
            $err_map = [
                'db' => 'Datasource error: savings DB is not available.',
                'required' => 'Account name and amount are required.',
                'readonly' => 'External savings table is read-only. Enable “Allow write to external” in Settings to add rows.',
                'missing_id' => 'Missing line ID.',
                'update_failed' => 'Failed to update. Please check DB privileges.',
                'save_failed' => 'Failed to save. Please check DB privileges.',
            ];
            $msg = '<div class="notice notice-error"><p>'.esc_html($err_map[$err] ?? 'An error occurred. Please try again.').'</p></div>';
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
        echo '<input type="hidden" name="return_page" value="'.esc_attr($return_page).'" />';
        echo '<input type="hidden" name="return_from" value="'.esc_attr($return_from).'" />';
        echo '<input type="hidden" name="return_to" value="'.esc_attr($return_to).'" />';

        echo '<label>Line ID (PK) <input type="text" name="line_id" value="'.esc_attr($edit_mode ? ($existing['line_id'] ?? '') : '').'" '.($edit_mode ? 'readonly' : '').' placeholder="'.esc_attr($edit_mode ? '' : 'Auto generated if empty').'" /></label>';
        echo '<label>Saving ID <input type="text" name="saving_id" value="'.esc_attr($edit_mode ? ($existing['saving_id'] ?? '') : '').'" '.($edit_mode ? 'readonly' : '').' placeholder="'.esc_attr($edit_mode ? '' : 'Auto generated if empty').'" /></label>';
        echo '<label>Account name <input type="text" name="account_name" value="'.esc_attr($edit_mode ? ($existing['account_name'] ?? '') : '').'" placeholder="e.g. Emergency Fund" required /></label>';
        echo '<label>Amount (Rp) <input type="number" name="amount" value="'.esc_attr($edit_mode ? (string)($existing['amount'] ?? '') : '').'" min="0" step="1" required /></label>';

        // Optional: allocate this saving to a Saving-based Budget Target so progress is tracked.
        echo '<label>Budget Target (optional) <select name="budget_goal_id">';
        echo '<option value="0">— None —</option>';
        if (!empty($saving_goals)) {
            foreach ($saving_goals as $g) {
                $gid = (int)($g['id'] ?? 0);
                if ($gid <= 0) continue;
                $gname = (string)($g['goal_name'] ?? '');
                $gdate = (string)($g['target_date'] ?? '');
                $gamt  = (int)($g['target_amount'] ?? 0);
                $label = trim($gname);
                if ($gdate) $label .= ' — Due: ' . $gdate;
                if ($gamt > 0) $label .= ' — Target: Rp ' . number_format_i18n($gamt);
                echo '<option value="'.esc_attr($gid).'" '.selected($existing_alloc_goal_id, $gid, false).'>'.esc_html($label).'</option>';
            }
        }
        echo '</select></label>';
        echo '<label>Stored at <input type="text" name="institution" value="'.esc_attr($edit_mode ? ($existing['institution'] ?? '') : '').'" placeholder="e.g. Bank / E-Wallet / Cash" /></label>';
        $saved_at_val = $edit_mode ? ($existing[$date_col] ?? '') : '';
        $saved_at_dt = $saved_at_val ? wp_date('Y-m-d\TH:i', strtotime($saved_at_val)) : wp_date('Y-m-d\TH:i');
        echo '<label>Saved at <input type="datetime-local" name="saved_at" value="'.esc_attr($saved_at_dt).'" /></label>';
        echo '<label>Notes <textarea name="notes" rows="5" placeholder="Additional notes…">'.esc_textarea($edit_mode ? ($existing['notes'] ?? '') : '').'</textarea></label>';

        // Attachments (multiple images)
        echo '<div class="fl-field simku-saving-attachments">';
        echo   '<label for="simku_saving_images">Attachments (images)</label>';
        echo   '<input id="simku_saving_images" type="file" class="fl-input" name="saving_images[]" multiple accept="image/*" />';
        echo   '<div class="fl-help">You can select multiple images (use Ctrl / Shift when choosing files).</div>';
        if ($edit_mode && !empty($existing_images)) {
            $nimg = count($existing_images);
            echo '<div class="fl-help">Existing attachments: '.esc_html($nimg).' image(s). You can remove selected images below.</div>';
            echo '<div class="simku-img-grid simku-img-grid-sm simku-existing-attachments">';
            foreach ((array)$existing_images as $u) {
                $u = esc_url((string)$u);
                if (!$u) continue;
                echo '<label class="simku-img-tile">';
                echo '<input type="checkbox" name="saving_images_remove[]" value="'.esc_attr($u).'" /> ';
                echo '<span class="simku-img-thumb"><img src="'.esc_url($u).'" alt="attachment" loading="lazy" /></span>';
                echo '</label>';
            }
            echo '</div>';
            echo '<label class="fl-field fl-check fl-full simku-replace-all"><input type="checkbox" name="saving_images_replace_all" value="1" /> Replace all existing attachments with uploaded files</label>';
        }
        echo '</div>';

        echo '<div class="fl-btnrow simku-saving-btnrow">';
        echo '<button class="button button-primary">'.esc_html($edit_mode ? 'Update' : 'Save').'</button> ';
        echo '<a class="button" href="'.esc_url($back_url).'">Back</a>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Right column: Savings guide (modern)
echo '<div class="fl-guide-card fl-mt">';
echo   '<div class="fl-guide-header">';
echo     '<div class="fl-guide-title">Savings Form Guide</div>';
echo     '<div class="fl-guide-subtitle">Short explanations for each field (what to enter, and when to leave it blank).</div>';
echo   '</div>';
echo   '<div class="fl-guide-body">';

echo   '<details class="fl-guide-section" open><summary>Identification</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Line ID (Primary Key)</b><p class="fl-guide-hint">Leave empty. The system generates a unique internal ID.</p></div>';
echo     '<div class="fl-guide-item"><b>Saving ID</b><p class="fl-guide-hint">Optional. If left blank, an ID is generated automatically. Use only if you need a custom reference.</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section" open><summary>Details</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Account name</b><p class="fl-guide-hint">Name of the savings account (e.g., Emergency Fund, Education, Investments).</p></div>';
echo     '<div class="fl-guide-item"><b>Amount (Rp)</b><p class="fl-guide-hint">Current saved amount for this record.</p></div>';
echo     '<div class="fl-guide-item"><b>Stored at</b><p class="fl-guide-hint">Where it is kept (e.g., Bank, E‑Wallet, Cash).</p></div>';
echo     '<div class="fl-guide-item"><b>Saved at</b><p class="fl-guide-hint">Date/time of the deposit (uses WordPress timezone).</p></div>';
echo     '<div class="fl-guide-item"><b>Notes</b><p class="fl-guide-hint">Optional notes (purpose, source, etc.).</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section"><summary>Tips</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Multiple entries</b><p class="fl-guide-hint">You can add multiple savings records over time. Use the Savings page filters to view them by date range.</p></div>';
echo   '</div></details>';

echo   '</div>'; // guide body
echo '</div>'; // guide cardecho '</div>';
        echo '</div>';
    }

    

}
