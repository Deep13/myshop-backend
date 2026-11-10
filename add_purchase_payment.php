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

// Records payments to a distributor, against one purchase bill or on account.
// Takes either one payment (payDate, mode, amount, referenceNo, note) or
// several in "lines" — e.g. part cash, part UPI. All lines are saved
// together or none are.
$body = json_decode(file_get_contents("php://input"), true);
if (!$body) pp_json(400, ["status"=>"error","message"=>"Invalid JSON"]);

$distributorId = intval($body["distributorId"] ?? 0);
$purchaseId    = intval($body["purchaseId"] ?? 0);   // 0 = on account, no bill
$createdBy     = intval($body["createdBy"] ?? 0);

$rawLines = (isset($body["lines"]) && is_array($body["lines"])) ? $body["lines"] : [$body];
$lines = [];
foreach ($rawLines as $raw) {
  if (!isset($raw["payDate"]) && isset($body["payDate"])) $raw["payDate"] = $body["payDate"];
  [$line, $err] = pp_clean_line($raw);
  if ($err) pp_json(400, ["status"=>"error","message"=>$err]);
  $lines[] = $line;
}
if (!$lines) pp_json(400, ["status"=>"error","message"=>"No payment to save"]);

// The bill decides the distributor, so the two can't disagree
if ($purchaseId > 0) {
  $bill = pp_bill_summary($conn, $purchaseId);
  if (!$bill) pp_json(404, ["status"=>"error","message"=>"Purchase bill not found"]);
  if ($distributorId > 0 && $distributorId !== intval($bill["distributor_id"])) {
    pp_json(400, ["status"=>"error","message"=>"Purchase bill not found for this distributor"]);
  }
  $distributorId = intval($bill["distributor_id"]);
}
if ($distributorId <= 0) pp_json(400, ["status"=>"error","message"=>"distributorId required"]);

$stmtD = $conn->prepare("SELECT id FROM distributors WHERE id=? LIMIT 1");
$stmtD->bind_param("i", $distributorId);
$stmtD->execute();
$found = $stmtD->get_result()->num_rows > 0;
$stmtD->close();
if (!$found) pp_json(400, ["status"=>"error","message"=>"Distributor not found"]);

ensure_purchase_payment_audit($conn);

$conn->begin_transaction();
try {
  $stmt = $conn->prepare("
    INSERT INTO purchase_payments
      (distributor_id, purchase_id, pay_date, mode, amount, reference_no, note, created_by)
    VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?, NULLIF(?,0))
  ");
  $ids = [];
  foreach ($lines as $l) {
    $stmt->bind_param("iissdssi", $distributorId, $purchaseId, $l["payDate"], $l["mode"], $l["amount"], $l["referenceNo"], $l["note"], $createdBy);
    if (!$stmt->execute()) throw new Exception("Insert payment failed: " . $stmt->error);
    $ids[] = $stmt->insert_id;
  }
  $stmt->close();
  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => count($ids) === 1 ? "Payment added" : count($ids) . " payments added",
    "data" => [
      "paymentId" => $ids[0],
      "paymentIds" => $ids,
      "distributorId" => $distributorId,
      "purchaseId" => $purchaseId ?: null,
      "bill" => $purchaseId > 0 ? pp_bill_summary($conn, $purchaseId) : null,
    ],
  ]);
} catch (Exception $e) {
  $conn->rollback();
  pp_json(500, ["status"=>"error","message"=>$e->getMessage()]);
}
