<?php
// Stock movements for ordinary (non-packet) items at the till.
//
// One item + batch number can have several inventory rows — the same goods
// bought on two bills, or bought twice with no batch number at all. A sale
// used to take its whole quantity off whichever one row the database found
// first, never below zero; if that row held less than was sold, the rest
// silently stayed on the books. These spread the quantity across the rows.

// Rows of item + batch, in the order stock is taken: the row the till picked
// first, then rows that still hold stock, earliest expiry, oldest first.
function batch_rows_for_update($conn, $itemId, $batch, $preferId = 0) {
  $stmt = $conn->prepare("
    SELECT id, current_qty FROM inventory
    WHERE item_id = ? AND batch_no = ?
    ORDER BY (id = ?) DESC, (current_qty > 0) DESC, (exp_date IS NULL), exp_date, id
    FOR UPDATE
  ");
  $stmt->bind_param("isi", $itemId, $batch, $preferId);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows;
}

// Takes $qty off item + batch. Never drives a row below zero; if the batch is
// short altogether, it takes what is there (as before).
function deduct_batch_stock($conn, $itemId, $batch, $qty, $preferId = 0) {
  $remaining = round(floatval($qty), 3);
  if ($itemId <= 0 || $remaining <= 0) return;
  $upd = $conn->prepare("UPDATE inventory SET current_qty = GREATEST(current_qty - ?, 0) WHERE id = ?");
  foreach (batch_rows_for_update($conn, $itemId, (string)$batch, intval($preferId)) as $r) {
    if ($remaining <= 0.0005) break;
    $take = min($remaining, floatval($r["current_qty"]));
    if ($take <= 0) continue;
    $upd->bind_param("di", $take, $r["id"]);
    if (!$upd->execute()) throw new Exception("Stock deduction failed: " . $upd->error);
    $remaining = round($remaining - $take, 3);
  }
  $upd->close();
}

// Puts $qty back on item + batch — a sale edited down. Goes to the row that
// would be taken from first, so a deduct/restore round trip lands where it came from.
function restore_batch_stock($conn, $itemId, $batch, $qty, $preferId = 0) {
  $qty = round(floatval($qty), 3);
  if ($itemId <= 0 || $qty <= 0) return;
  $rows = batch_rows_for_update($conn, $itemId, (string)$batch, intval($preferId));
  if (!$rows) return; // no stock row for this batch — nothing to put back on
  $upd = $conn->prepare("UPDATE inventory SET current_qty = current_qty + ? WHERE id = ?");
  $upd->bind_param("di", $qty, $rows[0]["id"]);
  if (!$upd->execute()) throw new Exception("Stock restore failed: " . $upd->error);
  $upd->close();
}
