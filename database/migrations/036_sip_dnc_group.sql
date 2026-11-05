-- 036: Per-SIP-user DNC group (block before trunk / vendor)

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dnc_group_id') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN dnc_group_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER dst_format, ADD KEY idx_sip_accounts_dnc (dnc_group_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
