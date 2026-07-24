<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  ECHTE (semi-live) monitor-checks voor door de gebruiker toegevoegde URL's
 * ============================================================================
 * Aanvulling op simulate.php: de curatie-demo-monitors (geseed bij elke reseed)
 * blijven volledig gesimuleerd — die geschiedenis is bewust "geregisseerd" om
 * een realistisch 90-dagen-verhaal te vertellen. Monitors die een gebruiker zelf
 * aanmaakt met `check_mode = 'live'` krijgen in plaats daarvan een echte HTTP-
 * request naar hun eigen URL: minstens 1x per dag automatisch (bij het eerste
 * bezoek van de dag, zie refreshMonitorSimulation()), en op elk moment handmatig
 * via de "Nu controleren"-knop op monitors.php/monitor.php. Vandaar "semi-live":
 * geen continue achtergrond-cron, maar wel een daadwerkelijke request i.p.v.
 * altijd nep-data.
 *
 * SSRF-bescherming: dit voert een server-side HTTP-request uit naar een door de
 * gebruiker opgegeven URL — zonder mitigatie zou dit misbruikt kunnen worden om
 * interne/private netwerkadressen te bereiken (bv. http://169.254.169.254/,
 * http://localhost/, http://10.0.0.5/). We resolven de hostnaam vooraf en
 * weigeren elk privé/loopback/link-local/reserved IP-bereik, zowel voor de
 * oorspronkelijke URL als voor elke redirect-hop (CURLOPT_FOLLOWLOCATION staat
 * daarom uit; we volgen redirects zelf, met een cap van 3, en valideren elke
 * nieuwe locatie opnieuw voordat we hem volgen).
 */

const HS_LIVECHECK_MAX_REDIRECTS = 3;
const HS_LIVECHECK_TIMEOUT_SECONDS = 6;
const HS_LIVECHECK_CONNECT_TIMEOUT_SECONDS = 4;
const HS_LIVECHECK_DEGRADED_THRESHOLD_MS = 2000;
const HS_LIVECHECK_MAX_BODY_BYTES = 1_000_000; // 1MB is ruim genoeg voor een keyword-check

/**
 * Weigert IP-adressen die niet naar het publieke internet wijzen (SSRF-guard).
 * Dekt loopback, private (RFC1918), link-local, en overige "special-use"-
 * bereiken uit IPv4 + IPv6.
 */
function hsIsPubliclyRoutableIp(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    // filter_var's "reserved range"-lijst mist een paar relevante blokken op sommige
    // PHP-builds (o.a. 100.64.0.0/10 CGNAT, 169.254.0.0/16 link-local wordt wel
    // gedekt door NO_RES_RANGE maar we checken hier expliciet ter zekerheid).
    $blocklist = ['100.64.0.0/10', '169.254.0.0/16', '::ffff:0:0/96'];
    foreach ($blocklist as $cidr) {
        if (hsIpInCidr($ip, $cidr)) {
            return false;
        }
    }
    return true;
}

function hsIpInCidr(string $ip, string $cidr): bool
{
    [$subnet, $bits] = explode('/', $cidr);
    $bits = (int) $bits;
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    $remBits = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($remBits === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
    return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
}

/** Valideert schema + hostname-resolutie van een URL vóórdat we hem daadwerkelijk aanroepen. */
function hsAssertSafeUrl(string $url): void
{
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
        throw new RuntimeException('Ongeldig URL-schema.');
    }
    $host = $parts['host'];
    // IPv6-literal ("[::1]") komt uit parse_url zonder haakjes.
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        throw new RuntimeException('Hostnaam kon niet worden opgelost.');
    }
    foreach ($ips as $ip) {
        if (!hsIsPubliclyRoutableIp($ip)) {
            throw new RuntimeException('URL wijst naar een niet-publiek adres en wordt om veiligheidsredenen geweigerd.');
        }
    }
}

/**
 * Voert een echte HTTP-check uit tegen $url. Retourneert
 * ['status' => 'up'|'degraded'|'down', 'response_time_ms' => int, 'detail' => string].
 * Vangt alle fouten af (nooit een uncaught exception naar de caller) — een
 * mislukte live-check resulteert altijd in status 'down' met een uitleg,
 * nooit in een kapotte pagina.
 */
function performLiveCheck(string $url, string $type = 'http', ?string $keyword = null): array
{
    $currentUrl = $url;
    $start = microtime(true);

    for ($redirects = 0; $redirects <= HS_LIVECHECK_MAX_REDIRECTS; $redirects++) {
        try {
            hsAssertSafeUrl($currentUrl);
        } catch (RuntimeException $e) {
            return ['status' => 'down', 'response_time_ms' => 0, 'detail' => $e->getMessage()];
        }

        if (!function_exists('curl_init')) {
            return ['status' => 'down', 'response_time_ms' => 0, 'detail' => 'cURL niet beschikbaar op deze server.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // we volgen redirects zelf, met her-validatie per hop
            CURLOPT_TIMEOUT => HS_LIVECHECK_TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => HS_LIVECHECK_CONNECT_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOBODY => ($type === 'ping'),
            CURLOPT_RANGE => $type === 'keyword' ? '0-' . HS_LIVECHECK_MAX_BODY_BYTES : null,
            CURLOPT_USERAGENT => 'HanzeStatus-Monitor/1.0 (+https://demo.hanzeonline.nl/hanzestatus)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*'],
        ]);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($errNo !== 0) {
            return ['status' => 'down', 'response_time_ms' => 0, 'detail' => 'Verbinding mislukt: ' . $errMsg];
        }

        if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
            // Redirect: haal Location opnieuw op via curl_getinfo en volg 'm (met her-validatie
            // via hsAssertSafeUrl() bovenaan de volgende iteratie) i.p.v. blind te vertrouwen.
            $ch2 = curl_init($currentUrl);
            curl_setopt_array($ch2, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => HS_LIVECHECK_CONNECT_TIMEOUT_SECONDS]);
            $headResp = curl_exec($ch2);
            if (preg_match('/^Location:\s*(\S+)/mi', (string) $headResp, $m)) {
                $currentUrl = $m[1];
                continue;
            }
            return ['status' => 'degraded', 'response_time_ms' => (int) round((microtime(true) - $start) * 1000), 'detail' => 'Redirect zonder bruikbare Location-header.'];
        }

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        if ($httpCode >= 500 || $httpCode === 0) {
            return ['status' => 'down', 'response_time_ms' => $elapsedMs, 'detail' => "HTTP $httpCode"];
        }
        if ($type === 'keyword' && $keyword !== null && $keyword !== '') {
            if (!is_string($body) || stripos($body, $keyword) === false) {
                return ['status' => 'down', 'response_time_ms' => $elapsedMs, 'detail' => "Trefwoord '$keyword' niet gevonden op de pagina."];
            }
        }
        if ($httpCode >= 400 || $elapsedMs > HS_LIVECHECK_DEGRADED_THRESHOLD_MS) {
            return ['status' => 'degraded', 'response_time_ms' => $elapsedMs, 'detail' => $httpCode >= 400 ? "HTTP $httpCode" : 'Trage respons'];
        }
        return ['status' => 'up', 'response_time_ms' => $elapsedMs, 'detail' => "HTTP $httpCode"];
    }

    return ['status' => 'down', 'response_time_ms' => 0, 'detail' => 'Te veel redirects.'];
}
