#!/usr/bin/env bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # target volume for fppd during announcement (ex: 25%)

usage() {
  echo "Usage: $0 /path/to/announcement.(wav|mp3|ogg|flac|m4a) [duck_percent]" >&2
  exit 2
}

[[ -n "$ANN_FILE" ]] || usage
[[ -f "$ANN_FILE" ]] || { echo "ERROR: File not found: $ANN_FILE" >&2; exit 3; }

need() { command -v "$1" >/dev/null 2>&1 || { echo "ERROR: '$1' not found" >&2; exit 4; }; }
need pactl
need ffmpeg
need pacat
need flock

# Prefer FPP-writable temp area over /tmp (some environments lock /tmp down)
LOCK_DIR="/home/fpp/media/tmp"
mkdir -p "$LOCK_DIR" >/dev/null 2>&1 || true

LOCK_FILE="$LOCK_DIR/aa_announcement.lock"
exec 9>"$LOCK_FILE"
# MVP behavior: ignore if already playing
flock -n 9 || exit 0

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

# Get default sink name (portable: pactl info)
DEFAULT_SINK="$(pactl info 2>/dev/null | awk -F': ' '/^Default Sink:/{print $2; exit}')"
if [[ -z "${DEFAULT_SINK:-}" ]]; then
  # fallback: first sink name from short list
  DEFAULT_SINK="$(pactl list short sinks 2>/dev/null | awk 'NR==1{print $2; exit}')"
fi

# Get sink sample rate (for cleaner playback / less crackle)
SINK_RATE="44100"
if [[ -n "${DEFAULT_SINK:-}" ]]; then
  # Parse "Sample Specification: s16le 2ch 44100Hz"
  rate="$(pactl list sinks 2>/dev/null | awk -v s="$DEFAULT_SINK" '
    $1=="Name:" {name=$2}
    name==s && /Sample Specification:/ {
      for(i=1;i<=NF;i++){
        if($i ~ /^[0-9]+Hz$/){ gsub(/Hz/,"",$i); print $i; exit }
      }
    }')"
  [[ -n "${rate:-}" ]] && SINK_RATE="$rate"
fi

# Find sink-input ids for fppd (prefer process.binary, fallback to application.name)
mapfile -t FPP_IDS < <(
  pactl list sink-inputs 2>/dev/null | awk '
    /^Sink Input #/ {id=$3; sub("#","",id)}
    /application\.process\.binary = "fppd"/ {print id}
    /application\.name = "fppd"/ {print id}
  ' | awk '!seen[$0]++'
)

# Always define ORIG_VOL so trap can't trip over -u
declare -A ORIG_VOL=()

restore_volumes() {
  # Only attempt restore if we actually captured anything
  if [[ ${#ORIG_VOL[@]} -gt 0 ]]; then
    for id in "${!ORIG_VOL[@]}"; do
      pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
    done
  fi
}
trap restore_volumes EXIT

# If no fppd sink-input exists (show not playing), just play the announcement (no ducking)
if [[ ${#FPP_IDS[@]} -eq 0 ]]; then
  ffmpeg -hide_banner -loglevel error -i "$ANN_FILE" -f s16le -ac 2 -ar "$SINK_RATE" - \
    | pacat --raw --channels=2 --rate="$SINK_RATE" --format=s16le \
        --client-name="AnnouncementAssistant" >/dev/null 2>&1
  exit 0
fi

# Capture + duck show audio
for id in "${FPP_IDS[@]}"; do
  v="$(pactl get-sink-input-volume "$id" 2>/dev/null | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
  [[ -n "${v:-}" ]] || v="100%"
  ORIG_VOL["$id"]="$v"
  pactl set-sink-input-volume "$id" "$DUCK" >/dev/null 2>&1 || true
done

# Play announcement as its own stream mixed over show audio
ffmpeg -hide_banner -loglevel error -i "$ANN_FILE" -f s16le -ac 2 -ar "$SINK_RATE" - \
  | pacat --raw --channels=2 --rate="$SINK_RATE" --format=s16le \
      --client-name="AnnouncementAssistant" >/dev/null 2>&1

exit 0
