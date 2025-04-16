<?php
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$today = date('Y-m-d');

$query = "SELECT 
    t.no_transaksi,
    t.jenis_transaksi,
    t.jumlah,
    t.created_at,
    u.nama AS nama_siswa
FROM transaksi t
LEFT JOIN rekening r ON t.rekening_id = r.id
LEFT JOIN users u ON r.user_id = u.id
WHERE DATE(t.created_at) = ?
AND t.jenis_transaksi != 'transfer'
ORDER BY t.created_at DESC
LIMIT ? OFFSET ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("sii", $today, $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();

$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = [
        'no_transaksi' => $row['no_transaksi'],
        'nama_siswa' => $row['nama_siswa'] ?? 'Unknown',
        'jenis_transaksi' => $row['jenis_transaksi'],
        'jumlah' => $row['jumlah'],
        'created_at' => $row['created_at']
    ];
}

header('Content-Type: application/json');
echo json_encode($transactions);
exit;
?>