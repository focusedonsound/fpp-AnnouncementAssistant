#!/bin/bash
set -euo pipefail

# Stop the currently running duck/overlay script (trap restores volume)
pgrep -f "aa_duck_overlay_pulse.sh" >/dev/null 2>&1 && \
  pgrep -f "aa_duck_overlay_pulse.sh" | xargs -r kill -TERM || true

# Also stop any paplay client launched by the plugin (extra safety)
pgrep -f "paplay.*AnnouncementAssistant" >/dev/null 2>&1 && \
  pgrep -f "paplay.*AnnouncementAssistant" | xargs -r kill -TERM || true

exit 0
