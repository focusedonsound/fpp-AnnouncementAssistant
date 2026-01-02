#!/bin/bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # target volume for fppd during announcement (ex: 25%)

if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
  echo "Usage: $0 /path/to/announcement.(wav|mp3|m4a|etc) [duck_percent]"
  exit 1
fi

# MVP behavior: ignore if already playing
exec 9>/tmp/aa_announcement.lock
flock -n 9 || exit 0

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

# Find sink-input id(s) for fppd (may be 0 if show audio isn't currently playing)
mapfile -t FPP_IDS < <(
  pactl list sink-inputs | awk '
    /^Sink Input #/ {id=$3; sub("#","",id)}
    /application\.process\.binary = "fppd"/ {print id}
  '
)

declare -A ORIG_VOL

restore_volumes() {
  for id in "${FPP_IDS[@]:-}"; do
    if [[ -n "${ORIG_VOL[$id]:-}" ]]; then
      pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
    fi
  done
}
trap restore_volumes EXIT

# Duck show audio (only if fppd is currently producing a sink input)
if [[ ${#FPP_IDS[@]} -gt 0 ]]; then
  for id in "${FPP_IDS[@]}"; do
    ORIG_VOL["$id"]="$(pactl get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
    pactl set-sink-input-volume "$id" "$DUCK" >/dev/null
  done
fi

play_via_pulse() {
  local f="$1"

  # Best path: decode anything ffmpeg supports -> raw PCM -> pacat to PulseAudio
  if command -v ffmpeg >/dev/null 2>&1 && command -v pacat >/dev/null 2>&1; then
    ffmpeg -v error -i "$f" -f s16le -acodec pcm_s16le -ac 2 -ar 44100 - \
      | pacat --raw --format=s16le --rate=44100 --channels=2 \
          --property=application.name=FPPAnnouncer \
          --property=media.role=announcement
    return
  fi

  # Fallback: works reliably for WAV, sometimes other formats depending on libs
  paplay "$f"
}

play_via_pulse "$ANN_FILE" 
