<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: ' . BASE . '/login.php');
    exit;
}
if (isLoggedIn()) {
    header('Location: ' . BASE . '/dashboard.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM hst_users WHERE id = ?');
$stmt->execute([$_SESSION['pending_2fa_user_id']]);
$user = $stmt->fetch();
if (!$user) {
    unset($_SESSION['pending_2fa_user_id']);
    header('Location: ' . BASE . '/login.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $code = (string) ($_POST['code'] ?? '');
    if (totpVerify($user['totp_secret'], $code)) {
        finalizeLogin($user);
        header('Location: ' . BASE . '/dashboard.php');
        exit;
    }
    $error = 'Ongeldige of verlopen verificatiecode.';
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Verificatie — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:400px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div class="hs-brand-mark" style="width:44px;height:44px;margin:0 auto .75rem;font-size:1.2rem;"><?= hz_icon('shield') ?></div>
            <h2 class="hs-display" style="margin:0;">Verificatie vereist</h2>
            <p style="color:var(--hs-text-muted);font-size:.88rem;">Voer de 6-cijferige code uit je authenticator-app in</p>
        </div>
        <?php if ($error): ?>
            <div class="hs-alert hs-alert--error" role="alert"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="hs-field">
                <label for="code">Verificatiecode</label>
                <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" style="text-align:center;letter-spacing:.5em;font-size:1.2rem;">
            </div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Verifiëren</button>
        </form>
        <p style="text-align:center;margin-top:1rem;font-size:.78rem;color:var(--hs-text-muted);">
            DEMO-modus: huidige geldige code is <strong style="font-family:'JetBrains Mono',monospace;"><?= e(totpCurrentCode($user['totp_secret'])) ?></strong> (ververst elke 30s)
        </p>
        <p style="text-align:center;margin-top:.75rem;">
            <a href="login.php" style="font-size:.85rem;color:var(--hs-text-muted);">&larr; Terug naar inloggen</a>
        </p>
    </div>
</body>
</html>
