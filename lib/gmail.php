<?php
// ============================================================
//  lib/gmail.php — Gmail API 連携ライブラリ
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';

class Gmail
{
    private const API_BASE    = 'https://www.googleapis.com/gmail/v1/users/me';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';

    // --------------------------------------------------------
    //  OAuth2 認証URLを生成
    // --------------------------------------------------------
    public static function getAuthUrl(int $employeeId): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_oauth_state']    = $state;
        $_SESSION['google_oauth_employee'] = $employeeId;

        $params = http_build_query([
            'client_id'     => GOOGLE_CLIENT_ID,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'response_type' => 'code',
            'scope'         => implode(' ', GOOGLE_SCOPES),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);

        return self::AUTH_URL . '?' . $params;
    }

    // --------------------------------------------------------
    //  コールバック処理（認証コード → トークン取得 → DB保存）
    // --------------------------------------------------------
    public static function handleCallback(string $code, string $state): bool
    {
        if ($state !== ($_SESSION['google_oauth_state'] ?? '')) {
            return false;
        }

        $empId = (int)($_SESSION['google_oauth_employee'] ?? 0);
        if (!$empId) return false;

        $token = self::exchangeCode($code);
        if (!$token) return false;

        // Gmailアドレス取得
        $profile = self::apiGet('/profile', $token['access_token']);
        $gmailAddress = $profile['emailAddress'] ?? '';

        if (!$gmailAddress) return false;

        // DB保存（トークンを暗号化）
        DB::staff()->prepare('
            INSERT INTO gmail_tokens
                (employee_id, gmail_address, access_token, refresh_token, token_expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 3600 SECOND))
            ON DUPLICATE KEY UPDATE
                gmail_address    = VALUES(gmail_address),
                access_token     = VALUES(access_token),
                refresh_token    = VALUES(refresh_token),
                token_expires_at = VALUES(token_expires_at),
                is_active        = 1,
                linked_at        = NOW()
        ')->execute([
            $empId,
            $gmailAddress,
            Crypto::encrypt($token['access_token']),
            Crypto::encrypt($token['refresh_token'] ?? ''),
        ]);

        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_employee']);
        return true;
    }

    // --------------------------------------------------------
    //  アクセストークン取得（期限切れ時は自動リフレッシュ）
    // --------------------------------------------------------
    public static function getToken(int $employeeId): ?string
    {
        $db   = DB::staff();
        $stmt = $db->prepare('
            SELECT access_token, refresh_token, token_expires_at
            FROM gmail_tokens
            WHERE employee_id = ? AND is_active = 1
        ');
        $stmt->execute([$employeeId]);
        $row = $stmt->fetch();

        if (!$row) return null;

        // 有効期限チェック（60秒前にリフレッシュ）
        if (strtotime($row['token_expires_at']) - 60 < time()) {
            $newToken = self::refreshToken(Crypto::decrypt($row['refresh_token']));
            if (!$newToken) return null;

            $db->prepare('
                UPDATE gmail_tokens
                SET access_token     = ?,
                    token_expires_at = DATE_ADD(NOW(), INTERVAL 3600 SECOND)
                WHERE employee_id = ?
            ')->execute([Crypto::encrypt($newToken), $employeeId]);

            return $newToken;
        }

        return Crypto::decrypt($row['access_token']);
    }

    // --------------------------------------------------------
    //  受信メール同期（登録顧客のみCRMに記録）
    // --------------------------------------------------------
    public static function syncInbox(int $employeeId, int $maxResults = 50): array
    {
        $token = self::getToken($employeeId);
        if (!$token) return ['ok' => false, 'error' => 'Gmail未連携'];

        // メール一覧取得
        $list = self::apiGet("/messages?maxResults={$maxResults}&labelIds=INBOX", $token);
        if (empty($list['messages'])) return ['ok' => true, 'synced' => 0];

        $db       = DB::crm();
        $staffDb  = DB::staff();
        $synced   = 0;

        // 登録済み顧客メールアドレス一覧を取得（マッチング用）
        $custEmails = $db->query('SELECT id, email FROM customers WHERE is_deleted = 0')
                         ->fetchAll(PDO::FETCH_KEY_PAIR); // email => id

        // 未登録通知済みアドレス（重複通知抑制）
        $suppressedStmt = $staffDb->query("
            SELECT from_address FROM unknown_email_notices
            WHERE status = 'ignored'
               OR (status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY))
        ");
        $suppressed = array_column($suppressedStmt->fetchAll(), 'from_address');

        foreach ($list['messages'] as $msg) {
            $msgId = $msg['id'];

            // 取得済みチェック
            $exists = $db->prepare('SELECT id FROM email_histories WHERE gmail_message_id = ?');
            $exists->execute([$msgId]);
            if ($exists->fetch()) continue;

            // メール詳細取得
            $detail = self::apiGet("/messages/{$msgId}?format=full", $token);
            if (!$detail) continue;

            $headers  = self::parseHeaders($detail['payload']['headers'] ?? []);
            $from     = self::extractEmail($headers['From'] ?? '');
            $subject  = $headers['Subject'] ?? '';
            $sentAt   = date('Y-m-d H:i:s', ($detail['internalDate'] ?? time()) / 1000);
            $body     = self::extractBody($detail['payload']);

            // 顧客マッチング
            $customerId = self::matchCustomer($from, $custEmails);

            if ($customerId) {
                // 登録顧客 → CRMに記録
                $db->prepare('
                    INSERT IGNORE INTO email_histories
                        (customer_id, employee_id, gmail_message_id, gmail_thread_id,
                         direction, from_address, to_address, subject, body_plain, sent_at)
                    VALUES (?, ?, ?, ?, \'inbound\', ?, ?, ?, ?, ?)
                ')->execute([
                    $customerId,
                    $employeeId,
                    $msgId,
                    $detail['threadId'] ?? '',
                    $from,
                    $headers['To']  ?? '',
                    $subject,
                    $body,
                    $sentAt,
                ]);
                $synced++;
            } elseif (!in_array($from, $suppressed, true)) {
                // 未登録アドレス → 通知テーブルへ
                $staffDb->prepare('
                    INSERT INTO unknown_email_notices
                        (from_address, from_name, subject, body_preview, gmail_message_id, received_at, employee_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    $from,
                    self::extractName($headers['From'] ?? ''),
                    $subject,
                    mb_substr($body, 0, 500),
                    $msgId,
                    $sentAt,
                    $employeeId,
                ]);

                // 管理者へGmail通知
                self::notifyAdminUnknown($from, $subject, $sentAt);
            }
        }

        return ['ok' => true, 'synced' => $synced];
    }

    // --------------------------------------------------------
    //  メール送信
    // --------------------------------------------------------
    public static function sendMail(
        int $employeeId,
        string $to,
        string $subject,
        string $body,
        ?int $customerId = null
    ): bool {
        $token = self::getToken($employeeId);
        if (!$token) return false;

        // MIME形式のメールを作成
        $rawMessage = self::buildRawMessage($to, $subject, $body);
        $encoded    = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $result = self::apiPost('/messages/send', $token, ['raw' => $encoded]);
        if (!isset($result['id'])) return false;

        // CRMにメール送信履歴を記録
        if ($customerId) {
            DB::crm()->prepare('
                INSERT INTO email_histories
                    (customer_id, employee_id, gmail_message_id, gmail_thread_id,
                     direction, from_address, to_address, subject, body_plain, sent_at)
                VALUES (?, ?, ?, ?, \'outbound\', ?, ?, ?, ?, NOW())
            ')->execute([
                $customerId,
                $employeeId,
                $result['id'],
                $result['threadId'] ?? '',
                Auth::user()['email'],
                $to,
                $subject,
                $body,
            ]);
        }

        return true;
    }

    // --------------------------------------------------------
    //  管理者への未登録通知メール送信
    // --------------------------------------------------------
    private static function notifyAdminUnknown(string $from, string $subject, string $receivedAt): void
    {
        // 設定から通知先管理者を取得
        $stmt = DB::staff()->query("
            SELECT value FROM system_settings
            WHERE setting_key = 'unknown_mail_notify_admins'
        ");
        $adminIds = array_filter(explode(',', $stmt->fetchColumn() ?? ''));

        foreach ($adminIds as $adminId) {
            $adminId = (int)trim($adminId);
            $token   = self::getToken($adminId);
            if (!$token) continue;

            $adminEmail = DB::staff()
                ->prepare('SELECT email FROM employees WHERE id = ?')
                ->execute([$adminId]) ? null : null;

            // 管理者自身のGmailに通知を送信
            $notifySubject = "[社内CRM] 未登録アドレスからメールが届いています";
            $notifyBody    = implode("\n", [
                "以下の未登録アドレスからメールが届きました。",
                "",
                "差出人: {$from}",
                "件名:   {$subject}",
                "受信日: {$receivedAt}",
                "",
                "以下のURLから対応してください:",
                APP_URL . "/pages/unknown_notices.php",
            ]);

            // 管理者自身に送信（自分→自分）
            $rawMsg = self::buildRawMessage('me', $notifySubject, $notifyBody);
            $encoded = rtrim(strtr(base64_encode($rawMsg), '+/', '-_'), '=');
            self::apiPost('/messages/send', $token, ['raw' => $encoded]);
        }
    }

    // --------------------------------------------------------
    //  プライベートヘルパー
    // --------------------------------------------------------
    private static function matchCustomer(string $fromEmail, array $custEmails): ?int
    {
        $email = strtolower($fromEmail);
        foreach ($custEmails as $custEmail => $custId) {
            if (strtolower($custEmail) === $email) return (int)$custId;
        }
        return null;
    }

    private static function parseHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $h) {
            $result[$h['name']] = $h['value'];
        }
        return $result;
    }

    private static function extractEmail(string $from): string
    {
        if (preg_match('/<(.+?)>/', $from, $m)) return strtolower(trim($m[1]));
        return strtolower(trim($from));
    }

    private static function extractName(string $from): string
    {
        if (preg_match('/^(.+?)\s*</', $from, $m)) return trim($m[1], ' "\'');
        return '';
    }

    private static function extractBody(array $payload): string
    {
        // マルチパートの場合は text/plain を優先
        if (!empty($payload['parts'])) {
            foreach ($payload['parts'] as $part) {
                if ($part['mimeType'] === 'text/plain') {
                    return base64_decode(strtr($part['body']['data'] ?? '', '-_', '+/'));
                }
            }
        }
        return base64_decode(strtr($payload['body']['data'] ?? '', '-_', '+/'));
    }

    private static function buildRawMessage(string $to, string $subject, string $body): string
    {
        return implode("\r\n", [
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode($body),
        ]);
    }

    private static function exchangeCode(string $code): ?array
    {
        $response = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => GOOGLE_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
        ]);
        return isset($response['access_token']) ? $response : null;
    }

    private static function refreshToken(string $refreshToken): ?string
    {
        $response = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'grant_type'    => 'refresh_token',
        ]);
        return $response['access_token'] ?? null;
    }

    private static function apiGet(string $endpoint, string $token): ?array
    {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    private static function apiPost(string $endpoint, string $token, array $data): ?array
    {
        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }

    private static function httpPost(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode($res, true);
    }
}