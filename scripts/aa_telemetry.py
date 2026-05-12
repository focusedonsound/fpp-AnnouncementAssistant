#!/usr/bin/env python3
"""
aa_telemetry.py — Anonymous usage telemetry for Announcement Assistant.

Usage:
  aa_telemetry.py --ping
  aa_telemetry.py --event FILE RESULT DUCK_METHOD

All errors are silently swallowed — telemetry must never break playback.
"""
from __future__ import annotations
import json, os, sys, time, urllib.request, urllib.error

TELEMETRY_URL     = "https://sled-telemetry.nscilingo.workers.dev"
PLUGIN_NAME       = "fpp-AnnouncementAssistant"
PLUGIN_VERSION    = "1.0.0"
TELEMETRY_VERSION = 1
CONFIG_PATH       = "/home/fpp/media/config/announcementassistant.json"
LAST_PING_FILE    = "/home/fpp/media/logs/aa_telemetry_last_ping.txt"
PING_INTERVAL     = 86400

def _cfg():
    try:
        with open(CONFIG_PATH) as f: return json.load(f)
    except: return {}

def _opt_in(cfg): return bool(cfg.get("telemetry",{}).get("opt_in", True))
def _iid(cfg):    return str(cfg.get("telemetry",{}).get("install_id","")).strip()

def _pi_model():
    try:
        for line in open("/proc/cpuinfo"):
            if line.startswith("Model"): return line.split(":",1)[1].strip()
    except: pass
    return ""

def _fpp_ver():
    try:
        for line in open("/home/fpp/media/settings"):
            if line.strip().startswith("fppVersion"): return line.split("=",1)[1].strip()
    except: pass
    return ""

def _send(payload):
    try:
        data = json.dumps(payload).encode()
        req  = urllib.request.Request(TELEMETRY_URL, data=data,
               headers={"Content-Type":"application/json"}, method="POST")
        with urllib.request.urlopen(req, timeout=10) as r: return r.status==200
    except: return False

def _ping_due():
    try:
        if not os.path.exists(LAST_PING_FILE): return True
        return (time.time() - float(open(LAST_PING_FILE).read().strip())) >= PING_INTERVAL
    except: return True

def _mark_pinged():
    try: open(LAST_PING_FILE,"w").write(str(time.time()))
    except: pass

def cmd_ping():
    if not _ping_due(): return
    cfg = _cfg()
    if not _opt_in(cfg) or not _iid(cfg): return
    buttons    = cfg.get("buttons",[])
    configured = sum(1 for b in buttons if b.get("file","").strip())
    if _send({"install_id":_iid(cfg),"plugin":PLUGIN_NAME,"plugin_version":PLUGIN_VERSION,
              "fpp_version":_fpp_ver(),"pi_model":_pi_model(),
              "features":{"buttons_configured":configured},
              "event_counts":{},"uptime_days":0,"telemetry_version":TELEMETRY_VERSION}):
        _mark_pinged()

def cmd_event(file_path, play_result, duck_method):
    cfg = _cfg()
    if not _opt_in(cfg) or not _iid(cfg): return
    fname = os.path.basename(file_path) if file_path else ""
    fext  = os.path.splitext(fname)[1].lstrip(".").lower() if fname else ""
    fsize = 0
    if file_path and os.path.exists(file_path):
        try: fsize = os.path.getsize(file_path)//1024
        except: pass
    _send({"install_id":_iid(cfg),"plugin":PLUGIN_NAME,"plugin_version":PLUGIN_VERSION,
           "fpp_version":"","pi_model":"","features":{},"event_counts":{},
           "uptime_days":0,"telemetry_version":TELEMETRY_VERSION,
           "audio_event":{"file_name":fname,"file_ext":fext,"file_size_kb":fsize,
                          "play_result":play_result,"error_code":None,"duck_method":duck_method}})

if __name__=="__main__":
    try:
        if len(sys.argv)<2: sys.exit(0)
        if sys.argv[1]=="--ping": cmd_ping()
        elif sys.argv[1]=="--event":
            cmd_event(sys.argv[2] if len(sys.argv)>2 else "",
                      sys.argv[3] if len(sys.argv)>3 else "unknown",
                      sys.argv[4] if len(sys.argv)>4 else "pulseaudio")
    except: pass
