#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DUCKER="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

log() {
  local msg="$*"
  printf '[%(%Y-%m-%d %H:%M:%S)T] [play] %s\n' -1 "$msg" >> "$LOG_FILE" 2>/dev/null || true
}

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>                # play configured slot" >&2
  echo "  $0 </path/to/audio> [duck%]  # direct play (testing)" >&2
  exit 2
}

arg1="${1:-}"
[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { echo "ERROR: Missing/invalid ducker script: $DUCKER" >&2; exit 3; }

# Slot mode
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "ERROR: slot must be 0-5" >&2; exit 2; }

  [[ -f "$CONFIG_FILE" ]] || { echo "ERROR: Config not found: $CONFIG_FILE" >&2; exit 4; }

  duck="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}"))
print(cfg.get("duck","25%"))
PY
)"

  ann_file="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}"))
slot=int(${slot})
buttons=cfg.get("buttons",[])
f=""
if 0 <= slot < len(buttons):
  f=(buttons[slot] or {}).get("file","") or ""
print(f)
PY
)"

  [[ -n "$ann_file" ]] || { echo "ERROR: No announcement file configured for slot $slot" >&2; exit 5; }

  # Normalize common “music/…” paths into absolute
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  [[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; exit 6; }

  log "PLAY slot=${slot} duck=${duck} file=${ann_file}"
  exec "$DUCKER" "$ann_file" "$duck"
fi

# Direct mode
ann_file="$arg1"
duck="${2:-25%}"
[[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; exit 6; }

log "PLAY direct duck=${duck} file=${ann_file}"
exec "$DUCKER" "$ann_file" "$duck"
