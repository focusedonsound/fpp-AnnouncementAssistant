#!/bin/bash
# FPP Command: Announcement Assistant - Play
#
# Args:  $1 = slot index (0-5, passed from descriptions.json contentListUrl keys)
# Env:   MEDIADIR, FPPDIR, SCRIPTDIR set by FPP command runner

SLOT="${1}"
LOGFILE="${MEDIADIR:-/home/fpp/media}/logs/AnnouncementAssistant.log"
CONFIG="${MEDIADIR:-/home/fpp/media}/config/announcementassistant.json"
PLUGIN_DIR="$(dirname "$(dirname "$0")")"
PLAY_SCRIPT="${PLUGIN_DIR}/scripts/aa_play.sh"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOGFILE"; }

if [[ -z "$SLOT" ]]; then
    log "ERROR: no slot argument provided"
    exit 1
fi

if [[ ! -f "$CONFIG" ]]; then
    log "ERROR: config not found: $CONFIG"
    exit 1
fi

if [[ ! -f "$PLAY_SCRIPT" ]]; then
    log "ERROR: play script not found: $PLAY_SCRIPT"
    exit 1
fi

# Extract file and duck for the given slot index using python3
read -r FILE DUCK < <(python3 - <<PYEOF
import json, sys

try:
    cfg  = json.load(open("$CONFIG"))
    idx  = int("$SLOT")
    btns = cfg.get("buttons", [])
    btn  = btns[idx] if idx < len(btns) else {}
    f    = btn.get("file", "").strip()
    d    = btn.get("duck", cfg.get("duck", "25%"))
    d    = str(d).rstrip("%") + "%"
    print(f, d)
except Exception as e:
    print("", "25%")
PYEOF
)

if [[ -z "$FILE" ]]; then
    log "ERROR: no audio file configured for slot $SLOT"
    exit 1
fi

log "PLAY slot=$SLOT file=$FILE duck=$DUCK"
exec bash "$PLAY_SCRIPT" "$FILE" "$DUCK"
