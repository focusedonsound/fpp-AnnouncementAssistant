<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/announcementassistant.json";

function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge([
    "status" => $ok ? "OK" : "ERROR",
    "message" => $msg
  ], $extra));
  exit;
}

function parseDuck($v, $fallback = "25%") {
  $v = trim((string)$v);
  if ($v === "") $v = $fallback;
  $v = rtrim($v, "%");
  if ($v === "" || !is_numeric($v)) $v = rtrim($fallback, "%");
  $n = (int)$v;
  if ($n < 0) $n = 0;
  if ($n > 100) $n = 100;
  return $n . "%";
}

$dir = dirname($configFile);
if (!is_dir($dir)) {
  respond(false, "Config directory missing: $dir");
}
if (!is_writable($dir)) {
  respond(false, "Config directory not writable: $dir");
}

$cfg = ["duck"=>"25%","buttons"=>[]];
if (file_exists($configFile)) {
  $j = json_decode(@file_get_contents($configFile), true);
  if (is_array($j)) $cfg = array_merge($cfg, $j);
}

$defaultDuck = isset($_POST["duck_default"]) ? parseDuck($_POST["duck_default"], ($cfg["duck"] ?? "25%")) : ($cfg["duck"] ?? "25%");
$cfg["duck"] = $defaultDuck;

$buttons = [];
for ($i=0; $i<6; $i++) {
  $label = trim((string)($_POST["label_$i"] ?? ("Announcement ".($i+1))));
  if ($label === "") $label = "Announcement ".($i+1);

  $file  = trim((string)($_POST["file_$i"] ?? ""));
  $duck  = parseDuck($_POST["duck_$i"] ?? "", $defaultDuck);

  $buttons[] = [
    "label" => $label,
    "file"  => $file,
    "duck"  => $duck
  ];
}

$cfg["buttons"] = $buttons;

// Atomic write
$tmp = $configFile . ".tmp";
$data = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($tmp, $data) === false) {
  respond(false, "Failed to write temp config: $tmp");
}
if (!@rename($tmp, $configFile)) {
  @unlink($tmp);
  respond(false, "Failed to replace config file: $configFile");
}

respond(true, "Saved.");
