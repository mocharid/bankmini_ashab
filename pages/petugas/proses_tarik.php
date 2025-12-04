<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

// Validasi keberadaan petugas1_nama dan petugas2_nama di jadwal hari ini
$query_schedule = "
    SELECT u1.nama AS petugas1_nama, u2.nama AS petugas2_nama 
    FROM petugas_tugas pt
    LEFT JOIN users u1 ON pt.petugas1_id = u1.id
    LEFT JOIN users u2 ON pt.petugas2_id = u2.id
    WHERE pt.tanggal = CURDATE()
";
$stmt_schedule = $conn->prepare($query_schedule);
if (!$stmt_schedule) {
    echo json_encode(['status' => 'error', 'message' => 'Error preparing schedule query.', 'email_status' => 'none']);
    exit();
}
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();

if (!$schedule || empty($schedule['petugas1_nama']) || empty($schedule['petugas2_nama'])) {
    echo json_encode(['status' => 'error', 'message' => 'Transaksi tidak dapat dilakukan karena tidak ada petugas.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';
    $petugas_id = $_SESSION['user_id'];

    if ($action === 'cek_rekening') {
        // Validasi format no_rekening: 8 digit angka tanpa prefix
        if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
            exit();
        }

        $query = "
            SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, 
                   j.nama_jurusan AS jurusan, 
                   CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas,
                   u.is_frozen, u.pin_block_until
            FROM rekening r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id
            LEFT JOIN kelas k ON u.kelas_id = k.id
            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
            WHERE r.no_rekening = ?
        ";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Error preparing statement.', 'email_status' => 'none']);
            exit();
        }
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
        } else {
            $row = $result->fetch_assoc();
            $is_blocked = $row['pin_block_until'] && strtotime($row['pin_block_until']) > time();
            $is_frozen = (bool)$row['is_frozen'];

            if ($is_frozen || $is_blocked) {
                $error_message = $is_frozen ? 'Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.' : 'Akun sedang diblokir sementara. Tidak dapat melakukan tarik tunai.';
                echo json_encode(['status' => 'error', 'message' => $error_message, 'email_status' => 'none']);
                exit();
            }

            if (!empty($row['pin']) && !$row['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param('i', $row['user_id']);
                $update_stmt->execute();
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
        }
        $stmt->close();
    } elseif ($action === 'check_balance') {
        // Validasi format no_rekening: 8 digit angka tanpa prefix
        if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
            exit();
        }

        if ($jumlah < 1) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan minimal Rp 1.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah > 99999999) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan maksimal Rp 99.999.999.', 'email_status' => 'none']);
            exit();
        }

        $query = "SELECT saldo FROM rekening WHERE no_rekening = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Error preparing statement.', 'email_status' => 'none']);
            exit();
        }
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
            exit();
        }

        $row = $result->fetch_assoc();
        $saldo = floatval($row['saldo']);

        if ($saldo < $jumlah) {
            echo json_encode(['status' => 'error', 'message' => 'Saldo tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'Saldo cukup untuk penarikan.', 'email_status' => 'none']);
        $stmt->close();
    } elseif ($action === 'verify_pin') {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'PIN harus diisi', 'email_status' => 'none']);
            exit();
        }

        if (empty($user_id) || $user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID User tidak valid', 'email_status' => 'none']);
            exit();
        }

        $query = "SELECT pin, has_pin, pin_block_until, failed_pin_attempts FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Error preparing statement.', 'email_status' => 'none']);
            exit();
        }
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan', 'email_status' => 'none']);
            exit();
        }

        $user = $result->fetch_assoc();

        if ($user['pin_block_until'] && strtotime($user['pin_block_until']) > time()) {
            $block_time = date('d M Y H:i:s', strtotime($user['pin_block_until']));
            echo json_encode(['status' => 'error', 'message' => "PIN diblokir hingga $block_time. Hubungi admin untuk bantuan.", 'email_status' => 'none']);
            exit();
        }

        if (!empty($user['pin']) && !$user['has_pin']) {
            $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $user_id);
            $update_stmt->execute();
            $update_stmt->close();
            $user['has_pin'] = 1;
        }

        if (!$user['has_pin'] && empty($user['pin'])) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN', 'email_status' => 'none']);
            exit();
        }

        $hashed_pin = hash('sha256', $pin);
        
        if ($user['pin'] !== $hashed_pin) {
            $failed_attempts = $user['failed_pin_attempts'] + 1;
            $max_attempts = 3;
            $block_duration = 24 * 3600; // 24 hours in seconds

            $update_attempts_query = "UPDATE users SET failed_pin_attempts = ? WHERE id = ?";
            $update_attempts_stmt = $conn->prepare($update_attempts_query);
            if (!$update_attempts_stmt) {
                echo json_encode(['status' => 'error', 'message' => 'Error updating attempts.', 'email_status' => 'none']);
                exit();
            }
            $update_attempts_stmt->bind_param('ii', $failed_attempts, $user_id);
            $update_attempts_stmt->execute();
            $update_attempts_stmt->close();

            if ($failed_attempts >= $max_attempts) {
                $block_until = date('Y-m-d H:i:s', time() + $block_duration);
                $update_block_query = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                $update_block_stmt = $conn->prepare($update_block_query);
                if (!$update_block_stmt) {
                    echo json_encode(['status' => 'error', 'message' => 'Error blocking PIN.', 'email_status' => 'none']);
                    exit();
                }
                $update_block_stmt->bind_param('si', $block_until, $user_id);
                $update_block_stmt->execute();
                $update_block_stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Terlalu banyak percobaan PIN salah. PIN diblokir selama 24 jam.', 'email_status' => 'none']);
                exit();
            }

            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah', 'email_status' => 'none']);
            exit();
        }

        $reset_attempts_query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
        $reset_attempts_stmt = $conn->prepare($reset_attempts_query);
        if ($reset_attempts_stmt) {
            $reset_attempts_stmt->bind_param('i', $user_id);
            $reset_attempts_stmt->execute();
            $reset_attempts_stmt->close();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
        $stmt->close();
    } elseif ($action === 'tarik_tunai') {
        if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah < 1) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan minimal Rp 1.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah > 99999999) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan maksimal Rp 99.999.999.', 'email_status' => 'none']);
            exit();
        }
        if ($user_id <= 0) {
            $query_user = "SELECT user_id FROM rekening WHERE no_rekening = ?";
            $stmt_user = $conn->prepare($query_user);
            if (!$stmt_user) {
                echo json_encode(['status' => 'error', 'message' => 'Error fetching user ID.', 'email_status' => 'none']);
                exit();
            }
            $stmt_user->bind_param('s', $no_rekening);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();
            if ($result_user->num_rows == 0) {
                echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
                exit();
            }
            $row_user = $result_user->fetch_assoc();
            $user_id = intval($row_user['user_id']);
            $stmt_user->close();

            if ($user_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID user tidak valid.', 'email_status' => 'none']);
                exit();
            }
        }

        try {
            $conn->begin_transaction();

            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, u.is_frozen, 
                       j.nama_jurusan AS jurusan, 
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ?
            ";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Error preparing statement untuk cek rekening.');
            }
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
            $user_id_check = $row['user_id'];
            $email = $row['email'] ?? '';
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';
            $stmt->close();

            if ($user_id != $user_id_check) {
                throw new Exception('ID user tidak cocok dengan rekening.');
            }

            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk melakukan penarikan.');
            }

            $today = date('Y-m-d');

            // Hitung total setoran dan penarikan petugas hari ini
            $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total_setoran 
                              FROM transaksi 
                              WHERE petugas_id = ? 
                              AND jenis_transaksi = 'setor' 
                              AND status = 'approved' 
                              AND DATE(created_at) = ?";
            $stmt_setoran = $conn->prepare($query_setoran);
            if (!$stmt_setoran) {
                throw new Exception('Error preparing setoran query.');
            }
            $stmt_setoran->bind_param('is', $petugas_id, $today);
            $stmt_setoran->execute();
            $total_setoran = floatval($stmt_setoran->get_result()->fetch_assoc()['total_setoran']);
            $stmt_setoran->close();

            $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total_penarikan 
                               FROM transaksi 
                               WHERE petugas_id = ? 
                               AND jenis_transaksi = 'tarik' 
                               AND status = 'approved' 
                               AND DATE(created_at) = ?";
            $stmt_penarikan = $conn->prepare($query_penarikan);
            if (!$stmt_penarikan) {
                throw new Exception('Error preparing penarikan query.');
            }
            $stmt_penarikan->bind_param('is', $petugas_id, $today);
            $stmt_penarikan->execute();
            $total_penarikan = floatval($stmt_penarikan->get_result()->fetch_assoc()['total_penarikan']);
            $stmt_penarikan->close();

            $saldo_bersih = $total_setoran - $total_penarikan;

            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas petugas hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            // Generate unique ID Transaksi
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

            // Generate unique Nomor Referensi
            do {
                $date_prefix = date('ymd');
                $random_6digit = sprintf('%06d', mt_rand(100000, 999999));
                $no_transaksi = 'TRXTP' . $date_prefix . $random_6digit;
                
                $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param('s', $no_transaksi);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->num_rows > 0;
                $check_stmt->close();
            } while ($exists);

            // Insert transaksi dengan keterangan default "Tarik Saldo Tabungan"
            $query_transaksi = "INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, keterangan, created_at) 
                                VALUES (?, ?, ?, 'tarik', ?, ?, 'approved', 'Tarik Saldo Tabungan', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Error preparing transaksi statement.');
            }
            $stmt_transaksi->bind_param('ssidi', $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            // Update saldo
            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            if (!$stmt_update_saldo) {
                throw new Exception('Error preparing update saldo statement.');
            }
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo.');
            }
            $stmt_update_saldo->close();

            // Catat mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            if (!$stmt_mutasi) {
                throw new Exception('Error preparing mutasi statement.');
            }
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }
            $stmt_mutasi->close();

            // Simpan notifikasi
            $message = "Transaksi penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh petugas.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            if (!$stmt_notifikasi) {
                throw new Exception('Error preparing notifikasi statement.');
            }
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }
            $stmt_notifikasi->close();

            // Kirim email notifikasi
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Penarikan Tunai {$no_transaksi}";

                $bulan = [
                    'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
                    'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
                    'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
                ];
                $tanggal_transaksi = date('d M Y, H:i');
                foreach ($bulan as $en => $id_bulan) {
                    $tanggal_transaksi = str_replace($en, $id_bulan, $tanggal_transaksi);
                }

                $message_email = "
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
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>Tarik Saldo Tabungan</p>
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

                    <!-- Footer (tanpa background warna, lebih bersih) -->
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
                    $mail->Body = $message_email;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message_email));

                    if ($mail->send()) {
                        $email_status = 'success';
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

            $response_message = "Penarikan tunai berhasil diproses.";
            if ($email_status === 'failed') {
                $response_message = "Transaksi berhasil, tetapi gagal mengirim bukti transaksi ke email.";
            } elseif ($email_status === 'success') {
                $response_message = "Penarikan tunai berhasil diproses dan bukti transaksi telah dikirim ke email.";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_status' => $email_status,
                'id_transaksi' => $id_transaksi,
                'no_transaksi' => $no_transaksi,
                'saldo_baru' => number_format($saldo_baru, 0, ',', '.')
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'email_status' => 'none'
            ]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan.', 'email_status' => 'none']);
}

$conn->close();
?>