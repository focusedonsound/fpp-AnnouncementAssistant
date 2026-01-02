<?php
header('Content-Type: application/json');

$configFile = "/home/fpp/media/config/announcementassistant.json";
$musicRoot  = "/home/fpp/media/music";

function jsonOut($status, $message) {
  echo json_encode(["status" => $status, "message" => $message]);
  exit;
}

function ensureDir($dir) {
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
}

function sanitizeDuck($duck) {
  $duck = trim((string)$duck);
  // Accept "25" or "25%" and normalize to "25%"
  if (preg_match('/^(\d{1,3})\s*%?$/', $duck, $m)) {
    $n = intval($m[1]);
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n . "%";
  }
  return "25%";
}

function isAudioFile($path) {
  return (bool)preg_match('/\.(wav|mp3|ogg|flac|m4a)$/i', $path);
}

function normalizeMusicPath($musicRoot, $value) {
  $value = trim((string)$value);
  if ($value === "") return "";

  // Allow either absolute paths or "music/..." paths, normalize to absolute
  if (strpos($value, "music/") === 0) {
    $value = "/home/fpp/media/" . $value; // -> /home/fpp/media/music/...
  }

  // Must be an absolute path under $musicRoot
  if (strpos($value, $musicRoot . "/") !== 0) return "";
  if (!isAudioFile($value)) return "";
  if (!file_exists($value)) return "";

  return $value;
}

$duck = sanitizeDuck($_POST["duck"] ?? "25%");

$buttons = [];
for ($i = 0; $i < 6; $i++) {
  $label = trim((string)($_POST["label_$i"] ?? ""));
  if ($label === "") $label = "Announcement " . ($i + 1);

  $fileRaw = $_POST["file_$i"] ?? "";
  $fileAbs = normalizeMusicPath($musicRoot, $fileRaw);

  $buttons[] = [
    "label" => $label,
    "file"  => $fileAbs
  ];
}

$cfg = [
  "duck" => $duck,
  "buttons" => $buttons
];

ensureDir(dirname($configFile));

$ok = @file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
if ($ok === false) {
  jsonOut("ERROR", "Failed to write config: $configFile");
}

// Helpful perms (doesn't have to be perfect in Docker, but good practice)
@chmod($configFile, 0664);

jsonOut("OK", "Saved settings.");
