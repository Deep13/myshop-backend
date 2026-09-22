<?php
// Manage the pack sizes of a bulk item, from the bulk item's side.
//
// POST JSON:
//   { "action": "create",     "bulkItemId": 5725, "name": "BADAM 250G", "code": "…", "packWeight": 0.25 }
//   { "action": "connect",    "bulkItemId": 5725, "itemId": 5474, "packWeight": 0.25 }
//   { "action": "disconnect", "itemId": 5474 }
//   { "action": "setStock",   "bulkItemId": 5725, "kg": 10.5 }
//
// create     — a new barcode for a new size. Inherits the bulk item's category,
//              HSN and GST; priced from its per-kg rates.
// connect    — turns an existing item into a size of this bulk item. Any stock it
//              holds moves onto the bulk item as kg (it is the same goods).
// disconnect — makes the item an ordinary item again. It holds no stock (packs
//              never do), so there is nothing to move back.
// setStock   — sets the bulk item's total stock to a counted figure in kg. A
//              lower count comes off the batches soonest-expiry first, exactly
//              as sales do; a higher one goes into a dated "ADJ" batch at the
//              bulk item's cost, so no purchase bill is altered.
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); echo json_encode(["status"=>"error","message"=>"Method not allowed"]); exit; }

include "db.php";
require_once __DIR__ . "/bulk_stock.php";

function fail($msg, $code = 400) { http_response_code($code); echo json_encode(["status"=>"error","message"=>$msg]); exit; }
function item_row($conn, $id) {
  $s = $conn->prepare("SELECT id, name, code, hsn, category, tax_pct, is_primary, pack_size,
                              bulk_item_id, pack_weight, is_bulk FROM items WHERE id=? LIMIT 1");
  $s->bind_param("i", $id);
  $s->execute();
  $r = $s->get_result()->fetch_assoc();
  $s->close();
  return $r;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) fail("Invalid JSON");
$action     = trim((string)($body["action"] ?? ""));
$bulkId     = intval($body["bulkItemId"] ?? 0);
$itemId     = intval($body["itemId"] ?? 0);
$packWeight = round(floatval($body["packWeight"] ?? 0), 3);

if (!in_array($action, ["create", "connect", "disconnect", "setStock"], true)) fail("Unknown action");

$conn->begin_transaction();
try {
  if ($action === "disconnect") {
    $it = item_row($conn, $itemId);
    if (!$it) fail("Item not found", 404);
    if (empty($it["bulk_item_id"])) fail("This item is not a pack size");
    $s = $conn->prepare("UPDATE items SET bulk_item_id = NULL, pack_weight = NULL WHERE id = ?");
    $s->bind_param("i", $itemId);
    $s->execute();
    $s->close();
    $conn->commit();
    echo json_encode(["status"=>"success","message"=>"Disconnected","itemId"=>$itemId]);
    exit;
  }

  // Every other action needs a real bulk item.
  $bulk = item_row($conn, $bulkId);
  if (!$bulk) fail("Bulk item not found", 404);
  if (intval($bulk["is_bulk"]) !== 1) fail("'".$bulk["name"]."' is not marked as a bulk item");

  if ($action === "setStock") {
    if (!isset($body["kg"]) || !is_numeric($body["kg"])) fail("Enter the counted stock in kg");
    $target = round(floatval($body["kg"]), 3);
    if ($target < 0 || $target > 100000) fail("Stock must be between 0 and 100000 kg");

    $s = $conn->prepare("SELECT COALESCE(SUM(current_qty), 0) AS kg FROM inventory WHERE item_id = ? FOR UPDATE");
    $s->bind_param("i", $bulkId);
    $s->execute();
    $before = round(floatval($s->get_result()->fetch_assoc()["kg"]), 3);
    $s->close();
    $delta = round($target - $before, 3);

    if ($delta < 0) {
      deduct_bulk_stock($conn, $bulkId, -$delta);
    } elseif ($delta > 0) {
      // One adjustment batch per day: top it up if today's already exists.
      $batch = "ADJ-" . date("Ymd");
      $s = $conn->prepare("SELECT id FROM inventory WHERE item_id = ? AND batch_no = ? AND purchase_bill_id IS NULL LIMIT 1");
      $s->bind_param("is", $bulkId, $batch);
      $s->execute();
      $adj = $s->get_result()->fetch_assoc();
      $s->close();
      if ($adj) {
        $s = $conn->prepare("UPDATE inventory SET current_qty = current_qty + ?, initial_qty = initial_qty + ? WHERE id = ?");
        $s->bind_param("ddi", $delta, $delta, $adj["id"]);
      } else {
        $cost = floatval($conn->query("SELECT purchase_price FROM items WHERE id = $bulkId")->fetch_assoc()["purchase_price"]);
        $s = $conn->prepare("
          INSERT INTO inventory (item_id, purchase_bill_id, batch_no, exp_date, mrp, purchase_price,
                                 sale_price, tax_pct, gst_flag, initial_qty, current_qty)
          VALUES (?, NULL, ?, NULL, 0, ?, 0, ?, 1, ?, ?)
        ");
        $tax = floatval($bulk["tax_pct"]);
        $s->bind_param("isdddd", $bulkId, $batch, $cost, $tax, $delta, $delta);
      }
      if (!$s->execute()) throw new Exception("Stock adjustment failed: " . $s->error);
      $s->close();
    }

    $after = round(floatval($conn->query("SELECT COALESCE(SUM(current_qty),0) AS kg FROM inventory WHERE item_id = $bulkId")->fetch_assoc()["kg"]), 3);
    $conn->commit();
    echo json_encode(["status"=>"success","message"=>"Stock set","beforeKg"=>$before,"afterKg"=>$after,"changeKg"=>round($after - $before, 3)]);
    exit;
  }

  // create / connect need a sensible weight.
  if ($packWeight <= 0 || $packWeight > 100) fail("Pack weight must be between 0 and 100 kg");

  if ($action === "create") {
    $name = trim((string)($body["name"] ?? ""));
    $code = trim((string)($body["code"] ?? ""));
    if ($name === "" || $code === "") fail("Name and barcode are required");
    $d = $conn->prepare("SELECT id, name FROM items WHERE code = ? LIMIT 1");
    $d->bind_param("s", $code);
    $d->execute();
    $dup = $d->get_result()->fetch_assoc();
    $d->close();
    if ($dup) fail("Barcode ".$code." is already used by '".$dup["name"]."'");

    $s = $conn->prepare("
      INSERT INTO items (name, code, hsn, category, mrp, sale_price, purchase_price, tax_pct,
                         is_primary, bulk_item_id, pack_weight)
      VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?)
    ");
    $s->bind_param("ssssdiid", $name, $code, $bulk["hsn"], $bulk["category"],
                   $bulk["tax_pct"], $bulk["is_primary"], $bulkId, $packWeight);
    if (!$s->execute()) throw new Exception("Creating the pack failed: " . $s->error);
    $newId = $s->insert_id;
    $s->close();
    reprice_packs($conn, $bulkId, $newId);
    $conn->commit();
    echo json_encode(["status"=>"success","message"=>"Pack size added","itemId"=>$newId]);
    exit;
  }

  // connect
  $it = item_row($conn, $itemId);
  if (!$it) fail("Item not found", 404);
  if ($itemId === $bulkId) fail("An item cannot be a pack size of itself");
  if (intval($it["is_bulk"]) === 1) fail("'".$it["name"]."' is itself a bulk item");
  if (!empty($it["bulk_item_id"])) fail("'".$it["name"]."' is already a pack size of another bulk item — disconnect it there first");
  if (preg_match('/^Rice\b/i', (string)$it["category"]) && floatval($it["pack_size"]) > 0)
    fail("'".$it["name"]."' is a Rice bag item, which is priced differently");

  $movedKg = move_stock_to_bulk($conn, $itemId, $bulkId, $packWeight);
  $s = $conn->prepare("UPDATE items SET bulk_item_id = ?, pack_weight = ? WHERE id = ?");
  $s->bind_param("idi", $bulkId, $packWeight, $itemId);
  $s->execute();
  $s->close();
  reprice_packs($conn, $bulkId, $itemId);
  $conn->commit();
  echo json_encode(["status"=>"success","message"=>"Connected","itemId"=>$itemId,"movedKg"=>$movedKg]);
} catch (Exception $e) {
  $conn->rollback();
  fail($e->getMessage(), 500);
}
