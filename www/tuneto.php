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
  ];
  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) {
      $cfg = $defaults;
      foreach (["enabled", "overlay_model"] as $k) {
        if (array_key_exists($k, $j)) $cfg[$k] = $j[$k];
      }
      // Keyed blocks merge recursively (forward-compatible with new subkeys)...
      foreach (["onoff", "environment", "fallback"] as $k) {
        if (is_array($j[$k] ?? null)) $cfg[$k] = array_replace_recursive($cfg[$k], $j[$k]);
      }
      // ...but regions is a list: take the saved one verbatim.
      if (is_array($j["regions"] ?? null)) $cfg["regions"] = $j["regions"];
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
    <label class="tt-muted" style="margin-right:.4rem;">Display</label>
    <select id="ttPreviewStyle" class="form-control form-control-sm" style="width:auto; display:inline-block;">
      <option value="led-round" selected>LED matrix (round) &mdash; P5 / P10</option>
      <option value="led-square">LED matrix (square)</option>
      <option value="solid">Solid pixels &mdash; TV / projector</option>
    </select>
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
  <div style="margin-top:.3rem;"><strong class="tt-muted">Weather:</strong>
    <code>{temp}</code><code>{humidity}</code><code>{conditions}</code><code>{wind}</code><code>{high}</code><code>{low}</code><code>{pressure}</code>
  </div>
</div>

<!-- ── Regions ──────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-2">
  <h4 class="mb-0"><i class="fas fa-fw fa-th-large"></i> Regions</h4>
  <button type="button" class="btn btn-sm btn-secondary" onclick="ttAddRegion()">
    <i class="fas fa-fw fa-plus"></i> Add Region
  </button>
</div>
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
  var ttModels = [];
  var ttFonts  = [];

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
        '<button type="button" class="btn btn-sm btn-outline-danger" onclick="ttDelRegion(' + ri + ')"><i class="fas fa-fw fa-trash"></i> Remove Region</button>' +
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
      '<div style="margin-top:.6rem;"><label class="tt-muted">Messages (rotate in order; empty-token messages are skipped)</label>' + msgs + '</div>' +
      '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="ttAddMsg(' + ri + ')"><i class="fas fa-fw fa-plus"></i> Add Message</button>' +
    '</div>';
  }

  function ttRenderRegions() {
    var host = el('ttRegions');
    var html = '';
    for (var ri = 0; ri < ttConfig.regions.length; ri++) html += regionCard(ri, ttConfig.regions[ri]);
    if (!ttConfig.regions.length) html = '<p class="tt-muted">No regions yet. Click "Add Region" to begin.</p>';
    host.innerHTML = html;
  }

  // ── Sync DOM back into ttConfig (before any re-render or save) ───────
  function ttSyncFromDom() {
    ttConfig.enabled = el('ttEnabled').value === '1';
    ttConfig.overlay_model = el('ttModel').value;

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
    ttConfig.regions = regions;

    ttConfig.fallback.mode = el('ttFallbackMode').value;
    ttConfig.fallback.idle_message = el('ttIdleMsg').value;
    ttConfig.fallback.showtimes.source = el('ttShowtimesSrc').value;
    ttConfig.fallback.showtimes.custom = el('ttShowtimesCustom').value;
  }

  // ── Region / message mutations (exposed to inline onclick) ──────────
  window.ttAddRegion = function () {
    ttSyncFromDom();
    ttConfig.regions.push({
      name: 'Region ' + (ttConfig.regions.length + 1),
      rect: { x: 0, y: 0, w: 64, h: 8 },
      font: '', size: 10, color: '#FFFFFF', align: 'center',
      messages: [{ template: '', effect: 'static', speed: 20, dwell_sec: 5 }]
    });
    ttRenderRegions();
  };
  window.ttDelRegion = function (ri) {
    ttSyncFromDom(); ttConfig.regions.splice(ri, 1); ttRenderRegions();
  };
  window.ttAddMsg = function (ri) {
    ttSyncFromDom();
    ttConfig.regions[ri].messages.push({ template: '', effect: 'static', speed: 20, dwell_sec: 5 });
    ttRenderRegions();
  };
  window.ttDelMsg = function (ri, mi) {
    ttSyncFromDom(); ttConfig.regions[ri].messages.splice(mi, 1); ttRenderRegions();
  };

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

  // ── Populate static controls from ttConfig ──────────────────────────
  function ttFillControls() {
    el('ttEnabled').value = ttConfig.enabled ? '1' : '0';
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

  var ttOffCanvas = null;   // offscreen buffer to sample the rendered pixels
  var ttLastImg = null;     // last preview frame, so style changes redraw instantly

  // Draw the rendered frame as the chosen display simulates it: a grid of round
  // or square LEDs (P5/P10 panels) with dark gaps, or solid pixels (TV/projector).
  function ttDrawPreview(im) {
    var pw = im.width, ph = im.height;
    if (!pw || !ph) return;
    var off = ttOffCanvas || (ttOffCanvas = document.createElement('canvas'));
    off.width = pw; off.height = ph;
    var og = off.getContext('2d');
    og.clearRect(0, 0, pw, ph);
    og.drawImage(im, 0, 0);
    var data = null;
    try { data = og.getImageData(0, 0, pw, ph).data; } catch (e) { data = null; }

    var style = (el('ttPreviewStyle') && el('ttPreviewStyle').value) || 'led-round';
    var cell = Math.max(3, Math.min(14, Math.floor(620 / pw)));
    var cv = el('ttPreviewCanvas');
    cv.width = pw * cell; cv.height = ph * cell;
    cv.style.width = Math.min(pw * cell, 640) + 'px';
    var g = cv.getContext('2d');

    if (!data || style === 'solid') {
      g.imageSmoothingEnabled = false;
      g.fillStyle = '#000'; g.fillRect(0, 0, cv.width, cv.height);
      g.drawImage(im, 0, 0, pw, ph, 0, 0, cv.width, cv.height);
    } else {
      var round = (style !== 'led-square');
      g.fillStyle = '#0b0b0b'; g.fillRect(0, 0, cv.width, cv.height);
      var r = cell * 0.42;
      var pad = Math.max(1, Math.round(cell * 0.12));
      for (var y = 0; y < ph; y++) {
        for (var x = 0; x < pw; x++) {
          var i = (y * pw + x) * 4, R = data[i], G = data[i + 1], B = data[i + 2];
          g.fillStyle = (R + G + B) >= 24 ? ('rgb(' + R + ',' + G + ',' + B + ')') : '#161616';
          var cx = x * cell, cy = y * cell;
          if (round) {
            g.beginPath(); g.arc(cx + cell / 2, cy + cell / 2, r, 0, 6.2832); g.fill();
          } else {
            g.fillRect(cx + pad, cy + pad, cell - 2 * pad, cell - 2 * pad);
          }
        }
      }
    }
    el('ttPreviewNote').textContent = pw + '×' + ph + ' — live preview';
  }

  function ttRenderPreview() {
    if (ttPreviewBusy || !ttTabVisible()) return;
    ttPreviewBusy = true;
    ttSyncFromDom();
    fetch(TT_BASE + 'tuneto_preview.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(ttConfig),
      cache: 'no-store'
    }).then(function (res) {
      var ct = res.headers.get('Content-Type') || '';
      if (ct.indexOf('image') === 0) return res.blob();
      return res.text().then(function (t) { throw new Error(t); });
    }).then(function (blob) {
      var url = URL.createObjectURL(blob);
      var im = new Image();
      im.onload = function () {
        ttLastImg = im;
        ttDrawPreview(im);
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
    if (sel) sel.addEventListener('change', function () { if (ttLastImg) ttDrawPreview(ttLastImg); });
  })();

  setInterval(ttRenderPreview, 1500);
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

  // ── Init ────────────────────────────────────────────────────────────
  ttFillControls();
  ttFillModelSelect();
  ttRenderRegions();
  ttUpdateVisibility();

  // Load overlay models + fonts, then refresh the selects that depend on them.
  fetch(TT_BASE + 'tuneto_models.php', { cache: 'no-store' })
    .then(function (res) { return res.json(); })
    .then(function (j) {
      if (j && j.status === 'OK') {
        ttModels = j.models || [];
        ttFonts  = j.fonts || [];
        ttFillModelSelect();
        ttSyncFromDom();   // capture current edits
        ttRenderRegions(); // re-render so font dropdowns populate
      }
    })
    .catch(function () { /* leave placeholders; model name is still preserved */ });
})();
</script>
