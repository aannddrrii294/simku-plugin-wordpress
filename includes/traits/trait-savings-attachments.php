<?php
/**
 * Savings Attachments (multiple images per saving line).
 *
 * Stored in an internal WP table so it works for both internal and external savings datasources.
 *
 * Storage format:
 * - images LONGTEXT storing either:
 *   - '' (no images)
 *   - single URL string
 *   - JSON array of URLs
 */

if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Saving_Attachments {

    private function saving_attachments_table() {
        global $wpdb;
        return $wpdb->prefix . 'fl_saving_attachments';
    }

    /** Ensure table exists (safe on admin_init). */
    public function maybe_ensure_saving_attachments_table() {
        if (!is_admin()) return;
        $this->ensure_saving_attachments_table();
    }

    private function ensure_saving_attachments_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->saving_attachments_table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            saving_line_id VARCHAR(100) NOT NULL,
            images LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY saving_line_id (saving_line_id)
        ) {$charset};";

        if (function_exists('dbDelta')) {
            dbDelta($sql);
        } else {
            $wpdb->query($sql);
        }
    }

    /**
     * Upload multiple images from a <input type="file" multiple> field.
     * Returns an array of uploaded URLs.
     */
    private function handle_multi_image_uploads($field_name) {
        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
            return [];
        }

        $names = $_FILES[$field_name]['name'];
        $tmp_names = $_FILES[$field_name]['tmp_name'];
        $errors = $_FILES[$field_name]['error'];
        $sizes = $_FILES[$field_name]['size'];
        $types = $_FILES[$field_name]['type'];

        if (!is_array($names)) {
            // Not a multi upload field.
            return [];
        }

        $mimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
        ];

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = [
            'test_form' => false,
            'mimes' => $mimes,
        ];

        $max = method_exists($this, 'receipts_max_upload_bytes') ? (int)$this->receipts_max_upload_bytes() : (8 * 1024 * 1024);

        $urls = [];
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $name = (string)($names[$i] ?? '');
            $tmp  = (string)($tmp_names[$i] ?? '');
            $err  = (int)($errors[$i] ?? 0);
            $size = (int)($sizes[$i] ?? 0);
            $type = (string)($types[$i] ?? '');

            if ($tmp === '' || $name === '') continue;
            if ($err !== 0) continue;
            if ($size > 0 && $max > 0 && $size > $max) continue;

            $file = [
                'name' => $name,
                'type' => $type,
                'tmp_name' => $tmp,
                'error' => $err,
                'size' => $size,
            ];

            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $mimes);
            if (empty($check['type'])) {
                continue;
            }

            $upload = wp_handle_upload($file, $overrides);
            if (empty($upload) || !empty($upload['error']) || empty($upload['url'])) {
                continue;
            }

            if (!empty($upload['file'])) {
                $this->optimize_uploaded_image_file((string)$upload['file']);
            }

            $urls[] = esc_url_raw((string)$upload['url']);
        }

        return array_values(array_unique(array_filter($urls)));
    }

    private function simku_saving_attachments_get_urls($saving_line_id) {
        global $wpdb;
        $this->ensure_saving_attachments_table();
        $table = $this->saving_attachments_table();

        $saving_line_id = sanitize_text_field((string)$saving_line_id);
        if ($saving_line_id === '') return [];

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT images FROM {$table} WHERE saving_line_id=%s LIMIT 1",
            $saving_line_id
        ), ARRAY_A);

        $val = is_array($row) ? ($row['images'] ?? '') : '';
        return method_exists($this, 'images_from_db_value') ? $this->images_from_db_value($val) : [];
    }

    private function simku_saving_attachments_upsert_merge($saving_line_id, $new_urls) {
        global $wpdb;
        $this->ensure_saving_attachments_table();
        $table = $this->saving_attachments_table();

        $saving_line_id = sanitize_text_field((string)$saving_line_id);
        if ($saving_line_id === '') return false;

        $new_urls = is_array($new_urls) ? $new_urls : [];
        $existing = $this->simku_saving_attachments_get_urls($saving_line_id);
        $all = array_values(array_unique(array_merge($existing, $new_urls)));

        $now = current_time('mysql');
        $encoded = method_exists($this, 'images_to_db_value') ? $this->images_to_db_value($all) : '';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE saving_line_id=%s LIMIT 1",
            $saving_line_id
        ));

        if ($exists) {
            $res = $wpdb->update($table, [
                'images' => $encoded,
                'updated_at' => $now,
            ], [
                'saving_line_id' => $saving_line_id,
            ], ['%s','%s'], ['%s']);
            return $res !== false;
        }

        $res = $wpdb->insert($table, [
            'saving_line_id' => $saving_line_id,
            'images' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%s']);

        return $res !== false;
    }


    /**
     * Upsert and SET the full list of images for a saving line.
     * (Replaces existing list; use for edit mode with remove/replace logic.)
     */
    private function simku_saving_attachments_upsert_set($saving_line_id, $urls) {
        global $wpdb;
        $this->ensure_saving_attachments_table();
        $table = $this->saving_attachments_table();

        $saving_line_id = sanitize_text_field((string)$saving_line_id);
        if ($saving_line_id === '') return false;

        $urls = is_array($urls) ? $urls : [];
        $clean = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            $clean[] = esc_url_raw($u);
        }
        $clean = array_values(array_unique(array_filter($clean)));

        $now = current_time('mysql');
        $encoded = method_exists($this, 'images_to_db_value') ? $this->images_to_db_value($clean) : '';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE saving_line_id=%s LIMIT 1",
            $saving_line_id
        ));

        if ($exists) {
            $res = $wpdb->update($table, [
                'images' => $encoded,
                'updated_at' => $now,
            ], [
                'saving_line_id' => $saving_line_id,
            ], ['%s','%s'], ['%s']);
            return $res !== false;
        }

        $res = $wpdb->insert($table, [
            'saving_line_id' => $saving_line_id,
            'images' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%s']);

        return $res !== false;
    }

    private function simku_saving_attachments_delete_by_saving_line($saving_line_id) {
        global $wpdb;
        $this->ensure_saving_attachments_table();
        $table = $this->saving_attachments_table();
        $saving_line_id = sanitize_text_field((string)$saving_line_id);
        if ($saving_line_id === '') return false;
        $res = $wpdb->delete($table, ['saving_line_id' => $saving_line_id], ['%s']);
        return $res !== false;
    }

    /**
     * Map saving_line_id => array(urls)
     */
    private function simku_saving_attachments_map_for_lines($line_ids) {
        global $wpdb;
        $this->ensure_saving_attachments_table();
        $table = $this->saving_attachments_table();

        $ids = array_values(array_filter(array_map(function($v){
            $v = sanitize_text_field((string)$v);
            return $v !== '' ? $v : null;
        }, (array)$line_ids)));

        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $sql = "SELECT saving_line_id, images FROM {$table} WHERE saving_line_id IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $ids), ARRAY_A);

        $map = [];
        foreach ((array)$rows as $r) {
            $lid = (string)($r['saving_line_id'] ?? '');
            if ($lid === '') continue;
            $map[$lid] = method_exists($this, 'images_from_db_value') ? $this->images_from_db_value($r['images'] ?? '') : [];
        }
        return $map;
    }
}
