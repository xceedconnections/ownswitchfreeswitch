-- MagnusBilling-parity SIP peer / trunk signalling parameters.
-- OpenSIPS uses strip, pri_prefix, probe_mode, attrs, rpid, call limit; other Asterisk-named
-- fields are persisted for dialplan/media and exported into gateway attrs.

SET @db := DATABASE();

-- ---- carrier_trunks ----
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN provider_tech VARCHAR(16) NOT NULL DEFAULT ''sip'' AFTER transport',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'provider_tech'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN add_prefix VARCHAR(32) NULL AFTER provider_tech',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'add_prefix'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN remove_prefix VARCHAR(32) NULL AFTER add_prefix',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'remove_prefix'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN from_user VARCHAR(64) NULL AFTER remove_prefix',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'from_user'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN from_domain VARCHAR(128) NULL AFTER from_user',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'from_domain'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN cid_add_prefix VARCHAR(32) NULL AFTER from_domain',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'cid_add_prefix'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN cid_remove_prefix VARCHAR(32) NULL AFTER cid_add_prefix',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'cid_remove_prefix'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN context VARCHAR(64) NOT NULL DEFAULT ''billing'' AFTER cid_remove_prefix',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'context'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN dtmfmode VARCHAR(32) NOT NULL DEFAULT ''RFC2833'' AFTER context',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'dtmfmode'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN insecure VARCHAR(64) NOT NULL DEFAULT ''port,invite'' AFTER dtmfmode',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'insecure'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN nat VARCHAR(64) NOT NULL DEFAULT ''force_rport,comedia'' AFTER insecure',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'nat'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN directmedia VARCHAR(16) NOT NULL DEFAULT ''no'' AFTER nat',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'directmedia'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- qualify is reserved in MySQL 8 — applied in 018_fix_qualify_columns.sql (direct ALTER, no PREPARE)
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN peer_type VARCHAR(16) NOT NULL DEFAULT ''peer'' AFTER directmedia',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'peer_type'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN sendrpid VARCHAR(8) NOT NULL DEFAULT ''no'' AFTER peer_type',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'sendrpid'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN addparameter VARCHAR(255) NULL AFTER sendrpid',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'addparameter'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE carrier_trunks ADD COLUMN asterisk_extra TEXT NULL AFTER addparameter',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'carrier_trunks' AND COLUMN_NAME = 'asterisk_extra'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- sip_accounts ----
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN alias VARCHAR(64) NULL AFTER caller_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'alias'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN sip_host VARCHAR(190) NOT NULL DEFAULT ''dynamic'' AFTER alias',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'sip_host'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN codecs VARCHAR(190) NULL AFTER sip_host',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'codecs'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN disallow VARCHAR(64) NOT NULL DEFAULT ''all'' AFTER codecs',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'disallow'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN videosupport VARCHAR(8) NOT NULL DEFAULT ''no'' AFTER disallow',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'videosupport'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN nat VARCHAR(64) NOT NULL DEFAULT ''force_rport,comedia'' AFTER videosupport',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'nat'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN directmedia VARCHAR(16) NOT NULL DEFAULT ''no'' AFTER nat',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'directmedia'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- qualify reserved — see 018_fix_qualify_columns.sql
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN routing_plan_id BIGINT UNSIGNED NULL AFTER directmedia',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'routing_plan_id'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN context VARCHAR(64) NOT NULL DEFAULT ''billing'' AFTER routing_plan_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'context'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN dtmfmode VARCHAR(32) NOT NULL DEFAULT ''RFC2833'' AFTER context',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dtmfmode'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN insecure VARCHAR(64) NOT NULL DEFAULT ''no'' AFTER dtmfmode',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'insecure'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN deny_acl VARCHAR(255) NULL AFTER insecure',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'deny_acl'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN permit_acl VARCHAR(255) NULL AFTER deny_acl',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'permit_acl'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN peer_type VARCHAR(16) NOT NULL DEFAULT ''friend'' AFTER permit_acl',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'peer_type'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN allowtransfer VARCHAR(8) NOT NULL DEFAULT ''no'' AFTER peer_type',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'allowtransfer'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN fake_ring VARCHAR(8) NOT NULL DEFAULT ''no'' AFTER allowtransfer',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'fake_ring'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN moh VARCHAR(64) NULL AFTER fake_ring',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'moh'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN addparameter VARCHAR(255) NULL AFTER moh',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'addparameter'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN asterisk_extra TEXT NULL AFTER addparameter',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'asterisk_extra'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD CONSTRAINT fk_sip_routing_plan FOREIGN KEY (routing_plan_id) REFERENCES routing_plans (id)',
    'DO 0')
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND CONSTRAINT_NAME = 'fk_sip_routing_plan'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
