<?php
session_start();
ob_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.', 'email_status' => 'none']);
    exit();
}

$action = $_POST['action'] ?? '';
$no_rekening = trim($_POST['no_rekening'] ?? '');
$jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;
$user_id = intval($_POST['user_id'] ?? 0);
$pin = trim($_POST['pin'] ?? '');

function is_account_blocked($pin_block_until) {
    return $pin_block_until && strtotime($pin_block_until) > time();
}

try {
    switch ($action) {
        case 'cek_rekening':
            if (empty($no_rekening) || strlen($no_rekening) !== 8 || !ctype_digit($no_rekening)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak valid. Harus 8 digit angka.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $query = "
                SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, u.pin_block_until,
                       j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas, u.is_frozen
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
            ";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan query: ' . $conn->error);
            }
            $stmt->bind_param('s', $no_rekening);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menjalankan query: ' . $stmt->error);
            }
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak ditemukan.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $row = $result->fetch_assoc();

            if ($row['is_frozen']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }
            if (is_account_blocked($row['pin_block_until'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang diblokir sementara. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            if (!empty($row['pin']) && !$row['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) {
                    throw new Exception('Gagal menyiapkan query update: ' . $conn->error);
                }
                $update_stmt->bind_param('i', $row['user_id']);
                if (!$update_stmt->execute()) {
                    throw new Exception('Gagal menjalankan query update: ' . $update_stmt->error);
                }
                $update_stmt->close();
                $row['has_pin'] = 1;
            }

            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'jurusan' => $row['jurusan'] ?: '-',
                'kelas' => $row['kelas'] ?: '-',
                'email' => $row['email'] ?: '',
                'user_id' => $row['user_id'],
                'rekening_id' => $row['rekening_id'],
                'has_pin' => (bool)$row['has_pin'],
                'saldo' => floatval($row['saldo']),
                'email_status' => 'none'
            ]);
            $stmt->close();
            break;

        case 'check_balance':
            if (empty($no_rekening) || strlen($no_rekening) !== 8 || !ctype_digit($no_rekening)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak valid.',
                    'email_status' => 'none'
                ]);
                exit();
            }
            if ($jumlah < 1) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Jumlah penarikan minimal Rp 1.',
                    'email_status' => 'none'
                ]);
                exit();
            }
            if ($jumlah > 99999999.99) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $query = "SELECT r.saldo, u.is_frozen, u.pin_block_until FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan query: ' . $conn->error);
            }
            $stmt->bind_param('s', $no_rekening);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menjalankan query: ' . $stmt->error);
            }
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak ditemukan.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $row = $result->fetch_assoc();

            if ($row['is_frozen']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }
            if (is_account_blocked($row['pin_block_until'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang diblokir sementara. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            if (floatval($row['saldo']) < $jumlah) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Saldo tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Saldo cukup untuk penarikan.',
                'email_status' => 'none'
            ]);
            $stmt->close();
            break;

        case 'verify_pin':
            if (empty($pin) || strlen($pin) !== 6 || !ctype_digit($pin)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'PIN harus 6 digit angka.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            if ($user_id <= 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ID User tidak valid.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $query = "SELECT pin, has_pin, is_frozen, pin_block_until, failed_pin_attempts FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan query: ' . $conn->error);
            }

            $stmt->bind_param('i', $user_id);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menjalankan query: ' . $stmt->error);
            }
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User tidak ditemukan.',
                    'email_status' => 'none'
                ]);
                $stmt->close();
                exit();
            }

            $user = $result->fetch_assoc();
            $stmt->close();

            if ($user['is_frozen']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            if (is_account_blocked($user['pin_block_until'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang diblokir sementara. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            if (!empty($user['pin']) && !$user['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) {
                    throw new Exception('Gagal menyiapkan query update: ' . $conn->error);
                }
                $update_stmt->bind_param('i', $user_id);
                if (!$update_stmt->execute()) {
                    throw new Exception('Gagal menjalankan query update: ' . $update_stmt->error);
                }
                $update_stmt->close();
                $user['has_pin'] = 1;
            }

            if (!$user['has_pin'] || empty($user['pin'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'User belum mengatur PIN. Silakan atur PIN terlebih dahulu.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $hashed_input_pin = hash('sha256', $pin);
            if ($hashed_input_pin !== $user['pin']) {
                $failed_attempts = $user['failed_pin_attempts'] + 1;
                $update_query = "UPDATE users SET failed_pin_attempts = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                if (!$update_stmt) {
                    throw new Exception('Gagal menyiapkan query update: ' . $conn->error);
                }
                $update_stmt->bind_param('ii', $failed_attempts, $user_id);
                if (!$update_stmt->execute()) {
                    throw new Exception('Gagal menjalankan query update: ' . $update_stmt->error);
                }
                $update_stmt->close();

                if ($failed_attempts >= 3) {
                    $block_until = date('Y-m-d H:i:s', strtotime('+30 minutes'));
                    $block_query = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                    $block_stmt = $conn->prepare($block_query);
                    if (!$block_stmt) {
                        throw new Exception('Gagal menyiapkan query block: ' . $conn->error);
                    }
                    $block_stmt->bind_param('si', $block_until, $user_id);
                    if (!$block_stmt->execute()) {
                        throw new Exception('Gagal menjalankan query block: ' . $block_stmt->error);
                    }
                    $block_stmt->close();
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'PIN salah sebanyak 3 kali. Akun diblokir sementara selama 30 menit.',
                        'email_status' => 'none'
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'message' => 'PIN salah. Sisa percobaan: ' . (3 - $failed_attempts) . '.',
                        'email_status' => 'none'
                    ]);
                }
                exit();
            }

            $reset_query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $reset_stmt = $conn->prepare($reset_query);
            if (!$reset_stmt) {
                throw new Exception('Gagal menyiapkan query reset: ' . $conn->error);
            }
            $reset_stmt->bind_param('i', $user_id);
            if (!$reset_stmt->execute()) {
                throw new Exception('Gagal menjalankan query reset: ' . $reset_stmt->error);
            }
            $reset_stmt->close();

            echo json_encode([
                'status' => 'success',
                'message' => 'PIN valid.',
                'email_status' => 'none'
            ]);
            break;

        case 'reset_pin_attempts':
            if ($user_id <= 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'ID User tidak valid.',
                    'email_status' => 'none'
                ]);
                exit();
            }

            $reset_query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $reset_stmt = $conn->prepare($reset_query);
            if (!$reset_stmt) {
                throw new Exception('Gagal menyiapkan query reset: ' . $conn->error);
            }
            $reset_stmt->bind_param('i', $user_id);
            if (!$reset_stmt->execute()) {
                throw new Exception('Gagal menjalankan query reset: ' . $reset_stmt->error);
            }
            $reset_stmt->close();

            echo json_encode([
                'status' => 'success',
                'message' => 'PIN attempts reset.',
                'email_status' => 'none'
            ]);
            break;

        case 'tarik_saldo':
            $conn->begin_transaction();

            if (empty($no_rekening) || strlen($no_rekening) !== 8 || !ctype_digit($no_rekening)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak valid.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }
            if ($jumlah < 1) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Jumlah penarikan minimal Rp 1.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }
            if ($jumlah > 99999999.99) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }

            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, u.is_frozen, u.pin_block_until,
                       j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
                FOR UPDATE
            ";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan query: ' . $conn->error);
            }
            $stmt->bind_param('s', $no_rekening);
            if (!$stmt->execute()) {
                throw new Exception('Gagal menjalankan query: ' . $stmt->error);
            }
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Nomor rekening tidak ditemukan.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }

            $row = $result->fetch_assoc();

            if ($row['is_frozen']) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }
            if (is_account_blocked($row['pin_block_until'])) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Akun sedang diblokir sementara. Tidak dapat melakukan tarik tunai.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }

            if ($row['saldo'] < $jumlah) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Saldo rekening tidak mencukupi.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }

            $rekening_id = $row['id'];
            $saldo_sekarang = floatval($row['saldo']);
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id'];
            $email = $row['email'] ?? '';
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';
            $stmt->close();

            $today = date('Y-m-d');
            $query_saldo_kas = "
                SELECT (
                    COALESCE((
                        SELECT SUM(net_setoran)
                        FROM (
                            SELECT SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                                   SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                            FROM transaksi
                            WHERE status = 'approved' AND DATE(created_at) < ?
                            GROUP BY DATE(created_at)
                        ) as daily_net
                    ), 0) +
                    COALESCE((
                        SELECT SUM(jumlah)
                        FROM transaksi
                        WHERE jenis_transaksi = 'setor' AND status = 'approved'
                              AND DATE(created_at) = ? AND petugas_id IS NULL
                    ), 0) -
                    COALESCE((
                        SELECT SUM(jumlah)
                        FROM transaksi
                        WHERE jenis_transaksi = 'tarik' AND status = 'approved'
                              AND DATE(created_at) = ? AND petugas_id IS NULL
                    ), 0)
                ) as saldo_kas
            ";
            $stmt_saldo_kas = $conn->prepare($query_saldo_kas);
            if (!$stmt_saldo_kas) {
                throw new Exception('Gagal menyiapkan query saldo kas: ' . $conn->error);
            }
            $stmt_saldo_kas->bind_param('sss', $today, $today, $today);
            if (!$stmt_saldo_kas->execute()) {
                throw new Exception('Gagal menjalankan query saldo kas: ' . $stmt_saldo_kas->error);
            }
            $saldo_kas = floatval($stmt_saldo_kas->get_result()->fetch_assoc()['saldo_kas']);
            $stmt_saldo_kas->close();

            if ($saldo_kas < $jumlah) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Saldo kas sistem tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.',
                    'email_status' => 'none'
                ]);
                $conn->rollback();
                exit();
            }

            // Generate ID Transaksi
            do {
                $date_prefix = date('ymd');
                $random_8digit = sprintf('%08d', mt_rand(10000000, 99999999));
                $id_transaksi = $date_prefix . $random_8digit;
                
                $check_id_query = "SELECT id FROM transaksi WHERE id_transaksi = ?";
                $check_id_stmt = $conn->prepare($check_id_query);
                $check_id_stmt->bind_param('s', $id_transaksi);
                $check_id_stmt->execute();
                $check_id_result = $check_id_stmt->get_result();
                $id_exists = $check_id_result->num_rows > 0;
                $check_id_stmt->close();
            } while ($id_exists);

            // Generate Nomor Referensi
            do {
                $date_prefix = date('ymd');
                $random_6digit = sprintf('%06d', mt_rand(100000, 999999));
                $no_transaksi = 'TRXTA' . $date_prefix . $random_6digit;
                
                $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param('s', $no_transaksi);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->num_rows > 0;
                $check_stmt->close();
            } while ($exists);

            $keterangan = 'Tarik Tunai';
            $petugas_id = null;

            $query_transaksi = "INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, keterangan, created_at) 
                               VALUES (?, ?, ?, 'tarik', ?, ?, 'approved', ?, NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Gagal menyiapkan query transaksi: ' . $conn->error);
            }
            $stmt_transaksi->bind_param('ssidis', $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $petugas_id, $keterangan);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menjalankan query transaksi: ' . $stmt_transaksi->error);
            }
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            if (!$stmt_update_saldo) {
                throw new Exception('Gagal menyiapkan query update saldo: ' . $conn->error);
            }
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal menjalankan query update saldo: ' . $stmt_update_saldo->error);
            }
            $stmt_update_saldo->close();

            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            if (!$stmt_mutasi) {
                throw new Exception('Gagal menyiapkan query mutasi: ' . $conn->error);
            }
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal menjalankan query mutasi: ' . $stmt_mutasi->error);
            }
            $stmt_mutasi->close();

            $notif_message = "Asiik! Transaksi penarikan tunai Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh admin. Cek saldo kamu sekarang Yu! Jika bukan kamu yang melakukan, hubungi kami ya.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            if (!$stmt_notifikasi) {
                throw new Exception('Gagal menyiapkan query notifikasi: ' . $conn->error);
            }
            $stmt_notifikasi->bind_param('is', $user_id, $notif_message);
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal menjalankan query notifikasi: ' . $stmt_notifikasi->error);
            }
            $stmt_notifikasi->close();

            // Kirim email dengan format baru
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Tarik Tunai {$no_transaksi}";
                
                $bulan = [
                            'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
                            'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
                            'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
                ];
                $tanggal_transaksi = date('d M Y, H:i');
                foreach ($bulan as $en => $id) {
                    $tanggal_transaksi = str_replace($en, $id, $tanggal_transaksi);
                }

                $message = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
</head>
<body style='margin:0; padding:0; font-family:Poppins,Arial,sans-serif; background:#f5f5f5;'>
    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%'>
        <tr>
            <td style='padding:40px 20px;'>
                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='600' style='margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
                    <!-- Header -->
                    <tr>
                        <td style='padding:40px 40px 30px; text-align:center; background:#ffffff;'>
                            <img src='cid:header_img' alt='Schobank' style='max-width:260px; width:100%; height:auto;' />
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style='padding:0 40px 40px;'>
                            <h2 style='margin:0 0 12px; font-size:20px; font-weight:600; color:#1a1a1a;'>Halo <strong>{$nama_nasabah}</strong>,</h2>
                            <p style='margin:0 0 24px; font-size:15px; line-height:1.6; color:#4a4a4a;'>
                                Kamu berhasil menarik tunai <strong style='color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</strong> dari rekening Tabungan Utama.
                            </p>

                            <hr style='border:none; border-top:1px solid #e5e5e5; margin:30px 0;' />

                            <h3 style='margin:0 0 20px; font-size:17px; font-weight:700; color:#1a1a1a;'>Detail Transaksi</h3>

                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Penarikan</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Biaya Admin</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#22c55e;'>Gratis</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Total</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Sumber</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_nasabah}<br><span style='font-size:14px; color:#808080;'>Schobank • {$no_rekening}</span></p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>Tarik Tunai</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal & Waktu</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
                            </div>
                            <div style='margin-bottom:0;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
                                <p style='margin:0; font-size:16px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style='padding:30px 40px; text-align:center; border-top:1px solid #e5e5e5; background:#ffffff;'>
                            <p style='margin:0 0 8px; font-size:13px; color:#6b7280;'>
                                © " . date('Y') . " Schobank Student Digital Banking. All rights reserved.
                            </p>
                            <p style='margin:0 0 8px; font-size:12px; color:#9ca3af;'>
                                Email ini dikirim secara otomatis. Mohon tidak membalas email ini.
                            </p>
                            <p style='margin:0; font-size:11px; color:#d1d5db;'>
                                Ref: {$no_transaksi} • ID: {$id_transaksi}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";

                try {
                    $mail = new PHPMailer(true);
                    
                    $mail->clearAllRecipients();
                    $mail->clearAttachments();
                    $mail->clearReplyTos();
                    
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'myschobank@gmail.com';
                    $mail->Password = 'xpni zzju utfu mkth';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';
                    
                    $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
                    $mail->addAddress($email, $nama_nasabah);
                    $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
                    
                    $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
                    $mail->MessageID = '<' . $unique_id . '>';
                    
                    $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
                    $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
                    $mail->addCustomHeader('X-Mailer', 'Schobank-System-v1.0');
                    $mail->addCustomHeader('X-Priority', '1');
                    $mail->addCustomHeader('Importance', 'High');

                    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/header.png';
                    if (file_exists($header_path)) {
                        $mail->addEmbeddedImage($header_path, 'header_img', 'header.png');
                    }

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message));

                    if ($mail->send()) {
                        $email_status = 'sent';
                    } else {
                        throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
                    }
                    
                    $mail->smtpClose();
                    
                } catch (Exception $e) {
                    error_log("Mail error for transaction {$id_transaksi}: " . $e->getMessage());
                    $email_status = 'failed';
                }
            }

            $conn->commit();

            error_log("Penarikan berhasil: no_transaksi=$no_transaksi, id_transaksi=$id_transaksi, jumlah=$jumlah, no_rekening=$no_rekening, petugas_id=NULL, status=approved, email_status=$email_status");

            $response_message = "Transaksi penarikan saldo untuk {$nama_nasabah} telah berhasil diproses.";
            if ($email_status === 'failed') {
                $response_message .= " (Gagal mengirim bukti transaksi ke email)";
            } else if ($email_status === 'sent') {
                $response_message .= " (Bukti transaksi telah dikirim ke {$email})";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_status' => $email_status,
                'data' => [
                    'id_transaksi' => $id_transaksi,
                    'no_transaksi' => $no_transaksi,
                    'no_rekening' => $no_rekening,
                    'nama_nasabah' => $nama_nasabah,
                    'jumlah' => number_format($jumlah, 0, ',', '.'),
                    'saldo_sebelum' => number_format($saldo_sekarang, 0, ',', '.'),
                    'saldo_sesudah' => number_format($saldo_baru, 0, ',', '.'),
                    'status_transaksi' => 'approved',
                    'keterangan' => $keterangan,
                    'diproses_oleh' => 'Admin',
                    'tanggal' => date('d M Y H:i:s')
                ]
            ]);
            break;

        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Aksi tidak valid.',
                'email_status' => 'none'
            ]);
            exit();
    }
} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction()) {
        $conn->rollback();
    }
    error_log("Error in proses_tarik_admin.php: {$e->getMessage()} | Action: $action | No Rekening: $no_rekening | User ID: $user_id");
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'email_status' => 'none'
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
}
?>