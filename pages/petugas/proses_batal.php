<?php
session_start();
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$transaksi_id = $data['transaksi_id'] ?? 0;

if (empty($transaksi_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid transaction ID']);
    exit();
}

try {
    $conn->begin_transaction();

    // Ambil data transaksi
    $query = "SELECT * FROM transaksi WHERE id = ? AND status = 'approved' FOR UPDATE";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $transaksi_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        throw new Exception('Transaksi tidak ditemukan atau sudah dibatalkan.');
    }

    $transaksi = $result->fetch_assoc();

    // Ambil data rekening
    $query_rekening = "SELECT * FROM rekening WHERE id = ? FOR UPDATE";
    $stmt_rekening = $conn->prepare($query_rekening);
    $stmt_rekening->bind_param('i', $transaksi['rekening_id']);
    $stmt_rekening->execute();
    $result_rekening = $stmt_rekening->get_result();

    if ($result_rekening->num_rows === 0) {
        throw new Exception('Rekening tidak ditemukan.');
    }

    $rekening = $result_rekening->fetch_assoc();

    // Kembalikan saldo jika transaksi adalah setor
    if ($transaksi['jenis_transaksi'] === 'setor') {
        $saldo_baru = $rekening['saldo'] - $transaksi['jumlah'];
    } elseif ($transaksi['jenis_transaksi'] === 'tarik') {
        $saldo_baru = $rekening['saldo'] + $transaksi['jumlah'];
    }

    // Update saldo rekening
    $query_update = "UPDATE rekening SET saldo = ? WHERE id = ?";
    $stmt_update = $conn->prepare($query_update);
    $stmt_update->bind_param('di', $saldo_baru, $rekening['id']);
    $stmt_update->execute();

    // Update status transaksi menjadi 'batal'
    $query_batal = "UPDATE transaksi SET status = 'batal' WHERE id = ?";
    $stmt_batal = $conn->prepare($query_batal);
    $stmt_batal->bind_param('i', $transaksi_id);
    $stmt_batal->execute();

    // Hapus mutasi terkait
    $query_hapus_mutasi = "DELETE FROM mutasi WHERE transaksi_id = ?";
    $stmt_hapus_mutasi = $conn->prepare($query_hapus_mutasi);
    $stmt_hapus_mutasi->bind_param('i', $transaksi_id);
    $stmt_hapus_mutasi->execute();

    $conn->commit();

    echo json_encode(['status' => 'success', 'message' => 'Transaksi berhasil dibatalkan.']);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>