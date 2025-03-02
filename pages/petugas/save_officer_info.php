<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    $_SESSION['officer1_name'] = $data['officer1_name'];
    $_SESSION['officer1_class'] = $data['officer1_class'];
    $_SESSION['officer2_name'] = $data['officer2_name'];
    $_SESSION['officer2_class'] = $data['officer2_class'];

    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
}
?>