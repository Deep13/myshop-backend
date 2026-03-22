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
    SUM(ROUND(it.purchase_price * ii.qty, 2)) AS total_cost,
    COUNT(DISTINCT ii.invoice_id) AS total_bills,
    SUM(ii.qty) AS total_qty
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  WHERE i.invoice_date BETWEEN ? AND ?
");
$stmt->bind_param("ss", $from, $to);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

$taxableRev = floatval($summary["taxable_revenue"] ?? 0);
$totalCost  = floatval($summary["total_cost"] ?? 0);
$totalProfit = round($taxableRev - $totalCost, 2);
$marginPct  = $taxableRev > 0 ? round(($totalProfit / $taxableRev) * 100, 2) : 0;

$result["summary"] = [
  "total_revenue"   => round(floatval($summary["total_revenue"] ?? 0), 2),
  "taxable_revenue" => round($taxableRev, 2),
  "total_cost"      => round($totalCost, 2),
  "total_profit"    => $totalProfit,
  "margin_pct"      => $marginPct,
  "total_bills"     => intval($summary["total_bills"] ?? 0),
  "total_qty"       => floatval($summary["total_qty"] ?? 0),
];

// ═══ Item-wise profit (top profitable) ═══
$stmt2 = $conn->prepare("
  SELECT
    ii.item_name,
    ii.item_code,
    it.purchase_price,
    SUM(ii.qty) AS qty_sold,
    SUM(ii.amount) AS revenue,
    SUM(
      CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
        THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
        ELSE ii.amount
      END
    ) AS taxable_revenue,
    SUM(ROUND(it.purchase_price * ii.qty, 2)) AS cost,
    ROUND(
      SUM(
        CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
          THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
          ELSE ii.amount
        END
      ) - SUM(ROUND(it.purchase_price * ii.qty, 2))
    , 2) AS profit
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
  WHERE i.invoice_date BETWEEN ? AND ?
  GROUP BY ii.item_code, ii.item_name, it.purchase_price
  ORDER BY profit DESC
");
$stmt2->bind_param("ss", $from, $to);
$stmt2->execute();
$res = $stmt2->get_result();
$items = [];
while ($r = $res->fetch_assoc()) {
  $taxRev = floatval($r["taxable_revenue"]);
  $cost   = floatval($r["cost"]);
  $profit = floatval($r["profit"]);
  $items[] = [
    "item_name"       => $r["item_name"],
    "item_code"       => $r["item_code"],
    "purchase_price"  => floatval($r["purchase_price"]),
    "qty_sold"        => floatval($r["qty_sold"]),
    "revenue"         => round(floatval($r["revenue"]), 2),
    "taxable_revenue" => round($taxRev, 2),
    "cost"            => round($cost, 2),
    "profit"          => round($profit, 2),
    "margin_pct"      => $taxRev > 0 ? round(($profit / $taxRev) * 100, 2) : 0,
  ];
}
$stmt2->close();
$result["items"] = $items;

// ═══ Daily profit trend ═══
$stmt3 = $conn->prepare("
  SELECT
    i.invoice_date AS dt,
    SUM(
      CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
        THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
        ELSE ii.amount
      END
    ) AS revenue,
    SUM(ROUND(it.purchase_price * ii.qty, 2)) AS cost
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
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
    SUM(
      CASE WHEN ii.gst_flag = 1 AND ii.tax > 0
        THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2)
        ELSE ii.amount
      END
    ) AS revenue,
    SUM(ROUND(it.purchase_price * ii.qty, 2)) AS cost
  FROM invoice_items ii
  JOIN invoices i ON i.id = ii.invoice_id
  LEFT JOIN items it ON it.id = ii.item_id
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
