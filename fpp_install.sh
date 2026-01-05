#!/bin/bash
set -euo pipefail

PLUGIN_NAME="Announcement Assistant (Audio Ducking)"
PLUGIN_ID="AnnouncementAssistant"

# FPP Plugin Manager may pass these as args like: FPPDIR=/opt/fpp SRCDIR=... PLUGINDIR=...
FPPDIR="${FPPDIR:-}"
SRCDIR="${SRCDIR:-}"
PLUGINDIR="${PLUGINDIR:-}"

# Where FPP stores persistent config on real installs
CFG_DIR="/home/fpp/media/config"
CFG_FILE="${CFG_DIR}/announcementassistant.json"

# Defaults
APPLY_48K=1          # optional tweak; default ON
PIN_FPP_PULSE=1      # recommended; default ON

log() { echo "[$PLUGIN_ID] $*"; }

usage() {
  cat <<EOF
Usage: sudo ./fpp_install.sh [options] [FPPDIR=/opt/fpp SRCDIR=... PLUGINDIR=...]

Options:
  --no-48k        Do NOT modify /etc/pulse/daemon.conf sample rate (default is to set 48k)
  --force-48k     Force set /etc/pulse/daemon.conf sample rate to 48k (same as default)
  --no-pin-fpp    Do NOT create /home/fpp/.config/pulse/client.conf (recommended to keep ON)
  -h, --help      Show this help

Notes:
  - This installer runs a system-wide PulseAudio with socket: /run/pulse/native
  - It validates the socket exists after restart (otherwise announcements will fail silently)
  - FPP Plugin Manager may pass FPPDIR=/opt/fpp style arguments; these are accepted.
EOF
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --no-48k) APPLY_48K=0; shift ;;
      --force-48k) APPLY_48K=1; shift ;;
      --no-pin-fpp) PIN_FPP_PULSE=0; shift ;;
      -h|--help) usage; exit 0 ;;
      *=*)
        # Accept KEY=VALUE args (FPP Plugin Manager uses these)
        # Only export valid shell variable names to be safe.
        key="${1%%=*}"
        val="${1#*=}"
        if [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
          export "$key=$val"
          # Capture common FPP vars (optional; helpful for debugging)
          [[ "$key" == "FPPDIR" ]] && FPPDIR="$val"
          [[ "$key" == "SRCDIR" ]] && SRCDIR="$val"
          [[ "$key" == "PLUGINDIR" ]] && PLUGINDIR="$val"
        fi
        shift
        ;;
      *)
        log "ERROR: Unknown option: $1"
        usage
        exit 1
        ;;
    esac
  done
}

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    log "ERROR: fpp_install.sh must be run as root."
    log "Tip: sudo ./fpp_install.sh"
    exit 1
  fi
}

ensure_dir() {
  local d="$1"
  [[ -d "$d" ]] || mkdir -p "$d"
}

install_pkgs_if_missing() {
  local missing=0
  local pkgs=(
    pulseaudio
    pulseaudio-utils
    libasound2-plugins
    alsa-utils
  )

  for p in "${pkgs[@]}"; do
    if ! dpkg -s "$p" >/dev/null 2>&1; then
      missing=1
      break
    fi
  done

  if [[ "$missing" -eq 1 ]]; then
    log "Installing required packages (PulseAudio + ALSA pulse plugin)…"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y --no-install-recommends "${pkgs[@]}"
  else
    log "Required packages already installed."
  fi
}

ensure_users_in_audio_group() {
  if id -u pulse >/dev/null 2>&1; then
    usermod -aG audio pulse || true
  fi

  if id -u fpp >/dev/null 2>&1; then
    usermod -aG audio fpp || true
  fi
}

install_pulse_system_pa() {
  local pulse_dir="/etc/pulse"
  local system_pa="${pulse_dir}/system.pa"

  ensure_dir "$pulse_dir"

  if [[ -f "$system_pa" && ! -f "${system_pa}.aa.bak" ]]; then
    cp -a "$system_pa" "${system_pa}.aa.bak"
    log "Backed up existing system.pa to system.pa.aa.bak"
  fi

  cat > "$system_pa" <<'EOF'
### Announcement Assistant system PulseAudio config
### Creates a local unix socket at /run/pulse/native for mixing/ducking use.

.nofail

# Local unix socket all local processes can connect to
# NOTE: Keep arguments minimal for compatibility across PulseAudio builds.
load-module module-native-protocol-unix auth-anonymous=1 socket=/run/pulse/native

# Detect ALSA devices
load-module module-udev-detect

# Always have a sink (prevents “no sink” edge cases)
load-module module-always-sink

# Nice defaults (safe if missing)
load-module module-stream-restore
load-module module-device-restore
load-module module-default-device-restore
EOF

  # Upgrade-proofing: strip socket_mode if an older install left it behind.
  sed -i -E 's/[[:space:]]+socket_mode=[0-9]+//g' "$system_pa"

  chmod 644 "$system_pa"
  log "Installed /etc/pulse/system.pa"
}

ensure_pulse_48k_daemon_conf() {
  local pulse_dir="/etc/pulse"
  local daemon_conf="${pulse_dir}/daemon.conf"

  ensure_dir "$pulse_dir"

  if [[ -f "$daemon_conf" && ! -f "${daemon_conf}.aa.bak" ]]; then
    cp -a "$daemon_conf" "${daemon_conf}.aa.bak"
    log "Backed up existing daemon.conf to daemon.conf.aa.bak"
  fi

  [[ -f "$daemon_conf" ]] || : > "$daemon_conf"

  if grep -qE '^[[:space:]]*default-sample-rate[[:space:]]*=' "$daemon_conf"; then
    sed -i -E 's|^[[:space:]]*default-sample-rate[[:space:]]*=.*|default-sample-rate = 48000|' "$daemon_conf"
  else
    echo 'default-sample-rate = 48000' >> "$daemon_conf"
  fi

  if grep -qE '^[[:space:]]*alternate-sample-rate[[:space:]]*=' "$daemon_conf"; then
    sed -i -E 's|^[[:space:]]*alternate-sample-rate[[:space:]]*=.*|alternate-sample-rate = 48000|' "$daemon_conf"
  else
    echo 'alternate-sample-rate = 48000' >> "$daemon_conf"
  fi

  chmod 644 "$daemon_conf" || true
  log "Ensured /etc/pulse/daemon.conf sample rate is 48000 Hz"
}

install_systemd_service_if_available() {
  if ! command -v systemctl >/dev/null 2>&1; then
    log "systemctl not found; skipping systemd service install."
    return 0
  fi

  local svc="/etc/systemd/system/announcementassistant-pulse.service"

  cat > "$svc" <<'EOF'
[Unit]
Description=Announcement Assistant - PulseAudio (system) for audio mixing/ducking
After=sound.target

[Service]
Type=simple

# /run is tmpfs; ensure pulse runtime dirs exist each boot with correct ownership
ExecStartPre=/usr/bin/install -d -o pulse -g pulse -m 0755 /run/pulse
ExecStartPre=/usr/bin/install -d -o pulse -g pulse -m 0700 /run/pulse/.config
ExecStartPre=/usr/bin/install -d -o pulse -g pulse -m 0700 /run/pulse/.config/pulse

ExecStart=/usr/bin/pulseaudio --system -nF /etc/pulse/system.pa --disallow-exit --exit-idle-time=-1 --log-target=journal

# Ensure local clients (fppd + plugin scripts) can connect to the socket
ExecStartPost=/bin/sh -c 'chmod 0666 /run/pulse/native || true'

Restart=on-failure
RestartSec=1

[Install]
WantedBy=multi-user.target
EOF

  chmod 644 "$svc"
  systemctl daemon-reload
  systemctl enable announcementassistant-pulse.service

  # Force a clean restart so updated system.pa takes effect and /run/pulse/native is created.
  systemctl stop announcementassistant-pulse.service 2>/dev/null || true
  pkill -u pulse pulseaudio 2>/dev/null || true
  rm -rf /run/pulse
  systemctl start announcementassistant-pulse.service
  sleep 1

  if [[ ! -S /run/pulse/native ]]; then
    log "ERROR: Pulse socket /run/pulse/native was not created. Announcements will not work."
    log "Last journal lines:"
    journalctl -u announcementassistant-pulse.service -b --no-pager | tail -n 60 || true
    exit 1
  fi

  log "Enabled and started announcementassistant-pulse.service"
}

pin_fpp_user_to_system_pulse() {
  if [[ "$PIN_FPP_PULSE" -ne 1 ]]; then
    log "Skipping fpp Pulse client pin (per --no-pin-fpp)"
    return 0
  fi

  if ! id -u fpp >/dev/null 2>&1; then
    return 0
  fi

  local d="/home/fpp/.config/pulse"
  ensure_dir "$d"

  cat > "${d}/client.conf" <<'EOF'
autospawn = no
default-server = unix:/run/pulse/native
EOF

  chown -R fpp:fpp "/home/fpp/.config" 2>/dev/null || true
  chmod 644 "${d}/client.conf" 2>/dev/null || true

  pkill -u fpp pulseaudio 2>/dev/null || true

  log "Pinned user 'fpp' Pulse client to system socket and disabled autospawn"
}

seed_default_config_if_missing() {
  ensure_dir "$CFG_DIR"

  if [[ ! -f "$CFG_FILE" ]]; then
    cat > "$CFG_FILE" <<'EOF'
{
  "duck": "25%",
  "buttons": [
    { "label": "Announcement 1", "file": "" },
    { "label": "Announcement 2", "file": "" },
    { "label": "Announcement 3", "file": "" },
    { "label": "Announcement 4", "file": "" },
    { "label": "Announcement 5", "file": "" },
    { "label": "Announcement 6", "file": "" }
  ]
}
EOF
    chown fpp:fpp "$CFG_FILE" 2>/dev/null || true
    chmod 664 "$CFG_FILE" || true
    log "Created default config: $CFG_FILE"
  else
    log "Config already exists: $CFG_FILE"
  fi

  chown fpp:fpp "$CFG_FILE" 2>/dev/null || true
  chmod 664 "$CFG_FILE" 2>/dev/null || true
}

fix_plugin_script_perms() {
  local here
  here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

  if [[ -d "${here}/scripts" ]]; then
    chmod 775 "${here}/scripts"/*.sh 2>/dev/null || true
  fi

  log "Ensured plugin script permissions."
}

post_install_notes() {
  cat <<EOF

[$PLUGIN_ID] Install complete.

Next steps in FPP UI:
  1) Set Audio Output Device to: pulse
  2) Restart fppd

Notes:
  - PulseAudio system socket: /run/pulse/native
  - Announcement audio files should be placed in: /home/fpp/media/music
  - 48kHz tweak: $([[ "$APPLY_48K" -eq 1 ]] && echo "ENABLED" || echo "DISABLED")

EOF
}

main() {
  parse_args "$@"
  need_root
  log "Installing ${PLUGIN_NAME}…"

  if [[ -n "${FPPDIR}" || -n "${SRCDIR}" || -n "${PLUGINDIR}" ]]; then
    log "FPP installer context: FPPDIR=${FPPDIR:-<unset>} SRCDIR=${SRCDIR:-<unset>} PLUGINDIR=${PLUGINDIR:-<unset>}"
  fi

  install_pkgs_if_missing
  ensure_users_in_audio_group
  install_pulse_system_pa

  if [[ "$APPLY_48K" -eq 1 ]]; then
    ensure_pulse_48k_daemon_conf
  else
    log "Skipping 48kHz daemon.conf tweak (per --no-48k)"
  fi

  install_systemd_service_if_available
  pin_fpp_user_to_system_pulse
  seed_default_config_if_missing
  fix_plugin_script_perms
  post_install_notes

  log "Done."
}

main "$@"
