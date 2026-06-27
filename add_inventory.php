<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!$data) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$itemId   = intval($data["itemId"] ?? 0);
$batchNo  = trim($data["batchNo"] ?? "");
$expDate  = trim($data["expDate"] ?? "");
$qty      = floatval($data["qty"] ?? 0);
$mrp      = floatval($data["mrp"] ?? 0);
$purchasePrice = floatval($data["purchasePrice"] ?? 0);
$salePrice = floatval($data["salePrice"] ?? 0);
$taxPct   = floatval($data["taxPct"] ?? 0);
$gstFlag  = intval($data["gstFlag"] ?? 1);
$userId   = intval($data["userId"] ?? ($data["createdBy"] ?? 0));

if ($itemId <= 0) { echo json_encode(["status"=>"error","message"=>"Item is required"]); exit; }
if ($qty <= 0)    { echo json_encode(["status"=>"error","message"=>"Quantity must be > 0"]); exit; }

// Verify item exists
$check = $conn->prepare("SELECT id, name, code FROM items WHERE id=? LIMIT 1");
$check->bind_param("i", $itemId);
$check->execute();
if ($check->get_result()->num_rows === 0) {
    echo json_encode(["status"=>"error","message"=>"Item not found in master"]);
    exit;
}
$check->close();

$expDateVal = ($expDate === "") ? null : $expDate;

// Check if same batch already exists for this item
$existing = $conn->prepare("SELECT id, current_qty FROM inventory WHERE item_id=? AND batch_no=? AND (exp_date=? OR (exp_date IS NULL AND ? IS NULL)) LIMIT 1");
$existing->bind_param("isss", $itemId, $batchNo, $expDateVal, $expDateVal);
$existing->execute();
$res = $existing->get_result();

if ($res->num_rows > 0) {
    // Update existing batch — add quantity, update prices
    $row = $res->fetch_assoc();
    $stmt = $conn->prepare("
        UPDATE inventory SET
            current_qty = current_qty + ?,
            initial_qty = initial_qty + ?,
            mrp = ?, purchase_price = ?, sale_price = ?, tax_pct = ?, gst_flag = ?,
            updated_by = NULLIF(?, 0)
        WHERE id = ?
    ");
    $stmt->bind_param("ddddddiii", $qty, $qty, $mrp, $purchasePrice, $salePrice, $taxPct, $gstFlag, $userId, $row["id"]);
    if (!$stmt->execute()) {
        echo json_encode(["status"=>"error","message"=>"Failed to update inventory"]);
        exit;
    }
    $stmt->close();
    $invId = $row["id"];
    $newQty = floatval($row["current_qty"]) + $qty;
} else {
    // Insert new batch
    $stmt = $conn->prepare("
        INSERT INTO inventory (item_id, batch_no, exp_date, mrp, purchase_price, sale_price, tax_pct, gst_flag, initial_qty, current_qty, created_by, updated_by)
        VALUES (?,?,?,?,?,?,?,?,?,?, NULLIF(?, 0), NULLIF(?, 0))
    ");
    $stmt->bind_param("issddddiddii", $itemId, $batchNo, $expDateVal, $mrp, $purchasePrice, $salePrice, $taxPct, $gstFlag, $qty, $qty, $userId, $userId);
    if (!$stmt->execute()) {
        echo json_encode(["status"=>"error","message"=>"Failed to insert inventory: " . $stmt->error]);
        exit;
    }
    $invId = $conn->insert_id;
    $newQty = $qty;
    $stmt->close();
}
$existing->close();

echo json_encode([
    "status" => "success",
    "message" => "Stock added",
    "inventoryId" => $invId,
    "currentQty" => $newQty,
]);
