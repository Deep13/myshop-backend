<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *"); // or set exact domain (recommended)
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");

// ✅ Handle preflight request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
  exit;
}

$invoiceNo = trim($data["invoiceNo"] ?? "");
$invoiceDate = trim($data["invoiceDate"] ?? "");
$customerType = trim($data["customerType"] ?? "Retail");
$customerName = trim($data["customerName"] ?? "Cash");
$phone = trim($data["phone"] ?? "");
$rows = $data["rows"] ?? [];
$payments = $data["payments"] ?? [];
$totals = $data["totals"] ?? [];

if ($invoiceNo === "" || $invoiceDate === "" || $customerName === "" || !is_array($rows) || count($rows) === 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "invoiceNo, invoiceDate, customerName and at least 1 item are required"]);
  exit;
}

// totals (safe numeric)
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
  // Insert invoice
  $stmt = $conn->prepare("
    INSERT INTO invoices
    (invoice_no, invoice_date, customer_type, customer_name, phone,
     subtotal, bill_discount, bill_discount_value, final_total,
     round_off_enabled, rounded_final_total, round_off_diff,
     received, balance)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmt->bind_param(
    "ssssssssdsddds",
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
    $balance
  );

  if (!$stmt->execute()) {
    // if invoice_no unique conflict
    throw new Exception("Failed to insert invoice: " . $stmt->error);
  }

  $invoiceId = $conn->insert_id;
  $stmt->close();

  // Insert items
  $stmtItem = $conn->prepare("
    INSERT INTO invoice_items
    (invoice_id, item_name, hsn, batch_no, exp_date, mrp, qty, price, discount, tax, amount)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
  ");

  foreach ($rows as $r) {
    $itemName = trim($r["itemName"] ?? "");
    if ($itemName === "") continue;

    $hsn = trim($r["hsn"] ?? "");
    $batch = trim($r["batchNo"] ?? "");
    $exp = trim($r["expDate"] ?? "");
    $expDate = ($exp === "") ? null : $exp;

    $mrp = floatval($r["mrp"] ?? 0);
    $qty = floatval($r["qty"] ?? 0);
    $price = floatval($r["price"] ?? 0);
    $discount = strval($r["discount"] ?? "");
    $tax = floatval($r["tax"] ?? 0);
    $amount = floatval($r["amount"] ?? 0);

    $stmtItem->bind_param(
      "issssdddssd",
      $invoiceId,
      $itemName,
      $hsn,
      $batch,
      $expDate,
      $mrp,
      $qty,
      $price,
      $discount,
      $tax,
      $amount
    );

    if (!$stmtItem->execute()) {
      throw new Exception("Failed to insert item: " . $stmtItem->error);
    }
  }
  $stmtItem->close();

  // Insert payments
  $stmtPay = $conn->prepare("
    INSERT INTO invoice_payments (invoice_id, pay_type, amount)
    VALUES (?,?,?)
  ");

  if (is_array($payments)) {
    foreach ($payments as $p) {
      $ptype = trim($p["type"] ?? "Cash");
      $pamt = floatval($p["amount"] ?? 0);
      if ($pamt <= 0) continue;

      $stmtPay->bind_param("isd", $invoiceId, $ptype, $pamt);
      if (!$stmtPay->execute()) {
        throw new Exception("Failed to insert payment: " . $stmtPay->error);
      }
    }
  }
  $stmtPay->close();

  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Invoice saved",
    "invoiceId" => $invoiceId,
    "invoiceNo" => $invoiceNo
  ]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
