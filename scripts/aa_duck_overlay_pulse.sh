#!/usr/bin/env bash
set -euo pipefail

ANN_FILE="${1:-}"
DUCK="${2:-25%}"     # duck target for the SHOW stream(s) while announcement plays

usage() {
  echo "Usage: $0 /path/to/announcement.(wav|mp3|ogg|flac|m4a) [duck_percent]" >&2
  exit 2
}

[[ -n "$ANN_FILE" && -f "$ANN_FILE" ]] || usage

need() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "ERROR: '$1' not found" >&2
    exit 3
  }
}

# Best practice: we want Pulse tools. We'll try to recover if Pulse isn't running.
need pactl

# MVP behavior: ignore trigger if already busy
acquire_lock() {
  if command -v flock >/dev/null 2>&1; then
    exec 9>/tmp/aa_announcementassistant.lock
    flock -n 9 || exit 0
  else
    local d="/tmp/aa_announcementassistant.lockdir"
    mkdir "$d" 2>/dev/null || exit 0
    trap 'rmdir "$d" 2>/dev/null || true' EXIT
  fi
}
acquire_lock

# Allow docker/Pi to override Pulse socket; otherwise use default Pulse discovery.
export PULSE_SERVER="${PULSE_SERVER:-}"

ensure_pulse() {
  pactl info >/dev/null 2>&1 && return 0

  # Try starting user-mode pulseaudio (more Pi/FPP friendly than system mode)
  if command -v pulseaudio >/dev/null 2>&1; then
    pulseaudio --daemonize=yes --exit-idle-time=-1 >/dev/null 2>&1 || true
    for _ in $(seq 1 50); do
      pactl info >/dev/null 2>&1 && return 0
      sleep 0.1
    done
  fi

  return 1
}

# Fallback: ALSA-only attempt (may fail if device is busy, but better than nothing)
alsa_fallback_play() {
  if command -v aplay >/dev/null 2>&1; then
    aplay "$ANN_FILE" >/dev/null 2>&1 || aplay -D default "$ANN_FILE" >/dev/null 2>&1 || true
    return 0
  fi
  echo "ERROR: Pulse not reachable and no 'aplay' fallback available." >&2
  return 1
}

if ! ensure_pulse; then
  alsa_fallback_play
  exit 0
fi

# Find fppd sink-input ids (strip any '#' that sneaks in)
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

# If show audio isn't currently playing, just play announcement (no duck needed).
duck_show=1
if [[ ${#FPP_IDS[@]} -eq 0 ]]; then
  duck_show=0
fi

# Capture + duck show audio
if [[ "$duck_show" -eq 1 ]]; then
  for id in "${FPP_IDS[@]}"; do
    v="$(pactl get-sink-input-volume "$id" \
        | awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}')"
    ORIG_VOL["$id"]="${v:-100%}"
  done

  for id in "${FPP_IDS[@]}"; do
    pactl set-sink-input-volume "$id" "$DUCK" >/dev/null 2>&1 || true
  done
fi

# Play announcement as its own Pulse stream so it MIXES over fppd
# Prefer ffmpeg->pacat for “anything goes” formats; fallback to paplay.
if command -v ffmpeg >/dev/null 2>&1 && command -v pacat >/dev/null 2>&1; then
  ffmpeg -hide_banner -loglevel error -i "$ANN_FILE" -f s16le -ac 2 -ar 44100 - \
    | pacat --raw --channels=2 --rate=44100 --format=s16le \
            --client-name="AnnouncementAssistant" >/dev/null 2>&1 || true
elif command -v paplay >/dev/null 2>&1; then
  paplay "$ANN_FILE" >/dev/null 2>&1 || true
else
  # Last-ditch
  alsa_fallback_play || true
fi

exit 0
