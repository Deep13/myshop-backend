<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$phone = trim($_GET["phone"] ?? "");
if ($phone === "") {
  echo json_encode(["status" => "error", "message" => "phone is required"]);
  exit;
}

try {

// 1) All cards for this phone, newest first. Resolve redeemed_invoice_id from
//    redeemed_invoice_no via invoices (the redeem endpoint stores the no but
//    not the id, so resolving here lets the UI link to the bill).
$stmt = $conn->prepare("
  SELECT lc.*,
         uc.name AS created_by_name,
         uu.name AS updated_by_name,
         ri.id   AS resolved_redeemed_invoice_id
  FROM loyalty_cards lc
  LEFT JOIN users uc    ON uc.id = lc.created_by
  LEFT JOIN users uu    ON uu.id = lc.updated_by
  LEFT JOIN invoices ri ON ri.invoice_no = lc.redeemed_invoice_no
  WHERE lc.phone = ?
  ORDER BY lc.card_number DESC
");
$stmt->bind_param("s", $phone);
$stmt->execute();
$res = $stmt->get_result();
$cards = [];
while ($r = $res->fetch_assoc()) {
  // Fall back to the resolved id when the stored column is NULL
  if (empty($r["redeemed_invoice_id"]) && !empty($r["resolved_redeemed_invoice_id"])) {
    $r["redeemed_invoice_id"] = $r["resolved_redeemed_invoice_id"];
  }
  unset($r["resolved_redeemed_invoice_id"]);
  $cards[] = $r;
}
$stmt->close();

// 2) Stamps for the entire set + resolved invoice_id/date from invoice_no
//    so the UI can link straight to the bill (and show its real total).
$stamps_by_card = [];
if ($cards) {
  $ids = array_map(fn($c) => intval($c["id"]), $cards);
  $in  = implode(",", $ids);
  $sql = "
    SELECT s.*,
           u.name              AS stamped_by_name,
           i.id                AS resolved_invoice_id,
           i.invoice_date      AS invoice_date,
           i.rounded_final_total AS invoice_total
    FROM loyalty_stamps s
    LEFT JOIN users u    ON u.id = s.stamped_by
    LEFT JOIN invoices i ON i.invoice_no = s.invoice_no
    WHERE s.card_id IN ($in)
    ORDER BY s.card_id, s.stamp_no
  ";
  $res = $conn->query($sql);
  while ($r = $res->fetch_assoc()) {
    $cid = intval($r["card_id"]);
    if (!isset($stamps_by_card[$cid])) $stamps_by_card[$cid] = [];
    $stamps_by_card[$cid][] = $r;
  }
}

// 3) Per-card profit from invoices linked via stamps.
//    Stamps store invoice_no — we resolve invoice_id via invoices.invoice_no,
//    then compute the same GST-inclusive profit math used by the profit report:
//    profit = SUM(ii.amount) - SUM(cost_basis * qty), where cost_basis adjusts
//    for inclusive/exclusive purchase bills.
$profit_by_card = [];
if ($cards) {
  $ids = array_map(fn($c) => intval($c["id"]), $cards);
  $in  = implode(",", $ids);
  $sql = "
    SELECT lc.id AS card_id,
           COALESCE(SUM(ROUND(ii.amount, 2)), 0) AS revenue,
           COALESCE(SUM(ROUND(
             (CASE WHEN it.category LIKE 'Rice%' AND it.pack_size > 0
                   THEN COALESCE(pp.avg_pp, it.purchase_price) / it.pack_size
                   ELSE COALESCE(pp.avg_pp, it.purchase_price) END) * ii.qty
           , 2)), 0) AS cost
    FROM loyalty_cards lc
    JOIN loyalty_stamps ls ON ls.card_id = lc.id
    JOIN invoices       i  ON i.invoice_no = ls.invoice_no
    JOIN invoice_items  ii ON ii.invoice_id = i.id
    LEFT JOIN items it ON it.id = ii.item_id
    LEFT JOIN (
      SELECT pbi.item_code,
             SUM(
               CASE
                 WHEN pb.bill_type = 'NON-GST'   THEN pbi.purchase_price * (1 + COALESCE(itm.tax_pct,0)/100)
                 WHEN pb.gst_mode  = 'exclusive' THEN pbi.purchase_price * (1 + pbi.tax_pct/100)
                 ELSE pbi.purchase_price
               END * pbi.qty
             ) / NULLIF(SUM(pbi.qty),0) AS avg_pp
      FROM purchase_bill_items pbi
      JOIN purchase_bills pb ON pb.id = pbi.purchase_id
      LEFT JOIN items itm ON itm.code = pbi.item_code
      WHERE pbi.purchase_price > 0 AND pbi.qty > 0
      GROUP BY pbi.item_code
    ) pp ON pp.item_code = ii.item_code
    WHERE lc.id IN ($in) AND ls.invoice_no IS NOT NULL AND ls.invoice_no <> ''
    GROUP BY lc.id
  ";
  $res = $conn->query($sql);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $cid = intval($r["card_id"]);
      $profit_by_card[$cid] = [
        "revenue" => round(floatval($r["revenue"]), 2),
        "cost"    => round(floatval($r["cost"]), 2),
        "profit"  => round(floatval($r["revenue"]) - floatval($r["cost"]), 2),
      ];
    }
  }
}

// Attach stamps + profit + compute "current" card and totals.
// total_earned  = sum of profit on every linked invoice across all cards
//                 (what the shop made from this customer's loyalty bills)
// total_saved   = ₹100 × number of redeemed cards (what the customer got back)
// total_net     = total_earned − total_saved (shop's net after discounts)
$current      = null;
$total_saved  = 0;
$total_earned = 0;
$total_revenue = 0;
$total_cost   = 0;
foreach ($cards as &$c) {
  $cid = intval($c["id"]);
  $c["stamps"] = $stamps_by_card[$cid] ?? [];
  $c["totals"] = $profit_by_card[$cid] ?? ["revenue" => 0, "cost" => 0, "profit" => 0];
  if ($c["status"] !== "redeemed" && $current === null) $current = $c;
  if ($c["status"] === "redeemed") $total_saved += 100;
  $total_revenue += floatval($c["totals"]["revenue"]);
  $total_cost    += floatval($c["totals"]["cost"]);
  $total_earned  += floatval($c["totals"]["profit"]);
}
unset($c);

// 4) Eligible invoices for stamping the current card.
//    Rules:
//    - This customer's bills only (matched by phone)
//    - Today's date only ("stamps cannot be claimed later")
//    - Rounded final total ≥ ₹200
//    - Skip invoices already used as stamps on the current card
//    - Skip the invoice used as the most-recent redemption
//    - Newest first, max 10
$eligible = [];
$today = date("Y-m-d");

// Build skip-list: stamps' invoice_no on the current card + last redeem invoice_no
$skip_nos = [];
if ($current && !empty($current["stamps"])) {
  foreach ($current["stamps"] as $s) {
    if (!empty($s["invoice_no"])) $skip_nos[] = $s["invoice_no"];
  }
}
foreach ($cards as $c) {
  if ($c["status"] === "redeemed" && !empty($c["redeemed_invoice_no"])) {
    $skip_nos[] = $c["redeemed_invoice_no"];
    break; // most-recent (cards are ordered DESC by card_number)
  }
}
$skip_nos = array_unique($skip_nos);

$placeholders = "";
$skip_count = count($skip_nos);
if ($skip_count > 0) {
  $placeholders = " AND i.invoice_no NOT IN (" . implode(",", array_fill(0, $skip_count, "?")) . ")";
}

$sql = "
  SELECT i.id, i.invoice_no, i.invoice_date, i.rounded_final_total AS total, i.created_at
  FROM invoices i
  WHERE i.phone = ?
    AND i.invoice_date = ?
    AND i.rounded_final_total >= 200
  $placeholders
  ORDER BY i.created_at DESC, i.id DESC
  LIMIT 10
";
$stmt = $conn->prepare($sql);
if ($stmt) {
  $types  = "ss" . str_repeat("s", $skip_count);
  $params = array_merge([$phone, $today], $skip_nos);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $eligible[] = [
      "id"           => intval($r["id"]),
      "invoice_no"   => $r["invoice_no"],
      "invoice_date" => $r["invoice_date"],
      "total"        => floatval($r["total"]),
      "created_at"   => $r["created_at"],
    ];
  }
  $stmt->close();
}

echo json_encode([
  "status"             => "success",
  "cards"              => $cards,
  "current"            => $current,
  "cards_taken"        => count($cards),
  "total_saved"        => round($total_saved, 2),
  "total_earned"       => round($total_earned, 2),
  "total_net"          => round($total_earned - $total_saved, 2),
  "total_revenue"      => round($total_revenue, 2),
  "total_cost"         => round($total_cost, 2),
  "eligible_invoices"  => $eligible,
]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
