#!/usr/bin/env bash
# Compatibility wrapper — use fix-fs-trunk-register.sh
exec bash "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/fix-fs-trunk-register.sh" "$@"
