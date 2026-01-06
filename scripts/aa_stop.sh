#!/usr/bin/env bash
set -euo pipefail

PID_FILE="/home/fpp/media/tmp/aa_announcement.pid"

if [[ ! -f "$PID_FILE" ]]; then
  echo "No announcement is currently playing."
  exit 0
fi

pid="$(cat "$PID_FILE" 2>/dev/null || true)"
if [[ -z "$pid" ]]; then
  rm -f "$PID_FILE" || true
  echo "No announcement is currently playing."
  exit 0
fi

if ! kill -0 "$pid" 2>/dev/null; then
  rm -f "$PID_FILE" || true
  echo "No announcement is currently playing."
  exit 0
fi

kill -TERM "$pid" 2>/dev/null || true

# Give it a moment to exit cleanly (so volumes restore)
for _ in $(seq 1 20); do
  if ! kill -0 "$pid" 2>/dev/null; then
    echo "Announcement stopped."
    exit 0
  fi
  sleep 0.1
done

# Last resort
kill -KILL "$pid" 2>/dev/null || true
echo "Announcement stopped (forced)."
exit 0
