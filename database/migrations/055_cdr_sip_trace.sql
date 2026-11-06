-- SIP trace text stored with every CDR (answered, cancelled, busy, failed).
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND COLUMN_NAME = 'sip_trace') = 0,
    'ALTER TABLE cdrs ADD COLUMN sip_trace MEDIUMTEXT NULL AFTER failure_reason',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
