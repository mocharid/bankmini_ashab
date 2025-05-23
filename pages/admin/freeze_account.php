<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../index.php");
    exit();
}

// Temporary debug logging
function debugLog($message) {
    error_log("[DEBUG freeze_account.php] " . $message);
}

// Function to format account number (REK9****6 format)
function formatRekening($no_rekening) {
    if (empty($no_rekening) || strlen($no_rekening) < 6 || $no_rekening === 'Belum punya rekening') {
        return 'Belum ada';
    }
    if (strpos($no_rekening, 'REK') === 0) {
        return $no_rekening; // Already formatted
    }
    return 'REK' . substr($no_rekening, 0, 1) . '****' . substr($no_rekening, -1);
}

// Function to send email
function sendAccountEmail($conn, $siswa, $action, $alasan) {
    $nama_siswa = $siswa['nama'];
    $jurusan = $siswa['nama_jurusan'] ?? 'Tidak ada';
    $kelas = $siswa['nama_kelas'] ?? 'Tidak ada';
    $tanggal = date('d M Y H:i:s');
    $email = $siswa['email'];

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'none';
    }

    $action_text = $action === 'freeze' ? 'Pembekuan Akun' : 'Pembukaan Akun';
    $action_verb = $action === 'freeze' ? 'dibekukan' : 'dibuka kembali';
    $subject = "Pemberitahuan {$action_text} - SCHOBANK SYSTEM";

    $message = "
    <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f0f5ff; border-radius: 12px; overflow: hidden;'>
        <div style='background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px 20px; text-align: center; color: #ffffff;'>
            <h2 style='font-size: 24px; font-weight: 600; margin: 0;'>SCHOBANK SYSTEM</h2>
            <p style='font-size: 16px; opacity: 0.8; margin: 5px 0 0;'>Pemberitahuan {$action_text}</p>
        </div>
        <div style='background: #ffffff; padding: 30px;'>
            <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama_siswa}</h3>
            <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami ingin memberitahu Anda bahwa akun Anda telah {$action_verb}. Berikut adalah rinciannya:</p>
            <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                <table style='width: 100%; font-size: 15px; color: #333333;'>
                    <tr>
                        <td style='padding: 8px 0; font-weight: 500;'>Nama Pemilik</td>
                        <td style='padding: 8px 0; text-align: right;'>{$nama_siswa}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: 500;'>Jurusan</td>
                        <td style='padding: 8px 0; text-align: right;'>{$jurusan}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: 500;'>Kelas</td>
                        <td style='padding: 8px 0; text-align: right;'>{$kelas}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: 500;'>Alasan</td>
                        <td style='padding: 8px 0; text-align: right;'>{$alasan}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px 0; font-weight: 500;'>Tanggal</td>
                        <td style='padding: 8px 0; text-align: right;'>{$tanggal} WIB</td>
                    </tr>
                </table>
            </div>
            <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>" . ($action === 'freeze' ? 'Akun Anda tidak dapat digunakan hingga dibuka kembali.' : 'Pastikan akun Anda terhindar dari aktivitas yang tidak wajar.') . "</p>
            <p style='color: #e74c3c; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;'>
                <span style='font-size: 18px;'>🔒</span> Jangan bagikan informasi rekening atau kata sandi kepada pihak lain.
            </p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='mailto:support@schobank.xai' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Hubungi Kami</a>
            </div>
        </div>
        <div style='background: #f0f5ff; padding: 15px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
            <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
            <p style='margin: 5px 0 0;'>Email ini otomatis. Mohon tidak membalas.</p>
        </div>
    </div>";

    try {
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
        $mail->addAddress($email, $nama_siswa);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags($message);

        $mail->send();
        return 'success';
    } catch (Exception $e) {
        debugLog("Email failed for {$action} (siswa ID {$siswa['id']}): " . $e->getMessage());
        return 'failed';
    }
}

// Handle AJAX freeze confirmation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_freeze'])) {
    $response = ['status' => 'error', 'message' => '', 'email_status' => 'none', 'has_frozen_accounts' => false];

    if (!isset($_POST['siswa_id']) || !isset($_POST['alasan'])) {
        $response['message'] = 'Data tidak lengkap. Silakan pilih alasan pembekuan.';
        echo json_encode($response);
        exit();
    }

    $siswa_id = intval($_POST['siswa_id']);
    $alasan = trim($_POST['alasan']);
    $petugas_id = $_SESSION['user_id'];

    // Validate alasan
    $valid_reasons = ['Aktivitas mencurigakan', 'Permintaan pengguna', 'Pelanggaran ketentuan', 'Lainnya'];
    if (!in_array($alasan, $valid_reasons)) {
        $response['message'] = 'Alasan tidak valid.';
        echo json_encode($response);
        exit();
    }

    try {
        $conn->begin_transaction();

        $query = "SELECT u.id, u.nama, u.email, r.no_rekening, j.nama_jurusan, k.nama_kelas, u.is_frozen 
                  FROM users u 
                  LEFT JOIN rekening r ON u.id = r.user_id 
                  LEFT JOIN jurusan j ON u.jurusan_id = j.id 
                  LEFT JOIN kelas k ON u.kelas_id = k.id 
                  WHERE u.id = ? AND u.role = 'siswa'";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: ' . $conn->error);
        }
        $stmt->bind_param("i", $siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $siswa = $result->fetch_assoc();
        $stmt->close();

        if (!$siswa) {
            throw new Exception('Siswa tidak ditemukan.');
        }

        if ($siswa['is_frozen']) {
            throw new Exception('Akun sudah dibekukan.');
        }

        $update_query = "UPDATE users SET is_frozen = 1, freeze_timestamp = NOW() WHERE id = ? AND role = 'siswa'";
        $stmt = $conn->prepare($update_query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: ' . $conn->error);
        }
        $stmt->bind_param("i", $siswa_id);
        if (!$stmt->execute()) {
            throw new Exception('Gagal membekukan akun: ' . $stmt->error);
        }
        $stmt->close();

        $log_query = "INSERT INTO account_freeze_log (petugas_id, siswa_id, action, alasan, waktu) 
                      VALUES (?, ?, 'freeze', ?, NOW())";
        $stmt = $conn->prepare($log_query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: ' . $conn->error);
        }
        $stmt->bind_param("iis", $petugas_id, $siswa_id, $alasan);
        if (!$stmt->execute()) {
            throw new Exception('Gagal menyimpan log aktivitas: ' . $stmt->error);
        }
        $stmt->close();

        $notification_message = "Akun Anda telah dibekukan karena: $alasan. Hubungi petugas untuk informasi lebih lanjut.";
        $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
        $stmt_notifikasi = $conn->prepare($query_notifikasi);
        if (!$stmt_notifikasi) {
            throw new Exception('Kesalahan database: Gagal mempersiapkan notifikasi query - ' . $conn->error);
        }
        $stmt_notifikasi->bind_param("is", $siswa_id, $notification_message);
        if (!$stmt_notifikasi->execute()) {
            throw new Exception('Gagal mengirim notifikasi: ' . $stmt_notifikasi->error);
        }
        $stmt_notifikasi->close();

        $email_status = sendAccountEmail($conn, $siswa, 'freeze', $alasan);
        $response['email_status'] = $email_status;

        // Check if there are any frozen accounts
        $query_count = "SELECT COUNT(*) as count FROM users WHERE role = 'siswa' AND is_frozen = 1";
        $result_count = $conn->query($query_count);
        $response['has_frozen_accounts'] = $result_count && $result_count->fetch_assoc()['count'] > 0;

        $conn->commit();
        debugLog("Success: Account frozen for siswa ID $siswa_id, email_status: $email_status, has_frozen_accounts: " . ($response['has_frozen_accounts'] ? 'true' : 'false'));
        $response['status'] = 'success';
        $response['message'] = 'Akun siswa berhasil dibekukan.';
        $response['new_frozen_account'] = [
            'id' => $siswa['id'],
            'nama' => $siswa['nama'],
            'nama_kelas' => $siswa['nama_kelas'] ?? 'Tidak ada',
            'nama_jurusan' => $siswa['nama_jurusan'] ?? 'Tidak ada',
            'alasan' => $alasan,
            'waktu' => date('d-m-Y H:i')
        ];
    } catch (Exception $e) {
        $conn->rollback();
        debugLog("Error during freeze for siswa ID $siswa_id: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// Handle AJAX unfreeze action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_unfreeze'])) {
    $response = ['status' => 'error', 'message' => '', 'email_status' => 'none', 'has_frozen_accounts' => false];

    $siswa_id = intval($_POST['siswa_id'] ?? 0);
    $alasan = trim($_POST['alasan_unfreeze'] ?? '');
    $petugas_id = $_SESSION['user_id'];

    if (empty($siswa_id) || empty($alasan)) {
        $response['message'] = 'Harap pilih alasan pembukaan akun.';
        echo json_encode($response);
        exit();
    }

    // Validate alasan
    $valid_reasons = ['Verifikasi selesai', 'Permintaan pengguna', 'Kesalahan sistem', 'Lainnya'];
    if (!in_array($alasan, $valid_reasons)) {
        $response['message'] = 'Alasan tidak valid.';
        echo json_encode($response);
        exit();
    }

    try {
        $conn->begin_transaction();

        $query = "SELECT u.id, u.nama, u.email, u.is_frozen, r.no_rekening, j.nama_jurusan, k.nama_kelas 
                  FROM users u 
                  LEFT JOIN rekening r ON u.id = r.user_id 
                  LEFT JOIN jurusan j ON u.jurusan_id = j.id 
                  LEFT JOIN kelas k ON u.kelas_id = k.id 
                  WHERE u.id = ? AND u.role = 'siswa'";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: Gagal mempersiapkan query - ' . $conn->error);
        }
        $stmt->bind_param("i", $siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $siswa = $result->fetch_assoc();
        $stmt->close();

        if (!$siswa) {
            throw new Exception('Siswa tidak ditemukan.');
        }

        if (!$siswa['is_frozen']) {
            throw new Exception('Akun tidak dalam status dibekukan.');
        }

        $update_query = "UPDATE users SET is_frozen = 0, freeze_timestamp = NULL WHERE id = ? AND role = 'siswa'";
        $stmt = $conn->prepare($update_query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: Gagal mempersiapkan update query - ' . $conn->error);
        }
        $stmt->bind_param("i", $siswa_id);
        if (!$stmt->execute()) {
            throw new Exception('Gagal mengaktifkan akun: ' . $stmt->error);
        }
        $stmt->close();

        $log_query = "INSERT INTO account_freeze_log (petugas_id, siswa_id, action, alasan, waktu) 
                      VALUES (?, ?, 'unfreeze', ?, NOW())";
        $stmt = $conn->prepare($log_query);
        if (!$stmt) {
            throw new Exception('Kesalahan database: Gagal mempersiapkan log query - ' . $conn->error);
        }
        $stmt->bind_param("iis", $petugas_id, $siswa_id, $alasan);
        if (!$stmt->execute()) {
            throw new Exception('Gagal menyimpan log aktivitas: ' . $stmt->error);
        }
        $stmt->close();

        $notification_message = "Akun Anda telah dibuka kembali. Pastikan akun Anda terhindar dari aktivitas yang tidak wajar.";
        $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
        $stmt_notifikasi = $conn->prepare($query_notifikasi);
        if (!$stmt_notifikasi) {
            throw new Exception('Kesalahan database: Gagal mempersiapkan notifikasi query - ' . $conn->error);
        }
        $stmt_notifikasi->bind_param("is", $siswa_id, $notification_message);
        if (!$stmt_notifikasi->execute()) {
            throw new Exception('Gagal mengirim notifikasi: ' . $stmt_notifikasi->error);
        }
        $stmt_notifikasi->close();

        $email_status = sendAccountEmail($conn, $siswa, 'unfreeze', $alasan);
        $response['email_status'] = $email_status;

        // Check if there are any frozen accounts
        $query_count = "SELECT COUNT(*) as count FROM users WHERE role = 'siswa' AND is_frozen = 1";
        $result_count = $conn->query($query_count);
        $response['has_frozen_accounts'] = $result_count && $result_count->fetch_assoc()['count'] > 0;

        $conn->commit();
        debugLog("Success: Account unfrozen for siswa ID $siswa_id, email_status: $email_status, has_frozen_accounts: " . ($response['has_frozen_accounts'] ? 'true' : 'false'));
        $response['status'] = 'success';
        $response['message'] = 'Akun siswa berhasil diaktifkan.';
        $response['removed_siswa_id'] = $siswa_id;
    } catch (Exception $e) {
        $conn->rollback();
        debugLog("Error during unfreeze for siswa ID $siswa_id: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit();
}

// Handle AJAX search for frozen accounts
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_search_frozen'])) {
    $search_query = trim($_POST['search_query'] ?? '');
    $response = ['status' => 'error', 'message' => '', 'data' => [], 'has_frozen_accounts' => false];

    // Check if there are any frozen accounts
    $query_count = "SELECT COUNT(*) as count FROM users WHERE role = 'siswa' AND is_frozen = 1";
    $result_count = $conn->query($query_count);
    $response['has_frozen_accounts'] = $result_count && $result_count->fetch_assoc()['count'] > 0;

    $query = "SELECT u.id, u.nama, j.nama_jurusan, k.nama_kelas, l.alasan, l.waktu 
              FROM users u 
              LEFT JOIN rekening r ON u.id = r.user_id 
              LEFT JOIN jurusan j ON u.jurusan_id = j.id 
              LEFT JOIN kelas k ON u.kelas_id = k.id 
              LEFT JOIN (
                  SELECT siswa_id, alasan, waktu 
                  FROM account_freeze_log 
                  WHERE action = 'freeze' 
                  AND id IN (
                      SELECT MAX(id) 
                      FROM account_freeze_log 
                      WHERE action = 'freeze' 
                      GROUP BY siswa_id
                  )
              ) l ON u.id = l.siswa_id
              WHERE u.role = 'siswa' AND u.is_frozen = 1";
    
    if (!empty($search_query)) {
        $query .= " AND u.nama LIKE ?";
    }
    $query .= " ORDER BY l.waktu DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $response['message'] = 'Kesalahan database: ' . $conn->error;
        echo json_encode($response);
        exit();
    }

    if (!empty($search_query)) {
        $search_term = "%$search_query%";
        $stmt->bind_param("s", $search_term);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $row['nama_kelas'] = $row['nama_kelas'] ?? 'Tidak ada';
        $row['nama_jurusan'] = $row['nama_jurusan'] ?? 'Tidak ada';
        $row['waktu'] = $row['waktu'] ? date('d-m-Y H:i', strtotime($row['waktu'])) : 'Tidak ada';
        $accounts[] = $row;
    }
    $stmt->close();

    if (count($accounts) > 0) {
        $response['status'] = 'success';
        $response['data'] = $accounts;
    } else {
        $response['message'] = empty($search_query) ? 'Tidak ada akun yang dibekukan.' : 'Hasil tidak ditemukan.';
    }

    echo json_encode($response);
    exit();
}

// Handle initial freeze form submission with PRG pattern
$show_confirm_popup = false;
$selected_siswa = null;
$alasan = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['ajax_freeze']) && !isset($_POST['ajax_unfreeze']) && !isset($_POST['ajax_search_frozen'])) {
    debugLog("Initial freeze submission: " . json_encode($_POST));

    if (!isset($_POST['siswa_id']) || !isset($_POST['alasan'])) {
        debugLog("Missing required fields: " . json_encode(array_keys($_POST)));
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Harap pilih siswa dan alasan pembekuan.'];
        header("Location: freeze_account.php");
        exit();
    }

    $siswa_id = intval($_POST['siswa_id']);
    $alasan = trim($_POST['alasan']);

    // Validate alasan
    $valid_reasons = ['Aktivitas mencurigakan', 'Permintaan pengguna', 'Pelanggaran ketentuan', 'Lainnya'];
    if (!in_array($alasan, $valid_reasons)) {
        debugLog("Validation failed: Invalid alasan ($alasan)");
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Alasan tidak valid.'];
        header("Location: freeze_account.php");
        exit();
    }

    if (empty($siswa_id)) {
        debugLog("Validation failed: Empty siswa_id");
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Harap pilih siswa.'];
        header("Location: freeze_account.php");
        exit();
    }

    $query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, k.nama_kelas, u.is_frozen 
              FROM users u 
              LEFT JOIN rekening r ON u.id = r.user_id 
              LEFT JOIN jurusan j ON u.jurusan_id = j.id 
              LEFT JOIN kelas k ON u.kelas_id = k.id 
              WHERE u.id = ? AND u.role = 'siswa'";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        debugLog("Database error: Failed to prepare query - " . $conn->error);
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Kesalahan database: ' . $conn->error];
        header("Location: freeze_account.php");
        exit();
    }
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_siswa = $result->fetch_assoc();
    $stmt->close();

    if (!$selected_siswa) {
        debugLog("Validation failed: Siswa tidak ditemukan (ID: $siswa_id)");
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Siswa tidak ditemukan.'];
        header("Location: freeze_account.php");
        exit();
    }

    if ($selected_siswa['is_frozen']) {
        debugLog("Validation failed: Akun sudah dibekukan (ID: $siswa_id)");
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Akun sudah dibekukan.'];
        header("Location: freeze_account.php");
        exit();
    }

    // Store data in session and redirect (PRG pattern)
    $_SESSION['freeze_confirm_data'] = [
        'siswa' => $selected_siswa,
        'alasan' => $alasan
    ];
    header("Location: freeze_account.php");
    exit();
}

// Check for session data to show confirmation popup
if (isset($_SESSION['freeze_confirm_data'])) {
    $show_confirm_popup = true;
    $selected_siswa = $_SESSION['freeze_confirm_data']['siswa'];
    $alasan = $_SESSION['freeze_confirm_data']['alasan'];
    unset($_SESSION['freeze_confirm_data']); // Clear session data
}

// Check if there are any frozen accounts
$query = "SELECT COUNT(*) as count FROM users WHERE role = 'siswa' AND is_frozen = 1";
$result = $conn->query($query);
$has_frozen_accounts = $result && $result->fetch_assoc()['count'] > 0;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Nonaktifkan Akun - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.4);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .clear-btn {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: #ddd;
            border: none;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #666;
            font-size: 12px;
            transition: var(--transition);
            display: none;
        }

        .clear-btn:hover {
            background: #ccc;
            color: #333;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            max-height: 300px;
            overflow-y: auto;
            z-index: 1000;
            margin-top: 5px;
            display: none;
        }

        .search-result-item {
            padding: 12px 15px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
        }

        .search-result-item:hover {
            background-color: #f0f5ff;
            color: var(--primary-dark);
        }

        .search-result-item.no-results {
            cursor: default;
            color: var(--text-secondary);
        }

        .search-result-item.no-results:hover {
            background-color: transparent;
        }

        select {
            appearance: none;
            position: relative;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--text-secondary);
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .table-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: clamp(0.9rem, 2vw, 1rem);
            margin-top: 20px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f0f5ff;
            color: var(--primary-dark);
            font-weight: 600;
        }

        td {
            color: var(--text-primary);
        }

        .action-btn {
            padding: 8px 15px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            border-radius: 10px;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: default;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(300px, 80vw, 400px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: default;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            margin: 0 auto 15px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 15px;
            font-size: clamp(1.2rem, 2.2vw, 1.3rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin: 0 0 20px;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin: 15px 0;
            padding: 15px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 15px;
            font-size: clamp(0.9rem, 1.8vw, 1rem);
        }

        .modal-label {
            font-weight: 500;
            color: #f0f5ff;
            flex: 1;
            text-align: left;
            opacity: 0.9;
        }

        .modal-value {
            font-weight: 600;
            color: #ffffff;
            flex: 1;
            text-align: right;
            max-width: 60%;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            margin-top: 15px;
            gap: 15px;
        }

        .modal-cancel-btn {
            background: #ffffff;
            color: var(--primary-dark);
            border: 1px solid var(--primary-dark);
            padding: 10px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .modal-cancel-btn:hover {
            background: #f0f5ff;
            transform: translateY(-2px);
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            display: none;
        }

        .error-message.show {
            display: block;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .success-modal, .error-modal {
                width: clamp(280px, 85vw, 360px);
                padding: clamp(15px, 3vw, 20px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2.3rem, 4.5vw, 2.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input, select {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(260px, 90vw, 320px);
                padding: clamp(12px, 2.5vw, 15px);
                margin: 8px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2rem, 4vw, 2.2rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }
        }
    </style>
    <script>
        // Prevent zooming and double-tap issues
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });

        // Close modal on overlay click
        document.addEventListener('click', (event) => {
            const confirmModal = document.getElementById('confirmModal');
            const unfreezeModal = document.getElementById('unfreezeModal');
            
            if (event.target.classList.contains('modal-overlay')) {
                if (confirmModal && confirmModal.style.display !== 'none') {
                    confirmModal.style.display = 'none';
                    window.location.href = 'freeze_account.php';
                }
                if (unfreezeModal && unfreezeModal.style.display !== 'none') {
                    unfreezeModal.style.display = 'none';
                }
            }
        });
    </script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-user-lock"></i> Nonaktifkan Akun Siswa</h2>
            <p>Fitur ini memungkinkan admin untuk menonaktifkan akun siswa sementara demi keamanan atau kepatuhan terhadap kebijakan, memastikan pengelolaan akses pengguna yang aman dan terkontrol.</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form action="" method="POST" id="freeze-form" class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="search_siswa">Cari Siswa (Nama)</label>
                        <div class="input-container">
                            <input type="text" id="search_siswa" name="search_siswa" placeholder="Ketik nama siswa..." autocomplete="off" required>
                            <button type="button" class="clear-btn" id="clear-btn"><i class="fas fa-times"></i></button>
                            <span class="tooltip">Masukkan nama siswa untuk mencari</span>
                        </div>
                        <input type="hidden" id="siswa_id" name="siswa_id" value="">
                        <div class="search-results" id="search-results"></div>
                        <span class="error-message" id="search_siswa-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="alasan">Alasan Pembekuan</label>
                        <div class="select-wrapper">
                            <select id="alasan" name="alasan" required>
                                <option value="">Pilih alasan pembekuan</option>
                                <option value="Aktivitas mencurigakan">Aktivitas mencurigakan</option>
                                <option value="Permintaan pengguna">Permintaan pengguna</option>
                                <option value="Pelanggaran ketentuan">Pelanggaran ketentuan</option>
                                <option value="Lainnya">Lainnya</option>
                            </select>
                            <span class="tooltip">Pilih alasan pembekuan akun</span>
                        </div>
                        <span class="error-message" id="alasan-error"></span>
                    </div>
                </div>
                <div class="form-buttons">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-lock"></i> Nonaktifkan Akun</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="table-section" id="frozen-accounts-section" style="display: <?php echo $has_frozen_accounts ? 'block' : 'none'; ?>">
            <h3>Akun Dinonaktifkan</h3>
            <div class="form-group">
                <label for="search_query">Cari Akun Beku (Nama)</label>
                <div class="input-container">
                    <input type="text" id="search_query" name="search_query" placeholder="Ketik nama siswa..." autocomplete="off">
                    <button type="button" class="clear-btn" id="clear-search-btn"><i class="fas fa-times"></i></button>
                    <span class="tooltip">Cari akun beku berdasarkan nama siswa</span>
                </div>
                <span class="error-message" id="search_query-error"></span>
            </div>
            <div id="frozen-accounts-table"></div>
        </div>

        <?php if ($show_confirm_popup): ?>
            <div class="modal-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>Konfirmasi Pembekuan Akun</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Nama Siswa</span>
                            <span class="modal-value"><?php echo htmlspecialchars($selected_siswa['nama'] ?? ''); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($selected_siswa['nama_kelas'] ?? 'Tidak ada'); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($selected_siswa['nama_jurusan'] ?? 'Tidak ada'); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Alasan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($alasan); ?></span>
                        </div>
                    </div>
                    <form id="confirm-form">
                        <input type="hidden" name="siswa_id" value="<?php echo $selected_siswa['id'] ?? ''; ?>">
                        <input type="hidden" name="alasan" value="<?php echo htmlspecialchars($alasan); ?>">
                        <input type="hidden" name="ajax_freeze" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn" id="confirm-btn">
                                <span class="btn-content">Konfirmasi</span>
                            </button>
                            <button type="button" class="modal-cancel-btn" onclick="document.getElementById('confirmModal').style.display='none'; window.location.href='freeze_account.php';">
                                Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="modal-overlay" id="unfreezeModal" style="display: none;">
            <div class="success-modal unfreeze-modal">
                <div class="success-icon">
                    <i class="fas fa-unlock"></i>
                </div>
                <h3>Konfirmasi Pembukaan Akun</h3>
                <div class="modal-content-confirm">
                    <div class="modal-row">
                        <span class="modal-label">Nama Siswa</span>
                        <span class="modal-value" id="unfreeze-nama"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Kelas</span>
                        <span class="modal-value" id="unfreeze-kelas"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Jurusan</span>
                        <span class="modal-value" id="unfreeze-jurusan"></span>
                    </div>
                    <div class="form-group">
                        <label for="alasan_unfreeze">Alasan Pembukaan</label>
                        <div class="select-wrapper">
                            <select id="alasan_unfreeze" name="alasan_unfreeze" required>
                                <option value="">Pilih alasan pembukaan</option>
                                <option value="Verifikasi selesai">Verifikasi selesai</option>
                                <option value="Permintaan pengguna">Permintaan pengguna</option>
                                <option value="Kesalahan sistem">Kesalahan sistem</option>
                                <option value="Lainnya">Lainnya</option>
                            </select>
                            <span class="tooltip">Pilih alasan pembukaan akun</span>
                        </div>
                        <span class="error-message" id="alasan_unfreeze-error"></span>
                    </div>
                </div>
                <form id="unfreeze-form">
                    <input type="hidden" name="siswa_id" id="unfreeze-siswa-id">
                    <input type="hidden" name="alasan_unfreeze" id="unfreeze-alasan-hidden">
                    <input type="hidden" name="ajax_unfreeze" value="1">
                    <div class="modal-buttons">
                        <button type="submit" class="btn" id="unfreeze-confirm-btn">
                            <span class="btn-content">Konfirmasi</span>
                        </button>
                        <button type="button" class="modal-cancel-btn" onclick="document.getElementById('unfreezeModal').style.display='none';">
                            Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
$(document).ready(function() {
    let frozenAccounts = [];
    let hasFrozenAccounts = <?php echo json_encode($has_frozen_accounts); ?>;

    function toggleClearButton(input, clearBtn) {
        if (input.val().trim().length > 0) {
            clearBtn.show();
        } else {
            clearBtn.hide();
        }
    }

    function showAlert(message, type) {
        const alertContainer = document.getElementById('alertContainer');
        const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
        existingAlerts.forEach(alert => {
            alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
            setTimeout(() => alert.remove(), 500);
        });
        const alertDiv = document.createElement('div');
        alertDiv.className = 'modal-overlay';
        alertDiv.innerHTML = `
            <div class="${type}-modal">
                <div class="${type}-icon">
                    <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                </div>
                <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                <p>${message}</p>
            </div>
        `;
        alertContainer.appendChild(alertDiv);
        setTimeout(() => closeModal(alertDiv.id), 5000);
        alertDiv.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-overlay')) {
                closeModal(alertDiv.id);
            }
        });
        alertDiv.id = 'alert-' + Date.now();
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
            setTimeout(() => modal.remove(), 500);
        }
    }

    function showLoadingSpinner(button) {
        console.log('[DEBUG] Showing loading spinner for button:', button.attr('id'));
        const originalContent = button.html();
        button.html('<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>').addClass('loading');
        return {
            restore: () => {
                button.html(originalContent).removeClass('loading');
            }
        };
    }

    function resetForm() {
        $('#freeze-form')[0].reset();
        $('#siswa_id').val('');
        $('#search_siswa').val('');
        $('#search-results').hide().empty();
        toggleClearButton($('#search_siswa'), $('#clear-btn'));
        $('#freeze-form').fadeIn(300);
    }

    function updateFrozenTable(accounts, showNoResultsPopup = false, isSearch = false) {
        frozenAccounts = accounts;
        const tableContainer = $('#frozen-accounts-table');
        const section = $('#frozen-accounts-section');

        hasFrozenAccounts = accounts.length > 0 || hasFrozenAccounts;

        if (!hasFrozenAccounts) {
            section.hide();
            tableContainer.empty();
            return;
        }

        section.show();
        tableContainer.empty();

        let tableHtml = `
            <table>
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Kelas</th>
                        <th>Jurusan</th>
                        <th>Alasan</th>
                        <th>Waktu</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>`;

        if (accounts.length > 0) {
            accounts.forEach(account => {
                const kelas = account.nama_kelas || 'Tidak ada';
                const jurusan = account.nama_jurusan || 'Tidak ada';
                tableHtml += `
                    <tr data-id="${account.id}">
                        <td>${account.nama}</td>
                        <td>${kelas}</td>
                        <td>${jurusan}</td>
                        <td>${account.alasan || 'Tidak ada'}</td>
                        <td>${account.waktu}</td>
                        <td>
                            <button class="btn action-btn" 
                                    data-id="${account.id}" 
                                    data-nama="${account.nama}" 
                                    data-kelas="${kelas}" 
                                    data-jurusan="${jurusan}">
                                <i class="fas fa-unlock"></i> Buka
                            </button>
                        </td>
                    </tr>`;
            });
        } else {
            tableHtml += `
                <tr>
                    <td colspan="6" style="text-align: center;">Hasil tidak ditemukan</td>
                </tr>`;
            if (showNoResultsPopup && isSearch) {
                showAlert('Tidak ada akun beku yang cocok dengan pencarian.', 'error');
            }
        }

        tableHtml += `
                </tbody>
            </table>`;
        tableContainer.html(tableHtml);
    }

    // Initial load of frozen accounts
    $.ajax({
        url: window.location.href,
        method: 'POST',
        data: { ajax_search_frozen: 1, search_query: '' },
        dataType: 'json',
        success: function(response) {
            console.log('[DEBUG] Initial frozen accounts load:', response);
            hasFrozenAccounts = response.has_frozen_accounts;
            if (response.status === 'success') {
                updateFrozenTable(response.data);
            } else {
                updateFrozenTable([], false, false);
            }
        },
        error: function(xhr, status, error) {
            console.error('[DEBUG] Error loading frozen accounts:', status, error, xhr.responseText);
            updateFrozenTable([], false, false);
            showAlert('Gagal memuat akun beku. Coba lagi.', 'error');
        }
    });

    // Student search functionality
    const searchInput = $('#search_siswa');
    const siswaIdInput = $('#siswa_id');
    const searchResults = $('#search-results');
    const clearBtn = $('#clear-btn');

    toggleClearButton(searchInput, clearBtn);

    let debounceTimeout;
    searchInput.on('input', function() {
        clearTimeout(debounceTimeout);
        const query = $(this).val().trim();
        toggleClearButton(searchInput, clearBtn);

        if (query.length < 2) {
            searchResults.hide().empty();
            siswaIdInput.val('');
            $('#search_siswa-error').removeClass('show').text('');
            return;
        }

        debounceTimeout = setTimeout(() => {
            $.ajax({
                url: 'search_students.php',
                method: 'POST',
                data: { query: query },
                dataType: 'json',
                success: function(response) {
                    console.log('[DEBUG] Student search response:', response);
                    searchResults.empty();
                    if (response.status === 'success' && response.students.length > 0) {
                        response.students.forEach(student => {
                            if (student.is_frozen) return; // Skip frozen accounts
                            const kelas = student.nama_kelas || 'Tidak ada';
                            const jurusan = student.nama_jurusan || 'Tidak ada';
                            const displayText = `${student.nama} - ${kelas}`;
                            const item = $('<div>')
                                .addClass('search-result-item')
                                .text(displayText)
                                .data('id', student.id)
                                .data('display', displayText);
                            searchResults.append(item);
                        });
                        searchResults.show();
                    } else {
                        searchResults.append('<div class="search-result-item no-results">Tidak ada siswa ditemukan</div>');
                        searchResults.show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEBUG] Search AJAX error:', status, error, xhr.responseText);
                    searchResults.empty().append('<div class="search-result-item no-results">Gagal mencari siswa</div>').show();
                    showAlert('Gagal mencari siswa. Coba lagi.', 'error');
                }
            });
        }, 500);
    });

    searchResults.on('click', '.search-result-item:not(.no-results)', function() {
        const selected = $(this);
        searchInput.val(selected.data('display'));
        siswaIdInput.val(selected.data('id'));
        searchResults.hide().empty();
        toggleClearButton(searchInput, clearBtn);
        $('#search_siswa-error').removeClass('show').text('');
    });

    $(document).on('click', function(e) {
        if (!searchInput.is(e.target) && !searchResults.is(e.target) && searchResults.has(e.target).length === 0 && !clearBtn.is(e.target)) {
            searchResults.hide().empty();
        }
    });

    clearBtn.on('click', function() {
        searchInput.val('');
        siswaIdInput.val('');
        searchResults.hide().empty();
        toggleClearButton(searchInput, clearBtn);
        searchInput.focus();
        $('#search_siswa-error').removeClass('show').text('');
    });

    // Frozen accounts search
    const searchFrozenInput = $('#search_query');
    const clearSearchBtn = $('#clear-search-btn');

    if (searchFrozenInput.length) {
        toggleClearButton(searchFrozenInput, clearSearchBtn);

        let searchDebounceTimeout;
        searchFrozenInput.on('input', function() {
            clearTimeout(searchDebounceTimeout);
            const query = $(this).val().trim();
            toggleClearButton(searchFrozenInput, clearSearchBtn);

            searchDebounceTimeout = setTimeout(() => {
                console.log('[DEBUG] Searching frozen accounts with query:', query);
                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: { ajax_search_frozen: 1, search_query: query },
                    dataType: 'json',
                    success: function(response) {
                        console.log('[DEBUG] Frozen search response:', response);
                        hasFrozenAccounts = response.has_frozen_accounts;
                        if (response.status === 'success') {
                            updateFrozenTable(response.data, false, !!query);
                        } else {
                            updateFrozenTable([], true, !!query);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('[DEBUG] Frozen search AJAX error:', status, error, xhr.responseText);
                        showAlert('Gagal mencari akun beku. Coba lagi.', 'error');
                        updateFrozenTable(frozenAccounts, false, !!query);
                    }
                });
            }, 300);
        });

        clearSearchBtn.on('click', function() {
            searchFrozenInput.val('');
            toggleClearButton(searchFrozenInput, clearSearchBtn);
            console.log('[DEBUG] Clearing frozen accounts search');
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: { ajax_search_frozen: 1, search_query: '' },
                dataType: 'json',
                success: function(response) {
                    console.log('[DEBUG] Clear search response:', response);
                    hasFrozenAccounts = response.has_frozen_accounts;
                    if (response.status === 'success') {
                        updateFrozenTable(response.data, false, false);
                    } else {
                        updateFrozenTable([], false, false);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEBUG] Clear search AJAX error:', status, error, xhr.responseText);
                    showAlert('Gagal memuat akun beku. Coba lagi.', 'error');
                    updateFrozenTable([], false, false);
                }
            });
            searchFrozenInput.focus();
        });
    }

    $('#freeze-form').on('submit', function(e) {
        e.preventDefault();
        const button = $('#submit-btn');
        console.log('[DEBUG] Freeze form submission');

        if (!siswaIdInput.val()) {
            $('#search_siswa-error').text('Harap pilih siswa dari daftar.').addClass('show');
            searchInput.focus();
            return;
        } else {
            $('#search_siswa-error').removeClass('show').text('');
        }

        if (!$('#alasan').val()) {
            $('#alasan-error').text('Harap pilih alasan pembekuan.').addClass('show');
            $('#alasan').focus();
            return;
        } else {
            $('#alasan-error').removeClass('show').text('');
        }

        const spinner = showLoadingSpinner(button);
        setTimeout(() => {
            spinner.restore();
            this.submit();
        }, 1500);
    });

    $('#confirm-form').on('submit', function(e) {
        e.preventDefault();
        const button = $('#confirm-btn');
        console.log('[DEBUG] Freeze confirmation submission');
        const spinner = showLoadingSpinner(button);

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                spinner.restore();
                console.log('[DEBUG] Freeze confirmation response:', response);
                $('#confirmModal').fadeOut(300, () => {
                    resetForm();
                    showAlert(response.message, response.status);
                    if (response.status === 'success') {
                        frozenAccounts.unshift(response.new_frozen_account);
                        hasFrozenAccounts = response.has_frozen_accounts;
                        updateFrozenTable(frozenAccounts, false, !!$('#search_query').val().trim());
                    }
                });
            },
            error: function(xhr, status, error) {
                spinner.restore();
                console.error('[DEBUG] Freeze AJAX error:', status, error, xhr.responseText);
                showAlert('Gagal membekukan akun. Coba lagi.', 'error');
            }
        });
    });

    $(document).on('click', '.action-btn', function() {
        const siswaId = $(this).data('id');
        const nama = $(this).data('nama');
        const kelas = $(this).data('kelas');
        const jurusan = $(this).data('jurusan');
        console.log('[DEBUG] Opening unfreeze modal for siswa ID:', siswaId);
        $('#unfreeze-siswa-id').val(siswaId);
        $('#unfreeze-nama').text(nama);
        $('#unfreeze-kelas').text(kelas);
        $('#unfreeze-jurusan').text(jurusan);
        $('#alasan_unfreeze').val('');
        $('#unfreezeModal').fadeIn(300);
    });

    $('#unfreeze-form').on('submit', function(e) {
        e.preventDefault();
        const button = $('#unfreeze-confirm-btn');
        console.log('[DEBUG] Unfreeze form submission');

        const alasan = $('#alasan_unfreeze').val();
        if (!alasan) {
            $('#alasan_unfreeze-error').text('Harap pilih alasan pembukaan.').addClass('show');
            $('#alasan_unfreeze').focus();
            return;
        } else {
            $('#alasan_unfreeze-error').removeClass('show').text('');
        }

        $('#unfreeze-alasan-hidden').val(alasan);
        const spinner = showLoadingSpinner(button);

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                spinner.restore();
                console.log('[DEBUG] Unfreeze response:', response);
                $('#unfreezeModal').fadeOut(300, () => {
                    resetForm();
                    showAlert(response.message, response.status);
                    if (response.status === 'success') {
                        frozenAccounts = frozenAccounts.filter(account => account.id !== response.removed_siswa_id);
                        hasFrozenAccounts = response.has_frozen_accounts;
                        updateFrozenTable(frozenAccounts, false, !!$('#search_query').val().trim());
                    }
                });
            },
            error: function(xhr, status, error) {
                spinner.restore();
                console.error('[DEBUG] Unfreeze AJAX error:', status, error, xhr.responseText);
                showAlert('Gagal membuka akun. Coba lagi.', 'error');
            }
        });
    });

    // Handle session-based popup
    <?php if (isset($_SESSION['show_popup'])): ?>
        showAlert('<?php echo addslashes($_SESSION['show_popup']['message']); ?>', '<?php echo $_SESSION['show_popup']['type']; ?>');
        <?php unset($_SESSION['show_popup']); ?>
    <?php endif; ?>
});
    </script>
</body>
</html>