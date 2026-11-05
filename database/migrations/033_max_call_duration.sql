-- 033: Per SIP user / trunk max call duration (0 = use Settings default)

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'max_call_duration') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN max_call_duration INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_cps',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'max_call_duration') = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN max_call_duration INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_channels',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
