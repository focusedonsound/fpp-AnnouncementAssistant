#!/usr/bin/env bash
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SOCKET="/run/pulse/native"
export PULSE_SERVER="unix:${PULSE_SOCKET}"

ts(){ date '+%Y-%m-%d %H:%M:%S'; }
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

# Sink input IDs currently attached to the default sink
get_sink_inputs_for_sink() {
  local sink="$1"
  pactl list short sink-inputs 2>/dev/null | awk -v s="$sink" '$2==s {print $1}'
}

# Optional: log some identifying info so we can see what we’re ducking
log_sink_input_summary() {
  local id="$1"
  local summary
  summary="$(pactl list sink-inputs 2>/dev/null | awk -v target="$id" '
    $0 ~ "^Sink Input #"target { inblk=1; print "id="target; next }
    inblk && /application.name =/ { print "  " $0 }
    inblk && /application.process.binary =/ { print "  " $0 }
    inblk && /media.name =/ { print "  " $0 }
    inblk && /^$/ { exit }
  ')"
  [[ -n "$summary" ]] && while IFS= read -r line; do log "sink-input: $line"; done <<< "$summary"
}

get_sink_input_vol_pct() {
  local id="$1"
  pactl get-sink-input-volume "$id" 2>/dev/null | sed -n 's/.*\/ \([0-9]\+%\) .*/\1/p' | head -n 1
}

play_announcement() {
  local file="$1" sink="$2"
  # Play on the default sink explicitly
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

# Capture what’s playing *before* we start the announcement
PRE_IDS="$(get_sink_inputs_for_sink "$SINK" | xargs echo -n || true)"
log "START: duck=$DUCK file=$FILE sink=$SINK pre_ids=${PRE_IDS:-none}"

declare -A ORIG
if [[ -n "$PRE_IDS" ]]; then
  for id in $PRE_IDS; do
    [[ "$id" =~ ^[0-9]+$ ]] || { log "WARN: skipping non-numeric sink-input id='$id'"; continue; }
    ORIG["$id"]="$(get_sink_input_vol_pct "$id")"
    log "Captured volume: id=$id vol=${ORIG[$id]:-unknown}"
    log_sink_input_summary "$id"
  done

  # Duck them now
  for id in "${!ORIG[@]}"; do
    log "Ducking sink-input id=$id -> $DUCK"
    if pactl set-sink-input-volume "$id" "$DUCK" >>"$LOG_FILE" 2>&1; then
      NOW="$(get_sink_input_vol_pct "$id")"
      log "Duck applied: id=$id now=$NOW"
    else
      log "WARN: duck failed for id=$id"
    fi
  done
else
  log "INFO: No existing sink-inputs on $SINK (nothing to duck)"
fi

# Play announcement
set +e
play_announcement "$FILE" "$SINK"
PLAY_RC=$?
set -e

# Restore volumes
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
