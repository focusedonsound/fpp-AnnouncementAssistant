#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="${MEDIADIR:-/home/fpp/media}/logs/plugin-fpp-AnnouncementAssistant.log"
CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
STATE_DIR="/home/fpp/media/plugins/fpp-AnnouncementAssistant/state"
STATE_FILE="${STATE_DIR}/aa_playing.lock"
COOLDOWN_FILE="${STATE_DIR}/aa_cooldown.ts"
mkdir -p "$STATE_DIR" 2>/dev/null || true

# This is the one script both real entry points share: the FPP Command API
# (fppd runs Commands as root) and this plugin's own www/trigger.php "test"
# button (PHP-FPM, runs as the unprivileged fpp user). Whichever runs
# first decides this directory's (and its files') ownership - reported on
# real hardware (issue #3): root creates it first, and the next fpp-run
# attempt to write aa_cooldown.ts fails outright, since an unprivileged
# process can never fix a root-owned directory or file itself, only root
# can. So do that proactively, every time this happens to be the one
# running as root, rather than leaving it to chance which path runs
# first - via a trap (not just once at the top) so it also catches files
# THIS run itself freshly creates while running as root (confirmed on
# real hardware: a single top-of-script-only sweep left aa_cooldown.ts
# freshly root-owned again the moment this same root run wrote it a few
# lines later, reopening the exact same window for the very next fpp-only
# run). -R so an already-existing root-owned file from before this fix
# gets repaired too, matching the reporter's own manual fix.
fix_state_ownership() {
    [[ "$(id -u)" -eq 0 ]] || return 0
    chown -R fpp:fpp "$STATE_DIR" 2>/dev/null || true
    chmod -R u+rwX,g+rwX "$STATE_DIR" 2>/dev/null || true
}
fix_state_ownership
trap fix_state_ownership EXIT

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [aa_play] $*" >> "$LOG_FILE"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Backend selection ───────────────────────────────────────────────────
# Probe fppd's Command API rather than branching on FPP version: whether
# Stream Slots (GStreamer/PipeWire) are actually active is what matters, not
# which major version is installed (see PLUGIN_GUIDELINES.md — a plugin can
# be asked to differentiate real backend behavior, not just declare a
# version range). Where available, duck via the documented Command API
# (no pactl, no sudo — see aa_duck_overlay_pipewire.sh). Where not, fall
# back to the existing PulseAudio sink-input approach unchanged, which is
# what's tested and working on pre-PipeWire FPP installs.
pipewire_slots_available() {
    local code
    code="$(curl -s -m 3 -o /dev/null -w '%{http_code}' http://localhost/api/command/Media%20Slot%20Status 2>/dev/null || echo 000)"
    [[ "$code" == "200" ]]
}

if pipewire_slots_available; then
    DUCK_SCRIPT="${SCRIPT_DIR}/aa_duck_overlay_pipewire.sh"
else
    DUCK_SCRIPT="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"
fi

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
log "START file=${FILE:-<none>} duck=$DUCK slot=${SLOT:-none} backend=$(basename "$DUCK_SCRIPT")"

if [[ -z "$FILE" ]];    then log "ERROR: Missing file arg";                            exit 2; fi
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
COUNT_FILE="/home/fpp/media/plugins/fpp-AnnouncementAssistant/state/aa_play_counts.json"
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

exit $RC
