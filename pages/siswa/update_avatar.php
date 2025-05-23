<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Set response header to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if avatar_style is provided
if (!isset($_POST['avatar_style']) || empty($_POST['avatar_style'])) {
    echo json_encode(['status' => 'error', 'message' => 'Avatar style not provided']);
    exit();
}

$avatar_style = $_POST['avatar_style'];

// Validate avatar style
$allowed_styles = ['avataaars', 'open-peeps', 'bottts', 'pixel-art', 'adventurer', 'micah', 'croodles', 'lorelei'];
if (!in_array($avatar_style, $allowed_styles)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid avatar style']);
    exit();
}

// Update avatar style in the database
$query = "UPDATE users SET avatar = ? WHERE id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: Failed to prepare statement']);
    exit();
}

$stmt->bind_param("si", $avatar_style, $user_id);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => 'Avatar updated successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No changes made to avatar']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update avatar']);
}

$stmt->close();
$conn->close();
?>