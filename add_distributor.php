<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$name  = trim($body["name"] ?? "");
$gstin = trim($body["gstin"] ?? "");
$phone = trim($body["phone"] ?? "");

if ($name === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Distributor name is required"]);
  exit;
}

$stmt = $conn->prepare("INSERT INTO distributors (name, gstin, phone) VALUES (?,?,?)");
$stmt->bind_param("sss", $name, $gstin, $phone);

if (!$stmt->execute()) {
  // Handle duplicate name nicely
  if (strpos($stmt->error, "Duplicate") !== false) {
    http_response_code(409);
    echo json_encode(["status"=>"error","message"=>"Distributor already exists"]);
    exit;
  }
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$stmt->error]);
  exit;
}

$id = $stmt->insert_id;
$stmt->close();

echo json_encode([
  "status"=>"success",
  "data"=>["id"=>$id, "name"=>$name, "gstin"=>$gstin, "phone"=>$phone]
]);
