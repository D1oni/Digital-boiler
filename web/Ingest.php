<?php
header('Content-Type: application/json; charset=utf-8');
header('Connection: close');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(["error" => "Only POST allowed"]);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["error" => "Invalid JSON"]);
  exit;
}

$device_uid  = $data['device_uid'] ?? ($data['device_id'] ?? null);
$readings    = $data['readings'] ?? null;
$relay_state = array_key_exists('relay_state', $data) ? (int)!!$data['relay_state'] : null;

if (!$device_uid || !is_array($readings) || count($readings) === 0) {
  http_response_code(400);
  echo json_encode(["error" => "Missing device_uid/device_id or readings"]);
  exit;
}

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
$mysqli->set_charset("utf8mb4");

$mysqli->begin_transaction();

try {
  // ---- device ----
  $devId = null;
  $sel = $mysqli->prepare("SELECT id FROM devices WHERE device_uid=? LIMIT 1");
  $sel->bind_param("s", $device_uid);
  $sel->execute();
  $sel->bind_result($devId);
  $sel->fetch();
  $sel->close();

  if (!$devId) {
    $ins = $mysqli->prepare("INSERT INTO devices (device_uid, name) VALUES (?,?)");
    $name = $data['device_name'] ?? $device_uid;
    $ins->bind_param("ss", $device_uid, $name);
    $ins->execute();
    $devId = $ins->insert_id;
    $ins->close();
  }

  // ---- prepared statements ----
  $upsertSensor = $mysqli->prepare("
    INSERT INTO sensors (device_id, sensor_uid, type, unit)
    VALUES (?,?,?,?)
    ON DUPLICATE KEY UPDATE type=VALUES(type), unit=VALUES(unit)
  ");

  $getSensor = $mysqli->prepare("SELECT id FROM sensors WHERE device_id=? AND sensor_uid=? LIMIT 1");

  $insReading = $mysqli->prepare("INSERT INTO readings (sensor_id, value) VALUES (?,?)");

  $inserted = 0;

  foreach ($readings as $r) {
    $sensor_uid = $r['sensor_uid'] ?? null;
    $type       = $r['type'] ?? '';
    $unit       = $r['unit'] ?? '';
    $valueRaw   = $r['value'] ?? null;

    if (!$sensor_uid || $valueRaw === null || !is_numeric($valueRaw)) continue;
    $value = (float)$valueRaw;

    // upsert sensor
    $upsertSensor->bind_param("isss", $devId, $sensor_uid, $type, $unit);
    $upsertSensor->execute();

    // get sensor id (IMPORTANT: store_result + free_result)
    $sid = null;
    $getSensor->bind_param("is", $devId, $sensor_uid);
    $getSensor->execute();
    $getSensor->store_result();
    $getSensor->bind_result($sid);
    $getSensor->fetch();
    $getSensor->free_result();

    if ($sid !== null) {
      $insReading->bind_param("id", $sid, $value);
      $insReading->execute();
      $inserted++;
    }
  }

  // ---- relay log ----
  if ($relay_state !== null) {
    $insRelay = $mysqli->prepare("INSERT INTO relay_logs (device_id, state) VALUES (?,?)");
    $insRelay->bind_param("ii", $devId, $relay_state);
    $insRelay->execute();
    $insRelay->close();
  }

  $getSensor->close();
  $upsertSensor->close();
  $insReading->close();

  $mysqli->commit();
  echo json_encode(["ok" => true, "inserted" => $inserted, "device_uid" => $device_uid]);
} catch (Throwable $e) {
  $mysqli->rollback();
  http_response_code(500);
  echo json_encode(["error" => "Server error", "detail" => $e->getMessage()]);
} finally {
  $mysqli->close();
}
