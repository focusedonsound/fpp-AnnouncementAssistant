#!/usr/bin/env bash
set -euo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
LOCK_FILE="/run/lock/announcementassistant.lock"

ANN_FILE="${1:-}"
DUCK="${2:-25%}"

log() {
  local msg="$*"
  printf '[%(%Y-%m-%d %H:%M:%S)T] [duck] %s\n' -1 "$msg" >> "$LOG_FILE" 2>/dev/null || true
}

die() {
  log "ERROR: $*"
  echo "ERROR: $*" >&2
  exit 1
}

need() { command -v "$1" >/dev/null 2>&1 || die "Missing dependency: $1"; }
need pactl
need pacat
need ffmpeg

[[ -n "$ANN_FILE" ]] || die "Missing announcement file argument"
[[ -f "$ANN_FILE" ]] || die "Announcement file not found: $ANN_FILE"

# Normalize DUCK input (must end with %)
DUCK="${DUCK//[[:space:]]/}"
[[ -n "$DUCK" ]] || DUCK="25%"
if [[ "$DUCK" =~ ^[0-9]+$ ]]; then
  DUCK="${DUCK}%"
elif [[ ! "$DUCK" =~ ^[0-9]+%$ ]]; then
  die "Invalid duck value '$DUCK' (expected e.g. 25%)"
fi

# Acquire lock (ignore triggers while busy)
exec 9>"$LOCK_FILE" || die "Cannot open lock file: $LOCK_FILE"
if ! flock -n 9; then
  log "BUSY: announcement already playing"
  exit 0
fi

# Ensure we restore volumes even if something fails
declare -A ORIG_VOL=()
FPP_IDS=()

restore_volumes() {
  if ((${#FPP_IDS[@]})); then
    for id in "${FPP_IDS[@]}"; do
      if [[ -n "${ORIG_VOL[$id]:-}" ]]; then
        pactl set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>&1 || true
      fi
    done
    log "RESTORE: volumes restored"
  fi
}
trap restore_volumes EXIT

# Pick sink: prefer Default Sink, fall back to "sb"
SINK="$(pactl info 2>/dev/null | awk -F': ' '/^Default Sink:/{print $2; exit}')"
[[ -n "${SINK:-}" ]] || SINK="sb"

# Find FPP sink-input IDs (numeric only) by scanning sink-input blocks
# We look for application.process.binary = "fppd" (and also accept application.name="fppd" just in case)
mapfile -t FPP_IDS < <(
  pactl list sink-inputs 2>/dev/null \
  | awk '
      /^Sink Input #/ { id=$3; sub(/^#/, "", id); isfpp=0 }
      /application\.process\.binary = "fppd"/ { isfpp=1 }
      /application\.name = "fppd"/ { isfpp=1 }
      /^$/ { if (isfpp && id ~ /^[0-9]+$/) print id; id=""; isfpp=0 }
      END { if (isfpp && id ~ /^[0-9]+$/) print id }
    ' | sort -n -u
)

# Capture original volumes
if ((${#FPP_IDS[@]})); then
  for id in "${FPP_IDS[@]}"; do
    v="$(pactl get-sink-input-volume "$id" 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i ~ /%/){print $i; exit}}' | head -n 1)"
    [[ -n "${v:-}" ]] || v="100%"
    ORIG_VOL["$id"]="$v"
  done
fi

log "START: duck=$DUCK file=$ANN_FILE sink=$SINK fpp_ids=${FPP_IDS[*]:-none}"

# Apply duck (only to fppd stream)
if ((${#FPP_IDS[@]})); then
  for id in "${FPP_IDS[@]}"; do
    pactl set-sink-input-volume "$id" "$DUCK" >/dev/null 2>&1 || true
  done
fi

# Play announcement as a separate Pulse stream (mixes over show audio)
# Decode to raw PCM and feed pacat with a bit more buffering to reduce “jumpy” playback.
ffmpeg -hide_banner -loglevel error -i "$ANN_FILE" -f s16le -ac 2 -ar 44100 - \
  | pacat --raw --channels=2 --rate=44100 --format=s16le \
          --latency-msec=120 --process-time-msec=30 \
          --device="$SINK" --client-name="AnnouncementAssistant" \
          >/dev/null 2>&1

log "DONE: overlay played"
exit 0
