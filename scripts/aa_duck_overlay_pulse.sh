#!/usr/bin/env bash
# aa_duck_overlay_pulse.sh — duck show audio, play announcement, restore with fades
#
# Usage:
#   aa_duck_overlay_pulse.sh <audio_file> <duck%>
#   aa_duck_overlay_pulse.sh --stop

set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
STATE_FILE="/home/fpp/media/logs/aa_playing.lock"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts()  { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [duck] $*" >> "$LOG_FILE"; }

# Global state — declared early so the EXIT trap can always reference them safely.
declare -A ORIG=()
VOLUMES_RESTORED=false

# ── Helpers ────────────────────────────────────────────────────────────────

normalize_duck() {
    local d="${1:-}"
    d="$(echo -n "$d" | tr -d '[:space:]')"
    [[ -z "$d" ]] && { echo "25%"; return 0; }
    [[ "$d" =~ ^[0-9]+$ ]] && d="${d}%"
    [[ "$d" =~ ^[0-9]+%$ ]] || { echo ""; return 0; }
    local n="${d%\%}"
    (( n < 0  )) && n=0
    (( n > 100 )) && n=100
    echo "${n}%"
}

get_default_sink() {
    pactl info 2>/dev/null | awk -F': ' '/^Default Sink:/{print $2; exit}'
}

get_sink_inputs_for_sink() {
    local sink="$1"
    pactl list short sink-inputs 2>/dev/null | awk -v s="$sink" '$2==s {print $1}'
}

get_sink_input_vol_pct() {
    local id="$1"
    pactl get-sink-input-volume "$id" 2>/dev/null \
        | sed -n 's/.*\/ \([0-9]\+\)% .*/\1/p' | head -n 1
}

# Read a top-level numeric field from the config JSON, with a fallback default.
read_config_float() {
    local key="$1" default="$2"
    if [[ -f "$CONFIG_FILE" ]]; then
        python3 -c "
import json
try:
    v = json.load(open('$CONFIG_FILE')).get('$key', $default)
    print(float(v))
except: print($default)
" 2>/dev/null || echo "$default"
    else
        echo "$default"
    fi
}

play_announcement() {
    local file="$1" sink="$2"
    if command -v ffmpeg >/dev/null 2>&1; then
        ffmpeg -nostdin -v error -i "$file" -f wav -acodec pcm_s16le -ac 2 -ar 48000 - \
            | paplay --client-name="AnnouncementAssistant" --device="$sink" \
              >>"$LOG_FILE" 2>&1
    else
        paplay --client-name="AnnouncementAssistant" --device="$sink" "$file" \
            >>"$LOG_FILE" 2>&1
    fi
}

# Smooth volume fade across multiple sink inputs.
# fade_inputs <steps> <step_sleep_s> [id:from_pct:to_pct ...]
#   Each spec defines the interpolation range for one sink input.
fade_inputs() {
    local steps="$1" step_sleep="$2"
    shift 2
    # Guard: nothing to fade
    [[ $# -eq 0 ]] && return 0

    local i spec id rest from to vol
    for (( i=1; i<=steps; i++ )); do
        for spec in "$@"; do
            id="${spec%%:*}"
            rest="${spec#*:}"
            from="${rest%%:*}"
            to="${rest##*:}"
            vol=$(python3 -c "print(max(0,min(100,round($from+($to-$from)*$i/$steps))))")
            pactl set-sink-input-volume "$id" "${vol}%" >>"$LOG_FILE" 2>&1 || true
        done
        sleep "$step_sleep"
    done
}

# ── Cleanup trap — restores volumes on exit or signal ──────────────────────

restore_all() {
    [[ "$VOLUMES_RESTORED" == "true" ]] && return 0
    VOLUMES_RESTORED=true
    # Guard against empty ORIG (nothing was ducked)
    [[ ${#ORIG[@]} -eq 0 ]] && { log "RESTORE: nothing to restore"; return 0; }
    local id v
    for id in "${!ORIG[@]}"; do
        v="${ORIG[$id]:-}"
        [[ -n "$v" ]] || continue
        pactl set-sink-input-volume "$id" "${v}%" >>"$LOG_FILE" 2>&1 || true
        log "RESTORE: id=$id -> ${v}%"
    done
    log "RESTORE: complete"
}

cleanup() {
    restore_all
    rm -f "$STATE_FILE"
}
trap cleanup EXIT SIGTERM SIGINT

# ── Stop handler ──────────────────────────────────────────────────────────

if [[ "${1:-}" == "--stop" ]]; then
    if [[ -f "$STATE_FILE" ]]; then
        PID=$(cat "$STATE_FILE" 2>/dev/null || true)
        if [[ -n "$PID" && "$PID" =~ ^[0-9]+$ ]]; then
            log "STOP: signalling PID=$PID"
            kill -TERM "$PID" 2>/dev/null || true
            # Give the trap a moment to restore and clean up
            local_wait=0
            while [[ -f "$STATE_FILE" && $local_wait -lt 20 ]]; do
                sleep 0.1
                (( local_wait++ )) || true
            done
            # Force-kill if still alive
            kill -9 "$PID" 2>/dev/null || true
            rm -f "$STATE_FILE"
        else
            log "STOP: invalid PID in state file"
            rm -f "$STATE_FILE"
        fi
    else
        log "STOP: no active playback state found"
        # Fallback: kill any lingering paplay
        pkill -f "paplay.*AnnouncementAssistant" 2>/dev/null || true
    fi
    exit 0
fi

# ── Play flow ─────────────────────────────────────────────────────────────

FILE="${1:-}"
DUCK_RAW="${2:-}"

if [[ -z "$FILE" || -z "$DUCK_RAW" ]]; then
    log "ERROR: usage aa_duck_overlay_pulse.sh <file> <duck%>"
    exit 2
fi

DUCK="$(normalize_duck "$DUCK_RAW")"
if [[ -z "$DUCK" ]]; then
    log "ERROR: invalid duck value: '$DUCK_RAW'"
    exit 2
fi
DUCK_NUM="${DUCK%\%}"

if [[ ! -S "$PULSE_SOCKET" ]]; then
    log "ERROR: Pulse socket missing: $PULSE_SOCKET"
    exit 1
fi

SINK="$(get_default_sink)"
if [[ -z "$SINK" ]]; then
    log "ERROR: Could not determine Default Sink"
    exit 1
fi

# Read fade durations from config (seconds, float)
FADE_DOWN="$(read_config_float fade_down 0.5)"
FADE_UP="$(read_config_float fade_up 1.0)"
FADE_STEPS=20
FADE_DOWN_SLEEP=$(python3 -c "print(round($FADE_DOWN/$FADE_STEPS,4))")
FADE_UP_SLEEP=$(python3 -c "print(round($FADE_UP/$FADE_STEPS,4))")

# Capture sink inputs present before the announcement
PRE_IDS="$(get_sink_inputs_for_sink "$SINK" || true)"
log "START: duck=$DUCK fade_down=${FADE_DOWN}s fade_up=${FADE_UP}s sink=$SINK pre_ids=${PRE_IDS:-none}"

if [[ -n "$PRE_IDS" ]]; then
    for id in $PRE_IDS; do
        [[ "$id" =~ ^[0-9]+$ ]] || { log "WARN: skipping non-numeric id='$id'"; continue; }
        ORIG["$id"]="$(get_sink_input_vol_pct "$id")"
        log "Captured: id=$id vol=${ORIG[$id]:-unknown}"
    done
fi

# Write state lock so --stop can find and signal this process
echo "$$" > "$STATE_FILE"

# ── Fade down ──────────────────────────────────────────────────────────────
if [[ ${#ORIG[@]} -gt 0 ]]; then
    SPECS=()
    for id in "${!ORIG[@]}"; do
        from="${ORIG[$id]:-100}"
        SPECS+=("${id}:${from}:${DUCK_NUM}")
    done
    log "FADE DOWN: ${FADE_DOWN}s (${FADE_STEPS} steps)"
    fade_inputs "$FADE_STEPS" "$FADE_DOWN_SLEEP" "${SPECS[@]+"${SPECS[@]}"}"
    log "FADE DOWN: complete -> duck=$DUCK"
else
    log "INFO: No sink-inputs to duck"
fi

# ── Play announcement ──────────────────────────────────────────────────────
set +e
play_announcement "$FILE" "$SINK"
PLAY_RC=$?
set -e
log "PLAY: rc=$PLAY_RC"

# ── Fade up ────────────────────────────────────────────────────────────────
if [[ ${#ORIG[@]} -gt 0 ]]; then
    SPECS=()
    for id in "${!ORIG[@]}"; do
        to="${ORIG[$id]:-100}"
        SPECS+=("${id}:${DUCK_NUM}:${to}")
    done
    log "FADE UP: ${FADE_UP}s (${FADE_STEPS} steps)"
    fade_inputs "$FADE_STEPS" "$FADE_UP_SLEEP" "${SPECS[@]+"${SPECS[@]}"}"
    log "FADE UP: complete -> restored"
fi

# Mark volumes restored so the EXIT trap skips the instant-restore fallback
VOLUMES_RESTORED=true
rm -f "$STATE_FILE"

[[ $PLAY_RC -ne 0 ]] && { log "PLAY FAILED rc=$PLAY_RC"; exit $PLAY_RC; }
log "DONE"
