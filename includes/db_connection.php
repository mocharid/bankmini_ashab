<?php
// Set timezone to WIB (Western Indonesia Time)
date_default_timezone_set('Asia/Jakarta');

$host = 'localhost';
$db = 'schobank';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set MySQL timezone to match PHP timezone
$conn->query("SET time_zone = '+07:00'");
?>