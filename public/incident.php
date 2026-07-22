<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$slug = (string) ($_GET['w'] ?? '');
$stmt = db()->prepare('SELECT * FROM hst_workspaces WHERE slug = ?');
$stmt->execute([$slug]);
$workspace = $stmt->fetch();
if (!$workspace) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}
$wsId = (int) $workspace['id'];

$incidentId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id WHERE i.id = ? AND i.workspace_id = ?');
$stmt->execute([$incidentId, $wsId]);
$incident = $stmt->fetch();
if (!$incident) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$updatesStmt = db()->prepare(
    'SELECT u.*, usr.name AS user_name FROM hst_incident_updates u LEFT JOIN hst_users usr ON usr.id = u.created_by_user_id
     WHERE u.incident_id = ? ORDER BY u.created_at DESC'
);
$updatesStmt->execute([$incidentId]);
$updates = $updatesStmt->fetchAll();

renderPublicStart($workspace, $incident['title']);
?>
<div class="hs-container" style="max-width:760px;padding-top:2rem;padding-bottom:3rem;">
    <a href="status.php?w=<?= e($slug) ?>" style="font-size:.85rem;color:var(--hs-text-muted);text-decoration:none;"><?= hz_icon('arrow-left') ?> Terug naar statuspagina</a>

    <div class="hs-incident-card" style="margin-top:1rem;">
        <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
            <span class="hs-status-pill hs-status-<?= impactBadgeClass($incident['impact']) ?>"><?= e(impactLabel($incident['impact'])) ?></span>
            <span class="hs-status-pill hs-status-<?= $incident['status'] === 'resolved' ? 'up' : 'degraded' ?>"><?= e(incidentStatusLabel($incident['status'])) ?></span>
            <?php if ($incident['monitor_name']): ?><span style="font-size:.8rem;color:var(--hs-text-muted);"><?= hz_icon('globe') ?> <?= e($incident['monitor_name']) ?></span><?php endif; ?>
        </div>
        <h1 class="hs-display" style="font-size:1.5rem;margin:0 0 .5rem;"><?= e($incident['title']) ?></h1>
        <p style="color:var(--hs-text-muted);font-size:.85rem;">Geopend <?= nlDateTime($incident['created_at']) ?><?php if ($incident['resolved_at']): ?> · Opgelost <?= nlDateTime($incident['resolved_at']) ?><?php endif; ?></p>
    </div>

    <div class="hs-card" style="margin-top:1.5rem;">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Tijdlijn</h3>
        <?php foreach ($updates as $u): ?>
            <div class="hs-incident-update">
                <span class="hs-incident-update-dot"></span>
                <div>
                    <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.2rem;">
                        <strong style="font-size:.86rem;"><?= e(incidentStatusLabel($u['status'])) ?></strong>
                        <span style="font-size:.76rem;color:var(--hs-text-muted);"><?= nlDateTime($u['created_at']) ?></span>
                    </div>
                    <p style="margin:0;font-size:.9rem;white-space:pre-wrap;"><?= e($u['body']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php renderPublicEnd(); ?>
