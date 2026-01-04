#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DUCKER="${SCRIPT_DIR}/aa_duck_overlay_pulse.sh"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

log() {
  local msg="$*"
  printf '[%(%F %T)T] %s\n' -1 "$msg" >> "$LOG_FILE"
}

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>" >&2
  echo "  $0 </path/to/audio> [duck%]" >&2
  exit 2
}

arg1="${1:-}"
[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { echo "ERROR: ducker not executable: $DUCKER" >&2; exit 3; }

# Slot mode
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "ERROR: slot must be 0-5" >&2; exit 2; }

  [[ -f "$CONFIG_FILE" ]] || { echo "ERROR: missing config: $CONFIG_FILE" >&2; exit 4; }

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
buttons=cfg.get("buttons",[]) or []
if slot < 0 or slot >= len(buttons):
    print("")
else:
    print((buttons[slot] or {}).get("file","") or "")
PY
)"

  if [[ -z "$ann_file" ]]; then
    echo "ERROR: No file configured for slot $slot" >&2
    exit 5
  fi

  # Normalize common "music/..." into absolute
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  [[ -f "$ann_file" ]] || { echo "ERROR: file not found: $ann_file" >&2; exit 6; }

  log "[play] PLAY slot=${slot} duck=${duck} file=${ann_file}"
  exec "$DUCKER" "$ann_file" "$duck"
fi

# Direct mode
ann_file="$arg1"
duck="${2:-25%}"
log "[play] PLAY direct duck=${duck} file=${ann_file}"
exec "$DUCKER" "$ann_file" "$duck"
