-- Encrypted SIP password (portal) so OpenSIPS can use calculate_ha1=1 for any Domain (FQDN/IP).

SET @db := DATABASE();

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN password_enc TEXT NULL AFTER ha1b',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'password_enc'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
