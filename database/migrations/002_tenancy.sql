-- 002_tenancy: currencies, resellers, customers, SIP accounts, IP endpoints

CREATE TABLE IF NOT EXISTS currencies (
    code           CHAR(3) NOT NULL PRIMARY KEY,
    name           VARCHAR(64) NOT NULL,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO currencies (code, name, decimal_places) VALUES
 ('USD','US Dollar',2), ('EUR','Euro',2), ('GBP','Pound Sterling',2), ('PKR','Pakistani Rupee',2), ('AED','UAE Dirham',2);

CREATE TABLE IF NOT EXISTS resellers (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(120) NOT NULL,
    code           VARCHAR(32)  NOT NULL,
    contact_email  VARCHAR(190) NULL,
    status         VARCHAR(16)  NOT NULL DEFAULT 'active',
    can_view_rates TINYINT(1)   NOT NULL DEFAULT 0,
    notes          TEXT NULL,
    is_demo        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_resellers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id          BIGINT UNSIGNED NULL,
    name                 VARCHAR(120) NOT NULL,
    code                 VARCHAR(32)  NOT NULL,
    contact_email        VARCHAR(190) NULL,
    billing_mode         VARCHAR(10)  NOT NULL DEFAULT 'prepaid',     -- prepaid | postpaid
    currency             CHAR(3)      NOT NULL DEFAULT 'USD',
    timezone             VARCHAR(64)  NOT NULL DEFAULT 'UTC',
    status               VARCHAR(16)  NOT NULL DEFAULT 'active',      -- active | suspended | closed
    credit_limit         DECIMAL(18,4) NOT NULL DEFAULT 0,            -- postpaid
    max_concurrent_calls INT UNSIGNED NOT NULL DEFAULT 10,
    rate_plan_id         BIGINT UNSIGNED NULL,
    routing_plan_id      BIGINT UNSIGNED NULL,
    media_policy         VARCHAR(16) NULL,                            -- direct | relay | record (NULL = global default)
    recording_enabled    TINYINT(1)  NOT NULL DEFAULT 0,
    is_demo              TINYINT(1)  NOT NULL DEFAULT 0,
    created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_customers_code (code),
    KEY ix_customers_reseller (reseller_id),
    CONSTRAINT fk_customers_reseller FOREIGN KEY (reseller_id) REFERENCES resellers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_reseller FOREIGN KEY (reseller_id) REFERENCES resellers (id),
    ADD CONSTRAINT fk_users_customer FOREIGN KEY (customer_id) REFERENCES customers (id);

CREATE TABLE IF NOT EXISTS sip_accounts (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id         BIGINT UNSIGNED NULL,
    customer_id         BIGINT UNSIGNED NOT NULL,
    username            VARCHAR(64)  NOT NULL,
    domain              VARCHAR(128) NOT NULL,
    ha1                 CHAR(32) NOT NULL,           -- md5(user:realm:password); plaintext is never stored
    ha1b                CHAR(32) NOT NULL,           -- md5(user@domain:realm:password)
    caller_id           VARCHAR(32) NULL,
    max_channels        INT UNSIGNED NOT NULL DEFAULT 2,
    status              VARCHAR(16) NOT NULL DEFAULT 'active',
    notes               VARCHAR(255) NULL,
    password_changed_at DATETIME NULL,
    created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_sip_user_domain (username, domain),
    KEY ix_sip_customer (customer_id),
    KEY ix_sip_reseller (reseller_id),
    CONSTRAINT fk_sip_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ip_endpoints (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    label       VARCHAR(120) NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    mask        TINYINT UNSIGNED NOT NULL DEFAULT 32,
    port        INT UNSIGNED NOT NULL DEFAULT 0,
    proto       VARCHAR(8)   NOT NULL DEFAULT 'any',
    status      VARCHAR(16)  NOT NULL DEFAULT 'active',
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_ip_endpoint (ip_address, mask, port),
    KEY ix_ip_customer (customer_id),
    KEY ix_ip_reseller (reseller_id),
    CONSTRAINT fk_ip_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
