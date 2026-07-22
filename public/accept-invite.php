<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$stmt = db()->prepare(
    'SELECT i.*, w.name AS workspace_name FROM hst_invites i JOIN hst_workspaces w ON w.id = i.workspace_id
     WHERE i.token_hash = ? AND i.expires_at > NOW() AND i.accepted_at IS NULL'
);
$stmt->execute([hash('sha256', $token)]);
$invite = $stmt->fetch();

$errors = [];
if (!$invite) {
    $errors[] = 'Deze uitnodiging is ongeldig, al gebruikt of verlopen.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $existingStmt = db()->prepare('SELECT * FROM hst_users WHERE email = ?');
    $existingStmt->execute([$invite['email']]);
    $existingUser = $existingStmt->fetch();

    if ($existingUser) {
        $userId = (int) $existingUser['id'];
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($name) < 2) {
            $errors[] = 'Vul je naam in.';
        }
        if ($pwError = validatePasswordStrength($password)) {
            $errors[] = $pwError;
        }
        if (!$errors) {
            db()->prepare('INSERT INTO hst_users (email, password_hash, name) VALUES (?, ?, ?)')
                ->execute([$invite['email'], password_hash($password, PASSWORD_DEFAULT), $name]);
            $userId = (int) db()->lastInsertId();
        }
    }

    if (!$errors) {
        db()->prepare('INSERT IGNORE INTO hst_workspace_members (workspace_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$invite['workspace_id'], $userId, $invite['role']]);
        db()->prepare('UPDATE hst_invites SET accepted_at = NOW() WHERE id = ?')->execute([$invite['id']]);
        auditLog((int) $invite['workspace_id'], $userId, 'team.invite_accept', 'workspace_member', $userId, null);

        $_SESSION['user_id'] = $userId;
        $_SESSION['workspace_id'] = (int) $invite['workspace_id'];
        session_regenerate_id(true);
        header('Location: ' . BASE . '/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Uitnodiging accepteren — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:400px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <?php if ($errors): ?>
            <?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
            <a href="login.php" class="hs-btn hs-btn--secondary" style="width:100%;">Naar inloggen</a>
        <?php else: ?>
            <h2 class="hs-display" style="margin:0 0 .3rem;">Uitnodiging voor <?= e($invite['workspace_name']) ?></h2>
            <p style="color:var(--hs-text-muted);font-size:.88rem;margin-bottom:1.25rem;">Je bent uitgenodigd als <strong><?= e(ucfirst($invite['role'])) ?></strong> (<?= e($invite['email']) ?>).</p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="hs-field">
                    <label for="name">Je naam</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="hs-field">
                    <label for="password">Kies een wachtwoord</label>
                    <input type="password" id="password" name="password" required>
                    <p class="hs-hint">Minstens 10 tekens, 1 hoofdletter en 1 cijfer.</p>
                </div>
                <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Uitnodiging accepteren</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
