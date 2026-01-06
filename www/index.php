<?php
$configFile = "/home/fpp/media/config/announcementassistant.json";

function loadConfig($path) {
  if (!file_exists($path)) {
    $cfg = ["duck"=>"25%", "duckDefault"=>"25%", "buttons"=>[]];
    for ($i=0; $i<6; $i++) {
      $cfg["buttons"][] = ["label"=>"Announcement ".($i+1), "file"=>"", "duck"=>"25%"];
    }
    return $cfg;
  }

  $raw = @file_get_contents($path);
  $j = json_decode($raw ?: "{}", true);
  if (!is_array($j)) $j = [];
  if (!isset($j["buttons"]) || !is_array($j["buttons"])) $j["buttons"] = [];
  return $j;
}

function listAudio($dir) {
  $out = [];
  if (!is_dir($dir)) return $out;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
  );
  foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
    if (in_array($ext, ["mp3","wav","ogg","flac","m4a","aac"])) {
      $rel = str_replace($dir."/", "", $f->getPathname());
      $out[] = $rel;
    }
  }
  sort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}

$cfg = loadConfig($configFile);

$duckDefault = "25%";
if (isset($cfg["duckDefault"])) $duckDefault = (string)$cfg["duckDefault"];
else if (isset($cfg["duck"])) $duckDefault = (string)$cfg["duck"]; // legacy fallback

$buttons = $cfg["buttons"];
while (count($buttons) < 6) $buttons[] = [];

for ($i=0; $i<6; $i++) {
  if (!isset($buttons[$i]["label"])) $buttons[$i]["label"] = "Announcement ".($i+1);
  if (!isset($buttons[$i]["file"]))  $buttons[$i]["file"]  = "";
  if (!isset($buttons[$i]["duck"]) || $buttons[$i]["duck"] === "") {
    $buttons[$i]["duck"] = $duckDefault;
  }
}

$audioFiles = listAudio("/home/fpp/media/music");
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Announcement Assistant</title>
  <style>
    body { font-family: Arial, sans-serif; }
    table { border-collapse: collapse; width: 100%; max-width: 1100px; }
    th, td { border: 1px solid #ddd; padding: 8px; vertical-align: middle; }
    th { background: #f7f7f7; text-align: left; }
    input[type="text"] { width: 95%; padding: 6px; }
    select { width: 100%; padding: 6px; }
    button { padding: 6px 10px; cursor: pointer; }
    .row-actions button { margin-right: 6px; }
    .status { margin: 10px 0; padding: 8px; background: #f5f5f5; border: 1px solid #ddd; max-width: 1100px; }
    .note { color: #555; max-width: 1100px; }
    .live-buttons { display: flex; flex-wrap: wrap; gap: 10px; max-width: 1100px; margin-top: 10px; }
    .live-buttons button { padding: 12px 16px; font-size: 14px; }
  </style>
</head>
<body>

<h2>Announcement Assistant (Audio Ducking)</h2>

<div class="note">
  <p>
    Each announcement can have its own <b>duck %</b> (how loud the show audio stays while the announcement plays).
    Lower % = more ducking. Example: <code>15%</code> ducks harder than <code>40%</code>.
  </p>
</div>

<div id="aaStatus" class="status">Ready.</div>

<form id="aaForm">
  <table>
    <thead>
      <tr>
        <th style="width: 28%;">Label</th>
        <th>Audio File</th>
        <th style="width: 10%;">Duck %</th>
        <th style="width: 16%;">Test</th>
      </tr>
    </thead>
    <tbody>
      <?php for ($i=0; $i<6; $i++): ?>
      <tr>
        <td>
          <input type="text" name="label_<?php echo $i; ?>" value="<?php echo htmlspecialchars($buttons[$i]["label"]); ?>">
        </td>
        <td>
          <select name="file_<?php echo $i; ?>">
            <option value="">-- select --</option>
            <?php foreach ($audioFiles as $f): ?>
              <option value="<?php echo htmlspecialchars($f); ?>" <?php echo ($f === $buttons[$i]["file"]) ? "selected" : ""; ?>>
                <?php echo htmlspecialchars($f); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <input type="text" name="duck_<?php echo $i; ?>" value="<?php echo htmlspecialchars($buttons[$i]["duck"]); ?>" placeholder="25%">
        </td>
        <td class="row-actions">
          <button type="button" onclick="aaTrigger(<?php echo $i; ?>)">Play</button>
          <button type="button" onclick="aaStop()">Stop</button>
        </td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <p style="max-width:1100px;">
    <button type="button" onclick="aaSave()">Save</button>
  </p>
</form>

<h3>Live Buttons</h3>
<div class="live-buttons">
  <?php for ($i=0; $i<6; $i++): ?>
    <button type="button" onclick="aaTrigger(<?php echo $i; ?>)"><?php echo htmlspecialchars($buttons[$i]["label"]); ?></button>
  <?php endfor; ?>
</div>

<script>
const AA_BASE = "/plugin.php?plugin=fpp-AnnouncementAssistant&page=";

function setStatus(msg) {
  document.getElementById("aaStatus").textContent = msg;
}

async function aaTrigger(slot) {
  try {
    setStatus("Playing announcement " + (slot + 1) + "...");
    const r = await fetch(AA_BASE + "trigger.php&slot=" + slot, { cache: "no-store" });
    const t = await r.text();
    setStatus(t.trim() || "Triggered.");
  } catch (e) {
    setStatus("Error triggering announcement: " + e);
  }
}

async function aaStop() {
  try {
    setStatus("Stopping announcement...");
    const r = await fetch(AA_BASE + "trigger.php&action=stop", { cache: "no-store" });
    const t = await r.text();
    setStatus(t.trim() || "Stopped.");
  } catch (e) {
    setStatus("Error stopping announcement: " + e);
  }
}

async function aaSave() {
  try {
    setStatus("Saving...");
    const form = document.getElementById("aaForm");
    const data = new FormData(form);
    const r = await fetch(AA_BASE + "save.php", { method: "POST", body: data });
    const t = await r.text();
    setStatus(t.trim() || "Saved.");
  } catch (e) {
    setStatus("Error saving: " + e);
  }
}
</script>

</body>
</html>
