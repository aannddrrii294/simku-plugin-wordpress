<?php
/**
 * Google Drive integration for Receipt storage (private).
 *
 * Uses Service Account JSON (server-to-server) to upload receipt images into a
 * specific Drive folder, and streams them back through a nonce-protected proxy
 * endpoint in WP Admin (admin-post.php?action=simku_receipt_media).
 */
if (!defined('ABSPATH')) { exit; }

trait SIMKU_Trait_GDrive {

    /* -------------------- Settings -------------------- */

    private function receipts_settings() {
        $s = $this->settings();
        $r = $s['receipts'] ?? [];
        if (!is_array($r)) $r = [];
        return $r;
    }

    private function receipts_storage_mode() {
        $r = $this->receipts_settings();
        $m = strtolower(trim((string)($r['storage'] ?? 'uploads')));
        if (!in_array($m, ['uploads','gdrive'], true)) $m = 'uploads';
        return $m;
    }

    private function receipts_max_upload_bytes() {
        $r = $this->receipts_settings();
        $mb = (int)($r['max_upload_mb'] ?? 8);
        if ($mb < 1) $mb = 1;
        if ($mb > 50) $mb = 50;
        return $mb * 1024 * 1024;
    }

    private function receipts_delete_local_after_upload() {
        $r = $this->receipts_settings();
        return !empty($r['delete_local_after_upload']);
    }

    private function gdrive_folder_id() {
        $r = $this->receipts_settings();
        return trim((string)($r['gdrive_folder_id'] ?? ''));
    }

    private function gdrive_service_account_json() {
        $r = $this->receipts_settings();
        return trim((string)($r['gdrive_service_account_json'] ?? ''));
    }

    private function gdrive_is_configured() {
        return ($this->gdrive_folder_id() !== '' && $this->gdrive_service_account_json() !== '');
    }

    /* -------------------- JWT + Access Token -------------------- */

    private function gdrive_b64url($data) {
        $b = base64_encode((string)$data);
        $b = str_replace(['+','/','='], ['-','_',''], $b);
        return $b;
    }

    private function gdrive_get_access_token() {
        // Cache token in transient to avoid frequent signing.
        $cache_key = 'simku_gdrive_token_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['access_token']) && !empty($cached['exp']) && time() < ((int)$cached['exp'] - 60)) {
            return (string)$cached['access_token'];
        }

        $json = $this->gdrive_service_account_json();
        $sa = json_decode($json, true);
        if (!is_array($sa)) return '';

        $client_email = trim((string)($sa['client_email'] ?? ''));
        $private_key = (string)($sa['private_key'] ?? '');
        $token_uri = trim((string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'));

        if ($client_email === '' || $private_key === '' || $token_uri === '') return '';

        $now = time();
        $exp = $now + 3600;

        $header = $this->gdrive_b64url(wp_json_encode(['alg'=>'RS256','typ'=>'JWT']));
        $payload = $this->gdrive_b64url(wp_json_encode([
            'iss' => $client_email,
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => $token_uri,
            'iat' => $now,
            'exp' => $exp,
        ]));

        $to_sign = $header . '.' . $payload;

        $signature = '';
        $ok = openssl_sign($to_sign, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$ok) return '';

        $jwt = $to_sign . '.' . $this->gdrive_b64url($signature);

        $resp = wp_safe_remote_post($token_uri, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        if (is_wp_error($resp)) return '';
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return '';

        $body = json_decode((string)wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || empty($body['access_token'])) return '';

        $token = (string)$body['access_token'];
        $expires_in = (int)($body['expires_in'] ?? 3600);
        if ($expires_in < 300) $expires_in = 300;

        set_transient($cache_key, ['access_token'=>$token,'exp'=>time()+$expires_in], $expires_in);

        return $token;
    }

    /* -------------------- Drive API -------------------- */

    private function gdrive_upload_file($local_path, $filename, $mime_type = '') {
        if (!$this->gdrive_is_configured()) return ['ok'=>false, 'id'=>'', 'view'=>'', 'mime'=>'', 'error'=>'Google Drive not configured.'];
        $token = $this->gdrive_get_access_token();
        if ($token === '') return ['ok'=>false, 'id'=>'', 'view'=>'', 'mime'=>'', 'error'=>'Failed to obtain Google access token.'];

        $folder_id = $this->gdrive_folder_id();
        $filename = $filename ?: ('receipt-' . wp_date('Ymd-His') . '.jpg');

        if (!file_exists($local_path)) return ['ok'=>false, 'id'=>'', 'view'=>'', 'mime'=>'', 'error'=>'Local file not found.'];

        $content = file_get_contents($local_path);
        if ($content === false) return ['ok'=>false, 'id'=>'', 'view'=>'', 'mime'=>'', 'error'=>'Failed to read local file.'];

        $meta = ['name' => $filename, 'parents' => [$folder_id]];
        $boundary = 'simkuDrive' . wp_generate_password(12, false, false);

        $mime_type = $mime_type ?: 'application/octet-stream';

        $body = '';
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode($meta) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mime_type}\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,mimeType,webViewLink';

        $resp = wp_safe_remote_post($url, [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'multipart/related; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($resp)) {
            return ['ok'=>false,'id'=>'','view'=>'','mime'=>'','error'=>$resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false,'id'=>'','view'=>'','mime'=>'','error'=>'Drive upload failed (HTTP '.$code.'): '.wp_remote_retrieve_body($resp)];
        }

        $out = json_decode((string)wp_remote_retrieve_body($resp), true);
        if (!is_array($out) || empty($out['id'])) {
            return ['ok'=>false,'id'=>'','view'=>'','mime'=>'','error'=>'Drive upload failed: invalid response.'];
        }

        return [
            'ok' => true,
            'id' => (string)$out['id'],
            'view' => (string)($out['webViewLink'] ?? ''),
            'mime' => (string)($out['mimeType'] ?? $mime_type),
            'error' => '',
        ];
    }

    private function gdrive_download_file($file_id) {
        if (!$this->gdrive_is_configured()) return ['ok'=>false, 'body'=>'', 'mime'=>'', 'error'=>'Google Drive not configured.'];
        $token = $this->gdrive_get_access_token();
        if ($token === '') return ['ok'=>false, 'body'=>'', 'mime'=>'', 'error'=>'Failed to obtain Google access token.'];

        $file_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$file_id);
        if ($file_id === '') return ['ok'=>false, 'body'=>'', 'mime'=>'', 'error'=>'Invalid file id.'];

        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media';

        $resp = wp_safe_remote_get($url, [
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($resp)) return ['ok'=>false, 'body'=>'', 'mime'=>'', 'error'=>$resp->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            return ['ok'=>false, 'body'=>'', 'mime'=>'', 'error'=>'Drive download failed (HTTP '.$code.').'];
        }

        $mime = (string) wp_remote_retrieve_header($resp, 'content-type');
        $body = (string) wp_remote_retrieve_body($resp);

        return ['ok'=>true,'body'=>$body,'mime'=>$mime,'error'=>''];
    }

    /* -------------------- Proxy URL + Handler -------------------- */

    private function gdrive_proxy_url($file_id) {
        $file_id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$file_id);
        if ($file_id === '') return '';
        $url = admin_url('admin-post.php?action=simku_receipt_media&file_id=' . rawurlencode($file_id));
        return wp_nonce_url($url, 'simku_receipt_media:' . $file_id);
    }

    public function handle_admin_post_simku_receipt_media() {
        if (!is_user_logged_in() || !current_user_can(self::CAP_VIEW_TX)) {
            wp_die('Forbidden', 403);
        }

        $file_id = sanitize_text_field(wp_unslash($_GET['file_id'] ?? ''));
        $file_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $file_id);
        if ($file_id === '') wp_die('Bad request', 400);

        $nonce = (string)($_GET['_wpnonce'] ?? '');
        if (!wp_verify_nonce($nonce, 'simku_receipt_media:' . $file_id)) {
            wp_die('Forbidden', 403);
        }

        $dl = $this->gdrive_download_file($file_id);
        if (empty($dl['ok'])) {
            wp_die('Failed to load file', 500);
        }

        $mime = $dl['mime'] ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        // Cache privately for a short time.
        header('Cache-Control: private, max-age=300');
        echo $dl['body'];
        exit;
    }

    /* -------------------- Helpers for Transactions uploads -------------------- */

    private function handle_multi_receipt_upload_to_gdrive($field_name) {
        $out = ['ok'=>false,'items'=>[],'error'=>''];

        if (empty($_FILES[$field_name]) || empty($_FILES[$field_name]['name'])) {
            return ['ok'=>false,'items'=>[],'error'=>''];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $max_bytes = $this->receipts_max_upload_bytes();

        // Normalize to multiple
        $names = $_FILES[$field_name]['name'];
        $tmps  = $_FILES[$field_name]['tmp_name'];
        $errs  = $_FILES[$field_name]['error'];
        $sizes = $_FILES[$field_name]['size'];
        $types = $_FILES[$field_name]['type'];

        $is_multi = is_array($names);
        $count = $is_multi ? count($names) : 1;

        $items = [];
        for ($i=0; $i<$count; $i++) {
            $name = $is_multi ? (string)$names[$i] : (string)$names;
            $tmp  = $is_multi ? (string)$tmps[$i] : (string)$tmps;
            $err  = $is_multi ? (int)$errs[$i] : (int)$errs;
            $size = $is_multi ? (int)$sizes[$i] : (int)$sizes;
            $type = $is_multi ? (string)$types[$i] : (string)$types;

            if ($tmp === '') continue;
            if ($err) {
                $out['error'] = 'Upload failed (error code: '.$err.')';
                continue;
            }
            if ($size > $max_bytes) {
                $out['error'] = 'File too large. Max '.(int)($max_bytes/1024/1024).'MB.';
                continue;
            }

            // First: store to uploads folder using wp_handle_sideload (safe file name)
            $file = ['name'=>$name,'tmp_name'=>$tmp,'error'=>$err,'size'=>$size,'type'=>$type];

            $allowed = [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'gif'          => 'image/gif',
                'webp'         => 'image/webp',
            ];
            $check = wp_check_filetype_and_ext($tmp, $name, $allowed);
            if (empty($check['type'])) {
                $out['error'] = 'Invalid file type. Allowed: JPG, PNG, GIF, WebP.';
                continue;
            }

            $overrides = ['test_form'=>false,'mimes'=>$allowed];
            $upload = wp_handle_upload($file, $overrides);
            if (empty($upload) || !empty($upload['error']) || empty($upload['file'])) {
                $out['error'] = is_array($upload) ? (string)($upload['error'] ?? 'Upload failed') : 'Upload failed';
                continue;
            }

            $local_path = (string)$upload['file'];
            $mime = (string)($upload['type'] ?? $check['type']);
            $res = $this->gdrive_upload_file($local_path, wp_basename($local_path), $mime);
            if (empty($res['ok'])) {
                $out['error'] = (string)($res['error'] ?? 'Drive upload failed');
                // best-effort cleanup
                @unlink($local_path);
                continue;
            }

            if ($this->receipts_delete_local_after_upload()) {
                @unlink($local_path);
            }

            $items[] = [
                'id' => (string)$res['id'],
                'view' => (string)$res['view'],
                'mime' => (string)$res['mime'],
            ];
        }

        if (!empty($items)) {
            return ['ok'=>true,'items'=>$items,'error'=>''];
        }
        return $out;
    }
}
