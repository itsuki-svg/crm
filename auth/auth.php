<?php
// ============================================================
//  auth.php — 認証・セッション管理
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';

class Auth
{
    // --------------------------------------------------------
    //  ログイン処理
    // --------------------------------------------------------
    public static function login(string $employeeCode, string $password): array
    {
        $db = DB::staff();

        // 社員取得
        $stmt = $db->prepare('
            SELECT e.id, e.employee_code, e.last_name, e.first_name,
                   e.email, e.role, e.is_active, e.department_id,
                   a.password_hash, a.login_failure_count, a.locked_until
            FROM employees e
            JOIN auth a ON a.employee_id = e.id
            WHERE e.employee_code = ? AND e.is_active = 1
            LIMIT 1
        ');
        // ※ employees に is_deleted を追加している前提
        // なければ: WHERE e.employee_code = ? AND e.is_active = 1
        $stmt->execute([$employeeCode]);
        $emp = $stmt->fetch();

        if (!$emp) {
            return ['ok' => false, 'error' => '社員番号またはパスワードが正しくありません'];
        }

        // 在籍チェック
        if (!$emp['is_active']) {
            return ['ok' => false, 'error' => 'このアカウントは無効です'];
        }

        // ロックチェック
        if ($emp['locked_until'] && new DateTime() < new DateTime($emp['locked_until'])) {
            $until = (new DateTime($emp['locked_until']))->format('H:i');
            return ['ok' => false, 'error' => "アカウントがロックされています。{$until} 以降に再試行してください"];
        }

        // パスワード照合
        if (!password_verify($password, $emp['password_hash'])) {
            self::recordFailure($db, $emp['id'], $emp['login_failure_count']);
            return ['ok' => false, 'error' => '社員番号またはパスワードが正しくありません'];
        }

        // ログイン成功 → セッション発行
        self::resetFailure($db, $emp['id']);
        self::startSession($emp);

        return ['ok' => true, 'employee' => $emp];
    }

    // --------------------------------------------------------
    //  セッション開始（ログイン成功時）
    // --------------------------------------------------------
    private static function startSession(array $emp): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // セッションID再生成（セッション固定攻撃対策）
        session_regenerate_id(true);

        $csrfToken = bin2hex(random_bytes(32));

        $_SESSION['employee_id']   = $emp['id'];
        $_SESSION['employee_code'] = $emp['employee_code'];
        $_SESSION['name']          = $emp['last_name'] . ' ' . $emp['first_name'];
        $_SESSION['email']         = $emp['email'];
        $_SESSION['role']          = $emp['role'];
        $_SESSION['csrf_token']    = $csrfToken;
        $_SESSION['login_at']      = time();
        $_SESSION['last_active']   = time();

        // DBセッション記録（一時的に無効化）

        // 最終ログイン更新
        try {
            $db2 = DB::staff();
            $db2->prepare('UPDATE auth SET last_login_at = NOW(), last_login_ip = ? WHERE employee_id = ?')
                ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $emp['id']]);
        } catch (Exception $e) {}
    }

    // --------------------------------------------------------
    //  認証チェック（各ページ冒頭で呼ぶ）
    // --------------------------------------------------------
    public static function check(string $requiredRole = 'general'): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // セッションなし → ログインページへ
        if (empty($_SESSION['employee_id'])) {
            self::redirectLogin();
        }

        // セッションタイムアウトチェック
        $lifetime = SESSION_LIFETIME_DAYS * 86400;
        if (time() - ($_SESSION['last_active'] ?? 0) > $lifetime) {
            self::logout();
            self::redirectLogin('セッションが期限切れです。再度ログインしてください。');
        }

        // DBセッション照合（一時的に無効化）
        // session_id()とDBのsession_idの不一致問題のため無効化中

        // 権限チェック
        $roles = ['general' => 1, 'manager' => 2, 'executive' => 3, 'admin' => 4];
        $userLevel     = $roles[$_SESSION['role']]          ?? 0;
        $requiredLevel = $roles[$requiredRole]               ?? 0;
        if ($userLevel < $requiredLevel) {
            http_response_code(403);
            require_once dirname(__DIR__) . '/pages/403.php';
            render403($requiredRole);
            exit;
        }

        // 最終アクティブ更新
        $_SESSION['last_active'] = time();
    }

    // --------------------------------------------------------
    //  ログアウト
    // --------------------------------------------------------
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        // DBセッション削除
        if (!empty($_SESSION['employee_id'])) {
            try {
                DB::staff()->prepare('DELETE FROM sessions WHERE session_id = ?')
                    ->execute([session_id()]);
            } catch (Exception $e) {
                // 無視
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // --------------------------------------------------------
    //  CSRFトークン検証
    // --------------------------------------------------------
    public static function verifyCsrf(string $token): bool
    {
        return isset($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }

    // --------------------------------------------------------
    //  現在のログインユーザー情報取得
    // --------------------------------------------------------
    public static function user(): array
    {
        return [
            'id'   => $_SESSION['employee_id']   ?? 0,
            'code' => $_SESSION['employee_code'] ?? '',
            'name' => $_SESSION['name']          ?? '',
            'email'=> $_SESSION['email']         ?? '',
            'role' => $_SESSION['role']          ?? 'general',
        ];
    }

    public static function isAdmin(): bool
    {
        return ($_SESSION['role'] ?? '') === 'admin';
    }

    public static function isManager(): bool
    {
        return in_array($_SESSION['role'] ?? '', ['admin', 'executive', 'manager'], true);
    }

    // --------------------------------------------------------
    //  ログイン失敗カウント
    // --------------------------------------------------------
    private static function recordFailure(PDO $db, int $empId, int $current): void
    {
        $newCount = $current + 1;
        $lock = null;

        if ($newCount >= LOGIN_MAX_FAILURES) {
            $lock = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            $newCount = 0;
        }

        $db->prepare('
            UPDATE auth SET login_failure_count = ?, locked_until = ?
            WHERE employee_id = ?
        ')->execute([$newCount, $lock, $empId]);
    }

    private static function resetFailure(PDO $db, int $empId): void
    {
        $db->prepare('
            UPDATE auth SET login_failure_count = 0, locked_until = NULL
            WHERE employee_id = ?
        ')->execute([$empId]);
    }

    // --------------------------------------------------------
    //  リダイレクト
    // --------------------------------------------------------
    private static function redirectLogin(string $msg = ''): never
    {
        $url = APP_URL . '/login';
        if ($msg) {
            $url .= '?msg=' . urlencode($msg);
        }
        header('Location: ' . $url);
        exit;
    }
}