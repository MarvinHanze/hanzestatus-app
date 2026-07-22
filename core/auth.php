<?php
declare(strict_types=1);

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE . '/login.php');
        exit;
    }
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare('SELECT * FROM hst_users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
        if (!$user) {
            logout();
        }
    }
    return $user;
}

/**
 * Huidige workspace + rol van de ingelogde gebruiker daarin. Retourneert
 * null als de gebruiker nog geen workspace heeft of geen toegang (meer)
 * heeft tot de workspace die in de sessie stond — voorkomt dat een
 * verlopen/verwijderd lidmaatschap alsnog toegang geeft.
 */
function currentWorkspace(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    static $workspace = null;
    if ($workspace !== null) {
        return $workspace === false ? null : $workspace;
    }

    $workspaceId = $_SESSION['workspace_id'] ?? null;
    if ($workspaceId) {
        $stmt = db()->prepare(
            'SELECT w.*, m.role FROM hst_workspaces w
             JOIN hst_workspace_members m ON m.workspace_id = w.id
             WHERE w.id = ? AND m.user_id = ?'
        );
        $stmt->execute([$workspaceId, $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $workspace = $row;
            return $workspace;
        }
    }

    // Sessie-workspace ongeldig of niet gezet: pak de eerste beschikbare workspace.
    $stmt = db()->prepare(
        'SELECT w.*, m.role FROM hst_workspaces w
         JOIN hst_workspace_members m ON m.workspace_id = w.id
         WHERE m.user_id = ? ORDER BY w.created_at LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $_SESSION['workspace_id'] = (int) $row['id'];
        $workspace = $row;
        return $workspace;
    }

    $workspace = false;
    return null;
}

function requireWorkspace(): array
{
    $ws = currentWorkspace();
    if (!$ws) {
        header('Location: ' . BASE . '/onboarding.php');
        exit;
    }
    return $ws;
}

/** RBAC: staat alleen de opgegeven rollen toe binnen de huidige workspace. */
function requireRole(array $allowedRoles): array
{
    $ws = requireWorkspace();
    if (!in_array($ws['role'], $allowedRoles, true)) {
        http_response_code(403);
        require __DIR__ . '/../public/403.php';
        exit;
    }
    return $ws;
}

function attemptLogin(string $email, string $password): array
{
    $ip = clientIp();
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM hst_login_attempts WHERE (email = ? OR ip = ?) AND success = 0 AND attempted_at > NOW() - INTERVAL 15 MINUTE"
    );
    $stmt->execute([$email, $ip]);
    $recentFails = (int) $stmt->fetchColumn();

    if ($recentFails >= 8) {
        return ['success' => false, 'error' => 'Te veel mislukte pogingen. Probeer het over 15 minuten opnieuw.', 'locked' => true];
    }

    $stmt = db()->prepare('SELECT * FROM hst_users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    $ok = $user && password_verify($password, $user['password_hash']);
    db()->prepare('INSERT INTO hst_login_attempts (email, ip, success) VALUES (?, ?, ?)')
        ->execute([$email, $ip, $ok ? 1 : 0]);

    if (!$ok) {
        return ['success' => false, 'error' => 'Ongeldige inloggegevens.'];
    }

    if ((int) $user['totp_enabled'] === 1) {
        $_SESSION['pending_2fa_user_id'] = (int) $user['id'];
        return ['success' => true, 'needs_2fa' => true];
    }

    finalizeLogin($user);
    return ['success' => true, 'needs_2fa' => false];
}

function finalizeLogin(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['pending_2fa_user_id']);
    db()->prepare('UPDATE hst_users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
