-- Trunk Groups: strategy may be priority | lcr | random (VARCHAR already wide enough).
-- Catch-all member trunks are stored as routing_rules.prefix = '*' for the plan.
-- No ALTER required on existing installs; this migration is a no-op marker for deploy tracking.
UPDATE schema_migrations SET migration = migration WHERE 0;
