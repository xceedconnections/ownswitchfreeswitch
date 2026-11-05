-- SIP CallerID mode + DTMF pass; customer personal / compliance profile fields.

SET @db := DATABASE();

-- ---- sip_accounts ----
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN caller_id_mode VARCHAR(16) NOT NULL DEFAULT ''passthrough'' AFTER caller_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'caller_id_mode'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE sip_accounts ADD COLUMN dtmf_pass VARCHAR(8) NOT NULL DEFAULT ''yes'' AFTER dtmfmode',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'dtmf_pass'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- customers personal / regulatory ----
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN full_name VARCHAR(190) NULL AFTER name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'full_name'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN company_name VARCHAR(190) NULL AFTER full_name',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'company_name'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN phone VARCHAR(32) NULL AFTER contact_email',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'phone'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN teams_id VARCHAR(190) NULL AFTER phone',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'teams_id'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN robocall_id VARCHAR(64) NULL AFTER teams_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'robocall_id'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN frn_id VARCHAR(64) NULL AFTER robocall_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'frn_id'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE customers ADD COLUMN filler_499_id VARCHAR(64) NULL AFTER frn_id',
    'DO 0')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'filler_499_id'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
