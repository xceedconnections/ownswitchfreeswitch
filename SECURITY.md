# Ownswitch — Security Design

Goal: resist common web, telephony, and operational attacks. No system is unhackable; controls below are mandatory for Ownswitch.

## 1. Threat model (summary)

| Asset | Threats |
|---|---|
| Customer balances / ledger | Double-spend, forged CDRs, privilege escalation |
| SIP credentials / IP trunks | Theft, credential stuffing, toll fraud |
| CDR / recordings PII | IDOR, XSS, insecure direct object refs |
| Rating engines / DB | Unauthorized RPC, SQLi, public exposure |
| Admin sessions | Session fixation, CSRF, weak passwords |

## 2. Web application controls

### SQL injection

- PDO prepared statements only; `PDO::ATTR_EMULATE_PREPARES = false`.
- Never concatenate user input into SQL.
- Whitelist ORDER BY columns and ASC/DESC.
- LIMIT/OFFSET as bound integers or validated ints.
- App DB user: least privilege (no SUPER, no FILE unless required).
- Automated tests for search/filter/login/import endpoints.

### XSS

- Context escape with `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- Treat CDR fields, SIP headers, CLIDs, CSV imports as untrusted.
- CSP header; avoid inline JS where practical; no `innerHTML` with raw data.

### CSRF

- Per-session tokens on all state-changing browser requests.
- Validate method and Content-Type for APIs.

### AuthN / sessions

- `password_hash` / `password_verify` (PASSWORD_DEFAULT).
- HTTP-only, SameSite=Lax/Strict, Secure (HTTPS) cookies.
- Regenerate session ID on login and privilege change.
- Login throttle + temporary lockout; generic error messages.
- Optional TOTP MFA for admin/reseller privileged roles.
- Session revoke on password reset / suspension.

### AuthZ / tenancy

- Enforce permissions in middleware **and** services.
- Ownership checks on every CDR, recording, invoice, customer, SIP account.
- Exports and workers inherit the same tenant scope.
- Never trust browser-supplied customer_id without authz.

### Other HTTP

- TLS via Nginx; HSTS when HTTPS stable.
- Security headers: CSP, X-Content-Type-Options, Frame-Options/CSP frame-ancestors, Referrer-Policy.
- Rate-limit login and public APIs.
- Upload allowlists; path traversal checks; no shell with raw request data.
- Secrets outside web root and git (`/etc/ownswitch/secrets.env` or equivalent).
- Redact credentials in logs.

## 3. SIP / telephony security

- Digest auth with stored HA1 or equivalent; never show plaintext password after create.
- IP allow/deny lists; immediate revocation.
- Concurrent call and CPS limits.
- Destination allow/block policies; high-cost destination alerts.
- Fail closed when CGRateS unreachable for prepaid (configurable; default reject).
- Do not retry answered calls in failover (prevents double charge + weird media).

## 4. Network exposure

Bind to localhost and firewall-deny by default:

- MySQL, Redis, CGRateS JSON-RPC, OpenSIPS MI, FreeSWITCH ESL, recording notify (allow only OpenSIPS/RTPengine hosts).

Public: HTTPS portal, SIP signaling ports, RTP range as required.

## 5. Secrets management

- Installer generates random passwords (`openssl rand -base64 32`).
- Stored in root-readable config outside repo.
- `.env.example` documents keys without values.
- No production credentials in source, tests, or docs screenshots.

## 6. Audit and fraud

- Audit log: admin mutations, provisioning, playback, exports, login failures.
- Signaling fraud rules (auth failures, CPS, short calls, spend spikes) with alert / suspend / reject actions.
- Audio fraud: pluggable analyzer; store model version + confidence; **do not** auto-kill solely on unvalidated audio score.
- Direct-media calls: audio inspection unavailable — UI and policies must state this.

## 7. Recordings

- Files outside web root.
- Authenticated streaming with authz; signed short-lived URLs optional.
- Retention jobs; audit on play/download.

## 8. Supply chain / ops

- Composer lockfile; periodic `composer audit`.
- OS unattended security updates recommended in docs.
- Static analysis in CI (PHPStan/Psalm level to be set in Phase 9).
- Backup encryption; restore drills; config backup before reload.

## 9. Security test suite (Phase 9 / ongoing)

Mapped to master spec items 22–24:

- SQLi payloads on search/login/import
- XSS in CDR rendering
- CSRF negative tests
- Session fixation / Secure cookie flags
- IDOR across customers/resellers
- Authz matrix per role

## 10. Installation security checklist

1. Validate OS and harden SSH.
2. UFW/nftables: allow only required ports.
3. `mysql_secure_installation` equivalent automation.
4. Redis `bind 127.0.0.1` + requirepass.
5. CGRateS listen localhost.
6. Create non-root MySQL app users per database.
7. First admin created interactively with strong password (not default).
8. HTTPS certificates (Let’s Encrypt or provided).
9. Deployment report lists listening ports and auth status.
