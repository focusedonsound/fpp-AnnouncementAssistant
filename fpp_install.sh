#!/usr/bin/env bash
set -euo pipefail

PKGS=(pulseaudio pulseaudio-utils libasound2-plugins)

echo "== Announcement Assistant (AA) install =="
echo "Installing dependencies: ${PKGS[*]}"

if command -v apt-get >/dev/null 2>&1; then
  if [ "$(id -u)" -ne 0 ]; then
    sudo apt-get update
    sudo apt-get install -y --no-install-recommends "${PKGS[@]}"
  else
    apt-get update
    apt-get install -y --no-install-recommends "${PKGS[@]}"
  fi
else
  echo "ERROR: apt-get not found. Cannot auto-install dependencies." >&2
  exit 1
fi

echo
echo "IMPORTANT:"
echo "For audio mixing + ducking to work, set FPP Audio Output Device to: pulse"
echo "Then restart fppd (or reboot) so fppd outputs through PulseAudio."
echo
echo "Install complete."
