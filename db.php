<?php
$host = "localhost";
$user = "root"; 
$pass = ""; // default for XAMPP
$db   = "myshop"; 

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>