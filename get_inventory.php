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
    inv.batch_no,
    inv.exp_date,
    inv.mrp,
    inv.purchase_price,
    inv.sale_price,
    inv.tax_pct,
    inv.gst_flag,
    inv.current_qty,
    inv.purchase_bill_id,
    pb.bill_no      AS purchase_bill_no,
    pb.bill_date    AS purchase_bill_date,
    CASE WHEN inv.exp_date IS NOT NULL AND inv.exp_date < '$today' THEN 1 ELSE 0 END AS is_expired
  FROM inventory inv
  JOIN items it ON it.id = inv.item_id
  LEFT JOIN purchase_bills pb ON pb.id = inv.purchase_bill_id
  $whereSql
  ORDER BY inv.current_qty DESC, inv.exp_date ASC, it.name ASC
  LIMIT 500
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
  $row["is_expired"]     = intval($row["is_expired"]);
  $data[] = $row;
}

echo json_encode(["status" => "success", "data" => $data]);
