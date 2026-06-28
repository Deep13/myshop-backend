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
  $where[] = "(pb.distributor_name LIKE ? OR pb.distributor_gstin LIKE ? OR pb.bill_no LIKE ?)";
  $like = "%" . $q . "%";
  $params[] = $like; $params[] = $like; $params[] = $like;
  $types .= "sss";
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
    WHEN COALESCE(pp.paid_amount,0) >= (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END)
         AND (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END) > 0 THEN 'Paid'
    WHEN COALESCE(pp.paid_amount,0) > 0
         AND COALESCE(pp.paid_amount,0) < (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END) THEN 'Partial'
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

// Filter by computed payment status by putting the CASE expression directly
// in the WHERE clause. The status value is whitelisted (no SQL-injection
// risk) and inlined — using a bound parameter triggers a collation mismatch
// on Hostinger's MariaDB 11.x (utf8mb4_unicode_ci vs utf8mb4_general_ci).
$VALID_STATUS = ["Paid", "Partial", "Unpaid"];
if (in_array($status, $VALID_STATUS, true)) {
  $where[] = "
    (CASE
       WHEN COALESCE(pp.paid_amount,0) >= (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END)
            AND (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END) > 0 THEN 'Paid'
       WHEN COALESCE(pp.paid_amount,0) > 0
            AND COALESCE(pp.paid_amount,0) < (CASE WHEN pb.round_off_enabled=1 THEN pb.rounded_grand_total ELSE pb.grand_total END) THEN 'Partial'
       ELSE 'Unpaid'
     END) = '" . $status . "'
  ";
}

if (count($where) > 0) $sql .= " WHERE " . implode(" AND ", $where);

$sql .= " ORDER BY pb.bill_date DESC, pb.id DESC LIMIT ?";
$params[] = $limit;
$types .= "i";

$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); echo json_encode(["status"=>"error","message"=>$conn->error]); exit; }

if (count($params) > 0) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while($row = $res->fetch_assoc()) $data[] = $row;
$stmt->close();

echo json_encode(["status"=>"success","data"=>$data]);
