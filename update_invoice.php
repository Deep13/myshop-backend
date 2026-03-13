<?php
// ---------- CORS ----------
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

include "db.php";

// Read JSON body
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
  exit;
}

// Required
$id = intval($data["invoiceId"] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing invoiceId"]);
  exit;
}

$invoiceNo = trim($data["invoiceNo"] ?? "");
$updatedBy   = intval($data["updatedBy"] ?? 0);
$invoiceDate = trim($data["invoiceDate"] ?? "");
$customerType = trim($data["customerType"] ?? "Retail");
$customerName = trim($data["customerName"] ?? "");
$phone = trim($data["phone"] ?? "");

$rows = $data["rows"] ?? [];
$payments = $data["payments"] ?? [];
$totals = $data["totals"] ?? [];

if ($invoiceNo === "" || $invoiceDate === "" || $customerName === "" || !is_array($rows) || count($rows) === 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "invoiceNo, invoiceDate, customerName and at least 1 row required"]);
  exit;
}

// Totals
$subtotal = floatval($totals["grandTotal"] ?? 0);
$billDiscount = strval($totals["billDiscount"] ?? "");
$billDiscountValue = floatval($totals["billDiscountValue"] ?? 0);
$finalTotal = floatval($totals["finalTotal"] ?? 0);

$roundOffEnabled = !empty($totals["roundOffEnabled"]) ? 1 : 0;
$roundedFinalTotal = floatval($totals["roundedFinalTotal"] ?? 0);
$roundOffDiff = floatval($totals["roundOffDiff"] ?? 0);

$received = floatval($totals["received"] ?? 0);
$balance = floatval($totals["balance"] ?? 0);

$conn->begin_transaction();

try {
  // 1) Update invoice header
  $stmt = $conn->prepare("
    UPDATE invoices SET
      invoice_no=?,
      invoice_date=?,
      customer_type=?,
      customer_name=?,
      phone=?,
      subtotal=?,
      bill_discount=?,
      bill_discount_value=?,
      final_total=?,
      round_off_enabled=?,
      rounded_final_total=?,
      round_off_diff=?,
      received=?,
      balance=?,
      updated_by=?
    WHERE id=?
  ");

  if (!$stmt) {
    throw new Exception("Prepare failed (update invoices): " . $conn->error);
  }

  // 15 params => "sssssdsddiddddi"
  $stmt->bind_param(
    "sssssdsddiddddii",
    $invoiceNo,
    $invoiceDate,
    $customerType,
    $customerName,
    $phone,
    $subtotal,
    $billDiscount,
    $billDiscountValue,
    $finalTotal,
    $roundOffEnabled,
    $roundedFinalTotal,
    $roundOffDiff,
    $received,
    $balance,
     $updatedBy,
    $id
  );

  if (!$stmt->execute()) {
    throw new Exception("Update failed: " . $stmt->error);
  }

  // Ensure row existed
  if ($stmt->affected_rows === 0) {
    // Might be same data; check exists:
    // If invoice id doesn't exist, affected_rows could be 0 too.
    $chk = $conn->prepare("SELECT id FROM invoices WHERE id=? LIMIT 1");
    $chk->bind_param("i", $id);
    $chk->execute();
    $chkRes = $chk->get_result();
    if ($chkRes->num_rows === 0) {
      throw new Exception("Invoice not found for update (id=$id)");
    }
    $chk->close();
  }

  $stmt->close();

  // 2) Replace items: delete old
  $stmt = $conn->prepare("DELETE FROM invoice_items WHERE invoice_id=?");
  if (!$stmt) throw new Exception("Prepare failed (delete items): " . $conn->error);
  $stmt->bind_param("i", $id);
  if (!$stmt->execute()) throw new Exception("Delete items failed: " . $stmt->error);
  $stmt->close();

  // 3) Insert new items
  $stmtItem = $conn->prepare("
    INSERT INTO invoice_items
      (invoice_id, item_name, hsn, batch_no, exp_date, mrp, qty, price, discount, tax, amount)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?)
  ");
  if (!$stmtItem) throw new Exception("Prepare failed (insert items): " . $conn->error);

  foreach ($rows as $r) {
    $itemName = trim($r["itemName"] ?? "");
    if ($itemName === "") continue;

    $hsn = trim($r["hsn"] ?? "");
    $batchNo = trim($r["batchNo"] ?? "");
    $expDate = trim($r["expDate"] ?? "");
    $expDate = ($expDate === "") ? null : $expDate;

    $mrp = floatval($r["mrp"] ?? 0);
    $qty = floatval($r["qty"] ?? 0);
    $price = floatval($r["price"] ?? 0);
    $discount = strval($r["discount"] ?? "");
    $tax = floatval($r["tax"] ?? 0);
    $amount = floatval($r["amount"] ?? 0);

    // invoice_id(i), item_name(s), hsn(s), batch_no(s), exp_date(s or null), mrp(d), qty(d), price(d), discount(s), tax(d), amount(d)
    // => "issssddd sdd" without spaces => "issssdddsdd"
    // but exp_date can be NULL; bind as string and pass null works with mysqlnd, ok.
    $stmtItem->bind_param(
      "issssdddsdd",
      $id,
      $itemName,
      $hsn,
      $batchNo,
      $expDate,
      $mrp,
      $qty,
      $price,
      $discount,
      $tax,
      $amount
    );

    if (!$stmtItem->execute()) {
      throw new Exception("Insert item failed: " . $stmtItem->error);
    }
  }
  $stmtItem->close();

  // 4) Replace payments: delete old
  $stmt = $conn->prepare("DELETE FROM invoice_payments WHERE invoice_id=?");
  if (!$stmt) throw new Exception("Prepare failed (delete payments): " . $conn->error);
  $stmt->bind_param("i", $id);
  if (!$stmt->execute()) throw new Exception("Delete payments failed: " . $stmt->error);
  $stmt->close();

  // 5) Insert payments
  $stmtPay = $conn->prepare("
    INSERT INTO invoice_payments (invoice_id, pay_type, amount)
    VALUES (?,?,?)
  ");
  if (!$stmtPay) throw new Exception("Prepare failed (insert payments): " . $conn->error);

  if (is_array($payments)) {
    foreach ($payments as $p) {
      $ptype = trim($p["type"] ?? "Cash");
      $pamt = floatval($p["amount"] ?? 0);
      if ($pamt <= 0) continue;

      $stmtPay->bind_param("isd", $id, $ptype, $pamt);
      if (!$stmtPay->execute()) {
        throw new Exception("Insert payment failed: " . $stmtPay->error);
      }
    }
  }
  $stmtPay->close();

  // Done
  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Invoice updated successfully",
    "invoiceId" => $id,
    "invoiceNo" => $invoiceNo
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => $e->getMessage()
  ]);
}
