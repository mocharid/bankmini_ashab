<?php
session_start();
require_once '../../includes/db_connection.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit();
}

// Cek metode request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Ambil data dari body request (JSON)
    $data = json_decode(file_get_contents('php://input'), true);
    $petugas_id = $_SESSION['user_id'];
    $saldo_bersih = floatval($data['saldo_bersih'] ?? 0);

    // Validasi saldo bersih
    if ($saldo_bersih <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Saldo bersih tidak valid.']);
        exit();
    }

    try {
        $conn->begin_transaction();

        // 1. Update total saldo di admin (hanya jika tombol "Serahkan Uang" diklik)
        $query_admin = "UPDATE rekening SET saldo = saldo + ? WHERE user_id = 1"; // Asumsi user_id admin adalah 1
        $stmt_admin = $conn->prepare($query_admin);
        $stmt_admin->bind_param('d', $saldo_bersih);
        $stmt_admin->execute();

        // 2. Reset saldo bersih petugas dengan mengupdate status transaksi
        $query_reset = "UPDATE transaksi SET status = 'completed' WHERE petugas_id = ? AND (status = 'approved' OR status IS NULL)";
        $stmt_reset = $conn->prepare($query_reset);
        $stmt_reset->bind_param('i', $petugas_id);
        $stmt_reset->execute();

        // Commit transaksi
        $conn->commit();

        echo json_encode(['status' => 'success', 'message' => 'Uang berhasil diserahkan.']);
    } catch (Exception $e) {
        // Rollback jika terjadi error
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.']);
}
?>