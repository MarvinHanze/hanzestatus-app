<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../core/api_auth.php';

header('Content-Type: application/json');
$workspace = requireApiToken();
$wsId = (int) $workspace['id'];

refreshMonitorSimulation($wsId);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode niet toegestaan.'], 405);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare('SELECT COUNT(*) FROM hst_monitors WHERE workspace_id = ?');
$countStmt->execute([$wsId]);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT id, name, url, type, check_interval_seconds, current_status, created_at
     FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$wsId]);
$monitors = $stmt->fetchAll();

$data = array_map(static function (array $m): array {
    return [
        'id' => (int) $m['id'],
        'name' => $m['name'],
        'url' => $m['url'],
        'type' => $m['type'],
        'check_interval_seconds' => (int) $m['check_interval_seconds'],
        'status' => $m['current_status'],
        'uptime_percent_90d' => monitorUptimePercent((int) $m['id'], 90),
        'created_at' => $m['created_at'],
    ];
}, $monitors);

jsonResponse(['data' => $data, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
