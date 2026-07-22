<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

// Statussen van geplande vensters automatisch bijwerken op basis van tijd
// (geen cron nodig: idempotent, wordt elke page-load gesynchroniseerd).
db()->prepare("UPDATE hst_maintenance_windows SET status = 'in_progress' WHERE workspace_id = ? AND status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()")->execute([$wsId]);
db()->prepare("UPDATE hst_maintenance_windows SET status = 'completed' WHERE workspace_id = ? AND status IN ('scheduled','in_progress') AND ends_at <= NOW()")->execute([$wsId]);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $startsAt = (string) ($_POST['starts_at'] ?? '');
    $endsAt = (string) ($_POST['ends_at'] ?? '');
    $monitorIds = array_map('intval', (array) ($_POST['monitor_ids'] ?? []));

    $startsTs = strtotime($startsAt);
    $endsTs = strtotime($endsAt);

    if (mb_strlen($title) < 3) {
        $errors[] = 'Vul een titel in.';
    } elseif (mb_strlen($description) < 5) {
        $errors[] = 'Vul een omschrijving in.';
    } elseif (!$startsTs || !$endsTs || $endsTs <= $startsTs) {
        $errors[] = 'Vul een geldige start- en eindtijd in (eindtijd moet na starttijd liggen).';
    } else {
        db()->prepare('INSERT INTO hst_maintenance_windows (workspace_id, title, description, starts_at, ends_at, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$wsId, $title, $description, date('Y-m-d H:i:s', $startsTs), date('Y-m-d H:i:s', $endsTs), $startsTs <= time() ? 'in_progress' : 'scheduled']);
        $maintenanceId = (int) db()->lastInsertId();
        foreach ($monitorIds as $mid) {
            if ($mid > 0) {
                db()->prepare('INSERT IGNORE INTO hst_maintenance_monitors (maintenance_id, monitor_id) VALUES (?, ?)')->execute([$maintenanceId, $mid]);
            }
        }
        auditLog($wsId, (int) $user['id'], 'maintenance.create', 'maintenance', $maintenanceId, "Onderhoud gepland: $title");
        header('Location: ' . BASE . '/maintenance.php');
        exit;
    }
}

$stmt = db()->prepare('SELECT * FROM hst_maintenance_windows WHERE workspace_id = ? ORDER BY starts_at DESC');
$stmt->execute([$wsId]);
$windows = $stmt->fetchAll();

$monitorNamesStmt = db()->prepare(
    'SELECT mm.maintenance_id, m.name FROM hst_maintenance_monitors mm JOIN hst_monitors m ON m.id = mm.monitor_id WHERE m.workspace_id = ?'
);
$monitorNamesStmt->execute([$wsId]);
$monitorNamesByMaintenance = [];
foreach ($monitorNamesStmt->fetchAll() as $row) {
    $monitorNamesByMaintenance[(int) $row['maintenance_id']][] = $row['name'];
}

$monitorsStmt = db()->prepare('SELECT id, name FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id');
$monitorsStmt->execute([$wsId]);
$monitors = $monitorsStmt->fetchAll();

renderAdminStart('maintenance', 'Onderhoud');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
    <p style="color:var(--hs-text-muted);font-size:.85rem;margin:0;"><?= count($windows) ?> onderhoudsvenster(s)</p>
    <?php if ($canManage): ?>
        <button class="hs-btn hs-btn--primary" data-hs-modal-open="hsCreateMaintenanceModal"><?= hz_icon('plus') ?> Onderhoud plannen</button>
    <?php endif; ?>
</div>

<?php if (!$windows): ?>
    <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('tool') ?></div>Geen onderhoudsvensters gepland.</div>
<?php else: ?>
    <?php foreach ($windows as $w):
        $statusPill = $w['status'] === 'completed' ? 'up' : ($w['status'] === 'in_progress' ? 'degraded' : 'up');
    ?>
        <a href="maintenance-detail.php?id=<?= (int) $w['id'] ?>" class="hs-card" style="display:block;text-decoration:none;color:inherit;margin-bottom:.85rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                <div>
                    <h3 style="margin:0 0 .4rem;font-size:1rem;"><?= e($w['title']) ?></h3>
                    <p style="margin:0 0 .4rem;font-size:.85rem;color:var(--hs-text-muted);"><?= e($w['description']) ?></p>
                    <div style="font-size:.78rem;color:var(--hs-text-muted);">
                        <?= hz_icon('calendar') ?> <?= nlDateTime($w['starts_at']) ?> &rarr; <?= nlDateTime($w['ends_at']) ?>
                        <?php if (!empty($monitorNamesByMaintenance[(int) $w['id']])): ?>
                            &middot; <?= e(implode(', ', $monitorNamesByMaintenance[(int) $w['id']])) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="hs-status-pill hs-status-<?= $statusPill ?>"><?= e(maintenanceStatusLabel($w['status'])) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="hs-modal-backdrop" id="hsCreateMaintenanceModal">
    <div class="hs-modal" role="dialog" aria-modal="true" aria-labelledby="hsCreateMaintenanceTitle">
        <div class="hs-modal-head">
            <h2 id="hsCreateMaintenanceTitle" class="hs-display" style="font-size:1.15rem;margin:0;">Onderhoud plannen</h2>
            <button type="button" data-hs-modal-close class="hs-btn hs-btn--ghost hs-btn--sm" aria-label="Sluiten"><?= hz_icon('x') ?></button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <div class="hs-field"><label for="title">Titel</label><input type="text" id="title" name="title" required maxlength="200"></div>
            <div class="hs-field"><label for="description">Omschrijving</label><textarea id="description" name="description" rows="3" required></textarea></div>
            <div class="hs-field"><label for="starts_at">Start</label><input type="datetime-local" id="starts_at" name="starts_at" required></div>
            <div class="hs-field"><label for="ends_at">Einde</label><input type="datetime-local" id="ends_at" name="ends_at" required></div>
            <?php if ($monitors): ?>
                <div class="hs-field">
                    <label>Betrokken monitors</label>
                    <div style="display:flex;flex-direction:column;gap:.4rem;max-height:160px;overflow-y:auto;border:1px solid var(--hs-border);border-radius:10px;padding:.6rem .8rem;">
                        <?php foreach ($monitors as $m): ?>
                            <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;font-size:.85rem;">
                                <input type="checkbox" name="monitor_ids[]" value="<?= (int) $m['id'] ?>"> <?= e($m['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="hs-modal-foot">
                <button type="button" class="hs-btn hs-btn--ghost" data-hs-modal-close>Annuleren</button>
                <button type="submit" class="hs-btn hs-btn--primary">Onderhoud plannen</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php renderAdminEnd(); ?>
