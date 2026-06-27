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

// 1) Header (join users so we can show "last updated by")
$stmtH = $conn->prepare("
  SELECT
    pb.id,
    pb.distributor_id,
    pb.distributor_name,
    pb.distributor_gstin,
    pb.bill_no,
    pb.bill_date,
    pb.due_date,
    pb.sub_total,
    pb.tax_total,
    pb.grand_total,
    pb.round_off_enabled,
    pb.round_off_diff,
    pb.rounded_grand_total,
    pb.bill_type,
    pb.gst_mode,
    pb.created_by,
    pb.updated_by,
    pb.created_at,
    pb.updated_at,
    uc.name AS created_by_name,
    uu.name AS updated_by_name
  FROM purchase_bills pb
  LEFT JOIN users uc ON uc.id = pb.created_by
  LEFT JOIN users uu ON uu.id = pb.updated_by
  WHERE pb.id=?
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
    COALESCE(free_qty, 0) AS free_qty,
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
