<?php
declare(strict_types=1);

/**
 * Databaseverbinding + volledig schema. Gebruikt de gedeelde externe MySQL-
 * omgeving (zoals alle andere HanzeOnline demo's), met tabelprefix hst_
 * (HanzeStatus) zodat tabellen niet botsen met andere apps in de gedeelde
 * "demos"-database. Schema-init is idempotent: CREATE TABLE IF NOT EXISTS +
 * expliciete kolommigraties (ensureColumn) voor latere wijzigingen.
 */

define('DB_HOST', 'y11ovnrne4yk4p9zbhe39tti');
define('DB_NAME', 'demos');
define('DB_USER', 'mysql');
define('DB_PASS', '23ns613Dyo1vgiAOQCt2ABFZzujOsxuyROvqNk4unUoZxWpwN9nIPrMNTt4QFkzG');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function columnExists(string $table, string $column): bool
{
    $row = db()->prepare(
        "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
    );
    $row->execute([$table, $column]);
    return (bool) $row->fetchColumn();
}

function ensureColumn(string $table, string $column, string $definition): void
{
    if (!columnExists($table, $column)) {
        db()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function initSchema(): void
{
    $pdo = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(150) NOT NULL,
        avatar_color VARCHAR(20) NOT NULL DEFAULT '#059669',
        totp_secret VARCHAR(64) NULL,
        totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
        totp_confirmed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_workspaces (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        slug VARCHAR(60) NOT NULL UNIQUE,
        brand_color VARCHAR(20) NOT NULL DEFAULT '#059669',
        plan ENUM('free','pro','business') NOT NULL DEFAULT 'free',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_workspace_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        user_id INT NOT NULL,
        role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_member (workspace_id, user_id),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES hst_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_monitors (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        url VARCHAR(255) NOT NULL,
        type ENUM('http','ping','keyword') NOT NULL DEFAULT 'http',
        check_interval_seconds INT NOT NULL DEFAULT 60,
        current_status ENUM('up','degraded','down','paused') NOT NULL DEFAULT 'up',
        position INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_workspace (workspace_id),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_monitor_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        monitor_id INT NOT NULL,
        status ENUM('up','degraded','down') NOT NULL,
        checked_at DATETIME NOT NULL,
        response_time_ms INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_monitor_day (monitor_id, checked_at),
        KEY idx_monitor_time (monitor_id, checked_at),
        FOREIGN KEY (monitor_id) REFERENCES hst_monitors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_incidents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        monitor_id INT NULL,
        title VARCHAR(200) NOT NULL,
        status ENUM('investigating','identified','monitoring','resolved') NOT NULL DEFAULT 'investigating',
        impact ENUM('minor','major','critical') NOT NULL DEFAULT 'minor',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL,
        KEY idx_workspace_status (workspace_id, status),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE,
        FOREIGN KEY (monitor_id) REFERENCES hst_monitors(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_incident_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incident_id INT NOT NULL,
        status ENUM('investigating','identified','monitoring','resolved') NOT NULL,
        body TEXT NOT NULL,
        created_by_user_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (incident_id) REFERENCES hst_incidents(id) ON DELETE CASCADE,
        FOREIGN KEY (created_by_user_id) REFERENCES hst_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_maintenance_windows (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        title VARCHAR(200) NOT NULL,
        description TEXT NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        status ENUM('scheduled','in_progress','completed') NOT NULL DEFAULT 'scheduled',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_workspace_time (workspace_id, starts_at),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_maintenance_monitors (
        maintenance_id INT NOT NULL,
        monitor_id INT NOT NULL,
        PRIMARY KEY (maintenance_id, monitor_id),
        FOREIGN KEY (maintenance_id) REFERENCES hst_maintenance_windows(id) ON DELETE CASCADE,
        FOREIGN KEY (monitor_id) REFERENCES hst_monitors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        email VARCHAR(190) NOT NULL,
        confirmed_at DATETIME NULL,
        unsubscribed_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_subscriber (workspace_id, email),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(40) NOT NULL,
        title VARCHAR(200) NOT NULL,
        body VARCHAR(500) NOT NULL,
        link VARCHAR(255) NOT NULL,
        read_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_unread (user_id, read_at),
        FOREIGN KEY (user_id) REFERENCES hst_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        user_id INT NULL,
        action VARCHAR(60) NOT NULL,
        entity_type VARCHAR(40) NOT NULL,
        entity_id INT NULL,
        meta VARCHAR(500) NULL,
        ip VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_workspace_time (workspace_id, created_at),
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        token_hash VARCHAR(64) NOT NULL UNIQUE,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        ip VARCHAR(45) NOT NULL,
        success TINYINT(1) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_email_time (email, attempted_at),
        KEY idx_ip_time (ip, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_rate_limit_hits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bucket_key VARCHAR(150) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_bucket_time (bucket_key, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES hst_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        workspace_id INT NOT NULL,
        email VARCHAR(190) NOT NULL,
        role ENUM('admin','member') NOT NULL DEFAULT 'member',
        token_hash VARCHAR(64) NOT NULL,
        invited_by_user_id INT NOT NULL,
        expires_at DATETIME NOT NULL,
        accepted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_settings (
        workspace_id INT PRIMARY KEY,
        public_intro VARCHAR(300) NOT NULL DEFAULT 'Actuele status van al onze diensten, live bijgewerkt.',
        notify_on_incident TINYINT(1) NOT NULL DEFAULT 1,
        notify_on_monitor_down TINYINT(1) NOT NULL DEFAULT 1,
        notify_on_monitor_recovery TINYINT(1) NOT NULL DEFAULT 1,
        FOREIGN KEY (workspace_id) REFERENCES hst_workspaces(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hst_meta (
        id INT PRIMARY KEY DEFAULT 1,
        last_reset DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
