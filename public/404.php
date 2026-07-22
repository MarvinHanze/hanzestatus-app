<?php
declare(strict_types=1);
if (!defined('BASE')) {
    define('BASE', '/hanzestatus');
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Niet gevonden — HanzeStatus</title>
    <base href="<?= htmlspecialchars(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div style="text-align:center;padding:2rem;">
        <h1 class="hs-display" style="font-size:2rem;">404 — Niet gevonden</h1>
        <p style="color:var(--hs-text-muted);margin:.5rem 0 1.5rem;">Deze pagina bestaat niet (meer).</p>
        <a href="dashboard.php" class="hs-btn hs-btn--primary">Terug naar dashboard</a>
    </div>
</body>
</html>
