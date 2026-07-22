<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $monitorId = (int) ($_POST['monitor_id'] ?? 0) ?: null;
    $impact = (string) ($_POST['impact'] ?? 'minor');
    $body = trim((string) ($_POST['body'] ?? ''));

    if (mb_strlen($title) < 5) {
        $errors[] = 'Titel moet minstens 5 tekens bevatten.';
    } elseif (!in_array($impact, ['minor', 'major', 'critical'], true)) {
        $errors[] = 'Ongeldige impact.';
    } elseif (mb_strlen($body) < 5) {
        $errors[] = 'Vul een eerste update in voor de tijdlijn.';
    } else {
        db()->prepare('INSERT INTO hst_incidents (workspace_id, monitor_id, title, status, impact) VALUES (?, ?, ?, "investigating", ?)')
            ->execute([$wsId, $monitorId, $title, $impact]);
        $incidentId = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO hst_incident_updates (incident_id, status, body, created_by_user_id) VALUES (?, "investigating", ?, ?)')
            ->execute([$incidentId, $body, $user['id']]);
        auditLog($wsId, (int) $user['id'], 'incident.create', 'incident', $incidentId, "Incident geopend: $title");

        $settingsStmt = db()->prepare('SELECT notify_on_incident FROM hst_settings WHERE workspace_id = ?');
        $settingsStmt->execute([$wsId]);
        if ((int) $settingsStmt->fetchColumn() === 1) {
            notifyWorkspaceMembers($wsId, 'incident_opened', 'Nieuw incident geopend', $title, 'incident-admin.php?id=' . $incidentId, (int) $user['id']);
        }
        header('Location: ' . BASE . '/incident-admin.php?id=' . $incidentId);
        exit;
    }
}

$statusFilter = (string) ($_GET['status'] ?? '');
$validStatuses = ['investigating', 'identified', 'monitoring', 'resolved'];
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 15;

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
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id
     WHERE $whereSql ORDER BY i.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

$monitorsStmt = db()->prepare('SELECT id, name FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id');
$monitorsStmt->execute([$wsId]);
$monitors = $monitorsStmt->fetchAll();

renderAdminStart('incidents', 'Incidenten');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:1rem;">
    <form method="get" style="display:flex;gap:.75rem;">
        <select name="status" onchange="this.form.submit()" aria-label="Filter op status" style="padding:.6rem .9rem;border:1px solid var(--hs-border);border-radius:10px;background:var(--hs-surface);color:var(--hs-text);font-family:inherit;font-size:.85rem;">
            <option value="">Alle statussen</option>
            <?php foreach ($validStatuses as $s): ?>
                <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(incidentStatusLabel($s)) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php if ($canManage): ?>
        <button class="hs-btn hs-btn--primary" data-hs-modal-open="hsCreateIncidentModal"><?= hz_icon('plus') ?> Nieuw incident</button>
    <?php endif; ?>
</div>

<?php if (!$incidents): ?>
    <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('alert-triangle') ?></div>Geen incidenten gevonden.</div>
<?php else: ?>
    <?php foreach ($incidents as $inc): ?>
        <a href="incident-admin.php?id=<?= (int) $inc['id'] ?>" class="hs-incident-card" style="display:block;text-decoration:none;color:inherit;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                <div>
                    <h3 style="margin:0 0 .4rem;font-size:1rem;"><?= e($inc['title']) ?></h3>
                    <div style="display:flex;gap:.5rem;align-items:center;font-size:.78rem;color:var(--hs-text-muted);flex-wrap:wrap;">
                        <span class="hs-status-pill hs-status-<?= impactBadgeClass($inc['impact']) ?>"><?= e(impactLabel($inc['impact'])) ?></span>
                        <span>&middot;</span>
                        <span><?= e(incidentStatusLabel($inc['status'])) ?></span>
                        <?php if ($inc['monitor_name']): ?><span>&middot;</span><span><?= e($inc['monitor_name']) ?></span><?php endif; ?>
                        <span>&middot;</span>
                        <span><?= timeAgo($inc['created_at']) ?></span>
                    </div>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
    <?= renderPagination($page, $totalPages, 'incidents.php' . ($statusFilter ? '?status=' . urlencode($statusFilter) : '')) ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="hs-modal-backdrop" id="hsCreateIncidentModal">
    <div class="hs-modal" role="dialog" aria-modal="true" aria-labelledby="hsCreateIncidentTitle">
        <div class="hs-modal-head">
            <h2 id="hsCreateIncidentTitle" class="hs-display" style="font-size:1.15rem;margin:0;">Nieuw incident</h2>
            <button type="button" data-hs-modal-close class="hs-btn hs-btn--ghost hs-btn--sm" aria-label="Sluiten"><?= hz_icon('x') ?></button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <div class="hs-field"><label for="title">Titel</label><input type="text" id="title" name="title" required maxlength="200" placeholder="bv. Verhoogde foutmeldingen op API"></div>
            <div class="hs-field">
                <label for="monitor_id">Gekoppelde monitor (optioneel)</label>
                <select id="monitor_id" name="monitor_id">
                    <option value="0">Geen specifieke monitor</option>
                    <?php foreach ($monitors as $m): ?><option value="<?= (int) $m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="hs-field">
                <label for="impact">Impact</label>
                <select id="impact" name="impact">
                    <option value="minor">Klein</option>
                    <option value="major">Groot</option>
                    <option value="critical">Kritiek</option>
                </select>
            </div>
            <div class="hs-field"><label for="body">Eerste update (voor de publieke tijdlijn)</label><textarea id="body" name="body" rows="3" required placeholder="Wat is er aan de hand? Wat gaan we doen?"></textarea></div>
            <div class="hs-modal-foot">
                <button type="button" class="hs-btn hs-btn--ghost" data-hs-modal-close>Annuleren</button>
                <button type="submit" class="hs-btn hs-btn--primary">Incident openen</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php renderAdminEnd(); ?>
