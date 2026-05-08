<?php
// Endpoint: returns configured slot labels as a JSON object for FPP Command contentListUrl.
// Format: { "0": "Announcement 1", "1": "Holiday Greeting", ... }
// Slots without an audio file assigned are omitted so the dropdown only shows usable slots.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configFile = "/home/fpp/media/config/announcementassistant.json";

$cfg  = [];
if (file_exists($configFile)) {
    $j = json_decode(@file_get_contents($configFile), true);
    if (is_array($j)) $cfg = $j;
}

$buttons = $cfg['buttons'] ?? [];
$result  = [];

for ($i = 0; $i < 6; $i++) {
    $label = trim((string)($buttons[$i]['label'] ?? ''));
    $file  = trim((string)($buttons[$i]['file']  ?? ''));

    if ($label === '') $label = 'Announcement ' . ($i + 1);

    // Only include slots that have an audio file assigned
    if ($file !== '') {
        $result[(string)$i] = $label;
    }
}

// Fallback: if nothing is configured yet, return all 6 with generic names
// so the command is still usable even before slots are saved.
if (empty($result)) {
    for ($i = 0; $i < 6; $i++) {
        $result[(string)$i] = 'Announcement ' . ($i + 1);
    }
}

// JSON_FORCE_OBJECT ensures {"0":"Label",...} not ["Label",...] even for sequential keys.
// The FPP command UI uses the key as the option value, so the slot index must be the key.
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);
