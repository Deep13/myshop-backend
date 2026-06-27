<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); echo json_encode(["status"=>"error","message"=>"Method not allowed"]); exit; }

include "db.php";

function strv($v){return trim((string)($v??""));}
function nullIfEmpty($s){$s=strv($s);return $s===""?null:$s;}
function num($v){if($v===null)return 0;if(is_numeric($v))return floatval($v);$s=trim((string)$v);if($s==="")return 0;$s=str_replace([",","₹","Rs.","INR"],"",$s);$s=preg_replace('/[^0-9.]/','',  $s);return is_numeric($s)?floatval($s):0;}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$purchaseId        = intval($body["purchaseId"] ?? 0);
$updatedBy         = intval($body["updatedBy"] ?? 0);
$distributorId     = intval($body["distributorId"] ?? 0);
$billNo            = strv($body["billNo"] ?? "");
$billDate          = strv($body["billDate"] ?? "");
$dueDate           = nullIfEmpty($body["dueDate"] ?? "");
$billType          = strv($body["billType"] ?? "GST");
$gstMode           = strv($body["gstMode"] ?? "exclusive");
$rows              = $body["rows"] ?? [];
$payments          = $body["payments"] ?? [];
$subTotal          = num($body["totals"]["subTotal"] ?? 0);
$taxTotal          = num($body["totals"]["taxTotal"] ?? 0);
$grandTotal        = num($body["totals"]["grandTotal"] ?? 0);
$roundOffEnabled   = !empty($body["totals"]["roundOffEnabled"]) ? 1 : 0;
$roundOffDiff      = num($body["totals"]["roundOffDiff"] ?? 0);
$roundedGrandTotal = num($body["totals"]["roundedGrandTotal"] ?? $grandTotal);

if (!in_array($billType, ["GST","NON-GST"])) $billType = "GST";
$gstFlag = ($billType === "GST") ? 1 : 0;

if ($purchaseId <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"purchaseId required"]); exit; }
if ($updatedBy <= 0)  { http_response_code(400); echo json_encode(["status"=>"error","message"=>"updatedBy required"]); exit; }
if ($distributorId <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"distributorId required"]); exit; }
if ($billNo === "" || $billDate === "") { http_response_code(400); echo json_encode(["status"=>"error","message"=>"billNo and billDate required"]); exit; }
if (!is_array($rows) || count($rows) === 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"rows required"]); exit; }

// Validate purchase + distributor
$stmtChk = $conn->prepare("SELECT id FROM purchase_bills WHERE id=? AND distributor_id=? LIMIT 1");
$stmtChk->bind_param("ii", $purchaseId, $distributorId);
$stmtChk->execute();
if ($stmtChk->get_result()->num_rows === 0) { $stmtChk->close(); http_response_code(404); echo json_encode(["status"=>"error","message"=>"Purchase not found"]); exit; }
$stmtChk->close();

$stmtD = $conn->prepare("SELECT id,name,gstin FROM distributors WHERE id=? LIMIT 1");
$stmtD->bind_param("i", $distributorId);
$stmtD->execute();
$resD = $stmtD->get_result();
if ($resD->num_rows === 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Distributor not found"]); exit; }
$dist = $resD->fetch_assoc(); $stmtD->close();
$distributorName = $dist["name"]; $gstin = $dist["gstin"] ?? "";

// Duplicate-bill guard — same distributor + same bill_no on a different purchase row
$stmtDup = $conn->prepare("SELECT id FROM purchase_bills WHERE distributor_id=? AND bill_no=? AND id<>? LIMIT 1");
$stmtDup->bind_param("isi", $distributorId, $billNo, $purchaseId);
$stmtDup->execute();
if ($stmtDup->get_result()->num_rows > 0) {
  $stmtDup->close();
  http_response_code(409);
  echo json_encode(["status"=>"error","message"=>"Bill no '$billNo' already exists for this distributor"]);
  exit;
}
$stmtDup->close();

$conn->begin_transaction();
try {
  // 1) Update header
  $stmtH = $conn->prepare("
    UPDATE purchase_bills SET
      distributor_id=?,distributor_name=?,distributor_gstin=?,bill_no=?,bill_date=?,due_date=?,
      sub_total=?,tax_total=?,grand_total=?,round_off_enabled=?,round_off_diff=?,rounded_grand_total=?,
      bill_type=?,gst_mode=?,updated_by=?,updated_at=NOW()
    WHERE id=?
  ");
  $stmtH->bind_param("isssssdddiddssii", $distributorId,$distributorName,$gstin,$billNo,$billDate,$dueDate,$subTotal,$taxTotal,$grandTotal,$roundOffEnabled,$roundOffDiff,$roundedGrandTotal,$billType,$gstMode,$updatedBy,$purchaseId);
  if (!$stmtH->execute()) throw new Exception("Update header failed: ".$stmtH->error);
  $stmtH->close();

  // 2) Capture how much of each existing batch has already been SOLD,
  //    so we can preserve that figure when re-inserting the inventory rows.
  $invExists = $conn->query("SHOW TABLES LIKE 'inventory'")->num_rows > 0;
  $soldByKey = [];          // key = item_id|batch_no => sold_qty
  if ($invExists) {
    $stmtOld = $conn->prepare("SELECT item_id, COALESCE(batch_no,'') AS batch_no, initial_qty, current_qty FROM inventory WHERE purchase_bill_id=?");
    $stmtOld->bind_param("i", $purchaseId);
    $stmtOld->execute();
    $resOld = $stmtOld->get_result();
    while ($row = $resOld->fetch_assoc()) {
      $key = intval($row["item_id"]) . "|" . $row["batch_no"];
      $soldByKey[$key] = floatval($row["initial_qty"]) - floatval($row["current_qty"]);
    }
    $stmtOld->close();

    // Now safe to clear the rows — sold qty is captured in $soldByKey
    $stmtDelInv = $conn->prepare("DELETE FROM inventory WHERE purchase_bill_id=?");
    $stmtDelInv->bind_param("i", $purchaseId);
    $stmtDelInv->execute(); $stmtDelInv->close();
  }

  // 3) Replace items
  $stmtDelItems = $conn->prepare("DELETE FROM purchase_bill_items WHERE purchase_id=?");
  $stmtDelItems->bind_param("i", $purchaseId);
  if (!$stmtDelItems->execute()) throw new Exception("Delete items failed");
  $stmtDelItems->close();

  $stmtFindItem = $conn->prepare("SELECT id FROM items WHERE code=? LIMIT 1");
  $stmtLine = $conn->prepare("
    INSERT INTO purchase_bill_items
      (purchase_id,item_id,item_name,item_code,hsn,batch_no,exp_date,mrp,qty,free_qty,purchase_price,sale_price,discount,tax_pct,amount,gst_flag)
    VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");

  $stmtInv = null;
  if ($invExists) {
    $stmtInv = $conn->prepare("
      INSERT INTO inventory (item_id,purchase_bill_id,batch_no,exp_date,mrp,purchase_price,sale_price,tax_pct,gst_flag,initial_qty,current_qty)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE initial_qty=VALUES(initial_qty),current_qty=VALUES(current_qty),mrp=VALUES(mrp),purchase_price=VALUES(purchase_price),sale_price=VALUES(sale_price)
    ");
  }

  foreach ($rows as $r) {
    $itemName = strv($r["itemName"] ?? ""); if ($itemName === "") continue;
    $itemCode = strv($r["code"] ?? ""); $hsn=strv($r["hsn"]??""); $batchNo=strv($r["batchNo"]??"");
    $expDate=nullIfEmpty($r["expDate"]??""); $discount=strv($r["discount"]??"");
    $mrp=num($r["mrp"]??0); $qty=num($r["qty"]??0); $freeQty=num($r["freeQty"]??0);
    $stockQty=$qty+$freeQty; $purchasePrice=num($r["purchasePrice"]??0);
    $salePrice=num($r["salePrice"]??0); $taxPct=num($r["tax"]??0); $amount=num($r["amount"]??0);
    if ($qty <= 0) continue;

    $itemId = 0;
    if ($itemCode !== "") {
      $stmtFindItem->bind_param("s", $itemCode); $stmtFindItem->execute();
      $resItem = $stmtFindItem->get_result();
      if ($resItem && $resItem->num_rows > 0) $itemId = intval($resItem->fetch_assoc()["id"]);
    }
    if ($itemId === 0) throw new Exception("Item '".$itemName."' not found in item master.");

    $stmtLine->bind_param("iisssssdddddsddi",$purchaseId,$itemId,$itemName,$itemCode,$hsn,$batchNo,$expDate,$mrp,$qty,$freeQty,$purchasePrice,$salePrice,$discount,$taxPct,$amount,$gstFlag);
    if (!$stmtLine->execute()) throw new Exception("Insert item failed: ".$stmtLine->error);

    if ($stmtInv) {
      // Preserve already-sold qty for this batch so we don't reset stock back to full.
      $key = $itemId . "|" . $batchNo;
      $sold = isset($soldByKey[$key]) ? $soldByKey[$key] : 0;
      $newCurrent = max(0, $stockQty - $sold);
      $stmtInv->bind_param("iissddddidd",$itemId,$purchaseId,$batchNo,$expDate,$mrp,$purchasePrice,$salePrice,$taxPct,$gstFlag,$stockQty,$newCurrent);
      if (!$stmtInv->execute()) throw new Exception("Inventory update failed: ".$stmtInv->error);
    }
  }

  $stmtFindItem->close(); $stmtLine->close();
  if ($stmtInv) $stmtInv->close();

  // 4) Replace payments
  $stmtDelPay = $conn->prepare("DELETE FROM purchase_payments WHERE purchase_id=?");
  $stmtDelPay->bind_param("i", $purchaseId);
  $stmtDelPay->execute(); $stmtDelPay->close();

  if (is_array($payments) && count($payments) > 0) {
    $stmtPay = $conn->prepare("INSERT INTO purchase_payments (distributor_id,purchase_id,pay_date,mode,amount,reference_no,note) VALUES (?,?,?,?,?,'','')");
    foreach ($payments as $p) {
      $mode=strv($p["type"]??"Cash"); $amt=num($p["amount"]??0);
      if ($amt <= 0) continue;
      $stmtPay->bind_param("iissd",$distributorId,$purchaseId,$billDate,$mode,$amt);
      if (!$stmtPay->execute()) throw new Exception("Insert payment failed: ".$stmtPay->error);
    }
    $stmtPay->close();
  }

  $conn->commit();
  echo json_encode(["status"=>"success","message"=>"Purchase updated","purchaseId"=>$purchaseId]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
