<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireRole(['owner', 'admin']);
$wsId = (int) $ws['id'];
$user = currentUser();

$newToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';
    if ($act === 'create') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $name = 'API-token';
        }
        $plain = 'hst_' . bin2hex(random_bytes(24));
        db()->prepare('INSERT INTO hst_api_tokens (workspace_id, name, token_hash) VALUES (?, ?, ?)')
            ->execute([$wsId, $name, hash('sha256', $plain)]);
        auditLog($wsId, (int) $user['id'], 'api_token.create', 'api_token', (int) db()->lastInsertId(), $name);
        $newToken = $plain;
    } elseif ($act === 'revoke') {
        db()->prepare('UPDATE hst_api_tokens SET revoked_at = NOW() WHERE id = ? AND workspace_id = ?')
            ->execute([(int) $_POST['id'], $wsId]);
        auditLog($wsId, (int) $user['id'], 'api_token.revoke', 'api_token', (int) $_POST['id'], null);
    }
}

$stmt = db()->prepare('SELECT * FROM hst_api_tokens WHERE workspace_id = ? ORDER BY created_at DESC');
$stmt->execute([$wsId]);
$tokens = $stmt->fetchAll();

renderAdminStart('api-tokens', 'API-tokens');
?>
<?php if ($newToken): ?>
    <div class="hs-alert hs-alert--success">
        <?= hz_icon('check-circle') ?>
        <span>Token aangemaakt — kopieer 'm nu, hij wordt maar één keer getoond:<br>
        <code style="word-break:break-all;background:var(--hs-bg);padding:.3rem .5rem;border-radius:6px;display:inline-block;margin-top:.4rem;"><?= e($newToken) ?></code></span>
    </div>
<?php endif; ?>

<div class="hs-card" style="margin-bottom:1.5rem;max-width:520px;">
    <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Nieuw token aanmaken</h3>
    <form method="post" style="display:flex;gap:1rem;align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="hs-field" style="margin:0;flex:1;"><label for="name">Naam (bv. "Grafana-integratie")</label><input type="text" id="name" name="name"></div>
        <button type="submit" class="hs-btn hs-btn--primary"><?= hz_icon('plus') ?> Aanmaken</button>
    </form>
</div>

<div class="hs-table-wrap">
    <table class="hs-table">
        <thead><tr><th>Naam</th><th>Laatst gebruikt</th><th>Aangemaakt</th><th>Status</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['name']) ?></td>
                    <td><?= $t['last_used_at'] ? timeAgo($t['last_used_at']) : 'Nog niet gebruikt' ?></td>
                    <td><?= nlDate($t['created_at']) ?></td>
                    <td><?= $t['revoked_at'] ? '<span class="hs-status-pill hs-status-degraded">Ingetrokken</span>' : '<span class="hs-status-pill hs-status-up">Actief</span>' ?></td>
                    <td>
                        <?php if (!$t['revoked_at']): ?>
                            <form method="post" onsubmit="return confirm('Dit token intrekken? Integraties die dit token gebruiken werken dan niet meer.')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm">Intrekken</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="hs-card" style="margin-top:1.5rem;">
    <h3 class="hs-display" style="font-size:.95rem;margin:0 0 .6rem;">API-documentatie</h3>
    <p style="font-size:.85rem;color:var(--hs-text-muted);">Gebruik het token als Bearer-token:</p>
    <pre style="background:var(--hs-bg);padding:.9rem;border-radius:8px;font-size:.8rem;overflow-x:auto;">curl -H "Authorization: Bearer hst_..." <?= e(APP_URL) ?>/api/v1/monitors.php
curl -H "Authorization: Bearer hst_..." <?= e(APP_URL) ?>/api/v1/incidents.php</pre>
</div>
<?php renderAdminEnd(); ?>
