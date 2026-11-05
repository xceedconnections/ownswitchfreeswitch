-- 032: SIP connection mode (dynamic REGISTER vs IP-based peer)

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'connection_mode') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN connection_mode VARCHAR(16) NOT NULL DEFAULT ''dynamic'' AFTER sip_host',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE sip_accounts
SET connection_mode = 'ip'
WHERE connection_mode = 'dynamic'
  AND sip_host IS NOT NULL
  AND TRIM(sip_host) <> ''
  AND LOWER(TRIM(sip_host)) <> 'dynamic'
  AND sip_host REGEXP '^[0-9a-fA-F:.]+$';
