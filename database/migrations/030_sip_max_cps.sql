-- 030: Per-SIP CPS (calls per second) limit

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'max_cps') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN max_cps INT NOT NULL DEFAULT 1 AFTER max_channels',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
