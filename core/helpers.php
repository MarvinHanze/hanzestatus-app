<?php
declare(strict_types=1);

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Ongeldig verzoek (CSRF-validatie mislukt). Ververs de pagina en probeer opnieuw.']);
        exit;
    }
}

/**
 * Generieke, database-gebaseerde rate limiter (sliding window). Retourneert
 * true als de actie is toegestaan (en registreert de hit), false als de
 * limiet is bereikt.
 */
function rateLimit(string $bucketKey, int $maxHits, int $windowSeconds): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM hst_rate_limit_hits WHERE bucket_key = ? AND created_at > NOW() - INTERVAL $windowSeconds SECOND"
    );
    $stmt->execute([$bucketKey]);
    $hits = (int) $stmt->fetchColumn();
    if ($hits >= $maxHits) {
        return false;
    }
    $pdo->prepare('INSERT INTO hst_rate_limit_hits (bucket_key) VALUES (?)')->execute([$bucketKey]);
    return true;
}

function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'zojuist';
    if ($diff < 3600) return floor($diff / 60) . ' min geleden';
    if ($diff < 86400) return floor($diff / 3600) . ' uur geleden';
    if ($diff < 2592000) return floor($diff / 86400) . ' dag(en) geleden';
    return date('d-m-Y', strtotime($datetime));
}

function nlDate(string $datetime): string
{
    $maanden = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec'];
    $ts = strtotime($datetime);
    return (int) date('j', $ts) . ' ' . $maanden[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function nlDateTime(string $datetime): string
{
    $ts = strtotime($datetime);
    return nlDate($datetime) . ' om ' . date('H:i', $ts);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_filter($parts);
    if (!$parts) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
}

/* ============ Statuslabels (monitors, incidenten, onderhoud) ============ */

function monitorStatusLabel(string $status): string
{
    $labels = ['up' => 'Online', 'degraded' => 'Verminderd', 'down' => 'Offline', 'paused' => 'Gepauzeerd'];
    return $labels[$status] ?? $status;
}

function monitorStatusPill(string $status): string
{
    $cssStatus = $status === 'paused' ? 'degraded' : $status;
    return '<span class="hs-status-pill hs-status-' . e($cssStatus) . '"><span class="hs-status-dot hs-status-' . e($cssStatus) . '"></span>' . e(monitorStatusLabel($status)) . '</span>';
}

function incidentStatusLabel(string $status): string
{
    $labels = [
        'investigating' => 'Onderzoek loopt',
        'identified' => 'Oorzaak bekend',
        'monitoring' => 'Wordt gevolgd',
        'resolved' => 'Opgelost',
    ];
    return $labels[$status] ?? $status;
}

function impactLabel(string $impact): string
{
    $labels = ['minor' => 'Klein', 'major' => 'Groot', 'critical' => 'Kritiek'];
    return $labels[$impact] ?? $impact;
}

function impactBadgeClass(string $impact): string
{
    return $impact === 'critical' ? 'down' : ($impact === 'major' ? 'degraded' : 'up');
}

function maintenanceStatusLabel(string $status): string
{
    $labels = ['scheduled' => 'Gepland', 'in_progress' => 'Bezig', 'completed' => 'Afgerond'];
    return $labels[$status] ?? $status;
}

/**
 * Bouwt een pagination-widget (Bootstrap-vrij, eigen hs-pagination-stijl,
 * hergebruikt de generieke tabel/kaart-look uit style.css).
 */
function renderPagination(int $currentPage, int $totalPages, string $baseUrl): string
{
    if ($totalPages <= 1) {
        return '';
    }
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $html = '<nav aria-label="Paginanavigatie" style="display:flex;gap:.4rem;margin-top:1.25rem;">';
    $prevDisabled = $currentPage <= 1;
    $html .= '<a href="' . e($baseUrl . $sep . 'pagina=' . max(1, $currentPage - 1)) . '" class="hs-btn hs-btn--secondary hs-btn--sm"' . ($prevDisabled ? ' style="pointer-events:none;opacity:.4;"' : '') . ' aria-label="Vorige pagina">&laquo;</a>';

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $currentPage;
        $html .= '<a href="' . e($baseUrl . $sep . 'pagina=' . $p) . '" class="hs-btn ' . ($active ? 'hs-btn--primary' : 'hs-btn--secondary') . ' hs-btn--sm"' . ($active ? ' aria-current="page"' : '') . '>' . $p . '</a>';
    }
    $nextDisabled = $currentPage >= $totalPages;
    $html .= '<a href="' . e($baseUrl . $sep . 'pagina=' . min($totalPages, $currentPage + 1)) . '" class="hs-btn hs-btn--secondary hs-btn--sm"' . ($nextDisabled ? ' style="pointer-events:none;opacity:.4;"' : '') . ' aria-label="Volgende pagina">&raquo;</a>';
    $html .= '</nav>';
    return $html;
}

function jsonResponse(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Valideert wachtwoordsterkte: min. 10 tekens, minstens 1 cijfer, 1 hoofdletter. */
function validatePasswordStrength(string $password): ?string
{
    if (mb_strlen($password) < 10) {
        return 'Wachtwoord moet minstens 10 tekens bevatten.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Wachtwoord moet minstens 1 cijfer bevatten.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Wachtwoord moet minstens 1 hoofdletter bevatten.';
    }
    return null;
}

function validateEmail(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateSlug(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{1,48}[a-z0-9])?$/', $slug);
}

function validateUrl(string $url): bool
{
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
}
