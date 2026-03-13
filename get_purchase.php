<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
  exit;
}

include "db.php";

$purchaseId = intval($_GET["id"] ?? 0);
if ($purchaseId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"id required"]);
  exit;
}

// 1) Header
$stmtH = $conn->prepare("
  SELECT
    id,
    distributor_id,
    distributor_name,
    distributor_gstin,
    bill_no,
    bill_date,
    due_date,
    sub_total,
    tax_total,
    grand_total,
    round_off_enabled,
    round_off_diff,
    rounded_grand_total,
    created_by,
    updated_by,
    created_at,
    updated_at
  FROM purchase_bills
  WHERE id=?
  LIMIT 1
");
$stmtH->bind_param("i", $purchaseId);
$stmtH->execute();
$resH = $stmtH->get_result();

if ($resH->num_rows === 0) {
  $stmtH->close();
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"Purchase not found"]);
  exit;
}
$header = $resH->fetch_assoc();
$stmtH->close();

// 2) Items
$stmtI = $conn->prepare("
  SELECT
    id,
    purchase_id,
    item_id,
    item_name,
    item_code,
    hsn,
    batch_no,
    exp_date,
    mrp,
    qty,
    purchase_price,
    sale_price,
    discount,
    tax_pct AS tax,
    amount
  FROM purchase_bill_items
  WHERE purchase_id=?
  ORDER BY id ASC
");
$stmtI->bind_param("i", $purchaseId);
$stmtI->execute();
$resI = $stmtI->get_result();

$items = [];
while ($row = $resI->fetch_assoc()) {
  $items[] = $row;
}
$stmtI->close();

// 3) Payments linked to this purchase (NOT advances with NULL purchase_id)
$stmtP = $conn->prepare("
  SELECT
    id,
    distributor_id,
    purchase_id,
    pay_date,
    mode,
    amount,
    reference_no,
    note,
    created_at
  FROM purchase_payments
  WHERE purchase_id=?
  ORDER BY pay_date ASC, id ASC
");
$stmtP->bind_param("i", $purchaseId);
$stmtP->execute();
$resP = $stmtP->get_result();

$payments = [];
while ($row = $resP->fetch_assoc()) {
  $payments[] = $row;
}
$stmtP->close();

echo json_encode([
  "status" => "success",
  "header" => $header,
  "items" => $items,
  "payments" => $payments
]);
