<?php
// settings_set.php - saves the T_ON threshold into devices.t_on
header('Content-Type: application/json; charset=utf-8');

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(["error"=>"db_connect"]); exit; }
$mysqli->set_charset("utf8mb4");

$device_uid = $_POST['device_uid'] ?? '';
$t_on_param = $_POST['t_on'] ?? null;

if ($device_uid === '' || $t_on_param === null) {
  http_response_code(400); echo json_encode(["error"=>"missing_params"]); exit;
}
$t_on = (float)$t_on_param;

// find device_id
$stmt = $mysqli->prepare("SELECT id FROM devices WHERE device_uid=?");
$stmt->bind_param("s", $device_uid);
$stmt->execute();
$res = $stmt->get_result();
$dev = $res->fetch_assoc();
$stmt->close();

if (!$dev) { http_response_code(404); echo json_encode(["error"=>"device_not_found"]); exit; }
$device_id = (int)$dev['id'];

// save t_on
$u = $mysqli->prepare("UPDATE devices SET t_on=? WHERE id=?");
$u->bind_param("di", $t_on, $device_id);
$u->execute();
$u->close();

// (optional) set the relay to AUTO when the threshold changes
$up = $mysqli->prepare("
  INSERT INTO relay_desired (device_id, mode, state, updated_at)
  VALUES (?, 'auto', NULL, NOW())
  ON DUPLICATE KEY UPDATE mode='auto', state=NULL, updated_at=NOW()
");
$up->bind_param("i", $device_id);
$up->execute();
$up->close();

$mysqli->close();
echo json_encode(["ok"=>true, "device_uid"=>$device_uid, "t_on"=>$t_on]);
