-- 010_tariff_import: CSV import audit trail

CREATE TABLE IF NOT EXISTS tariff_import_batches (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind       VARCHAR(16) NOT NULL,          -- customer | carrier
    owner_id   BIGINT UNSIGNED NOT NULL,      -- rate_plan_id or carrier_id
    user_id    BIGINT UNSIGNED NULL,
    row_count  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_tib_owner (kind, owner_id, created_at),
    CONSTRAINT fk_tib_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
