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

// ── Per-item weighted-avg purchase price, GST-inclusive (i.e. gross).
//    Inclusive purchase bills  → use pp as-is (already contains tax).
//    Exclusive GST bills       → pp × (1 + line tax%) so the figure matches the sticker.
//    NON-GST bills             → pp × (1 + item-master tax%) — the line has tax=0 but the
//                                  customer-side sticker price still bakes in the item's GST,
//                                  so the cost basis must do the same to be comparable.
$ppSubquery = "
  LEFT JOIN (
    SELECT pbi.item_code,
           SUM(
             CASE
               WHEN pb.bill_type = 'NON-GST'   THEN pbi.purchase_price * (1 + COALESCE(itm.tax_pct,0)/100)
               WHEN pb.gst_mode  = 'exclusive' THEN pbi.purchase_price * (1 + pbi.tax_pct/100)
               ELSE pbi.purchase_price
             END * pbi.qty
           ) / NULLIF(SUM(pbi.qty),0) AS avg_pp
    FROM purchase_bill_items pbi
    JOIN purchase_bills pb ON pb.id = pbi.purchase_id
    LEFT JOIN items itm ON itm.code = pbi.item_code
    WHERE pbi.purchase_price > 0 AND pbi.qty > 0
    GROUP BY pbi.item_code
  ) pp ON pp.item_code = ii.item_code
";

// effective per-unit cost (per-kg for rice)
$costExpr = "(CASE WHEN it.category LIKE 'Rice%' AND it.pack_size > 0
              THEN COALESCE(pp.avg_pp, it.purchase_price) / it.pack_size
              ELSE COALESCE(pp.avg_pp, it.purchase_price) END)";

$result = [];

// ═══ Overall profit summary ═══
$stmt = $conn->prepare("
  SELECT
    SUM(ii.amount) AS total_revenue,
    SUM(
      CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
        THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
        ELSE ii.amount
      END
    ) AS taxable_revenue,
    SUM(ROUND($costExpr * ii.qty, 2)) AS total_cost,
    COUNT(DISTINCT ii.invoice_id) AS total_bills,
    SUM(ii.qty) AS total_qty
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  $ppSubquery
  WHERE i.invoice_date BETWEEN ? AND ?
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totalRev   = floatval($summary["total_revenue"] ?? 0);
$taxableRev = floatval($summary["taxable_revenue"] ?? 0);
$totalCost  = floatval($summary["total_cost"] ?? 0);
$totalProfit = round($totalRev - $totalCost, 2);
$marginPct  = $totalRev > 0 ? round(($totalProfit / $totalRev) * 100, 2) : 0;

$result["summary"] = [
  "total_revenue"   => round($totalRev, 2),
  "taxable_revenue" => round($taxableRev, 2),
  "total_cost"      => round($totalCost, 2),
  "total_profit"    => $totalProfit,
  "margin_pct"      => $marginPct,
  "total_bills"     => intval($summary["total_bills"] ?? 0),
  "total_qty"       => floatval($summary["total_qty"] ?? 0),
];

// ═══ Item-wise profit ═══
$stmt2 = $conn->prepare("
  SELECT
    it.id AS item_id,
    ii.item_name,
    ii.item_code,
    $costExpr AS purchase_price,
    SUM(ii.qty) AS qty_sold,
    SUM(ii.amount) AS revenue,
    SUM(
      CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
        THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
        ELSE ii.amount
      END
    ) AS taxable_revenue,
    SUM(ROUND($costExpr * ii.qty, 2)) AS cost,
    ROUND(SUM(ii.amount) - SUM(ROUND($costExpr * ii.qty, 2)), 2) AS profit
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  $ppSubquery
  WHERE i.invoice_date BETWEEN ? AND ?
  GROUP BY it.id, ii.item_code, ii.item_name, it.category, it.pack_size, it.purchase_price, pp.avg_pp
  HAVING cost > 0
  ORDER BY profit DESC
");
$stmt2->bind_param("ss", $from, $to);
$stmt2->execute();
$res = $stmt2->get_result();
$items = [];
while ($r = $res->fetch_assoc()) {
  $rev    = floatval($r["revenue"]);
  $taxRev = floatval($r["taxable_revenue"]);
  $cost   = floatval($r["cost"]);
  $profit = floatval($r["profit"]);
  $items[] = [
    "item_id"         => isset($r["item_id"]) ? intval($r["item_id"]) : null,
    "item_name"       => $r["item_name"],
    "item_code"       => $r["item_code"],
    "purchase_price"  => floatval($r["purchase_price"]),
    "qty_sold"        => floatval($r["qty_sold"]),
    "revenue"         => round($rev, 2),
    "taxable_revenue" => round($taxRev, 2),
    "cost"            => round($cost, 2),
    "profit"          => round($profit, 2),
    "margin_pct"      => $rev > 0 ? round(($profit / $rev) * 100, 2) : 0,
  ];
}
$stmt2->close();
$result["items"] = $items;

// ═══ Daily profit trend ═══
$stmt3 = $conn->prepare("
  SELECT
    i.invoice_date AS dt,
    SUM(ii.amount) AS revenue,
    SUM(ROUND($costExpr * ii.qty, 2)) AS cost
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  $ppSubquery
  WHERE i.invoice_date BETWEEN ? AND ?
  GROUP BY i.invoice_date
  ORDER BY i.invoice_date ASC
");
$stmt3->bind_param("ss", $from, $to);
$stmt3->execute();
$res = $stmt3->get_result();
$daily = [];
while ($r = $res->fetch_assoc()) {
  $rev = floatval($r["revenue"]);
  $cost = floatval($r["cost"]);
  $daily[] = [
    "dt"      => $r["dt"],
    "revenue" => round($rev, 2),
    "cost"    => round($cost, 2),
    "profit"  => round($rev - $cost, 2),
  ];
}
$stmt3->close();
$result["daily"] = $daily;

// ═══ Monthly profit trend ═══
$stmt4 = $conn->prepare("
  SELECT
    DATE_FORMAT(i.invoice_date, '%Y-%m') AS month,
    SUM(ii.amount) AS revenue,
    SUM(ROUND($costExpr * ii.qty, 2)) AS cost
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  $ppSubquery
  WHERE i.invoice_date BETWEEN ? AND ?
  GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
  ORDER BY month ASC
");
$stmt4->bind_param("ss", $from, $to);
$stmt4->execute();
$res = $stmt4->get_result();
$monthly = [];
while ($r = $res->fetch_assoc()) {
  $rev = floatval($r["revenue"]);
  $cost = floatval($r["cost"]);
  $monthly[] = [
    "month"   => $r["month"],
    "revenue" => round($rev, 2),
    "cost"    => round($cost, 2),
    "profit"  => round($rev - $cost, 2),
  ];
}
$stmt4->close();
$result["monthly"] = $monthly;

echo json_encode(["status" => "success", "data" => $result, "from" => $from, "to" => $to]);
