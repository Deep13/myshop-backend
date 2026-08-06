<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"));

$id   = intval($data->id ?? 0);
$name = $conn->real_escape_string(trim($data->name ?? ""));
$role = $conn->real_escape_string(trim($data->role ?? "user"));
$pass = trim($data->password ?? "");
// Off-day pattern: "rotate" or "half:0".."half:6" (empty = leave unchanged)
$offMode = trim($data->off_mode ?? "");
if ($offMode !== "" && $offMode !== "rotate" && !preg_match("/^(half|full):[0-6]$/", $offMode)) {
    echo json_encode(["status" => "error", "message" => "Invalid off_mode"]); exit;
}

if ($id <= 0) { echo json_encode(["status" => "error", "message" => "User ID required"]); exit; }
if ($name === "") { echo json_encode(["status" => "error", "message" => "Username required"]); exit; }

// Check duplicate name (excluding current user)
$check = $conn->query("SELECT id FROM users WHERE name='$name' AND id != $id LIMIT 1");
if ($check->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Username already taken"]);
    exit;
}

if ($pass !== "") {
    // Update name, role, and password
    $hashed = password_hash($pass, PASSWORD_DEFAULT);
    if ($offMode !== "") {
        $stmt = $conn->prepare("UPDATE users SET name=?, pass=?, role=?, off_mode=? WHERE id=?");
        $stmt->bind_param("ssssi", $name, $hashed, $role, $offMode, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, pass=?, role=? WHERE id=?");
        $stmt->bind_param("sssi", $name, $hashed, $role, $id);
    }
} else {
    // Update name and role only
    if ($offMode !== "") {
        $stmt = $conn->prepare("UPDATE users SET name=?, role=?, off_mode=? WHERE id=?");
        $stmt->bind_param("sssi", $name, $role, $offMode, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, role=? WHERE id=?");
        $stmt->bind_param("ssi", $name, $role, $id);
    }
}

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "User updated"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update user"]);
}
$stmt->close();
