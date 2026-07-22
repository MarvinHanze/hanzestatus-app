<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

$maintenanceId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM hst_maintenance_windows WHERE id = ? AND workspace_id = ?');
$stmt->execute([$maintenanceId, $wsId]);
$window = $stmt->fetch();
if (!$window) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $act = $_POST['action'] ?? '';
    if ($act === 'delete') {
        db()->prepare('DELETE FROM hst_maintenance_windows WHERE id = ? AND workspace_id = ?')->execute([$maintenanceId, $wsId]);
        auditLog($wsId, (int) $user['id'], 'maintenance.delete', 'maintenance', $maintenanceId, "Onderhoud verwijderd: {$window['title']}");
        header('Location: ' . BASE . '/maintenance.php');
        exit;
    } elseif ($act === 'update') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if (mb_strlen($title) < 3 || mb_strlen($description) < 5) {
            $errors[] = 'Vul een geldige titel en omschrijving in.';
        } else {
            db()->prepare('UPDATE hst_maintenance_windows SET title = ?, description = ? WHERE id = ?')->execute([$title, $description, $maintenanceId]);
            $window['title'] = $title;
            $window['description'] = $description;
            auditLog($wsId, (int) $user['id'], 'maintenance.update', 'maintenance', $maintenanceId, "Onderhoud bijgewerkt: $title");
        }
    }
}

$monitorsStmt = db()->prepare(
    'SELECT m.name FROM hst_maintenance_monitors mm JOIN hst_monitors m ON m.id = mm.monitor_id WHERE mm.maintenance_id = ?'
);
$monitorsStmt->execute([$maintenanceId]);
$affectedMonitors = $monitorsStmt->fetchAll(PDO::FETCH_COLUMN);

renderAdminStart('maintenance', $window['title']);
?>
<a href="maintenance.php" style="font-size:.85rem;color:var(--hs-text-muted);text-decoration:none;display:inline-block;margin-bottom:1rem;"><?= hz_icon('arrow-left') ?> Terug naar alle onderhoudsvensters</a>

<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>

<div class="hs-grid" style="grid-template-columns:2fr 1fr;align-items:start;">
    <div class="hs-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:.8rem;">
            <h2 class="hs-display" style="margin:0;"><?= e($window['title']) ?></h2>
            <span class="hs-status-pill hs-status-<?= $window['status'] === 'in_progress' ? 'degraded' : 'up' ?>"><?= e(maintenanceStatusLabel($window['status'])) ?></span>
        </div>
        <p style="color:var(--hs-text-muted);font-size:.85rem;margin-bottom:1rem;"><?= hz_icon('calendar') ?> <?= nlDateTime($window['starts_at']) ?> &rarr; <?= nlDateTime($window['ends_at']) ?></p>
        <p style="white-space:pre-wrap;"><?= e($window['description']) ?></p>
        <?php if ($affectedMonitors): ?>
            <p style="margin-top:1rem;font-size:.85rem;"><strong>Betrokken monitors:</strong> <?= e(implode(', ', $affectedMonitors)) ?></p>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
    <div>
        <div class="hs-card" style="margin-bottom:1.25rem;">
            <h3 class="hs-display" style="font-size:.95rem;margin:0 0 1rem;">Bewerken</h3>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <div class="hs-field"><label for="title">Titel</label><input type="text" id="title" name="title" required value="<?= e($window['title']) ?>"></div>
                <div class="hs-field"><label for="description">Omschrijving</label><textarea id="description" name="description" rows="3" required><?= e($window['description']) ?></textarea></div>
                <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Opslaan</button>
            </form>
        </div>
        <div class="hs-card">
            <form method="post" onsubmit="return confirm('Onderhoudsvenster definitief verwijderen?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="hs-btn hs-btn--danger" style="width:100%;"><?= hz_icon('trash') ?> Verwijderen</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php renderAdminEnd(); ?>
