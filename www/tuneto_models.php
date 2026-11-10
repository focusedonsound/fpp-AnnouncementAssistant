<?php
// Announcement Assistant - Tune To helper endpoint.
// Returns the FPP Pixel Overlay Models (name + geometry) and the fonts
// available on this system, for populating the Tune To tab's selects.
// Read-only; makes no changes.

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Pixel Overlay Models ──────────────────────────────────────────────
// FPP exposes configured overlay models at /api/overlays/models. We ask
// FPP through its own API rather than parsing its config files directly.
function ttOverlayModels() {
  $out = [];
  $raw = @file_get_contents('http://127.0.0.1/api/overlays/models');
  if ($raw === false) return $out;

  $j = json_decode($raw, true);
  if (!is_array($j)) return $out;

  // FPP may return a bare array of model objects, or {"models":[...]}.
  $list = isset($j['models']) && is_array($j['models']) ? $j['models'] : $j;
  if (!is_array($list)) return $out;

  foreach ($list as $m) {
    if (!is_array($m)) continue;
    $name = $m['Name'] ?? ($m['name'] ?? '');
    if ($name === '') continue;
    $out[] = [
      'name'   => (string)$name,
      'width'  => (int)($m['Width']  ?? $m['width']  ?? 0),
      'height' => (int)($m['Height'] ?? $m['height'] ?? 0),
    ];
  }

  usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
  return $out;
}

// ── Installed fonts ───────────────────────────────────────────────────
// Scan the usual TrueType/OpenType locations. Returns de-duplicated
// display names (file basename without extension) with their path.
function ttFonts() {
  // The plugin's own fonts/ dir ships pixel fonts tuned for LED matrices; list
  // it first and flag those so the UI can surface them as recommended.
  $bundledDir = dirname(__DIR__) . '/fonts';
  $dirs = [
    $bundledDir,
    '/usr/share/fonts',
    '/usr/local/share/fonts',
    '/opt/fpp/www/fonts',
    '/home/fpp/media/fonts',
  ];
  $seen = [];
  $out  = [];
  foreach ($dirs as $base) {
    if (!is_dir($base)) continue;
    try {
      $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
      );
    } catch (Exception $e) { continue; }

    foreach ($it as $f) {
      if (!$f->isFile()) continue;
      $p = $f->getPathname();
      if (!preg_match('/\.(ttf|otf)$/i', $p)) continue;
      $name = preg_replace('/\.(ttf|otf)$/i', '', $f->getFilename());
      if (isset($seen[$name])) continue;
      $seen[$name] = true;
      $out[] = ['name' => $name, 'path' => $p, 'bundled' => ($base === $bundledDir)];
    }
  }
  // Bundled pixel fonts first, then everything else alphabetically.
  usort($out, function ($a, $b) {
    if ($a['bundled'] !== $b['bundled']) return $a['bundled'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
  });
  return $out;
}

// ── Discovered FPP systems (for the "show player" host picker) ─────────
// Returns the FPP instances this box knows about via multisync, so the user
// can point Tune To at the player that runs the audio without typing an IP.
function ttSystems() {
  $out = [];
  $raw = @file_get_contents('http://127.0.0.1/api/fppd/multiSyncSystems');
  if ($raw === false) return $out;
  $j = json_decode($raw, true);
  $list = (is_array($j) && isset($j['systems']) && is_array($j['systems'])) ? $j['systems'] : null;
  if (!is_array($list)) return $out;
  foreach ($list as $s) {
    if (!is_array($s)) continue;
    $addr = trim((string)($s['address'] ?? ''));
    if ($addr === '') continue;
    $out[] = [
      'address'  => $addr,
      'hostname' => (string)($s['hostname'] ?? $addr),
      'mode'     => (string)($s['fppModeString'] ?? ''),
      'local'    => !empty($s['local']),
    ];
  }
  // Local box first, then by hostname.
  usort($out, function ($a, $b) {
    if ($a['local'] !== $b['local']) return $a['local'] ? -1 : 1;
    return strcasecmp($a['hostname'], $b['hostname']);
  });
  return $out;
}

echo json_encode([
  'status'  => 'OK',
  'models'  => ttOverlayModels(),
  'fonts'   => ttFonts(),
  'systems' => ttSystems(),
]);
