<?php
/**
 * CSV parsing & bulk import helpers (moved from simku-keuangan.php to improve maintainability).
 *
 * This file is part of WP SIMKU and is loaded from includes/bootstrap.php.
 */

if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_CSV {
        private function parse_csv_datetime($val) {
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
    private function parse_csv_date($val) {
        $val = trim((string)$val);
        if ($val === '') return '';

        // Common UI format: dd/mm/YYYY
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $val, $m)) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        $ts = strtotime($val);
        if ($ts) return wp_date('Y-m-d', $ts);
        return sanitize_text_field($val);
    }

    /**
     * Normalize tags from CSV (comma/newline/semicolon separated)
     */
    private function normalize_tags_csv($tags) {
        if ($tags === null) return '';
        $tags = (string)$tags;
        $parts = preg_split('/[\s,;]+/', $tags);
        $clean = [];
        foreach ((array)$parts as $p) {
            $p = strtolower(trim((string)$p));
            if ($p === '') continue;
            $p = preg_replace('/[^a-z0-9_-]/', '', $p);
            if ($p !== '') $clean[] = $p;
        }
        $clean = array_values(array_unique($clean));
        return implode(',', $clean);
    }

    private function handle_bulk_csv_import($db, $table) {
        $current_user = wp_get_current_user();
        $user_login = $current_user && $current_user->user_login ? $current_user->user_login : 'system';
        $user_email = $current_user && !empty($current_user->user_email) ? $current_user->user_email : '';

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
            if ($k === 'purchase_date' || $k === 'purchase') $k = 'purchase_date';
            if ($k === 'receive_date' || $k === 'receive') $k = 'receive_date';
            if ($k === 'gambar_url' || $k === 'image_url' || $k === 'image') $k = 'gambar_url';
            if ($k === 'description' || $k === 'desc' || $k === 'note') $k = 'description';
            if ($k === 'user' || $k === 'user_login') $k = 'user_login';
            if ($k === 'line_id') $k = 'line_id';
            if ($k === 'transaction_id' || $k === 'tx_id') $k = 'transaction_id';
            if ($k === 'tags' || $k === 'tag' || $k === 'labels' || $k === 'label') $k = 'tags';
            if ($k === 'split_group' || $k === 'split' || $k === 'group') $k = 'split_group';

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

        // Optional columns (present in internal schema; safe for external too).
        $has_purchase_date = $this->ds_column_exists('purchase_date');
        $has_receive_date  = $this->ds_column_exists('receive_date');
        $has_user_id       = $this->ds_column_exists('wp_user_id');
        $has_user_login    = $this->ds_column_exists('wp_user_login');
        $has_tags          = $this->ds_column_exists('tags');
        $has_split_group   = $this->ds_column_exists('split_group');

        $inserted = 0; $skipped = 0; $errors = [];
        $rownum = 1;

        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
            $rownum++;
            if (!$row || (count($row) === 1 && trim((string)$row[0]) === '')) { $skipped++; continue; }

            // Build data in a deterministic order so formats align.
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
            ];
            if ($has_purchase_date) $data['purchase_date'] = '';
            if ($has_receive_date)  $data['receive_date'] = '';
            $data['gambar_url'] = '';
            $data['description'] = '';
            if ($has_user_id)    $data['wp_user_id'] = (int)($current_user ? $current_user->ID : 0);
            if ($has_user_login) $data['wp_user_login'] = sanitize_text_field($user_login);
            if ($has_split_group) $data['split_group'] = '';
            if ($has_tags) $data['tags'] = '';

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
                    case 'purchase_date':
                        if ($has_purchase_date) $data['purchase_date'] = $this->parse_csv_date($v);
                        break;
                    case 'receive_date':
                        if ($has_receive_date) $data['receive_date'] = $this->parse_csv_date($v);
                        break;
                    case 'gambar_url': $data['gambar_url'] = esc_url_raw($v); break;
                    case 'description': $data['description'] = wp_kses_post($v); break;
                    case 'user_login':
                        if ($has_user_login) $data['wp_user_login'] = sanitize_text_field($v);
                        break;
                    case 'tags':
                        if ($has_tags) $data['tags'] = $this->normalize_tags_csv($v);
                        break;
                    case 'split_group':
                        if ($has_split_group) $data['split_group'] = sanitize_text_field($v);
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
            if ($has_split_group && empty($data['split_group'])) {
                $data['split_group'] = $data['transaction_id'];
            }
            if (!$data['tanggal_input']) $data['tanggal_input'] = current_time('mysql');

            // Normalize and validate category.
            $data['kategori'] = $this->normalize_category((string)$data['kategori']);
            if (!in_array($data['kategori'], ['income','expense'], true)) {
                $skipped++;
                $errors[] = "Row {$rownum}: Category must be income or expense.";
                continue;
            }

            // Apply date rules: Income requires Receive Date, Expense requires Purchase Date.
            $purchase = $has_purchase_date ? (string)($data['purchase_date'] ?? '') : '';
            $receive  = $has_receive_date  ? (string)($data['receive_date'] ?? '') : '';
            $struk    = (string)($data['tanggal_struk'] ?? '');

            if ($data['kategori'] === 'income') {
                if (!$receive) $receive = $struk;
                if (!$receive) {
                    $skipped++;
                    $errors[] = "Row {$rownum}: Receive Date is required for income.";
                    continue;
                }
                $purchase = '';
                $struk = $receive;
            } else {
                if (!$purchase) $purchase = $struk;
                if (!$purchase) {
                    $skipped++;
                    $errors[] = "Row {$rownum}: Purchase Date is required for expense.";
                    continue;
                }
                $receive = '';
                $struk = $purchase;
            }

            $data['tanggal_struk'] = $struk;
            if ($has_purchase_date) $data['purchase_date'] = $purchase ? $purchase : null;
            if ($has_receive_date)  $data['receive_date']  = $receive ? $receive : null;

            // Formats must match the order of keys in $data.
            $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s'];
            if ($has_purchase_date) $formats[] = '%s';
            if ($has_receive_date)  $formats[] = '%s';
            $formats[] = '%s'; // gambar_url
            $formats[] = '%s'; // description
            if ($has_user_id)    $formats[] = '%d';
            if ($has_user_login) $formats[] = '%s';

            $write_data = $this->tx_map_write_data($data, $db, $table);
                                    $res = $db->insert($table, $write_data, $formats);
            if ($res === false) {
                $skipped++;
                $errors[] = "Row {$rownum}: insert failed.";
                continue;
            }

            $inserted++;
            $this->log_event('bulk_create', 'transaction', $data['line_id'], ['row' => $rownum, 'data' => $data]);

            if (method_exists($this, 'simku_fire_webhooks')) {
                $payload = [
                    'line_id' => $data['line_id'],
                    'transaction_id' => $data['transaction_id'],
                    'split_group' => $data['split_group'] ?? null,
                    'tags' => ($data['tags'] ?? '') !== '' ? array_values(array_filter(array_map('trim', explode(',', (string)($data['tags'] ?? ''))))) : [],
                    'category' => $data['kategori'],
                    'counterparty' => $data['nama_toko'],
                    'item' => $data['items'],
                    'qty' => (int)$data['quantity'],
                    'price' => (int)$data['harga'],
                    'total' => (int)((float)$data['harga'] * (float)$data['quantity']),
                    'entry_date' => $data['tanggal_input'],
                    'receipt_date' => $data['tanggal_struk'],
                    'purchase_date' => $data['purchase_date'] ?? null,
                    'receive_date' => $data['receive_date'] ?? null,
                    'receipt_urls' => $this->images_from_db_value((string)($data['gambar_url'] ?? '')),
                    'description' => wp_strip_all_tags((string)($data['description'] ?? '')),
                    'user_login' => $data['wp_user_login'] ?? '',
                    'user_id' => (int)($data['wp_user_id'] ?? 0),
                    'source' => 'csv',
                    'rownum' => (int)$rownum,
                ];
                $this->simku_fire_webhooks('transaction.created', $payload);
            }

            if ($send_tg || $send_email) {
                // Reuse per-transaction notifications (same as single add)
                $total = (float)$data['harga'] * (float)$data['quantity'];
                $ctx = [
                    'user' => esc_html($data['wp_user_login']),
                    
                    'user_email' => esc_html($user_email),'kategori' => esc_html($data['kategori'] ?? ''),
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

}
