# Tune To — Plan & Specification

> Status: **Phase 0 + Phase 1 built & hardware-validated (fpp-dev, FPP 10.x) 2026-11-06.** Engine first, live preview as a fast-follow.
> Branch: `feature/tune-to` (do not merge to `main` until phases are tested on real hardware).
>
> Progress: Phase 0 ✅ (tab + config + model/font listing). Phase 1 ✅ (renderer
> daemon: overlay take-over/release, region compositing, song/playback/clock
> tokens, static+hscroll+shrink+truncate, on/off, systemd unit, installer wiring).
> Phase 2 ✅ (weather via Open-Meteo — validated live; DHT22/DHT11/BME280 sensor
> readers with lazy imports, installer sensor-lib wiring, privacy manifest
> updated). Phase 3 ✅ (no-media fallbacks: static-only / idle-message /
> blank-release / bank-sign / showtimes; FPP-scheduler showtimes reader +
> custom override; vscroll + wipe effects; fixed a rotation bug that skipped
> each region's first message on startup). Phase 4 ✅ (in-tab live preview:
> `--preview` mode renders one frame of the current — even unsaved — config to a
> PNG via the real renderer; `tuneto_preview.php` returns it over HTTP; the tab
> polls it onto a scaled canvas. **All planned phases built & validated on
> fpp-dev; real-matrix hardware test pending.**

---

## 1. Problem

In a typical FPP/xLights show the "Tune to 103.3 FM" matrix sign is **baked into every
sequence** as a rendered text effect on the tune-to model. Changing the frequency, the
wording, or the format means re-editing and re-rendering *every sequence*. There is no
single place to manage what the sign says, and the sign can't react to the song that's
actually playing.

## 2. Solution

A new **Tune To** tab inside Announcement Assistant that manages the sign centrally and
drives it **live**, independent of any sequence:

- The user designates their tune-to matrix as an **FPP Pixel Overlay Model**.
- A persistent renderer composes the sign from user-defined **regions** and **templated
  messages** and pushes frames to that overlay model via FPP's overlay API.
- Dynamic data (now-playing song tags, clock, weather) is injected through **`{tokens}`**.
- The text is no longer in the sequences at all — change it once, here.

**Division of responsibility:** the user still builds the physical panel + channel outputs
and defines the Pixel Overlay Model in FPP/xLights as usual. AA reads that model's pixel
dimensions from FPP and paints *within* them. AA does not define the hardware.

---

## 3. Scope (v1)

| Decision | Choice |
|---|---|
| Number of signs | **Single** overlay-model target |
| Layout | **Spatial regions + per-region message rotation** on a timer |
| Content | Literal text + `{token}` shortcodes |
| Song source | ID3 tags of FPP's **currently-playing media** |
| Weather source | **Open-Meteo** (keyless) **or** local sensor (DHT22/DHT11, BME280) — user picks one |
| Overflow / effects | Per-region/per-message: static / h-scroll / v-scroll / wipe / shrink-to-fit / truncate |
| No-media fallback | Static-only / custom idle message / blank / "bank sign" (time+temp) / showtimes |
| On/off | Manual master; when auto, ON if **any** enabled source says on (OR) |
| Showtimes content | Auto-read FPP scheduler, overridable with custom text |
| Empty tokens | Message is **skipped** in rotation |
| Announcement/ducking tie-in | **Not in v1** |
| Renderer language | **Python (Pillow)** |
| Live WYSIWYG preview | **Phase 4** (approximates, not pixel-identical to the panel) |

---

## 4. Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                          Raspberry Pi                              │
│                                                                    │
│  ┌───────────────┐   save (POST)   ┌──────────────────────────┐   │
│  │  Tune To tab  │ ──────────────▶ │  tuneto.json (config)    │   │
│  │  (www/*.php)  │                 └─────────────┬────────────┘   │
│  └───────────────┘                               │ reload on change│
│         ▲ status/preview (AJAX)                  ▼                 │
│  ┌──────┴────────────────────────────────────────────────────┐   │
│  │        aa_tuneto_renderer  (persistent Python daemon)      │   │
│  │                                                            │   │
│  │  • on/off decision (manual / window / schedule / playing)  │   │
│  │  • token resolver  ← sources below                         │   │
│  │  • region compositor (Pillow) → frame                      │   │
│  │  • rotation timers + scroll/wipe animation                 │   │
│  │  • push frame → FPP overlay API                            │   │
│  └───┬─────────────┬───────────────┬──────────────┬───────────┘   │
│      │             │               │              │               │
│      ▼             ▼               ▼              ▼               │
│  FPP status    ID3 tags       Open-Meteo /     FPP overlay       │
│  /api/...      (ffprobe/      local sensor     model (paint)     │
│  (now playing, mutagen)       (temp/humidity)                    │
│   schedule)                                                      │
└──────────────────────────────────────────────────────────────────┘
```

### Runtime component (new to AA)

AA's existing scripts are fire-and-forget. Tune To needs a **persistent service**,
`announcementassistant-tuneto.service`, running `scripts/aa_tuneto_renderer.py`. It:

1. Loads `tuneto.json`; watches it for changes (reload without restart).
2. Evaluates the on/off decision each tick.
3. Polls data sources on their own cadences (see §7).
4. Composes the current frame with Pillow and animates transitions.
5. Pushes frames to the overlay model via FPP's overlay HTTP API.
6. On stop/disable, clears the overlay so sequences render normally.

Frame rate target: ~20 fps while an animated effect is active; idle (all-static) drops to
event-driven repaint to spare CPU on a Pi Zero 2 W.

---

## 5. Config schema — `/home/fpp/media/config/tuneto.json`

Separate file from `announcementassistant.json` (keeps the tab's concerns isolated;
matches AA's existing atomic-write + default-merge save pattern).

```jsonc
{
  "enabled": true,                 // master switch (manual)
  "overlay_model": "TuneTo",       // FPP Pixel Overlay Model name
  "onoff": {
    "mode": "auto",                // "on" | "off" | "auto"
    "while_playing": true,         // OR-combined when mode=auto
    "follow_schedule": true,
    "window": { "enabled": false, "start": "17:00", "end": "22:00" }
  },
  "environment": {
    "source": "open-meteo",        // "open-meteo" | "sensor" | "none"
    "units": "F",                  // "F" | "C"
    "open_meteo": { "lat": 40.90, "lon": -74.40, "refresh_min": 10 },
    "sensor": { "type": "DHT22", "gpio": 4, "i2c_addr": "0x76", "refresh_sec": 30 }
  },
  "regions": [
    {
      "name": "Header",
      "rect": { "x": 0, "y": 0, "w": 64, "h": 8 },
      "font": "DejaVuSans", "size": 8, "color": "#FF0000", "align": "center",
      "messages": [
        { "template": "TUNE TO 103.3 FM", "effect": "static", "dwell_sec": 6 }
      ]
    },
    {
      "name": "NowPlaying",
      "rect": { "x": 0, "y": 8, "w": 64, "h": 24 },
      "font": "DejaVuSans", "size": 10, "color": "#FFFFFF", "align": "left",
      "messages": [
        { "template": "{title}", "effect": "hscroll", "speed": 20, "dwell_sec": 5 },
        { "template": "{artist}", "effect": "hscroll", "speed": 20, "dwell_sec": 5 }
      ]
    }
  ],
  "fallback": {
    "mode": "idle_message",        // "static_only" | "idle_message" | "blank" | "bank" | "showtimes"
    "idle_message": "Merry Christmas!",
    "showtimes": { "source": "fpp", "custom": "" }   // "fpp" | "custom" | "both"
  }
}
```

State / runtime (not user config), under `plugindata/fpp-AnnouncementAssistant/state/`:
`tuneto_weather.cache.json`, `tuneto_renderer.pid`, `tuneto_last_frame.png` (for preview).

---

## 6. Token reference

| Group | Tokens | Source |
|---|---|---|
| Song (ID3) | `{title} {artist} {album} {year} {genre} {track} {albumartist} {comment}` | tags of currently-playing media |
| Playback | `{elapsed} {remaining} {duration} {next_title}` | FPP status API |
| Clock | `{time} {date}` | system clock (format configurable later) |
| Weather | `{temp} {humidity} {conditions} {wind} {high} {low}` (+`{pressure}` on BME280) | Open-Meteo or sensor |

Rules:
- Literal text is typed directly; tokens are replaced at render time.
- A message whose **required** tokens resolve empty is **skipped** in its region's rotation
  (e.g. `{next_title}` with nothing queued, failed weather fetch, no song playing).
- If every message in a region is skipped, the region renders empty that tick.

---

## 7. Data sources & cadence

- **Now playing:** FPP status API (`/api/fppd/status` / current playlist entry) for the
  active media filename; resolve ID3 via `ffprobe` (already recommended dep) with a
  `mutagen` fallback. Update on media-change (poll ~1 s; cheap).
- **Weather (Open-Meteo):** keyless HTTPS; user sets lat/lon; poll every `refresh_min`
  (default 10). Cache last good result; on failure keep cache and skip weather-only messages.
- **Local sensor:** DHT22/DHT11 via `adafruit-circuitpython-dht` (GPIO); BME280 via
  `adafruit-circuitpython-bme280` (I²C). Poll every `refresh_sec` (default 30).
- **Schedule:** FPP scheduler entries for showtimes + the `follow_schedule` on/off input.

## 8. On/off decision (per tick)

```
if onoff.mode == "off":  sign = OFF
elif onoff.mode == "on": sign = ON
else: # auto
  sign = (window.enabled  and now within window)
      or (follow_schedule and inside an FPP scheduled show)
      or (while_playing    and FPP is actively playing)
```
`enabled=false` (master) forces OFF regardless. On OFF, the overlay is cleared.

---

## 9. Phasing

Each phase is independently testable on the real panel and committed on `feature/tune-to`.

### Phase 0 — Scaffolding & data model  ← **start here**
- `www/tuneto.php` tab UI shell (Bootstrap nav-tab within the AA plugin page) + wire it in.
- `www/tuneto_save.php` — atomic save of `tuneto.json` (mirror `save.php`).
- Helper endpoints: list FPP Pixel Overlay Models; list installed fonts.
- Write a default `tuneto.json` if absent.
- **No rendering yet.** Acceptance: tab loads, lets you pick an overlay model + define
  regions/messages/tokens, and saves valid `tuneto.json`.

### Phase 1 — Renderer daemon (core)
- `scripts/aa_tuneto_renderer.py` + `announcementassistant-tuneto.service`.
- Token resolver for song/playback/clock; region compositor (Pillow); rotation + h-scroll;
  push frames to overlay model; on/off logic; clear-on-disable.
- Acceptance: live `{title} - {artist}` appears on the panel and updates as songs change;
  sign honors on/off.

### Phase 2 — Weather + sensors
- Open-Meteo poller; DHT/BME280 reader; weather tokens + units; install-time deps.
- Acceptance: `{temp}` etc. render and refresh; sensor path works on a wired Pi.

### Phase 3 — Fallbacks & schedule
- No-media modes (static / idle / blank / bank / showtimes); FPP scheduler read + custom
  override; remaining effects (v-scroll, wipe, shrink-to-fit, truncate).
- Acceptance: each fallback mode behaves; showtimes populate from FPP and/or custom.

### Phase 4 — Live preview ✅
- Server-rendered, pixel-accurate: the renderer's `--preview <png>` mode paints one
  frame of the current config (ignoring the on/off master, honoring the no-media
  fallback) to a PNG without touching the overlay; `www/tuneto_preview.php` runs it
  and returns the PNG (POST the live edits to preview unsaved changes, else the saved
  config); the tab's Live Preview card polls it (~1.5 s) onto a scaled, nearest-neighbor
  `<canvas>`.
- Acceptance: tab shows the actual rendered sign; byte-identical to the direct render.
  **Validated on fpp-dev 2026-11-06** (HTTP 200, `image/png`, identical bytes to the
  CLI render; frame correct: amber `103.3 FM`, idle message, live clock).

---

## 10. File manifest (new)

| File | Phase | Purpose |
|---|---|---|
| `docs/TUNE_TO.md` | — | this document |
| `www/tuneto.php` | 0 | Tune To tab UI |
| `www/tuneto_save.php` | 0 | save `tuneto.json` |
| `www/tuneto_models.php` | 0 | list overlay models + fonts for the UI |
| `scripts/aa_tuneto_renderer.py` | 1 | persistent renderer daemon |
| `scripts/aa_tuneto_sources.py` | 1–2 | token/data-source resolvers |
| `announcementassistant-tuneto.service` | 1 | systemd unit (installed by `fpp_install.sh`) |
| `www/tuneto_preview.php` | 4 | renders the current config to a preview PNG via the renderer's `--preview` mode |

Touched: `status_menu.inc`/`www/index.php` (tab wiring), `fpp_install.sh` /
`fpp_uninstall.sh` (service + Python deps), `pluginInfo.json` (privacy: Open-Meteo is the
plugin's first outbound call + GPIO/I²C sensor use — must be declared), `README.md`.

## 11. Risks / open items

- **Privacy manifest:** Open-Meteo is AA's first-ever outbound network call, and sensors
  are AA's first `sensors` entry — `pluginInfo.json` privacy block must be updated honestly.
- **Overlay API specifics** (text vs raw-pixel paint, clearing, geometry read) to be
  confirmed against the installed FPP version during Phase 1.
- **Font fidelity** on small matrices: TTF at tiny sizes is rough; may want bundled bitmap
  fonts. Revisit if panels are small.
- **Pi Zero 2 W CPU:** keep idle event-driven; cap animated fps.
- **Baked-in signs:** document that users should remove tune-to text from sequences, since
  the overlay now owns that model while active.

## 12. Git workflow

- All work on `feature/tune-to`, committed per phase.
- Test each phase on real hardware before the next.
- Merge to `main` only after the feature is tested end-to-end; tag a release then.
