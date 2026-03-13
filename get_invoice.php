<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$id = isset($_GET["id"]) ? intval($_GET["id"]) : 0;
if ($id <= 0) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Missing id"]); exit; }

// invoice header
$stmt = $conn->prepare("SELECT * FROM invoices WHERE id=? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$invRes = $stmt->get_result();
if ($invRes->num_rows === 0) { echo json_encode(["status"=>"error","message"=>"Invoice not found"]); exit; }
$invoice = $invRes->fetch_assoc();
$stmt->close();

// items
$stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$itemsRes = $stmt->get_result();
$items = [];
while ($r = $itemsRes->fetch_assoc()) $items[] = $r;
$stmt->close();

// payments
$stmt = $conn->prepare("SELECT * FROM invoice_payments WHERE invoice_id=? ORDER BY id ASC");
$stmt->bind_param("i", $id);
$stmt->execute();
$payRes = $stmt->get_result();
$payments = [];
while ($r = $payRes->fetch_assoc()) $payments[] = $r;
$stmt->close();

echo json_encode([
  "status" => "success",
  "invoice" => $invoice,
  "items" => $items,
  "payments" => $payments
]);
