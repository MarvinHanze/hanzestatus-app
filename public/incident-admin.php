<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

$incidentId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id WHERE i.id = ? AND i.workspace_id = ?');
$stmt->execute([$incidentId, $wsId]);
$incident = $stmt->fetch();
if (!$incident) {
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
    $status = (string) ($_POST['status'] ?? '');
    $body = trim((string) ($_POST['body'] ?? ''));
    $validStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];

    if (!in_array($status, $validStatuses, true)) {
        $errors[] = 'Ongeldige status.';
    } elseif (mb_strlen($body) < 5) {
        $errors[] = 'Vul een update in (minstens 5 tekens).';
    } else {
        db()->prepare('INSERT INTO hst_incident_updates (incident_id, status, body, created_by_user_id) VALUES (?, ?, ?, ?)')
            ->execute([$incidentId, $status, $body, $user['id']]);
        $resolvedSql = $status === 'resolved' ? ', resolved_at = NOW()' : '';
        db()->prepare("UPDATE hst_incidents SET status = ?$resolvedSql WHERE id = ?")->execute([$status, $incidentId]);
        auditLog($wsId, (int) $user['id'], $status === 'resolved' ? 'incident.resolve' : 'incident.update', 'incident', $incidentId, "Status -> $status");

        $settingsStmt = db()->prepare('SELECT notify_on_incident FROM hst_settings WHERE workspace_id = ?');
        $settingsStmt->execute([$wsId]);
        if ((int) $settingsStmt->fetchColumn() === 1) {
            $title = $status === 'resolved' ? 'Incident opgelost' : 'Incident bijgewerkt';
            notifyWorkspaceMembers($wsId, $status === 'resolved' ? 'incident_resolved' : 'incident_update', $title, $incident['title'], 'incident-admin.php?id=' . $incidentId, (int) $user['id']);
        }
        header('Location: ' . BASE . '/incident-admin.php?id=' . $incidentId);
        exit;
    }
}

$updatesStmt = db()->prepare(
    'SELECT u.*, usr.name AS user_name FROM hst_incident_updates u LEFT JOIN hst_users usr ON usr.id = u.created_by_user_id
     WHERE u.incident_id = ? ORDER BY u.created_at DESC'
);
$updatesStmt->execute([$incidentId]);
$updates = $updatesStmt->fetchAll();

$validStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];

renderAdminStart('incidents', $incident['title']);
?>
<a href="incidents.php" style="font-size:.85rem;color:var(--hs-text-muted);text-decoration:none;display:inline-block;margin-bottom:1rem;"><?= hz_icon('arrow-left') ?> Terug naar alle incidenten</a>

<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>

<div class="hs-grid" style="grid-template-columns:2fr 1fr;align-items:start;">
    <div>
        <div class="hs-incident-card" style="margin-bottom:1.25rem;">
            <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;">
                <span class="hs-status-pill hs-status-<?= impactBadgeClass($incident['impact']) ?>"><?= e(impactLabel($incident['impact'])) ?></span>
                <span class="hs-status-pill hs-status-<?= $incident['status'] === 'resolved' ? 'up' : 'degraded' ?>"><?= e(incidentStatusLabel($incident['status'])) ?></span>
                <?php if ($incident['monitor_name']): ?><span style="font-size:.8rem;color:var(--hs-text-muted);"><?= hz_icon('globe') ?> <?= e($incident['monitor_name']) ?></span><?php endif; ?>
            </div>
            <h2 class="hs-display" style="margin:0 0 .4rem;"><?= e($incident['title']) ?></h2>
            <p style="color:var(--hs-text-muted);font-size:.85rem;">Geopend <?= nlDateTime($incident['created_at']) ?><?php if ($incident['resolved_at']): ?> · Opgelost <?= nlDateTime($incident['resolved_at']) ?><?php endif; ?></p>
        </div>

        <div class="hs-card">
            <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Tijdlijn</h3>
            <?php foreach ($updates as $u): ?>
                <div class="hs-incident-update">
                    <span class="hs-incident-update-dot"></span>
                    <div>
                        <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.2rem;">
                            <strong style="font-size:.86rem;"><?= e(incidentStatusLabel($u['status'])) ?></strong>
                            <span style="font-size:.76rem;color:var(--hs-text-muted);"><?= nlDateTime($u['created_at']) ?> &middot; <?= e($u['user_name'] ?? 'Systeem') ?></span>
                        </div>
                        <p style="margin:0;font-size:.88rem;white-space:pre-wrap;"><?= e($u['body']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($canManage && $incident['status'] !== 'resolved'): ?>
    <div class="hs-card">
        <h3 class="hs-display" style="font-size:.95rem;margin:0 0 1rem;">Update plaatsen</h3>
        <form method="post">
            <?= csrfField() ?>
            <div class="hs-field">
                <label for="status">Nieuwe status</label>
                <select id="status" name="status">
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $incident['status'] === $s ? 'selected' : '' ?>><?= e(incidentStatusLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="hs-field"><label for="body">Update-tekst</label><textarea id="body" name="body" rows="4" required placeholder="Deel de laatste stand van zaken..."></textarea></div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Update plaatsen</button>
        </form>
    </div>
    <?php elseif ($incident['status'] === 'resolved'): ?>
        <div class="hs-alert hs-alert--success" style="margin:0;"><?= hz_icon('check-circle') ?> Dit incident is opgelost.</div>
    <?php endif; ?>
</div>
<?php renderAdminEnd(); ?>
