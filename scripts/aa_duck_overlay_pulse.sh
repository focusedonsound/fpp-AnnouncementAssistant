#!/usr/bin/env bash
set -Eeuo pipefail

PLUGIN="AnnouncementAssistant"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

log() {
  local msg="$*"
  printf '[%(%F %T)T] %s\n' -1 "$msg" >> "$LOG_FILE"
}

err_trap() {
  local rc=$?
  log "[duck] ERROR rc=${rc} line=${LINENO} cmd=${BASH_COMMAND}"
  exit "$rc"
}
trap err_trap ERR

ANN_FILE="${1:-}"
DUCK_RAW="${2:-25%}"

if [[ -z "$ANN_FILE" ]]; then
  echo "Usage: $0 /path/to/audio [duck%]" >&2
  exit 2
fi
if [[ ! -f "$ANN_FILE" ]]; then
  log "[duck] ERROR: file not found: $ANN_FILE"
  exit 3
fi

DUCK="$DUCK_RAW"
if [[ "$DUCK" =~ ^[0-9]+$ ]]; then
  DUCK="${DUCK}%"
fi
if ! [[ "$DUCK" =~ ^[0-9]+%$ ]]; then
  log "[duck] ERROR: invalid duck value: $DUCK_RAW"
  exit 4
fi

DEFAULT_PULSE_SERVER="unix:/run/pulse/native"
if [[ -n "${AA_PULSE_SERVER:-}" ]]; then
  export PULSE_SERVER="$AA_PULSE_SERVER"
else
  export PULSE_SERVER="$DEFAULT_PULSE_SERVER"
fi
export PULSE_AUTOSPAWN=0

PCTL=(pactl --server "$PULSE_SERVER")
PPLAY=(paplay --server "$PULSE_SERVER" --client-name "AnnouncementAssistant")

LOCK_DIR="/home/fpp/media/tmp"
mkdir -p "$LOCK_DIR"
LOCK_FILE="$LOCK_DIR/aa_announcement.lock"
PID_FILE="$LOCK_DIR/aa_announcement.pid"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  log "[duck] BUSY: another announcement is running"
  exit 0
fi

# Record PID so UI can stop it
echo $$ > "$PID_FILE" 2>/dev/null || true

cleanup_pid() {
  rm -f "$PID_FILE" 2>/dev/null || true
}

# Ensure TERM/INT exits cleanly so EXIT trap runs and restores volumes
trap 'log "[duck] SIGNAL: TERM"; exit 143' TERM
trap 'log "[duck] SIGNAL: INT";  exit 130' INT

SINK="$("${PCTL[@]}" get-default-sink 2>/dev/null || true)"
if [[ -z "$SINK" ]]; then
  SINK="$("${PCTL[@]}" list short sinks 2>/dev/null | awk 'NR==1{print $2}' || true)"
fi

mapfile -t FPP_IDS < <(
  "${PCTL[@]}" list sink-inputs 2>/dev/null \
  | awk '
      /Sink Input #/ { id=$3 }
      /application.name = "fppd"/ { print id }
    '
) || true

declare -A ORIG_VOL=()

restore() {
  for id in "${!ORIG_VOL[@]}"; do
    "${PCTL[@]}" set-sink-input-volume "$id" "${ORIG_VOL[$id]}" >/dev/null 2>>"$LOG_FILE" || true
  done
  cleanup_pid
  log "[duck] RESTORE: volumes restored"
}
trap restore EXIT

if [[ "${#FPP_IDS[@]}" -eq 0 ]]; then
  log "[duck] START: duck=${DUCK} file=${ANN_FILE} sink=${SINK} fpp_ids=none"
  if [[ -n "$SINK" ]] && "${PPLAY[@]}" --device="$SINK" "$ANN_FILE" >>/dev/null 2>>"$LOG_FILE"; then
    :
  else
    "${PPLAY[@]}" "$ANN_FILE" >>/dev/null 2>>"$LOG_FILE"
  fi
  log "[duck] DONE: overlay played (no active fppd stream)"
  exit 0
fi

log "[duck] START: duck=${DUCK} file=${ANN_FILE} sink=${SINK} fpp_ids=${FPP_IDS[*]}"

for id in "${FPP_IDS[@]}"; do
  raw="$("${PCTL[@]}" get-sink-input-volume "$id" 2>/dev/null || true)"
  vol="$(awk 'match($0,/[0-9]+%/){print substr($0,RSTART,RLENGTH); exit}' <<<"$raw")"
  [[ -n "$vol" ]] || vol="100%"
  ORIG_VOL["$id"]="$vol"
done

for id in "${FPP_IDS[@]}"; do
  "${PCTL[@]}" set-sink-input-volume "$id" "$DUCK" >/dev/null 2>>"$LOG_FILE" || true
done

if [[ -n "$SINK" ]] && "${PPLAY[@]}" --device="$SINK" "$ANN_FILE" >>/dev/null 2>>"$LOG_FILE"; then
  :
else
  "${PPLAY[@]}" "$ANN_FILE" >>/dev/null 2>>"$LOG_FILE"
fi

log "[duck] DONE: overlay played"
exit 0
