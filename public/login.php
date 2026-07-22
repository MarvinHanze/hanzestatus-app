<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    header('Location: ' . BASE . '/dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!validateEmail($email) || $password === '') {
        $error = 'Vul een geldig e-mailadres en wachtwoord in.';
    } else {
        $result = attemptLogin($email, $password);
        if (!$result['success']) {
            $error = $result['error'];
        } elseif (!empty($result['needs_2fa'])) {
            header('Location: ' . BASE . '/login-2fa.php');
            exit;
        } else {
            header('Location: ' . BASE . '/dashboard.php');
            exit;
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
    <title>Inloggen — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:400px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div class="hs-brand-mark" style="width:44px;height:44px;margin:0 auto .75rem;font-size:1.2rem;"><?= hz_icon('activity') ?></div>
            <h2 class="hs-display" style="margin:0;">Welkom terug</h2>
            <p style="color:var(--hs-text-muted);font-size:.88rem;">Log in op je HanzeStatus-workspace</p>
        </div>
        <?php if ($error): ?>
            <div class="hs-alert hs-alert--error" role="alert"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="hs-field">
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" required autocomplete="username" value="eigenaar@hanzestatus-demo.nl">
            </div>
            <div class="hs-field">
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" value="demo123">
            </div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Inloggen</button>
        </form>
        <p style="text-align:center;margin-top:1.25rem;font-size:.82rem;color:var(--hs-text-muted);">
            Demo: eigenaar@hanzestatus-demo.nl / demo123 (owner) — of ops@hanzestatus-demo.nl (admin), support@hanzestatus-demo.nl (member)
        </p>
        <p style="text-align:center;margin-top:.6rem;font-size:.85rem;">
            <a href="forgot-password.php" style="color:var(--hs-primary);text-decoration:none;">Wachtwoord vergeten?</a>
            &nbsp;·&nbsp;
            <a href="register.php" style="color:var(--hs-primary);text-decoration:none;">Nieuwe workspace aanmaken</a>
        </p>
        <p style="text-align:center;margin-top:.75rem;">
            <a href="status.php?w=demo" style="font-size:.82rem;color:var(--hs-text-muted);">Bekijk de publieke demo-statuspagina &rarr;</a>
        </p>
    </div>
</body>
</html>
