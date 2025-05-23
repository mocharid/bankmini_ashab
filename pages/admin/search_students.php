<?php
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['query'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit();
}

$query = trim($_POST['query']);
$response = ['status' => 'error', 'message' => '', 'students' => []];

if (strlen($query) < 2) {
    echo json_encode(['status' => 'error', 'message' => 'Query terlalu pendek']);
    exit();
}

try {
    $sql = "SELECT u.id, u.nama, u.is_frozen, j.nama_jurusan, k.nama_kelas 
            FROM users u 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id 
            LEFT JOIN kelas k ON u.kelas_id = k.id 
            WHERE u.role = 'siswa' AND u.is_frozen = 0 AND u.nama LIKE ? 
            ORDER BY u.nama ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Kesalahan database: ' . $conn->error);
    }

    $search_term = "%$query%";
    $stmt->bind_param("s", $search_term);
    $stmt->execute();
    $result = $stmt->get_result();

    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'id' => $row['id'],
            'nama' => $row['nama'],
            'nama_jurusan' => $row['nama_jurusan'] ?? 'Tidak ada',
            'nama_kelas' => $row['nama_kelas'] ?? 'Tidak ada',
            'is_frozen' => $row['is_frozen']
        ];
    }
    $stmt->close();

    if (count($students) > 0) {
        $response['status'] = 'success';
        $response['students'] = $students;
    } else {
        $response['message'] = 'Tidak ada siswa ditemukan';
    }

} catch (Exception $e) {
    $response['message'] = 'Kesalahan server: ' . $e->getMessage();
}

echo json_encode($response);
exit();