<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Scan {
    public function page_scan_struk() {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

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
        $user_email = $current_user ? $current_user->user_email : '';

                    $nama_toko = sanitize_text_field(wp_unslash($_POST['nama_toko'] ?? ''));
                    $kategori = $this->normalize_category(sanitize_text_field(wp_unslash($_POST['kategori'] ?? 'expense')));
                    if (!in_array($kategori, ['income','expense'], true)) {
                        echo '<div class="notice notice-error"><p>Category must be Income or Expense.</p></div>';
                        return;
                    }
                    $tanggal_input_raw = sanitize_text_field(wp_unslash($_POST['tanggal_input'] ?? current_time('mysql')));
                    $tanggal_input = $this->parse_csv_datetime($tanggal_input_raw);
                    if (!$tanggal_input) $tanggal_input = current_time('mysql');

                    // Dates: Purchase Date (expense) / Receive Date (income)
                    $has_purchase_date = $this->ds_column_exists('purchase_date');
                    $has_receive_date  = $this->ds_column_exists('receive_date');
                    $purchase_date_raw = sanitize_text_field(wp_unslash($_POST['purchase_date'] ?? ''));
                    $receive_date_raw  = sanitize_text_field(wp_unslash($_POST['receive_date'] ?? ''));
                    $purchase_date = $this->parse_csv_date($purchase_date_raw);
                    $receive_date  = $this->parse_csv_date($receive_date_raw);

                    $purchase_date_db = $purchase_date ? $purchase_date : null;
                    $receive_date_db  = $receive_date ? $receive_date : null;
                    $tanggal_struk = null;
                    if ($kategori === 'income') {
                        if (!$receive_date) {
                            echo '<div class="notice notice-error"><p>Receive Date is required for Income.</p></div>';
                            return;
                        }
                        $tanggal_struk = $receive_date;
                        $purchase_date_db = null;
                    } else {
                        if (!$purchase_date) {
                            echo '<div class="notice notice-error"><p>Purchase Date is required for Expense.</p></div>';
                            return;
                        }
                        $tanggal_struk = $purchase_date;
                        $receive_date_db = null;
                    }
                    $receipt_token = sanitize_text_field(wp_unslash($_POST['receipt_token'] ?? ''));
                    $media = ['urls'=>[], 'gdrive'=>[]];

                    // If we have a scan receipt token, prefer it (more secure than trusting URL).
                    if ($receipt_token) {
                        $tkey = 'simku_scan_receipt_' . $receipt_token;
                        $tmp = get_transient($tkey);
                        if (is_array($tmp) && (int)($tmp['user_id'] ?? 0) === (int)($current_user ? $current_user->ID : 0)) {
                            $local_file = (string)($tmp['file'] ?? '');
                            $local_url  = (string)($tmp['url'] ?? '');
                            $mime       = (string)($tmp['mime'] ?? '');

                            $storage_mode = method_exists($this, 'receipts_storage_mode') ? $this->receipts_storage_mode() : 'uploads';

                            if ($storage_mode === 'gdrive' && method_exists($this, 'gdrive_upload_file') && $this->gdrive_is_configured() && $local_file && file_exists($local_file)) {
                                $up = $this->gdrive_upload_file($local_file, wp_basename($local_file), $mime ?: 'image/jpeg');
                                if (!empty($up['ok'])) {
                                    $media['gdrive'][] = ['id'=>$up['id'], 'view'=>$up['view'], 'mime'=>$up['mime']];
                                    if ($this->receipts_delete_local_after_upload()) {
                                        @unlink($local_file);
                                    }
                                } else {
                                    // fallback to URL if Drive upload fails
                                    if ($local_url) $media['urls'][] = $local_url;
                                }
                            } else {
                                if ($local_url) $media['urls'][] = $local_url;
                            }
                        }
                        // Clear token after use
                        delete_transient($tkey);
                    }

                    // Fallback: accept posted URL(s)
                    if (empty($media['urls']) && empty($media['gdrive'])) {
                        $posted_url = trim((string)wp_unslash($_POST['gambar_url'] ?? ''));
                        if ($posted_url !== '') $media['urls'][] = esc_url_raw($posted_url);
                    }

                    $gambar_url = $this->receipt_media_to_db_value($media);
                    $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));

                    // Tags: merge picker + manual input, normalize (unique + sort) to prevent duplicates.
                    $tags_pick = wp_unslash($_POST['tags_pick'] ?? []);
                    if (!is_array($tags_pick)) $tags_pick = [];
                    $tags_pick = array_values(array_filter(array_map(function($t){
                        $t = strtolower(trim((string)$t));
                        $t = preg_replace('/[^a-z0-9_-]/', '', $t);
                        return $t !== '' ? $t : '';
                    }, $tags_pick)));
                    $tags_manual = (string)wp_unslash($_POST['tags'] ?? '');
                    $tags_combined = trim(implode(',', $tags_pick) . ',' . $tags_manual, ',');
                    $tags = method_exists($this, 'normalize_tags_value') ? $this->normalize_tags_value($tags_combined) : sanitize_text_field($tags_combined);

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
                        // Base IDs
                        $base_id = '';
                        if ($line_id_input) {
                            $base_id = preg_replace('/-\d+$/', '', $line_id_input);
                        } else {
                            $base_id = 'ln_' . wp_generate_uuid4();
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
                            ];
                            if ($has_purchase_date) $data['purchase_date'] = $purchase_date_db;
                            if ($has_receive_date) $data['receive_date'] = $receive_date_db;
                            $data['gambar_url'] = $gambar_url;
                            $data['description'] = $description;
                            if (!empty($tags)) { $data['tags'] = $tags; }
                            $data['wp_user_id'] = (int)($current_user ? $current_user->ID : 0);
                            $data['wp_user_login'] = sanitize_text_field($user_login);

                            $write_data = $this->tx_map_write_data($data, $db, $table);
                            $formats = $this->tx_build_write_formats($write_data);
                            $res = $db->insert($table, $write_data, $formats);
                            if ($res === false) {
                                $failed++;
                                $fail_msgs[] = 'Failed to insert line ' . $line_id;
                                continue;
                            }
                            $created++;
                            $this->log_event('create', 'transaction', $line_id, $data);

                            if (method_exists($this, 'simku_fire_webhooks')) {
                                $payload = [
                                    'line_id' => $line_id,
                                    'transaction_id' => $transaction_id,
                                    'category' => $kategori,
                                    'counterparty' => $nama_toko,
                                    'item' => $data['items'],
                                    'qty' => (int)$data['quantity'],
                                    'price' => (int)$data['harga'],
                                    'total' => (int)((float)$data['harga'] * (float)$data['quantity']),
                                    'entry_date' => $data['tanggal_input'],
                                    'receipt_date' => $data['tanggal_struk'],
                                    'purchase_date' => $data['purchase_date'] ?? null,
                                    'receive_date' => $data['receive_date'] ?? null,
                                    'receipt_urls' => $this->receipt_urls_only((string)($data['gambar_url'] ?? '')),
                                    'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)($data['gambar_url'] ?? '')),
                                    'description' => wp_strip_all_tags((string)($data['description'] ?? '')),
                                    'user_login' => $user_login,
                                    'user_id' => (int)($current_user ? $current_user->ID : 0),
                                    'source' => 'scan',
                                ];
                                $this->simku_fire_webhooks('transaction.created', $payload);
                            }

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
                                
                            'user_email' => esc_html($user_email),'kategori' => esc_html($kategori ?? ''),
                                'toko' => esc_html($nama_toko ?? ''),
                                'item' => esc_html(implode("\n", $item_lines_txt)),
                                'qty' => esc_html(''),
                                'harga' => esc_html(''),
                                'total' => esc_html(number_format_i18n($total_sum)),
                                'tanggal_input' => esc_html($tanggal_input ?? ''),
                                'tanggal_struk' => esc_html($tanggal_struk ?? ''),
                                'transaction_id' => esc_html($transaction_id ?? ''),
                                'line_id' => esc_html($first_line_id ?? ''),
                                'gambar_url' => esc_html($this->receipt_primary_url_for_notification($gambar_url)),
                                'description' => esc_html(wp_strip_all_tags((string)($description ?? ''))),
                                'tags' => esc_html((string)($tags ?? '')),
                            ];
                            if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                            if ($send_email_new) $this->send_email_new_tx($ctx);
                            if (!empty($tags)) {
                                $parts = array_values(array_filter(array_map('trim', explode(',', (string)$tags))));
                                $parts = array_values(array_unique(array_map(function($t){ return strtolower(trim((string)$t)); }, $parts)));
                                $opt = 'simku_tags_index_v1';
                                $idx = get_option($opt, []);
                                if (!is_array($idx)) $idx = [];
                                foreach (['all', $kategori] as $k) {
                                    $cur = (isset($idx[$k]) && is_array($idx[$k])) ? $idx[$k] : [];
                                    $cur = array_values(array_filter(array_map(function($t){ return strtolower(trim((string)$t)); }, $cur)));
                                    $merged = array_values(array_unique(array_merge($cur, $parts)));
                                    sort($merged, SORT_STRING);
                                    $idx[$k] = $merged;
                                }
                                update_option($opt, $idx, false);
                            }
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

                $allowed_mimes = [
                    'jpg|jpeg' => 'image/jpeg',
                    'png'      => 'image/png',
                    'webp'     => 'image/webp',
                ];

                // Size limit (configurable in Settings -> Receipt Storage)
                $max_bytes = method_exists($this, 'receipts_max_upload_bytes') ? (int)$this->receipts_max_upload_bytes() : (8 * 1024 * 1024);
                $size = (int)($file['size'] ?? 0);
                if ($max_bytes > 0 && $size > $max_bytes) {
                    $mb = round($max_bytes / 1024 / 1024, 1);
                    $scan_error = 'File too large. Max ' . $mb . ' MB.';
                } else {
                    // Validate MIME using the actual file content (not client-provided).
                    $check = wp_check_filetype_and_ext((string)$file['tmp_name'], (string)$file['name'], $allowed_mimes);
                    $mime = (string)($check['type'] ?? '');
                    if (!$mime || !in_array($mime, array_values($allowed_mimes), true)) {
                        $scan_error = 'Unsupported file type. Use JPG/PNG/WEBP.';
                    } else {
                        $overrides = ['test_form' => false, 'mimes' => $allowed_mimes];
                        $movefile = wp_handle_upload($file, $overrides);

                        if (isset($movefile['error'])) {
                            $scan_error = 'Upload failed: ' . (string)$movefile['error'];
                        } else {
                            $uploaded = $movefile;
                            $uploaded['type'] = $mime;

                            // Create a secure token for saving later (do NOT trust posted URLs).
                            $token = preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(24, false, false));
                            if ($token) {
                                $tkey = 'simku_scan_receipt_' . $token;
                                $cu = wp_get_current_user();
                                set_transient($tkey, [
                                    'file' => (string)($movefile['file'] ?? ''),
                                    'url'  => (string)($movefile['url'] ?? ''),
                                    'mime' => $mime,
                                    'user_id' => (int)($cu ? $cu->ID : 0),
                                ], HOUR_IN_SECONDS);
                                $uploaded['token'] = $token;
                            }

                            $ocr = $this->receipt_ocr_run((string)$movefile['file']);

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
        }

	        // Diagnostics for UI (avoid PHP notices on undefined vars)
	        $n8n_url = '';
	        $use_n8n = false;
	        if (method_exists($this, 'get_n8n_scan_config')) {
	            $cfg = $this->get_n8n_scan_config();
	            if (is_array($cfg) && !empty($cfg[0])) {
	                $n8n_url = (string)$cfg[0];
	                $use_n8n = true;
	            }
	        }

	        // Legacy python OCR diagnostics
	        $script_ok = false;
	        $diag_py = '';
	        $python = defined('SIMKU_OCR_PYTHON') ? (string)SIMKU_OCR_PYTHON : 'python3';
	        if (method_exists($this, 'receipt_ocr_script_path')) {
	            $script = (string)$this->receipt_ocr_script_path();
	            $script_ok = ($script !== '' && file_exists($script));
	        }
	        // Best-effort: detect python version (safe even if disabled)
	        if (function_exists('proc_open')) {
	            $cmd = escapeshellcmd($python) . ' --version';
	            $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
	            $proc = @proc_open($cmd, $desc, $pipes);
	            if (is_resource($proc)) {
	                @fclose($pipes[0]);
	                $out = (string)@stream_get_contents($pipes[1]);
	                $err = (string)@stream_get_contents($pipes[2]);
	                @fclose($pipes[1]); @fclose($pipes[2]);
	                $code = @proc_close($proc);
	                $ver = trim($out !== '' ? $out : $err);
	                if ($code === 0 && $ver !== '') {
	                    // keep first line only
	                    $ver = preg_split('/\r\n|\r|\n/', $ver)[0] ?? $ver;
	                    $diag_py = $ver;
	                }
	            }
	        }

        // Page wrapper
        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Scan Receipt');

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
            echo '<div class="fl-help"><b>Note:</b> OCR requires a server that can run <code>python3</code> + <code>tesseract</code>. To override the Python command, set <code>SIMKU_OCR_PYTHON</code> in wp-config.php.</div>';
            echo '<div class="fl-help">Status: Script '.($script_ok?'<span class="fl-badge fl-badge-ok">OK</span>':'<span class="fl-badge fl-badge-bad">Not found</span>').' | Python: '.($diag_py?'<span class="fl-badge fl-badge-ok">'.esc_html($diag_py).'</span>':'<span class="fl-badge fl-badge-sub">unknown</span>').'</div>';
        }
        echo '<div class="fl-help"><b>Tip:</b> images are automatically compressed to reduce size (target &lt; 1.37 MB per image).</div>';
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
            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-image"><h2>Preview Image</h2>'
                .'<div class="simku-scan-preview-wrap"><img class="simku-scan-preview-img" src="'.$img.'" alt="Receipt" /></div>'
                .'<div class="fl-actions simku-scan-preview-actions"><a class="button" href="'.$img.'" target="_blank" rel="noopener noreferrer">Open full size</a></div>'
                .'</div>';
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
            if (!in_array($kategori_default, ['expense','income'], true)) $kategori_default = 'expense';
            $ocr_total = (int)($scan_result['total'] ?? 0);
            $ocr_warnings = $scan_result['warnings'] ?? [];
            if (!is_array($ocr_warnings)) $ocr_warnings = [];

            // Build defaults
            $default_tanggal_input = current_time('mysql');
            $ti_local = '';
            $ts2 = strtotime($default_tanggal_input);
            if ($ts2) $ti_local = wp_date('Y-m-d\\TH:i', $ts2);

            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-result"><h2>Scan Receipt preview</h2>';
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

            
            if ($uploaded && !empty($uploaded['token'])) {
                echo '<input type="hidden" name="receipt_token" value="'.esc_attr((string)$uploaded['token']).'" />';
            }

            // Date fields follow the category rules:
            // - Expense: Purchase Date required, Receive Date is N/A
            // - Income : Receive Date required, Purchase Date is N/A
            $default_purchase = ($kategori_default === 'income') ? '' : $tanggal_struk;
            $default_receive  = ($kategori_default === 'income') ? $tanggal_struk : '';

            // Tags picker + manual input (same pattern as Add/Edit Transaction)
            $tags_all = [];
            if (method_exists($this, 'list_transaction_tags')) {
                $tags_all = $this->list_transaction_tags(20000, '');
            }
            $tags_raw = trim((string)($scan_result['tags'] ?? ''));
            $tags_selected = [];
            if ($tags_raw !== '') {
                $tags_selected = array_values(array_filter(array_map(function($t){
                    $t = strtolower(trim((string)$t));
                    $t = preg_replace('/[^a-z0-9_-]/', '', $t);
                    return $t !== '' ? $t : '';
                }, explode(',', $tags_raw))));
            }

            // Desktop layout: compact, modern, and aligned.
            // Single column stack: Counterparty (top), Category, Entry Date, Purchase Date, Receive Date, Tags.
            echo '<div class="simku-scan-fields-grid">';
              echo '<div class="simku-scan-fields-left">';
                echo '<div class="fl-field"><label>Counterparty</label><input type="text" class="fl-input" name="nama_toko" value="'.esc_attr($merchant).'" placeholder="Example: FamilyMart / Dana / Salary / Bank Transfer" autocomplete="off" /></div>';
                echo '<div class="fl-field"><label>Category</label><select id="simku_scan_kategori" class="fl-input" name="kategori">';
                  foreach (["expense","income"] as $cat) {
                      echo '<option value="'.esc_attr($cat).'" '.selected($kategori_default,$cat,false).'>'.esc_html($this->category_label($cat)).'</option>';
                  }
                echo '</select></div>';
                echo '<div class="fl-field"><label>Entry Date</label><input type="datetime-local" class="fl-input" name="tanggal_input" value="'.esc_attr($ti_local).'" /></div>';
                echo '<div class="fl-field"><label>Purchase Date</label><input id="simku_scan_purchase_date" type="date" class="fl-input" name="purchase_date" value="'.esc_attr($default_purchase).'" /></div>';
                echo '<div class="fl-field"><label>Receive Date</label><input id="simku_scan_receive_date" type="date" class="fl-input" name="receive_date" value="'.esc_attr($default_receive).'" /></div>';

                // Tags should sit below Receive Date (to keep the left stack aligned).
                echo '<div class="fl-field simku-tags-field simku-scan-tags">';
                  echo '<label>Tags <span class="fl-muted">(comma)</span></label>';
                  echo '<select class="fl-input simku-tags-picker" name="tags_pick[]" multiple size="6">';
                    if (!empty($tags_all)) {
                        foreach ($tags_all as $t) {
                            $t = (string)$t;
                            if ($t === '') continue;
                            echo '<option value="'.esc_attr($t).'" '.(in_array($t, $tags_selected, true) ? 'selected' : '').'>'.esc_html($t).'</option>';
                        }
                    }
                  echo '</select>';
                  echo '<div class="fl-help">Pick existing tags to avoid duplicates.</div>';
                  echo '<input type="text" class="fl-input" name="tags" value="'.esc_attr($tags_raw).'" placeholder="Add new tags: pulsa,internet" style="margin-top:8px;" />';
                echo '</div>';
              echo '</div>';
            echo '</div>';

            echo '<div class="fl-field"><label>Items</label>';
            echo '<div id="simak-scan-line-items" class="simak-line-items">';
            echo '<div class="simak-line-item-head" aria-hidden="true"><span>Item</span><span>Qty</span><span>Price</span><span></span></div>';
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
            echo '<div class="fl-actions simku-scan-add-item-actions"><button type="button" class="button" id="simak-scan-add-item-row">+ Add Item</button></div>';
            echo '</div>';

            $raw_text = (string)($scan_result['raw_text'] ?? $scan_result['text'] ?? '');
            $desc_default = $raw_text ? ("OCR Raw:\n" . $raw_text) : '';
            echo '<div class="fl-field simku-scan-description">'
                .'<label>Description</label>'
                .'<textarea class="fl-input simku-scan-desc-textarea" name="description" rows="10" placeholder="Input your description here">'.esc_textarea($desc_default).'</textarea>'
                .'<div class="fl-help">Optional: store the raw OCR text as notes.</div>'
                .'</div>';

            // Notify options: stack vertically and align left.
            echo '<div class="simku-scan-notify">';
            echo '<label><input type="checkbox" name="send_telegram_new" value="1" '.checked($notify_tg_default, true, false).' /> Send Telegram notification for new transaction</label>';
            echo '<label><input type="checkbox" name="send_email_new" value="1" '.checked($notify_email_default, true, false).' /> Send Email notification for new transaction</label>';
            echo '</div>';

            echo '<div class="fl-actions simku-scan-submit-actions">';
            echo '<button class="button button-primary" type="submit">Save to Transactions</button>';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">View Transactions</a>';
            echo '</div>';

            echo '</form>';

            echo '</div>';
        } else {
            // Placeholder card in the right column (before any scan result)
            echo '<div class="fl-card fl-mt simku-scan-card simku-scan-card-result">'
                .'<h2>Scan Receipt preview</h2>'
                .'<div class="fl-help">Upload a receipt photo and click <b>Scan Receipt</b>. The parsed result will appear here for review before saving.</div>'
                .'</div>';
        }

        echo '</div>'; // simku-scan-layout

        echo '</div>'; // wrap
    }

}
