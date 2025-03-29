<?php
// Set timezone
date_default_timezone_set('Asia/Jakarta');

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Get parameters from AJAX request
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Number of records per page
$offset = ($page - 1) * $limit;

// Get today's date
$today = date('Y-m-d');

// Query to fetch transactions
$query = "SELECT t.*, u1.nama as nama_siswa
          FROM transaksi t 
          JOIN rekening r1 ON t.rekening_id = r1.id
          JOIN users u1 ON r1.user_id = u1.id
          WHERE DATE(t.created_at) = ?
          AND jenis_transaksi != 'transfer'
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

// Send JSON response
header('Content-Type: application/json');
echo json_encode($transactions);
?>