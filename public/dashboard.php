<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];

refreshMonitorSimulation($wsId);

$stmt = db()->prepare('SELECT * FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id');
$stmt->execute([$wsId]);
$monitors = $stmt->fetchAll();

$totalMonitors = count($monitors);
$upCount = 0;
$degradedCount = 0;
$downCount = 0;
$pausedCount = 0;
foreach ($monitors as $m) {
    switch ($m['current_status']) {
        case 'up': $upCount++; break;
        case 'degraded': $degradedCount++; break;
        case 'down': $downCount++; break;
        case 'paused': $pausedCount++; break;
    }
}

$uptimeSum = 0.0;
$uptimeCounted = 0;
foreach ($monitors as $m) {
    if ($m['current_status'] === 'paused') continue;
    $uptimeSum += monitorUptimePercent((int) $m['id'], 30);
    $uptimeCounted++;
}
$avgUptime30d = $uptimeCounted > 0 ? round($uptimeSum / $uptimeCounted, 2) : 100.0;

$stmt = db()->prepare("SELECT COUNT(*) FROM hst_incidents WHERE workspace_id = ? AND status != 'resolved'");
$stmt->execute([$wsId]);
$activeIncidentCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare(
    "SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id
     WHERE i.workspace_id = ? ORDER BY i.created_at DESC LIMIT 5"
);
$stmt->execute([$wsId]);
$recentIncidents = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT a.*, u.name AS user_name FROM hst_audit_logs a LEFT JOIN hst_users u ON u.id = a.user_id
     WHERE a.workspace_id = ? ORDER BY a.created_at DESC LIMIT 8"
);
$stmt->execute([$wsId]);
$recentActivity = $stmt->fetchAll();

$actionLabels = [
    'workspace.create' => 'Workspace aangemaakt', 'monitor.create' => 'Monitor aangemaakt',
    'monitor.pause' => 'Monitor gepauzeerd', 'monitor.resume' => 'Monitor hervat',
    'monitor.delete' => 'Monitor verwijderd', 'incident.create' => 'Incident geopend',
    'incident.update' => 'Incident bijgewerkt', 'incident.resolve' => 'Incident opgelost',
    'maintenance.create' => 'Onderhoud gepland', 'subscriber.add' => 'Abonnee toegevoegd',
    'team.invite' => 'Teamlid uitgenodigd', 'settings.update' => 'Instellingen bijgewerkt',
    'api_token.create' => 'API-token aangemaakt',
];

renderAdminStart('dashboard', 'Dashboard');
?>
<div class="hs-grid hs-grid--4" style="margin-bottom:1.5rem;">
    <div class="hs-card">
        <div class="hs-stat-value"><?= $totalMonitors ?></div>
        <div class="hs-stat-label">Monitors</div>
    </div>
    <div class="hs-card">
        <div class="hs-stat-value" style="color:var(--hs-up);"><?= $upCount ?></div>
        <div class="hs-stat-label">Online</div>
    </div>
    <div class="hs-card">
        <div class="hs-stat-value" style="color:<?= ($degradedCount + $downCount) > 0 ? 'var(--hs-down)' : 'inherit' ?>;"><?= $degradedCount + $downCount ?></div>
        <div class="hs-stat-label">Verminderd / offline</div>
    </div>
    <div class="hs-card">
        <div class="hs-stat-value"><?= e(number_format($avgUptime30d, 2)) ?>%</div>
        <div class="hs-stat-label">Gem. uptime (30d)</div>
    </div>
</div>

<?php if ($activeIncidentCount > 0): ?>
    <div class="hs-alert hs-alert--warning">
        <?= hz_icon('alert-triangle') ?>
        <span><?= $activeIncidentCount ?> actief incident<?= $activeIncidentCount > 1 ? 'en' : '' ?> — <a href="incidents.php" style="color:inherit;font-weight:700;">bekijk incidenten &rarr;</a></span>
    </div>
<?php endif; ?>

<div class="hs-grid" style="grid-template-columns:1.3fr 1fr;margin-bottom:1.5rem;">
    <div class="hs-card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h3 class="hs-display" style="font-size:1.05rem;margin:0;">Monitors</h3>
            <a href="monitors.php" style="font-size:.82rem;font-weight:600;color:var(--hs-primary);text-decoration:none;">Alle monitors &rarr;</a>
        </div>
        <?php if (!$monitors): ?>
            <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('activity') ?></div>Nog geen monitors aangemaakt.</div>
        <?php endif; ?>
        <?php foreach ($monitors as $m): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-top:1px solid var(--hs-border);">
                <a href="monitor.php?id=<?= (int) $m['id'] ?>" style="font-weight:600;font-size:.88rem;text-decoration:none;color:var(--hs-text);"><?= e($m['name']) ?></a>
                <?= monitorStatusPill($m['current_status']) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="hs-card">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Recente incidenten</h3>
        <?php if (!$recentIncidents): ?>
            <p style="color:var(--hs-text-muted);font-size:.85rem;">Geen incidenten gerapporteerd.</p>
        <?php endif; ?>
        <?php foreach ($recentIncidents as $inc): ?>
            <a href="incident-admin.php?id=<?= (int) $inc['id'] ?>" style="display:block;padding:.6rem 0;border-top:1px solid var(--hs-border);text-decoration:none;color:inherit;">
                <p style="font-weight:600;font-size:.86rem;margin:0 0 .25rem;"><?= e($inc['title']) ?></p>
                <div style="display:flex;gap:.5rem;align-items:center;font-size:.76rem;color:var(--hs-text-muted);">
                    <span class="hs-status-pill hs-status-<?= impactBadgeClass($inc['impact']) ?>"><?= e(incidentStatusLabel($inc['status'])) ?></span>
                    <span><?= timeAgo($inc['created_at']) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="hs-card">
    <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Recente activiteit</h3>
    <?php if (!$recentActivity): ?>
        <p style="color:var(--hs-text-muted);font-size:.85rem;">Nog geen activiteit.</p>
    <?php endif; ?>
    <?php foreach ($recentActivity as $log): ?>
        <div style="display:flex;justify-content:space-between;padding:.55rem 0;border-top:1px solid var(--hs-border);font-size:.85rem;">
            <span><strong><?= e($actionLabels[$log['action']] ?? $log['action']) ?></strong> <span style="color:var(--hs-text-muted);">door <?= e($log['user_name'] ?? 'systeem') ?></span></span>
            <span style="color:var(--hs-text-muted);font-size:.78rem;"><?= timeAgo($log['created_at']) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php renderAdminEnd(); ?>
