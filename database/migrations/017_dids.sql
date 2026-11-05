-- DID inventory, providers link, rates, assignments (inbound to SIP users via OpenSIPS dbaliases)

CREATE TABLE IF NOT EXISTS did_providers (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    carrier_id  BIGINT UNSIGNED NULL,
    status      VARCHAR(16) NOT NULL DEFAULT 'active',
    notes       VARCHAR(255) NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    KEY ix_didprov_carrier (carrier_id),
    CONSTRAINT fk_didprov_carrier FOREIGN KEY (carrier_id) REFERENCES carriers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dids (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    did_provider_id BIGINT UNSIGNED NULL,
    number_e164     VARCHAR(32)  NOT NULL,
    number_norm     VARCHAR(32)  NOT NULL,
    country         VARCHAR(64) NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'available',
    setup_fee       DECIMAL(18,6) NOT NULL DEFAULT 0,
    monthly_fee     DECIMAL(18,6) NOT NULL DEFAULT 0,
    per_minute      DECIMAL(18,8) NOT NULL DEFAULT 0,
    currency        CHAR(3) NOT NULL DEFAULT 'USD',
    notes           VARCHAR(255) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_dids_norm (number_norm),
    KEY ix_dids_status (status),
    KEY ix_dids_provider (did_provider_id),
    CONSTRAINT fk_dids_provider FOREIGN KEY (did_provider_id) REFERENCES did_providers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS did_assignments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    did_id          BIGINT UNSIGNED NOT NULL,
    reseller_id     BIGINT UNSIGNED NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    sip_account_id  BIGINT UNSIGNED NULL,
    dest_type       VARCHAR(16) NOT NULL DEFAULT 'sip_user',
    destination     VARCHAR(64) NULL,
    status          VARCHAR(16) NOT NULL DEFAULT 'active',
    assigned_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    next_billing_at DATE NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_did_assignment_did (did_id),
    KEY ix_da_customer (customer_id),
    KEY ix_da_sip (sip_account_id),
    CONSTRAINT fk_da_did FOREIGN KEY (did_id) REFERENCES dids (id),
    CONSTRAINT fk_da_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
    CONSTRAINT fk_da_sip FOREIGN KEY (sip_account_id) REFERENCES sip_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
