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

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid JSON"]);
  exit;
}

$invoiceId = intval($body["invoiceId"] ?? 0);
$payType   = trim($body["payType"]  ?? $body["mode"] ?? "Cash");
$amount    = floatval($body["amount"] ?? 0);

$allowedModes = ["Cash","UPI","Card","Bank","Cheque","Other"];

if ($invoiceId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"invoiceId required"]);
  exit;
}
if (!in_array($payType, $allowedModes)) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid payment type"]);
  exit;
}
if ($amount <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"amount must be > 0"]);
  exit;
}

// Validate invoice exists
$stmtI = $conn->prepare("SELECT id, received, balance FROM invoices WHERE id=? LIMIT 1");
$stmtI->bind_param("i", $invoiceId);
$stmtI->execute();
$resI = $stmtI->get_result();
if ($resI->num_rows === 0) {
  $stmtI->close();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invoice not found"]);
  exit;
}
$inv = $resI->fetch_assoc();
$stmtI->close();

$conn->begin_transaction();

try {
  // Insert payment record
  $stmt = $conn->prepare("INSERT INTO invoice_payments (invoice_id, pay_type, amount) VALUES (?,?,?)");
  $stmt->bind_param("isd", $invoiceId, $payType, $amount);
  if (!$stmt->execute()) throw new Exception("Insert payment failed: " . $stmt->error);
  $paymentId = $stmt->insert_id;
  $stmt->close();

  // Update invoice received & balance
  $newReceived = floatval($inv["received"]) + $amount;
  $newBalance  = floatval($inv["balance"]) - $amount;
  if ($newBalance < 0) $newBalance = 0;

  $stmtU = $conn->prepare("UPDATE invoices SET received=?, balance=? WHERE id=?");
  $stmtU->bind_param("ddi", $newReceived, $newBalance, $invoiceId);
  if (!$stmtU->execute()) throw new Exception("Update invoice failed: " . $stmtU->error);
  $stmtU->close();

  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Payment recorded",
    "data" => [
      "paymentId" => $paymentId,
      "invoiceId" => $invoiceId,
      "amount" => $amount,
      "newReceived" => $newReceived,
      "newBalance" => $newBalance
    ]
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
