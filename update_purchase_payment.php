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

// Corrects one recorded payment: date, mode, amount, reference or note.
// The payment stays on the same bill and distributor.
$body = json_decode(file_get_contents("php://input"), true);
if (!$body) pp_json(400, ["status"=>"error","message"=>"Invalid JSON"]);

$id        = intval($body["id"] ?? 0);
$updatedBy = intval($body["updatedBy"] ?? 0);
if ($id <= 0) pp_json(400, ["status"=>"error","message"=>"id required"]);

[$l, $err] = pp_clean_line($body);
if ($err) pp_json(400, ["status"=>"error","message"=>$err]);

ensure_purchase_payment_audit($conn);

$stmt = $conn->prepare("SELECT purchase_id FROM purchase_payments WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$row) pp_json(404, ["status"=>"error","message"=>"Payment not found — it may have been deleted"]);

$stmt = $conn->prepare("
  UPDATE purchase_payments
  SET pay_date=?, mode=?, amount=?, reference_no=?, note=?, updated_by=NULLIF(?,0), updated_at=NOW()
  WHERE id=?
");
$stmt->bind_param("ssdssii", $l["payDate"], $l["mode"], $l["amount"], $l["referenceNo"], $l["note"], $updatedBy, $id);
if (!$stmt->execute()) pp_json(500, ["status"=>"error","message"=>"Update failed: " . $stmt->error]);
$stmt->close();

$purchaseId = intval($row["purchase_id"] ?? 0);
echo json_encode([
  "status" => "success",
  "message" => "Payment updated",
  "data" => ["id" => $id, "bill" => $purchaseId > 0 ? pp_bill_summary($conn, $purchaseId) : null],
]);
