<?php
/**
* Notification helpers (Telegram, Email, WhatsApp webhook).
 *
 * Extracted to keep notification logic in one place.
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_Notify {
/* -------------------- Notifications -------------------- */

    private function telegram_send($message) {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        if (empty($n['telegram_enabled']) || empty($n['telegram_bot_token']) || empty($n['telegram_chat_id'])) return false;

        $token = trim($n['telegram_bot_token']);
        $chat  = trim($n['telegram_chat_id']);

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $resp = wp_safe_remote_post($url, [
            'timeout' => 12,
            // Some shared hostings have incomplete CA bundles; allow Telegram to work.
            'sslverify' => empty($n['telegram_allow_insecure_tls']),
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'chat_id' => $chat,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);
        if (is_wp_error($resp)) {
            $this->log_event('notify_fail', 'telegram', null, ['error' => $resp->get_error_message()]);
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            $this->log_event('notify_fail', 'telegram', null, ['http_code' => $code, 'body' => wp_remote_retrieve_body($resp)]);
            return false;
        }
        $this->log_event('notify_ok', 'telegram', null, ['chat_id' => $chat]);
        return true;
    }

    private function whatsapp_webhook_send($payload) {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        $url = trim($n['whatsapp_webhook'] ?? '');
        if (!$url) return false;

        $resp = wp_safe_remote_post($url, [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);
        return !is_wp_error($resp) && (int)wp_remote_retrieve_response_code($resp) < 300;
    }

    private function build_email_headers() {
        $s = $this->settings();
        $n = $s['notify'] ?? [];

        $headers = [];
        $from_email = sanitize_email(trim((string)($n['email_from'] ?? '')));
        if ($from_email) {
            $from_name = trim((string)($n['email_from_name'] ?? ''));
            if ($from_name === '') {
                $from_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
            }
            // Basic header injection safety
            $from_name = preg_replace('/[\r\n]+/', ' ', $from_name);
            $from_name = trim(preg_replace('/\s+/', ' ', $from_name));
            $headers[] = 'From: ' . $from_name . ' <' . $from_email . '>';
            $headers[] = 'Reply-To: ' . $from_name . ' <' . $from_email . '>';
        }
        return $headers;
    }

    private function resolve_user_email_from_ctx($ctx) {
        if (!empty($ctx['user_email'])) {
            $e = sanitize_email((string)$ctx['user_email']);
            if ($e) return $e;
        }
        if (!empty($ctx['user'])) {
            $u = get_user_by('login', (string)$ctx['user']);
            if ($u && !empty($u->user_email)) {
                $e = sanitize_email((string)$u->user_email);
                if ($e) return $e;
            }
        }
        $cu = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        if ($cu && !empty($cu->user_email)) {
            $e = sanitize_email((string)$cu->user_email);
            if ($e) return $e;
        }
        return '';
    }

    private function email_send($subject, $message) {
        $s = $this->settings();
        $n = $s['notify'] ?? [];
        if (empty($n['email_enabled']) || empty($n['email_to'])) return false;

        $to = trim((string)$n['email_to']);
        $headers = $this->build_email_headers();
        $ok = (bool) wp_mail($to, $subject, $message, $headers);

        if ($ok) {
            $this->log_event('notify_ok', 'email', null, ['to' => $to, 'subject' => $subject]);
        } else {
            $this->log_event('notify_fail', 'email', null, ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }


private function send_telegram_new_tx($ctx) {
    $s = $this->settings();
    $tpl = (string)($s['notify']['telegram_new_tpl'] ?? '');
    if (!$tpl) $tpl = self::default_settings()['notify']['telegram_new_tpl'];
    $msg = $this->render_tpl($tpl, $ctx);
    return $this->telegram_send($msg);
}

private function send_email_new_tx($ctx) {
    $s = $this->settings();
    $n = $s['notify'] ?? [];
    if (empty($n['email_enabled'])) return false;

    $subj_tpl = (string)($n['email_new_subject_tpl'] ?? '');
    $body_tpl = (string)($n['email_new_body_tpl'] ?? '');
    if (!$subj_tpl) $subj_tpl = self::default_settings()['notify']['email_new_subject_tpl'];
    if (!$body_tpl) $body_tpl = self::default_settings()['notify']['email_new_body_tpl'];

    $subject = $this->render_tpl($subj_tpl, $ctx);
    $body = $this->render_tpl($body_tpl, $ctx);

    $mode = (string)($n['email_new_to_mode'] ?? 'settings');
    $tos = [];

    if (($mode === 'settings' || $mode === 'both') && !empty($n['email_to'])) {
        $tos[] = trim((string)$n['email_to']);
    }
    if ($mode === 'user' || $mode === 'both') {
        $user_email = $this->resolve_user_email_from_ctx($ctx);
        if ($user_email) $tos[] = $user_email;
    }

    $tos = array_values(array_unique(array_filter($tos)));
    if (empty($tos)) return false;

    $headers = $this->build_email_headers();
    $ok = (bool) wp_mail($tos, $subject, $body, $headers);

    if ($ok) {
        $this->log_event('notify_ok', 'email', null, ['to' => $tos, 'subject' => $subject, 'kind' => 'new_tx']);
    } else {
        $this->log_event('notify_fail', 'email', null, ['to' => $tos, 'subject' => $subject, 'kind' => 'new_tx']);
    }
    return $ok;
}




    
    private function send_telegram_reminder($ctx) {
        $s = $this->settings();
        $tpl = (string)($s['notify']['reminder_telegram_tpl'] ?? '');
        if (!$tpl) $tpl = self::default_settings()['notify']['reminder_telegram_tpl'];
        $msg = $this->render_tpl($tpl, $ctx);
        return $this->telegram_send($msg);
    }

    private function send_whatsapp_reminder($ctx) {
        $s = $this->settings();
        $tpl = (string)($s['notify']['reminder_telegram_tpl'] ?? '');
        if (!$tpl) $tpl = self::default_settings()['notify']['reminder_telegram_tpl'];
        $msg = $this->render_tpl($tpl, $ctx);
        // WhatsApp: plain text, strip HTML
        $plain = wp_strip_all_tags($msg);
        return $this->whatsapp_webhook_send([
            'type' => 'payment_reminder',
            'text' => $plain,
            'context' => $ctx,
        ]);
    }

    private function send_email_reminder($ctx) {
        $s = $this->settings();
        $subj_tpl = (string)($s['notify']['reminder_email_subject_tpl'] ?? '');
        $body_tpl = (string)($s['notify']['reminder_email_body_tpl'] ?? '');
        if (!$subj_tpl) $subj_tpl = self::default_settings()['notify']['reminder_email_subject_tpl'];
        if (!$body_tpl) $body_tpl = self::default_settings()['notify']['reminder_email_body_tpl'];
        $subject = $this->render_tpl($subj_tpl, $ctx);
        $body = $this->render_tpl($body_tpl, $ctx);
        return $this->email_send($subject, $body);
    }
}
