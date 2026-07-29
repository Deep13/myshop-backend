<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

// Dead-stock threshold: in stock but nothing sold for this many days.
const DEAD_STOCK_DAYS = 30;

$itemId = intval($_GET["item_id"] ?? 0);
if ($itemId <= 0) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "item_id required"]);
  exit;
}

try {
  // Item + code (sales lines may match by item_id or item_code)
  $stmt = $conn->prepare("SELECT id, code, name FROM items WHERE id = ? LIMIT 1");
  $stmt->bind_param("i", $itemId);
  $stmt->execute();
  $item = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$item) {
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Item not found"]);
    exit;
  }
  $code = $item["code"] ?? "";

  // Current stock + when the oldest still-live batch arrived.
  // Arrival = purchase bill date when linked, else the inventory row's created_at.
  $stmt = $conn->prepare("
    SELECT
      COALESCE(SUM(inv.current_qty), 0) AS total_stock,
      MIN(CASE WHEN inv.current_qty > 0
               THEN COALESCE(pb.bill_date, DATE(inv.created_at)) END) AS oldest_live_arrival,
      MAX(CASE WHEN inv.current_qty > 0
               THEN COALESCE(pb.bill_date, DATE(inv.created_at)) END) AS newest_live_arrival
    FROM inventory inv
    LEFT JOIN purchase_bills pb ON pb.id = inv.purchase_bill_id
    WHERE inv.item_id = ?
  ");
  $stmt->bind_param("i", $itemId);
  $stmt->execute();
  $stock = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // Sales recency + 30-day velocity (match by item_id OR item_code for old rows)
  $stmt = $conn->prepare("
    SELECT
      MAX(i.invoice_date) AS last_sale_date,
      COALESCE(SUM(CASE WHEN i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN ii.qty END), 0) AS qty_sold_30d
    FROM invoice_items ii
    JOIN invoices i ON i.id = ii.invoice_id
    WHERE ii.item_id = ? OR (ii.item_code <> '' AND ii.item_code = ?)
  ");
  $stmt->bind_param("is", $itemId, $code);
  $stmt->execute();
  $sales = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  $totalStock    = floatval($stock["total_stock"]);
  $oldestArrival = $stock["oldest_live_arrival"];
  $lastSale      = $sales["last_sale_date"];
  $today         = new DateTime("today");

  $daysInStock = null;
  if ($oldestArrival) {
    $daysInStock = (int)$today->diff(new DateTime($oldestArrival))->format("%a");
  }
  $daysSinceLastSale = null;
  if ($lastSale) {
    $daysSinceLastSale = (int)$today->diff(new DateTime($lastSale))->format("%a");
  }

  // Dead = there is stock, and it hasn't sold recently (or ever).
  $isDead = $totalStock > 0
    && ($lastSale === null || $daysSinceLastSale >= DEAD_STOCK_DAYS);

  echo json_encode([
    "status" => "success",
    "data" => [
      "item_id"              => intval($item["id"]),
      "total_stock"          => $totalStock,
      "oldest_live_arrival"  => $oldestArrival,
      "newest_live_arrival"  => $stock["newest_live_arrival"],
      "days_in_stock"        => $daysInStock,
      "last_sale_date"       => $lastSale,
      "days_since_last_sale" => $daysSinceLastSale,
      "qty_sold_30d"         => floatval($sales["qty_sold_30d"]),
      "is_dead"              => $isDead,
      "dead_threshold_days"  => DEAD_STOCK_DAYS,
    ],
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
