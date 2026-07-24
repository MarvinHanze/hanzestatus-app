<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

refreshMonitorSimulation($wsId);

$monitorId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM hst_monitors WHERE id = ? AND workspace_id = ?');
$stmt->execute([$monitorId, $wsId]);
$monitor = $stmt->fetch();
if (!$monitor) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'update') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'http');
        $interval = (int) ($_POST['check_interval_seconds'] ?? 60);
        $checkMode = (($_POST['check_mode'] ?? '') === 'live') ? 'live' : 'simulated';
        $keyword = trim((string) ($_POST['keyword_text'] ?? ''));
        if (mb_strlen($name) < 2) {
            $errors[] = 'Vul een naam in.';
        } elseif (!validateUrl($url)) {
            $errors[] = 'Vul een geldige URL in.';
        } elseif (!in_array($type, ['http', 'ping', 'keyword'], true)) {
            $errors[] = 'Ongeldig monitortype.';
        } elseif ($type === 'keyword' && mb_strlen($keyword) < 1) {
            $errors[] = 'Vul een trefwoord in om op te controleren.';
        } else {
            $interval = max(15, min(3600, $interval));
            db()->prepare('UPDATE hst_monitors SET name = ?, url = ?, type = ?, check_interval_seconds = ?, check_mode = ?, keyword_text = ? WHERE id = ?')
                ->execute([$name, $url, $type, $interval, $checkMode, $type === 'keyword' ? $keyword : null, $monitorId]);
            auditLog($wsId, (int) $user['id'], 'monitor.update', 'monitor', $monitorId, "Monitor bijgewerkt: $name");
            $monitor['name'] = $name;
            $monitor['url'] = $url;
            $monitor['type'] = $type;
            $monitor['check_interval_seconds'] = $interval;
            $monitor['check_mode'] = $checkMode;
            $monitor['keyword_text'] = $type === 'keyword' ? $keyword : null;
            $success = 'Monitor bijgewerkt.';
        }
    } elseif ($act === 'check_now') {
        if ($monitor['check_mode'] === 'live') {
            $status = runLiveMonitorCheck($monitorId, $monitor['url'], $monitor['type'], $monitor['keyword_text'], date('Y-m-d'));
            if ($status !== $monitor['current_status']) {
                db()->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$status, $monitorId]);
            }
            $monitor['current_status'] = $status;
            $monitor['last_checked_at'] = date('Y-m-d H:i:s');
            auditLog($wsId, (int) $user['id'], 'monitor.check_now', 'monitor', $monitorId, "Handmatige live-check uitgevoerd voor: {$monitor['name']} (resultaat: $status)");
            $success = 'Live-check uitgevoerd: status is nu "' . monitorStatusLabel($status) . '".';
        }
    } elseif ($act === 'toggle_pause') {
        if ($monitor['current_status'] === 'paused') {
            $sim = simulatedDailyStatus($monitorId, simDayIndex(time()));
            db()->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$sim['status'], $monitorId]);
            $monitor['current_status'] = $sim['status'];
            auditLog($wsId, (int) $user['id'], 'monitor.resume', 'monitor', $monitorId, "Monitor hervat: {$monitor['name']}");
        } else {
            db()->prepare('UPDATE hst_monitors SET current_status = "paused" WHERE id = ?')->execute([$monitorId]);
            $monitor['current_status'] = 'paused';
            auditLog($wsId, (int) $user['id'], 'monitor.pause', 'monitor', $monitorId, "Monitor gepauzeerd: {$monitor['name']}");
        }
    } elseif ($act === 'delete') {
        db()->prepare('DELETE FROM hst_monitors WHERE id = ? AND workspace_id = ?')->execute([$monitorId, $wsId]);
        auditLog($wsId, (int) $user['id'], 'monitor.delete', 'monitor', $monitorId, "Monitor verwijderd: {$monitor['name']}");
        header('Location: ' . BASE . '/monitors.php');
        exit;
    }
}

$uptime90 = monitorUptimePercent($monitorId, 90);
$uptime30 = monitorUptimePercent($monitorId, 30);
$ticks = getUptimeTicks($monitorId, 90);

$incidentStmt = db()->prepare('SELECT * FROM hst_incidents WHERE monitor_id = ? ORDER BY created_at DESC LIMIT 10');
$incidentStmt->execute([$monitorId]);
$relatedIncidents = $incidentStmt->fetchAll();

renderAdminStart('monitors', $monitor['name']);
?>
<a href="monitors.php" style="font-size:.85rem;color:var(--hs-text-muted);text-decoration:none;display:inline-block;margin-bottom:1rem;"><?= hz_icon('arrow-left') ?> Terug naar alle monitors</a>

<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> <?= e($success) ?></div><?php endif; ?>

<div class="hs-grid" style="grid-template-columns:2fr 1fr;align-items:start;">
    <div>
        <div class="hs-card" style="margin-bottom:1.25rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
                <div>
                    <h2 class="hs-display" style="margin:0 0 .3rem;"><?= hz_icon('globe') ?> <?= e($monitor['name']) ?>
                        <?php if ($monitor['check_mode'] === 'live'): ?>
                            <span class="hs-live-badge"><span class="hs-live-dot"></span> Live</span>
                        <?php endif; ?>
                    </h2>
                    <p style="color:var(--hs-text-muted);font-size:.85rem;margin:0;"><?= e($monitor['url']) ?></p>
                    <?php if ($monitor['check_mode'] === 'live' && $monitor['last_checked_at']): ?>
                        <p style="color:var(--hs-text-muted);font-size:.76rem;margin:.3rem 0 0;">
                            Laatst live gecontroleerd: <?= nlDateTime($monitor['last_checked_at']) ?>
                            <?php if (!empty($monitor['last_check_detail'])): ?> · <?= e($monitor['last_check_detail']) ?><?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.5rem;">
                    <?= monitorStatusPill($monitor['current_status']) ?>
                    <?php if ($canManage && $monitor['check_mode'] === 'live'): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="check_now">
                            <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm"><?= hz_icon('refresh-cw') ?> Nu controleren</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hs-uptime-bar">
                <?php foreach ($ticks as $t): ?>
                    <div class="hs-uptime-tick hs-status-<?= e($t['status']) ?>" title="<?= e($t['date']) ?>: <?= e(monitorStatusLabel($t['status'] === 'nodata' ? 'paused' : $t['status'])) ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="hs-uptime-labels"><span>90 dagen geleden</span><span>Vandaag</span></div>
        </div>

        <div class="hs-grid hs-grid--4" style="margin-bottom:1.25rem;">
            <div class="hs-card">
                <div class="hs-stat-value" style="font-size:1.4rem;"><?= e(number_format($uptime30, 2)) ?>%</div>
                <div class="hs-stat-label">Uptime (30d)</div>
            </div>
            <div class="hs-card">
                <div class="hs-stat-value" style="font-size:1.4rem;"><?= e(number_format($uptime90, 2)) ?>%</div>
                <div class="hs-stat-label">Uptime (90d)</div>
            </div>
            <div class="hs-card">
                <div class="hs-stat-value" style="font-size:1.4rem;"><?= e(ucfirst($monitor['type'])) ?></div>
                <div class="hs-stat-label">Type</div>
            </div>
            <div class="hs-card">
                <div class="hs-stat-value" style="font-size:1.4rem;"><?= (int) $monitor['check_interval_seconds'] ?>s</div>
                <div class="hs-stat-label">Interval</div>
            </div>
        </div>

        <div class="hs-card">
            <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Gekoppelde incidenten</h3>
            <?php if (!$relatedIncidents): ?>
                <p style="color:var(--hs-text-muted);font-size:.85rem;">Geen incidenten gekoppeld aan deze monitor.</p>
            <?php endif; ?>
            <?php foreach ($relatedIncidents as $inc): ?>
                <a href="incident-admin.php?id=<?= (int) $inc['id'] ?>" style="display:block;padding:.6rem 0;border-top:1px solid var(--hs-border);text-decoration:none;color:inherit;">
                    <p style="font-weight:600;font-size:.86rem;margin:0 0 .25rem;"><?= e($inc['title']) ?></p>
                    <div style="display:flex;gap:.5rem;align-items:center;font-size:.76rem;color:var(--hs-text-muted);">
                        <span class="hs-status-pill hs-status-<?= impactBadgeClass($inc['impact']) ?>"><?= e(incidentStatusLabel($inc['status'])) ?></span>
                        <span><?= nlDate($inc['created_at']) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($canManage): ?>
    <div>
        <div class="hs-card" style="margin-bottom:1.25rem;">
            <h3 class="hs-display" style="font-size:.95rem;margin:0 0 1rem;">Monitor bewerken</h3>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update">
                <div class="hs-field"><label for="name">Naam</label><input type="text" id="name" name="name" required value="<?= e($monitor['name']) ?>"></div>
                <div class="hs-field"><label for="url">URL</label><input type="url" id="url" name="url" required value="<?= e($monitor['url']) ?>"></div>
                <div class="hs-field">
                    <label for="type">Type</label>
                    <select id="type" name="type" onchange="document.getElementById('hsKeywordFieldEdit').style.display = this.value === 'keyword' ? 'block' : 'none';">
                        <option value="http" <?= $monitor['type'] === 'http' ? 'selected' : '' ?>>HTTP(S)</option>
                        <option value="keyword" <?= $monitor['type'] === 'keyword' ? 'selected' : '' ?>>Keyword</option>
                        <option value="ping" <?= $monitor['type'] === 'ping' ? 'selected' : '' ?>>Ping</option>
                    </select>
                </div>
                <div class="hs-field" id="hsKeywordFieldEdit" style="<?= $monitor['type'] === 'keyword' ? '' : 'display:none;' ?>">
                    <label for="keyword_text">Trefwoord</label>
                    <input type="text" id="keyword_text" name="keyword_text" maxlength="150" value="<?= e($monitor['keyword_text'] ?? '') ?>">
                </div>
                <div class="hs-field"><label for="check_interval_seconds">Interval (sec.)</label><input type="number" id="check_interval_seconds" name="check_interval_seconds" value="<?= (int) $monitor['check_interval_seconds'] ?>" min="15" max="3600" step="15"></div>
                <div class="hs-field">
                    <label style="display:flex;align-items:center;gap:.5rem;font-weight:600;">
                        <input type="checkbox" name="check_mode" value="live" <?= $monitor['check_mode'] === 'live' ? 'checked' : '' ?> style="width:auto;">
                        Echte controle uitvoeren op deze URL (semi-live)
                    </label>
                </div>
                <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Opslaan</button>
            </form>
        </div>
        <div class="hs-card">
            <h3 class="hs-display" style="font-size:.95rem;margin:0 0 .8rem;">Beheeracties</h3>
            <form method="post" style="margin-bottom:.6rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_pause">
                <button type="submit" class="hs-btn hs-btn--secondary" style="width:100%;">
                    <?= hz_icon($monitor['current_status'] === 'paused' ? 'play' : 'pause') ?>
                    <?= $monitor['current_status'] === 'paused' ? 'Hervatten' : 'Pauzeren' ?>
                </button>
            </form>
            <form method="post" onsubmit="return confirm('Monitor definitief verwijderen? Dit verwijdert ook de geschiedenis.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="hs-btn hs-btn--danger" style="width:100%;"><?= hz_icon('trash') ?> Verwijderen</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php renderAdminEnd(); ?>
