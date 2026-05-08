#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [aa_play] $*" >> "$LOG_FILE"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DUCK_SCRIPT="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"

# ── Stop handler ──────────────────────────────────────────────────────────
if [[ "${1:-}" == "--stop" ]]; then
    log "STOP requested"
    if [[ ! -x "$DUCK_SCRIPT" ]]; then
        log "ERROR: duck script missing: $DUCK_SCRIPT"
        exit 1
    fi
    exec "$DUCK_SCRIPT" --stop
fi

# ── Play ──────────────────────────────────────────────────────────────────
FILE="${1:-}"
DUCK="${2:-25%}"

log "----"
log "START args: ${FILE:-<none>} ${DUCK} user=$(id -un) uid=$(id -u)"
log "Pulse diag: socket=$([[ -S "$PULSE_SOCKET" ]] && echo ok || echo MISSING)"

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

log "DISPATCH: script=$DUCK_SCRIPT file=$FILE duck=$DUCK"
exec "$DUCK_SCRIPT" "$FILE" "$DUCK"
