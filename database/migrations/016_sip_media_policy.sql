-- SIP user media policy (Default / Direct / Relay) — NULL follows Settings default.
SET @db := DATABASE();

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN media_policy VARCHAR(16) NULL AFTER max_channels',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'media_policy'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
