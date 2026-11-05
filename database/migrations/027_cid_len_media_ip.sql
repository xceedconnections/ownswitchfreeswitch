-- 027: CallerID min/max length + CDR media/signalling IP

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'cid_min_len') = 0,
  'ALTER TABLE sip_accounts ADD COLUMN cid_min_len SMALLINT UNSIGNED NULL AFTER caller_id_mode, ADD COLUMN cid_max_len SMALLINT UNSIGNED NULL AFTER cid_min_len',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'cid_min_len') = 0,
  'ALTER TABLE carrier_trunks ADD COLUMN cid_min_len SMALLINT UNSIGNED NULL AFTER cid_remove_prefix, ADD COLUMN cid_max_len SMALLINT UNSIGNED NULL AFTER cid_min_len',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'source_ip') = 0,
  'ALTER TABLE cdrs ADD COLUMN source_ip VARCHAR(45) NULL AFTER failure_reason',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'media_ip') = 0,
  'ALTER TABLE cdrs ADD COLUMN media_ip VARCHAR(64) NULL AFTER source_ip',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
