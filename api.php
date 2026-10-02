<?php
// Announcement Assistant - cross-plugin API for Encore Radio's
// integration (naming an AA announcement slot in Encore Radio's own
// schedule). FPP core auto-discovers a plugin's api.php and registers
// whatever getEndpoints<repoName>() returns, no other wiring needed.

include_once("/opt/fpp/www/common.php");

function getEndpointsfppAnnouncementAssistant() {
    $result = array();

    $ep = array(
        'method' => 'GET',
        'endpoint' => 'slots',
        'callback' => 'aaSlots');
    array_push($result, $ep);

    return $result;
}

// GET /api/plugin/fpp-AnnouncementAssistant/slots
// This plugin's configured announcement buttons (index + label) - lets
// another plugin (Encore Radio) offer "play this announcement" without
// reading announcementassistant.json directly itself (Plugin Guidelines
// §5: talk to another plugin through its own interface, not its files).
// Reachability of this endpoint at all is also how a caller tells AA is
// actually installed, rather than checking for its config file's
// existence as a installed-or-not proxy.
function aaSlots() {
    $cfg = json_decode(@file_get_contents("/home/fpp/media/config/announcementassistant.json"), true);
    $buttons = (is_array($cfg) && is_array($cfg["buttons"] ?? null)) ? $cfg["buttons"] : array();

    $slots = array();
    foreach ($buttons as $i => $btn) {
        $label = trim((string)($btn["label"] ?? ""));
        if ($label === "") {
            $label = "Slot " . ($i + 1);
        }
        array_push($slots, array("index" => $i, "label" => $label));
    }

    header('Content-Type: application/json');
    echo json_encode($slots);
}
