-- 009_ledger_guard: append-only ledger (optional if TRIGGER privilege missing).
-- Applied best-effort: failure must not block later migrations when run via fix-registration.sh as root.

DROP TRIGGER IF EXISTS trg_ledger_no_update;
DROP TRIGGER IF EXISTS trg_ledger_no_delete;

CREATE TRIGGER trg_ledger_no_update BEFORE UPDATE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: post a reversing entry';

CREATE TRIGGER trg_ledger_no_delete BEFORE DELETE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only: post a reversing entry';
