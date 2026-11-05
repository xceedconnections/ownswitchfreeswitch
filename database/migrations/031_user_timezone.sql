-- 031: Per-portal-user timezone for CDR / report display

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'timezone') = 0,
    'ALTER TABLE users ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT ''UTC'' AFTER status',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
