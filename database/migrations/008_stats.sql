-- 008_stats: reporting aggregates (sums only; ASR/ACD are always derived from sums), jobs, health

CREATE TABLE IF NOT EXISTS stats_hourly (
    bucket_start       DATETIME NOT NULL,
    reseller_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    customer_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    carrier_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    attempts           INT UNSIGNED NOT NULL DEFAULT 0,
    answered           INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    billable_seconds   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    revenue            DECIMAL(18,6) NOT NULL DEFAULT 0,
    cost               DECIMAL(18,6) NOT NULL DEFAULT 0,
    updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (bucket_start, reseller_id, customer_id, carrier_id),
    KEY ix_sh_customer (customer_id, bucket_start),
    KEY ix_sh_reseller (reseller_id, bucket_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stats_daily (
    bucket_date        DATE NOT NULL,
    reseller_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    customer_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
    carrier_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
    attempts           INT UNSIGNED NOT NULL DEFAULT 0,
    answered           INT UNSIGNED NOT NULL DEFAULT 0,
    duration_seconds   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    billable_seconds   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    revenue            DECIMAL(18,6) NOT NULL DEFAULT 0,
    cost               DECIMAL(18,6) NOT NULL DEFAULT 0,
    updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (bucket_date, reseller_id, customer_id, carrier_id),
    KEY ix_sd_customer (customer_id, bucket_date),
    KEY ix_sd_reseller (reseller_id, bucket_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS background_jobs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    job_type    VARCHAR(64) NOT NULL,
    status      VARCHAR(12) NOT NULL DEFAULT 'pending',
    payload     JSON NULL,
    last_error  VARCHAR(500) NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    finished_at DATETIME NULL,
    KEY ix_jobs_status (status, job_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS health_checks (
    component  VARCHAR(48) NOT NULL PRIMARY KEY,
    status     VARCHAR(16) NOT NULL,        -- ok | warn | fail | unconfigured
    detail     VARCHAR(500) NULL,
    checked_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
