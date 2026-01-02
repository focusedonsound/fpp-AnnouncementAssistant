<?php
header('Content-Type: application/json');

$configFile = "/home/fpp/media/config/announcementassistant.json";
$duck = isset($_POST["duck"]) ? trim($_POST["duck"]) : "25%";

$buttons = [];
for ($i=0; $i<6; $i++) {
  $label = isset($_POST["label_$i"]) ? trim($_POST["label_$i"]) : "Announcement ".($i+1);
  $file  = isset($_POST["file_$i"]) ? trim($_POST["file_$i"]) : "";
  $buttons[] = ["label"=>$label, "file"=>$file];
}

$cfg = ["duck"=>$duck, "buttons"=>$buttons];

if (!is_dir(dirname($configFile))) {
  @mkdir(dirname($configFile), 0777, true);
}

$ok = file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT));
if ($ok === false) {
  echo json_encode(["status"=>"ERROR","message"=>"Failed to write config: $configFile"]);
  exit;
}

echo json_encode(["status"=>"OK","message"=>"Saved."]);
