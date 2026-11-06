-- Trunk and SIP-user reports filter cdrs by these columns as the table grows.
SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND INDEX_NAME = 'ix_cdr_trunk_time') = 0,
    'ALTER TABLE cdrs ADD INDEX ix_cdr_trunk_time (carrier_trunk_id, setup_at)',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cdrs' AND INDEX_NAME = 'ix_cdr_sip_time') = 0,
    'ALTER TABLE cdrs ADD INDEX ix_cdr_sip_time (sip_account_id, setup_at)',
    'SELECT 1'
  )
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
