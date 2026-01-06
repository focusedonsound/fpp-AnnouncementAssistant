<?php
$configFile = "/home/fpp/media/config/announcementassistant.json";

function loadConfig($path) {
  $cfg = ["duck"=>"25%","buttons"=>[]];

  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) $cfg = array_merge($cfg, $j);
  }

  if (!isset($cfg["buttons"]) || !is_array($cfg["buttons"])) $cfg["buttons"] = [];
  while (count($cfg["buttons"]) < 6) {
    $cfg["buttons"][] = ["label"=>"Announcement ".(count($cfg["buttons"])+1), "file"=>"", "duck"=>$cfg["duck"]];
  }

  // Back-compat: ensure each button has duck
  for ($i=0; $i<6; $i++) {
    if (!isset($cfg["buttons"][$i]["label"])) $cfg["buttons"][$i]["label"] = "Announcement ".($i+1);
    if (!isset($cfg["buttons"][$i]["file"]))  $cfg["buttons"][$i]["file"]  = "";
    if (!isset($cfg["buttons"][$i]["duck"]) || $cfg["buttons"][$i]["duck"] === "") $cfg["buttons"][$i]["duck"] = $cfg["duck"];
  }

  if (!isset($cfg["duck"]) || $cfg["duck"] === "") $cfg["duck"] = "25%";
  return $cfg;
}

function listAudio($base) {
  $out = [];
  if (!is_dir($base)) return $out;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
  foreach ($it as $f) {
    if ($f->isDir()) continue;
    $p = $f->getPathname();
    if (preg_match('/\.(wav|mp3|ogg|flac|m4a)$/i', $p)) $out[] = $p;
  }
  sort($out);
  return $out;
}

function duckToNumber($duck) {
  $duck = trim((string)$duck);
  $duck = rtrim($duck, "%");
  if ($duck === "" || !is_numeric($duck)) return 25;
  $n = (int)$duck;
  if ($n < 0) $n = 0;
  if ($n > 100) $n = 100;
  return $n;
}

$cfg = loadConfig($configFile);
$buttons = $cfg["buttons"];
$audioFiles = listAudio("/home/fpp/media/music");
?>

<h1 class="title">Announcement Assistant (Audio Ducking)</h1>
<p>
  Each announcement can have its own duck % (how loud the show audio stays while the announcement plays).
  Lower % = more ducking. Example: <strong>15%</strong> ducks harder than <strong>40%</strong>.
</p>

<form id="aaForm" onsubmit="return false;">
  <!-- keep a top-level duck in config as a fallback/back-compat, but don’t show as “the” setting -->
  <input type="hidden" name="duck_default" value="<?php echo htmlspecialchars($cfg["duck"]); ?>" />

  <table class="fppTable" style="width:100%; max-width:1200px;">
    <tr>
      <th style="width:40px;">#</th>
      <th style="width:280px;">Label</th>
      <th>Audio File</th>
      <th style="width:140px;">Duck %</th>
      <th style="width:220px;">Test</th>
    </tr>

    <?php for ($i=0; $i<6; $i++): ?>
      <tr>
        <td><?php echo ($i+1); ?></td>

        <td>
          <input type="text"
                 name="label_<?php echo $i; ?>"
                 value="<?php echo htmlspecialchars($buttons[$i]["label"]); ?>"
                 style="width:100%;" />
        </td>

        <td>
          <select name="file_<?php echo $i; ?>" style="width:100%;">
            <option value="">-- select --</option>
            <?php foreach ($audioFiles as $f): ?>
              <option value="<?php echo htmlspecialchars($f); ?>" <?php echo ($buttons[$i]["file"]===$f) ? "selected" : ""; ?>>
                <?php echo htmlspecialchars(str_replace("/home/fpp/media/music/","",$f)); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>

        <td>
          <input type="number"
                 name="duck_<?php echo $i; ?>"
                 min="0" max="100" step="1"
                 value="<?php echo duckToNumber($buttons[$i]["duck"]); ?>"
                 style="width:90px;" /> %
        </td>

        <td>
          <button type="button" class="buttons btn-outline-primary" onclick="aaTrigger(<?php echo $i; ?>)">Play</button>
          <button type="button" class="buttons btn-outline-secondary" onclick="aaStop()">Stop</button>
        </td>
      </tr>
    <?php endfor; ?>
  </table>

  <div style="margin-top:12px;">
    <button type="button" class="buttons btn-outline-success" onclick="aaSave()">Save</button>
    <span id="aaStatus" style="margin-left:10px;"></span>
  </div>
</form>

<hr/>

<h3>Live Buttons</h3>
<div style="display:flex; gap:10px; flex-wrap:wrap;">
  <?php for ($i=0; $i<6; $i++): ?>
    <button type="button"
            class="buttons btn-outline-primary"
            style="min-width:220px; min-height:48px;"
            onclick="aaTrigger(<?php echo $i; ?>)">
      <?php echo htmlspecialchars($buttons[$i]["label"]); ?>
    </button>
  <?php endfor; ?>

  <button type="button"
          class="buttons btn-outline-secondary"
          style="min-width:220px; min-height:48px;"
          onclick="aaStop()">
    Stop Current
  </button>
</div>

<script>
  // IMPORTANT: build URLs off pluginBase when available (prevents “save.php not found”)
  const AA_PLUGIN_BASE =
    (typeof pluginBase !== 'undefined' && pluginBase)
      ? pluginBase
      : 'plugin.php?plugin=fpp-AnnouncementAssistant&';

  const AA_BASE = AA_PLUGIN_BASE + 'nopage=1&page=';

  function aaUrl(rel) {
    // rel like: 'save.php' or 'trigger.php'
    return AA_BASE + 'www/' + rel;
  }

  async function aaReadJson(res) {
    const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) {
      return { status: "ERROR", message: "Non-JSON response (wrong URL). First 200 chars:\n" + text.slice(0,200) };
    }
  }

  function aaSetStatus(msg) {
    const el = document.getElementById('aaStatus');
    if (el) el.textContent = msg || "";
  }

  async function aaSave() {
    aaSetStatus("Saving...");
    const form = document.getElementById('aaForm');
    const fd = new FormData(form);

    const res = await fetch(aaUrl('save.php'), {
      method: 'POST',
      body: fd,
      cache: 'no-store'
    });

    const j = await aaReadJson(res);
    aaSetStatus(j.message || j.status || "OK");
  }

  async function aaTrigger(slot) {
    aaSetStatus("Playing...");
    const res = await fetch(
      aaUrl('trigger.php') + '&action=play&slot=' + encodeURIComponent(slot),
      { cache: 'no-store' }
    );

    const j = await aaReadJson(res);
    aaSetStatus(j.message || j.status || "OK");
  }

  async function aaStop() {
    aaSetStatus("Stopping...");
    const res = await fetch(
      aaUrl('trigger.php') + '&action=stop',
      { cache: 'no-store' }
    );

    const j = await aaReadJson(res);
    aaSetStatus(j.message || j.status || "OK");
  }
</script>
