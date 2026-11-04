<?php
// Buy prices are worked back from the line total off the bill (total ÷ qty), so
// they need more than two decimals or the line no longer adds back up to the
// bill. Widen the purchase-line and batch cost columns to 4 places, once.
// Runs before the save transaction opens: ALTER TABLE commits implicitly.
function ensure_purchase_precision($conn) {
  $res = $conn->query("
    SELECT NUMERIC_SCALE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_bill_items' AND COLUMN_NAME = 'purchase_price'
  ");
  $row = $res ? $res->fetch_assoc() : null;
  if (!$row || intval($row["NUMERIC_SCALE"]) >= 4) return;

  $conn->query("ALTER TABLE purchase_bill_items
    MODIFY purchase_price DECIMAL(12,4) DEFAULT 0.0000,
    MODIFY amount DECIMAL(14,4) DEFAULT 0.0000");
  $conn->query("ALTER TABLE inventory MODIFY purchase_price DECIMAL(12,4) DEFAULT 0.0000");
}
