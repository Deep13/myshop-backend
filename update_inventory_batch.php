<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$body = json_decode(file_get_contents("php://input"), true);
if (!$body) { http_response_code(400); echo json_encode(["status"=>"error","message"=>"Invalid JSON"]); exit; }

$inventoryId = intval($body["inventoryId"] ?? 0);
$batchNo     = trim((string)($body["batch_no"] ?? ""));
$expDateRaw  = trim((string)($body["exp_date"] ?? ""));
$expDate     = $expDateRaw === "" ? null : $expDateRaw;
$updatedBy   = intval($body["updatedBy"] ?? 0);
// Optional: change current_qty for this batch. Omit (or send empty string)
// to leave it untouched. Must be ≥ 0 when provided.
$hasQty      = array_key_exists("current_qty", $body) && $body["current_qty"] !== "" && $body["current_qty"] !== null;
$currentQty  = $hasQty ? floatval($body["current_qty"]) : null;
if ($hasQty && $currentQty < 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"current_qty must be 0 or greater"]);
  exit;
}
// A ceiling as well as a floor. A mis-keyed quantity once put ten million
// bottles of Campa Cola on the books and showed the shop as holding 20 crore
// of stock. Nothing here comes in six-figure counts, in units or in kilos.
if ($hasQty && $currentQty > 100000) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"That quantity looks mis-typed. Enter 100000 or less."]);
  exit;
}

if ($inventoryId <= 0) {
  http_response_code(400);
  echo json_encode(["status"=>"error","message"=>"inventoryId is required"]);
  exit;
}

// The batch NUMBER on a bill-linked row is owned by the bill — changing it
// here would silently desync the bill snapshot, so it is ignored for those
// rows and only the bill can change it. Stock and expiry are different: a
// physical recount and a mis-keyed expiry date are everyday corrections, and
// they must not require editing a months-old purchase bill to put right.
$stmt = $conn->prepare("SELECT id, purchase_bill_id, item_id, batch_no FROM inventory WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $inventoryId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
  http_response_code(404);
  echo json_encode(["status"=>"error","message"=>"Inventory row not found"]);
  exit;
}
// NULL for a bill-linked row, so the UPDATE's COALESCE leaves batch_no exactly
// as the bill set it — including leaving a NULL as NULL rather than "".
$billLinked  = ($row["purchase_bill_id"] !== null && $row["purchase_bill_id"] !== "");
$batchParam  = $billLinked ? null : $batchNo;

if ($hasQty) {
  // Update qty too. initial_qty is bumped along with current_qty when the new
  // value exceeds the existing initial — that keeps the "stock ever held"
  // ceiling sensible. When the new qty is lower we leave initial_qty alone.
  $stmt = $conn->prepare("
    UPDATE inventory
    SET batch_no = COALESCE(?, batch_no), exp_date = ?,
        current_qty = ?, initial_qty = GREATEST(initial_qty, ?),
        updated_by = NULLIF(?, 0)
    WHERE id = ? LIMIT 1
  ");
  $stmt->bind_param("ssddii", $batchParam, $expDate, $currentQty, $currentQty, $updatedBy, $inventoryId);
} else {
  $stmt = $conn->prepare("UPDATE inventory SET batch_no = COALESCE(?, batch_no), exp_date = ?, updated_by = NULLIF(?, 0) WHERE id = ? LIMIT 1");
  $stmt->bind_param("ssii", $batchParam, $expDate, $updatedBy, $inventoryId);
}
if (!$stmt->execute()) {
  $errno = $conn->errno;
  $err   = $stmt->error;
  $stmt->close();
  if ($errno === 1062) {
    http_response_code(409);
    echo json_encode(["status"=>"error","message"=>"Another batch with the same number already exists for this item. Pick a different batch number."]);
    exit;
  }
  http_response_code(500);
  echo json_encode(["status"=>"error","message"=>"Update failed: " . $err]);
  exit;
}
$stmt->close();

// Report the batch number that was actually stored, not the one that was
// asked for: on a bill-linked row the request's value is discarded, and a
// caller that trusted the echo would show a number the database never took.
echo json_encode([
  "status"      => "success",
  "message"     => "Batch updated",
  "bill_linked" => $billLinked,
  "data"        => [
    "id"          => $inventoryId,
    "batch_no"    => $billLinked ? $row["batch_no"] : $batchNo,
    "exp_date"    => $expDate,
    "current_qty" => $hasQty ? $currentQty : null,
  ],
]);
