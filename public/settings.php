<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$ws = requireRole(['owner', 'admin']);
$wsId = (int) $ws['id'];
$user = currentUser();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'update_workspace') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
        $brandColor = (string) ($_POST['brand_color'] ?? '#059669');
        if (mb_strlen($name) < 2) {
            $errors[] = 'Vul een geldige naam in.';
        } elseif (!validateSlug($slug)) {
            $errors[] = 'Gebruik alleen kleine letters, cijfers en koppeltekens voor de URL (min. 3 tekens).';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor)) {
            $errors[] = 'Ongeldige kleurcode.';
        } else {
            $dupStmt = db()->prepare('SELECT 1 FROM hst_workspaces WHERE slug = ? AND id != ?');
            $dupStmt->execute([$slug, $wsId]);
            if ($dupStmt->fetch()) {
                $errors[] = 'Deze URL is al bezet, kies een andere.';
            } else {
                db()->prepare('UPDATE hst_workspaces SET name = ?, slug = ?, brand_color = ? WHERE id = ?')->execute([$name, $slug, $brandColor, $wsId]);
                auditLog($wsId, (int) $user['id'], 'settings.update', 'workspace', $wsId, 'Naam/URL/kleur bijgewerkt');
                $success = 'Instellingen opgeslagen.';
            }
        }
    } elseif ($act === 'update_public') {
        $intro = trim((string) ($_POST['public_intro'] ?? ''));
        $notifyIncident = isset($_POST['notify_on_incident']) ? 1 : 0;
        $notifyDown = isset($_POST['notify_on_monitor_down']) ? 1 : 0;
        $notifyRecovery = isset($_POST['notify_on_monitor_recovery']) ? 1 : 0;
        db()->prepare('UPDATE hst_settings SET public_intro = ?, notify_on_incident = ?, notify_on_monitor_down = ?, notify_on_monitor_recovery = ? WHERE workspace_id = ?')
            ->execute([$intro, $notifyIncident, $notifyDown, $notifyRecovery, $wsId]);
        auditLog($wsId, (int) $user['id'], 'settings.update', 'workspace_settings', $wsId, null);
        $success = 'Instellingen opgeslagen.';
    }
}

$stmt = db()->prepare('SELECT * FROM hst_workspaces WHERE id = ?');
$stmt->execute([$wsId]);
$workspace = $stmt->fetch();

$settingsStmt = db()->prepare('SELECT * FROM hst_settings WHERE workspace_id = ?');
$settingsStmt->execute([$wsId]);
$settings = $settingsStmt->fetch();

$tab = (string) ($_GET['tab'] ?? 'algemeen');

renderAdminStart('settings', 'Instellingen');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> <?= e($success) ?></div><?php endif; ?>

<div class="hs-tabs">
    <a href="?tab=algemeen" class="hs-tab <?= $tab === 'algemeen' ? 'hs-is-active' : '' ?>">Algemeen</a>
    <a href="?tab=publiek" class="hs-tab <?= $tab === 'publiek' ? 'hs-is-active' : '' ?>">Statuspagina & notificaties</a>
</div>

<?php if ($tab === 'algemeen'): ?>
    <div class="hs-card" style="max-width:520px;">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_workspace">
            <div class="hs-field">
                <label for="name">Workspace-naam</label>
                <input type="text" id="name" name="name" required value="<?= e($workspace['name']) ?>">
            </div>
            <div class="hs-field">
                <label for="slug">Statuspagina-URL</label>
                <input type="text" id="slug" name="slug" required value="<?= e($workspace['slug']) ?>">
                <p class="hs-hint"><?= e(APP_URL) ?>/status.php?w=<?= e($workspace['slug']) ?></p>
            </div>
            <div class="hs-field">
                <label for="brand_color">Merkkleur</label>
                <input type="color" id="brand_color" name="brand_color" value="<?= e($workspace['brand_color']) ?>" style="height:44px;padding:.3rem;">
            </div>
            <button type="submit" class="hs-btn hs-btn--primary">Opslaan</button>
        </form>
    </div>
<?php else: ?>
    <div class="hs-card" style="max-width:520px;">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_public">
            <div class="hs-field">
                <label for="public_intro">Introductietekst op de publieke statuspagina</label>
                <textarea id="public_intro" name="public_intro" rows="2" maxlength="300"><?= e($settings['public_intro']) ?></textarea>
            </div>
            <div class="hs-checkbox-row" style="margin-bottom:.8rem;">
                <input type="checkbox" id="notify_on_incident" name="notify_on_incident" value="1" <?= $settings['notify_on_incident'] ? 'checked' : '' ?>>
                <label for="notify_on_incident" style="margin:0;">Team notificeren bij nieuw/opgelost incident</label>
            </div>
            <div class="hs-checkbox-row" style="margin-bottom:.8rem;">
                <input type="checkbox" id="notify_on_monitor_down" name="notify_on_monitor_down" value="1" <?= $settings['notify_on_monitor_down'] ? 'checked' : '' ?>>
                <label for="notify_on_monitor_down" style="margin:0;">Team notificeren als een monitor offline gaat</label>
            </div>
            <div class="hs-checkbox-row" style="margin-bottom:1rem;">
                <input type="checkbox" id="notify_on_monitor_recovery" name="notify_on_monitor_recovery" value="1" <?= $settings['notify_on_monitor_recovery'] ? 'checked' : '' ?>>
                <label for="notify_on_monitor_recovery" style="margin:0;">Team notificeren als een monitor herstelt</label>
            </div>
            <button type="submit" class="hs-btn hs-btn--primary">Opslaan</button>
        </form>
    </div>
<?php endif; ?>
<?php renderAdminEnd(); ?>
