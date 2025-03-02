<?php
session_start();
require_once '../../includes/db_connection.php';

$user_id = $_SESSION['user_id'];

// Fetch unread notifications
$query = "SELECT * FROM notifications 
          WHERE user_id = ? AND is_read = 0 
          ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

echo json_encode([
    'unread' => count($notifications),
    'notifications' => $notifications,
]);
?>