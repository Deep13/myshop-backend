<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$from = trim($_GET["from"] ?? "");
$to   = trim($_GET["to"]   ?? "");

if ($from === "") $from = date("Y-m-01");
if ($to === "")   $to   = date("Y-m-d");

$result = [];

// ═══ 1) Summary KPIs ═══
$stmt = $conn->prepare("
  SELECT
    COUNT(*) AS total_bills,
    COALESCE(SUM(rounded_grand_total), 0) AS total_purchase,
    COALESCE(AVG(rounded_grand_total), 0) AS avg_bill_value,
    COALESCE(SUM(sub_total), 0) AS sub_total,
    COALESCE(SUM(tax_total), 0) AS tax_total,
    COUNT(DISTINCT distributor_id) AS unique_distributors
  FROM purchase_bills
  WHERE bill_date BETWEEN ? AND ?
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Total paid
$stmtPaid = $conn->prepare("
  SELECT COALESCE(SUM(pp.amount), 0) AS total_paid
  FROM purchase_payments pp
  JOIN purchase_bills pb ON pb.id = pp.purchase_id
  WHERE pb.bill_date BETWEEN ? AND ?
");
$stmtPaid->bind_param("ss", $from, $to);
$stmtPaid->execute();
$paid = $stmtPaid->get_result()->fetch_assoc();
$stmtPaid->close();

$totalPurchase = floatval($s["total_purchase"]);
$totalPaid = floatval($paid["total_paid"]);

$result["summary"] = [
  "total_bills"          => intval($s["total_bills"]),
  "total_purchase"       => round($totalPurchase, 2),
  "avg_bill_value"       => round(floatval($s["avg_bill_value"]), 2),
  "sub_total"            => round(floatval($s["sub_total"]), 2),
  "tax_total"            => round(floatval($s["tax_total"]), 2),
  "unique_distributors"  => intval($s["unique_distributors"]),
  "total_paid"           => round($totalPaid, 2),
  "total_balance"        => round($totalPurchase - $totalPaid, 2),
];

// ═══ 2) Top purchased items (by qty & amount) ═══
$stmt2 = $conn->prepare("
  SELECT pbi.item_code, pbi.item_name,
         SUM(pbi.qty) AS total_qty,
         SUM(pbi.amount) AS total_amount,
         COUNT(DISTINCT pbi.purchase_id) AS bill_count,
         ROUND(AVG(pbi.purchase_price), 2) AS avg_price
  FROM purchase_bill_items pbi
  JOIN purchase_bills pb ON pb.id = pbi.purchase_id
  WHERE pb.bill_date BETWEEN ? AND ?
    AND pbi.item_name != ''
  GROUP BY pbi.item_code, pbi.item_name
  ORDER BY total_amount DESC
  LIMIT 50
");
$stmt2->bind_param("ss", $from, $to);
$stmt2->execute();
$res = $stmt2->get_result();
$topItems = [];
while ($r = $res->fetch_assoc()) {
  $r["total_qty"]    = floatval($r["total_qty"]);
  $r["total_amount"] = floatval($r["total_amount"]);
  $r["bill_count"]   = intval($r["bill_count"]);
  $r["avg_price"]    = floatval($r["avg_price"]);
  $topItems[] = $r;
}
$stmt2->close();
$result["top_items"] = $topItems;

// ═══ 3) Distributor-wise summary ═══
$stmt3 = $conn->prepare("
  SELECT
    pb.distributor_id,
    pb.distributor_name,
    pb.distributor_gstin,
    COUNT(*) AS bill_count,
    COALESCE(SUM(pb.rounded_grand_total), 0) AS total_amount,
    COALESCE(SUM(pp_agg.paid), 0) AS total_paid
  FROM purchase_bills pb
  LEFT JOIN (
    SELECT purchase_id, SUM(amount) AS paid FROM purchase_payments GROUP BY purchase_id
  ) pp_agg ON pp_agg.purchase_id = pb.id
  WHERE pb.bill_date BETWEEN ? AND ?
  GROUP BY pb.distributor_id, pb.distributor_name, pb.distributor_gstin
  ORDER BY total_amount DESC
");
$stmt3->bind_param("ss", $from, $to);
$stmt3->execute();
$res = $stmt3->get_result();
$distributors = [];
while ($r = $res->fetch_assoc()) {
  $r["bill_count"]    = intval($r["bill_count"]);
  $r["total_amount"]  = floatval($r["total_amount"]);
  $r["total_paid"]    = floatval($r["total_paid"]);
  $r["balance"]       = round($r["total_amount"] - $r["total_paid"], 2);
  $distributors[] = $r;
}
$stmt3->close();
$result["distributors"] = $distributors;

// ═══ 4) Daily purchase trend ═══
$stmt4 = $conn->prepare("
  SELECT pb.bill_date AS dt,
         COUNT(*) AS bill_count,
         COALESCE(SUM(pb.rounded_grand_total), 0) AS total
  FROM purchase_bills pb
  WHERE pb.bill_date BETWEEN ? AND ?
  GROUP BY pb.bill_date
  ORDER BY pb.bill_date ASC
");
$stmt4->bind_param("ss", $from, $to);
$stmt4->execute();
$res = $stmt4->get_result();
$daily = [];
while ($r = $res->fetch_assoc()) {
  $r["bill_count"] = intval($r["bill_count"]);
  $r["total"]      = floatval($r["total"]);
  $daily[] = $r;
}
$stmt4->close();
$result["daily"] = $daily;

// ═══ 5) Weekly purchase trend ═══
$stmt5 = $conn->prepare("
  SELECT YEAR(pb.bill_date) AS yr,
         WEEK(pb.bill_date, 1) AS wk,
         MIN(pb.bill_date) AS week_start,
         COUNT(*) AS bill_count,
         COALESCE(SUM(pb.rounded_grand_total), 0) AS total
  FROM purchase_bills pb
  WHERE pb.bill_date BETWEEN ? AND ?
  GROUP BY YEAR(pb.bill_date), WEEK(pb.bill_date, 1)
  ORDER BY yr ASC, wk ASC
");
$stmt5->bind_param("ss", $from, $to);
$stmt5->execute();
$res = $stmt5->get_result();
$weekly = [];
while ($r = $res->fetch_assoc()) {
  $r["bill_count"] = intval($r["bill_count"]);
  $r["total"]      = floatval($r["total"]);
  $weekly[] = $r;
}
$stmt5->close();
$result["weekly"] = $weekly;

// ═══ 6) Monthly purchase trend ═══
$stmt6 = $conn->prepare("
  SELECT DATE_FORMAT(pb.bill_date, '%Y-%m') AS month,
         COUNT(*) AS bill_count,
         COALESCE(SUM(pb.rounded_grand_total), 0) AS total,
         COALESCE(SUM(pbi_agg.total_qty), 0) AS total_items
  FROM purchase_bills pb
  LEFT JOIN (
    SELECT purchase_id, SUM(qty) AS total_qty FROM purchase_bill_items GROUP BY purchase_id
  ) pbi_agg ON pbi_agg.purchase_id = pb.id
  WHERE pb.bill_date BETWEEN ? AND ?
  GROUP BY DATE_FORMAT(pb.bill_date, '%Y-%m')
  ORDER BY month ASC
");
$stmt6->bind_param("ss", $from, $to);
$stmt6->execute();
$res = $stmt6->get_result();
$monthly = [];
while ($r = $res->fetch_assoc()) {
  $r["bill_count"]   = intval($r["bill_count"]);
  $r["total"]        = floatval($r["total"]);
  $r["total_items"]  = floatval($r["total_items"]);
  $monthly[] = $r;
}
$stmt6->close();
$result["monthly"] = $monthly;

// ═══ 7) Payment mode breakdown ═══
$stmt7 = $conn->prepare("
  SELECT pp.mode,
         COUNT(*) AS pay_count,
         COALESCE(SUM(pp.amount), 0) AS total_amount
  FROM purchase_payments pp
  JOIN purchase_bills pb ON pb.id = pp.purchase_id
  WHERE pb.bill_date BETWEEN ? AND ?
  GROUP BY pp.mode
  ORDER BY total_amount DESC
");
$stmt7->bind_param("ss", $from, $to);
$stmt7->execute();
$res = $stmt7->get_result();
$payModes = [];
while ($r = $res->fetch_assoc()) {
  $r["pay_count"]    = intval($r["pay_count"]);
  $r["total_amount"] = floatval($r["total_amount"]);
  $payModes[] = $r;
}
$stmt7->close();
$result["pay_modes"] = $payModes;

echo json_encode(["status" => "success", "data" => $result, "from" => $from, "to" => $to]);
