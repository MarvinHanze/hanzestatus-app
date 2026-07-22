<?php
declare(strict_types=1);

require_once __DIR__ . '/simulate.php';

function seedDemoData(): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ([
        'hst_notifications', 'hst_audit_logs', 'hst_incident_updates', 'hst_incidents',
        'hst_maintenance_monitors', 'hst_maintenance_windows', 'hst_monitor_checks', 'hst_monitors',
        'hst_subscribers', 'hst_api_tokens', 'hst_invites', 'hst_settings',
        'hst_workspace_members', 'hst_workspaces', 'hst_users', 'hst_login_attempts', 'hst_rate_limit_hits',
        'hst_password_resets',
    ] as $t) {
        $pdo->exec("TRUNCATE TABLE $t");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

    // --- Workspace ---
    $pdo->exec("INSERT INTO hst_workspaces (name, slug, brand_color, plan) VALUES ('HanzeStatus Demo', 'demo', '#059669', 'pro')");
    $workspaceId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO hst_settings (workspace_id, public_intro) VALUES (?, ?)')
        ->execute([$workspaceId, 'Actuele status van al onze diensten. Bekijk lopende incidenten, geplande onderhoudswerkzaamheden en de uptime-geschiedenis van de afgelopen 90 dagen.']);

    // --- Team ---
    $users = [
        ['eigenaar@hanzestatus-demo.nl', 'Sanne Kramer', 'owner', '#059669'],
        ['ops@hanzestatus-demo.nl', 'Daan Vermeulen', 'admin', '#0891b2'],
        ['support@hanzestatus-demo.nl', 'Lotte Verhoeven', 'member', '#d97706'],
    ];
    $userIds = [];
    foreach ($users as [$email, $name, $role, $color]) {
        $pdo->prepare('INSERT INTO hst_users (email, password_hash, name, avatar_color) VALUES (?, ?, ?, ?)')
            ->execute([$email, password_hash('demo123', PASSWORD_DEFAULT), $name, $color]);
        $userId = (int) $pdo->lastInsertId();
        $userIds[$role] = $userId;
        $pdo->prepare('INSERT INTO hst_workspace_members (workspace_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$workspaceId, $userId, $role]);
    }

    // --- Monitors ---
    $monitorDefs = [
        ['Marketing website', 'https://www.hanzestatus-demo.nl', 'http', 60],
        ['API Gateway', 'https://api.hanzestatus-demo.nl', 'http', 30],
        ['Klantportaal', 'https://app.hanzestatus-demo.nl', 'http', 60],
        ['Betaalservice', 'https://payments.hanzestatus-demo.nl', 'http', 30],
        ['E-mail service', 'https://mail.hanzestatus-demo.nl', 'keyword', 120],
        ['CDN / static assets', 'https://cdn.hanzestatus-demo.nl', 'http', 60],
        ['Database-cluster', 'https://db-status.hanzestatus-demo.nl', 'ping', 30],
        ['Achtergrond-jobs (queue worker)', 'https://queue.hanzestatus-demo.nl', 'http', 120],
    ];
    $monitorIds = [];
    foreach ($monitorDefs as $i => [$name, $url, $type, $interval]) {
        $pdo->prepare('INSERT INTO hst_monitors (workspace_id, name, url, type, check_interval_seconds, position) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$workspaceId, $name, $url, $type, $interval, $i]);
        $monitorIds[$name] = (int) $pdo->lastInsertId();
    }

    // --- 90 dagen historie backfillen via de gedeelde simulatiefunctie (core/simulate.php) ---
    // Zelfde functie als de "live" simulatie op elke request gebruikt, zodat
    // historie en actuele status altijd consistent zijn.
    $days = 90;
    $today = simDayIndex(time());
    $insertCheck = $pdo->prepare('INSERT INTO hst_monitor_checks (monitor_id, status, checked_at, response_time_ms) VALUES (?, ?, ?, ?)');
    $latestStatusByMonitor = [];
    foreach ($monitorIds as $name => $monitorId) {
        for ($i = $days - 1; $i >= 0; $i--) {
            $dayIndex = $today - $i;
            $date = date('Y-m-d', strtotime("-$i day"));
            $sim = simulatedDailyStatus($monitorId, $dayIndex);
            $insertCheck->execute([$monitorId, $sim['status'], $date, $sim['response_time_ms']]);
            if ($i === 0) {
                $latestStatusByMonitor[$monitorId] = $sim['status'];
            }
        }
    }
    foreach ($latestStatusByMonitor as $monitorId => $status) {
        $pdo->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$status, $monitorId]);
    }
    // "Achtergrond-jobs" staat bewust gepauzeerd, om de paused-status te demonstreren.
    $pdo->prepare('UPDATE hst_monitors SET current_status = "paused" WHERE id = ?')
        ->execute([$monitorIds['Achtergrond-jobs (queue worker)']]);

    // --- Incidenten ---
    // 1) Actief incident, gekoppeld aan de Betaalservice (die recent een degraded/down-blok had).
    $pdo->prepare(
        'INSERT INTO hst_incidents (workspace_id, monitor_id, title, status, impact, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL 3 HOUR)'
    )->execute([$workspaceId, $monitorIds['Betaalservice'], 'Verhoogde latency bij het verwerken van betalingen', 'monitoring', 'major']);
    $activeIncidentId = (int) $pdo->lastInsertId();
    $activeUpdates = [
        ['investigating', 'We onderzoeken meldingen van vertraagde betalingsbevestigingen op de Betaalservice.', -180],
        ['identified', 'De oorzaak is gevonden: een upstream-provider levert vertraagde responses. We schakelen over op een secundair kanaal.', -120],
        ['monitoring', 'De failover is actief, responstijden zijn merkbaar verbeterd. We blijven de situatie de komende uren volgen.', -45],
    ];
    foreach ($activeUpdates as [$status, $body, $minutesAgo]) {
        $pdo->prepare('INSERT INTO hst_incident_updates (incident_id, status, body, created_by_user_id, created_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)')
            ->execute([$activeIncidentId, $status, $body, $userIds['admin'], $minutesAgo]);
    }

    // 2) Historische, opgeloste incidenten.
    $resolvedIncidents = [
        [
            'monitor' => 'API Gateway',
            'title' => 'API Gateway tijdelijk onbereikbaar',
            'impact' => 'critical',
            'daysAgo' => 14,
            'durationHours' => 2,
            'updates' => [
                ['investigating', 'We zien een piek in 5xx-foutmeldingen op de API Gateway en onderzoeken de oorzaak.', 0],
                ['identified', 'Een misconfiguratie in een recente deploy blokkeerde een deel van het verkeer. Rollback is gestart.', 40],
                ['monitoring', 'De rollback is voltooid, foutpercentage is genormaliseerd. We monitoren nog even actief.', 80],
                ['resolved', 'Het incident is volledig opgelost. Er zijn maatregelen genomen om deze misconfiguratie in het vervolg automatisch te detecteren.', 120],
            ],
        ],
        [
            'monitor' => 'CDN / static assets',
            'title' => 'Verhoogde laadtijden voor statische bestanden',
            'impact' => 'minor',
            'daysAgo' => 27,
            'durationHours' => 1,
            'updates' => [
                ['investigating', 'Gebruikers melden tragere laadtijden voor afbeeldingen en scripts via onze CDN.', 0],
                ['identified', 'Een van de edge-regios had verminderde capaciteit. Verkeer wordt herverdeeld.', 25],
                ['resolved', 'Laadtijden zijn weer normaal. De betreffende edge-regio is hersteld.', 60],
            ],
        ],
        [
            'monitor' => 'Klantportaal',
            'title' => 'Klantportaal volledig onbereikbaar',
            'impact' => 'critical',
            'daysAgo' => 45,
            'durationHours' => 3,
            'updates' => [
                ['investigating', 'Het klantportaal reageert niet. We zijn dit met hoogste prioriteit aan het onderzoeken.', 0],
                ['identified', 'De database-connectiepool raakte uitgeput door een achtergrondtaak die vastliep. De taak is gestopt.', 55],
                ['monitoring', 'Het portaal is weer bereikbaar. We volgen de connectiepool nauwlettend.', 110],
                ['resolved', 'Volledig hersteld. Er is een limiet ingebouwd om herhaling te voorkomen.', 175],
            ],
        ],
        [
            'monitor' => 'E-mail service',
            'title' => 'Vertraagde bezorging van transactionele e-mails',
            'impact' => 'minor',
            'daysAgo' => 61,
            'durationHours' => 4,
            'updates' => [
                ['investigating', 'E-mails (bevestigingen, wachtwoordresets) komen met vertraging aan.', 0],
                ['identified', 'De wachtrij bij onze e-mailprovider liep vast door een piek in verzendvolume.', 60],
                ['resolved', 'De wachtrij is leeggewerkt, bezorgtijden zijn weer normaal.', 230],
            ],
        ],
    ];
    foreach ($resolvedIncidents as $inc) {
        $createdAt = "NOW() - INTERVAL {$inc['daysAgo']} DAY";
        $pdo->prepare(
            "INSERT INTO hst_incidents (workspace_id, monitor_id, title, status, impact, created_at, resolved_at)
             VALUES (?, ?, ?, 'resolved', ?, $createdAt, $createdAt + INTERVAL {$inc['durationHours']} HOUR)"
        )->execute([$workspaceId, $monitorIds[$inc['monitor']], $inc['title'], $inc['impact']]);
        $incidentId = (int) $pdo->lastInsertId();
        foreach ($inc['updates'] as [$status, $body, $minutesOffset]) {
            $pdo->prepare(
                "INSERT INTO hst_incident_updates (incident_id, status, body, created_by_user_id, created_at)
                 VALUES (?, ?, ?, ?, $createdAt + INTERVAL ? MINUTE)"
            )->execute([$incidentId, $status, $body, $userIds['admin'], $minutesOffset]);
        }
    }

    // --- Onderhoudsvensters ---
    $pdo->prepare(
        "INSERT INTO hst_maintenance_windows (workspace_id, title, description, starts_at, ends_at, status, created_at)
         VALUES (?, ?, ?, NOW() + INTERVAL 4 DAY, NOW() + INTERVAL 4 DAY + INTERVAL 2 HOUR, 'scheduled', NOW())"
    )->execute([$workspaceId, 'Database-migratie klantportaal', 'We voeren een geplande database-migratie uit om de prestaties van het Klantportaal te verbeteren. Verwacht geen downtime, mogelijk kortstondig tragere responstijden.']);
    $maint1Id = (int) $pdo->lastInsertId();
    foreach ([$monitorIds['Klantportaal'], $monitorIds['Database-cluster']] as $mid) {
        $pdo->prepare('INSERT INTO hst_maintenance_monitors (maintenance_id, monitor_id) VALUES (?, ?)')->execute([$maint1Id, $mid]);
    }

    $pdo->prepare(
        "INSERT INTO hst_maintenance_windows (workspace_id, title, description, starts_at, ends_at, status, created_at)
         VALUES (?, ?, ?, NOW() - INTERVAL 30 MINUTE, NOW() + INTERVAL 90 MINUTE, 'in_progress', NOW() - INTERVAL 2 DAY)"
    )->execute([$workspaceId, 'Upgrade CDN-edgenodes', 'De CDN-edgenodes worden bijgewerkt naar een nieuwe softwareversie voor betere caching-prestaties.']);
    $maint2Id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO hst_maintenance_monitors (maintenance_id, monitor_id) VALUES (?, ?)')->execute([$maint2Id, $monitorIds['CDN / static assets']]);

    $pdo->prepare(
        "INSERT INTO hst_maintenance_windows (workspace_id, title, description, starts_at, ends_at, status, created_at)
         VALUES (?, ?, ?, NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 10 DAY + INTERVAL 3 HOUR, 'completed', NOW() - INTERVAL 12 DAY)"
    )->execute([$workspaceId, 'Beveiligingspatch API Gateway', 'Kritieke beveiligingsupdate toegepast op de API Gateway-servers.']);
    $maint3Id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO hst_maintenance_monitors (maintenance_id, monitor_id) VALUES (?, ?)')->execute([$maint3Id, $monitorIds['API Gateway']]);

    // --- Abonnees (mock e-mailabonnementen, geen echte verzending) ---
    $subscribers = [
        ['timo@klant-a.nl', -40], ['iris@klant-b.nl', -33], ['lucas@klant-c.nl', -21],
        ['nina@klant-d.nl', -14], ['bram@klant-e.nl', -8], ['sara@klant-f.nl', -3],
    ];
    foreach ($subscribers as [$email, $daysAgo]) {
        $pdo->prepare('INSERT INTO hst_subscribers (workspace_id, email, confirmed_at, created_at) VALUES (?, ?, NOW() + INTERVAL ? DAY, NOW() + INTERVAL ? DAY)')
            ->execute([$workspaceId, $email, $daysAgo, $daysAgo]);
    }
    // Eén nog niet bevestigde abonnee.
    $pdo->prepare('INSERT INTO hst_subscribers (workspace_id, email, created_at) VALUES (?, ?, NOW() - INTERVAL 1 DAY)')
        ->execute([$workspaceId, 'jesse@klant-g.nl']);

    // --- Notificaties voor het team ---
    $pdo->prepare('INSERT INTO hst_notifications (user_id, type, title, body, link, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL 3 HOUR)')
        ->execute([$userIds['owner'], 'incident_opened', 'Nieuw incident geopend', 'Verhoogde latency bij het verwerken van betalingen', 'incident-admin.php?id=' . $activeIncidentId]);
    $pdo->prepare('INSERT INTO hst_notifications (user_id, type, title, body, link, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL 45 MINUTE)')
        ->execute([$userIds['admin'], 'incident_update', 'Incident bijgewerkt', 'Failover actief voor de Betaalservice, responstijden verbeterd.', 'incident-admin.php?id=' . $activeIncidentId]);
    $pdo->prepare('INSERT INTO hst_notifications (user_id, type, title, body, link, created_at) VALUES (?, ?, ?, ?, ?, NOW() - INTERVAL 6 DAY)')
        ->execute([$userIds['owner'], 'team_invite_accepted', 'Teamlid toegevoegd', 'Lotte Verhoeven is toegevoegd aan de workspace.', 'team.php']);

    // --- Audit log ---
    $auditEntries = [
        [$userIds['owner'], 'workspace.create', 'workspace', $workspaceId, 'Workspace aangemaakt'],
        [$userIds['admin'], 'monitor.create', 'monitor', $monitorIds['Betaalservice'], 'Monitor aangemaakt: Betaalservice'],
        [$userIds['admin'], 'monitor.pause', 'monitor', $monitorIds['Achtergrond-jobs (queue worker)'], 'Monitor gepauzeerd: Achtergrond-jobs (queue worker)'],
        [$userIds['admin'], 'incident.create', 'incident', $activeIncidentId, 'Incident geopend: Verhoogde latency bij het verwerken van betalingen'],
        [$userIds['owner'], 'team.invite', 'workspace_member', $userIds['member'], 'Lotte Verhoeven uitgenodigd als member'],
        [$userIds['owner'], 'settings.update', 'workspace', $workspaceId, 'Introductietekst statuspagina aangepast'],
    ];
    foreach ($auditEntries as [$uid, $action, $entityType, $entityId, $meta]) {
        $pdo->prepare('INSERT INTO hst_audit_logs (workspace_id, user_id, action, entity_type, entity_id, meta, ip) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$workspaceId, $uid, $action, $entityType, $entityId, $meta, '127.0.0.1']);
    }
}
