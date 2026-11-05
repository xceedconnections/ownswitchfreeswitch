#!/usr/bin/env bash
# =============================================================================
# Ownswitch - main installer for a clean Ubuntu Server 24.04 LTS host.
#
#   sudo ./deploy/install.sh [options]
#
# Idempotent: safe to re-run at any time (it converges the host to the desired
# state; services are only restarted when their configuration changed).
#
# Windows checkout note: exec bit cannot be set on NTFS. On Ubuntu run:
#   chmod +x deploy/install.sh && find deploy -name '*.sh' -exec chmod +x {} +
# (or just invoke with `sudo bash deploy/install.sh`).
#
# Options:
#   --config FILE        extra KEY=VALUE settings file (non-secret!)
#   --only 30,50         run only these stage numbers
#   --from 50            run stages >= 50
#   --skip 75,20         skip these stage numbers
#   --list               list stages and exit
#   --create-admin       only run the first-admin creation hook (stage 90 part)
#   --yes | -y           do not ask for confirmation
#   --non-interactive    never prompt (admin password: env or auto-generate)
#   -h | --help
#
# Key environment variables (all optional; see docs/INSTALL.md):
#   OWNSWITCH_DOMAIN, OWNSWITCH_ADMIN_EMAIL, OWN_SWITCH_ADMIN_PASS,
#   PUBLIC_IP, PRIVATE_IP, FREESWITCH_ENABLED=0|1, LETSENCRYPT_EMAIL,
#   SIP_ALLOW_CIDRS, SSH_ALLOW_CIDRS, APP_SRC, CGRATES_VERSION ...
# =============================================================================
set -Eeuo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SELF_DIR}/lib/common.sh"

ONLY=""; FROM=""; SKIP=""; ASSUME_YES=0; LIST_ONLY=0; CREATE_ADMIN_ONLY=0

usage() { sed -n '2,32p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while (($#)); do
  case "$1" in
    --config)          export OWNSWITCH_CONFIG="$2"; shift 2 ;;
    --only)            ONLY="$2"; shift 2 ;;
    --from)            FROM="$2"; shift 2 ;;
    --skip)            SKIP="$2"; shift 2 ;;
    --list)            LIST_ONLY=1; shift ;;
    --create-admin)    CREATE_ADMIN_ONLY=1; shift ;;
    -y|--yes)          ASSUME_YES=1; shift ;;
    --non-interactive) export NON_INTERACTIVE=1; ASSUME_YES=1; shift ;;
    -h|--help)         usage; exit 0 ;;
    *) die "Unknown option: $1 (see --help)" ;;
  esac
done

mapfile -t STAGE_FILES < <(find "${SELF_DIR}/stages" -maxdepth 1 -type f -name '[0-9][0-9]_*.sh' | sort)
((${#STAGE_FILES[@]})) || die "No stage scripts found in ${SELF_DIR}/stages"

stage_num() { basename "$1" | cut -c1-2; }

in_csv() { # needle csv
  local n=$1 IFS=','; local x
  for x in $2; do [[ "$x" == "$n" ]] && return 0; done
  return 1
}

if ((LIST_ONLY)); then
  for f in "${STAGE_FILES[@]}"; do printf '%s  %s\n' "$(stage_num "$f")" "$(basename "$f")"; done
  exit 0
fi

require_root
setup_logging
trap 'trap_err $LINENO' ERR

ensure_base_layout_early() { install -d -m 0750 -o root -g root "${OWN_STATE}" "${OWN_STATE}/runs"; }
ensure_base_layout_early
init_config
detect_os

log_step "Ownswitch installer  run=${RUN_ID}  host=$(hostname)"
log_info "OS: ${OS_PRETTY} (${OS_ARCH})"
log_info "Domain=${OWNSWITCH_DOMAIN}  public_ip=${PUBLIC_IP}  private_ip=${PRIVATE_IP}  freeswitch=${FREESWITCH_ENABLED}"
log_info "App source: ${APP_SRC}"
log_info "Log file: ${INSTALL_LOG}"

if ((CREATE_ADMIN_ONLY)); then
  export OWNSWITCH_ADMIN_ONLY=1
  ONLY="90"
fi

if ! ((ASSUME_YES)) && [[ -t 0 && -t 1 ]]; then
  read -r -p "Proceed with installation on this host? [y/N] " ans
  [[ "${ans:-}" =~ ^[Yy]$ ]] || die "Aborted by user"
fi

export RUN_ID
FAILED_STAGE=""
for f in "${STAGE_FILES[@]}"; do
  n="$(stage_num "$f")"; name="$(basename "$f")"
  if [[ -n "$ONLY" ]] && ! in_csv "$n" "$ONLY"; then continue; fi
  if [[ -n "$FROM" ]] && ((10#$n < 10#$FROM)); then continue; fi
  if [[ -n "$SKIP" ]] && in_csv "$n" "$SKIP"; then
    record_result "$name" "SKIPPED" "--skip"; log_info "Skipping ${name}"; continue
  fi

  start_ts=$SECONDS
  if bash "$f"; then
    # stages that decide to skip themselves record their own status
    if ! grep -q "^${name}"$'\t' "$(run_dir)/results.tsv" 2>/dev/null; then
      record_result "$name" "OK" "$((SECONDS - start_ts))s"
    fi
  else
    rc=$?
    record_result "$name" "FAILED" "exit ${rc}"
    FAILED_STAGE="$name"
    log_error "Stage ${name} failed (exit ${rc}). Fix the problem and re-run: sudo ${0} --from ${n}"
    break
  fi
done

if [[ -n "$FAILED_STAGE" ]]; then
  # Still produce a report so there is something to attach to a support ticket.
  if [[ "$FAILED_STAGE" != 99_* ]]; then bash "${SELF_DIR}/stages/99_health_report.sh" || true; fi
  exit 1
fi

log_ok "Ownswitch installation finished. Report: ${OWN_STATE}/reports/latest.txt"
