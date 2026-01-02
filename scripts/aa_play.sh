#!/usr/bin/env bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"

if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
  echo "ERROR: Missing/invalid announcement file: $ANN_FILE" >&2
  exit 2
fi

need() { command -v "$1" >/dev/null 2>&1 || { echo "ERROR: '$1' not found" >&2; exit 3; }; }
need pactl
need pacat
need ffmpeg

# MVP behavior: ignore trigger if already busy
if command -v flock >/dev/null 2>&1; then
  exec 9>/tmp/aa_announcementassistant.lock
  flock -n 9 || exit 0
else
  LOCKDIR="/tmp/aa_announcementassistant.lockdir"
  mkdir "$LOCKDIR" 2>/dev/null || exit 0
  trap 'rmdir "$LOCKDIR" 2>/dev/null || true' EXIT
fi

ensure_pulse() {
  if pactl info >/dev/null 2>&1; then return 0; fi
  # Try to start user-mode pulseaudio (Pi/FPP friendly)
  pulseaudio --daemonize=yes --exit-idle-time=-1 >/dev/null 2>&1 || true
  for _ in $(seq 1 50); do
    pactl info >/dev/null 2>&1 && return 0
    sleep 0.1
  done
  echo "ERROR: PulseAudio not running / not reachable" >&2
  return 1
}

ensure_pulse

# Find fppd sink-input(s)
FPP_IDS="$(pactl list sink-inputs | awk '
  /^Sink Input #/ {id=$3; sub(/^#/, "", id)}
  /application\.process\.binary = "fppd"/ {print id}
  /application\.name = "fppd"/ {print id}
' | sort -u)"

if [[ -z "$FPP_IDS" ]]; then
  echo "ERROR: fppd is not showing up as a Pulse sink-input."
  echo "Set FPP Audio Output Device to 'pulse' and restart fppd."
  exit 4
fi

# Capture current volumes
declare -A ORIG_VOL
for id in $FPP_IDS; do
  v="$(pactl get-sink-input-volume "$id" | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
  ORIG_VOL["$id"]="${v:-100%}"
done

# Duck show audio
for id in $FPP_IDS; do
  pactl set-sink-input-volume "$id" "$DUCK" >/dev/null 2>&1 || true
done

# Play announcement as a separate Pulse stream (mixes over show audio)
# Decode anything ffmpeg supports into raw PCM for pacat
ffmpeg -hide_banner -loglevel error -i "$ANN_FILE" -f s16le -ac 2 -ar 44100 - \
  | pacat --raw --channels=2 --rate=44100 --format=s16le --client-name="AnnouncementAssistant" >/dev/null 2>&1 || true

# Restore show audio
for id in $FPP_IDS; do
  pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
done

exit 0
