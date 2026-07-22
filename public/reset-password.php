<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$tokenHash = hash('sha256', $token);
$stmt = db()->prepare(
    'SELECT r.*, u.email FROM hst_password_resets r JOIN hst_users u ON u.id = r.user_id
     WHERE r.token_hash = ? AND r.expires_at > NOW() AND r.used_at IS NULL'
);
$stmt->execute([$tokenHash]);
$reset = $stmt->fetch();

$error = '';
$done = false;

if (!$reset) {
    $error = 'Deze resetlink is ongeldig of verlopen. Vraag een nieuwe aan.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $password = (string) ($_POST['password'] ?? '');
    if ($pwError = validatePasswordStrength($password)) {
        $error = $pwError;
    } else {
        db()->prepare('UPDATE hst_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        db()->prepare('UPDATE hst_password_resets SET used_at = NOW() WHERE id = ?')->execute([$reset['id']]);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Nieuw wachtwoord — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:400px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <h2 class="hs-display" style="margin:0 0 1rem;">Nieuw wachtwoord instellen</h2>
        <?php if ($done): ?>
            <div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> Je wachtwoord is bijgewerkt.</div>
            <a href="login.php" class="hs-btn hs-btn--primary" style="width:100%;">Naar inloggen</a>
        <?php else: ?>
            <?php if ($error): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div><?php endif; ?>
            <?php if ($reset): ?>
                <form method="post" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="hs-field">
                        <label for="password">Nieuw wachtwoord voor <?= e($reset['email']) ?></label>
                        <input type="password" id="password" name="password" required>
                        <p class="hs-hint">Minstens 10 tekens, 1 hoofdletter en 1 cijfer.</p>
                    </div>
                    <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Wachtwoord bijwerken</button>
                </form>
            <?php else: ?>
                <a href="forgot-password.php" class="hs-btn hs-btn--secondary" style="width:100%;">Nieuwe resetlink aanvragen</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
