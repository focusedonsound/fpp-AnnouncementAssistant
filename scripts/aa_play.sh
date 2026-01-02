#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUCKER="${PLUGIN_DIR}/scripts/aa_duck_overlay_pulse.sh"

arg1="${1:-}"

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>                # play configured slot" >&2
  echo "  $0 </path/to/audio> [duck%]  # direct play (testing)" >&2
  exit 2
}

log() {
  mkdir -p "$(dirname "$LOG_FILE")" >/dev/null 2>&1 || true
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] [play] $*" >> "$LOG_FILE"
}

need() { command -v "$1" >/dev/null 2>&1 || { echo "ERROR: '$1' not found" >&2; exit 3; }; }

[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { echo "ERROR: Missing/invalid ducker script: $DUCKER" >&2; exit 4; }

need python3

export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

# Slot mode
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "ERROR: slot must be 0-5" >&2; exit 2; }

  if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config not found: $CONFIG_FILE" >&2
    exit 5
  fi

  duck="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}","r"))
print(cfg.get("duck","25%"))
PY
)"

  ann_file="$(python3 - <<PY
import json
cfg=json.load(open("${CONFIG_FILE}","r"))
slot=int(${slot})
buttons=cfg.get("buttons",[])
f=""
if 0 <= slot < len(buttons):
    f=(buttons[slot] or {}).get("file","") or ""
print(f)
PY
)"

  if [[ -z "${ann_file:-}" ]]; then
    echo "ERROR: No announcement file configured for slot $slot" >&2
    exit 6
  fi

  # Normalize "music/..." into absolute path
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  [[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; exit 7; }

  log "PLAY slot=${slot} duck=${duck} file=${ann_file}"
  if ! "$DUCKER" "$ann_file" "$duck" >>"$LOG_FILE" 2>&1; then
    rc=$?
    log "ERROR: ducker failed rc=${rc}"
    exit "$rc"
  fi

  exit 0
fi

# Direct file mode
ann_file="$arg1"
duck="${2:-25%}"

[[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; exit 7; }

log "PLAY direct duck=${duck} file=${ann_file}"
if ! "$DUCKER" "$ann_file" "$duck" >>"$LOG_FILE" 2>&1; then
  rc=$?
  log "ERROR: ducker failed rc=${rc}"
  exit "$rc"
fi

exit 0
