#!/usr/bin/env bash
# Install host cron jobs to restore AWG NAT after reboot (and periodic heal).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENSURE_SCRIPT="$SCRIPT_DIR/ensure_awg_nat.sh"
CONTAINER="${AWG_CONTAINER_NAME:-amnezia-awg}"
MARKER="amnezia-panel-awg-nat"
LOG_FILE="${AWG_NAT_LOG:-/var/log/amnezia-awg-nat.log}"

if [ ! -x "$ENSURE_SCRIPT" ]; then
    chmod +x "$ENSURE_SCRIPT"
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker not found — skip AWG NAT cron"
    exit 0
fi

if ! docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "Container '$CONTAINER' not found — skip AWG NAT cron (set AWG_CONTAINER_NAME if different)"
    exit 0
fi

REBOOT_LINE="@reboot sleep 45 && AWG_CONTAINER_NAME=$CONTAINER $ENSURE_SCRIPT >> $LOG_FILE 2>&1 # $MARKER"
HEAL_LINE="*/15 * * * * AWG_CONTAINER_NAME=$CONTAINER $ENSURE_SCRIPT >> $LOG_FILE 2>&1 # $MARKER"

(
    crontab -l 2>/dev/null | grep -v "$MARKER" || true
    echo "$REBOOT_LINE"
    echo "$HEAL_LINE"
) | crontab -

touch "$LOG_FILE" 2>/dev/null || true

echo "AWG NAT cron installed (reboot + every 15 min)"
echo "  Script: $ENSURE_SCRIPT"
echo "  Log:    $LOG_FILE"

AWG_CONTAINER_NAME="$CONTAINER" "$ENSURE_SCRIPT" || true
