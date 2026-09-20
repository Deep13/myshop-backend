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
// An item is a PACKET when both are set. Everything here is a no-op for the
// thousands of ordinary items where they are NULL.

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
