<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

refreshMonitorSimulation($wsId);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $url = trim((string) ($_POST['url'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'http');
        $interval = (int) ($_POST['check_interval_seconds'] ?? 60);
        // Checkbox: bij afwezigheid in de POST-body (onvinkt) valt dit terug op 'simulated',
        // niet op 'live' — een ontbrekende checkbox-waarde mag nooit stilzwijgend "aan" betekenen.
        $checkMode = (($_POST['check_mode'] ?? '') === 'live') ? 'live' : 'simulated';
        $keyword = trim((string) ($_POST['keyword_text'] ?? ''));
        if (mb_strlen($name) < 2) {
            $errors[] = 'Vul een naam in voor de monitor.';
        } elseif (!validateUrl($url)) {
            $errors[] = 'Vul een geldige URL in (moet beginnen met http:// of https://).';
        } elseif (!in_array($type, ['http', 'ping', 'keyword'], true)) {
            $errors[] = 'Ongeldig monitortype.';
        } elseif ($type === 'keyword' && mb_strlen($keyword) < 1) {
            $errors[] = 'Vul een trefwoord in om op te controleren.';
        } else {
            $interval = max(15, min(3600, $interval));
            $posStmt = db()->prepare('SELECT COALESCE(MAX(position),0) FROM hst_monitors WHERE workspace_id = ?');
            $posStmt->execute([$wsId]);
            $maxPos = (int) $posStmt->fetchColumn();
            db()->prepare('INSERT INTO hst_monitors (workspace_id, name, url, type, check_interval_seconds, position, check_mode, keyword_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$wsId, $name, $url, $type, $interval, $maxPos + 1, $checkMode, $type === 'keyword' ? $keyword : null]);
            $newMonitorId = (int) db()->lastInsertId();
            if ($checkMode === 'live') {
                // Meteen een echte check uitvoeren zodat de monitor direct een betrouwbare
                // status toont i.p.v. te wachten tot het volgende bezoek van de dag.
                $liveStatus = runLiveMonitorCheck($newMonitorId, $url, $type, $type === 'keyword' ? $keyword : null, date('Y-m-d'));
                db()->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$liveStatus, $newMonitorId]);
            } else {
                refreshMonitorSimulation($wsId);
            }
            auditLog($wsId, (int) $user['id'], 'monitor.create', 'monitor', $newMonitorId, "Monitor aangemaakt: $name (" . ($checkMode === 'live' ? 'live' : 'gesimuleerd') . ')');
            $success = 'Monitor aangemaakt.' . ($checkMode === 'live' ? ' Echte controle uitgevoerd.' : '');
        }
    } elseif ($act === 'check_now') {
        $monitorId = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM hst_monitors WHERE id = ? AND workspace_id = ?');
        $stmt->execute([$monitorId, $wsId]);
        $monitor = $stmt->fetch();
        if ($monitor && $monitor['check_mode'] === 'live') {
            $status = runLiveMonitorCheck($monitorId, $monitor['url'], $monitor['type'], $monitor['keyword_text'], date('Y-m-d'));
            if ($status !== $monitor['current_status']) {
                db()->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$status, $monitorId]);
            }
            auditLog($wsId, (int) $user['id'], 'monitor.check_now', 'monitor', $monitorId, "Handmatige live-check uitgevoerd voor: {$monitor['name']} (resultaat: $status)");
            $success = 'Live-check uitgevoerd: status is nu "' . monitorStatusLabel($status) . '".';
        }
    } elseif ($act === 'toggle_pause') {
        $monitorId = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM hst_monitors WHERE id = ? AND workspace_id = ?');
        $stmt->execute([$monitorId, $wsId]);
        $monitor = $stmt->fetch();
        if ($monitor) {
            if ($monitor['current_status'] === 'paused') {
                $sim = simulatedDailyStatus($monitorId, simDayIndex(time()));
                db()->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$sim['status'], $monitorId]);
                auditLog($wsId, (int) $user['id'], 'monitor.resume', 'monitor', $monitorId, "Monitor hervat: {$monitor['name']}");
            } else {
                db()->prepare('UPDATE hst_monitors SET current_status = "paused" WHERE id = ?')->execute([$monitorId]);
                auditLog($wsId, (int) $user['id'], 'monitor.pause', 'monitor', $monitorId, "Monitor gepauzeerd: {$monitor['name']}");
            }
        }
    } elseif ($act === 'delete') {
        $monitorId = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM hst_monitors WHERE id = ? AND workspace_id = ?');
        $stmt->execute([$monitorId, $wsId]);
        $monitor = $stmt->fetch();
        if ($monitor) {
            db()->prepare('DELETE FROM hst_monitors WHERE id = ? AND workspace_id = ?')->execute([$monitorId, $wsId]);
            auditLog($wsId, (int) $user['id'], 'monitor.delete', 'monitor', $monitorId, "Monitor verwijderd: {$monitor['name']}");
        }
    }
}

$stmt = db()->prepare('SELECT * FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id');
$stmt->execute([$wsId]);
$monitors = $stmt->fetchAll();

renderAdminStart('monitors', 'Monitors');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> <?= e($success) ?></div><?php endif; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;">
    <p style="color:var(--hs-text-muted);font-size:.85rem;margin:0;"><?= count($monitors) ?> monitor(s). Curatie-demomonitors zijn gesimuleerd; een monitor met een <strong>live</strong>-badge voert echte HTTP-controles uit tegen zijn eigen URL — zie de toelichting op de <a href="settings.php" style="color:var(--hs-primary);">instellingenpagina</a>.</p>
    <?php if ($canManage): ?>
        <button class="hs-btn hs-btn--primary" data-hs-modal-open="hsCreateMonitorModal"><?= hz_icon('plus') ?> Nieuwe monitor</button>
    <?php endif; ?>
</div>

<?php if (!$monitors): ?>
    <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('activity') ?></div>Nog geen monitors aangemaakt.</div>
<?php else: ?>
    <?php foreach ($monitors as $m): $uptime = monitorUptimePercent((int) $m['id'], 90); $ticks = getUptimeTicks((int) $m['id'], 90); ?>
        <div class="hs-monitor-row">
            <div class="hs-monitor-head">
                <a href="monitor.php?id=<?= (int) $m['id'] ?>" class="hs-monitor-name" style="text-decoration:none;color:var(--hs-text);">
                    <?= hz_icon('globe') ?> <?= e($m['name']) ?>
                    <span style="font-weight:400;color:var(--hs-text-muted);font-size:.78rem;">· <?= e($m['url']) ?></span>
                </a>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <?php if ($m['check_mode'] === 'live'): ?>
                        <span class="hs-live-badge" title="<?= $m['last_checked_at'] ? 'Laatst gecontroleerd: ' . e(nlDateTime($m['last_checked_at'])) . ($m['last_check_detail'] ? ' · ' . e($m['last_check_detail']) : '') : 'Nog niet gecontroleerd' ?>">
                            <span class="hs-live-dot"></span> Live
                        </span>
                    <?php endif; ?>
                    <span class="hs-uptime-pct"><?= e(number_format($uptime, 2)) ?>%</span>
                    <?= monitorStatusPill($m['current_status']) ?>
                    <?php if ($canManage): ?>
                        <?php if ($m['check_mode'] === 'live'): ?>
                            <form method="post" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="check_now">
                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm" title="Nu controleren (echte HTTP-check)">
                                    <?= hz_icon('refresh-cw') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_pause">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm" title="<?= $m['current_status'] === 'paused' ? 'Hervatten' : 'Pauzeren' ?>">
                                <?= hz_icon($m['current_status'] === 'paused' ? 'play' : 'pause') ?>
                            </button>
                        </form>
                        <form method="post" onsubmit="return confirm('Monitor \'<?= e($m['name']) ?>\' definitief verwijderen? Dit verwijdert ook de geschiedenis.')" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm"><?= hz_icon('trash') ?></button>
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
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="hs-modal-backdrop" id="hsCreateMonitorModal">
    <div class="hs-modal" role="dialog" aria-modal="true" aria-labelledby="hsCreateMonitorTitle">
        <div class="hs-modal-head">
            <h2 id="hsCreateMonitorTitle" class="hs-display" style="font-size:1.15rem;margin:0;">Nieuwe monitor</h2>
            <button type="button" data-hs-modal-close class="hs-btn hs-btn--ghost hs-btn--sm" aria-label="Sluiten"><?= hz_icon('x') ?></button>
        </div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="hs-field"><label for="name">Naam</label><input type="text" id="name" name="name" required maxlength="150" placeholder="bv. Mijn website"></div>
            <div class="hs-field"><label for="url">URL</label><input type="url" id="url" name="url" required placeholder="https://www.mijnbedrijf.nl"></div>
            <div class="hs-field">
                <label for="type">Type</label>
                <select id="type" name="type" onchange="document.getElementById('hsKeywordField').style.display = this.value === 'keyword' ? 'block' : 'none';">
                    <option value="http">HTTP(S) — statuscode-check</option>
                    <option value="keyword">Keyword — controleert inhoud van de pagina</option>
                    <option value="ping">Ping — bereikbaarheidscheck</option>
                </select>
            </div>
            <div class="hs-field" id="hsKeywordField" style="display:none;">
                <label for="keyword_text">Trefwoord</label>
                <input type="text" id="keyword_text" name="keyword_text" maxlength="150" placeholder="bv. In stock">
                <p class="hs-hint">De check faalt als dit trefwoord niet op de pagina wordt gevonden.</p>
            </div>
            <div class="hs-field">
                <label for="check_interval_seconds">Controle-interval (seconden)</label>
                <input type="number" id="check_interval_seconds" name="check_interval_seconds" value="60" min="15" max="3600" step="15">
            </div>
            <div class="hs-field">
                <label style="display:flex;align-items:center;gap:.5rem;font-weight:600;">
                    <input type="checkbox" id="check_mode" name="check_mode" value="live" checked style="width:auto;">
                    Echte controle uitvoeren op deze URL (semi-live)
                </label>
                <p class="hs-hint">Aan: minstens 1x per dag wordt er een echte HTTP-request naar deze URL gedaan (plus handmatig via "Nu controleren"). Uit: de status wordt net als de curatie-demomonitors puur gesimuleerd — geen echte requests.</p>
            </div>
            <div class="hs-modal-foot">
                <button type="button" class="hs-btn hs-btn--ghost" data-hs-modal-close>Annuleren</button>
                <button type="submit" class="hs-btn hs-btn--primary">Monitor aanmaken</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php renderAdminEnd(); ?>
