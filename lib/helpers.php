<?php
// ============================================================
//  lib/helpers.php — 共通ヘルパー関数
// ============================================================

/**
 * XSS対策 出力エスケープ
 */
function h(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
}

/**
 * JSONレスポンス送信
 */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * CSRF検証（POST時に必ず呼ぶ）
 */
function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!Auth::verifyCsrf($token)) {
        jsonResponse(['ok' => false, 'error' => '不正なリクエストです'], 403);
    }
}

/**
 * ページネーション計算
 */
function paginate(int $total, int $perPage, int $page): array
{
    $totalPages = (int)ceil($total / $perPage);
    $page       = max(1, min($page, $totalPages ?: 1));
    $offset     = ($page - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $page,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

/**
 * 日付フォーマット
 */
function fmtDate(?string $dt, string $format = 'Y/m/d'): string
{
    if (!$dt) return '—';
    return (new DateTime($dt))->format($format);
}

function fmtDatetime(?string $dt): string
{
    return fmtDate($dt, 'Y/m/d H:i');
}

/**
 * 金額フォーマット
 */
function fmtMoney(float $amount): string
{
    return '¥' . number_format($amount);
}

/**
 * ステータスラベル
 */
function customerStatusLabel(string $status): array
{
    return match($status) {
        'prospect'    => ['label' => '見込み',   'class' => 'bg-amber'],
        'negotiating' => ['label' => '商談中',   'class' => 'bg-blue'],
        'contracted'  => ['label' => '契約中',   'class' => 'bg-green'],
        'pending'     => ['label' => '保留',     'class' => 'bg-gray'],
        'lost'        => ['label' => '失注',     'class' => 'bg-red'],
        default       => ['label' => $status,   'class' => 'bg-gray'],
    };
}

function taskStatusLabel(string $status): array
{
    return match($status) {
        'todo'        => ['label' => '未着手',   'class' => 'bg-gray'],
        'in_progress' => ['label' => '進行中',   'class' => 'bg-blue'],
        'review'      => ['label' => '確認待ち', 'class' => 'bg-amber'],
        'done'        => ['label' => '完了',     'class' => 'bg-green'],
        default       => ['label' => $status,   'class' => 'bg-gray'],
    };
}

function priorityLabel(string $p): array
{
    return match($p) {
        'high'   => ['label' => '高', 'class' => 'bg-red'],
        'medium' => ['label' => '中', 'class' => 'bg-amber'],
        'low'    => ['label' => '低', 'class' => 'bg-green'],
        default  => ['label' => $p,  'class' => 'bg-gray'],
    };
}

function roleLabel(string $role): array
{
    return match($role) {
        'admin'     => ['label' => '管理者',       'class' => 'bg-red'],
        'executive' => ['label' => '幹部職',       'class' => 'bg-purple'],
        'manager'   => ['label' => 'マネージャー', 'class' => 'bg-amber'],
        'general'   => ['label' => '一般',         'class' => 'bg-blue'],
        default     => ['label' => $role,          'class' => 'bg-gray'],
    };
}

/**
 * ログ書き込み
 */
function writeLog(string $level, string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    file_put_contents(LOG_DIR . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
}

// ─── 権限チェックヘルパー ───────────────────────────────────────

function getRoleLevel(string $role): int
{
    return match ($role) {
        'admin'     => 4,
        'executive' => 3,
        'manager'   => 2,
        'general'   => 1,
        default     => 0,
    };
}

function requiredRole(string $required_role = 'general', bool $redirect = true): bool
{
    $current_role = $_SESSION['role'] ?? 'general';
    $ok = getRoleLevel($current_role) >= getRoleLevel($required_role);

    if (!$ok && $redirect) {
        require_once __DIR__ . '/../pages/403.php';
        render403($required_role);
        exit;
    }

    return $ok;
}