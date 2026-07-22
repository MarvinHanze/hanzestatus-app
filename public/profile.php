<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
requireAuth();
$user = currentUser();
$userId = (int) $user['id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $color = (string) ($_POST['avatar_color'] ?? '#059669');
        if (mb_strlen($name) < 2) {
            $errors[] = 'Vul een geldige naam in.';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = 'Ongeldige kleurcode.';
        } else {
            db()->prepare('UPDATE hst_users SET name = ?, avatar_color = ? WHERE id = ?')->execute([$name, $color, $userId]);
            $success = 'Profiel bijgewerkt.';
        }
    } elseif ($act === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            $errors[] = 'Huidig wachtwoord is onjuist.';
        } elseif ($pwError = validatePasswordStrength($new)) {
            $errors[] = $pwError;
        } else {
            db()->prepare('UPDATE hst_users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            $success = 'Wachtwoord bijgewerkt.';
        }
    } elseif ($act === 'enable_2fa') {
        $code = (string) ($_POST['code'] ?? '');
        $pendingSecret = $_SESSION['pending_totp_secret'] ?? '';
        if ($pendingSecret && totpVerify($pendingSecret, $code)) {
            db()->prepare('UPDATE hst_users SET totp_secret = ?, totp_enabled = 1, totp_confirmed_at = NOW() WHERE id = ?')
                ->execute([$pendingSecret, $userId]);
            unset($_SESSION['pending_totp_secret']);
            $success = 'Tweestapsverificatie is ingeschakeld.';
        } else {
            $errors[] = 'Ongeldige verificatiecode.';
        }
    } elseif ($act === 'disable_2fa') {
        db()->prepare('UPDATE hst_users SET totp_secret = NULL, totp_enabled = 0, totp_confirmed_at = NULL WHERE id = ?')->execute([$userId]);
        $success = 'Tweestapsverificatie is uitgeschakeld.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT * FROM hst_users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    }
}

if (empty($user['totp_enabled']) && empty($_SESSION['pending_totp_secret'])) {
    $_SESSION['pending_totp_secret'] = totpGenerateSecret();
}
$pendingSecret = $_SESSION['pending_totp_secret'] ?? '';

renderAdminStart('profile', 'Profiel');
?>
<?php foreach ($errors as $err): ?><div class="hs-alert hs-alert--error"><?= hz_icon('alert-triangle') ?> <?= e($err) ?></div><?php endforeach; ?>
<?php if ($success): ?><div class="hs-alert hs-alert--success"><?= hz_icon('check-circle') ?> <?= e($success) ?></div><?php endif; ?>

<div class="hs-grid" style="grid-template-columns:1fr 1fr;align-items:start;gap:1.5rem;">
    <div class="hs-card">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Profielgegevens</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="hs-field">
                <label for="name">Naam</label>
                <input type="text" id="name" name="name" required value="<?= e($user['name']) ?>">
            </div>
            <div class="hs-field">
                <label>E-mailadres</label>
                <p style="font-size:.85rem;color:var(--hs-text-muted);"><?= e($user['email']) ?> <span style="font-size:.75rem;">(niet wijzigbaar in deze demo)</span></p>
            </div>
            <div class="hs-field">
                <label for="avatar_color">Avatarkleur</label>
                <input type="color" id="avatar_color" name="avatar_color" value="<?= e($user['avatar_color']) ?>" style="height:44px;padding:.3rem;">
            </div>
            <button type="submit" class="hs-btn hs-btn--primary">Opslaan</button>
        </form>
    </div>

    <div class="hs-card">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Wachtwoord wijzigen</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="hs-field"><label for="current_password">Huidig wachtwoord</label><input type="password" id="current_password" name="current_password" required></div>
            <div class="hs-field">
                <label for="new_password">Nieuw wachtwoord</label>
                <input type="password" id="new_password" name="new_password" required>
                <p class="hs-hint">Minstens 10 tekens, 1 hoofdletter en 1 cijfer.</p>
            </div>
            <button type="submit" class="hs-btn hs-btn--primary">Wachtwoord bijwerken</button>
        </form>
    </div>

    <div class="hs-card" style="grid-column:1/-1;">
        <h3 class="hs-display" style="font-size:1.05rem;margin:0 0 1rem;">Tweestapsverificatie (2FA)</h3>
        <?php if (!empty($user['totp_enabled'])): ?>
            <p style="color:var(--hs-up);font-weight:600;margin-bottom:1rem;"><?= hz_icon('check-circle') ?> 2FA is ingeschakeld voor je account.</p>
            <form method="post" onsubmit="return confirm('2FA uitschakelen? Dit maakt je account minder veilig.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="disable_2fa">
                <button type="submit" class="hs-btn hs-btn--danger hs-btn--sm">2FA uitschakelen</button>
            </form>
        <?php else: ?>
            <p style="color:var(--hs-text-muted);font-size:.88rem;margin-bottom:1rem;">Voeg een authenticator-app toe (Google Authenticator, 1Password, etc.) met onderstaande sleutel, en bevestig met de gegenereerde code.</p>
            <p style="font-family:'JetBrains Mono',monospace;background:var(--hs-bg);padding:.6rem 1rem;border-radius:8px;display:inline-block;margin-bottom:1rem;letter-spacing:.1em;"><?= e($pendingSecret) ?></p>
            <form method="post" style="display:flex;gap:.75rem;align-items:end;max-width:340px;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="enable_2fa">
                <div class="hs-field" style="margin:0;flex:1;"><label for="code">Verificatiecode</label><input type="text" id="code" name="code" required inputmode="numeric" maxlength="6" pattern="[0-9]{6}"></div>
                <button type="submit" class="hs-btn hs-btn--primary">Inschakelen</button>
            </form>
            <p style="font-size:.78rem;color:var(--hs-text-muted);margin-top:.75rem;">DEMO-modus: huidige geldige code is <strong style="font-family:'JetBrains Mono',monospace;"><?= e(totpCurrentCode($pendingSecret)) ?></strong></p>
        <?php endif; ?>
    </div>
</div>
<?php renderAdminEnd(); ?>
