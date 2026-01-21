<?php
$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_error) { die("DB error: ".$mysqli->connect_error); }
$mysqli->set_charset("utf8mb4");

// Which device
$device_uid = $_GET['device'] ?? "ESP32-BOILER-01";

// find device_id
$stmt = $mysqli->prepare("SELECT id FROM devices WHERE device_uid=?");
$stmt->bind_param("s", $device_uid);
$stmt->execute();
$res = $stmt->get_result();
$dev = $res->fetch_assoc();
$stmt->close();
if (!$dev) { die("Device not found"); }
$device_id = (int)$dev['id'];

// get the sensors for voltage and current
$q = $mysqli->prepare("SELECT id, type FROM sensors WHERE device_id=? AND type IN ('voltage','current')");
$q->bind_param("i", $device_id);
$q->execute();
$sr = $q->get_result();
$volt_id = $curr_id = null;
while ($row = $sr->fetch_assoc()) {
  if ($row['type'] === 'voltage') $volt_id = (int)$row['id'];
  if ($row['type'] === 'current') $curr_id = (int)$row['id'];
}
$q->close();
if (!$volt_id || !$curr_id) { die("Sensors missing"); }

// the latest readings e.g. last 24 hours for these two sensors
$sql = "
  SELECT r.sensor_id, r.value, r.created_at
  FROM readings r
  WHERE r.sensor_id IN (?, ?)
    AND r.created_at >= NOW() - INTERVAL 24 HOUR
  ORDER BY r.created_at ASC
";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("ii", $volt_id, $curr_id);
$stmt->execute();
$res = $stmt->get_result();

// store the values in memory by time
$data = []; // key = timestamp (Y-m-d H:i:s), value = ['V'=>..., 'I'=>...]
while ($row = $res->fetch_assoc()) {
  $ts = $row['created_at'];
  if (!isset($data[$ts])) $data[$ts] = ['V'=>null,'I'=>null];
  if ($row['sensor_id'] == $volt_id) $data[$ts]['V'] = (float)$row['value'];
  if ($row['sensor_id'] == $curr_id) $data[$ts]['I'] = (float)$row['value'];
}
$stmt->close();

// calculate energy for each hour
$energyPerHour = []; // key = hour_start, value = kWh
foreach ($data as $ts => $vi) {
  if ($vi['V'] === null || $vi['I'] === null) continue; // need both V and I
  $P = $vi['V'] * $vi['I'];          // Watt
  $kwh_sample = ($P * 2.0) / 3600000.0; // 2 second interval, kWh

  $hour_start = date('Y-m-d H:00:00', strtotime($ts));
  if (!isset($energyPerHour[$hour_start])) $energyPerHour[$hour_start] = 0.0;
  $energyPerHour[$hour_start] += $kwh_sample;
}

// write into energy_hourly table
$ins = $mysqli->prepare("
  INSERT INTO energy_hourly (device_id, hour_start, kwh)
  VALUES (?, ?, ?)
  ON DUPLICATE KEY UPDATE kwh = VALUES(kwh), created_at = NOW()
");

foreach ($energyPerHour as $hour => $kwh) {
  $ins->bind_param("isd", $device_id, $hour, $kwh);
  $ins->execute();
}
$ins->close();

$mysqli->close();
echo "OK\n";
