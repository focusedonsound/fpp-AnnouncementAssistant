#!/bin/bash
set -euo pipefail

PLUGIN_NAME="AnnouncementAssistant"
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

CFG_DIR="/home/fpp/media/config"
CFG_FILE="${CFG_DIR}/announcementassistant.json"

PULSE_SYSTEM_PA="/etc/pulse/system.pa"
PULSE_CLIENT_CONF="/etc/pulse/client.conf"
PULSE_RUN_DIR="/run/pulse"
PULSE_SOCKET="${PULSE_RUN_DIR}/native"

SERVICE_NAME="aa-pulseaudio"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"

log() { echo "[$PLUGIN_NAME] $*"; }

SUDO=""
if [[ "${EUID}" -ne 0 ]]; then
  if command -v sudo >/dev/null 2>&1; then
    SUDO="sudo"
  else
    echo "[$PLUGIN_NAME] ERROR: Need root (or sudo) to install dependencies/services."
    exit 1
  fi
fi

have_cmd() { command -v "$1" >/dev/null 2>&1; }

backup_if_exists() {
  local f="$1"
  if [[ -f "$f" ]]; then
    local ts
    ts="$(date +%Y%m%d-%H%M%S)"
    $SUDO cp -a "$f" "${f}.aa.bak.${ts}"
    log "Backed up $f -> ${f}.aa.bak.${ts}"
  fi
}

install_deps() {
  if ! have_cmd apt-get; then
    log "ERROR: apt-get not found. Cannot install PulseAudio packages automatically."
    log "On FPP this should exist; if not, install pulseaudio/pulseaudio-utils manually."
    exit 1
  fi

  log "Installing dependencies (pulseaudio, pulseaudio-utils, libasound2-plugins)..."
  $SUDO apt-get update -y
  $SUDO apt-get install -y pulseaudio pulseaudio-utils libasound2-plugins
}

write_pulse_configs() {
  log "Configuring PulseAudio system instance..."

  # system.pa: minimal + predictable; no dbus modules; explicit native socket
  backup_if_exists "$PULSE_SYSTEM_PA"
  $SUDO mkdir -p /etc/pulse

  $SUDO tee "$PULSE_SYSTEM_PA" >/dev/null <<'EOF'
#!/usr/bin/pulseaudio -nF
# AA - Announcement Assistant (Audio Ducking)
# Minimal system-wide PulseAudio config for FPP mixing/ducking.
# Creates a native socket at /run/pulse/native and uses ALSA default device.

.nofail
.fail

# Native UNIX socket for local clients (FPP + plugin scripts)
load-module module-native-protocol-unix auth-anonymous=1 socket=/run/pulse/native

# Create an ALSA sink using the system default device
# Users who want to steer "default" can do so via ALSA (asound.conf) / FPP audio settings.
load-module module-alsa-sink device=default tsched=0

# Ensure there's always a sink
load-module module-always-sink

# Nice, predictable defaults
set-default-sink alsa_output
EOF

  # client.conf: force clients (fppd/paplay/pactl) to use the system socket
  backup_if_exists "$PULSE_CLIENT_CONF"
  $SUDO tee "$PULSE_CLIENT_CONF" >/dev/null <<EOF
# AA - Announcement Assistant (Audio Ducking)
default-server = unix:${PULSE_SOCKET}
autospawn = no
EOF
}

install_service() {
  if ! have_cmd systemctl; then
    log "WARNING: systemctl not found. Skipping service install."
    log "You will need to start pulseaudio manually on boot."
    return 0
  fi

  log "Installing systemd service: ${SERVICE_NAME}.service"
  $SUDO tee "$SERVICE_FILE" >/dev/null <<EOF
[Unit]
Description=AA - PulseAudio system server for FPP (Announcement Assistant)
After=sound.target
Wants=sound.target

[Service]
Type=forking
# Make sure runtime dir exists + is permissive for local clients
ExecStartPre=/bin/mkdir -p ${PULSE_RUN_DIR}
ExecStartPre=/bin/chmod 0777 ${PULSE_RUN_DIR}
# Start PulseAudio in system mode with our config and pid file
ExecStart=/usr/bin/pulseaudio --system -nF ${PULSE_SYSTEM_PA} --disallow-exit --exit-idle-time=-1 --daemonize=yes --log-target=journal --pid-file=${PULSE_RUN_DIR}/pid
# Wait briefly for socket, then relax perms so fpp/scripts can connect
ExecStartPost=/bin/sh -c 'for i in \$(seq 1 100); do [ -S "${PULSE_SOCKET}" ] && chmod 0666 "${PULSE_SOCKET}" && exit 0; sleep 0.1; done; exit 0'
ExecStop=/usr/bin/pulseaudio --system --kill
Restart=on-failure
RestartSec=1

[Install]
WantedBy=multi-user.target
EOF

  $SUDO systemctl daemon-reload
  $SUDO systemctl enable --now "$SERVICE_NAME" || true
}

ensure_plugin_perms() {
  # Make scripts executable
  if [[ -d "${PLUGIN_DIR}/scripts" ]]; then
    $SUDO chmod +x "${PLUGIN_DIR}/scripts/"*.sh 2>/dev/null || true
  fi

  # Prefer chown by name; fall back to UID/GID 500 if needed
  if id -u fpp >/dev/null 2>&1; then
    $SUDO chown -R fpp:fpp "$PLUGIN_DIR" || true
  else
    $SUDO chown -R 500:500 "$PLUGIN_DIR" || true
  fi
}

ensure_default_config() {
  log "Ensuring default config exists..."
  $SUDO mkdir -p "$CFG_DIR"

  if [[ ! -f "$CFG_FILE" ]]; then
    $SUDO tee "$CFG_FILE" >/dev/null <<'EOF'
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
    if id -u fpp >/dev/null 2>&1; then
      $SUDO chown fpp:fpp "$CFG_FILE" || true
    else
      $SUDO chown 500:500 "$CFG_FILE" || true
    fi
    $SUDO chmod 664 "$CFG_FILE" || true
  fi
}

post_install_notes() {
  log "Install complete."
  log "Next steps in FPP UI:"
  log "  1) Settings -> Audio/Video -> Audio Output Device: set to 'pulse'"
  log "  2) Restart fppd"
  log "Quick validation:"
  log "  pactl info"
  log "  pactl list short sinks"
  log "  systemctl status ${SERVICE_NAME} --no-pager"
}

install_deps
write_pulse_configs
install_service
ensure_default_config
ensure_plugin_perms
post_install_notes

exit 0
