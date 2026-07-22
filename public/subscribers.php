<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireWorkspace();
$wsId = (int) $ws['id'];
$user = currentUser();
$canManage = in_array($ws['role'], ['owner', 'admin'], true);

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$canManage) {
        http_response_code(403);
        die('Geen toegang.');
    }
    $act = $_POST['action'] ?? '';

    if ($act === 'add') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!validateEmail($email)) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        } else {
            $dup = db()->prepare('SELECT 1 FROM hst_subscribers WHERE workspace_id = ? AND email = ?');
            $dup->execute([$wsId, $email]);
            if ($dup->fetch()) {
                $errors[] = 'Dit e-mailadres is al abonnee.';
            } else {
                db()->prepare('INSERT INTO hst_subscribers (workspace_id, email, confirmed_at) VALUES (?, ?, NOW())')->execute([$wsId, $email]);
                auditLog($wsId, (int) $user['id'], 'subscriber.add', 'subscriber', (int) db()->lastInsertId(), "Handmatig toegevoegd: $email");
                $success = 'Abonnee toegevoegd (direct bevestigd, want handmatig door het team toegevoegd).';
            }
        }
    } elseif ($act === 'remove') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT email FROM hst_subscribers WHERE id = ? AND workspace_id = ?');
        $stmt->execute([$id, $wsId]);
        $email = $stmt->fetchColumn();
        if ($email) {
            db()->prepare('DELETE FROM hst_subscribers WHERE id = ? AND workspace_id = ?')->execute([$id, $wsId]);
            auditLog($wsId, (int) $user['id'], 'subscriber.remove', 'subscriber', $id, "Verwijderd: $email");
        }
    }
}

$stmt = db()->prepare('SELECT * FROM hst_subscribers WHERE workspace_id = ? ORDER BY created_at DESC');
$stmt->execute([$wsId]);
$subscribers = $stmt->fetchAll();

renderAdminStart('subscribers', 'Abonnees');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> <?= e($success) ?></div><?php endif; ?>

<p style="color:var(--hs-text-muted);font-size:.85rem;margin-bottom:1.25rem;">
    Abonnees ontvangen (in het echt) een e-mail bij nieuwe incidenten en onderhoud. Deze demo verstuurt geen echte e-mails —
    bevestigingslinks worden net als bij andere acties direct op het scherm getoond.
</p>

<?php if ($canManage): ?>
<div class="hs-card" style="margin-bottom:1.5rem;max-width:520px;">
    <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Abonnee handmatig toevoegen</h3>
    <form method="post" style="display:flex;gap:1rem;align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="hs-field" style="margin:0;flex:1;"><label for="email">E-mailadres</label><input type="email" id="email" name="email" required></div>
        <button type="submit" class="hs-btn hs-btn--primary"><?= hz_icon('plus') ?> Toevoegen</button>
    </form>
</div>
<?php endif; ?>

<div class="hs-table-wrap">
    <table class="hs-table">
        <thead><tr><th>E-mailadres</th><th>Status</th><th>Aangemeld</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($subscribers as $s): ?>
                <tr>
                    <td><?= hz_icon('mail') ?> <?= e($s['email']) ?></td>
                    <td>
                        <?php if ($s['unsubscribed_at']): ?>
                            <span class="hs-status-pill hs-status-degraded">Uitgeschreven</span>
                        <?php elseif ($s['confirmed_at']): ?>
                            <span class="hs-status-pill hs-status-up">Bevestigd</span>
                        <?php else: ?>
                            <span class="hs-status-pill hs-status-degraded">Nog niet bevestigd</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--hs-text-muted);"><?= nlDate($s['created_at']) ?></td>
                    <td>
                        <?php if ($canManage): ?>
                            <form method="post" onsubmit="return confirm('Deze abonnee verwijderen?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm"><?= hz_icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$subscribers): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--hs-text-muted);padding:2rem;">Nog geen abonnees.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php renderAdminEnd(); ?>
