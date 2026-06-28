<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include "db.php";

$sql = "SELECT id, name, role FROM users";
$result = $conn->query($sql);

$rows = array();
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

echo json_encode(["status" => "success", "users" => $rows]);
?>
