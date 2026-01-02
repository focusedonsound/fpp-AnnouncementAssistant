#!/bin/bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"   # show audio volume during announcement
ANN_CLIENT="${ANN_CLIENT:-FPPAnnouncer}"

if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
  echo "Usage: $0 /path/to/announcement.(wav|mp3|ogg|flac|m4a) [duck_percent]"
  exit 1
fi

# MVP behavior: ignore if already playing
exec 9>/tmp/aa_announcement.lock
flock -n 9 || exit 0

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

play_via_pulse() {
  local f="$1"

  # Best effort: decode anything -> wav -> paplay (Pulse)
  if command -v ffmpeg >/dev/null 2>&1; then
    ffmpeg -hide_banner -loglevel error -i "$f" -vn -ac 2 -ar 44100 -f wav - \
      | paplay --client-name="$ANN_CLIENT" --stream-name="Announcement Assistant" -
  else
    # Fallback: try paplay directly (works well for wav)
    paplay --client-name="$ANN_CLIENT" --stream-name="Announcement Assistant" "$f"
  fi
}

# Find sink-input id(s) for fppd (match name OR binary)
mapfile -t FPP_IDS < <(
  pactl list sink-inputs | awk '
    /^Sink Input #/ {id=$3; sub("#","",id)}
    /application\.name = "fppd"/ {print id}
    /application\.process\.binary = "fppd"/ {print id}
  ' | sort -u
)

declare -A ORIG_VOL
DUCKED=0

restore_volumes() {
  if [[ "$DUCKED" -eq 1 ]]; then
    for id in "${!ORIG_VOL[@]}"; do
      pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
    done
  fi
}
trap restore_volumes EXIT

# If show audio is playing via Pulse, duck it. If not, just play the announcement.
if [[ ${#FPP_IDS[@]} -gt 0 ]]; then
  for id in "${FPP_IDS[@]}"; do
    ORIG_VOL["$id"]="$(pactl get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
    pactl set-sink-input-volume "$id" "$DUCK" >/dev/null
  done
  DUCKED=1
fi

play_via_pulse "$ANN_FILE"
exit 0
