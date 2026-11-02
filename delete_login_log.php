<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$body = json_decode(file_get_contents("php://input"), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

// How much history to keep. Floored at 30 days on purpose: this endpoint exists
// to trim an audit log, not to empty it, and a floor means a stray 0 or 1 in the
// request cannot wipe the record of who signed in this morning.
$days = intval($body["days"] ?? 30);
if ($days < 30) $days = 30;

// Without confirm the call only counts. The screen uses that to show a real
// number in the confirmation rather than asking the user to approve a guess.
$confirm = !empty($body["confirm"]);

$stmt = $conn->prepare("SELECT COUNT(*) AS n, MIN(logged_at) AS oldest
                        FROM login_log WHERE logged_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->bind_param("i", $days);
$stmt->execute();
$pre = $stmt->get_result()->fetch_assoc();
$stmt->close();
$matched = intval($pre["n"] ?? 0);

if (!$confirm) {
  echo json_encode([
    "status"  => "success",
    "preview" => true,
    "days"    => $days,
    "matched" => $matched,
    "oldest"  => $pre["oldest"],
  ]);
  exit;
}

$stmt = $conn->prepare("DELETE FROM login_log WHERE logged_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
$stmt->bind_param("i", $days);
if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Delete failed: " . $stmt->error]);
  exit;
}
$deleted = $stmt->affected_rows;
$stmt->close();

$rem = $conn->query("SELECT COUNT(*) AS n, MIN(logged_at) AS oldest FROM login_log")->fetch_assoc();

echo json_encode([
  "status"    => "success",
  "preview"   => false,
  "days"      => $days,
  "deleted"   => $deleted,
  "remaining" => intval($rem["n"] ?? 0),
  "oldest"    => $rem["oldest"],
]);
