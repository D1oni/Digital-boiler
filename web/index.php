<?php
include 'db.php';
include 'script.php';

// (optional) for nav "active"
$current = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Digital Boiler - <?= htmlspecialchars($device_name) ?></title>
  <meta http-equiv="refresh" content="10">
  <link rel="stylesheet" href="style.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>
</head>

<body>
  <header class="site-header">
    <div class="container header-inner">
      <div class="logo">Digital Boiler</div>
      <nav class="nav">
        <a class="nav-link <?= $current==='index.php'?'active':'' ?>" href="index.php">Controls</a>
        <a class="nav-link <?= $current==='dashboard.php'?'active':'' ?>" href="dashboard.php">Dashboard</a>
        <a class="nav-link <?= $current==='history.php'?'active':'' ?>" href="history.php">History</a>
      </nav>
    </div>
  </header>

  <main class="container dashboard">
    <h1>Digital Boiler - <?= htmlspecialchars($device_name) ?></h1>

    <section class="card">
      <header class="card-head">
        <h2>Relay Control</h2>
        <div class="rele-state">
          Mode: <span class="chip"><?= htmlspecialchars($desired['mode']) ?></span>
          <?php if ($desired['mode'] === 'manual'): ?>
            <span class="sep">|</span>
            State: <span class="chip <?= ((int)$desired['state']===1)?'chip-on':'chip-off' ?>">
              <?= (int)$desired['state'] === 1 ? 'ON' : 'OFF' ?>
            </span>
          <?php endif; ?>
        </div>
      </header>

      <div class="actions">
        <button class="btn btn-outline" onclick="setAuto()">AUTO</button>
        <button class="btn" onclick="setManual(1)">MANUAL ON</button>
        <button class="btn btn-soft" onclick="setManual(0)">MANUAL OFF</button>
      </div>
    </section>

    <section class="card">
      <h2>Configure T_ON Threshold</h2>
      <form class="form-grid" onsubmit="return saveTOn(event)">
        <input type="hidden" name="device_uid" id="device_uid" value="<?= htmlspecialchars($device_uid) ?>">
        <label for="t_on">T_ON (C)</label>
        <input type="number" step="0.01" id="t_on" name="t_on"
               value="<?= htmlspecialchars($tOnVal ?? '') ?>" placeholder="e.g. 24.5" required>
        <button type="submit" class="btn">Save</button>
      </form>
    </section>

    <section class="card">
      <h2>Relay Status</h2>
      <?php if ($relayRow): $isOn = ((int)$relayRow['state'] === 1); ?>
        <div class="chip <?= $isOn ? 'chip-on' : 'chip-off' ?>">
          Relay: <strong><?= $isOn ? 'ON' : 'OFF' ?></strong>
        </div>
        <p class="muted">Updated: <?= htmlspecialchars($relayRow['created_at']) ?></p>
      <?php else: ?>
        <div class="chip">No relay data yet.</div>
      <?php endif; ?>
    </section>
  </main>

  <footer class="site-footer">
    <div class="container">
      <small>Copyright <?= date('Y') ?> Digital Boiler</small>
    </div>
  </footer>

  <script>
    async function setAuto(){
      const p = new URLSearchParams({
        device_uid: "<?= htmlspecialchars($device_uid) ?>",
        mode: "auto"
      });
      await fetch("relay_set.php", {
        method:"POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: p
      });
      location.reload();
    }

    async function setManual(state){
      const p = new URLSearchParams({
        device_uid: "<?= htmlspecialchars($device_uid) ?>",
        mode: "manual",
        state: state
      });
      await fetch("relay_set.php", {
        method:"POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: p
      });
      location.reload();
    }

    async function saveTOn(e){
      e.preventDefault();
      const t_on = document.getElementById('t_on').value;
      const device_uid = document.getElementById('device_uid').value;
      const p = new URLSearchParams({ device_uid, t_on });

      const r = await fetch("settings_set.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: p
      });
      if (r.ok) {
        alert("Saved successfully!");
        location.reload();
      } else {
        const txt = await r.text();
        alert("Error while saving T_ON: " + txt);
      }
    }

    async function startTimerUI(e){
      e.preventDefault();
      const device_uid = document.getElementById('device_uid_timer').value;
      const timer_duration_min = document.getElementById('timer_duration_min').value;
      const target_temp = document.getElementById('target_temp').value;

      const p = new URLSearchParams({ device_uid, timer_duration_min, target_temp });

      const r = await fetch("timer_set.php", {
        method: "POST",
        headers: {"Content-Type":"application/x-www-form-urlencoded"},
        body: p
      });

      if (r.ok) {
        alert("Timer was sent!");
        location.reload();
      } else {
        const txt = await r.text();
        alert("Timer error: " + txt);
      }
    }
  </script>
</body>
</html>
