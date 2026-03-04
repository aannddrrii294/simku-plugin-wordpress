<?php
/**
* Datasource helpers (internal/external) for transactions, savings, and reminders.
 *
 * Extracted to keep the main plugin class smaller and easier to maintain.
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Datasource {
/* -------------------- Datasource helpers -------------------- */

    /**
     * Sanitize a MySQL table identifier coming from settings.
     * Only allows alphanumeric + underscore.
     */
    private function ds_sanitize_table_name($t, $fallback = '') {
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$t);
        if (!$t && $fallback !== '') return $fallback;
        return $t;
    }

    private function ds_table() {
        $s = $this->settings();
        // Single "Table" field used for both internal & external.
        // Default to the plugin internal table name.
        $t = $s['external']['table'] ?? 'fl_transactions';
        $t = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$t);
        if (!$t) {
            global $wpdb;
            return $wpdb->prefix . 'fl_transactions';
        }

        // Internal mode: users often input table name without WP prefix.
        // Auto-resolve and fallback to known internal tables if setting is wrong.
        if (!$this->ds_is_external()) {
            global $wpdb;

            $candidates = array_values(array_unique([
                $t,
                $wpdb->prefix . $t,
                $wpdb->prefix . 'fl_transactions',
                $wpdb->prefix . 'finance_transactions',
            ]));

            foreach ($candidates as $cand) {
                $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $cand));
                if ($exists) return $cand;
            }

            // Ultimate fallback (most common)
            return $wpdb->prefix . 'fl_transactions';
        }

        return $t;
    }

    /* -------------------- Savings datasource helpers -------------------- */

    private function savings_mode() {
        $s = $this->settings();
        $m = (string)($s['savings']['mode'] ?? 'same');
        if (!in_array($m, ['same','internal','external'], true)) $m = 'same';
        return $m;
    }

    private function savings_is_external() {
        $m = $this->savings_mode();
        if ($m === 'external') return true;
        if ($m === 'internal') return false;
        // same
        return $this->ds_is_external();
    }

    private function savings_db() {
        if ($this->savings_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function savings_table() {
        $s = $this->settings();
        if ($this->savings_is_external()) {
            // Recommended external name (no WP prefix). Older versions used finance_savings.
            $t = (string)($s['savings']['external_table'] ?? 'fl_savings');
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
            if (!$t) $t = 'fl_savings';
            return $t;
        }
        global $wpdb;
        return $wpdb->prefix . 'fl_savings';
    }

    private function savings_date_column($db = null, $table = null) {
        // Backward compatibility: some older versions created `tanggal_input` instead of `saved_at`.
        static $cache = [];
        if (!$db) $db = $this->savings_db();
        if (!($db instanceof wpdb)) return 'saved_at';
        if (!$table) $table = $this->savings_table();
        $key = spl_object_hash($db) . '|' . $table;
        if (isset($cache[$key])) return $cache[$key];
        $has_saved_at = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'saved_at'));
        if ($has_saved_at) return $cache[$key] = 'saved_at';
        $has_tanggal = $db->get_var($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'tanggal_input'));
        if ($has_tanggal) return $cache[$key] = 'tanggal_input';
        return $cache[$key] = 'saved_at';
    }

    /* -------------------- Payment reminders datasource helpers -------------------- */

    private function reminders_mode() {
        $s = $this->settings();
        $m = (string)($s['reminders']['mode'] ?? 'same');
        if (!in_array($m, ['same','internal','external'], true)) $m = 'same';
        return $m;
    }

    private function reminders_is_external() {
        $m = $this->reminders_mode();
        if ($m === 'external') return true;
        if ($m === 'internal') return false;
        return $this->ds_is_external();
    }

    private function reminders_db() {
        if ($this->reminders_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function reminders_table() {
        $s = $this->settings();
        if ($this->reminders_is_external()) {
            // Recommended external name (no WP prefix). Older versions used finance_payment_reminders.
            $t = (string)($s['reminders']['external_table'] ?? 'fl_payment_reminders');
            $t = preg_replace('/[^a-zA-Z0-9_]/', '', $t);
            if (!$t) $t = 'fl_payment_reminders';
            return $t;
        }
        global $wpdb;
        return $wpdb->prefix . 'fl_payment_reminders';
    }

    
    private function ds_is_external() {
        $s = $this->settings();
        return (($s['datasource_mode'] ?? 'external') === 'external');
    }

    private function ds_allow_write_external() {
        $s = $this->settings();
        return !empty($s['external']['allow_write']);
    }

    private function ext_db() {
        $s = $this->settings();
        $ext = $s['external'] ?? [];
        $host = $ext['host'] ?? '';
        $db   = $ext['db'] ?? '';
        $user = $ext['user'] ?? '';
        $pass = $ext['pass'] ?? '';
        if (!$host || !$db || !$user) return null;
        $wpdb_ext = new wpdb($user, $pass, $db, $host);
        $wpdb_ext->set_prefix(''); // no prefix
        $wpdb_ext->show_errors(false);
        return $wpdb_ext;
    }

    /**
     * Build an external wpdb instance from a settings array (used by Settings page preview/test).
     */
    private function ext_db_from_settings_array($s) {
        $ext = $s['external'] ?? [];
        $host = $ext['host'] ?? '';
        $db   = $ext['db'] ?? '';
        $user = $ext['user'] ?? '';
        $pass = $ext['pass'] ?? '';
        if (!$host || !$db || !$user) return null;
        $wpdb_ext = new wpdb($user, $pass, $db, $host);
        $wpdb_ext->set_prefix('');
        $wpdb_ext->show_errors(false);
        $wpdb_ext->suppress_errors(true);
        return $wpdb_ext;
    }

    private function ds_db() {
        if ($this->ds_is_external()) return $this->ext_db();
        global $wpdb;
        return $wpdb;
    }

    private function ds_ready() {
        $db = $this->ds_db();
        return $db instanceof wpdb;
    }

    /**
     * Check whether a column exists on the current datasource table.
     * Works for both internal (wpdb) and external (ext_db) modes.
     */
    private function ds_column_exists($col, $db = null, $table = null) {
        if (!($db instanceof wpdb)) $db = $this->ds_db();
        if (!($db instanceof wpdb)) return false;
        if (!$table) $table = $this->ds_table();

        $col = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
        if (!$col) return false;

        static $cache = [];
        $cache_key = (function_exists('spl_object_hash') ? spl_object_hash($db) : ((string)$db->dbname)) . '|' . (string)$table . '|' . $col;
        if (array_key_exists($cache_key, $cache)) return (bool)$cache[$cache_key];

        $row = $db->get_row($db->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $col));
        $cache[$cache_key] = !empty($row);
        return (bool)$cache[$cache_key];
    }


    /* -------------------- Schema helpers (Settings page) -------------------- */

    /**
     * Return a CREATE TABLE statement for an existing table in the provided DB.
     * Tries SHOW CREATE TABLE first (best fidelity), falls back to DESCRIBE + SHOW INDEX.
     */
    private function db_get_create_table_sql($db, $table) {
        if (!($db instanceof wpdb)) return '';
        $table = $this->ds_sanitize_table_name($table);
        if (!$table) return '';

        // 1) Best effort: SHOW CREATE TABLE
        $row = $db->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
        if (is_array($row) && isset($row[1]) && is_string($row[1]) && $row[1] !== '') {
            $sql = trim($row[1]);
            return (substr($sql, -1) === ';') ? $sql : ($sql . ';');
        }

        // 2) Fallback: DESCRIBE
        $cols = $db->get_results("DESCRIBE `{$table}`", ARRAY_A);
        if (!$cols || !is_array($cols)) return '';

        $lines = [];
        $primary = [];
        foreach ($cols as $c) {
            $field = (string)($c['Field'] ?? '');
            if ($field === '') continue;
            $type = (string)($c['Type'] ?? '');
            $null = strtoupper((string)($c['Null'] ?? '')) === 'NO' ? 'NOT NULL' : 'NULL';
            $default = $c['Default'] ?? null;
            $extra = (string)($c['Extra'] ?? '');
            $key = (string)($c['Key'] ?? '');

            $line = "  `{$field}` {$type} {$null}";
            if ($default !== null) {
                // Numbers can be left unquoted; everything else quoted.
                if (is_numeric($default)) {
                    $line .= " DEFAULT {$default}";
                } else {
                    $line .= " DEFAULT '" . str_replace("'", "\\'", (string)$default) . "'";
                }
            }
            if ($extra) {
                $line .= ' ' . strtoupper($extra);
            }
            $lines[] = $line;

            if ($key === 'PRI') {
                $primary[] = $field;
            }
        }

        // Indexes (best effort)
        $idx = $db->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $indexes = [];
        if (is_array($idx)) {
            foreach ($idx as $r) {
                $k = (string)($r['Key_name'] ?? '');
                $col = (string)($r['Column_name'] ?? '');
                $seq = (int)($r['Seq_in_index'] ?? 0);
                $non_unique = (int)($r['Non_unique'] ?? 1);
                if ($k === '' || $col === '') continue;
                if (!isset($indexes[$k])) {
                    $indexes[$k] = ['non_unique' => $non_unique, 'cols' => []];
                }
                $indexes[$k]['cols'][$seq] = $col;
            }
        }

        // PRIMARY KEY line (prefer SHOW INDEX ordering if available)
        if (isset($indexes['PRIMARY'])) {
            ksort($indexes['PRIMARY']['cols']);
            $primary = array_values($indexes['PRIMARY']['cols']);
            unset($indexes['PRIMARY']);
        }
        if ($primary) {
            $pk_cols = array_map(function($c){ return "`{$c}`"; }, $primary);
            $lines[] = '  PRIMARY KEY (' . implode(',', $pk_cols) . ')';
        }

        // Other keys
        foreach ($indexes as $k => $meta) {
            if ($k === '' || $k === 'PRIMARY') continue;
            $cols_map = $meta['cols'] ?? [];
            if (!$cols_map) continue;
            ksort($cols_map);
            $cols_list = array_values($cols_map);
            $cols_sql = implode(',', array_map(function($c){ return "`{$c}`"; }, $cols_list));
            // Keep output close to MySQL: UNIQUE KEY vs KEY
            $lines[] = ((int)($meta['non_unique'] ?? 1) === 0 ? "  UNIQUE KEY `{$k}` ({$cols_sql})" : "  KEY `{$k}` ({$cols_sql})");
        }

        return "CREATE TABLE `{$table}` (\n" . implode(",\n", $lines) . "\n);";
    }
}
