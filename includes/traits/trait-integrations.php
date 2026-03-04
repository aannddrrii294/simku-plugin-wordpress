<?php
/**
 * Integrations (REST API, Webhooks, Chat Input).
 *
 * - REST API: /wp-json/simku/v1/...
 * - Webhooks: POST JSON payload to configured URLs with HMAC signature.
 * - Telegram inbound: users can input transactions via Telegram chat.
 */

if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Integrations {

    /* ------------------------------------------------------------------ */
    /* Settings helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function integ_settings() {
        $s = $this->settings();
        $i = $s['integrations'] ?? [];
        if (!is_array($i)) $i = [];
        return $i;
    }

    private function chat_settings() {
        $s = $this->settings();
        $c = $s['chat'] ?? [];
        if (!is_array($c)) $c = [];
        return $c;
    }

    private function integ_api_key() {
        $i = $this->integ_settings();
        return trim((string)($i['rest_api_key'] ?? ''));
    }

    private function integ_webhook_secret() {
        $i = $this->integ_settings();
        return trim((string)($i['webhook_secret'] ?? ''));
    }

    private function integ_webhook_urls() {
        $i = $this->integ_settings();
        $raw = (string)($i['webhook_urls'] ?? '');

        // Allow newline / comma separated.
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $raw);
        $out = [];
        foreach ((array)$parts as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $u = esc_url_raw($p);
            if ($u) $out[] = $u;
        }
        $out = array_values(array_unique($out));
        return $out;
    }

    private function integ_google_sheets_webhook_url() {
        $i = $this->integ_settings();
        $u = trim((string)($i['google_sheets_webhook_url'] ?? ''));
        return $u ? esc_url_raw($u) : '';
    }


    private function integ_webhooks_enabled() {
        $i = $this->integ_settings();
        return !empty($i['webhooks_enabled']);
    }

    private function integ_webhook_timeout() {
        $i = $this->integ_settings();
        $t = (int)($i['webhook_timeout'] ?? 12);
        if ($t < 2) $t = 2;
        if ($t > 60) $t = 60;
        return $t;
    }

    private function integ_webhook_event_allowed($event) {
        $i = $this->integ_settings();
        $events = $i['webhook_events'] ?? null;
        if (!is_array($events) || !$events) {
            // If no filter configured, allow all events (backward compatible).
            return true;
        }
        if (!array_key_exists($event, $events)) return true;
        return !empty($events[$event]);
    }

    /* ------------------------------------------------------------------ */
    /* Webhooks                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Fire outbound webhooks.
     *
     * Each webhook receives:
     *  - JSON body (payload)
     *  - Header: X-SIMKU-Event
     *  - Header: X-SIMKU-Signature (HMAC SHA256 hex of raw body)
     */
    private function simku_fire_webhooks($event, $payload) {
        $event = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$event);
        if ($event === '') return;

        if (!$this->integ_webhooks_enabled()) return;
        if (!$this->integ_webhook_event_allowed($event)) return;

        $urls = $this->integ_webhook_urls();
        $gs = $this->integ_google_sheets_webhook_url();
        if ($gs) $urls[] = $gs;

        $urls = array_values(array_unique(array_filter($urls)));
        if (empty($urls)) return;

        $timeout = $this->integ_webhook_timeout();

        $body = wp_json_encode([
            'event' => $event,
            'ts' => current_time('mysql'),
            'site' => home_url('/'),
            'payload' => $payload,
        ]);
        if (!$body) return;

        $secret = $this->integ_webhook_secret();
        $sig = $secret ? hash_hmac('sha256', $body, $secret) : '';

        foreach ($urls as $url) {
            if (!$this->simku_url_allows_http($url)) {
                $this->log_event('webhook_skip', 'webhook', null, ['event'=>$event,'url'=>$url,'reason'=>'http_not_allowed']);
                continue;
            }
            $resp = wp_safe_remote_post($url, [
                'timeout' => $timeout,
                'headers' => array_filter([
                    'Content-Type' => 'application/json',
                    'X-SIMKU-Event' => $event,
                    'X-SIMKU-Signature' => $sig,
                ]),
                'body' => $body,
            ]);
            if (is_wp_error($resp)) {
                $this->log_event('webhook_fail', 'webhook', null, ['event'=>$event, 'url'=>$url, 'error'=>$resp->get_error_message()]);
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code >= 300) {
                $this->log_event('webhook_fail', 'webhook', null, ['event'=>$event, 'url'=>$url, 'http_code'=>$code, 'body'=>wp_remote_retrieve_body($resp)]);
                continue;
            }
            $this->log_event('webhook_ok', 'webhook', null, ['event'=>$event, 'url'=>$url, 'http_code'=>$code]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* REST API auth                                                       */
    /* ------------------------------------------------------------------ */

    private function simku_rest_has_valid_key($request) {
        $key = $this->integ_api_key();
        if (!$key) return false;

        $hdr = '';
        $auth = '';
        if (is_object($request) && method_exists($request, 'get_header')) {
            $hdr = (string) $request->get_header('x-simku-key');
            $auth = (string) $request->get_header('authorization');
        }

        // Support: Authorization: Bearer <key>
        $bearer = '';
        if ($auth && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            $bearer = trim((string)$m[1]);
        }

        $q = '';
        $allow_q = !empty(($this->integ_settings()['allow_query_api_key'] ?? 0));
        if ($allow_q && is_object($request) && method_exists($request, 'get_param')) {
            $q = (string) $request->get_param('api_key');
        }

        $sent = trim($hdr ?: ($bearer ?: $q));
        if ($sent === '') return false;

        return hash_equals($key, $sent);
    }
    private function simku_rest_can($request, $capability) {
        // Auth via API key OR WP session/cookie.
        if ($this->simku_rest_has_valid_key($request)) return true;
        return current_user_can($capability);
    }

    private function normalize_tags_value($tags) {
        if ($tags === null) return '';
        $parts = [];
        if (is_array($tags)) {
            $parts = $tags;
        } else {
            $parts = preg_split('/[\s,;]+/', (string)$tags);
        }
        $clean = [];
        foreach ((array)$parts as $p) {
            $p = strtolower(trim((string)$p));
            if ($p === '') continue;
            $p = preg_replace('/[^a-z0-9_-]/', '', $p);
            if ($p !== '') $clean[] = $p;
        }
        $clean = array_values(array_unique($clean));
        sort($clean, SORT_STRING);
        return implode(',', $clean);
    }

    /* ------------------------------------------------------------------ */
    /* REST permission callbacks (capability-based)                         */
    /* ------------------------------------------------------------------ */

    public function simku_rest_permission_view_transactions($request) {
        if ($this->simku_rest_can($request, self::CAP_VIEW_TX)) return true;
        return new WP_Error('simku_forbidden', 'Permission denied.', ['status' => 403]);
    }

    public function simku_rest_permission_manage_transactions($request) {
        if ($this->simku_rest_can($request, self::CAP_MANAGE_TX)) return true;
        return new WP_Error('simku_forbidden', 'Permission denied.', ['status' => 403]);
    }

    public function simku_rest_permission_view_budgets($request) {
        if ($this->simku_rest_can($request, self::CAP_VIEW_REPORTS)) return true;
        return new WP_Error('simku_forbidden', 'Permission denied.', ['status' => 403]);
    }

    public function simku_rest_permission_manage_budgets($request) {
        if ($this->simku_rest_can($request, self::CAP_MANAGE_BUDGETS) || $this->simku_rest_can($request, self::CAP_MANAGE_SETTINGS)) return true;
        return new WP_Error('simku_forbidden', 'Permission denied.', ['status' => 403]);
    }


    /**
     * REST permission: hanya role administrator atau contributor yang boleh.
     * Jika REST API Key diset di Settings, maka key juga wajib match.
     *
     * Catatan: role check butuh autentikasi user (cookie/session atau Application Password).
     */
    public function simku_rest_perm__deprecated_admin_or_contributor( WP_REST_Request $request ) {

    // Optional: jika API key di-set, wajib match (biar aman untuk external calls).
    $key_configured = $this->integ_api_key();
    if ($key_configured !== '') {
        $hdr = (string)$request->get_header('x-simku-key');
        $auth = (string)$request->get_header('authorization');
        $bearer = '';
        if ($auth && preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
            $bearer = trim((string)$m[1]);
        }
        $allow_q = !empty(($this->integ_settings()['allow_query_api_key'] ?? 0));
        $q = $allow_q ? (string)$request->get_param('api_key') : '';
        $sent = trim($hdr ?: ($bearer ?: $q));
        if ($sent === '' || !hash_equals($key_configured, $sent)) {
            return new WP_Error('simku_forbidden', 'Permission denied (invalid API key).', ['status' => 403]);
        }
    }

    if (!is_user_logged_in()) {
        return new WP_Error('simku_forbidden', 'Permission denied (not authenticated).', ['status' => 403]);
    }

    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    if (array_intersect($roles, ['administrator', 'contributor'])) {
        return true;
    }

    return new WP_Error('simku_forbidden', 'Permission denied (role not allowed).', ['status' => 403]);
}


    /* ------------------------------------------------------------------ */
    /* REST API routes                                                     */
    /* ------------------------------------------------------------------ */

    public function register_rest_routes() {
        register_rest_route('simku/v1', '/transactions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_list_transactions'],
                'permission_callback' => [$this, 'simku_rest_permission_view_transactions'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_create_transaction'],
                'permission_callback' => [$this, 'simku_rest_permission_manage_transactions'],
            ],
        ]);

        register_rest_route('simku/v1', '/budgets', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'rest_list_budgets'],
                'permission_callback' => [$this, 'simku_rest_permission_view_budgets'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_upsert_budget'],
                'permission_callback' => [$this, 'simku_rest_permission_manage_budgets'],
            ],
        ]);

        register_rest_route('simku/v1', '/budgets/(?P<id>\d+)', [
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'rest_delete_budget'],
                'permission_callback' => [$this, 'simku_rest_permission_manage_budgets'],
            ],
        ]);



// Secure inbound webhook (HMAC signed)
register_rest_route('simku/v1', '/inbound/transactions', [
    [
        'methods' => 'POST',
        'callback' => [$this, 'rest_inbound_upsert_transactions'],
        'permission_callback' => '__return_true',
    ],
    [
        'methods' => 'DELETE',
        'callback' => [$this, 'rest_inbound_delete_transactions'],
        'permission_callback' => '__return_true',
    ],
]);

register_rest_route('simku/v1', '/inbound/batch', [
    [
        'methods' => 'POST',
        'callback' => [$this, 'rest_inbound_batch'],
        'permission_callback' => '__return_true',
    ],
]);

// Telegram inbound webhook

        register_rest_route('simku/v1', '/telegram/webhook', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'rest_telegram_webhook'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* REST: transactions                                                  */
    /* ------------------------------------------------------------------ */

    public function rest_list_transactions($request) {
        $db = $this->ds_db();
        if (!$db) return new WP_REST_Response(['ok'=>false,'message'=>'Datasource not configured'], 400);

        $table = $this->ds_table();
        $limit = (int)($request->get_param('limit') ?? 50);
        if ($limit < 1) $limit = 1;
        if ($limit > 500) $limit = 500;

        $offset = (int)($request->get_param('offset') ?? 0);
        if ($offset < 0) $offset = 0;

        $date_basis = (string)($request->get_param('date_basis') ?? 'input'); // input|receipt
        if (!in_array($date_basis, ['input','receipt'], true)) $date_basis = 'input';

        $start = trim((string)($request->get_param('start') ?? ''));
        $end = trim((string)($request->get_param('end') ?? ''));
        if ($start === '') $start = wp_date('Y-m-01 00:00:00');
        if ($end === '')   $end = wp_date('Y-m-d 23:59:59');

        // For receipt basis, use DATE boundaries.
        if ($date_basis === 'receipt') {
            if (strlen($start) > 10) $start = substr($start, 0, 10);
            if (strlen($end) > 10)   $end = substr($end, 0, 10);
        } else {
            // Ensure datetime format boundaries.
            if (strlen($start) === 10) $start .= ' 00:00:00';
            if (strlen($end) === 10)   $end .= ' 23:59:59';
        }

        $cats = $request->get_param('category');
        $cats = is_array($cats) ? $cats : ($cats !== null ? [$cats] : []);
        $cats = array_values(array_filter(array_map(function($c){
            return $this->normalize_category(sanitize_text_field($c));
        }, $cats)));

        $user = (string)($request->get_param('user') ?? '');

        $cat_col   = $this->tx_col('kategori', $db, $table);
        $price_col = $this->tx_col('harga', $db, $table);
        $qty_col   = $this->tx_col('quantity', $db, $table);
        $party_col = $this->tx_col('nama_toko', $db, $table);
        $items_col = $this->tx_col('items', $db, $table);
        $entry_col = $this->tx_col('tanggal_input', $db, $table);
        $receipt_col = $this->tx_col('tanggal_struk', $db, $table);
        $img_col   = $this->tx_col('gambar_url', $db, $table);
        $desc_col  = $this->tx_desc_col($db, $table);
        $tags_col  = $this->tx_col('tags', $db, $table);
        $split_col = $this->tx_col('split_group', $db, $table);

        $date_expr = $this->date_basis_expr($date_basis);
        $where = "{$date_expr} >= %s AND {$date_expr} <= %s";
        $params = [$start, $end];

        if (!empty($cats)) {
            $exp = $this->expand_category_filter($cats);
            $in = implode(',', array_fill(0, count($exp), '%s'));
            $where .= " AND TRIM(LOWER(`{$cat_col}`)) IN ({$in})";
            foreach ($exp as $c) $params[] = $c;
        }

        $user_col = $this->tx_user_col();
        if ($user !== '' && $user !== 'all' && $user_col) {
            $u_login = strtolower(trim($user));
            $is_id_col = ($user_col === 'wp_user_id') || (substr($user_col, -3) === '_id');
            if ($is_id_col) {
                $u_obj = get_user_by('login', $u_login);
                $u_id = $u_obj ? (int)$u_obj->ID : 0;
                if ($u_id > 0) {
                    $where .= " AND `{$user_col}` = %d";
                    $params[] = $u_id;
                } else {
                    $where .= " AND 1=0";
                }
            } else {
                $where .= " AND LOWER(`{$user_col}`) = %s";
                $params[] = $u_login;
            }
        }

        $desc_sel = $desc_col ? "`{$desc_col}` AS description" : "NULL AS description";
        $tags_sel = ($tags_col && $this->ds_column_exists($tags_col, $db, $table)) ? "`{$tags_col}` AS tags" : "NULL AS tags";
        $split_sel = ($split_col && $this->ds_column_exists($split_col, $db, $table)) ? "`{$split_col}` AS split_group" : "NULL AS split_group";
        $sql = "SELECT line_id, transaction_id,
                       `{$party_col}` AS nama_toko,
                       `{$items_col}` AS items,
                       `{$qty_col}` AS quantity,
                       `{$price_col}` AS harga,
                       `{$cat_col}` AS kategori,
                       `{$entry_col}` AS tanggal_input,
                       `{$receipt_col}` AS tanggal_struk,
                       `{$img_col}` AS gambar_url,
                       {$desc_sel},
                       {$tags_sel},
                       {$split_sel}
                FROM `{$table}`
                WHERE {$where}
                ORDER BY {$date_expr} DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;
        $prepared = $db->prepare($sql, $params);
        $rows = $db->get_results($prepared, ARRAY_A);

        return new WP_REST_Response([
            'ok' => true,
            'limit' => $limit,
            'offset' => $offset,
            'rows' => $rows,
        ], 200);
    }

    private function simku_api_create_transaction_internal($data, $source = 'api') {
        $db = $this->ds_db();
        if (!$db) return [false, 'Datasource not configured', null];
        if ($this->ds_is_external() && !$this->ds_allow_write_external()) return [false, 'External datasource is read-only', null];

        // Ensure user columns exist for external mode.
        if ($this->ds_is_external()) {
            [$ok, $msgs] = $this->ensure_external_user_columns();
            if (!$ok) return [false, 'External datasource missing required columns: '.implode(' ', $msgs), null];
        }

        $table = $this->ds_table();
        $current_user = wp_get_current_user();
        $user_login = $current_user && $current_user->user_login ? $current_user->user_login : 'api';

        $kategori = $this->normalize_category((string)($data['kategori'] ?? 'expense'));
        // Keep consistent with current UI: only income / expense.
        if (!in_array($kategori, ['income','expense'], true)) {
            if (in_array($kategori, ['saving','invest'], true)) {
                $kategori = 'expense';
            } else {
                return [false, 'Category must be income or expense', null];
            }
        }

        $nama_toko = sanitize_text_field((string)($data['nama_toko'] ?? ''));
        $items = sanitize_text_field((string)($data['items'] ?? ''));
        $qty = (int)($data['quantity'] ?? 1);
        if ($qty <= 0) $qty = 1;
        $harga = (int)preg_replace('/[^0-9]/', '', (string)($data['harga'] ?? 0));

        $entry_raw = (string)($data['tanggal_input'] ?? current_time('mysql'));
        $tanggal_input = $this->parse_csv_datetime($entry_raw);
        if (!$tanggal_input) $tanggal_input = current_time('mysql');

        $has_purchase_date = $this->ds_column_exists('purchase_date');
        $has_receive_date  = $this->ds_column_exists('receive_date');

        $purchase_date = $this->parse_csv_date((string)($data['purchase_date'] ?? ($data['tanggal_struk'] ?? '')));
        $receive_date  = $this->parse_csv_date((string)($data['receive_date'] ?? ($data['tanggal_struk'] ?? '')));

        $tanggal_struk = '';
        $purchase_date_db = null;
        $receive_date_db = null;
        if ($kategori === 'income') {
            if ($receive_date === '') return [false, 'Receive Date is required for income', null];
            $tanggal_struk = $receive_date;
            $receive_date_db = $receive_date;
        } else {
            if ($purchase_date === '') return [false, 'Purchase Date is required for expense', null];
            $tanggal_struk = $purchase_date;
            $purchase_date_db = $purchase_date;
        }

        $gambar_url = '';
        if (!empty($data['gambar_url'])) $gambar_url = sanitize_text_field((string)$data['gambar_url']);

        $description = '';
        if (!empty($data['description'])) $description = wp_kses_post((string)$data['description']);

        $tags = $this->normalize_tags_value($data['tags'] ?? null);
        $split_group = sanitize_text_field((string)($data['split_group'] ?? ''));

        $line_id = sanitize_text_field((string)($data['line_id'] ?? ''));
        if (!$line_id) {
            $base = (string) round(microtime(true) * 1000);
            $rand = substr(wp_generate_password(12, false, false), 0, 8);
            $line_id = $base . '_' . $rand . '-001';
        }
        $transaction_id = sanitize_text_field((string)($data['transaction_id'] ?? ''));
        if (!$transaction_id) $transaction_id = preg_replace('/-\d+$/', '', $line_id);
        if (!$split_group) $split_group = $transaction_id;

        $row = [
            'line_id' => $line_id,
            'transaction_id' => $transaction_id,
            'nama_toko' => $nama_toko,
            'items' => $items,
            'quantity' => $qty,
            'harga' => $harga,
            'kategori' => $kategori,
            'tanggal_input' => $tanggal_input,
            'tanggal_struk' => $tanggal_struk,
        ];
        if ($has_purchase_date) $row['purchase_date'] = $purchase_date_db;
        if ($has_receive_date)  $row['receive_date']  = $receive_date_db;
        $row['gambar_url'] = $gambar_url;
        $row['description'] = $description;
        $row['wp_user_id'] = (int)($current_user ? $current_user->ID : 0);
        $row['wp_user_login'] = sanitize_text_field($user_login);
        if ($this->ds_column_exists('tags')) $row['tags'] = $tags;
        if ($this->ds_column_exists('split_group')) $row['split_group'] = $split_group;

        // Formats follow order.
        $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s'];
        if ($has_purchase_date) $formats[] = '%s';
        if ($has_receive_date)  $formats[] = '%s';
        $formats = array_merge($formats, ['%s','%s','%d','%s']);

        $write_data = $this->tx_map_write_data($row, $db, $table);
        $res = $db->insert($table, $write_data, $formats);
        if ($res === false) {
            return [false, 'Insert failed (line_id may already exist)', null];
        }

        $this->log_event('create', 'transaction', $line_id, ['source'=>$source,'data'=>$row]);

        
// Fire webhooks (transaction.created)
$total = (float)$harga * (float)$qty;
$receipt_urls = method_exists($this, 'images_from_db_value')
    ? $this->images_from_db_value((string)$gambar_url)
    : ( ($gambar_url !== '') ? [$gambar_url] : [] );

$this->simku_fire_webhooks('transaction.created', [
    // stable payload (same as admin/csv)
    'line_id' => $line_id,
    'transaction_id' => $transaction_id,
    'split_group' => $split_group,
    'tags' => ($tags !== '') ? array_values(array_filter(array_map('trim', explode(',', (string)$tags)))) : [],
    'category' => $kategori,
    'counterparty' => $nama_toko,
    'item' => $items,
    'qty' => (int)$qty,
    'price' => (int)$harga,
    'total' => (int)round($total),
    'entry_date' => $tanggal_input,
    'receipt_date' => $tanggal_struk,
    'purchase_date' => $purchase_date_db,
    'receive_date' => $receive_date_db,
    'receipt_urls' => $receipt_urls,
    'description' => wp_strip_all_tags((string)$description),
    'user_login' => $user_login,
    'user_id' => (int)($current_user ? $current_user->ID : 0),
    'source' => $source,

    // backward-compatible fields
    'kategori' => $kategori,
    'nama_toko' => $nama_toko,
    'items' => $items,
    'quantity' => (int)$qty,
    'harga' => (int)$harga,
    'tanggal_input' => $tanggal_input,
    'tanggal_struk' => $tanggal_struk,
    'gambar_url' => $gambar_url,
]);

        return [true, 'OK', $row];
    }

    public function rest_create_transaction($request) {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        [$ok, $msg, $row] = $this->simku_api_create_transaction_internal($body, 'rest');
        return new WP_REST_Response(['ok'=>$ok,'message'=>$msg,'data'=>$row], $ok ? 201 : 400);
    }


    /* ------------------------------------------------------------------ */
    /* REST: inbound webhook (HMAC signed, HTTPS-only)                     */
    /* ------------------------------------------------------------------ */

    private function inbound_settings() {
        $i = $this->integ_settings();
        return [
            'enabled' => !empty($i['inbound_enabled']),
            'secret' => trim((string)($i['inbound_secret'] ?? '')),
            'tolerance' => (int)($i['inbound_tolerance_sec'] ?? 300),
            'rate_limit' => (int)($i['inbound_rate_limit_per_min'] ?? 60),
        ];
    }

    private function inbound_is_https() {
        if (is_ssl()) return true;
        $xfp = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $xfp === 'https';
    }

    private function inbound_remote_ip() {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $parts = explode(',', $xff);
            $cand = trim((string)($parts[0] ?? ''));
            if ($cand !== '') $ip = $cand;
        }
        return $ip !== '' ? $ip : '0.0.0.0';
    }

    private function inbound_rate_limit_ok($limit_per_min) {
        $limit_per_min = (int)$limit_per_min;
        if ($limit_per_min < 1) $limit_per_min = 1;
        if ($limit_per_min > 600) $limit_per_min = 600;

        $ip = $this->inbound_remote_ip();
        $bucket = gmdate('YmdHi'); // per minute bucket
        $key = 'simku_inb_rl_' . md5($ip . '|' . $bucket);
        $cur = (int)get_transient($key);
        $cur++;
        set_transient($key, $cur, 70);
        return $cur <= $limit_per_min;
    }

    private function inbound_verify($request) {
        $cfg = $this->inbound_settings();

        if (empty($cfg['enabled'])) {
            return new WP_Error('simku_inbound_disabled', 'Inbound webhook is disabled.', ['status' => 403]);
        }
        if (empty($cfg['secret'])) {
            return new WP_Error('simku_inbound_secret', 'Inbound secret is not set.', ['status' => 400]);
        }
        if (!$this->inbound_is_https()) {
            return new WP_Error('simku_inbound_https', 'Inbound webhook requires HTTPS.', ['status' => 400]);
        }
        if (!$this->inbound_rate_limit_ok($cfg['rate_limit'])) {
            return new WP_Error('simku_inbound_rate_limited', 'Rate limit exceeded.', ['status' => 429]);
        }

        $tol = (int)$cfg['tolerance'];
        if ($tol < 30) $tol = 30;
        if ($tol > 3600) $tol = 3600;

        $ts = (int)$request->get_header('x-simku-timestamp');
        $nonce = (string)$request->get_header('x-simku-nonce');
        $sig = strtolower(trim((string)$request->get_header('x-simku-signature')));

        if ($ts <= 0 || strlen($nonce) < 16 || $sig === '') {
            return new WP_Error('simku_inbound_auth', 'Missing auth headers.', ['status' => 401]);
        }
        if (abs(time() - $ts) > $tol) {
            return new WP_Error('simku_inbound_auth', 'Timestamp expired.', ['status' => 401]);
        }

        $nonce_key = 'simku_inb_nonce_' . md5($ts . '|' . $nonce);
        if (get_transient($nonce_key)) {
            return new WP_Error('simku_inbound_auth', 'Replay detected.', ['status' => 401]);
        }
        set_transient($nonce_key, 1, $tol + 120);

        $raw = (string)$request->get_body();
        $method = strtoupper((string)$request->get_method());
        $route = (string)$request->get_route();

        $base = $ts . '.' . $nonce . '.' . $method . '.' . $route . '.' . $raw;
        $calc = hash_hmac('sha256', $base, (string)$cfg['secret']);

        if (!hash_equals($calc, $sig)) {
            return new WP_Error('simku_inbound_auth', 'Invalid signature.', ['status' => 401]);
        }

        return true;
    }

    private function inbound_unpack_transactions($body) {
        if (!is_array($body)) return [];
        if (isset($body['transactions']) && is_array($body['transactions'])) return $body['transactions'];
        if (isset($body['transaction']) && is_array($body['transaction'])) return [$body['transaction']];
        // allow single transaction object
        if (isset($body['transaction_id']) || isset($body['lines']) || isset($body['items'])) return [$body];
        return [];
    }

    private function inbound_tx_to_lines($tx) {
        if (!is_array($tx)) return [];
        $tx_id = sanitize_text_field((string)($tx['transaction_id'] ?? ''));

        if (isset($tx['lines']) && is_array($tx['lines'])) {
            $lines = [];
            $idx = 0;
            foreach ((array)$tx['lines'] as $ln) {
                if (!is_array($ln)) continue;
                $idx++;
                $merged = $tx;
                unset($merged['lines']);
                foreach ($ln as $k=>$v) $merged[$k] = $v;

                if (empty($merged['transaction_id'])) $merged['transaction_id'] = $tx_id;
                if (empty($merged['line_id']) && $tx_id !== '') {
                    $seq = str_pad((string)$idx, 3, '0', STR_PAD_LEFT);
                    $merged['line_id'] = $tx_id . '-' . $seq;
                }
                $lines[] = $merged;
            }
            return $lines;
        }

        // single-line tx
        if (empty($tx['line_id']) && $tx_id !== '') {
            $tx['line_id'] = $tx_id . '-001';
        }
        return [$tx];
    }

    public function rest_inbound_upsert_transactions($request) {
        $auth = $this->inbound_verify($request);
        if (is_wp_error($auth)) return $auth;

        $body = $request->get_json_params();
        if (!is_array($body)) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid JSON body'], 400);

        $txs = $this->inbound_unpack_transactions($body);
        if (!$txs) return new WP_REST_Response(['ok'=>false,'message'=>'No transactions provided'], 400);

        $created = 0; $updated = 0; $errors = []; $results = [];

        foreach ($txs as $tx) {
            foreach ($this->inbound_tx_to_lines($tx) as $line) {
                [$ok, $msg, $row, $was_update] = $this->simku_api_upsert_transaction_line_internal($line, 'inbound');
                $results[] = ['ok'=>$ok,'message'=>$msg,'data'=>$row];
                if ($ok) {
                    if ($was_update) $updated++; else $created++;
                } else {
                    $errors[] = $msg;
                }
            }
        }

        return new WP_REST_Response([
            'ok' => empty($errors),
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'results' => $results,
        ], empty($errors) ? 200 : 207);
    }

    public function rest_inbound_delete_transactions($request) {
        $auth = $this->inbound_verify($request);
        if (is_wp_error($auth)) return $auth;

        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];

        [$ok, $msg, $out] = $this->simku_api_delete_transactions_internal($body, 'inbound');
        return new WP_REST_Response(['ok'=>$ok,'message'=>$msg,'data'=>$out], $ok ? 200 : 400);
    }

    public function rest_inbound_batch($request) {
        $auth = $this->inbound_verify($request);
        if (is_wp_error($auth)) return $auth;

        $body = $request->get_json_params();
        if (!is_array($body)) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid JSON body'], 400);

        $ops = $body['operations'] ?? null;
        if (!is_array($ops) || !$ops) return new WP_REST_Response(['ok'=>false,'message'=>'operations[] is required'], 400);

        $out = []; $has_err = false;

        foreach ($ops as $op) {
            if (!is_array($op)) { $has_err=true; $out[]=['ok'=>false,'message'=>'Invalid operation']; continue; }
            $t = strtolower((string)($op['op'] ?? ''));

            if ($t === 'upsert') {
                $txs = $this->inbound_unpack_transactions($op);
                if (!$txs && isset($op['data']) && is_array($op['data'])) $txs = $this->inbound_unpack_transactions($op['data']);
                if (!$txs) { $has_err=true; $out[]=['ok'=>false,'message'=>'No transaction(s)']; continue; }

                $created=0; $updated=0; $errs=[]; $results=[];
                foreach ($txs as $tx) foreach ($this->inbound_tx_to_lines($tx) as $line) {
                    [$ok,$msg,$row,$was_update] = $this->simku_api_upsert_transaction_line_internal($line,'inbound');
                    $results[]=['ok'=>$ok,'message'=>$msg,'data'=>$row];
                    if ($ok) { $was_update ? $updated++ : $created++; } else { $has_err=true; $errs[]=$msg; }
                }
                $out[]=['ok'=>empty($errs),'created'=>$created,'updated'=>$updated,'errors'=>$errs,'results'=>$results];
            } elseif ($t === 'delete') {
                $payload = $op['data'] ?? $op;
                [$ok,$msg,$res] = $this->simku_api_delete_transactions_internal(is_array($payload)?$payload:[], 'inbound');
                if (!$ok) $has_err=true;
                $out[]=['ok'=>$ok,'message'=>$msg,'data'=>$res];
            } else {
                $has_err=true;
                $out[]=['ok'=>false,'message'=>'Unsupported op (use upsert|delete)'];
            }
        }

        return new WP_REST_Response(['ok'=>!$has_err,'results'=>$out], !$has_err ? 200 : 207);
    }

    /**
     * Upsert (idempotent) by line_id.
     * Returns: [ok, message, canonical_row, was_update]
     */
    private function simku_api_upsert_transaction_line_internal($data, $source = 'api') {
        $db = $this->ds_db();
        if (!$db) return [false, 'Datasource not configured', null, false];
        if ($this->ds_is_external() && !$this->ds_allow_write_external()) return [false, 'External datasource is read-only', null, false];

        // Ensure user columns exist for external mode.
        if ($this->ds_is_external()) {
            [$ok, $msgs] = $this->ensure_external_user_columns();
            if (!$ok) return [false, 'External datasource missing required columns: '.implode(' ', $msgs), null, false];
        }

        $table = $this->ds_table();

        $line_id = sanitize_text_field((string)($data['line_id'] ?? ''));
        $transaction_id = sanitize_text_field((string)($data['transaction_id'] ?? ''));
        if ($transaction_id === '' && $line_id !== '') {
            $transaction_id = preg_replace('/-\d+$/', '', $line_id);
        }
        if ($transaction_id !== '' && $line_id === '') {
            $line_id = $transaction_id . '-001';
        }
        if ($line_id === '' || $transaction_id === '') return [false, 'transaction_id and line_id are required', null, false];

        $exists = (int)$db->get_var($db->prepare("SELECT COUNT(1) FROM `{$table}` WHERE line_id=%s", $line_id)) > 0;

        $kategori = $this->normalize_category((string)($data['kategori'] ?? 'expense'));
        if (!in_array($kategori, ['income','expense'], true)) {
            if (in_array($kategori, ['saving','invest'], true)) $kategori = 'expense';
            else return [false, 'Category must be income or expense', null, $exists];
        }

        $nama_toko = sanitize_text_field((string)($data['nama_toko'] ?? ''));
        $items = sanitize_text_field((string)($data['items'] ?? ''));
        $qty = (int)($data['quantity'] ?? 1);
        if ($qty <= 0) $qty = 1;
        $harga = (int)preg_replace('/[^0-9]/', '', (string)($data['harga'] ?? 0));

        $entry_raw = (string)($data['tanggal_input'] ?? current_time('mysql'));
        $tanggal_input = $this->parse_csv_datetime($entry_raw);
        if (!$tanggal_input) $tanggal_input = current_time('mysql');

        $has_purchase_date = $this->ds_column_exists('purchase_date');
        $has_receive_date  = $this->ds_column_exists('receive_date');

        $purchase_date = $this->parse_csv_date((string)($data['purchase_date'] ?? ($data['tanggal_struk'] ?? '')));
        $receive_date  = $this->parse_csv_date((string)($data['receive_date'] ?? ($data['tanggal_struk'] ?? '')));

        $tanggal_struk = '';
        $purchase_date_db = null;
        $receive_date_db = null;
        if ($kategori === 'income') {
            if ($receive_date === '') return [false, 'Receive Date is required for income', null, $exists];
            $tanggal_struk = $receive_date;
            $receive_date_db = $receive_date;
        } else {
            if ($purchase_date === '') return [false, 'Purchase Date is required for expense', null, $exists];
            $tanggal_struk = $purchase_date;
            $purchase_date_db = $purchase_date;
        }

        if ($items === '') return [false, 'items is required', null, $exists];

        $gambar_url = '';
        if (!empty($data['gambar_url'])) $gambar_url = sanitize_text_field((string)$data['gambar_url']);

        $description = '';
        if (!empty($data['description'])) $description = wp_kses_post((string)$data['description']);

        $tags = $this->normalize_tags_value($data['tags'] ?? null);
        $split_group = sanitize_text_field((string)($data['split_group'] ?? ''));
        if ($split_group === '') $split_group = $transaction_id;

        $user_login = sanitize_text_field((string)($data['user_login'] ?? 'inbound'));
        $user_id = (int)($data['user_id'] ?? 0);

        $row = [
            'line_id' => $line_id,
            'transaction_id' => $transaction_id,
            'nama_toko' => $nama_toko,
            'items' => $items,
            'quantity' => $qty,
            'harga' => $harga,
            'kategori' => $kategori,
            'tanggal_input' => $tanggal_input,
            'tanggal_struk' => $tanggal_struk,
        ];
        if ($has_purchase_date) $row['purchase_date'] = $purchase_date_db;
        if ($has_receive_date)  $row['receive_date']  = $receive_date_db;
        $row['gambar_url'] = $gambar_url;
        $row['description'] = $description;
        if ($this->ds_column_exists('wp_user_id')) $row['wp_user_id'] = $user_id;
        if ($this->ds_column_exists('wp_user_login')) $row['wp_user_login'] = $user_login;
        if ($this->ds_column_exists('tags')) $row['tags'] = $tags;
        if ($this->ds_column_exists('split_group')) $row['split_group'] = $split_group;

        $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s'];
        if ($has_purchase_date) $formats[] = '%s';
        if ($has_receive_date)  $formats[] = '%s';
        $formats = array_merge($formats, ['%s','%s']);
        if ($this->ds_column_exists('wp_user_id')) $formats[] = '%d';
        if ($this->ds_column_exists('wp_user_login')) $formats[] = '%s';
        if ($this->ds_column_exists('tags')) $formats[] = '%s';
        if ($this->ds_column_exists('split_group')) $formats[] = '%s';

        $write = $this->tx_map_write_data($row, $db, $table);

        if ($exists) {
            $res = $db->update($table, $write, ['line_id'=>$line_id], $formats, ['%s']);
            if ($res === false) return [false, 'Update failed', null, true];

            $this->log_event('update', 'transaction', $line_id, ['source'=>$source,'data'=>$row]);
            if (method_exists($this, 'simku_fire_webhooks')) {
                $payload = [
                    'line_id' => $line_id,
                    'transaction_id' => $transaction_id,
                    'split_group' => $split_group,
                    'tags' => ($tags !== '') ? array_values(array_filter(array_map('trim', explode(',', $tags)))) : [],
                    'category' => $kategori,
                    'counterparty' => $nama_toko,
                    'item' => $items,
                    'qty' => (int)$qty,
                    'price' => (int)$harga,
                    'total' => (int)((float)$harga * (float)$qty),
                    'entry_date' => $tanggal_input,
                    'receipt_date' => $tanggal_struk,
                    'purchase_date' => $purchase_date_db,
                    'receive_date' => $receive_date_db,
                    'receipt_urls' => $this->receipt_urls_only((string)$gambar_url),
                    'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)$gambar_url),
                    'description' => wp_strip_all_tags((string)$description),
                    'user_login' => $user_login,
                    'user_id' => $user_id,
                    'source' => $source,
                ];
                $this->simku_fire_webhooks('transaction.updated', $payload);
            }
            return [true, 'Updated', $row, true];
        }

        $res = $db->insert($table, $write, $formats);
        if ($res === false) return [false, 'Insert failed', null, false];

        $this->log_event('create', 'transaction', $line_id, ['source'=>$source,'data'=>$row]);
        if (method_exists($this, 'simku_fire_webhooks')) {
            $payload = [
                'line_id' => $line_id,
                'transaction_id' => $transaction_id,
                'split_group' => $split_group,
                'tags' => ($tags !== '') ? array_values(array_filter(array_map('trim', explode(',', $tags)))) : [],
                'category' => $kategori,
                'counterparty' => $nama_toko,
                'item' => $items,
                'qty' => (int)$qty,
                'price' => (int)$harga,
                'total' => (int)((float)$harga * (float)$qty),
                'entry_date' => $tanggal_input,
                'receipt_date' => $tanggal_struk,
                'purchase_date' => $purchase_date_db,
                'receive_date' => $receive_date_db,
                'receipt_urls' => $this->receipt_urls_only((string)$gambar_url),
                'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)$gambar_url),
                'description' => wp_strip_all_tags((string)$description),
                'user_login' => $user_login,
                'user_id' => $user_id,
                'source' => $source,
            ];
            $this->simku_fire_webhooks('transaction.created', $payload);
        }

        return [true, 'Created', $row, false];
    }

    private function simku_api_delete_transactions_internal($data, $source = 'api') {
        $db = $this->ds_db();
        if (!$db) return [false, 'Datasource not configured', null];
        if ($this->ds_is_external() && !$this->ds_allow_write_external()) return [false, 'External datasource is read-only', null];

        $table = $this->ds_table();

        $line_ids = [];
        if (!empty($data['line_id'])) $line_ids[] = (string)$data['line_id'];
        if (!empty($data['line_ids']) && is_array($data['line_ids'])) $line_ids = array_merge($line_ids, (array)$data['line_ids']);
        $line_ids = array_values(array_filter(array_map(function($x){ return sanitize_text_field((string)$x); }, $line_ids)));

        $tx_ids = [];
        if (!empty($data['transaction_id'])) $tx_ids[] = (string)$data['transaction_id'];
        if (!empty($data['transaction_ids']) && is_array($data['transaction_ids'])) $tx_ids = array_merge($tx_ids, (array)$data['transaction_ids']);
        $tx_ids = array_values(array_filter(array_map(function($x){ return sanitize_text_field((string)$x); }, $tx_ids)));

        if (!$line_ids && !$tx_ids) return [false, 'Provide line_id(s) or transaction_id(s)', null];

        $deleted = 0;

        foreach ($line_ids as $lid) {
            $res = $db->delete($table, ['line_id'=>$lid], ['%s']);
            if ($res !== false) {
                $deleted += (int)$res;
                $this->log_event('delete', 'transaction', $lid, ['source'=>$source,'mode'=>'line_id']);
                if (method_exists($this, 'simku_fire_webhooks')) {
                    $this->simku_fire_webhooks('transaction.deleted', ['line_id'=>$lid,'source'=>$source]);
                }
            }
        }

        foreach ($tx_ids as $tid) {
            $res = $db->query($db->prepare("DELETE FROM `{$table}` WHERE `transaction_id`=%s", $tid));
            if ($res !== false) {
                $deleted += (int)$res;
                $this->log_event('delete', 'transaction', $tid, ['source'=>$source,'mode'=>'transaction_id','deleted_rows'=>(int)$res]);
                if (method_exists($this, 'simku_fire_webhooks')) {
                    $this->simku_fire_webhooks('transaction.deleted', ['transaction_id'=>$tid,'deleted_rows'=>(int)$res,'source'=>$source]);
                }
            }
        }

        return [true, 'Deleted', ['deleted'=>$deleted]];
    }

    /* ------------------------------------------------------------------ */
    /* REST: budgets                                                       */
    /* ------------------------------------------------------------------ */

    public function rest_list_budgets($request) {
        $ym = trim((string)($request->get_param('ym') ?? wp_date('Y-m')));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = wp_date('Y-m');
        $user = (string)($request->get_param('user') ?? 'all');
        $rows = $this->simku_budgets_with_actual($ym, $user);
        return new WP_REST_Response(['ok'=>true,'ym'=>$ym,'rows'=>$rows], 200);
    }

    public function rest_upsert_budget($request) {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];

        $ym = trim((string)($body['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid ym (expected YYYY-MM)'], 400);

        $cat = $this->normalize_category((string)($body['category'] ?? ''));
        if (!in_array($cat, ['income','expense','saving','invest'], true)) {
            return new WP_REST_Response(['ok'=>false,'message'=>'Invalid category'], 400);
        }

        $amount = (int)preg_replace('/[^0-9]/', '', (string)($body['amount'] ?? 0));
        if ($amount < 0) $amount = 0;

        $user = (string)($body['user'] ?? 'all');
        $ok = $this->simku_budget_upsert($ym, $cat, $amount, $user);
        if (!$ok) return new WP_REST_Response(['ok'=>false,'message'=>'Failed to save budget'], 500);

        
// Fire webhooks (budget.upserted)
$budget_row = null;
if (method_exists($this, 'simku_budgets_for_month')) {
    $rows = $this->simku_budgets_for_month($ym, $user);
    foreach ((array)$rows as $r) {
        if (($r['category'] ?? '') === $cat) { $budget_row = $r; break; }
    }
}
$payload = [
    'id' => $budget_row['id'] ?? null,
    'ym' => $ym,
    'category' => $cat,
    'amount' => (int)$amount,
    'user_scope' => $user,
    'user' => $user, // backward-compatible
    'created_at' => $budget_row['created_at'] ?? null,
    'updated_at' => $budget_row['updated_at'] ?? null,
    'source' => 'rest',
];
$this->simku_fire_webhooks('budget.upserted', $payload);
        return new WP_REST_Response(['ok'=>true,'message'=>'Saved'], 200);
    }

    public function rest_delete_budget($request) {
        $id = (int)($request->get_param('id') ?? 0);
        if ($id <= 0) return new WP_REST_Response(['ok'=>false,'message'=>'Invalid id'], 400);
        $ok = $this->simku_budget_delete($id);
        if (!$ok) return new WP_REST_Response(['ok'=>false,'message'=>'Failed to delete'], 500);
        
// Fire webhooks (budget.deleted)
$budget_row = method_exists($this, 'simku_budget_get_by_id') ? $this->simku_budget_get_by_id($id) : null;
$payload = [
    'id' => $id,
    'ym' => $budget_row['period_ym'] ?? null,
    'category' => $budget_row['category'] ?? null,
    'amount' => isset($budget_row['amount']) ? (int)$budget_row['amount'] : null,
    'user_scope' => $budget_row['user_scope'] ?? null,
    'user' => $budget_row['user_scope'] ?? null, // backward-compatible
    'deleted_at' => current_time('mysql'),
    'source' => 'rest',
];
$this->simku_fire_webhooks('budget.deleted', $payload);
        return new WP_REST_Response(['ok'=>true], 200);
    }

    /* ------------------------------------------------------------------ */
    /* Telegram inbound                                                    */
    /* ------------------------------------------------------------------ */

    private function tg_inbound_enabled() {
        $c = $this->chat_settings();
        return !empty($c['telegram_in_enabled']);
    }

    private function tg_inbound_secret() {
        $c = $this->chat_settings();
        return trim((string)($c['telegram_webhook_secret'] ?? ''));
    }

    private function tg_inbound_allowed_chat_ids() {
        $c = $this->chat_settings();
        $raw = (string)($c['telegram_allowed_chat_ids'] ?? '');
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $parts = preg_split('/[\n,]+/', $raw);
        $out = [];
        foreach ((array)$parts as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            if (!preg_match('/^-?\d+$/', $p)) continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    private function tg_bot_token_for_inbound() {
        // Prefer explicit inbound token, fallback to Notifications bot token.
        $c = $this->chat_settings();
        $t = trim((string)($c['telegram_bot_token'] ?? ''));
        if ($t) return $t;
        $s = $this->settings();
        return trim((string)($s['notify']['telegram_bot_token'] ?? ''));
    }

    private function tg_send_message_raw($chat_id, $text) {
        $token = $this->tg_bot_token_for_inbound();
        if (!$token) return false;
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $resp = wp_safe_remote_post($url, [
            'timeout' => 12,
            // Some shared hostings have incomplete CA bundles; allow Telegram to work.
            'sslverify' => empty(($this->settings()['notify']['telegram_allow_insecure_tls'] ?? 0)),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);
        return !is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) === 200;
    }

    private function tg_parse_amount($text) {
        // Supports: 25000, 25k, 25rb, 25.000
        $t = strtolower($text);
        $t = str_replace(['rp', 'idr'], '', $t);
        if (preg_match('/(\d+[\d\.,]*)(\s*)(k|rb)\b/', $t, $m)) {
            $num = preg_replace('/[^0-9]/', '', (string)$m[1]);
            $v = (int)$num;
            return $v * 1000;
        }
        if (preg_match('/\b(\d+[\d\.,]*)\b/', $t, $m)) {
            $num = preg_replace('/[^0-9]/', '', (string)$m[1]);
            return (int)$num;
        }
        return 0;
    }

    private function tg_parse_category($text) {
        $t = strtolower($text);
        // income keywords.
        if (preg_match('/\b(income|masuk|gaji|salary|payday)\b/', $t)) return 'income';
        return 'expense';
    }

    private function tg_parse_purchase_date($text) {
        // Accept: tgl:2026-02-18 or date:2026-02-18
        if (preg_match('/\b(tgl|date)\s*:\s*(\d{4}-\d{2}-\d{2})\b/i', $text, $m)) {
            return (string)$m[2];
        }
        return wp_date('Y-m-d');
    }

    private function tg_parse_merchant($text) {
        // Accept: toko:xxx or merchant:xxx
        if (preg_match('/\b(toko|merchant|payee)\s*:\s*([^\n]+)$/i', $text, $m)) {
            return trim((string)$m[2]);
        }
        return '';
    }

    private function tg_clean_item_name($text) {
        // Remove known tokens: /catat, catat, amount, tgl:, toko:, kategori:
        $t = trim($text);
        $t = preg_replace('/^\s*\/?catat\b\s*/i', '', $t);
        $t = preg_replace('/\b(tgl|date|toko|merchant|payee)\s*:\s*[^\s]+/i', '', $t);
        $t = preg_replace('/\b(kategori|category)\s*:\s*[^\s]+/i', '', $t);
        $t = preg_replace('/\b(rp\s*)?\d+[\d\.,]*(\s*(k|rb))?\b/i', '', $t);
        $t = trim(preg_replace('/\s+/', ' ', $t));
        return $t;
    }

    public function rest_telegram_webhook($request) {
        // Security: validate Telegram secret token.
        // Prefer the official header X-Telegram-Bot-Api-Secret-Token (setWebhook secret_token),
        // and optionally allow query param ?secret=... for backward compatibility.
        $need = $this->tg_inbound_secret();
        $sent_hdr = '';
        if (is_object($request) && method_exists($request, 'get_header')) {
            $sent_hdr = (string) $request->get_header('x-telegram-bot-api-secret-token');
        }
        $sent_q = trim((string)($request->get_param('secret') ?? ''));
        $allow_q = !empty(($this->chat_settings()['telegram_allow_query_secret'] ?? 0));

        $sent = trim($sent_hdr !== '' ? $sent_hdr : ($allow_q ? $sent_q : ''));
        if (!$need || $sent === '' || !hash_equals($need, $sent)) {
            return new WP_REST_Response(['ok'=>false,'message'=>'Forbidden'], 403);
        }
        if (!$this->tg_inbound_enabled()) {
            return new WP_REST_Response(['ok'=>false,'message'=>'Telegram inbound disabled'], 400);
        }

        $update = $request->get_json_params();
        if (!is_array($update)) $update = [];

        $msg = $update['message'] ?? $update['edited_message'] ?? null;
        if (!$msg || !is_array($msg)) return new WP_REST_Response(['ok'=>true], 200);

        $chat_id = $msg['chat']['id'] ?? null;
        $text = (string)($msg['text'] ?? '');
        if (!$chat_id || $text === '') return new WP_REST_Response(['ok'=>true], 200);

        // Optional allowlist
        $allowed = $this->tg_inbound_allowed_chat_ids();
        if (!empty($allowed) && !in_array((string)$chat_id, $allowed, true)) {
            $this->log_event('telegram_forbidden', 'chat', null, ['chat_id'=>$chat_id]);
            return new WP_REST_Response(['ok'=>true], 200);
        }

        $lower = strtolower(trim($text));
        if (!preg_match('/^(\/)?catat\b/', $lower)) {
            // Help
            $help = "Format:\n".
                "<b>catat</b> <i>item</i> <i>jumlah</i>\n".
                "Contoh: <code>catat kopi 25k</code>\n".
                "Opsional: <code>tgl:YYYY-MM-DD</code> <code>toko:Nama</code>\n".
                "Income: <code>catat gaji 5000000</code>";
            $this->tg_send_message_raw($chat_id, $help);
            return new WP_REST_Response(['ok'=>true], 200);
        }

        $amount = $this->tg_parse_amount($text);
        if ($amount <= 0) {
            $this->tg_send_message_raw($chat_id, "Jumlah tidak terbaca. Contoh: <code>catat kopi 25k</code>");
            return new WP_REST_Response(['ok'=>true], 200);
        }

        $kategori = $this->tg_parse_category($text);
        $date = $this->tg_parse_purchase_date($text);
        $toko = $this->tg_parse_merchant($text);
        $item = $this->tg_clean_item_name($text);
        if ($item === '') $item = ($kategori === 'income') ? 'Income' : 'Expense';

        $payload = [
            'nama_toko' => $toko,
            'items' => $item,
            'quantity' => 1,
            'harga' => $amount,
            'kategori' => $kategori,
            'tanggal_input' => current_time('mysql'),
        ];
        if ($kategori === 'income') {
            $payload['receive_date'] = $date;
            $payload['tanggal_struk'] = $date;
        } else {
            $payload['purchase_date'] = $date;
            $payload['tanggal_struk'] = $date;
        }

        [$ok, $msg2, $row] = $this->simku_api_create_transaction_internal($payload, 'telegram');
        if ($ok) {
            $total = (float)$row['harga'] * (float)$row['quantity'];
            $reply = "✅ Tercatat\n".
                "Kategori: <b>".esc_html($row['kategori'])."</b>\n".
                "Item: <b>".esc_html($row['items'])."</b>\n".
                "Total: <b>Rp ".number_format_i18n($total)."</b>\n".
                "Tanggal: ".esc_html($row['tanggal_struk']);
            $this->tg_send_message_raw($chat_id, $reply);
        } else {
            $this->tg_send_message_raw($chat_id, "❌ Gagal: ".esc_html($msg2));
        }

        return new WP_REST_Response(['ok'=>true], 200);
    }
}
