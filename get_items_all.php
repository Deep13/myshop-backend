<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$q     = trim($_GET["q"] ?? "");
$limit = intval($_GET["limit"] ?? 200);
if ($limit <= 0 || $limit > 500) $limit = 200;

if ($q !== "") {
  $like  = "%" . $q . "%";
  $stmt  = $conn->prepare("SELECT id,name,code,hsn,mrp,sale_price,purchase_price,tax_pct,is_primary FROM items WHERE name LIKE ? OR code LIKE ? OR hsn LIKE ? ORDER BY name ASC LIMIT ?");
  $stmt->bind_param("sssi", $like, $like, $like, $limit);
} else {
  $stmt = $conn->prepare("SELECT id,name,code,hsn,mrp,sale_price,purchase_price,tax_pct,is_primary FROM items ORDER BY name ASC LIMIT ?");
  $stmt->bind_param("i", $limit);
}

$stmt->execute();
$res = $stmt->get_result();
$data = [];
while ($row = $res->fetch_assoc()) {
  $data[] = [
    "id"            => intval($row["id"]),
    "name"          => $row["name"],
    "code"          => $row["code"],
    "hsn"           => $row["hsn"] ?? "",
    "mrp"           => floatval($row["mrp"]),
    "salePrice"     => floatval($row["sale_price"]),
    "purchasePrice" => floatval($row["purchase_price"]),
    "tax"           => floatval($row["tax_pct"]),
    "is_primary"    => intval($row["is_primary"]),
  ];
}
$stmt->close();
echo json_encode(["status" => "success", "data" => $data]);
