<?php
declare(strict_types=1);

function notify(int $userId, string $type, string $title, string $body, string $link): void
{
    db()->prepare(
        'INSERT INTO hst_notifications (user_id, type, title, body, link) VALUES (?, ?, ?, ?, ?)'
    )->execute([$userId, $type, $title, $body, $link]);
}

/** Stuurt een notificatie naar alle leden van een workspace (bijv. bij een nieuw incident). */
function notifyWorkspaceMembers(int $workspaceId, string $type, string $title, string $body, string $link, ?int $excludeUserId = null): void
{
    $stmt = db()->prepare('SELECT user_id FROM hst_workspace_members WHERE workspace_id = ?');
    $stmt->execute([$workspaceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        if ($excludeUserId !== null && (int) $userId === $excludeUserId) {
            continue;
        }
        notify((int) $userId, $type, $title, $body, $link);
    }
}

/** Stuurt een notificatie naar alle admin/owner-leden van een workspace. */
function notifyWorkspaceAdmins(int $workspaceId, string $type, string $title, string $body, string $link, ?int $excludeUserId = null): void
{
    $stmt = db()->prepare(
        "SELECT user_id FROM hst_workspace_members WHERE workspace_id = ? AND role IN ('owner','admin')"
    );
    $stmt->execute([$workspaceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        if ($excludeUserId !== null && (int) $userId === $excludeUserId) {
            continue;
        }
        notify((int) $userId, $type, $title, $body, $link);
    }
}

function unreadNotificationCount(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM hst_notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}
