<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

if (!isset($_GET['tingkatan_kelas_id']) || empty($_GET['tingkatan_kelas_id']) || !isset($_GET['jurusan_id']) || empty($_GET['jurusan_id'])) {
    echo json_encode([]);
    exit;
}

$tingkatan_kelas_id = $conn->real_escape_string($_GET['tingkatan_kelas_id']);
$jurusan_id = $conn->real_escape_string($_GET['jurusan_id']);
$query = "SELECT k.id, k.nama_kelas, tk.nama_tingkatan 
          FROM kelas k 
          JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
          WHERE k.tingkatan_kelas_id = ? AND k.jurusan_id = ? 
          ORDER BY tk.nama_tingkatan, k.nama_kelas ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $tingkatan_kelas_id, $jurusan_id);
$stmt->execute();
$result = $stmt->get_result();

$kelas_list = [];
while ($row = $result->fetch_assoc()) {
    $kelas_list[] = $row;
}

echo json_encode($kelas_list);