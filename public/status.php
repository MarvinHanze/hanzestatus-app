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

refreshMonitorSimulation($wsId);

// Onderhoudsstatussen bijwerken op basis van tijd (zelfde idempotente aanpak als maintenance.php).
db()->prepare("UPDATE hst_maintenance_windows SET status = 'in_progress' WHERE workspace_id = ? AND status = 'scheduled' AND starts_at <= NOW() AND ends_at > NOW()")->execute([$wsId]);
db()->prepare("UPDATE hst_maintenance_windows SET status = 'completed' WHERE workspace_id = ? AND status IN ('scheduled','in_progress') AND ends_at <= NOW()")->execute([$wsId]);

$settingsStmt = db()->prepare('SELECT * FROM hst_settings WHERE workspace_id = ?');
$settingsStmt->execute([$wsId]);
$settings = $settingsStmt->fetch() ?: ['public_intro' => ''];

$formErrors = [];
$subscribeSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'subscribe') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!validateEmail($email)) {
            $formErrors[] = 'Vul een geldig e-mailadres in.';
        } elseif (!rateLimit('subscribe:' . clientIp(), 8, 3600)) {
            $formErrors[] = 'Te veel aanmeldingen vanaf dit IP-adres. Probeer het later opnieuw.';
        } else {
            $dup = db()->prepare('SELECT id, unsubscribed_at FROM hst_subscribers WHERE workspace_id = ? AND email = ?');
            $dup->execute([$wsId, $email]);
            $existing = $dup->fetch();
            if ($existing && !$existing['unsubscribed_at']) {
                $formErrors[] = 'Dit e-mailadres is al aangemeld voor updates.';
            } elseif ($existing) {
                db()->prepare('UPDATE hst_subscribers SET unsubscribed_at = NULL, confirmed_at = NOW() WHERE id = ?')->execute([$existing['id']]);
                $subscribeSuccess = true;
            } else {
                db()->prepare('INSERT INTO hst_subscribers (workspace_id, email) VALUES (?, ?)')->execute([$wsId, $email]);
                $subscribeSuccess = true;
            }
        }
    }
}

$stmt = db()->prepare('SELECT * FROM hst_monitors WHERE workspace_id = ? ORDER BY position, id');
$stmt->execute([$wsId]);
$monitors = $stmt->fetchAll();
$overallStatus = aggregateWorkspaceStatus($monitors);

$activeIncidentsStmt = db()->prepare(
    "SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id
     WHERE i.workspace_id = ? AND i.status != 'resolved' ORDER BY i.created_at DESC"
);
$activeIncidentsStmt->execute([$wsId]);
$activeIncidents = $activeIncidentsStmt->fetchAll();

$pastIncidentsStmt = db()->prepare(
    "SELECT i.*, m.name AS monitor_name FROM hst_incidents i LEFT JOIN hst_monitors m ON m.id = i.monitor_id
     WHERE i.workspace_id = ? AND i.status = 'resolved' ORDER BY i.created_at DESC LIMIT 10"
);
$pastIncidentsStmt->execute([$wsId]);
$pastIncidents = $pastIncidentsStmt->fetchAll();

$maintenanceStmt = db()->prepare(
    "SELECT * FROM hst_maintenance_windows WHERE workspace_id = ? AND status IN ('scheduled','in_progress') ORDER BY starts_at ASC"
);
$maintenanceStmt->execute([$wsId]);
$maintenanceWindows = $maintenanceStmt->fetchAll();

$monitorNamesStmt = db()->prepare(
    'SELECT mm.maintenance_id, m.name FROM hst_maintenance_monitors mm JOIN hst_monitors m ON m.id = mm.monitor_id WHERE m.workspace_id = ?'
);
$monitorNamesStmt->execute([$wsId]);
$monitorNamesByMaintenance = [];
foreach ($monitorNamesStmt->fetchAll() as $row) {
    $monitorNamesByMaintenance[(int) $row['maintenance_id']][] = $row['name'];
}

$overallLabels = [
    'up' => ['Alle systemen operationeel', 'Al onze diensten werken normaal.'],
    'degraded' => ['Verminderde prestaties', 'Eén of meer diensten ondervinden momenteel problemen.'],
    'down' => ['Storing actief', 'Eén of meer diensten zijn momenteel niet bereikbaar.'],
];
[$overallTitle, $overallSubtitle] = $overallLabels[$overallStatus];

renderPublicStart($workspace, 'Status');
?>
<div class="hs-overall-banner hs-status-<?= e($overallStatus) ?>">
    <div class="hs-overall-icon hs-status-<?= e($overallStatus) ?>"><?= hz_icon($overallStatus === 'up' ? 'check' : ($overallStatus === 'down' ? 'x-octagon' : 'alert-triangle'), 'hz-icon') ?></div>
    <h1><?= e($overallTitle) ?></h1>
    <p><?= e($overallSubtitle) ?></p>
</div>

<div class="hs-container" style="padding-bottom:3rem;">
    <?php if ($settings['public_intro']): ?>
        <p style="color:var(--hs-text-muted);text-align:center;margin:1rem 0 2rem;font-size:.92rem;"><?= e($settings['public_intro']) ?></p>
    <?php endif; ?>

    <?php if ($activeIncidents): ?>
        <h2 class="hs-display" style="font-size:1.1rem;margin-bottom:1rem;"><?= hz_icon('alert-triangle') ?> Actieve incidenten</h2>
        <?php foreach ($activeIncidents as $inc): ?>
            <a href="incident.php?w=<?= e($slug) ?>&id=<?= (int) $inc['id'] ?>" class="hs-incident-card" style="display:block;text-decoration:none;color:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .4rem;font-size:1rem;"><?= e($inc['title']) ?></h3>
                        <div style="display:flex;gap:.5rem;align-items:center;font-size:.78rem;color:var(--hs-text-muted);flex-wrap:wrap;">
                            <span class="hs-status-pill hs-status-<?= impactBadgeClass($inc['impact']) ?>"><?= e(impactLabel($inc['impact'])) ?></span>
                            <span>&middot;</span><span><?= e(incidentStatusLabel($inc['status'])) ?></span>
                            <?php if ($inc['monitor_name']): ?><span>&middot;</span><span><?= e($inc['monitor_name']) ?></span><?php endif; ?>
                            <span>&middot;</span><span><?= timeAgo($inc['created_at']) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($maintenanceWindows): ?>
        <h2 class="hs-display" style="font-size:1.1rem;margin:2rem 0 1rem;"><?= hz_icon('tool') ?> Gepland onderhoud</h2>
        <?php foreach ($maintenanceWindows as $w): ?>
            <div class="hs-incident-card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .4rem;font-size:1rem;"><?= e($w['title']) ?></h3>
                        <p style="margin:0 0 .5rem;font-size:.85rem;color:var(--hs-text-muted);"><?= e($w['description']) ?></p>
                        <div style="font-size:.78rem;color:var(--hs-text-muted);">
                            <?= hz_icon('calendar') ?> <?= nlDateTime($w['starts_at']) ?> &rarr; <?= nlDateTime($w['ends_at']) ?>
                            <?php if (!empty($monitorNamesByMaintenance[(int) $w['id']])): ?>
                                &middot; <?= e(implode(', ', $monitorNamesByMaintenance[(int) $w['id']])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="hs-status-pill hs-status-<?= $w['status'] === 'in_progress' ? 'degraded' : 'up' ?>"><?= e(maintenanceStatusLabel($w['status'])) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2 class="hs-display" style="font-size:1.1rem;margin:2rem 0 1rem;"><?= hz_icon('activity') ?> Diensten</h2>
    <div class="hs-services-grid">
    <?php foreach ($monitors as $m):
        $uptime = monitorUptimePercent((int) $m['id'], 90);
        $ticks = getUptimeTicks((int) $m['id'], 90);
        // Bento-indeling: een dienst met een actief probleem krijgt visueel meer gewicht
        // (breder + geaccentueerde kleur) i.p.v. dezelfde grootte als een gezonde dienst.
        $tileClass = 'hs-service-tile';
        if ($m['current_status'] === 'down') {
            $tileClass .= ' hs-service-tile--down hs-service-tile--hero';
        } elseif ($m['current_status'] === 'degraded') {
            $tileClass .= ' hs-service-tile--alert hs-service-tile--wide';
        }
    ?>
        <div class="<?= e($tileClass) ?>">
            <div class="hs-service-tile-top">
                <div style="display:flex;align-items:center;gap:.65rem;min-width:0;">
                    <span class="hs-service-tile-icon"><?= hz_icon('activity') ?></span>
                    <span class="hs-service-tile-name"><?= e($m['name']) ?></span>
                    <?php if ($m['check_mode'] === 'live'): ?>
                        <span class="hs-live-badge" title="Deze dienst wordt echt gecontroleerd (semi-live), niet gesimuleerd"><span class="hs-live-dot"></span> Live</span>
                    <?php endif; ?>
                </div>
                <?= monitorStatusPill($m['current_status']) ?>
            </div>
            <div class="hs-service-tile-uptime hs-mono"><?= e(number_format($uptime, 2)) ?>%</div>
            <div class="hs-uptime-bar">
                <?php foreach ($ticks as $t): ?>
                    <div class="hs-uptime-tick hs-status-<?= e($t['status']) ?>" title="<?= e($t['date']) ?>: <?= e(monitorStatusLabel($t['status'] === 'nodata' ? 'paused' : $t['status'])) ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="hs-uptime-labels"><span>90 dagen geleden</span><span>Vandaag</span></div>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if ($pastIncidents): ?>
        <h2 class="hs-display" style="font-size:1.1rem;margin:2rem 0 1rem;"><?= hz_icon('clock') ?> Eerdere incidenten</h2>
        <?php foreach ($pastIncidents as $inc): ?>
            <a href="incident.php?w=<?= e($slug) ?>&id=<?= (int) $inc['id'] ?>" class="hs-incident-card" style="display:block;text-decoration:none;color:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .4rem;font-size:1rem;"><?= e($inc['title']) ?></h3>
                        <div style="display:flex;gap:.5rem;align-items:center;font-size:.78rem;color:var(--hs-text-muted);flex-wrap:wrap;">
                            <span class="hs-status-pill hs-status-up">Opgelost</span>
                            <?php if ($inc['monitor_name']): ?><span>&middot;</span><span><?= e($inc['monitor_name']) ?></span><?php endif; ?>
                            <span>&middot;</span><span><?= nlDate($inc['created_at']) ?></span>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="hs-card" style="margin-top:2rem;max-width:480px;">
        <h3 class="hs-display" style="font-size:1rem;margin:0 0 .5rem;"><?= hz_icon('mail') ?> Blijf op de hoogte</h3>
        <p style="font-size:.85rem;color:var(--hs-text-muted);margin-bottom:1rem;">Meld je aan voor updates via e-mail bij nieuwe incidenten en gepland onderhoud.</p>
        <?php foreach ($formErrors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
        <?php if ($subscribeSuccess): ?>
            <div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> Bedankt! Je ontvangt voortaan updates op dit e-mailadres.</div>
        <?php else: ?>
            <form method="post" style="display:flex;gap:.6rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="subscribe">
                <input type="email" name="email" required placeholder="jij@bedrijf.nl" style="flex:1;" aria-label="E-mailadres">
                <button type="submit" class="hs-btn hs-btn--primary">Aanmelden</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php renderPublicEnd(); ?>
