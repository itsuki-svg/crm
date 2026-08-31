<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/auth/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
Auth::check('general');
if ($_SERVER['REQUEST_METHOD'] === 'POST') verifyCsrf();
$action = $_GET['action'] ?? '';
if ($action === 'get') {
    $db = DB::crm();
    $db->exec("SET NAMES utf8mb4");
    $stmt = $db->query('SELECT * FROM pipelines WHERE is_default = 1 LIMIT 1');
    $pipeline = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($pipeline) {
        $stages = json_decode($pipeline['stages'], true);
        jsonResponse(['ok' => true, 'stages' => $stages, 'pipeline' => $pipeline]);
    } else {
        jsonResponse(['ok' => false, 'error' => 'パイプラインが見つかりません']);
    }
} else {
    jsonResponse(['ok' => false, 'error' => 'Invalid action'], 400);
}
