<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$cardId    = intval($data["cardId"] ?? 0);
$invoiceId = intval($data["invoiceId"] ?? 0);
$invoiceNo = trim((string)($data["invoiceNo"] ?? ""));
$updatedBy = intval($data["updatedBy"] ?? 0);

if ($cardId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "cardId is required"]);
  exit;
}

$conn->begin_transaction();
try {
  $stmt = $conn->prepare("SELECT id, status FROM loyalty_cards WHERE id=? FOR UPDATE");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $card = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$card) throw new Exception("Card not found");
  if ($card["status"] === "redeemed") throw new Exception("Already redeemed");
  if ($card["status"] !== "completed") throw new Exception("Card must have 5 stamps before redemption");

  $invIdParam = $invoiceId > 0 ? $invoiceId : null;
  $invNoParam = $invoiceNo !== "" ? $invoiceNo : null;

  $stmt = $conn->prepare("
    UPDATE loyalty_cards
    SET status='redeemed', redeemed_at=NOW(),
        redeemed_invoice_id=?, redeemed_invoice_no=?,
        updated_by=NULLIF(?,0)
    WHERE id=?
  ");
  $stmt->bind_param("isii", $invIdParam, $invNoParam, $updatedBy, $cardId);
  $stmt->execute();
  $stmt->close();

  $conn->commit();
  echo json_encode(["status" => "success"]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
