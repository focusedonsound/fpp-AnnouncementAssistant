#!/usr/bin/env python3
"""Announcement Assistant - Tune To renderer daemon (Phase 1).

Persistent service that composes the Tune To sign from the configured regions
and messages and pushes frames to an FPP Pixel Overlay Model via FPP's bulk
pixel-data API. Resolves song/playback/clock tokens live; honors the on/off
logic. Weather/sensor tokens and vscroll/wipe effects arrive in later phases.

Run standalone for testing:   python3 aa_tuneto_renderer.py --verbose
Render one frame and exit:    python3 aa_tuneto_renderer.py --once
Blank/release the model:      python3 aa_tuneto_renderer.py --stop
"""

import argparse
import json
import os
import signal
import sys
import time
import urllib.parse
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import aa_tuneto_sources as src  # noqa: E402

from PIL import Image, ImageDraw, ImageFont  # noqa: E402

CONFIG_FILE = "/home/fpp/media/config/tuneto.json"
FPP_API = "http://127.0.0.1/api"
FONT_DIRS = ["/usr/share/fonts", "/usr/local/share/fonts",
             "/opt/fpp/www/fonts", "/home/fpp/media/fonts"]
DEFAULT_FONT_PREFS = ["FreeSans", "DejaVuSans", "FreeMono", "LiberationSans"]

VERBOSE = False


def log(*a):
    if VERBOSE:
        print("[tuneto]", *a, flush=True)


# ── Fonts ────────────────────────────────────────────────────────────────────
_font_map = None     # name -> path
_font_cache = {}      # (path, size) -> ImageFont


def font_map():
    global _font_map
    if _font_map is not None:
        return _font_map
    m = {}
    for base in FONT_DIRS:
        if not os.path.isdir(base):
            continue
        for dirpath, _dirs, files in os.walk(base):
            for f in files:
                if f.lower().endswith((".ttf", ".otf")):
                    name = os.path.splitext(f)[0]
                    m.setdefault(name, os.path.join(dirpath, f))
    _font_map = m
    return m


def default_font_path():
    m = font_map()
    for pref in DEFAULT_FONT_PREFS:
        if pref in m:
            return m[pref]
    return next(iter(m.values()), None)


def get_font(name, size):
    size = max(4, int(size))
    m = font_map()
    path = m.get(name) if name else None
    if not path:
        path = default_font_path()
    key = (path, size)
    if key not in _font_cache:
        try:
            _font_cache[key] = ImageFont.truetype(path, size) if path else ImageFont.load_default()
        except Exception:
            _font_cache[key] = ImageFont.load_default()
    return _font_cache[key]


def hex_rgb(s):
    s = (s or "").lstrip("#")
    if len(s) != 6:
        return (255, 255, 255)
    try:
        return (int(s[0:2], 16), int(s[2:4], 16), int(s[4:6], 16))
    except ValueError:
        return (255, 255, 255)


def text_width(draw, text, font):
    try:
        return draw.textlength(text, font=font)
    except Exception:
        bbox = font.getbbox(text)
        return bbox[2] - bbox[0]


# ── FPP overlay model I/O ────────────────────────────────────────────────────
def model_geometry(name):
    """Return (width, height) for the overlay model, or (0, 0)."""
    if not name:
        return (0, 0)
    url = FPP_API + "/overlays/model/" + urllib.parse.quote(name, safe="")
    d = src._http_json(url)
    if not isinstance(d, dict):
        return (0, 0)
    return (int(d.get("width") or d.get("Width") or 0),
            int(d.get("height") or d.get("Height") or 0))


def push_frame(name, img):
    """PUT a full RGB frame to the overlay model (auto-enables it)."""
    raw = img.tobytes()  # RGB, row-major, w*h*3
    url = (FPP_API + "/overlays/model/" + urllib.parse.quote(name, safe="")
           + "/data?autoEnable=true")
    req = urllib.request.Request(url, data=raw, method="PUT",
                                 headers={"Content-Type": "application/octet-stream"})
    try:
        with urllib.request.urlopen(req, timeout=3) as r:
            r.read()
        return True
    except Exception as e:
        log("push_frame failed:", e)
        return False


def release_model(name):
    """Clear the model and set its overlay state off."""
    if not name:
        return
    enc = urllib.parse.quote(name, safe="")
    try:
        urllib.request.urlopen(FPP_API + "/overlays/model/" + enc + "/clear", timeout=3).read()
    except Exception:
        pass
    try:
        req = urllib.request.Request(FPP_API + "/overlays/model/" + enc + "/state",
                                     data=b'{"State":0}', method="PUT",
                                     headers={"Content-Type": "application/json"})
        urllib.request.urlopen(req, timeout=3).read()
    except Exception:
        pass


# ── On/off decision ──────────────────────────────────────────────────────────
def _in_window(start, end, now=None):
    now = now or time.localtime()
    cur = now.tm_hour * 60 + now.tm_min
    try:
        sh, sm = [int(x) for x in start.split(":")]
        eh, em = [int(x) for x in end.split(":")]
    except (ValueError, AttributeError):
        return False
    s, e = sh * 60 + sm, eh * 60 + em
    if s == e:
        return False
    return (s <= cur < e) if s < e else (cur >= s or cur < e)


def sign_is_on(cfg, status):
    if not cfg.get("enabled", False):
        return False
    onoff = cfg.get("onoff", {})
    mode = onoff.get("mode", "auto")
    if mode == "on":
        return True
    if mode == "off":
        return False
    # auto: OR across enabled sources
    if onoff.get("while_playing"):
        if (bool(status.get("media_playing"))
                or str(status.get("status_name", "")) == "playing"
                or bool(status.get("current_sequence"))):
            return True
    if onoff.get("follow_schedule"):
        if str(status.get("scheduler", {}).get("status", "")) == "playing":
            return True
    win = onoff.get("window", {})
    if win.get("enabled") and _in_window(win.get("start", "17:00"), win.get("end", "22:00")):
        return True
    return False


# ── Message rotation state ───────────────────────────────────────────────────
class RegionState:
    def __init__(self):
        self.idx = 0
        self.since = 0.0
        self.scroll = 0.0


# ── Rendering ────────────────────────────────────────────────────────────────
def render_region(region, state, ctx, dt):
    """Return an RGB Image of the region, or None if nothing to show."""
    rect = region.get("rect", {})
    w = max(1, int(rect.get("w", 64)))
    h = max(1, int(rect.get("h", 8)))
    msgs = region.get("messages", []) or []

    # Which messages are renderable right now (tokens resolve non-empty)?
    renderable = []
    for i, m in enumerate(msgs):
        text, skip = src.resolve_template(m.get("template", ""), ctx)
        if not skip and text != "":
            renderable.append((i, text))
    if not renderable:
        return None

    idxs = [i for i, _ in renderable]
    now = time.monotonic()
    if state.idx not in idxs:
        state.idx, state.since, state.scroll = idxs[0], now, 0.0
    else:
        cur_msg = msgs[state.idx]
        if len(idxs) > 1 and (now - state.since) >= float(cur_msg.get("dwell_sec", 5)):
            nxt = idxs[(idxs.index(state.idx) + 1) % len(idxs)]
            state.idx, state.since, state.scroll = nxt, now, 0.0

    text = dict(renderable)[state.idx]
    msg = msgs[state.idx]
    effect = msg.get("effect", "static")
    speed = float(msg.get("speed", 20))
    color = hex_rgb(region.get("color", "#FFFFFF"))
    align = region.get("align", "center")

    img = Image.new("RGB", (w, h), (0, 0, 0))
    draw = ImageDraw.Draw(img)
    font = get_font(region.get("font", ""), region.get("size", 10))

    tw = text_width(draw, text, font)
    mid_y = h / 2.0

    # Overflow handling per effect.
    if effect == "shrink" and tw > w:
        sz = int(region.get("size", 10))
        while sz > 4:
            sz -= 1
            font = get_font(region.get("font", ""), sz)
            tw = text_width(draw, text, font)
            if tw <= w:
                break
    elif effect == "truncate" and tw > w:
        while len(text) > 1 and text_width(draw, text + "…", font) > w:
            text = text[:-1]
        text = text + "…"
        tw = text_width(draw, text, font)

    if effect in ("hscroll",) and tw > w:
        # Continuous right-to-left scroll with a gap; draw two copies.
        gap = max(8, int(w * 0.25))
        state.scroll += speed * dt
        period = tw + gap
        off = state.scroll % period
        draw.text((-off, mid_y), text, font=font, fill=color, anchor="lm")
        draw.text((-off + period, mid_y), text, font=font, fill=color, anchor="lm")
    else:
        # static / shrink / truncate / fits: align within the region.
        if align == "left":
            draw.text((0, mid_y), text, font=font, fill=color, anchor="lm")
        elif align == "right":
            draw.text((w, mid_y), text, font=font, fill=color, anchor="rm")
        else:
            draw.text((w / 2.0, mid_y), text, font=font, fill=color, anchor="mm")

    return img, (effect in ("hscroll", "vscroll", "wipe") and tw > w)


def compose_frame(cfg, ctx, width, height, states, dt):
    """Build the full-panel RGB frame. Returns (Image, animating)."""
    frame = Image.new("RGB", (width, height), (0, 0, 0))
    animating = False
    for i, region in enumerate(cfg.get("regions", [])):
        st = states.setdefault(i, RegionState())
        out = render_region(region, st, ctx, dt)
        if not out:
            continue
        img, anim = out
        animating = animating or anim
        rect = region.get("rect", {})
        x, y = int(rect.get("x", 0)), int(rect.get("y", 0))
        # Clip to panel bounds.
        if x >= width or y >= height:
            continue
        frame.paste(img, (x, y))
    return frame, animating


# ── Config ───────────────────────────────────────────────────────────────────
def load_config(path):
    try:
        with open(path, "r") as f:
            return json.load(f)
    except Exception:
        return {}


# ── Main loop ────────────────────────────────────────────────────────────────
_running = True


def _sigterm(_signo, _frame):
    global _running
    _running = False


def main():
    global VERBOSE, _running
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default=CONFIG_FILE)
    ap.add_argument("--once", action="store_true", help="render one frame and exit")
    ap.add_argument("--stop", action="store_true", help="clear/release the model and exit")
    ap.add_argument("--verbose", action="store_true")
    args = ap.parse_args()
    VERBOSE = args.verbose

    cfg = load_config(args.config)
    model = cfg.get("overlay_model", "")

    if args.stop:
        release_model(model)
        log("released", model)
        return

    signal.signal(signal.SIGTERM, _sigterm)
    signal.signal(signal.SIGINT, _sigterm)

    states = {}
    env = src.EnvironmentPoller()
    cfg_mtime = os.path.getmtime(args.config) if os.path.exists(args.config) else 0
    geom = (0, 0)
    geom_name = None
    geom_check = 0.0
    status = {}
    status_check = 0.0
    last = time.monotonic()
    was_on = False

    while _running:
        now = time.monotonic()
        dt = max(0.0, now - last)
        last = now

        # Reload config if it changed on disk.
        try:
            m = os.path.getmtime(args.config)
            if m != cfg_mtime:
                cfg = load_config(args.config)
                cfg_mtime = m
                model = cfg.get("overlay_model", "")
                states.clear()
                log("config reloaded; model =", model)
        except OSError:
            pass

        # Refresh FPP status ~1/s.
        if now - status_check >= 1.0:
            status = src.fpp_status()
            status_check = now

        on = sign_is_on(cfg, status)

        if not on:
            if was_on:
                release_model(model)
                was_on = False
                log("sign OFF -> released")
            if args.once:
                break
            time.sleep(0.5)
            continue

        # Refresh geometry if the model changed or periodically.
        if model != geom_name or now - geom_check >= 10.0:
            geom = model_geometry(model)
            geom_name = model
            geom_check = now
        width, height = geom
        if width <= 0 or height <= 0:
            log("no geometry for model", repr(model), "- waiting")
            if args.once:
                break
            time.sleep(1.0)
            continue

        weather = env.poll(cfg.get("environment", {}))
        ctx = src.build_context(status, weather)
        frame, animating = compose_frame(cfg, ctx, width, height, states, dt)
        push_frame(model, frame)
        was_on = True

        if args.once:
            break
        time.sleep(1.0 / 20.0 if animating else 1.0 / 5.0)

    # Clean exit: release the model so sequences render normally.
    release_model(model)
    log("exiting; released", model)


if __name__ == "__main__":
    main()
