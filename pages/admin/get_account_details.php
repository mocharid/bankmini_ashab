<?php
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['no_rekening'])) {
    echo json_encode(['status' => 'error', 'message' => 'Permintaan tidak valid.']);
    exit();
}

$no_rekening = trim($_POST['no_rekening']);
$response = ['status' => 'error', 'message' => ''];

if (!preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
    echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak valid. Format: REK + 6 digit angka.']);
    exit();
}

try {
    $sql = "SELECT u.nama, u.email, u.tanggal_lahir, j.nama_jurusan, k.nama_kelas
            FROM rekening r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id
            LEFT JOIN kelas k ON u.kelas_id = k.id
            WHERE r.no_rekening = ? AND u.role = 'siswa' AND u.is_frozen = 0";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Kesalahan server: Gagal mempersiapkan query.');
    }

    $stmt->bind_param("s", $no_rekening);
    if (!$stmt->execute()) {
        throw new Exception('Kesalahan server: Gagal menjalankan query.');
    }

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $response['status'] = 'success';
        $response['nama'] = $row['nama'];
        $response['email'] = $row['email'];
        $response['tanggal_lahir'] = $row['tanggal_lahir'];
        $response['nama_jurusan'] = $row['nama_jurusan'];
        $response['nama_kelas'] = $row['nama_kelas'];
    } else {
        $response['message'] = 'Nomor rekening tidak ditemukan atau akun tidak valid (mungkin dibekukan atau bukan siswa).';
    }

    $stmt->close();
} catch (Exception $e) {
    $response['message'] = 'Kesalahan server: ' . $e->getMessage();
    error_log("Error in get_account_details: " . $e->getMessage());
}

echo json_encode($response);
exit();