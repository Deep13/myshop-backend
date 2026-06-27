<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$userId   = intval($data["userId"] ?? 0);
$userName = trim((string)($data["userName"] ?? ""));

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$status = "logout";

$stmt = $conn->prepare("INSERT INTO login_log (user_id, user_name, status, ip, user_agent) VALUES (NULLIF(?, 0), ?, ?, ?, ?)");
if ($stmt) {
  $stmt->bind_param("issss", $userId, $userName, $status, $ip, $ua);
  @$stmt->execute();
  $stmt->close();
}

echo json_encode(["status" => "success"]);
