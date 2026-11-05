#!/usr/bin/env bash
# Show which Ownswitch health checks failed.
#   sudo bash /opt/ownswitch-src/deploy/show-health-fails.sh
set -euo pipefail
RDIR=/var/lib/ownswitch-install/reports
R="${1:-}"
if [[ -z "$R" ]]; then
  if [[ -L "${RDIR}/latest.txt" || -f "${RDIR}/latest.txt" ]]; then
    R="${RDIR}/latest.txt"
  else
    R="$(ls -1t "${RDIR}"/deploy-report-*.txt 2>/dev/null | head -n1 || true)"
  fi
fi
[[ -n "${R:-}" && -f "$R" ]] || {
  echo "No report found. Run: sudo bash /opt/ownswitch-src/deploy/install.sh --only 99"
  exit 1
}
echo "Report: $R"
echo
echo "----- FAIL lines -----"
grep -E '\[FAIL\]|>>> FAIL' "$R" || true
# Also parse JSON if present
J="${RDIR}/latest.json"
if [[ -f "$J" ]]; then
  echo
  echo "----- latest.json FAILs -----"
  python3 - "$J" <<'PY' 2>/dev/null || true
import json,sys
d=json.load(open(sys.argv[1]))
fails=[c for c in d.get("checks",[]) if c.get("status")=="FAIL"]
if not fails:
    print("(none in JSON)")
else:
    for c in fails:
        print(f"FAIL | {c.get('name')} | {c.get('detail')}")
PY
fi
echo
echo "Re-run: sudo bash /opt/ownswitch-src/deploy/install.sh --only 99"
