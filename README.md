# Fpp-AnnouncementAssistant
## AA — Announcement Assistant (Audio Ducking) for Falcon Player (FPP)

Turn your show into an **interactive experience**.

Announcement Assistant adds **instant, one-tap announcement buttons** to FPP — and it plays your pre-recorded messages **over the currently playing show audio** with **automatic ducking** (the show keeps running, the message is crystal clear). No awkward pauses. No “dead air.” Just clean overlays that sound like you planned it that way.

Perfect for the moments where you need to *say something* without stopping the magic:
- “Welcome to the show!”
- “Please keep volume down in the neighborhood.”
- “Kids, off the grass and away from the props.”
- “Tune to 103.3 FM for audio.”
- “Thanks for visiting — enjoy the lights!”

### Built for interactive shows (the fun part)
AA isn’t just buttons — it’s a building block for **event-driven audio**:
- **Donation box trigger:** Someone drops a donation → play a *thank you* announcement.
- **Mailbox / interaction prop:** A sensor trips → play a personalized “Letter received!” message.
- **Halloween scare zones:** Motion sensor hit → play a spooky overlay, laugh, or jump-scare line.
- **Queue / crowd control:** A button press (or automation) reminds visitors about traffic flow and safety.
- **Guest experience:** Rotate “welcome / safety / directions” messages throughout the night without stopping the playlist.

### Why it’s different
✅ **True overlay playback** — announcements mix over the show audio  
✅ **Automatic ducking** — show volume drops just enough for clarity  
✅ **Ignore-if-busy MVP** — prevents overlapping announcements (keeps things clean)  
✅ **Works great on Pi audio + common USB sound cards** (tested with Pi output and Sound Blaster Play! 3)

If you’ve ever wished your display could “talk” back to visitors — this is that.

## What’s Next (In Development)
We’re just getting started. Here’s what’s coming next:

### ✅ Matrix Text Overlay (Audio + Visual)
Alongside an audio announcement, AA will be able to **overlay a text message on an FPP Matrix/Model** (think: “THANK YOU!”, “TUNE TO 103.3 FM”, “PLEASE KEEP VOLUME DOWN”, “WELCOME!”).
Perfect for noisy nights, accessibility, and making announcements impossible to miss.

1) Trigger engine (Sensors / Events → Announcements)
2) Cooldown + rate limiting (per announcement)
3) Priority + interrupt modes
4) Per-announcement volume trim / normalize
5) Scheduling rules (built-in)
6) Metrics / counters (fun + useful) How many times did you have to tell people to turn down their radio
7) UI polish - Live status: shows “Currently playing: Slot 3 — 00:04 remaining”