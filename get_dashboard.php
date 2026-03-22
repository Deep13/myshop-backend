<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$today     = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));
$d7        = date("Y-m-d", strtotime("-6 days")); // 7 days including today
$d30       = date("Y-m-d", strtotime("-29 days"));

function salesTotal($conn, $from, $to) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(rounded_final_total),0) AS t, COUNT(*) AS c FROM invoices WHERE invoice_date BETWEEN ? AND ?");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return ["total" => floatval($r["t"]), "count" => intval($r["c"])];
}

function purchaseTotal($conn, $from, $to) {
  $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN round_off_enabled=1 THEN rounded_grand_total ELSE grand_total END),0) AS t, COUNT(*) AS c FROM purchase_bills WHERE bill_date BETWEEN ? AND ?");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return ["total" => floatval($r["t"]), "count" => intval($r["c"])];
}

// ── Sales & Purchase numbers
$result = [
  "sales" => [
    "today"     => salesTotal($conn, $today, $today),
    "yesterday" => salesTotal($conn, $yesterday, $yesterday),
    "days7"     => salesTotal($conn, $d7, $today),
    "days30"    => salesTotal($conn, $d30, $today),
  ],
  "purchase" => [
    "today"     => purchaseTotal($conn, $today, $today),
    "yesterday" => purchaseTotal($conn, $yesterday, $yesterday),
    "days7"     => purchaseTotal($conn, $d7, $today),
    "days30"    => purchaseTotal($conn, $d30, $today),
  ],
];

// ── Inventory numbers (from inventory table if exists)
$invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;
if ($invExists) {
  $invR = $conn->query("
    SELECT
      COALESCE(SUM(current_qty * purchase_price), 0) AS stock_by_ptr,
      COALESCE(SUM(current_qty * mrp), 0)            AS stock_by_mrp,
      COALESCE(SUM(CASE WHEN exp_date < '$today' THEN current_qty * purchase_price ELSE 0 END), 0) AS expired_by_ptr,
      COALESCE(SUM(CASE WHEN exp_date < '$today' THEN current_qty * mrp ELSE 0 END), 0)            AS expired_by_mrp,
      COALESCE(SUM(current_qty), 0) AS total_units
    FROM inventory
  ")->fetch_assoc();
  $result["inventory"] = [
    "stock_by_ptr"   => floatval($invR["stock_by_ptr"]),
    "stock_by_mrp"   => floatval($invR["stock_by_mrp"]),
    "expired_by_ptr" => floatval($invR["expired_by_ptr"]),
    "expired_by_mrp" => floatval($invR["expired_by_mrp"]),
    "total_units"    => floatval($invR["total_units"]),
  ];

  // Expiring in next 90 days
  $exp90 = date("Y-m-d", strtotime("+90 days"));
  $expiringR = $conn->query("
    SELECT inv.id, it.name AS item_name, it.code AS item_code,
           inv.batch_no, inv.exp_date, inv.current_qty, inv.mrp, inv.purchase_price
    FROM inventory inv
    JOIN items it ON it.id = inv.item_id
    WHERE inv.exp_date BETWEEN '$today' AND '$exp90' AND inv.current_qty > 0
    ORDER BY inv.exp_date ASC
    LIMIT 20
  ");
  $expiring = [];
  while ($r = $expiringR->fetch_assoc()) $expiring[] = $r;
  $result["expiring_items"] = $expiring;

  // Already expired with stock
  $expiredR = $conn->query("
    SELECT inv.id, it.name AS item_name, it.code AS item_code,
           inv.batch_no, inv.exp_date, inv.current_qty, inv.mrp, inv.purchase_price
    FROM inventory inv
    JOIN items it ON it.id = inv.item_id
    WHERE inv.exp_date < '$today' AND inv.current_qty > 0
    ORDER BY inv.exp_date DESC
    LIMIT 20
  ");
  $expired = [];
  while ($r = $expiredR->fetch_assoc()) $expired[] = $r;
  $result["expired_items"] = $expired;
} else {
  $result["inventory"] = ["stock_by_ptr"=>0,"stock_by_mrp"=>0,"expired_by_ptr"=>0,"expired_by_mrp"=>0,"total_units"=>0];
  $result["expiring_items"] = [];
  $result["expired_items"]  = [];
}

// ── Need to Pay (purchase balance)
$needPayR = $conn->query("
  SELECT pb.id, pb.distributor_name, pb.bill_no, pb.bill_date, pb.due_date,
         (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END) AS total,
         COALESCE(pp.paid,0) AS paid
  FROM purchase_bills pb
  LEFT JOIN (SELECT purchase_id, SUM(amount) AS paid FROM purchase_payments WHERE purchase_id IS NOT NULL GROUP BY purchase_id) pp ON pp.purchase_id=pb.id
  HAVING (total - paid) > 0.01
  ORDER BY pb.due_date ASC, pb.bill_date ASC
  LIMIT 20
");
$needPay = [];
while ($r = $needPayR->fetch_assoc()) {
  $r["balance"] = floatval($r["total"]) - floatval($r["paid"]);
  $needPay[] = $r;
}
$result["need_to_pay"]     = $needPay;
$result["need_to_pay_total"] = array_sum(array_column($needPay, "balance"));

// ── Need to Collect (sales balance)
$needCollR = $conn->query("
  SELECT id, customer_name, invoice_no, invoice_date, rounded_final_total AS total, balance
  FROM invoices
  WHERE balance > 0.01
  ORDER BY invoice_date ASC
  LIMIT 20
");
$needColl = [];
while ($r = $needCollR->fetch_assoc()) $needColl[] = $r;
$result["need_to_collect"]       = $needColl;
$result["need_to_collect_total"] = floatval($conn->query("SELECT COALESCE(SUM(balance),0) AS t FROM invoices WHERE balance > 0.01")->fetch_assoc()["t"]);

echo json_encode(["status" => "success", "data" => $result]);
