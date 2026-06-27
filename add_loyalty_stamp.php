<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$cardId         = intval($data["cardId"] ?? 0);
$invoiceId      = intval($data["invoiceId"] ?? 0);
$invoiceNo      = trim((string)($data["invoiceNo"] ?? ""));
$invoiceAmount  = floatval($data["invoiceAmount"] ?? 0);
$stampedBy      = intval($data["stampedBy"] ?? 0);

if ($cardId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "cardId is required"]);
  exit;
}

$conn->begin_transaction();
try {
  // Lock the card row and re-read status + current stamp count
  $stmt = $conn->prepare("SELECT id, status FROM loyalty_cards WHERE id=? FOR UPDATE");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $card = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$card) throw new Exception("Card not found");
  if ($card["status"] === "redeemed") throw new Exception("This card has already been redeemed");
  if ($card["status"] === "completed") throw new Exception("Card already has 5 stamps. Redeem the ₹100 OFF.");

  // Next stamp_no = current count + 1
  $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM loyalty_stamps WHERE card_id=?");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $cnt = intval($stmt->get_result()->fetch_assoc()["c"]);
  $stmt->close();
  $nextStamp = $cnt + 1;
  if ($nextStamp > 5) throw new Exception("Card already full");

  $invIdParam  = $invoiceId > 0 ? $invoiceId : null;
  $invNoParam  = $invoiceNo !== "" ? $invoiceNo : null;
  $invAmtParam = $invoiceAmount > 0 ? $invoiceAmount : null;

  $stmt = $conn->prepare("
    INSERT INTO loyalty_stamps (card_id, stamp_no, invoice_id, invoice_no, invoice_amount, stamped_by)
    VALUES (?, ?, ?, ?, ?, NULLIF(?, 0))
  ");
  $stmt->bind_param("iiisdi", $cardId, $nextStamp, $invIdParam, $invNoParam, $invAmtParam, $stampedBy);
  $stmt->execute();
  $stamp_id = $conn->insert_id;
  $stmt->close();

  // Mark card completed when the 5th stamp lands
  if ($nextStamp === 5) {
    $stmt = $conn->prepare("UPDATE loyalty_cards SET status='completed', completed_at=NOW(), updated_by=NULLIF(?,0) WHERE id=?");
    $stmt->bind_param("ii", $stampedBy, $cardId);
    $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("UPDATE loyalty_cards SET updated_by=NULLIF(?,0) WHERE id=?");
    $stmt->bind_param("ii", $stampedBy, $cardId);
    $stmt->execute();
    $stmt->close();
  }

  $conn->commit();
  echo json_encode([
    "status"   => "success",
    "stampId"  => $stamp_id,
    "stampNo"  => $nextStamp,
    "complete" => $nextStamp === 5,
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
