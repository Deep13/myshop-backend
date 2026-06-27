<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") { http_response_code(200); exit; }

include "db.php";

$from   = trim($_GET["from"]   ?? "");
$to     = trim($_GET["to"]     ?? "");
$userId = intval($_GET["user_id"] ?? 0);
$status = trim($_GET["status"] ?? ""); // success | failure
$limit  = intval($_GET["limit"] ?? 500);
if ($limit < 1)    $limit = 1;
if ($limit > 5000) $limit = 5000;

$where  = [];
$params = [];
$types  = "";

if ($from !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
  $where[]  = "ll.logged_at >= ?";
  $params[] = $from . " 00:00:00";
  $types   .= "s";
}
if ($to !== "" && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  $where[]  = "ll.logged_at <= ?";
  $params[] = $to . " 23:59:59";
  $types   .= "s";
}
if ($userId > 0) {
  $where[]  = "ll.user_id = ?";
  $params[] = $userId;
  $types   .= "i";
}
if ($status === "success" || $status === "failure") {
  $where[]  = "ll.status = ?";
  $params[] = $status;
  $types   .= "s";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

$sql = "
  SELECT ll.id, ll.user_id, ll.user_name, ll.status, ll.ip, ll.user_agent, ll.logged_at,
         u.role AS role
  FROM login_log ll
  LEFT JOIN users u ON u.id = ll.user_id
  $whereSql
  ORDER BY ll.logged_at DESC, ll.id DESC
  LIMIT $limit
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "Prepare failed: " . $conn->error]);
  exit;
}
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

echo json_encode(["status" => "success", "data" => $rows]);
