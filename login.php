<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"));
$name = isset($data->name) ? trim((string)$data->name) : "";
$pass = isset($data->password) ? (string)$data->password : "";

function logLogin($conn, $userId, $userName, $status) {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
  $stmt = $conn->prepare("INSERT INTO login_log (user_id, user_name, status, ip, user_agent) VALUES (?,?,?,?,?)");
  if ($stmt) {
    // user_id may be null on failed-with-unknown-username
    $stmt->bind_param("issss", $userId, $userName, $status, $ip, $ua);
    @$stmt->execute();
    $stmt->close();
  }
}

if ($name === "" || $pass === "") {
  echo json_encode(["status" => "error", "message" => "Username and password required"]);
  exit;
}

$stmt = $conn->prepare("SELECT id, name, pass, role FROM users WHERE name=? LIMIT 1");
$stmt->bind_param("s", $name);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  $stmt->close();
  logLogin($conn, null, $name, "failure");
  echo json_encode(["status" => "error", "message" => "User not found"]);
  exit;
}

$user = $res->fetch_assoc();
$stmt->close();

if (password_verify($pass, $user['pass'])) {
  logLogin($conn, (int)$user["id"], $user["name"], "success");
  echo json_encode([
    "status" => "success",
    "message" => "Login successful",
    "user" => [
      "id" => $user["id"],
      "name" => $user["name"],
      "role" => $user["role"]
    ]
  ]);
} else {
  logLogin($conn, (int)$user["id"], $user["name"], "failure");
  echo json_encode(["status" => "error", "message" => "Invalid password"]);
}
