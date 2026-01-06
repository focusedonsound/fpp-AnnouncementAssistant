<?php
$configFile = "/home/fpp/media/config/announcementassistant.json";

function sanitizeDuck($duck) {
  $duck = trim((string)$duck);
  if ($duck === "") return "25%";

  // allow "25" or "25%" -> normalize
  if (preg_match('/^([0-9]{1,3})%?$/', $duck, $m)) {
    $n = intval($m[1]);
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n . "%";
  }

  return "25%";
}

$buttons = [];
$defaultDuck = "25%";

for ($i=0; $i<6; $i++) {
  $label = isset($_POST["label_$i"]) ? trim($_POST["label_$i"]) : ("Announcement ".($i+1));
  $file  = isset($_POST["file_$i"])  ? trim($_POST["file_$i"])  : "";
  $duck  = sanitizeDuck($_POST["duck_$i"] ?? "25%");

  if ($i === 0) $defaultDuck = $duck;

  // Validate file if set: must exist under /home/fpp/media/music
  if ($file !== "") {
    $full = "/home/fpp/media/music/" . $file;
    if (!file_exists($full)) {
      http_response_code(400);
      echo "ERROR: File not found: " . htmlspecialchars($file);
      exit;
    }
  }

  $buttons[] = [
    "label" => $label,
    "file"  => $file,
    "duck"  => $duck
  ];
}

$cfg = [
  // Keep legacy keys for backward compatibility (older installs / scripts)
  "duck"        => $defaultDuck,
  "duckDefault" => $defaultDuck,
  "buttons"     => $buttons
];

$tmp = $configFile . ".tmp";
$ok = @file_put_contents($tmp, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($ok === false) {
  http_response_code(500);
  echo "ERROR: Failed to write temp config.";
  exit;
}

@rename($tmp, $configFile);
echo "OK: Saved.";
