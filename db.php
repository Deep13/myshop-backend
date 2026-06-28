<?php
include_once __DIR__ . "/config.php";

// Always operate in the shop's local timezone (IST). Without this, PHP would
// default to the server's timezone (Europe/Berlin on local XAMPP, UTC on
// Hostinger) and date('Y-m-d') would return the wrong day late at night.
date_default_timezone_set('Asia/Kolkata');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Align MySQL session timezone with PHP so NOW(), CURDATE(), etc. also match.
$conn->query("SET time_zone = '+05:30'");
?>