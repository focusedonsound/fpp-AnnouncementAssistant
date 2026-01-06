#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [aa_play] $*" >> "$LOG_FILE"; }

FILE="${1:-}"
DUCK="${2:-25%}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DUCK_SCRIPT="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"

log "----"
log "START args: ${FILE:-<none>} ${DUCK:-<none>} user=$(id -un) uid=$(id -u) gid=$(id -g)"
log "Pulse diag: socket_exists=$([[ -S "$PULSE_SOCKET" ]] && echo yes || echo no) PULSE_SERVER=$PULSE_SERVER"

if [[ -z "$FILE" ]]; then
  log "ERROR: Missing file arg"
  exit 2
fi

if [[ ! -f "$FILE" ]]; then
  log "ERROR: File not found: $FILE"
  exit 2
fi

if [[ ! -x "$DUCK_SCRIPT" ]]; then
  log "ERROR: Duck script missing or not executable: $DUCK_SCRIPT"
  exit 2
fi

# Helpful diagnostics (won’t abort if pactl fails)
pactl info >> "$LOG_FILE" 2>&1 || true
pactl list short sinks >> "$LOG_FILE" 2>&1 || true
pactl list short sink-inputs >> "$LOG_FILE" 2>&1 || true

log "DISPATCH duck+play: script=$DUCK_SCRIPT file=$FILE duck=$DUCK"
"$DUCK_SCRIPT" "$FILE" "$DUCK"
RC=$?
log "DONE rc=$RC"
exit $RC
