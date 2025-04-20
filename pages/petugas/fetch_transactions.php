<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$today = date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$query = "SELECT t.*, u.nama as nama_siswa 
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id 
          JOIN users u ON r.user_id = u.id 
          WHERE DATE(t.created_at) = ? 
          AND t.jenis_transaksi != 'transfer' 
          AND t.petugas_id IS NOT NULL 
          AND (t.status = 'approved' OR t.status IS NULL)
          ORDER BY t.created_at DESC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $today, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

header('Content-Type: application/json');
echo json_encode($transactions);

$stmt->close();
$conn->close();
?>