<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$cardId = intval($data["cardId"] ?? 0);

if ($cardId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "cardId is required"]);
  exit;
}

$conn->begin_transaction();
try {
  // Confirm the card exists
  $stmt = $conn->prepare("SELECT id, phone, card_number FROM loyalty_cards WHERE id=? FOR UPDATE");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $card = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$card) throw new Exception("Card not found");

  // Drop stamps first, then the card.
  $stmt = $conn->prepare("DELETE FROM loyalty_stamps WHERE card_id=?");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $stampsRemoved = $stmt->affected_rows;
  $stmt->close();

  $stmt = $conn->prepare("DELETE FROM loyalty_cards WHERE id=?");
  $stmt->bind_param("i", $cardId);
  $stmt->execute();
  $stmt->close();

  $conn->commit();
  echo json_encode([
    "status"        => "success",
    "deletedCardId" => $cardId,
    "stampsRemoved" => $stampsRemoved,
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
