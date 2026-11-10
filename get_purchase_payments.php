<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";
require_once __DIR__ . "/purchase_payment_lib.php";

// Payments for one purchase bill (purchaseId) or one distributor (distributorId).
// For a bill it also returns "bill": total, paid, balance and status.
$purchaseId = intval($_GET["purchaseId"] ?? 0);
$distributorId = intval($_GET["distributorId"] ?? 0);

if ($purchaseId <= 0 && $distributorId <= 0) {
  pp_json(400, ["status"=>"error","message"=>"purchaseId or distributorId required"]);
}

ensure_purchase_payment_audit($conn);

$cols = "
  pp.id, pp.distributor_id, pp.purchase_id, pp.pay_date, pp.mode, pp.amount,
  pp.reference_no, pp.note, pp.created_at, pp.updated_at,
  cu.name AS created_by_name, uu.name AS updated_by_name
";
$joins = "
  LEFT JOIN users cu ON cu.id = pp.created_by
  LEFT JOIN users uu ON uu.id = pp.updated_by
";

if ($purchaseId > 0) {
  $stmt = $conn->prepare("SELECT $cols FROM purchase_payments pp $joins WHERE pp.purchase_id=? ORDER BY pp.pay_date ASC, pp.id ASC");
  $stmt->bind_param("i", $purchaseId);
} else {
  $stmt = $conn->prepare("SELECT $cols FROM purchase_payments pp $joins WHERE pp.distributor_id=? ORDER BY pp.pay_date ASC, pp.id ASC");
  $stmt->bind_param("i", $distributorId);
}

$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

$out = ["status"=>"success","data"=>$data];
if ($purchaseId > 0) $out["bill"] = pp_bill_summary($conn, $purchaseId);
echo json_encode($out);
