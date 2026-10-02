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
    pkill -u fpp pipewire-pulse 2>/dev/null || true
  fi
}

main() {
  need_root
  log "Uninstalling Announcement Assistant (Audio Ducking)…"

  # The announcementassistant-pulse.service unit file only ever exists
  # when THIS plugin was the one that set up the shared PulseAudio/
  # PipeWire-pulse bridge at /run/pulse/native - if Encore Radio (or a
  # previous AA install) got there first, this plugin's own install
  # detects the existing socket and never creates this unit at all.
  #
  # But "does my own unit file exist" only ever answered "did I create
  # the bridge", never "is anyone else still depending on it" - a real
  # gap, since whichever plugin installs first becomes the sole owner
  # with zero durable record that the other plugin is also relying on
  # that same socket. Reverting on that check alone silences the OTHER
  # plugin's audio the moment the owner uninstalls, with no self-healing
  # short of that plugin's own next reinstall. The shared marker directory
  # below (written by both plugins' fpp_install.sh) is the actual
  # reference count: remove our own marker, then check whether anyone
  # else's is still there before touching anything.
  BRIDGE_OWNERS_DIR="/etc/fpp-plugins/pulse-bridge-owners"
  rm -f "${BRIDGE_OWNERS_DIR}/fpp-AnnouncementAssistant" 2>/dev/null || true
  OTHER_BRIDGE_OWNERS=0
  if [[ -d "$BRIDGE_OWNERS_DIR" ]] && [[ -n "$(ls -A "$BRIDGE_OWNERS_DIR" 2>/dev/null)" ]]; then
    OTHER_BRIDGE_OWNERS=1
  else
    # Nobody left depending on the bridge - the directory itself is also
    # this mechanism's own footprint and shouldn't outlive every plugin
    # that used it. rmdir rather than rm -rf: only removes it if it's
    # actually empty, so a marker written between the check above and
    # here (another plugin's install racing this uninstall) is never
    # silently destroyed along with it. Mirrors the same fix in Encore
    # Radio's fpp_uninstall.sh - found missing here first on real
    # hardware (the directory was left behind after both plugins were
    # uninstalled, one right after the other).
    rmdir "$BRIDGE_OWNERS_DIR" 2>/dev/null || true
  fi

  local svc="/etc/systemd/system/announcementassistant-pulse.service"
  # Encore Radio's own unit name - only relevant in the "we're the last
  # one standing" branch below, where IT already uninstalled first and
  # left its own unit running for us (its marker was already gone when we
  # checked above), so nothing else is left to clean it up but us.
  local other_svc="/etc/systemd/system/encoreradio-pulse.service"

  if [[ "$OTHER_BRIDGE_OWNERS" -eq 1 ]]; then
    log "Another plugin still depends on the shared PulseAudio/PipeWire-pulse bridge - leaving it running."
  elif [[ -f "$svc" ]]; then
    log "Reverting AA's PulseAudio/PipeWire-pulse setup (nothing else appears to depend on it)"
    remove_systemd_service
    restore_pulse_system_pa
    restore_pulse_daemon_conf
    remove_fpp_pulse_pin
  elif [[ -f "$other_svc" ]]; then
    log "Reverting Encore Radio's PipeWire-pulse bridge (nothing else depends on it, and Encore Radio is no longer installed to do this itself)"
    systemctl stop encoreradio-pulse.service 2>/dev/null || true
    systemctl disable encoreradio-pulse.service 2>/dev/null || true
    rm -f "$other_svc" || true
    systemctl daemon-reload 2>/dev/null || true
    remove_fpp_pulse_pin
  else
    log "No PulseAudio/PipeWire-pulse bridge unit present - AA never owned the shared bridge (or another plugin does) - leaving it untouched."
  fi

  # Signal FPP to restart fppd
  set +u
  . "${FPPDIR:-/opt/fpp}/scripts/common" 2>/dev/null || true
  set -u
  setSetting restartFlag 1 2>/dev/null || true

  log "Done. Announcement config and audio files under /home/fpp/media were left in place."
}

main "$@"
