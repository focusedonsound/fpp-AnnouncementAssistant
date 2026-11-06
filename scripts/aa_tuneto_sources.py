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


# ── Weather & sensors (Phase 2) ──────────────────────────────────────────────
# WMO weather interpretation codes -> short human text (Open-Meteo "weather_code").
_WMO = {
    0: "Clear", 1: "Mostly Clear", 2: "Partly Cloudy", 3: "Cloudy",
    45: "Fog", 48: "Rime Fog",
    51: "Light Drizzle", 53: "Drizzle", 55: "Heavy Drizzle",
    56: "Freezing Drizzle", 57: "Freezing Drizzle",
    61: "Light Rain", 63: "Rain", 65: "Heavy Rain",
    66: "Freezing Rain", 67: "Freezing Rain",
    71: "Light Snow", 73: "Snow", 75: "Heavy Snow", 77: "Snow Grains",
    80: "Showers", 81: "Showers", 82: "Heavy Showers",
    85: "Snow Showers", 86: "Snow Showers",
    95: "Thunderstorm", 96: "Thunderstorm", 99: "Thunderstorm",
}


def _fetch_openmeteo(lat, lon, units):
    """Return a weather dict from Open-Meteo (keyless), or {} on failure."""
    temp_unit = "fahrenheit" if units == "F" else "celsius"
    wind_unit = "mph" if units == "F" else "kmh"
    wind_label = "mph" if units == "F" else "km/h"
    url = (
        "https://api.open-meteo.com/v1/forecast"
        "?latitude=%s&longitude=%s"
        "&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m"
        "&daily=temperature_2m_max,temperature_2m_min"
        "&temperature_unit=%s&wind_speed_unit=%s&timezone=auto&forecast_days=1"
        % (lat, lon, temp_unit, wind_unit)
    )
    d = _http_json(url, timeout=6)
    if not isinstance(d, dict):
        return {}
    cur = d.get("current", {}) or {}
    daily = d.get("daily", {}) or {}
    out = {}
    try:
        if cur.get("temperature_2m") is not None:
            out["temp"] = "%d°%s" % (round(cur["temperature_2m"]), units)
        if cur.get("relative_humidity_2m") is not None:
            out["humidity"] = "%d%%" % round(cur["relative_humidity_2m"])
        if cur.get("weather_code") is not None:
            out["conditions"] = _WMO.get(int(cur["weather_code"]), "")
        if cur.get("wind_speed_10m") is not None:
            out["wind"] = "%d %s" % (round(cur["wind_speed_10m"]), wind_label)
        hi = (daily.get("temperature_2m_max") or [None])[0]
        lo = (daily.get("temperature_2m_min") or [None])[0]
        if hi is not None:
            out["high"] = "%d°" % round(hi)
        if lo is not None:
            out["low"] = "%d°" % round(lo)
    except (TypeError, ValueError):
        pass
    return out


def _read_dht(sensor_type, gpio, units):
    """Read a DHT11/DHT22 on a GPIO pin. Lazy import; {} if unavailable."""
    try:
        import board
        import adafruit_dht
    except Exception:
        return {}
    try:
        pin = getattr(board, "D%d" % int(gpio))
        cls = adafruit_dht.DHT11 if sensor_type == "DHT11" else adafruit_dht.DHT22
        dev = cls(pin)
        t_c = dev.temperature
        rh = dev.humidity
        try:
            dev.exit()
        except Exception:
            pass
        out = {}
        if t_c is not None:
            t = t_c * 9 / 5 + 32 if units == "F" else t_c
            out["temp"] = "%d°%s" % (round(t), units)
        if rh is not None:
            out["humidity"] = "%d%%" % round(rh)
        return out
    except Exception:
        return {}


def _read_bme280(i2c_addr, units):
    """Read a BME280 over I2C. Lazy import; {} if unavailable."""
    try:
        import board
        import busio
        from adafruit_bme280 import basic as adafruit_bme280
    except Exception:
        return {}
    try:
        addr = int(i2c_addr, 16) if isinstance(i2c_addr, str) else int(i2c_addr)
        i2c = busio.I2C(board.SCL, board.SDA)
        dev = adafruit_bme280.Adafruit_BME280_I2C(i2c, address=addr)
        t_c = dev.temperature
        rh = dev.relative_humidity
        p = dev.pressure
        out = {}
        if t_c is not None:
            t = t_c * 9 / 5 + 32 if units == "F" else t_c
            out["temp"] = "%d°%s" % (round(t), units)
        if rh is not None:
            out["humidity"] = "%d%%" % round(rh)
        if p is not None:
            out["pressure"] = "%d hPa" % round(p)
        return out
    except Exception:
        return {}


class EnvironmentPoller:
    """Polls the configured weather/sensor source on its own cadence and
    caches the last good reading (so a transient failure keeps the last value
    rather than blanking weather tokens)."""

    def __init__(self):
        self._cache = {}
        self._next = 0.0
        self._sig = None

    def poll(self, env_cfg):
        import time as _t
        env_cfg = env_cfg or {}
        source = env_cfg.get("source", "none")
        units = env_cfg.get("units", "F")
        if source == "none":
            return {}

        # Re-fetch immediately if the relevant config changed.
        sig = json.dumps({"s": source, "u": units,
                          "om": env_cfg.get("open_meteo"),
                          "se": env_cfg.get("sensor")}, sort_keys=True)
        now = _t.monotonic()
        if sig != self._sig:
            self._sig, self._next, self._cache = sig, 0.0, {}
        if now < self._next:
            return self._cache

        if source == "open-meteo":
            om = env_cfg.get("open_meteo", {}) or {}
            data = _fetch_openmeteo(om.get("lat", 0), om.get("lon", 0), units)
            interval = max(60, int(om.get("refresh_min", 10)) * 60)
        else:  # sensor
            se = env_cfg.get("sensor", {}) or {}
            stype = se.get("type", "DHT22")
            if stype == "BME280":
                data = _read_bme280(se.get("i2c_addr", "0x76"), units)
            else:
                data = _read_dht(stype, se.get("gpio", 4), units)
            interval = max(2, int(se.get("refresh_sec", 30)))

        if data:                      # keep last good values on a failed read
            self._cache = data
        self._next = now + interval
        return self._cache
