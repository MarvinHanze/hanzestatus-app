<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    db()->prepare('UPDATE hst_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$user['id']]);
    header('Location: ' . BASE . '/notifications.php');
    exit;
}

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 20;
$countStmt = db()->prepare('SELECT COUNT(*) FROM hst_notifications WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT * FROM hst_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

renderAdminStart('notifications', 'Notificaties');
?>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem;">
    <form method="post"><?= csrfField() ?><button type="submit" class="hs-btn hs-btn--secondary hs-btn--sm">Alles markeren als gelezen</button></form>
</div>

<?php if (!$notifications): ?>
    <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('bell') ?></div>Geen notificaties.</div>
<?php else: ?>
    <div class="hs-card" style="padding:0;">
        <?php foreach ($notifications as $n): ?>
            <a href="<?= e($n['link']) ?>" class="hs-notif-item <?= $n['read_at'] ? '' : 'hs-is-unread' ?>" style="border-bottom:1px solid var(--hs-border);">
                <?php if (!$n['read_at']): ?><span class="hs-notif-dot"></span><?php endif; ?>
                <div>
                    <strong style="display:block;"><?= e($n['title']) ?></strong>
                    <?= e($n['body']) ?>
                    <div style="color:var(--hs-text-muted);font-size:.75rem;margin-top:.2rem;"><?= timeAgo($n['created_at']) ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
    <?= renderPagination($page, $totalPages, 'notifications.php') ?>
<?php endif; ?>
<?php renderAdminEnd(); ?>
