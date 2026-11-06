-- Users follow Settings → System timezone until they pick a city on My profile.
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'timezone') = 1,
    'ALTER TABLE users MODIFY COLUMN timezone VARCHAR(64) NOT NULL DEFAULT ''system''',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE users SET timezone = 'system' WHERE timezone = 'UTC' OR timezone = '';
