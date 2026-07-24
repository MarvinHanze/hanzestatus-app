<?php
declare(strict_types=1);

function renderAdminStart(string $active, string $pageTitle): void
{
    $user = currentUser();
    $ws = currentWorkspace();
    $unread = $user ? unreadNotificationCount((int) $user['id']) : 0;
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
        <title><?= e($pageTitle) ?> — HanzeStatus</title>
        <base href="<?= e(BASE) ?>/">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>
    <a href="#hs-main-content" class="hs-skip-link">Naar inhoud springen</a>
    <div class="hs-app-shell">
        <aside class="hs-sidebar">
            <a href="dashboard.php" class="hs-brand">
                <span class="hs-brand-mark"><?= hz_icon('activity', 'hz-icon') ?></span>
                <span>HanzeStatus</span>
            </a>
            <nav aria-label="Hoofdnavigatie">
                <a class="hs-nav-item <?= $active === 'dashboard' ? 'hs-is-active' : '' ?>" href="dashboard.php"><?= hz_icon('bar-chart') ?> Dashboard</a>
                <a class="hs-nav-item <?= $active === 'monitors' ? 'hs-is-active' : '' ?>" href="monitors.php"><?= hz_icon('activity') ?> Monitors</a>
                <a class="hs-nav-item <?= $active === 'incidents' ? 'hs-is-active' : '' ?>" href="incidents.php"><?= hz_icon('alert-triangle') ?> Incidenten</a>
                <a class="hs-nav-item <?= $active === 'maintenance' ? 'hs-is-active' : '' ?>" href="maintenance.php"><?= hz_icon('tool') ?> Onderhoud</a>
                <a class="hs-nav-item <?= $active === 'subscribers' ? 'hs-is-active' : '' ?>" href="subscribers.php"><?= hz_icon('mail') ?> Abonnees</a>
                <a class="hs-nav-item <?= $active === 'notifications' ? 'hs-is-active' : '' ?>" href="notifications.php">
                    <?= hz_icon('bell') ?> Notificaties
                    <?php if ($unread): ?><span class="hs-nav-badge"><?= $unread ?></span><?php endif; ?>
                </a>
                <?php if ($ws && in_array($ws['role'], ['owner', 'admin'], true)): ?>
                    <p class="hs-nav-group-label">Beheer</p>
                    <a class="hs-nav-item <?= $active === 'team' ? 'hs-is-active' : '' ?>" href="team.php"><?= hz_icon('users') ?> Team</a>
                    <a class="hs-nav-item <?= $active === 'settings' ? 'hs-is-active' : '' ?>" href="settings.php"><?= hz_icon('settings') ?> Instellingen</a>
                    <a class="hs-nav-item <?= $active === 'api-tokens' ? 'hs-is-active' : '' ?>" href="api-tokens.php"><?= hz_icon('key') ?> API-tokens</a>
                    <a class="hs-nav-item <?= $active === 'audit-log' ? 'hs-is-active' : '' ?>" href="audit-log.php"><?= hz_icon('clipboard') ?> Audit-log</a>
                <?php endif; ?>
                <p class="hs-nav-group-label">Publiek</p>
                <a class="hs-nav-item" href="<?= e($ws ? 'status.php?w=' . $ws['slug'] : '#') ?>" target="_blank"><?= hz_icon('arrow-right') ?> Bekijk statuspagina</a>
            </nav>
            <div class="hs-sidebar-footer">
                <a class="hs-nav-item <?= $active === 'profile' ? 'hs-is-active' : '' ?>" href="profile.php"><?= hz_icon('user') ?> Profiel</a>
                <a class="hs-nav-item" href="logout.php"><?= hz_icon('log-out') ?> Uitloggen</a>
            </div>
        </aside>
        <div class="hs-main">
            <header class="hs-topbar">
                <h1><?= e($pageTitle) ?></h1>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <div class="hs-theme-picker" id="hsThemePicker">
                        <button type="button" class="hs-theme-picker-btn" id="hsThemePickerBtn" aria-haspopup="true" aria-expanded="false">
                            <?= hz_icon('palette') ?> <span id="hsThemePickerLabel">Thema</span>
                        </button>
                        <div class="hs-theme-picker-panel" id="hsThemePickerPanel" role="menu">
                            <button type="button" class="hs-theme-option" data-hs-theme="" role="menuitem">
                                <span class="hs-theme-swatch" style="background:#ecfdf5;"></span> Licht (standaard)
                            </button>
                            <button type="button" class="hs-theme-option" data-hs-theme="dark" role="menuitem">
                                <span class="hs-theme-swatch" style="background:#131b18;"></span> Donker
                            </button>
                            <button type="button" class="hs-theme-option" data-hs-theme="midnight" role="menuitem">
                                <span class="hs-theme-swatch" style="background:#12162a;"></span> Middernacht (bento)
                            </button>
                            <button type="button" class="hs-theme-option" data-hs-theme="sunrise" role="menuitem">
                                <span class="hs-theme-swatch" style="background:#fff7ed;border-color:#fde4cd;"></span> Zonsopgang (bento)
                            </button>
                        </div>
                    </div>
                    <div class="hs-user-menu">
                        <button class="hs-user-btn" id="hsNotifBtn" aria-haspopup="true" aria-expanded="false" aria-label="Notificaties (<?= $unread ?> ongelezen)">
                            <span class="hs-avatar" style="background:<?= e($user['avatar_color'] ?? '#059669') ?>;width:34px;height:34px;position:relative;">
                                <?= hz_icon('bell') ?>
                                <?php if ($unread): ?><span style="position:absolute;top:-2px;right:-2px;width:9px;height:9px;background:var(--hs-down);border-radius:50%;border:2px solid var(--hs-surface);"></span><?php endif; ?>
                            </span>
                        </button>
                        <div class="hs-dropdown hs-notif-panel" id="hsNotifPanel" role="menu"></div>
                    </div>
                    <div class="hs-user-menu">
                        <button class="hs-user-btn" id="hsUserBtn" aria-haspopup="true" aria-expanded="false">
                            <span class="hs-avatar" style="background:<?= e($user['avatar_color'] ?? '#059669') ?>;"><?= e(initials($user['name'] ?? '?')) ?></span>
                        </button>
                        <div class="hs-dropdown" id="hsUserPanel" role="menu">
                            <div style="padding:.7rem .9rem;border-bottom:1px solid var(--hs-border);">
                                <strong style="font-size:.85rem;"><?= e($user['name'] ?? '') ?></strong>
                                <div style="font-size:.78rem;color:var(--hs-text-muted);"><?= e($user['email'] ?? '') ?></div>
                            </div>
                            <a href="profile.php"><?= hz_icon('user') ?> Profiel</a>
                            <a href="logout.php"><?= hz_icon('log-out') ?> Uitloggen</a>
                        </div>
                    </div>
                </div>
            </header>
            <main class="hs-content" id="hs-main-content">
    <?php
}

function renderAdminEnd(): void
{
    ?>
            </main>
        </div>
    </div>
    <div class="hs-toast-wrap" id="hsToastWrap"></div>
    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}

function renderPublicStart(array $workspace, string $pageTitle): void
{
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
        <title><?= e($pageTitle) ?> — <?= e($workspace['name']) ?></title>
        <meta name="description" content="Actuele status en incidentgeschiedenis van <?= e($workspace['name']) ?>.">
        <base href="<?= e(BASE) ?>/">
        <link rel="stylesheet" href="assets/css/style.css">
        <style>:root { --hs-primary: <?= e($workspace['brand_color']) ?>; --hs-primary-dark: <?= e($workspace['brand_color']) ?>; }</style>
    </head>
    <body>
    <a href="#hs-main-content" class="hs-skip-link">Naar inhoud springen</a>
    <header class="hs-public-header">
        <div class="hs-container" style="display:flex;align-items:center;justify-content:space-between;">
            <a href="status.php?w=<?= e($workspace['slug']) ?>" class="hs-brand">
                <span class="hs-brand-mark"><?= hz_icon('activity', 'hz-icon') ?></span>
                <span><?= e($workspace['name']) ?></span>
            </a>
            <div class="hs-theme-picker" id="hsThemePicker">
                <button type="button" class="hs-theme-picker-btn" id="hsThemePickerBtn" aria-haspopup="true" aria-expanded="false">
                    <?= hz_icon('palette') ?> <span id="hsThemePickerLabel">Thema</span>
                </button>
                <div class="hs-theme-picker-panel" id="hsThemePickerPanel" role="menu">
                    <button type="button" class="hs-theme-option" data-hs-theme="" role="menuitem">
                        <span class="hs-theme-swatch" style="background:#ecfdf5;"></span> Licht (standaard)
                    </button>
                    <button type="button" class="hs-theme-option" data-hs-theme="dark" role="menuitem">
                        <span class="hs-theme-swatch" style="background:#131b18;"></span> Donker
                    </button>
                    <button type="button" class="hs-theme-option" data-hs-theme="midnight" role="menuitem">
                        <span class="hs-theme-swatch" style="background:#12162a;"></span> Middernacht (bento)
                    </button>
                    <button type="button" class="hs-theme-option" data-hs-theme="sunrise" role="menuitem">
                        <span class="hs-theme-swatch" style="background:#fff7ed;border-color:#fde4cd;"></span> Zonsopgang (bento)
                    </button>
                </div>
            </div>
        </div>
    </header>
    <main id="hs-main-content">
    <?php
}

function renderPublicEnd(): void
{
    ?>
    </main>
    <footer style="text-align:center;padding:2.5rem 1.5rem;color:var(--hs-text-muted);font-size:.82rem;">
        Aangedreven door <a href="/" style="color:var(--hs-primary);font-weight:600;text-decoration:none;">HanzeStatus</a>
        &middot; <span style="opacity:.75;">Dit is een demo-omgeving: curatiemonitors zijn gesimuleerd, monitors met een "live"-badge worden echt gecontroleerd.</span>
    </footer>
    <div class="hs-toast-wrap" id="hsToastWrap"></div>
    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
}
