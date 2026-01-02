#!/bin/bash
set -euo pipefail

PLUGIN_NAME="Announcement Assistant (Audio Ducking)"
PLUGIN_ID="AnnouncementAssistant"

# Where FPP stores persistent config on real installs
CFG_DIR="/home/fpp/media/config"
CFG_FILE="${CFG_DIR}/announcementassistant.json"

log() { echo "[$PLUGIN_ID] $*"; }

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
  # On Debian, pulseaudio package usually creates user "pulse"
  if id -u pulse >/dev/null 2>&1; then
    usermod -aG audio pulse || true
  fi

  # FPP user on real installs
  if id -u fpp >/dev/null 2>&1; then
    usermod -aG audio fpp || true
  fi
}

install_pulse_system_pa() {
  # We run a system-wide Pulse server so FPP can always connect without a desktop session.
  # Socket is local-only and open (0666) since this is a show appliance.
  local pulse_dir="/etc/pulse"
  local system_pa="${pulse_dir}/system.pa"

  ensure_dir "$pulse_dir"

  # Backup existing once
  if [[ -f "$system_pa" && ! -f "${system_pa}.aa.bak" ]]; then
    cp -a "$system_pa" "${system_pa}.aa.bak"
    log "Backed up existing system.pa to system.pa.aa.bak"
  fi

  cat > "$system_pa" <<'EOF'
### Announcement Assistant system PulseAudio config
### Creates a local unix socket at /run/pulse/native for mixing/ducking use.

.nofail

# Local unix socket all local processes can connect to
load-module module-native-protocol-unix auth-anonymous=1 socket=/run/pulse/native socket_mode=0666

# Detect ALSA devices
load-module module-udev-detect

# Always have a sink (prevents “no sink” edge cases)
load-module module-always-sink

# Nice defaults (safe if missing)
load-module module-stream-restore
load-module module-device-restore
load-module module-default-device-restore

EOF

  chmod 644 "$system_pa"
  log "Installed /etc/pulse/system.pa"
}

install_systemd_service_if_available() {
  if ! command -v systemctl >/dev/null 2>&1; then
    log "systemctl not found; skipping systemd service install."
    log "You can still start PulseAudio manually if needed."
    return 0
  fi

  local svc="/etc/systemd/system/announcementassistant-pulse.service"

  cat > "$svc" <<'EOF'
[Unit]
Description=Announcement Assistant - PulseAudio (system) for audio mixing/ducking
After=sound.target

[Service]
Type=simple
ExecStartPre=/bin/mkdir -p /run/pulse
ExecStartPre=/bin/chmod 0777 /run/pulse
ExecStart=/usr/bin/pulseaudio --system -nF /etc/pulse/system.pa --disallow-exit --exit-idle-time=-1 --log-target=journal
Restart=on-failure
RestartSec=1

[Install]
WantedBy=multi-user.target
EOF

  chmod 644 "$svc"
  systemctl daemon-reload
  systemctl enable --now announcementassistant-pulse.service
  log "Enabled and started announcementassistant-pulse.service"
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
    chmod 664 "$CFG_FILE" || true
    log "Created default config: $CFG_FILE"
  else
    log "Config already exists: $CFG_FILE"
  fi
}

fix_plugin_script_perms() {
  # Make sure our scripts can execute
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
EOF
}

main() {
  need_root
  log "Installing ${PLUGIN_NAME}…"

  install_pkgs_if_missing
  ensure_users_in_audio_group
  install_pulse_system_pa
  install_systemd_service_if_available
  seed_default_config_if_missing
  fix_plugin_script_perms
  post_install_notes

  log "Done."
}

main "$@"
