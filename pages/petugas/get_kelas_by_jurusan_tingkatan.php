<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['jurusan_id']) || !isset($_GET['tingkatan_kelas_id'])) {
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit;
}

$jurusan_id = intval($_GET['jurusan_id']);
$tingkatan_id = intval($_GET['tingkatan_kelas_id']);

$query = "SELECT id, nama_kelas FROM kelas WHERE jurusan_id = ? AND tingkatan_kelas_id = ? ORDER BY nama_kelas ASC";
$stmt = $conn->prepare($query);

if ($stmt) {
    $stmt->bind_param("ii", $jurusan_id, $tingkatan_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $kelas = [];
    while ($row = $result->fetch_assoc()) {
        $kelas[] = $row;
    }

    echo json_encode(['success' => true, 'data' => $kelas]);
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>