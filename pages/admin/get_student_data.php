<?php
require_once '../../includes/db_connection.php';

$id = $_GET['id'] ?? 0;

$query = "SELECT u.id, u.nama, u.jurusan_id, u.kelas_id, r.no_rekening 
          FROM users u
          LEFT JOIN rekening r ON u.id = r.user_id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode($row);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Data siswa tidak ditemukan']);
}
?>