<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);
$id = intval($data["id"] ?? 0);

if ($id <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Missing id"]); exit; }

$stmt = $conn->prepare("DELETE FROM invoices WHERE id=?");
$stmt->bind_param("i", $id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Delete failed: ".$stmt->error]);
  exit;
}

echo json_encode(["status"=>"success","message"=>"Deleted"]);
