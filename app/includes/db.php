<?php
function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'bh_tracker';
    $user = getenv('DB_USER') ?: 'bh_user';
    $pass = getenv('DB_PASSWORD') ?: '';
    $pdo  = new PDO(
        "mysql:host=$host;dbname=$name;charset=utf8mb4",
        $user, $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    runMigrations($pdo);
    return $pdo;
}

function runMigrations(PDO $pdo): void {
    // Colunas de rejeição de registros (adicionadas se ainda não existirem)
    $cols = $pdo->query("SHOW COLUMNS FROM records LIKE 'rejected_at'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE records
            ADD COLUMN rejected_at     DATETIME  NULL,
            ADD COLUMN rejected_by     CHAR(36)  NULL,
            ADD COLUMN reject_reason   TEXT      NULL");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id         CHAR(36)  NOT NULL,
            user_id    CHAR(36)  NOT NULL,
            token      CHAR(64)  NOT NULL,
            expires_at DATETIME  NOT NULL,
            used_at    DATETIME  NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token),
            KEY idx_pr_user (user_id),
            CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS collaborator_salary (
            user_id        CHAR(36)      NOT NULL,
            monthly_salary DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            CONSTRAINT fk_sal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bh_requests (
            id                CHAR(36)  NOT NULL,
            user_id           CHAR(36)  NOT NULL,
            requested_minutes INT       NOT NULL,
            reason            TEXT      NULL,
            status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by       CHAR(36)  NULL,
            reviewed_at       DATETIME  NULL,
            review_note       TEXT      NULL,
            created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_bhr_user   (user_id),
            KEY idx_bhr_status (status),
            CONSTRAINT fk_bhr_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_bhr_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
