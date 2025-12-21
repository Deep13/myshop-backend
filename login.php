<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");

include "db.php";

$data = json_decode(file_get_contents("php://input"));

$name = $data->name;
$pass = $data->password;

// Query user
$sql = "SELECT * FROM users WHERE name='$name' LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

$user = $result->fetch_assoc();

// Check pass
if (password_verify($pass, $user['pass'])) {
    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "user" => [
            "id" => $user["id"],
            "name" => $user["name"]
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid password"]);
}
// echo password_hash("1234567", PASSWORD_DEFAULT);
?>
