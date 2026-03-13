<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$q = trim($_GET["q"] ?? "");
$limit = intval($_GET["limit"] ?? 8);
if ($limit <= 0 || $limit > 20) $limit = 8;

if ($q === "") {
  echo json_encode(["status"=>"success","data"=>[]]);
  exit;
}

$like = "%" . $q . "%";
$stmt = $conn->prepare("
  SELECT id, name, code, hsn, mrp, sale_price, purchase_price, tax_pct
  FROM items
  WHERE name LIKE ? OR code LIKE ? OR hsn LIKE ?
  ORDER BY name ASC
  LIMIT ?
");
$stmt->bind_param("sssi", $like, $like, $like, $limit);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()){
  $data[] = $row;
}
$stmt->close();

echo json_encode(["status"=>"success","data"=>$data]);
