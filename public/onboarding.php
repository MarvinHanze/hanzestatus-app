<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();

if (currentWorkspace()) {
    header('Location: ' . BASE . '/dashboard.php');
    exit;
}

$user = currentUser();
$errors = [];
$values = ['workspace_name' => '', 'slug' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $values['workspace_name'] = trim((string) ($_POST['workspace_name'] ?? ''));
    $values['slug'] = strtolower(trim((string) ($_POST['slug'] ?? '')));

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

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO hst_workspaces (name, slug) VALUES (?, ?)')->execute([$values['workspace_name'], $values['slug']]);
            $workspaceId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO hst_workspace_members (workspace_id, user_id, role) VALUES (?, ?, "owner")')->execute([$workspaceId, $user['id']]);
            $pdo->prepare('INSERT INTO hst_settings (workspace_id) VALUES (?)')->execute([$workspaceId]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors['form'] = 'Er ging iets mis. Probeer het opnieuw.';
        }
        if (!$errors) {
            $_SESSION['workspace_id'] = $workspaceId;
            auditLog($workspaceId, (int) $user['id'], 'workspace.create', 'workspace', $workspaceId, null);
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
    <title>Workspace aanmaken — HanzeStatus</title>
    <base href="<?= e(BASE) ?>/">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1.5rem;">
    <div class="hs-card" style="max-width:420px;width:100%;box-shadow:var(--hs-shadow-lg);">
        <h2 class="hs-display" style="margin:0 0 .3rem;">Nog geen workspace</h2>
        <p style="color:var(--hs-text-muted);font-size:.88rem;margin-bottom:1.25rem;">Maak een workspace aan om je monitors en statuspagina te beheren.</p>
        <?php if (!empty($errors['form'])): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($errors['form']) ?></div><?php endif; ?>
        <form method="post" novalidate>
            <?= csrfField() ?>
            <div class="hs-field <?= isset($errors['workspace_name']) ? 'hs-has-error' : '' ?>">
                <label for="workspace_name">Naam van je workspace</label>
                <input type="text" id="workspace_name" name="workspace_name" required value="<?= e($values['workspace_name']) ?>">
                <?php if (isset($errors['workspace_name'])): ?><p class="hs-field-error"><?= e($errors['workspace_name']) ?></p><?php endif; ?>
            </div>
            <div class="hs-field <?= isset($errors['slug']) ? 'hs-has-error' : '' ?>">
                <label for="slug">Statuspagina-URL</label>
                <input type="text" id="slug" name="slug" required value="<?= e($values['slug']) ?>">
                <?php if (isset($errors['slug'])): ?><p class="hs-field-error"><?= e($errors['slug']) ?></p><?php endif; ?>
            </div>
            <button type="submit" class="hs-btn hs-btn--primary" style="width:100%;">Workspace aanmaken</button>
        </form>
    </div>
</body>
</html>
