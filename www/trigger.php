<?php
// Trigger play (slot) OR stop currently playing announcement.
// Called by index.php via fetch().

$configFile = "/home/fpp/media/config/announcementassistant.json";
$playScript = "/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh";

function loadCfg($path) {
  if (!file_exists($path)) return [];
  $j = json_decode(@file_get_contents($path), true);
  return is_array($j) ? $j : [];
}

function sanitizeDuck($duck) {
  $duck = trim((string)$duck);
  if ($duck === "") return "25%";
  if (preg_match('/^([0-9]{1,3})%?$/', $duck, $m)) {
    $n = intval($m[1]);
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n . "%";
  }
  return "25%";
}

// Stop request
$action = $_GET["action"] ?? "";
if ($action === "stop") {
  $cmd = sprintf('bash %s --stop >/dev/null 2>&1', escapeshellarg($playScript));
  exec($cmd);
  echo "OK: Stop sent.";
  exit;
}

$slot = isset($_GET["slot"]) ? intval($_GET["slot"]) : -1;
if ($slot < 0) {
  http_response_code(400);
  echo "ERROR: Missing slot.";
  exit;
}

$cfg = loadCfg($configFile);
$buttons = $cfg["buttons"] ?? [];
$duckDefault = $cfg["duckDefault"] ?? ($cfg["duck"] ?? "25%");
$duckDefault = sanitizeDuck($duckDefault);

if (!isset($buttons[$slot])) {
  http_response_code(400);
  echo "ERROR: Invalid slot.";
  exit;
}

$btn = $buttons[$slot];
$fileRel = isset($btn["file"]) ? trim($btn["file"]) : "";
if ($fileRel === "") {
  http_response_code(400);
  echo "ERROR: No file set for slot.";
  exit;
}

$duck = sanitizeDuck($btn["duck"] ?? $duckDefault);

$ann = "/home/fpp/media/music/" . $fileRel;
if (!file_exists($ann)) {
  http_response_code(400);
  echo "ERROR: File missing: " . htmlspecialchars($fileRel);
  exit;
}

$cmd = sprintf(
  'bash %s %s %s >/dev/null 2>&1 &',
  escapeshellarg($playScript),
  escapeshellarg($ann),
  escapeshellarg($duck)
);

exec($cmd);
echo "OK: Triggered slot " . ($slot + 1) . " (duck " . htmlspecialchars($duck) . ")";
