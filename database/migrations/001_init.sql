-- 001_init: identity, audit, settings
-- Portal schema 'ownswitch'. MySQL 8.4 / MariaDB 10.6+ compatible. UTC timestamps.

CREATE TABLE IF NOT EXISTS roles (
    id          TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    code        VARCHAR(32)  NOT NULL,
    name        VARCHAR(64)  NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO roles (id, code, name, description) VALUES
 (1, 'super_admin',    'Super Admin',    'Full platform control'),
 (2, 'admin',          'Admin',          'Platform administration'),
 (3, 'reseller',       'Reseller',       'Manages own customers'),
 (4, 'customer_owner', 'Customer Owner', 'Full access to own account'),
 (5, 'customer_tech',  'Customer Tech',  'SIP/IP/CDR access to own account'),
 (6, 'reporter',       'Reporter',       'Read-only reporting');

CREATE TABLE IF NOT EXISTS users (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(190) NOT NULL,
    name           VARCHAR(120) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role_id        TINYINT UNSIGNED NOT NULL,
    reseller_id    BIGINT UNSIGNED NULL,
    customer_id    BIGINT UNSIGNED NULL,
    status         VARCHAR(16) NOT NULL DEFAULT 'active',
    failed_logins  INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME NULL,
    last_login_at  DATETIME NULL,
    last_login_ip  VARCHAR(45) NULL,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_tenant (reseller_id, customer_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    email_hash   CHAR(64) NOT NULL,
    success      TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_login_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action        VARCHAR(64) NOT NULL,
    entity_type   VARCHAR(64) NULL,
    entity_id     BIGINT UNSIGNED NULL,
    before_json   JSON NULL,
    after_json    JSON NULL,
    ip            VARCHAR(45) NULL,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_audit_actor (actor_user_id, created_at),
    KEY ix_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by    BIGINT UNSIGNED NULL,
    updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
 ('default_currency', 'USD'),
 ('default_customer_initial', '6'),
 ('default_customer_subsequent', '6'),
 ('default_carrier_initial', '6'),
 ('default_carrier_subsequent', '6'),
 ('prefer_portal_rating', '1'),
 ('charge_connect_on_zero', '0'),
 ('rating_tolerance', '0.000001'),
 ('prepaid_hard_cap_seconds', '7200'),
 ('default_media_policy', 'relay'),
 ('recording_retention_days', '90');
