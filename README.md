# 📣 Announcement Assistant — Audio Ducking for Falcon Player

### *FPP Plugin: Announcement Assistant (Audio Ducking)*

[![FPP Compatible](https://img.shields.io/badge/FPP-8.x%20%7C%209.x%20%7C%2010.x%2B-red?style=for-the-badge&logo=raspberry-pi)](https://github.com/FalconChristmasLighting/fpp)
[![Platform](https://img.shields.io/badge/Platform-Raspberry%20Pi-c51a4a?style=for-the-badge&logo=raspberry-pi)](https://www.raspberrypi.com/)
[![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)](LICENSE)
[![Audio](https://img.shields.io/badge/Audio-PulseAudio-orange?style=for-the-badge)](https://www.freedesktop.org/wiki/Software/PulseAudio/)
[![GitHub](https://img.shields.io/badge/GitHub-focusedonsound-181717?style=for-the-badge&logo=github)](https://github.com/focusedonsound/fpp-AnnouncementAssistant)

---

> **Your show keeps running. Your voice cuts right through. Announcement Assistant layers pre-recorded messages over your active FPP show audio with silky-smooth automatic ducking — no dead air, no stopping the magic, just clear announcements exactly when you need them. 🎙️✨**

---

## 🎄 Table of Contents

- [What Is Announcement Assistant?](#-what-is-announcement-assistant)
- [Feature Overview](#-feature-overview)
- [How It Works (Architecture)](#️-how-it-works-architecture)
- [Real-World Use Cases](#-real-world-use-cases)
- [Requirements](#-requirements)
- [Installation](#-installation)
- [Configuration](#️-configuration)
- [The Six Announcement Slots](#-the-six-announcement-slots)
- [Ducking & Fading Explained](#-ducking--fading-explained)
- [Behavior Modes](#-behavior-modes)
- [Per-Slot Priority (Interrupt)](#-per-slot-priority-interrupt)
- [FPP Commands](#-fpp-commands)
- [Trigger from Sensors & Automations](#-trigger-from-sensors--automations)
- [Play Count Tracking](#-play-count-tracking)
- [Audio File Tips](#-audio-file-tips)
- [PulseAudio Setup Details](#-pulseaudio-setup-details)
- [Troubleshooting](#-troubleshooting)
- [File Reference](#-file-reference)
- [FAQ](#-faq)
- [Credits](#-credits)

---

## 🎤 What Is Announcement Assistant?

**Announcement Assistant (AA)** is a Falcon Player plugin that gives your Christmas light show a *voice* — without ever stopping the show.

You record six announcements. You configure each slot with a label, an audio file, and a duck level. When you — or a sensor, or a donation box, or an automation — triggers a slot, AA:

1. **Smoothly fades down** the show audio to your configured duck level
2. **Plays your announcement** on top of the live show via PulseAudio mixing
3. **Smoothly fades the show back up** to full volume once the announcement finishes

The lights keep going. The music never stops (it just gets politely quieter for a moment). Your message is crisp and clear. Your visitors know to tune to 103.3 FM. The donation box says thank you. The crowd smiles.

This is **true audio mixing** — not muting, not pausing, not stopping. The show and the announcement *play simultaneously*, with the announcement layered on top. And it's built right into FPP with a one-click installer.

---

## ✨ Feature Overview

| Feature | Description |
|---|---|
| 🎚️ 6 Announcement Slots | Each has its own label, audio file, duck level, and priority flag |
| 🔉 PulseAudio Ducking | Show audio fades to configured % while announcement plays |
| 🌊 Smooth Fade Down/Up | Configurable fade durations (default: 0.5s down, 1.0s up) |
| 🔇 Behavior Modes | Ignore (drop if busy), Queue (wait), or Interrupt (stop current) |
| ⚡ Per-Slot Priority | Mark any slot as high-priority to always interrupt, regardless of global policy |
| ⏱️ Cooldown Protection | Configurable minimum time between triggers to prevent spamming |
| 🎮 Live Trigger Buttons | One-click test and trigger buttons right in the FPP UI |
| ⚡ FPP Commands | "Play" (with slot picker) and "Stop" — usable in FPP sequences, scripts, and schedules |
| 📊 Play Count Tracking | Today and lifetime play counts displayed per slot |
| 🎵 Wide Format Support | WAV, MP3, OGG, FLAC, M4A — uses ffmpeg for format conversion if available |
| 🔧 Auto PulseAudio Setup | Configures system-wide PulseAudio with 48kHz for optimal audio quality |
| 🛑 Clean Stop | Stop button immediately halts playback and restores show audio |

---

## 🏗️ How It Works (Architecture)

```
┌──────────────────────────────────────────────────────────────────────┐
│                          Raspberry Pi                                │
│                                                                      │
│  ┌───────────────┐    FPP Command /    ┌──────────────────────────┐  │
│  │  FPP Web UI   │    direct script    │      aa_play.sh          │  │
│  │  (Trigger     │ ─────────────────▶  │  ┌────────────────────┐  │  │
│  │   Buttons)    │                     │  │ Behavior check     │  │  │
│  └───────────────┘                     │  │ (ignore/queue/     │  │  │
│                                        │  │  interrupt)        │  │  │
│  ┌───────────────┐                     │  └────────────────────┘  │  │
│  │ FPP Sequence  │    FPP Command      │  ┌────────────────────┐  │  │
│  │  / Schedule   │ ─────────────────▶  │  │  Cooldown check    │  │  │
│  └───────────────┘                     │  └────────────────────┘  │  │
│                                        │  ┌────────────────────┐  │  │
│  ┌───────────────┐                     │  │  Play count track  │  │  │
│  │  GPIO / SLED  │    Shell script     │  └────────────────────┘  │  │
│  │  Sensor Prop  │ ─────────────────▶  └────────────┬─────────────┘  │
│  └───────────────┘                                  │                │
│                                                     ▼                │
│  ┌─────────────────────────────────────────────────────────────────┐ │
│  │                  aa_duck_overlay_pulse.sh                       │ │
│  │                                                                 │ │
│  │  1. pactl list sink-inputs → capture active show audio IDs     │ │
│  │  2. Smooth fade DOWN (20 steps over fade_down seconds)         │ │
│  │  3. paplay / ffmpeg → play announcement on PulseAudio sink     │ │
│  │  4. Smooth fade UP  (20 steps over fade_up seconds)            │ │
│  │  5. EXIT trap → restore volumes on error/signal/stop           │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                │                                     │
│          ┌─────────────────────┴──────────────────────┐             │
│          ▼                                             ▼             │
│  ┌───────────────────┐                    ┌────────────────────┐    │
│  │  PulseAudio Sink  │                    │  aa_play_counts    │    │
│  │  (Show audio at   │                    │  .json             │    │
│  │   duck %)         │                    │  (today/total per  │    │
│  │  + Announcement   │                    │   slot)            │    │
│  │  (100% volume)    │                    └────────────────────┘    │
│  └───────────────────┘                                              │
│          │                                                          │
│          ▼                                                          │
│  ┌───────────────────┐                                              │
│  │  🔊 Your Speaker  │   Show audio + announcement mixed live      │
│  └───────────────────┘                                              │
└──────────────────────────────────────────────────────────────────────┘
```

**The key insight:** PulseAudio handles all the mixing. The show audio is already playing through PulseAudio. AA identifies the active sink inputs, fades their volume down, injects the announcement as a second stream, and fades the show back up — all while the show keeps running uninterrupted.

---

## 🎯 Real-World Use Cases

This is where AA gets *fun*. The trigger button in the FPP UI is just the beginning — because AA registers as an **FPP Command**, you can fire announcements from anywhere FPP can run a command:

### 🎅 Christmas Light Shows

| Scenario | How to Set It Up |
|---|---|
| **"Welcome to our show!"** | Schedule a trigger at show start via FPP's scheduler |
| **"Tune to 103.3 FM"** | Trigger periodically from an FPP sequence |
| **"Please stay on the sidewalk"** | Button in the FPP UI for when the crowd gets rowdy |
| **"Thank you for your donation!"** | Wire a donation box sensor → GPIO → FPP Command |
| **"Santa received your letter!"** | SLED plugin letter sensor → AA trigger via script |
| **"Show ends in 10 minutes"** | Schedule near end of show window |

### 🎃 Halloween Haunts

| Scenario | How to Set It Up |
|---|---|
| **"They're coming for you..."** | Motion sensor → trigger spooky voice overlay |
| **"Muwahaha!"** | Jump-scare trigger from prop controller |
| **Crowd atmosphere layer** | Low-duck background narration over music |

### 🎪 Interactive Props

| Scenario | How to Set It Up |
|---|---|
| **Donation box thank-you** | Beam break sensor → FPP GPIO trigger → AA Play command |
| **Mailbox letter confirmation** | SLED plugin triggers AA via callback script |
| **Button-activated greetings** | Physical push button on the yard → Pi GPIO → AA |
| **Remote triggers** | Home Assistant automation → FPP API → AA Command |

---

## 📋 Requirements

### Software
- **Falcon Player (FPP)** 8.0, 9.x, or 10.x+
- **PulseAudio** (installed automatically)
- **pulseaudio-utils** (installed automatically)
- **libasound2-plugins** (installed automatically)
- **ffmpeg** *(optional but recommended — enables all audio formats)*

### Hardware
- **Raspberry Pi** (Pi 3B, 4, or Zero 2W recommended)
  - Pi 3.5mm audio output works great
  - USB sound cards work great (tested with Sound Blaster Play! 3)
  - HDMI audio output works too
- **Speaker or amplifier** connected to audio output
- FPP must be playing show audio through PulseAudio for ducking to work

> **Note on USB Sound Cards:** AA works with USB audio devices. Make sure your USB sound card is set as the default PulseAudio sink in FPP's audio settings for ducking to target the correct output.

---

## 🚀 Installation

### Via FPP Plugin Manager (Recommended)

1. In FPP, go to **Content Setup → Plugin Manager**
2. Click **Available Plugins**
3. Find **"Announcement Assistant (Audio Ducking)"** in the list
4. Click **Install**
5. Watch the install log — it configures PulseAudio system-wide and validates the socket
6. When you see `Install complete`, navigate to **Announcement Assistant** in the left menu

### What the Installer Does

The installer (`fpp_install.sh`) does several important things automatically:

- Installs `pulseaudio`, `pulseaudio-utils`, `libasound2-plugins` via apt
- Configures PulseAudio to run **system-wide** (so it's available at boot, not per-user)
- Sets PulseAudio sample rate to **48kHz** for optimal quality (configurable)
- Creates `/home/fpp/.config/pulse/client.conf` so the `fpp` user connects to the system socket at `/run/pulse/native`
- Validates the PulseAudio socket exists and is functional
- Writes a default `announcementassistant.json` config if none exists

> ⚠️ **Important:** AA requires PulseAudio to be the active audio system on your Pi. If you're using a different audio output method, the install log will tell you what needs adjusting.

---

## ⚙️ Configuration

All settings live in:
```
/home/fpp/media/config/announcementassistant.json
```

### Global Settings

| Setting | Default | Description |
|---|---|---|
| `duck` | `25%` | Default duck level — show audio volume while announcement plays. Lower = more ducking. `15%` is very noticeable; `40%` is subtle. |
| `fade_down` | `0.5` | Seconds to fade the show audio *down* before the announcement starts |
| `fade_up` | `1.0` | Seconds to fade the show audio back *up* after the announcement ends |
| `behavior` | `ignore` | What to do if an announcement is already playing: `ignore`, `queue`, or `interrupt` |
| `cooldown` | `3.0` | Minimum seconds between triggers (applies to `ignore` mode) |

### Full Config Example

```json
{
  "duck": "25%",
  "fade_down": 0.5,
  "fade_up": 1.0,
  "behavior": "ignore",
  "cooldown": 3.0,
  "buttons": [
    {
      "label": "Welcome to the Show!",
      "file": "/home/fpp/media/music/announcements/welcome.mp3",
      "duck": "20%",
      "interrupt": false
    },
    {
      "label": "Tune to 103.3 FM",
      "file": "/home/fpp/media/music/announcements/tune_fm.mp3",
      "duck": "25%",
      "interrupt": false
    },
    {
      "label": "Thank You for Donating!",
      "file": "/home/fpp/media/music/announcements/donation_thanks.mp3",
      "duck": "15%",
      "interrupt": true
    },
    {
      "label": "Stay on the Sidewalk",
      "file": "/home/fpp/media/music/announcements/sidewalk.mp3",
      "duck": "30%",
      "interrupt": false
    },
    {
      "label": "Show Ending Soon",
      "file": "/home/fpp/media/music/announcements/ending.mp3",
      "duck": "20%",
      "interrupt": false
    },
    {
      "label": "Emergency: Clear the Area",
      "file": "/home/fpp/media/music/announcements/emergency.mp3",
      "duck": "5%",
      "interrupt": true
    }
  ],
  "telemetry": {
    "opt_in": true,
    "install_id": "your-uuid-here"
  }
}
```

---

## 🎰 The Six Announcement Slots

AA gives you **6 configurable announcement slots**, each fully independent:

```
┌────┬──────────────────────┬────────────────────────────────────┬────────┬──────────┬─────────────┐
│ #  │ Label                │ Audio File                         │ Duck % │ Priority │ Plays       │
├────┼──────────────────────┼────────────────────────────────────┼────────┼──────────┼─────────────┤
│ 1  │ Welcome to the Show! │ .../music/welcome.mp3              │  20%   │  normal  │ 12 today    │
│ 2  │ Tune to 103.3 FM     │ .../music/tune_fm.mp3              │  25%   │  normal  │  8 today    │
│ 3  │ Thank You Donation!  │ .../music/donation.mp3             │  15%   │ ⚡ HIGH  │  3 today    │
│ 4  │ Stay on Sidewalk     │ .../music/sidewalk.mp3             │  30%   │  normal  │  2 today    │
│ 5  │ Show Ending Soon     │ .../music/ending.mp3               │  20%   │  normal  │  1 today    │
│ 6  │ (empty)              │ —                                  │  25%   │  normal  │  0 today    │
└────┴──────────────────────┴────────────────────────────────────┴────────┴──────────┴─────────────┘
```

Each slot has:
- **Label** — friendly name shown in the UI and in the FPP Command slot picker
- **Audio File** — any WAV, MP3, OGG, FLAC, or M4A file on the Pi (auto-scanned from `/home/fpp/media/music`)
- **Duck %** — this slot's specific duck level, overriding the global default
- **Priority** ⚡ — if checked, this slot always interrupts whatever is playing (regardless of global behavior)
- **Test Button** — play the slot immediately for testing, right from the UI
- **Play Count** — today / lifetime plays, auto-resetting at midnight

---

## 🔉 Ducking & Fading Explained

"Ducking" is the technique of temporarily lowering background audio so a foreground sound is clear. Radio stations duck background music under voice-overs. News shows duck ambient sound under reporters. AA does this for your Christmas light show.

### How the Numbers Work

**Duck % = the show audio level WHILE the announcement plays.**

| Duck % | Effect |
|---|---|
| `5%` | Near-silent show audio — announcement dominates completely |
| `15%` | Heavy duck — show clearly in background, announcement very prominent |
| `25%` | Standard duck — noticeable reduction, clean mix *(default)* |
| `40%` | Light duck — subtle reduction, gentle overlay feel |
| `100%` | No ducking — show audio unchanged (announcement mixed in equally) |

### Fade Timeline

```
Volume
  100% ─────────────────╮              ╭────────────────────
                         ╲            ╱
   25% ─── show ──────────╲──────────╱── show ──────────────
                            ╲        ╱
    0%                       ╲──────╱  ← announcement plays here
                               ↑    ↑
                          fade_down  fade_up
                           (0.5s)   (1.0s)

         ◄── show playing ──►◄announcement►◄─── show playing ─►
```

- **`fade_down`** (default 0.5s): How long it takes to smoothly lower the show audio
- **`fade_up`** (default 1.0s): How long it takes to smoothly restore the show audio after the announcement ends

The fade uses **20 interpolation steps** for buttery-smooth transitions. No click, no pop, no jarring cut.

### Fail-Safe Volume Restore

If the announcement process is killed, interrupted, or crashes, an **EXIT trap** guarantees the show audio is restored to its original volume. You will never get stuck at 15% volume because something went wrong.

---

## 🚦 Behavior Modes

What happens when you trigger an announcement and one is *already playing?*

### `ignore` (Default — Recommended for most setups)
- If busy → **drop the trigger silently** and log it
- If within cooldown → **drop the trigger silently** and log it
- Best for: donation boxes, sensor props, automated triggers where you never want overlapping audio
- Cooldown timer prevents rapid re-fires from noisy sensors

### `queue`
- If busy → **wait up to 5 minutes** for the current announcement to finish, then play
- Best for: when you *really* need every trigger to play, just in order
- ⚠️ Long queues can pile up — use with care on frequently-fired triggers

### `interrupt`
- If busy → **immediately stop current playback** and restore show audio, then play the new announcement
- Best for: global setups where the newest announcement always wins

### Setting Behavior

In the FPP UI, use the **Behavior** dropdown in the global settings section. Or set it directly in the config JSON:
```json
"behavior": "ignore"   // or "queue" or "interrupt"
```

---

## ⚡ Per-Slot Priority (Interrupt)

The **Priority** checkbox (⚡) on each slot overrides the global behavior for that specific slot only.

When a slot has Priority enabled:
- It **always interrupts** whatever is currently playing
- It **ignores the global behavior** setting entirely
- Perfect for high-importance announcements that should always cut through

```
Scenario: Global behavior = "ignore", Slot 3 has Priority ⚡

Normal trigger (Slot 1) while busy   → DROPPED (ignore policy)
Priority trigger (Slot 3) while busy → INTERRUPTS current, plays immediately
```

Set it in the UI with the ⚡ checkbox, or in the config JSON:
```json
{
  "label": "Thank You for Donating!",
  "file": "/home/fpp/media/music/donation_thanks.mp3",
  "duck": "15%",
  "interrupt": true
}
```

---

## 🎮 FPP Commands

AA registers two commands in FPP's command system. These appear everywhere FPP accepts commands — sequences, scripts, GPIO triggers, schedules, and the FPP REST API.

### `Announcement Assistant - Play`

Plays a specific announcement slot with full ducking.

| Argument | Type | Description |
|---|---|---|
| `slot` | String (dropdown) | Which slot to play — auto-populated from your configured slot labels |

**Example uses:**
- In an FPP sequence: add an "Effect" step → choose "Announcement Assistant - Play" → pick the slot
- In a GPIO trigger: wire a button → "on press" → run this command with the desired slot
- Via REST API: `GET /api/command/Announcement%20Assistant%20-%20Play/Slot%201`

### `Announcement Assistant - Stop`

Immediately stops any playing announcement and fades the show audio back to full volume.

**Example uses:**
- Add to a playlist to ensure clean state at show start
- Assign to a "kill" button on your display board
- Trigger at end-of-show cleanup

---

## 🔌 Trigger from Sensors & Automations

Because AA uses FPP's command system, anything that can run an FPP command can trigger an announcement.

### GPIO Button / Beam Break Sensor

In FPP → GPIO Inputs:
1. Set pin, mode `gpio_pu` (pull-up, active-low)
2. On **falling** edge (trigger): command = `Announcement Assistant - Play`, arg = `Slot 2`

### From a Shell Script

```bash
# Play Slot 3 (index 2) from any script on the Pi
/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh \
  "/home/fpp/media/music/donation_thanks.mp3" "15%" "2"

# Or via FPP's REST API
curl "http://localhost/api/command/Announcement%20Assistant%20-%20Play/Slot%203"
```

### From SLED (Santa Mailbox Plugin)

Pair SLED with AA for the full experience — SLED plays a letter-received video while AA simultaneously plays a "Santa got your letter!" audio announcement over the show music.

### From Home Assistant

```yaml
# HA action — trigger an announcement via FPP REST API
action: rest_command.fpp_announcement
data:
  slot: "Slot%201"
```

```yaml
# Define in configuration.yaml
rest_command:
  fpp_announcement:
    url: "http://192.168.1.xxx/api/command/Announcement%20Assistant%20-%20Play/{{ slot }}"
    method: get
```

### Scheduled Triggers in FPP

In FPP's **Scheduler**, create time-based triggers:
- At **17:00** → FPP Command "Announcement Assistant - Play" → "Welcome to the Show!"
- Every **30 minutes** → "Tune to 103.3 FM"
- At **21:50** → "Show Ending in 10 Minutes"
- At **22:00** → "Announcement Assistant - Stop" + stop show playlist

---

## 📊 Play Count Tracking

AA tracks how many times each slot has been played — **today** and **lifetime total** — displayed right in the settings UI next to each slot.

```
Slot 1: Welcome to the Show!   ▓ 12 today  /  847 total
Slot 2: Tune to 103.3 FM       ▓  8 today  /  512 total
Slot 3: Thank You Donation!    ▓  3 today  /   47 total
```

- **Today counts** auto-reset at midnight
- **Lifetime totals** accumulate across the full season
- Stored in `/home/fpp/media/logs/aa_play_counts.json`
- Updates happen after every successful play

How many times did you need to ask visitors to stay off the grass this season? Now you'll know. 😄

---

## 🎵 Audio File Tips

### Supported Formats

| Format | Notes |
|---|---|
| `.wav` | Best — no decoding overhead, instant start |
| `.mp3` | Works great with ffmpeg installed |
| `.ogg` | Excellent quality/size ratio |
| `.flac` | Lossless — overkill but works |
| `.m4a` | Works with ffmpeg |

> **Install ffmpeg** for the best experience: `sudo apt install ffmpeg`. Without it, AA falls back to `paplay` which only natively handles WAV.

### Recording Tips

- Record at **48kHz, stereo** to match AA's PulseAudio configuration (no resampling needed)
- Use a **pop filter** and a quiet room — background hiss is very obvious during ducked playback
- Normalize your recordings to around **-3dB** so they're loud enough to cut through even at moderate duck levels
- Keep announcements **short and punchy** — under 10 seconds for triggered ones, 15–20 seconds max for scheduled ones
- Add a **brief music sting or jingle** at the start to signal "something is happening" before the voice kicks in

### Where to Put Files

Drop announcement files anywhere under:
```
/home/fpp/media/music/
```
AA scans this entire directory tree and populates the file picker dropdown. A tidy structure:
```
/home/fpp/media/music/
└── announcements/
    ├── welcome.mp3
    ├── tune_103fm.mp3
    ├── donation_thanks.mp3
    ├── sidewalk_warning.mp3
    └── show_ending.mp3
```

---

## 🔧 PulseAudio Setup Details

AA uses **system-wide PulseAudio** — a single PulseAudio daemon running as a system service, accessible by all users (including `fpp` and `root`) via a Unix socket at `/run/pulse/native`.

This is different from the default per-user PulseAudio that most Linux desktops use. System-wide mode is required because:
- FPP's audio playback runs as the `fpp` user
- AA's scripts may run as `root` (via FPP's plugin system)
- Both need to share the same PulseAudio instance for ducking to work

### What the Installer Configures

```
/etc/pulse/daemon.conf     ← sets default-sample-rate = 48000
/etc/pulse/system.pa       ← system-wide config (loads ALSA, sets socket)
/home/fpp/.config/pulse/client.conf  ← fpp user points to unix:/run/pulse/native
```

### Verifying PulseAudio is Working

```bash
# Check if socket exists
ls -la /run/pulse/native

# Check server info
PULSE_SERVER=unix:/run/pulse/native pactl info | grep -E "Server|Default Sink"

# List active sink inputs (should show FPP's audio while a show plays)
PULSE_SERVER=unix:/run/pulse/native pactl list short sink-inputs

# Test a manual announcement playback
PULSE_SERVER=unix:/run/pulse/native paplay /home/fpp/media/music/test.wav
```

### 48kHz Sample Rate

The installer sets PulseAudio's default sample rate to **48kHz** to match FPP's native output and avoid resampling artifacts. Pass `--no-48k` to the install script or manually edit `/etc/pulse/daemon.conf` if your hardware requires a different rate.

---

## 🔍 Troubleshooting

### Announcement plays but show audio isn't ducking

**Most likely cause:** FPP isn't routing audio through PulseAudio.

```bash
# Check what's active on the PulseAudio sink while the show plays
PULSE_SERVER=unix:/run/pulse/native pactl list short sink-inputs
# If this returns nothing, FPP is bypassing PulseAudio.
# Go to FPP → Settings → Audio and set PulseAudio / correct device as output.
```

---

### "Pulse socket missing" error in the log

PulseAudio isn't running or hasn't started yet.

```bash
systemctl status pulseaudio
sudo systemctl start pulseaudio
ls -la /run/pulse/native

# If it's not starting at boot, re-run the installer:
sudo bash /home/fpp/media/plugins/fpp-AnnouncementAssistant/fpp_install.sh
```

---

### Volumes stuck low after a stopped or crashed announcement

The EXIT trap handles this automatically, but if PulseAudio was restarted mid-play you can restore manually:

```bash
PULSE_SERVER=unix:/run/pulse/native pactl list short sink-inputs | awk '{print $1}' | \
  xargs -I{} pactl set-sink-input-volume {} 100%
```

---

### Trigger fires but nothing plays (silent drop)

Check the log first:
```bash
tail -50 /home/fpp/media/logs/AnnouncementAssistant.log
```

| Log Message | Cause | Fix |
|---|---|---|
| `BUSY: dropping trigger (policy=ignore)` | Another announcement is playing | Normal — wait, or change behavior to `queue` |
| `COOLDOWN: dropping trigger (2s elapsed, cooldown=3s)` | Triggered too fast | Normal — wait for cooldown, or lower `cooldown` value |
| `ERROR: File not found: /path/to/file.mp3` | File path in config is wrong | Check slot config in the UI |
| `ERROR: Pulse socket missing` | PulseAudio not running | Start PulseAudio (see above) |
| `ERROR: Could not determine Default Sink` | PulseAudio up but no output | Check audio output in FPP settings |

---

### FPP Command "Announcement Assistant - Play" doesn't appear

```bash
# Verify the command definition file exists and is valid
cat /home/fpp/media/plugins/fpp-AnnouncementAssistant/commands/descriptions.json | python3 -m json.tool

# Restart FPP to reload commands
sudo systemctl restart fppd
```

---

### Audio quality issues (crackling, pops, resampling artifacts)

```bash
# Verify 48kHz is configured
grep "default-sample-rate" /etc/pulse/daemon.conf

# Set it if missing
echo "default-sample-rate = 48000" | sudo tee -a /etc/pulse/daemon.conf
sudo systemctl restart pulseaudio
```

---

### USB sound card: ducking targets the wrong device

If you have multiple audio outputs, ensure PulseAudio's default sink matches FPP's audio output:

```bash
# List all sinks
PULSE_SERVER=unix:/run/pulse/native pactl list short sinks

# Set the correct one as default
PULSE_SERVER=unix:/run/pulse/native pactl set-default-sink <sink-name>
```

---

## 📁 File Reference

| File | Description |
|---|---|
| `fpp_install.sh` | Installer — configures system-wide PulseAudio, installs deps, creates default config |
| `scripts/aa_play.sh` | Main dispatcher — behavior/cooldown logic, play count tracking, telemetry |
| `scripts/aa_duck_overlay_pulse.sh` | Core engine — PulseAudio fade down/play/fade up with EXIT trap restore |
| `scripts/aa_stop.sh` | Stop wrapper — delegates to duck script's `--stop` handler |
| `scripts/aa_telemetry.py` | Anonymous opt-in telemetry (play counts, daily ping) |
| `commands/aa_cmd_play.sh` | FPP Command wrapper for Play |
| `commands/aa_cmd_stop.sh` | FPP Command wrapper for Stop |
| `commands/descriptions.json` | FPP Command definitions with slot picker `contentListUrl` |
| `www/index.php` | Plugin UI — slot config table, trigger buttons, play counts |
| `www/save.php` | Config save endpoint |
| `www/trigger.php` | AJAX trigger endpoint for UI test buttons |
| `www/stop.php` | AJAX stop endpoint |
| `www/slots.php` | Returns slot list for FPP Command argument picker |
| `/home/fpp/media/config/announcementassistant.json` | Runtime config (created on first save) |
| `/home/fpp/media/logs/AnnouncementAssistant.log` | Full play / duck / restore log |
| `/home/fpp/media/logs/aa_playing.lock` | PID lock file — present while announcement plays |
| `/home/fpp/media/logs/aa_cooldown.ts` | Last-trigger timestamp for cooldown enforcement |
| `/home/fpp/media/logs/aa_play_counts.json` | Per-slot today/total play counts |

---

## ❓ FAQ

**Q: Does the show audio actually keep playing during the announcement?**  
A: Yes — 100%. PulseAudio mixes both streams simultaneously. The show audio is never paused or stopped; its volume is just temporarily lowered.

**Q: What if FPP is playing a video with audio?**  
A: Same behavior. AA ducks whatever PulseAudio sink inputs are active, whether they're music files, video audio tracks, or anything else FPP outputs through PulseAudio.

**Q: Can I have different duck levels for different announcements?**  
A: Yes — each slot has its own duck % setting that overrides the global default. Your emergency announcement can duck to 5%, your welcome message can stay at 30%.

**Q: What audio formats work?**  
A: WAV works natively. MP3, OGG, FLAC, and M4A work with ffmpeg installed (`sudo apt install ffmpeg`). Highly recommended.

**Q: Can I use AA without a show playing?**  
A: Yes — if no show audio is active, AA just plays the announcement at full volume with no ducking needed. It handles "no sink inputs" gracefully.

**Q: Will this work with my USB sound card?**  
A: Yes, tested with Sound Blaster Play! 3 and similar USB audio devices. Make sure the USB card is FPP's selected audio output and PulseAudio's default sink.

**Q: How do I record good announcement audio?**  
A: A ~$30 USB microphone, a quiet room, and Audacity (free) is all you need. Record at 48kHz stereo, normalize to -3dB, export as WAV or MP3. Short and energetic beats long and rambling every time.

**Q: Can I trigger AA from Home Assistant without Pi GPIO?**  
A: Yes — use FPP's REST API. HA sends an HTTP GET to `http://<fpp-ip>/api/command/Announcement%20Assistant%20-%20Play/Slot%201`. No additional setup needed.

---

## 💛 Support the Project

If Announcement Assistant has made your show more magical, consider supporting development!

<a href="https://buymeacoffee.com/jm9pwtesct" target="_blank">
  <img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-☕-yellow?style=for-the-badge" alt="Buy Me a Coffee" />
</a>
&nbsp;
<a href="https://paypal.me/NScilingo" target="_blank">
  <img src="https://img.shields.io/badge/Donate-PayPal-blue?style=for-the-badge&logo=paypal" alt="Donate via PayPal" />
</a>

---

## 🤝 Contributing

Found a bug? Have a feature idea? PRs and issues are welcome!

1. Fork the repo
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit your changes
4. Open a pull request against `main`

Bug reports: [github.com/focusedonsound/fpp-AnnouncementAssistant/issues](https://github.com/focusedonsound/fpp-AnnouncementAssistant/issues)

---

## 📜 License

MIT License — see [LICENSE](LICENSE) for details.

Free for personal use. If you're building something commercial with this, please reach out.

---

## 🎉 Credits

**Author:** Nick Scilingo ([FocusedOnSound](https://github.com/focusedonsound))

Built with ❤️ for the Christmas lighting community — and for every show operator who's ever watched a visitor walk across their yard and wished they could say something about it without killing the vibe.

---

*Part of the [FocusedOnSound FPP Plugin Collection](https://github.com/focusedonsound):*
- 🎅 **[SLED Smart Letters to Santa](https://github.com/focusedonsound/fpp-sled-mailbox)** — sensor-driven Santa Mailbox with video playback, car counting, and Home Assistant integration
- 📺 **[HDMI CEC Control+](https://github.com/focusedonsound/fpp-hdmi-cec)** — power your TV or monitor on/off from FPP playlists and schedules
