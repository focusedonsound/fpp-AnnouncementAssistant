<?php
// Announcement Assistant (Audio Ducking) - UI
// - Per-button duck %
// - Stop button
// - Correct save endpoint: plugin.php?...&nopage=1&page=www/save.php

declare(strict_types=1);

const AA_PLUGIN   = 'fpp-AnnouncementAssistant';
const AA_CFG_FILE = '/home/fpp/media/config/announcementassistant.json';

function aa_default_config(): array {
    $buttons = [];
    for ($i = 1; $i <= 6; $i++) {
        $buttons[] = ['label' => "Announcement $i", 'file' => '', 'duck' => '25%'];
    }
    return [
        'version' => 2,
        // Keep a legacy default too (doesn't hurt, helps backward compat)
        'duck'    => '25%',
        'buttons' => $buttons
    ];
}

function aa_load_config(): array {
    if (!file_exists(AA_CFG_FILE)) {
        return aa_default_config();
    }
    $raw = @file_get_contents(AA_CFG_FILE);
    if ($raw === false) {
        return aa_default_config();
    }
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) {
        return aa_default_config();
    }
    return $cfg;
}

function aa_ensure_buttons(array $cfg): array {
    $legacyDuck = isset($cfg['duck']) && is_string($cfg['duck']) ? $cfg['duck'] : '25%';

    $buttons = $cfg['buttons'] ?? [];
    if (!is_array($buttons)) $buttons = [];

    // Ensure 6 buttons
    for ($i = 0; $i < 6; $i++) {
        if (!isset($buttons[$i]) || !is_array($buttons[$i])) {
            $buttons[$i] = ['label' => 'Announcement ' . ($i + 1), 'file' => '', 'duck' => $legacyDuck];
        }
        $buttons[$i]['label'] = isset($buttons[$i]['label']) ? (string)$buttons[$i]['label'] : ('Announcement ' . ($i + 1));
        $buttons[$i]['file']  = isset($buttons[$i]['file'])  ? (string)$buttons[$i]['file']  : '';
        $buttons[$i]['duck']  = isset($buttons[$i]['duck'])  ? (string)$buttons[$i]['duck']  : $legacyDuck;

        // Normalize duck to "NN%"
        if (preg_match('/^\d+$/', $buttons[$i]['duck'])) {
            $buttons[$i]['duck'] .= '%';
        }
        if (!preg_match('/^\d+%$/', $buttons[$i]['duck'])) {
            $buttons[$i]['duck'] = $legacyDuck;
        }
    }

    return $buttons;
}

function aa_list_music_files(): array {
    $base = '/home/fpp/media/music';
    $out  = [];

    if (!is_dir($base)) return $out;

    $exts = ['mp3','wav','ogg','flac','m4a'];
    foreach ($exts as $ext) {
        foreach (glob($base . '/*.' . $ext) ?: [] as $path) {
            $name = basename($path);
            $out[] = 'music/' . $name;
        }
    }
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

$cfg      = aa_load_config();
$buttons  = aa_ensure_buttons($cfg);
$files    = aa_list_music_files();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }

// Base for AJAX calls (nopage=1 so we get clean JSON/text back)
$AA_BASE = "plugin.php?plugin=" . AA_PLUGIN . "&nopage=1&page=";

?>
<div class="aa-wrap">
  <p>
    Each announcement can have its own duck % (how loud the show audio stays while the announcement plays).
    Lower % = more ducking. Example: <span style="color:#c00">15%</span> ducks harder than <span style="color:#090">40%</span>.
  </p>

  <table class="fppTable">
    <thead>
      <tr>
        <th style="width: 25%;">Label</th>
        <th>Audio File</th>
        <th style="width: 110px;">Duck %</th>
        <th style="width: 170px;">Test</th>
      </tr>
    </thead>
    <tbody>
    <?php for ($i = 0; $i < 6; $i++): ?>
      <?php
        $b = $buttons[$i];
        $duckNum = (int) rtrim($b['duck'], '%');
      ?>
      <tr data-slot="<?= $i ?>">
        <td>
          <input class="aa-label" type="text" value="<?= h($b['label']) ?>" style="width: 98%;">
        </td>
        <td>
          <select class="aa-file" style="width: 100%;">
            <option value="">-- select --</option>
            <?php foreach ($files as $f): ?>
              <option value="<?= h($f) ?>" <?= ($b['file'] === $f ? 'selected' : '') ?>><?= h(basename($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <div style="display:flex; gap:6px; align-items:center;">
            <input class="aa-duck" type="number" min="0" max="100" step="1" value="<?= $duckNum ?>" style="width: 70px;">
            <span>%</span>
          </div>
        </td>
        <td>
          <div style="display:flex; gap:8px; align-items:center;">
            <button class="buttons btn-outline-light" type="button" onclick="aaPlay(<?= $i ?>)">Play</button>
            <button class="buttons btn-outline-light" type="button" onclick="aaStop()">Stop</button>
          </div>
        </td>
      </tr>
    <?php endfor; ?>
    </tbody>
  </table>

  <div style="margin-top: 12px;">
    <button class="buttons btn-outline-light" type="button" onclick="aaSave()">Save</button>
  </div>

  <hr>

  <h3>Live Buttons</h3>
  <div id="aa-live" style="display:flex; gap:10px; flex-wrap:wrap;">
    <?php for ($i = 0; $i < 6; $i++): ?>
      <button class="buttons btn-outline-light" type="button" onclick="aaPlay(<?= $i ?>)">
        <?= h($buttons[$i]['label']) ?>
      </button>
    <?php endfor; ?>
  </div>
</div>

<script>
const AA_BASE = <?= json_encode($AA_BASE) ?>;

function aaToast(msg, ok=true) {
  try {
    if (window.$ && $.jGrowl) {
      $.jGrowl(msg, { theme: ok ? 'jGrowl-notification' : 'jGrowl-error' });
      return;
    }
  } catch(e) {}
  alert(msg);
}

function aaReadRows() {
  const rows = document.querySelectorAll('tr[data-slot]');
  const buttons = [];
  rows.forEach(r => {
    const label = (r.querySelector('.aa-label')?.value || '').trim();
    const file  = (r.querySelector('.aa-file')?.value  || '').trim();
    let duckNum = parseInt((r.querySelector('.aa-duck')?.value || '25'), 10);
    if (Number.isNaN(duckNum)) duckNum = 25;
    if (duckNum < 0) duckNum = 0;
    if (duckNum > 100) duckNum = 100;
    buttons.push({ label, file, duck: `${duckNum}%` });
  });
  return buttons;
}

async function aaSave() {
  const payload = {
    version: 2,
    // keep a legacy "duck" default for backward compat
    duck: "25%",
    buttons: aaReadRows()
  };

  const url = AA_BASE + "www/save.php";
  let respText = "";

  try {
    const resp = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    respText = await resp.text();

    // Expect JSON
    let data;
    try {
      data = JSON.parse(respText);
    } catch (e) {
      aaToast("Save failed: non-JSON response (wrong URL). First 200 chars:\n" + respText.slice(0,200), false);
      return;
    }

    if (!data.ok) {
      aaToast("Save failed: " + (data.error || "unknown error"), false);
      return;
    }

    aaToast("Saved.");
    // Refresh to reflect saved labels in the Live Buttons section
    window.location.reload();
  } catch (e) {
    aaToast("Save exception: " + e, false);
    if (respText) console.log(respText);
  }
}

async function aaPlay(slot) {
  const url = AA_BASE + "www/trigger.php?slot=" + encodeURIComponent(slot);
  try {
    const resp = await fetch(url);
    const txt  = await resp.text();
    let data;
    try { data = JSON.parse(txt); } catch(e) { data = { ok:false, error:"non-JSON response", raw: txt.slice(0,200)}; }
    if (!data.ok) {
      aaToast("Play failed: " + (data.error || data.raw || "unknown"), false);
      return;
    }
    aaToast("Playing announcement...");
  } catch (e) {
    aaToast("Play exception: " + e, false);
  }
}

async function aaStop() {
  const url = AA_BASE + "www/stop.php";
  try {
    const resp = await fetch(url);
    const txt  = await resp.text();
    let data;
    try { data = JSON.parse(txt); } catch(e) { data = { ok:false, error:"non-JSON response", raw: txt.slice(0,200)}; }
    if (!data.ok) {
      aaToast("Stop failed: " + (data.error || data.raw || "unknown"), false);
      return;
    }
    aaToast(data.message || "Stopped.");
  } catch (e) {
    aaToast("Stop exception: " + e, false);
  }
}
</script>
