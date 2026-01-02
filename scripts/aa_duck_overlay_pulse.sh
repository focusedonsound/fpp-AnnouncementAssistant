#!/bin/bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # target volume for fppd during announcement (ex: 25%)

if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
  echo "Usage: $0 /path/to/announcement.(wav|mp3) [duck_percent]"
  exit 1
fi

# MVP: ignore if already busy playing an announcement
exec 9>/tmp/aa_announcement.lock
flock -n 9 || exit 0

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

have_pactl=0
have_paplay=0
command -v pactl >/dev/null 2>&1 && have_pactl=1
command -v paplay >/dev/null 2>&1 && have_paplay=1

# If Pulse tools aren't present, last-ditch fallback:
# - This may fail while show audio is playing (device busy), but will work when idle.
fallback_play() {
  if command -v aplay >/dev/null 2>&1; then
    aplay "$ANN_FILE" >/dev/null 2>&1 || aplay -D default "$ANN_FILE" || true
  else
    echo "ERROR: Neither paplay nor aplay is available to play: $ANN_FILE"
    return 1
  fi
}

# If pactl can't talk to Pulse, we can't duck — just try to play.
if [[ "$have_pactl" -eq 0 ]]; then
  [[ "$have_paplay" -eq 1 ]] && paplay "$ANN_FILE" || fallback_play
  exit 0
fi
if ! pactl info >/dev/null 2>&1; then
  [[ "$have_paplay" -eq 1 ]] && paplay "$ANN_FILE" || fallback_play
  exit 0
fi

# Find sink-input id(s) for fppd (more than one is possible)
mapfile -t FPP_IDS < <(
  pactl list sink-inputs | awk '
    /^Sink Input #/ {id=$3; gsub("#","",id)}
    /application\.process\.binary = "fppd"/ {print id}
    /application\.name = "fppd"/ {print id}
  ' | awk 'NF && !seen[$0]++'
)

declare -A ORIG_VOL
restore_volumes() {
  # Best-effort restore
  if [[ ${#ORIG_VOL[@]} -gt 0 ]]; then
    for id in "${!ORIG_VOL[@]}"; do
      pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
    done
  fi
}
trap restore_volumes EXIT INT TERM

# If no fppd sink inputs, show audio isn't currently playing.
# Fallback behavior: just play announcement at normal volume.
if [[ ${#FPP_IDS[@]} -eq 0 ]]; then
  if [[ "$have_paplay" -eq 1 ]]; then
    paplay "$ANN_FILE"
  else
    fallback_play
  fi
  exit 0
fi

# Duck show audio
for id in "${FPP_IDS[@]}"; do
  ORIG_VOL["$id"]="$(pactl get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
  [[ -n "${ORIG_VOL[$id]}" ]] || ORIG_VOL["$id"]="100%"
  pactl set-sink-input-volume "$id" "$DUCK" >/dev/null
done

# Play announcement over the top
if [[ "$have_paplay" -eq 1 ]]; then
  paplay "$ANN_FILE"
else
  fallback_play
fi
