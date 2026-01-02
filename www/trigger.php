<?php
header('Content-Type: application/json');

$configFile = "/home/fpp/media/config/announcementassistant.json";
$musicRoot  = "/home/fpp/media/music";
$script     = "/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh";

function jsonOut($status, $message) {
  echo json_encode(["status" => $status, "message" => $message]);
  exit;
}

function isAudioFile($path) {
  return (bool)preg_match('/\.(wav|mp3|ogg|flac|m4a)$/i', $path);
}

function normalizeMusicPath($musicRoot, $value) {
  $value = trim((string)$value);
  if ($value === "") return "";

  // Accept either absolute or "music/..." (future-proof)
  if (strpos($value, "music/") === 0) {
    $value = "/home/fpp/media/" . $value;
  }

  if (strpos($value, $musicRoot . "/") !== 0) return "";
  if (!isAudioFile($value)) return "";
  if (!file_exists($value)) return "";

  return $value;
}

$slot = isset($_GET["slot"]) ? intval($_GET["slot"]) : -1;
if ($slot < 0 || $slot > 5) {
  jsonOut("ERROR", "Invalid slot");
}

if (!file_exists($configFile)) {
  jsonOut("ERROR", "Config not found. Save settings first.");
}

$cfg = json_decode(@file_get_contents($configFile), true);
if (!is_array($cfg)) {
  jsonOut("ERROR", "Invalid config JSON.");
}

$duck = isset($cfg["duck"]) ? (string)$cfg["duck"] : "25%";
$btn  = $cfg["buttons"][$slot] ?? null;

if (!$btn || empty($btn["file"])) {
  jsonOut("ERROR", "No audio file assigned to this button.");
}

$file = normalizeMusicPath($musicRoot, $btn["file"]);
if ($file === "") {
  jsonOut("ERROR", "Selected file is missing or not under /home/fpp/media/music.");
}

if (!file_exists($script)) {
  jsonOut("ERROR", "Missing script: $script");
}

// Run in background. Script itself is responsible for MVP ignore-if-busy behavior.
$cmd = "bash " . escapeshellarg($script) . " " . escapeshellarg($file) . " " . escapeshellarg($duck) . " >/dev/null 2>&1 &";
exec($cmd);

$label = isset($btn["label"]) ? $btn["label"] : ("Announcement " . ($slot + 1));
jsonOut("OK", "Triggered: " . $label);
