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

$purchaseId = intval($body["purchaseId"] ?? 0);
$deletedBy  = intval($body["deletedBy"] ?? 0); // optional (audit)

if ($purchaseId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"purchaseId required"]);
  exit;
}

$conn->begin_transaction();

try {
  // If you have foreign keys with ON DELETE CASCADE, you can delete only from purchase_bills.
  // Otherwise we delete children first.

  // delete payments linked to this purchase
  $stmt1 = $conn->prepare("DELETE FROM purchase_payments WHERE purchase_id=?");
  $stmt1->bind_param("i", $purchaseId);
  if (!$stmt1->execute()) throw new Exception("Delete payments failed: " . $stmt1->error);
  $stmt1->close();

  // delete items
  $stmt2 = $conn->prepare("DELETE FROM purchase_bill_items WHERE purchase_id=?");
  $stmt2->bind_param("i", $purchaseId);
  if (!$stmt2->execute()) throw new Exception("Delete items failed: " . $stmt2->error);
  $stmt2->close();

  // delete bill header
  $stmt3 = $conn->prepare("DELETE FROM purchase_bills WHERE id=?");
  $stmt3->bind_param("i", $purchaseId);
  if (!$stmt3->execute()) throw new Exception("Delete purchase failed: " . $stmt3->error);

  if ($stmt3->affected_rows === 0) {
    $stmt3->close();
    throw new Exception("Purchase not found");
  }
  $stmt3->close();

  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Purchase deleted",
    "purchaseId" => $purchaseId
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
