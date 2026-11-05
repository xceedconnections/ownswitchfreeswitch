-- Call limit: allow -1 = unlimited (was UNSIGNED). Multi remove-prefix on trunks.
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'max_concurrent_calls'
             AND COLUMN_TYPE LIKE '%unsigned%'),
    'ALTER TABLE customers MODIFY COLUMN max_concurrent_calls INT NOT NULL DEFAULT 10',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'max_channels'
             AND COLUMN_TYPE LIKE '%unsigned%'),
    'ALTER TABLE sip_accounts MODIFY COLUMN max_channels INT NOT NULL DEFAULT 2',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'remove_prefix'),
    'ALTER TABLE carrier_trunks MODIFY COLUMN remove_prefix VARCHAR(120) NULL',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
