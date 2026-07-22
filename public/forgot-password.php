<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

$resetLink = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim((string) ($_POST['email'] ?? ''));

    if (!validateEmail($email)) {
        $error = 'Vul een geldig e-mailadres in.';
    } elseif (!rateLimit('reset:' . clientIp(), 5, 900)) {
        $error = 'Te veel aanvragen. Probeer het over 15 minuten opnieuw.';
    } else {
        $stmt = db()->prepare('SELECT id FROM hst_users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        // Altijd dezelfde melding tonen, ongeacht of het e-mailadres bestaat
        // (voorkomt dat een aanvaller e-mailadressen kan raden/valideren).
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO hst_password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)')
                ->execute([$user['id'], hash('sha256', $token)]);
            $resetLink = APP_URL . '/reset-password.php?token=' . $token;
        } else {
            $resetLink = '__sent__';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Wachtwoord vergeten — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:400px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <h2 class="hs-display" style="margin:0 0 .3rem;">Wachtwoord vergeten</h2>
        <p style="color:var(--hs-text-muted);font-size:.88rem;margin-bottom:1.25rem;">Vul je e-mailadres in, dan sturen we een resetlink.</p>

        <?php if ($error): ?>
            <div class="hs-alert hs-alert--error" role="alert"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($resetLink === '__sent__'): ?>
            <div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> Als dit e-mailadres bestaat, is er een resetlink verstuurd.</div>
        <?php elseif ($resetLink): ?>
            <div class="hs-alert hs-alert--info">
                <?= hz_icon('activity') ?>
                <span>
                    <strong>DEMO-modus:</strong> er is geen echte e-mailverzending gekoppeld. Gebruik deze link direct:<br>
                    <a href="<?= e($resetLink) ?>" style="word-break:break-all;color:var(--hs-primary-dark);"><?= e($resetLink) ?></a>
                </span>
            </div>
        <?php else: ?>
            <form method="post" novalidate>
                <?= csrfField() ?>
                <div class="hs-field">
                    <label for="email">E-mailadres</label>
                    <input type="email" id="email" name="email" required autocomplete="username">
                </div>
                <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Resetlink versturen</button>
            </form>
        <?php endif; ?>
        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;">
            <a href="login.php" style="color:var(--hs-primary);text-decoration:none;">&larr; Terug naar inloggen</a>
        </p>
    </div>
</body>
</html>
