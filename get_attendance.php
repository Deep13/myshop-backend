<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

// Optional: restrict to a single user (non-admin viewing their own card)
$forUser = intval($_GET["user_id"] ?? 0);

try {
  $users = [];
  if ($forUser > 0) {
    $stmt = $conn->prepare("SELECT id, name, role, off_mode FROM users WHERE id = ? ORDER BY id");
    $stmt->bind_param("i", $forUser);
  } else {
    $stmt = $conn->prepare("SELECT id, name, role, off_mode FROM users ORDER BY id");
  }
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $users[] = [
      "id"       => intval($r["id"]),
      "name"     => $r["name"],
      "role"     => $r["role"],
      "off_mode" => $r["off_mode"] ?: "rotate",
    ];
  }
  $stmt->close();

  // All records (tiny table; the balance needs full history anyway)
  $records = [];
  if ($forUser > 0) {
    $stmt = $conn->prepare("SELECT user_id, work_date, status FROM attendance_records WHERE user_id = ?");
    $stmt->bind_param("i", $forUser);
    $stmt->execute();
    $res = $stmt->get_result();
  } else {
    $res = $conn->query("SELECT user_id, work_date, status FROM attendance_records");
  }
  while ($r = $res->fetch_assoc()) {
    $records[] = [
      "user_id"   => intval($r["user_id"]),
      "work_date" => $r["work_date"],
      "status"    => $r["status"],
    ];
  }

  echo json_encode(["status" => "success", "users" => $users, "records" => $records]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
