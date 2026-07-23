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
        .hs-noc-links { text-align: center; margin-top: .8rem; font-size: .8rem; }
        .hs-noc-links a { color: #34d399; text-decoration: none; }
    </style>
</head>
<body class="hs-noc-scene">
    <div class="hs-noc-card">
        <div class="hs-noc-title">
            <div class="hs-pulse-wrap"><span class="hs-pulse-dot"></span><span class="hs-mono-label">verification required</span></div>
            <h2>Verificatie vereist</h2>
            <p>Voer de 6-cijferige code uit je authenticator-app in</p>
        </div>
        <?php if ($error): ?>
            <div class="hs-alert hs-alert--error" role="alert" style="margin-bottom:1rem;"><?= hz_icon('alert-triangle') ?> <?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="hs-field">
                <label for="code">Verificatiecode</label>
                <input type="text" id="code" name="code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" style="text-align:center;letter-spacing:.5em;font-size:1.2rem;">
            </div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Verifiëren</button>
        </form>
        <p class="hs-noc-footer">
            DEMO-modus: huidige geldige code is <strong style="color:#5eead4;"><?= e(totpCurrentCode($user['totp_secret'])) ?></strong><br>(ververst elke 30s)
        </p>
        <p class="hs-noc-links">
            <a href="login.php">&larr; Terug naar inloggen</a>
        </p>
    </div>
</body>
</html>
