<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$invoiceNo    = trim($data["invoiceNo"]    ?? "");
$invoiceDate  = trim($data["invoiceDate"]  ?? "");
$customerType = trim($data["customerType"] ?? "Retail");
$customerName = trim($data["customerName"] ?? "Cash");
$phone        = trim($data["phone"]        ?? "");
$rows         = $data["rows"]     ?? [];
$payments     = $data["payments"] ?? [];
$totals       = $data["totals"]   ?? [];

if ($invoiceNo === "" || $invoiceDate === "" || $customerName === "" || !is_array($rows) || count($rows) === 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"invoiceNo, invoiceDate, customerName and at least 1 item are required"]);
  exit;
}

$subtotal          = floatval($totals["grandTotal"]        ?? 0);
$billDiscount      = strval($totals["billDiscount"]        ?? "");
$billDiscountValue = floatval($totals["billDiscountValue"] ?? 0);
$finalTotal        = floatval($totals["finalTotal"]        ?? 0);
$roundOffEnabled   = !empty($totals["roundOffEnabled"]) ? 1 : 0;
$roundedFinalTotal = floatval($totals["roundedFinalTotal"] ?? 0);
$roundOffDiff      = floatval($totals["roundOffDiff"]      ?? 0);
$received          = floatval($totals["received"]          ?? 0);
$balance           = floatval($totals["balance"]           ?? 0);

// Check if inventory table exists
$invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;

$conn->begin_transaction();

try {
  // 1) Insert invoice header
  $stmt = $conn->prepare("
    INSERT INTO invoices
      (invoice_no, invoice_date, customer_type, customer_name, phone,
       subtotal, bill_discount, bill_discount_value, final_total,
       round_off_enabled, rounded_final_total, round_off_diff, received, balance)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmt->bind_param("ssssssssdsddds",
    $invoiceNo, $invoiceDate, $customerType, $customerName, $phone,
    $subtotal, $billDiscount, $billDiscountValue, $finalTotal,
    $roundOffEnabled, $roundedFinalTotal, $roundOffDiff, $received, $balance
  );
  if (!$stmt->execute()) throw new Exception("Failed to insert invoice: " . $stmt->error);
  $invoiceId = $conn->insert_id;
  $stmt->close();

  // 2) Insert items + deduct inventory
  $stmtItem = $conn->prepare("
    INSERT INTO invoice_items
      (invoice_id, item_id, item_name, item_code, hsn, batch_no, exp_date, mrp, qty, price, discount, tax, amount, gst_flag)
    VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  // Prepared statement for inventory deduction — match by item_id + batch_no
  $stmtDeduct = null;
  if ($invExists) {
    $stmtDeduct = $conn->prepare("
      UPDATE inventory
      SET current_qty = GREATEST(current_qty - ?, 0)
      WHERE item_id = ? AND batch_no = ?
      LIMIT 1
    ");
  }

  // Also need item_id lookup by code
  $stmtItemLookup = $invExists ? $conn->prepare("SELECT id FROM items WHERE code = ? LIMIT 1") : null;

  foreach ($rows as $r) {
    $itemName = trim($r["itemName"] ?? "");
    if ($itemName === "") continue;

    $hsn      = trim($r["hsn"]     ?? "");
    $batch    = trim($r["batchNo"] ?? "");
    $exp      = trim($r["expDate"] ?? "");
    $expDate  = ($exp === "") ? null : $exp;
    $mrp      = floatval($r["mrp"]      ?? 0);
    $qty      = floatval($r["qty"]      ?? 0);
    $price    = floatval($r["price"]    ?? 0);
    $discount = strval($r["discount"]   ?? "");
    $tax      = floatval($r["tax"]      ?? 0);
    $amount   = floatval($r["amount"]   ?? 0);
    $itemCode = trim($r["code"]  ?? "");
    $invId    = intval($r["invId"] ?? 0);   // inventory record PK sent from frontend

    // Resolve item_id from items master
    $itemId = 0;
    if ($invExists && $stmtItemLookup && $itemCode !== "") {
      $stmtItemLookup->bind_param("s", $itemCode);
      $stmtItemLookup->execute();
      $resLookup = $stmtItemLookup->get_result();
      if ($resLookup->num_rows > 0) $itemId = intval($resLookup->fetch_assoc()["id"]);
    }

    // Resolve gst_flag — prefer direct PK lookup (invId), fallback to item_id+batch
    $gstFlag = 1; // default: treat as GST
    if ($invExists) {
      if ($invId > 0) {
        // Best: exact inventory record the user picked
        $resGst = $conn->query("SELECT gst_flag FROM inventory WHERE id=$invId LIMIT 1");
        if ($resGst && $resGst->num_rows > 0) $gstFlag = intval($resGst->fetch_assoc()["gst_flag"]);
      } elseif ($itemId > 0 && $batch !== "") {
        // Fallback: match by item + batch
        $safeBatch = mysqli_real_escape_string($conn, $batch);
        $resGst = $conn->query("SELECT gst_flag FROM inventory WHERE item_id=$itemId AND batch_no='$safeBatch' LIMIT 1");
        if ($resGst && $resGst->num_rows > 0) $gstFlag = intval($resGst->fetch_assoc()["gst_flag"]);
      }
    }

    // Insert invoice line
    // 14 params: i i s s s s s d d d s d d i
    // invoice_id, item_id, item_name, item_code, hsn, batch_no, exp_date,
    // mrp, qty, price, discount, tax, amount, gst_flag
    $stmtItem->bind_param("iisssssdddsddi",
      $invoiceId, $itemId, $itemName, $itemCode, $hsn, $batch, $expDate,
      $mrp, $qty, $price, $discount, $tax, $amount, $gstFlag
    );
    if (!$stmtItem->execute()) throw new Exception("Failed to insert item: " . $stmtItem->error);

    // Deduct from inventory
    if ($invExists && $stmtDeduct && $itemId > 0 && $qty > 0) {
      $stmtDeduct->bind_param("dis", $qty, $itemId, $batch);
      $stmtDeduct->execute();
    }
  }
  $stmtItem->close();
  if ($stmtDeduct) $stmtDeduct->close();
  if ($stmtItemLookup) $stmtItemLookup->close();

  // 3) Insert payments
  $stmtPay = $conn->prepare("INSERT INTO invoice_payments (invoice_id, pay_type, amount) VALUES (?,?,?)");
  if (is_array($payments)) {
    foreach ($payments as $p) {
      $ptype = trim($p["type"] ?? $p["payType"] ?? "Cash");
      $pamt  = floatval($p["amount"] ?? 0);
      if ($pamt <= 0) continue;
      $stmtPay->bind_param("isd", $invoiceId, $ptype, $pamt);
      if (!$stmtPay->execute()) throw new Exception("Failed to insert payment: " . $stmtPay->error);
    }
  }
  $stmtPay->close();

  $conn->commit();
  echo json_encode(["status"=>"success","message"=>"Invoice saved","invoiceId"=>$invoiceId,"invoiceNo"=>$invoiceNo]);

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
