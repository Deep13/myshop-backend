<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
  exit;
}

include "db.php";

function num($v) {
  if ($v === null) return 0;
  if (is_numeric($v)) return floatval($v);
  $s = trim((string)$v);
  if ($s === "") return 0;
  $s = str_replace([",","₹","Rs.","INR"], "", $s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  return is_numeric($s) ? floatval($s) : 0;
}
function strv($v) { return trim((string)($v ?? "")); }
function nullIfEmpty($s) { $s = strv($s); return $s === "" ? null : $s; }

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Invalid JSON body"]);
  exit;
}

// ---- required payload fields ----
$distributorId = isset($body["distributorId"]) ? intval($body["distributorId"]) : 0;
$billNo   = strv($body["billNo"] ?? "");
$billDate = strv($body["billDate"] ?? "");
$dueDate  = nullIfEmpty($body["dueDate"] ?? "");

$rows = $body["rows"] ?? null;

$subTotal   = num($body["totals"]["subTotal"] ?? 0);
$taxTotal   = num($body["totals"]["taxTotal"] ?? 0);
$grandTotal = num($body["totals"]["grandTotal"] ?? 0);
$createdBy = intval($body["createdBy"] ?? 0);
$roundOffEnabled   = !empty($body["totals"]["roundOffEnabled"]) ? 1 : 0; // store as 0/1
$roundOffDiff      = num($body["totals"]["roundOffDiff"] ?? 0);
$roundedGrandTotal = num($body["totals"]["roundedGrandTotal"] ?? ($grandTotal));
if ($createdBy <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"createdBy required"]);
  exit;
}

if ($distributorId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"distributorId is required (must select distributor)"]);
  exit;
}
if ($billNo === "" || $billDate === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"billNo and billDate are required"]);
  exit;
}
if (!is_array($rows) || count($rows) === 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"rows are required"]);
  exit;
}

// ---- Load distributor from DB (enforce no manual entry) ----
$stmtD = $conn->prepare("SELECT id, name, gstin FROM distributors WHERE id=? LIMIT 1");
$stmtD->bind_param("i", $distributorId);
$stmtD->execute();
$resD = $stmtD->get_result();
if ($resD->num_rows === 0) {
  $stmtD->close();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Distributor not found"]);
  exit;
}
$distRow = $resD->fetch_assoc();
$stmtD->close();

$distributorName = $distRow["name"];
$distributorGstin = $distRow["gstin"] ?? null;

// ---- transaction ----
$conn->begin_transaction();

try {
  // 1) Insert purchase header
 $stmtH = $conn->prepare("
  INSERT INTO purchase_bills
    (distributor_id, distributor_name, distributor_gstin,
     bill_no, bill_date, due_date,
     sub_total, tax_total, grand_total,
     round_off_enabled, round_off_diff, rounded_grand_total,
     created_by)
  VALUES (?,?,?,?,?,?,?,?,?,?,?, ?,?)
");

  // Types: i s s s s s d d d  (due_date can be NULL, bind as string)
 $stmtH->bind_param(
  "isssssdddiddi",
  $distributorId,
  $distributorName,
  $distributorGstin,
  $billNo,
  $billDate,
  $dueDate,
  $subTotal,
  $taxTotal,
  $grandTotal,
  $roundOffEnabled,
  $roundOffDiff,
  $roundedGrandTotal,
  $createdBy
);


  if (!$stmtH->execute()) {
    throw new Exception("Failed to insert purchase header: " . $stmtH->error);
  }

  $purchaseId = $stmtH->insert_id;
  $stmtH->close();

  // 2) Prepare helpers
  $stmtFindItem = $conn->prepare("SELECT id FROM items WHERE code=? LIMIT 1");

  $stmtLine = $conn->prepare("
    INSERT INTO purchase_bill_items
      (purchase_id, item_id, item_name, item_code, hsn, batch_no, exp_date,
       mrp, qty, purchase_price, sale_price, discount, tax_pct, amount)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  /**
   * bind types must match 14 variables:
   * purchase_id      i
   * item_id          i (can be NULL -> bind as int but use null + set_null trick below)
   * item_name        s
   * item_code        s
   * hsn              s
   * batch_no         s
   * exp_date         s (can be NULL)
   * mrp              d
   * qty              d
   * purchase_price    d
   * sale_price        d
   * discount         s
   * tax_pct          d
   * amount           d
   */
  $types = "iissss sddddsdd";
  $types = str_replace(" ", "", $types); // => "iissssssddddsdd"

  foreach ($rows as $r) {
    $itemName = strv($r["itemName"] ?? "");
    if ($itemName === "") continue;

    $itemCode = strv($r["code"] ?? "");
    $hsn      = strv($r["hsn"] ?? "");
    $batchNo  = strv($r["batchNo"] ?? "");
    $expDate  = nullIfEmpty($r["expDate"] ?? "");
    $discount = strv($r["discount"] ?? "");

    $mrp           = num($r["mrp"] ?? 0);
    $qty           = num($r["qty"] ?? 0);
    $purchasePrice = num($r["purchasePrice"] ?? 0);
    $salePrice     = num($r["salePrice"] ?? 0);
    $taxPct        = num($r["tax"] ?? 0);
    $amount        = num($r["amount"] ?? 0);

    if ($qty <= 0) continue;

    // find item_id from items table (optional)
    $itemId = null;
    if ($itemCode !== "") {
      $stmtFindItem->bind_param("s", $itemCode);
      $stmtFindItem->execute();
      $resItem = $stmtFindItem->get_result();
      if ($resItem && $resItem->num_rows > 0) {
        $itemId = intval($resItem->fetch_assoc()["id"]);
      }
    }

    // IMPORTANT: mysqli bind_param does not accept NULL for "i" well in some setups.
    // So we bind an int variable, and if null, set it to NULL via $stmtLine->send_long_data trick is messy.
    // Practical approach: convert null -> 0 and store item_id as NULL using SQL IF.
    // We'll do that by inserting with item_id = NULL when 0 using a small change:
    // But since prepared statement is fixed, easiest: if null -> set 0, and later update NULL.
    // Better: change SQL to use NULLIF(?,0). We'll do that instead.

    // ---- Re-prepare statement once with NULLIF for item_id ----
    // If you prefer no re-prepare, keep as is and store 0. But DB column allows NULL, so let's do it right.

    // We'll re-prepare only once outside loop? To keep code simple, prepare it once here if not already.
  }

  // Close old stmtLine and prepare correct one with NULLIF
  $stmtLine->close();
  $stmtLine = $conn->prepare("
    INSERT INTO purchase_bill_items
      (purchase_id, item_id, item_name, item_code, hsn, batch_no, exp_date,
       mrp, qty, purchase_price, sale_price, discount, tax_pct, amount)
    VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  foreach ($rows as $r) {
    $itemName = strv($r["itemName"] ?? "");
    if ($itemName === "") continue;

    $itemCode = strv($r["code"] ?? "");
    $hsn      = strv($r["hsn"] ?? "");
    $batchNo  = strv($r["batchNo"] ?? "");
    $expDate  = nullIfEmpty($r["expDate"] ?? "");
    $discount = strv($r["discount"] ?? "");

    $mrp           = num($r["mrp"] ?? 0);
    $qty           = num($r["qty"] ?? 0);
    $purchasePrice = num($r["purchasePrice"] ?? 0);
    $salePrice     = num($r["salePrice"] ?? 0);
    $taxPct        = num($r["tax"] ?? 0);
    $amount        = num($r["amount"] ?? 0);

    if ($qty <= 0) continue;

    // find item_id from items table (optional)
    $itemId = 0;
    if ($itemCode !== "") {
      $stmtFindItem->bind_param("s", $itemCode);
      $stmtFindItem->execute();
      $resItem = $stmtFindItem->get_result();
      if ($resItem && $resItem->num_rows > 0) {
        $itemId = intval($resItem->fetch_assoc()["id"]);
      }
    }

    // 14 params
    $stmtLine->bind_param(
      "iisssssddddsdd",
      $purchaseId,      // i
      $itemId,          // i (0 -> NULLIF -> NULL)
      $itemName,        // s
      $itemCode,        // s
      $hsn,             // s
      $batchNo,         // s
      $expDate,         // s (NULL ok)
      $mrp,             // d
      $qty,             // d
      $purchasePrice,   // d
      $salePrice,       // d
      $discount,        // s
      $taxPct,          // d
      $amount           // d
    );

    if (!$stmtLine->execute()) {
      throw new Exception("Failed to insert purchase line: " . $stmtLine->error);
    }
  }

  $stmtFindItem->close();
  $stmtLine->close();

  $conn->commit();

  echo json_encode([
    "status" => "success",
    "message" => "Purchase saved",
    "purchaseId" => $purchaseId
  ]);

} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
