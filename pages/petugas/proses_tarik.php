<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';

    if ($action === 'cek_rekening') {
        $query = "SELECT r.*, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id 
                  FROM rekening r 
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
            // Update has_pin if pin exists but flag is incorrect
            if (!empty($row['pin']) && !$row['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param('i', $row['user_id']);
                $update_stmt->execute();
                $row['has_pin'] = 1;
            }
            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'saldo' => floatval($row['saldo']),
                'email' => $row['email'],
                'user_id' => $row['user_id'],
                'rekening_id' => $row['rekening_id'],
                'has_pin' => (bool)$row['has_pin']
            ]);
        }
    } elseif ($action === 'verify_pin') {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'PIN harus diisi']);
            exit();
        }

        if (empty($user_id) || $user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID User tidak valid']);
            exit();
        }

        $query = "SELECT pin, has_pin FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan']);
            exit();
        }

        $user = $result->fetch_assoc();

        // Update has_pin if pin exists but flag is incorrect
        if (!empty($user['pin']) && !$user['has_pin']) {
            $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $user_id);
            $update_stmt->execute();
            $user['has_pin'] = 1;
        }

        if (!$user['has_pin'] && empty($user['pin'])) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN']);
            exit();
        }

        // Verify PIN (assuming plain text for demo; use password_verify() if hashed)
        if ($user['pin'] !== $pin) {
            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid']);
    } elseif ($action === 'tarik_tunai') {
        try {
            $conn->begin_transaction();

            // Ambil data rekening
            $query = "SELECT r.*, u.nama, u.email FROM rekening r 
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
            $user_id = $row['user_id'];
            $email = $row['email'];

            // Validasi saldo nasabah
            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk melakukan penarikan.');
            }

            // Ambil data untuk saldo petugas
            $today = date('Y-m-d');
            $petugas_id = $_SESSION['user_id'];

            // Total setoran
            $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total_setoran 
                             FROM transaksi 
                             WHERE petugas_id = ? 
                             AND jenis_transaksi = 'setor' 
                             AND status = 'approved' 
                             AND DATE(created_at) = ?";
            $stmt_setoran = $conn->prepare($query_setoran);
            $stmt_setoran->bind_param('is', $petugas_id, $today);
            $stmt_setoran->execute();
            $total_setoran = floatval($stmt_setoran->get_result()->fetch_assoc()['total_setoran']);

            // Total penarikan
            $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total_penarikan 
                               FROM transaksi 
                               WHERE petugas_id = ? 
                               AND jenis_transaksi = 'tarik' 
                               AND status = 'approved' 
                               AND DATE(created_at) = ?";
            $stmt_penarikan = $conn->prepare($query_penarikan);
            $stmt_penarikan->bind_param('is', $petugas_id, $today);
            $stmt_penarikan->execute();
            $total_penarikan = floatval($stmt_penarikan->get_result()->fetch_assoc()['total_penarikan']);

            // Saldo tambahan dari admin
            $query_saldo_tambahan = "SELECT COALESCE(SUM(jumlah), 0) as saldo_tambahan 
                                    FROM saldo_transfers 
                                    WHERE petugas_id = ? 
                                    AND tanggal = ?";
            $stmt_saldo_tambahan = $conn->prepare($query_saldo_tambahan);
            $stmt_saldo_tambahan->bind_param('is', $petugas_id, $today);
            $stmt_saldo_tambahan->execute();
            $saldo_tambahan = floatval($stmt_saldo_tambahan->get_result()->fetch_assoc()['saldo_tambahan']);

            // Hitung saldo bersih petugas
            $saldo_bersih = ($total_setoran - $total_penarikan) + $saldo_tambahan;

            // Validasi saldo petugas
            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas petugas hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            // Proses transaksi
            $no_transaksi = 'TRX' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved')";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            $stmt_transaksi->execute();

            $transaksi_id = $conn->insert_id;

            // Tidak mengurangi saldo rekening langsung
            $saldo_baru = $saldo_sekarang; // Saldo tetap sama

            // Catat mutasi (opsional, untuk pencatatan sementara)
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                            VALUES (?, ?, ?, ?)";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            $stmt_mutasi->execute();

            // Kirim notifikasi
            $message = "Penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah diproses oleh petugas. Saldo Anda akan diperbarui setelah rekonsiliasi.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            $stmt_notifikasi->execute();

            // Kirim email
            $email_sent = false;
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'mocharid.ip@gmail.com';
                $mail->Password = 'spjs plkg ktuu lcxh';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
                $mail->addAddress($email, $nama_nasabah);
                $mail->isHTML(true);
                $mail->Subject = "Bukti Transaksi Penarikan Tunai - SCHOBANK SYSTEM";
                $mail->Body = "<h2>Penarikan Tunai Berhasil</h2><p>Jumlah: Rp " . number_format($jumlah, 0, ',', '.') . "<br>Catatan: Saldo Anda akan diperbarui setelah rekonsiliasi oleh admin.</p>";
                $mail->AltBody = "Penarikan Tunai Berhasil\nJumlah: Rp " . number_format($jumlah, 0, ',', '.') . "\nCatatan: Saldo Anda akan diperbarui setelah rekonsiliasi oleh admin.";

                $mail->send();
                $email_sent = true;
            }

            $conn->commit();
            echo json_encode([
                'status' => 'success',
                'message' => "Penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil.",
                'email_sent' => $email_sent
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>