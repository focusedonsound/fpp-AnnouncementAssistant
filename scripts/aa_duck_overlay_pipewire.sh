#!/usr/bin/env bash
# aa_duck_overlay_pipewire.sh — duck the show via fppd's Stream Slot API, play
# the announcement on its own slot, restore.
#
# Used on GStreamer/PipeWire-backed fppd builds (probed by aa_play.sh at
# dispatch time). Talks to FPP entirely through its documented Command API
# (Set Slot Volume / Play Media / Stop Media Slot / Media Slot Status) —
# no pactl, no sudo, no reach into FPP internals. See PLUGIN_GUIDELINES.md
# §2.4 ("Do not use sudo") and §3 ("Talk to FPP through its interfaces").
#
# Usage:
#   aa_duck_overlay_pipewire.sh <audio_file> <duck%>
#   aa_duck_overlay_pipewire.sh --stop

set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
STATE_FILE="/home/fpp/media/plugins/fpp-AnnouncementAssistant/state/aa_playing_slot.lock"
FPP_BASE="http://localhost"
SHOW_SLOT=1
POLL_INTERVAL_S=0.5
MAX_WAIT_S=180

ts()  { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [duck-pw] $*" >> "$LOG_FILE"; }

VOLUMES_RESTORED=false

# ── Config ──────────────────────────────────────────────────────────────

read_config_int() {
    local key="$1" default="$2"
    if [[ -f "$CONFIG_FILE" ]]; then
        python3 -c "
import json
try:
    v = json.load(open('$CONFIG_FILE')).get('$key', $default)
    print(int(v))
except: print($default)
" 2>/dev/null || echo "$default"
    else
        echo "$default"
    fi
}

normalize_duck() {
    local d="${1:-}"
    d="$(echo -n "$d" | tr -d '[:space:]%')"
    [[ "$d" =~ ^[0-9]+$ ]] || { echo ""; return 0; }
    (( d < 0 ))   && d=0
    (( d > 100 )) && d=100
    echo "$d"
}

# ── FPP Command API helper ─────────────────────────────────────────────
# Always POST a JSON array body — avoids arg-splitting issues that a GET-form
# URL would have with a file path (which itself contains "/").
#
# Retries a transient failure (a slow/dropped round-trip to fppd, not an
# HTTP-level error - curl's own exit code doesn't change on a non-2xx
# response here) up to 3 times with a short backoff, rather than one
# unretried attempt. Reproduced on real hardware: a single hiccup here,
# under this script's own set -Eeuo pipefail, used to kill the whole
# script silently right at this line - the EXIT trap still logged
# RESTORE, so the log looked like a normal run except for the missing
# PLAY/DONE lines in between, and FPP's own Command wrapper always
# reports "complete" regardless, so neither the log nor the UI ever
# surfaced that anything had gone wrong.
api_cmd() {
    local name="$1"; shift
    local args_json encoded_name resp attempt
    args_json="$(python3 -c "import json,sys; print(json.dumps(sys.argv[1:]))" "$@")"
    encoded_name="$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$name")"

    for attempt in 1 2 3; do
        resp="$(curl -s -m 10 -X POST -H 'Content-Type: application/json' \
            -d "$args_json" \
            "${FPP_BASE}/api/command/${encoded_name}")"
        if [[ -n "$resp" ]]; then
            echo "$resp"
            return 0
        fi
        log "WARNING: '$name' API call returned nothing (attempt ${attempt}/3)"
        [[ "$attempt" -lt 3 ]] && sleep 1
    done
    log "ERROR: '$name' API call failed after 3 attempts - fppd may be unresponsive"
    return 1
}

media_slot_status() {
    curl -s -m 10 "${FPP_BASE}/api/command/Media%20Slot%20Status"
}

# Returns 0 and prints a free slot number (2-5), or returns 1 if none free.
find_free_slot() {
    local status
    status="$(media_slot_status 2>/dev/null || true)"
    [[ -z "$status" ]] && return 1
    python3 -c "
import json, sys
try:
    slots = json.loads(sys.argv[1])
    for s in slots:
        if s.get('slot', 1) > 1 and s.get('status') != 'playing':
            print(s['slot'])
            sys.exit(0)
except Exception:
    pass
sys.exit(1)
" "$status"
}

slot_is_playing() {
    local slot="$1" status
    status="$(media_slot_status 2>/dev/null || true)"
    [[ -z "$status" ]] && return 1
    python3 -c "
import json, sys
try:
    slots = json.loads(sys.argv[1])
    for s in slots:
        if s.get('slot') == int(sys.argv[2]):
            sys.exit(0 if s.get('status') == 'playing' else 1)
except Exception:
    pass
sys.exit(1)
" "$status" "$slot"
}

# ── Cleanup ─────────────────────────────────────────────────────────────

restore_show_volume() {
    [[ "$VOLUMES_RESTORED" == "true" ]] && return 0
    VOLUMES_RESTORED=true
    local normal
    normal="$(read_config_int normal_volume 100)"
    api_cmd "Set Slot Volume" "$SHOW_SLOT" "$normal" >/dev/null 2>&1 || true
    log "RESTORE: slot=$SHOW_SLOT -> ${normal}%"
}

cleanup() {
    restore_show_volume
    rm -f "$STATE_FILE"
}
trap cleanup EXIT SIGTERM SIGINT

# ── Stop handler ──────────────────────────────────────────────────────────

if [[ "${1:-}" == "--stop" ]]; then
    if [[ -f "$STATE_FILE" ]]; then
        SLOT="$(cat "$STATE_FILE" 2>/dev/null || true)"
        if [[ "$SLOT" =~ ^[0-9]+$ ]]; then
            log "STOP: stopping slot=$SLOT"
            api_cmd "Stop Media Slot" "$SLOT" >/dev/null 2>&1 || true
        fi
    else
        log "STOP: no active playback state found"
    fi
    exit 0
fi

# ── Play flow ─────────────────────────────────────────────────────────────

FILE="${1:-}"
DUCK_RAW="${2:-}"

if [[ -z "$FILE" || -z "$DUCK_RAW" ]]; then
    log "ERROR: usage aa_duck_overlay_pipewire.sh <file> <duck%>"
    exit 2
fi

DUCK="$(normalize_duck "$DUCK_RAW")"
if [[ -z "$DUCK" ]]; then
    log "ERROR: invalid duck value: '$DUCK_RAW'"
    exit 2
fi

SLOT="$(find_free_slot || true)"
if [[ -z "$SLOT" ]]; then
    log "ERROR: no free stream slot (2-5) available to play announcement"
    exit 1
fi

echo "$SLOT" > "$STATE_FILE"
log "START: duck=${DUCK}% file=$FILE slot=$SLOT"

# Duck the show (slot 1) — independent of the announcement's own slot, so
# only the show attenuates while the overlay plays at full volume.
api_cmd "Set Slot Volume" "$SHOW_SLOT" "$DUCK" >/dev/null 2>&1 || true
log "DUCK: slot=$SHOW_SLOT -> ${DUCK}%"

# Dispatch the announcement onto its own slot: media, loop=1, volumeAdjust=0, slot.
# Explicit failure handling, not a bare call under set -e: api_cmd's own
# retries already absorb a transient hiccup, but if it still fails, this
# must log clearly and exit through the normal trap (which restores show
# volume) rather than die silently with no indication which step failed.
if ! RESP="$(api_cmd "Play Media" "$FILE" "1" "0" "$SLOT")"; then
    log "ERROR: Play Media dispatch failed - announcement will not play, restoring show volume"
    exit 1
fi
log "PLAY: dispatched to slot=$SLOT resp=$RESP"

# Wait for it to actually start (bounded — a slot that never starts, e.g. a
# bad file, must not hang ducking forever), then for it to finish (bounded).
MAX_START_POLLS=$(python3 -c "print(int(5 / $POLL_INTERVAL_S))")
MAX_RUN_POLLS=$(python3 -c "print(int($MAX_WAIT_S / $POLL_INTERVAL_S))")

polls=0
while ! slot_is_playing "$SLOT" && (( polls < MAX_START_POLLS )); do
    sleep "$POLL_INTERVAL_S"
    (( polls++ )) || true
done

polls=0
while slot_is_playing "$SLOT" && (( polls < MAX_RUN_POLLS )); do
    sleep "$POLL_INTERVAL_S"
    (( polls++ )) || true
done
if (( polls >= MAX_RUN_POLLS )) && slot_is_playing "$SLOT"; then
    log "WARN: slot=$SLOT still playing after ${MAX_WAIT_S}s, giving up waiting"
fi

restore_show_volume
rm -f "$STATE_FILE"
log "DONE"
