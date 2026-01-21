<?php
include 'db.php';
include 'script.php';
?>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Boiler History</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>

</head>
<div class="dashboard-wrapper">
  <header class="top-header">
  <div class="header-inner">
    <div class="logo">Digital Boiler</div>
    <nav class="nav-links">
      <button onclick="scrollToSection('main')"><a href="index.php">Controls</a></button>
      <button onclick="scrollToSection('metrics')"><a href="dashboard.php">Dashboard</a></button>
      <button onclick="scrollToSection('history')"><a href="history.php">History</a></button>
    </nav>
  </div>
</header>

<body>

  <h2>Latest sensor values</h2>
  <table>
    <thead><tr><th>Sensor</th><th>Type</th><th>Value</th><th>Time</th></tr></thead>
    <tbody>
    <?php if ($latestRows): foreach ($latestRows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['sensor_uid']) ?></td>
        <td><?= htmlspecialchars($r['type']) ?></td>
        <td><?= htmlspecialchars($r['value']) . ' ' . htmlspecialchars($r['unit']) ?></td>
        <td><?= htmlspecialchars($r['created_at']) ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="4">No data yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  <h2 id="history">Latest history (50 rows)</h2>
  <table>
    <thead><tr><th>#</th><th>Sensor</th><th>Type</th><th>Value</th><th>Time</th></tr></thead>
    <tbody>
    <?php if ($historyRows): foreach ($historyRows as $row): ?>
      <tr>
        <td><?= (int)$row['id'] ?></td>
        <td><?= htmlspecialchars($row['sensor_uid']) ?></td>
        <td><?= htmlspecialchars($row['type']) ?></td>
        <td><?= htmlspecialchars($row['value']) . ' ' . htmlspecialchars($row['unit']) ?></td>
        <td><?= htmlspecialchars($row['created_at']) ?></td>
      </tr>
    <?php endforeach; else: ?>
      <tr><td colspan="5">No data was found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>

  </main>
    </div>
    
</body>
</html>
