<?php
// /home/fpp/media/plugins/fpp-AnnouncementAssistant/www/trigger.php

header('Content-Type: application/json; charset=utf-8');

$configFile = "/home/fpp/media/config/announcementassistant.json";
$musicRoot  = "/home/fpp/media/music";
$script     = "/home/fpp/media/plugins/fpp-AnnouncementAssistant/scripts/aa_play.sh";
$logFile    = "/home/fpp/media/logs/AnnouncementAssistant.log";

function logLine($logFile, $msg) {
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$ts] [trigger] $msg\n", FILE_APPEND | LOCK_EX);
}

function jsonOut($status, $message, $extra = []) {
    $payload = array_merge(["status" => $status, "message" => $message], $extra);
    echo json_encode($payload);
    exit;
}

function isAudioFile($path) {
    return (bool)preg_match('/\.(wav|mp3|ogg|flac|m4a)$/i', $path);
}

function normalizeMusicPath($musicRoot, $value) {
    $value = trim((string)$value);
    if ($value === "") return "";

    // Accept either absolute path or "music/..."
    if (strpos($value, "music/") === 0) {
        $value = "/home/fpp/media/" . $value;
    }

    // Must be under /home/fpp/media/music
    if (strpos($value, $musicRoot . "/") !== 0) return "";
    if (!isAudioFile($value)) return "";
    if (!file_exists($value)) return "";

    return $value;
}

function getDuckForButton($cfg, $slot) {
    // Priority:
    // 1) button-specific duck keys
    // 2) global cfg duck
    // 3) fallback 25%
    $btn = $cfg["buttons"][$slot] ?? [];

    $candidates = [
        $btn["duck"]        ?? null,
        $btn["duckPct"]     ?? null,
        $btn["duckPercent"] ?? null,
        $btn["duck_percent"]?? null,
        $cfg["duck"]        ?? null,
    ];

    foreach ($candidates as $v) {
        if ($v === null) continue;
        $v = trim((string)$v);
        if ($v === "") continue;

        // Normalize to "NN%"
        if (preg_match('/^\d+$/', $v)) return $v . "%";
        if (preg_match('/^\d+%$/', $v)) return $v;
    }
    return "25%";
}

// ---- Request parsing ----
$action = strtolower(trim((string)($_REQUEST["action"] ?? "play"))); // play|stop
$slot   = isset($_REQUEST["slot"]) ? intval($_REQUEST["slot"]) : -1;
$debug  = isset($_REQUEST["debug"]) ? intval($_REQUEST["debug"]) : 0;

logLine($logFile, "REQUEST action=$action slot=$slot debug=$debug remote=" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"));

// Validate script exists
if (!file_exists($script)) {
    logLine($logFile, "ERROR missing script: $script");
    jsonOut("ERROR", "Missing script: $script");
}

// STOP: stop any currently playing announcement(s)
if ($action === "stop") {
    // Optional slot passed from UI; not required for stop-all.
    $cmd = "bash -lc " . escapeshellarg(
        "bash " . escapeshellarg($script) . " --stop >> " . escapeshellarg($logFile) . " 2>&1 & echo $!"
    );
    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    $pid = trim($out[0] ?? "");

    logLine($logFile, "STOP dispatched rc=$rc pid=" . ($pid ?: "n/a") . " cmd=" . $cmd);

    if ($rc !== 0) {
        jsonOut("ERROR", "Failed to dispatch stop.", ["rc" => $rc]);
    }
    jsonOut("OK", "Stop requested.", ["pid" => $pid]);
}

// PLAY: slot is required
if ($slot < 0 || $slot > 5) {
    logLine($logFile, "ERROR invalid slot: $slot");
    jsonOut("ERROR", "Invalid slot");
}

if (!file_exists($configFile)) {
    logLine($logFile, "ERROR config not found: $configFile");
    jsonOut("ERROR", "Config not found. Save settings first.");
}

$cfgRaw = @file_get_contents($configFile);
$cfg = json_decode($cfgRaw ?: "", true);

if (!is_array($cfg)) {
    logLine($logFile, "ERROR invalid config JSON. First 120 chars=" . substr((string)$cfgRaw, 0, 120));
    jsonOut("ERROR", "Invalid config JSON.");
}

$btn = $cfg["buttons"][$slot] ?? null;
if (!$btn || empty($btn["file"])) {
    logLine($logFile, "ERROR no file assigned for slot=$slot");
    jsonOut("ERROR", "No audio file assigned to this button.");
}

$file = normalizeMusicPath($musicRoot, $btn["file"]);
if ($file === "") {
    logLine($logFile, "ERROR file invalid/missing for slot=$slot raw=" . ($btn["file"] ?? ""));
    jsonOut("ERROR", "Selected file is missing or not under /home/fpp/media/music.");
}

$duck  = getDuckForButton($cfg, $slot);
$label = isset($btn["label"]) && trim((string)$btn["label"]) !== "" ? $btn["label"] : ("Announcement " . ($slot + 1));

logLine($logFile, "PLAY slot=$slot label=" . json_encode($label) . " file=" . json_encode($file) . " duck=" . json_encode($duck));

// Run background + capture PID
$cmd = "bash -lc " . escapeshellarg(
    "bash " . escapeshellarg($script) . " " . escapeshellarg($file) . " " . escapeshellarg($duck) .
    " >> " . escapeshellarg($logFile) . " 2>&1 & echo $!"
);

$out = [];
$rc  = 0;
exec($cmd, $out, $rc);
$pid = trim($out[0] ?? "");

logLine($logFile, "PLAY dispatched rc=$rc pid=" . ($pid ?: "n/a") . " cmd=" . $cmd);

if ($rc !== 0) {
    jsonOut("ERROR", "Failed to dispatch announcement.", ["rc" => $rc]);
}

jsonOut("OK", "Triggered: " . $label, [
    "slot" => $slot,
    "duck" => $duck,
    "pid"  => $pid,
    "file" => $file,
]);
