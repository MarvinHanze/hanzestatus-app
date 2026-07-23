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
    <style>
        .hs-noc-scene {
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
            background:
                radial-gradient(ellipse 700px 500px at 50% 0%, rgba(16,185,129,.14), transparent 60%),
                linear-gradient(180deg, #06110d 0%, #050a08 100%);
            color: #e6faf2;
        }
        .hs-noc-card {
            max-width: 400px; width: 100%; background: #0a1713; border: 1px solid #17342a;
            border-radius: 10px; padding: 2rem 1.85rem;
            box-shadow: 0 0 0 1px rgba(16,185,129,.06), 0 20px 60px -20px rgba(0,0,0,.7);
        }
        .hs-pulse-wrap { display: flex; align-items: center; justify-content: center; gap: .6rem; margin-bottom: 1rem; }
        .hs-pulse-dot { position: relative; width: 14px; height: 14px; border-radius: 50%; background: #10b981; flex-shrink: 0; }
        .hs-pulse-dot::before, .hs-pulse-dot::after {
            content: ''; position: absolute; inset: 0; border-radius: 50%; background: #10b981;
            animation: hs-pulse-ring 2.2s cubic-bezier(0,.4,.6,1) infinite;
        }
        .hs-pulse-dot::after { animation-delay: 1.1s; }
        @keyframes hs-pulse-ring {
            0% { transform: scale(1); opacity: .65; }
            100% { transform: scale(3.4); opacity: 0; }
        }
        .hs-mono-label { font-family: 'JetBrains Mono', 'SFMono-Regular', monospace; font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: #5eead4; }
        .hs-noc-title { text-align: center; margin: 0 0 1.5rem; }
        .hs-noc-title h2 { margin: .35rem 0 .3rem; font-size: 1.35rem; color: #ecfdf5; }
        .hs-noc-title p { margin: 0; font-size: .85rem; color: #6b9284; }
        .hs-noc-card label { color: #9fc7b8 !important; font-family: 'JetBrains Mono', monospace; font-size: .74rem !important; letter-spacing: .04em; text-transform: uppercase; }
        .hs-noc-card input { background: #04100b !important; border: 1px solid #1c3a2f !important; color: #e6faf2 !important; font-family: 'JetBrains Mono', monospace; }
        .hs-noc-card input:focus { border-color: #10b981 !important; box-shadow: 0 0 0 3px rgba(16,185,129,.15) !important; }
        .hs-noc-card .hs-btn--primary { background: #10b981; color: #04100b; font-weight: 700; letter-spacing: .02em; }
        .hs-noc-card .hs-btn--primary:hover { background: #34d399; }
        .hs-noc-footer { text-align: center; font-family: 'JetBrains Mono', monospace; font-size: .74rem; color: #588071; margin-top: 1.15rem; line-height: 1.6; }
        .hs-noc-footer a { color: #5eead4; text-decoration: none; }
        .hs-noc-links { text-align: center; margin-top: .8rem; font-size: .8rem; }
        .hs-noc-links a { color: #34d399; text-decoration: none; }
    </style>
</head>
<body class="hs-noc-scene">
    <div class="hs-noc-card">
        <div class="hs-noc-title">
            <div class="hs-pulse-wrap"><span class="hs-pulse-dot"></span><span class="hs-mono-label">all systems operational</span></div>
            <h2>Welkom terug</h2>
            <p>Log in op je HanzeStatus-workspace</p>
        </div>
        <?php if ($error): ?>
            <div class="hs-alert hs-alert--error" role="alert" style="margin-bottom:1rem;"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div>
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
        <p class="hs-noc-footer">
            demo: eigenaar@hanzestatus-demo.nl / demo123 (owner)<br>
            of ops@hanzestatus-demo.nl (admin), support@hanzestatus-demo.nl (member)
        </p>
        <p class="hs-noc-links">
            <a href="forgot-password.php">Wachtwoord vergeten?</a>
            &nbsp;·&nbsp;
            <a href="register.php">Nieuwe workspace aanmaken</a>
        </p>
        <p class="hs-noc-links" style="margin-top:.6rem;">
            <a href="status.php?w=demo">Bekijk de publieke demo-statuspagina &rarr;</a>
        </p>
    </div>
</body>
</html>
