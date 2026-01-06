#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [duck] $*" >> "$LOG_FILE"; }

FILE="${1:-}"
DUCK_RAW="${2:-}"

normalize_duck() {
  local d="${1:-}"
  d="$(echo -n "$d" | tr -d '[:space:]')"
  [[ -z "$d" ]] && { echo "25%"; return 0; }
  [[ "$d" =~ ^[0-9]+$ ]] && d="${d}%"
  [[ "$d" =~ ^[0-9]+%$ ]] || { echo ""; return 0; }
  local n="${d%\%}"
  (( n < 0 )) && n=0
  (( n > 100 )) && n=100
  echo "${n}%"
}

get_default_sink() {
  pactl info 2>/dev/null | awk -F': ' '/^Default Sink:/{print $2; exit}'
}

# Identify FPP sink-input ids and strip leading "#"
get_fpp_sink_inputs() {
  pactl list sink-inputs 2>/dev/null | awk '
    /^Sink Input #/ { id=$3; sub(/^#/, "", id); match=0 }
    /application.process.binary = "fppd"/ { match=1 }
    /application.name = "fppd"/ { match=1 }
    /application.name = "FPP"/ { match=1 }
    /^$/ {
      if (match && id != "") print id
      id=""; match=0
    }
    END { if (match && id != "") print id }
  ' | sort -u | xargs echo -n
}

get_sink_input_vol_pct() {
  local id="$1"
  pactl get-sink-input-volume "$id" 2>/dev/null | sed -n 's/.*\/ \([0-9]\+%\) .*/\1/p' | head -n 1
}

play_announcement() {
  local file="$1" sink="$2"
  if command -v ffmpeg >/dev/null 2>&1; then
    ffmpeg -nostdin -v error -i "$file" -f wav -acodec pcm_s16le -ac 2 -ar 48000 - \
      | paplay --client-name="AnnouncementAssistant" --device="$sink" >>"$LOG_FILE" 2>&1
  else
    paplay --client-name="AnnouncementAssistant" --device="$sink" "$file" >>"$LOG_FILE" 2>&1
  fi
}

if [[ -z "$FILE" || -z "$DUCK_RAW" ]]; then
  log "ERROR: usage aa_duck_overlay_pulse.sh <file> <duck%>"
  exit 2
fi

DUCK="$(normalize_duck "$DUCK_RAW")"
if [[ -z "$DUCK" ]]; then
  log "ERROR: invalid duck value: '$DUCK_RAW'"
  exit 2
fi

if [[ ! -S "$PULSE_SOCKET" ]]; then
  log "ERROR: Pulse socket missing: $PULSE_SOCKET"
  exit 1
fi

SINK="$(get_default_sink)"
if [[ -z "$SINK" ]]; then
  log "ERROR: Could not determine Default Sink"
  exit 1
fi

FPP_IDS="$(get_fpp_sink_inputs)"
log "START: duck=$DUCK file=$FILE sink=$SINK fpp_ids=${FPP_IDS:-none}"

declare -A ORIG
if [[ -n "$FPP_IDS" ]]; then
  for id in $FPP_IDS; do
    if [[ ! "$id" =~ ^[0-9]+$ ]]; then
      log "WARN: skipping non-numeric sink-input id='$id'"
      continue
    fi
    ORIG["$id"]="$(get_sink_input_vol_pct "$id")"
    log "Captured volume: id=$id vol=${ORIG[$id]:-unknown}"
  done

  # Duck (don’t let failures abort playback)
  for id in "${!ORIG[@]}"; do
    log "Ducking sink-input id=$id -> $DUCK"
    pactl set-sink-input-volume "$id" "$DUCK" >>"$LOG_FILE" 2>&1 || log "WARN: duck failed for id=$id"
  done
else
  log "WARN: no FPP sink-input ids found; will play without ducking"
fi

# Play announcement
set +e
play_announcement "$FILE" "$SINK"
PLAY_RC=$?
set -e

# Restore
for id in "${!ORIG[@]}"; do
  v="${ORIG[$id]}"
  if [[ -n "$v" ]]; then
    log "Restoring sink-input id=$id -> $v"
    pactl set-sink-input-volume "$id" "$v" >>"$LOG_FILE" 2>&1 || log "WARN: restore failed for id=$id"
  fi
done

if [[ $PLAY_RC -ne 0 ]]; then
  log "PLAY FAILED rc=$PLAY_RC"
  exit $PLAY_RC
fi

log "DONE"
