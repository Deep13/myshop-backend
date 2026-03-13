<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$purchaseId = intval($_GET["purchaseId"] ?? 0);
$distributorId = intval($_GET["distributorId"] ?? 0);

if ($purchaseId <= 0 && $distributorId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"purchaseId or distributorId required"]);
  exit;
}

if ($purchaseId > 0) {
  $stmt = $conn->prepare("
    SELECT id, distributor_id, purchase_id, pay_date, mode, amount, reference_no, note, created_at
    FROM purchase_payments
    WHERE purchase_id=?
    ORDER BY pay_date ASC, id ASC
  ");
  $stmt->bind_param("i", $purchaseId);
} else {
  $stmt = $conn->prepare("
    SELECT id, distributor_id, purchase_id, pay_date, mode, amount, reference_no, note, created_at
    FROM purchase_payments
    WHERE distributor_id=?
    ORDER BY pay_date ASC, id ASC
  ");
  $stmt->bind_param("i", $distributorId);
}

$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo json_encode(["status"=>"success","data"=>$data]);
