CREATE TABLE IF NOT EXISTS users (
    id               CHAR(36)     NOT NULL,
    name             VARCHAR(100) NOT NULL,
    email            VARCHAR(150) NOT NULL,
    password_hash    VARCHAR(255) NOT NULL,
    role             ENUM('admin','collaborator') NOT NULL DEFAULT 'collaborator',
    must_change_pass TINYINT(1)   NOT NULL DEFAULT 1,
    active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS records (
    id            CHAR(36)     NOT NULL,
    user_id       CHAR(36)     NOT NULL,
    started_at    DATETIME     NOT NULL,
    ended_at      DATETIME     NOT NULL,
    ticket        VARCHAR(100) NOT NULL,
    description   TEXT         NOT NULL,
    validated_at  DATETIME     NULL,
    validated_by  CHAR(36)     NULL,
    rejected_at   DATETIME     NULL,
    rejected_by   CHAR(36)     NULL,
    reject_reason TEXT         NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user    (user_id),
    KEY idx_started (started_at),
    CONSTRAINT fk_rec_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rec_validator FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collaborator_salary (
    user_id        CHAR(36)      NOT NULL,
    monthly_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    work_start     TIME          NOT NULL DEFAULT '08:00:00',
    work_end       TIME          NOT NULL DEFAULT '18:00:00',
    updated_at     TIMESTAMP     NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_sal_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bh_requests (
    id                CHAR(36)    NOT NULL,
    user_id           CHAR(36)    NOT NULL,
    requested_minutes INT         NOT NULL,
    request_date      DATE        NULL,
    request_date_end  DATE        NULL,
    period_type       VARCHAR(30) NULL,
    reason            TEXT        NULL,
    status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_by       CHAR(36)    NULL,
    reviewed_at       DATETIME    NULL,
    review_note       TEXT        NULL,
    created_at        TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bh_user (user_id),
    CONSTRAINT fk_bh_user     FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_bh_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    token      CHAR(64)     NOT NULL,
    user_id    CHAR(36)     NOT NULL,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token),
    KEY idx_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
