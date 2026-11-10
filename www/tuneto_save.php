<?php
// Announcement Assistant - Tune To config save.
// Accepts the Tune To configuration as JSON (raw request body, or a
// "config" POST field) and atomically writes a normalized copy to
// /home/fpp/media/config/tuneto.json. Mirrors save.php's write pattern.

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/tuneto.json";

function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge([
    "status"  => $ok ? "OK" : "ERROR",
    "message" => $msg
  ], $extra));
  exit;
}

function clampInt($v, $min, $max, $default = 0) {
  if (!is_numeric($v)) return $default;
  $n = (int)$v;
  if ($n < $min) $n = $min;
  if ($n > $max) $n = $max;
  return $n;
}

function clampNum($v, $min, $max, $default) {
  if (!is_numeric($v)) return $default;
  $f = (float)$v;
  if ($f < $min) $f = $min;
  if ($f > $max) $f = $max;
  return round($f, 2);
}

function hexColor($v, $default = "#FFFFFF") {
  $v = trim((string)$v);
  if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $v)) {
    return '#' . strtoupper(ltrim($v, '#'));
  }
  return $default;
}

function pick($v, array $allowed, $default) {
  $v = trim((string)$v);
  return in_array($v, $allowed, true) ? $v : $default;
}

// ── Read incoming JSON ────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
  $raw = isset($_POST['config']) ? (string)$_POST['config'] : '';
}
$in = json_decode($raw, true);
if (!is_array($in)) {
  respond(false, "Invalid or empty config payload.");
}

// ── Normalize ─────────────────────────────────────────────────────────
$EFFECTS = ["static", "hscroll", "vscroll", "wipe", "shrink", "truncate"];
$ALIGNS  = ["left", "center", "right"];

$out = [];
$out["enabled"]       = !empty($in["enabled"]);
$out["overlay_model"] = trim((string)($in["overlay_model"] ?? ""));

// on/off
$onoff = is_array($in["onoff"] ?? null) ? $in["onoff"] : [];
$win   = is_array($onoff["window"] ?? null) ? $onoff["window"] : [];
$out["onoff"] = [
  "mode"            => pick($onoff["mode"] ?? "auto", ["on", "off", "auto"], "auto"),
  "while_playing"   => !empty($onoff["while_playing"]),
  "follow_schedule" => !empty($onoff["follow_schedule"]),
  "window" => [
    "enabled" => !empty($win["enabled"]),
    "start"   => preg_match('/^\d{1,2}:\d{2}$/', (string)($win["start"] ?? "")) ? (string)$win["start"] : "17:00",
    "end"     => preg_match('/^\d{1,2}:\d{2}$/', (string)($win["end"]   ?? "")) ? (string)$win["end"]   : "22:00",
  ],
];

// environment / weather
$env = is_array($in["environment"] ?? null) ? $in["environment"] : [];
$om  = is_array($env["open_meteo"] ?? null) ? $env["open_meteo"] : [];
$sen = is_array($env["sensor"] ?? null) ? $env["sensor"] : [];
$out["environment"] = [
  "source" => pick($env["source"] ?? "none", ["none", "open-meteo", "sensor"], "none"),
  "units"  => pick($env["units"] ?? "F", ["F", "C"], "F"),
  "open_meteo" => [
    "lat"         => clampNum($om["lat"] ?? 0, -90, 90, 0),
    "lon"         => clampNum($om["lon"] ?? 0, -180, 180, 0),
    "refresh_min" => clampInt($om["refresh_min"] ?? 10, 1, 1440, 10),
  ],
  "sensor" => [
    "type"        => pick($sen["type"] ?? "DHT22", ["DHT22", "DHT11", "BME280"], "DHT22"),
    "gpio"        => clampInt($sen["gpio"] ?? 4, 0, 40, 4),
    "i2c_addr"    => preg_match('/^0x[0-9A-Fa-f]{2}$/', (string)($sen["i2c_addr"] ?? "")) ? (string)$sen["i2c_addr"] : "0x76",
    "refresh_sec" => clampInt($sen["refresh_sec"] ?? 30, 2, 3600, 30),
  ],
];

// regions
$out["regions"] = [];
$regions = is_array($in["regions"] ?? null) ? $in["regions"] : [];
foreach ($regions as $r) {
  if (!is_array($r)) continue;
  $rect = is_array($r["rect"] ?? null) ? $r["rect"] : [];
  $region = [
    "name"  => trim((string)($r["name"] ?? "Region")) ?: "Region",
    "rect"  => [
      "x" => clampInt($rect["x"] ?? 0, 0, 10000, 0),
      "y" => clampInt($rect["y"] ?? 0, 0, 10000, 0),
      "w" => clampInt($rect["w"] ?? 64, 1, 10000, 64),
      "h" => clampInt($rect["h"] ?? 8, 1, 10000, 8),
    ],
    "font"  => trim((string)($r["font"] ?? "")),
    "size"  => clampInt($r["size"] ?? 10, 4, 400, 10),
    "color" => hexColor($r["color"] ?? "#FFFFFF"),
    "align" => pick($r["align"] ?? "center", $ALIGNS, "center"),
    "messages" => [],
  ];
  $msgs = is_array($r["messages"] ?? null) ? $r["messages"] : [];
  foreach ($msgs as $m) {
    if (!is_array($m)) continue;
    $region["messages"][] = [
      "template" => (string)($m["template"] ?? ""),
      "effect"   => pick($m["effect"] ?? "static", $EFFECTS, "static"),
      "speed"    => clampInt($m["speed"] ?? 20, 1, 200, 20),
      "dwell_sec"=> clampNum($m["dwell_sec"] ?? 5, 0.5, 600, 5),
    ];
  }
  $out["regions"][] = $region;
}

// fallback
$fb = is_array($in["fallback"] ?? null) ? $in["fallback"] : [];
$st = is_array($fb["showtimes"] ?? null) ? $fb["showtimes"] : [];
$out["fallback"] = [
  "mode"         => pick($fb["mode"] ?? "static_only",
                         ["static_only", "idle_message", "blank", "bank", "showtimes"], "static_only"),
  "idle_message" => (string)($fb["idle_message"] ?? ""),
  "showtimes"    => [
    "source" => pick($st["source"] ?? "fpp", ["fpp", "custom", "both"], "fpp"),
    "custom" => (string)($st["custom"] ?? ""),
  ],
];

// border (candy-cane edge)
$bd = is_array($in["border"] ?? null) ? $in["border"] : [];
$bcolors = is_array($bd["colors"] ?? null) ? array_values($bd["colors"]) : [];
$normColors = [];
foreach ($bcolors as $c) { $normColors[] = hexColor($c, "#CC0000"); }
if (count($normColors) < 2) $normColors = ["#CC0000", "#FFFFFF"];
$out["border"] = [
  "enabled" => !empty($bd["enabled"]),
  "width"   => clampInt($bd["width"] ?? 2, 1, 2, 2),
  "colors"  => array_slice($normColors, 0, 6),
  "stripe"  => clampInt($bd["stripe"] ?? 2, 1, 32, 2),
  "animate" => !empty($bd["animate"]),
  "speed"   => clampNum($bd["speed"] ?? 8, 0, 120, 8),
];

// show player host (FPP that runs the audio -> song/playback/schedule source)
$sh = trim((string)($in["show_host"] ?? "127.0.0.1"));
$out["show_host"] = ($sh !== "" && preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/', $sh)) ? $sh : "127.0.0.1";

// ── Atomic write ──────────────────────────────────────────────────────
$dir = dirname($configFile);
if (!is_dir($dir))      respond(false, "Config directory missing: $dir");
if (!is_writable($dir)) respond(false, "Config directory not writable: $dir");

$tmp  = $configFile . ".tmp";
$data = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) {
  respond(false, "Failed to write temp config: $tmp");
}
if (!@rename($tmp, $configFile)) {
  @unlink($tmp);
  respond(false, "Failed to replace config file: $configFile");
}

respond(true, "Tune To settings saved.");
