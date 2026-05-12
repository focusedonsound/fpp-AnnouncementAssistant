#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
STATE_FILE="/home/fpp/media/logs/aa_playing.lock"
COOLDOWN_FILE="/home/fpp/media/logs/aa_cooldown.ts"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [aa_play] $*" >> "$LOG_FILE"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DUCK_SCRIPT="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"

# ── Config helpers ────────────────────────────────────────────────────────

cfg_str() {
    local key="$1" default="$2"
    [[ -f "$CONFIG_FILE" ]] || { echo "$default"; return; }
    python3 -c "
import json
try:    print(json.load(open('$CONFIG_FILE')).get('$key', '$default'))
except: print('$default')
" 2>/dev/null || echo "$default"
}

cfg_float() {
    local key="$1" default="$2"
    [[ -f "$CONFIG_FILE" ]] || { echo "$default"; return; }
    python3 -c "
import json
try:    print(float(json.load(open('$CONFIG_FILE')).get('$key', $default)))
except: print($default)
" 2>/dev/null || echo "$default"
}

slot_interrupt_enabled() {
    local slot="${1:-}"
    [[ -z "$slot" || ! -f "$CONFIG_FILE" ]] && { echo "false"; return; }
    python3 -c "
import json
try:
    cfg = json.load(open('$CONFIG_FILE'))
    btn = cfg.get('buttons', [])
    idx = int('$slot')
    print('true' if idx < len(btn) and btn[idx].get('interrupt', False) else 'false')
except: print('false')
" 2>/dev/null || echo "false"
}

# ── Stop handler ──────────────────────────────────────────────────────────

if [[ "${1:-}" == "--stop" ]]; then
    log "STOP requested"
    [[ -x "$DUCK_SCRIPT" ]] || { log "ERROR: duck script missing"; exit 1; }
    exec "$DUCK_SCRIPT" --stop
fi

# ── Play ──────────────────────────────────────────────────────────────────

FILE="${1:-}"
DUCK="${2:-25%}"
SLOT="${3:-}"   # Optional: slot index (0-5), used for per-slot interrupt check

log "----"
log "START file=${FILE:-<none>} duck=$DUCK slot=${SLOT:-none}"

if [[ -z "$FILE" ]];     then log "ERROR: Missing file arg";                            exit 2; fi
if [[ ! -f "$FILE" ]];   then log "ERROR: File not found: $FILE";                       exit 2; fi
if [[ ! -x "$DUCK_SCRIPT" ]]; then log "ERROR: Duck script not executable: $DUCK_SCRIPT"; exit 2; fi

# ── Interrupt protection ──────────────────────────────────────────────────

BEHAVIOR="$(cfg_str behavior ignore)"      # ignore | queue | interrupt
COOLDOWN="$(cfg_float cooldown 3.0)"
FORCE_INTERRUPT="$(slot_interrupt_enabled "$SLOT")"

if [[ "$FORCE_INTERRUPT" == "true" ]]; then
    # Per-slot high-priority: always stop whatever is playing and proceed.
    if [[ -f "$STATE_FILE" ]]; then
        log "INTERRUPT: slot=$SLOT forcing stop of current playback"
        "$DUCK_SCRIPT" --stop 2>/dev/null || true
        sleep 0.5
    fi

elif [[ "$BEHAVIOR" == "interrupt" ]]; then
    # Global interrupt policy: stop current playback if busy.
    if [[ -f "$STATE_FILE" ]]; then
        log "INTERRUPT: policy=interrupt stopping current playback"
        "$DUCK_SCRIPT" --stop 2>/dev/null || true
        sleep 0.5
    fi

elif [[ "$BEHAVIOR" == "queue" ]]; then
    # Queue: wait for current playback to finish before starting.
    if [[ -f "$STATE_FILE" ]]; then
        log "QUEUE: waiting for current playback to finish..."
        wait_secs=0
        while [[ -f "$STATE_FILE" && $wait_secs -lt 300 ]]; do
            sleep 1
            (( wait_secs++ )) || true
        done
        if [[ -f "$STATE_FILE" ]]; then
            log "QUEUE: timeout after ${wait_secs}s — giving up"
            exit 1
        fi
        log "QUEUE: current finished after ${wait_secs}s, proceeding"
    fi

else
    # Ignore (default): drop if busy or within cooldown.
    if [[ -f "$STATE_FILE" ]]; then
        log "BUSY: dropping trigger (policy=ignore, playback active)"
        exit 0
    fi
    if [[ -f "$COOLDOWN_FILE" ]]; then
        LAST_TS=$(cat "$COOLDOWN_FILE" 2>/dev/null || echo "0")
        NOW_TS=$(date +%s)
        ELAPSED=$(( NOW_TS - ${LAST_TS:-0} ))
        COOLDOWN_INT=${COOLDOWN%.*}   # integer part for bash comparison
        if (( ELAPSED < COOLDOWN_INT )); then
            log "COOLDOWN: dropping trigger (${ELAPSED}s elapsed, cooldown=${COOLDOWN}s)"
            exit 0
        fi
    fi
fi

# Stamp the cooldown clock at dispatch time
date +%s > "$COOLDOWN_FILE"

log "DISPATCH: duck=$DUCK file=$FILE"
"$DUCK_SCRIPT" "$FILE" "$DUCK"
RC=$?
log "DONE rc=$RC"

# ── Play count tracking ────────────────────────────────────────────────────
# Increment today + lifetime count for this slot on successful play.
COUNT_FILE="/home/fpp/media/logs/aa_play_counts.json"
if [[ $RC -eq 0 && -n "$SLOT" ]]; then
    python3 - "$SLOT" "$COUNT_FILE" << 'PYEOF' 2>/dev/null || true
import json, sys
from datetime import date
slot, path = sys.argv[1], sys.argv[2]
try:    d = json.load(open(path))
except: d = {}
if slot not in d:
    d[slot] = {"total": 0, "today": 0, "date": ""}
today = str(date.today())
if d[slot].get("date") != today:
    d[slot]["today"] = 0
    d[slot]["date"]  = today
d[slot]["total"] += 1
d[slot]["today"] += 1
json.dump(d, open(path, "w"))
PYEOF
    log "COUNT: incremented slot=$SLOT"
fi

# ── Telemetry (non-blocking, fire-and-forget) ──────────────────────────────
# Both calls run in background so they never delay or block playback.
TELEMETRY_PY="${SCRIPT_DIR}/aa_telemetry.py"
if command -v python3 >/dev/null 2>&1 && [[ -f "$TELEMETRY_PY" ]]; then
    PLAY_RESULT="ok"
    [[ $RC -ne 0 ]] && PLAY_RESULT="error"
    python3 "$TELEMETRY_PY" --event "$FILE" "$PLAY_RESULT" "pulseaudio" &>/dev/null &
    python3 "$TELEMETRY_PY" --ping &>/dev/null &
fi

exit $RC
