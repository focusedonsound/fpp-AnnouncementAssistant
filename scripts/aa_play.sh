#!/bin/bash
set -euo pipefail

LOG_FILE="/home/fpp/media/logs/announcementassistant.log"
CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
DUCKER="/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_duck_overlay_pulse.sh"

PULSE_SERVER="unix:/run/pulse/native"
PLAY_PID_FILE="/home/fpp/media/tmp/aa_announcement.paplay.pid"

log() {
  echo "[$(date '+%F %T')] $*" >> "$LOG_FILE"
}

usage() {
  cat <<EOF
Usage:
  $0 --stop
  $0 --slot <0-5>
  $0 /full/path/to/audio [duck%]

Notes:
  - --stop will stop a currently playing announcement (if any)
  - duck can be "25" or "25%" (0..100)
EOF
}

sanitize_duck() {
  local d="${1:-25%}"
  d="$(echo "$d" | tr -d '[:space:]')"
  if [[ "$d" =~ ^[0-9]+$ ]]; then d="${d}%"; fi
  if [[ "$d" =~ ^([0-9]{1,3})%$ ]]; then
    local n="${BASH_REMATCH[1]}"
    if (( n < 0 )); then n=0; fi
    if (( n > 100 )); then n=100; fi
    echo "${n}%"
    return 0
  fi
  echo "25%"
}

stop_announcement() {
  # 1) Preferred: tell PulseAudio to kill our sink-input(s)
  local ids
  ids="$(env PULSE_SERVER="$PULSE_SERVER" pactl list sink-inputs 2>/dev/null | awk '
    /^Sink Input #/ {id=$3}
    /application.name = "AnnouncementAssistant"/ {print id}
  ' || true)"

  if [[ -n "$ids" ]]; then
    while read -r id; do
      [[ -z "$id" ]] && continue
      env PULSE_SERVER="$PULSE_SERVER" pactl kill-sink-input "$id" 2>/dev/null || true
    done <<< "$ids"
    log "[stop] killed sink-input(s): $(echo "$ids" | tr '\n' ' ')"
  fi

  # 2) Fallback: pidfile kill
  if [[ -f "$PLAY_PID_FILE" ]]; then
    local pid
    pid="$(cat "$PLAY_PID_FILE" 2>/dev/null || true)"
    if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
      kill "$pid" 2>/dev/null || true
      sleep 0.1
      kill -9 "$pid" 2>/dev/null || true
      log "[stop] killed paplay pid $pid"
    fi
  fi

  echo "OK"
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ "${1:-}" == "--stop" || "${1:-}" == "stop" ]]; then
  stop_announcement
  exit 0
fi

ANN_FILE=""
DUCK="25%"

if [[ "${1:-}" == "--slot" ]]; then
  SLOT="${2:-}"
  if [[ -z "$SLOT" || ! "$SLOT" =~ ^[0-5]$ ]]; then
    echo "ERROR: --slot must be 0..5" >&2
    exit 2
  fi

  if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config not found: $CONFIG_FILE" >&2
    exit 3
  fi

  IFS=$'\t' read -r ANN_FILE DUCK < <(python3 - <<PY
import json
cfg=json.load(open("$CONFIG_FILE","r"))
buttons=cfg.get("buttons") or []
slot=int("$SLOT")
btn=buttons[slot] if slot < len(buttons) else {}
rel=(btn.get("file") or "").strip()
duck=(btn.get("duck") or cfg.get("duckDefault") or cfg.get("duck") or "25%").strip()
ann="/home/fpp/media/music/" + rel if rel else ""
print(ann + "\t" + duck)
PY
)

  if [[ -z "$ANN_FILE" || ! -f "$ANN_FILE" ]]; then
    echo "ERROR: Slot $SLOT has no valid file configured" >&2
    exit 4
  fi
else
  ANN_FILE="${1:-}"
  DUCK="${2:-25%}"
  if [[ -z "$ANN_FILE" ]]; then
    usage
    exit 2
  fi
  if [[ ! -f "$ANN_FILE" ]]; then
    echo "ERROR: file not found: $ANN_FILE" >&2
    exit 3
  fi
fi

DUCK="$(sanitize_duck "$DUCK")"

if [[ ! -x "$DUCKER" ]]; then
  echo "ERROR: ducker script not executable: $DUCKER" >&2
  exit 5
fi

log "[play] START file=$ANN_FILE duck=$DUCK"
bash "$DUCKER" "$ANN_FILE" "$DUCK" >/dev/null 2>&1 &
echo "OK"
