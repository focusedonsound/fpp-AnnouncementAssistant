#!/usr/bin/env bash
# /home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
LOCK_FILE="/tmp/announcementassistant.lock"
PULSE_SOCKET="/run/pulse/native"
DUCK_SCRIPT="/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_duck_overlay_pulse.sh"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(ts)] [aa_play] $*" >> "$LOG_FILE"; }

usage() {
  cat <<'EOF'
Usage:
  aa_play.sh <audio_file> <duck_percent>
  aa_play.sh --stop

Examples:
  aa_play.sh /home/fpp/media/music/Opening.mp3 25%
  aa_play.sh --stop
EOF
}

normalize_duck() {
  local d="${1:-}"
  d="$(echo -n "$d" | tr -d '[:space:]')"
  if [[ -z "$d" ]]; then echo "25%"; return 0; fi

  # allow "25" or "25%"
  if [[ "$d" =~ ^[0-9]+$ ]]; then
    d="${d}%"
  fi

  if [[ ! "$d" =~ ^[0-9]+%$ ]]; then
    echo ""
    return 0
  fi

  local n="${d%\%}"
  # clamp 0..100
  if (( n < 0 )); then n=0; fi
  if (( n > 100 )); then n=100; fi
  echo "${n}%"
}

pulse_env() {
  # Force scripts to hit the system daemon socket
  export PULSE_SERVER="unix:${PULSE_SOCKET}"
}

dump_pulse_diag() {
  # Keep this lightweight but useful
  pulse_env
  log "Pulse diag: socket_exists=$( [[ -S "$PULSE_SOCKET" ]] && echo yes || echo no ) PULSE_SERVER=$PULSE_SERVER"
  pactl info 2>&1 | sed 's/^/[pactl info] /' | while read -r line; do log "$line"; done || true
  pactl list short sinks 2>&1 | sed 's/^/[pactl sinks] /' | while read -r line; do log "$line"; done || true
  pactl list short sink-inputs 2>&1 | sed 's/^/[pactl sink-inputs] /' | while read -r line; do log "$line"; done || true
}

stop_announcements() {
  pulse_env

  if [[ ! -S "$PULSE_SOCKET" ]]; then
    log "STOP: Pulse socket missing at $PULSE_SOCKET (cannot stop via pactl)."
    return 1
  fi

  # Find sink-input IDs for our client-name
  # When using: paplay --client-name "AnnouncementAssistant"
  # the sink-input will include: application.name = "AnnouncementAssistant"
  local ids
  ids="$(pactl list sink-inputs 2>/dev/null | awk '
    /Sink Input #/ { id=$3 }
    /application.name = "AnnouncementAssistant"/ { print id }
  ' | xargs echo -n || true)"

  if [[ -z "$ids" ]]; then
    log "STOP: No active AnnouncementAssistant sink-inputs found."
    return 0
  fi

  log "STOP: Killing sink-input(s): $ids"
  local id rc=0
  for id in $ids; do
    if pactl kill-sink-input "$id" >>"$LOG_FILE" 2>&1; then
      log "STOP: Killed sink-input $id"
    else
      log "STOP: FAILED to kill sink-input $id"
      rc=1
    fi
  done
  return "$rc"
}

main() {
  log "----"
  log "START args: $* user=$(id -un) uid=$(id -u) gid=$(id -g)"

  if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
  fi

  if [[ "${1:-}" == "--stop" ]]; then
    stop_announcements || true
    dump_pulse_diag
    log "DONE --stop"
    exit 0
  fi

  local ann_file="${1:-}"
  local duck_raw="${2:-}"

  if [[ -z "$ann_file" || -z "$duck_raw" ]]; then
    log "ERROR: Missing args. ann_file='$ann_file' duck='$duck_raw'"
    usage >>"$LOG_FILE" 2>&1 || true
    exit 2
  fi

  local duck
  duck="$(normalize_duck "$duck_raw")"
  if [[ -z "$duck" ]]; then
    log "ERROR: Invalid duck percent: '$duck_raw' (expected like 25% or 25)"
    exit 2
  fi

  if [[ ! -f "$ann_file" ]]; then
    log "ERROR: File not found: $ann_file"
    exit 2
  fi

  # MVP ignore-if-busy lock
  if [[ -e "$LOCK_FILE" ]]; then
    log "BUSY: lock exists ($LOCK_FILE). Ignoring new request for: $ann_file"
    exit 0
  fi
  echo "$$" >"$LOCK_FILE"
  trap 'rm -f "$LOCK_FILE" || true' EXIT

  # Check Pulse socket
  if [[ ! -S "$PULSE_SOCKET" ]]; then
    log "ERROR: Pulse socket missing at $PULSE_SOCKET (announcements will not play)."
    dump_pulse_diag
    exit 1
  fi

  if [[ ! -x "$DUCK_SCRIPT" ]]; then
    log "ERROR: Duck script not executable: $DUCK_SCRIPT"
    ls -l "$DUCK_SCRIPT" >>"$LOG_FILE" 2>&1 || true
    exit 1
  fi

  # Diagnostics snapshot before play
  dump_pulse_diag

  log "PLAY: file=$ann_file duck=$duck"
  if "$DUCK_SCRIPT" "$ann_file" "$duck" >>"$LOG_FILE" 2>&1; then
    log "PLAY: completed OK"
  else
    local rc=$?
    log "PLAY: FAILED rc=$rc"
    dump_pulse_diag
    exit "$rc"
  fi

  # Diagnostics snapshot after play
  dump_pulse_diag

  log "DONE"
}

main "$@"
