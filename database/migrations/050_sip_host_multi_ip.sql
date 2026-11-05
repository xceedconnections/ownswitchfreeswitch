-- Allow multiple IP peers on one SIP user (comma-separated in sip_host).
SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    (SELECT DATA_TYPE FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'sip_host') = 'varchar'
    AND (SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'sip_host') < 1000,
    'ALTER TABLE sip_accounts MODIFY COLUMN sip_host VARCHAR(1000) NOT NULL DEFAULT ''dynamic''',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
