<?php
// ---------- CORS ----------
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(200);
  exit;
}

include "db.php";

// --- query params ---
$from = isset($_GET["from"]) ? trim($_GET["from"]) : "";
$to = isset($_GET["to"]) ? trim($_GET["to"]) : "";
$party = isset($_GET["party"]) ? trim($_GET["party"]) : "";
$paymentType = isset($_GET["paymentType"]) ? trim($_GET["paymentType"]) : "";

// --- build SQL safely ---
$where = [];
$params = [];
$types = "";

// date range
if ($from !== "") { $where[] = "i.invoice_date >= ?"; $types .= "s"; $params[] = $from; }
if ($to !== "") { $where[] = "i.invoice_date <= ?"; $types .= "s"; $params[] = $to; }

// party name filter
if ($party !== "") {
  $where[] = "i.customer_name LIKE ?";
  $types .= "s";
  $params[] = "%" . $party . "%";
}

// payment filter (matches any payment row)
if ($paymentType !== "") {
  $where[] = "EXISTS (SELECT 1 FROM invoice_payments p WHERE p.invoice_id = i.id AND p.pay_type = ?)";
  $types .= "s";
  $params[] = $paymentType;
}

$whereSql = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

// payment types for display: Cash+UPI etc
$sql = "
  SELECT
    i.id,
    i.invoice_date AS date,
    i.invoice_no AS invoice,
    i.customer_name AS party,
    i.phone AS phone,
    'Sale' AS transaction,
    i.rounded_final_total AS amount,
    (
      SELECT GROUP_CONCAT(DISTINCT p.pay_type ORDER BY p.pay_type SEPARATOR '+')
      FROM invoice_payments p
      WHERE p.invoice_id = i.id
    ) AS paymentType
  FROM invoices i
  $whereSql
  ORDER BY i.invoice_date DESC, i.id DESC
  LIMIT 500
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]);
  exit;
}

if (count($params) > 0) {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
  if ($r["paymentType"] === null || $r["paymentType"] === "") $r["paymentType"] = "Cash";
  $r["amount"] = floatval($r["amount"]);
  $rows[] = $r;
}

echo json_encode([
  "status" => "success",
  "data" => $rows
]);
