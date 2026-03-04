<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Transactions {
    public function page_transactions() {
        if (!current_user_can(self::CAP_VIEW_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        $db = $this->ds_db();
        if (!$db) {
            echo '<div class="wrap fl-wrap"><h1>Transactions</h1><div class="notice notice-error"><p>Datasource not configured.</p></div></div>';
            return;
        }

        $table = $this->ds_table();

        // View images for a transaction line (single URL or JSON-array stored in gambar_url)
        if (!empty($_GET['view_images']) && !empty($_GET['line_id'])) {
            $line_id = sanitize_text_field(wp_unslash($_GET['line_id']));
            $img_col = $this->tx_col('gambar_url', $db, $table);
$img_sel = ($img_col && $this->ds_column_exists($img_col, $db, $table)) ? "`{$img_col}` AS gambar_url" : "NULL AS gambar_url";
$row = $db->get_row($db->prepare(
    "SELECT line_id, transaction_id, items, {$img_sel} FROM `{$table}` WHERE line_id = %s LIMIT 1",
    $line_id
));

            echo '<div class="wrap fl-wrap">';
            echo $this->page_header_html(__('Transaction Images', self::TEXT_DOMAIN));
            echo '<div class="fl-btnrow fl-mt">';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=fl-transactions')) . '">' . esc_html__('Back to Transactions', self::TEXT_DOMAIN) . '</a>';
            echo '</div>';

            if (!$row) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Transaction not found.', self::TEXT_DOMAIN) . '</p></div>';
                echo '</div>';
                return;
            }

            $media = $this->receipt_media_from_db_value($row->gambar_url);
            $urls = $this->normalize_images_field($media['urls'] ?? []);
            $gds  = is_array($media['gdrive'] ?? null) ? $media['gdrive'] : [];

            if (empty($urls) && empty($gds)) {
                echo '<div class="notice notice-info"><p>' . esc_html__('No images attached.', self::TEXT_DOMAIN) . '</p></div>';
                echo '</div>';
                return;
            }

            echo '<div class="fl-card fl-mt">';
            echo '<div class="fl-card-body">';
            echo '<p><strong>' . esc_html__('Transaction ID', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($row->transaction_id) . '</p>';
            echo '<p><strong>' . esc_html__('Item', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($row->items) . '</p>';
            echo '<div class="fl-grid fl-grid-3" style="align-items:start">';

            foreach ($urls as $img) {
                $u = esc_url($img);
                if (!$u) continue;
                echo '<a href="' . $u . '" target="_blank" rel="noopener" style="display:block;border:1px solid #ddd;padding:6px;border-radius:10px;background:#fff;">';
                echo '<img src="' . $u . '" alt="" style="width:100%;max-width:100%;height:auto;display:block;" />';
                echo '</a>';
            }

            foreach ($gds as $g) {
                if (!is_array($g)) continue;
                $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($g['id'] ?? ''));
                if (!$id) continue;
                $u = esc_url($this->gdrive_proxy_url($id));
                if (!$u) continue;
                echo '<a href="' . $u . '" target="_blank" rel="noopener" style="display:block;border:1px solid #ddd;padding:6px;border-radius:10px;background:#fff;">';
                echo '<img src="' . $u . '" alt="' . esc_attr__('Receipt image', self::TEXT_DOMAIN) . '" style="width:100%;max-width:100%;height:auto;display:block;" />';
                echo '</a>';
            }

            echo '</div>'; // grid
            echo '</div>'; // card body
            echo '</div>'; // card
            echo '</div>';
            return;
        }

        
        // View description for a transaction line
        if (!empty($_GET['view_desc']) && !empty($_GET['line_id'])) {
            $line_id = sanitize_text_field(wp_unslash($_GET['line_id']));
            $purchase_sel = $this->ds_column_exists('purchase_date', $db, $table) ? 'purchase_date AS purchase_date' : 'NULL AS purchase_date';
$receive_sel  = $this->ds_column_exists('receive_date', $db, $table) ? 'receive_date AS receive_date' : 'NULL AS receive_date';

$cat_col   = $this->tx_col('kategori', $db, $table);
$entry_col = $this->tx_col('tanggal_input', $db, $table);
$receipt_col = $this->tx_col('tanggal_struk', $db, $table);
$receipt_sel = ($receipt_col && $this->ds_column_exists($receipt_col, $db, $table)) ? "`{$receipt_col}` AS tanggal_struk" : "NULL AS tanggal_struk";
$desc_col = $this->tx_desc_col($db, $table);
$desc_sel = $desc_col ? "`{$desc_col}` AS description" : "NULL AS description";

$row = $db->get_row($db->prepare(
    "SELECT line_id, transaction_id, items, `{$cat_col}` AS kategori, `{$entry_col}` AS tanggal_input, {$receipt_sel}, {$purchase_sel}, {$receive_sel}, {$desc_sel} FROM `{$table}` WHERE line_id = %s LIMIT 1",
    $line_id
), ARRAY_A);

            echo '<div class="wrap fl-wrap">';
            echo $this->page_header_html(__('Transaction Details', self::TEXT_DOMAIN));
            echo '<div class="fl-btnrow fl-mt">';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=fl-transactions')) . '">' . esc_html__('Back to Transactions', self::TEXT_DOMAIN) . '</a>';
            echo '</div>';

            if (!$row) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Transaction not found.', self::TEXT_DOMAIN) . '</p></div>';
                echo '</div>';
                return;
            }

            $cat_norm = $this->normalize_category((string)($row['kategori'] ?? ''));
            $date_label = ($cat_norm === 'income') ? __('Receive Date', self::TEXT_DOMAIN) : __('Purchase Date', self::TEXT_DOMAIN);
            $purchase_val = (string)($row['purchase_date'] ?? '');
            $receive_val  = (string)($row['receive_date'] ?? '');
            $date_val = ($cat_norm === 'income') ? $receive_val : $purchase_val;
            if (!$date_val || $date_val === '0000-00-00') {
                $date_val = (string)($row['tanggal_struk'] ?? '');
            }
            if (!$date_val || $date_val === '0000-00-00') $date_val = __('N/A', self::TEXT_DOMAIN);

            echo '<div class="fl-card fl-mt">';
            echo '<div class="fl-card-body">';
            echo '<p><strong>' . esc_html__('Transaction ID', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($row['transaction_id'] ?? '') . '</p>';
            echo '<p><strong>' . esc_html__('Item', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($row['items'] ?? '') . '</p>';
            echo '<p><strong>' . esc_html__('Category', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($this->category_label((string)($row['kategori'] ?? ''))) . '</p>';
            echo '<p><strong>' . esc_html__('Entry Date', self::TEXT_DOMAIN) . ':</strong> ' . esc_html($this->fmt_mysql_dt_display((string)($row['tanggal_input'] ?? ''))) . '</p>';
            echo '<p><strong>' . esc_html($date_label) . ':</strong> ' . esc_html($date_val) . '</p>';
            echo '<div class="simku-desc-box"><pre style="white-space:pre-wrap;margin:0;">' . esc_html((string)($row['description'] ?? '')) . '</pre></div>';
            echo '</div>'; // card body
            echo '</div>'; // card

            echo '</div>';
            return;
        }

// Handle delete
        if (!empty($_GET['fl_action']) && $_GET['fl_action'] === 'delete' && current_user_can(self::CAP_MANAGE_TX)) {
            check_admin_referer('fl_delete_tx');
            $line_id = isset($_GET['line_id']) ? sanitize_text_field(wp_unslash($_GET['line_id'])) : '';
            if ($line_id) {
                $row_for_webhook = null;
                if (method_exists($this, 'tx_get_row_for_ui')) {
                    $row_for_webhook = $this->tx_get_row_for_ui($db, $table, $line_id);
                }
                if ($this->ds_is_external() && !$this->ds_allow_write_external()) {
                    echo '<div class="notice notice-error"><p>External datasource is read-only.</p></div>';
                } else {
                    $res = $db->delete($table, ['line_id' => $line_id], ['%s']);
                    if ($res !== false) {
                        $this->log_event('delete', 'transaction', $line_id, ['line_id'=>$line_id]);

                        if (method_exists($this, 'simku_fire_webhooks') && is_array($row_for_webhook)) {
                            $payload = [
                                'line_id' => $row_for_webhook['line_id'] ?? $line_id,
                                'transaction_id' => $row_for_webhook['transaction_id'] ?? '',
                                'category' => $row_for_webhook['kategori'] ?? '',
                                'counterparty' => $row_for_webhook['nama_toko'] ?? '',
                                'item' => $row_for_webhook['items'] ?? '',
                                'qty' => (int)($row_for_webhook['quantity'] ?? 0),
                                'price' => (int)($row_for_webhook['harga'] ?? 0),
                                'total' => (int)((float)($row_for_webhook['harga'] ?? 0) * (float)($row_for_webhook['quantity'] ?? 0)),
                                'entry_date' => $row_for_webhook['tanggal_input'] ?? null,
                                'receipt_date' => $row_for_webhook['tanggal_struk'] ?? null,
                                'purchase_date' => $row_for_webhook['purchase_date'] ?? null,
                                'receive_date' => $row_for_webhook['receive_date'] ?? null,
                                'receipt_urls' => $this->images_from_db_value((string)($row_for_webhook['gambar_url'] ?? '')),
                                'description' => wp_strip_all_tags((string)($row_for_webhook['description'] ?? '')),
                                'user_login' => $row_for_webhook['wp_user_login'] ?? '',
                                'user_id' => (int)($row_for_webhook['wp_user_id'] ?? 0),
                                'source' => 'admin',
                                'deleted_at' => current_time('mysql'),
                            ];
                            $this->simku_fire_webhooks('transaction.deleted', $payload);
                        }

                        echo '<div class="notice notice-success"><p>Deleted.</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>Delete failed.</p></div>';
                    }
                }
            }
        }

        // Filters
        $q = [
            's' => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'kategori' => isset($_GET['kategori']) ? sanitize_text_field(wp_unslash($_GET['kategori'])) : '',
            'user' => isset($_GET['user']) ? sanitize_text_field(wp_unslash($_GET['user'])) : '',
            'tag' => isset($_GET['tag']) ? sanitize_text_field(wp_unslash($_GET['tag'])) : '',
            // Date filter applies to the selected date field
            'date_field' => isset($_GET['date_field']) ? sanitize_text_field(wp_unslash($_GET['date_field'])) : 'entry',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
        ];


        // v0.5.38: normalize legacy category name
        if (!empty($q['kategori'])) $q['kategori'] = $this->normalize_category((string)$q['kategori']);
        // Normalize date field
        $q['date_field'] = $this->reports_sanitize_date_field((string)($q['date_field'] ?? 'entry'));
// Column mapping for transactions table (legacy Indonesian keys in UI, English columns in DB).
$party_col   = $this->tx_col('nama_toko', $db, $table);
$cat_col     = $this->tx_col('kategori', $db, $table);
$price_col   = $this->tx_col('harga', $db, $table);
$entry_col   = $this->tx_col('tanggal_input', $db, $table);
$receipt_col = $this->tx_col('tanggal_struk', $db, $table);
$img_col     = $this->tx_col('gambar_url', $db, $table);
$items_col   = $this->tx_col('items', $db, $table);
$qty_col     = $this->tx_col('quantity', $db, $table);
$desc_col    = $this->tx_desc_col($db, $table);
$tags_col    = $this->tx_col('tags', $db, $table);
$split_col   = $this->tx_col('split_group', $db, $table);

$has_receipt_col = ($receipt_col && $this->ds_column_exists($receipt_col, $db, $table));

$where = "1=1";
$params = [];
if ($q['s']) {
    // Search across multiple fields (IDs, party, item, category, price, qty, description)
    $like = '%' . $db->esc_like($q['s']) . '%';
    $clauses = [
        "transaction_id LIKE %s",
        "line_id LIKE %s",
        "`{$party_col}` LIKE %s",
        "`{$items_col}` LIKE %s",
        "`{$cat_col}` LIKE %s",
        "CAST(`{$price_col}` AS CHAR) LIKE %s",
        "CAST(`{$qty_col}` AS CHAR) LIKE %s",
    ];
    if ($desc_col) $clauses[] = "`{$desc_col}` LIKE %s";
    $where .= " AND (" . implode(' OR ', $clauses) . ")";
    for ($i = 0; $i < count($clauses); $i++) $params[] = $like;
}
if ($q['kategori']) {
    if ($q['kategori'] === 'expense') {
        $where .= " AND `{$cat_col}` IN (%s,%s)";
        $params[] = 'expense';
        $params[] = 'outcome';
    } else {
        $where .= " AND `{$cat_col}` = %s";
        $params[] = $q['kategori'];
    }
}

// Tag filtering
$tag_norm = strtolower(trim((string)($q['tag'] ?? '')));
if ($tag_norm !== '' && $tags_col && $this->ds_column_exists($tags_col, $db, $table)) {
    $where .= " AND LOWER(CONCAT(',', `{$tags_col}`, ',')) LIKE %s";
    $params[] = '%,' . $tag_norm . ',%';
}

// Date filtering (Entry / Purchase / Receive)
$has_purchase_date = $this->ds_column_exists('purchase_date', $db, $table);
$has_receive_date  = $this->ds_column_exists('receive_date', $db, $table);

$date_col = $entry_col;
if ($q['date_field'] === 'purchase') {
    $date_col = $has_purchase_date ? 'purchase_date' : ($has_receipt_col ? $receipt_col : $entry_col);
} elseif ($q['date_field'] === 'receive') {
    $date_col = $has_receive_date ? 'receive_date' : ($has_receipt_col ? $receipt_col : $entry_col);
}

// If user didn't explicitly filter by category, constrain by semantic date type.
if (empty($q['kategori']) && ($q['date_field'] === 'purchase' || $q['date_field'] === 'receive')) {
    if ($q['date_field'] === 'purchase') {
        $where .= " AND `{$cat_col}` IN (%s,%s)";
        $params[] = 'expense';
        $params[] = 'outcome';
    } elseif ($q['date_field'] === 'receive') {
        $where .= " AND `{$cat_col}` = %s";
        $params[] = 'income';
    }
}

if ($q['date_from']) {
    $where .= " AND `{$date_col}` >= %s";
    $params[] = ($date_col === $entry_col) ? ($q['date_from'] . ' 00:00:00') : $q['date_from'];
}
if ($q['date_to']) {
    $where .= " AND `{$date_col}` <= %s";
    $params[] = ($date_col === $entry_col) ? ($q['date_to'] . ' 23:59:59') : $q['date_to'];
}


// User columns can differ per datasource (internal/external/legacy).
        $user_login_col = null; // backticked column name
        if ($this->ds_column_exists('wp_user_login')) {
            $user_login_col = '`wp_user_login`';
        } elseif ($this->ds_column_exists('user')) {
            // Legacy / custom schema
            $user_login_col = '`user`';
        } elseif ($this->ds_column_exists('username')) {
            $user_login_col = '`username`';
        }

        $user_id_col = $this->ds_column_exists('wp_user_id') ? '`wp_user_id`' : null;

        // Apply user filter (if the datasource supports it).
        if (!empty($q['user']) && $user_login_col) {
            $where .= " AND {$user_login_col} = %s";
            $params[] = $q['user'];
        }

        // Build user dropdown options from existing rows (fast + relevant).
        $user_options = [];
        if ($user_login_col) {
            $user_options = $db->get_col("SELECT DISTINCT {$user_login_col} AS u FROM `{$table}` WHERE {$user_login_col} IS NOT NULL AND {$user_login_col} <> '' ORDER BY u ASC LIMIT 200");
        }
        $page = max(1, (int)($_GET['paged'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        $total = $params ? (int)$db->get_var($db->prepare($count_sql, $params)) : (int)$db->get_var($count_sql);

        // Pagination (used at top & bottom)
        $total_pages = max(1, (int)ceil($total / $per_page));
        $pagination_html = $this->render_pagination('fl-transactions', $page, $total_pages, $q);

        // User columns can differ per datasource (internal/external/legacy).
        $user_login_select = $user_login_col ? $user_login_col : 'NULL';
        $user_id_select = $user_id_col ? $user_id_col : 'NULL';


        
$purchase_select = $has_purchase_date ? 'purchase_date AS purchase_date' : 'NULL AS purchase_date';
$receive_select  = $has_receive_date ? 'receive_date AS receive_date' : 'NULL AS receive_date';

$receipt_select = $has_receipt_col ? "`{$receipt_col}` AS tanggal_struk" : "NULL AS tanggal_struk";
$img_select     = ($img_col && $this->ds_column_exists($img_col, $db, $table)) ? "`{$img_col}` AS gambar_url" : "NULL AS gambar_url";
$desc_select    = $desc_col ? "`{$desc_col}` AS description" : "NULL AS description";
$tags_select    = ($tags_col && $this->ds_column_exists($tags_col, $db, $table)) ? "`{$tags_col}` AS tags" : "NULL AS tags";
$split_select   = ($split_col && $this->ds_column_exists($split_col, $db, $table)) ? "`{$split_col}` AS split_group" : "NULL AS split_group";

$sql = "SELECT line_id, transaction_id, {$user_id_select} AS wp_user_id, {$user_login_select} AS wp_user_login, "
     . "`{$party_col}` AS nama_toko, `{$items_col}` AS items, `{$qty_col}` AS quantity, `{$price_col}` AS harga, `{$cat_col}` AS kategori, `{$entry_col}` AS tanggal_input, {$receipt_select}, {$purchase_select}, {$receive_select}, {$img_select}, {$desc_select}, {$tags_select}, {$split_select} "
     . "FROM `{$table}` WHERE {$where} ORDER BY `{$entry_col}` DESC LIMIT %d OFFSET %d";
        $params2 = $params ? array_merge($params, [$per_page, $offset]) : [$per_page, $offset];
        $rows = $db->get_results($db->prepare($sql, $params2), ARRAY_A);

        $base_url = admin_url('admin.php?page=fl-transactions');
        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html('Transactions', '[simku_transactions]', '[simku page="transactions"]');

        // Migration notice
        if ($this->ds_is_external() && (!$this->ext_column_exists('wp_user_id') || !$this->ext_column_exists('wp_user_login'))) {
            echo '<div class="notice notice-warning"><p><b>External table needs user columns.</b> Click "Run Migration" in Settings → Datasource to add <code>wp_user_id</code> and <code>wp_user_login</code>.</p></div>';
        }

        echo '<form method="get" class="fl-filters fl-card simku-tx-filters">';
        echo '<input type="hidden" name="page" value="fl-transactions" />';
        echo '<div class="fl-filters-grid">';

        echo '<div class="fl-field fl-field-search">';
        echo '<label>Search</label>';
        echo '<input type="search" name="s" value="'.esc_attr($q['s']).'" placeholder="Search…" />';
        echo '</div>';

        echo '<div class="fl-field fl-field-tag">';
        echo '<label>Tag</label>';
        echo '<input type="text" name="tag" value="'.esc_attr($q['tag']).'" placeholder="example: food" />';
        echo '</div>';

        echo '<div class="fl-field fl-field-category">';
        echo '<label>Category</label>';
        echo '<select name="kategori"><option value="">All categories</option>';
        foreach (['expense','income'] as $cat) {
            printf('<option value="%s"%s>%s</option>', esc_attr($cat), selected($q['kategori'],$cat,false), esc_html($this->category_label($cat)));
        }
        echo '</select>';
        echo '</div>';

        if ($user_login_col) {
            echo '<div class="fl-field fl-field-user">';
            echo '<label>User</label>';
            echo '<select name="user"><option value="">All users</option>' ;
            foreach ((array)$user_options as $uopt) {
                $uopt = (string)$uopt;
                if ($uopt === '') { continue; }
                printf('<option value="%s"%s>%s</option>', esc_attr($uopt), selected($q['user'], $uopt, false), esc_html($uopt));
            }
            echo '</select>';
echo '</div>';
        }


        // Date Field selector
        echo '<div class="fl-field fl-field-date-field">';
        echo '<label>Date Field</label>';
        echo '<select name="date_field">';
        printf('<option value="entry"%s>Entry Date</option>', selected($q['date_field'], 'entry', false));
        printf('<option value="purchase"%s>Purchase Date</option>', selected($q['date_field'], 'purchase', false));
        printf('<option value="receive"%s>Receive Date</option>', selected($q['date_field'], 'receive', false));
        echo '</select>';
        echo '</div>';


        echo '<div class="fl-field fl-field-from">';
        echo '<label>From</label>';
        echo '<input type="date" name="date_from" value="'.esc_attr($q['date_from']).'" />';
        echo '</div>';

        echo '<div class="fl-field fl-field-to">';
        echo '<label>To</label>';
        echo '<input type="date" name="date_to" value="'.esc_attr($q['date_to']).'" />';
        echo '</div>';

        echo '<div class="fl-field fl-filter-actions">';
        echo '<label>&nbsp;</label>';
        echo '<button class="button button-primary" type="submit">Filter</button>';
        echo '</div>';

        echo '</div>';
        echo '</form>';

        // Use auto table layout for better responsive scrolling on mobile.
        echo '<div class="fl-table-wrap"><table class="widefat striped simku-table">';
        echo '<thead><tr>';
	        $cols = [
            'line_id' => 'Line ID',
            'transaction_id' => 'Transaction ID',
            'split_group' => 'Split Group',
            'tags' => 'Tags',
            'user' => 'User',
	            'nama_toko' => 'Counterparty',
            'items' => 'Item',
            'quantity' => 'Qty',
            'harga' => 'Price',
            'kategori' => 'Category',
            'tanggal_input' => 'Entry Date',
            'purchase_date' => 'Purchase Date',
            'receive_date' => 'Receive Date',
            'gambar_url' => 'Image',
            'description' => 'Description',
            'actions' => 'Actions',
        ];
        foreach ($cols as $k=>$label) {
            echo '<th>'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="'.count($cols).'">No data.</td></tr>';
        } else {
            foreach ($rows as $r) {
                $line_id = (string)($r['line_id'] ?? '');
                $del_url = wp_nonce_url(add_query_arg([
                    'page' => 'fl-transactions',
                    'fl_action' => 'delete',
                    'line_id' => rawurlencode($line_id),
                ], admin_url('admin.php')), 'fl_delete_tx');

                $edit_url = add_query_arg(['page'=>'fl-add-transaction','edit'=>rawurlencode($line_id)], admin_url('admin.php'));

                echo '<tr>';
                echo '<td><code>'.esc_html($line_id).'</code></td>';
                echo '<td>'.esc_html($r['transaction_id'] ?? '').'</td>';

                // Split group (optional). If empty, fall back to transaction_id for readability.
                $split_disp = trim((string)($r['split_group'] ?? ''));
                if ($split_disp === '' && !empty($r['transaction_id'])) {
                    $split_disp = (string)$r['transaction_id'];
                }
                echo '<td>'.esc_html($split_disp).'</td>';

                // Tags (comma-separated in DB)
                $tags_raw = trim((string)($r['tags'] ?? ''));
                $tags_disp = '';
                if ($tags_raw !== '') {
                    $parts = array_values(array_filter(array_map('trim', explode(',', $tags_raw))));
                    $tags_disp = implode(', ', $parts);
                }
                echo '<td>'.esc_html($tags_disp).'</td>';

                $user_disp = (string)($r['wp_user_login'] ?? '');
                if ($user_disp === '' && !empty($r['wp_user_id'])) {
                    $u = get_user_by('id', (int)$r['wp_user_id']);
                    if ($u && !empty($u->user_login)) $user_disp = (string)$u->user_login;
                }
                echo '<td>'.esc_html($user_disp).'</td>';
                echo '<td>'.esc_html($r['nama_toko'] ?? '').'</td>';
                echo '<td>'.esc_html($r['items'] ?? '').'</td>';
                echo '<td>'.esc_html($r['quantity'] ?? '').'</td>';
                echo '<td>Rp '.esc_html(number_format_i18n((float)($r['harga'] ?? 0))).'</td>';
                echo '<td>'.esc_html($this->category_label((string)($r['kategori'] ?? ''))).'</td>';
                echo '<td>'.esc_html($this->fmt_mysql_dt_display((string)($r['tanggal_input'] ?? ''))).'</td>';
                $cat_norm_row = $this->normalize_category((string)($r['kategori'] ?? ''));
                $is_income = ($cat_norm_row === 'income');
                // Legacy installs may still have outcome; treat it as expense.
                $is_expense = in_array($cat_norm_row, ['expense','outcome'], true);

                $struk = trim((string)($r['tanggal_struk'] ?? ''));
                $purchase_val = trim((string)($r['purchase_date'] ?? ''));
                $receive_val  = trim((string)($r['receive_date'] ?? ''));

                $purchase_disp = 'N/A';
                $receive_disp  = 'N/A';
                if ($is_expense) {
                    $v = $purchase_val && $purchase_val !== '0000-00-00' ? $purchase_val : $struk;
                    $purchase_disp = ($v && $v !== '0000-00-00') ? $v : 'N/A';
                } elseif ($is_income) {
                    $v = $receive_val && $receive_val !== '0000-00-00' ? $receive_val : $struk;
                    $receive_disp = ($v && $v !== '0000-00-00') ? $v : 'N/A';
                }
                echo '<td>'.esc_html($purchase_disp).'</td>';
                echo '<td>'.esc_html($receive_disp).'</td>';
                $imgs = $this->normalize_images_field($r['gambar_url'] ?? '');
                if (!empty($imgs)) {
                    $label = (count($imgs) > 1) ? ('View (' . count($imgs) . ')') : 'View';
                    $view_imgs_url = admin_url('admin.php?page=fl-transactions&view_images=1&line_id=' . rawurlencode((string)$r['line_id']));
                    echo '<td><a class="button button-small" href="'.esc_url($view_imgs_url).'">'.esc_html($label).'</a></td>';
                } else {
                    echo '<td></td>';
                }
                $desc = trim((string)($r['description'] ?? ''));
                if ($desc !== '') {
                    $view_desc_url = admin_url('admin.php?page=fl-transactions&view_desc=1&line_id=' . rawurlencode((string)$r['line_id']));
                    echo '<td><a class="button button-small" href="'.esc_url($view_desc_url).'">View</a></td>';
                } else {
                    echo '<td></td>';
                }
                echo '<td class="fl-actions-col">';
                if (current_user_can(self::CAP_MANAGE_TX)) {
                    echo '<div class="simku-actions">';
                    echo '<a class="button button-small" href="'.esc_url($edit_url).'">Edit</a>';
                    echo '<a class="button button-small button-link-delete" href="'.esc_url($del_url).'" onclick="return confirm(\'Delete this row?\')">Delete</a>';
                    echo '</div>';
                } else {
                    echo '<span class="fl-muted">—</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';

        // Pagination
        echo $pagination_html;

        echo '<hr class="fl-hr">';
        echo '<form method="post">';
        wp_nonce_field('simak_export_pdf');
        echo '<input type="hidden" name="simak_export_pdf" value="1" />';
        echo '<button class="button">Export PDF (current filter)</button>';
        echo '</form>';

        // Export PDF action
        if (!empty($_POST['simak_export_pdf'])) {
            check_admin_referer('simak_export_pdf');
            $this->export_pdf_transactions($rows, $q);
        }

        echo '</div>';
    }

    


public function page_add_transaction() {
        if (!current_user_can(self::CAP_MANAGE_TX)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        $db = $this->ds_db();
        if (!$db) { echo '<div class="wrap fl-wrap"><h1>Add Transaction</h1><div class="notice notice-error"><p>Datasource not configured.</p></div></div>'; return; }

        $s = $this->settings();
        $notify_tg_default = !empty($s['notify']['telegram_notify_new_tx_default']);
        $notify_email_default = !empty($s['notify']['email_notify_new_tx_default']);

        $table = $this->ds_table();
        $edit_id = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : '';
        $is_edit = !empty($edit_id);


// Bulk CSV import
$bulk_result = null;
if (!empty($_POST['fl_bulk_csv_submit'])) {
    check_admin_referer('fl_bulk_csv', 'fl_bulk_csv_nonce');
    $bulk_result = $this->handle_bulk_csv_import($db, $table);
}

        $row = null;
        if ($is_edit) {
            $row = $this->tx_get_row_for_ui($db, $table, $edit_id);
        }

        $current_user = wp_get_current_user();
        $user_login = $current_user ? $current_user->user_login : '';
        $user_email = $current_user ? $current_user->user_email : '';

        // Save
        if (!empty($_POST['fl_save_tx'])) {
            check_admin_referer('fl_save_tx');
            if ($this->ds_is_external() && !$this->ds_allow_write_external()) {
                echo '<div class="notice notice-error"><p>External datasource is read-only.</p></div>';
            } else {
                // Ensure user columns exist (option B)
                [$ok, $msgs] = $this->ensure_external_user_columns();
                foreach ($msgs as $m) {
                    echo '<div class="notice '.($ok?'notice-success':'notice-error').'"><p>'.esc_html($m).'</p></div>';
                }
                if ($ok) {
                    // Shared fields (apply to all line items)
                    $line_id_input = sanitize_text_field(wp_unslash($_POST['line_id'] ?? ''));
                    $transaction_id_input = sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? ''));
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

                    // Store as DATE NULL in the new columns, and keep tanggal_struk in sync for legacy code.
                    $purchase_date_db = $purchase_date !== '' ? $purchase_date : null;
                    $receive_date_db  = $receive_date !== '' ? $receive_date : null;

                    $tanggal_struk = '';
                    if ($kategori === 'income') {
                        if ($receive_date === '') {
                            echo '<div class="notice notice-error"><p>Receive Date is required for Income.</p></div>';
                            return;
                        }
                        $tanggal_struk = $receive_date;
                        $purchase_date_db = null;
                    } else { // expense
                        if ($purchase_date === '') {
                            echo '<div class="notice notice-error"><p>Purchase Date is required for Expense.</p></div>';
                            return;
                        }
                        $tanggal_struk = $purchase_date;
                        $receive_date_db = null;
                    }
                    $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));

                    // Tags: allow selecting existing tags to avoid duplicates.
                    $tags_manual = wp_unslash($_POST['tags'] ?? '');
                    $tags_pick = isset($_POST['tags_pick']) ? (array)$_POST['tags_pick'] : [];
                    $tags_pick = array_values(array_filter(array_map(function($t){
                        $t = strtolower(trim((string)wp_unslash($t)));
                        $t = preg_replace('/[^a-z0-9_-]/', '', $t);
                        return $t;
                    }, $tags_pick)));

                    $tags_combined = (string)$tags_manual;
                    if (!empty($tags_pick)) {
                        $tags_combined = trim($tags_combined);
                        $tags_combined .= ($tags_combined !== '' ? ',' : '') . implode(',', $tags_pick);
                    }

                    $tags = method_exists($this, 'normalize_tags_value')
                        ? $this->normalize_tags_value($tags_combined)
                        : sanitize_text_field($tags_combined);
                    $split_group_post = sanitize_text_field(wp_unslash($_POST['split_group'] ?? ''));

                    // Receipt images: support URL(s) + Google Drive (private) storage.
                    $keep_tokens = isset($_POST['existing_images']) ? (array)$_POST['existing_images'] : [];
                    $keep_tokens = array_values(array_filter(array_map(function($x){ return sanitize_text_field(wp_unslash($x)); }, $keep_tokens)));

                    $remove_tokens = isset($_POST['remove_images']) ? (array)$_POST['remove_images'] : [];
                    $remove_tokens = array_values(array_filter(array_map(function($x){ return sanitize_text_field(wp_unslash($x)); }, $remove_tokens)));

                    $manual_urls = $this->parse_image_urls_textarea(wp_unslash($_POST['gambar_url'] ?? ''));

                    $add_urls = [];
                    $add_gdrive = [];

                    $storage_mode = method_exists($this, 'receipts_storage_mode') ? $this->receipts_storage_mode() : 'uploads';

                    if ($storage_mode === 'gdrive') {
                        // Upload any selected files to Google Drive (private).
                        if (method_exists($this, 'handle_multi_receipt_upload_to_gdrive')) {
                            $upg = $this->handle_multi_receipt_upload_to_gdrive('gambar_files');
                            if (!empty($upg['ok'])) {
                                foreach ((array)$upg['items'] as $g) {
                                    if (is_array($g) && !empty($g['id'])) $add_gdrive[] = $g;
                                }
                            } elseif (!empty($upg['error'])) {
                                echo '<div class="notice notice-error"><p>'.esc_html((string)$upg['error']).'</p></div>';
                            }

                            // Backward compat: single input
                            $upg2 = $this->handle_multi_receipt_upload_to_gdrive('gambar_file');
                            if (!empty($upg2['ok'])) {
                                foreach ((array)$upg2['items'] as $g) {
                                    if (is_array($g) && !empty($g['id'])) $add_gdrive[] = $g;
                                }
                            } elseif (!empty($upg2['error'])) {
                                echo '<div class="notice notice-error"><p>'.esc_html((string)$upg2['error']).'</p></div>';
                            }
                        }
                    } else {
                        // Store in WordPress uploads (public URLs)
                        $multi = $this->handle_multi_image_upload('gambar_files');
                        if (!empty($multi['ok']) && !empty($multi['urls']) && is_array($multi['urls'])) {
                            $add_urls = array_merge($add_urls, array_map('strval', $multi['urls']));
                        } elseif (!empty($multi['error'])) {
                            echo '<div class="notice notice-error"><p>'.esc_html((string)$multi['error']).'</p></div>';
                        }

                        $up = $this->handle_tx_image_upload('gambar_file');
                        if (!empty($up['ok']) && !empty($up['url'])) {
                            $add_urls[] = (string) $up['url'];
                        } elseif (!empty($up['error'])) {
                            echo '<div class="notice notice-error"><p>'.esc_html((string)$up['error']).'</p></div>';
                        }
                    }

                    $media = $this->receipt_media_merge($is_edit ? ($row['gambar_url'] ?? '') : '', $keep_tokens, $remove_tokens, array_merge($manual_urls, $add_urls), $add_gdrive);
                    $gambar_url = $this->receipt_media_to_db_value($media);

                    // Normalize datetime-local to MySQL DATETIME following WordPress site timezone.
                    $tanggal_input = $tanggal_input ? $this->mysql_from_ui_datetime((string)$tanggal_input) : '';
                    if (!$tanggal_input) $tanggal_input = current_time('mysql');

                    $send_telegram_new = !empty($_POST['notify_telegram_new']) ? 1 : 0;
                    $send_email_new = !empty($_POST['notify_email_new']) ? 1 : 0;

                    // Build formats dynamically depending on which columns exist.
                    $formats = ['%s','%s','%s','%s','%d','%d','%s','%s','%s'];
                    if ($has_purchase_date) $formats[] = '%s';
                    if ($has_receive_date)  $formats[] = '%s';
                    $formats = array_merge($formats, ['%s','%s','%d','%s']);

                    // Multi-line items mode (only for Add, not Edit): items[] / quantity[] / harga[]
                    $items_post = $_POST['items'] ?? '';
                    $is_multi = (!$is_edit && is_array($items_post));

                    if ($is_multi) {
                        $qty_post = $_POST['quantity'] ?? [];
                        $harga_post = $_POST['harga'] ?? [];

                        $items_arr = is_array($items_post) ? $items_post : [];
                        $qty_arr = is_array($qty_post) ? $qty_post : [];
                        $harga_arr = is_array($harga_post) ? $harga_post : [];

                        $line_items = [];
                        $max = max(count($items_arr), count($qty_arr), count($harga_arr));
                        for ($i = 0; $i < $max; $i++) {
                            $it = isset($items_arr[$i]) ? sanitize_text_field(wp_unslash($items_arr[$i])) : '';
                            $qt = isset($qty_arr[$i]) ? (int) wp_unslash($qty_arr[$i]) : 0;
                            if ($qt <= 0) $qt = 1;
                            $hg_raw = isset($harga_arr[$i]) ? (string) wp_unslash($harga_arr[$i]) : '0';
                            $hg = (int) preg_replace('/[^0-9]/', '', $hg_raw);

                            if ($it === '' && $qt === 1 && $hg === 0) continue; // skip empty template row
                            if ($it === '') continue; // item name is required

                            $line_items[] = ['items' => $it, 'quantity' => $qt, 'harga' => $hg];
                        }

                        if (empty($line_items)) {
                            echo '<div class="notice notice-error"><p>At least 1 item is required.</p></div>';
                        } else {
                            // Determine base id for line_id generation
                            $base = '';
                            $start_n = 1;
                            if ($line_id_input) {
                                if (preg_match('/-(\d+)$/', $line_id_input, $m)) {
                                    $start_n = max(1, (int)$m[1]);
                                }
                                $base = preg_replace('/-\d+$/', '', $line_id_input);
                                if (!$base) $base = $line_id_input;
                            } elseif ($transaction_id_input) {
                                $base = $transaction_id_input;
                            } else {
                                $base = (string) round(microtime(true) * 1000) . '_' . substr(wp_generate_password(12, false, false), 0, 8);
                            }

                            $transaction_id = $transaction_id_input ?: $base;

                            $split_group = $split_group_post ? $split_group_post : $transaction_id;

                            $created = 0;
                            $failed = 0;
                            $first_line_id = '';
                            $fail_msgs = [];
                            $total_sum = 0.0;
                            $item_lines_txt = [];

                            foreach ($line_items as $idx => $li) {
                                $n = $start_n + $idx;
                                $seq = str_pad((string)$n, 3, '0', STR_PAD_LEFT);

                                // first row uses explicit line_id if user filled it; otherwise generate base-###
                                if ($idx === 0 && $line_id_input) {
                                    $line_id = $line_id_input;
                                } else {
                                    $line_id = $base . '-' . $seq;
                                }

                                $data = [
                                    'line_id' => $line_id,
                                    'transaction_id' => $transaction_id,
                                    'nama_toko' => $nama_toko,
                                    'items' => $li['items'],
                                    'quantity' => (int)$li['quantity'],
                                    'harga' => (int)$li['harga'],
                                    'kategori' => $kategori,
                                    'tanggal_input' => $tanggal_input,
                                    'tanggal_struk' => $tanggal_struk,
                                ];
                                if ($has_purchase_date) $data['purchase_date'] = $purchase_date_db;
                                if ($has_receive_date)  $data['receive_date'] = $receive_date_db;
                                $data['gambar_url'] = $gambar_url;
                                $data['description'] = $description;
                                $data['wp_user_id'] = (int)($current_user ? $current_user->ID : 0);
                                $data['wp_user_login'] = sanitize_text_field($user_login);

                                $write_data = $this->tx_map_write_data($data, $db, $table);
                                
                                $formats = $this->tx_build_write_formats($write_data);
$res = $db->insert($table, $write_data, $formats);
                                if ($res === false) {
                                    $failed++;
                                    $fail_msgs[] = "Line {$seq}: insert failed (line_id may already exist).";
                                    continue;
                                }

                                if (!$first_line_id) $first_line_id = $line_id;
                                $created++;
                                $this->log_event('create', 'transaction', $line_id, $data);

                                if (method_exists($this, 'simku_fire_webhooks')) {
                                    $payload = [
                                        'line_id' => $line_id,
                                        'transaction_id' => $transaction_id,
                                        'split_group' => $split_group,
                                        'tags' => $tags !== '' ? array_values(array_filter(array_map('trim', explode(',', (string)$tags)))) : [],
                                        'category' => $kategori,
                                        'counterparty' => $nama_toko,
                                        'item' => $data['items'],
                                        'qty' => (int)$data['quantity'],
                                        'price' => (int)$data['harga'],
                                        'total' => (int)((float)$data['harga'] * (float)$data['quantity']),
                                        'entry_date' => $tanggal_input,
                                        'receipt_date' => $tanggal_struk,
                                        'purchase_date' => $purchase_date_db,
                                        'receive_date' => $receive_date_db,
                                        'receipt_urls' => $this->receipt_urls_only((string)($data['gambar_url'] ?? '')),
                                        'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)($data['gambar_url'] ?? '')),
                                        'description' => wp_strip_all_tags((string)$description),
                                        'user_login' => $user_login,
                                        'user_id' => (int)($current_user ? $current_user->ID : 0),
                                        'source' => 'admin',
                                    ];
                                    $this->simku_fire_webhooks('transaction.created', $payload);
                                }

                                $line_total = (float)$data['harga'] * (float)$data['quantity'];
                                $total_sum += $line_total;
                                $item_lines_txt[] = '• ' . $data['items'] . ' (' . $data['quantity'] . ' x ' . number_format_i18n((float)$data['harga']) . ' = ' . number_format_i18n($line_total) . ')';
                            }

                            if ($created > 0) {
                                $tx_url = esc_url(admin_url('admin.php?page=fl-transactions'));
                                $edit_url = $first_line_id ? esc_url(admin_url('admin.php?page=fl-add-transaction&edit=' . rawurlencode($first_line_id))) : $tx_url;

                                echo '<div class="notice notice-success"><p>Created <b>'.esc_html((string)$created).'</b> item(s) for transaction <code>'.esc_html($transaction_id).'</code>. <a href="'.$tx_url.'">View Transactions</a> | <a href="'.$edit_url.'">Edit first item</a>. The form has been reset so you can add another transaction.</p></div>';

                                if ($failed > 0) {
                                    echo '<div class="notice notice-warning"><p>Insert failed: <b>'.esc_html((string)$failed).'</b>. '.esc_html(implode(' ', array_slice($fail_msgs, 0, 3))).'</p></div>';
                                }

                                // Send single notification (summary) for multi-item transaction
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
                                    ];
                                    if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                                    if ($send_email_new) $this->send_email_new_tx($ctx);
                                }

                                // Check limits quickly
                                $this->cron_check_limits();

                                // reset form
                                $row = [];
                                $edit_id = '';
                            } else {
                                echo '<div class="notice notice-error"><p>All rows failed to insert. '.esc_html(implode(' ', array_slice($fail_msgs, 0, 3))).'</p></div>';
                            }
                        }
                    } else {
                        // Single item (existing behavior)
                        $data = [
                            'line_id' => sanitize_text_field(wp_unslash($_POST['line_id'] ?? '')),
                            'transaction_id' => sanitize_text_field(wp_unslash($_POST['transaction_id'] ?? '')),
                            'split_group' => $split_group_post,
                            'tags' => $tags,
                            'nama_toko' => $nama_toko,
                            'items' => sanitize_text_field(wp_unslash($_POST['items'] ?? '')),
                            'quantity' => (int)($_POST['quantity'] ?? 0),
                            'harga' => (int)preg_replace('/[^0-9]/', '', (string)($_POST['harga'] ?? 0)),
                            'kategori' => $kategori,
                            'tanggal_input' => $tanggal_input,
                            'tanggal_struk' => $tanggal_struk,
                        ];
                        if ($has_purchase_date) $data['purchase_date'] = $purchase_date_db;
                        if ($has_receive_date)  $data['receive_date'] = $receive_date_db;
                        $data['gambar_url'] = $gambar_url;
                        $data['description'] = $description;
                        $data['wp_user_id'] = (int)($current_user ? $current_user->ID : 0);
                        $data['wp_user_login'] = sanitize_text_field($user_login);

                        if ($data['quantity'] <= 0) $data['quantity'] = 1;

                        // Auto generate line_id if empty
                        if (!$data['line_id']) {
                            $base = (string) round(microtime(true) * 1000);
                            $rand = substr(wp_generate_password(12, false, false), 0, 8);
                            $data['line_id'] = $base . '_' . $rand . '-001';
                        }
                        if (!$data['transaction_id']) {
                            // default: strip -### suffix if exists
                            $data['transaction_id'] = preg_replace('/-\d+$/', '', $data['line_id']);
                        }

                        if (empty($data['split_group'])) $data['split_group'] = $data['transaction_id'];

                        if ($is_edit) {
                            $line_id = $edit_id;
                            $data['line_id'] = $line_id; // do not allow changing PK
                            $write_data = $this->tx_map_write_data($data, $db, $table);
                            
                                $formats = $this->tx_build_write_formats($write_data);
$res = $db->update($table, $write_data, ['line_id' => $line_id], $formats, ['%s']);
                            if ($res !== false) {
                                $this->log_event('update', 'transaction', $line_id, $data);

                                if (method_exists($this, 'simku_fire_webhooks')) {
                                    $payload = [
                                        'line_id' => $line_id,
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
                                        'receipt_urls' => $this->receipt_urls_only((string)($data['gambar_url'] ?? '')),
                                    'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)($data['gambar_url'] ?? '')),
                                        'description' => wp_strip_all_tags((string)($data['description'] ?? '')),
                                        'user_login' => $user_login,
                                        'user_id' => (int)($current_user ? $current_user->ID : 0),
                                        'source' => 'admin',
                                    ];
                                    $this->simku_fire_webhooks('transaction.updated', $payload);
                                }

                                echo '<div class="notice notice-success"><p>Updated.</p></div>';
                            } else {
                                echo '<div class="notice notice-error"><p>Update failed.</p></div>';
                            }
                        } else {
                            $write_data = $this->tx_map_write_data($data, $db, $table);
                                
                                $formats = $this->tx_build_write_formats($write_data);
$res = $db->insert($table, $write_data, $formats);
                            if ($res !== false) {
                                $this->log_event('create', 'transaction', $data['line_id'], $data);

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
                                        'receipt_urls' => $this->receipt_urls_only((string)($data['gambar_url'] ?? '')),
                                    'receipt_gdrive_ids' => $this->receipt_gdrive_ids((string)($data['gambar_url'] ?? '')),
                                        'description' => wp_strip_all_tags((string)($data['description'] ?? '')),
                                        'user_login' => $user_login,
                                        'user_id' => (int)($current_user ? $current_user->ID : 0),
                                        'source' => 'admin',
                                    ];
                                    $this->simku_fire_webhooks('transaction.created', $payload);
                                }

                                echo '<div class="notice notice-success"><p>Created. <a href="'.esc_url(admin_url('admin.php?page=fl-add-transaction&edit=' . rawurlencode($data['line_id']))).'">Edit this transaction</a>. The form has been reset so you can add another transaction.</p></div>';

                                // Telegram / Email on new transaction (manual)
                                if ($send_telegram_new || $send_email_new) {
                                    $total = (float)$data['harga'] * (float)$data['quantity'];
                                    $ctx = [
                                        'user' => esc_html($user_login),
                                        
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
                                        'tags' => esc_html((string)($data['tags'] ?? '')),
                                        'gambar_url' => esc_html($this->receipt_primary_url_for_notification((string)($data['gambar_url'] ?? ''))),
                                        'description' => esc_html(wp_strip_all_tags((string)($data['description'] ?? ''))),
                                    ];
                                    if ($send_telegram_new) $this->send_telegram_new_tx($ctx);
                                    if ($send_email_new) $this->send_email_new_tx($ctx);
                                }

                                // Check limits quickly
                                $this->cron_check_limits();
                            } else {
                                echo '<div class="notice notice-error"><p>Insert failed (line_id may already exist).</p></div>';
                            }
                        }

                        // refresh row for edit form. For a new transaction, reset the form so user can add again.
                        if ($is_edit) {
                            $row = $this->tx_get_row_for_ui($db, $table, $data['line_id']);
                        } else {
                            $row = [];
                            $edit_id = '';
                        }
                    }
                }
            }
        }

        // Defaults for form
        $cat_norm_row = $this->normalize_category((string)($row['kategori'] ?? 'expense'));
        $purchase_val = (string)($row['purchase_date'] ?? '');
        $receive_val  = (string)($row['receive_date'] ?? '');
        $struk_val    = (string)($row['tanggal_struk'] ?? '');
        if ($purchase_val === '' && $receive_val === '' && $struk_val !== '') {
            if ($cat_norm_row === 'income') {
                $receive_val = $struk_val;
            } else {
                $purchase_val = $struk_val;
            }
        }

        $v = [
            'line_id' => $row['line_id'] ?? '',
            'transaction_id' => $row['transaction_id'] ?? '',
            'split_group' => $row['split_group'] ?? '',
            'tags' => $row['tags'] ?? '',
            'nama_toko' => $row['nama_toko'] ?? '',
            'items' => $row['items'] ?? '',
            'quantity' => $row['quantity'] ?? 1,
            'harga' => $row['harga'] ?? 0,
            'kategori' => $cat_norm_row,
            'tanggal_input' => $row['tanggal_input'] ?? current_time('mysql'),
            'purchase_date' => $purchase_val,
            'receive_date' => $receive_val,
            // keep for legacy display/exports
            'tanggal_struk' => $struk_val,
            'gambar_url' => $row['gambar_url'] ?? '',
            'description' => $row['description'] ?? '',
            'wp_user_login' => $row['wp_user_login'] ?? $user_login,
        ];

        echo '<div class="wrap fl-wrap">';
        echo $this->page_header_html(($is_edit?'Edit Transaction':'Add Transaction'), '[simku_add_transaction]', '[simku page="add-transaction"]');
        

// Bulk import result notice
if ($bulk_result !== null) {
    if (!empty($bulk_result['ok'])) {
        echo '<div class="notice notice-success"><p>CSV import finished. Inserted: <b>'.esc_html((string)$bulk_result['inserted']).'</b>, Skipped: <b>'.esc_html((string)$bulk_result['skipped']).'</b>.</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>CSV import failed: '.esc_html(($bulk_result['errors'][0] ?? 'Unknown error')).'</p></div>';
    }
    if (!empty($bulk_result['errors']) && count($bulk_result['errors']) > 1) {
        echo '<div class="notice notice-warning"><p><b>Detail:</b><br>'.esc_html(implode("\n", array_slice($bulk_result['errors'], 0, 5))).'</p></div>';
    }
}

// Bulk CSV Import UI (captured for right-side column)
ob_start();
echo '<div class="fl-card fl-card-split simku-bulk-import fl-mt">';
echo '<div class="fl-card-head"><h2 style="margin:0;">Bulk Import (CSV)</h2><div class="fl-help">Upload a CSV to add many transactions at once.</div></div>';
echo '<div class="fl-card-body">';
echo '<form method="post" enctype="multipart/form-data" class="fl-form fl-bulk-form">';
wp_nonce_field('fl_bulk_csv', 'fl_bulk_csv_nonce');
echo '<div class="fl-grid fl-grid-2">';
echo '<div class="fl-field"><label>CSV File</label><input type="file" name="fl_bulk_csv_file" accept=".csv,text/csv" required></div>';
echo '<div class="fl-field"><label>Options</label>';
echo '<div class="fl-check-group">';
echo '<label class="fl-check"><input type="checkbox" name="fl_bulk_csv_notify_telegram" value="1"> <span>Send Telegram (per row)</span></label>';
echo '<label class="fl-check"><input type="checkbox" name="fl_bulk_csv_notify_email" value="1"> <span>Send Email (per row)</span></label>';
echo '</div></div>';
echo '</div>';
echo '<div class="fl-help fl-bulk-help">';
echo '<div><b>Supported headers</b> (subset allowed): <code>user</code>, <code>line_id</code>, <code>transaction_id</code>, <code>split_group</code>, <code>tags</code>, <code>nama_toko</code>, <code>items</code>, <code>quantity</code>, <code>harga</code>, <code>kategori</code>, <code>tanggal_input</code>, <code>purchase_date</code>, <code>receive_date</code>, <code>tanggal_struk</code> (legacy fallback), <code>gambar_url</code>, <code>description</code>.</div>';
echo '<div><b>Aliases</b>: <code>toko</code>, <code>item</code>, <code>qty</code>, <code>price</code>, <code>category</code>, <code>type</code>, <code>date_input</code>, <code>date_receipt</code>, <code>receipt_date</code>, <code>purchase</code>, <code>receive</code>.</div>';
echo '<div><b>Date rules</b>: category must be <code>income</code> or <code>expense</code>. Income requires <code>receive_date</code> (or uses <code>tanggal_struk</code> as fallback). Expense requires <code>purchase_date</code> (or uses <code>tanggal_struk</code> as fallback).</div><div><b>Date format</b>: dates use <code>YYYY-mm-dd</code>; datetime uses <code>YYYY-mm-dd HH:ii:ss</code> or <code>dd/mm/YYYY HH.ii</code>.</div>';
echo '</div>';
 $tpl_url = add_query_arg(['fl_export_transaction_template'=>1]);
echo '<div class="fl-actions fl-btnrow">';
echo '<button class="button button-primary" type="submit" name="fl_bulk_csv_submit" value="1">Import CSV</button> ';
echo '<a class="button" href="'.esc_url($tpl_url).'">Download template</a>';
echo '</div>';
echo '</form>';
echo '</div></div>';

$bulk_import_html = ob_get_clean();

if ($this->ds_is_external() && (!$this->ext_column_exists('wp_user_id') || !$this->ext_column_exists('wp_user_login'))) {
            echo '<div class="notice notice-warning"><p><b>External table needs user columns.</b> Go to <a href="'.esc_url(admin_url('admin.php?page=fl-settings#fl-datasource')).'">Settings → Datasource</a> and run migration.</p></div>';
        }

        // Two-column layout (matches Add Reminder)
echo '<div class="fl-grid fl-grid-2 fl-guide-layout fl-mt">';
echo '<div class="fl-main-col">';

echo '<form method="post" enctype="multipart/form-data" class="fl-form simku-addtx-form">';
        wp_nonce_field('fl_save_tx');
        echo '<input type="hidden" name="fl_save_tx" value="1" />';

        
echo '<div class="fl-card fl-card-full">';
echo '<h2>Transaction</h2>';

// User field (readonly)
echo '<div class="fl-field"><label>User</label><input type="text" class="fl-input" value="'.esc_attr($user_login).'" readonly /></div>';

if ($is_edit) {
    echo '<div class="fl-field"><label>Line ID (PK)</label><input type="text" class="fl-input" name="line_id" value="'.esc_attr($v['line_id']).'" readonly /></div>';
} else {
    echo '<div class="fl-field"><label>Line ID (PK) <span class="fl-muted">(optional, auto)</span></label><input type="text" class="fl-input" name="line_id" value="'.esc_attr($v['line_id']).'" placeholder="Auto generated if empty" /></div>';
}

echo '<div class="fl-field"><label>Transaction ID</label><input type="text" class="fl-input" name="transaction_id" value="'.esc_attr($v['transaction_id']).'" placeholder="Auto from Line ID if empty" /></div>';
echo '<div class="fl-grid fl-grid-2">';
echo '<div class="fl-field"><label>Split Group <span class="fl-muted">(optional)</span></label><input type="text" class="fl-input" name="split_group" value="'.esc_attr($v['split_group'] ?? '').'" placeholder="Default = Transaction ID" /></div>';

// Tags picker + manual input (merged server-side)
$tags_all = [];
if (method_exists($this, 'list_transaction_tags')) {
    $tags_all = $this->list_transaction_tags(20000, '');
}
$tags_selected = [];
$tags_raw = trim((string)($v['tags'] ?? ''));
if ($tags_raw !== '') {
    $tags_selected = array_values(array_filter(array_map(function($t){
        $t = strtolower(trim((string)$t));
        return $t !== '' ? preg_replace('/[^a-z0-9_-]/', '', $t) : '';
    }, explode(',', $tags_raw))));
}

echo '<div class="fl-field simku-tags-field">';
echo '<label>Tags <span class="fl-muted">(comma)</span></label>';
echo '<select class="fl-input simku-tags-picker" name="tags_pick[]" multiple size="5">';
if (!empty($tags_all)) {
    foreach ($tags_all as $t) {
        $t = (string)$t;
        if ($t === '') continue;
        echo '<option value="'.esc_attr($t).'" '.(in_array($t, $tags_selected, true) ? 'selected' : '').'>'.esc_html($t).'</option>';
    }
}
echo '</select>';
echo '<div class="fl-help">Pick existing tags to avoid duplicates.</div>';
echo '<input type="text" class="fl-input" name="tags" value="'.esc_attr($v['tags'] ?? '').'" placeholder="Add new tags: food,coffee" style="margin-top:8px;" />';
echo '</div>';

echo '</div>';
echo '<div class="fl-field"><label>Counterparty</label><input type="text" class="fl-input" name="nama_toko" value="'.esc_attr($v['nama_toko']).'" placeholder="Example: FamilyMart / Dana / Salary / Bank Transfer" /></div>';

if ($is_edit) {
    echo '<div class="fl-field"><label>Item</label><input type="text" class="fl-input" name="items" value="'.esc_attr($v['items']).'" required /></div>';
    echo '<div class="fl-grid fl-grid-2">';
    echo '<div class="fl-field"><label>Qty</label><input type="number" class="fl-input" min="0" name="quantity" value="'.esc_attr($v['quantity']).'" required /></div>';
    echo '<div class="fl-field"><label>Price</label><input type="number" class="fl-input" min="0" name="harga" value="'.esc_attr($v['harga']).'" required /></div>';
    echo '</div>';
} else {
    echo '<div class="fl-field"><label>Items</label><div class="fl-help">Click <b>Add Item</b> to add a new row (each row is saved as a different line_id, but shares the same <code>transaction_id</code>).</div>';
    echo '<div id="simak-line-items" class="simak-line-items">';
    echo '<div class="simak-line-item-head" aria-hidden="true"><span>Item</span><span>Qty</span><span>Price</span><span></span></div>';
    echo '<div class="simak-line-item-row">';
    echo '<input type="text" class="fl-input" name="items[]" placeholder="Item name" required />';
    echo '<input type="number" class="fl-input" min="1" name="quantity[]" value="1" data-default="1" placeholder="Qty" required />';
    echo '<input type="number" class="fl-input" min="0" name="harga[]" value="0" data-default="0" placeholder="Price" required />';
    echo '<button type="button" class="button simak-remove-row" aria-label="Remove row" title="Remove row">×</button>';
    echo '</div>';
    echo '</div>';
    echo '<div class="fl-actions" style="margin-top:10px;"><button type="button" class="button" id="simak-add-item-row">+ Add Item</button></div>';
    echo '</div>';
}

echo '<div class="fl-field"><label>Category</label><select id="simku_kategori" class="fl-input" name="kategori">';
foreach (['expense','income'] as $cat) {
    echo '<option value="'.esc_attr($cat).'" '.selected($v['kategori'],$cat,false).'>'.esc_html($this->category_label($cat)).'</option>';
}
echo '</select></div>';

echo '<hr class="fl-divider" />';
echo '<h3 class="fl-section-title">Dates & Attachments</h3>';

// datetime-local expects 2026-01-03T20:50
$ti_local = $this->dtlocal_value_from_mysql((string)($v['tanggal_input'] ?? ''));
echo '<div class="fl-field"><label>Entry Date</label><input type="datetime-local" class="fl-input" name="tanggal_input" value="'.esc_attr($ti_local).'" /></div>';
// NOTE(UI): keep Add Transaction form compact (no extra help paragraph here).
echo '<div class="fl-grid fl-grid-2">';
echo '<div class="fl-field"><label>Purchase Date</label><input id="simku_purchase_date" type="date" class="fl-input" name="purchase_date" value="'.esc_attr($v['purchase_date']).'" /></div>';
echo '<div class="fl-field"><label>Receive Date</label><input id="simku_receive_date" type="date" class="fl-input" name="receive_date" value="'.esc_attr($v['receive_date']).'" /></div>';
echo '</div>';

// Images (multi)
echo '<div class="fl-field"><label>Upload Images</label>';
echo '<div class="fl-filepicker">';
echo '<input type="file" id="fl_tx_images" name="gambar_files[]" accept="image/*" multiple style="position:absolute;left:-9999px;" />';
echo '<button type="button" class="button" data-fl-file-trigger="fl_tx_images">Choose Files</button> ';
echo '<span class="fl-file-label" data-fl-file-label="fl_tx_images">No files chosen</span>';
echo '</div>';
// NOTE(UI): do not show image compression tip text.
echo '</div>';

$media_prev = $this->receipt_media_from_db_value($v['gambar_url'] ?? '');
$prev_urls = $this->normalize_images_field($media_prev['urls'] ?? []);
$prev_gds  = is_array($media_prev['gdrive'] ?? null) ? $media_prev['gdrive'] : [];

if (!empty($prev_urls) || !empty($prev_gds)) {
    echo '<div class="fl-field"><label>Existing Images</label>';
    echo '<div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-start;">';

    foreach ($prev_urls as $img_url) {
        $img = esc_url($img_url);
        if (!$img) continue;
        echo '<div style="width:140px;">';
        echo '<input type="hidden" name="existing_images[]" value="'.esc_attr($img).'" />';
        echo '<a href="'.$img.'" target="_blank" rel="noopener noreferrer"><img src="'.$img.'" alt="Preview" style="width:140px;height:auto;border:1px solid #d0d7de;border-radius:8px;" /></a>';
        if ($is_edit) {
            echo '<label style="display:block;margin-top:6px;"><input type="checkbox" name="remove_images[]" value="'.esc_attr($img).'"> Remove</label>';
        }
        echo '</div>';
    }

    foreach ($prev_gds as $g) {
        if (!is_array($g)) continue;
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($g['id'] ?? ''));
        if (!$id) continue;
        $proxy = esc_url($this->gdrive_proxy_url($id));
        if (!$proxy) continue;
        $token = 'gdrive:' . $id;
        echo '<div style="width:140px;">';
        echo '<input type="hidden" name="existing_images[]" value="'.esc_attr($token).'" />';
        echo '<a href="'.$proxy.'" target="_blank" rel="noopener noreferrer"><img src="'.$proxy.'" alt="Drive receipt" style="width:140px;height:auto;border:1px solid #d0d7de;border-radius:8px;" /></a>';
        if ($is_edit) {
            echo '<label style="display:block;margin-top:6px;"><input type="checkbox" name="remove_images[]" value="'.esc_attr($token).'"> Remove</label>';
        }
        echo '</div>';
    }

    echo '</div>';
    if ($is_edit) {
        echo '<div class="fl-help">Tick <b>Remove</b>, then click <b>Save Changes</b> to delete images.</div>';
    }
    echo '</div>';
}

echo '<div class="fl-field"><label>Image URL(s)</label><textarea class="fl-input" name="gambar_url" rows="3" placeholder="https://...\nhttps://..."></textarea><div class="fl-help">One URL per line. If you upload images, the URLs will be appended automatically when saved.</div></div>';
echo '<div class="fl-field"><label>Description</label><textarea class="fl-input" name="description" rows="5">'.esc_textarea($v['description']).'</textarea></div>';

$s = $this->settings();
$n = $s['notify'] ?? [];

// New transaction notifications (optional)
if (!empty($n['telegram_enabled']) && !empty($n['telegram_bot_token']) && !empty($n['telegram_chat_id'])) {
    $checked = $notify_tg_default ? 'checked' : '';
    echo '<div class="fl-field fl-check"><label><input type="checkbox" name="notify_telegram_new" value="1" '.$checked.' /> Send Telegram notification for new transaction</label></div>';
} else {
    echo '<div class="fl-muted">Telegram notification is not configured (Settings → Notifications).</div>';
}

if (!empty($n['email_enabled']) && !empty($n['email_to'])) {
    $checked_email = $notify_email_default ? 'checked' : '';
    echo '<div class="fl-field fl-check"><label><input type="checkbox" name="notify_email_new" value="1" '.$checked_email.' /> Send Email notification for new transaction</label></div>';
} else {
    echo '<div class="fl-muted">Email notification is not configured (Settings → Notifications).</div>';
}

echo '<div class="fl-actions">';
echo '<button class="button button-primary">'.($is_edit?'Save Changes':'Add Transaction').'</button> ';
echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fl-transactions')).'">Back</a>';
echo '</div>';

echo '</div>'; // single card

echo '</form>';

echo '</div>'; // left column

// Right column: Bulk import + guide
echo '<div class="fl-guide-col">';
echo $bulk_import_html;
echo '<div class="fl-guide-card fl-mt">';
echo   '<div class="fl-guide-header">';
echo     '<div class="fl-guide-title">Transaction Form Guide</div>';
echo     '<div class="fl-guide-subtitle">Quick help for filling the fields correctly (and CSV import notes).</div>';
echo   '</div>';
echo   '<div class="fl-guide-body">';

echo   '<details class="fl-guide-section" open><summary>Identification</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Line ID (Primary Key)</b><p class="fl-guide-hint">Leave empty when adding a new transaction. The system generates a unique row ID. In Edit mode it is read-only.</p></div>';
echo     '<div class="fl-guide-item"><b>Transaction ID</b><p class="fl-guide-hint">Optional. If left blank, it can be derived automatically (grouping multiple items under one transaction).</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section" open><summary>Counterparty & items</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Counterparty</b><p class="fl-guide-hint">Who you paid / who paid you (e.g., FamilyMart, Salary, Bank Transfer).</p></div>';
echo     '<div class="fl-guide-item"><b>Items</b><p class="fl-guide-hint">Use <b>Add Item</b> to add one or more line items. Each line item is stored as a separate row but can share the same Transaction ID.</p></div>';
echo     '<div class="fl-guide-item"><b>Category</b><p class="fl-guide-hint"><b>Expense</b> for spending, <b>Income</b> for money received.</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section" open><summary>Dates</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Entry date</b><p class="fl-guide-hint">When you recorded the transaction (system uses the WordPress timezone).</p></div>';
echo     '<div class="fl-guide-item"><b>Purchase date (Expense)</b><p class="fl-guide-hint">Required for Expense. If empty, the system may fall back to receipt date (if provided).</p></div>';
echo     '<div class="fl-guide-item"><b>Receive date (Income)</b><p class="fl-guide-hint">Required for Income. If empty, the system may fall back to receipt date (if provided).</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section"><summary>Attachments & notes</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Upload images / Image URLs</b><p class="fl-guide-hint">Optional. Attach receipts or proof. One URL per line in the URL box.</p></div>';
echo     '<div class="fl-guide-item"><b>Description</b><p class="fl-guide-hint">Optional notes for additional context.</p></div>';
echo   '</div></details>';

echo   '<details class="fl-guide-section"><summary>CSV import template</summary><div class="fl-guide-content">';
echo     '<div class="fl-guide-item"><b>Download template</b><p class="fl-guide-hint">Use the <b>Download template</b> button above to get a ready-to-fill CSV with headers and an example row.</p></div>';
echo     '<div class="fl-guide-item"><b>Date formats</b><p class="fl-guide-hint">Dates: <code>YYYY-mm-dd</code>. Datetime: <code>YYYY-mm-dd HH:ii:ss</code> (recommended).</p></div>';
echo   '</div></details>';

echo   '</div>'; // guide body
echo '</div>'; // guide card
        if (!$is_edit) {
            echo '<script>
(function(){
  const wrap = document.getElementById("simak-line-items");
  const addBtn = document.getElementById("simak-add-item-row");
  if (!wrap || !addBtn) return;

  const template = wrap.querySelector(".simak-line-item-row");
  function renumber(){
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    rows.forEach((row) => {
      const rm = row.querySelector(".simak-remove-row");
      if (rm) rm.style.visibility = (rows.length > 1) ? "visible" : "hidden";
    });
  }

  addBtn.addEventListener("click", function(){
    const clone = template.cloneNode(true);
    clone.querySelectorAll("input").forEach((inp) => {
      const def = inp.getAttribute("data-default");
      inp.value = (def !== null) ? def : "";
    });
    wrap.appendChild(clone);
    renumber();
  });

  wrap.addEventListener("click", function(e){
    const btn = e.target.closest(".simak-remove-row");
    if (!btn) return;
    const row = btn.closest(".simak-line-item-row");
    const rows = wrap.querySelectorAll(".simak-line-item-row");
    if (row && rows.length > 1) {
      row.remove();
      renumber();
    }
  });

  renumber();
})();
</script>';
        }

                // UI: toggle Purchase Date / Receive Date based on category (show N/A when disabled).
                echo '<script>(function(){
  function el(id){ return document.getElementById(id); }
  var sel = el("simku_kategori") || document.querySelector("select[name=\"kategori\"]");
  var purchase = el("simku_purchase_date") || document.querySelector("input[name=\"purchase_date\"]");
  var receive  = el("simku_receive_date") || document.querySelector("input[name=\"receive_date\"]");

  function setFieldState(input, enabled){
    if(!input) return;
    if(enabled){
      input.disabled = false;
      input.required = true;
      if(input.type !== "date") input.type = "date";
      if(input.value === "N/A") input.value = input.dataset.prevVal || "";
    } else {
      // remember previous value (to restore if toggled back)
      if(input.type === "date") input.dataset.prevVal = input.value || "";
      input.required = false;
      input.disabled = true;
      input.type = "text";
      input.value = "N/A";
    }
  }

  function sync(){
    var cat = String((sel && sel.value) ? sel.value : "expense").toLowerCase();
    var isIncome = (cat === "income");
    setFieldState(purchase, !isIncome);
    setFieldState(receive, isIncome);
  }

  if(sel){
    sel.addEventListener("change", sync);
    sel.addEventListener("input", sync);
  }
  sync();
})();</script>';



        echo '</div>'; // right column
	echo '</div>'; // outer layout
	
	echo '</div>'; // wrap
	echo '<div class="clear"></div>';
    }

    
    

}
