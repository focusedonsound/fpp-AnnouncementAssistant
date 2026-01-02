#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUCKER="${PLUGIN_DIR}/scripts/aa_duck_overlay_pulse.sh"

arg1="${1:-}"

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>                # play configured slot" >&2
  echo "  $0 </path/to/audio> [duck%]  # direct play (testing)" >&2
  exit 2
}

[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { echo "ERROR: Missing/invalid ducker script: $DUCKER" >&2; exit 3; }

# If first arg looks like a number, treat as slot.
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "ERROR: slot must be 0-5" >&2; exit 2; }

  if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config not found: $CONFIG_FILE" >&2
    exit 4
  fi

  # Extract duck + file for slot (config stores absolute paths from /home/fpp/media/music)
  duck="$(python3 - <<PY
import json
p="${CONFIG_FILE}"
cfg=json.load(open(p))
print(cfg.get("duck","25%"))
PY
)"

  ann_file="$(python3 - <<PY
import json
p="${CONFIG_FILE}"
slot=${slot}
cfg=json.load(open(p))
buttons=cfg.get("buttons",[])
if slot < 0 or slot >= len(buttons):
    print("")
else:
    print((buttons[slot] or {}).get("file","") or "")
PY
)"

  if [[ -z "$ann_file" ]]; then
    echo "ERROR: No announcement file configured for slot $slot" >&2
    exit 5
  fi

  # Normalize common “music/…” paths into absolute
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  [[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; exit 6; }

  exec "$DUCKER" "$ann_file" "$duck"
fi

# Otherwise treat as: direct file path
ann_file="$arg1"
duck="${2:-25%}"
exec "$DUCKER" "$ann_file" "$duck"
