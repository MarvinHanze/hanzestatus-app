<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../../core/api_auth.php';

header('Content-Type: application/json');
$workspace = requireApiToken();
$wsId = (int) $workspace['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Methode niet toegestaan.'], 405);
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 25)));
$offset = ($page - 1) * $perPage;
$statusFilter = (string) ($_GET['status'] ?? '');
$validStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];

$where = ['i.workspace_id = ?'];
$params = [$wsId];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) FROM hst_incidents i WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT i.id, i.title, i.status, i.impact, i.created_at, i.resolved_at, m.name AS monitor_name
     FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id
     WHERE $whereSql ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

foreach ($incidents as &$inc) {
    $updatesStmt = db()->prepare('SELECT status, body, created_at FROM hst_incident_updates WHERE incident_id = ? ORDER BY created_at ASC');
    $updatesStmt->execute([$inc['id']]);
    $inc['id'] = (int) $inc['id'];
    $inc['updates'] = $updatesStmt->fetchAll();
}
unset($inc);

jsonResponse(['data' => $incidents, 'page' => $page, 'per_page' => $perPage, 'total' => $total]);
