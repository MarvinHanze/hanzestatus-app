<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireRole(['owner', 'admin']);
$wsId = (int) $ws['id'];

$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = 25;

$countStmt = db()->prepare('SELECT COUNT(*) FROM hst_audit_logs WHERE workspace_id = ?');
$countStmt->execute([$wsId]);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare(
    "SELECT a.*, u.name AS user_name FROM hst_audit_logs a LEFT JOIN hst_users u ON u.id = a.user_id
     WHERE a.workspace_id = ? ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset"
);
$stmt->execute([$wsId]);
$logs = $stmt->fetchAll();

$actionLabels = [
    'workspace.create' => 'Workspace aangemaakt',
    'monitor.create' => 'Monitor aangemaakt',
    'monitor.update' => 'Monitor bijgewerkt',
    'monitor.pause' => 'Monitor gepauzeerd',
    'monitor.resume' => 'Monitor hervat',
    'monitor.delete' => 'Monitor verwijderd',
    'incident.create' => 'Incident geopend',
    'incident.update' => 'Incident bijgewerkt',
    'incident.resolve' => 'Incident opgelost',
    'maintenance.create' => 'Onderhoud gepland',
    'maintenance.update' => 'Onderhoud bijgewerkt',
    'maintenance.delete' => 'Onderhoud verwijderd',
    'subscriber.add' => 'Abonnee toegevoegd',
    'subscriber.remove' => 'Abonnee verwijderd',
    'team.invite' => 'Teamlid uitgenodigd',
    'team.invite_accept' => 'Uitnodiging geaccepteerd',
    'team.role_change' => 'Rol gewijzigd',
    'team.remove' => 'Teamlid verwijderd',
    'settings.update' => 'Instellingen bijgewerkt',
    'api_token.create' => 'API-token aangemaakt',
    'api_token.revoke' => 'API-token ingetrokken',
];

renderAdminStart('audit-log', 'Audit-log');
?>
<p style="color:var(--hs-text-muted);font-size:.85rem;margin-bottom:1.25rem;">Onwijzigbaar overzicht van alle beheeracties binnen deze workspace (<?= $total ?> gebeurtenissen).</p>

<?php if (!$logs): ?>
    <div class="hs-empty-state"><div class="hs-icon-wrap"><?= hz_icon('clipboard') ?></div>Nog geen activiteit gelogd.</div>
<?php else: ?>
    <div class="hs-table-wrap">
        <table class="hs-table">
            <thead><tr><th>Actie</th><th>Door</th><th>Details</th><th>IP</th><th>Wanneer</th></tr></thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><strong><?= e($actionLabels[$log['action']] ?? $log['action']) ?></strong></td>
                        <td><?= e($log['user_name'] ?? 'Systeem') ?></td>
                        <td style="color:var(--hs-text-muted);"><?= e($log['meta'] ?? '-') ?></td>
                        <td style="color:var(--hs-text-muted);font-family:'JetBrains Mono',monospace;font-size:.78rem;"><?= e($log['ip'] ?? '-') ?></td>
                        <td><?= timeAgo($log['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= renderPagination($page, $totalPages, 'audit-log.php') ?>
<?php endif; ?>
<?php renderAdminEnd(); ?>
