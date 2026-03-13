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

function strv($v){ return trim((string)($v ?? "")); }
function num($v){
  if ($v === null) return 0;
  if (is_numeric($v)) return floatval($v);
  $s = trim((string)$v);
  if ($s === "") return 0;
  $s = str_replace([",","₹","Rs.","INR"], "", $s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  return is_numeric($s) ? floatval($s) : 0;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid JSON"]);
  exit;
}

$distributorId = intval($body["distributorId"] ?? 0);
$purchaseIdRaw = $body["purchaseId"] ?? null; // may be null
$payDate       = strv($body["payDate"] ?? "");
$mode          = strv($body["mode"] ?? "Cash");
$amount        = num($body["amount"] ?? 0);
$referenceNo   = strv($body["referenceNo"] ?? "");
$note          = strv($body["note"] ?? "");

// Normalize purchaseId: allow null
$purchaseId = null;
if ($purchaseIdRaw !== null && $purchaseIdRaw !== "" && $purchaseIdRaw !== 0 && $purchaseIdRaw !== "0") {
  $purchaseId = intval($purchaseIdRaw);
}

$allowedModes = ["Cash","UPI","Card","Bank","Cheque","Other"];

if ($distributorId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"distributorId required"]);
  exit;
}
if ($payDate === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"payDate required"]);
  exit;
}
if (!in_array($mode, $allowedModes)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid mode"]);
  exit;
}
if ($amount <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"amount must be > 0"]);
  exit;
}

// Validate distributor exists
$stmtD = $conn->prepare("SELECT id FROM distributors WHERE id=? LIMIT 1");
$stmtD->bind_param("i", $distributorId);
$stmtD->execute();
$resD = $stmtD->get_result();
if ($resD->num_rows === 0) {
  $stmtD->close();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Distributor not found"]);
  exit;
}
$stmtD->close();

// If purchaseId provided, validate purchase belongs to distributor
if ($purchaseId !== null) {
  $stmtP = $conn->prepare("SELECT id FROM purchase_bills WHERE id=? AND distributor_id=? LIMIT 1");
  $stmtP->bind_param("ii", $purchaseId, $distributorId);
  $stmtP->execute();
  $resP = $stmtP->get_result();
  if ($resP->num_rows === 0) {
    $stmtP->close();
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Purchase bill not found for this distributor"]);
    exit;
  }
  $stmtP->close();
}

// Insert payment
$conn->begin_transaction();

try {
  // Use NULLIF to store purchase_id as NULL if 0
  $stmt = $conn->prepare("
    INSERT INTO purchase_payments
      (distributor_id, purchase_id, pay_date, mode, amount, reference_no, note)
    VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?)
  ");

  $pid = ($purchaseId === null) ? 0 : $purchaseId;

  $stmt->bind_param(
    "iissdss",
    $distributorId,
    $pid,
    $payDate,
    $mode,
    $amount,
    $referenceNo,
    $note
  );

  if (!$stmt->execute()) {
    throw new Exception("Insert payment failed: " . $stmt->error);
  }

  $paymentId = $stmt->insert_id;
  $stmt->close();

  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Payment added",
    "data" => [
      "paymentId" => $paymentId,
      "distributorId" => $distributorId,
      "purchaseId" => $purchaseId,
      "payDate" => $payDate,
      "mode" => $mode,
      "amount" => $amount,
      "referenceNo" => $referenceNo,
      "note" => $note
    ]
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
