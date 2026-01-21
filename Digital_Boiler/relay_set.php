<?php
// relay_set.php - set mode/state from the web UI
header('Content-Type: application/json; charset=utf-8');

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(["error"=>"db_connect"]); exit; }
$mysqli->set_charset("utf8mb4");

$device_uid = $_REQUEST['device_uid'] ?? '';
$mode       = $_REQUEST['mode'] ?? null;   // 'auto' | 'manual'
$stateParam = $_REQUEST['state'] ?? null;  // 0|1 or empty when auto

if ($device_uid === '' || $mode === null) { http_response_code(400); echo json_encode(["error"=>"missing_params"]); exit; }

// find device_id
$sd = $mysqli->prepare("SELECT id FROM devices WHERE device_uid=?");
$sd->bind_param("s", $device_uid);
$sd->execute();
$dev = $sd->get_result()->fetch_assoc();
$sd->close();
if (!$dev) { http_response_code(404); echo json_encode(["error"=>"device_not_found"]); exit; }
$device_id = (int)$dev['id'];

if ($mode === 'manual') {
  if ($stateParam === null || !in_array((int)$stateParam, [0,1], true)) {
    http_response_code(400); echo json_encode(["error"=>"state_required_for_manual"]); exit;
  }
  $state = (int)$stateParam;
  $q = $mysqli->prepare("
    INSERT INTO relay_desired (device_id, mode, state, updated_at)
    VALUES (?, 'manual', ?, NOW())
    ON DUPLICATE KEY UPDATE mode='manual', state=VALUES(state), updated_at=NOW()
  ");
  $q->bind_param("ii", $device_id, $state);
} else { // auto
  $q = $mysqli->prepare("
    INSERT INTO relay_desired (device_id, mode, state, updated_at)
    VALUES (?, 'auto', NULL, NOW())
    ON DUPLICATE KEY UPDATE mode='auto', state=NULL, updated_at=NOW()
  ");
  $q->bind_param("i", $device_id);
}
$q->execute();
$q->close();
$mysqli->close();

echo json_encode(["ok"=>true, "device_uid"=>$device_uid, "mode"=>$mode, "state"=>$stateParam]);
