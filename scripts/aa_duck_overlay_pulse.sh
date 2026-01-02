#!/usr/bin/env bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # target volume for fppd during announcement (ex: 25%)

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

log() {
  echo "[$(date '+%F %T')] [duck] $*" >> "$LOG_FILE"
}

usage() {
  echo "Usage: $0 /path/to/announcement.wav [duck_percent]" >&2
  exit 1
}

[[ -n "$ANN_FILE" ]] || usage
[[ -f "$ANN_FILE" ]] || { log "ERROR: file not found: $ANN_FILE"; echo "File not found: $ANN_FILE" >&2; exit 1; }

# MVP behavior: ignore if already playing
exec 9>/tmp/aa_announcement.lock
flock -n 9 || { log "BUSY: announcement already running, ignoring"; exit 0; }

# Use explicit server every time (avoids env differences between CLI vs PHP)
pactl_s() { pactl -s "$PULSE_SERVER" "$@"; }
paplay_s() { paplay --server="$PULSE_SERVER" "$@"; }

# Default sink (be explicit so paplay doesn't go somewhere weird)
DEFAULT_SINK="$(pactl_s info | awk -F': ' '/^Default Sink:/ {print $2}')"
if [[ -z "${DEFAULT_SINK:-}" ]]; then
  # fallback: first sink
  DEFAULT_SINK="$(pactl_s list short sinks | awk 'NR==1{print $2}')"
fi
log "START: duck=$DUCK file=$ANN_FILE sink=${DEFAULT_SINK:-unknown}"

# Find sink-input id(s) for fppd
mapfile -t FPP_IDS < <(
  pactl_s list sink-inputs | awk '
    /^Sink Input #/ {id=$3; sub("#","",id)}
    /application\.process\.binary = "fppd"/ {print id}
  '
)

# If no fppd input, just play the announcement (fallback behavior)
if [[ ${#FPP_IDS[@]} -eq 0 ]]; then
  log "WARN: no fppd sink-input found; playing without ducking"
  paplay_s --device="$DEFAULT_SINK" "$ANN_FILE" || { log "ERROR: paplay failed ($?)"; exit 2; }
  log "DONE: played (no duck)"
  exit 0
fi

declare -A ORIG_VOL

restore() {
  for id in "${FPP_IDS[@]}"; do
    if [[ -n "${ORIG_VOL[$id]:-}" ]]; then
      pactl_s set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
    fi
  done
  log "RESTORE: volumes restored"
}
trap restore EXIT

# Duck show audio
for id in "${FPP_IDS[@]}"; do
  ORIG_VOL["$id"]="$(pactl_s get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
  log "DUCK: id=$id orig=${ORIG_VOL[$id]} -> $DUCK"
  pactl_s set-sink-input-volume "$id" "$DUCK" >/dev/null
done

# Play announcement over the top
paplay_s --device="$DEFAULT_SINK" "$ANN_FILE" || { log "ERROR: paplay failed ($?)"; exit 3; }

log "DONE: played"
exit 0
