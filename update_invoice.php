<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$id           = intval($data["invoiceId"]    ?? $data["id"] ?? 0);
$invoiceNo    = trim($data["invoiceNo"]      ?? "");
$updatedBy    = intval($data["updatedBy"]    ?? 0);
$invoiceDate  = trim($data["invoiceDate"]    ?? "");
$customerType = trim($data["customerType"]   ?? "Retail");
$customerName = trim($data["customerName"]   ?? "");
$phone        = trim($data["phone"]          ?? "");
$rows         = $data["rows"]     ?? [];
$payments     = $data["payments"] ?? [];
$totals       = $data["totals"]   ?? [];

if ($id <= 0)         { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Missing invoiceId"]); exit; }
if ($invoiceNo === "" || $invoiceDate === "" || $customerName === "" || !is_array($rows) || count($rows) === 0) {
  http_response_code(400); echo json_encode(["status"=>"error","message"=>"invoiceNo, invoiceDate, customerName and at least 1 row required"]); exit;
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

$invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;

$conn->begin_transaction();

try {
  // 1) Restore old inventory quantities (add back what was previously sold)
  if ($invExists) {
    $stmtOldItems = $conn->prepare("SELECT ii.item_code, ii.batch_no, ii.qty FROM invoice_items ii WHERE ii.invoice_id = ?");
    // invoice_items might not have item_code — join via item_name match or store code
    // Safe fallback: match by batch_no only if item_code is stored, else skip restore
    $stmtOldItems = $conn->prepare("SELECT item_name, batch_no, qty FROM invoice_items WHERE invoice_id = ?");
    $stmtOldItems->bind_param("i", $id);
    $stmtOldItems->execute();
    $oldItemsRes = $stmtOldItems->get_result();

    $stmtRestore = $conn->prepare("
      UPDATE inventory inv
      JOIN items it ON it.id = inv.item_id
      SET inv.current_qty = inv.current_qty + ?
      WHERE it.name = ? AND inv.batch_no = ?
      LIMIT 1
    ");

    while ($oldRow = $oldItemsRes->fetch_assoc()) {
      $oldQty = floatval($oldRow["qty"]);
      $oldName = $oldRow["item_name"];
      $oldBatch = $oldRow["batch_no"] ?? "";
      if ($oldQty > 0 && $oldBatch !== "") {
        $stmtRestore->bind_param("dss", $oldQty, $oldName, $oldBatch);
        $stmtRestore->execute();
      }
    }
    $stmtOldItems->close();
    $stmtRestore->close();
  }

  // 2) Update invoice header
  $stmt = $conn->prepare("
    UPDATE invoices SET
      invoice_no=?, invoice_date=?, customer_type=?, customer_name=?, phone=?,
      subtotal=?, bill_discount=?, bill_discount_value=?, final_total=?,
      round_off_enabled=?, rounded_final_total=?, round_off_diff=?,
      received=?, balance=?, updated_by=?
    WHERE id=?
  ");
  $stmt->bind_param("sssssdsddiddddii",
    $invoiceNo, $invoiceDate, $customerType, $customerName, $phone,
    $subtotal, $billDiscount, $billDiscountValue, $finalTotal,
    $roundOffEnabled, $roundedFinalTotal, $roundOffDiff,
    $received, $balance, $updatedBy, $id
  );
  if (!$stmt->execute()) throw new Exception("Update failed: " . $stmt->error);
  $stmt->close();

  // 3) Delete old items
  $stmtDel = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id=?");
  $stmtDel->bind_param("i", $id);
  $stmtDel->execute();
  $stmtDel->close();

  // 4) Insert new items + deduct inventory
  $stmtItem = $conn->prepare("
    INSERT INTO invoice_items
      (invoice_id, item_id, item_name, item_code, hsn, batch_no, exp_date, mrp, qty, price, discount, tax, amount, gst_flag)
    VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $stmtDeduct = null;
  if ($invExists) {
    $stmtDeduct = $conn->prepare("
      UPDATE inventory SET current_qty = GREATEST(current_qty - ?, 0)
      WHERE item_id = ? AND batch_no = ? LIMIT 1
    ");
  }

  foreach ($rows as $r) {
    $itemName = trim($r["itemName"] ?? ""); if ($itemName === "") continue;
    $hsn      = trim($r["hsn"]     ?? "");
    $batchNo  = trim($r["batchNo"] ?? "");
    $expDate  = trim($r["expDate"] ?? ""); $expDate = ($expDate === "") ? null : $expDate;
    $mrp      = floatval($r["mrp"]      ?? 0);
    $qty      = floatval($r["qty"]      ?? 0);
    $price    = floatval($r["price"]    ?? 0);
    $discount = strval($r["discount"]   ?? "");
    $tax      = floatval($r["tax"]      ?? 0);
    $amount   = floatval($r["amount"]   ?? 0);
    $itemCode = trim($r["code"]  ?? "");
    $invId2   = intval($r["invId"] ?? 0);   // inventory record PK from frontend

    // Resolve item_id and gst_flag
    $itemId2 = 0; $gstFlag2 = 1;
    if ($invExists && $itemCode !== "") {
      $rl = $conn->prepare("SELECT id FROM items WHERE code=? LIMIT 1");
      $rl->bind_param("s", $itemCode); $rl->execute();
      $rr = $rl->get_result();
      if ($rr->num_rows > 0) $itemId2 = intval($rr->fetch_assoc()["id"]);
      $rl->close();
    }
    // gst_flag: prefer invId PK lookup, fallback to item_id+batch
    if ($invExists) {
      if ($invId2 > 0) {
        $rg = $conn->query("SELECT gst_flag FROM inventory WHERE id=$invId2 LIMIT 1");
        if ($rg && $rg->num_rows > 0) $gstFlag2 = intval($rg->fetch_assoc()["gst_flag"]);
      } elseif ($itemId2 > 0 && $batchNo !== "") {
        $safeBatch = mysqli_real_escape_string($conn, $batchNo);
        $rg = $conn->query("SELECT gst_flag FROM inventory WHERE item_id=$itemId2 AND batch_no='$safeBatch' LIMIT 1");
        if ($rg && $rg->num_rows > 0) $gstFlag2 = intval($rg->fetch_assoc()["gst_flag"]);
      }
    }

    $stmtItem->bind_param("iisssssdddsddi",
      $id, $itemId2, $itemName, $itemCode,
      $hsn, $batchNo, $expDate,
      $mrp, $qty, $price, $discount, $tax, $amount, $gstFlag2
    );
    if (!$stmtItem->execute()) throw new Exception("Insert item failed: " . $stmtItem->error);

    // Deduct new quantities from inventory
    if ($invExists && $stmtDeduct && $itemId2 > 0 && $qty > 0) {
      $stmtDeduct->bind_param("dis", $qty, $itemId2, $batchNo);
      $stmtDeduct->execute();
    }
  }
  $stmtItem->close();
  if ($stmtDeduct) $stmtDeduct->close();

  // 5) Replace payments
  $stmtDelPay = $conn->prepare("DELETE FROM invoice_payments WHERE invoice_id=?");
  $stmtDelPay->bind_param("i", $id);
  $stmtDelPay->execute();
  $stmtDelPay->close();

  $stmtPay = $conn->prepare("INSERT INTO invoice_payments (invoice_id, pay_type, amount) VALUES (?,?,?)");
  if (is_array($payments)) {
    foreach ($payments as $p) {
      $ptype = trim($p["type"] ?? $p["payType"] ?? "Cash");
      $pamt  = floatval($p["amount"] ?? 0);
      if ($pamt <= 0) continue;
      $stmtPay->bind_param("isd", $id, $ptype, $pamt);
      if (!$stmtPay->execute()) throw new Exception("Insert payment failed: " . $stmtPay->error);
    }
  }
  $stmtPay->close();

  $conn->commit();
  echo json_encode(["status"=>"success","message"=>"Invoice updated","invoiceId"=>$id,"invoiceNo"=>$invoiceNo]);

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
