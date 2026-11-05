-- 035: Destination dial format (E.164 + / digits) on SIP users and trunks

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dst_format') = 0,
    "ALTER TABLE sip_accounts ADD COLUMN dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is' AFTER rate_cap",
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'dst_format') = 0,
    "ALTER TABLE carrier_trunks ADD COLUMN dst_format VARCHAR(16) NOT NULL DEFAULT 'as_is' AFTER dnc_group_id",
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
