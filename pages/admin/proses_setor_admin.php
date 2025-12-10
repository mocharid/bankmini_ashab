<?php
/**
 * Proses Setor Admin - Adaptive Path Version
 * File: pages/admin/proses_setor_admin.php
 * 
 * Compatible with:
 * - Local: schobank/pages/admin/proses_setor_admin.php
 * - Hosting: public_html/pages/admin/proses_setor_admin.php
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
$current_dir  = dirname($current_file);
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
        'email_status' => 'none',
        'debug' => [
            'includes_path' => INCLUDES_PATH,
            'project_root'  => PROJECT_ROOT
        ]
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
        'email_status' => 'none',
        'debug' => [
            'vendor_path' => VENDOR_PATH,
            'project_root' => PROJECT_ROOT
        ]
    ]);
    exit;
}

require VENDOR_PATH . '/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
// AUTHORIZATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;
    $is_frozen_input = isset($_POST['is_frozen']) ? intval($_POST['is_frozen']) : 0;

    // Fungsi untuk memeriksa apakah akun sedang diblokir
    function is_account_blocked($pin_block_until) {
        return $pin_block_until && strtotime($pin_block_until) > time();
    }

    // Fungsi untuk memproses transaksi pending secara otomatis
    function process_pending_deposits($conn) {
        try {
            $conn->begin_transaction();
            
            $query_pending = "
                SELECT t.id as transaksi_id, t.no_transaksi, t.id_transaksi, t.rekening_id, t.jumlah, t.pending_reason,
                       u.id as user_id, u.nama, u.email, us.is_frozen, us.pin_block_until,
                       r.no_rekening, r.saldo as saldo_sekarang,
                       j.nama_jurusan AS jurusan,
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM transaksi t
                JOIN rekening r ON t.rekening_id = r.id
                JOIN users u ON r.user_id = u.id
                JOIN user_security us ON u.id = us.user_id
                JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE t.jenis_transaksi = 'setor' AND t.status = 'pending' AND t.petugas_id IS NULL
                AND (t.pending_reason = 'frozen' OR t.pending_reason = 'blocked')
            ";
            
            $stmt_pending = $conn->prepare($query_pending);
            if (!$stmt_pending) {
                throw new Exception('Gagal menyiapkan query pending: ' . $conn->error);
            }
            
            $stmt_pending->execute();
            $result_pending = $stmt_pending->get_result();
            $processed_count = 0;
            $failed_count = 0;
            
            while ($pending_row = $result_pending->fetch_assoc()) {
                $transaksi_id = intval($pending_row['transaksi_id']);
                $rekening_id = intval($pending_row['rekening_id']);
                $user_id = intval($pending_row['user_id']);
                $id_transaksi = $pending_row['id_transaksi'];
                $no_transaksi = $pending_row['no_transaksi'];
                $no_rekening = $pending_row['no_rekening'];
                $jumlah = floatval($pending_row['jumlah']);
                $saldo_sekarang = floatval($pending_row['saldo_sekarang']);
                $nama_nasabah = $pending_row['nama'];
                $email = $pending_row['email'];
                $jurusan = $pending_row['jurusan'] ?? '-';
                $kelas = $pending_row['kelas'] ?? '-';
                $is_frozen = intval($pending_row['is_frozen']);
                $pin_block_until = $pending_row['pin_block_until'];

                $is_blocked_now = is_account_blocked($pin_block_until);
                $can_process = true;

                if ($can_process) {
                    $lock_query = "SELECT saldo FROM rekening WHERE id = ? FOR UPDATE";
                    $lock_stmt = $conn->prepare($lock_query);
                    $lock_stmt->bind_param('i', $rekening_id);
                    $lock_stmt->execute();
                    $lock_result = $lock_stmt->get_result();
                    $saldo_sekarang = floatval($lock_result->fetch_assoc()['saldo']);
                    $lock_stmt->close();

                    $saldo_baru = $saldo_sekarang + $jumlah;
                    $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
                    $stmt_update = $conn->prepare($query_update_saldo);
                    if (!$stmt_update) {
                        throw new Exception('Gagal update saldo: ' . $conn->error);
                    }
                    $stmt_update->bind_param('di', $saldo_baru, $rekening_id);
                    if (!$stmt_update->execute()) {
                        $failed_count++;
                        error_log("Gagal update saldo untuk pending transaksi {$no_transaksi}: " . $stmt_update->error);
                        continue;
                    }

                    $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at)
                                     VALUES (?, ?, ?, ?, NOW())";
                    $stmt_mutasi = $conn->prepare($query_mutasi);
                    if (!$stmt_mutasi) {
                        throw new Exception('Gagal mutasi: ' . $conn->error);
                    }
                    $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
                    if (!$stmt_mutasi->execute()) {
                        $failed_count++;
                        error_log("Gagal insert mutasi untuk pending transaksi {$no_transaksi}: " . $stmt_mutasi->error);
                        continue;
                    }

                    $query_update_trans = "UPDATE transaksi SET status = 'approved', pending_reason = NULL, updated_at = NOW() WHERE id = ?";
                    $stmt_trans = $conn->prepare($query_update_trans);
                    if (!$stmt_trans) {
                        throw new Exception('Gagal update transaksi: ' . $conn->error);
                    }
                    $stmt_trans->bind_param('i', $transaksi_id);
                    if (!$stmt_trans->execute()) {
                        $failed_count++;
                        error_log("Gagal update status transaksi {$no_transaksi}: " . $stmt_trans->error);
                        continue;
                    }

                    $notif_message = "Transaksi penyetoran saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " yang sebelumnya pending kini telah diproses otomatis. Saldo Anda telah ditambahkan!";
                    $query_notif = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
                    $stmt_notif = $conn->prepare($query_notif);
                    if ($stmt_notif) {
                        $stmt_notif->bind_param('is', $user_id, $notif_message);
                        $stmt_notif->execute();
                        $stmt_notif->close();
                    }

                    // Kirim email dengan format baru
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $subject = "Bukti Transaksi Setor {$no_transaksi}";

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
    
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Bukti Transaksi Setor</h2>
    
    <p>Halo <strong>{$nama_nasabah}</strong>,</p>
    
    <p>Transaksi penyetoran saldo Anda yang sebelumnya pending kini telah diproses secara otomatis. Setoran dana sebesar <strong>Rp " . number_format($jumlah, 0, ',', '.') . "</strong> telah berhasil masuk ke rekening kamu, berikut rinciannya:</p>
    
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>

    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal</p>
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
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_nasabah}<br><span style='font-size:14px; color:#808080;'>Schobank • {$no_rekening}</span></p>
    </div>
    
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>Setor Tunai (Pending Diproses Otomatis)</p>
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

                        $mail = new PHPMailer(true);
                        try {
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
                            
                            $mail->isHTML(true);
                            $mail->Subject = $subject;
                            $mail->Body = $message_email;

                            // Plain text version
                            $labels = [
                                'Nama' => $nama_nasabah,
                                'Nominal' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                                'Biaya Admin' => 'Gratis',
                                'Total' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                                'Rekening Tujuan' => $no_rekening,
                                'Keterangan' => 'Setor Tunai (Pending Diproses Otomatis)',
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
                            
                            if (!$mail->send()) {
                                error_log("Gagal kirim email update untuk pending {$no_transaksi}: " . $mail->ErrorInfo);
                            }
                        } catch (Exception $e) {
                            error_log("Email error untuk pending {$no_transaksi}: " . $e->getMessage());
                        }
                        $mail->smtpClose();
                    }
                    
                    $processed_count++;
                    error_log("Pending transaksi {$no_transaksi} diproses otomatis: jumlah={$jumlah}, user_id={$user_id}");
                }
            }
            
            $stmt_pending->close();
            $conn->commit();
            
            return [
                'status' => 'success',
                'message' => "Diproses otomatis: {$processed_count} transaksi pending berhasil diproses, {$failed_count} gagal.",
                'processed' => $processed_count,
                'failed' => $failed_count
            ];
            
        } catch (Exception $e) {
            if ($conn->in_transaction()) {
                $conn->rollback();
            }
            error_log("Error processing pending deposits: " . $e->getMessage());
            return ['status' => 'error', 'message' => 'Gagal memproses transaksi pending: ' . $e->getMessage()];
        }
    }

    if ($action === 'process_pending') {
        echo json_encode(process_pending_deposits($conn));
        $conn->close();
        exit();
    }

    if ($action === 'cek_rekening') {
        try {
            $query = "
                SELECT r.no_rekening, u.nama, u.email, u.id as user_id, r.id as rekening_id, r.saldo,
                       j.nama_jurusan AS jurusan,
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas,
                       us.is_frozen, us.pin_block_until
                FROM rekening r
                JOIN users u ON r.user_id = u.id
                JOIN user_security us ON u.id = us.user_id
                JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan kueri: ' . $conn->error);
            }
            
            $stmt->bind_param('s', $no_rekening);
            if (!$stmt->execute()) {
                throw new Exception('Gagal eksekusi kueri: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            if ($result->num_rows == 0) {
                echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
            } else {
                $row = $result->fetch_assoc();
                $is_blocked = is_account_blocked($row['pin_block_until']);
                $is_frozen = intval($row['is_frozen']) || $is_blocked;
                
                echo json_encode([
                    'status' => 'success',
                    'no_rekening' => $row['no_rekening'],
                    'nama' => $row['nama'],
                    'jurusan' => $row['jurusan'] ?: '-',
                    'kelas' => $row['kelas'] ?: '-',
                    'email' => $row['email'],
                    'user_id' => $row['user_id'],
                    'rekening_id' => $row['rekening_id'],
                    'saldo' => floatval($row['saldo']),
                    'is_frozen' => $is_frozen ? 1 : 0,
                    'is_blocked' => $is_blocked ? 1 : 0,
                    'pin_block_until' => $row['pin_block_until'],
                    'email_status' => 'none'
                ]);
            }
            
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error di cek_rekening: " . $e->getMessage() . " | No Rekening: $no_rekening");
            echo json_encode(['status' => 'error', 'message' => 'Error memeriksa rekening: ' . $e->getMessage(), 'email_status' => 'none']);
        }
    } elseif ($action === 'setor_saldo') {
        try {
            $auto_process = process_pending_deposits($conn);
            if ($auto_process['status'] === 'error') {
                error_log("Auto-process pending gagal sebelum setor_saldo: " . $auto_process['message']);
            }

            $conn->begin_transaction();

            if (empty($no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            
            if ($jumlah <= 0 || $jumlah < 1) {
                throw new Exception('Jumlah penyetoran minimal Rp 1 dan harus lebih dari 0.');
            }
            
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penyetoran melebihi batas maksimum Rp 99.999.999,99.');
            }

            $query = "
                SELECT r.id, r.user_id, r.saldo, r.no_rekening, u.nama, u.email,
                       j.nama_jurusan AS jurusan,
                       CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas,
                       us.is_frozen, us.pin_block_until
                FROM rekening r
                JOIN users u ON r.user_id = u.id
                JOIN user_security us ON u.id = us.user_id
                JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
                FOR UPDATE
            ";
            
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan kueri rekening: ' . $conn->error);
            }
            
            $stmt->bind_param('s', $no_rekening);
            if (!$stmt->execute()) {
                throw new Exception('Gagal eksekusi kueri rekening: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }
            
            $row = $result->fetch_assoc();
            $is_blocked = is_account_blocked($row['pin_block_until']);
            $is_frozen = intval($row['is_frozen']) || $is_blocked;
            
            $rekening_id = intval($row['id']);
            $saldo_sekarang = floatval($row['saldo']);
            $nama_nasabah = $row['nama'];
            $user_id = intval($row['user_id']);
            $email = $row['email'];
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';
            $no_rekening_full = $row['no_rekening'];
            $stmt->close();

            if ($rekening_id <= 0) {
                throw new Exception('ID rekening tidak valid.');
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
                $no_transaksi = 'TRXSA' . $date_prefix . $random_6digit;
                
                $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param('s', $no_transaksi);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->num_rows > 0;
                $check_stmt->close();
            } while ($exists);

            $petugas_id = null;
            $jumlah = round(floatval($jumlah), 2);
            $status_transaksi = 'approved';
            $pending_reason = null;
            $keterangan = 'Setor Tunai';

            $query_transaksi = "INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, pending_reason, keterangan, created_at)
                              VALUES (?, ?, ?, 'setor', ?, ?, ?, ?, ?, NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Gagal menyiapkan statement transaksi: ' . $conn->error);
            }
            $stmt_transaksi->bind_param('ssidisss', $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $petugas_id, $status_transaksi, $pending_reason, $keterangan);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi: ' . $stmt_transaksi->error);
            }
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            $saldo_baru = $saldo_sekarang + $jumlah;
            
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            if (!$stmt_update_saldo) {
                throw new Exception('Gagal menyiapkan update saldo: ' . $conn->error);
            }
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo siswa: ' . $stmt_update_saldo->error);
            }
            $stmt_update_saldo->close();

            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at)
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            if (!$stmt_mutasi) {
                throw new Exception('Gagal menyiapkan mutasi: ' . $conn->error);
            }
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi: ' . $stmt_mutasi->error);
            }
            $stmt_mutasi->close();

            $notif_message = "Asikkk! Transaksi penyetoran saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh admin. Cek saldo Mu jangan lupa ya!";
            
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            if (!$stmt_notifikasi) {
                error_log("Gagal menyiapkan notifikasi: " . $conn->error);
            } else {
                $stmt_notifikasi->bind_param('is', $user_id, $notif_message);
                if (!$stmt_notifikasi->execute()) {
                    error_log("Gagal menyimpan notifikasi untuk user_id: $user_id, no_transaksi: $no_transaksi, error: " . $stmt_notifikasi->error);
                }
                $stmt_notifikasi->close();
            }

            // Kirim email dengan format baru
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Transaksi Setor {$no_transaksi}";

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
    
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Bukti Transaksi Setor</h2>
    
    <p>Halo <strong>{$nama_nasabah}</strong>,</p>
    
    <p>Setoran dana sebesar <strong>Rp " . number_format($jumlah, 0, ',', '.') . "</strong> telah berhasil masuk ke rekening kamu, berikut rinciannya:</p>
    
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>

    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal</p>
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
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$nama_nasabah}<br><span style='font-size:14px; color:#808080;'>Schobank • {$no_rekening_full}</span></p>
    </div>
    
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>Setor Tunai</p>
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
                    $mail->addCustomHeader('X-Mailer', 'Schobank-System-v1.0');
                    $mail->addCustomHeader('X-Priority', '1');
                    $mail->addCustomHeader('Importance', 'High');
                    
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message_email;

                    // Plain text version
                    $labels = [
                        'Nama' => $nama_nasabah,
                        'Nominal' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                        'Biaya Admin' => 'Gratis',
                        'Total' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                        'Rekening Tujuan' => $no_rekening_full,
                        'Keterangan' => 'Setor Tunai',
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
                        $email_status = 'sent';
                    } else {
                        throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
                    }
                   
                    $mail->smtpClose();
                   
                } catch (Exception $e) {
                    error_log("Mail error for transaction {$no_transaksi}: " . $e->getMessage());
                    $email_status = 'failed';
                }
            }

            $conn->commit();

            error_log("Penyetoran berhasil: no_transaksi=$no_transaksi, jumlah=$jumlah, no_rekening=$no_rekening_full, petugas_id=NULL, status=$status_transaksi, email_status=$email_status");

            $response_message = "Transaksi penyetoran saldo untuk {$nama_nasabah} telah berhasil diproses.";
            
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
                    'no_rekening' => $no_rekening_full,
                    'nama_nasabah' => $nama_nasabah,
                    'jumlah' => number_format($jumlah, 0, ',', '.'),
                    'saldo_sebelum' => number_format($saldo_sekarang, 0, ',', '.'),
                    'saldo_sesudah' => number_format($saldo_baru, 0, ',', '.'),
                    'status_transaksi' => $status_transaksi,
                    'tanggal' => date('d M Y H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            if ($conn->in_transaction()) {
                $conn->rollback();
            }
            $error_message = $e->getMessage();
            error_log("Penyetoran gagal: no_rekening=$no_rekening, jumlah=$jumlah, error: $error_message");
            echo json_encode(['status' => 'error', 'message' => $error_message, 'email_status' => 'none']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.', 'email_status' => 'none']);
}

$conn->close();
?>
