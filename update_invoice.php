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
$customerGstin = trim($data["customerGstin"] ?? "");
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

// Credit-sale guard: balance > 0 means money is owed → need a real customer + phone.
if ($balance > 0.01) {
  $isCashName = $customerName === "" || preg_match('/^cash( sale)?$/i', $customerName);
  if ($isCashName) {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Credit sales need a customer name (cannot be saved as Cash)"]);
    exit;
  }
  if (trim($phone) === "") {
    http_response_code(400);
    echo json_encode(["status"=>"error","message"=>"Credit sales need a customer phone number"]);
    exit;
  }
}
// Phone format guard: if a phone is provided, it must be exactly 10 digits.
$phoneDigits = preg_replace('/\D/', '', (string)$phone);
if ($phoneDigits !== "" && strlen($phoneDigits) !== 10) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Phone number must be 10 digits"]);
  exit;
}

$invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;

$conn->begin_transaction();

try {
  // 1) Snapshot the old line items keyed by (item_id, batch_no) so we can
  //    apply the NET delta to inventory at the end (instead of restore-then-deduct,
  //    which touched every row regardless of change).
  $oldByKey = []; // key = item_id|batch_no => qty
  if ($invExists) {
    // Resolve item_id from item_code when the stored item_id is NULL
    $stmtOldItems = $conn->prepare("SELECT COALESCE(item_id, 0) AS item_id, COALESCE(item_code,'') AS item_code, COALESCE(batch_no,'') AS batch_no, qty FROM invoice_items WHERE invoice_id = ?");
    $stmtOldItems->bind_param("i", $id);
    $stmtOldItems->execute();
    $oldItemsRes = $stmtOldItems->get_result();
    $codeLookup = $conn->prepare("SELECT id FROM items WHERE code = ? LIMIT 1");
    while ($oldRow = $oldItemsRes->fetch_assoc()) {
      $iid = intval($oldRow["item_id"]);
      if ($iid === 0 && $oldRow["item_code"] !== "") {
        $codeLookup->bind_param("s", $oldRow["item_code"]);
        $codeLookup->execute();
        $rl = $codeLookup->get_result();
        if ($rl->num_rows > 0) $iid = intval($rl->fetch_assoc()["id"]);
      }
      if ($iid <= 0) continue;
      $key = $iid . "|" . $oldRow["batch_no"];
      $oldByKey[$key] = ($oldByKey[$key] ?? 0) + floatval($oldRow["qty"]);
    }
    $stmtOldItems->close();
    $codeLookup->close();
  }

  // 2) Update invoice header
  $stmt = $conn->prepare("
    UPDATE invoices SET
      invoice_no=?, invoice_date=?, customer_type=?, customer_name=?, phone=?, customer_gstin=?,
      subtotal=?, bill_discount=?, bill_discount_value=?, final_total=?,
      round_off_enabled=?, rounded_final_total=?, round_off_diff=?,
      received=?, balance=?, updated_by=?
    WHERE id=?
  ");
  $stmt->bind_param("ssssssdsddiddddii",
    $invoiceNo, $invoiceDate, $customerType, $customerName, $phone, $customerGstin,
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

  // We no longer deduct in the per-item loop. Instead we collect the new qtys per
  // (item_id, batch_no) and apply only the NET delta vs the old snapshot at the end.
  $newByKey = []; // key = item_id|batch_no => qty

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

    // Accumulate the new qty for net-delta application below
    if ($invExists && $itemId2 > 0 && $qty > 0) {
      $key = $itemId2 . "|" . $batchNo;
      $newByKey[$key] = ($newByKey[$key] ?? 0) + $qty;
    }
  }
  $stmtItem->close();

  // Apply net delta to inventory — touches only the batches whose qty actually changed.
  // delta > 0 means more sold than before → deduct; delta < 0 means restore.
  if ($invExists) {
    $allKeys = array_unique(array_merge(array_keys($oldByKey), array_keys($newByKey)));
    $stmtAdj = $conn->prepare("UPDATE inventory SET current_qty = GREATEST(current_qty - ?, 0) WHERE item_id = ? AND batch_no = ? LIMIT 1");
    foreach ($allKeys as $key) {
      $delta = ($newByKey[$key] ?? 0) - ($oldByKey[$key] ?? 0);
      if (abs($delta) < 0.0001) continue; // unchanged batch — leave inventory alone
      [$iidStr, $batch] = explode("|", $key, 2);
      $iid = intval($iidStr);
      $stmtAdj->bind_param("dis", $delta, $iid, $batch);
      $stmtAdj->execute();
    }
    $stmtAdj->close();
  }

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
