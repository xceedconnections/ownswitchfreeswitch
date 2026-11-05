#!/usr/bin/env bash
# Diagnose ViciDial/Asterisk SIP user that "registers" on PBX but portal shows offline / no INVITE.
# Usage: sudo bash /opt/ownswitch-src/deploy/diagnose-vicidial-sip.sh [username]
set -euo pipefail
USER="${1:-test}"
CFG="${OPENSIPS_CFG:-/etc/opensips/opensips.cfg}"

echo "======== ViciDial / dynamic SIP diagnose (user=$USER) ========"
echo
echo "== 1) opensips.subscriber =="
mysql -Nuroot opensips -e \
  "SELECT username, domain, LEFT(password,3) AS pw, cid_mode, allow_calls FROM subscriber WHERE username='${USER}'" \
  2>/dev/null || echo "(no subscriber row — sync SIP user from portal)"

echo
echo "== 2) opensips.location (REGISTER in DB) =="
mysql -Nuroot opensips -e \
  "SELECT username, contact, received, expires, FROM_UNIXTIME(expires) AS exp_utc,
          IF(expires > UNIX_TIMESTAMP(), 'LIVE', 'EXPIRED/MISSING') AS state
   FROM location WHERE username='${USER}'" 2>/dev/null || echo "(no location rows)"

echo
echo "== 3) Recent OpenSIPS REGISTER/INVITE for this user =="
journalctl -u opensips -n 400 --no-pager 2>/dev/null \
  | grep -iE "REGISTER|INVITE|auth failed|${USER}" \
  | tail -n 40 || echo "(no matching logs)"

echo
echo "== 4) fail2ban (Asterisk IP may be banned after digest fails) =="
if command -v fail2ban-client >/dev/null 2>&1; then
  fail2ban-client status opensips-auth 2>/dev/null || fail2ban-client status 2>/dev/null | head -n 20 || true
else
  echo "(fail2ban-client not found)"
fi

echo
echo "== 5) Listening SIP =="
ss -ulnp | grep -E ':5060|:5061' || true
ss -tlnp | grep -E ':5060|:5061' || true

echo
echo "======== How to interpret ========"
echo "• location LIVE  → Asterisk DID register; portal should show online after UI fix (refresh-ui)."
echo "• location empty + REGISTER auth failed → wrong password/realm/domain on ViciDial trunk."
echo "• location empty + no REGISTER logs → Asterisk is NOT hitting this OpenSIPS (wrong host/port/firewall)."
echo "• INVITE auth failed → same digest issue; fix ViciDial SIP username/secret/domain = portal Domain IP or FQDN."
echo "• fail2ban banned →: fail2ban-client set opensips-auth unbanip <ASTERISK_PUBLIC_IP>"
echo
echo "ViciDial trunk tip (dynamic digest):"
echo "  Host/Proxy = Ownswitch public IP or SIP domain"
echo "  Username   = ${USER}   Secret = portal SIP password"
echo "  From user  = ${USER}   (must match Authorization username)"
echo "  Register   = yes       Context as needed"
echo "  Domain/realm must match what OpenSIPS challenges (usually the Domain IP/FQDN you set on the phone)."
