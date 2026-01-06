#!/bin/bash
set -euo pipefail

PLUGIN_DIR="/home/fpp/media/plugins/fpp-AnnouncementAssistant"
CFG="/home/fpp/media/config/announcementassistant.json"
DUCKER="${PLUGIN_DIR}/scripts/aa_duck_overlay_pulse.sh"

SLOT="${1:-}"
if [[ -z "$SLOT" || ! "$SLOT" =~ ^[0-5]$ ]]; then
  echo "Usage: $0 <slot 0-5>" >&2
  exit 1
fi

mapfile -t vals < <(python3 - "$SLOT" <<'PY'
import json,sys
cfg_path="/home/fpp/media/config/announcementassistant.json"
slot=int(sys.argv[1])
cfg={}
try:
  with open(cfg_path,"r") as f:
    cfg=json.load(f)
except Exception:
  cfg={}
duck_default=cfg.get("duck","25%")
buttons=cfg.get("buttons",[])
btn=buttons[slot] if slot < len(buttons) else {}
file=str(btn.get("file","") or "")
duck=str(btn.get("duck","") or duck_default or "25%")
print(file)
print(duck)
PY
)

FILE="${vals[0]:-}"
DUCK="${vals[1]:-25%}"

if [[ -z "$FILE" ]]; then
  echo "No file configured for slot $SLOT" >&2
  exit 1
fi

# Allow relative selection (from /home/fpp/media/music) if someone stored that way
if [[ "$FILE" != /* ]]; then
  FILE="/home/fpp/media/music/$FILE"
fi

if [[ ! -f "$FILE" ]]; then
  echo "File not found: $FILE" >&2
  exit 1
fi

exec "$DUCKER" "$DUCK" "$FILE"
