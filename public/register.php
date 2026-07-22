<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    header('Location: ' . BASE . '/dashboard.php');
    exit;
}

$errors = [];
$values = ['name' => '', 'email' => '', 'workspace_name' => '', 'slug' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $values['name'] = trim((string) ($_POST['name'] ?? ''));
    $values['email'] = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $values['workspace_name'] = trim((string) ($_POST['workspace_name'] ?? ''));
    $values['slug'] = strtolower(trim((string) ($_POST['slug'] ?? '')));

    if (mb_strlen($values['name']) < 2) {
        $errors['name'] = 'Vul je volledige naam in.';
    }
    if (!validateEmail($values['email'])) {
        $errors['email'] = 'Vul een geldig e-mailadres in.';
    } else {
        $stmt = db()->prepare('SELECT 1 FROM hst_users WHERE email = ?');
        $stmt->execute([$values['email']]);
        if ($stmt->fetch()) {
            $errors['email'] = 'Dit e-mailadres is al in gebruik.';
        }
    }
    if ($pwError = validatePasswordStrength($password)) {
        $errors['password'] = $pwError;
    }
    if (mb_strlen($values['workspace_name']) < 2) {
        $errors['workspace_name'] = 'Vul een naam voor je workspace in.';
    }
    if (!validateSlug($values['slug'])) {
        $errors['slug'] = 'Gebruik alleen kleine letters, cijfers en koppeltekens (min. 3 tekens).';
    } else {
        $stmt = db()->prepare('SELECT 1 FROM hst_workspaces WHERE slug = ?');
        $stmt->execute([$values['slug']]);
        if ($stmt->fetch()) {
            $errors['slug'] = 'Deze URL is al bezet, kies een andere.';
        }
    }
    if (!rateLimit('register:' . clientIp(), 5, 3600)) {
        $errors['form'] = 'Te veel registratiepogingen vanaf dit IP-adres. Probeer het later opnieuw.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO hst_users (email, password_hash, name) VALUES (?, ?, ?)')
                ->execute([$values['email'], password_hash($password, PASSWORD_DEFAULT), $values['name']]);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO hst_workspaces (name, slug) VALUES (?, ?)')
                ->execute([$values['workspace_name'], $values['slug']]);
            $workspaceId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO hst_workspace_members (workspace_id, user_id, role) VALUES (?, ?, "owner")')
                ->execute([$workspaceId, $userId]);
            $pdo->prepare('INSERT INTO hst_settings (workspace_id) VALUES (?)')->execute([$workspaceId]);

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors['form'] = 'Er ging iets mis bij het aanmaken van je workspace. Probeer het opnieuw.';
        }

        if (!$errors) {
            $_SESSION['user_id'] = $userId;
            $_SESSION['workspace_id'] = $workspaceId;
            session_regenerate_id(true);
            auditLog($workspaceId, $userId, 'workspace.create', 'workspace', $workspaceId, 'Workspace aangemaakt bij registratie');
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
    <title>Registreren — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:440px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div class="hs-brand-mark" style="width:44px;height:44px;margin:0 auto .75rem;font-size:1.2rem;"><?= hz_icon('activity') ?></div>
            <h2 class="hs-display" style="margin:0;">Start je workspace</h2>
            <p style="color:var(--hs-text-muted);font-size:.88rem;">Gratis, in minder dan een minuut</p>
        </div>
        <?php if (!empty($errors['form'])): ?>
            <div class="hs-alert hs-alert--error" role="alert"><?= hz_icon('alert-triangle') ?> <?= e($errors['form']) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="hs-field <?= isset($errors['name']) ? 'hs-has-error' : '' ?>">
                <label for="name">Je naam</label>
                <input type="text" id="name" name="name" required value="<?= e($values['name']) ?>">
                <?php if (isset($errors['name'])): ?><p class="hs-field-error"><?= hz_icon('alert-triangle') ?> <?= e($errors['name']) ?></p><?php endif; ?>
            </div>
            <div class="hs-field <?= isset($errors['email']) ? 'hs-has-error' : '' ?>">
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" required value="<?= e($values['email']) ?>">
                <?php if (isset($errors['email'])): ?><p class="hs-field-error"><?= hz_icon('alert-triangle') ?> <?= e($errors['email']) ?></p><?php endif; ?>
            </div>
            <div class="hs-field <?= isset($errors['password']) ? 'hs-has-error' : '' ?>">
                <label for="password">Wachtwoord</label>
                <input type="password" id="password" name="password" required>
                <p class="hs-hint">Minstens 10 tekens, 1 hoofdletter en 1 cijfer.</p>
                <?php if (isset($errors['password'])): ?><p class="hs-field-error"><?= hz_icon('alert-triangle') ?> <?= e($errors['password']) ?></p><?php endif; ?>
            </div>
            <div class="hs-field <?= isset($errors['workspace_name']) ? 'hs-has-error' : '' ?>">
                <label for="workspace_name">Naam van je workspace</label>
                <input type="text" id="workspace_name" name="workspace_name" required value="<?= e($values['workspace_name']) ?>" placeholder="bv. Acme Cloud">
                <?php if (isset($errors['workspace_name'])): ?><p class="hs-field-error"><?= hz_icon('alert-triangle') ?> <?= e($errors['workspace_name']) ?></p><?php endif; ?>
            </div>
            <div class="hs-field <?= isset($errors['slug']) ? 'hs-has-error' : '' ?>">
                <label for="slug">Statuspagina-URL</label>
                <input type="text" id="slug" name="slug" required value="<?= e($values['slug']) ?>" placeholder="acme">
                <p class="hs-hint">demo.hanzeonline.nl/hanzestatus/status.php?w=<?= e($values['slug'] ?: 'acme') ?></p>
                <?php if (isset($errors['slug'])): ?><p class="hs-field-error"><?= hz_icon('alert-triangle') ?> <?= e($errors['slug']) ?></p><?php endif; ?>
            </div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Workspace aanmaken</button>
        </form>
        <p style="text-align:center;margin-top:1.25rem;font-size:.85rem;">
            Heb je al een account? <a href="login.php" style="color:var(--hs-primary);text-decoration:none;">Inloggen</a>
        </p>
    </div>
</body>
</html>
