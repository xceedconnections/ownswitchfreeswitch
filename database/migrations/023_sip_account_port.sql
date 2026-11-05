-- Per-account SIP signalling port for fixed-host (IP peer) endpoints.
-- OpenSIPS address.port — 0 means any port; non-zero matches that UDP/TCP port only.

SET @db := DATABASE();

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN sip_port SMALLINT UNSIGNED NOT NULL DEFAULT 5060 AFTER sip_host',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'sip_port'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
