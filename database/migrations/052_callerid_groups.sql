-- 052: CallerID groups (dialled-prefix -> outbound CLI) assignable to SIP users

CREATE TABLE IF NOT EXISTS callerid_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  customer_id BIGINT UNSIGNED NULL,          -- NULL = global group usable by any SIP user
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_callerid_groups_name (name),
  KEY idx_callerid_groups_customer (customer_id),
  CONSTRAINT fk_callerid_groups_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- prefix = digits the dialled number must start with ('*' = default / any destination).
-- Longest matching prefix wins; several rows may share a prefix (CLI rotation).
CREATE TABLE IF NOT EXISTS callerid_group_numbers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  callerid_group_id BIGINT UNSIGNED NOT NULL,
  prefix VARCHAR(32) NOT NULL DEFAULT '*',
  cli_number VARCHAR(32) NOT NULL,
  name VARCHAR(64) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cgn_group_prefix (callerid_group_id, prefix),
  CONSTRAINT fk_cgn_group FOREIGN KEY (callerid_group_id) REFERENCES callerid_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND COLUMN_NAME = 'callerid_group_id') = 0,
    'ALTER TABLE sip_accounts ADD COLUMN callerid_group_id BIGINT UNSIGNED NULL DEFAULT NULL, ADD KEY idx_sip_accounts_callerid_group (callerid_group_id)',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'sip_accounts' AND CONSTRAINT_NAME = 'fk_sip_accounts_callerid_group') = 0,
    'ALTER TABLE sip_accounts ADD CONSTRAINT fk_sip_accounts_callerid_group FOREIGN KEY (callerid_group_id) REFERENCES callerid_groups (id) ON DELETE SET NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
