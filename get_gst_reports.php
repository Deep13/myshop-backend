<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$from = trim($_GET["from"] ?? "");
$to   = trim($_GET["to"]   ?? "");
$type = trim($_GET["type"] ?? ""); // gstr1, gstr2a, gstr3b, hsn

if ($from === "") $from = date("Y-m-01");
if ($to === "")   $to   = date("Y-m-d");

$result = [];

// ═══ GSTR-1: Outward supplies (Sales) ═══
if ($type === "" || $type === "gstr1") {
  // B2B invoices (with GSTIN — not applicable here since retail, but include all)
  $stmt = $conn->prepare("
    SELECT
      i.id,
      i.invoice_no,
      i.invoice_date,
      i.customer_name,
      i.phone,
      ii.item_name,
      ii.item_code,
      ii.hsn,
      ii.qty,
      ii.price,
      ii.tax AS tax_pct,
      ii.amount,
      ii.gst_flag
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
    ORDER BY i.invoice_date ASC, i.id ASC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $amt = floatval($r["amount"]);
    $taxPct = floatval($r["tax_pct"]);
    $gstFlag = intval($r["gst_flag"]);
    // Sale price is inclusive of tax: taxable = amount * 100 / (100 + taxPct)
    if ($gstFlag && $taxPct > 0) {
      $taxableValue = round($amt * 100 / (100 + $taxPct), 2);
      $totalTax = round($amt - $taxableValue, 2);
      $cgst = round($totalTax / 2, 2);
      $sgst = round($totalTax / 2, 2);
    } else {
      $taxableValue = $amt;
      $totalTax = 0; $cgst = 0; $sgst = 0;
    }
    $rows[] = [
      "invoice_no"    => $r["invoice_no"],
      "invoice_date"  => $r["invoice_date"],
      "customer_name" => $r["customer_name"],
      "phone"         => $r["phone"],
      "item_name"     => $r["item_name"],
      "item_code"     => $r["item_code"],
      "hsn"           => $r["hsn"],
      "qty"           => floatval($r["qty"]),
      "rate"          => floatval($r["price"]),
      "taxable_value" => $taxableValue,
      "tax_pct"       => $taxPct,
      "cgst_pct"      => $taxPct / 2,
      "cgst"          => $cgst,
      "sgst_pct"      => $taxPct / 2,
      "sgst"          => $sgst,
      "total_tax"     => $totalTax,
      "total"         => $amt,
      "gst_flag"      => $gstFlag,
    ];
  }
  $stmt->close();
  $result["gstr1"] = $rows;
}

// ═══ GSTR-2A: Inward supplies (Purchases) ═══
if ($type === "" || $type === "gstr2a") {
  $stmt = $conn->prepare("
    SELECT
      pb.id,
      pb.bill_no,
      pb.bill_date,
      pb.distributor_name,
      pb.distributor_gstin,
      pb.bill_type,
      pbi.item_name,
      pbi.item_code,
      pbi.hsn,
      pbi.qty,
      pbi.purchase_price,
      pbi.tax_pct,
      pbi.amount,
      pbi.gst_flag
    FROM purchase_bill_items pbi
    JOIN purchase_bills pb ON pb.id = pbi.purchase_id
    WHERE pb.bill_date BETWEEN ? AND ?
    ORDER BY pb.bill_date ASC, pb.id ASC
  ");
  $stmt->bind_param("ss", $from, $to);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $amt = floatval($r["amount"]);
    $taxPct = floatval($r["tax_pct"]);
    $gstFlag = intval($r["gst_flag"]);
    // Purchase price is exclusive of tax: taxable = amount, tax = amount * taxPct / 100
    if ($gstFlag && $taxPct > 0) {
      $taxableValue = $amt;
      $totalTax = round($amt * $taxPct / 100, 2);
      $cgst = round($totalTax / 2, 2);
      $sgst = round($totalTax / 2, 2);
    } else {
      $taxableValue = $amt;
      $totalTax = 0; $cgst = 0; $sgst = 0;
    }
    $rows[] = [
      "bill_no"          => $r["bill_no"],
      "bill_date"        => $r["bill_date"],
      "distributor_name" => $r["distributor_name"],
      "gstin"            => $r["distributor_gstin"],
      "bill_type"        => $r["bill_type"],
      "item_name"        => $r["item_name"],
      "item_code"        => $r["item_code"],
      "hsn"              => $r["hsn"],
      "qty"              => floatval($r["qty"]),
      "rate"             => floatval($r["purchase_price"]),
      "taxable_value"    => $taxableValue,
      "tax_pct"          => $taxPct,
      "cgst_pct"         => $taxPct / 2,
      "cgst"             => $cgst,
      "sgst_pct"         => $taxPct / 2,
      "sgst"             => $sgst,
      "total_tax"        => $totalTax,
      "total"            => $amt + $totalTax,
      "gst_flag"         => $gstFlag,
    ];
  }
  $stmt->close();
  $result["gstr2a"] = $rows;
}

// ═══ GSTR-3B: Summary return ═══
if ($type === "" || $type === "gstr3b") {
  // Outward (Sales)
  $stmtS = $conn->prepare("
    SELECT
      SUM(ii.amount) AS total_amount,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2) ELSE ii.amount END) AS taxable_value,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND((ii.amount - ROUND(ii.amount * 100 / (100 + ii.tax), 2)) / 2, 2) ELSE 0 END) AS cgst,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND((ii.amount - ROUND(ii.amount * 100 / (100 + ii.tax), 2)) / 2, 2) ELSE 0 END) AS sgst
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
  ");
  $stmtS->bind_param("ss", $from, $to);
  $stmtS->execute();
  $sales = $stmtS->get_result()->fetch_assoc();
  $stmtS->close();

  // Inward (Purchases)
  $stmtP = $conn->prepare("
    SELECT
      SUM(pbi.amount) AS total_amount,
      SUM(pbi.amount) AS taxable_value,
      SUM(CASE WHEN pbi.gst_flag=1 AND pbi.tax_pct>0 THEN ROUND(pbi.amount * pbi.tax_pct / 200, 2) ELSE 0 END) AS cgst,
      SUM(CASE WHEN pbi.gst_flag=1 AND pbi.tax_pct>0 THEN ROUND(pbi.amount * pbi.tax_pct / 200, 2) ELSE 0 END) AS sgst
    FROM purchase_bill_items pbi
    JOIN purchase_bills pb ON pb.id = pbi.purchase_id
    WHERE pb.bill_date BETWEEN ? AND ?
  ");
  $stmtP->bind_param("ss", $from, $to);
  $stmtP->execute();
  $purchase = $stmtP->get_result()->fetch_assoc();
  $stmtP->close();

  $salesCGST = floatval($sales["cgst"] ?? 0);
  $salesSGST = floatval($sales["sgst"] ?? 0);
  $purchCGST = floatval($purchase["cgst"] ?? 0);
  $purchSGST = floatval($purchase["sgst"] ?? 0);

  $result["gstr3b"] = [
    "outward" => [
      "taxable_value" => round(floatval($sales["taxable_value"] ?? 0), 2),
      "cgst" => round($salesCGST, 2),
      "sgst" => round($salesSGST, 2),
      "igst" => 0,
      "total_tax" => round($salesCGST + $salesSGST, 2),
    ],
    "inward" => [
      "taxable_value" => round(floatval($purchase["taxable_value"] ?? 0), 2),
      "cgst" => round($purchCGST, 2),
      "sgst" => round($purchSGST, 2),
      "igst" => 0,
      "total_tax" => round($purchCGST + $purchSGST, 2),
    ],
    "itc" => [
      "cgst" => round($purchCGST, 2),
      "sgst" => round($purchSGST, 2),
      "igst" => 0,
      "total" => round($purchCGST + $purchSGST, 2),
    ],
    "payable" => [
      "cgst" => round(max($salesCGST - $purchCGST, 0), 2),
      "sgst" => round(max($salesSGST - $purchSGST, 0), 2),
      "igst" => 0,
      "total" => round(max($salesCGST - $purchCGST, 0) + max($salesSGST - $purchSGST, 0), 2),
    ],
  ];
}

// ═══ HSN-wise Summary ═══
if ($type === "" || $type === "hsn") {
  // Sales HSN
  $stmtH = $conn->prepare("
    SELECT
      ii.hsn,
      ii.tax AS tax_pct,
      SUM(ii.qty) AS total_qty,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND(ii.amount * 100 / (100 + ii.tax), 2) ELSE ii.amount END) AS taxable_value,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND((ii.amount - ROUND(ii.amount * 100 / (100 + ii.tax), 2)) / 2, 2) ELSE 0 END) AS cgst,
      SUM(CASE WHEN ii.gst_flag=1 AND ii.tax>0 THEN ROUND((ii.amount - ROUND(ii.amount * 100 / (100 + ii.tax), 2)) / 2, 2) ELSE 0 END) AS sgst,
      SUM(ii.amount) AS total_value,
      COUNT(DISTINCT ii.invoice_id) AS invoice_count
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE i.invoice_date BETWEEN ? AND ?
    GROUP BY ii.hsn, ii.tax
    ORDER BY taxable_value DESC
  ");
  $stmtH->bind_param("ss", $from, $to);
  $stmtH->execute();
  $res = $stmtH->get_result();
  $hsn = [];
  while ($r = $res->fetch_assoc()) {
    $r["total_qty"]     = floatval($r["total_qty"]);
    $r["taxable_value"] = round(floatval($r["taxable_value"]), 2);
    $r["cgst"]          = round(floatval($r["cgst"]), 2);
    $r["sgst"]          = round(floatval($r["sgst"]), 2);
    $r["total_value"]   = round(floatval($r["total_value"]), 2);
    $r["tax_pct"]       = floatval($r["tax_pct"]);
    $r["invoice_count"] = intval($r["invoice_count"]);
    $hsn[] = $r;
  }
  $stmtH->close();
  $result["hsn_sales"] = $hsn;

  // Purchase HSN
  $stmtHP = $conn->prepare("
    SELECT
      pbi.hsn,
      pbi.tax_pct,
      SUM(pbi.qty) AS total_qty,
      SUM(pbi.amount) AS taxable_value,
      SUM(CASE WHEN pbi.gst_flag=1 AND pbi.tax_pct>0 THEN ROUND(pbi.amount * pbi.tax_pct / 200, 2) ELSE 0 END) AS cgst,
      SUM(CASE WHEN pbi.gst_flag=1 AND pbi.tax_pct>0 THEN ROUND(pbi.amount * pbi.tax_pct / 200, 2) ELSE 0 END) AS sgst,
      COUNT(DISTINCT pbi.purchase_id) AS bill_count
    FROM purchase_bill_items pbi
    JOIN purchase_bills pb ON pb.id = pbi.purchase_id
    WHERE pb.bill_date BETWEEN ? AND ?
    GROUP BY pbi.hsn, pbi.tax_pct
    ORDER BY taxable_value DESC
  ");
  $stmtHP->bind_param("ss", $from, $to);
  $stmtHP->execute();
  $res = $stmtHP->get_result();
  $hsnP = [];
  while ($r = $res->fetch_assoc()) {
    $r["total_qty"]     = floatval($r["total_qty"]);
    $r["taxable_value"] = round(floatval($r["taxable_value"]), 2);
    $r["cgst"]          = round(floatval($r["cgst"]), 2);
    $r["sgst"]          = round(floatval($r["sgst"]), 2);
    $r["tax_pct"]       = floatval($r["tax_pct"]);
    $r["bill_count"]    = intval($r["bill_count"]);
    $hsnP[] = $r;
  }
  $stmtHP->close();
  $result["hsn_purchase"] = $hsnP;
}

echo json_encode(["status" => "success", "data" => $result, "from" => $from, "to" => $to]);
