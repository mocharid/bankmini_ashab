<?php
/**
 * Proses Tarik - Adaptive Path Version
 * File: proses_tarik.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_tarik.php or similar
 * - Hosting: public_html/pages/petugas/proses_tarik.php or similar
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
// Fallback: pakai current dir
if (!$project_root) {
    $project_root = $current_dir;
}
// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
// ============================================
// SESSION & OUTPUT BUFFER
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!ob_get_level()) {
    ob_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
header('Content-Type: application/json; charset=UTF-8');
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    echo json_encode([
        'status' => 'error',
        'message' => 'File db_connection.php tidak ditemukan.',
        'email_status' => 'none',
        'debug' => [
            'includes_path' => INCLUDES_PATH,
            'project_root' => PROJECT_ROOT
        ]
    ]);
    exit();
}
require_once INCLUDES_PATH . '/db_connection.php';
if (!file_exists(VENDOR_PATH . '/autoload.php')) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Composer autoloader tidak ditemukan.',
        'email_status' => 'none',
        'debug' => [
            'vendor_path' => VENDOR_PATH,
            'project_root' => PROJECT_ROOT
        ]
    ]);
    exit();
}
require VENDOR_PATH . '/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// ============================================
// AUTH CHECK
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}
// Validasi keberadaan petugas1 dan petugas2 di shift hari ini (petugas_tugas → petugas_shift)
$query_schedule = "
    SELECT u1.nama AS petugas1_nama, u2.nama AS petugas2_nama
    FROM petugas_shift ps
    LEFT JOIN users u1 ON ps.petugas1_id = u1.id
    LEFT JOIN users u2 ON ps.petugas2_id = u2.id
    WHERE ps.tanggal = CURDATE()
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
// ============================================
// REQUEST METHOD CHECK
// ============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.', 'email_status' => 'none']);
    exit();
}
// ============================================
// INPUTS
// ============================================
$action = $_POST['action'] ?? '';
$no_rekening = trim($_POST['no_rekening'] ?? '');
$jumlah = floatval($_POST['jumlah'] ?? 0);
$user_id = intval($_POST['user_id'] ?? 0);
$pin = $_POST['pin'] ?? '';
$petugas_id = $_SESSION['user_id'];
// ============================================
// HELPER
// ============================================
function is_account_blocked($pin_block_until) {
    return $pin_block_until && strtotime($pin_block_until) > time();
}
try {
    switch ($action) {
        // ==========================
        // CEK REKENING
        // ==========================
        case 'cek_rekening':
            // Validasi format no_rekening: 8 digit angka
            if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
                echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
                exit();
            }
            // Query diperbaiki untuk struktur DB baru
            $query = "
                SELECT r.no_rekening, u.nama, u.email, u.id as user_id, r.id as rekening_id, r.saldo,
                       us.has_pin, us.pin, us.pin_block_until, COALESCE(us.is_frozen, 0) as is_frozen,
                       j.nama_jurusan AS jurusan,
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM rekening r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN user_security us ON u.id = us.user_id
                LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
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
            break;
        // ==========================
        // CHECK BALANCE
        // ==========================
        case 'check_balance':
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
            break;
        // ==========================
        // VERIFY PIN
        // ==========================
        case 'verify_pin':
            if (empty($pin)) {
                echo json_encode(['status' => 'error', 'message' => 'PIN harus diisi', 'email_status' => 'none']);
                exit();
            }
            if (empty($user_id) || $user_id <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'ID User tidak valid', 'email_status' => 'none']);
                exit();
            }
            // Query diperbaiki untuk user_security
            $query = "SELECT us.pin, us.has_pin, us.pin_block_until, us.failed_pin_attempts FROM user_security us WHERE us.user_id = ?";
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
            if (!$user['has_pin'] && empty($user['pin'])) {
                echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN', 'email_status' => 'none']);
                exit();
            }
            // Ubah verifikasi PIN menjadi BCRYPT seperti di login
            if (!password_verify($pin, $user['pin'])) {
                $failed_attempts = $user['failed_pin_attempts'] + 1;
                $max_attempts = 3;
                $block_duration = 24 * 3600; // 24 hours
                $update_attempts_query = "UPDATE user_security SET failed_pin_attempts = ? WHERE user_id = ?";
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
                    $update_block_query = "UPDATE user_security SET pin_block_until = ?, failed_pin_attempts = 0 WHERE user_id = ?";
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
            $reset_attempts_query = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
            $reset_attempts_stmt = $conn->prepare($reset_attempts_query);
            if ($reset_attempts_stmt) {
                $reset_attempts_stmt->bind_param('i', $user_id);
                $reset_attempts_stmt->execute();
                $reset_attempts_stmt->close();
            }
            echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
            $stmt->close();
            break;
        // ==========================
        // TARIK TUNAI
        // ==========================
        case 'tarik_tunai':
            $conn->begin_transaction();
            if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
                echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
                $conn->rollback();
                exit();
            }
            if ($jumlah < 1) {
                echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan minimal Rp 1.', 'email_status' => 'none']);
                $conn->rollback();
                exit();
            }
            if ($jumlah > 99999999) {
                echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan maksimal Rp 99.999.999.', 'email_status' => 'none']);
                $conn->rollback();
                exit();
            }
            if ($user_id <= 0) {
                $query_user = "SELECT user_id FROM rekening WHERE no_rekening = ?";
                $stmt_user = $conn->prepare($query_user);
                if (!$stmt_user) {
                    echo json_encode(['status' => 'error', 'message' => 'Error fetching user ID.', 'email_status' => 'none']);
                    $conn->rollback();
                    exit();
                }
                $stmt_user->bind_param('s', $no_rekening);
                $stmt_user->execute();
                $result_user = $stmt_user->get_result();
                if ($result_user->num_rows == 0) {
                    echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
                    $conn->rollback();
                    exit();
                }
                $row_user = $result_user->fetch_assoc();
                $user_id = intval($row_user['user_id']);
                $stmt_user->close();
                if ($user_id <= 0) {
                    echo json_encode(['status' => 'error', 'message' => 'ID user tidak valid.', 'email_status' => 'none']);
                    $conn->rollback();
                    exit();
                }
            }
            // Query diperbaiki untuk struktur DB baru
            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, COALESCE(us.is_frozen, 0) as is_frozen,
                       j.nama_jurusan AS jurusan,
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM rekening r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN user_security us ON u.id = us.user_id
                LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
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
            if ($row['is_frozen']) {
                throw new Exception('Akun sedang dibekukan. Tidak dapat melakukan tarik tunai.');
            }
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
            // Insert transaksi
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
                $subject = "Bukti Transaksi Penarikan {$no_transaksi}";
                // Konversi bulan ke bahasa Indonesia
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
<div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
   
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Bukti Transaksi Penarikan</h2>
   
    <p>Halo <strong>{$nama_nasabah}</strong>,</p>
   
    <p>Penarikan dana sebesar <strong>Rp " . number_format($jumlah, 0, ',', '.') . "</strong> telah berhasil diproses dari rekening kamu, berikut rinciannya:</p>
   
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Penarikan</p>
        <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp " . number_format($jumlah, 0, ',', '.') . "</p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Biaya Admin</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#16a34a;'>Gratis</p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Total</p>
        <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp " . number_format($jumlah, 0, ',', '.') . "</p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Sumber</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_nasabah}<br><span style='font-size:14px; color:#808080;'>Schobank • {$no_rekening}</span></p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>Tarik Saldo Tabungan</p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal & Waktu</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
    </div>
   
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
        <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
    </div>
   
    <div style='margin-bottom:0;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
        <p style='margin:0; font-size:16px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
    </div>
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
    <p style='font-size: 12px; color: #999;'>
        Ini adalah pesan otomatis dari sistem Schobank Student Digital Banking.<br>
        Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
    </p>
</div>";
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
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message_email;
                   
                    // Plain text version
                    $labels = [
                        'Nama' => $nama_nasabah,
                        'Nominal Penarikan' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                        'Biaya Admin' => 'Gratis',
                        'Total' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                        'Rekening Sumber' => $no_rekening,
                        'Keterangan' => 'Tarik Saldo Tabungan',
                        'Tanggal & Waktu' => $tanggal_transaksi . ' WIB',
                        'Nomor Referensi' => $no_transaksi,
                        'ID Transaksi' => $id_transaksi
                    ];
                    $max_label_length = max(array_map('strlen', array_keys($labels)));
                    $max_label_length = max($max_label_length, 20);
                    $text_rows = [];
                    foreach ($labels as $label => $value) {
                        $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
                    }
                    $text_rows[] = "\nHormat kami,\nTim Schobank Student Digital Banking";
                    $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
                    $mail->AltBody = implode("\n", $text_rows);
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
            break;
        // ==========================
        // DEFAULT
        // ==========================
        default:
            echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
            exit();
    }
} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction()) {
        $conn->rollback();
    }
    error_log("Error in proses_tarik.php: {$e->getMessage()} | Action: $action | No Rekening: $no_rekening | User ID: $user_id");
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'email_status' => 'none'
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    if (ob_get_level()) {
        ob_end_flush();
    }
}
?>