-- Trunk Groups: strategy may be priority | lcr | asr (best connectivity) | random.
-- VARCHAR(16) already wide enough; marker migration for deploy tracking.
UPDATE schema_migrations SET migration = migration WHERE 0;
