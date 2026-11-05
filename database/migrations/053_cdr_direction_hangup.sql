-- CDR enrichment: direction + hangup initiator (actual vs billed / IP / SIP user already present).
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'direction') = 0,
    'ALTER TABLE cdrs ADD COLUMN direction VARCHAR(16) NULL AFTER disposition',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'hangup_initiator') = 0,
    'ALTER TABLE cdrs ADD COLUMN hangup_initiator VARCHAR(32) NULL AFTER direction',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'hangup_cause') = 0,
    'ALTER TABLE cdrs ADD COLUMN hangup_cause VARCHAR(64) NULL AFTER hangup_initiator',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
