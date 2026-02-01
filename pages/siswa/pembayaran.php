<?php
/**
 * Siswa Pembayaran Tagihan Viewer & Payment - Adapted for New Schema
 * File: pages/siswa/pembayaran.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
if (!$project_root) {
    $project_root = dirname(dirname($current_dir));
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// SESSION & TIMEZONE
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ============================================
// HELPER FUNCTIONS
// ============================================
function getIndonesianMonth($date)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    return date('d', strtotime($date)) . ' ' . $months[(int) date('m', strtotime($date))] . ' ' . date('Y', strtotime($date));
}

function sendPaymentEmail($mail, $email, $nama, $no_transaksi, $nama_item, $nominal, $tanggal_bayar)
{
    if (empty($email)) {
        return false;
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

        $mail->setFrom('noreply@kasdig.web.id', 'KASDIG SYSTEM');
        $mail->addAddress($email, $nama);

        $tanggal_formatted = getIndonesianMonth($tanggal_bayar) . ' ' . date('H:i', strtotime($tanggal_bayar));

        $subject = "Konfirmasi Pembayaran $nama_item - $no_transaksi";

        $message_email = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Pembayaran Berhasil!</h2>
            <p>Halo <strong>$nama</strong>,</p>
            <p>Pembayaran tagihan Anda telah berhasil diproses. Berikut rincian transaksi:</p>
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Pembayaran</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>$no_transaksi</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Jenis Tagihan</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>$nama_item</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Jumlah Pembayaran</p>
                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp " . number_format($nominal, 0, ',', '.') . "</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal Pembayaran</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>$tanggal_formatted WIB</p>
            </div>
            <div style='margin-bottom: 18px;'>
                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Status</p>
                <p style='margin:0; font-size:16px; font-weight:600; color:#10b981;'>✓ Lunas</p>
            </div>
            
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem KASDIG.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>";

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message_email;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</div>', '</p>'], ["\n", "\n", "\n"], $message_email));

        if ($mail->send()) {
            $mail->smtpClose();
            return true;
        } else {
            throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
        }

    } catch (Exception $e) {
        error_log("Mail error for payment $no_transaksi: " . $e->getMessage());
        return false;
    }
}

$username = $_SESSION['username'] ?? 'Siswa';
$siswa_id = $_SESSION['user_id'] ?? 0;

if ($_SESSION['role'] !== 'siswa') {
    header("Location: /pages/login.php");
    exit;
}

// ============================================
// DETECT BASE URL
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));

$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;

// ============================================
// SWEETALERT MESSAGE HOLDER
// ============================================
$swal_type = '';
$swal_title = '';
$swal_message = '';

// ============================================
// AJAX PIN VERIFICATION HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_verify_pin'])) {
    header('Content-Type: application/json');
    $tagihan_id = filter_input(INPUT_POST, 'tagihan_id', FILTER_VALIDATE_INT);
    $pin = trim($_POST['pin'] ?? '');

    if (!$tagihan_id || empty($pin) || strlen($pin) !== 6) {
        echo json_encode(['success' => false, 'message' => 'PIN harus 6 digit angka.']);
        exit;
    }

    // Verify PIN
    $query_pin = "SELECT us.pin FROM user_security us WHERE us.user_id = ?";
    $stmt_pin = $conn->prepare($query_pin);
    $stmt_pin->bind_param("i", $siswa_id);
    $stmt_pin->execute();
    $result_pin = $stmt_pin->get_result();
    $user_security = $result_pin->fetch_assoc();

    if (!$user_security || empty($user_security['pin'])) {
        echo json_encode(['success' => false, 'message' => 'PIN Transaksi belum diatur. Silakan atur di Pengaturan.']);
        exit;
    }

    if (!password_verify($pin, $user_security['pin'])) {
        echo json_encode(['success' => false, 'message' => 'PIN yang Anda masukkan salah!']);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// HANDLE PAYMENT SUBMISSION WITH PIN VERIFICATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar_tagihan'])) {
    $tagihan_id = filter_input(INPUT_POST, 'tagihan_id', FILTER_VALIDATE_INT);
    $pin = trim($_POST['pin'] ?? '');

    if ($tagihan_id) {
        if (empty($pin) || strlen($pin) !== 6) {
            $swal_type = 'error';
            $swal_title = 'PIN tidak valid';
            $swal_message = 'PIN harus 6 digit angka.';
        } else {
            // Verify PIN
            $query_pin = "SELECT us.pin FROM user_security us WHERE us.user_id = ?";
            $stmt_pin = $conn->prepare($query_pin);
            $stmt_pin->bind_param("i", $siswa_id);
            $stmt_pin->execute();
            $result_pin = $stmt_pin->get_result();
            $user_security = $result_pin->fetch_assoc();

            if (!$user_security || empty($user_security['pin'])) {
                $swal_type = 'error';
                $swal_title = 'PIN Belum Diatur';
                $swal_message = 'Silakan atur PIN Transaksi Anda terlebih dahulu di menu Pengaturan.';
            } elseif (!password_verify($pin, $user_security['pin'])) {
                $swal_type = 'error';
                $swal_title = 'PIN Salah';
                $swal_message = 'PIN yang Anda masukkan salah!';
            } else {
                // Get tagihan details
                $query_tagihan = "SELECT pt.*, pi.nama_item 
                                  FROM pembayaran_tagihan pt
                                  JOIN pembayaran_item pi ON pt.item_id = pi.id
                                  WHERE pt.id = ? AND pt.siswa_id = ?";
                $stmt_tagihan = $conn->prepare($query_tagihan);
                $stmt_tagihan->bind_param("ii", $tagihan_id, $siswa_id);
                $stmt_tagihan->execute();
                $result_tagihan = $stmt_tagihan->get_result();

                if ($row_tagihan = $result_tagihan->fetch_assoc()) {
                    if ($row_tagihan['status'] === 'sudah_bayar') {
                        $swal_type = 'error';
                        $swal_title = 'Tagihan sudah dibayar';
                        $swal_message = 'Tagihan ini sudah tercatat sebagai lunas.';
                    } else {
                        $nominal_bayar = $row_tagihan['nominal'];

                        // Get student's balance
                        $query_rek_siswa = "SELECT id, saldo FROM rekening WHERE user_id = ?";
                        $stmt_rek_siswa = $conn->prepare($query_rek_siswa);
                        $stmt_rek_siswa->bind_param("i", $siswa_id);
                        $stmt_rek_siswa->execute();
                        $result_rek_siswa = $stmt_rek_siswa->get_result();
                        $row_rek_siswa = $result_rek_siswa->fetch_assoc();

                        if ($row_rek_siswa['saldo'] < $nominal_bayar) {
                            $swal_type = 'error';
                            $swal_title = 'Saldo tidak cukup';
                            $swal_message = 'Saldo rekening Anda tidak mencukupi untuk membayar tagihan ini.';
                        } else {
                            // Get bendahara info
                            $query_bendahara = "SELECT u.id AS bendahara_id, r.id AS rekening_bendahara_id 
                                                FROM users u 
                                                JOIN rekening r ON u.id = r.user_id 
                                                WHERE u.role = 'bendahara' LIMIT 1";
                            $result_bendahara = $conn->query($query_bendahara);
                            $row_bendahara = $result_bendahara->fetch_assoc();

                            if ($row_bendahara) {
                                $bendahara_id = $row_bendahara['bendahara_id'];
                                $rekening_bendahara_id = $row_bendahara['rekening_bendahara_id'];
                                $rekening_siswa_id = $row_rek_siswa['id'];

                                // Generate IDs
                                $date_prefix = date('ymd');
                                $random_suffix = sprintf('%06d', mt_rand(100000, 999999));
                                $id_transaksi = $date_prefix . $random_suffix . rand(10, 99);
                                $no_transaksi = 'PAY' . $date_prefix . $random_suffix; // Length: 3+6+6 = 15 chars

                                // Start Transaction
                                $conn->begin_transaction();

                                try {

                                    $query_insert = "INSERT INTO pembayaran_transaksi 
                                                     (tagihan_id, siswa_id, bendahara_id, rekening_siswa_id, rekening_bendahara_id, 
                                                      nominal_bayar, no_pembayaran, metode_bayar, tanggal_bayar, keterangan) 
                                                     VALUES (?, ?, ?, ?, ?, ?, ?, 'transfer', NOW(), ?)";
                                    $stmt_insert = $conn->prepare($query_insert);
                                    $keterangan_transaksi = "Pembayaran Tagihan " . $row_tagihan['nama_item'] . " Via KASDIG";
                                    $stmt_insert->bind_param(
                                        "iiiiidss",
                                        $tagihan_id,
                                        $siswa_id,
                                        $bendahara_id,
                                        $rekening_siswa_id,
                                        $rekening_bendahara_id,
                                        $nominal_bayar,
                                        $no_transaksi,
                                        $keterangan_transaksi
                                    );
                                    $stmt_insert->execute();

                                    // 3. Update the auto-generated Transaksi record to ensure correct type/status
                                    // The trigger created a record with 'no_transaksi' = $no_transaksi. We ensure it's labeled correctly.
                                    $query_update_trx = "UPDATE transaksi SET jenis_transaksi = 'bayar', status = 'approved', keterangan = ? WHERE no_transaksi = ?";
                                    $stmt_update_trx = $conn->prepare($query_update_trx);
                                    $stmt_update_trx->bind_param("ss", $keterangan_transaksi, $no_transaksi);
                                    $stmt_update_trx->execute();

                                    $conn->commit();

                                    // Send payment notification email
                                    try {
                                        // Get student email and name
                                        $query_siswa = "SELECT u.nama, u.email FROM users u WHERE u.id = ?";
                                        $stmt_siswa = $conn->prepare($query_siswa);
                                        $stmt_siswa->bind_param("i", $siswa_id);
                                        $stmt_siswa->execute();
                                        $siswa_data = $stmt_siswa->get_result()->fetch_assoc();
                                        $stmt_siswa->close();

                                        if ($siswa_data && !empty($siswa_data['email'])) {
                                            $mail = new PHPMailer(true);
                                            sendPaymentEmail(
                                                $mail,
                                                $siswa_data['email'],
                                                $siswa_data['nama'],
                                                $no_transaksi,
                                                $row_tagihan['nama_item'],
                                                $nominal_bayar,
                                                date('Y-m-d H:i:s')
                                            );
                                        }
                                    } catch (Exception $mailEx) {
                                        // Email failure should not affect payment success
                                        error_log("Failed to send payment email: " . $mailEx->getMessage());
                                    }

                                    $swal_type = 'success';
                                    $swal_title = 'Pembayaran berhasil';
                                    $swal_message = 'Pembayaran ' . $row_tagihan['nama_item'] . ' berhasil. No: ' . $no_transaksi;
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $swal_type = 'error';
                                    $swal_title = 'Gagal membayar';
                                    $swal_message = 'Terjadi kesalahan: ' . $e->getMessage();
                                }
                            } else {
                                $swal_type = 'error';
                                $swal_title = 'Bendahara tidak ditemukan';
                                $swal_message = 'Data bendahara tidak tersedia. Silakan hubungi admin.';
                            }
                        }
                    }
                } else {
                    $swal_type = 'error';
                    $swal_title = 'Tagihan tidak ditemukan';
                    $swal_message = 'Tagihan yang dipilih tidak ditemukan.';
                }
            }
        }
    } else {
        $swal_type = 'error';
        $swal_title = 'Data tidak valid';
        $swal_message = 'Permintaan pembayaran tidak valid.';
    }
}

// ============================================
// GET TAGIHAN LIST & SPLIT
// ============================================
$today = date('Y-m-d');

$tagihan_list = [];
$query_tagihan_list = "
    SELECT 
        pt.id AS tagihan_id,
        pt.no_tagihan,
        pt.nominal,
        pt.status,
        pt.tanggal_buat,
        pt.tanggal_jatuh_tempo,
        pi.nama_item,
        ptr.no_pembayaran,
        ptr.tanggal_bayar
    FROM pembayaran_tagihan pt
    JOIN pembayaran_item pi ON pt.item_id = pi.id
    LEFT JOIN pembayaran_transaksi ptr ON pt.id = ptr.tagihan_id
    WHERE pt.siswa_id = ?
    ORDER BY ptr.tanggal_bayar DESC, pt.tanggal_jatuh_tempo DESC
";
$stmt_tagihan_list = $conn->prepare($query_tagihan_list);
$stmt_tagihan_list->bind_param("i", $siswa_id);
$stmt_tagihan_list->execute();
$result_tagihan_list = $stmt_tagihan_list->get_result();
while ($row = $result_tagihan_list->fetch_assoc()) {
    $tagihan_list[] = $row;
}
$conn->close();

// Filter tagihan - split into unpaid (active) and paid
// Tagihan yang lewat jatuh tempo tidak ditampilkan di halaman pembayaran siswa
$tagihan_berjalan = [];
$tagihan_riwayat = [];
foreach ($tagihan_list as $t) {
    $is_overdue = strtotime($t['tanggal_jatuh_tempo']) < strtotime($today);

    if ($t['status'] === 'belum_bayar') {
        // Hanya tampilkan tagihan yang belum lewat jatuh tempo
        if (!$is_overdue) {
            $tagihan_berjalan[] = $t;
        }
        // Tagihan yang lewat jatuh tempo akan dihandle oleh bendahara di arsip_riwayat.php
    } else {
        // Ini adalah tagihan yang sudah dibayar
        $tagihan_riwayat[] = $t;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Tagihan Pembayaran - KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-padding-top: 70px;
            scroll-behavior: smooth;
        }

        :root {
            /* Original Dark/Elegant Theme - Matching Dashboard */
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #10B981;
            --success-light: #D1FAE5;
            --warning: #F59E0B;
            --warning-light: #FEF3C7;
            --danger: #EF4444;

            /* Neutral Colors */
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F8FAFC;
            --gray-100: #F1F5F9;
            --gray-200: #E2E8F0;
            --gray-300: #CBD5E1;
            --gray-400: #94A3B8;
            --gray-500: #64748B;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1E293B;
            --gray-900: #0F172A;
            --white: #FFFFFF;

            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);

            /* Border Radius */
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            padding-top: 0;
            padding-bottom: 100px;
            margin: 0;
            min-height: 100vh;
        }

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
            padding: 20px 20px 120px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-info {
            color: var(--white);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .page-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .header-btn i {
            font-size: 18px;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        .scroll-section {
            padding-bottom: 40px;
        }

        .scroll-section::-webkit-scrollbar {
            width: 6px;
        }

        .scroll-section::-webkit-scrollbar-track {
            background: transparent;
        }

        .scroll-section::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 3px;
        }

        /* ============================================ */
        /* BILL CARD - WHITE THEME */
        /* ============================================ */
        .bill-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            position: relative;
            transition: transform 0.2s;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .bill-card:hover {
            transform: translateY(-2px);
        }

        .bill-header {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px dashed var(--gray-200);
            background: linear-gradient(to right, #fff, #f9fafb);
        }

        .bill-period {
            display: flex;
            flex-direction: column;
        }

        .bill-item-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .bill-status {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 4px 12px;
            border-radius: 20px;
            letter-spacing: 0.5px;
        }

        .status-unpaid {
            background: var(--warning-light);
            color: #92400E;
        }

        .status-paid {
            background: var(--success-light);
            color: #065F46;
        }

        .bill-body {
            padding: 30px 24px;
            text-align: center;
        }

        .bill-label {
            font-size: 12px;
            color: var(--gray-500);
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .bill-amount {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -0.5px;
        }

        .bill-amount .currency {
            font-size: 16px;
            font-weight: 500;
            color: var(--gray-500);
            margin-right: 4px;
            vertical-align: top;
            position: relative;
            top: 4px;
        }

        .bill-due {
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 8px;
        }

        .bill-due.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .bill-footer {
            padding: 16px 24px;
            background: var(--gray-50);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid var(--gray-100);
        }

        .bill-info {
            font-size: 12px;
            color: var(--gray-500);
        }

        .btn {
            padding: 14px 24px;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-pay {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            color: #fff;
        }

        .btn-pay:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .btn-disabled {
            background: var(--gray-200);
            color: var(--gray-400);
            cursor: not-allowed;
        }

        /* ============================================ */
        /* SECTION SEPARATOR */
        /* ============================================ */
        .section-separator {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 30px 0 20px;
        }

        .section-separator::before,
        .section-separator::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--gray-300);
        }

        .section-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ============================================ */
        /* HISTORY LIST */
        /* ============================================ */
        .history-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .history-item {
            background: var(--white);
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            transition: transform 0.1s;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .history-item:hover {
            transform: scale(1.01);
        }

        .h-left {
            display: flex;
            flex-direction: column;
        }

        .h-item-name {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .h-date {
            font-size: 11px;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .h-right {
            text-align: right;
        }

        .h-amount {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-800);
        }

        .h-status {
            font-size: 11px;
            color: var(--success);
            font-weight: 600;
            margin-top: 4px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--gray-400);
            background: var(--white);
            border-radius: var(--radius-lg);
            border: 1px dashed var(--gray-300);
        }

        .empty-icon {
            font-size: 40px;
            margin-bottom: 12px;
            opacity: 0.5;
            display: block;
        }

        .empty-text {
            font-size: 14px;
        }

        /* ============================================ */
        /* HORIZONTAL SCROLL FOR BILLS */
        /* ============================================ */
        .bills-scroll-container {
            display: block;
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 0 16px;
            }

            .top-header {
                padding: 16px 16px 70px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 12px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
            }

            .bills-scroll-container {
                display: flex;
                overflow-x: auto;
                gap: 16px;
                padding: 4px 0 20px 0;
                scroll-snap-type: x mandatory;
                -webkit-overflow-scrolling: touch;
            }

            .bills-scroll-container::-webkit-scrollbar {
                display: none;
            }

            .bill-card.unpaid {
                min-width: 85vw;
                max-width: 350px;
                scroll-snap-align: center;
                margin-bottom: 0;
                flex-shrink: 0;
            }

            .bills-scroll-container::after {
                content: "";
                flex: 0 0 4px;
            }

            .bills-scroll-container.single-item {
                justify-content: center;
            }

            .bills-scroll-container.single-item::after {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .btn {
                width: 100%;
                justify-content: center;
            }

            .bill-footer {
                flex-direction: column;
                gap: 12px;
                text-align: center;
            }

            .bill-amount {
                font-size: 26px;
            }
        }
    </style>
</head>

<body>
    <!-- Dark Gradient Header -->
    <header class="top-header">
        <div class="header-content">
            <div class="header-top">
                <div class="page-info">
                    <h1 class="page-title">Tagihan Pembayaran</h1>
                    <p class="page-subtitle">Kelola dan bayar tagihan pembayaran sekolah</p>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Tagihan Berjalan (Unpaid) -->
        <?php if (!empty($tagihan_berjalan)): ?>
            <div class="bills-scroll-container <?php echo count($tagihan_berjalan) === 1 ? 'single-item' : ''; ?>">
                <?php foreach ($tagihan_berjalan as $tagihan): ?>
                    <?php
                    $is_overdue = strtotime($tagihan['tanggal_jatuh_tempo']) < strtotime($today);
                    $bulan_indo = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                    $tgl_jt = strtotime($tagihan['tanggal_jatuh_tempo']);
                    $jatuh_tempo_formatted = date('d', $tgl_jt) . ' ' . $bulan_indo[(int) date('n', $tgl_jt)] . ' ' . date('Y', $tgl_jt);
                    ?>
                    <div class="bill-card unpaid">
                        <div class="bill-header">
                            <div class="bill-period">
                                <span class="bill-item-name"><?php echo htmlspecialchars($tagihan['nama_item']); ?></span>
                            </div>
                            <span class="bill-status status-unpaid">Belum Bayar</span>
                        </div>
                        <div class="bill-body">
                            <div class="bill-label">Total Tagihan</div>
                            <div class="bill-amount">
                                <span class="currency">Rp</span><?php echo number_format($tagihan['nominal'], 0, ',', '.'); ?>
                            </div>
                            <div class="bill-due <?php echo $is_overdue ? 'overdue' : ''; ?>">
                                Jatuh Tempo: <?php echo $jatuh_tempo_formatted; ?>
                                <?php if ($is_overdue): ?><i class="fas fa-exclamation-circle"></i><?php endif; ?>
                            </div>
                        </div>
                        <div class="bill-footer">
                            <span class="bill-info">No: <?php echo htmlspecialchars($tagihan['no_tagihan']); ?></span>
                            <form method="POST" class="payment-form" onsubmit="return handlePayment(event, this);"
                                data-nama="<?php echo htmlspecialchars($tagihan['nama_item']); ?>"
                                data-nominal="<?php echo number_format($tagihan['nominal'], 0, ',', '.'); ?>"
                                data-jatuh-tempo="<?php echo $jatuh_tempo_formatted; ?>">
                                <input type="hidden" name="tagihan_id" value="<?php echo $tagihan['tagihan_id']; ?>">
                                <input type="hidden" name="pin" class="pin-input">
                                <input type="hidden" name="bayar_tagihan" value="1">
                                <button type="submit" class="btn btn-pay">
                                    <i class="fas fa-wallet"></i> Bayar Sekarang
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle empty-icon"></i>
                <div class="empty-text">Tidak ada tagihan yang perlu dibayar saat ini.</div>
            </div>
        <?php endif; ?>
        <!-- Section: Riwayat -->
        <div class="section-separator">
            <span class="section-title">Riwayat Pembayaran</span>
        </div>

        <div class="scroll-section">
            <?php if (!empty($tagihan_riwayat)): ?>
                <div class="history-list">
                    <?php foreach ($tagihan_riwayat as $t): ?>
                        <a href="struk_pembayaran.php?no_transaksi=<?php echo htmlspecialchars($t['no_pembayaran']); ?>"
                            class="history-item" style="text-decoration: none;">
                            <div class="h-left">
                                <span class="h-item-name"><?php echo htmlspecialchars($t['nama_item']); ?></span>
                                <?php
                                $bulan_indo_short = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
                                $tgl_bayar = strtotime($t['tanggal_bayar']);
                                $tanggal_bayar_formatted = date('d', $tgl_bayar) . ' ' . $bulan_indo_short[(int) date('n', $tgl_bayar)] . ' ' . date('Y', $tgl_bayar);
                                ?>
                                <span class="h-date">Dibayar: <?php echo $tanggal_bayar_formatted; ?></span>
                            </div>
                            <div class="h-right">
                                <div class="h-amount">Rp <?php echo number_format($t['nominal'], 0, ',', '.'); ?></div>
                                <span class="h-status"><i class="fas fa-check"></i> Lunas</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt empty-icon"></i>
                    <div class="empty-text">Belum ada riwayat pembayaran.</div>
                </div>
            <?php endif; ?>
        </div><!-- end scroll-section -->
    </div><!-- end main-container -->

    <!-- Bottom Navigation -->
    <?php include 'bottom_navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function handlePayment(e, form) {
            e.preventDefault();
            const tagihanId = form.querySelector('input[name="tagihan_id"]').value;
            const namaTagihan = form.dataset.nama || 'Tagihan';
            const nominalTagihan = form.dataset.nominal || '-';
            const jatuhTempo = form.dataset.jatuhTempo || '-';

            // Step 1: Konfirmasi Pembayaran
            Swal.fire({
                icon: 'info',
                title: 'Konfirmasi Pembayaran',
                html: `Dengan menekan <b>Bayar Sekarang</b>, kamu akan membayar tagihan <b>${namaTagihan}</b> sebesar <b style="color: #1e293b;">Rp ${nominalTagihan}</b>.<br><br>Pastikan saldo rekening kamu mencukupi.`,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-wallet"></i> Bayar Sekarang',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#2c3e50',
                cancelButtonColor: '#94A3B8'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Step 2: PIN Verification
                    showPinModal(form, tagihanId);
                }
            });

            return false;
        }

        function showPinModal(form, tagihanId) {
            // Store PIN values separately for immediate masking
            let pinValues = ['', '', '', '', '', ''];
            let isProcessing = false;

            Swal.fire({
                title: '',
                html: `
                    <div class="pin-modal">
                        <h2 class="pin-title">Verifikasi PIN</h2>
                        <p class="pin-subtitle">Masukkan 6 digit PIN transaksi Anda</p>
                        <!-- Hidden input for mobile keyboard - positioned off-screen -->
                        <input type="tel" id="hidden-pin-input" inputmode="numeric" pattern="[0-9]*" 
                               maxlength="6" autocomplete="off" 
                               style="position: fixed; left: -9999px; top: -9999px; opacity: 0; width: 0; height: 0; border: none;">
                        <div class="pin-container" id="pin-container-clickable">
                            <div class="pin-box" data-index="0"></div>
                            <div class="pin-box" data-index="1"></div>
                            <div class="pin-box" data-index="2"></div>
                            <div class="pin-box" data-index="3"></div>
                            <div class="pin-box" data-index="4"></div>
                            <div class="pin-box" data-index="5"></div>
                        </div>
                        <div id="pin-error" class="pin-error-msg"></div>
                    </div>
                    <style>
                        .swal2-popup { padding: 0 !important; border-radius: 12px !important; overflow: hidden; }
                        .swal2-html-container { margin: 0 !important; padding: 0 !important; overflow: visible !important; }
                        .swal2-actions { margin-top: 0 !important; padding: 20px 30px 25px !important; background: #f8fafc; border-top: 1px solid #e2e8f0; gap: 12px !important; }
                        .swal2-confirm { border-radius: 10px !important; font-weight: 600 !important; padding: 14px 28px !important; font-size: 14px !important; }
                        .swal2-cancel { border-radius: 10px !important; font-weight: 600 !important; padding: 14px 28px !important; font-size: 14px !important; }
                        
                        .pin-modal { padding: 30px 30px 20px; position: relative; }
                        .pin-title {
                            font-size: 20px; font-weight: 700; color: #1e293b;
                            margin: 0 0 6px; text-align: center;
                        }
                        .pin-subtitle {
                            font-size: 13px; color: #64748b; margin: 0 0 24px; text-align: center;
                        }
                        .pin-container {
                            display: flex; gap: 10px; justify-content: center; align-items: center;
                            margin-bottom: 12px; cursor: text;
                        }
                        .pin-separator { width: 12px; height: 3px; background: #cbd5e1; border-radius: 2px; }
                        .pin-box {
                            width: 46px; height: 54px;
                            border: 2px solid #e2e8f0; border-radius: 10px;
                            font-size: 22px; font-weight: 700; font-family: 'Poppins', sans-serif;
                            display: flex; align-items: center; justify-content: center;
                            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
                            color: #1e293b;
                            box-shadow: 0 2px 4px rgba(0,0,0,0.04);
                            cursor: text;
                            user-select: none;
                        }
                        .pin-box.active {
                            border-color: #2c3e50;
                            box-shadow: 0 0 0 4px rgba(44, 62, 80, 0.12), 0 4px 12px rgba(44, 62, 80, 0.15);
                            transform: translateY(-2px);
                            background: white;
                        }
                        .pin-box.filled {
                            border-color: #2c3e50;
                            background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
                            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
                        }
                        .pin-box.error {
                            border-color: #ef4444;
                            background: linear-gradient(180deg, #fef2f2 0%, #fee2e2 100%);
                            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
                        }
                        .pin-box.shake { animation: shake 0.4s ease-in-out; }
                        @keyframes shake {
                            0%, 100% { transform: translateX(0); }
                            20%, 60% { transform: translateX(-4px); }
                            40%, 80% { transform: translateX(4px); }
                        }
                        .pin-box.pop { animation: pop 0.2s ease-out; }
                        @keyframes pop {
                            0% { transform: scale(1); }
                            50% { transform: scale(1.1) translateY(-2px); }
                            100% { transform: scale(1) translateY(-2px); }
                        }
                        .pin-error-msg {
                            min-height: 20px; color: #ef4444; font-size: 13px; font-weight: 500;
                            text-align: center; opacity: 0; transform: translateY(-8px);
                            transition: all 0.3s ease; display: flex; align-items: center;
                            justify-content: center; gap: 6px;
                        }
                        .pin-error-msg.show { opacity: 1; transform: translateY(0); }
                        .pin-error-msg i { font-size: 14px; }
                    </style>
                `,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-wallet"></i> Bayar',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#2c3e50',
                cancelButtonColor: '#94A3B8',
                focusConfirm: false,
                allowOutsideClick: false,
                didOpen: () => {
                    const boxes = document.querySelectorAll('.pin-box:not(.pin-separator)');
                    const errorDiv = document.getElementById('pin-error');
                    const hiddenInput = document.getElementById('hidden-pin-input');
                    const pinContainer = document.getElementById('pin-container-clickable');

                    // Focus hidden input to trigger keyboard
                    setTimeout(() => {
                        hiddenInput.focus();
                        updateActiveBox();
                    }, 100);

                    function clearError() {
                        errorDiv.classList.remove('show');
                        errorDiv.textContent = '';
                        boxes.forEach(box => box.classList.remove('error'));
                    }

                    function updateActiveBox() {
                        const currentLength = pinValues.filter(v => v !== '').length;
                        const activeIndex = Math.min(currentLength, 5);
                        boxes.forEach((box, i) => {
                            box.classList.remove('active');
                            if (i === activeIndex && currentLength < 6) {
                                box.classList.add('active');
                            }
                        });
                    }

                    function updateDisplay() {
                        boxes.forEach((box, i) => {
                            // Always show bullet for filled, empty for unfilled
                            box.textContent = pinValues[i] ? '●' : '';
                            if (pinValues[i]) {
                                box.classList.add('filled');
                            } else {
                                box.classList.remove('filled');
                            }
                        });
                        updateActiveBox();
                    }

                    // Click on container or boxes to focus hidden input
                    pinContainer.addEventListener('click', () => {
                        hiddenInput.focus();
                    });

                    boxes.forEach(box => {
                        box.addEventListener('click', () => {
                            hiddenInput.focus();
                        });
                    });

                    // Handle input from hidden input
                    hiddenInput.addEventListener('input', (e) => {
                        clearError();
                        const value = e.target.value.replace(/[^0-9]/g, '');

                        // Update pinValues based on input
                        for (let i = 0; i < 6; i++) {
                            pinValues[i] = value[i] || '';
                        }

                        // Add pop animation to last filled box
                        const filledCount = pinValues.filter(v => v !== '').length;
                        if (filledCount > 0 && filledCount <= 6) {
                            const lastFilledBox = boxes[filledCount - 1];
                            if (lastFilledBox) {
                                lastFilledBox.classList.add('pop');
                                setTimeout(() => lastFilledBox.classList.remove('pop'), 150);
                            }
                        }

                        // Keep hidden input in sync (max 6 digits)
                        hiddenInput.value = value.slice(0, 6);

                        updateDisplay();
                    });

                    // Handle keydown for backspace on empty
                    hiddenInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Backspace') {
                            clearError();
                        }
                    });

                    // Handle paste
                    hiddenInput.addEventListener('paste', (e) => {
                        e.preventDefault();
                        clearError();
                        const paste = (e.clipboardData || window.clipboardData).getData('text');
                        const digits = paste.replace(/[^0-9]/g, '').slice(0, 6);

                        for (let i = 0; i < 6; i++) {
                            pinValues[i] = digits[i] || '';
                        }
                        hiddenInput.value = digits;
                        updateDisplay();
                    });
                },
                preConfirm: () => {
                    if (isProcessing) return false;

                    const boxes = document.querySelectorAll('.pin-box:not(.pin-separator)');
                    const errorDiv = document.getElementById('pin-error');
                    const hiddenInput = document.getElementById('hidden-pin-input');
                    const pin = pinValues.join('');

                    if (pin.length !== 6) {
                        boxes.forEach(box => {
                            box.classList.add('shake', 'error');
                            setTimeout(() => box.classList.remove('shake'), 400);
                        });
                        errorDiv.textContent = 'PIN harus 6 digit angka';
                        errorDiv.classList.add('show');
                        return false;
                    }

                    isProcessing = true;

                    // Verify PIN via AJAX
                    return fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `ajax_verify_pin=1&tagihan_id=${tagihanId}&pin=${pin}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            isProcessing = false;
                            if (!data.success) {
                                // Show error and allow retry - reset PIN values
                                pinValues = ['', '', '', '', '', ''];
                                boxes.forEach(box => {
                                    box.classList.add('shake', 'error');
                                    box.classList.remove('filled', 'active');
                                    setTimeout(() => box.classList.remove('shake'), 400);
                                    box.textContent = '';
                                });
                                // Reset hidden input and refocus
                                hiddenInput.value = '';
                                setTimeout(() => {
                                    hiddenInput.focus();
                                    boxes[0].classList.add('active');
                                }, 100);
                                errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + data.message;
                                errorDiv.classList.add('show');
                                return false;
                            }
                            return pin;
                        })
                        .catch(err => {
                            isProcessing = false;
                            errorDiv.textContent = 'Terjadi kesalahan koneksi';
                            errorDiv.classList.add('show');
                            return false;
                        });
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    // Show loading spinner for 3 seconds
                    Swal.fire({
                        title: 'Memproses Pembayaran',
                        html: 'Mohon tunggu sebentar...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    // Submit form after 3 seconds
                    setTimeout(() => {
                        form.querySelector('.pin-input').value = result.value;
                        form.submit();
                    }, 3000);
                }
            });
            return false;
        }

        <?php if (!empty($swal_type) && !empty($swal_message)): ?>
            Swal.fire({
                icon: '<?php echo $swal_type; ?>',
                title: '<?php echo addslashes($swal_title); ?>',
                text: '<?php echo addslashes($swal_message); ?>',
                confirmButtonColor: '<?php echo $swal_type === 'success' ? '#10B981' : '#2c3e50'; ?>'
            });
        <?php endif; ?>
    </script>
</body>

</html>