<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$itemId = intval($_GET["item_id"] ?? 0);
if ($itemId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"item_id required"]);
  exit;
}

// 1) Item master info
$stmt = $conn->prepare("SELECT * FROM items WHERE id=? LIMIT 1");
$stmt->bind_param("i", $itemId);
$stmt->execute();
$item = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$item) {
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"Item not found"]);
  exit;
}
$itemName = $item["name"];
$itemCode = $item["code"];

// 2) Inventory batches (all, including zero-stock)
$invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;
$batches = [];
if ($invExists) {
  $res = $conn->query("
    SELECT inv.*,
           pb.bill_no AS purchase_bill_no,
           pb.bill_date AS purchase_bill_date,
           pb.bill_type,
           pb.distributor_name
    FROM inventory inv
    LEFT JOIN purchase_bills pb ON pb.id = inv.purchase_bill_id
    WHERE inv.item_id = $itemId
    ORDER BY inv.exp_date ASC, inv.id ASC
  ");
  while ($r = $res->fetch_assoc()) $batches[] = $r;
}

// 3) Purchase bill history — match by item_id (always reliable)
$purchaseHistory = [];
$pRes = $conn->query("
  SELECT
    pbi.id,
    pbi.batch_no,
    pbi.exp_date,
    pbi.qty,
    pbi.purchase_price,
    pbi.sale_price,
    pbi.tax_pct,
    pbi.gst_flag,
    pbi.amount,
    pb.id          AS bill_id,
    pb.bill_no,
    pb.bill_date,
    pb.distributor_name,
    pb.bill_type
  FROM purchase_bill_items pbi
  JOIN purchase_bills pb ON pb.id = pbi.purchase_id
  WHERE pbi.item_id = $itemId
  ORDER BY pb.bill_date DESC, pb.id DESC
  LIMIT 100
");
while ($r = $pRes->fetch_assoc()) $purchaseHistory[] = $r;

// 4) Sales history
// Strategy: try multiple matching approaches and merge results
// to handle old bills (before item_id column existed)
$salesHistory = [];
$seenIds = [];

// Check which extra columns exist on invoice_items
$hasItemId   = $conn->query("SHOW COLUMNS FROM invoice_items LIKE 'item_id'")->num_rows   > 0;
$hasItemCode = $conn->query("SHOW COLUMNS FROM invoice_items LIKE 'item_code'")->num_rows > 0;
$hasGstFlag  = $conn->query("SHOW COLUMNS FROM invoice_items LIKE 'gst_flag'")->num_rows  > 0;

// Build SELECT — gst_flag optional
$gstSel = $hasGstFlag ? "ii.gst_flag," : "NULL AS gst_flag,";

// Always include ii.invoice_id so the frontend can link to the invoice
$baseSelect = "
  SELECT
    ii.id,
    ii.invoice_id,
    ii.item_name,
    ii.batch_no,
    ii.qty,
    ii.price,
    ii.discount,
    ii.tax,
    ii.amount,
    $gstSel
    inv.invoice_no,
    inv.invoice_date,
    inv.customer_name,
    inv.customer_type
  FROM invoice_items ii
  JOIN invoices inv ON inv.id = ii.invoice_id
";

// Approach A: match by item_id (new bills)
if ($hasItemId) {
  $res = $conn->query($baseSelect . "
    WHERE ii.item_id = $itemId
    ORDER BY inv.invoice_date DESC, inv.id DESC
    LIMIT 200
  ");
  while ($r = $res->fetch_assoc()) {
    $seenIds[$r["id"]] = true;
    $salesHistory[] = $r;
  }
}

// Approach B: match by item_code (newer bills without item_id populated)
if ($hasItemCode && $itemCode !== "") {
  $safeCode = mysqli_real_escape_string($conn, $itemCode);
  $res = $conn->query($baseSelect . "
    WHERE ii.item_code = '$safeCode'
    ORDER BY inv.invoice_date DESC, inv.id DESC
    LIMIT 200
  ");
  while ($r = $res->fetch_assoc()) {
    if (!isset($seenIds[$r["id"]])) {
      $seenIds[$r["id"]] = true;
      $salesHistory[] = $r;
    }
  }
}

// Approach C: match by item_name (old bills — always works)
if ($itemName !== "") {
  $safeName = mysqli_real_escape_string($conn, $itemName);
  $res = $conn->query($baseSelect . "
    WHERE ii.item_name = '$safeName'
    ORDER BY inv.invoice_date DESC, inv.id DESC
    LIMIT 200
  ");
  while ($r = $res->fetch_assoc()) {
    if (!isset($seenIds[$r["id"]])) {
      $seenIds[$r["id"]] = true;
      $salesHistory[] = $r;
    }
  }
}

// Sort merged results by invoice_date desc
usort($salesHistory, function($a, $b) {
  $da = $a["invoice_date"] ?? "";
  $db = $b["invoice_date"] ?? "";
  if ($da === $db) return 0;
  return ($da < $db) ? 1 : -1;
});

// Limit to 200
$salesHistory = array_slice($salesHistory, 0, 200);

echo json_encode([
  "status"           => "success",
  "item"             => $item,
  "batches"          => $batches,
  "purchase_history" => $purchaseHistory,
  "sales_history"    => $salesHistory,
]);
