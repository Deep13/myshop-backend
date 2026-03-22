<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); echo json_encode(["status"=>"error","message"=>"Method not allowed"]); exit; }

include "db.php";

function num($v){if($v===null)return 0;if(is_numeric($v))return floatval($v);$s=trim((string)$v);if($s==="")return 0;$s=str_replace([",","₹","Rs.","INR"],"",$s);$s=preg_replace('/[^0-9.]/','',  $s);return is_numeric($s)?floatval($s):0;}
function strv($v){return trim((string)($v??""));}
function nullIfEmpty($s){$s=strv($s);return $s===""?null:$s;}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$distributorId     = intval($body["distributorId"] ?? 0);
$billNo            = strv($body["billNo"] ?? "");
$billDate          = strv($body["billDate"] ?? "");
$dueDate           = nullIfEmpty($body["dueDate"] ?? "");
$billType          = strv($body["billType"] ?? "GST"); // GST | NON-GST
$rows              = $body["rows"] ?? null;
$subTotal          = num($body["totals"]["subTotal"] ?? 0);
$taxTotal          = num($body["totals"]["taxTotal"] ?? 0);
$grandTotal        = num($body["totals"]["grandTotal"] ?? 0);
$createdBy         = intval($body["createdBy"] ?? 0);
$roundOffEnabled   = !empty($body["totals"]["roundOffEnabled"]) ? 1 : 0;
$roundOffDiff      = num($body["totals"]["roundOffDiff"] ?? 0);
$roundedGrandTotal = num($body["totals"]["roundedGrandTotal"] ?? $grandTotal);

if (!in_array($billType, ["GST","NON-GST"])) $billType = "GST";
$gstFlag = ($billType === "GST") ? 1 : 0;

if ($distributorId <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"distributorId required"]); exit; }
if ($billNo === "" || $billDate === "") { http_response_code(400); echo json_encode(["status"=>"error","message"=>"billNo and billDate required"]); exit; }
if (!is_array($rows) || count($rows) === 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"rows required"]); exit; }
if ($createdBy <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"createdBy required"]); exit; }

// Validate distributor
$stmtD = $conn->prepare("SELECT id,name,gstin FROM distributors WHERE id=? LIMIT 1");
$stmtD->bind_param("i", $distributorId);
$stmtD->execute();
$resD = $stmtD->get_result();
if ($resD->num_rows === 0) { $stmtD->close(); http_response_code(400); echo json_encode(["status"=>"error","message"=>"Distributor not found"]); exit; }
$dist = $resD->fetch_assoc();
$stmtD->close();
$distributorName  = $dist["name"];
$distributorGstin = $dist["gstin"] ?? null;

$conn->begin_transaction();
try {
  // 1) Insert purchase header
  $stmtH = $conn->prepare("
    INSERT INTO purchase_bills
      (distributor_id, distributor_name, distributor_gstin, bill_no, bill_date, due_date,
       sub_total, tax_total, grand_total, round_off_enabled, round_off_diff, rounded_grand_total,
       bill_type, created_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $stmtH->bind_param("isssssdddiddsi", $distributorId,$distributorName,$distributorGstin,$billNo,$billDate,$dueDate,$subTotal,$taxTotal,$grandTotal,$roundOffEnabled,$roundOffDiff,$roundedGrandTotal,$billType,$createdBy);
  if (!$stmtH->execute()) throw new Exception("Insert header failed: ".$stmtH->error);
  $purchaseId = $stmtH->insert_id;
  $stmtH->close();

  // 2) Insert items + update inventory
  $stmtFindItem = $conn->prepare("SELECT id FROM items WHERE code=? LIMIT 1");
  $stmtLine = $conn->prepare("
    INSERT INTO purchase_bill_items
      (purchase_id, item_id, item_name, item_code, hsn, batch_no, exp_date,
       mrp, qty, purchase_price, sale_price, discount, tax_pct, amount, gst_flag)
    VALUES (?, NULLIF(?,0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");
  $stmtInv = $conn->prepare("
    INSERT INTO inventory
      (item_id, purchase_bill_id, batch_no, exp_date, mrp, purchase_price, sale_price, tax_pct, gst_flag, initial_qty, current_qty)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      initial_qty   = initial_qty + VALUES(initial_qty),
      current_qty   = current_qty + VALUES(current_qty),
      mrp           = VALUES(mrp),
      purchase_price= VALUES(purchase_price),
      sale_price    = VALUES(sale_price)
  ");

  foreach ($rows as $r) {
    $itemName = strv($r["itemName"] ?? "");
    if ($itemName === "") continue;
    $itemCode      = strv($r["code"] ?? "");
    $hsn           = strv($r["hsn"] ?? "");
    $batchNo       = strv($r["batchNo"] ?? "");
    $expDate       = nullIfEmpty($r["expDate"] ?? "");
    $discount      = strv($r["discount"] ?? "");
    $mrp           = num($r["mrp"] ?? 0);
    $qty           = num($r["qty"] ?? 0);
    $purchasePrice = num($r["purchasePrice"] ?? 0);
    $salePrice     = num($r["salePrice"] ?? 0);
    $taxPct        = num($r["tax"] ?? 0);
    $amount        = num($r["amount"] ?? 0);
    if ($qty <= 0) continue;

    // Validate item exists in master (item_id required)
    $itemId = 0;
    if ($itemCode !== "") {
      $stmtFindItem->bind_param("s", $itemCode);
      $stmtFindItem->execute();
      $resItem = $stmtFindItem->get_result();
      if ($resItem && $resItem->num_rows > 0) $itemId = intval($resItem->fetch_assoc()["id"]);
    }
    if ($itemId === 0) throw new Exception("Item '".$itemName."' not found in item master. All items must be from master.");

    // Insert purchase line
    // 15 params: i i s s s s s d d d d s d d i
    // purchase_id, item_id, item_name, item_code, hsn, batch_no, exp_date,
    // mrp, qty, purchase_price, sale_price, discount, tax_pct, amount, gst_flag
    $stmtLine->bind_param("iisssssddddsddi",
      $purchaseId,$itemId,$itemName,$itemCode,$hsn,$batchNo,$expDate,
      $mrp,$qty,$purchasePrice,$salePrice,$discount,$taxPct,$amount,$gstFlag
    );
    if (!$stmtLine->execute()) throw new Exception("Insert item failed: ".$stmtLine->error);

    // Upsert inventory
    $stmtInv->bind_param("iissddddidd",
      $itemId,$purchaseId,$batchNo,$expDate,$mrp,$purchasePrice,$salePrice,$taxPct,$gstFlag,$qty,$qty
    );
    if (!$stmtInv->execute()) throw new Exception("Inventory update failed: ".$stmtInv->error);
  }

  $stmtFindItem->close();
  $stmtLine->close();
  $stmtInv->close();
  $conn->commit();

  echo json_encode(["status"=>"success","message"=>"Purchase saved","purchaseId"=>$purchaseId]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
