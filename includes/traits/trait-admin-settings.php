<?php
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Admin_Settings_Page {
public function page_settings() {
        if (!current_user_can(self::CAP_MANAGE_SETTINGS)) wp_die(esc_html__('Forbidden.', self::TEXT_DOMAIN));

        $s = $this->settings();
        $notices = []; // each: ['type'=>'success|error|warning','msg'=>string]

        // Helper to build temp settings from POST (for test/save)
        $build_from_post = function($base) {
            $base['datasource_mode'] = sanitize_text_field(wp_unslash($_REQUEST['datasource_mode'] ?? ($base['datasource_mode'] ?? 'external')));

            $base['external']['host']  = sanitize_text_field(wp_unslash($_REQUEST['ext_host'] ?? ($base['external']['host'] ?? '')));
            $base['external']['db']    = sanitize_text_field(wp_unslash($_REQUEST['ext_db'] ?? ($base['external']['db'] ?? '')));
            $base['external']['user']  = sanitize_text_field(wp_unslash($_REQUEST['ext_user'] ?? ($base['external']['user'] ?? '')));
            $base['external']['table'] = sanitize_text_field(wp_unslash($_REQUEST['ext_table'] ?? ($base['external']['table'] ?? 'fl_transactions')));
            $base['external']['allow_write'] = !empty($_REQUEST['ext_allow_write']) ? 1 : 0;

            // Savings datasource (optional)
            $base['savings']['mode'] = sanitize_text_field(wp_unslash($_REQUEST['savings_mode'] ?? ($base['savings']['mode'] ?? 'same')));
            $base['savings']['external_table'] = sanitize_text_field(wp_unslash($_REQUEST['savings_ext_table'] ?? ($base['savings']['external_table'] ?? 'fl_savings')));

            // Payment reminders datasource (optional)
            $base['reminders']['mode'] = sanitize_text_field(wp_unslash($_REQUEST['reminders_mode'] ?? ($base['reminders']['mode'] ?? 'same')));
            $base['reminders']['external_table'] = sanitize_text_field(wp_unslash($_REQUEST['reminders_ext_table'] ?? ($base['reminders']['external_table'] ?? 'fl_payment_reminders')));

            // Password: only update if user typed something (security best practice)
            $new_pass = (string)wp_unslash($_REQUEST['ext_pass'] ?? '');
            if ($new_pass !== '') {
                $base['external']['pass'] = sanitize_text_field($new_pass);
            }

            $base['limits']['daily'] = (float)($_REQUEST['limit_daily'] ?? ($base['limits']['daily'] ?? 0));
            $base['limits']['weekly'] = (float)($_REQUEST['limit_weekly'] ?? ($base['limits']['weekly'] ?? 0));
            $base['limits']['monthly'] = (float)($_REQUEST['limit_monthly'] ?? ($base['limits']['monthly'] ?? 0));
            $base['limits']['expense_categories'] = array_values(array_filter(array_map('sanitize_text_field', (array)($_REQUEST['expense_categories'] ?? ($base['limits']['expense_categories'] ?? [])))));

            $base['notify']['email_enabled'] = !empty($_REQUEST['email_enabled']) ? 1 : 0;
            $base['notify']['email_to'] = sanitize_text_field(wp_unslash($_REQUEST['email_to'] ?? ($base['notify']['email_to'] ?? '')));
            $base['notify']['email_new_to_mode'] = sanitize_text_field(wp_unslash($_REQUEST['email_new_to_mode'] ?? ($base['notify']['email_new_to_mode'] ?? 'settings')));
            $base['notify']['email_from'] = sanitize_text_field(wp_unslash($_REQUEST['email_from'] ?? ($base['notify']['email_from'] ?? '')));
            $base['notify']['email_from_name'] = sanitize_text_field(wp_unslash($_REQUEST['email_from_name'] ?? ($base['notify']['email_from_name'] ?? '')));
            $base['notify']['email_notify_new_tx_default'] = !empty($_REQUEST['email_new_default']) ? 1 : 0;
            $base['notify']['email_new_subject_tpl'] = sanitize_text_field(wp_unslash($_REQUEST['email_new_subject_tpl'] ?? ($base['notify']['email_new_subject_tpl'] ?? '')));
            // Body templates can contain newlines; keep as textarea-safe text.
            $base['notify']['email_new_body_tpl'] = (string) wp_unslash($_REQUEST['email_new_body_tpl'] ?? ($base['notify']['email_new_body_tpl'] ?? ''));

            $base['notify']['telegram_enabled'] = !empty($_REQUEST['telegram_enabled']) ? 1 : 0;

            // Bot token (password-like): blank = keep existing.
            if (!empty($_REQUEST['telegram_clear_bot_token'])) {
                $base['notify']['telegram_bot_token'] = '';
            } else {
                $new_tok = (string) wp_unslash($_REQUEST['telegram_bot_token'] ?? '');
                if ($new_tok !== '') {
                    $base['notify']['telegram_bot_token'] = sanitize_text_field($new_tok);
                }
            }

            $base['notify']['telegram_chat_id'] = sanitize_text_field(wp_unslash($_REQUEST['telegram_chat_id'] ?? ($base['notify']['telegram_chat_id'] ?? '')));
            $base['notify']['telegram_allow_insecure_tls'] = !empty($_REQUEST['telegram_allow_insecure_tls']) ? 1 : 0;
            $base['notify']['telegram_notify_new_tx_default'] = !empty($_REQUEST['telegram_new_default']) ? 1 : 0;
            $base['notify']['telegram_new_tpl'] = (string) wp_unslash($_REQUEST['telegram_new_tpl'] ?? ($base['notify']['telegram_new_tpl'] ?? ''));

            $base['notify']['whatsapp_webhook'] = esc_url_raw(wp_unslash($_REQUEST['whatsapp_webhook'] ?? ($base['notify']['whatsapp_webhook'] ?? '')));
            $base['notify']['notify_on_limit'] = !empty($_REQUEST['notify_on_limit']) ? 1 : 0;

            // Reminder templates
            $base['notify']['reminder_offsets'] = [7,5,3]; // fixed by design
            $base['notify']['reminder_telegram_tpl'] = (string) wp_unslash($_REQUEST['reminder_telegram_tpl'] ?? ($base['notify']['reminder_telegram_tpl'] ?? ''));
            $base['notify']['reminder_email_subject_tpl'] = sanitize_text_field(wp_unslash($_REQUEST['reminder_email_subject_tpl'] ?? ($base['notify']['reminder_email_subject_tpl'] ?? '')));
            $base['notify']['reminder_email_body_tpl'] = (string) wp_unslash($_REQUEST['reminder_email_body_tpl'] ?? ($base['notify']['reminder_email_body_tpl'] ?? ''));

            // Receipt scanner (n8n)
            if (!is_array($base['n8n'] ?? null)) $base['n8n'] = self::default_settings()['n8n'];
            $base['n8n']['webhook_url'] = esc_url_raw(wp_unslash($_REQUEST['n8n_webhook_url'] ?? ($base['n8n']['webhook_url'] ?? '')));
            $base['n8n']['timeout'] = (int)($_REQUEST['n8n_timeout'] ?? ($base['n8n']['timeout'] ?? 90));
            if ($base['n8n']['timeout'] < 10) $base['n8n']['timeout'] = 10;
            if ($base['n8n']['timeout'] > 180) $base['n8n']['timeout'] = 180;

            if (!empty($_REQUEST['n8n_clear_api_key'])) {
                $base['n8n']['api_key'] = '';
            } else {
                $new_key = (string) wp_unslash($_REQUEST['n8n_api_key'] ?? '');
                if ($new_key !== '') {
                    $base['n8n']['api_key'] = sanitize_text_field($new_key);
                }
            }


            
            // Receipt Storage (uploads / Google Drive)
            if (!is_array($base['receipts'] ?? null)) $base['receipts'] = self::default_settings()['receipts'];

            $st = strtolower(trim((string)wp_unslash($_REQUEST['receipts_storage'] ?? ($base['receipts']['storage'] ?? 'uploads'))));
            if (!in_array($st, ['uploads','gdrive'], true)) $st = 'uploads';
            $base['receipts']['storage'] = $st;

            $base['receipts']['gdrive_folder_id'] = sanitize_text_field(wp_unslash($_REQUEST['receipts_gdrive_folder_id'] ?? ($base['receipts']['gdrive_folder_id'] ?? '')));

            $base['receipts']['delete_local_after_upload'] = !empty($_REQUEST['receipts_delete_local_after_upload']) ? 1 : 0;

            $mb = (int)($_REQUEST['receipts_max_upload_mb'] ?? ($base['receipts']['max_upload_mb'] ?? 8));
            if ($mb < 1) $mb = 1;
            if ($mb > 50) $mb = 50;
            $base['receipts']['max_upload_mb'] = $mb;

            if (!empty($_REQUEST['receipts_clear_gdrive_service_json'])) {
                $base['receipts']['gdrive_service_account_json'] = '';
            } else {
                $sj = (string) wp_unslash($_REQUEST['receipts_gdrive_service_json'] ?? '');
                if ($sj !== '') {
                    $base['receipts']['gdrive_service_account_json'] = trim($sj);
                }
            }

// Integrations (REST API, Webhooks, Google Sheets)
            if (!is_array($base['integrations'] ?? null)) $base['integrations'] = self::default_settings()['integrations'];

            $base['integrations']['allow_query_api_key'] = !empty($_REQUEST['sec_allow_query_api_key']) ? 1 : 0;
            $base['integrations']['allow_http_webhooks'] = !empty($_REQUEST['sec_allow_http_webhooks']) ? 1 : 0;

            // Inbound (signed webhook; HTTPS-only)
            $base['integrations']['inbound_enabled'] = !empty($_REQUEST['int_inbound_enabled']) ? 1 : 0;

            $base['integrations']['inbound_tolerance_sec'] = (int)($_REQUEST['int_inbound_tolerance_sec'] ?? ($base['integrations']['inbound_tolerance_sec'] ?? 300));
            if ($base['integrations']['inbound_tolerance_sec'] < 30) $base['integrations']['inbound_tolerance_sec'] = 30;
            if ($base['integrations']['inbound_tolerance_sec'] > 3600) $base['integrations']['inbound_tolerance_sec'] = 3600;

            $base['integrations']['inbound_rate_limit_per_min'] = (int)($_REQUEST['int_inbound_rate_limit_per_min'] ?? ($base['integrations']['inbound_rate_limit_per_min'] ?? 60));
            if ($base['integrations']['inbound_rate_limit_per_min'] < 1) $base['integrations']['inbound_rate_limit_per_min'] = 1;
            if ($base['integrations']['inbound_rate_limit_per_min'] > 600) $base['integrations']['inbound_rate_limit_per_min'] = 600;

            if (!empty($_REQUEST['int_clear_inbound_secret'])) {
                $base['integrations']['inbound_secret'] = '';
            } else {
                $new = (string) wp_unslash($_REQUEST['int_inbound_secret'] ?? '');
                if ($new !== '') $base['integrations']['inbound_secret'] = sanitize_text_field($new);
            }

            if (!empty($_REQUEST['int_clear_api_key'])) {
                $base['integrations']['rest_api_key'] = '';
            } else {
                $new = (string) wp_unslash($_REQUEST['int_api_key'] ?? '');
                if ($new !== '') $base['integrations']['rest_api_key'] = sanitize_text_field($new);
            }

            $base['integrations']['webhooks_enabled'] = !empty($_REQUEST['int_webhooks_enabled']) ? 1 : 0;

            if (!empty($_REQUEST['int_clear_webhook_secret'])) {
                $base['integrations']['webhook_secret'] = '';
            } else {
                $new = (string) wp_unslash($_REQUEST['int_webhook_secret'] ?? '');
                if ($new !== '') $base['integrations']['webhook_secret'] = sanitize_text_field($new);
            }

            $base['integrations']['webhook_urls'] = sanitize_textarea_field(wp_unslash($_REQUEST['int_webhook_urls'] ?? ($base['integrations']['webhook_urls'] ?? '')));
            $base['integrations']['google_sheets_webhook_url'] = esc_url_raw(wp_unslash($_REQUEST['int_gsheets_webhook_url'] ?? ($base['integrations']['google_sheets_webhook_url'] ?? '')));

            $base['integrations']['webhook_timeout'] = (int)($_REQUEST['int_webhook_timeout'] ?? ($base['integrations']['webhook_timeout'] ?? 12));
            if ($base['integrations']['webhook_timeout'] < 2) $base['integrations']['webhook_timeout'] = 2;
            if ($base['integrations']['webhook_timeout'] > 60) $base['integrations']['webhook_timeout'] = 60;

            // Event filters
            $def_events = (array)(self::default_settings()['integrations']['webhook_events'] ?? []);
            $events = [];
            foreach ($def_events as $ev => $on) {
                $key = 'int_wh_event_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $ev);
                $events[$ev] = !empty($_REQUEST[$key]) ? 1 : 0;
            }
            $base['integrations']['webhook_events'] = $events;

            // Chat (Telegram inbound)
            if (!is_array($base['chat'] ?? null)) $base['chat'] = self::default_settings()['chat'];
            $base['chat']['telegram_in_enabled'] = !empty($_REQUEST['chat_telegram_in_enabled']) ? 1 : 0;

            $base['chat']['telegram_allow_query_secret'] = !empty($_REQUEST['chat_telegram_allow_query_secret']) ? 1 : 0;
            if (!empty($_REQUEST['chat_clear_telegram_secret'])) {
                $base['chat']['telegram_webhook_secret'] = '';
            } else {
                $new = (string) wp_unslash($_REQUEST['chat_telegram_webhook_secret'] ?? '');
                if ($new !== '') $base['chat']['telegram_webhook_secret'] = sanitize_text_field($new);
            }

            $base['chat']['telegram_allowed_chat_ids'] = sanitize_textarea_field(wp_unslash($_REQUEST['chat_telegram_allowed_chat_ids'] ?? ($base['chat']['telegram_allowed_chat_ids'] ?? '')));

            if (!empty($_REQUEST['chat_clear_telegram_bot_token'])) {
                $base['chat']['telegram_bot_token'] = '';
            } else {
                $new = (string) wp_unslash($_REQUEST['chat_telegram_bot_token'] ?? '');
                if ($new !== '') $base['chat']['telegram_bot_token'] = sanitize_text_field($new);
            }

            return $base;
        };

        // Create internal table
        if (!empty($_REQUEST['fl_create_internal_table'])) {
            check_admin_referer('fl_create_internal_table', 'fl_create_internal_table_nonce');
            [$ok, $msg] = $this->create_internal_transactions_table();
            $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
        }

        // Test connection (does NOT save settings)
        elseif (!empty($_REQUEST['fl_test_connection'])) {
            check_admin_referer('fl_test_connection', 'fl_test_connection_nonce');
            $temp = $build_from_post($s);
            [$ok, $msg] = $this->test_connection_from_settings($temp);
            $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => $msg];
            // keep entered values in UI by using $s = $temp for rendering
            $s = $temp;
        }

        // Run migration (external user columns)
        elseif (!empty($_REQUEST['fl_run_migration'])) {
            check_admin_referer('fl_run_migration', 'fl_run_migration_nonce');
            $temp = $build_from_post($s);
            // keep entered values for rendering
            $s = $temp;

            [$ok_conn, $msg_conn] = $this->test_connection_from_settings($temp);
            if (!$ok_conn) {
                $notices[] = ['type' => 'error', 'msg' => 'Cannot run migration: ' . $msg_conn];
            } else {
                // Save settings first (so migration uses same config)
                $this->update_settings($temp);
                [$ok, $msgs] = $this->ensure_external_user_columns();
                $notices[] = ['type' => $ok ? 'success' : 'error', 'msg' => implode(' ', $msgs)];
            }
        }

        // Save settings
        elseif (!empty($_REQUEST['fl_save_settings'])) {
            check_admin_referer('fl_save_settings', 'fl_save_settings_nonce');
            $s = $build_from_post($s);
            $this->update_settings($s);

            [$ok, $msg] = $this->test_connection_from_settings($s);
            if ($ok) {
                $notices[] = ['type' => 'success', 'msg' => 'Settings saved. ' . $msg];
            } else {
                $notices[] = ['type' => 'error', 'msg' => 'Settings saved, but connection check failed: ' . $msg];
            }
        }

        // Render view via template (easier to maintain for open-source contributors).
        $this->render_template('admin/settings.php', [
            's'       => $s,
            'notices' => $notices,
        ]);

    }

    

}
