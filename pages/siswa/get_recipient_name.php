<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_rekening'])) {
    $no_rekening = trim($_POST['no_rekening']);
    $user_id = $_SESSION['user_id'];

    $query = "SELECT u.nama 
              FROM users u 
              JOIN rekening r ON u.id = r.user_id 
              WHERE r.no_rekening = ? AND r.user_id != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $no_rekening, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    if ($data) {
        echo json_encode(['success' => true, 'nama' => $data['nama']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Nomor rekening tidak ditemukan']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}

$conn->close();
?>