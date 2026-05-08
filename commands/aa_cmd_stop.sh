#!/bin/bash
# FPP Command: Announcement Assistant - Stop
#
# Stops the currently playing announcement and restores show audio volume.
# Env:  MEDIADIR, FPPDIR, SCRIPTDIR set by FPP command runner

LOGFILE="${MEDIADIR:-/home/fpp/media}/logs/AnnouncementAssistant.log"
PLUGIN_DIR="$(dirname "$(dirname "$0")")"
PLAY_SCRIPT="${PLUGIN_DIR}/scripts/aa_play.sh"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [fpp-cmd] $*" >> "$LOGFILE"; }

if [[ ! -f "$PLAY_SCRIPT" ]]; then
    log "ERROR: play script not found: $PLAY_SCRIPT"
    exit 1
fi

log "STOP requested via FPP command"
exec bash "$PLAY_SCRIPT" --stop
