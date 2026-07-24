<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  DEMO-MECHANISME: gesimuleerde monitor-status (geen echte uptime-checks!)
 * ============================================================================
 * Er is in deze demo-omgeving geen achtergrond-worker/cron-daemon die
 * daadwerkelijk HTTP-requests doet naar de gemonitorde URL's. In plaats
 * daarvan berekenen we een pseudo-willekeurige maar STABIELE status per
 * monitor per dag, gebaseerd op een hash van (monitor-id, dag-index [+
 * blok-index]). "Stabiel" wil zeggen: dezelfde input geeft altijd dezelfde
 * uitkomst, dus de status "flikkert" niet bij elke page-load, en eenmaal
 * weggeschreven historie (hst_monitor_checks) verandert nooit meer met
 * terugwerkende kracht.
 *
 * Dit geeft twee dingen "gratis", zonder cron:
 *  1. Een realistische 90-dagen-geschiedenis (hoofdzakelijk "up", met af en
 *     toe een korte down/degraded-storing) — geseed bij elke (re)seed-cyclus.
 *  2. Een "vandaag"-status die bij elke request wordt gecontroleerd/aangevuld
 *     (refreshMonitorSimulation), zodat het dashboard en de publieke
 *     statuspagina blijven "leven" zonder een achtergrondproces nodig te
 *     hebben.
 *
 * We groeperen dagen in blokken van 4 om korte, aaneengesloten
 * storingsreeksen te krijgen (zoals een echte storing van 1-3 dagen) in
 * plaats van willekeurige losse rode stipjes door de hele balk heen.
 */

function simBlockSize(): int
{
    return 4;
}

/** Dag-index sinds unix-epoch (UTC), gebruikt als stabiele tijdas voor de simulatie. */
function simDayIndex(int $timestamp): int
{
    return intdiv($timestamp, 86400);
}

/**
 * Berekent de gesimuleerde status + gemiddelde responstijd voor één
 * monitor op één dag-index. Puur functioneel/deterministisch: geen state,
 * geen randomness die niet terug te herleiden is uit de input.
 */
function simulatedDailyStatus(int $monitorId, int $dayIndex): array
{
    $blockIndex = intdiv($dayIndex, simBlockSize());
    $blockSeed = crc32($monitorId . ':block:' . $blockIndex);
    $blockRoll = $blockSeed % 1000;

    $daySeed = crc32($monitorId . ':day:' . $dayIndex);
    $dayRoll = $daySeed % 1000;

    if ($blockRoll < 18) {
        // Korte storing (offline) voor dit hele blok van ~4 dagen (~1.8% kans/blok).
        return ['status' => 'down', 'response_time_ms' => 0];
    }
    if ($blockRoll < 55) {
        // Verminderde prestaties voor dit blok (~3.7% kans/blok).
        return ['status' => 'degraded', 'response_time_ms' => 650 + ($daySeed % 700)];
    }
    if ($dayRoll < 12) {
        // Losse incidentele blip op een enkele dag, los van het blok (~1.2%).
        return ['status' => 'degraded', 'response_time_ms' => 500 + ($daySeed % 500)];
    }
    return ['status' => 'up', 'response_time_ms' => 55 + ($daySeed % 180)];
}

/**
 * Zorgt dat er voor elke actieve (niet-gepauzeerde) monitor in de workspace
 * een check-record bestaat voor "vandaag", en synchroniseert current_status.
 * Idempotent en goedkoop (1 SELECT + evt. 1 INSERT/UPDATE per monitor) —
 * bedoeld om aan te roepen aan het begin van dashboard.php/status.php, als
 * vervanging voor een echte cron-worker.
 */
function refreshMonitorSimulation(int $workspaceId): void
{
    $pdo = db();
    $today = simDayIndex(time());
    $todayDate = date('Y-m-d', time());

    $stmt = $pdo->prepare('SELECT id, current_status, check_mode, url, type, keyword_text FROM hst_monitors WHERE workspace_id = ?');
    $stmt->execute([$workspaceId]);
    $monitors = $stmt->fetchAll();

    foreach ($monitors as $m) {
        $monitorId = (int) $m['id'];
        if ($m['current_status'] === 'paused') {
            continue;
        }

        $existing = $pdo->prepare('SELECT status FROM hst_monitor_checks WHERE monitor_id = ? AND checked_at = ?');
        $existing->execute([$monitorId, $todayDate]);
        $row = $existing->fetch();

        if ($row) {
            $status = $row['status'];
        } elseif ($m['check_mode'] === 'live') {
            // Semi-live: minstens 1 echte HTTP-check per dag per monitor (bij het eerste
            // bezoek van de dag). Geen achtergrond-cron nodig, maar wel een daadwerkelijk
            // uitgevoerde request i.p.v. altijd gesimuleerde data — zie ook de
            // "Nu controleren"-knop op monitors.php voor een handmatige tussentijdse check.
            $status = runLiveMonitorCheck($monitorId, $m['url'], $m['type'], $m['keyword_text'], $todayDate);
        } else {
            $sim = simulatedDailyStatus($monitorId, $today);
            $pdo->prepare('INSERT IGNORE INTO hst_monitor_checks (monitor_id, status, checked_at, response_time_ms) VALUES (?, ?, ?, ?)')
                ->execute([$monitorId, $sim['status'], $todayDate, $sim['response_time_ms']]);
            $status = $sim['status'];
        }

        if ($status !== $m['current_status']) {
            $pdo->prepare('UPDATE hst_monitors SET current_status = ? WHERE id = ?')->execute([$status, $monitorId]);
        }
    }
}

/**
 * Voert een echte live-check uit voor één monitor, schrijft het check-record voor
 * $todayDate (INSERT IGNORE — als er door een race al net een record is bijgeschreven
 * winnen we niet opnieuw, geen probleem) en de last_checked_at/last_check_detail-kolommen
 * bij (altijd bijgewerkt, ook als er vandaag al een record bestond — zodat "Nu controleren"
 * altijd een verse detail-melding toont ook al verandert de dag-status niet).
 */
function runLiveMonitorCheck(int $monitorId, string $url, string $type, ?string $keyword, string $todayDate): string
{
    $result = performLiveCheck($url, $type, $keyword);
    $pdo = db();
    $pdo->prepare('INSERT IGNORE INTO hst_monitor_checks (monitor_id, status, checked_at, response_time_ms) VALUES (?, ?, ?, ?)')
        ->execute([$monitorId, $result['status'], $todayDate, $result['response_time_ms']]);
    $pdo->prepare('UPDATE hst_monitors SET last_checked_at = NOW(), last_check_detail = ? WHERE id = ?')
        ->execute([$result['detail'], $monitorId]);
    return $result['status'];
}

/**
 * Haalt de laatste $days dagen check-ticks op voor een monitor, gevuld met
 * 'nodata' voor dagen zonder record (bijv. vóór aanmaakdatum monitor).
 * Retourneert oudste-eerst (links = ouder, rechts = vandaag), zoals bij
 * Statuspage.io/Better Uptime gebruikelijk is.
 */
function getUptimeTicks(int $monitorId, int $days = 90): array
{
    $stmt = db()->prepare(
        'SELECT DATE(checked_at) AS d, status FROM hst_monitor_checks
         WHERE monitor_id = ? AND checked_at >= CURDATE() - INTERVAL ? DAY
         ORDER BY checked_at ASC'
    );
    $stmt->execute([$monitorId, $days - 1]);
    $byDate = [];
    foreach ($stmt->fetchAll() as $row) {
        $byDate[$row['d']] = $row['status'];
    }

    $ticks = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i day"));
        $ticks[] = ['date' => $date, 'status' => $byDate[$date] ?? 'nodata'];
    }
    return $ticks;
}

/** Uptime-percentage over de laatste $days dagen (degraded telt als halve uptime). */
function monitorUptimePercent(int $monitorId, int $days = 90): float
{
    $ticks = getUptimeTicks($monitorId, $days);
    $counted = 0;
    $score = 0.0;
    foreach ($ticks as $t) {
        if ($t['status'] === 'nodata') {
            continue;
        }
        $counted++;
        if ($t['status'] === 'up') {
            $score += 1.0;
        } elseif ($t['status'] === 'degraded') {
            $score += 0.5;
        }
    }
    if ($counted === 0) {
        return 100.0;
    }
    return round($score / $counted * 100, 2);
}

/** Aggregeert de globale status van een workspace uit de status van al zijn monitors. */
function aggregateWorkspaceStatus(array $monitors): string
{
    $hasDown = false;
    $hasDegraded = false;
    foreach ($monitors as $m) {
        if ($m['current_status'] === 'down') {
            $hasDown = true;
        } elseif ($m['current_status'] === 'degraded') {
            $hasDegraded = true;
        }
    }
    if ($hasDown) return 'down';
    if ($hasDegraded) return 'degraded';
    return 'up';
}
