<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
  exit;
}

include "db.php";
require_once __DIR__ . "/purchase_payment_lib.php";

// Removes one recorded payment. When purchaseId is sent it must match the
// payment's bill, so a stale screen can't delete a payment off another bill.
$body = json_decode(file_get_contents("php://input"), true);
if (!$body) pp_json(400, ["status"=>"error","message"=>"Invalid JSON"]);

$id = intval($body["id"] ?? 0);
$purchaseId = intval($body["purchaseId"] ?? 0);
if ($id <= 0) pp_json(400, ["status"=>"error","message"=>"id required"]);

$stmt = $conn->prepare("SELECT purchase_id FROM purchase_payments WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) pp_json(404, ["status"=>"error","message"=>"Payment not found — it may already be deleted"]);

$billId = intval($row["purchase_id"] ?? 0);
if ($purchaseId > 0 && $billId !== $purchaseId) {
  pp_json(400, ["status"=>"error","message"=>"This payment belongs to a different bill"]);
}

$stmt = $conn->prepare("DELETE FROM purchase_payments WHERE id=?");
$stmt->bind_param("i", $id);
if (!$stmt->execute()) pp_json(500, ["status"=>"error","message"=>"Delete failed: " . $stmt->error]);
$stmt->close();

echo json_encode([
  "status" => "success",
  "message" => "Payment deleted",
  "data" => ["bill" => $billId > 0 ? pp_bill_summary($conn, $billId) : null],
]);
