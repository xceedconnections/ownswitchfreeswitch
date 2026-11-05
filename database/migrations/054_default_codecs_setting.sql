-- Seed Settings → Default codecs (PCMU/PCMA/G729/GSM/telephone-event) when missing.
INSERT INTO system_settings (setting_key, setting_value, updated_by)
SELECT 'default_codecs', 'PCMU,PCMA,G729,GSM,telephone-event', NULL
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM system_settings WHERE setting_key = 'default_codecs'
);
