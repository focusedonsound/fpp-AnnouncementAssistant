<?php
// Announcement Assistant - Tune To preview.
// Renders one frame of the Tune To sign to a PNG using the real renderer, so
// the tab can show a live, pixel-accurate preview of the CURRENT (even unsaved)
// configuration. POST the config JSON to preview unsaved edits; with no body it
// previews the saved tuneto.json. Does not touch the overlay model.

ini_set('display_errors', '0');
header('Cache-Control: no-store');

$plugin_dir = '/home/fpp/media/plugins/fpp-AnnouncementAssistant';
$renderer   = $plugin_dir . '/scripts/aa_tuneto_renderer.py';
$state_dir  = $plugin_dir . '/state';
$saved_cfg  = '/home/fpp/media/config/tuneto.json';

function fail($msg) {
  header('Content-Type: application/json');
  echo json_encode(['status' => 'ERROR', 'message' => $msg]);
  exit;
}

if (!is_dir($state_dir)) {
  @mkdir($state_dir, 0775, true);
}
if (!file_exists($renderer)) {
  fail('Renderer not found: ' . $renderer);
}

// Config source: posted JSON (current edits) or the saved file.
$raw = file_get_contents('php://input');
if ($raw !== false && trim($raw) !== '' && is_array(json_decode($raw, true))) {
  $cfg_arg = $state_dir . '/tuneto_preview.json';
  if (@file_put_contents($cfg_arg, $raw) === false) {
    fail('Could not write preview config.');
  }
} else {
  $cfg_arg = $saved_cfg;
}

$out_png = $state_dir . '/tuneto_preview.png';
@unlink($out_png);

$cmd = 'python3 ' . escapeshellarg($renderer)
     . ' --preview ' . escapeshellarg($out_png)
     . ' --config '  . escapeshellarg($cfg_arg) . ' 2>&1';
$output = [];
$rc = 0;
exec($cmd, $output, $rc);

if ($rc !== 0 || !file_exists($out_png)) {
  fail('Preview render failed. ' . implode(' | ', array_slice($output, -5)));
}

header('Content-Type: image/png');
header('Content-Length: ' . filesize($out_png));
readfile($out_png);
