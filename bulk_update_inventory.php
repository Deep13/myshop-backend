<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") { http_response_code(405); echo json_encode(["status"=>"error","message"=>"Method not allowed"]); exit; }

include "db.php";

// Quick edit of MRP, sale price and stock on many batches at once — the
// Inventory page's Quick Edit grid and the mobile scan screen.
//   { changes: [{ inventoryId, mrp?, salePrice?, currentQty? }], updatedBy }
// A field left out (or sent as "") is not touched.
//
// The till prices a numbered batch off the batch itself, but an un-numbered
// batch off the item master (withMasterPricing), and new purchases default to
// the master too. So when the edited batch has no batch number, or is the
// item's newest batch, its MRP / sale price are copied onto the item as well.

$body = json_decode(file_get_contents("php://input"), true);
$changes   = $body["changes"] ?? null;
$updatedBy = intval($body["updatedBy"] ?? 0);
if (!is_array($changes) || count($changes) === 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"No changes sent"]);
  exit;
}

function optNum($c, $k) {
  if (!array_key_exists($k, $c) || $c[$k] === "" || $c[$k] === null) return null;
  return is_numeric($c[$k]) ? floatval($c[$k]) : false;
}

$stmtGet = $conn->prepare("
  SELECT inv.id, inv.item_id, inv.batch_no, i.name,
         (SELECT MAX(x.id) FROM inventory x WHERE x.item_id = inv.item_id) AS newest_id
  FROM inventory inv JOIN items i ON i.id = inv.item_id
  WHERE inv.id = ? LIMIT 1
");

$conn->begin_transaction();
try {
  $updated = 0;
  foreach ($changes as $c) {
    $invId = intval($c["inventoryId"] ?? 0);
    $mrp   = optNum($c, "mrp");
    $sale  = optNum($c, "salePrice");
    $qty   = optNum($c, "currentQty");
    if ($invId <= 0) continue;

    $stmtGet->bind_param("i", $invId);
    $stmtGet->execute();
    $row = $stmtGet->get_result()->fetch_assoc();
    if (!$row) throw new Exception("Stock row $invId not found — refresh and try again.");
    $label = $row["name"] . ($row["batch_no"] ? " (batch " . $row["batch_no"] . ")" : "");

    if ($mrp === false || $sale === false || $qty === false) throw new Exception("$label: enter numbers only.");
    if ($mrp !== null && $mrp < 0)  throw new Exception("$label: MRP can't be negative.");
    if ($sale !== null && $sale < 0) throw new Exception("$label: sale price can't be negative.");
    if ($qty !== null && $qty < 0)  throw new Exception("$label: stock can't be negative.");
    // Same ceiling as update_inventory_batch.php: a mis-keyed count once put
    // crores of stock on the books.
    if ($qty !== null && $qty > 100000) throw new Exception("$label: that stock looks mis-typed. Enter 100000 or less.");

    $sets = []; $types = ""; $vals = [];
    if ($mrp !== null)  { $sets[] = "mrp = ?";        $types .= "d"; $vals[] = $mrp; }
    if ($sale !== null) { $sets[] = "sale_price = ?"; $types .= "d"; $vals[] = $sale; }
    if ($qty !== null)  {
      // initial_qty is the "stock ever held" ceiling — raise it with a recount above it.
      $sets[] = "current_qty = ?";                   $types .= "d"; $vals[] = $qty;
      $sets[] = "initial_qty = GREATEST(initial_qty, ?)"; $types .= "d"; $vals[] = $qty;
    }
    if (!$sets) continue;
    $sets[] = "updated_by = NULLIF(?, 0)"; $types .= "i"; $vals[] = $updatedBy;
    $types .= "i"; $vals[] = $invId;

    $st = $conn->prepare("UPDATE inventory SET " . implode(", ", $sets) . " WHERE id = ? LIMIT 1");
    $st->bind_param($types, ...$vals);
    if (!$st->execute()) throw new Exception("Update failed: " . $st->error);
    $st->close();
    $updated++;

    $noBatch = trim((string)$row["batch_no"]) === "";
    $newest  = intval($row["newest_id"]) === $invId;
    if (($noBatch || $newest) && ($mrp !== null || $sale !== null)) {
      $iSets = []; $iTypes = ""; $iVals = [];
      if ($mrp !== null)  { $iSets[] = "mrp = ?";        $iTypes .= "d"; $iVals[] = $mrp; }
      if ($sale !== null) { $iSets[] = "sale_price = ?"; $iTypes .= "d"; $iVals[] = $sale; }
      $iTypes .= "i"; $iVals[] = intval($row["item_id"]);
      $st = $conn->prepare("UPDATE items SET " . implode(", ", $iSets) . " WHERE id = ? LIMIT 1");
      $st->bind_param($iTypes, ...$iVals);
      if (!$st->execute()) throw new Exception("Item update failed: " . $st->error);
      $st->close();
    }
  }
  $stmtGet->close();
  $conn->commit();
  echo json_encode(["status"=>"success","updated"=>$updated]);
} catch (Exception $e) {
  $conn->rollback();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
