<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

if (!isset($_GET['jurusan_id']) || empty($_GET['jurusan_id'])) {
    echo json_encode([]);
    exit;
}

$jurusan_id = $conn->real_escape_string($_GET['jurusan_id']);
$query = "SELECT id, nama_kelas FROM kelas WHERE jurusan_id = ? ORDER BY nama_kelas ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $jurusan_id);
$stmt->execute();
$result = $stmt->get_result();

$kelas_list = [];
while ($row = $result->fetch_assoc()) {
    $kelas_list[] = $row;
}

echo json_encode($kelas_list);