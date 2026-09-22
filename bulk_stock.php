<?php
// Bulk-to-packet stock.
//
// Some goods are bought in bulk by weight (cashew, raisins, chia seeds) and sold
// as fixed-size packets that each carry their own barcode, i.e. their own `items`
// row. The packet rows hold NO inventory of their own: stock lives on the bulk
// item in kg, and selling one packet takes pack_weight kg off it.
//
//   items.bulk_item_id  -> the bulk item holding the stock
//   items.pack_weight   -> kg in one packet (0.250, 0.500, 1.000)
//
// An item is a PACKET when both are set, and a BULK item when items.is_bulk = 1.
// Everything here is a no-op for the thousands of ordinary items.
//
// Pricing: the bulk item's purchase_price, sale_price and mrp are PER KG. Every
// pack is priced at pack_weight x those, to the nearest rupee — see reprice_packs().

// Returns ["bulk_item_id" => int, "pack_weight" => float] for a packet, else null.
function pack_info($conn, $itemId) {
  if ($itemId <= 0) return null;
  $stmt = $conn->prepare("SELECT bulk_item_id, pack_weight FROM items WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $itemId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row || $row["bulk_item_id"] === null || floatval($row["pack_weight"]) <= 0) return null;
  return ["bulk_item_id" => intval($row["bulk_item_id"]), "pack_weight" => floatval($row["pack_weight"])];
}

// Batch order for consuming bulk stock. Mirrors compareBatchesForSale() in
// src/ui.jsx: usable stock first, soonest expiry first, undated after dated,
// larger quantity breaking ties.
const BULK_BATCH_ORDER = "
  ORDER BY (inv.exp_date IS NOT NULL AND inv.exp_date < CURDATE()) ASC,
           (inv.exp_date IS NULL) ASC,
           inv.exp_date ASC,
           inv.current_qty DESC,
           inv.id ASC
";

// Takes $kg off the bulk item, spreading across batches in the order above.
// Never drives a batch below zero; if the item is short, it takes what is there.
// Returns the batches consumed: [["batch_no","exp_date","qty"], ...].
function deduct_bulk_stock($conn, $bulkItemId, $kg) {
  $consumed = [];
  $remaining = round(floatval($kg), 3);
  if ($bulkItemId <= 0 || $remaining <= 0) return $consumed;

  $stmt = $conn->prepare("
    SELECT inv.id, inv.batch_no, inv.exp_date, inv.current_qty
    FROM inventory inv
    WHERE inv.item_id = ? AND inv.current_qty > 0
    " . BULK_BATCH_ORDER . "
    FOR UPDATE
  ");
  $stmt->bind_param("i", $bulkItemId);
  $stmt->execute();
  $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $upd = $conn->prepare("UPDATE inventory SET current_qty = GREATEST(current_qty - ?, 0) WHERE id = ?");
  foreach ($batches as $b) {
    if ($remaining <= 0.0005) break;
    $take = min($remaining, floatval($b["current_qty"]));
    if ($take <= 0) continue;
    $upd->bind_param("di", $take, $b["id"]);
    if (!$upd->execute()) throw new Exception("Bulk stock deduction failed: " . $upd->error);
    $consumed[] = ["batch_no" => $b["batch_no"], "exp_date" => $b["exp_date"], "qty" => $take];
    $remaining = round($remaining - $take, 3);
  }
  $upd->close();
  return $consumed;
}

// Puts $kg back on the bulk item — used when a sale is edited down or removed.
// Goes to the batch that would be consumed first, so a deduct/restore round trip
// leaves the same batch it came from.
function restore_bulk_stock($conn, $bulkItemId, $kg) {
  $kg = round(floatval($kg), 3);
  if ($bulkItemId <= 0 || $kg <= 0) return;

  $stmt = $conn->prepare("
    SELECT inv.id FROM inventory inv WHERE inv.item_id = ?
    " . BULK_BATCH_ORDER . "
    LIMIT 1
    FOR UPDATE
  ");
  $stmt->bind_param("i", $bulkItemId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) return;   // no batch ever existed for this bulk item — nothing to put back on

  $upd = $conn->prepare("UPDATE inventory SET current_qty = current_qty + ? WHERE id = ?");
  $upd->bind_param("di", $kg, $row["id"]);
  if (!$upd->execute()) throw new Exception("Bulk stock restore failed: " . $upd->error);
  $upd->close();
}

// Prices every pack of a bulk item from the bulk item's per-kg rates:
//   cost = weight x cost/kg (2 dp),  MRP = weight x MRP/kg,  sale = weight x sale/kg
// (both to the nearest rupee), and a pack's sale price is never allowed above its
// MRP. $fields says which prices moved: a bill that carries a new cost but no sale
// price must not touch the packs' selling prices (they may have been set by hand).
// A per-kg rate of 0 is never applied. $onlyItemId limits it to one pack — adding
// a new size must not reprice the sizes that are already there.
function reprice_packs($conn, $bulkId, $onlyItemId = 0, $fields = ["cost", "mrp", "sale"]) {
  $stmt = $conn->prepare("SELECT purchase_price, sale_price, mrp FROM items WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $bulkId);
  $stmt->execute();
  $b = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$b) return;
  $pp = floatval($b["purchase_price"]); $sp = floatval($b["sale_price"]); $mrp = floatval($b["mrp"]);

  $scope = "bulk_item_id = ? AND pack_weight > 0" . ($onlyItemId > 0 ? " AND id = ?" : "");
  $run = function ($set, $rate = null) use ($conn, $scope, $bulkId, $onlyItemId) {
    $stmt = $conn->prepare("UPDATE items SET $set WHERE $scope");
    if ($rate === null) {
      if ($onlyItemId > 0) $stmt->bind_param("ii", $bulkId, $onlyItemId);
      else                 $stmt->bind_param("i",  $bulkId);
    } elseif ($onlyItemId > 0) $stmt->bind_param("dii", $rate, $bulkId, $onlyItemId);
    else                       $stmt->bind_param("di",  $rate, $bulkId);
    if (!$stmt->execute()) throw new Exception("Pack repricing failed: " . $stmt->error);
    $stmt->close();
  };
  // CAST to DECIMAL keeps the arithmetic exact, so ROUND() rounds a half up
  // (0.25 x 170 = 42.5 -> 43). On a floating-point value MySQL may round half to
  // even instead, and 42.5 would come out as 42.
  $r = "CAST(? AS DECIMAL(12,4)) * pack_weight";
  $want = array_flip($fields);
  if ($pp > 0  && isset($want["cost"])) $run("purchase_price = ROUND($r, 2)", $pp);
  if ($mrp > 0 && isset($want["mrp"]))  $run("mrp = ROUND($r)", $mrp);
  // After MRP, so the cap uses the pack's new MRP.
  if ($sp > 0 && isset($want["sale"])) {
    $run("sale_price = LEAST(ROUND($r), CASE WHEN mrp > 0 THEN mrp ELSE 999999999 END)", $sp);
  } elseif ($mrp > 0 && isset($want["mrp"])) {
    // MRP moved but the sale price didn't: still never leave a pack above its MRP.
    $run("sale_price = LEAST(sale_price, CASE WHEN mrp > 0 THEN mrp ELSE 999999999 END)");
  }
}

// Moves whatever stock an item holds onto a bulk item, as kg, then empties the
// item. Used when an existing barcode is connected as a pack: its packets on the
// shelf are real goods and must not vanish from the count. The new bulk row has
// no purchase bill, so its quantity can be corrected by hand after a count.
// Returns the kg moved.
function move_stock_to_bulk($conn, $itemId, $bulkId, $packWeight) {
  $stmt = $conn->prepare("
    SELECT COALESCE(SUM(current_qty), 0) AS qty,
           SUM(current_qty * purchase_price) / NULLIF(SUM(current_qty), 0) AS avg_pp,
           MIN(exp_date) AS min_exp
    FROM inventory WHERE item_id = ? AND current_qty > 0
  ");
  $stmt->bind_param("i", $itemId);
  $stmt->execute();
  $s = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $qty = floatval($s["qty"]);
  if ($qty <= 0 || $packWeight <= 0) return 0;

  $stmt = $conn->prepare("SELECT i.code, b.purchase_price AS bulk_pp, b.tax_pct AS bulk_tax
                          FROM items i JOIN items b ON b.id = ? WHERE i.id = ? LIMIT 1");
  $stmt->bind_param("ii", $bulkId, $itemId);
  $stmt->execute();
  $m = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $kg     = round($qty * $packWeight, 3);
  $costKg = $s["avg_pp"] !== null ? round(floatval($s["avg_pp"]) / $packWeight, 2) : floatval($m["bulk_pp"]);
  $batch  = substr("MOVED-" . $m["code"], 0, 100);
  $tax    = floatval($m["bulk_tax"]);
  $exp    = $s["min_exp"];

  $ins = $conn->prepare("
    INSERT INTO inventory (item_id, purchase_bill_id, batch_no, exp_date, mrp, purchase_price,
                           sale_price, tax_pct, gst_flag, initial_qty, current_qty)
    VALUES (?, NULL, ?, ?, 0, ?, 0, ?, 1, ?, ?)
  ");
  $ins->bind_param("issdddd", $bulkId, $batch, $exp, $costKg, $tax, $kg, $kg);
  if (!$ins->execute()) throw new Exception("Moving stock to the bulk item failed: " . $ins->error);
  $ins->close();

  $z = $conn->prepare("UPDATE inventory SET current_qty = 0 WHERE item_id = ? AND current_qty > 0");
  $z->bind_param("i", $itemId);
  $z->execute();
  $z->close();
  return $kg;
}
