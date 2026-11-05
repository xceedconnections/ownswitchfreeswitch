-- 028: Rate CAP on SIP users and trunks (max buy $/min before dial is blocked)

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'rate_cap') = 0,
  'ALTER TABLE sip_accounts ADD COLUMN rate_cap DECIMAL(18,8) NULL AFTER max_channels',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'rate_cap') = 0,
  'ALTER TABLE carrier_trunks ADD COLUMN rate_cap DECIMAL(18,8) NULL AFTER max_channels',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
