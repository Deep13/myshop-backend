<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$from  = trim($_GET["from"]  ?? "");
$to    = trim($_GET["to"]    ?? "");
$type  = trim($_GET["type"]  ?? ""); // top_items, hourly, daily, monthly, weekly

if ($from === "") $from = date("Y-m-01"); // default: this month
if ($to === "")   $to   = date("Y-m-d");

$result = [];

// ── 1) Top Selling Items (by qty and revenue)
if ($type === "" || $type === "top_items") {
  $stmt = $conn->prepare("
    SELECT ii.item_code, ii.item_name,
           SUM(ii.qty) AS total_qty,
           SUM(ii.amount) AS total_revenue,
           COUNT(DISTINCT ii.invoice_id) AS invoice_count,
           ROUND(AVG(ii.price), 2) AS avg_price
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
      AND ii.item_name != ''
    GROUP BY ii.item_code, ii.item_name
    ORDER BY total_qty DESC
    LIMIT 50
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $r["total_qty"]     = floatval($r["total_qty"]);
    $r["total_revenue"] = floatval($r["total_revenue"]);
    $r["invoice_count"] = intval($r["invoice_count"]);
    $r["avg_price"]     = floatval($r["avg_price"]);
    $rows[] = $r;
  }
  $stmt->close();
  $result["top_items"] = $rows;

  // Also get least selling
  $stmt2 = $conn->prepare("
    SELECT ii.item_code, ii.item_name,
           SUM(ii.qty) AS total_qty,
           SUM(ii.amount) AS total_revenue,
           COUNT(DISTINCT ii.invoice_id) AS invoice_count
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
      AND ii.item_name != ''
    GROUP BY ii.item_code, ii.item_name
    ORDER BY total_qty ASC
    LIMIT 20
  ");
  $stmt2->bind_param("ss", $from, $to);
  $stmt2->execute();
  $res2 = $stmt2->get_result();
  $least = [];
  while ($r = $res2->fetch_assoc()) {
    $r["total_qty"]     = floatval($r["total_qty"]);
    $r["total_revenue"] = floatval($r["total_revenue"]);
    $r["invoice_count"] = intval($r["invoice_count"]);
    $least[] = $r;
  }
  $stmt2->close();
  $result["least_items"] = $least;
}

// ── 2) Hourly sales (peak time analysis)
if ($type === "" || $type === "hourly") {
  $stmt = $conn->prepare("
    SELECT HOUR(i.created_at) AS hr,
           COUNT(*) AS bill_count,
           COALESCE(SUM(i.rounded_final_total), 0) AS total
    FROM invoices i
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY HOUR(i.created_at)
    ORDER BY hr ASC
  ");
  // Fallback if created_at doesn't exist — use invoice_date only (hourly won't be meaningful)
  if (!$stmt) {
    // Try without HOUR — just daily
    $result["hourly"] = [];
  } else {
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $hourly = [];
    while ($r = $res->fetch_assoc()) {
      $r["hr"]         = intval($r["hr"]);
      $r["bill_count"] = intval($r["bill_count"]);
      $r["total"]      = floatval($r["total"]);
      $hourly[] = $r;
    }
    $stmt->close();
    $result["hourly"] = $hourly;
  }
}

// ── 3) Daily sales
if ($type === "" || $type === "daily") {
  $stmt = $conn->prepare("
    SELECT i.invoice_date AS dt,
           COUNT(*) AS bill_count,
           COALESCE(SUM(i.rounded_final_total), 0) AS total
    FROM invoices i
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY i.invoice_date
    ORDER BY i.invoice_date ASC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $daily = [];
  while ($r = $res->fetch_assoc()) {
    $r["bill_count"] = intval($r["bill_count"]);
    $r["total"]      = floatval($r["total"]);
    $daily[] = $r;
  }
  $stmt->close();
  $result["daily"] = $daily;
}

// ── 4) Weekly sales (by week number)
if ($type === "" || $type === "weekly") {
  $stmt = $conn->prepare("
    SELECT YEAR(i.invoice_date) AS yr,
           WEEK(i.invoice_date, 1) AS wk,
           MIN(i.invoice_date) AS week_start,
           MAX(i.invoice_date) AS week_end,
           COUNT(*) AS bill_count,
           COALESCE(SUM(i.rounded_final_total), 0) AS total
    FROM invoices i
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY YEAR(i.invoice_date), WEEK(i.invoice_date, 1)
    ORDER BY yr ASC, wk ASC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $weekly = [];
  while ($r = $res->fetch_assoc()) {
    $r["bill_count"] = intval($r["bill_count"]);
    $r["total"]      = floatval($r["total"]);
    $weekly[] = $r;
  }
  $stmt->close();
  $result["weekly"] = $weekly;
}

// ── 5) Monthly sales
if ($type === "" || $type === "monthly") {
  $stmt = $conn->prepare("
    SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS month,
           COUNT(*) AS bill_count,
           COALESCE(SUM(i.rounded_final_total), 0) AS total,
           COALESCE(SUM(ii_agg.total_qty), 0) AS total_items_sold
    FROM invoices i
    LEFT JOIN (
      SELECT invoice_id, SUM(qty) AS total_qty FROM invoice_items GROUP BY invoice_id
    ) ii_agg ON ii_agg.invoice_id = i.id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(i.invoice_date, '%Y-%m')
    ORDER BY month ASC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $monthly = [];
  while ($r = $res->fetch_assoc()) {
    $r["bill_count"]       = intval($r["bill_count"]);
    $r["total"]            = floatval($r["total"]);
    $r["total_items_sold"] = floatval($r["total_items_sold"]);
    $monthly[] = $r;
  }
  $stmt->close();
  $result["monthly"] = $monthly;
}

// ── 6) Summary KPIs for the period
if ($type === "" || $type === "summary") {
  $stmt = $conn->prepare("
    SELECT COUNT(*) AS total_bills,
           COALESCE(SUM(rounded_final_total), 0) AS total_revenue,
           COALESCE(AVG(rounded_final_total), 0) AS avg_bill_value,
           COUNT(DISTINCT customer_name) AS unique_customers
    FROM invoices
    WHERE invoice_date BETWEEN ? AND ?
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $stmtItems = $conn->prepare("
    SELECT COALESCE(SUM(ii.qty), 0) AS total_qty_sold,
           COUNT(DISTINCT ii.item_code) AS unique_items_sold
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
  ");
  $stmtItems->bind_param("ss", $from, $to);
  $stmtItems->execute();
  $ri = $stmtItems->get_result()->fetch_assoc();
  $stmtItems->close();

  $result["summary"] = [
    "total_bills"       => intval($r["total_bills"]),
    "total_revenue"     => floatval($r["total_revenue"]),
    "avg_bill_value"    => round(floatval($r["avg_bill_value"]), 2),
    "unique_customers"  => intval($r["unique_customers"]),
    "total_qty_sold"    => floatval($ri["total_qty_sold"]),
    "unique_items_sold" => intval($ri["unique_items_sold"]),
  ];
}

// ── 7) Category/Tax-wise breakdown
if ($type === "" || $type === "category") {
  $stmt = $conn->prepare("
    SELECT ii.tax AS tax_pct,
           ii.gst_flag,
           SUM(ii.qty) AS total_qty,
           SUM(ii.amount) AS total_revenue,
           COUNT(DISTINCT ii.item_code) AS item_count
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY ii.tax, ii.gst_flag
    ORDER BY total_revenue DESC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $cats = [];
  while ($r = $res->fetch_assoc()) {
    $r["total_qty"]     = floatval($r["total_qty"]);
    $r["total_revenue"] = floatval($r["total_revenue"]);
    $r["item_count"]    = intval($r["item_count"]);
    $cats[] = $r;
  }
  $stmt->close();
  $result["category"] = $cats;
}

echo json_encode(["status" => "success", "data" => $result, "from" => $from, "to" => $to]);
