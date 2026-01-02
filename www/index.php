<?php
$configFile = "/home/fpp/media/config/announcementassistant.json";

function loadConfig($path) {
  if (!file_exists($path)) {
    $cfg = ["duck"=>"25%","buttons"=>[]];
    for ($i=0; $i<6; $i++) $cfg["buttons"][] = ["label"=>"Announcement ".($i+1), "file"=>""];
    return $cfg;
  }
  $j = json_decode(file_get_contents($path), true);
  return is_array($j) ? $j : ["duck"=>"25%","buttons"=>[]];
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

$cfg = loadConfig($configFile);
$duck = isset($cfg["duck"]) ? $cfg["duck"] : "25%";
$buttons = isset($cfg["buttons"]) ? $cfg["buttons"] : [];
while (count($buttons) < 6) $buttons[] = ["label"=>"Announcement ".(count($buttons)+1), "file"=>""];

$audioFiles = listAudio("/home/fpp/media/music");
?>
<h2>AA - Announcement Assistant (Audio Ducking)</h2>

<p>
<strong>Requirement:</strong> For mixing + ducking, set FPP <em>Audio Output Device</em> to <code>pulse</code> and restart fppd.
</p>

<form id="aaForm">
  <div style="margin-bottom:10px;">
    <label><strong>Ducking Level (show audio):</strong></label>
    <input type="text" name="duck" value="<?php echo htmlspecialchars($duck); ?>" style="width:80px;" />
    <span style="color:#666;">(example: 25%)</span>
  </div>

  <table class="fppTable" style="width:100%; max-width:1100px;">
    <tr>
      <th>#</th>
      <th>Button Label</th>
      <th>Announcement Audio File (from /music)</th>
      <th>Test</th>
    </tr>
    <?php for ($i=0; $i<6; $i++): ?>
    <tr>
      <td><?php echo ($i+1); ?></td>
      <td>
        <input type="text" name="label_<?php echo $i; ?>" value="<?php echo htmlspecialchars($buttons[$i]["label"]); ?>" style="width:260px;" />
      </td>
      <td>
        <select name="file_<?php echo $i; ?>" style="width:100%;">
          <option value="">-- Select an audio file --</option>
          <?php foreach ($audioFiles as $f): ?>
            <option value="<?php echo htmlspecialchars($f); ?>" <?php echo ($buttons[$i]["file"]===$f) ? "selected" : ""; ?>>
              <?php echo htmlspecialchars(str_replace("/home/fpp/media/music/","music/",$f)); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>
      <td>
        <button type="button" onclick="aaTrigger(<?php echo $i; ?>)">Play</button>
      </td>
    </tr>
    <?php endfor; ?>
  </table>

  <div style="margin-top:12px;">
    <button type="button" onclick="aaSave()">Save Settings</button>
    <span id="aaStatus" style="margin-left:10px;"></span>
  </div>
</form>

<hr/>

<h3>Live Buttons</h3>
<div style="display:flex; gap:10px; flex-wrap:wrap;">
  <?php for ($i=0; $i<6; $i++): ?>
    <button style="min-width:220px; min-height:48px;" type="button" onclick="aaTrigger(<?php echo $i; ?>)">
      <?php echo htmlspecialchars($buttons[$i]["label"]); ?>
    </button>
  <?php endfor; ?>
</div>

<script>
const AA_BASE = 'plugin.php?plugin=fpp-AnnouncementAssistant&nopage=1&page=';

async function aaSave() {
  const form = document.getElementById('aaForm');
  const fd = new FormData(form);

  const res = await fetch(AA_BASE + 'save.php', {
    method: 'POST',
    body: fd,
    cache: 'no-store'
  });

  const text = await res.text();
  let j;
  try { j = JSON.parse(text); }
  catch (e) { j = { status: 'ERROR', message: 'Invalid JSON: ' + text.slice(0, 120) }; }

  document.getElementById('aaStatus').textContent = j.message || j.status;
}

async function aaTrigger(i) {
  const res = await fetch(AA_BASE + 'trigger.php&slot=' + encodeURIComponent(i), { cache: 'no-store' });

  const text = await res.text();
  let j;
  try { j = JSON.parse(text); }
  catch (e) { j = { status: 'ERROR', message: 'Invalid JSON: ' + text.slice(0, 120) }; }

  document.getElementById('aaStatus').textContent = j.message || j.status;
}
</script>
