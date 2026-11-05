-- Phase 1 ops settings (tax, capacity, failover, kill switch). Key/value store — insert defaults if missing.
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
 ('tax_rate_percent', '0'),
 ('system_max_concurrent', '0'),
 ('system_max_cps', '0'),
 ('failover_max_retries', '3'),
 ('failover_sip_codes', '408,480,486,503'),
 ('kill_switch', '0');
