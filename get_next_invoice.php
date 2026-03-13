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

// Get latest invoice number
$sql = "SELECT invoice_no FROM invoices ORDER BY id DESC LIMIT 1";
$result = $conn->query($sql);

$nextInvoiceNo = "INV001"; // default

if ($result && $result->num_rows > 0) {
  $row = $result->fetch_assoc();
  $lastInvoice = $row["invoice_no"]; // e.g. INV023

  // Extract numeric part
  if (preg_match("/(\d+)$/", $lastInvoice, $m)) {
    $num = intval($m[1]) + 1;
    $nextInvoiceNo = "INV" . str_pad($num, 3, "0", STR_PAD_LEFT);
  }
}

echo json_encode([
  "status" => "success",
  "invoiceNo" => $nextInvoiceNo
]);
