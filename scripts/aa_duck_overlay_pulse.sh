#!/usr/bin/env bash
# /home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_duck_overlay_pulse.sh
set -Eeuo pipefail

LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"
PULSE_SOCKET="/run/pulse/native"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log(){ echo "[$(ts)] [duck] $*" >> "$LOG_FILE"; }

# Normalize "25" or "25%" -> "25%"
normalize_duck() {
  local d="${1:-}"
  d="$(echo -n "$d" | tr -d '[:space:]')"
  [[ -z "$d" ]] && { echo "25%"; return 0; }
  [[ "$d" =~ ^[0-9]+$ ]] && d="${d}%"
  [[ "$d" =~ ^[0-9]+%$ ]] || { echo ""; return 0; }
  local n="${d%\%}"
  (( n < 0 )) && n=0
  (( n > 100 )) && n=100
  echo "${n}%"
}

pulse_env() {
  export PULSE_SERVER="unix:${PULSE_SOCKET}"
}

get_default_sink() {
  pactl info 2>/dev/null | awk -F': ' '/^Default Sink:/{print $2; exit}'
}

# Extract sink-input IDs for FPP audio and strip leading '#'
# This is intentionally broad, because different setups label FPP differently.
get_fpp_sink_inputs() {
  pactl list sink-inputs 2>/dev/null | awk '
    /Sink Input #/ { id=$3; gsub(/^#/, "", id) }
    /application.process.binary = "fppd"/ { print id; next }
    /application.name = "FPP"/ { print id; next }
    /application.name = "FPPD"/ { print id; next }
    /media.name = "FPP"/ { print id; next }
  ' | sort -u | xargs echo -n
}

# Fallback: if we can’t match labels, duck *all* sink-inputs except the announcement client.
get_non_announcement_sink_inputs() {
  pactl list sink-inputs 2>/dev/null | awk '
    /Sink Input #/ { id=$3; gsub(/^#/, "", id) }
    /application.name = "AnnouncementAssistant"/ { skip=1 }
    /Sink Input #/ { if (NR>1 && skip!=1 && prev!="") print prev; skip=0; prev=id; next }
    { }
    END { if (skip!=1 && prev!="") print prev }
  ' | sort -u | xargs echo -n
}

get_sink_input_volume_pct() {
  local id="$1"
  # Grab first percentage from pactl output
  pactl get-sink-input-volume "$id" 2>/dev/null | sed -n 's/.*\/ \([0-9]\+%\) .*/\1/p' | head -n 1
}

set_sink_input_volume() {
  local id="$1" vol="$2"
  pactl set-sink-input-volume "$id" "$vol" >> "$LOG_FILE" 2>&1
}

play_to_sink() {
  local file="$1" sink="$2"

  # Prefer ffmpeg pipeline for anything not guaranteed WAV
  if command -v ffmpeg >/dev/null 2>&1; then
    ffmpeg -nostdin -v error -i "$file" -f wav -acodec pcm_s16le -ac 2 -ar 48000 - \
      | paplay --client-name="AnnouncementAssistant" --device="$sink" >>"$LOG_FILE" 2>&1
  else
    # fallback (may fail for mp3 if paplay can’t decode it)
    paplay --client-name="AnnouncementAssistant" --device="$sink" "$file" >>"$LOG_FILE" 2>&1
  fi
}

main() {
  local file="${1:-}"
  local duck_raw="${2:-}"

  if [[ -z "$file" || -z "$duck_raw" ]]; then
    log "ERROR: usage: aa_duck_overlay_pulse.sh <file> <duck%>"
    exit 2
  fi

  local duck
  duck="$(normalize_duck "$duck_raw")"
  if [[ -z "$duck" ]]; then
    log "ERROR: invalid duck value '$duck_raw'"
    exit 2
  fi

  pulse_env

  if [[ ! -S "$PULSE_SOCKET" ]]; then
    log "ERROR: Pulse socket missing at $PULSE_SOCKET"
    exit 1
  fi

  local sink
  sink="$(get_default_sink)"
  if [[ -z "$sink" ]]; then
    log "ERROR: Could not determine Default Sink"
    exit 1
  fi

  # Try to duck only FPP’s sink-input(s). If none found, duck everything except announcements.
  local fpp_ids
  fpp_ids="$(get_fpp_sink_inputs)"
  if [[ -z "$fpp_ids" ]]; then
    fpp_ids="$(get_non_announcement_sink_inputs)"
    log "WARN: Could not confidently identify FPP sink-inputs; ducking non-announcement inputs instead: $fpp_ids"
  fi

  log "START: duck=$duck file=$file sink=$sink fpp_ids=${fpp_ids:-<none>}"

  # Save original volumes
  declare -A orig_vol
  for id in $fpp_ids; do
    # sanity: ensure numeric
    if [[ ! "$id" =~ ^[0-9]+$ ]]; then
      log "WARN: Skipping non-numeric sink-input id='$id'"
      continue
    fi
    orig_vol["$id"]="$(get_sink_input_volume_pct "$id")"
    log "Captured volume: id=$id vol=${orig_vol[$id]:-unknown}"
  done

  # Apply duck
  for id in "${!orig_vol[@]}"; do
    log "Ducking sink-input id=$id -> $duck"
    set_sink_input_volume "$id" "$duck" || { log "ERROR: Failed to duck id=$id"; }
  done

  # Always restore volumes even if playback fails
  set +e
  play_to_sink "$file" "$sink"
  local play_rc=$?
  set -e

  # Restore volumes
  for id in "${!orig_vol[@]}"; do
    local v="${orig_vol[$id]}"
    if [[ -n "$v" ]]; then
      log "Restoring sink-input id=$id -> $v"
      set_sink_input_volume "$id" "$v" || { log "ERROR: Failed to restore id=$id"; }
    else
      log "Restoring sink-input id=$id -> 100% (fallback)"
      set_sink_input_volume "$id" "100%" || true
    fi
  done

  if [[ $play_rc -ne 0 ]]; then
    log "PLAY FAILED rc=$play_rc"
    exit $play_rc
  fi

  log "DONE"
}

main "$@"
