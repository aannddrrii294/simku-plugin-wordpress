<?php
/**
 * Reports module (daily/weekly/monthly summaries + export handlers).
 *
 * Extracted from the main plugin file to improve maintainability.
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Reports {

    private function reports_sanitize_pdf_tpl($tpl){
        $tpl = strtolower(trim((string)$tpl));
        $allow = ['standard','compact','summary','custom'];
        return in_array($tpl, $allow, true) ? $tpl : 'standard';
    }

    private function reports_sanitize_pdf_orient($o){
        $o = strtolower(trim((string)$o));
        return in_array($o, ['portrait','landscape'], true) ? $o : 'portrait';
    }

    private function reports_pdf_cols_from_request(){
        $cols = [];
        if (isset($_REQUEST['pdf_cols'])) {
            $raw = wp_unslash($_REQUEST['pdf_cols']);
            if (is_array($raw)) {
                foreach ($raw as $v) { $cols[] = sanitize_text_field($v); }
            } else {
                $raw = sanitize_text_field((string)$raw);
                if ($raw !== '') $cols = array_map('sanitize_text_field', array_filter(array_map('trim', explode(',', $raw))));
            }
        }
        $cols = array_values(array_unique(array_filter($cols)));
        return $cols;
    }

    private function reports_pdf_text_from_request($key){
        $v = isset($_REQUEST[$key]) ? sanitize_text_field(wp_unslash($_REQUEST[$key])) : '';
        // keep it short to avoid huge PDFs
        if (strlen($v) > 120) $v = substr($v, 0, 120);
        return $v;
    }


    private function reports_sanitize_pdf_mode($m){
        $m = strtolower(trim((string)$m));
        $allow = ['summary_breakdown','full_detail'];
        return in_array($m, $allow, true) ? $m : 'full_detail';
    }

    private function reports_pdf_breakdowns_from_request(){
        $raw = isset($_REQUEST['pdf_breakdowns']) ? wp_unslash($_REQUEST['pdf_breakdowns']) : [];
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $v) { $out[] = sanitize_text_field($v); }
        } else {
            $raw = sanitize_text_field((string)$raw);
            if ($raw !== '') $out = array_map('sanitize_text_field', array_filter(array_map('trim', explode(',', $raw))));
        }
        $out = array_values(array_unique(array_filter($out)));
        $allow = ['category','tags','counterparty','date'];
        $out = array_values(array_intersect($out, $allow));
        if (empty($out)) $out = ['category'];
        return $out;
    }

    private function reports_pdf_logo_id_from_request(){
        $id = isset($_REQUEST['pdf_logo_id']) ? (int)sanitize_text_field(wp_unslash($_REQUEST['pdf_logo_id'])) : 0;
        return ($id > 0) ? $id : 0;
    }

    private function reports_prepare_pdf_logo_image($logo_id){
        $logo_id = (int)$logo_id;
        if ($logo_id <= 0) return null;

        if (!function_exists('get_attached_file')) return null;
        $path = (string)get_attached_file($logo_id);
        if ($path === '' || !file_exists($path)) return null;

        $info = @getimagesize($path);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) return null;
        $w = (int)$info[0]; $h = (int)$info[1];
        $mime = (string)($info['mime'] ?? '');

        // Convert to JPEG for simple PDF embedding (DCTDecode). Also downscale to keep PDF small.
        $max_dim = 360;
        $target_w = $w; $target_h = $h;
        if ($w > $max_dim || $h > $max_dim) {
            if ($w >= $h) {
                $target_w = $max_dim;
                $target_h = (int)round(($h / max(1, $w)) * $max_dim);
            } else {
                $target_h = $max_dim;
                $target_w = (int)round(($w / max(1, $h)) * $max_dim);
            }
        }

        $img = null;
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $img = @imagecreatefromjpeg($path);
        } elseif ($mime === 'image/png') {
            $img = @imagecreatefrompng($path);
        } elseif ($mime === 'image/gif') {
            $img = @imagecreatefromgif($path);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($path);
        } else {
            // try fallback by extension
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg'], true)) $img = @imagecreatefromjpeg($path);
            elseif ($ext === 'png') $img = @imagecreatefrompng($path);
            elseif ($ext === 'gif') $img = @imagecreatefromgif($path);
            elseif ($ext === 'webp' && function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($path);
        }
        if (!is_resource($img) && !($img instanceof GdImage)) return null;

        // Resample if needed
        if ($target_w !== $w || $target_h !== $h) {
            $dst = imagecreatetruecolor($target_w, $target_h);
            // fill white background (for transparent PNG)
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $target_w, $target_h, $white);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $target_w, $target_h, $w, $h);
            imagedestroy($img);
            $img = $dst;
            $w = $target_w; $h = $target_h;
        }

        ob_start();
        imagejpeg($img, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($img);

        if (!is_string($jpeg) || $jpeg === '') return null;

        return [
            'name' => 'ImLogo',
            'data' => $jpeg,
            'w'    => $w,
            'h'    => $h,
        ];
    }

    private function reports_row_amount($r){
        $price = (float)($r['harga'] ?? $r['price'] ?? 0);
        $qty   = (float)($r['quantity'] ?? $r['qty'] ?? 0);
        if ($qty <= 0) $qty = 1;
        return $price * $qty;
    }

    private function reports_pick_row_date_raw($r, $tx_type){
        $receipt_raw  = (string)($r['tanggal_struk'] ?? $r['tanggal_receipt'] ?? $r['receipt_date'] ?? '');
        $purchase_raw = (string)($r['purchase_date'] ?? $r['purchaseDate'] ?? '');
        $receive_raw  = (string)($r['receive_date'] ?? $r['receiveDate'] ?? '');
        $entry_raw    = (string)($r['tanggal_input'] ?? $r['entry_date'] ?? $r['entryDate'] ?? '');

        $cat      = (string)($r['kategori'] ?? $r['category'] ?? '');
        $cat_norm = strtolower(trim($cat));
        $is_income = ($cat_norm === 'income');

        $date_raw = $entry_raw;
        if ($tx_type === 'income' || ($tx_type === 'all' && $is_income)) {
            $date_raw = $receive_raw ?: $receipt_raw ?: $entry_raw;
        } elseif ($tx_type === 'expense' || ($tx_type === 'all' && !$is_income)) {
            $date_raw = $purchase_raw ?: $receipt_raw ?: $entry_raw;
        }
        return $date_raw;
    }

    private function reports_compute_breakdowns($rows, $tx_type, $selected){
        $tx_type = $this->reports_sanitize_tx_type((string)$tx_type);
        $selected = is_array($selected) ? $selected : [];
        $out = [
            'category' => [],
            'tags' => [],
            'counterparty' => [],
            'date' => [],
        ];

        foreach ((array)$rows as $r) {
            $amt = $this->reports_row_amount($r);
            if ($amt <= 0) continue;

            $cat = $this->normalize_category((string)($r['kategori'] ?? $r['category'] ?? ''));
            $party = trim((string)($r['nama_toko'] ?? $r['merchant'] ?? ''));
            $tags = trim((string)($r['tags'] ?? ''));

            if (in_array('category', $selected, true)) {
                $key = $cat !== '' ? $cat : '(none)';
                $out['category'][$key] = (float)($out['category'][$key] ?? 0) + $amt;
            }

            if (in_array('counterparty', $selected, true)) {
                $key = $party !== '' ? $party : '(none)';
                $out['counterparty'][$key] = (float)($out['counterparty'][$key] ?? 0) + $amt;
            }

            if (in_array('date', $selected, true)) {
                $dr = $this->reports_pick_row_date_raw($r, $tx_type);
                $dd = $this->fmt_date_short($dr);
                if ($dd === '') $dd = $this->fmt_date_short((string)($r['tanggal_input'] ?? ''));
                if ($dd === '') $dd = '(unknown)';
                $out['date'][$dd] = (float)($out['date'][$dd] ?? 0) + $amt;
            }

            if (in_array('tags', $selected, true) && $tags !== '') {
                $parts = array_filter(array_map('trim', explode(',', $tags)));
                foreach ($parts as $tg) {
                    if ($tg === '') continue;
                    $out['tags'][$tg] = (float)($out['tags'][$tg] ?? 0) + $amt;
                }
            }
        }

        // sort: amount desc (except date asc)
        foreach (['category','tags','counterparty'] as $k) {
            arsort($out[$k], SORT_NUMERIC);
        }
        if (!empty($out['date'])) {
            // parse d/m/Y and sort ascending
            uksort($out['date'], function($a,$b){
                $ta = strtotime(str_replace('/','-',$a));
                $tb = strtotime(str_replace('/','-',$b));
                if (!$ta || !$tb) return strcmp($a,$b);
                return $ta <=> $tb;
            });
        }

        return $out;
    }

private function export_pdf_report($title, $tot, $meta = []) {
        $generated = wp_date('Y-m-d H:i:s');
        $range_display = (string)($meta['range_display'] ?? ($meta['range'] ?? ''));
        $start_dt = (string)($meta['start_dt'] ?? '');
        $end_dt = (string)($meta['end_dt'] ?? '');
        $date_basis = $this->sanitize_date_basis((string)($meta['date_basis'] ?? 'input'));
        $user_login = (string)($meta['user_login'] ?? '');
        $tx_type = $this->reports_sanitize_tx_type((string)($meta['tx_type'] ?? 'all'));

        // PDF options (layout + content)
        $opts = [
            'tpl'        => $this->reports_sanitize_pdf_tpl($meta['pdf_tpl'] ?? 'standard'),
            'orient'     => $this->reports_sanitize_pdf_orient($meta['pdf_orient'] ?? 'portrait'),
            'cols'       => (isset($meta['pdf_cols']) && is_array($meta['pdf_cols'])) ? $meta['pdf_cols'] : [],
            'header'     => (string)($meta['pdf_header'] ?? ''),
            'footer'     => (string)($meta['pdf_footer'] ?? ''),
            'mode'       => $this->reports_sanitize_pdf_mode($meta['pdf_mode'] ?? 'full_detail'),
            'breakdowns' => (isset($meta['pdf_breakdowns']) && is_array($meta['pdf_breakdowns'])) ? $meta['pdf_breakdowns'] : [],
            'logo_id'    => (int)($meta['pdf_logo_id'] ?? 0),
        ];
        $opts['breakdowns'] = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$opts['breakdowns']))));

        // Fetch detailed rows for the report table / breakdowns.
        // - Full detail: keep table readable (cap 2000)
        // - Summary+breakdown: allow more rows for better breakdown accuracy (cap 20000)
        $rows = [];
        $truncated = false;

        if ($start_dt !== '' && $end_dt !== '' && $opts['tpl'] !== 'summary') {
            $limit = ($opts['mode'] === 'summary_breakdown') ? 20000 : 2000;
            $rows = $this->fetch_report_detail_rows(
                $start_dt,
                $end_dt,
                $date_basis,
                $limit + 1,
                ($user_login !== '' && $user_login !== 'all' && $user_login !== '0') ? $user_login : null,
                $tx_type
            );
            if (count($rows) > $limit) {
                $truncated = true;
                $rows = array_slice($rows, 0, $limit);
            }
        }

        $built = $this->build_report_pdf_pages($title, $generated, $range_display, $tot, $rows, $truncated, $tx_type, $opts);

        $pages = is_array($built) && isset($built['pages']) ? (array)$built['pages'] : (array)$built;
        $meta_pdf = (is_array($built) && isset($built['meta']) && is_array($built['meta'])) ? $built['meta'] : [];

        $pdf = $this->simple_pdf_pages($pages, [
            'F1' => 'Helvetica',
            'F2' => 'Helvetica-Bold',
            'F3' => 'Helvetica-Oblique',
        ], $meta_pdf);

        $filename = sanitize_file_name(strtolower(str_replace([' ',':'], '_', $title))).'.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo $pdf;
        exit;
    }


    
private function fetch_report_detail_rows($start_dt, $end_dt, $date_basis, $limit = 2000, $user_login = null, $tx_type = 'all') {
    $db = $this->ds_db();
    if (!($db instanceof wpdb)) return [];

    $table = $this->ds_table();
    $date_expr = $this->date_basis_expr($date_basis);

    // Map columns (supports both legacy Indonesian + English schemas)
    $party_col   = $this->tx_col('nama_toko', $db, $table);
    $cat_col     = $this->tx_col('kategori', $db, $table);
    $price_col   = $this->tx_col('harga', $db, $table);
    $items_col   = $this->tx_col('items', $db, $table);
    $qty_col     = $this->tx_col('quantity', $db, $table);
    $entry_col    = $this->tx_col('tanggal_input', $db, $table);
    $receipt_col  = $this->tx_col('tanggal_struk', $db, $table);
    $purchase_col = $this->tx_col('purchase_date', $db, $table);
    $receive_col  = $this->tx_col('receive_date', $db, $table);

    // Keep receipt date available as a legacy fallback (older rows / older schemas).
    $receipt_expr  = ($receipt_col && $this->ds_column_exists($receipt_col, $db, $table)) ? "DATE(`{$receipt_col}`)" : "NULL";
    $purchase_expr = ($purchase_col && $this->ds_column_exists($purchase_col, $db, $table)) ? "`{$purchase_col}`" : "NULL";
    $receive_expr  = ($receive_col && $this->ds_column_exists($receive_col, $db, $table)) ? "`{$receive_col}`" : "NULL";
    $entry_expr    = ($entry_col && $this->ds_column_exists($entry_col, $db, $table)) ? "`{$entry_col}`" : "NULL";

    $user_col = $this->tx_user_col();
    $user_expr = ($user_col && $this->ds_column_exists($user_col, $db, $table)) ? "`{$user_col}`" : "NULL";

    $desc_col = $this->tx_desc_col($db, $table);
    $desc_expr = $desc_col ? "`{$desc_col}`" : "NULL";

    $tags_col = $this->tx_col('tags', $db, $table);
    $tags_expr = ($tags_col && $this->ds_column_exists($tags_col, $db, $table)) ? "`{$tags_col}`" : "NULL";

    // Canonical keys used by admin templates.
    $select = "line_id, transaction_id, {$user_expr} AS tx_user, `{$party_col}` AS nama_toko, `{$items_col}` AS items, `{$qty_col}` AS quantity, `{$price_col}` AS harga, `{$cat_col}` AS kategori, {$entry_expr} AS tanggal_input, {$receipt_expr} AS tanggal_struk, {$purchase_expr} AS purchase_date, {$receive_expr} AS receive_date, {$desc_expr} AS description, {$tags_expr} AS tags";

    $where = "{$date_expr} >= %s AND {$date_expr} < %s";
    $params = [$start_dt, $end_dt];

    // Optional user filter (kept flexible for both internal and external datasources)
    if ($user_login !== null && $user_login !== '' && $user_login !== 'all' && $user_login !== '0' && $user_col) {
        $u_login = strtolower(trim((string)$user_login));

        // Handle case where the dropdown submits a numeric WP user ID.
        // When our transactions table stores the username (not an ID),
        // translate the ID into a login so the filter works.
        if (ctype_digit($u_login) && !($user_col === 'wp_user_id' || (strlen($user_col) >= 3 && substr($user_col, -3) === '_id'))) {
            $u_by_id = get_user_by('id', (int)$u_login);
            if ($u_by_id && isset($u_by_id->user_login)) {
                $u_login = strtolower((string)$u_by_id->user_login);
            }
        }
        if ($user_col === 'wp_user_id' || (strlen($user_col) >= 3 && substr($user_col, -3) === '_id')) {
            $u = get_user_by('login', $u_login);
            $uid = $u ? (int)$u->ID : 0;
            if ($uid > 0) {
                $where = "`{$user_col}` = %d AND {$where}";
                $params = array_merge([$uid], $params);
            } else {
                $where = "1=0 AND {$where}";
            }
        } else {
            $where = "LOWER(`{$user_col}`) = %s AND {$where}";
            $params = array_merge([$u_login], $params);
        }
    }

    // Optional transaction type filter for reports (All / Income / Expense).
    $tx_type = $this->reports_sanitize_tx_type((string)$tx_type);
    if ($tx_type === 'income') {
        $where .= " AND TRIM(LOWER(`{$cat_col}`)) = 'income'";
    } elseif ($tx_type === 'expense') {
        // Treat "expense" as anything that is NOT income.
        // This matches calc_totals_between() and supports installs where `kategori`
        // stores detailed expense categories (e.g. food, transport, bills).
        $where .= " AND TRIM(LOWER(`{$cat_col}`)) <> 'income'";
    }

    $limit = max(1, (int)$limit);

    $sql = "SELECT {$select}
            FROM `{$table}`
            WHERE {$where}
            ORDER BY {$date_expr} DESC
            LIMIT {$limit}";

    $rows = $db->get_results($db->prepare($sql, ...$params), ARRAY_A);
    return is_array($rows) ? $rows : [];
}



private function build_report_pdf_pages($title, $generated, $range_display, $tot, $rows, $truncated, $tx_type = 'all', $opts = []) {
        // Vector-PDF pages with basic text + grid table. Supports multi-page with repeating headers.
        $opts = is_array($opts) ? $opts : [];
        $tpl = $this->reports_sanitize_pdf_tpl($opts['tpl'] ?? 'standard');
        $orient = $this->reports_sanitize_pdf_orient($opts['orient'] ?? 'portrait');
        $cols = (isset($opts['cols']) && is_array($opts['cols'])) ? array_values(array_unique(array_filter($opts['cols']))) : [];
        $header_note = trim((string)($opts['header'] ?? ''));
        $footer_note = trim((string)($opts['footer'] ?? ''));

        $mode = $this->reports_sanitize_pdf_mode($opts['mode'] ?? 'full_detail');
        $breakdowns_sel = (isset($opts['breakdowns']) && is_array($opts['breakdowns'])) ? $opts['breakdowns'] : [];
        $breakdowns_sel = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array)$breakdowns_sel))));
        if (empty($breakdowns_sel)) $breakdowns_sel = ['category'];

        $logo_id = (int)($opts['logo_id'] ?? 0);
        $logo_img = $this->reports_prepare_pdf_logo_image($logo_id);
        $images = [];
        if (is_array($logo_img) && !empty($logo_img['data'])) {
            $images[(string)$logo_img['name']] = ['data'=>$logo_img['data'], 'w'=>(int)$logo_img['w'], 'h'=>(int)$logo_img['h']];
        }

        // Page size (A4)
        if ($orient === 'landscape') {
            $page_w = 842; $page_h = 595;
            $top = 545; $bottom = 45;
        } else {
            $page_w = 595; $page_h = 842;
            $top = 780; $bottom = 60;
        }

        $mediabox = [0, 0, $page_w, $page_h];

        // Margins
        $left = 48; $right = 48;
        if ($tpl === 'compact') {
            $left = 40; $right = 40;
            if ($orient === 'portrait') { $top = 792; $bottom = 52; }
            else { $top = 555; $bottom = 45; }
        }

        $tx_type = $this->reports_sanitize_tx_type((string)$tx_type);

        // Prepare breakdown data from the same rows used for the PDF (keeps summary consistent if rows are truncated).
        $bd_data = $this->reports_compute_breakdowns($rows, $tx_type, $breakdowns_sel);

        // Logo box (points)
        $logo_box_w = 0.0; $logo_box_h = 0.0;
        if (!empty($images['ImLogo']) && !empty($images['ImLogo']['w']) && !empty($images['ImLogo']['h'])) {
            $logo_box_w = 54.0;
            $ratio = ((float)$images['ImLogo']['h']) / max(1.0, (float)$images['ImLogo']['w']);
            $logo_box_h = min(36.0, max(18.0, $logo_box_w * $ratio));
        }


        // Default column set (used by Standard/Compact; Custom uses user-selected)
        $default_cols = ['date','party','category','item','qty','price'];
        if ($tpl !== 'custom') {
            $cols = $default_cols;
        } else {
            if (empty($cols)) $cols = $default_cols;
        }

        // Labels per column
        $col_labels = [
            'date' => ($tx_type === 'income') ? 'Receive Date' : (($tx_type === 'expense') ? 'Purchase Date' : 'Date'),
            'party' => ($tx_type === 'income') ? 'Source' : (($tx_type === 'expense') ? 'Payee' : 'Payee/Source'),
            'category' => 'Category',
            'item' => 'Item',
            'qty' => 'Qty',
            'price' => 'Price',
            'tags' => 'Tags',
            'description' => 'Description',
            'txid' => 'Tx ID',
        ];

        // If summary-only, render a minimal single page without a table.
        $income  = 'Rp ' . number_format_i18n((float)($tot['income'] ?? 0));
        $expense = 'Rp ' . number_format_i18n((float)($tot['expense'] ?? 0));

        if ($tpl === 'summary') {
            $s = "";
            $y = $top;

            $title_x = $left;
            if ($logo_box_w > 0) {
                $logo_y = $y - $logo_box_h + 6;
                $s .= $this->pdf_image_cmd('ImLogo', $left, $logo_y, $logo_box_w, $logo_box_h);
                $title_x = $left + $logo_box_w + 10;
            }

            $s .= $this->pdf_text_cmd($title_x, $y, 18, $title, 'F2');
$y -= 20;

            $content_left = ($logo_box_w > 0) ? $title_x : $left;

            if ($header_note !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 10, $this->pdf_truncate_text($header_note, 86), 'F3');
                $y -= 14;
            }

            if ($range_display !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 12, "Date: {$range_display}", 'F1');
                $y -= 14;
            }

            $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
            $y -= 18;

            $s .= $this->pdf_text_cmd($left, $y, 12, "Summary:", 'F2');
            $y -= 16;

            if ($tx_type === 'income') {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                $y -= 14;
            } elseif ($tx_type === 'expense') {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                $y -= 14;
            } else {
                $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                $y -= 14;
                $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                $y -= 14;
            }

            if ($truncated) {
                $y -= 4;
                $s .= $this->pdf_text_cmd($left, $y, 10, "Note: detail rows were truncated (filtered).", 'F3');
                $y -= 14;
            }

            if ($footer_note !== '') {
                $fy = 32;
                $lines = $this->pdf_wrap_text($footer_note, 90);
                $li = 0;
                foreach ($lines as $ln) {
                    $s .= $this->pdf_text_cmd($left, $fy + (12 * ($li)), 9, $ln, 'F3');
                    $li++;
                    if ($li >= 2) break;
                }
            }

            $footer_label = "Page 1";
            $approx_char_w = 4.6;
            $fx = (int)round(($page_w / 2) - (($this->pdf_strlen($footer_label) * $approx_char_w) / 2));
            $s .= $this->pdf_text_cmd($fx, 32, 9, $footer_label, 'F3');

            $gen_label = "Generated: {$generated}";
            $approx_char_wg = 4.2;
            $gx = (int)round(($page_w - $right) - (($this->pdf_strlen($gen_label) * $approx_char_wg)));
            if ($gx < ($left + 120)) $gx = $left + 120;
            $s .= $this->pdf_text_cmd_gray($gx, 32, 9, $gen_label, 'F3', 0.35);

            return ['pages'=>[$s], 'meta'=>['mediaboxes'=>[$mediabox], 'images'=>$images]];
        }


        // Summary + breakdown (no detail table)
        if ($mode === 'summary_breakdown') {
            $s = "";
            $y = $top;

            $title_x = $left;
            if ($logo_box_w > 0) {
                $logo_y = $y - $logo_box_h + 6;
                $s .= $this->pdf_image_cmd('ImLogo', $left, $logo_y, $logo_box_w, $logo_box_h);
                $title_x = $left + $logo_box_w + 10;
            }

            $s .= $this->pdf_text_cmd($title_x, $y, 18, $title, 'F2');
$y -= 20;

            $content_left = ($logo_box_w > 0) ? $title_x : $left;

            if ($header_note !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 10, $this->pdf_truncate_text($header_note, 86), 'F3');
                $y -= 14;
            }

            if ($range_display !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 12, "Date: {$range_display}", 'F1');
                $y -= 14;
            }

            $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
            $y -= 18;

                        $show_summary = !in_array('category', $breakdowns_sel, true);
                        if ($show_summary) {
            $s .= $this->pdf_text_cmd($left, $y, 12, "Summary:", 'F2');
                        $y -= 16;

                        if ($tx_type === 'income') {
                            $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                            $y -= 14;
                        } elseif ($tx_type === 'expense') {
                            $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                            $y -= 14;
                        } else {
                            $s .= $this->pdf_text_cmd($left, $y, 12, "Income: {$income}", 'F1');
                            $y -= 14;
                            $s .= $this->pdf_text_cmd($left, $y, 12, "Expense: {$expense}", 'F1');
                            $y -= 14;
                        }

            
                        }if ($truncated) {
                $y -= 4;
                $s .= $this->pdf_text_cmd($left, $y, 10, "Note: showing first rows only (truncated).", 'F3');
                $y -= 14;
            }

            // Breakdown sections (limited to keep it readable)
            $bd_titles = [
                'category' => 'By category',
                'tags' => 'By tags',
                'counterparty' => 'By counterparty',
                'date' => 'By date',
            ];
            $max_items_base = ($orient === 'landscape') ? 6 : 10;
            foreach ($breakdowns_sel as $bdk) {
                $arr = $bd_data[$bdk] ?? [];
                if (empty($arr)) continue;

                $s .= $this->pdf_text_cmd($left, $y, 11, "Breakdown: " . ($bd_titles[$bdk] ?? $bdk), 'F2');
                $y -= 14;

                $i = 0;
                foreach ($arr as $lbl => $amt) {
                    if ($i >= $max_items_base) {
                        $more = max(0, count($arr) - $max_items_base);
                        if ($more > 0) {
                            $s .= $this->pdf_text_cmd($left + 10, $y, 9, "… {$more} more", 'F3');
                            $y -= 12;
                        }
                        break;
                    }
                    $line = $this->pdf_truncate_text((string)$lbl, 40);
                    $s .= $this->pdf_text_cmd($left + 10, $y, 10, $line . ': Rp ' . number_format_i18n((float)$amt), 'F1');
                    $y -= 12;
                    $i++;
                    if ($y < ($bottom + 90)) break;
                }
                $y -= 6;
                if ($y < ($bottom + 90)) break;
            }

            // Footer: optional note + page number
            if ($footer_note !== '') {
                $fy = 32;
                $lines = $this->pdf_wrap_text($footer_note, 90);
                $li = 0;
                foreach ($lines as $ln) {
                    $s .= $this->pdf_text_cmd($left, $fy + (12 * ($li)), 9, $ln, 'F3');
                    $li++;
                    if ($li >= 2) break;
                }
            }

            $footer_label = "Page 1";
            $approx_char_w = 4.6;
            $fx = (int)round(($page_w / 2) - (($this->pdf_strlen($footer_label) * $approx_char_w) / 2));
            $s .= $this->pdf_text_cmd($fx, 32, 9, $footer_label, 'F3');

            $gen_label = "Generated: {$generated}";
            $approx_char_wg = 4.2;
            $gx = (int)round(($page_w - $right) - (($this->pdf_strlen($gen_label) * $approx_char_wg)));
            if ($gx < ($left + 120)) $gx = $left + 120;
            $s .= $this->pdf_text_cmd_gray($gx, 32, 9, $gen_label, 'F3', 0.35);

            return ['pages'=>[$s], 'meta'=>['mediaboxes'=>[$mediabox], 'images'=>$images]];
        }

        // Build associative rows for flexible column selection
        $print_rows_assoc = [];
        foreach ((array)$rows as $r) {
            $receipt_raw  = (string)($r['tanggal_struk'] ?? $r['tanggal_receipt'] ?? $r['receipt_date'] ?? '');
            $purchase_raw = (string)($r['purchase_date'] ?? $r['purchaseDate'] ?? '');
            $receive_raw  = (string)($r['receive_date'] ?? $r['receiveDate'] ?? '');
            $entry_raw    = (string)($r['tanggal_input'] ?? $r['entry_date'] ?? $r['entryDate'] ?? '');

            $cat      = (string)($r['kategori'] ?? $r['category'] ?? '');
            $cat_norm = strtolower(trim($cat));
            $is_income = ($cat_norm === 'income');

            $date_raw = $entry_raw;
            if ($tx_type === 'income' || ($tx_type === 'all' && $is_income)) {
                $date_raw = $receive_raw ?: $receipt_raw ?: $entry_raw;
            } elseif ($tx_type === 'expense' || ($tx_type === 'all' && !$is_income)) {
                $date_raw = $purchase_raw ?: $receipt_raw ?: $entry_raw;
            }

            $date_disp = $this->fmt_date_short($date_raw);
            if ($date_disp === '' && $entry_raw !== '') $date_disp = $this->fmt_date_short($entry_raw);

            $merchant = (string)($r['nama_toko'] ?? $r['merchant'] ?? '');
            $item     = (string)($r['items'] ?? $r['item'] ?? '');
            $qty      = (string)($r['quantity'] ?? $r['qty'] ?? '');
            $price    = (float)($r['harga'] ?? $r['price'] ?? 0);
            $tags     = (string)($r['tags'] ?? '');
            $desc     = (string)($r['description'] ?? '');
            $txid     = (string)($r['transaction_id'] ?? '');

            $print_rows_assoc[] = [
                'date' => $date_disp ?: '',
                'party' => $merchant ?: '',
                'category' => $cat ?: '',
                'item' => $item ?: '',
                'qty' => $qty ?: '',
                'price' => 'Rp ' . number_format_i18n($price),
                'tags' => $tags ?: '',
                'description' => $desc ?: '',
                'txid' => $txid ?: '',
            ];
        }

        if (empty($print_rows_assoc)) {
            for ($i=0;$i<5;$i++) $print_rows_assoc[] = ['date'=>'','party'=>'','category'=>'','item'=>'','qty'=>'','price'=>'','tags'=>'','description'=>'','txid'=>''];
        }

        // Convert to ordered rows per selected columns
        $headers = [];
        foreach ($cols as $ck) { $headers[] = $col_labels[$ck] ?? $ck; }

        $print_rows = [];
        foreach ($print_rows_assoc as $r) {
            $row = [];
            foreach ($cols as $ck) { $row[] = (string)($r[$ck] ?? ''); }
            $print_rows[] = $row;
        }

        // Column widths (base) and wrap limits
        $base_w = [
            'date' => 80,
            'party' => 110,
            'category' => 85,
            'item' => 150,
            'qty' => 35,
            'price' => 62,
            'tags' => 90,
            'description' => 160,
            'txid' => 70,
        ];
        if ($orient === 'landscape') {
            // wider page: give more space to text-heavy cols
            $base_w['item'] = 190;
            $base_w['description'] = 220;
            $base_w['party'] = 140;
        }

        $col_w = [];
        foreach ($cols as $ck) {
            $col_w[] = (int)($base_w[$ck] ?? 90);
        }

        $max_table_w = $page_w - $left - $right;
        $table_w = array_sum($col_w);
        if ($table_w > $max_table_w) {
            $scale = $max_table_w / max(1, $table_w);
            foreach ($col_w as &$cw) { $cw = max(28, (int)floor($cw * $scale)); }
            unset($cw);
        } else {
            // Keep a bit of breathing room on wide pages by not stretching too much.
            // If table is too narrow (< 70%), scale up slightly.
            if ($table_w < ($max_table_w * 0.70)) {
                $scale = ($max_table_w * 0.76) / max(1, $table_w);
                foreach ($col_w as &$cw) { $cw = (int)floor($cw * $scale); }
                unset($cw);
            }
        }

        $wrap_base = [
            'date' => 16,
            'party' => 22,
            'category' => 16,
            'item' => 32,
            'qty' => 6,
            'price' => 14,
            'tags' => 18,
            'description' => 38,
            'txid' => 18,
        ];
        $wrap_max = [];
        foreach ($cols as $ck) { $wrap_max[] = (int)($wrap_base[$ck] ?? 20); }

        $font_size = ($tpl === 'compact') ? 8 : 9;
        $line_h = ($tpl === 'compact') ? 10 : 11;
        $header_h = ($tpl === 'compact') ? 22 : 24;
        $row_base_h = ($tpl === 'compact') ? 20 : 22;

        $pages = [];
        $idx = 0;
        $n = count($print_rows);
        $page_no = 1;

        while ($idx < $n) {
            $s = "";

            // Header block
            $y = $top;
            $title_x = $left;
            if ($logo_box_w > 0) {
                $logo_y = $y - $logo_box_h + 6;
                $s .= $this->pdf_image_cmd('ImLogo', $left, $logo_y, $logo_box_w, $logo_box_h);
                $title_x = $left + $logo_box_w + 10;
            }

            $s .= $this->pdf_text_cmd($title_x, $y, 18, $title, 'F2');
$y -= 20;

            $content_left = ($logo_box_w > 0) ? $title_x : $left;

            if ($header_note !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 10, $this->pdf_truncate_text($header_note, 86), 'F3');
                $y -= 14;
            }

            if ($range_display !== '') {
                $s .= $this->pdf_text_cmd($content_left, $y, 12, "Date: {$range_display}", 'F1');
                $y -= 14;
            }

            $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
            $y -= 18;

            // Summary + breakdown (first page only). Page 2+ shows only the detail table.
            if ($page_no === 1) {
                $sum_fs = ($tpl === 'compact') ? 10 : 12;
                $sum_ls = ($tpl === 'compact') ? 12 : 14;

                                $show_summary = !in_array('category', $breakdowns_sel, true);
                                if ($show_summary) {
                $s .= $this->pdf_text_cmd($left, $y, $sum_fs, "Summary:", 'F2');
                                $y -= $sum_ls;

                                if ($tx_type === 'income') {
                                    $s .= $this->pdf_text_cmd($left, $y, $sum_fs, "Income: {$income}", 'F1');
                                    $y -= ($sum_ls - 2);
                                } elseif ($tx_type === 'expense') {
                                    $s .= $this->pdf_text_cmd($left, $y, $sum_fs, "Expense: {$expense}", 'F1');
                                    $y -= ($sum_ls - 2);
                                } else {
                                    $s .= $this->pdf_text_cmd($left, $y, $sum_fs, "Income: {$income}", 'F1');
                                    $y -= ($sum_ls - 2);
                                    $s .= $this->pdf_text_cmd($left, $y, $sum_fs, "Expense: {$expense}", 'F1');
                                    $y -= ($sum_ls - 2);
                                }

                
                                }if ($truncated && $tpl === 'compact') {
                    $y -= 2;
                    $s .= $this->pdf_text_cmd($left, $y, 9, "Note: showing first rows only (truncated).", 'F3');
                    $y -= 12;
                }

                // Breakdown sections (limited to keep table readable)
                if (!empty($breakdowns_sel)) {
                    $bd_titles = [
                        'category' => 'By category',
                        'tags' => 'By tags',
                        'counterparty' => 'By counterparty',
                        'date' => 'By date',
                    ];
                    $max_items = ($orient === 'landscape') ? 5 : (($tpl === 'compact') ? 6 : 8);

                    foreach ($breakdowns_sel as $bdk) {
                        $arr = $bd_data[$bdk] ?? [];
                        if (empty($arr)) continue;

                        $s .= $this->pdf_text_cmd($left, $y, 10, "Breakdown: " . ($bd_titles[$bdk] ?? $bdk), 'F2');
                        $y -= 12;

                        $i = 0;
                        foreach ($arr as $lbl => $amt) {
                            if ($i >= $max_items) break;
                            $line = $this->pdf_truncate_text((string)$lbl, 36);
                            $s .= $this->pdf_text_cmd($left + 10, $y, 9, $line . ': Rp ' . number_format_i18n((float)$amt), 'F1');
                            $y -= 11;
                            $i++;
                            if ($y < ($bottom + 160)) break;
                        }

                        $y -= 6;
                        if ($y < ($bottom + 160)) break;
                    }

                    $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
                    $y -= 18;
                } else {
                    $s .= $this->pdf_line_cmd($left, $y, $page_w - $right, $y, 0.8);
                    $y -= 18;
                }
            }

            $label = ($page_no === 1) ? "Detail transactions:" : "Detail transactions (continued):";
            $s .= $this->pdf_text_cmd($left, $y, 12, $label, 'F2');
            $y -= 12;

            if ($truncated && $idx === 0) {
                $s .= $this->pdf_text_cmd($left, $y, 10, "Note: showing first 2000 rows (filtered).", 'F3');
                $y -= 12;
            }

            // Table region
            $table_x = $left;
            $table_top = $y;
            $table_bottom = $bottom;
            $avail_h = $table_top - $table_bottom;

            // Decide how many rows fit on this page (variable row height due to wrapping)
            $row_heights = [$header_h];
            $page_row_wrapped = [];

            $used_h = $header_h;
            while ($idx < $n) {
                $row = $print_rows[$idx];

                $wrapped_cells = [];
                $max_lines = 1;
                foreach ($row as $cidx => $cell) {
                    $cell = (string)$cell;
                    $cell = $this->pdf_clean_text($cell);
                    $lines = $this->pdf_wrap_text($cell, (int)($wrap_max[$cidx] ?? 20));
                    $wrapped_cells[] = $lines;
                    $max_lines = max($max_lines, count($lines));
                }

                $rh = max($row_base_h, $row_base_h + (($max_lines - 1) * $line_h));
                if (($used_h + $rh) > $avail_h) break;

                $row_heights[] = $rh;
                $page_row_wrapped[] = $wrapped_cells;
                $used_h += $rh;
                $idx++;
            }

            if (empty($page_row_wrapped)) {
                $wrapped_cells = [];
                foreach ($print_rows[$idx] as $cidx => $cell) {
                    $cell = $this->pdf_clean_text((string)$cell);
                    $wrapped_cells[] = $this->pdf_wrap_text($cell, (int)($wrap_max[$cidx] ?? 20));
                }
                $row_heights[] = $row_base_h;
                $page_row_wrapped[] = $wrapped_cells;
                $idx++;
            }

            // Draw grid
            $s .= $this->pdf_table_grid_var_cmd($table_x, $table_top, $col_w, $row_heights, 0.8);

            // Header row text
            $x = $table_x;
            $header_y = $table_top - 16;
            foreach ($headers as $cidx => $h) {
                $s .= $this->pdf_text_cmd($x + 4, $header_y, $font_size, $h, 'F2');
                $x += $col_w[$cidx];
            }

            // Data rows
            $y_cursor_top = $table_top - $header_h;
            foreach ($page_row_wrapped as $ridx => $wrapped_cells) {
                $rh = $row_heights[$ridx + 1];
                $row_top = $y_cursor_top;
                $first_baseline = $row_top - 14;

                $x = $table_x;
                foreach ($wrapped_cells as $cidx => $lines) {
                    $li = 0;
                    foreach ($lines as $line) {
                        $s .= $this->pdf_text_cmd($x + 4, $first_baseline - ($li * $line_h), $font_size, $line, 'F1');
                        $li++;
                        if (($li * $line_h) > ($rh - 12)) break;
                    }
                    $x += $col_w[$cidx];
                }

                $y_cursor_top -= $rh;
            }

            // Footer: optional note (left) + page number (center)
            if ($footer_note !== '') {
                $lines = $this->pdf_wrap_text($footer_note, 92);
                $li = 0;
                foreach ($lines as $ln) {
                    $s .= $this->pdf_text_cmd($left, 32 + (12 * $li), 9, $ln, 'F3');
                    $li++;
                    if ($li >= 2) break;
                }
            }

            $footer_label = "Page {$page_no}";
            $approx_char_w = 4.6;
            $fx = (int)round(($page_w / 2) - (($this->pdf_strlen($footer_label) * $approx_char_w) / 2));
            $s .= $this->pdf_text_cmd($fx, 32, 9, $footer_label, 'F3');

            $gen_label = "Generated: {$generated}";
            $approx_char_wg = 4.2;
            $gx = (int)round(($page_w - $right) - (($this->pdf_strlen($gen_label) * $approx_char_wg)));
            if ($gx < ($left + 120)) $gx = $left + 120;
            $s .= $this->pdf_text_cmd_gray($gx, 32, 9, $gen_label, 'F3', 0.35);

            $pages[] = $s;
            $page_no++;
        }

        return ['pages'=>$pages, 'meta'=>['mediaboxes'=>array_fill(0, count($pages), $mediabox), 'images'=>$images]];
    }


public function handle_export_report_pdf() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        check_admin_referer('simku_export_report_pdf');

        $tab_raw = isset($_REQUEST['report_tab']) ? wp_unslash($_REQUEST['report_tab']) : (isset($_REQUEST['tab']) ? wp_unslash($_REQUEST['tab']) : 'daily');
        $tab = sanitize_text_field($tab_raw);
        $tab = in_array($tab, ['daily','weekly','monthly'], true) ? $tab : 'daily';

        // For now reports use Entry date as basis (same as existing totals). Can be extended later.
        $date_basis = 'input';

        // User filter (default: all users). Non-admins are restricted to their own login.
        $user_login_raw = isset($_REQUEST['report_user']) ? wp_unslash($_REQUEST['report_user']) : (isset($_REQUEST['user']) ? wp_unslash($_REQUEST['user']) : 'all');
        $user_login = sanitize_text_field($user_login_raw);
        if ($user_login === '' || $user_login === '0') $user_login = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $user_login = $cu->user_login;
        }

        $tx_type_raw = isset($_REQUEST['report_tx_type']) ? wp_unslash($_REQUEST['report_tx_type']) : (isset($_REQUEST['type']) ? wp_unslash($_REQUEST['type']) : 'all');
        $tx_type = $this->reports_sanitize_tx_type(sanitize_text_field($tx_type_raw));

        // PDF layout options
        $pdf_tpl = $this->reports_sanitize_pdf_tpl(isset($_REQUEST['pdf_tpl']) ? wp_unslash($_REQUEST['pdf_tpl']) : 'standard');
        $pdf_orient = $this->reports_sanitize_pdf_orient(isset($_REQUEST['pdf_orient']) ? wp_unslash($_REQUEST['pdf_orient']) : 'portrait');
        $pdf_cols = $this->reports_pdf_cols_from_request();
        $pdf_header = $this->reports_pdf_text_from_request('pdf_header');
        $pdf_footer = $this->reports_pdf_text_from_request('pdf_footer');
        $pdf_mode = $this->reports_sanitize_pdf_mode(isset($_REQUEST['pdf_mode']) ? wp_unslash($_REQUEST['pdf_mode']) : 'full_detail');
        $pdf_breakdowns = $this->reports_pdf_breakdowns_from_request();
        $pdf_logo_id = $this->reports_pdf_logo_id_from_request();
        if ($tab === 'daily') {
            $date_raw = isset($_REQUEST['report_date']) ? wp_unslash($_REQUEST['report_date']) : (isset($_REQUEST['date']) ? wp_unslash($_REQUEST['date']) : wp_date('Y-m-d'));
            $date = sanitize_text_field($date_raw);
            $start = $date . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
            $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

            $report_title = ($tx_type === 'income') ? "Daily income report: {$date}" : (($tx_type === 'expense') ? "Daily expense report: {$date}" : "Daily report: {$date}");

            $this->export_pdf_report($report_title, $tot, [
                'report_type'   => 'daily',
                'range_display' => $date,
                'start_dt'      => $start,
                'end_dt'        => $end,
                'date_basis'    => $date_basis,
                'user_login'    => $user_login,
                'tx_type'      => $tx_type,
                'pdf_tpl'     => $pdf_tpl,
                'pdf_orient'  => $pdf_orient,
                'pdf_cols'    => $pdf_cols,
                'pdf_header'  => $pdf_header,
                'pdf_footer'  => $pdf_footer,
                'pdf_mode'    => $pdf_mode,
                'pdf_breakdowns' => $pdf_breakdowns,
                'pdf_logo_id' => $pdf_logo_id,
            ]);
            return;
        }

        if ($tab === 'weekly') {
            $from_raw = isset($_REQUEST['report_from']) ? wp_unslash($_REQUEST['report_from']) : (isset($_REQUEST['from']) ? wp_unslash($_REQUEST['from']) : wp_date('Y-m-d', strtotime('monday this week')));
            $from = sanitize_text_field($from_raw);
            $to_raw = isset($_REQUEST['report_to']) ? wp_unslash($_REQUEST['report_to']) : (isset($_REQUEST['to']) ? wp_unslash($_REQUEST['to']) : wp_date('Y-m-d', strtotime('sunday this week')));
            $to = sanitize_text_field($to_raw);
            $start = $from . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
            $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

            $from_disp = wp_date('d/m/Y', strtotime($from));
            $to_disp   = wp_date('d/m/Y', strtotime($to));

            $report_title = ($tx_type === 'income') ? "Weekly income report" : (($tx_type === 'expense') ? "Weekly expense report" : "Weekly report");

            $this->export_pdf_report($report_title, $tot, [
                'report_type'   => 'weekly',
                'range_display' => "{$from_disp} - {$to_disp}",
                'start_dt'      => $start,
                'end_dt'        => $end,
                'date_basis'    => $date_basis,
                'user_login'    => $user_login,
                'tx_type'      => $tx_type,
                'pdf_tpl'     => $pdf_tpl,
                'pdf_orient'  => $pdf_orient,
                'pdf_cols'    => $pdf_cols,
                'pdf_header'  => $pdf_header,
                'pdf_footer'  => $pdf_footer,
                'pdf_mode'    => $pdf_mode,
                'pdf_breakdowns' => $pdf_breakdowns,
                'pdf_logo_id' => $pdf_logo_id,
            ]);
            return;
        }

        // monthly
        $month_raw = isset($_REQUEST['report_month']) ? wp_unslash($_REQUEST['report_month']) : (isset($_REQUEST['month']) ? wp_unslash($_REQUEST['month']) : wp_date('Y-m'));
        $month = sanitize_text_field($month_raw);
        $start = $month . '-01 00:00:00';
        $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        $tot = $this->calc_totals_between($start, $end, $date_basis, $user_login);

        $report_title = ($tx_type === 'income') ? "Monthly income report" : (($tx_type === 'expense') ? "Monthly expense report" : "Monthly report");

        $this->export_pdf_report($report_title, $tot, [
            'report_type'   => 'monthly',
            'range_display' => $month,
            'start_dt'      => $start,
            'end_dt'        => $end,
            'date_basis'    => $date_basis,
            'user_login'    => $user_login,
            'tx_type'       => $tx_type,
            'pdf_tpl'       => $pdf_tpl,
            'pdf_orient'    => $pdf_orient,
            'pdf_cols'      => $pdf_cols,
            'pdf_header'    => $pdf_header,
            'pdf_footer'    => $pdf_footer,
            'pdf_mode'      => $pdf_mode,
            'pdf_breakdowns'=> $pdf_breakdowns,
            'pdf_logo_id'   => $pdf_logo_id,
        ]);
}


    public function handle_export_report_csv() {
        if (!current_user_can(self::CAP_VIEW_REPORTS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));
        check_admin_referer('simku_export_report_csv');

        $tab = isset($_REQUEST['report_tab']) ? sanitize_text_field(wp_unslash($_REQUEST['report_tab'])) : 'daily';
        $tab = in_array($tab, ['daily','weekly','monthly'], true) ? $tab : 'daily';

        // Reports currently use Entry Date (tanggal_input) as the basis (same as PDF export).
        $date_basis = 'input';

        // User filter (default: all users). Non-admins are restricted to their own login.
        $user_login = isset($_REQUEST['report_user']) ? sanitize_text_field(wp_unslash($_REQUEST['report_user'])) : 'all';
        if ($user_login === '' || $user_login === '0') $user_login = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $user_login = $cu->user_login;
        }

        $tx_type = isset($_REQUEST['report_tx_type']) ? $this->reports_sanitize_tx_type(sanitize_text_field(wp_unslash($_REQUEST['report_tx_type']))) : 'all';

        $start = ''; $end = ''; $range_display = '';
        if ($tab === 'daily') {
            $date_raw = isset($_REQUEST['report_date']) ? wp_unslash($_REQUEST['report_date']) : (isset($_REQUEST['date']) ? wp_unslash($_REQUEST['date']) : wp_date('Y-m-d'));
            $date = sanitize_text_field($date_raw);
            $start = $date . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
            $range_display = $date;
        } elseif ($tab === 'weekly') {
            $from_raw = isset($_REQUEST['report_from']) ? wp_unslash($_REQUEST['report_from']) : (isset($_REQUEST['from']) ? wp_unslash($_REQUEST['from']) : wp_date('Y-m-d', strtotime('monday this week')));
            $from = sanitize_text_field($from_raw);
            $to_raw = isset($_REQUEST['report_to']) ? wp_unslash($_REQUEST['report_to']) : (isset($_REQUEST['to']) ? wp_unslash($_REQUEST['to']) : wp_date('Y-m-d', strtotime('sunday this week')));
            $to = sanitize_text_field($to_raw);
            $start = $from . ' 00:00:00';
            $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
            $range_display = "{$from} - {$to}";
        } else { // monthly
            $month_raw = isset($_REQUEST['report_month']) ? wp_unslash($_REQUEST['report_month']) : (isset($_REQUEST['month']) ? wp_unslash($_REQUEST['month']) : wp_date('Y-m'));
        $month = sanitize_text_field($month_raw);
            $start = $month . '-01 00:00:00';
            $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
            $range_display = $month;
        }

        $limit = 200000; // safety cap for large exports
        $rows = $this->fetch_report_detail_rows(
            $start,
            $end,
            $date_basis,
            $limit,
            ($user_login !== '' && $user_login !== 'all' && $user_login !== '0') ? $user_login : null,
            $tx_type
        );

        $title = ($tx_type === 'income') ? "Report income {$tab} {$range_display}"
              : (($tx_type === 'expense') ? "Report expense {$tab} {$range_display}" : "Report {$tab} {$range_display}");

        $filename = sanitize_file_name(strtolower(str_replace([' ',':','/'], '_', $title))).'.csv';

        // Clean any accidental output before headers.
        if (function_exists('ob_get_length') && ob_get_length()) { @ob_end_clean(); }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');

        $out = fopen('php://output', 'w');

        // CSV headers (Payee + Source are always present for easier Excel analysis).
        fputcsv($out, [
            'Entry Date',
            'Purchase Date',
            'Receive Date',
            'Payee',
            'Source',
            'Category',
            'Item',
            'Qty',
            'Price',
            'Total',
            'Description',
            'Transaction ID',
            'Line ID',
            'User',
        ]);

        foreach ((array)$rows as $r) {
            $cat_raw = strtolower(trim((string)($r['kategori'] ?? '')));
            $is_income = ($cat_raw === 'income');

            $counterparty = (string)($r['nama_toko'] ?? '');
            $payee = $is_income ? 'N/A' : $counterparty;
            $source = $is_income ? $counterparty : 'N/A';

            $struk = trim((string)($r['tanggal_struk'] ?? ''));
            $purchase_val = trim((string)($r['purchase_date'] ?? ''));
            $receive_val  = trim((string)($r['receive_date'] ?? ''));

            $purchase_date = '';
            $receive_date  = '';
            if ($is_income) {
                $v = ($receive_val !== '' && $receive_val !== '0000-00-00') ? $receive_val : $struk;
                if ($v !== '' && $v !== '0000-00-00') $receive_date = $v;
            } else {
                $v = ($purchase_val !== '' && $purchase_val !== '0000-00-00') ? $purchase_val : $struk;
                if ($v !== '' && $v !== '0000-00-00') $purchase_date = $v;
            }

            $entry_date = (string)($r['tanggal_input'] ?? $r['entry_date'] ?? '');
            $item = (string)($r['items'] ?? '');
            $qty = (string)($r['quantity'] ?? '');
            $price = (string)($r['harga'] ?? '');
            $total = '';
            if (is_numeric($qty) && is_numeric($price)) {
                $total = (string)((float)$qty * (float)$price);
            }

            $desc = (string)($r['description'] ?? '');
            $tx_id = (string)($r['transaction_id'] ?? '');
            $line_id = (string)($r['line_id'] ?? '');
            $user_val = (string)($r['tx_user'] ?? '');

            fputcsv($out, [
                $entry_date,
                $purchase_date !== '' ? $purchase_date : 'N/A',
                $receive_date !== '' ? $receive_date : 'N/A',
                $payee !== '' ? $payee : 'N/A',
                $source !== '' ? $source : 'N/A',
                (string)($r['kategori'] ?? ''),
                $item,
                $qty,
                $price,
                $total,
                $desc,
                $tx_id,
                $line_id,
                $user_val,
            ]);
        }

        fclose($out);
        exit;
    }

     /**
      * Read the reports user filter from GET/POST.
      * - Admins can choose "All users" or any user_login.
      * - Non-admins are restricted to their own user_login.
     */
    private function reports_get_user_filter($src, $key = 'user') {
        $u = isset($src[$key]) ? sanitize_text_field(wp_unslash($src[$key])) : 'all';
        if ($u === '' || $u === '0') $u = 'all';
        if (!current_user_can('manage_options')) {
            $cu = wp_get_current_user();
            if ($cu && !empty($cu->user_login)) $u = $cu->user_login;
        }
        return $u;
    }


    private function reports_render_user_dropdown($selected) {
        // Non-admins are restricted to their own user only.
        $args = [
            'name' => 'user',
            'value_field' => 'user_login',
            'echo' => 0,
        ];

        if (current_user_can('manage_options')) {
            $args['show_option_all'] = 'All users';
            // wp_dropdown_users uses value "0" for the "all" option.
            $args['selected'] = ($selected === 'all') ? '0' : $selected;
        } else {
            $args['include'] = [get_current_user_id()];
            $args['selected'] = $selected;
        }

        $html = wp_dropdown_users($args);

        // Ensure predictable id/class for styling.
        if (strpos($html, 'id=') === false) {
            $html = str_replace("name='user'", "name='user' id='simku_reports_user'", $html);
            $html = str_replace('name="user"', 'name="user" id="simku_reports_user"', $html);
        }

        if (strpos($html, 'class=') === false) {
            $html = preg_replace('/<select([^>]*)>/', '<select$1 class="simku-input">', $html, 1);
        } else {
            $html = preg_replace('/<select([^>]*)class="([^"]*)"([^>]*)>/', '<select$1class="$2 simku-input"$3>', $html, 1);
        }

        echo '<div class="simku-filter-field simku-filter-user">';
        echo '<label for="simku_reports_user">User</label>';
        echo $html;
        echo '</div>';
    }


    /**
     * Reports "Category" filter (Income / Expense / All).
     * We keep the request parameter name as `type` for backward-compatibility.
     */
    private function reports_sanitize_tx_type($raw) {
        $v = strtolower(trim((string)$raw));
        if ($v === '' || $v === '0' || $v === 'all' || $v === 'all_categories' || $v === 'all categories') return 'all';
        if ($v === 'income' || $v === 'in') return 'income';
        if ($v === 'expense' || $v === 'expenses' || $v === 'exp') return 'expense';
        return 'all';
    }


    /**
     * Transactions date filter selector: Entry / Purchase / Receive.
     */
    private function reports_sanitize_date_field($raw) {
        $v = strtolower(trim((string)$raw));
        if ($v === 'purchase' || $v === 'receive' || $v === 'entry') return $v;
        return 'entry';
    }


    private function reports_render_type_dropdown($selected) {
        $selected = $this->reports_sanitize_tx_type($selected);

        echo '<div class="simku-filter-field simku-filter-type">';
        echo '<label for="simku_report_type">Category</label>';
        echo '<select id="simku_report_type" name="type" class="simku-input">';
        echo '<option value="all"'.selected($selected, 'all', false).'>All categories</option>';
        echo '<option value="income"'.selected($selected, 'income', false).'>Income</option>';
        echo '<option value="expense"'.selected($selected, 'expense', false).'>Expense</option>';
        echo '</select>';
        echo '</div>';
    }



    private function report_daily() {
        $date = isset($_GET['date']) ? sanitize_text_field(wp_unslash($_GET['date'])) : wp_date('Y-m-d');
        $user_login = $this->reports_get_user_filter($_GET, 'user');
        $tx_type_raw = isset($_GET['type']) ? wp_unslash($_GET['type']) : (isset($_GET['kategori']) ? wp_unslash($_GET['kategori']) : 'all');
        $tx_type = $this->reports_sanitize_tx_type(sanitize_text_field($tx_type_raw));

        // PDF layout options
        $pdf_tpl = $this->reports_sanitize_pdf_tpl(isset($_REQUEST['pdf_tpl']) ? wp_unslash($_REQUEST['pdf_tpl']) : 'standard');
        $pdf_orient = $this->reports_sanitize_pdf_orient(isset($_REQUEST['pdf_orient']) ? wp_unslash($_REQUEST['pdf_orient']) : 'portrait');
        $pdf_cols = $this->reports_pdf_cols_from_request();
        $pdf_header = $this->reports_pdf_text_from_request('pdf_header');
        $pdf_footer = $this->reports_pdf_text_from_request('pdf_footer');
        $pdf_mode = $this->reports_sanitize_pdf_mode(isset($_REQUEST['pdf_mode']) ? wp_unslash($_REQUEST['pdf_mode']) : 'full_detail');
        $pdf_breakdowns = $this->reports_pdf_breakdowns_from_request();
        $pdf_logo_id = $this->reports_pdf_logo_id_from_request();
        if ($tx_type === '') { $tx_type = 'all'; }

        $start = $date . ' 00:00:00';
        $end = wp_date('Y-m-d 00:00:00', strtotime($date . ' +1 day'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        // Export URLs (use GET + nonce so export still works even if inline JS is blocked)
        $pdf_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_pdf',
            'tab'    => 'daily',
            'date'   => $date,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_pdf');

        $csv_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_csv',
            'tab'    => 'daily',
            'date'   => $date,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_csv');

        $this->render_template('admin/reports/toolbar.php', [
            'tab' => 'daily',
            'date' => $date,
            'from' => '',
            'to' => '',
            'month' => '',
            'user_login' => $user_login,
            'tx_type' => $tx_type,
            'pdf_url' => $pdf_url,
            'csv_url' => $csv_url,
            'pdf_tpl' => $pdf_tpl,
            'pdf_orient' => $pdf_orient,
            'pdf_cols' => $pdf_cols,
            'pdf_header' => $pdf_header,
            'pdf_footer' => $pdf_footer,
            'pdf_mode' => $pdf_mode,
            'pdf_breakdowns' => $pdf_breakdowns,
            'pdf_logo_id' => $pdf_logo_id,
        ]);

	    $ui_title = ($tx_type === 'income') ? "Daily income report: {$date}" : (($tx_type === 'expense') ? "Daily expense report: {$date}" : "Daily report: {$date}");
	    $this->render_report_summary($ui_title, $tot, $tx_type);
	    // Detail uses the same inclusive date range as totals (end is exclusive, +1 day).
	    $this->render_report_detail_table($start, $end, $user_login, $tx_type);
    }


    private function report_weekly_custom() {
        $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : wp_date('Y-m-d', strtotime('monday this week'));
        $to = isset($_GET['to']) ? sanitize_text_field(wp_unslash($_GET['to'])) : wp_date('Y-m-d', strtotime('sunday this week'));
        $user_login = $this->reports_get_user_filter($_GET, 'user');

        // inclusive end date -> end dt +1 day
        $tx_type_raw = isset($_GET['type']) ? wp_unslash($_GET['type']) : (isset($_GET['kategori']) ? wp_unslash($_GET['kategori']) : 'all');
        $tx_type = $this->reports_sanitize_tx_type(sanitize_text_field($tx_type_raw));

        // PDF layout options
        $pdf_tpl = $this->reports_sanitize_pdf_tpl(isset($_REQUEST['pdf_tpl']) ? wp_unslash($_REQUEST['pdf_tpl']) : 'standard');
        $pdf_orient = $this->reports_sanitize_pdf_orient(isset($_REQUEST['pdf_orient']) ? wp_unslash($_REQUEST['pdf_orient']) : 'portrait');
        $pdf_cols = $this->reports_pdf_cols_from_request();
        $pdf_header = $this->reports_pdf_text_from_request('pdf_header');
        $pdf_footer = $this->reports_pdf_text_from_request('pdf_footer');
        $pdf_mode = $this->reports_sanitize_pdf_mode(isset($_REQUEST['pdf_mode']) ? wp_unslash($_REQUEST['pdf_mode']) : 'full_detail');
        $pdf_breakdowns = $this->reports_pdf_breakdowns_from_request();
        $pdf_logo_id = $this->reports_pdf_logo_id_from_request();
        if ($tx_type === '') { $tx_type = 'all'; }

        $start = $from . ' 00:00:00';
        $end = wp_date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        // Export URLs (GET + nonce) so export works even if JS is blocked
        $pdf_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_pdf',
            'tab'    => 'weekly',
            'from'   => $from,
            'to'     => $to,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_pdf');

        $csv_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_csv',
            'tab'    => 'weekly',
            'from'   => $from,
            'to'     => $to,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_csv');
        $this->render_template('admin/reports/toolbar.php', [
            'tab' => 'weekly',
            'date' => '',
            'from' => $from,
            'to' => $to,
            'month' => '',
            'user_login' => $user_login,
            'tx_type' => $tx_type,
            'pdf_url' => $pdf_url,
            'csv_url' => $csv_url,
            'pdf_tpl' => $pdf_tpl,
            'pdf_orient' => $pdf_orient,
            'pdf_cols' => $pdf_cols,
            'pdf_header' => $pdf_header,
            'pdf_footer' => $pdf_footer,
            'pdf_mode' => $pdf_mode,
            'pdf_breakdowns' => $pdf_breakdowns,
            'pdf_logo_id' => $pdf_logo_id,
        ]);

        $ui_title = ($tx_type === 'income') ? "Weekly income report: {$from} → {$to}" : (($tx_type === 'expense') ? "Weekly expense report: {$from} → {$to}" : "Weekly report: {$from} → {$to}");
        $this->render_report_summary($ui_title, $tot, $tx_type);
        $this->render_report_detail_table($start, $end, $user_login, $tx_type);
    }


    private function report_monthly() {
        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : wp_date('Y-m');
        $user_login = $this->reports_get_user_filter($_GET, 'user');
        $tx_type_raw = isset($_GET['type']) ? wp_unslash($_GET['type']) : (isset($_GET['kategori']) ? wp_unslash($_GET['kategori']) : 'all');
        $tx_type = $this->reports_sanitize_tx_type(sanitize_text_field($tx_type_raw));

        // PDF layout options
        $pdf_tpl = $this->reports_sanitize_pdf_tpl(isset($_REQUEST['pdf_tpl']) ? wp_unslash($_REQUEST['pdf_tpl']) : 'standard');
        $pdf_orient = $this->reports_sanitize_pdf_orient(isset($_REQUEST['pdf_orient']) ? wp_unslash($_REQUEST['pdf_orient']) : 'portrait');
        $pdf_cols = $this->reports_pdf_cols_from_request();
        $pdf_header = $this->reports_pdf_text_from_request('pdf_header');
        $pdf_footer = $this->reports_pdf_text_from_request('pdf_footer');
        $pdf_mode = $this->reports_sanitize_pdf_mode(isset($_REQUEST['pdf_mode']) ? wp_unslash($_REQUEST['pdf_mode']) : 'full_detail');
        $pdf_breakdowns = $this->reports_pdf_breakdowns_from_request();
        $pdf_logo_id = $this->reports_pdf_logo_id_from_request();
        if ($tx_type === '') { $tx_type = 'all'; }

        $start = $month . '-01 00:00:00';
        $end = wp_date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));
        $tot = $this->calc_totals_between($start, $end, 'input', $user_login);

        // Export URLs (GET + nonce) so export works even if JS is blocked
        $pdf_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_pdf',
            'tab'    => 'monthly',
            'month'  => $month,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_pdf');

        $csv_url = wp_nonce_url(add_query_arg([
            'action' => 'simku_export_report_csv',
            'tab'    => 'monthly',
            'month'  => $month,
            'user'   => $user_login,
            'type'   => $tx_type,
        ], admin_url('admin-post.php')), 'simku_export_report_csv');

        $this->render_template('admin/reports/toolbar.php', [
            'tab' => 'monthly',
            'date' => '',
            'from' => '',
            'to' => '',
            'month' => $month,
            'user_login' => $user_login,
            'tx_type' => $tx_type,
            'pdf_url' => $pdf_url,
            'csv_url' => $csv_url,
            'pdf_tpl' => $pdf_tpl,
            'pdf_orient' => $pdf_orient,
            'pdf_cols' => $pdf_cols,
            'pdf_header' => $pdf_header,
            'pdf_footer' => $pdf_footer,
            'pdf_mode' => $pdf_mode,
            'pdf_breakdowns' => $pdf_breakdowns,
            'pdf_logo_id' => $pdf_logo_id,
        ]);

        $ui_title = ($tx_type === 'income') ? "Monthly income report: {$month}" : (($tx_type === 'expense') ? "Monthly expense report: {$month}" : "Monthly report: {$month}");
        $this->render_report_summary($ui_title, $tot, $tx_type);
        $this->render_report_detail_table($start, $end, $user_login, $tx_type);
    }


    private function render_report_summary($title, $tot, $tx_type = 'all') {
        $this->render_template('admin/reports/summary.php', [
            'title' => $title,
            'tot' => $tot,
            'tx_type' => $tx_type,
        ]);
    }


    private function render_report_detail_table($start_date, $end_date, $user_login, $tx_type) {
        // Reports use transaction entry/input date as the primary basis.
        // fetch_report_detail_rows signature: (start, end, date_basis, limit, user_login, tx_type)
        $rows = $this->fetch_report_detail_rows($start_date, $end_date, 'input', 500, $user_login, $tx_type);

        $this->render_template('admin/reports/detail-table.php', [
            'rows' => $rows,
        ]);
    }

    public function shortcode_simku_reports($atts = [], $content = null) {
        return $this->shortcode_simku(array_merge((array)$atts, ['page' => 'reports']), $content, 'simku_reports');
    }

}
