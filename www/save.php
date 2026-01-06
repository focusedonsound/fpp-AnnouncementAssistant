<?php
declare(strict_types=1);

header('Content-Type: application/json');

const CFG_FILE = '/home/fpp/media/config/announcementassistant.json';

function respond(bool $ok, array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok], $extra));
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    respond(false, ['error' => 'Empty request body']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    respond(false, ['error' => 'Invalid JSON']);
}

$buttons = $data['buttons'] ?? null;
if (!is_array($buttons) || count($buttons) !== 6) {
    respond(false, ['error' => 'buttons must be an array of 6 items']);
}

$cleanButtons = [];
for ($i = 0; $i < 6; $i++) {
    $b = $buttons[$i];
    if (!is_array($b)) $b = [];

    $label = isset($b['label']) ? trim((string)$b['label']) : ('Announcement ' . ($i + 1));
    if ($label === '') $label = 'Announcement ' . ($i + 1);
    if (mb_strlen($label) > 50) $label = mb_substr($label, 0, 50);

    $file = isset($b['file']) ? trim((string)$b['file']) : '';
    // Allow blank. If provided, keep it constrained to "music/<filename>"
    if ($file !== '') {
        if (str_contains($file, '..') || str_starts_with($file, '/') || !preg_match('#^music/[^/]+$#', $file)) {
            respond(false, ['error' => "Invalid file for slot $i"]);
        }
        $full = '/home/fpp/media/' . $file;
        if (!file_exists($full)) {
            respond(false, ['error' => "File not found for slot $i: $file"]);
        }
    }

    $duck = isset($b['duck']) ? trim((string)$b['duck']) : '25%';
    if (preg_match('/^\d+$/', $duck)) $duck .= '%';
    if (!preg_match('/^(\d+)%$/', $duck, $m)) {
        respond(false, ['error' => "Invalid duck value for slot $i"]);
    }
    $duckNum = (int)$m[1];
    if ($duckNum < 0) $duckNum = 0;
    if ($duckNum > 100) $duckNum = 100;
    $duck = $duckNum . '%';

    $cleanButtons[] = [
        'label' => $label,
        'file'  => $file,
        'duck'  => $duck
    ];
}

// Keep a legacy default duck too (helps older scripts, harmless for new)
$out = [
    'version' => 2,
    'duck'    => '25%',
    'buttons' => $cleanButtons
];

// Atomic write
$tmp = CFG_FILE . '.tmp';
if (file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
    respond(false, ['error' => 'Failed to write temp config']);
}
if (!rename($tmp, CFG_FILE)) {
    @unlink($tmp);
    respond(false, ['error' => 'Failed to replace config']);
}

respond(true, ['message' => 'Saved']);
