<?php
require_once '../../includes/db_connection.php';

// Ambil ID siswa dari parameter GET
$student_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$student_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID siswa tidak valid']);
    exit;
}

// Query untuk mendapatkan data siswa lengkap
$query = $conn->prepare("
    SELECT 
        u.id, 
        u.nama, 
        u.jurusan_id, 
        u.kelas_id, 
        r.no_rekening 
    FROM users u
    LEFT JOIN rekening r ON u.id = r.user_id
    WHERE u.id = ? AND u.role = 'siswa'
");
$query->bind_param("i", $student_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Siswa tidak ditemukan']);
    exit;
}

// Ambil data siswa
$student_data = $result->fetch_assoc();

// Kirim data sebagai JSON
header('Content-Type: application/json');
echo json_encode($student_data);
?>