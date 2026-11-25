<?php
// Announcement Assistant - Tune To tab (Phase 0 scaffolding).
// Rendered as a tab pane included by index.php. Lets the user configure a
// data-driven FPP Pixel Overlay sign: regions, templated messages with
// {tokens}, weather/sensor source, on/off, and no-media fallback.
// Saves to /home/fpp/media/config/tuneto.json via tuneto_save.php.
// No rendering happens yet - that is the Phase 1 renderer daemon.

$ttConfigFile = "/home/fpp/media/config/tuneto.json";

function ttLoadConfig($path) {
  $defaults = [
    "enabled"       => false,
    "overlay_model" => "",
    "show_host"     => "127.0.0.1",
    "onoff" => [
      "mode" => "auto", "while_playing" => true, "follow_schedule" => true,
      "window" => ["enabled" => false, "start" => "17:00", "end" => "22:00"],
    ],
    "environment" => [
      "source" => "none", "units" => "F",
      "open_meteo" => ["lat" => 0, "lon" => 0, "refresh_min" => 10],
      "sensor" => ["type" => "DHT22", "gpio" => 4, "i2c_addr" => "0x76", "refresh_sec" => 30],
    ],
    "regions" => [
      [
        "name" => "Header",
        "rect" => ["x" => 0, "y" => 0, "w" => 64, "h" => 8],
        "font" => "", "size" => 8, "color" => "#FF0000", "align" => "center",
        "messages" => [
          ["template" => "TUNE TO 103.3 FM", "effect" => "static", "speed" => 20, "dwell_sec" => 6],
        ],
      ],
      [
        "name" => "Now Playing",
        "rect" => ["x" => 0, "y" => 8, "w" => 64, "h" => 24],
        "font" => "", "size" => 8, "color" => "#FFFFFF", "align" => "left",
        "messages" => [
          ["template" => "{title}",  "effect" => "hscroll", "speed" => 20, "dwell_sec" => 5],
          ["template" => "{artist}", "effect" => "hscroll", "speed" => 20, "dwell_sec" => 5],
        ],
      ],
    ],
    "fallback" => [
      "mode" => "static_only", "idle_message" => "Merry Christmas!",
      "showtimes" => ["source" => "fpp", "custom" => ""],
    ],
    "border" => [
      "enabled" => false, "width" => 2,
      "colors" => ["#CC0000", "#FFFFFF"],
      "stripe" => 2, "animate" => true, "speed" => 8,
    ],
    // Optional time-of-day layouts. When enabled, the sign shows whichever
    // day-part's hour window contains the current time (blank if none). Each
    // day-part carries its own full region set.
    "schedule" => [
      "enabled" => false,
      "dayparts" => [],   // [{ "name", "start", "end", "regions":[...] }]
    ],
  ];
  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) {
      $cfg = $defaults;
      foreach (["enabled", "overlay_model", "show_host"] as $k) {
        if (array_key_exists($k, $j)) $cfg[$k] = $j[$k];
      }
      // Keyed blocks merge recursively (forward-compatible with new subkeys)...
      foreach (["onoff", "environment", "fallback", "border"] as $k) {
        if (is_array($j[$k] ?? null)) $cfg[$k] = array_replace_recursive($cfg[$k], $j[$k]);
      }
      // colors is a list inside border: take saved verbatim if present.
      if (is_array($j["border"]["colors"] ?? null)) $cfg["border"]["colors"] = $j["border"]["colors"];
      // ...but regions is a list: take the saved one verbatim.
      if (is_array($j["regions"] ?? null)) $cfg["regions"] = $j["regions"];
      // schedule holds nested region lists per day-part: take it verbatim.
      if (is_array($j["schedule"] ?? null)) {
        $cfg["schedule"]["enabled"]  = !empty($j["schedule"]["enabled"]);
        $cfg["schedule"]["dayparts"] = is_array($j["schedule"]["dayparts"] ?? null)
          ? $j["schedule"]["dayparts"] : [];
      }
      return $cfg;
    }
  }
  return $defaults;
}

$ttCfg = ttLoadConfig($ttConfigFile);
?>

<style>
.tt-card { border:1px solid #3a3a3a; border-radius:.4rem; padding:12px 14px; margin-bottom:12px; background:rgba(255,255,255,.02); }
.tt-card h5 { margin:0 0 .6rem 0; font-size:1rem; }
.tt-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.5rem .75rem; }
.tt-field label { display:block; font-size:.78rem; color:#adb5bd; margin-bottom:2px; }
.tt-region { border:1px solid #444; border-left:4px solid #1a6eb5; border-radius:.4rem; padding:12px 14px; margin-bottom:12px; background:rgba(255,255,255,.02); }
.tt-region-head { display:flex; justify-content:space-between; align-items:center; gap:.5rem; margin-bottom:.6rem; }
.tt-msg { display:grid; grid-template-columns:1fr 130px 90px 90px 40px; gap:.4rem; align-items:end; margin-bottom:.4rem; }
.tt-msg label { display:block; font-size:.72rem; color:#adb5bd; margin-bottom:1px; }
.tt-tokens code { background:#222; color:#8fd; padding:1px 5px; border-radius:3px; font-size:.78rem; margin:0 2px; cursor:pointer; display:inline-block; }
.tt-muted { color:#adb5bd; font-size:.82rem; }
.tt-warn { color:#ffce3a; font-size:.82rem; }
.tt-warn i { margin-right:.3rem; }
@media (max-width:720px){ .tt-msg{ grid-template-columns:1fr 1fr; } }
</style>

<p class="tt-muted">
  Manage your "Tune To" matrix sign in one place instead of baking the text into every
  sequence. Pick an FPP <strong>Pixel Overlay Model</strong>, lay out <strong>regions</strong>,
  and fill each with <strong>messages</strong> that mix literal text and
  <code>{tokens}</code>. The <strong>Live Preview</strong> below updates as you edit.
</p>

<!-- ── Live Preview ─────────────────────────────────────────────────── -->
<div class="tt-card">
  <h5><i class="fas fa-fw fa-eye"></i> Live Preview
    <span class="tt-muted" style="font-weight:normal;">&mdash; reflects your current edits; clock / song / weather refresh automatically</span>
  </h5>
  <div style="margin-bottom:.5rem;">
    <label class="tt-muted" style="margin-right:.4rem;">Preview as</label>
    <select id="ttPreviewStyle" class="form-control form-control-sm" style="width:auto; display:inline-block;">
      <option value="led-round" selected>LED matrix, round dots &mdash; P5 / P10</option>
      <option value="led-square">LED matrix, square dots</option>
      <option value="solid">Solid pixels &mdash; TV / projector</option>
    </select>
    <span class="tt-muted" style="margin-left:.4rem; font-size:.85em;">changes this on-screen preview only &mdash; not the sign (the real output is the <strong>Overlay Model</strong> you pick in Sign settings)</span>
  </div>
  <div style="background:#000; border:1px solid #333; border-radius:.3rem; padding:8px; display:inline-block; max-width:100%;">
    <canvas id="ttPreviewCanvas" width="64" height="32"
            style="image-rendering:pixelated; image-rendering:crisp-edges; display:block; max-width:100%; height:auto;"></canvas>
  </div>
  <div id="ttPreviewNote" class="tt-muted" style="margin-top:.4rem;">Rendering preview&hellip;</div>
  <div class="tt-muted" style="margin-top:.2rem; font-size:.85em;">
    Tip: the bundled pixel fonts look sharpest at sizes 8, 16 or 24.
  </div>
</div>

<!-- ── Sign & On/Off ────────────────────────────────────────────────── -->
<div class="tt-card">
  <h5><i class="fas fa-fw fa-tv"></i> Sign &amp; Power</h5>
  <p class="tt-muted" style="font-size:.85em; margin-top:-.2rem;">
    <i class="fas fa-fw fa-lightbulb"></i> <strong>Best practice:</strong> install Announcement
    Assistant on the FPP controller that <em>drives the matrix</em> (the box with the panel
    output), so it paints the overlay locally. If your audio plays on a <em>separate</em> show
    player, set <strong>Show player</strong> below to that player so song details come from it.
    When the same device plays the audio, leave it on <em>This device</em>.
  </p>
  <div class="tt-grid">
    <div class="tt-field">
      <label>Master</label>
      <select id="ttEnabled" class="form-control form-control-sm">
        <option value="1">Enabled</option>
        <option value="0">Disabled (sign dark)</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Overlay Model</label>
      <select id="ttModel" class="form-control form-control-sm">
        <option value="">-- loading models --</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Show player (song source)</label>
      <select id="ttShowHost" class="form-control form-control-sm">
        <option value="127.0.0.1">This device (local)</option>
        <option value="__other__">Other&hellip; (enter host)</option>
      </select>
      <input type="text" id="ttShowHostManual" class="form-control form-control-sm"
             style="display:none; margin-top:.3rem;" placeholder="hostname or IP, e.g. 192.168.1.218">
    </div>
    <div class="tt-field">
      <label>When should it show?</label>
      <select id="ttMode" class="form-control form-control-sm">
        <option value="auto">Auto (any condition below)</option>
        <option value="on">Always on</option>
        <option value="off">Always off</option>
      </select>
    </div>
  </div>
  <div id="ttAutoConds" class="tt-grid" style="margin-top:.6rem;">
    <div class="tt-field">
      <label><input type="checkbox" id="ttWhilePlaying"> While FPP is playing</label>
      <label><input type="checkbox" id="ttFollowSchedule"> Inside an FPP scheduled show</label>
    </div>
    <div class="tt-field">
      <label><input type="checkbox" id="ttWindowEnabled"> Daily time window</label>
      <div class="input-group input-group-sm">
        <input type="time" id="ttWindowStart" class="form-control form-control-sm">
        <span class="input-group-text">to</span>
        <input type="time" id="ttWindowEnd" class="form-control form-control-sm">
      </div>
    </div>
  </div>
  <div class="tt-muted" style="margin-top:.4rem;">
    An on/off FPP Command is also registered so buttons, schedules, and Home Assistant can
    toggle the sign.
  </div>
</div>

<!-- ── Weather / Sensor ─────────────────────────────────────────────── -->
<div class="tt-card">
  <h5><i class="fas fa-fw fa-cloud-sun"></i> Temperature &amp; Weather</h5>
  <div class="tt-grid">
    <div class="tt-field">
      <label>Source</label>
      <select id="ttEnvSource" class="form-control form-control-sm">
        <option value="none">None</option>
        <option value="open-meteo">Open-Meteo (no API key)</option>
        <option value="sensor">Local sensor</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Units</label>
      <select id="ttUnits" class="form-control form-control-sm">
        <option value="F">&deg;F</option>
        <option value="C">&deg;C</option>
      </select>
    </div>
  </div>
  <div id="ttOpenMeteo" class="tt-grid" style="margin-top:.6rem; display:none;">
    <div class="tt-field"><label>Latitude</label><input type="number" step="0.0001" id="ttLat" class="form-control form-control-sm"></div>
    <div class="tt-field"><label>Longitude</label><input type="number" step="0.0001" id="ttLon" class="form-control form-control-sm"></div>
    <div class="tt-field"><label>Refresh (min)</label><input type="number" min="1" max="1440" id="ttRefreshMin" class="form-control form-control-sm"></div>
  </div>
  <div id="ttSensor" class="tt-grid" style="margin-top:.6rem; display:none;">
    <div class="tt-field">
      <label>Sensor type</label>
      <select id="ttSensorType" class="form-control form-control-sm">
        <option value="DHT22">DHT22</option>
        <option value="DHT11">DHT11</option>
        <option value="BME280">BME280 (I&sup2;C)</option>
      </select>
    </div>
    <div class="tt-field" id="ttSensorGpioWrap"><label>GPIO pin</label><input type="number" min="0" max="40" id="ttSensorGpio" class="form-control form-control-sm"></div>
    <div class="tt-field" id="ttSensorI2cWrap"><label>I&sup2;C address</label><input type="text" id="ttSensorI2c" class="form-control form-control-sm" placeholder="0x76"></div>
    <div class="tt-field"><label>Refresh (sec)</label><input type="number" min="2" max="3600" id="ttSensorRefresh" class="form-control form-control-sm"></div>
  </div>
</div>

<!-- ── Token reference ──────────────────────────────────────────────── -->
<div class="tt-card tt-tokens">
  <h5><i class="fas fa-fw fa-hashtag"></i> Available Tokens <span class="tt-muted">(click to copy)</span></h5>
  <div><strong class="tt-muted">Song:</strong>
    <code>{title}</code><code>{artist}</code><code>{album}</code><code>{year}</code><code>{genre}</code><code>{track}</code><code>{albumartist}</code><code>{comment}</code>
  </div>
  <div style="margin-top:.3rem;"><strong class="tt-muted">Playback:</strong>
    <code>{elapsed}</code><code>{remaining}</code><code>{duration}</code><code>{next_title}</code>
  </div>
  <div style="margin-top:.3rem;"><strong class="tt-muted">Clock:</strong>
    <code>{time}</code><code>{date}</code>
  </div>
  <div style="margin-top:.3rem;"><strong class="tt-muted">Custom date:</strong>
    <code>{MM/DD/YY}</code><code>{MM/DD/YYYY}</code><code>{MMMM D, YYYY}</code><code>{DDDD}</code><code>{MMM D}</code>
    <div class="tt-muted" style="margin-top:.25rem; font-size:.78rem; line-height:1.5;">
      Build any date format from these pieces (plus any separators you like):
      <code>YYYY</code>=2026 · <code>YY</code>=26 · <code>MMMM</code>=November · <code>MMM</code>=Nov ·
      <code>MM</code>=11 · <code>M</code>=11 · <code>DDDD</code>=Monday · <code>DDD</code>=Mon ·
      <code>DD</code>=09 · <code>D</code>=9. &nbsp;e.g. <code>{DDDD, MMMM D}</code> &rarr; Monday, November 23.
    </div>
  </div>
  <div style="margin-top:.3rem;"><strong class="tt-muted">Weather:</strong>
    <code>{temp}</code><code>{humidity}</code><code>{conditions}</code><code>{wind}</code><code>{high}</code><code>{low}</code><code>{pressure}</code>
  </div>
</div>

<!-- ── Schedules (time-of-day layouts) ──────────────────────────────── -->
<div class="tt-card">
  <h5><i class="fas fa-fw fa-clock"></i> Schedules
    <span class="tt-muted" style="font-weight:normal;">&mdash; show different messages by time of day</span></h5>
  <label><input type="checkbox" id="ttSchedEnabled"> Enable time-of-day scheduling</label>
  <div id="ttSchedBody" style="display:none; margin-top:.6rem;">
    <p class="tt-muted" style="margin-bottom:.5rem;">
      Define day-parts, each with its own hours and its own messages (for example
      <strong>Daytime</strong> and <strong>Nighttime</strong>). The sign shows whichever day-part's
      hours include the current time; edit each one's text below using the
      <strong>Editing layout</strong> picker. Outside every day-part's hours the sign is blank
      (the no-media fallback still applies). Hours may wrap past midnight (e.g. 22:00&ndash;02:00).
    </p>
    <div id="ttDayparts"></div>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ttAddDaypart()">
      <i class="fas fa-fw fa-plus"></i> Add day-part
    </button>
  </div>
</div>

<!-- ── Regions ──────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h4 class="mb-0"><i class="fas fa-fw fa-th-large"></i> Regions</h4>
    <small id="ttPanelInfo" class="tt-muted"></small>
    <div id="ttPanelWarn" class="tt-warn" style="display:none; margin-top:.2rem; max-width:720px;"></div>
  </div>
  <div class="d-flex align-items-center" style="gap:.4rem;">
    <select id="ttLayoutSel" class="form-control form-control-sm" style="display:none; width:auto;"
            title="Which day-part layout these regions belong to"></select>
    <button type="button" class="btn btn-sm btn-outline-secondary" title="Lay the regions out to fill the whole panel" onclick="ttFitAllRegions()">
      <i class="fas fa-fw fa-expand"></i> Fit to panel
    </button>
    <button type="button" class="btn btn-sm btn-secondary" onclick="ttAddRegion()">
      <i class="fas fa-fw fa-plus"></i> Add Region
    </button>
  </div>
</div>
<p class="tt-muted" style="font-size:.85em; margin-top:-.3rem;">
  A region's X/Y/Width/Height set <em>where</em> text appears on the panel. Text shows only
  inside its region &mdash; size regions to your panel (use <strong>Fit to panel</strong>) so
  text isn't stuck in a corner.
</p>
<div id="ttRegions"></div>

<!-- ── No-media fallback ────────────────────────────────────────────── -->
<div class="tt-card">
  <h5><i class="fas fa-fw fa-moon"></i> When no song is playing</h5>
  <div class="tt-grid">
    <div class="tt-field">
      <label>Fallback mode</label>
      <select id="ttFallbackMode" class="form-control form-control-sm">
        <option value="static_only">Static only (hide song tokens)</option>
        <option value="idle_message">Custom idle message</option>
        <option value="blank">Blank the sign</option>
        <option value="bank">Bank sign (rotate time + temp)</option>
        <option value="showtimes">Show the schedule</option>
      </select>
    </div>
    <div class="tt-field" id="ttIdleWrap">
      <label>Idle message</label>
      <input type="text" id="ttIdleMsg" class="form-control form-control-sm">
    </div>
  </div>
  <div id="ttShowtimes" class="tt-grid" style="margin-top:.6rem; display:none;">
    <div class="tt-field">
      <label>Showtimes source</label>
      <select id="ttShowtimesSrc" class="form-control form-control-sm">
        <option value="fpp">Auto-read FPP scheduler</option>
        <option value="custom">Custom text</option>
        <option value="both">Both (FPP + custom)</option>
      </select>
    </div>
    <div class="tt-field" style="grid-column:1/-1;">
      <label>Custom showtimes text</label>
      <input type="text" id="ttShowtimesCustom" class="form-control form-control-sm" placeholder="Shows nightly 5-10 PM">
    </div>
  </div>
</div>

<div class="tt-card">
  <h5><i class="fas fa-fw fa-border-style"></i> Border
    <span class="tt-muted" style="font-weight:normal;">&mdash; candy-cane edge around the whole sign</span>
  </h5>
  <div class="tt-grid">
    <div class="tt-field">
      <label>Border</label>
      <select id="ttBorderEnabled" class="form-control form-control-sm">
        <option value="0">Off</option>
        <option value="1">On</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Thickness</label>
      <select id="ttBorderWidth" class="form-control form-control-sm">
        <option value="1">1 pixel</option>
        <option value="2">2 pixels</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Color 1</label>
      <input type="color" id="ttBorderColor1" class="form-control form-control-sm" style="height:2.2rem;">
    </div>
    <div class="tt-field">
      <label>Color 2</label>
      <input type="color" id="ttBorderColor2" class="form-control form-control-sm" style="height:2.2rem;">
    </div>
    <div class="tt-field">
      <label>Stripe width (px)</label>
      <input type="number" id="ttBorderStripe" class="form-control form-control-sm" min="1" max="12" step="1">
    </div>
    <div class="tt-field">
      <label>Animate stripes</label>
      <select id="ttBorderAnimate" class="form-control form-control-sm">
        <option value="1">Yes (chase around edge)</option>
        <option value="0">No (hold still)</option>
      </select>
    </div>
    <div class="tt-field">
      <label>Speed (px/sec)</label>
      <input type="number" id="ttBorderSpeed" class="form-control form-control-sm" min="0" max="60" step="1">
    </div>
  </div>
  <div class="tt-muted" style="margin-top:.3rem; font-size:.85em;">
    Classic candy cane is deep red + white with a 2&#8209;pixel stripe. The border sits on the
    outermost pixels of the sign, so give regions a little margin from the edges.
  </div>
</div>

<div class="mb-4 d-flex gap-2 align-items-center">
  <button type="button" class="btn btn-primary" onclick="ttSave()">
    <i class="fas fa-fw fa-save"></i> Save Tune To Settings
  </button>
  <span class="tt-muted">Live preview and on-panel rendering arrive in later phases.</span>
</div>

<script>
(function () {
  "use strict";

  // Current config (from PHP) kept as the single source of truth.
  var ttConfig = <?php echo json_encode($ttCfg, JSON_UNESCAPED_SLASHES); ?>;
  var ttModels  = [];
  var ttFonts   = [];
  var ttSystems = [];

  // Which layout the region editor is currently editing: 'base' (the single
  // layout used when scheduling is off) or a day-part index (0-based) when
  // time-of-day scheduling is on. The region editor always reads/writes the
  // active layout's regions array via ttActiveRegions().
  if (!ttConfig.schedule) ttConfig.schedule = { enabled: false, dayparts: [] };
  if (!Array.isArray(ttConfig.schedule.dayparts)) ttConfig.schedule.dayparts = [];
  var ttLayout = (ttConfig.schedule.enabled && ttConfig.schedule.dayparts.length) ? 0 : 'base';

  function ttActiveRegions() {
    if (ttLayout !== 'base' && ttConfig.schedule.dayparts[ttLayout]) {
      var dp = ttConfig.schedule.dayparts[ttLayout];
      if (!Array.isArray(dp.regions)) dp.regions = [];
      return dp.regions;
    }
    if (!Array.isArray(ttConfig.regions)) ttConfig.regions = [];
    return ttConfig.regions;
  }
  function ttSetActiveRegions(arr) {
    if (ttLayout !== 'base' && ttConfig.schedule.dayparts[ttLayout]) {
      ttConfig.schedule.dayparts[ttLayout].regions = arr;
    } else {
      ttConfig.regions = arr;
    }
  }

  var TT_PLUGIN_BASE =
    (typeof pluginBase !== 'undefined' && pluginBase)
      ? pluginBase
      : 'plugin.php?plugin=fpp-AnnouncementAssistant&';
  var TT_BASE = TT_PLUGIN_BASE + 'nopage=1&page=www/';

  var EFFECTS = [
    ["static","Static"], ["hscroll","Scroll ←"], ["vscroll","Scroll ↑"],
    ["wipe","Wipe"], ["shrink","Shrink to fit"], ["truncate","Truncate"]
  ];

  function el(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
    return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[c]; }); }

  function ttNotify(msg, isError) {
    if (window.jQuery && jQuery.jGrowl) {
      jQuery.jGrowl(msg, { themeState: isError ? 'danger' : 'success' });
    } else { alert(msg); }
  }

  // ── Render regions from ttConfig ────────────────────────────────────
  function prettyFont(n) { return n.replace(/-Regular$/, '').replace(/-/g, ' '); }

  function fontOptions(selected) {
    var bundled = '', system = '';
    for (var i = 0; i < ttFonts.length; i++) {
      var n = ttFonts[i].name;
      var opt = '<option value="' + esc(n) + '"' + (n === selected ? ' selected' : '') +
                '>' + esc(prettyFont(n)) + '</option>';
      if (ttFonts[i].bundled) bundled += opt; else system += opt;
    }
    var html = '<option value="">(default — pixel font)</option>';
    if (bundled) html += '<optgroup label="Pixel fonts — best for panels">' + bundled + '</optgroup>';
    if (system)  html += '<optgroup label="System fonts">' + system + '</optgroup>';
    return html;
  }

  function effectOptions(selected) {
    var html = '';
    for (var i = 0; i < EFFECTS.length; i++) {
      html += '<option value="' + EFFECTS[i][0] + '"' + (EFFECTS[i][0] === selected ? ' selected' : '') + '>' + EFFECTS[i][1] + '</option>';
    }
    return html;
  }

  function msgRow(ri, mi, m) {
    return '' +
      '<div class="tt-msg" data-ri="' + ri + '" data-mi="' + mi + '">' +
        '<div><label>Template</label><input type="text" class="form-control form-control-sm tt-m-template" value="' + esc(m.template) + '"></div>' +
        '<div><label>Effect</label><select class="form-control form-control-sm tt-m-effect">' + effectOptions(m.effect) + '</select></div>' +
        '<div><label>Speed</label><input type="number" min="1" max="200" class="form-control form-control-sm tt-m-speed" value="' + esc(m.speed) + '"></div>' +
        '<div><label>Dwell (s)</label><input type="number" min="0.5" max="600" step="0.5" class="form-control form-control-sm tt-m-dwell" value="' + esc(m.dwell_sec) + '"></div>' +
        '<div><label>&nbsp;</label><button type="button" class="btn btn-sm btn-outline-danger" title="Remove message" onclick="ttDelMsg(' + ri + ',' + mi + ')"><i class="fas fa-fw fa-times"></i></button></div>' +
      '</div>';
  }

  function regionCard(ri, r) {
    var rect = r.rect || {};
    var msgs = '';
    var list = r.messages || [];
    for (var mi = 0; mi < list.length; mi++) msgs += msgRow(ri, mi, list[mi]);

    return '' +
    '<div class="tt-region" data-ri="' + ri + '">' +
      '<div class="tt-region-head">' +
        '<input type="text" class="form-control form-control-sm tt-r-name" style="max-width:240px;" value="' + esc(r.name) + '">' +
        '<span>' +
          '<button type="button" class="btn btn-sm btn-outline-secondary" style="margin-right:.4rem;" title="Set X=0 and width to the full panel" onclick="ttFitRegionWidth(' + ri + ')"><i class="fas fa-fw fa-arrows-alt-h"></i> Fit width</button>' +
          '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ttDelRegion(' + ri + ')"><i class="fas fa-fw fa-trash"></i> Remove Region</button>' +
        '</span>' +
      '</div>' +
      '<div class="tt-grid">' +
        '<div class="tt-field"><label>X</label><input type="number" min="0" class="form-control form-control-sm tt-r-x" value="' + esc(rect.x) + '"></div>' +
        '<div class="tt-field"><label>Y</label><input type="number" min="0" class="form-control form-control-sm tt-r-y" value="' + esc(rect.y) + '"></div>' +
        '<div class="tt-field"><label>Width</label><input type="number" min="1" class="form-control form-control-sm tt-r-w" value="' + esc(rect.w) + '"></div>' +
        '<div class="tt-field"><label>Height</label><input type="number" min="1" class="form-control form-control-sm tt-r-h" value="' + esc(rect.h) + '"></div>' +
        '<div class="tt-field"><label>Font</label><select class="form-control form-control-sm tt-r-font">' + fontOptions(r.font) + '</select></div>' +
        '<div class="tt-field"><label>Size</label><input type="number" min="4" max="400" class="form-control form-control-sm tt-r-size" value="' + esc(r.size) + '"></div>' +
        '<div class="tt-field"><label>Color</label><input type="color" class="form-control form-control-sm tt-r-color" value="' + esc(r.color) + '"></div>' +
        '<div class="tt-field"><label>Align</label><select class="form-control form-control-sm tt-r-align">' +
          ['left','center','right'].map(function (a) {
            return '<option value="' + a + '"' + (a === r.align ? ' selected' : '') + '>' + a + '</option>';
          }).join('') +
        '</select></div>' +
      '</div>' +
      '<div class="tt-r-warn tt-warn" style="display:none; margin-top:.35rem;"></div>' +
      '<div style="margin-top:.6rem;"><label class="tt-muted">Messages (rotate in order; empty-token messages are skipped)</label>' + msgs + '</div>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ttAddMsg(' + ri + ')"><i class="fas fa-fw fa-plus"></i> Add Message</button>' +
    '</div>';
  }

  function ttRenderRegions() {
    var host = el('ttRegions');
    var regs = ttActiveRegions();
    var html = '';
    for (var ri = 0; ri < regs.length; ri++) html += regionCard(ri, regs[ri]);
    if (!regs.length) html = '<p class="tt-muted">No regions yet. Click "Add Region" to begin.</p>';
    host.innerHTML = html;
    ttUpdateAllRegionWarns();
  }

  // A glyph is clipped to its region's height (the renderer draws text into an
  // image exactly rect.h tall), so a font size taller than the region never
  // shows — the classic "changing the size does nothing" trap.
  function ttUpdateRegionWarn(rel) {
    var warn = rel.querySelector('.tt-r-warn'); if (!warn) return;
    var size = parseInt((rel.querySelector('.tt-r-size') || {}).value, 10) || 0;
    var h    = parseInt((rel.querySelector('.tt-r-h')    || {}).value, 10) || 0;
    if (size > 0 && h > 0 && size > h) {
      warn.style.display = '';
      warn.innerHTML = '<i class="fas fa-fw fa-exclamation-triangle"></i>Font size <strong>' +
        size + '</strong> is taller than this region (<strong>' + h + ' px</strong>), so it will be ' +
        'clipped and changing the size won\'t show. Raise <em>Height</em> or lower <em>Size</em> ' +
        '(pixel fonts are sharpest at 8, 16 or 24).';
    } else {
      warn.style.display = 'none';
      warn.innerHTML = '';
    }
  }

  function ttUpdateAllRegionWarns() {
    document.querySelectorAll('#ttRegions .tt-region').forEach(ttUpdateRegionWarn);
  }

  // ── Sync DOM back into ttConfig (before any re-render or save) ───────
  function ttSyncFromDom() {
    ttConfig.enabled = el('ttEnabled').value === '1';
    ttConfig.overlay_model = el('ttModel').value;
    ttConfig.show_host = ttShowHostValue();

    ttConfig.onoff.mode = el('ttMode').value;
    ttConfig.onoff.while_playing = el('ttWhilePlaying').checked;
    ttConfig.onoff.follow_schedule = el('ttFollowSchedule').checked;
    ttConfig.onoff.window.enabled = el('ttWindowEnabled').checked;
    ttConfig.onoff.window.start = el('ttWindowStart').value || '17:00';
    ttConfig.onoff.window.end = el('ttWindowEnd').value || '22:00';

    ttConfig.environment.source = el('ttEnvSource').value;
    ttConfig.environment.units = el('ttUnits').value;
    ttConfig.environment.open_meteo.lat = parseFloat(el('ttLat').value) || 0;
    ttConfig.environment.open_meteo.lon = parseFloat(el('ttLon').value) || 0;
    ttConfig.environment.open_meteo.refresh_min = parseInt(el('ttRefreshMin').value, 10) || 10;
    ttConfig.environment.sensor.type = el('ttSensorType').value;
    ttConfig.environment.sensor.gpio = parseInt(el('ttSensorGpio').value, 10) || 4;
    ttConfig.environment.sensor.i2c_addr = el('ttSensorI2c').value || '0x76';
    ttConfig.environment.sensor.refresh_sec = parseInt(el('ttSensorRefresh').value, 10) || 30;

    var regionEls = document.querySelectorAll('#ttRegions .tt-region');
    var regions = [];
    regionEls.forEach(function (rel) {
      var q = function (sel) { return rel.querySelector(sel); };
      var msgs = [];
      rel.querySelectorAll('.tt-msg').forEach(function (mel) {
        msgs.push({
          template: mel.querySelector('.tt-m-template').value,
          effect:   mel.querySelector('.tt-m-effect').value,
          speed:    parseInt(mel.querySelector('.tt-m-speed').value, 10) || 20,
          dwell_sec: parseFloat(mel.querySelector('.tt-m-dwell').value) || 5
        });
      });
      regions.push({
        name:  q('.tt-r-name').value || 'Region',
        rect:  {
          x: parseInt(q('.tt-r-x').value, 10) || 0,
          y: parseInt(q('.tt-r-y').value, 10) || 0,
          w: parseInt(q('.tt-r-w').value, 10) || 64,
          h: parseInt(q('.tt-r-h').value, 10) || 8
        },
        font:  q('.tt-r-font').value,
        size:  parseInt(q('.tt-r-size').value, 10) || 10,
        color: q('.tt-r-color').value || '#FFFFFF',
        align: q('.tt-r-align').value,
        messages: msgs
      });
    });
    ttSetActiveRegions(regions);

    // Day-part meta (hours/names) from the schedule manager; regions on each
    // day-part are preserved (edited via the active-layout region editor).
    ttConfig.schedule.enabled = el('ttSchedEnabled').checked;
    document.querySelectorAll('#ttDayparts .tt-dp').forEach(function (del) {
      var dpi = parseInt(del.getAttribute('data-dpi'), 10);
      var dp = ttConfig.schedule.dayparts[dpi];
      if (!dp) return;
      dp.name  = del.querySelector('.tt-dp-name').value || ('Part ' + (dpi + 1));
      dp.start = del.querySelector('.tt-dp-start').value || '00:00';
      dp.end   = del.querySelector('.tt-dp-end').value || '00:00';
    });

    ttConfig.fallback.mode = el('ttFallbackMode').value;
    ttConfig.fallback.idle_message = el('ttIdleMsg').value;
    ttConfig.fallback.showtimes.source = el('ttShowtimesSrc').value;
    ttConfig.fallback.showtimes.custom = el('ttShowtimesCustom').value;

    ttConfig.border.enabled = el('ttBorderEnabled').value === '1';
    ttConfig.border.width   = parseInt(el('ttBorderWidth').value, 10) || 1;
    ttConfig.border.colors  = [el('ttBorderColor1').value || '#CC0000',
                               el('ttBorderColor2').value || '#FFFFFF'];
    ttConfig.border.stripe  = parseInt(el('ttBorderStripe').value, 10) || 2;
    ttConfig.border.animate = el('ttBorderAnimate').value === '1';
    ttConfig.border.speed   = parseInt(el('ttBorderSpeed').value, 10) || 0;
  }

  // ── Panel geometry helpers ──────────────────────────────────────────
  function ttPanel() {
    var name = ttConfig.overlay_model, w = 0, h = 0;
    for (var i = 0; i < ttModels.length; i++) {
      if (ttModels[i].name === name) { w = ttModels[i].width || 0; h = ttModels[i].height || 0; }
    }
    return { name: name, w: w, h: h };
  }

  function ttBorderInset() {
    var b = ttConfig.border || {};
    return b.enabled ? Math.max(1, Math.min(2, parseInt(b.width, 10) || 1)) : 0;
  }

  function ttUpdatePanelInfo() {
    var e = el('ttPanelInfo'); if (!e) return;
    var p = ttPanel();
    if (p.name && p.w && p.h) e.textContent = 'Panel: ' + p.name + ' — ' + p.w + ' × ' + p.h + ' px';
    else if (p.name)          e.textContent = 'Panel: ' + p.name + ' (size unknown — is the model valid?)';
    else                      e.textContent = 'No overlay model selected — pick one in Sign settings above.';
    ttUpdatePanelWarn(p);
  }

  // Flag an overlay model whose reported shape looks like a raw channel strip
  // rather than a real 2D panel — the classic symptom of a hand-built "Channel"
  // Pixel Overlay Model, or a Custom xLights model whose geometry was never
  // pushed to FPP. Both leave the sign painting onto the wrong shape.
  function ttUpdatePanelWarn(p) {
    var w = el('ttPanelWarn'); if (!w) return;
    p = p || ttPanel();
    var suspect = p.name && p.w && p.h &&
                  (p.h <= 8 || (p.h < 16 && (p.w / p.h) >= 16));
    if (!suspect) { w.style.display = 'none'; w.innerHTML = ''; return; }
    w.style.display = '';
    w.innerHTML =
      '<i class="fas fa-fw fa-exclamation-triangle"></i>This model reports <strong>' +
      p.w + '×' + p.h + '</strong>, which looks like a flat channel strip, not a 2D panel. ' +
      'If your sign is actually taller, FPP has the wrong geometry — usually a hand-made ' +
      '<em>Channel</em> overlay, or a <em>Custom</em> xLights model whose layout was never sent to FPP. ' +
      'Fix: in xLights run <strong>Tools → FPP Connect</strong> and upload <strong>Models</strong> to this ' +
      'controller, then pick the auto-created model here (and delete the hand-made one).';
  }

  window.ttFitRegionWidth = function (ri) {
    ttSyncFromDom();
    var p = ttPanel();
    if (!p.w) { ttNotify('Pick a valid overlay model first', true); return; }
    var ins = ttBorderInset();
    var regs = ttActiveRegions();
    regs[ri].rect.x = ins;
    regs[ri].rect.w = Math.max(1, p.w - 2 * ins);
    ttRenderRegions();
  };

  window.ttFitAllRegions = function () {
    ttSyncFromDom();
    var p = ttPanel();
    if (!p.w || !p.h) { ttNotify('Pick a valid overlay model first', true); return; }
    var rs = ttActiveRegions(), n = rs.length;
    if (!n) { ttNotify('Add a region first', true); return; }
    var ins = ttBorderInset();
    var top = ins, usableH = Math.max(1, p.h - 2 * ins);
    var band = Math.max(1, Math.floor(usableH / n));
    for (var i = 0; i < n; i++) {
      rs[i].rect.x = ins;
      rs[i].rect.w = Math.max(1, p.w - 2 * ins);
      rs[i].rect.y = top + i * band;
      rs[i].rect.h = (i === n - 1) ? (top + usableH - (top + i * band)) : band;
    }
    ttRenderRegions();
    ttNotify('Regions fitted to ' + p.w + '×' + p.h + (ins ? ' (inset ' + ins + 'px for border)' : ''), false);
  };

  // ── Region / message mutations (exposed to inline onclick) ──────────
  window.ttAddRegion = function () {
    ttSyncFromDom();
    var p = ttPanel();
    var ins = ttBorderInset();
    var w = p.w ? Math.max(1, p.w - 2 * ins) : 64;
    // Stack the new region directly below the existing ones.
    var y = ins;
    var regs = ttActiveRegions();
    for (var i = 0; i < regs.length; i++) {
      var rr = regs[i].rect || {};
      y = Math.max(y, (rr.y || 0) + (rr.h || 0));
    }
    regs.push({
      name: 'Region ' + (regs.length + 1),
      rect: { x: ins, y: y, w: w, h: 10 },
      font: '', size: 8, color: '#FFFFFF', align: 'center',
      messages: [{ template: '', effect: 'static', speed: 20, dwell_sec: 5 }]
    });
    ttRenderRegions();
  };
  window.ttDelRegion = function (ri) {
    ttSyncFromDom(); ttActiveRegions().splice(ri, 1); ttRenderRegions();
  };
  window.ttAddMsg = function (ri) {
    ttSyncFromDom();
    ttActiveRegions()[ri].messages.push({ template: '', effect: 'static', speed: 20, dwell_sec: 5 });
    ttRenderRegions();
  };
  window.ttDelMsg = function (ri, mi) {
    ttSyncFromDom(); ttActiveRegions()[ri].messages.splice(mi, 1); ttRenderRegions();
  };

  // ── Time-of-day schedules (day-parts) ───────────────────────────────
  function ttDaypartRow(i, dp) {
    return '' +
      '<div class="tt-dp" data-dpi="' + i + '" style="display:grid;grid-template-columns:1fr 120px 120px 40px;gap:.4rem;align-items:end;margin-bottom:.4rem;">' +
        '<div><label class="tt-muted">Name</label><input type="text" class="form-control form-control-sm tt-dp-name" value="' + esc(dp.name || ('Part ' + (i + 1))) + '"></div>' +
        '<div><label class="tt-muted">From</label><input type="time" class="form-control form-control-sm tt-dp-start" value="' + esc(dp.start || '08:00') + '"></div>' +
        '<div><label class="tt-muted">To</label><input type="time" class="form-control form-control-sm tt-dp-end" value="' + esc(dp.end || '17:00') + '"></div>' +
        '<div><label>&nbsp;</label><button type="button" class="btn btn-sm btn-outline-danger" title="Remove day-part" onclick="ttDelDaypart(' + i + ')"><i class="fas fa-fw fa-times"></i></button></div>' +
      '</div>';
  }
  function ttRenderDayparts() {
    var host = el('ttDayparts'); if (!host) return;
    var dps = ttConfig.schedule.dayparts, html = '';
    for (var i = 0; i < dps.length; i++) html += ttDaypartRow(i, dps[i]);
    if (!dps.length) html = '<p class="tt-muted">No day-parts yet. Click "Add day-part" to begin.</p>';
    host.innerHTML = html;
  }
  function ttRenderLayoutSel() {
    var sel = el('ttLayoutSel'); if (!sel) return;
    var on = ttConfig.schedule.enabled;
    sel.style.display = on ? '' : 'none';
    if (!on) { ttLayout = 'base'; return; }
    var dps = ttConfig.schedule.dayparts, html = '';
    for (var i = 0; i < dps.length; i++)
      html += '<option value="' + i + '">Editing: ' + esc(dps[i].name || ('Part ' + (i + 1))) + '</option>';
    sel.innerHTML = html;
    if (ttLayout === 'base' || ttLayout >= dps.length) ttLayout = dps.length ? 0 : 'base';
    if (ttLayout !== 'base') sel.value = String(ttLayout);
  }
  window.ttAddDaypart = function () {
    ttSyncFromDom();
    var dps = ttConfig.schedule.dayparts;
    dps.push({ name: 'Part ' + (dps.length + 1), start: '08:00', end: '17:00', regions: [] });
    ttRenderDayparts(); ttRenderLayoutSel();
  };
  window.ttDelDaypart = function (i) {
    ttSyncFromDom();
    ttConfig.schedule.dayparts.splice(i, 1);
    if (ttLayout !== 'base') {
      if (ttLayout === i) ttLayout = ttConfig.schedule.dayparts.length ? 0 : 'base';
      else if (ttLayout > i) ttLayout -= 1;
    }
    ttRenderDayparts(); ttRenderLayoutSel(); ttRenderRegions();
  };
  function ttSelectLayout(which) {
    ttSyncFromDom();
    ttLayout = (which === 'base') ? 'base' : parseInt(which, 10);
    ttRenderRegions();
  }
  function ttToggleSchedule() {
    ttSyncFromDom();
    var on = el('ttSchedEnabled').checked;
    ttConfig.schedule.enabled = on;
    if (on && !ttConfig.schedule.dayparts.length) {
      // Seed Daytime + Nighttime from the current single layout so the user
      // starts from what they already built, then tweaks each one.
      var baseRegions = Array.isArray(ttConfig.regions) ? ttConfig.regions : [];
      var clone = function () { return JSON.parse(JSON.stringify(baseRegions)); };
      ttConfig.schedule.dayparts = [
        { name: 'Daytime',   start: '08:00', end: '17:00', regions: clone() },
        { name: 'Nighttime', start: '17:00', end: '23:00', regions: clone() }
      ];
    }
    ttLayout = on ? 0 : 'base';
    el('ttSchedBody').style.display = on ? '' : 'none';
    ttRenderDayparts(); ttRenderLayoutSel(); ttRenderRegions();
  }

  // ── Conditional section visibility ──────────────────────────────────
  function ttUpdateVisibility() {
    el('ttAutoConds').style.display = (el('ttMode').value === 'auto') ? '' : 'none';
    var src = el('ttEnvSource').value;
    el('ttOpenMeteo').style.display = (src === 'open-meteo') ? '' : 'none';
    el('ttSensor').style.display = (src === 'sensor') ? '' : 'none';
    var isBme = el('ttSensorType').value === 'BME280';
    el('ttSensorGpioWrap').style.display = isBme ? 'none' : '';
    el('ttSensorI2cWrap').style.display = isBme ? '' : 'none';
    var fb = el('ttFallbackMode').value;
    el('ttIdleWrap').style.display = (fb === 'idle_message') ? '' : 'none';
    el('ttShowtimes').style.display = (fb === 'showtimes') ? '' : 'none';
  }

  // ── Show-player host picker ─────────────────────────────────────────
  function ttFillShowHost() {
    var sel = el('ttShowHost');
    var html = '<option value="127.0.0.1">This device (local)</option>';
    for (var i = 0; i < ttSystems.length; i++) {
      var s = ttSystems[i];
      if (!s.address || s.address === '127.0.0.1') continue;
      var label = s.hostname + ' (' + s.address + ')' + (s.mode ? ' — ' + s.mode : '');
      html += '<option value="' + esc(s.address) + '">' + esc(label) + '</option>';
    }
    html += '<option value="__other__">Other&hellip; (enter host)</option>';
    sel.innerHTML = html;
    ttApplyShowHost();
  }

  function ttApplyShowHost() {
    var sel = el('ttShowHost'), man = el('ttShowHostManual');
    var cur = ttConfig.show_host || '127.0.0.1';
    var found = false;
    for (var i = 0; i < sel.options.length; i++) {
      if (sel.options[i].value === cur) { found = true; break; }
    }
    if (found && cur !== '__other__') { sel.value = cur; man.style.display = 'none'; man.value = ''; }
    else { sel.value = '__other__'; man.style.display = ''; man.value = (cur === '__other__' ? '' : cur); }
  }

  function ttShowHostValue() {
    var sel = el('ttShowHost');
    if (sel.value === '__other__') return (el('ttShowHostManual').value || '127.0.0.1').trim() || '127.0.0.1';
    return sel.value || '127.0.0.1';
  }

  // ── Populate static controls from ttConfig ──────────────────────────
  function ttFillControls() {
    el('ttEnabled').value = ttConfig.enabled ? '1' : '0';
    ttFillShowHost();
    el('ttMode').value = ttConfig.onoff.mode;
    el('ttWhilePlaying').checked = !!ttConfig.onoff.while_playing;
    el('ttFollowSchedule').checked = !!ttConfig.onoff.follow_schedule;
    el('ttWindowEnabled').checked = !!ttConfig.onoff.window.enabled;
    el('ttWindowStart').value = ttConfig.onoff.window.start;
    el('ttWindowEnd').value = ttConfig.onoff.window.end;

    el('ttEnvSource').value = ttConfig.environment.source;
    el('ttUnits').value = ttConfig.environment.units;
    el('ttLat').value = ttConfig.environment.open_meteo.lat;
    el('ttLon').value = ttConfig.environment.open_meteo.lon;
    el('ttRefreshMin').value = ttConfig.environment.open_meteo.refresh_min;
    el('ttSensorType').value = ttConfig.environment.sensor.type;
    el('ttSensorGpio').value = ttConfig.environment.sensor.gpio;
    el('ttSensorI2c').value = ttConfig.environment.sensor.i2c_addr;
    el('ttSensorRefresh').value = ttConfig.environment.sensor.refresh_sec;

    el('ttFallbackMode').value = ttConfig.fallback.mode;
    el('ttIdleMsg').value = ttConfig.fallback.idle_message;
    el('ttShowtimesSrc').value = ttConfig.fallback.showtimes.source;
    el('ttShowtimesCustom').value = ttConfig.fallback.showtimes.custom;

    var bd = ttConfig.border || {};
    var bc = bd.colors || [];
    el('ttBorderEnabled').value = bd.enabled ? '1' : '0';
    el('ttBorderWidth').value   = String(bd.width || 2);
    el('ttBorderColor1').value  = bc[0] || '#CC0000';
    el('ttBorderColor2').value  = bc[1] || '#FFFFFF';
    el('ttBorderStripe').value  = (bd.stripe != null ? bd.stripe : 2);
    el('ttBorderAnimate').value = bd.animate ? '1' : '0';
    el('ttBorderSpeed').value   = (bd.speed != null ? bd.speed : 8);

    el('ttSchedEnabled').checked = !!ttConfig.schedule.enabled;
    el('ttSchedBody').style.display = ttConfig.schedule.enabled ? '' : 'none';
    ttRenderDayparts();
    ttRenderLayoutSel();
  }

  function ttFillModelSelect() {
    var sel = el('ttModel');
    var cur = ttConfig.overlay_model;
    var html = '<option value="">-- select an overlay model --</option>';
    var found = false;
    for (var i = 0; i < ttModels.length; i++) {
      var m = ttModels[i];
      var dims = (m.width && m.height) ? ' (' + m.width + '×' + m.height + ')' : '';
      if (m.name === cur) found = true;
      html += '<option value="' + esc(m.name) + '"' + (m.name === cur ? ' selected' : '') + '>' + esc(m.name) + dims + '</option>';
    }
    // Preserve a saved model name even if FPP didn't return it (e.g. API down).
    if (cur && !found) html += '<option value="' + esc(cur) + '" selected>' + esc(cur) + ' (not found)</option>';
    sel.innerHTML = html;
  }

  // ── Save ────────────────────────────────────────────────────────────
  window.ttSave = function () {
    ttSyncFromDom();
    fetch(TT_BASE + 'tuneto_save.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(ttConfig),
      cache: 'no-store'
    }).then(function (res) { return res.text(); })
      .then(function (text) {
        var j; try { j = JSON.parse(text); }
        catch (e) { j = { status: 'ERROR', message: 'Non-JSON response: ' + text.slice(0, 200) }; }
        ttNotify(j.message || (j.status === 'OK' ? 'Saved.' : 'Save failed.'), j.status !== 'OK');
      })
      .catch(function (e) { ttNotify('Save request failed: ' + e, true); });
  };

  // ── Live preview ────────────────────────────────────────────────────
  var ttPreviewBusy = false;

  function ttTabVisible() {
    var pane = document.getElementById('aaTabTuneTo');
    return pane && pane.style.display !== 'none' && !document.hidden;
  }

  // ── Animated LED preview ────────────────────────────────────────────
  // The endpoint returns a sprite sheet of N frames stacked vertically. We read
  // its pixels once, then play the frames on the canvas as the chosen display
  // (round / square LEDs, or solid pixels), looping. Re-fetched periodically so
  // the clock, song, weather and current edits stay live.
  var PV_FRAMES = 45, PV_FPS = 15;
  var ttOffCanvas = null;
  var ttAnim = null;              // { data, fw, fh, n }
  var ttFrameI = 0, ttLastTs = 0;

  function ttDrawFrame() {
    if (!ttAnim) return;
    var fw = ttAnim.fw, fh = ttAnim.fh, data = ttAnim.data;
    var style = (el('ttPreviewStyle') && el('ttPreviewStyle').value) || 'led-round';
    var cell = Math.max(3, Math.min(14, Math.floor(620 / fw)));
    var cv = el('ttPreviewCanvas');
    if (cv.width !== fw * cell) {
      cv.width = fw * cell; cv.height = fh * cell;
      cv.style.width = Math.min(fw * cell, 640) + 'px';
    }
    var g = cv.getContext('2d');
    var base = ttFrameI * fh;
    var solid = (style === 'solid'), round = (style === 'led-round');
    g.fillStyle = solid ? '#000' : '#0b0b0b';
    g.fillRect(0, 0, cv.width, cv.height);
    var r = cell * 0.42, pad = Math.max(1, Math.round(cell * 0.12));
    for (var y = 0; y < fh; y++) {
      for (var x = 0; x < fw; x++) {
        var i = (((base + y) * fw) + x) * 4;
        var R = data[i], G = data[i + 1], B = data[i + 2];
        var cx = x * cell, cy = y * cell;
        if (solid) {
          g.fillStyle = 'rgb(' + R + ',' + G + ',' + B + ')';
          g.fillRect(cx, cy, cell, cell);
        } else {
          g.fillStyle = (R + G + B) >= 24 ? ('rgb(' + R + ',' + G + ',' + B + ')') : '#161616';
          if (round) { g.beginPath(); g.arc(cx + cell / 2, cy + cell / 2, r, 0, 6.2832); g.fill(); }
          else { g.fillRect(cx + pad, cy + pad, cell - 2 * pad, cell - 2 * pad); }
        }
      }
    }
  }

  function ttAnimTick(ts) {
    if (ttAnim && ttAnim.n > 1 && ttTabVisible()) {
      if (!ttLastTs) ttLastTs = ts;
      if (ts - ttLastTs >= 1000 / (ttAnim.fps || PV_FPS)) {
        ttFrameI = (ttFrameI + 1) % ttAnim.n;
        ttLastTs = ts;
        ttDrawFrame();
      }
    }
    requestAnimationFrame(ttAnimTick);
  }

  function ttRenderPreview() {
    if (ttPreviewBusy || !ttTabVisible()) return;
    ttPreviewBusy = true;
    ttSyncFromDom();
    // Preview the layout currently being edited, regardless of the clock: send
    // the active layout's regions with scheduling disabled so the renderer paints
    // exactly what's on screen (not whichever day-part happens to match now).
    var pv = Object.assign({}, ttConfig, { schedule: { enabled: false }, regions: ttActiveRegions() });
    var hdr = {};
    fetch(TT_BASE + 'tuneto_preview.php&frames=' + PV_FRAMES + '&fps=' + PV_FPS, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(pv),
      cache: 'no-store'
    }).then(function (res) {
      var ct = res.headers.get('Content-Type') || '';
      hdr.n   = parseInt(res.headers.get('X-TT-Frames') || '1', 10) || 1;
      hdr.fw  = parseInt(res.headers.get('X-TT-FW') || '0', 10) || 0;
      hdr.fh  = parseInt(res.headers.get('X-TT-FH') || '0', 10) || 0;
      hdr.fps = parseFloat(res.headers.get('X-TT-FPS') || '') || PV_FPS;
      if (ct.indexOf('image') === 0) return res.blob();
      return res.text().then(function (t) { throw new Error(t); });
    }).then(function (blob) {
      var url = URL.createObjectURL(blob);
      var im = new Image();
      im.onload = function () {
        var n  = hdr.n || 1;
        var fw = hdr.fw || im.width;
        var fh = hdr.fh || Math.round(im.height / n);
        var off = ttOffCanvas || (ttOffCanvas = document.createElement('canvas'));
        off.width = im.width; off.height = im.height;
        var og = off.getContext('2d');
        og.clearRect(0, 0, im.width, im.height);
        og.drawImage(im, 0, 0);
        var data = null;
        try { data = og.getImageData(0, 0, im.width, im.height).data; } catch (e) { data = null; }
        if (data) {
          ttAnim = { data: data, fw: fw, fh: fh, n: n, fps: hdr.fps || PV_FPS };
          if (ttFrameI >= n) ttFrameI = 0;
          ttDrawFrame();
          el('ttPreviewNote').textContent =
            fw + '×' + fh + ' — live preview' + (n > 1 ? ' (animated)' : '');
        }
        URL.revokeObjectURL(url);
        ttPreviewBusy = false;
      };
      im.onerror = function () { URL.revokeObjectURL(url); ttPreviewBusy = false; };
      im.src = url;
    }).catch(function (e) {
      el('ttPreviewNote').textContent = 'Preview unavailable: ' + String(e.message || e).slice(0, 180);
      ttPreviewBusy = false;
    });
  }

  (function () {
    var sel = el('ttPreviewStyle');
    if (sel) sel.addEventListener('change', ttDrawFrame);
  })();

  requestAnimationFrame(ttAnimTick);
  setInterval(ttRenderPreview, 3000);
  ttRenderPreview();

  // ── Token click-to-copy ─────────────────────────────────────────────
  document.querySelectorAll('.tt-tokens code').forEach(function (c) {
    c.addEventListener('click', function () {
      var t = c.textContent;
      if (navigator.clipboard) navigator.clipboard.writeText(t);
      ttNotify('Copied ' + t, false);
    });
  });

  // ── Wire change handlers for conditional visibility ─────────────────
  ['ttMode','ttEnvSource','ttSensorType','ttFallbackMode'].forEach(function (id) {
    el(id).addEventListener('change', ttUpdateVisibility);
  });
  // Schedule: toggle day-part scheduling, and switch which layout is edited.
  el('ttSchedEnabled').addEventListener('change', ttToggleSchedule);
  el('ttLayoutSel').addEventListener('change', function () { ttSelectLayout(el('ttLayoutSel').value); });
  // Keep the panel-size readout current when the overlay model changes.
  el('ttModel').addEventListener('change', function () {
    ttConfig.overlay_model = el('ttModel').value;
    ttUpdatePanelInfo();
  });
  // Live-update the per-region "font taller than region" hint as Size/Height change.
  el('ttRegions').addEventListener('input', function (ev) {
    if (!ev.target) return;
    if (ev.target.classList.contains('tt-r-size') || ev.target.classList.contains('tt-r-h')) {
      var rel = ev.target.closest('.tt-region');
      if (rel) ttUpdateRegionWarn(rel);
    }
  });
  // Reveal the manual host box only when "Other…" is chosen.
  el('ttShowHost').addEventListener('change', function () {
    var man = el('ttShowHostManual');
    if (el('ttShowHost').value === '__other__') { man.style.display = ''; man.focus(); }
    else { man.style.display = 'none'; }
  });
  // Border on/off or thickness changes the fit inset — refresh the readout.
  ['ttBorderEnabled','ttBorderWidth'].forEach(function (id) {
    var e = el(id); if (e) e.addEventListener('change', function () { ttSyncFromDom(); ttUpdatePanelInfo(); });
  });

  // ── Init ────────────────────────────────────────────────────────────
  ttFillControls();
  ttFillModelSelect();
  ttRenderRegions();
  ttUpdateVisibility();
  ttUpdatePanelInfo();

  // Load overlay models + fonts, then refresh the selects that depend on them.
  fetch(TT_BASE + 'tuneto_models.php', { cache: 'no-store' })
    .then(function (res) { return res.json(); })
    .then(function (j) {
      if (j && j.status === 'OK') {
        ttSyncFromDom();   // capture current edits before repopulating selects
        ttModels  = j.models  || [];
        ttFonts   = j.fonts   || [];
        ttSystems = j.systems || [];
        ttFillModelSelect();
        ttFillShowHost();  // add discovered players to the picker
        ttUpdatePanelInfo(); // now that model dims are known
        ttRenderRegions(); // re-render so font dropdowns populate
      }
    })
    .catch(function () { /* leave placeholders; model name is still preserved */ });
})();
</script>
