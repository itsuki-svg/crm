<?php
// ============================================================
//  api/todo_download.php — TODO添付ファイル ダウンロード
// ============================================================
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';

Auth::check();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('IDが必要です'); }

$stmt = DB::crm()->prepare('
    SELECT ta.*, t.id AS todo_id
    FROM todo_attachments ta
    JOIN todos t ON t.id = ta.todo_id
    WHERE ta.id = ? AND t.is_deleted = 0
');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) { http_response_code(404); exit('ファイルが見つかりません'); }
if (!file_exists($file['file_path'])) { http_response_code(404); exit('ファイルが存在しません'); }

// ファイル送信
$mime = $file['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . rawurlencode($file['original_name']) . '"');
header('Content-Length: ' . filesize($file['file_path']));
header('Cache-Control: private, no-cache');
readfile($file['file_path']);
exit;
