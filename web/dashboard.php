<?php
include 'db.php';
include 'script.php';
?>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Boiler Dashboard</title>
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

<section class="metrics-grid" id="metrics">
  <article class="card gauge">
    <h3>Temperature</h3>
    <canvas id="gaugeTemp" height="160"></canvas>
    <div class="mini-meta">
      <small>
        <?= $metrics['temperature'] ? htmlspecialchars($metrics['temperature']['created_at']) : 'N/A' ?>
      </small>
    </div>
  </article>
  <article class="card gauge">
    <h3>Voltage</h3>
    <canvas id="gaugeVolt" height="160"></canvas>
    <div class="mini-meta">
      <small>
        <?= $metrics['voltage'] ? htmlspecialchars($metrics['voltage']['created_at']) : 'N/A' ?>
      </small>
    </div>
  </article>
  <article class="card gauge">
    <h3>Current</h3>
    <canvas id="gaugeCurr" height="160"></canvas>
    <div class="mini-meta">
      <small>
        <?= $metrics['current'] ? htmlspecialchars($metrics['current']['created_at']) : 'N/A' ?>
      </small>
    </div>
  </article>

  <article class="card chart span-2">
    <h3>Latest history (50 rows)</h3>
    <canvas id="lineTemp" height="220"></canvas>
  </article>

  <article class="card chart">
    <h3>Voltage & Current - Latest History</h3>
    <canvas id="barVR" height="220"></canvas>
  </article>
</section>
    <?php include 'script.php'; ?>
</body>
</html>
