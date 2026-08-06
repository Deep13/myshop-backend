<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$today = date("Y-m-d");
// Expiring window in days (default 90, clamped to 0–365). 0 disables the
// expiring set (caller gets only already-expired rows).
$days = isset($_GET["days"]) ? intval($_GET["days"]) : 90;
if ($days < 0)   $days = 0;
if ($days > 365) $days = 365;
$cutoff = date("Y-m-d", strtotime("+$days days"));

$sql = "
  SELECT
    inv.id, inv.item_id, inv.batch_no, inv.exp_date,
    inv.current_qty, inv.mrp, inv.purchase_price, inv.sale_price,
    it.name AS item_name, it.code AS item_code, it.hsn, it.category,
    inv.purchase_bill_id,
    pb.bill_no AS purchase_bill_no, pb.bill_date AS purchase_bill_date,
    pb.distributor_id, pb.distributor_name,
    CASE WHEN inv.exp_date < '$today' THEN 'expired' ELSE 'expiring' END AS status,
    DATEDIFF(inv.exp_date, '$today') AS days_to_exp
  FROM inventory inv
  JOIN items it ON it.id = inv.item_id
  LEFT JOIN purchase_bills pb ON pb.id = inv.purchase_bill_id
  WHERE inv.exp_date IS NOT NULL
    AND inv.exp_date <= '$cutoff'
    AND inv.current_qty > 0
  ORDER BY inv.exp_date ASC, it.name ASC
";
$res = $conn->query($sql);

$data = [];
$totals = [
  "expired"  => ["count" => 0, "qty" => 0.0, "value_ptr" => 0.0, "value_mrp" => 0.0],
  "expiring" => ["count" => 0, "qty" => 0.0, "value_ptr" => 0.0, "value_mrp" => 0.0],
];
while ($r = $res->fetch_assoc()) {
  $r["current_qty"]    = floatval($r["current_qty"]);
  $r["mrp"]            = floatval($r["mrp"]);
  $r["purchase_price"] = floatval($r["purchase_price"]);
  $r["sale_price"]     = floatval($r["sale_price"]);
  $r["days_to_exp"]    = intval($r["days_to_exp"]);
  $bucket = $r["status"]; // expired | expiring
  $totals[$bucket]["count"]++;
  $totals[$bucket]["qty"]       += $r["current_qty"];
  $totals[$bucket]["value_ptr"] += $r["current_qty"] * $r["purchase_price"];
  $totals[$bucket]["value_mrp"] += $r["current_qty"] * $r["mrp"];
  $data[] = $r;
}
foreach ($totals as &$t) {
  $t["value_ptr"] = round($t["value_ptr"], 2);
  $t["value_mrp"] = round($t["value_mrp"], 2);
}

echo json_encode([
  "status" => "success",
  "data"   => $data,
  "totals" => [
    // Combined totals (back-compat with previous shape)
    "count"      => $totals["expired"]["count"] + $totals["expiring"]["count"],
    "total_qty"  => $totals["expired"]["qty"]   + $totals["expiring"]["qty"],
    "value_ptr"  => round($totals["expired"]["value_ptr"] + $totals["expiring"]["value_ptr"], 2),
    "value_mrp"  => round($totals["expired"]["value_mrp"] + $totals["expiring"]["value_mrp"], 2),
    // Per-bucket breakdown
    "expired"    => $totals["expired"],
    "expiring"   => $totals["expiring"],
    "days_window"=> $days,
  ],
]);
