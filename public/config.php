<?php
declare(strict_types=1);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE', '/hanzestatus');
define('APP_URL', 'https://demo.hanzeonline.nl' . BASE);
define('DEMO_RESET_MINUTES', 30);

require_once __DIR__ . '/assets/icons.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/helpers.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/audit.php';
require_once __DIR__ . '/../core/notify.php';
require_once __DIR__ . '/../core/totp.php';
require_once __DIR__ . '/../core/simulate.php';
require_once __DIR__ . '/../core/livecheck.php';
require_once __DIR__ . '/../core/layout.php';

initSchema();
maybeReseedDemo();

/**
 * Demo-omgeving zelf-reseedt elke DEMO_RESET_MINUTES, net als de andere
 * HanzeOnline demo-apps, zodat de dataset na verloop van tijd weer fris is.
 */
function maybeReseedDemo(): void
{
    $pdo = db();
    $row = $pdo->query('SELECT last_reset FROM hst_meta WHERE id = 1')->fetch();
    $needsReset = false;
    if (!$row) {
        $pdo->exec('INSERT INTO hst_meta (id, last_reset) VALUES (1, NOW())');
        $needsReset = true;
    } else {
        $needsReset = (time() - strtotime((string) $row['last_reset'])) >= DEMO_RESET_MINUTES * 60;
    }
    if ($needsReset) {
        require_once __DIR__ . '/../core/seed.php';
        seedDemoData();
        $pdo->exec('UPDATE hst_meta SET last_reset = NOW() WHERE id = 1');
    }
}
