<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$itemId      = intval($_GET["item_id"]      ?? 0);
$q           = trim($_GET["q"]             ?? "");
// include_zero=1 means show all batches even out-of-stock (used by sales dropdown)
// include_zero=0 (default) means only show batches with stock > 0
$includeZero = intval($_GET["include_zero"] ?? 0);
$today       = date("Y-m-d");

$where  = [];
$params = [];
$types  = "";

// Only filter out zero-stock when NOT requested to include them
if (!$includeZero) {
  $where[] = "inv.current_qty > 0";
}

if ($itemId > 0) {
  $where[] = "inv.item_id = ?";
  $params[] = $itemId;
  $types   .= "i";
}
if ($q !== "") {
  $like     = "%" . $q . "%";
  $where[]  = "(it.name LIKE ? OR it.code LIKE ?)";
  $params[] = $like;
  $params[] = $like;
  $types   .= "ss";
}

$whereSql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$sql = "
  SELECT
    inv.id,
    inv.item_id,
    it.name         AS item_name,
    it.code         AS item_code,
    it.hsn,
    it.category     AS category,
    it.pack_size,
    it.bag_sale_price,
    inv.batch_no,
    inv.exp_date,
    inv.mrp,
    inv.purchase_price,
    CASE WHEN inv.sale_price > 0 THEN inv.sale_price ELSE it.sale_price END AS sale_price,
    CASE WHEN inv.tax_pct > 0 THEN inv.tax_pct ELSE it.tax_pct END AS tax_pct,
    inv.gst_flag,
    it.mrp            AS master_mrp,
    it.sale_price     AS master_sale_price,
    it.purchase_price AS master_purchase_price,
    it.tax_pct        AS master_tax_pct,
    inv.current_qty,
    inv.purchase_bill_id,
    pb.bill_no      AS purchase_bill_no,
    pb.bill_date    AS purchase_bill_date,
    pb.bill_type    AS purchase_bill_type,
    pb.gst_mode     AS purchase_gst_mode,
    inv.updated_at  AS updated_at,
    uu.name         AS updated_by_name,
    CASE WHEN inv.exp_date IS NOT NULL AND inv.exp_date < '$today' THEN 1 ELSE 0 END AS is_expired,
    NULL AS bulk_item_id,
    NULL AS pack_weight,
    0    AS is_pack,
    EXISTS(SELECT 1 FROM items c WHERE c.bulk_item_id = it.id) AS is_bulk
  FROM inventory inv
  JOIN items it ON it.id = inv.item_id
  LEFT JOIN purchase_bills pb ON pb.id = inv.purchase_bill_id
  LEFT JOIN users uu ON uu.id = inv.updated_by
  $whereSql
  ORDER BY inv.current_qty DESC, inv.exp_date ASC, it.name ASC
  LIMIT 10000
";

if (count($params) > 0) {
  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();
} else {
  $res = $conn->query($sql);
}

$data = [];
while ($row = $res->fetch_assoc()) {
  $row["current_qty"]    = floatval($row["current_qty"]);
  $row["mrp"]            = floatval($row["mrp"]);
  $row["purchase_price"] = floatval($row["purchase_price"]);
  $row["sale_price"]     = floatval($row["sale_price"]);
  $row["tax_pct"]        = floatval($row["tax_pct"]);
  foreach (["master_mrp", "master_sale_price", "master_purchase_price", "master_tax_pct"] as $k) {
    $row[$k] = $row[$k] !== null ? floatval($row[$k]) : null;
  }
  $row["pack_size"]      = $row["pack_size"]      !== null ? floatval($row["pack_size"])      : null;
  $row["bag_sale_price"] = $row["bag_sale_price"] !== null ? floatval($row["bag_sale_price"]) : null;
  $row["is_expired"]     = intval($row["is_expired"]);
  $row["pack_weight"]    = $row["pack_weight"]    !== null ? floatval($row["pack_weight"])    : null;
  $row["bulk_item_id"]   = $row["bulk_item_id"]   !== null ? intval($row["bulk_item_id"])     : null;
  $row["is_pack"]        = intval($row["is_pack"]);
  $row["is_bulk"]        = intval($row["is_bulk"]);
  $data[] = $row;
}

// ── Packet items ────────────────────────────────────────────────────────────
// A packet (e.g. "KAJU 250GM") holds no stock of its own: it is cut from a bulk
// item held in kg. Emit one row per (packet x bulk batch) so the till can scan
// and sell it exactly like any other batch. Stock is whole packs the bulk batch
// can still yield; cost is the bulk rate for that much weight.
// One aggregated row per packet, never one per bulk batch: the shop repacks from
// whichever sack is open, so the batch is the server's business (FEFO at sale
// time), not a choice at the till. `id` is 0 — a packet has no inventory row of
// its own, and that 0 is what keeps the stock-editing screens off it.
$pWhere  = ["p.pack_weight > 0", "p.bulk_item_id IS NOT NULL"];
$pParams = [];
$pTypes  = "";
if ($itemId > 0) { $pWhere[] = "p.id = ?";                       $pParams[] = $itemId; $pTypes .= "i"; }
if ($q !== "")   { $pWhere[] = "(p.name LIKE ? OR p.code LIKE ?)"; $like = "%$q%"; $pParams[] = $like; $pParams[] = $like; $pTypes .= "ss"; }
$pHaving = $includeZero ? "" : "HAVING current_qty > 0";

$packSql = "
  SELECT
    0               AS id,
    p.id            AS item_id,
    p.name          AS item_name,
    p.code          AS item_code,
    p.hsn,
    p.category      AS category,
    NULL            AS pack_size,
    NULL            AS bag_sale_price,
    ''              AS batch_no,
    agg.min_exp     AS exp_date,
    p.mrp,
    ROUND(COALESCE(agg.avg_pp_kg, b.purchase_price, 0) * p.pack_weight, 2) AS purchase_price,
    p.sale_price,
    p.tax_pct,
    p.is_primary    AS gst_flag,
    p.mrp           AS master_mrp,
    p.sale_price    AS master_sale_price,
    ROUND(COALESCE(agg.avg_pp_kg, b.purchase_price, 0) * p.pack_weight, 2) AS master_purchase_price,
    p.tax_pct       AS master_tax_pct,
    FLOOR(COALESCE(agg.stock_kg, 0) / p.pack_weight) AS current_qty,
    NULL            AS purchase_bill_id,
    NULL            AS purchase_bill_no,
    NULL            AS purchase_bill_date,
    NULL            AS purchase_bill_type,
    NULL            AS purchase_gst_mode,
    NULL            AS updated_at,
    NULL            AS updated_by_name,
    0               AS is_expired,
    p.bulk_item_id,
    p.pack_weight,
    1               AS is_pack,
    0               AS is_bulk,
    COALESCE(agg.stock_kg, 0) AS bulk_stock_kg,
    b.name          AS bulk_item_name
  FROM items p
  JOIN items b ON b.id = p.bulk_item_id
  LEFT JOIN (
    SELECT inv.item_id,
           SUM(inv.current_qty) AS stock_kg,
           SUM(inv.current_qty * inv.purchase_price) / NULLIF(SUM(inv.current_qty), 0) AS avg_pp_kg,
           MIN(inv.exp_date) AS min_exp
    FROM inventory inv
    WHERE inv.current_qty > 0
      AND (inv.exp_date IS NULL OR inv.exp_date >= '$today')
    GROUP BY inv.item_id
  ) agg ON agg.item_id = b.id
  WHERE " . implode(" AND ", $pWhere) . "
  $pHaving
  ORDER BY p.name ASC
  LIMIT 10000
";

if (count($pParams) > 0) {
  $pStmt = $conn->prepare($packSql);
  $pStmt->bind_param($pTypes, ...$pParams);
  $pStmt->execute();
  $pRes = $pStmt->get_result();
} else {
  $pRes = $conn->query($packSql);
}

while ($row = $pRes->fetch_assoc()) {
  $row["current_qty"]    = floatval($row["current_qty"]);
  $row["mrp"]            = floatval($row["mrp"]);
  $row["purchase_price"] = floatval($row["purchase_price"]);
  $row["sale_price"]     = floatval($row["sale_price"]);
  $row["tax_pct"]        = floatval($row["tax_pct"]);
  foreach (["master_mrp", "master_sale_price", "master_purchase_price", "master_tax_pct"] as $k) {
    $row[$k] = $row[$k] !== null ? floatval($row[$k]) : null;
  }
  $row["pack_size"]      = null;
  $row["bag_sale_price"] = null;
  $row["is_expired"]     = intval($row["is_expired"]);
  $row["pack_weight"]    = floatval($row["pack_weight"]);
  $row["bulk_item_id"]   = intval($row["bulk_item_id"]);
  $row["gst_flag"]       = intval($row["gst_flag"]);
  $row["is_pack"]        = 1;
  $row["is_bulk"]        = 0;
  $row["bulk_stock_kg"]  = floatval($row["bulk_stock_kg"]);
  $data[] = $row;
}

echo json_encode(["status" => "success", "data" => $data]);
