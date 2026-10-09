<?php
// Returns the saved Excel column mapping for a distributor, so the second and
// every later bill from them imports without asking again.
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$distId = intval($_GET["distributorId"] ?? 0);
$hash   = trim($_GET["hash"] ?? "");

if ($distId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "distributorId required"]);
  exit;
}

if ($hash !== "") {
  // The exact layout this file has, when the caller knows it
  $stmt = $conn->prepare("SELECT headers_json, col_map_json, updated_at
                          FROM distributor_mappings
                          WHERE distributor_id = ? AND headers_hash = ? LIMIT 1");
  $stmt->bind_param("is", $distId, $hash);
} else {
  // Otherwise the distributor's most recent mapping. Matching on the exact
  // column list is too brittle — the number of trailing empty columns varies
  // between bills from the same supplier — so this is offered for review
  // rather than applied silently.
  // updated_at is NULL until a mapping is saved a second time, so a brand-new
  // one would otherwise rank below an older mapping that had been edited.
  $stmt = $conn->prepare("SELECT headers_json, col_map_json,
                                 COALESCE(updated_at, created_at) AS updated_at
                          FROM distributor_mappings
                          WHERE distributor_id = ?
                          ORDER BY COALESCE(updated_at, created_at) DESC, id DESC LIMIT 1");
  $stmt->bind_param("i", $distId);
}
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($row) {
  echo json_encode([
    "status"  => "success",
    "headers" => json_decode($row["headers_json"], true),
    "colMap"  => json_decode($row["col_map_json"], true),
    "savedAt" => $row["updated_at"],
  ]);
} else {
  echo json_encode(["status" => "success", "colMap" => null]);
}
