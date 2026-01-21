<?php

// index.php
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// device we will display (you can make it dynamic with ?device=ESP32-BOILER-01)
$device_uid = $_GET['device'] ?? "ESP32-BOILER-01";

// Get the device ID + name for display
$stmt = $conn->prepare("SELECT id, COALESCE(name, device_uid) AS display_name FROM devices WHERE device_uid=?");
$stmt->bind_param("s", $device_uid);
$stmt->execute();
$res = $stmt->get_result();
$device = $res->fetch_assoc();
$stmt->close();

if (!$device) { $conn->close(); die("Device '".htmlspecialchars($device_uid, ENT_QUOTES)."' not found. First send data from ESP32."); }
$device_id   = (int)$device['id'];
$device_name = htmlspecialchars($device['display_name'], ENT_QUOTES);

// Latest reading for each sensor
$sqlLatestPerSensor = "
SELECT s.sensor_uid, s.type, s.unit, r.value, r.created_at
FROM sensors s
JOIN (
  SELECT sensor_id, MAX(created_at) AS last_time
  FROM readings
  GROUP BY sensor_id
) t ON t.sensor_id = s.id
JOIN readings r ON r.sensor_id = s.id AND r.created_at = t.last_time
WHERE s.device_id = ?
ORDER BY s.sensor_uid ASC
";
$stmt = $conn->prepare($sqlLatestPerSensor);
$stmt->bind_param("i", $device_id);
$stmt->execute();
$latest = $stmt->get_result();
$latestRows = $latest->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Last 50 readings
$sqlHistory = "
SELECT r.id, s.sensor_uid, s.type, s.unit, r.value, r.created_at
FROM readings r
JOIN sensors s ON s.id = r.sensor_id
WHERE s.device_id = ?
ORDER BY r.created_at DESC
LIMIT 50
";
$stmt = $conn->prepare($sqlHistory);
$stmt->bind_param("i", $device_id);
$stmt->execute();
$hist = $stmt->get_result();
$historyRows = $hist->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- 3 metrics (latest values) from $latestRows ----
function latestMetricsFromArray(array $latestRows): array {
  $pref = ['temperature'=>null,'voltage'=>null,'current'=>null];
  foreach ($latestRows as $r) {
    $t = strtolower($r['type'] ?? '');
    if (array_key_exists($t, $pref)) {
      if (!$pref[$t] || strtotime($r['created_at']) > strtotime($pref[$t]['created_at'])) {
        $pref[$t] = $r;
      }
    }
  }
  return $pref;
}
$meta = [
  'temperature' => ['label'=>'Temperature', 'unit'=>'C'],
  'voltage'     => ['label'=>'Voltage',     'unit'=>'V' ],
  'current'     => ['label'=>'Current',     'unit'=>'A' ],
];

$metrics = latestMetricsFromArray($latestRows ?? []);

// ---- Series for chart from $historyRows (latest 50) ----
// we sort them ascending so the lines go from past to present
$histAsc = array_reverse($historyRows ?? []);
$series = [
  'temperature' => ['labels'=>[], 'values'=>[]],
  'voltage'     => ['labels'=>[], 'values'=>[]],
  'current'     => ['labels'=>[], 'values'=>[]],
];
foreach ($histAsc as $r) {
  $type = strtolower($r['type']);
  if (!isset($series[$type])) continue;
  // short time label
  $label = date('H:i', strtotime($r['created_at']));
  $series[$type]['labels'][] = $label;
  $series[$type]['values'][] = (float)$r['value'];
}

// latest relay state
$sqlRelay = "SELECT state, created_at FROM relay_logs WHERE device_id=? ORDER BY created_at DESC LIMIT 1";
$stmt = $conn->prepare($sqlRelay);
$stmt->bind_param("i", $device_id);
$stmt->execute();
$relayRes = $stmt->get_result();
$relayRow = $relayRes->fetch_assoc();
$stmt->close();

// current desired state (auto/manual) for UI
$desired = ['mode'=>'auto','state'=>null];
$q = $conn->prepare("SELECT mode, state, timer_enable, timer_duration_min, target_temp, timer_updated_at FROM relay_desired WHERE device_id=?");
$q->bind_param("i", $device_id);
$q->execute();
$dres = $q->get_result()->fetch_assoc();
$q->close();
if ($dres) { $desired['mode']=$dres['mode']; $desired['state']=$dres['state']; }

// read T_ON threshold
$tOnVal = null;
$st = $conn->prepare("SELECT t_on FROM devices WHERE id=?");
$st->bind_param("i", $device_id);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if ($row && $row['t_on'] !== null) { $tOnVal = (float)$row['t_on']; }

$conn->close();

?>
