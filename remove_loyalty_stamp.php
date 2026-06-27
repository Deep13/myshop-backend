<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$cardId    = intval($data["cardId"] ?? 0);
$updatedBy = intval($data["updatedBy"] ?? 0);
// Optional: remove this stamp AND every later stamp. Stamps are sequential
// (1..5), so removing one in the middle would leave a gap; cascading keeps
// the invariant that stamp_no = 1..count.
$fromStampNo = intval($data["fromStampNo"] ?? 0); // 0 means "just the last one"

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
  if ($card["status"] === "redeemed") throw new Exception("Card already redeemed — cannot undo");

  // Resolve cutoff: explicit fromStampNo, or default to the last stamp
  if ($fromStampNo <= 0) {
    $stmt = $conn->prepare("SELECT stamp_no FROM loyalty_stamps WHERE card_id=? ORDER BY stamp_no DESC LIMIT 1");
    $stmt->bind_param("i", $cardId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$last) throw new Exception("No stamps to undo");
    $fromStampNo = intval($last["stamp_no"]);
  }

  $stmt = $conn->prepare("DELETE FROM loyalty_stamps WHERE card_id=? AND stamp_no >= ?");
  $stmt->bind_param("ii", $cardId, $fromStampNo);
  $stmt->execute();
  $removedCount = $stmt->affected_rows;
  $stmt->close();
  if ($removedCount === 0) throw new Exception("No stamps to undo");

  // If the 5th stamp was among those removed, the card is no longer 'completed' — back to active
  if ($fromStampNo <= 5 && $card["status"] === "completed") {
    $stmt = $conn->prepare("UPDATE loyalty_cards SET status='active', completed_at=NULL, updated_by=NULLIF(?,0) WHERE id=?");
    $stmt->bind_param("ii", $updatedBy, $cardId);
    $stmt->execute();
    $stmt->close();
  } else {
    $stmt = $conn->prepare("UPDATE loyalty_cards SET updated_by=NULLIF(?,0) WHERE id=?");
    $stmt->bind_param("ii", $updatedBy, $cardId);
    $stmt->execute();
    $stmt->close();
  }

  $conn->commit();
  echo json_encode([
    "status"        => "success",
    "fromStampNo"   => $fromStampNo,
    "removedCount"  => $removedCount,
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
