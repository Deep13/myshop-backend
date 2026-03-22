<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$from = trim($_GET["from"] ?? "");
$to   = trim($_GET["to"] ?? "");
$q    = trim($_GET["q"] ?? "");       // distributor name/gstin
$status = trim($_GET["status"] ?? ""); // Paid / Unpaid / Partial
$limit = intval($_GET["limit"] ?? 200);
if ($limit <= 0 || $limit > 1000) $limit = 200;

$where = [];
$params = [];
$types = "";

if ($from !== "") { $where[] = "pb.bill_date >= ?"; $params[] = $from; $types .= "s"; }
if ($to !== "")   { $where[] = "pb.bill_date <= ?"; $params[] = $to; $types .= "s"; }

if ($q !== "") {
  $where[] = "(pb.distributor_name LIKE ? OR pb.distributor_gstin LIKE ?)";
  $like = "%" . $q . "%";
  $params[] = $like; $params[] = $like;
  $types .= "ss";
}

$sql = "
SELECT
  pb.id,
  pb.distributor_id,
  pb.distributor_name,
  pb.distributor_gstin,
  pb.bill_no,
  pb.bill_date,
  pb.due_date,
  pb.sub_total,
  pb.tax_total,
  pb.grand_total,
  pb.rounded_grand_total,
  pb.round_off_enabled,
  pb.bill_type,
  COALESCE(pp.paid_amount, 0) AS paid_amount,
  CASE
    WHEN COALESCE(pp.paid_amount,0) >= pb.grand_total AND pb.grand_total > 0 THEN 'Paid'
    WHEN COALESCE(pp.paid_amount,0) > 0 AND COALESCE(pp.paid_amount,0) < pb.grand_total THEN 'Partial'
    ELSE 'Unpaid'
  END AS payment_status
FROM purchase_bills pb
LEFT JOIN (
  SELECT purchase_id, SUM(amount) AS paid_amount
  FROM purchase_payments
  WHERE purchase_id IS NOT NULL
  GROUP BY purchase_id
) pp ON pp.purchase_id = pb.id
";

if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);

// Filter by computed status (needs HAVING or wrap query)
if ($status !== "") {
  $sql = "SELECT * FROM (" . $sql . ") t WHERE t.payment_status = ?";
  $params[] = $status;
  $types .= "s";
}

$sql .= " ORDER BY bill_date DESC, id DESC LIMIT ?";
$params[] = $limit;
$types .= "i";

$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(["status"=>"error","message"=>$conn->error]); exit; }

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo json_encode(["status"=>"success","data"=>$data]);
