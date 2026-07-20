#!/bin/bash
set -euo pipefail

PLUGIN_ID="AnnouncementAssistant"

log() { echo "[$PLUGIN_ID] $*"; }

need_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    log "ERROR: fpp_uninstall.sh must be run as root."
    exit 1
  fi
}

remove_systemd_service() {
  if ! command -v systemctl >/dev/null 2>&1; then
    return 0
  fi

  local svc="/etc/systemd/system/announcementassistant-pulse.service"

  if systemctl is-active --quiet announcementassistant-pulse.service 2>/dev/null; then
    systemctl stop announcementassistant-pulse.service || true
  fi
  systemctl disable announcementassistant-pulse.service 2>/dev/null || true

  if [[ -f "$svc" ]]; then
    rm -f "$svc"
    systemctl daemon-reload
    log "Removed announcementassistant-pulse.service"
  fi
}

restore_pulse_system_pa() {
  local system_pa="/etc/pulse/system.pa"
  local backup="${system_pa}.aa.bak"

  if [[ -f "$backup" ]]; then
    mv -f "$backup" "$system_pa"
    log "Restored original /etc/pulse/system.pa from backup"
  elif [[ -f "$system_pa" ]]; then
    log "No pre-install backup of system.pa found; leaving it in place"
  fi
}

restore_pulse_daemon_conf() {
  local daemon_conf="/etc/pulse/daemon.conf"
  local backup="${daemon_conf}.aa.bak"

  if [[ -f "$backup" ]]; then
    mv -f "$backup" "$daemon_conf"
    log "Restored original /etc/pulse/daemon.conf from backup"
  elif [[ -f "$daemon_conf" ]]; then
    log "No pre-install backup of daemon.conf found; leaving it in place"
  fi
}

remove_fpp_pulse_pin() {
  local client_conf="/home/fpp/.config/pulse/client.conf"

  if [[ -f "$client_conf" ]]; then
    rm -f "$client_conf"
    log "Removed fpp user's Pulse client pin"
  fi

  if id -u fpp >/dev/null 2>&1; then
    pkill -u fpp pulseaudio 2>/dev/null || true
  fi
}

main() {
  need_root
  log "Uninstalling Announcement Assistant (Audio Ducking)…"

  remove_systemd_service
  restore_pulse_system_pa
  restore_pulse_daemon_conf
  remove_fpp_pulse_pin

  log "Done. Announcement config and audio files under /home/fpp/media were left in place."
}

main "$@"
