<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] == 'siswa') {
    header("Location: login.php");
    exit;
}

// Check if parameters are provided
if (!isset($_GET['id']) || empty($_GET['id']) || !isset($_GET['action']) || empty($_GET['action'])) {
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer_id = $_GET['id'];
$action = $_GET['action'];

// Validate action
if ($action != 'approve' && $action != 'reject') {
    header("Location: riwayat_transfer.php");
    exit;
}

// Get transfer details
$stmt = $koneksi->prepare("SELECT * FROM transaksi WHERE id = ? AND jenis_transaksi = 'transfer' AND status = 'pending'");
$stmt->bind_param("i", $transfer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Transfer tidak ditemukan atau sudah diproses.";
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer = $result->fetch_assoc();
$stmt->close();

// Begin transaction
$koneksi->begin_transaction();

try {
    if ($action == 'approve') {
        // Get source account details
        $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE id = ?");
        $stmt->bind_param("i", $transfer['rekening_id']);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Get destination account details
        $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE id = ?");
        $stmt->bind_param("i", $transfer['rekening_tujuan_id']);
        $stmt->execute();
        $rek_tujuan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Check if source account has sufficient balance
        if ($rek_asal['saldo'] < $transfer['jumlah']) {
            throw new Exception("Saldo rekening asal tidak mencukupi untuk transfer.");
        }
        
        // Update source account balance
        $saldo_asal_baru = $rek_asal['saldo'] - $transfer['jumlah'];
        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
        $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
        $stmt->execute();
        $stmt->close();
        
        // Update destination account balance
        $saldo_tujuan_baru = $rek_tujuan['saldo'] + $transfer['jumlah'];
        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
        $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
        $stmt->execute();
        $stmt->close();
        
        // Create mutation record for source account
        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
        $jumlah_negative = -$transfer['jumlah']; // negative for outgoing transfer
        $stmt->bind_param("iidd", $transfer_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
        $stmt->execute();
        $stmt->close();
        
        // Create mutation record for destination account
        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iidd", $transfer_id, $rek_tujuan['id'], $transfer['jumlah'], $saldo_tujuan_baru);
        $stmt->execute();
        $stmt->close();
        
        // Update transaction status to approved
        $stmt = $koneksi->prepare("UPDATE transaksi SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $transfer_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Transfer berhasil disetujui dan diproses.";
    } else {
        // Update transaction status to rejected
        $stmt = $koneksi->prepare("UPDATE transaksi SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $transfer_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success'] = "Transfer telah ditolak.";
    }
    
    // Commit transaction
    $koneksi->commit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    $koneksi->rollback();
    $_SESSION['error'] = "Gagal memproses transfer: " . $e->getMessage();
}

header("Location: riwayat_transfer.php");
exit;
?>