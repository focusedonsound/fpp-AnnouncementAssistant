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

// Animation: the sprite sheet stacks N frames vertically; the browser plays
// them as a loop. frames=1 keeps the single-still behavior.
$frames = isset($_GET['frames']) ? max(1, min(120, (int)$_GET['frames'])) : 1;
$fps    = isset($_GET['fps'])    ? max(1, min(30,  (int)$_GET['fps']))    : 15;

$out_png = $state_dir . '/tuneto_preview.png';
@unlink($out_png);

$cmd = 'python3 ' . escapeshellarg($renderer)
     . ' --preview ' . escapeshellarg($out_png)
     . ' --frames '  . $frames
     . ' --fps '     . $fps
     . ' --config '  . escapeshellarg($cfg_arg) . ' 2>&1';
$output = [];
$rc = 0;
exec($cmd, $output, $rc);

if ($rc !== 0 || !file_exists($out_png)) {
  fail('Preview render failed. ' . implode(' | ', array_slice($output, -5)));
}

// The renderer now sizes the sprite sheet itself (frame count + playback fps
// chosen so the scroll plays at the real speed) and reports them on a tagged
// stdout line: "TTMETA frames=N fps=F fw=W fh=H". Parse it; fall back to the
// image dimensions + requested frames if an older renderer didn't print it.
$meta = ['frames' => $frames, 'fps' => $fps, 'fw' => 0, 'fh' => 0];
foreach ($output as $line) {
  if (strpos($line, 'TTMETA ') === 0) {
    foreach (['frames', 'fps', 'fw', 'fh'] as $k) {
      if (preg_match('/\b' . $k . '=([0-9.]+)/', $line, $m)) $meta[$k] = $m[1] + 0;
    }
  }
}
$info = @getimagesize($out_png);
$frames_out = max(1, (int)$meta['frames']);
$fw = $meta['fw'] > 0 ? (int)$meta['fw'] : ($info ? (int)$info[0] : 0);
$fh = $meta['fh'] > 0 ? (int)$meta['fh'] : ($info ? (int)floor($info[1] / $frames_out) : 0);
header('X-TT-Frames: ' . $frames_out);
header('X-TT-FPS: ' . (float)$meta['fps']);
header('X-TT-FW: ' . $fw);
header('X-TT-FH: ' . $fh);
header('Content-Type: image/png');
header('Content-Length: ' . filesize($out_png));
readfile($out_png);
