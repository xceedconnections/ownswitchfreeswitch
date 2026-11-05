#!/usr/bin/env bash
# Stage 90 - deploy the PHP application as an immutable release, wire secrets from
# /etc/ownswitch, run the migrations hook, and create the first admin.
#
# APPLICATION CONTRACT (all optional; the installer degrades gracefully if absent):
#   public/index.php                      front controller (nginx docroot)
#   composer.json                         -> `composer install --no-dev` is run
#   bin/migrate.php                       migration runner (reads OWNSWITCH_ENV_FILE); exit 0 on success
#   database/migrations/*.sql             fallback when bin/migrate.php is absent (applied in name order,
#                                         tracked in table `schema_migrations`)
#   bin/create-admin.php --email=E --password-stdin [--force-password-change]
#                                         exit 0 = created, exit 10 = an admin already exists
#
# Admin password: OWN_SWITCH_ADMIN_PASS env (or OWNSWITCH_ADMIN_PASS) > interactive prompt >
#                 generated (written once to /root/ownswitch-initial-admin.txt, 0600).
# Run only the admin hook:  sudo deploy/install.sh --create-admin
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "90_app_deploy"

ensure_base_layout
ENV_FILE="${OWN_ETC}/ownswitch.env"
CURRENT="${OWN_OPT}/current"

# ---------------------------------------------------------------------------
# helpers
# ---------------------------------------------------------------------------
run_as_app() { # command...  (runs as the unprivileged app user with the env file exported)
  runuser -u ownswitch -- env -i PATH=/usr/local/bin:/usr/bin:/bin HOME="${OWN_VAR}" \
    OWNSWITCH_ENV_FILE="${ENV_FILE}" APP_ENV=production "$@"
}

migrations_hook() {
  local app="$1"
  if [[ -f "${app}/bin/migrate.php" ]]; then
    log_info "Running bin/migrate.php"
    ( cd "${app}" && run_as_app php bin/migrate.php ) || die "bin/migrate.php failed"
    note_change "ran application migrations (bin/migrate.php)"
  elif compgen -G "${app}/database/migrations/*.sql" >/dev/null; then
    log_info "Applying SQL migrations from database/migrations (built-in runner)"
    mysql_root "${DB_OWNSWITCH}" -e "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB"
    local f name sum seen
    while IFS= read -r f; do
      name="$(basename "$f")"; sum="$(sha256sum "$f" | cut -d' ' -f1)"
      seen="$(mysql_scalar "SELECT checksum FROM ${DB_OWNSWITCH}.schema_migrations WHERE filename='${name}'")"
      if [[ -n "$seen" ]]; then
        [[ "$seen" == "$sum" ]] || die "Migration ${name} changed after being applied (checksum mismatch)"
        continue
      fi
      log_info "  applying ${name}"
      mysql_root "${DB_OWNSWITCH}" <"$f" || die "Migration ${name} failed"
      mysql_root "${DB_OWNSWITCH}" -e "INSERT INTO schema_migrations (filename, checksum) VALUES ('${name}','${sum}')"
      note_change "applied migration ${name}"
    done < <(find "${app}/database/migrations" -maxdepth 1 -type f -name '*.sql' | sort)
  else
    log_warn "No migrations found (no bin/migrate.php, no database/migrations/*.sql) - skipped"
  fi
}

admin_hook() {
  local app="$1" email="${OWNSWITCH_ADMIN_EMAIL}" pass="" generated=0 rc
  pass="${OWN_SWITCH_ADMIN_PASS:-${OWNSWITCH_ADMIN_PASS:-}}"

  if [[ ! -f "${app}/bin/create-admin.php" ]]; then
    log_warn "bin/create-admin.php not present in the deployed app - first admin NOT created."
    log_warn "After deploying the application run:  sudo OWN_SWITCH_ADMIN_PASS='...' $(dirname "${BASH_SOURCE[0]}")/../install.sh --create-admin"
    record_result "${STAGE_FILE}:admin" "PENDING" "no bin/create-admin.php"
    return 0
  fi

  if [[ -z "$pass" ]]; then
    if [[ -t 0 && -t 1 ]] && ! is_true "${NON_INTERACTIVE}"; then
      local p1 p2
      read -r -p "Admin e-mail [${email}]: " in_email || true
      [[ -z "${in_email:-}" ]] || email="$in_email"
      while :; do
        read -r -s -p "Admin password (min 12 chars): " p1; echo
        read -r -s -p "Repeat password: " p2; echo
        if [[ "$p1" != "$p2" ]]; then log_warn "Passwords do not match"; continue; fi
        if (( ${#p1} < 12 )); then log_warn "Password too short"; continue; fi
        pass="$p1"; break
      done
    else
      pass="$(gen_secret 24)A1!"
      generated=1
    fi
  fi
  (( ${#pass} >= 12 )) || die "Admin password must be at least 12 characters"

  local extra=()
  if (( generated )); then extra+=(--force-password-change); fi
  set +e
  ( cd "${app}" && printf '%s' "$pass" | run_as_app php bin/create-admin.php --email="${email}" --password-stdin "${extra[@]}" )
  rc=$?
  set -e
  case "$rc" in
    0)  note_change "created first admin ${email}"
        if (( generated )); then
          ( umask 077; printf 'Ownswitch initial admin\nemail: %s\npassword: %s\n(change it at first login; delete this file afterwards)\n' "$email" "$pass" >/root/ownswitch-initial-admin.txt )
          log_warn "Generated admin password saved to /root/ownswitch-initial-admin.txt (0600). Delete it after first login."
        fi
        record_result "${STAGE_FILE}:admin" "OK" "created ${email}" ;;
    10) log_ok "An admin already exists - nothing to do"
        record_result "${STAGE_FILE}:admin" "OK" "already exists" ;;
    *)  die "bin/create-admin.php failed (exit ${rc})" ;;
  esac
  pass=""
}

# ---------------------------------------------------------------------------
# admin-only mode
# ---------------------------------------------------------------------------
if is_true "${OWNSWITCH_ADMIN_ONLY:-0}"; then
  [[ -f "${ENV_FILE}" ]] || die "${ENV_FILE} missing - run a full install first"
  admin_hook "${CURRENT}"
  exit 0
fi

# ---------------------------------------------------------------------------
# 1. Secrets + env file
# ---------------------------------------------------------------------------
export APP_KEY MYSQL_OWNSWITCH_PASSWORD MYSQL_OPENSIPS_PASSWORD MYSQL_CGRATES_RO_PASSWORD REDIS_PASSWORD \
       RECORDING_NOTIFY_TOKEN FS_ESL_PASSWORD FS_XML_SECRET OPENSIPS_MI_HTTP_URL
APP_KEY="$(ensure_secret app_key 64)"
MYSQL_OWNSWITCH_PASSWORD="$(get_secret mysql_ownswitch_password)"
MYSQL_OPENSIPS_PASSWORD="$(get_secret mysql_opensips_password)"
MYSQL_CGRATES_RO_PASSWORD="$(get_secret mysql_cgrates_ro_password)"
REDIS_PASSWORD="$(get_secret redis_password)"
RECORDING_NOTIFY_TOKEN="$(ensure_secret recording_notify_token 40)"
if is_true "${FREESWITCH_ENABLED}" && [[ -s "$(secret_path freeswitch_esl_password)" ]]; then
  FS_ESL_PASSWORD="$(get_secret freeswitch_esl_password)"
else
  FS_ESL_PASSWORD=""
fi
FS_XML_SECRET="$(ensure_secret freeswitch_xml_secret 40)"
if ss -H -ltn 'sport = :8888' | grep -q .; then OPENSIPS_MI_HTTP_URL="http://127.0.0.1:8888/mi"; else OPENSIPS_MI_HTTP_URL=""; fi

render_template "${TEMPLATE_DIR}/ownswitch.env.tpl" "${ENV_FILE}" 0640 root ownswitch
env_changed="${RENDER_CHANGED}"

# ---------------------------------------------------------------------------
# 2. Build the release from APP_SRC
# ---------------------------------------------------------------------------
# Prefer a tree that actually contains the portal. Mis-persisted APP_SRC (e.g. /opt/ownswitch)
# previously caused a silent 503 placeholder release.
if [[ ! -f "${APP_SRC}/public/index.php" && -f "${REPO_ROOT}/public/index.php" ]]; then
  log_warn "APP_SRC=${APP_SRC} has no public/index.php — using REPO_ROOT=${REPO_ROOT}"
  APP_SRC="${REPO_ROOT}"
  export APP_SRC
fi
if [[ ! -f "${APP_SRC}/public/index.php" && -f /opt/ownswitch-src/public/index.php ]]; then
  log_warn "Falling back to /opt/ownswitch-src"
  APP_SRC=/opt/ownswitch-src
  export APP_SRC
fi
persist_setting APP_SRC "${APP_SRC}"
[[ -d "${APP_SRC}" ]] || die "APP_SRC=${APP_SRC} is not a directory"
[[ -f "${APP_SRC}/public/index.php" ]] \
  || die "APP_SRC=${APP_SRC} has no public/index.php. Clone https://github.com/xceedconnections/ownswitchfreeswitch into /opt/ownswitch-src and re-run stage 90."

stage="$(mktemp -d "${OWN_OPT}/.stage.XXXXXX")"
trap 'rm -rf "$stage"' EXIT

rsync -a --delete \
  --exclude '.git/' --exclude '.github/' --exclude '.idea/' --exclude '.vscode/' \
  --exclude '/deploy/' --exclude '/docs/' --exclude 'node_modules/' --exclude '/tests/' \
  --exclude '.env' --exclude '.env.*' --exclude '*.md' --exclude '/storage/' --exclude '/var/' \
  "${APP_SRC}/" "${stage}/"

placeholder=0
if [[ ! -f "${stage}/public/index.php" ]]; then
  die "rsync from ${APP_SRC} did not produce public/index.php (refusing placeholder)"
fi
# Remove any previous placeholder marker from older releases after we switch
rm -f "${OWN_OPT}/current/.ownswitch-placeholder" 2>/dev/null || true

if [[ -f "${stage}/composer.json" ]]; then
  log_info "composer install --no-dev"
  ( cd "${stage}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts ) \
    || die "composer install failed"
fi

# Content hash => identical source reuses the existing release (idempotent re-runs)
hash="$( cd "${stage}" && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-12 )"
existing="$(find "${OWN_OPT}/releases" -maxdepth 1 -type d -name "*-${hash}" | sort | tail -n1 || true)"

if [[ -n "$existing" ]]; then
  release="$existing"
  log_ok "Release for content hash ${hash} already exists: $(basename "$release")"
  rm -rf "$stage"
else
  release="${OWN_OPT}/releases/$(date -u +%Y%m%d%H%M%S)-${hash}"
  mv "$stage" "$release"
  note_change "created release $(basename "$release")"
fi
trap - EXIT

# Permissions: code is root-owned & read-only for the app; only storage/log dirs are writable.
chown -R root:ownswitch "$release"
chmod 0751 "$release"
find "$release" -mindepth 1 -maxdepth 1 ! -name public ! -name storage -exec chmod -R u=rwX,g=rX,o= {} +
if [[ -d "$release/public" ]]; then
  chmod -R u=rwX,go=rX "$release/public"
fi
ln -sfn "${OWN_VAR}/storage" "$release/storage"
install -d -m 0750 -o ownswitch -g ownswitch "${OWN_VAR}/storage/cache" "${OWN_VAR}/storage/uploads"

# ---------------------------------------------------------------------------
# 3. Migrations (before switching traffic)
# ---------------------------------------------------------------------------
if (( placeholder )); then
  log_info "Placeholder release - migrations skipped"
else
  migrations_hook "$release"
fi

# ---------------------------------------------------------------------------
# 4. Atomic switch + FPM reload (opcache validate_timestamps=0)
# ---------------------------------------------------------------------------
prev="$(readlink -f "${CURRENT}" 2>/dev/null || true)"
if [[ "$prev" != "$(readlink -f "$release")" ]]; then
  tmp_link="${OWN_OPT}/.current.$$"
  ln -sfn "$release" "$tmp_link"
  mv -Tf "$tmp_link" "${CURRENT}"
  note_change "switched ${CURRENT} -> $(basename "$release") (previous: $(basename "${prev:-none}"))"
  switched=1
else
  switched=0
fi
if (( switched )) || [[ "${env_changed}" == "1" ]]; then
  systemctl reload "php${PHP_VERSION}-fpm" || systemctl restart "php${PHP_VERSION}-fpm"
fi

# keep the newest N releases (never the live one)
live="$(readlink -f "${CURRENT}")"
mapfile -t old < <(find "${OWN_OPT}/releases" -maxdepth 1 -mindepth 1 -type d | sort -r | tail -n +"$((APP_RELEASES_KEEP + 1))")
for r in "${old[@]:-}"; do
  [[ -n "$r" && "$(readlink -f "$r")" != "$live" ]] && { rm -rf "$r"; note_change "pruned old release $(basename "$r")"; }
done

# ---------------------------------------------------------------------------
# 5. First admin
# ---------------------------------------------------------------------------
if (( placeholder )); then
  record_result "${STAGE_FILE}:admin" "PENDING" "placeholder release"
  log_warn "Placeholder release deployed: create the first admin later with  sudo deploy/install.sh --create-admin"
elif is_true "${SKIP_ADMIN:-0}"; then
  record_result "${STAGE_FILE}:admin" "SKIPPED" "SKIP_ADMIN=1"
else
  admin_hook "$release"
fi

log_ok "Application release $(basename "$release") live at ${CURRENT}"
