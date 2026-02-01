<?php
/**
 * Proses Transfer - Adaptive Path Version
 * File: pages/petugas/proses_transfer.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_transfer.php
 * - Hosting: public_html/pages/petugas/proses_transfer.php
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
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/db_connection.php';
require VENDOR_PATH . '/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');

    if ($_POST['ajax_action'] == 'search_account') {
        $search = $_POST['search'] ?? '';
        $stmt = $conn->prepare("
            SELECT r.no_rekening, r.id, u.nama, r.saldo,
                   j.nama_jurusan AS jurusan, 
                   CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
            LEFT JOIN jurusan j ON sp.jurusan_id = j.id
            LEFT JOIN kelas k ON sp.kelas_id = k.id
            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
            WHERE r.no_rekening LIKE ? OR u.nama LIKE ?
            LIMIT 10
        ");
        $searchTerm = "%$search%";
        $stmt->bind_param("ss", $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'accounts' => $accounts]);
        exit;
    }

    if ($_POST['ajax_action'] == 'check_balance') {
        sleep(2);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $jumlah = preg_replace('/[^0-9]/', '', $_POST['jumlah'] ?? '0');

        $stmt = $conn->prepare("
            SELECT r.saldo, COALESCE(us.is_frozen, 0) as is_frozen, us.pin_block_until
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN user_security us ON u.id = us.user_id
            WHERE r.no_rekening = ?
        ");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rek_asal) {
            echo json_encode(['success' => false, 'message' => 'Rekening asal tidak ditemukan']);
            exit;
        }

        $is_blocked = $rek_asal['pin_block_until'] && strtotime($rek_asal['pin_block_until']) > time();
        $is_frozen = (int) $rek_asal['is_frozen'];

        if ($is_frozen || $is_blocked) {
            echo json_encode(['success' => false, 'message' => "Gagal\nrekening pengirim sedang di bekukan/di blokir sementara."]);
            exit;
        }

        if ($rek_asal['saldo'] < $jumlah) {
            echo json_encode(['success' => false, 'message' => 'Saldo tidak mencukupi']);
        } else {
            echo json_encode(['success' => true, 'saldo' => $rek_asal['saldo']]);
        }
        exit;
    }

    if ($_POST['ajax_action'] == 'validate_pin') {
        sleep(3);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $pin = $_POST['pin'] ?? '';

        $stmt = $conn->prepare("
            SELECT us.user_id, us.pin, us.has_pin, us.pin_block_until, us.failed_pin_attempts
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            JOIN user_security us ON u.id = us.user_id
            WHERE r.no_rekening = ?
        ");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan']);
            exit;
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        if ($user['pin_block_until'] && strtotime($user['pin_block_until']) > time()) {
            $block_time = date('d M Y H:i:s', strtotime($user['pin_block_until']));
            echo json_encode(['success' => false, 'message' => "PIN diblokir hingga $block_time. Hubungi admin untuk bantuan."]);
            exit;
        }

        if (!$user['has_pin'] && empty($user['pin'])) {
            echo json_encode(['success' => false, 'message' => 'User belum mengatur PIN']);
            exit;
        }

        if (!password_verify($pin, $user['pin'])) {
            $failed_attempts = $user['failed_pin_attempts'] + 1;
            $max_attempts = 5;
            $block_duration = 24 * 3600; // 24 hours

            $update_attempts_query = "UPDATE user_security SET failed_pin_attempts = ? WHERE user_id = ?";
            $update_attempts_stmt = $conn->prepare($update_attempts_query);
            $update_attempts_stmt->bind_param('ii', $failed_attempts, $user['user_id']);
            $update_attempts_stmt->execute();
            $update_attempts_stmt->close();

            if ($failed_attempts >= $max_attempts) {
                $block_until = date('Y-m-d H:i:s', time() + $block_duration);
                $update_block_query = "UPDATE user_security SET pin_block_until = ?, failed_pin_attempts = 0 WHERE user_id = ?";
                $update_block_stmt = $conn->prepare($update_block_query);
                $update_block_stmt->bind_param('si', $block_until, $user['user_id']);
                $update_block_stmt->execute();
                $update_block_stmt->close();
                echo json_encode(['success' => false, 'message' => 'PIN salah sebanyak 5 kali. Akun diblokir selama 24 jam.']);
                exit;
            }

            $remaining = $max_attempts - $failed_attempts;
            echo json_encode(['success' => false, 'message' => 'PIN salah. Sisa percobaan: ' . $remaining . '.']);
            exit;
        }

        $reset_attempts_query = "UPDATE user_security SET failed_pin_attempts = 0, pin_block_until = NULL WHERE user_id = ?";
        $reset_attempts_stmt = $conn->prepare($reset_attempts_query);
        $reset_attempts_stmt->bind_param('i', $user['user_id']);
        $reset_attempts_stmt->execute();
        $reset_attempts_stmt->close();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['ajax_action'] == 'process_transfer') {
        sleep(2);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $rekening_tujuan = $_POST['rekening_tujuan'] ?? '';
        $jumlah = preg_replace('/[^0-9]/', '', $_POST['jumlah'] ?? '0');
        $keterangan = trim($_POST['keterangan'] ?? '');

        // Validasi petugas shift hari ini
        $today = date('Y-m-d');
        $stmt_shift = $conn->prepare("
            SELECT u1.nama AS petugas1_nama, u2.nama AS petugas2_nama
            FROM petugas_shift ps
            LEFT JOIN users u1 ON ps.petugas1_id = u1.id
            LEFT JOIN users u2 ON ps.petugas2_id = u2.id
            WHERE ps.tanggal = ?
        ");
        $stmt_shift->bind_param("s", $today);
        $stmt_shift->execute();
        $petugas_tugas = $stmt_shift->get_result()->fetch_assoc();
        $stmt_shift->close();

        if (!$petugas_tugas || empty($petugas_tugas['petugas1_nama']) || empty($petugas_tugas['petugas2_nama'])) {
            echo json_encode(['success' => false, 'message' => 'Tidak ada petugas yang bertugas hari ini']);
            exit;
        }

        // Ambil data rekening asal
        $stmt = $conn->prepare("
            SELECT r.*, u.nama, u.email, COALESCE(us.is_frozen, 0) as is_frozen, us.pin_block_until,
                   j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN user_security us ON u.id = us.user_id
            LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
            LEFT JOIN jurusan j ON sp.jurusan_id = j.id
            LEFT JOIN kelas k ON sp.kelas_id = k.id
            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
            WHERE r.no_rekening = ?
        ");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Ambil data rekening tujuan
        $stmt = $conn->prepare("
            SELECT r.*, u.nama, u.email,
                   j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
            FROM rekening r
            JOIN users u ON r.user_id = u.id
            LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
            LEFT JOIN jurusan j ON sp.jurusan_id = j.id
            LEFT JOIN kelas k ON sp.kelas_id = k.id
            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
            WHERE r.no_rekening = ?
        ");
        $stmt->bind_param("s", $rekening_tujuan);
        $stmt->execute();
        $rek_tujuan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$rek_asal || !$rek_tujuan) {
            echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan']);
            exit;
        }

        $is_blocked = $rek_asal['pin_block_until'] && strtotime($rek_asal['pin_block_until']) > time();
        $is_frozen = (int) $rek_asal['is_frozen'];

        if ($is_frozen || $is_blocked) {
            echo json_encode(['success' => false, 'message' => "Gagal\nrekening pengirim sedang di bekukan/di blokir sementara."]);
            exit;
        }

        if ($rek_asal['saldo'] < $jumlah) {
            echo json_encode(['success' => false, 'message' => 'Saldo tidak mencukupi']);
            exit;
        }

        // PIN sudah divalidasi di step sebelumnya (validate_pin)

        $conn->begin_transaction();
        try {
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
                $no_transaksi = 'TRXTFP' . $date_prefix . $random_6digit;

                $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param('s', $no_transaksi);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->num_rows > 0;
                $check_stmt->close();
            } while ($exists);

            // Insert transaksi
            $stmt = $conn->prepare("
                INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, keterangan, petugas_id, status, created_at)
                VALUES (?, ?, ?, ?, 'transfer', ?, ?, ?, 'approved', NOW())
            ");
            $stmt->bind_param("ssiidsi", $id_transaksi, $no_transaksi, $rek_asal['id'], $rek_tujuan['id'], $jumlah, $keterangan, $_SESSION['user_id']);
            $stmt->execute();
            $transaksi_id = $conn->insert_id;
            $stmt->close();

            // Update saldo asal
            $saldo_asal_baru = $rek_asal['saldo'] - $jumlah;
            $stmt = $conn->prepare("UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
            $stmt->execute();
            $stmt->close();

            // Update saldo tujuan
            $saldo_tujuan_baru = $rek_tujuan['saldo'] + $jumlah;
            $stmt = $conn->prepare("UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
            $stmt->execute();
            $stmt->close();

            // Catat mutasi asal (keluar)
            $stmt = $conn->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())");
            $jumlah_negative = -$jumlah;
            $stmt->bind_param("iidd", $transaksi_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
            $stmt->execute();
            $stmt->close();

            // Catat mutasi tujuan (masuk)
            $stmt = $conn->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("iidd", $transaksi_id, $rek_tujuan['id'], $jumlah, $saldo_tujuan_baru);
            $stmt->execute();
            $stmt->close();

            // Notifikasi pengirim
            $ket_part = $keterangan ? " - $keterangan" : '';
            $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rek_tujuan['no_rekening'] . "$ket_part BERHASIL dilakukan. Saldo baru: Rp " . number_format($saldo_asal_baru, 0, ',', '.');
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('is', $rek_asal['user_id'], $message_pengirim);
            $stmt->execute();
            $stmt->close();

            // Notifikasi penerima
            $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $rek_asal['no_rekening'] . "$ket_part. Saldo baru: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.');
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param('is', $rek_tujuan['user_id'], $message_penerima);
            $stmt->execute();
            $stmt->close();

            // Kirim email
            sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $id_transaksi, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas, $keterangan, $conn);

            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Transfer berhasil']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Transfer gagal: ' . $e->getMessage()]);
        }
        exit;
    }
}
function sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $id_transaksi, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas, $keterangan, $conn)
{
    $mail = new PHPMailer(true);

    // Konversi bulan ke bahasa Indonesia
    $bulan = [
        'Jan' => 'Januari',
        'Feb' => 'Februari',
        'Mar' => 'Maret',
        'Apr' => 'April',
        'May' => 'Mei',
        'Jun' => 'Juni',
        'Jul' => 'Juli',
        'Aug' => 'Agustus',
        'Sep' => 'September',
        'Oct' => 'Oktober',
        'Nov' => 'November',
        'Dec' => 'Desember'
    ];
    $tanggal_transaksi = date('d M Y, H:i');
    foreach ($bulan as $en => $id) {
        $tanggal_transaksi = str_replace($en, $id, $tanggal_transaksi);
    }

    try {
        $mail->isSMTP();
        $mail->Host = 'mail.kasdig.web.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@kasdig.web.id';
        $mail->Password = 'BtRjT4wP8qeTL5M';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';

        // Email untuk pengirim (jika ada email)
        if (!empty($rek_asal['email']) && filter_var($rek_asal['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->clearReplyTos();

            $mail->setFrom('noreply@schobank.web.id', 'KASDIG');
            $mail->addAddress($rek_asal['email'], $rek_asal['nama']);
            $mail->addReplyTo('noreply@schobank.web.id', 'No Reply');
            $unique_id = uniqid('kasdig_', true) . '@kasdig.web.id';
            $mail->MessageID = '<' . $unique_id . '>';
            $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
            $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
            $mail->isHTML(true);
            $mail->Subject = "Bukti Transfer Keluar {$no_transaksi}";
            $mail->Body = "
<div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Bukti Transfer Keluar</h2>
  
    <p>Halo <strong>{$rek_asal['nama']}</strong>,</p>
  
    <p>Kamu berhasil transfer <strong>Rp " . number_format($jumlah, 0, ',', '.') . "</strong> ke rekening {$rek_tujuan['nama']}, berikut rinciannya:</p>
  
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Transfer</p>
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
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_asal['nama']}<br><span style='font-size:14px; color:#808080;'>KASDIG • {$rek_asal['no_rekening']}</span></p>
    </div>
  
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_tujuan['nama']}<br><span style='font-size:14px; color:#808080;'>KASDIG • {$rek_tujuan['no_rekening']}</span></p>
    </div>
  
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . ($keterangan ?: 'Tidak ada') . "</p>
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
        Ini adalah pesan otomatis dari sistem KASDIG.<br>
        Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
    </p>
</div>";

            // Plain text version
            $labels = [
                'Nama' => $rek_asal['nama'],
                'Nominal Transfer' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                'Biaya Admin' => 'Gratis',
                'Total' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                'Rekening Sumber' => $rek_asal['no_rekening'],
                'Rekening Tujuan' => $rek_tujuan['no_rekening'],
                'Keterangan' => $keterangan ?: 'Tidak ada',
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
            $text_rows[] = "\nHormat kami,\nTim KASDIG";
            $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
            $mail->AltBody = implode("\n", $text_rows);

            $mail->send();
        }

        // Email untuk penerima (jika ada email)
        if (!empty($rek_tujuan['email']) && filter_var($rek_tujuan['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->clearReplyTos();

            $mail->setFrom('noreply@schobank.web.id', 'KASDIG');
            $mail->addAddress($rek_tujuan['email'], $rek_tujuan['nama']);
            $mail->addReplyTo('noreply@schobank.web.id', 'No Reply');
            $unique_id = uniqid('kasdig_', true) . '@kasdig.web.id';
            $mail->MessageID = '<' . $unique_id . '>';
            $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
            $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
            $mail->isHTML(true);
            $mail->Subject = "Bukti Transfer Masuk {$no_transaksi}";
            $mail->Body = "
<div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Bukti Transfer Masuk</h2>
  
    <p>Halo <strong>{$rek_tujuan['nama']}</strong>,</p>
  
    <p>Kamu menerima transfer <strong>Rp " . number_format($jumlah, 0, ',', '.') . "</strong> dari rekening {$rek_asal['nama']}, berikut rinciannya:</p>
  
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Transfer</p>
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
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_asal['nama']}<br><span style='font-size:14px; color:#808080;'>KASDIG • {$rek_asal['no_rekening']}</span></p>
    </div>
  
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_tujuan['nama']}<br><span style='font-size:14px; color:#808080;'>KASDIG • {$rek_tujuan['no_rekening']}</span></p>
    </div>
  
    <div style='margin-bottom: 18px;'>
        <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
        <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . ($keterangan ?: 'Tidak ada') . "</p>
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
        Ini adalah pesan otomatis dari sistem KASDIG.<br>
        Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
    </p>
</div>";

            // Plain text version untuk penerima
            $labels = [
                'Nama' => $rek_tujuan['nama'],
                'Nominal Transfer' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                'Biaya Admin' => 'Gratis',
                'Total' => 'Rp ' . number_format($jumlah, 0, ',', '.'),
                'Rekening Sumber' => $rek_asal['no_rekening'],
                'Rekening Tujuan' => $rek_tujuan['no_rekening'],
                'Keterangan' => $keterangan ?: 'Tidak ada',
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
            $text_rows[] = "\nHormat kami,\nTim KASDIG";
            $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
            $mail->AltBody = implode("\n", $text_rows);

            $mail->send();
        }

    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
    }
}
$conn->close();
?>