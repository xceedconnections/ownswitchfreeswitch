-- 006_cdrs: unified CDRs, ingest queue, recordings

CREATE TABLE IF NOT EXISTS cdrs (
    id                            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid                          CHAR(36) NOT NULL,
    source_system                 VARCHAR(32) NOT NULL DEFAULT 'api',
    external_cdr_key              VARCHAR(191) NOT NULL,
    sip_call_id                   VARCHAR(191) NULL,
    reseller_id                   BIGINT UNSIGNED NULL,
    customer_id                   BIGINT UNSIGNED NULL,
    sip_account_id                BIGINT UNSIGNED NULL,
    ip_endpoint_id                BIGINT UNSIGNED NULL,
    src                           VARCHAR(64) NULL,
    dst_raw                       VARCHAR(64) NULL,
    dst_normalized                VARCHAR(32) NULL,
    dst_prefix                    VARCHAR(20) NULL,
    carrier_id                    BIGINT UNSIGNED NULL,
    carrier_trunk_id              BIGINT UNSIGNED NULL,
    routing_rule_id               BIGINT UNSIGNED NULL,
    setup_at                      DATETIME(3) NOT NULL,
    answer_at                     DATETIME(3) NULL,
    end_at                        DATETIME(3) NULL,
    duration_actual               INT UNSIGNED NOT NULL DEFAULT 0,        -- D (connected seconds)
    duration_billable_customer    INT UNSIGNED NOT NULL DEFAULT 0,
    duration_billable_carrier     INT UNSIGNED NOT NULL DEFAULT 0,
    customer_rate                 DECIMAL(18,8) NULL,
    carrier_rate                  DECIMAL(18,8) NULL,
    customer_initial              SMALLINT UNSIGNED NULL,
    customer_subsequent           SMALLINT UNSIGNED NULL,
    carrier_initial               SMALLINT UNSIGNED NULL,
    carrier_subsequent            SMALLINT UNSIGNED NULL,
    customer_charge               DECIMAL(18,6) NOT NULL DEFAULT 0,       -- total incl. connect fee
    carrier_cost                  DECIMAL(18,6) NOT NULL DEFAULT 0,       -- total incl. connect fee
    customer_connect_fee          DECIMAL(18,6) NOT NULL DEFAULT 0,
    carrier_connect_fee           DECIMAL(18,6) NOT NULL DEFAULT 0,
    gross_margin                  DECIMAL(18,6) NOT NULL DEFAULT 0,
    customer_rate_plan_version_id BIGINT UNSIGNED NULL,
    carrier_tariff_version_id     BIGINT UNSIGNED NULL,
    sip_response                  SMALLINT UNSIGNED NULL,
    disposition                   VARCHAR(16) NOT NULL DEFAULT 'FAILED',  -- ANSWERED|NO_ANSWER|BUSY|FAILED|CANCELLED
    failure_reason                VARCHAR(190) NULL,
    media_policy_requested        VARCHAR(16) NULL,
    media_policy_actual           VARCHAR(16) NULL,
    recording_status              VARCHAR(16) NULL,
    fraud_status                  VARCHAR(16) NULL,
    fraud_event_id                BIGINT UNSIGNED NULL,
    settlement_status             VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending|settled|unrated|unattributed|no_charge
    rating_source                 VARCHAR(32) NULL,
    rated_at                      DATETIME(3) NULL,
    rating_discrepancy            TINYINT(1) NOT NULL DEFAULT 0,
    cgrates_cost                  DECIMAL(18,6) NULL,
    currency                      CHAR(3) NULL,
    is_demo                       TINYINT(1) NOT NULL DEFAULT 0,
    created_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_cdr_external (external_cdr_key),
    KEY ix_cdr_customer_time (customer_id, setup_at),
    KEY ix_cdr_reseller_time (reseller_id, setup_at),
    KEY ix_cdr_carrier_time (carrier_id, setup_at),
    KEY ix_cdr_dst_time (dst_normalized, setup_at),
    KEY ix_cdr_call_id (sip_call_id),
    KEY ix_cdr_disp_time (disposition, setup_at),
    KEY ix_cdr_setup (setup_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cdr_ingest_queue (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payload      JSON NOT NULL,
    source       VARCHAR(32) NOT NULL DEFAULT 'queue',
    status       VARCHAR(12) NOT NULL DEFAULT 'pending',   -- pending | done | failed
    attempts     INT UNSIGNED NOT NULL DEFAULT 0,
    last_error   VARCHAR(500) NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    processed_at DATETIME NULL,
    KEY ix_queue_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recordings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cdr_id          BIGINT UNSIGNED NULL,
    reseller_id     BIGINT UNSIGNED NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    file_path       VARCHAR(500) NOT NULL,           -- relative to recordings dir
    sha256          CHAR(64) NULL,
    duration_sec    INT UNSIGNED NOT NULL DEFAULT 0,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status          VARCHAR(16) NOT NULL DEFAULT 'available',   -- available | missing | deleted
    retention_until DATE NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_rec_customer (customer_id, created_at),
    KEY ix_rec_reseller (reseller_id, created_at),
    KEY ix_rec_cdr (cdr_id),
    CONSTRAINT fk_rec_customer FOREIGN KEY (customer_id) REFERENCES customers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recording_access_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recording_id BIGINT UNSIGNED NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    action       VARCHAR(16) NOT NULL,     -- play | download | denied
    ip           VARCHAR(45) NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_recaccess_rec (recording_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
