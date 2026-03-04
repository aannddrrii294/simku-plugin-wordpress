<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Reminders {
    public function page_reminders() {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

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
        echo '<th style="width:72px">ID</th><th>Name</th><th>Due date</th><th>Countdown</th><th>Nominal</th><th>Installments</th><th>Status</th><th>Notify</th><th>Notes</th><th>Image</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="11" class="fl-muted">No reminders found.</td></tr>';
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

                $notes = trim((string)($r['notes'] ?? ''));

                $edit_url = admin_url('admin.php?page=fl-add-reminder&edit=' . rawurlencode((string)$r['line_id']));
                $mark_url = wp_nonce_url(admin_url('admin.php?page=fl-reminders&action=mark_paid&id=' . rawurlencode((string)$r['line_id'])), 'fl_reminder_mark_paid_' . (string)$r['line_id']);

                echo '<tr>';
                echo '<td><code style="font-size:12px">'.esc_html($id_val).'</code></td>';
                echo '<td><div style="font-weight:600">'.esc_html((string)$r['payment_name']).'</div><div class="fl-muted" style="font-size:12px">'.esc_html((string)($r['payee'] ?? '')).'</div></td>';
                echo '<td>'.esc_html($due ?: '-').'</td>';
                echo '<td>'.($days_badge ?: '<span class="fl-muted">-</span>').'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((float)($r['installment_amount'] ?? 0))).'</td>';
                echo '<td>'.esc_html((string)($r['installments_paid'] ?? 0)).'/'.esc_html((string)($r['installments_total'] ?? 1)).'</td>';
                echo '<td>'.$status_badge.'</td>';
                echo '<td>'.esc_html($notify_txt).'</td>';

                if ($notes !== '') {
                    $btn_notes = '<button type="button" class="button button-small simku-rem-view-notes" data-title="'.esc_attr((string)($r['payment_name'] ?? 'Notes')).'" data-notes="'.esc_attr(wp_json_encode($notes)).'">View</button>';
                } else {
                    $btn_notes = '<span class="fl-muted">-</span>';
                }
                echo '<td>' . $btn_notes . '</td>';

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

        // Notes modal (used by the Notes column View button)
        echo '<div id="simku-rem-notes-modal" class="simku-modal" style="display:none">';
        echo '  <div class="simku-modal-backdrop"></div>';
        echo '  <div class="simku-modal-dialog">';
        echo '    <div class="simku-modal-head">';
        echo '      <div style="font-weight:600" id="simku-rem-notes-title">Notes</div>';
        echo '      <a href="#" class="simku-modal-close" aria-label="Close">&times;</a>';
        echo '    </div>';
        echo '    <div class="simku-modal-body" id="simku-rem-notes-body"></div>';
        echo '  </div>';
        echo '</div>';

        echo '</div>';
    }

    


    public function page_add_reminder() {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

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
                        $col = function($row, $keys) use ($map) {
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

                            $line_id = 'ln_' . wp_generate_uuid4();
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

            // Existing images (keep by default). We also accept a hidden keep list from the form
            // as a safety net to prevent accidental replacement during edits.
            $db_images_value = ($editing && isset($edit_row['gambar_url'])) ? $edit_row['gambar_url'] : '';
            $keep_images = (isset($_POST['existing_images']) && is_array($_POST['existing_images'])) ? $_POST['existing_images'] : [];

            $remove_images = isset($_POST['remove_images']) ? (array)$_POST['remove_images'] : [];
            $remove_images = array_values(array_filter(array_map('trim', array_map('sanitize_text_field', $remove_images))));

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

            $all_images = $this->merge_images($db_images_value, $keep_images, $uploaded_images, $url_images, $remove_images);
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
                    if (!$line_id) $line_id = 'ln_' . wp_generate_uuid4();
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
                            // IMPORTANT:
                            // Do NOT redirect from inside the admin page callback.
                            // admin.php may have already started output, causing
                            // "Cannot modify header information" warnings.
                            // Instead, show a success notice and reset the form.
                            $this->log_event('create', 'reminder', $line_id, ['payment_name'=>$payment_name]);
                            $link = ' <a href="' . esc_url(admin_url('admin.php?page=fl-add-reminder&edit=' . rawurlencode((string)$line_id))) . '">Edit reminder ini</a>.';
                            $msg = '<div class="notice notice-success"><p>Reminder created.' . $link . ' Form siap untuk input berikutnya.</p></div>';
                            $existing = null;
                            $editing = false;
                            $edit_id = '';
                        } else {
                            $msg = '<div class="notice notice-error"><p>Failed to save. '.$db->last_error.'</p></div>';
                        }
                    }
                }
            }
        }

        $val = function($k, $default = '') use ($existing) {
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
        // Keep list (safety net): ensures existing images are preserved even if the DB read fails.
        echo '<input type="hidden" name="existing_images[]" value="'.esc_attr($u_safe).'">';
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

        $tpl_url = wp_nonce_url(
            admin_url('admin.php?page=fl-add-reminder&fl_export_reminder_template=1'),
            'fl_export_reminder_template'
        );

        echo '<div class="fl-btnrow" style="margin-top:10px">';
        echo '<button class="button">Import</button> ';
        echo '<a class="button" href="'.esc_url($tpl_url).'">Download template</a>';
        echo '</div>';
        echo '</form>';

        // Modern field guide panel (Add/Edit Reminder) - English.
        echo '<div class="fl-guide-card">';
        echo   '<div class="fl-guide-header">';
        echo     '<div class="fl-guide-title">Reminder Form Guide</div>';
        echo     '<div class="fl-guide-subtitle">Short explanations for each field (what to enter, and when to leave it blank).</div>';
        echo   '</div>';

        echo   '<div class="fl-guide-body">';

        // Identification
        echo   '<details class="fl-guide-section" open>';
        echo     '<summary>Identification</summary>';
        echo     '<div class="fl-guide-content">';
        echo       '<div class="fl-guide-item"><b>Line ID (Primary Key)</b><p class="fl-guide-hint">Leave empty. The system generates a unique internal ID.</p></div>';
        echo       '<div class="fl-guide-item"><b>Reminder ID</b><p class="fl-guide-hint">Optional. If left blank, an ID is generated automatically. Use only if you need a custom reference (e.g., <code>REM-HOUSE-01</code>).</p></div>';
        echo     '</div>';
        echo   '</details>';

        // Payment details
        echo   '<details class="fl-guide-section" open>';
        echo     '<summary>Payment details</summary>';
        echo     '<div class="fl-guide-content">';
        echo       '<div class="fl-guide-item"><b>Payment name</b><p class="fl-guide-hint">Name of the bill or installment (e.g., Motor Installment, House Rent, Hosting Subscription).</p></div>';
        echo       '<div class="fl-guide-item"><b>Amount (Rp)</b><p class="fl-guide-hint">Amount per period (usually monthly). Do not enter the total contract amount here.</p></div>';
        echo       '<div class="fl-guide-item"><b>Total installments (months)</b><p class="fl-guide-hint">Number of payment periods. Enter <b>1</b> for a one-time payment.</p></div>';
        echo     '</div>';
        echo   '</details>';

        // Due date settings
        echo   '<details class="fl-guide-section" open>';
        echo     '<summary>Due date settings</summary>';
        echo     '<div class="fl-guide-content">';
        echo       '<div class="fl-guide-item"><b>Due date input</b><p class="fl-guide-hint"><b>Manual</b>: pick a fixed due date. <b>Auto monthly</b>: generates monthly due dates based on the chosen day of month.</p></div>';
        echo       '<div class="fl-guide-item"><b>Due date</b><p class="fl-guide-hint">Used in Manual mode.</p></div>';
        echo       '<div class="fl-guide-item"><b>Day of month (Auto)</b><p class="fl-guide-hint">Enter 1–31 (e.g., <b>15</b> means every 15th).</p></div>';
        echo       '<div class="fl-guide-item"><b>Auto: first due date (optional)</b><p class="fl-guide-hint">Set the starting month for auto-generated schedules (leave empty to start next cycle).</p></div>';
        echo     '</div>';
        echo   '</details>';

        // Notifications & extras
        echo   '<details class="fl-guide-section" open>';
        echo     '<summary>Notifications & extras</summary>';
        echo     '<div class="fl-guide-content">';
        echo       '<div class="fl-guide-item"><b>Status</b><p class="fl-guide-hint"><b>Unpaid</b>: not completed. <b>Paid</b>: completed.</p></div>';
        echo       '<div class="fl-guide-item"><b>Notify</b><p class="fl-guide-hint">Select channels (Telegram / WhatsApp / Email). Reminders are sent automatically on D-7, D-5, and D-3 before the due date.</p></div>';
        echo       '<div class="fl-guide-item"><b>Payment recipient</b><p class="fl-guide-hint">Optional. Who you pay (bank / leasing / provider).</p></div>';
        echo       '<div class="fl-guide-item"><b>Upload images / Image URL(s)</b><p class="fl-guide-hint">Optional. Attach proof of payment or related documents.</p></div>';
        echo     '</div>';
        echo   '</details>';

        echo   '</div>'; // body
        echo '</div>'; // card

        echo '</div>';
        echo '</div>';
    }

}
