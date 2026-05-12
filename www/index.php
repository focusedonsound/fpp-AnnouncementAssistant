<?php
$configFile = "/home/fpp/media/config/announcementassistant.json";

function loadConfig($path) {
  $cfg = ["duck"=>"25%","fade_down"=>0.5,"fade_up"=>1.0,
          "behavior"=>"ignore","cooldown"=>3.0,"buttons"=>[]];

  if (file_exists($path)) {
    $j = json_decode(@file_get_contents($path), true);
    if (is_array($j)) $cfg = array_merge($cfg, $j);
  }

  if (!isset($cfg["buttons"]) || !is_array($cfg["buttons"])) $cfg["buttons"] = [];
  while (count($cfg["buttons"]) < 6) {
    $cfg["buttons"][] = ["label"=>"Announcement ".(count($cfg["buttons"])+1), "file"=>"", "duck"=>$cfg["duck"], "interrupt"=>false];
  }

  for ($i=0; $i<6; $i++) {
    if (!isset($cfg["buttons"][$i]["label"])) $cfg["buttons"][$i]["label"] = "Announcement ".($i+1);
    if (!isset($cfg["buttons"][$i]["file"]))  $cfg["buttons"][$i]["file"]  = "";
    if (!isset($cfg["buttons"][$i]["duck"]) || $cfg["buttons"][$i]["duck"] === "") $cfg["buttons"][$i]["duck"] = $cfg["duck"];
    if (!isset($cfg["buttons"][$i]["interrupt"])) $cfg["buttons"][$i]["interrupt"] = false;
  }

  if (!isset($cfg["duck"])      || $cfg["duck"]      === "") $cfg["duck"]      = "25%";
  if (!isset($cfg["fade_down"]) || $cfg["fade_down"] === "") $cfg["fade_down"] = 0.5;
  if (!isset($cfg["fade_up"])   || $cfg["fade_up"]   === "") $cfg["fade_up"]   = 1.0;
  if (!isset($cfg["behavior"])  || $cfg["behavior"]  === "") $cfg["behavior"]  = "ignore";
  if (!isset($cfg["cooldown"])  || $cfg["cooldown"]  === "") $cfg["cooldown"]  = 3.0;
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

$cfg     = loadConfig($configFile);
$buttons = $cfg["buttons"];
$audioFiles = listAudio("/home/fpp/media/music");
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="mb-0"><i class="fas fa-fw fa-bullhorn"></i> Announcement Assistant</h2>
  <div class="d-flex gap-2">
    <a href="https://buymeacoffee.com/jm9pwtesct"
       target="_blank" rel="noopener noreferrer"
       class="buttons btn-outline-light">
      <i class="fas fa-fw fa-mug-hot"></i> Buy Me a Coffee
    </a>
    <a href="https://paypal.me/NScilingo"
       target="_blank" rel="noopener noreferrer"
       class="buttons btn-outline-light">
      <i class="fab fa-fw fa-paypal"></i> Donate via PayPal
    </a>
  </div>
</div>
<p class="text-muted">
  Play pre-recorded announcements over active show audio with automatic PulseAudio ducking.<br>
  <strong>Duck %</strong> controls how loud the show audio stays while the announcement plays &mdash;
  lower = more ducking. Example: <strong>15%</strong> ducks harder than <strong>40%</strong>.
</p>

<form id="aaForm" onsubmit="return false;">
  <input type="hidden" name="duck_default" value="<?php echo htmlspecialchars($cfg["duck"]); ?>" />

  <!-- ── Config Table ─────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table id="aaConfigTable" class="fppSelectableRowTable fppStickyTheadTable" style="width:100%;">
        <thead>
          <tr>
            <th style="width:40px; padding:8px 8px;">#</th>
            <th style="width:220px; padding:8px 8px;">Label</th>
            <th style="padding:8px 8px;">Audio File</th>
            <th style="width:110px; padding:8px 8px;">Duck %</th>
            <th style="width:80px; padding:8px 8px; text-align:center;" title="Slot always interrupts current playback regardless of global policy">
              <i class="fas fa-fw fa-bolt" title="Interrupt"></i> Priority
            </th>
            <th style="width:180px; padding:8px 8px;">Test</th>
          </tr>
        </thead>
        <tbody>
          <?php for ($i=0; $i<6; $i++): ?>
          <tr>
            <td><?php echo ($i+1); ?></td>

            <td>
              <input type="text"
                     class="form-control form-control-sm"
                     name="label_<?php echo $i; ?>"
                     value="<?php echo htmlspecialchars($buttons[$i]["label"]); ?>" />
            </td>

            <td>
              <select name="file_<?php echo $i; ?>" class="form-control form-control-sm">
                <option value="">-- select --</option>
                <?php foreach ($audioFiles as $f): ?>
                  <option value="<?php echo htmlspecialchars($f); ?>" <?php echo ($buttons[$i]["file"]===$f) ? "selected" : ""; ?>>
                    <?php echo htmlspecialchars(str_replace("/home/fpp/media/music/","",$f)); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>

            <td>
              <div class="input-group input-group-sm">
                <input type="number"
                       class="form-control form-control-sm"
                       name="duck_<?php echo $i; ?>"
                       min="0" max="100" step="1"
                       value="<?php echo duckToNumber($buttons[$i]["duck"]); ?>" />
                <span class="input-group-text">%</span>
              </div>
            </td>

            <td style="text-align:center;">
              <div class="form-check d-flex justify-content-center mb-0">
                <input class="form-check-input"
                       type="checkbox"
                       name="interrupt_<?php echo $i; ?>"
                       id="interrupt_<?php echo $i; ?>"
                       value="1"
                       <?php echo !empty($buttons[$i]["interrupt"]) ? "checked" : ""; ?>
                       title="This slot always interrupts current playback" />
              </div>
            </td>

            <td>
              <button type="button" class="buttons btn-outline-light btn-sm me-1"
                      onclick="aaTrigger(<?php echo $i; ?>)">
                <i class="fas fa-fw fa-play"></i> Play
              </button>
              <button type="button" class="buttons btn-outline-light btn-sm"
                      onclick="aaStop()">
                <i class="fas fa-fw fa-stop"></i> Stop
              </button>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Fade Settings ──────────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px 8px;">
              <i class="fas fa-fw fa-sliders-h"></i> Fade Settings
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-arrow-down"></i> Fade Down</label>
              <div class="text-muted small">Seconds to duck show audio</div>
            </td>
            <td style="width:160px; padding:8px;">
              <div class="input-group input-group-sm">
                <input type="number"
                       class="form-control form-control-sm"
                       name="fade_down"
                       id="fadeDown"
                       min="0" max="10" step="0.1"
                       value="<?php echo number_format((float)$cfg['fade_down'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-arrow-up"></i> Fade Up</label>
              <div class="text-muted small">Seconds to restore show audio</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;">
                <input type="number"
                       class="form-control form-control-sm"
                       name="fade_up"
                       id="fadeUp"
                       min="0" max="10" step="0.1"
                       value="<?php echo number_format((float)$cfg['fade_up'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── Behavior Settings ──────────────────────────────────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents fppFThScrollContainer">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th colspan="4" style="padding:8px 8px;">
              <i class="fas fa-fw fa-shield-alt"></i> Interrupt Protection
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-traffic-light"></i> When Busy</label>
              <div class="text-muted small">Trigger arrives while playing</div>
            </td>
            <td style="width:200px; padding:8px;">
              <select name="behavior" id="aaBehavior" class="form-control form-control-sm">
                <option value="ignore"    <?php echo ($cfg["behavior"]==="ignore")    ? "selected" : ""; ?>>
                  Ignore — drop the trigger
                </option>
                <option value="queue"     <?php echo ($cfg["behavior"]==="queue")     ? "selected" : ""; ?>>
                  Queue — play after current finishes
                </option>
                <option value="interrupt" <?php echo ($cfg["behavior"]==="interrupt") ? "selected" : ""; ?>>
                  Interrupt — stop current, play new
                </option>
              </select>
            </td>
            <td style="width:200px; padding:8px;">
              <label class="mb-0"><i class="fas fa-fw fa-hourglass-half"></i> Cooldown</label>
              <div class="text-muted small">Ignore re-triggers for N sec after play</div>
            </td>
            <td style="padding:8px;">
              <div class="input-group input-group-sm" style="max-width:160px;" id="cooldownGroup">
                <input type="number"
                       class="form-control form-control-sm"
                       name="cooldown"
                       id="cooldownInput"
                       min="0" max="60" step="0.5"
                       value="<?php echo number_format((float)$cfg['cooldown'], 1); ?>" />
                <span class="input-group-text">sec</span>
              </div>
              <div class="text-muted small mt-1" id="cooldownNote">Only applies to Ignore mode</div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mb-4">
    <button type="button" class="buttons btn-outline-light" onclick="aaSave()">
      <i class="fas fa-fw fa-save"></i> Save Settings
    </button>
  </div>

  <!-- ── Footer: Non-commercial notice + telemetry opt-in ────────────── -->
  <div class="fppTableWrapper fppTableWrapperAsTable mb-3">
    <div class="fppTableContents">
      <table class="fppSelectableRowTable" style="width:100%;">
        <thead>
          <tr>
            <th style="padding:8px;">
              <i class="fas fa-fw fa-heart"></i> About This Plugin
            </th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:12px 16px;">
              <p class="mb-3">
                SLED and Announcement Assistant are free for personal use.
                If you&rsquo;re using either plugin in a paid display, sponsored event, or
                professional environment &mdash; please consider
                <a href="https://paypal.me/NScilingo" target="_blank" rel="noopener noreferrer">
                  making a donation</a>.
                It helps keep development going.
              </p>
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="telemetry_opt_in"
                       id="telemetryOptIn" value="1"
                       <?php echo !empty($cfg['telemetry']['opt_in']) ? 'checked' : ''; ?> />
                <label class="form-check-label small" for="telemetryOptIn" style="cursor:pointer;">
                  Help improve this plugin by sharing anonymous usage stats
                  <span style="cursor:help; color:var(--bs-info);"
                        title="Sends once per day: plugin version, FPP version, Pi model, and how many announcement buttons are configured. Audio filenames, file sizes, and playback results are included to help diagnose playback issues. No personal data is collected and no IP addresses are stored.">
                    <i class="fas fa-circle-question fa-xs"></i>
                  </span>
                </label>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

</form>

<hr/>

<!-- ── Live Trigger Buttons ───────────────────────────────────────── -->
<h4><i class="fas fa-fw fa-bolt"></i> Live Triggers</h4>
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php for ($i=0; $i<6; $i++): ?>
    <button type="button"
            class="buttons btn-outline-light"
            style="min-width:180px; min-height:48px;"
            id="liveBtn<?php echo $i; ?>"
            onclick="aaTrigger(<?php echo $i; ?>)">
      <i class="fas fa-fw fa-play"></i>
      <?php echo htmlspecialchars($buttons[$i]["label"]); ?>
    </button>
  <?php endfor; ?>

  <button type="button"
          class="buttons btn-outline-light"
          style="min-width:180px; min-height:48px;"
          onclick="aaStop()">
    <i class="fas fa-fw fa-stop"></i> Stop Current
  </button>
</div>

<script>
  const AA_PLUGIN_BASE =
    (typeof pluginBase !== 'undefined' && pluginBase)
      ? pluginBase
      : 'plugin.php?plugin=fpp-AnnouncementAssistant&';

  const AA_BASE = AA_PLUGIN_BASE + 'nopage=1&page=';

  function aaUrl(rel) {
    return AA_BASE + 'www/' + rel;
  }

  async function aaReadJson(res) {
    const text = await res.text();
    try { return JSON.parse(text); }
    catch (e) {
      return { status: "ERROR", message: "Non-JSON response. First 200 chars:\n" + text.slice(0, 200) };
    }
  }

  function aaNotify(msg, isError) {
    $.jGrowl(msg, { themeState: isError ? 'danger' : 'success' });
  }

  async function aaSave() {
    const form  = document.getElementById('aaForm');
    const fd    = new FormData(form);

    const res = await fetch(aaUrl('save.php'), {
      method: 'POST',
      body: fd,
      cache: 'no-store'
    });

    const j = await aaReadJson(res);
    const ok = (j.status === 'OK');
    aaNotify(j.message || (ok ? 'Saved.' : 'Save failed.'), !ok);

    // Refresh live button labels from form values
    for (let i = 0; i < 6; i++) {
      const labelInput = document.querySelector(`[name="label_${i}"]`);
      const btn = document.getElementById('liveBtn' + i);
      if (labelInput && btn) {
        btn.innerHTML = '<i class="fas fa-fw fa-play"></i> ' +
          labelInput.value.trim() || ('Announcement ' + (i + 1));
      }
    }
  }

  async function aaTrigger(slot) {
    const res = await fetch(
      aaUrl('trigger.php') + '&action=play&slot=' + encodeURIComponent(slot),
      { cache: 'no-store' }
    );

    const j = await aaReadJson(res);
    const ok = (j.status === 'OK');
    aaNotify(j.message || (ok ? 'Playing...' : 'Trigger failed.'), !ok);
  }

  // Dim cooldown field when behavior isn't "ignore"
  function aaUpdateBehaviorUI() {
    const behavior = document.getElementById('aaBehavior')?.value;
    const group    = document.getElementById('cooldownGroup');
    const note     = document.getElementById('cooldownNote');
    const input    = document.getElementById('cooldownInput');
    if (!group) return;
    const relevant = (behavior === 'ignore');
    group.style.opacity = relevant ? '1' : '0.4';
    if (input) input.disabled = !relevant;
    if (note)  note.style.opacity = relevant ? '0.7' : '0.4';
  }
  document.getElementById('aaBehavior')?.addEventListener('change', aaUpdateBehaviorUI);
  aaUpdateBehaviorUI();

  async function aaStop() {
    const res = await fetch(
      aaUrl('trigger.php') + '&action=stop',
      { cache: 'no-store' }
    );

    const j = await aaReadJson(res);
    const ok = (j.status === 'OK');
    aaNotify(j.message || (ok ? 'Stopped.' : 'Stop failed.'), !ok);
  }
</script>
