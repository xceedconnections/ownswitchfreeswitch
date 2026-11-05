#!/usr/bin/env bash
# Stage 95 - systemd units for workers / scheduler / backups, log rotation, backup tool.
# Units guard themselves with ConditionPathExists, so they are harmless until the application ships
# bin/worker.php and bin/console.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "95_workers"

ensure_base_layout
export OWN_OPT OWN_ETC OWN_VAR OWN_LOG

# --- backup tool + secret ---------------------------------------------------
install -d -m 0755 /usr/local/sbin
if ! cmp -s "${DEPLOY_DIR}/bin/ownswitch-backup" /usr/local/sbin/ownswitch-backup 2>/dev/null; then
  install -m 0750 -o root -g root "${DEPLOY_DIR}/bin/ownswitch-backup" /usr/local/sbin/ownswitch-backup
  note_change "installed /usr/local/sbin/ownswitch-backup"
fi
ensure_secret backup_passphrase 64 >/dev/null
if [[ ! -f "${OWN_ETC}/backup.conf" ]]; then
  cat >"${OWN_ETC}/backup.conf" <<'EOF'
# ownswitch-backup settings (KEY=VALUE). See docs/BACKUP.md
BACKUP_DIR=/var/backups/ownswitch
RETENTION_DAYS=14
#BACKUP_RSYNC_TARGET=backup@offsite.example.com:/srv/backups/ownswitch
#BACKUP_INCLUDE_RECORDINGS=0
EOF
  chown root:root "${OWN_ETC}/backup.conf"; chmod 0600 "${OWN_ETC}/backup.conf"
  note_change "wrote ${OWN_ETC}/backup.conf"
fi

# --- systemd units ----------------------------------------------------------
units_changed=0
for u in ownswitch-worker.service ownswitch-scheduler.service ownswitch-scheduler.timer \
         ownswitch-backup.service ownswitch-backup.timer; do
  render_template "${TEMPLATE_DIR}/systemd/${u}" "/etc/systemd/system/${u}" 0644 root root
  [[ "${RENDER_CHANGED}" == "1" ]] && units_changed=1
done
(( units_changed )) && daemon_reload || true

# --- logrotate --------------------------------------------------------------
render_template "${TEMPLATE_DIR}/logrotate-ownswitch.tpl" /etc/logrotate.d/ownswitch 0644 root root

# --- enable -----------------------------------------------------------------
systemctl enable ownswitch-worker.service >/dev/null 2>&1 || true
if [[ -f "${OWN_OPT}/current/bin/worker.php" ]]; then
  svc_apply ownswitch-worker "${units_changed}"
else
  log_info "bin/worker.php not deployed yet: ownswitch-worker enabled but dormant (ConditionPathExists)"
fi
for t in ownswitch-scheduler.timer ownswitch-backup.timer; do
  systemctl enable --now "$t" >/dev/null 2>&1 || die "could not enable ${t}"
  log_ok "${t} enabled"
done

log_ok "Workers/timers installed. Backup passphrase: $(secret_path backup_passphrase) (copy it offline!)"
