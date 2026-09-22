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

function strv($v){ return trim((string)($v ?? "")); }
function num($v){
  if ($v === null) return 0;
  if (is_numeric($v)) return floatval($v);
  $s = trim((string)$v);
  if ($s === "") return 0;
  $s = str_replace([",","₹","Rs.","INR"], "", $s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  return is_numeric($s) ? floatval($s) : 0;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$id   = intval($body["id"] ?? 0);
$name = strv($body["name"] ?? "");
$code = strv($body["code"] ?? "");
$hsn  = strv($body["hsn"] ?? "");
$category = strv($body["category"] ?? "");
$mrp  = num($body["mrp"] ?? 0);
$salePrice = num($body["salePrice"] ?? 0);
$purchasePrice = num($body["purchasePrice"] ?? 0);
$tax  = num($body["tax"] ?? 0);
$packSize     = isset($body["packSize"])     ? num($body["packSize"])     : null;
$bagSalePrice = isset($body["bagSalePrice"]) ? num($body["bagSalePrice"]) : null;
if ($packSize !== null && $packSize <= 0)         $packSize = null;
if ($bagSalePrice !== null && $bagSalePrice <= 0) $bagSalePrice = null;
$is_primary = !empty($body["is_primary"]) ? 1 : 0;
// Optional: when absent, the item's bulk switch is left as it is.
$isBulkIn = array_key_exists("isBulk", $body) ? (!empty($body["isBulk"]) ? 1 : 0) : null;

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Item id is required"]);
  exit;
}
if ($name === "" || $code === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"name and code are required"]);
  exit;
}

if ($is_primary == 0) $tax = 0;

// Check code uniqueness (exclude self)
$stmtC = $conn->prepare("SELECT id FROM items WHERE code=? AND id!=? LIMIT 1");
$stmtC->bind_param("si", $code, $id);
$stmtC->execute();
if ($stmtC->get_result()->num_rows > 0) {
  $stmtC->close();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Item code already used by another item"]);
  exit;
}
$stmtC->close();

// ── Bulk switch ─────────────────────────────────────────────────────────────
// Pack links are managed from the bulk item (set_pack.php) and are never touched
// here. A bulk item holds stock in kg and its prices are per kg.
$stmtO = $conn->prepare("SELECT is_bulk, bulk_item_id, purchase_price, sale_price, mrp,
                                (SELECT COUNT(*) FROM items c WHERE c.bulk_item_id = items.id) AS packs
                         FROM items WHERE id=? LIMIT 1");
$stmtO->bind_param("i", $id);
$stmtO->execute();
$old = $stmtO->get_result()->fetch_assoc();
$stmtO->close();
if (!$old) {
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"Item not found"]);
  exit;
}
$isBulk = $isBulkIn ?? intval($old["is_bulk"]);
if ($isBulk === 1 && !empty($old["bulk_item_id"])) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"This item is a pack size of another bulk item. Disconnect it there before making it a bulk item."]);
  exit;
}
if ($isBulk === 1 && preg_match('/^Rice\b/i', $category) && $packSize !== null) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"A Rice bag item is priced per bag and cannot be a bulk item"]);
  exit;
}
if ($isBulk === 0 && intval($old["packs"]) > 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"This bulk item still has ".$old["packs"]." pack size(s). Disconnect them first."]);
  exit;
}

$stmt = $conn->prepare("
  UPDATE items SET name=?, code=?, hsn=?, category=?, mrp=?, sale_price=?, pack_size=?, bag_sale_price=?, purchase_price=?, tax_pct=?, is_primary=?, is_bulk=?
  WHERE id=?
");
$stmt->bind_param("ssssddddddiii", $name, $code, $hsn, $category, $mrp, $salePrice, $packSize, $bagSalePrice, $purchasePrice, $tax, $is_primary, $isBulk, $id);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Update failed: ".$stmt->error]);
  exit;
}
$stmt->close();

// A change to a bulk item's per-kg prices reprices its packs, the same way a
// purchase does. Saving the item with the same prices (a rename, say) does not,
// so hand edits to individual packs survive until the prices actually move.
$moved = [];
if (abs(floatval($old["purchase_price"]) - $purchasePrice) > 0.005) $moved[] = "cost";
if (abs(floatval($old["mrp"])            - $mrp)           > 0.005) $moved[] = "mrp";
if (abs(floatval($old["sale_price"])     - $salePrice)     > 0.005) $moved[] = "sale";
if ($isBulk === 1 && $moved) {
  require_once __DIR__ . "/bulk_stock.php";
  reprice_packs($conn, $id, 0, $moved);
}

echo json_encode([
  "status" => "success",
  "message" => "Item updated",
  "data" => [
    "id" => $id, "name" => $name, "code" => $code, "hsn" => $hsn, "category" => $category,
    "mrp" => $mrp, "salePrice" => $salePrice, "packSize" => $packSize, "bagSalePrice" => $bagSalePrice,
    "purchasePrice" => $purchasePrice, "tax" => $tax, "is_primary" => $is_primary,
  ]
]);
