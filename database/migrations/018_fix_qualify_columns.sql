-- MySQL 8 treats QUALIFY as reserved. Add columns with backticks via plain ALTER
-- (not PREPARE FROM user-variable — that path was stripping/breaking identifiers on some hosts).
-- Idempotent: Migrator ignores Duplicate column name.

ALTER TABLE carrier_trunks ADD COLUMN `qualify` VARCHAR(8) NOT NULL DEFAULT 'yes';
ALTER TABLE sip_accounts ADD COLUMN `qualify` VARCHAR(8) NOT NULL DEFAULT 'yes';
