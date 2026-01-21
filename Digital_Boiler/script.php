<?php

// fallback if they're not defined
$historyRows = $historyRows ?? [];
$latestByUid = $latestByUid ?? [];

// Get the latest values (fallback: if latestByUid doesn't exist, extract them from historyRows)
$lastTemp = null;
$lastVolt = null;
$lastCurr = null;
$lastFlow = null;
$lastEnergy = null;

if (!empty($latestByUid)) {
  $lastTemp   = isset($latestByUid['temp-1']) ? (float)$latestByUid['temp-1'] : null;
  $lastVolt   = isset($latestByUid['zmpt-1']) ? (float)$latestByUid['zmpt-1'] : null;
  $lastCurr   = isset($latestByUid['acs-1'])  ? (float)$latestByUid['acs-1']  : null;
  $lastFlow   = isset($latestByUid['flow-1']) ? (float)$latestByUid['flow-1'] : null;
  $lastEnergy = isset($latestByUid['energy-1']) ? (float)$latestByUid['energy-1'] : null;
} else {
  // extract from historyRows (taking the latest row for each uid)
  foreach ($historyRows as $r) {
    $uid = $r['sensor_uid'] ?? '';
    $val = isset($r['value']) ? (float)$r['value'] : null;
    if ($uid === 'temp-1' && $lastTemp === null) $lastTemp = $val;
    if ($uid === 'zmpt-1' && $lastVolt === null) $lastVolt = $val;
    if ($uid === 'acs-1'  && $lastCurr === null) $lastCurr = $val;
    if ($uid === 'flow-1' && $lastFlow === null) $lastFlow = $val;
    if ($uid === 'energy-1' && $lastEnergy === null) $lastEnergy = $val;
  }
}

// For chart: extract time series (from oldest to newest)
$labels = [];
$tempSeries = [];
$voltSeries = [];
$currSeries = [];
$energySeries = [];

if (!empty($historyRows)) {
  $rows = array_reverse($historyRows); // so the chart runs normally (old -> new)

  // If historyRows is mixed (with different sensor_uid values over time),
  // we fill with null for sensors that don't have a value at that timestamp.
  foreach ($rows as $r) {
    $ts  = $r['created_at'] ?? $r['time'] ?? '';
    $uid = $r['sensor_uid'] ?? '';
    $val = isset($r['value']) ? (float)$r['value'] : null;

    if ($ts === '') continue;

    $labels[] = $ts;

    $tempSeries[]   = ($uid === 'temp-1')   ? $val : null;
    $voltSeries[]   = ($uid === 'zmpt-1')   ? $val : null;
    $currSeries[]   = ($uid === 'acs-1')    ? $val : null;
    $energySeries[] = ($uid === 'energy-1') ? $val : null;
  }
}

?>
<script>
/* ------------ Safe helpers ------------ */
function $(id){ return document.getElementById(id); }

function safeNumber(x, fallback=0){
  const n = Number(x);
  return Number.isFinite(n) ? n : fallback;
}

/* ------------ Gauge (canvas semicircle) ------------ */
function drawGauge(canvasId, value, minV, maxV, label){
  const c = $(canvasId);
  if (!c) return;

  const ctx = c.getContext("2d");
  if (!ctx) return;

  const w = c.width;
  const h = c.height;

  ctx.clearRect(0, 0, w, h);

  // parameters
  const cx = w/2;
  const cy = h*0.95;
  const r  = Math.min(w, h) * 0.42;

  // clamp
  const v = Math.max(minV, Math.min(maxV, value));
  const pct = (v - minV) / (maxV - minV); // 0..1

  // base
  ctx.lineWidth = 18;
  ctx.beginPath();
  ctx.arc(cx, cy, r, Math.PI, 0);
  ctx.strokeStyle = "rgba(0,0,0,0.10)";
  ctx.stroke();

  // progress
  ctx.beginPath();
  ctx.arc(cx, cy, r, Math.PI, Math.PI + (Math.PI * pct));
  ctx.strokeStyle = "rgba(0,150,200,0.75)";
  ctx.stroke();

  // text
  ctx.font = "bold 22px Arial";
  ctx.fillStyle = "#0b2b3a";
  ctx.textAlign = "center";
  ctx.fillText(String(value.toFixed(1)), cx, h*0.60);

  ctx.font = "14px Arial";
  ctx.fillStyle = "rgba(0,0,0,0.60)";
  ctx.fillText(label, cx, h*0.78);
}

/* ------------ Main ------------ */
document.addEventListener("DOMContentLoaded", () => {
  // Latest values from PHP
  const latest = {
    temp:  <?= json_encode($lastTemp, JSON_UNESCAPED_UNICODE) ?>,
    volt:  <?= json_encode($lastVolt, JSON_UNESCAPED_UNICODE) ?>,
    curr:  <?= json_encode($lastCurr, JSON_UNESCAPED_UNICODE) ?>,
    flow:  <?= json_encode($lastFlow, JSON_UNESCAPED_UNICODE) ?>,
    energy:<?= json_encode($lastEnergy, JSON_UNESCAPED_UNICODE) ?>
  };

  // Gauge (if canvas exists)
  if ($("gaugeTemp")) drawGauge("gaugeTemp", safeNumber(latest.temp, 0), 0, 100, "C");
  if ($("gaugeVolt")) drawGauge("gaugeVolt", safeNumber(latest.volt, 0), 0, 260, "V");
  if ($("gaugeCurr")) drawGauge("gaugeCurr", safeNumber(latest.curr, 0), 0, 40,  "A");

  // If Chart.js is not loaded, do not continue
  if (!window.Chart) {
    console.error("Chart.js was not loaded (missing Chart.js script tag).");
    return;
  }

  // Series for chart
  const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  const tempS  = <?= json_encode($tempSeries) ?>;
  const voltS  = <?= json_encode($voltSeries) ?>;
  const currS  = <?= json_encode($currSeries) ?>;
  const energS = <?= json_encode($energySeries) ?>;

  // Temperature line chart (if it exists)
  const elLineTemp = $("lineTemp");
  if (elLineTemp) {
    new Chart(elLineTemp, {
      type: "line",
      data: {
        labels,
        datasets: [{
          label: "Temperature (C)",
          data: tempS,
          spanGaps: true,
          tension: 0.25
        }]
      },
      options: {
        responsive: true,
        interaction: { mode: "index", intersect: false },
        plugins: { legend: { display: true } },
        scales: {
          x: { ticks: { maxTicksLimit: 6 } },
          y: { beginAtZero: true }
        }
      }
    });
  }

  // Voltage & Current bar chart (if it exists)
  const elBarVR = $("barVR");
  if (elBarVR) {
    new Chart(elBarVR, {
      type: "bar",
      data: {
        labels,
        datasets: [
          { label: "Voltage (V)", data: voltS },
          { label: "Current (A)", data: currS }
        ]
      },
      options: {
        responsive: true,
        interaction: { mode: "index", intersect: false },
        plugins: { legend: { display: true } },
        scales: {
          x: { ticks: { maxTicksLimit: 6 } },
          y: { beginAtZero: true }
        }
      }
    });
  }

  // Optional: energy (if you have the canvas in HTML)
  const elBarEnergy = $("barEnergy");
  if (elBarEnergy) {
    new Chart(elBarEnergy, {
      type: "bar",
      data: {
        labels,
        datasets: [{ label: "Energy (kWh)", data: energS }]
      },
      options: {
        responsive: true,
        plugins: { legend: { display: true } },
        scales: {
          x: { ticks: { maxTicksLimit: 6 } },
          y: { beginAtZero: true }
        }
      }
    });
  }
});
</script>
