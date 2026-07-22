<?php
declare(strict_types=1);

/**
 * Token-authenticatie voor de publieke REST-API (api/v1/*). Retourneert de
 * workspace-rij bij een geldig, niet-ingetrokken token, of stuurt direct een
 * 401 JSON-response en stopt de uitvoering.
 */
function requireApiToken(): array
{
    // Apache/mod_php laat de Authorization-header soms wegvallen uit $_SERVER
    // (of hernoemt 'm naar REDIRECT_HTTP_AUTHORIZATION) na een mod_rewrite-hop.
    // Val terug op apache_request_headers() voor robuustheid.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        jsonResponse(['error' => 'Ontbrekende of ongeldige Authorization-header. Gebruik: Authorization: Bearer <token>'], 401);
    }
    $token = trim($m[1]);

    if (!rateLimit('api:' . hash('sha256', $token), 120, 60)) {
        jsonResponse(['error' => 'Rate limit overschreden (max. 120 verzoeken per minuut).'], 429);
    }

    $stmt = db()->prepare(
        'SELECT w.* FROM hst_api_tokens t JOIN hst_workspaces w ON w.id = t.workspace_id
         WHERE t.token_hash = ? AND t.revoked_at IS NULL'
    );
    $stmt->execute([hash('sha256', $token)]);
    $workspace = $stmt->fetch();
    if (!$workspace) {
        jsonResponse(['error' => 'Ongeldig of ingetrokken API-token.'], 401);
    }

    db()->prepare('UPDATE hst_api_tokens SET last_used_at = NOW() WHERE token_hash = ?')->execute([hash('sha256', $token)]);
    return $workspace;
}
