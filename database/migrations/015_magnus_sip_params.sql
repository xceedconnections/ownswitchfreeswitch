-- Intentionally a no-op.
-- Older copies of this file contained `ADD COLUMN qualify` (reserved in MySQL 8) and broke migrate.
-- Real column adds live in 018_fix_qualify_columns.sql and 019_magnus_sip_params_rest.sql.
DO 0;
