<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
requireAuth();
$user = currentUser();
$action = (string) ($_GET['actie'] ?? '');

if ($action === 'lijst') {
    $stmt = db()->prepare('SELECT * FROM hst_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15');
    $stmt->execute([$user['id']]);
    $notifications = array_map(static function (array $n): array {
        return [
            'id' => (int) $n['id'],
            'title' => htmlspecialchars($n['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'body' => htmlspecialchars($n['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'link' => $n['link'],
            'read_at' => $n['read_at'],
            'time_ago' => timeAgo($n['created_at']),
        ];
    }, $stmt->fetchAll());
    jsonResponse(['notifications' => $notifications]);
} elseif ($action === 'lees_alles' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        jsonResponse(['error' => 'Ongeldig verzoek.'], 403);
    }
    db()->prepare('UPDATE hst_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$user['id']]);
    jsonResponse(['success' => true]);
} else {
    jsonResponse(['error' => 'Onbekende actie.'], 400);
}
