<?php
header('Content-Type: application/json');

$slot = isset($_GET["slot"]) ? intval($_GET["slot"]) : -1;
if ($slot < 0 || $slot > 5) {
  echo json_encode(["status"=>"ERROR","message"=>"Invalid slot"]);
  exit;
}

$configFile = "/home/fpp/media/config/announcementassistant.json";
if (!file_exists($configFile)) {
  echo json_encode(["status"=>"ERROR","message"=>"Config not found. Save settings first."]);
  exit;
}

$cfg = json_decode(file_get_contents($configFile), true);
$duck = isset($cfg["duck"]) ? $cfg["duck"] : "25%";
$btn  = $cfg["buttons"][$slot] ?? null;

if (!$btn || empty($btn["file"])) {
  echo json_encode(["status"=>"ERROR","message"=>"No audio file assigned to this button."]);
  exit;
}

$file = $btn["file"];
$script = "/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh";

$cmd = "bash " . escapeshellarg($script) . " " . escapeshellarg($file) . " " . escapeshellarg($duck) . " >/dev/null 2>&1 &";
exec($cmd);

echo json_encode(["status"=>"OK","message"=>"Triggered: ".$btn["label"]]);
