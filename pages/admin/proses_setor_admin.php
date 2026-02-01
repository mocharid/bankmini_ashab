<?php
/**
 * Proses Setor Admin
 * File: pages/admin/proses_setor_admin.php
 * 
 * Modified from pages/petugas/proses_setor.php
 * - Allows ADMIN role
 * - Bypasses Shift Schedule check
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

// Strategy 1: jika di folder 'pages' atau 'admin'
if (basename($current_dir) === 'admin') {
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
// SESSION START
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'File db_connection.php tidak ditemukan.',
        'email_status' => 'none'
    ]);
    exit;
}

require_once INCLUDES_PATH . '/db_connection.php';

// Load Composer autoloader
if (!file_exists(VENDOR_PATH . '/autoload.php')) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Composer autoloader tidak ditemukan.',
        'email_status' => 'none'
    ]);
    exit;
}

require VENDOR_PATH . '/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
// AUTHORIZATION CHECK (ADMIN ONLY)
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Hanya Admin.', 'email_status' => 'none']);
    exit();
}

// NOTE: Admin does NOT need Shift Schedule check. Bypassed.

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);
    // For Admin, we use session user_id as 'petugas_id' for tracking who processed it (or leave as Admin)
    $petugas_id = $_SESSION['user_id'];

    if ($action === 'cek_rekening' || $action === 'check_account') {
        // Validasi format no_rekening: 8 digit angka
        if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid. Harus 8 digit angka.', 'email_status' => 'none']);
            exit();
        }

        $query = "
            SELECT 
                r.no_rekening, 
                u.nama, 
                u.email, 
                u.id AS user_id, 
                r.id AS rekening_id, 
                j.nama_jurusan AS jurusan, 
                CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas,
                COALESCE(us.is_frozen, 0) AS is_frozen
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
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'no_rekening' => $row['no_rekening'],
                    'nama_lengkap' => $row['nama'], // JS uses this often
                    'nama' => $row['nama'], // Fallback
                    'jurusan' => $row['jurusan'] ?: '-',
                    'kelas' => $row['kelas'] ?: '-',
                    'status_akun' => ($row['is_frozen'] == 1) ? 'frozen' : 'active'
                ]
            ]);
        }
        $stmt->close();

    } elseif ($action === 'process_deposit' || $action === 'setor_saldo') { // Handle both action names
        // Validasi format no_rekening
        if (empty($no_rekening) || strlen($no_rekening) !== 8 || !preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Format nomor rekening tidak valid.', 'email_status' => 'none']);
            exit();
        }

        if ($jumlah < 1) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah setoran minimal Rp 1.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah > 99999999) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah setoran maksimal Rp 99.999.999.', 'email_status' => 'none']);
            exit();
        }

        try {
            $conn->begin_transaction();

            // Cek Rekening & Status
            $query = "
                SELECT 
                    r.id, 
                    r.user_id, 
                    r.saldo, 
                    u.nama, 
                    u.email, 
                    COALESCE(us.is_frozen, 0) AS is_frozen
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN user_security us ON u.id = us.user_id
                WHERE r.no_rekening = ?
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan.');
            }

            $rekening_id = $row['id'];
            $saldo_sekarang = $row['saldo'];
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id'];
            $email = $row['email'] ?? '';
            $stmt->close();

            // Generate IDs
            do {
                $id_transaksi = date('ymd') . sprintf('%08d', mt_rand(10000000, 99999999));
                $check = $conn->query("SELECT id FROM transaksi WHERE id_transaksi = '$id_transaksi'");
            } while ($check->num_rows > 0);

            do {
                $no_transaksi = 'TRXSA' . date('ymd') . sprintf('%06d', mt_rand(100000, 999999));
                $check = $conn->query("SELECT id FROM transaksi WHERE no_transaksi = '$no_transaksi'");
            } while ($check->num_rows > 0);

            // Insert Transaksi
            $query_transaksi = "
                INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, keterangan, created_at)
                VALUES (?, ?, ?, 'setor', ?, ?, 'approved', 'Setoran Tabungan (Admin)', NOW())
            ";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('ssidi', $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi: ' . $stmt_transaksi->error);
            }
            // Capture insert_id for mutasi relation if needed, though mutasi uses transaksi_id (int) or id_transaksi (string)? 
            // In petugas code: INSERT INTO mutasi (transaksi_id, ...) VALUES (?, ...)
            // Table structure usually links mutasi.transaksi_id to transaksi.id (autoinc).
            $transaksi_db_id = $conn->insert_id;
            $stmt_transaksi->close();

            // Update Saldo
            $saldo_baru = $saldo_sekarang + $jumlah;
            $query_update = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);
            $stmt_update->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update->execute()) {
                throw new Exception('Gagal memperbarui saldo.');
            }
            $stmt_update->close();

            // Insert Mutasi
            $query_mutasi = "
                INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at)
                VALUES (?, ?, ?, ?, NOW()) 
            ";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_db_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }
            $stmt_mutasi->close();

            // Insert Notifikasi
            $message_notif = "Transaksi setor tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh Admin.";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            $stmt_notif->bind_param('is', $user_id, $message_notif);
            $stmt_notif->execute();
            $stmt_notif->close();

            // Email Logic (Simplified for Admin, same as Petugas)
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Reuse standard PHPMailer logic
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'mail.kasdig.web.id';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'noreply@kasdig.web.id';
                    $mail->Password = 'BtRjT4wP8qeTL5M';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port = 465;
                    $mail->Timeout = 30;
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('noreply@kasdig.web.id', 'KASDIG');
                    $mail->addAddress($email, $nama_nasabah);

                    $mail->isHTML(true);
                    $mail->Subject = "Bukti Transaksi Setor {$no_transaksi}";
                    $mail->Body = "
                        <h3>Bukti Transaksi Setor</h3>
                        <p>Halo {$nama_nasabah},</p>
                        <p>Setoran dana sebesar <b>Rp " . number_format($jumlah, 0, ',', '.') . "</b> berhasil.</p>
                        <p>No Referensi: <b>{$no_transaksi}</b></p>
                        <p>Terima kasih.</p>
                    ";

                    $mail->send();
                    $email_status = 'success';
                } catch (Exception $e) {
                    // Log error but don't fail transaction
                    error_log("Email error: " . $e->getMessage());
                    $email_status = 'failed';
                }
            }

            $conn->commit();

            echo json_encode([
                'status' => 'success',
                'message' => 'Setoran berhasil diproses.',
                'saldo_baru' => number_format($saldo_baru, 0, ',', '.'),
                'id_transaksi' => $id_transaksi,
                'no_transaksi' => $no_transaksi,
                'email_status' => $email_status
            ]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'email_status' => 'none']);
            exit;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.']);
}
?>