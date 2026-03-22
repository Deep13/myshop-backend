<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

// Upload purchase bill file (Excel, PDF, Image)
// Stores file and returns path for reference

$uploadDir = __DIR__ . "/uploads/bills/";
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

if (!isset($_FILES["file"])) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "No file uploaded"]);
  exit;
}

$file = $_FILES["file"];
$allowed = ["xlsx", "xls", "csv", "pdf", "jpg", "jpeg", "png", "webp"];
$ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "File type not allowed. Use: " . implode(", ", $allowed)]);
  exit;
}

if ($file["size"] > 10 * 1024 * 1024) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "File too large. Max 10MB."]);
  exit;
}

$newName = "bill_" . date("Ymd_His") . "_" . uniqid() . "." . $ext;
$dest = $uploadDir . $newName;

if (!move_uploaded_file($file["tmp_name"], $dest)) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Failed to save file"]);
  exit;
}

echo json_encode([
  "status"    => "success",
  "file_name" => $newName,
  "file_path" => "uploads/bills/" . $newName,
  "file_type" => $ext,
  "file_size" => $file["size"],
]);
