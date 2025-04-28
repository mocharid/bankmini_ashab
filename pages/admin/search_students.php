<?php
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

// Log untuk debug
error_log("search_students.php diakses: " . date('Y-m-d H:i:s'));

// Tidak perlu cek session karena sudah dicek di freeze_account.php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['query'])) {
    $query = '%' . trim($_POST['query']) . '%';
    
    // Log query
    error_log("Query pencarian: " . $query);

    $sql = "SELECT u.id, u.nama, u.is_frozen, r.no_rekening, j.nama_jurusan, k.nama_kelas 
            FROM users u 
            LEFT JOIN rekening r ON u.id = r.user_id 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id 
            LEFT JOIN kelas k ON u.kelas_id = k.id 
            WHERE u.role = 'siswa' 
            AND (u.nama LIKE ? OR r.no_rekening LIKE ?)
            ORDER BY u.nama LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Gagal prepare query: " . $conn->error);
        echo json_encode(['status' => 'error', 'message' => 'Gagal menyiapkan query']);
        exit();
    }
    
    $stmt->bind_param('ss', $query, $query);
    if (!$stmt->execute()) {
        error_log("Gagal eksekusi query: " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Gagal menjalankan query']);
        exit();
    }
    
    $result = $stmt->get_result();
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = [
            'id' => $row['id'],
            'nama' => htmlspecialchars($row['nama']),
            'no_rekening' => htmlspecialchars($row['no_rekening'] ?? 'Belum punya rekening'),
            'jurusan' => htmlspecialchars($row['nama_jurusan'] ?? 'Tidak ada'),
            'kelas' => htmlspecialchars($row['nama_kelas'] ?? 'Tidak ada'),
            'status' => $row['is_frozen'] ? 'Dibekukan' : 'Aktif',
            'is_frozen' => (bool)$row['is_frozen'] // Tambahkan is_frozen untuk kompatibilitas
        ];
    }
    
    // Log jumlah hasil
    error_log("Ditemukan " . count($students) . " siswa");
    
    echo json_encode(['status' => 'success', 'students' => $students]);
    $stmt->close();
} else {
    error_log("Request tidak valid: POST atau query tidak ada");
    echo json_encode(['status' => 'error', 'message' => 'Permintaan tidak valid']);
}

$conn->close();
?>