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

// Next invoice number = max(numeric invoice_no) + 1. Plain number, no prefix.
$sql = "SELECT MAX(CAST(invoice_no AS UNSIGNED)) AS max_no FROM invoices WHERE invoice_no REGEXP '^[0-9]+$'";
$result = $conn->query($sql);

$nextNum = 2842; // default starting point if table is empty
if ($result && $result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $maxNo = intval($row["max_no"] ?? 0);
  if ($maxNo > 0) $nextNum = $maxNo + 1;
}

echo json_encode([
  "status" => "success",
  "invoiceNo" => (string) $nextNum
]);
