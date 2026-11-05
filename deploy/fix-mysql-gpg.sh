#!/usr/bin/env bash
# One-shot hotfix for EXPKEYSIG B7B3B788A8D3785C / unsigned MySQL APT repo.
# Prefer the full automatic path:
#   curl -fsSL .../deploy/repair-and-continue.sh | sudo bash
#
#   curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/fix-mysql-gpg.sh | sudo bash
set -Eeuo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root"; exit 1; }

KEYRING=/usr/share/keyrings/mysql.gpg
CODENAME="$(. /etc/os-release; echo "${VERSION_CODENAME:-noble}")"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> Removing broken MySQL APT lists/keyrings…"
shopt -s nullglob
for f in /etc/apt/sources.list.d/mysql*.list /etc/apt/sources.list.d/mysql*.list.* /etc/apt/sources.list.d/mysql*.sources; do
  [[ -e "$f" ]] || continue
  mv -f "$f" "${f}.bak.$(date +%s)" || true
done
shopt -u nullglob
rm -f /etc/apt/trusted.gpg.d/mysql* /usr/share/keyrings/mysql*.gpg /etc/apt/keyrings/mysql*.gpg 2>/dev/null || true
apt-key del B7B3B788A8D3785C 2>/dev/null || true

echo "==> Downloading MySQL APT signing keys…"
: >"$TMP/all.asc"
for url in \
  https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 \
  https://repo.mysql.com/RPM-GPG-KEY-mysql-2023 \
  "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB7B3B788A8D3785C"
do
  curl -fsSL --connect-timeout 20 --max-time 60 "$url" -o "$TMP/k" && cat "$TMP/k" >>"$TMP/all.asc" || true
done
grep -q 'BEGIN PGP PUBLIC KEY BLOCK' "$TMP/all.asc" || { echo "No key material downloaded"; exit 1; }

install -d -m 0755 /usr/share/keyrings /etc/apt/keyrings
gpg --batch --yes --dearmor -o "$KEYRING" "$TMP/all.asc"
chmod 644 "$KEYRING"
cp -f "$KEYRING" /usr/share/keyrings/mysql-apt-config.gpg
cp -f "$KEYRING" /usr/share/keyrings/mysql-8.4.gpg
cp -f "$KEYRING" /etc/apt/keyrings/mysql.gpg

if dpkg -l mysql-apt-config 2>/dev/null | grep -q '^ii'; then
  echo "==> Purging obsolete mysql-apt-config…"
  DEBIAN_FRONTEND=noninteractive apt-get purge -y mysql-apt-config >/dev/null 2>&1 || true
fi

PROTO=https
curl -fsS --connect-timeout 10 --max-time 20 -o /dev/null https://repo.mysql.com/ || PROTO=http
cat >/etc/apt/sources.list.d/mysql.list <<EOF
# Fixed by Ownswitch deploy/fix-mysql-gpg.sh
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-8.4-lts
deb [signed-by=${KEYRING}] ${PROTO}://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-tools
EOF

mkdir -p /etc/ownswitch
grep -q '^MYSQL_INSTALL_METHOD=' /etc/ownswitch/install.conf 2>/dev/null \
  && sed -i 's/^MYSQL_INSTALL_METHOD=.*/MYSQL_INSTALL_METHOD=direct/' /etc/ownswitch/install.conf \
  || { touch /etc/ownswitch/install.conf; echo 'MYSQL_INSTALL_METHOD=direct' >>/etc/ownswitch/install.conf; }

echo "==> apt-get update…"
apt-get update -o DPkg::Lock::Timeout=600

CAND="$(apt-cache policy mysql-server 2>/dev/null | awk '/Candidate:/ {print $2; found=1} END {exit 0}')"
echo "==> mysql-server candidate: ${CAND:-none}"
[[ "${CAND}" == 8.4.* ]] || { echo "ERROR: expected 8.4.x candidate"; exit 1; }

echo "==> OK. Prefer the automatic resume:"
echo "    curl -fsSL https://raw.githubusercontent.com/xceedconnections/ownswitchfreeswitch/main/deploy/repair-and-continue.sh | sudo bash"
