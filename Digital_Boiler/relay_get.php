<?php
// relay_get.php - returns mode/state and t_on for ESP32
header('Content-Type: application/json; charset=utf-8');

$DB_HOST = "localhost";
$DB_USER = "root";
$DB_PASS = "";
$DB_NAME = "digital_boiler";

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_error) { http_response_code(500); echo json_encode(["error"=>"db_connect"]); exit; }
$mysqli->set_charset("utf8mb4");

$device_uid = $_GET['device_uid'] ?? '';
if ($device_uid === '') { http_response_code(400); echo json_encode(["error"=>"missing_device_uid"]); exit; }

// find device + t_on
$sd = $mysqli->prepare("SELECT id, t_on FROM devices WHERE device_uid=?");
$sd->bind_param("s", $device_uid);
$sd->execute();
$dev = $sd->get_result()->fetch_assoc();
$sd->close();

if (!$dev) { echo json_encode(["mode"=>"auto","state"=>null,"t_on"=>null]); exit; }
$device_id = (int)$dev['id'];
$t_on = isset($dev['t_on']) ? (float)$dev['t_on'] : null;

// read relay desired state
$q = $mysqli->prepare("SELECT mode, state, timer_enable, timer_duration_min, target_temp FROM relay_desired WHERE device_id=?");
$q->bind_param("i", $device_id);
$q->execute();
$r = $q->get_result()->fetch_assoc();
$q->close();

if (!$r) { $r = []; } // always an array

$mode  = $r['mode'] ?? 'auto';
$state = array_key_exists('state', $r) ? $r['state'] : null;

$timer_enable = isset($r['timer_enable']) ? (int)$r['timer_enable'] : 0;
$timer_min    = isset($r['timer_duration_min']) ? (int)$r['timer_duration_min'] : 0;
$target_temp  = isset($r['target_temp']) ? (float)$r['target_temp'] : null;

/* One-shot: if timer_enable=1, then clear it so ESP32 doesn't restart it every 1 second */
if ($timer_enable === 1) {
  $clr = $mysqli->prepare("UPDATE relay_desired SET timer_enable=0 WHERE device_id=?");
  $clr->bind_param("i", $device_id);
  $clr->execute();
  $clr->close();
}

$mysqli->close();

echo json_encode([
  "mode" => $mode,
  "state" => is_null($state) ? null : (int)$state,
  "t_on" => is_null($t_on) ? null : (float)$t_on,

  // TIMER fields that ESP32 expects
  "timer_enable" => $timer_enable,
  "timer_duration_min" => $timer_min,
  "target_temp" => $target_temp
]);
