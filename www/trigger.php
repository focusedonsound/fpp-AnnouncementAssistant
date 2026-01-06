<?php
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pluginDir = "/home/fpp/media/plugins/fpp-AnnouncementAssistant";
$configFile = "/home/fpp/media/config/announcementassistant.json";

function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge([
    "status" => $ok ? "OK" : "ERROR",
    "message" => $msg
  ], $extra));
  exit;
}

$action = strtolower(trim((string)($_GET["action"] ?? "play")));

if ($action === "stop") {
  $stop = $pluginDir . "/scripts/aa_stop.sh";
  if (!file_exists($stop)) respond(false, "Stop script missing: $stop");
  @exec("bash " . escapeshellarg($stop) . " >/dev/null 2>&1 &");
  respond(true, "Stop requested.");
}

$slot = isset($_GET["slot"]) ? (int)$_GET["slot"] : -1;
if ($slot < 0 || $slot > 5) respond(false, "Invalid slot: $slot");

$cfg = @json_decode(@file_get_contents($configFile), true);
if (!is_array($cfg) || !isset($cfg["buttons"][$slot])) respond(false, "Config missing/invalid.");

$file = trim((string)($cfg["buttons"][$slot]["file"] ?? ""));
if ($file === "") respond(false, "No audio file configured for slot ".($slot+1));

$play = $pluginDir . "/scripts/aa_play.sh";
if (!file_exists($play)) respond(false, "Play script missing: $play");

$cmd = "bash " . escapeshellarg($play) . " " . escapeshellarg((string)$slot) . " >/dev/null 2>&1 &";
@exec($cmd);

respond(true, "Triggered slot ".($slot+1).".");
