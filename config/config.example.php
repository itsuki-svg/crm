<?php
// ============================================================
//  config.php — システム設定・DB接続
// ============================================================

// ---- エラー表示（本番は false） ----
define('APP_DEBUG', false);

if (APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ---- タイムゾーン ----
date_default_timezone_set('Asia/Tokyo');

// ---- セッション設定 ----
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure',   0);
ini_set('session.use_strict_mode', 1);
ini_set('session.gc_maxlifetime',  60 * 60 * 24 * 7);

// ---- DB接続情報 ----
define('DB_HOST',     'localhost');
define('DB_PORT',     3306);
define('DB_CHARSET',  'utf8mb4');

define('STAFF_DB_NAME', 'staff_db');
define('STAFF_DB_USER', 'staff_user');
define('STAFF_DB_PASS', 'your_password_here');

define('CRM_DB_NAME',   'crm_db');
define('CRM_DB_USER',   'crm_user');
define('CRM_DB_PASS',   'your_password_here');

// ---- アプリ設定 ----
define('APP_NAME',    '社内CRM');
define('APP_URL',     'https://example.com/crm');
define('APP_ROOT',    dirname(__DIR__));
define('UPLOAD_DIR',  APP_ROOT . '/uploads/');
define('LOG_DIR',     APP_ROOT . '/logs/');

// ---- セキュリティ ----
define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_LOCK_MINUTES', 30);
define('SESSION_LIFETIME_DAYS', 7);

// ---- Gmail API ----
define('GOOGLE_CLIENT_ID', '123456789012-abcdefghijklmnopqrstuvwxyz012345.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', 'GOCSPX-abcdefghijklmnopqrstuvwxyz');
define('GOOGLE_REDIRECT_URI',  APP_URL . '/auth/google_callback.php');
define('GOOGLE_SCOPES', [
    'https://www.googleapis.com/auth/gmail.readonly',
    'https://www.googleapis.com/auth/gmail.send',
    'https://www.googleapis.com/auth/calendar',
]);

// ---- 暗号化キー ----
// openssl rand -hex 16 で生成してください
define('ENCRYPT_KEY', 'your_encryption_key_here');

// ============================================================
//  DB接続クラス（PDO シングルトン）
// ============================================================
class DB
{
    private static array $instances = [];

    public static function staff(): PDO
    {
        return self::get('staff');
    }

    public static function crm(): PDO
    {
        return self::get('crm');
    }

    private static function get(string $key): PDO
    {
        if (!isset(self::$instances[$key])) {
            [$dbname, $user, $pass] = $key === 'staff'
                ? [STAFF_DB_NAME, STAFF_DB_USER, STAFF_DB_PASS]
                : [CRM_DB_NAME,   CRM_DB_USER,   CRM_DB_PASS];

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, $dbname, DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_FOUND_ROWS   => true,
            ];

            try {
                self::$instances[$key] = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                self::dbError($e);
            }
        }

        return self::$instances[$key];
    }

    private static function dbError(PDOException $e): never
    {
        if (APP_DEBUG) {
            throw $e;
        }
        error_log('[CRM DB Error] ' . $e->getMessage());
        http_response_code(500);
        exit('データベース接続エラーが発生しました。管理者へご連絡ください。');
    }
}

// ============================================================
//  暗号化ユーティリティ
// ============================================================
class Crypto
{
    private const CIPHER = 'aes-256-cbc';

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($plain, self::CIPHER, ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    public static function decrypt(string $cipher): string
    {
        $data = base64_decode($cipher);
        $iv   = substr($data, 0, 16);
        $enc  = substr($data, 16);
        return (string) openssl_decrypt($enc, self::CIPHER, ENCRYPT_KEY, OPENSSL_RAW_DATA, $iv);
    }
}
