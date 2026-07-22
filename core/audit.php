<?php
declare(strict_types=1);

function auditLog(int $workspaceId, ?int $userId, string $action, string $entityType, ?int $entityId = null, ?string $meta = null): void
{
    db()->prepare(
        'INSERT INTO hst_audit_logs (workspace_id, user_id, action, entity_type, entity_id, meta, ip) VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$workspaceId, $userId, $action, $entityType, $entityId, $meta, clientIp()]);
}
