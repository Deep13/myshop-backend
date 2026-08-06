<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$userId    = intval($data["userId"] ?? 0);
$date      = trim((string)($data["date"] ?? ""));
$status    = $data["status"] ?? null;   // 'absent' | 'half' | 'makeup' | null (= present, delete row)
$updatedBy = intval($data["updatedBy"] ?? 0);

if ($userId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "userId and date (YYYY-MM-DD) required"]);
  exit;
}
$allowed = ["absent", "half", "makeup"];
if ($status !== null && !in_array($status, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "status must be absent | half | makeup | null"]);
  exit;
}

// Only admins may write attendance
$isAdmin = false;
if ($updatedBy > 0) {
  $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $updatedBy);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $isAdmin = $row && $row["role"] === "admin";
}
if (!$isAdmin) {
  http_response_code(403);
  echo json_encode(["status" => "error", "message" => "Only admin can edit attendance"]);
  exit;
}

try {
  if ($status === null) {
    $stmt = $conn->prepare("DELETE FROM attendance_records WHERE user_id = ? AND work_date = ?");
    $stmt->bind_param("is", $userId, $date);
    $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("
      INSERT INTO attendance_records (user_id, work_date, status, updated_by)
      VALUES (?, ?, ?, NULLIF(?, 0))
      ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)
    ");
    $stmt->bind_param("issi", $userId, $date, $status, $updatedBy);
    $stmt->execute();
    $stmt->close();
  }
  echo json_encode(["status" => "success"]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
