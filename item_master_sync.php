<?php
// Keeps the items master in step with each item's most recent purchase.
//
// Called once per purchase line from save_purchase.php and update_purchase.php.
// The master only moves when this bill is the item's latest purchase by bill
// date, so a back-dated entry never overwrites newer prices.
//
// Stored conventions (the same ones get_profit_report.php and the sales
// screens already assume):
//   mrp, sale_price  tax-inclusive, copied as entered on the bill
//   purchase_price   GST-inclusive landed cost per unit
//   tax_pct          taken from GST bills only (NON-GST lines always carry 0)
// Rice keeps its formula-driven pricing, based on the raw bill rate.

const RICE_DELIVERY   = 13;
const RICE_KG_MARKUP  = 5;
const RICE_BAG_MARKUP = 50;

// Mirrors the cost basis in get_profit_report.php.
function effective_purchase_price($pp, $billType, $gstMode, $lineTax, $masterTax) {
  if ($billType === "NON-GST")  return $pp * (1 + $masterTax / 100);
  if ($gstMode === "exclusive") return $pp * (1 + $lineTax / 100);
  return $pp;
}

function is_latest_purchase($conn, $itemId, $purchaseId, $billDate) {
  $stmt = $conn->prepare("
    SELECT MAX(pb.bill_date) AS latest
    FROM purchase_bill_items pbi
    JOIN purchase_bills pb ON pb.id = pbi.purchase_id
    WHERE pbi.item_id = ? AND pb.id <> ?
  ");
  $stmt->bind_param("ii", $itemId, $purchaseId);
  $stmt->execute();
  $latest = $stmt->get_result()->fetch_assoc()["latest"] ?? null;
  $stmt->close();
  return $latest === null || strcmp($billDate, $latest) >= 0;
}

function sync_item_master($conn, $itemId, $purchaseId, $billDate, $billType, $gstMode,
                          $mrp, $purchasePrice, $salePrice, $taxPct) {
  if ($itemId <= 0) return;
  if (!is_latest_purchase($conn, $itemId, $purchaseId, $billDate)) return;

  $stmt = $conn->prepare("SELECT category, pack_size, tax_pct FROM items WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $itemId);
  $stmt->execute();
  $item = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$item) return;

  $isGstBill = ($billType === "GST");
  $packSize  = floatval($item["pack_size"] ?? 0);
  $sets = []; $types = ""; $vals = [];

  if (preg_match('/^Rice\b/i', (string)($item["category"] ?? "")) && $packSize > 0) {
    // sale_price (per kg) = ⌈(pp + delivery) / pack + kgMarkup⌉
    // bag_sale_price      = ⌈pp + delivery + bagMarkup⌉
    // mrp (per bag)       = sale_price × pack_size
    if ($purchasePrice > 0) {
      $salePerKg = ceil(($purchasePrice + RICE_DELIVERY) / $packSize + RICE_KG_MARKUP);
      $sets[] = "sale_price=?";     $types .= "d"; $vals[] = $salePerKg;
      $sets[] = "bag_sale_price=?"; $types .= "d"; $vals[] = ceil($purchasePrice + RICE_DELIVERY + RICE_BAG_MARKUP);
      $sets[] = "mrp=?";            $types .= "d"; $vals[] = $salePerKg * $packSize;
      $sets[] = "purchase_price=?"; $types .= "d"; $vals[] = $purchasePrice;
    }
  } else {
    if ($purchasePrice > 0) {
      $cost = effective_purchase_price($purchasePrice, $billType, $gstMode, $taxPct, floatval($item["tax_pct"] ?? 0));
      $sets[] = "purchase_price=?"; $types .= "d"; $vals[] = round($cost, 2);
    }
    if ($salePrice > 0) { $sets[] = "sale_price=?"; $types .= "d"; $vals[] = $salePrice; }
    if ($mrp > 0)       { $sets[] = "mrp=?";        $types .= "d"; $vals[] = $mrp; }
  }
  if ($isGstBill) { $sets[] = "tax_pct=?"; $types .= "d"; $vals[] = $taxPct; }

  if (!$sets) return;
  $types .= "i"; $vals[] = $itemId;
  $stmt = $conn->prepare("UPDATE items SET " . implode(", ", $sets) . " WHERE id=? LIMIT 1");
  $stmt->bind_param($types, ...$vals);
  if (!$stmt->execute()) throw new Exception("Item master update failed: " . $stmt->error);
  $stmt->close();
}
