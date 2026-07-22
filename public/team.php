<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireRole(['owner', 'admin']);
$wsId = (int) $ws['id'];
$user = currentUser();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'invite') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'member');
        if (!validateEmail($email)) {
            $errors[] = 'Vul een geldig e-mailadres in.';
        } elseif (!in_array($role, ['admin', 'member'], true)) {
            $errors[] = 'Ongeldige rol.';
        } else {
            $memberCheck = db()->prepare('SELECT 1 FROM hst_workspace_members m JOIN hst_users u ON u.id = m.user_id WHERE m.workspace_id = ? AND u.email = ?');
            $memberCheck->execute([$wsId, $email]);
            if ($memberCheck->fetch()) {
                $errors[] = 'Dit e-mailadres is al lid van de workspace.';
            } else {
                $token = bin2hex(random_bytes(24));
                db()->prepare('INSERT INTO hst_invites (workspace_id, email, role, token_hash, invited_by_user_id, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL 7 DAY)')
                    ->execute([$wsId, $email, $role, hash('sha256', $token), $user['id']]);
                auditLog($wsId, (int) $user['id'], 'team.invite', 'invite', null, "Uitgenodigd: $email als $role");
                $success = 'Uitnodigingslink aangemaakt (DEMO: geen echte e-mail verstuurd): ' . APP_URL . '/accept-invite.php?token=' . $token;
            }
        }
    } elseif ($act === 'change_role') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');
        if (in_array($role, ['admin', 'member'], true)) {
            $memberStmt = db()->prepare('SELECT * FROM hst_workspace_members WHERE id = ? AND workspace_id = ?');
            $memberStmt->execute([$memberId, $wsId]);
            $member = $memberStmt->fetch();
            if ($member && $member['role'] !== 'owner') {
                db()->prepare('UPDATE hst_workspace_members SET role = ? WHERE id = ?')->execute([$role, $memberId]);
                auditLog($wsId, (int) $user['id'], 'team.role_change', 'workspace_member', $memberId, "Rol -> $role");
            } else {
                $errors[] = 'De eigenaar-rol kan niet worden gewijzigd.';
            }
        }
    } elseif ($act === 'remove') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $memberStmt = db()->prepare('SELECT * FROM hst_workspace_members WHERE id = ? AND workspace_id = ?');
        $memberStmt->execute([$memberId, $wsId]);
        $member = $memberStmt->fetch();
        if ($member && $member['role'] !== 'owner') {
            db()->prepare('DELETE FROM hst_workspace_members WHERE id = ?')->execute([$memberId]);
            auditLog($wsId, (int) $user['id'], 'team.remove', 'workspace_member', $memberId, null);
        } else {
            $errors[] = 'De eigenaar kan niet verwijderd worden.';
        }
    }
}

$stmt = db()->prepare(
    'SELECT m.id AS member_id, m.role, u.name, u.email, u.avatar_color, u.last_login_at
     FROM hst_workspace_members m JOIN hst_users u ON u.id = m.user_id
     WHERE m.workspace_id = ? ORDER BY FIELD(m.role, "owner","admin","member"), u.name'
);
$stmt->execute([$wsId]);
$members = $stmt->fetchAll();

$inviteStmt = db()->prepare("SELECT * FROM hst_invites WHERE workspace_id = ? AND accepted_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC");
$inviteStmt->execute([$wsId]);
$pendingInvites = $inviteStmt->fetchAll();

renderAdminStart('team', 'Team');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--info"><?= hz_icon('activity') ?> <span style="word-break:break-all;"><?= e($success) ?></span></div><?php endif; ?>

<div class="hs-card" style="margin-bottom:1.5rem;">
    <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Teamlid uitnodigen</h3>
    <form method="post" style="display:grid;grid-template-columns:2fr 1fr auto;gap:1rem;align-items:end;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="invite">
        <div class="hs-field" style="margin:0;"><label for="invite_email">E-mailadres</label><input type="email" id="invite_email" name="email" required></div>
        <div class="hs-field" style="margin:0;">
            <label for="invite_role">Rol</label>
            <select id="invite_role" name="role" style="width:100%;">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
            </select>
        </div>
        <button type="submit" class="hs-btn hs-btn--primary"><?= hz_icon('plus') ?> Uitnodigen</button>
    </form>
</div>

<div class="hs-table-wrap" style="margin-bottom:1.5rem;">
    <table class="hs-table">
        <thead><tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Laatst actief</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td style="display:flex;align-items:center;gap:.6rem;"><span class="hs-avatar" style="background:<?= e($m['avatar_color']) ?>;width:28px;height:28px;font-size:.7rem;"><?= e(initials($m['name'])) ?></span><?= e($m['name']) ?></td>
                    <td><?= e($m['email']) ?></td>
                    <td>
                        <?php if ($m['role'] === 'owner'): ?>
                            <span class="hs-status-pill hs-status-up">Owner</span>
                        <?php else: ?>
                            <form method="post" style="display:inline;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_role">
                                <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">
                                <select name="role" style="padding:.4rem .6rem;font-size:.78rem;border:1px solid var(--hs-border);border-radius:8px;background:var(--hs-surface);color:var(--hs-text);font-family:inherit;" onchange="this.form.submit()">
                                    <option value="member" <?= $m['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                    <option value="admin" <?= $m['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--hs-text-muted);"><?= $m['last_login_at'] ? timeAgo($m['last_login_at']) : 'Nooit' ?></td>
                    <td>
                        <?php if ($m['role'] !== 'owner'): ?>
                            <form method="post" onsubmit="return confirm('Teamlid verwijderen uit deze workspace?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="member_id" value="<?= (int) $m['member_id'] ?>">
                                <button type="submit" class="hs-btn hs-btn--ghost hs-btn--sm"><?= hz_icon('trash') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($pendingInvites): ?>
    <div class="hs-card">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Openstaande uitnodigingen</h3>
        <table class="hs-table">
            <thead><tr><th>E-mail</th><th>Rol</th><th>Verloopt</th></tr></thead>
            <tbody>
                <?php foreach ($pendingInvites as $inv): ?>
                    <tr><td><?= e($inv['email']) ?></td><td><span class="hs-status-pill hs-status-degraded"><?= e(ucfirst($inv['role'])) ?></span></td><td><?= nlDate($inv['expires_at']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php renderAdminEnd(); ?>
