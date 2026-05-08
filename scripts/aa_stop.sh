#!/usr/bin/env bash
# aa_stop.sh — stop any currently playing announcement
# Called by stop.php

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${SCRIPT_DIR}/aa_play.sh" --stop
