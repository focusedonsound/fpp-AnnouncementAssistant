#!/usr/bin/env bash
set -euo pipefail

CONFIG_FILE="/home/fpp/media/config/announcementassistant.json"
LOG_FILE="/home/fpp/media/logs/AnnouncementAssistant.log"

# Ensure common bins are available even when launched from PHP
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PULSE_SERVER="${PULSE_SERVER:-unix:/run/pulse/native}"

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DUCKER="${PLUGIN_DIR}/scripts/aa_duck_overlay_pulse.sh"

arg1="${1:-}"

log() {
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"
}

usage() {
  echo "Usage:" >&2
  echo "  $0 <slot 0-5>                # play configured slot" >&2
  echo "  $0 </path/to/audio> [duck%]  # direct play (testing)" >&2
  exit 2
}

[[ -n "$arg1" ]] || usage
[[ -x "$DUCKER" ]] || { echo "ERROR: Missing/invalid ducker script: $DUCKER" >&2; log "ERROR ducker missing: $DUCKER"; exit 3; }

# Read config values using PHP (avoids python dependency)
php_get() {
  local slot="$1"
  php -r '
    $cfgPath = getenv("CFG");
    $slot    = intval(getenv("SLOT"));
    $key     = getenv("KEY");

    if (!file_exists($cfgPath)) { exit(10); }
    $cfg = json_decode(file_get_contents($cfgPath), true);
    if (!is_array($cfg)) { exit(11); }

    if ($key === "duck") {
      $duck = $cfg["duck"] ?? "25%";
      echo $duck;
      exit(0);
    }

    if ($key === "file") {
      $buttons = $cfg["buttons"] ?? [];
      if (!is_array($buttons) || $slot < 0 || $slot >= count($buttons)) { exit(12); }
      $file = $buttons[$slot]["file"] ?? "";
      echo $file;
      exit(0);
    }

    exit(13);
  '
}

# Slot mode
if [[ "$arg1" =~ ^[0-9]+$ ]]; then
  slot="$arg1"
  [[ "$slot" -ge 0 && "$slot" -le 5 ]] || { echo "ERROR: slot must be 0-5" >&2; log "ERROR slot out of range: $slot"; exit 2; }

  if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "ERROR: Config not found: $CONFIG_FILE" >&2
    log "ERROR config not found: $CONFIG_FILE"
    exit 4
  fi

  # Pull duck + file from config
  CFG="$CONFIG_FILE" SLOT="$slot" KEY="duck" duck="$(CFG="$CONFIG_FILE" SLOT="$slot" KEY="duck" php_get "$slot" 2>/dev/null || true)"
  [[ -n "${duck:-}" ]] || duck="25%"

  CFG="$CONFIG_FILE" SLOT="$slot" KEY="file" ann_file="$(CFG="$CONFIG_FILE" SLOT="$slot" KEY="file" php_get "$slot" 2>/dev/null || true)"

  if [[ -z "${ann_file:-}" ]]; then
    echo "ERROR: No announcement file configured for slot $slot" >&2
    log "ERROR slot $slot no file configured"
    exit 5
  fi

  # Normalize common “music/…” paths into absolute
  if [[ "$ann_file" == music/* ]]; then
    ann_file="/home/fpp/media/${ann_file}"
  fi

  if [[ "$ann_file" != /home/fpp/media/* ]]; then
    # If someone saved a relative path, try to anchor it to /home/fpp/media/music
    if [[ "$ann_file" != /* ]]; then
      ann_file="/home/fpp/media/music/$ann_file"
    fi
  fi

  [[ -f "$ann_file" ]] || { echo "ERROR: File not found: $ann_file" >&2; log "ERROR slot $slot file missing: $ann_file"; exit 6; }

  log "PLAY slot=$slot duck=$duck file=$ann_file"
  exec "$DUCKER" "$ann_file" "$duck"
fi

# Direct file mode
ann_file="$arg1"
duck="${2:-25%}"
log "PLAY direct duck=$duck file=$ann_file"
exec "$DUCKER" "$ann_file" "$duck"
