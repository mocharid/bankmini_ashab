<?php
session_start();
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);

    if ($action === 'cek') {
        // Cek rekening
        $query = "SELECT r.*, u.nama FROM rekening r 
                  JOIN users u ON r.user_id = u.id 
                  WHERE r.no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.']);
        } else {
            $row = $result->fetch_assoc();
            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'saldo' => floatval($row['saldo']) // Pastikan saldo adalah angka
            ]);
        }
    } elseif ($action === 'setor') {
        try {
            $conn->begin_transaction();

            // Cek rekening
            $query = "SELECT r.*, u.nama FROM rekening r 
                      JOIN users u ON r.user_id = u.id 
                      WHERE r.no_rekening = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }

            $row = $result->fetch_assoc();
            $rekening_id = $row['id'];
            $saldo_sekarang = $row['saldo'];
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id']; // ID siswa

            // Generate nomor transaksi
            $no_transaksi = 'TRX' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            // Insert transaksi
            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status) 
                               VALUES (?, ?, 'setor', ?, ?, 'approved')";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $petugas_id = $_SESSION['user_id'];
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }

            $transaksi_id = $conn->insert_id;

            // Update saldo
            $saldo_baru = $saldo_sekarang + $jumlah;
            $query_update = "UPDATE rekening SET saldo = ? WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);
            $stmt_update->bind_param('di', $saldo_baru, $rekening_id);
            
            if (!$stmt_update->execute()) {
                throw new Exception('Gagal update saldo.');
            }

            // Insert mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                            VALUES (?, ?, ?, ?)";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }

            // Kirim notifikasi ke siswa
            $message = "Setoran tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.');
            $query_notifikasi = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => "Setoran tunai untuk nasabah {$nama_nasabah} berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.')
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>