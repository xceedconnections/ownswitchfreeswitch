-- 007_fraud: signalling-based fraud rules and events

CREATE TABLE IF NOT EXISTS fraud_rules (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code           VARCHAR(48)  NOT NULL,
    name           VARCHAR(120) NOT NULL,
    rule_type      VARCHAR(24)  NOT NULL,    -- velocity | short_calls | destination_spread | blocked_prefix
    threshold      DECIMAL(18,4) NOT NULL,
    window_seconds INT UNSIGNED NOT NULL DEFAULT 300,
    action         VARCHAR(12)  NOT NULL DEFAULT 'alert',   -- alert | suspend
    status         VARCHAR(12)  NOT NULL DEFAULT 'active',
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_fraud_rule_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO fraud_rules (code, name, rule_type, threshold, window_seconds, action) VALUES
 ('velocity_300s',      'Call attempts per customer within window',        'velocity',           60, 300, 'alert'),
 ('short_calls_ratio',  'Answered calls shorter than 6s (percent of answered)', 'short_calls',   60, 900, 'alert'),
 ('destination_spread', 'Distinct destination prefixes within window',     'destination_spread', 25, 600, 'alert');

CREATE TABLE IF NOT EXISTS fraud_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reseller_id     BIGINT UNSIGNED NULL,
    customer_id     BIGINT UNSIGNED NULL,
    rule_id         BIGINT UNSIGNED NULL,
    rule_code       VARCHAR(48) NOT NULL,
    severity        VARCHAR(8)  NOT NULL DEFAULT 'medium',
    summary         VARCHAR(255) NOT NULL,
    details         JSON NULL,
    dedupe_key      VARCHAR(191) NOT NULL,
    status          VARCHAR(12) NOT NULL DEFAULT 'open',    -- open | acknowledged
    acknowledged_by BIGINT UNSIGNED NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_fraud_dedupe (dedupe_key),
    KEY ix_fraud_customer (customer_id, created_at),
    KEY ix_fraud_reseller (reseller_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
