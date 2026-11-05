-- 026: SIP attempt legs for failover / SIP-trace on CDRs

CREATE TABLE IF NOT EXISTS cdr_attempt_legs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sip_call_id     VARCHAR(191) NOT NULL,
    cdr_id          BIGINT UNSIGNED NULL,
    attempt_no      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    carrier_trunk_id BIGINT UNSIGNED NULL,
    trunk_name      VARCHAR(120) NULL,
    sip_code        SMALLINT UNSIGNED NULL,
    sip_reason      VARCHAR(190) NULL,
    disposition     VARCHAR(32) NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_leg_callid (sip_call_id),
    KEY ix_leg_cdr (cdr_id),
    KEY ix_leg_trunk (carrier_trunk_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();
SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'failover_trace') = 0,
  'ALTER TABLE cdrs ADD COLUMN failover_trace JSON NULL AFTER failure_reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
