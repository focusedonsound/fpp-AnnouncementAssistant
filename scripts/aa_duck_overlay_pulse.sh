#!/bin/bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # target volume for fppd during announcement (ex: 25%)

if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
  echo "Usage: $0 /path/to/announcement.wav [duck_percent]"
  exit 1
fi

# MVP behavior: ignore if already playing
exec 9>/tmp/aa_announcement.lock
flock -n 9 || exit 0

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

# Find sink-input id(s) for fppd
mapfile -t FPP_IDS < <(
  pactl list sink-inputs | awk '
    /^Sink Input #/ {id=$3; sub("#","",id)}
    /application\.process\.binary = "fppd"/ {print id}
  '
)

if [[ ${#FPP_IDS[@]} -eq 0 ]]; then
  echo "No fppd sink input found (is FPP playing audio right now?)"
  exit 1
fi

declare -A ORIG_VOL

# Duck show audio
for id in "${FPP_IDS[@]}"; do
  ORIG_VOL["$id"]="$(pactl get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
  pactl set-sink-input-volume "$id" "$DUCK" >/dev/null
done

# Play announcement (over the top)
paplay "$ANN_FILE"

# Restore volumes
for id in "${FPP_IDS[@]}"; do
  pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null
done
