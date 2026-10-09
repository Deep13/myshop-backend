<?php
// Remembers which column of a distributor's Excel bill holds which field, so the
// mapping is asked for once per distributor and reused afterwards.
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["status" => "error", "message" => "POST required"]);
  exit;
}

include "db.php";

$body    = json_decode(file_get_contents("php://input"), true);
$distId  = intval($body["distributorId"] ?? 0);
$headers = $body["headers"] ?? null;
$colMap  = $body["colMap"]  ?? null;

if ($distId <= 0 || !is_array($headers) || !is_array($colMap)) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "distributorId, headers and colMap are required"]);
  exit;
}

$headersJson = json_encode(array_values($headers));
$colMapJson  = json_encode($colMap);
// Keyed on the column layout, so a distributor who sends two different formats
// keeps a mapping for each rather than overwriting one with the other.
$hash = md5($headersJson);

$stmt = $conn->prepare("
  INSERT INTO distributor_mappings (distributor_id, headers_hash, headers_json, col_map_json)
  VALUES (?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE col_map_json = VALUES(col_map_json),
                          headers_json = VALUES(headers_json),
                          updated_at   = NOW()
");
$stmt->bind_param("isss", $distId, $hash, $headersJson, $colMapJson);

if ($stmt->execute()) {
  echo json_encode(["status" => "success", "hash" => $hash]);
} else {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $stmt->error]);
}
$stmt->close();
