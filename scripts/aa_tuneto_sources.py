#!/usr/bin/env python3
"""Announcement Assistant - Tune To data sources & token resolver.

Phase 1 scope: song (ID3 from the currently-playing FPP media), playback, and
clock tokens. Weather/sensor tokens are defined here but resolve empty until
Phase 2 wires their sources, so messages that use them are skipped for now.

No third-party deps beyond mutagen (optional; ffprobe is the fallback). Uses
only the Python standard library for HTTP so the renderer stays light.
"""

import json
import os
import re
import subprocess
import time
import urllib.request

FPP_API = "http://127.0.0.1/api"
MEDIA_ROOTS = ["/home/fpp/media/music", "/home/fpp/media"]

_TOKEN_RE = re.compile(r"\{([a-z_]+)\}")

# All tokens the UI advertises. Weather ones resolve empty in Phase 1.
KNOWN_TOKENS = {
    "title", "artist", "album", "year", "genre", "track", "albumartist", "comment",
    "elapsed", "remaining", "duration", "next_title",
    "time", "date",
    "temp", "humidity", "conditions", "wind", "high", "low", "pressure",
}


def _http_json(url, timeout=3):
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            return json.loads(r.read().decode("utf-8", "replace"))
    except Exception:
        return None


# ── FPP status ─────────────────────────────────────────────────────────────
def fpp_status():
    """Return the fppd status dict, or {} on failure."""
    d = _http_json(FPP_API + "/fppd/status")
    return d if isinstance(d, dict) else {}


# ── Media file / ID3 ─────────────────────────────────────────────────────────
_id3_cache = {"path": None, "mtime": 0, "tags": {}}


def _find_media(filename):
    """Resolve a bare media filename (as FPP reports it) to a real path."""
    if not filename:
        return None
    if os.path.isabs(filename) and os.path.exists(filename):
        return filename
    base = os.path.basename(filename)
    for root in MEDIA_ROOTS:
        cand = os.path.join(root, filename)
        if os.path.exists(cand):
            return cand
        cand = os.path.join(root, base)
        if os.path.exists(cand):
            return cand
    # last resort: shallow search of the music tree
    for root in MEDIA_ROOTS:
        for dirpath, _dirs, files in os.walk(root):
            if base in files:
                return os.path.join(dirpath, base)
    return None


def _read_tags_mutagen(path):
    try:
        from mutagen import File as MutagenFile
    except Exception:
        return None
    try:
        mf = MutagenFile(path, easy=True)
        if mf is None:
            return None
        g = lambda k: (mf.get(k, [""]) or [""])[0]
        return {
            "title": g("title"), "artist": g("artist"), "album": g("album"),
            "year": g("date") or g("year"), "genre": g("genre"),
            "track": g("tracknumber"), "albumartist": g("albumartist"),
            "comment": (mf.get("comment", [""]) or [""])[0],
        }
    except Exception:
        return None


def _read_tags_ffprobe(path):
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "quiet", "-print_format", "json", "-show_format", path],
            capture_output=True, text=True, timeout=5,
        )
        tags = (json.loads(out.stdout).get("format", {}) or {}).get("tags", {}) or {}
        low = {k.lower(): v for k, v in tags.items()}
        return {
            "title": low.get("title", ""), "artist": low.get("artist", ""),
            "album": low.get("album", ""), "year": low.get("date", low.get("year", "")),
            "genre": low.get("genre", ""), "track": low.get("track", ""),
            "albumartist": low.get("album_artist", ""), "comment": low.get("comment", ""),
        }
    except Exception:
        return None


def media_tags(filename):
    """ID3/metadata for the given media filename, cached by path+mtime."""
    path = _find_media(filename)
    if not path:
        return {}
    try:
        mtime = os.path.getmtime(path)
    except OSError:
        mtime = 0
    if _id3_cache["path"] == path and _id3_cache["mtime"] == mtime:
        return _id3_cache["tags"]
    tags = _read_tags_mutagen(path) or _read_tags_ffprobe(path) or {}
    # Fall back to the filename (no extension) for the title if untagged.
    if not tags.get("title"):
        tags["title"] = os.path.splitext(os.path.basename(path))[0]
    _id3_cache.update(path=path, mtime=mtime, tags=tags)
    return tags


# ── Build the token context from current FPP state ───────────────────────────
def build_context(status, weather=None):
    """Return {token: value} for the current moment. Missing -> ''."""
    ctx = {k: "" for k in KNOWN_TOKENS}

    now = time.localtime()
    ctx["time"] = time.strftime("%-I:%M %p", now) if os.name != "nt" else time.strftime("%I:%M %p", now)
    ctx["date"] = time.strftime("%a %b %-d", now) if os.name != "nt" else time.strftime("%a %b %d", now)

    playing = bool(status.get("media_playing")) or str(status.get("status_name", "")) == "playing"
    song = status.get("current_song") or ""
    if playing and song:
        for k, v in media_tags(song).items():
            ctx[k] = v or ""
        ctx["elapsed"] = str(status.get("time_elapsed", "") or "")
        ctx["remaining"] = str(status.get("time_remaining", "") or "")
        sp = status.get("seconds_played") or "0"
        sr = status.get("seconds_remaining") or "0"
        try:
            dur = int(float(sp)) + int(float(sr))
            ctx["duration"] = "%d:%02d" % (dur // 60, dur % 60)
        except (ValueError, TypeError):
            ctx["duration"] = ""
    # next_title: best-effort from the playlist's next entry if FPP exposes it.
    nxt = status.get("next_playlist", {})
    if isinstance(nxt, dict):
        ctx["next_title"] = ""  # Phase 1: FPP's next_playlist is a playlist name, not a song.

    if weather and isinstance(weather, dict):
        for k in ("temp", "humidity", "conditions", "wind", "high", "low", "pressure"):
            if weather.get(k) not in (None, ""):
                ctx[k] = str(weather[k])

    return ctx


# ── Template resolution ──────────────────────────────────────────────────────
def resolve_template(template, ctx):
    """Substitute {tokens}. Returns (text, skip).

    skip is True when the template referenced at least one token and any
    referenced token resolved empty - that message is dropped from rotation
    (e.g. {title} with nothing playing, or a weather token before Phase 2).
    """
    tokens = _TOKEN_RE.findall(template or "")
    skip = False
    for t in tokens:
        if t in KNOWN_TOKENS and (ctx.get(t, "") == ""):
            skip = True

    def sub(m):
        return str(ctx.get(m.group(1), ""))

    return _TOKEN_RE.sub(sub, template or ""), skip
