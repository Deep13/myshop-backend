<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true) ?: [];
$phone           = trim((string)($data["phone"] ?? ""));
$customerName    = trim((string)($data["customerName"] ?? ""));
$createdBy       = intval($data["createdBy"] ?? 0);
// Optional one-time digitisation of a physical card already handed to the
// customer. Stamps 1..N are created with invoice_no = NULL so they're clearly
// distinguishable from real stamps. Clamp 0..5; 5 marks the card 'completed'.
$preFilledStamps = max(0, min(5, intval($data["preFilledStamps"] ?? 0)));

if ($phone === "") {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "phone is required"]);
  exit;
}

$conn->begin_transaction();
try {
  // Block if there's already a non-redeemed card
  $stmt = $conn->prepare("SELECT id, status FROM loyalty_cards WHERE phone=? AND status<>'redeemed' LIMIT 1");
  $stmt->bind_param("s", $phone);
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($existing) {
    throw new Exception("Customer already has an active card. Redeem the current one first.");
  }

  // Next card_number = max + 1 for this phone
  $stmt = $conn->prepare("SELECT COALESCE(MAX(card_number),0)+1 AS next_no FROM loyalty_cards WHERE phone=?");
  $stmt->bind_param("s", $phone);
  $stmt->execute();
  $nextNo = intval($stmt->get_result()->fetch_assoc()["next_no"]);
  $stmt->close();

  $status = $preFilledStamps === 5 ? "completed" : "active";
  $stmt = $conn->prepare("
    INSERT INTO loyalty_cards (customer_name, phone, card_number, status, completed_at, created_by, updated_by)
    VALUES (?, ?, ?, ?, " . ($preFilledStamps === 5 ? "NOW()" : "NULL") . ", NULLIF(?,0), NULLIF(?,0))
  ");
  $stmt->bind_param("ssisii", $customerName, $phone, $nextNo, $status, $createdBy, $createdBy);
  $stmt->execute();
  $cardId = $conn->insert_id;
  $stmt->close();

  // Pre-fill stamps with NULL invoice_no
  if ($preFilledStamps > 0) {
    $stmt = $conn->prepare("
      INSERT INTO loyalty_stamps (card_id, stamp_no, invoice_no, stamped_by)
      VALUES (?, ?, NULL, NULLIF(?, 0))
    ");
    for ($n = 1; $n <= $preFilledStamps; $n++) {
      $stmt->bind_param("iii", $cardId, $n, $createdBy);
      $stmt->execute();
    }
    $stmt->close();
  }

  $conn->commit();
  echo json_encode([
    "status"           => "success",
    "cardId"           => $cardId,
    "card_number"      => $nextNo,
    "preFilledStamps"  => $preFilledStamps,
    "completed"        => $preFilledStamps === 5,
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
