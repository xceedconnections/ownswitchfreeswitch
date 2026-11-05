-- 004_routing: carriers, trunks, carrier tariffs, routing plans/rules, destination policies

CREATE TABLE IF NOT EXISTS carriers (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code          VARCHAR(32)  NOT NULL,
    name          VARCHAR(120) NOT NULL,
    status        VARCHAR(16)  NOT NULL DEFAULT 'active',
    contact_email VARCHAR(190) NULL,
    notes         TEXT NULL,
    is_demo       TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_carriers_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carrier_trunks (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carrier_id           BIGINT UNSIGNED NOT NULL,
    name                 VARCHAR(120) NOT NULL,
    host                 VARCHAR(190) NOT NULL,
    port                 INT UNSIGNED NOT NULL DEFAULT 5060,
    transport            VARCHAR(8)   NOT NULL DEFAULT 'udp',
    auth_username        VARCHAR(120) NULL,
    auth_secret_enc      TEXT NULL,                       -- AES-256-GCM (APP_KEY)
    max_channels         INT UNSIGNED NOT NULL DEFAULT 100,
    codecs               VARCHAR(190) NULL,
    media_policy         VARCHAR(16) NULL,                -- direct | relay | record
    initial_increment    SMALLINT UNSIGNED NOT NULL DEFAULT 6,   -- carrier-side I (trunk default)
    subsequent_increment SMALLINT UNSIGNED NOT NULL DEFAULT 6,   -- carrier-side S
    default_rate         DECIMAL(18,8) NULL,
    connect_fee          DECIMAL(18,6) NOT NULL DEFAULT 0,
    priority             INT UNSIGNED NOT NULL DEFAULT 100,
    status               VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY ix_trunk_carrier (carrier_id),
    CONSTRAINT fk_trunk_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS carrier_tariffs (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    carrier_id           BIGINT UNSIGNED NOT NULL,
    prefix               VARCHAR(20)  NOT NULL,
    destination          VARCHAR(120) NULL,
    rate                 DECIMAL(18,8) NOT NULL,
    initial_increment    SMALLINT UNSIGNED NULL,          -- NULL = trunk default
    subsequent_increment SMALLINT UNSIGNED NULL,
    connect_fee          DECIMAL(18,6) NULL,
    status               VARCHAR(16) NOT NULL DEFAULT 'active',
    effective_from       DATETIME NULL,
    effective_to         DATETIME NULL,
    created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_carrier_prefix (carrier_id, prefix),
    KEY ix_ctariff_prefix (prefix),
    CONSTRAINT fk_ctariff_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_plans (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(32)  NOT NULL,
    name        VARCHAR(120) NOT NULL,
    strategy    VARCHAR(16)  NOT NULL DEFAULT 'priority',   -- priority | lcr
    status      VARCHAR(16)  NOT NULL DEFAULT 'active',
    description VARCHAR(255) NULL,
    is_demo     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_routing_plans_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routing_rules (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    routing_plan_id  BIGINT UNSIGNED NOT NULL,
    prefix           VARCHAR(20) NOT NULL,
    carrier_trunk_id BIGINT UNSIGNED NOT NULL,
    priority         INT UNSIGNED NOT NULL DEFAULT 100,    -- lower = tried first (failover order)
    status           VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY ix_rules_plan_prefix (routing_plan_id, prefix),
    CONSTRAINT fk_rule_plan  FOREIGN KEY (routing_plan_id)  REFERENCES routing_plans (id),
    CONSTRAINT fk_rule_trunk FOREIGN KEY (carrier_trunk_id) REFERENCES carrier_trunks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS destination_policies (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id BIGINT UNSIGNED NULL,             -- NULL = global
    prefix      VARCHAR(20) NOT NULL,
    action      VARCHAR(8)  NOT NULL DEFAULT 'block',
    note        VARCHAR(255) NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_dp_prefix (prefix),
    CONSTRAINT fk_dp_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE customers
    ADD CONSTRAINT fk_customers_routing_plan FOREIGN KEY (routing_plan_id) REFERENCES routing_plans (id);
