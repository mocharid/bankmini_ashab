<?php
session_start();
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($notification_id > 0) {
    $query = "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $notification_id, $user_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to mark as read']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid notification ID']);
}
?>