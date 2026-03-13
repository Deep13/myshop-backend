<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["status"=>"error","message"=>"Method not allowed"]);
  exit;
}

include "db.php";

function strv($v){ return trim((string)($v ?? "")); }
function num($v){
  if ($v === null) return 0;
  if (is_numeric($v)) return floatval($v);
  $s = trim((string)$v);
  if ($s === "") return 0;
  $s = str_replace([",","₹","Rs.","INR"], "", $s);
  $s = preg_replace('/[^0-9.]/', '', $s);
  return is_numeric($s) ? floatval($s) : 0;
}

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$name = strv($body["name"] ?? "");
$code = strv($body["code"] ?? "");
$hsn  = strv($body["hsn"] ?? "");
$mrp  = num($body["mrp"] ?? 0);
$salePrice = num($body["salePrice"] ?? 0);
$purchasePrice = num($body["purchasePrice"] ?? 0);
$tax  = num($body["tax"] ?? 0);
$is_primary = !empty($body["is_primary"]) ? 1 : 0;

if ($name === "" || $code === "") {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"name and code are required"]);
  exit;
}

if ($is_primary == 0) $tax = 0;

// prevent duplicate code
$stmtC = $conn->prepare("SELECT id FROM items WHERE code=? LIMIT 1");
$stmtC->bind_param("s", $code);
$stmtC->execute();
$resC = $stmtC->get_result();
if ($resC->num_rows > 0) {
  $stmtC->close();
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"Item code already exists"]);
  exit;
}
$stmtC->close();

$stmt = $conn->prepare("
  INSERT INTO items (name, code, hsn, mrp, sale_price, purchase_price, tax_pct, is_primary)
  VALUES (?,?,?,?,?,?,?,?)
");

$stmt->bind_param("sssdddii", $name, $code, $hsn, $mrp, $salePrice, $purchasePrice, $tax, $is_primary);

if (!$stmt->execute()) {
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Insert failed: ".$stmt->error]);
  exit;
}

$id = $stmt->insert_id;
$stmt->close();

echo json_encode([
  "status" => "success",
  "message" => "Item added",
  "data" => [
    "id" => $id,
    "name" => $name,
    "code" => $code,
    "hsn" => $hsn,
    "mrp" => $mrp,
    "salePrice" => $salePrice,
    "purchasePrice" => $purchasePrice,
    "tax" => $tax,
    "is_primary" => $is_primary
  ]
]);
