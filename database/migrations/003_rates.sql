-- 003_rates: customer rate plans and tariffs (rates are per-minute DECIMAL(18,8))

CREATE TABLE IF NOT EXISTS rate_plans (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code                   VARCHAR(32)  NOT NULL,
    name                   VARCHAR(120) NOT NULL,
    currency               CHAR(3)      NOT NULL DEFAULT 'USD',
    status                 VARCHAR(16)  NOT NULL DEFAULT 'active',
    default_rate           DECIMAL(18,8) NULL,                -- used when no tariff row matches (optional)
    initial_increment      SMALLINT UNSIGNED NOT NULL DEFAULT 6,   -- I
    subsequent_increment   SMALLINT UNSIGNED NOT NULL DEFAULT 6,   -- S
    connect_fee            DECIMAL(18,6) NOT NULL DEFAULT 0,
    min_billable_seconds   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    charge_connect_on_zero TINYINT(1) NOT NULL DEFAULT 0,
    current_version        INT UNSIGNED NOT NULL DEFAULT 0,
    is_demo                TINYINT(1) NOT NULL DEFAULT 0,
    created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_rate_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_plan_versions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rate_plan_id BIGINT UNSIGNED NOT NULL,
    version_no   INT UNSIGNED NOT NULL,
    content_hash CHAR(64) NOT NULL,
    created_by   BIGINT UNSIGNED NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_plan_version (rate_plan_id, version_no),
    CONSTRAINT fk_rpv_plan FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_plan_tariffs (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rate_plan_id         BIGINT UNSIGNED NOT NULL,
    prefix               VARCHAR(20)  NOT NULL,
    destination          VARCHAR(120) NULL,
    rate                 DECIMAL(18,8) NOT NULL,
    initial_increment    SMALLINT UNSIGNED NULL,     -- NULL = inherit from plan
    subsequent_increment SMALLINT UNSIGNED NULL,
    connect_fee          DECIMAL(18,6) NULL,
    status               VARCHAR(16) NOT NULL DEFAULT 'active',
    effective_from       DATETIME NULL,
    effective_to         DATETIME NULL,
    version_id           BIGINT UNSIGNED NULL,
    created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_plan_prefix (rate_plan_id, prefix),
    KEY ix_tariff_prefix (prefix),
    CONSTRAINT fk_rpt_plan FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_rate_overrides (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id          BIGINT UNSIGNED NOT NULL,
    prefix               VARCHAR(20) NOT NULL,
    rate                 DECIMAL(18,8) NOT NULL,
    initial_increment    SMALLINT UNSIGNED NULL,
    subsequent_increment SMALLINT UNSIGNED NULL,
    connect_fee          DECIMAL(18,6) NULL,
    status               VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_override (customer_id, prefix),
    CONSTRAINT fk_cro_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customers
    ADD CONSTRAINT fk_customers_rate_plan FOREIGN KEY (rate_plan_id) REFERENCES rate_plans (id);
