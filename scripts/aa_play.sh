#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUCKER="${PLUGIN_DIR}/scripts/aa_duck_overlay_pulse.sh"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

log() {
  echo "[$(date '+%F %T')] [play] $*" >> "$LOG_FILE"
}

arg1="${1:-}"

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>                # play configured slot" >&2
  echo "  $0 </path/to/audio> [duck%]  # direct play (testing)" >&2
  exit 2
}

[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { log "ERROR: ducker missing: $DUCKER"; echo "Missing/invalid ducker script: $DUCKER" >&2; exit 3; }

# Slot mode
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "slot must be 0-5" >&2; exit 2; }

  [[ -f "$CONFIG_FILE" ]] || { log "ERROR: config missing: $CONFIG_FILE"; echo "Config not found" >&2; exit 4; }

  duck="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}"))
print(cfg.get("duck","25%"))
PY
)"

  ann_file="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}"))
slot=int("${slot}")
buttons=cfg.get("buttons",[])
print((buttons[slot] or {}).get("file","") if slot < len(buttons) else "")
PY
)"

  [[ -n "${ann_file:-}" ]] || { log "ERROR: slot=$slot no file configured"; echo "No file configured" >&2; exit 5; }

  # Normalize common “music/…” into absolute
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  [[ -f "$ann_file" ]] || { log "ERROR: slot=$slot file missing: $ann_file"; echo "File not found" >&2; exit 6; }

  log "PLAY slot=$slot duck=$duck file=$ann_file"
  "$DUCKER" "$ann_file" "$duck" >>"$LOG_FILE" 2>&1 || { log "ERROR: ducker failed rc=$?"; exit 7; }
  exit 0
fi

# Direct file mode
ann_file="$arg1"
duck="${2:-25%}"
log "PLAY direct duck=$duck file=$ann_file"
"$DUCKER" "$ann_file" "$duck" >>"$LOG_FILE" 2>&1 || { log "ERROR: ducker failed rc=$?"; exit 7; }
exit 0
