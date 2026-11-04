<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

// The last few purchases of one item, for the panel under the purchase-entry
// table. exclude_id leaves out the bill being edited, so it doesn't list itself.
$itemId    = intval($_GET["item_id"] ?? 0);
$limit     = max(1, min(50, intval($_GET["limit"] ?? 5)));
$excludeId = intval($_GET["exclude_id"] ?? 0);
if ($itemId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"item_id required"]);
  exit;
}

$stmt = $conn->prepare("
  SELECT pb.id AS bill_id, pb.bill_no, pb.bill_date, pb.distributor_name,
         pbi.purchase_price, pbi.mrp
  FROM purchase_bill_items pbi
  JOIN purchase_bills pb ON pb.id = pbi.purchase_id
  WHERE pbi.item_id = ? AND pb.id <> ?
  ORDER BY pb.bill_date DESC, pb.id DESC, pbi.id DESC
  LIMIT ?
");
$stmt->bind_param("iii", $itemId, $excludeId, $limit);
$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($r = $res->fetch_assoc()) $data[] = $r;
$stmt->close();

echo json_encode(["status"=>"success","data"=>$data]);
