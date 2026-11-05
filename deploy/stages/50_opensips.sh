#!/usr/bin/env bash
# Stage 50 - OpenSIPS is no longer installed.
# FreeSWITCH Sofia owns UDP/TCP 5060 (stage 75). On a host that already has OpenSIPS,
# run deploy/remove-opensips.sh after FreeSWITCH is listening. This stage does not
# drop the ownswitch database.
set -Eeuo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/lib/common.sh"
stage_init "50_opensips"

if systemctl list-unit-files 'opensips.service' --no-legend 2>/dev/null | grep -q opensips \
  || dpkg -l 'opensips' 2>/dev/null | grep -q '^ii'; then
  log_warn "OpenSIPS is still installed. After stage 75, run: sudo bash ${DEPLOY_DIR}/remove-opensips.sh"
  record_result "${STAGE_FILE}" "SKIPPED" "OpenSIPS retired; remove-opensips.sh still pending"
else
  log_ok "OpenSIPS is not installed (FreeSWITCH is the SIP edge)"
  record_result "${STAGE_FILE}" "SKIPPED" "OpenSIPS not used"
fi
exit 0
