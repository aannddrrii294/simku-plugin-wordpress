<?php
/**
 * Admin Settings template.
 *
 * Variables expected:
 * - $s (array settings)
 * - $notices (array)
 */
if (!defined('ABSPATH')) { exit; }

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html(__('Settings', self::TEXT_DOMAIN), '[simku_settings]', '[simku page="settings"]');

        foreach ($notices as $n) {
            $type = $n['type'] === 'error' ? 'notice-error' : ($n['type'] === 'warning' ? 'notice-warning' : 'notice-success');
            echo '<div class="notice '.$type.'"><p>'.esc_html($n['msg']).'</p></div>';
        }

        // Connection status badge (based on current saved settings)
        list($ok_now, $msg_now) = $this->test_connection_from_settings($this->settings());
        $badge = $ok_now ? '<span class="fl-badge fl-badge-ok">' . esc_html__("Connected", self::TEXT_DOMAIN) . '</span>' : '<span class="fl-badge fl-badge-bad">' . esc_html__("Not connected", self::TEXT_DOMAIN) . '</span>';

        echo '<form method="post" class="fl-form">';

        // Nonces (IMPORTANT: use different field names to avoid “link expired”)
        wp_nonce_field('fl_save_settings', 'fl_save_settings_nonce');
        wp_nonce_field('fl_test_connection', 'fl_test_connection_nonce');
        wp_nonce_field('fl_run_migration', 'fl_run_migration_nonce');
        wp_nonce_field('fl_create_internal_table', 'fl_create_internal_table_nonce');

        echo '<div class="fl-stack">';

        echo '<div id="fl-datasource" class="fl-card"><h2>Datasource '.$badge.'</h2>';
        echo '<p class="fl-muted">'.esc_html($msg_now).'</p>';

        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="datasource_mode">';
        foreach (['external'=>'External MySQL','internal'=>'Internal (WP DB)'] as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($s['datasource_mode'],$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';

        echo '<div class="fl-field"><label>Table</label><input name="ext_table" value="'.esc_attr($s['external']['table'] ?? 'fl_transactions').'" /></div>';
        echo '<div class="fl-field"><label>Host</label><input name="ext_host" value="'.esc_attr($s['external']['host'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>DB Name</label><input name="ext_db" value="'.esc_attr($s['external']['db'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>User</label><input name="ext_user" value="'.esc_attr($s['external']['user'] ?? '').'" /></div>';
        echo '<div class="fl-field"><label>Password</label><input type="password" name="ext_pass" value="" placeholder="Leave blank to keep existing" /></div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="ext_allow_write" value="1" '.checked(!empty($s['external']['allow_write']),true,false).' /> Allow write to external (required for Add/Edit/Delete & migration)</label></div>';
        echo '</div>';

        echo '<div class="fl-actions">';
        echo '<button class="button" name="fl_test_connection" value="1">Test Connection</button> ';
        echo '<button class="button button-primary" name="fl_save_settings" value="1">Save Settings</button>';
        echo '</div>';

        echo '<hr class="fl-hr">';

        $mode = (string)($s['datasource_mode'] ?? 'external');

        // Show the schema that matches the currently selected mode (avoid confusion).
        if ($mode === 'internal') {
            global $wpdb;
            $internal_table = $wpdb->prefix . 'fl_transactions';
            echo '<h3>Internal schema (WP DB table)</h3>';
            echo '<p class="fl-muted">Table: <code>' . esc_html($internal_table) . '</code></p>';

            $tx_schema = $this->db_get_create_table_sql($wpdb, $internal_table);
            if (!$tx_schema) {
                $tx_schema = "CREATE TABLE {$internal_table} (\n" .
                    "  line_id VARCHAR(80) NOT NULL,\n" .
                    "  transaction_id VARCHAR(64) NOT NULL,\n" .
                    "  counterparty VARCHAR(255) NULL,\n" .
                    "  items VARCHAR(255) NOT NULL,\n" .
                    "  quantity INT NOT NULL,\n" .
                    "  price BIGINT NOT NULL,\n" .
                    "  category VARCHAR(20) NULL,\n" .
                    "  entry_date DATETIME NOT NULL,\n" .
                    "  receipt_date DATE NULL,\n" .
                    "  purchase_date DATE NULL,\n" .
                    "  receive_date DATE NULL,\n" .
                    "  image_url TEXT NULL,\n" .
                    "  description LONGTEXT NULL,\n" .
                    "  wp_user_id BIGINT UNSIGNED NULL,\n" .
                    "  wp_user_login VARCHAR(60) NULL,\n" .
                    "  PRIMARY KEY (line_id),\n" .
                    "  KEY transaction_id (transaction_id),\n" .
                    "  KEY category (category),\n" .
                    "  KEY receipt_date (receipt_date),\n" .
                    "  KEY purchase_date (purchase_date),\n" .
                    "  KEY receive_date (receive_date)\n" .
                    ");";
            }
            echo '<pre class="fl-code">'.esc_html($tx_schema).'</pre>';
        } else {
            echo '<h3>External schema (matches your table)</h3>';
            $tx_table = $s['external']['table'] ?? 'fl_transactions';
            $tx_db = $this->ext_db_from_settings_array($s);
            $tx_schema = $this->db_get_create_table_sql($tx_db, $tx_table);
            if (!$tx_schema) {
                $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tx_table);
                if (!$t) $t = 'fl_transactions';
                $tx_schema = "CREATE TABLE {$t} (\n" .
                    "  line_id VARCHAR(80) NOT NULL PRIMARY KEY,\n" .
                    "  transaction_id VARCHAR(64) NOT NULL,\n" .
                    "  nama_toko VARCHAR(255) NULL,\n" .
                    "  items VARCHAR(255) NOT NULL,\n" .
                    "  quantity INT NOT NULL,\n" .
                    "  harga BIGINT NOT NULL,\n" .
                    "  kategori VARCHAR(20) NULL,\n" .
                    "  tanggal_input DATETIME NOT NULL,\n" .
                    "  tanggal_struk DATE NULL,\n" .
                    "  gambar_url TEXT NULL,\n" .
                    "  description LONGTEXT NULL,\n" .
                    "  wp_user_id BIGINT UNSIGNED NULL,\n" .
                    "  wp_user_login VARCHAR(60) NULL,\n" .
                    "  KEY transaction_id (transaction_id),\n" .
                    "  KEY kategori (kategori),\n" .
                    "  KEY tanggal_struk (tanggal_struk)\n" .
                    ");";
            }
            echo '<pre class="fl-code">'.esc_html($tx_schema).'</pre>';
        }

        echo '<div class="fl-actions">';
        echo '<button class="button" name="fl_run_migration" value="1">Run Migration (add user columns)</button> ';
        echo '<button class="button" name="fl_create_internal_table" value="1">Create Internal Table</button>';
        echo '</div>';

        echo '</div>'; // datasource card

        // Savings datasource card
        $savings_mode = (string)($s['savings']['mode'] ?? 'same');
        $savings_label_map = [
            'same' => 'Same as Transactions',
            'internal' => 'Internal (WP DB)',
            'external' => 'External (use same connection)',
        ];
        $badge_savings = '<span class="fl-badge fl-badge-sub">'.esc_html($savings_label_map[$savings_mode] ?? 'Same as Transactions').'</span>';
		echo '<div class="fl-card"><h2>Savings datasource '.$badge_savings.'</h2>';
        echo '<p class="fl-muted">Configure where Savings (Tabungan) data is stored. Default: same datasource as Transactions. If you choose External, it uses the same Host/DB/User/Password above and only changes the table name.</p>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="savings_mode">';
        foreach ($savings_label_map as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($savings_mode,$k,false).'>'.esc_html($label).'</option>';
        }
		echo '</select></div>';
		echo '<div class="fl-field"><label>External savings table</label><input name="savings_ext_table" value="'.esc_attr($s['savings']['external_table'] ?? 'fl_savings').'" placeholder="fl_savings" />';
		echo '<div class="fl-help">Used when Savings mode is External or Same-as-Transactions (and Transactions mode is External). Legacy name: <code>finance_savings</code>.</div>';
		echo '</div>';
		echo '</div>'; // grid

		$savings_is_ext = ($savings_mode === 'external' || ($savings_mode === 'same' && ($mode ?? 'external') === 'external'));
		if (!$savings_is_ext) {
			global $wpdb;
			$sv_internal = $wpdb->prefix . 'fl_savings';
			echo '<h3>Internal savings schema (WP DB table)</h3>';
			echo '<p class="fl-muted">Table: <code>' . esc_html($sv_internal) . '</code></p>';
			$sv_schema = $this->db_get_create_table_sql($wpdb, $sv_internal);
			if (!$sv_schema) {
				$sv_schema = "CREATE TABLE {$sv_internal} (\n" .
					"  line_id VARCHAR(80) NOT NULL,\n" .
					"  saving_id VARCHAR(64) NOT NULL,\n" .
					"  account_name VARCHAR(255) NOT NULL,\n" .
					"  amount BIGINT NOT NULL DEFAULT 0,\n" .
					"  institution VARCHAR(255) NULL,\n" .
					"  notes LONGTEXT NULL,\n" .
					"  saved_at DATETIME NOT NULL,\n" .
					"  wp_user_id BIGINT UNSIGNED NULL,\n" .
					"  wp_user_login VARCHAR(60) NULL,\n" .
					"  PRIMARY KEY  (line_id),\n" .
					"  KEY saved_at (saved_at),\n" .
					"  KEY wp_user_id (wp_user_id)\n" .
					");";
			}
			echo '<pre class="fl-code">'.esc_html($sv_schema).'</pre>';
		} else {
			echo '<h3>External savings schema</h3>';
			$sv_table = $s['savings']['external_table'] ?? 'fl_savings';
			$sv_db = $this->ext_db_from_settings_array($s);
			$sv_schema = $this->db_get_create_table_sql($sv_db, $sv_table);
			if (!$sv_schema) {
				$t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$sv_table);
				if (!$t) $t = 'fl_savings';
				$sv_schema = "CREATE TABLE {$t} (\n" .
					"  line_id VARCHAR(80) NOT NULL,\n" .
					"  saving_id VARCHAR(64) NOT NULL,\n" .
					"  account_name VARCHAR(255) NOT NULL,\n" .
					"  amount BIGINT NOT NULL DEFAULT 0,\n" .
					"  institution VARCHAR(255) NULL,\n" .
					"  notes LONGTEXT NULL,\n" .
					"  saved_at DATETIME NOT NULL,\n" .
					"  wp_user_id BIGINT UNSIGNED NULL,\n" .
					"  wp_user_login VARCHAR(60) NULL,\n" .
					"  PRIMARY KEY  (line_id),\n" .
					"  KEY saving_id (saving_id),\n" .
					"  KEY saved_at (saved_at)\n" .
					");";
			}
			echo '<pre class="fl-code">'.esc_html($sv_schema).'</pre>';
		}

		echo '</div>';

        // Payment Reminders datasource card
        $rem_mode = (string)($s['reminders']['mode'] ?? 'same');
        $rem_label_map = [
            'same' => 'Same as Transactions',
            'internal' => 'Internal (WP DB)',
            'external' => 'External (use same connection)',
        ];
        $badge_rem = '<span class="fl-badge fl-badge-sub">'.esc_html($rem_label_map[$rem_mode] ?? 'Same as Transactions').'</span>';
        echo '<div class="fl-card"><h2>Payment Reminders datasource '.$badge_rem.'</h2>';
        echo '<p class="fl-muted">Configure where Payment Reminders (cicilan/billing) data is stored. If you choose External, it uses the same Host/DB/User/Password above and only changes the table name.</p>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field"><label>Mode</label><select name="reminders_mode">';
        foreach ($rem_label_map as $k=>$label) {
            echo '<option value="'.esc_attr($k).'" '.selected($rem_mode,$k,false).'>'.esc_html($label).'</option>';
        }
        echo '</select></div>';
		echo '<div class="fl-field"><label>External reminders table</label><input name="reminders_ext_table" value="'.esc_attr($s['reminders']['external_table'] ?? 'fl_payment_reminders').'" placeholder="fl_payment_reminders" />';
		echo '<div class="fl-help">Used when Reminders mode is External or Same-as-Transactions (and Transactions mode is External). Legacy name: <code>finance_payment_reminders</code>.</div>';
        echo '</div>';
        echo '</div>';

		$rem_is_ext = ($rem_mode === 'external' || ($rem_mode === 'same' && ($mode ?? 'external') === 'external'));
		if (!$rem_is_ext) {
			global $wpdb;
			$rm_internal = $wpdb->prefix . 'fl_payment_reminders';
			echo '<h3>Internal reminders schema (WP DB table)</h3>';
			echo '<p class="fl-muted">Table: <code>' . esc_html($rm_internal) . '</code></p>';
			$rm_schema = $this->db_get_create_table_sql($wpdb, $rm_internal);
			if (!$rm_schema) {
				$rm_schema = "CREATE TABLE {$rm_internal} (\n" .
					"  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
					"  line_id VARCHAR(80) NOT NULL,\n" .
					"  reminder_id VARCHAR(64) NOT NULL,\n" .
					"  payment_name VARCHAR(255) NOT NULL,\n" .
					"  total_amount BIGINT NULL,\n" .
					"  installment_amount BIGINT NOT NULL,\n" .
					"  installments_total INT NOT NULL DEFAULT 1,\n" .
					"  installments_paid INT NOT NULL DEFAULT 0,\n" .
					"  schedule_mode VARCHAR(10) NOT NULL DEFAULT 'manual',\n" .
					"  due_day TINYINT UNSIGNED NULL,\n" .
					"  due_date DATE NOT NULL,\n" .
					"  payee VARCHAR(255) NULL,\n" .
					"  notes LONGTEXT NULL,\n" .
					"  gambar_url LONGTEXT NULL,\n" .
					"  status VARCHAR(10) NOT NULL DEFAULT 'belum',\n" .
					"  notify_telegram TINYINT UNSIGNED NOT NULL DEFAULT 1,\n" .
					"  notify_whatsapp TINYINT UNSIGNED NOT NULL DEFAULT 0,\n" .
					"  notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0,\n" .
					"  notified_for_due DATE NULL,\n" .
					"  notified_offsets VARCHAR(32) NULL,\n" .
					"  last_notified_at DATETIME NULL,\n" .
					"  wp_user_id BIGINT UNSIGNED NULL,\n" .
					"  wp_user_login VARCHAR(60) NULL,\n" .
					"  created_at DATETIME NOT NULL,\n" .
					"  updated_at DATETIME NOT NULL,\n" .
					"  PRIMARY KEY (id),\n" .
					"  UNIQUE KEY line_id (line_id),\n" .
					"  KEY reminder_id (reminder_id),\n" .
					"  KEY due_date (due_date),\n" .
					"  KEY status (status)\n" .
					");";
			}
			echo '<pre class="fl-code">'.esc_html($rm_schema).'</pre>';
		} else {
			echo '<h3>External reminders schema</h3>';
			$rm_table = $s['reminders']['external_table'] ?? 'fl_payment_reminders';
			$rm_db = $this->ext_db_from_settings_array($s);
			$rm_schema = $this->db_get_create_table_sql($rm_db, $rm_table);
			if (!$rm_schema) {
				$t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$rm_table);
				if (!$t) $t = 'fl_payment_reminders';
				$rm_schema = "CREATE TABLE {$t} (\n" .
					"  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n" .
					"  line_id VARCHAR(80) NOT NULL,\n" .
					"  reminder_id VARCHAR(64) NOT NULL,\n" .
					"  payment_name VARCHAR(255) NOT NULL,\n" .
					"  total_amount BIGINT NULL,\n" .
					"  installment_amount BIGINT NOT NULL,\n" .
					"  installments_total INT NOT NULL DEFAULT 1,\n" .
					"  installments_paid INT NOT NULL DEFAULT 0,\n" .
					"  schedule_mode VARCHAR(10) NOT NULL DEFAULT 'manual',\n" .
					"  due_day TINYINT UNSIGNED NULL,\n" .
					"  due_date DATE NOT NULL,\n" .
					"  payee VARCHAR(255) NULL,\n" .
					"  notes LONGTEXT NULL,\n" .
					"  gambar_url LONGTEXT NULL,\n" .
					"  status VARCHAR(10) NOT NULL DEFAULT 'belum',\n" .
					"  notify_telegram TINYINT UNSIGNED NOT NULL DEFAULT 1,\n" .
					"  notify_whatsapp TINYINT UNSIGNED NOT NULL DEFAULT 0,\n" .
					"  notify_email TINYINT UNSIGNED NOT NULL DEFAULT 0,\n" .
					"  notified_for_due DATE NULL,\n" .
					"  notified_offsets VARCHAR(32) NULL,\n" .
					"  last_notified_at DATETIME NULL,\n" .
					"  wp_user_id BIGINT UNSIGNED NULL,\n" .
					"  wp_user_login VARCHAR(60) NULL,\n" .
					"  created_at DATETIME NOT NULL,\n" .
					"  updated_at DATETIME NOT NULL,\n" .
					"  PRIMARY KEY (id),\n" .
					"  UNIQUE KEY line_id (line_id),\n" .
					"  KEY reminder_id (reminder_id),\n" .
					"  KEY due_date (due_date),\n" .
					"  KEY status (status)\n" .
					");";
			}
			echo '<pre class="fl-code">'.esc_html($rm_schema).'</pre>';
		}

        echo '</div>';

        echo '<div class="fl-card"><h2>Limits</h2>';
        echo '<div class="fl-grid fl-grid-3">';
        echo '<div class="fl-field"><label>Daily (Rp)</label><input type="number" min="0" name="limit_daily" value="'.esc_attr($s['limits']['daily'] ?? 0).'" /></div>';
        echo '<div class="fl-field"><label>Weekly (Rp)</label><input type="number" min="0" name="limit_weekly" value="'.esc_attr($s['limits']['weekly'] ?? 0).'" /></div>';
        echo '<div class="fl-field"><label>Monthly (Rp)</label><input type="number" min="0" name="limit_monthly" value="'.esc_attr($s['limits']['monthly'] ?? 0).'" /></div>';
        echo '</div>';
        echo '<div class="fl-field"><label>Expense categories (counted to limits)</label><div class="fl-check-group">';
        foreach (['outcome','saving','invest'] as $cat) {
            $checked = in_array($cat, (array)($s['limits']['expense_categories'] ?? []), true);
            echo '<label><input type="checkbox" name="expense_categories[]" value="'.esc_attr($cat).'" '.checked($checked,true,false).' /> '.esc_html($cat).'</label> ';
        }
        echo '</div></div>';
        echo '</div>';

        // Receipt Scanner (n8n)
        $has_n8n_key = !empty($s['n8n']['api_key']);
        $n8n_key_badge = $has_n8n_key ? '<span class="fl-badge fl-badge-ok">Saved</span>' : '<span class="fl-badge fl-badge-sub">Not set</span>';
        echo '<div id="fl-receipt-scanner" class="fl-card"><h2>Receipt Scanner (n8n)</h2>';
        echo '<div class="fl-help">Optional: use an n8n webhook (AI) to parse receipt images. If Webhook URL is set, Scan Receipt will use n8n instead of python OCR.</div>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field fl-full"><label>Webhook URL</label><input name="n8n_webhook_url" value="'.esc_attr($s['n8n']['webhook_url'] ?? '').'" placeholder="https://.../webhook/simku-scan-receipt" />';
        echo '<div class="fl-help">Expected response: valid JSON. Mode should return preview data.</div>';
        echo '</div>';
        echo '<div class="fl-field"><label>Timeout (seconds)</label><input type="number" min="10" max="180" name="n8n_timeout" value="'.esc_attr((int)($s['n8n']['timeout'] ?? 90)).'" /></div>';
        echo '<div class="fl-field"><label>API Key (optional)</label><input type="password" name="n8n_api_key" value="" placeholder="Leave blank to keep existing" />';
        echo '<div class="fl-help">Sent as header <code>X-API-Key</code>. ' . $n8n_key_badge . '</div>';
        echo '</div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="n8n_clear_api_key" value="1" /> Clear saved API key</label></div>';
        echo '</div>';
        echo '<div class="fl-help">Advanced: you can also define <code>SIMKU_N8N_WEBHOOK_URL</code> / <code>SIMKU_N8N_API_KEY</code> / <code>SIMKU_N8N_TIMEOUT</code> in wp-config.php (will override these settings).</div>';
        echo '</div>';


        // Receipt Storage (Uploads / Google Drive)
        $receipts = is_array($s['receipts'] ?? null) ? $s['receipts'] : [];
        $rec_storage = strtolower(trim((string)($receipts['storage'] ?? 'uploads')));
        if (!in_array($rec_storage, ['uploads','gdrive'], true)) $rec_storage = 'uploads';
        $sa_saved = !empty($receipts['gdrive_service_account_json']);
        $sa_badge = $sa_saved ? '<span class="fl-badge fl-badge-ok">Saved</span>' : '<span class="fl-badge fl-badge-sub">Not set</span>';

        echo '<div id="fl-receipt-storage" class="fl-card"><h2>Receipt Storage</h2>';
        echo '<div class="fl-help">Choose where receipt images are stored. <b>Google Drive</b> keeps receipts private and streams them via WP (requires Service Account + Folder ID shared to the service account).</div>';
        echo '<div class="fl-grid fl-grid-2">';

        echo '<div class="fl-field"><label>Storage mode</label><select name="receipts_storage">';
        echo '<option value="uploads" '.selected($rec_storage,'uploads',false).'>WordPress uploads (public URL)</option>';
        echo '<option value="gdrive" '.selected($rec_storage,'gdrive',false).'>Google Drive (private)</option>';
        echo '</select>';
        echo '<div class="fl-help">If you choose Google Drive, existing receipts in uploads will remain as URLs; new uploads will go to Drive.</div>';
        echo '</div>';

        echo '<div class="fl-field"><label>Max upload size (MB)</label><input type="number" min="1" max="50" name="receipts_max_upload_mb" value="'.esc_attr((int)($receipts['max_upload_mb'] ?? 8)).'" />';
        echo '<div class="fl-help">Applies to Scan Receipt and Transaction image uploads.</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Google Drive Folder ID</label><input class="large-text" name="receipts_gdrive_folder_id" value="'.esc_attr((string)($receipts['gdrive_folder_id'] ?? '')).'" placeholder="e.g. 1AbCDeFGhIJkLmNoPqRsTuvWxYz..." />';
        echo '<div class="fl-help">Create a folder in Google Drive, then share it with the Service Account email (client_email) as Editor.</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Service Account JSON</label>';
        echo '<textarea class="large-text code" rows="6" name="receipts_gdrive_service_json" placeholder="(leave blank to keep existing)" autocomplete="new-password"></textarea>';
        echo '<div class="fl-help">Paste the full JSON from Google Cloud (includes <code>private_key</code>). ' . $sa_badge . '</div>';
        echo '<label><input type="checkbox" name="receipts_clear_gdrive_service_json" value="1" /> Clear saved Service Account JSON</label>';
        echo '</div>';

        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="receipts_delete_local_after_upload" value="1" '.checked(!empty($receipts['delete_local_after_upload']),true,false).' /> Delete local file after upload (recommended)</label></div>';

        echo '</div>'; // grid
        echo '<div class="fl-help">Optional: you can keep the JSON out of the DB by defining a constant in wp-config.php and leaving this blank.</div>';
        echo '</div>';


        // Integrations
        $integ = is_array($s['integrations'] ?? null) ? $s['integrations'] : [];
        $chat  = is_array($s['chat'] ?? null) ? $s['chat'] : [];
        $events_def   = (array)(self::default_settings()['integrations']['webhook_events'] ?? []);
        $saved_events = is_array(($integ['webhook_events'] ?? null)) ? $integ['webhook_events'] : [];

        $api_key_badge = !empty($integ['rest_api_key'])
            ? '<span class="fl-badge fl-badge-ok">Saved</span>'
            : '<span class="fl-badge fl-badge-sub">Not set</span>';

        $wh_secret_badge = !empty($integ['webhook_secret'])
            ? '<span class="fl-badge fl-badge-ok">Saved</span>'
            : '<span class="fl-badge fl-badge-sub">Not set</span>';

        $notify_token = trim((string)($s['notify']['telegram_bot_token'] ?? ''));
        if (!empty($chat['telegram_bot_token'])) {
            $tg_token_badge = '<span class="fl-badge fl-badge-ok">Saved (Inbound)</span>';
        } elseif ($notify_token) {
            $tg_token_badge = '<span class="fl-badge fl-badge-sub">Using Notifications token</span>';
        } else {
            $tg_token_badge = '<span class="fl-badge fl-badge-sub">Not set</span>';
        }

        $tg_secret_badge = !empty($chat['telegram_webhook_secret'])
            ? '<span class="fl-badge fl-badge-ok">Saved</span>'
            : '<span class="fl-badge fl-badge-sub">Not set</span>';

        echo '<div class="fl-card"><h2>Integrations</h2>';

        /* REST API */
        echo '<div class="fl-card">';
        echo '<h2>REST API</h2>';
        echo '<p class="description">Gunakan API ini untuk input/ambil data transaksi &amp; budget dari aplikasi lain (n8n, backend, mobile app, dsb). Autentikasi bisa pakai <code>X-SIMKU-KEY</code> header atau query <code>?api_key=...</code>. Jika API key kosong, hanya user WP yang login (cookie) yang bisa akses.</p>';

        echo '<table class="form-table">';
        echo '<tr><th scope="row"><label for="int_api_key">REST API Key</label></th><td>';
        echo '<input type="password" class="regular-text" id="int_api_key" name="int_api_key" value="" placeholder="(leave blank to keep existing)" autocomplete="new-password" />';
        echo '<label style="margin-left:10px;"><input type="checkbox" name="int_clear_api_key" value="1" /> Clear saved key</label>';
        echo '<p class="description">Header contoh: <code>X-SIMKU-KEY: YOUR_KEY</code> ' . $api_key_badge . '</p>';
        echo '</td></tr>';


        $allow_query_api = !empty($integ['allow_query_api_key']);
        echo '<tr><th scope="row">Allow query api_key</th><td>';
        echo '<label><input type="checkbox" name="sec_allow_query_api_key" value="1" ' . checked($allow_query_api, true, false) . ' /> Allow using <code>?api_key=...</code> (legacy; prefer header/Bearer)</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row">Endpoints</th><td><div class="fl-help">';
        echo '<div><code>' . esc_html(rest_url('simku/v1/transactions')) . '</code> (GET, POST)</div>';
        echo '<div><code>' . esc_html(rest_url('simku/v1/budgets')) . '</code> (GET, POST)</div>';
        echo '<div><code>' . esc_html(rest_url('simku/v1/budgets/{id}')) . '</code> (DELETE)</div>';
        echo '</div></td></tr>';

        echo '</table>';
        echo '</div>';


/* Inbound webhook */
echo '<div class="fl-card">';
echo '<h2>Inbound Webhook (Signed, HTTPS-only)</h2>';
echo '<p class="description">Endpoint ini untuk <b>input transaksi dari sistem luar</b> (server, n8n, mobile app). Autentikasi wajib memakai HMAC signature + anti-replay. <b>HTTPS wajib</b>.</p>';

echo '<table class="form-table">';

echo '<tr><th scope="row">Enable Inbound</th><td>';
echo '<label><input type="checkbox" name="int_inbound_enabled" value="1" ' . checked(!empty($integ['inbound_enabled']), true, false) . ' /> Aktifkan inbound webhook</label>';
echo '</td></tr>';

echo '<tr><th scope="row"><label for="int_inbound_secret">Inbound Secret</label></th><td>';
echo '<input type="password" class="regular-text" id="int_inbound_secret" name="int_inbound_secret" value="" placeholder="(leave blank to keep existing)" autocomplete="new-password" />';
echo '<label style="margin-left:10px;"><input type="checkbox" name="int_clear_inbound_secret" value="1" /> Clear saved secret</label>';
echo '<p class="description">Digunakan untuk HMAC SHA256. ' . $inbound_badge . '</p>';
echo '</td></tr>';

echo '<tr><th scope="row"><label for="int_inbound_tolerance_sec">Timestamp tolerance</label></th><td>';
echo '<input type="number" min="30" max="3600" id="int_inbound_tolerance_sec" name="int_inbound_tolerance_sec" value="' . esc_attr((int)($integ['inbound_tolerance_sec'] ?? 300)) . '" /> <span class="description">detik (default 300)</span>';
echo '</td></tr>';

echo '<tr><th scope="row"><label for="int_inbound_rate_limit_per_min">Rate limit</label></th><td>';
echo '<input type="number" min="1" max="600" id="int_inbound_rate_limit_per_min" name="int_inbound_rate_limit_per_min" value="' . esc_attr((int)($integ['inbound_rate_limit_per_min'] ?? 60)) . '" /> <span class="description">req/menit per IP</span>';
echo '</td></tr>';

echo '<tr><th scope="row">Endpoints</th><td><div class="fl-help">';
echo '<div><code>' . esc_html(rest_url('simku/v1/inbound/transactions')) . '</code> (POST upsert, DELETE)</div>';
echo '<div><code>' . esc_html(rest_url('simku/v1/inbound/batch')) . '</code> (POST batch)</div>';
echo '</div></td></tr>';

echo '<tr><th scope="row">Auth headers</th><td><div class="fl-help">';
echo '<div><code>X-Simku-Timestamp</code> (epoch seconds)</div>';
echo '<div><code>X-Simku-Nonce</code> (random string, min 16 chars)</div>';
echo '<div><code>X-Simku-Signature</code> = <code>hex(hmac_sha256(secret, ts."."nonce."."METHOD."."ROUTE."."raw_body))</code></div>';
echo '</div></td></tr>';

echo '</table>';
echo '</div>';

        /* Webhooks */
        echo '<div class="fl-card">';
        echo '<h2>Outbound Webhooks / Google Sheets</h2>';
        echo '<p class="description">Setiap kali transaksi/budget berubah, SIMKU bisa <b>POST JSON</b> ke URL webhook (misal: n8n webhook, Apps Script Google Sheets). Jika mengisi Google Sheets Webhook URL, URL itu akan ikut dipanggil seperti webhook biasa.</p>';

        echo '<table class="form-table">';

        echo '<tr><th scope="row">Enable Webhooks</th><td>';
        echo '<label><input type="checkbox" name="int_webhooks_enabled" value="1" ' . checked(!empty($integ['webhooks_enabled']), true, false) . ' /> Aktifkan outbound webhooks</label>';
        echo '</td></tr>';


        $allow_http = !empty($integ['allow_http_webhooks']);
        echo '<tr><th scope="row">Allow HTTP URLs</th><td>';
        echo '<label><input type="checkbox" name="sec_allow_http_webhooks" value="1" ' . checked($allow_http, true, false) . ' /> Allow <code>http://</code> webhook URLs (not recommended)</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="int_webhook_timeout">Timeout</label></th><td>';
        echo '<input type="number" min="2" max="60" id="int_webhook_timeout" name="int_webhook_timeout" value="' . esc_attr((int)($integ['webhook_timeout'] ?? 12)) . '" /> <span class="description">detik</span>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="int_webhook_secret">Webhook Secret (HMAC)</label></th><td>';
        echo '<input type="password" class="regular-text" id="int_webhook_secret" name="int_webhook_secret" value="" placeholder="(leave blank to keep existing)" autocomplete="new-password" />';
        echo '<label style="margin-left:10px;"><input type="checkbox" name="int_clear_webhook_secret" value="1" /> Clear saved secret</label>';
        echo '<p class="description">Header yang dikirim: <code>X-SIMKU-Event</code>, <code>X-SIMKU-Signature</code> (HMAC SHA256 hex dari raw body) ' . $wh_secret_badge . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="int_webhook_urls">Webhook URLs</label></th><td>';
        echo '<textarea class="large-text code" rows="4" id="int_webhook_urls" name="int_webhook_urls" placeholder="https://... (1 URL per baris)">' . esc_textarea((string)($integ['webhook_urls'] ?? '')) . '</textarea>';
        echo '<p class="description">Pisahkan dengan baris baru atau koma.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="int_gsheets_webhook_url">Google Sheets Webhook URL (optional)</label></th><td>';
        echo '<input type="url" class="large-text" id="int_gsheets_webhook_url" name="int_gsheets_webhook_url" value="' . esc_attr((string)($integ['google_sheets_webhook_url'] ?? '')) . '" placeholder="Apps Script Web App URL / n8n webhook URL" />';
        echo '</td></tr>';

        echo '<tr><th scope="row">Events</th><td><div class="fl-help">';
        foreach ($events_def as $ev => $on) {
            $key = 'int_wh_event_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$ev);
            $checked_ev = array_key_exists($ev, $saved_events) ? !empty($saved_events[$ev]) : !empty($on);
            echo '<label style="display:inline-block;margin:0 14px 8px 0;">';
            echo '<input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($checked_ev, true, false) . ' /> ';
            echo '<code>' . esc_html((string)$ev) . '</code>';
            echo '</label>';
        }
        echo '</div></td></tr>';

        echo '</table>';
        echo '</div>';

        /* Telegram inbound */
        echo '<div class="fl-card">';
        echo '<h2>Input via Chat (Telegram)</h2>';
        echo '<p class="description">Kirim pesan ke bot Telegram untuk buat transaksi. Format cepat: <code>catat kopi 25k</code>, <code>catat gaji 5000000</code>, atau ketik <code>help</code> untuk bantuan. Opsional: <code>tgl:YYYY-MM-DD</code> <code>toko:Nama</code>.</p>';

        echo '<table class="form-table">';

        echo '<tr><th scope="row">Enable</th><td>';
        echo '<label><input type="checkbox" name="chat_telegram_in_enabled" value="1" ' . checked(!empty($chat['telegram_in_enabled']), true, false) . ' /> Aktifkan Telegram inbound</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chat_telegram_bot_token">Bot Token (optional)</label></th><td>';
        echo '<input type="password" class="large-text" id="chat_telegram_bot_token" name="chat_telegram_bot_token" value="" placeholder="(leave blank to keep existing; kosong = pakai token Notifications)" autocomplete="new-password" />';
        echo '<label style="margin-left:10px;"><input type="checkbox" name="chat_clear_telegram_bot_token" value="1" /> Clear saved token</label>';
        echo '<p class="description">Jika dikosongkan, SIMKU akan memakai <b>Telegram Bot Token</b> dari bagian Notifications. ' . $tg_token_badge . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="chat_telegram_webhook_secret">Webhook Secret</label></th><td>';
        echo '<input type="password" class="regular-text" id="chat_telegram_webhook_secret" name="chat_telegram_webhook_secret" value="" placeholder="(leave blank to keep existing)" autocomplete="new-password" />';
        echo '<label style="margin-left:10px;"><input type="checkbox" name="chat_clear_telegram_secret" value="1" /> Clear saved secret</label>';
        echo '<p class="description">Endpoint: <code>' . esc_html(rest_url('simku/v1/telegram/webhook')) . '</code>. Recommended: setWebhook with <code>secret_token</code> so Telegram sends header <code>X-Telegram-Bot-Api-Secret-Token</code>. Legacy: you can use query <code>?secret=...</code> if enabled. ' . $tg_secret_badge . '</p>';
        echo '</td></tr>';


        $allow_q_secret = !empty($chat['telegram_allow_query_secret']);
        echo '<tr><th scope="row">Allow query secret</th><td>';
        echo '<label><input type="checkbox" name="chat_telegram_allow_query_secret" value="1" ' . checked($allow_q_secret, true, false) . ' /> Allow using <code>?secret=...</code> (legacy; not recommended)</label>';
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="chat_telegram_allowed_chat_ids">Allowed Chat IDs</label></th><td>';
        echo '<textarea class="large-text code" rows="3" id="chat_telegram_allowed_chat_ids" name="chat_telegram_allowed_chat_ids" placeholder="123456789&#10;-1001234567890">' . esc_textarea((string)($chat['telegram_allowed_chat_ids'] ?? '')) . '</textarea>';
        echo '<p class="description">Opsional (kosong = izinkan semua). Bisa 1 per baris atau dipisah koma. Group ID biasanya negatif.</p>';
        echo '</td></tr>';

        echo '</table>';
        echo '</div>'; // telegram card

        echo '</div>'; // integrations card

        echo '<div class="fl-card"><h2>Notifications</h2>';

        // GENERAL
        echo '<div class="simku-settings-section">';
        echo '<h3>General</h3>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field fl-check fl-full"><label><input type="checkbox" name="notify_on_limit" value="1" '.checked(!empty($s['notify']['notify_on_limit']),true,false).' /> Notify when limits reached</label></div>';
        echo '</div>';
        echo '</div>';

        echo '<hr class="fl-hr">';

        // EMAIL
        echo '<div class="simku-settings-section">';
        echo '<h3>Email</h3>';
        echo '<div class="fl-grid fl-grid-2">';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="email_enabled" value="1" '.checked(!empty($s['notify']['email_enabled']),true,false).' /> Email enabled</label></div>';
        echo '<div class="fl-field"><label>Email to</label><input name="email_to" value="'.esc_attr($s['notify']['email_to'] ?? '').'" /></div>';

        echo '<div class="fl-field"><label>New transaction recipient</label>'
            . '<select name="email_new_to_mode">'
            . '<option value="settings" '.selected(($s['notify']['email_new_to_mode'] ?? 'settings'),'settings',false).'>Use Email to (setting)</option>'
            . '<option value="user" '.selected(($s['notify']['email_new_to_mode'] ?? 'settings'),'user',false).'>Current user email</option>'
            . '<option value="both" '.selected(($s['notify']['email_new_to_mode'] ?? 'settings'),'both',false).'>Both</option>'
            . '</select></div>';

        echo '<div class="fl-field"><label>From email</label><input name="email_from" value="'.esc_attr($s['notify']['email_from'] ?? '').'" placeholder="info@domain.com" /></div>';
        echo '<div class="fl-field"><label>From name</label><input name="email_from_name" value="'.esc_attr($s['notify']['email_from_name'] ?? '').'" placeholder="'.esc_attr(get_bloginfo('name')).'" /></div>';

        echo '<div class="fl-field fl-check fl-full"><label><input type="checkbox" name="email_new_default" value="1" '.checked(!empty($s['notify']['email_notify_new_tx_default']),true,false).' /> Default: notify Email on new transaction</label></div>';

        echo '<div class="fl-field fl-full"><label>Email subject template <span class="fl-muted">(new transaction)</span></label>';
        echo '<input name="email_new_subject_tpl" value="'.esc_attr($s['notify']['email_new_subject_tpl'] ?? '').'" placeholder="New transaction: {item} (Rp {total})" />';
        echo '<div class="fl-help">Placeholders: {user}, {kategori}, {toko}, {item}, {qty}, {harga}, {total}, {tanggal_input}, {tanggal_struk}, {transaction_id}, {line_id}, {gambar_url}, {description}</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email body template <span class="fl-muted">(new transaction)</span></label>';
        echo '<textarea class="fl-template" name="email_new_body_tpl" rows="8" placeholder="User: {user}\nCategory: {kategori}\nTotal: Rp {total}">'.esc_textarea($s['notify']['email_new_body_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">You can use new lines. Unused placeholders will be removed automatically.</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email subject template <span class="fl-muted">(payment reminder)</span></label>';
        echo '<input name="reminder_email_subject_tpl" value="'.esc_attr($s['notify']['reminder_email_subject_tpl'] ?? '').'" placeholder="Payment reminder: {payment_name} (D-{days_left})" />';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Email body template <span class="fl-muted">(payment reminder)</span></label>';
        echo '<textarea class="fl-template" name="reminder_email_body_tpl" rows="8" placeholder="PAYMENT REMINDER\nPayment: {payment_name}\nDue date: {due_date} (D-{days_left})\nAmount: Rp {installment_amount}">'.esc_textarea($s['notify']['reminder_email_body_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">You can use new lines. Unused placeholders will be removed automatically.</div>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // section

        echo '<hr class="fl-hr">';

        // TELEGRAM / WHATSAPP
        echo '<div class="simku-settings-section">';
        echo '<h3>Telegram & WhatsApp</h3>';
        echo '<div class="fl-grid fl-grid-2">';

        echo '<div class="fl-field fl-check fl-full"><label><input type="checkbox" name="telegram_enabled" value="1" '.checked(!empty($s['notify']['telegram_enabled']),true,false).' /> Telegram enabled</label></div>';

        echo '<div class="fl-field fl-full"><label>Telegram bot token</label>';
        echo '<input type="password" name="telegram_bot_token" value="" placeholder="(leave blank to keep existing)" autocomplete="new-password" />';
        echo '<div class="fl-help" style="margin-top:6px;"><label><input type="checkbox" name="telegram_clear_bot_token" value="1" /> Clear saved token</label> '.($notify_token ? '<span class="fl-badge fl-badge-ok" style="margin-left:8px;">Saved</span>' : '<span class="fl-badge fl-badge-sub" style="margin-left:8px;">Not set</span>').'</div>';
        echo '</div>';

        echo '<div class="fl-field"><label>Telegram chat id</label><input name="telegram_chat_id" value="'.esc_attr($s['notify']['telegram_chat_id'] ?? '').'" /></div>';
        echo '<div class="fl-field fl-check"><label><input type="checkbox" name="telegram_allow_insecure_tls" value="1" '.checked(!empty($s['notify']['telegram_allow_insecure_tls']),true,false).' /> Allow insecure TLS for Telegram (not recommended)</label></div>';

        echo '<div class="fl-field fl-check fl-full"><label><input type="checkbox" name="telegram_new_default" value="1" '.checked(!empty($s['notify']['telegram_notify_new_tx_default']),true,false).' /> Default: notify Telegram on new transaction</label></div>';

        echo '<div class="fl-field fl-full"><label>WhatsApp webhook URL (optional)</label><input name="whatsapp_webhook" value="'.esc_attr($s['notify']['whatsapp_webhook'] ?? '').'" /></div>';

        echo '<div class="fl-field fl-full"><label>Telegram template <span class="fl-muted">(new transaction)</span></label>';
        echo '<textarea class="fl-template" name="telegram_new_tpl" rows="8" placeholder="✅ <b>New transaction</b>\nUser: <b>{user}</b>\nTotal: <b>Rp {total}</b>">'.esc_textarea($s['notify']['telegram_new_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">Telegram supports HTML (bold/italic). Tip: use {gambar_url} to include the receipt URL.</div>';
        echo '</div>';

        echo '<div class="fl-field fl-full"><label>Telegram/WhatsApp template <span class="fl-muted">(payment reminder – H-7/H-5/H-3)</span></label>';
        echo '<textarea class="fl-template" name="reminder_telegram_tpl" rows="8" placeholder="⏰ <b>PAYMENT REMINDER</b>\nPayment: <b>{payment_name}</b>\nDue date: {due_date} (D-{days_left})">'.esc_textarea($s['notify']['reminder_telegram_tpl'] ?? '').'</textarea>';
        echo '<div class="fl-help">Placeholders: {payment_name}, {due_date}, {days_left}, {installment_amount}, {total_amount}, {installments_paid}, {installments_total}, {payee}, {notes}, {status}. WhatsApp uses the same template (HTML will be stripped).</div>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // section

        echo '</div>';
        echo '</div>'; // fl-stack

        echo '</form></div>';
