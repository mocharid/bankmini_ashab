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
    $jurusan = $siswa['nama_jurusan'] ?? '-';
    $kelas = $siswa['nama_kelas'] ?? '-';
    $tanggal = date('d M Y H:i:s');
    $email = $siswa['email'];

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'none';
    }

    $action_text = $action === 'freeze' ? 'Pembekuan Akun' : 'Pembukaan Akun';
    $action_verb = $action === 'freeze' ? 'dibekukan' : 'dibuka kembali';
    $subject = "Pemberitahuan {$action_text} - SCHOBANK SYSTEM";

    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
        <title>Pemberitahuan {$action_text} - SCHOBANK SYSTEM</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.5; 
                color: #333; 
                background-color: #f5f5f5;
                margin: 0;
                padding: 15px;
                font-size: 14px;
            }
            .container { 
                max-width: 580px; 
                margin: 0 auto; 
                padding: 20px; 
                border: 1px solid #e0e0e0; 
                border-radius: 6px;
                background-color: white;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            h2 { 
                color: #0a2e5c; 
                border-bottom: 1px solid #e0e0e0; 
                padding-bottom: 8px; 
                margin-top: 0;
                text-align: center;
                font-size: 16px;
                font-weight: 600;
            }
            .details {
                background-color: #fafafa;
                border: 1px solid #f0f0f0;
                border-radius: 4px;
                padding: 12px;
                margin: 15px 0;
            }
            .detail-row {
                display: flex;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            .detail-row:last-child {
                border-bottom: none;
            }
            .label {
                font-weight: 600;
                color: #2c3e50;
                width: 40%;
                position: relative;
                padding-right: 8px;
            }
            .label::after {
                content: ':';
                position: absolute;
                right: 8px;
            }
            .value {
                width: 60%;
                padding-left: 8px;
                text-align: left;
            }
            p {
                color: #34495e;
                margin: 8px 0;
            }
            .security-notice {
                background-color: #fff8e1;
                border-left: 3px solid #f39c12;
                padding: 8px 12px;
                margin: 15px 0;
                font-size: 12px;
                color: #7f8c8d;
            }
            .footer { 
                margin-top: 20px; 
                font-size: 12px; 
                color: #666; 
                border-top: 1px solid #e0e0e0; 
                padding-top: 10px; 
                text-align: center;
            }
            @media (max-width: 600px) {
                .container { padding: 15px; }
                .detail-row { flex-direction: column; }
                .label, .value { width: 100%; padding: 0; }
                .label::after { content: ''; }
                .value { margin-top: 4px; }
            }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <h2>Pemberitahuan {$action_text}</h2>
            <p>Yth. {$nama_siswa},</p>
            <p>Kami informasikan bahwa akun Anda telah {$action_verb}. Berikut rincian:</p>
            <div class=\"details\">
                <div class=\"detail-row\">
                    <div class=\"label\">Nama Pemilik</div>
                    <div class=\"value\">{$nama_siswa}</div>
                </div>
                <div class=\"detail-row\">
                    <div class=\"label\">Jurusan</div>
                    <div class=\"value\">{$jurusan}</div>
                </div>
                <div class=\"detail-row\">
                    <div class=\"label\">Kelas</div>
                    <div class=\"value\">{$kelas}</div>
                </div>
                <div class=\"detail-row\">
                    <div class=\"label\">Alasan</div>
                    <div class=\"value\">{$alasan}</div>
                </div>
                <div class=\"detail-row\">
                    <div class=\"label\">Tanggal</div>
                    <div class=\"value\">{$tanggal} WIB</div>
                </div>
            </div>
            <p>" . ($action === 'freeze' ? 'Akun Anda tidak dapat digunakan hingga dibuka kembali.' : 'Pastikan akun Anda terhindar dari aktivitas yang tidak wajar.') . " Untuk informasi lebih lanjut, silakan hubungi petugas kami.</p>
            <div class=\"security-notice\">
                <strong>Perhatian Keamanan:</strong> Jangan bagikan informasi rekening, kata sandi, atau detail akun kepada pihak lain. Petugas SCHOBANK tidak akan meminta informasi tersebut melalui email atau telepon.
            </div>
            <div class=\"footer\">
                <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                <p>Jika Anda memiliki pertanyaan, silakan hubungi Bank Mini SMK Plus Ashabulyamin.</p>
                <p>© " . date('Y') . " SCHOBANK SYSTEM - Hak cipta dilindungi.</p>
            </div>
        </div>
    </body>
    </html>
    ";

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
    <title>Freeze/Unfreeze Akun - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 0.125rem 0.625rem rgba(0, 0, 0, 0.05);
            --shadow-md: 0 0.3125rem 0.9375rem rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --spacing-sm: 0.75rem;
            --spacing-md: 1.25rem;
            --spacing-lg: 2rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-text-size-adjust: 100%;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.875rem, 2.5vw, 1rem);
            line-height: 1.5;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: var(--spacing-md) var(--spacing-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
        }

        .top-nav h1 {
            font-size: clamp(1.25rem, 3vw, 1.5rem);
            font-weight: 600;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: var(--spacing-sm);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: clamp(2rem, 5vw, 2.5rem);
            height: clamp(2rem, 5vw, 2.5rem);
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.125rem);
        }

        .main-content {
            flex: 1;
            padding: var(--spacing-lg);
            width: 100%;
            max-width: 75rem;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: var(--spacing-lg);
            border-radius: 0.9375rem;
            margin-bottom: var(--spacing-lg);
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
            from { opacity: 0; transform: translateY(-1.25rem); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            font-size: clamp(1.25rem, 3.5vw, 1.75rem);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            position: relative;
            z-index: 1;
        }

        .form-card {
            background: white;
            border-radius: 0.9375rem;
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-1.25rem); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
            gap: var(--spacing-md);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
            position: relative;
            width: 100%;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.8vw, 0.875rem);
        }

        input[type="text"],
        input[type="hidden"] {
            width: 100%;
            padding: var(--spacing-sm);
            border: 1px solid #ddd;
            border-radius: 0.625rem;
            font-size: clamp(0.875rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: #fff;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.1875rem rgba(12, 77, 162, 0.1);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .clear-btn {
            position: absolute;
            right: var(--spacing-sm);
            top: 50%;
            transform: translateY(-50%);
            background: #ddd;
            border: none;
            border-radius: 50%;
            width: 1.5rem;
            height: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #666;
            font-size: 0.75rem;
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
            border-radius: 0.625rem;
            box-shadow: var(--shadow-md);
            max-height: 18.75rem;
            overflow-y: auto;
            z-index: 1000;
            margin-top: var(--spacing-sm);
            display: none;
        }

        .search-result-item {
            padding: var(--spacing-sm) var(--spacing-md);
            font-size: clamp(0.875rem, 2vw, 1rem);
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
        }

        .search-result-item:hover {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .search-result-item.no-results {
            cursor: default;
            color: var(--text-secondary);
        }

        .search-result-item.no-results:hover {
            background-color: transparent;
        }

        textarea {
            width: 100%;
            padding: var(--spacing-sm);
            border: 1px solid #ddd;
            border-radius: 0.625rem;
            font-size: clamp(0.875rem, 2vw, 1rem);
            resize: vertical;
            min-height: 6.25rem;
            transition: var(--transition);
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.1875rem rgba(12, 77, 162, 0.1);
        }

        button[type="submit"],
        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: 0.625rem;
            cursor: pointer;
            font-size: clamp(0.875rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            text-decoration: none;
            justify-content: center;
        }

        button[type="submit"]:hover,
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-0.125rem);
        }

        .btn-confirm {
            background: var(--secondary-color);
        }

        .btn-confirm:hover {
            background: var(--secondary-dark);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: 0.625rem;
            cursor: pointer;
            font-size: clamp(0.875rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background: #e0e0e0;
            transform: translateY(-0.125rem);
        }

        .btn-unfreeze {
            background: var(--secondary-color);
        }

        .btn-unfreeze:hover {
            background: var(--secondary-dark);
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .success-modal {
            background: white;
            border-radius: 1.25rem;
            padding: var(--spacing-lg);
            text-align: center;
            max-width: 90%;
            width: clamp(18.75rem, 80vw, 28.125rem);
            box-shadow: 0 0.9375rem 2.5rem rgba(0, 0, 0, 0.2);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon,
        .error-icon {
            font-size: clamp(3rem, 8vw, 4rem);
            margin-bottom: var(--spacing-md);
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .error-icon {
            color: var(--danger-color);
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: var(--spacing-md);
            font-size: clamp(1.25rem, 3vw, 1.5rem);
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.875rem, 2.2vw, 1rem);
            margin-bottom: var(--spacing-md);
            line-height: 1.5;
        }

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
            margin-bottom: var(--spacing-md);
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--spacing-sm) 0;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.875rem, 2vw, 1rem);
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
            max-width: 60%;
        }

        .modal-buttons {
            display: flex;
            gap: var(--spacing-md);
            justify-content: center;
            margin-top: var(--spacing-md);
            flex-wrap: wrap;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .table-card {
            background: white;
            border-radius: 0.9375rem;
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: clamp(0.875rem, 2vw, 1rem);
            margin-top: var(--spacing-md);
        }

        th, td {
            padding: var(--spacing-md);
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 600;
        }

        td {
            color: var(--text-primary);
        }

        .action-btn {
            padding: var(--spacing-sm);
            font-size: clamp(0.75rem, 1.8vw, 0.875rem);
        }

        .unfreeze-modal textarea {
            margin-top: var(--spacing-sm);
        }

        select {
            width: 100%;
            padding: var(--spacing-sm);
            font-size: clamp(0.875rem, 2vw, 1rem);
            color: #333;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 0.625rem;
            outline: none;
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg fill="%23333" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>');
            background-repeat: no-repeat;
            background-position: right var(--spacing-sm) center;
            background-size: 16px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }

        select:focus {
            border-color: #007bff;
            box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
        }

        select:hover {
            border-color: #999;
        }

        select:invalid {
            color: #999;
        }

        select option {
            color: #333;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .top-nav {
                padding: var(--spacing-sm) var(--spacing-md);
            }

            .main-content {
                padding: var(--spacing-md);
            }

            .welcome-banner h2 {
                font-size: clamp(1.125rem, 3vw, 1.5rem);
            }

            .form-card {
                padding: var(--spacing-md);
            }

            button[type="submit"],
            .btn {
                width: 100%;
            }

            .modal-buttons {
                flex-direction: column;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.75rem, 2.5vw, 0.875rem);
            }

            .top-nav h1 {
                font-size: clamp(1rem, 2.5vw, 1.25rem);
            }

            .welcome-banner {
                padding: var(--spacing-md);
            }

            .success-modal h3 {
                font-size: clamp(1.125rem, 2.5vw, 1.25rem);
            }

            .success-modal p {
                font-size: clamp(0.75rem, 2vw, 0.875rem);
            }

            select {
                font-size: clamp(0.75rem, 2vw, 0.875rem);
                padding: var(--spacing-sm);
            }
        }

        body {
            touch-action: manipulation;
            -webkit-text-size-adjust: 100%;
            -webkit-user-select: text;
            user-select: text;
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: clamp(2rem, 5vw, 2.5rem);"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Nonaktifkan Akun Siswa</h2>
        </div>

        <div class="success-overlay" id="popupModal" style="display: none;">
            <div class="success-modal">
                <div class="popup-icon">
                    <i class="fas"></i>
                </div>
                <h3 class="popup-title"></h3>
                <p class="popup-message"></p>
            </div>
        </div>

        <div class="form-card" id="freeze-form-card">
            <form action="" method="POST" id="freeze-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="search_siswa">Cari Siswa (Nama):</label>
                        <div class="input-container">
                            <input type="text" id="search_siswa" name="search_siswa" placeholder="Ketik nama siswa..." autocomplete="off" required>
                            <button type="button" class="clear-btn" id="clear-btn"><i class="fas fa-times"></i></button>
                        </div>
                        <input type="hidden" id="siswa_id" name="siswa_id" value="">
                        <div class="search-results" id="search-results"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label for="alasan">Alasan Pembekuan:</label>
                    <select id="alasan" name="alasan" required>
                        <option value="" disabled selected>Pilih alasan pembekuan</option>
                        <option value="Aktivitas mencurigakan">Aktivitas mencurigakan</option>
                        <option value="Permintaan pengguna">Permintaan pengguna</option>
                        <option value="Pelanggaran ketentuan">Pelanggaran ketentuan</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>
                <button type="submit" id="submitBtn" class="btn">
                    <i class="fas fa-lock"></i> Nonaktifkan Akun
                </button>
            </form>
        </div>

        <div class="table-card" id="frozen-accounts-section" style="display: <?php echo $has_frozen_accounts ? 'block' : 'none'; ?>">
            <h3>Akun Dinonaktifkan</h3>
            <div class="form-group">
                <label for="search_query">Cari Akun Beku (Nama):</label>
                <div class="input-container">
                    <input type="text" id="search_query" name="search_query" placeholder="Ketik nama siswa..." autocomplete="off">
                    <button type="button" class="clear-btn" id="clear-search-btn"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <div id="frozen-accounts-table"></div>
        </div>

        <?php if ($show_confirm_popup): ?>
            <div class="success-overlay no-close" id="confirmModal">
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
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn-cancel" onclick="window.location.href='freeze_account.php'">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="success-overlay no-close" id="unfreezeModal" style="display: none;">
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
                        <label for="alasan_unfreeze">Alasan Pembukaan:</label>
                        <select id="alasan_unfreeze" name="alasan_unfreeze" required>
                            <option value="" disabled selected>Pilih alasan pembukaan</option>
                            <option value="Verifikasi selesai">Verifikasi selesai</option>
                            <option value="Permintaan pengguna">Permintaan pengguna</option>
                            <option value="Kesalahan sistem">Kesalahan sistem</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>
                </div>
                <form id="unfreeze-form">
                    <input type="hidden" name="siswa_id" id="unfreeze-siswa-id">
                    <input type="hidden" name="alasan_unfreeze" id="unfreeze-alasan-hidden">
                    <input type="hidden" name="ajax_unfreeze" value="1">
                    <div class="modal-buttons">
                        <button type="submit" class="btn btn-confirm" id="unfreeze-confirm-btn">
                            <i class="fas fa-check"></i> Konfirmasi
                        </button>
                        <button type="button" class="btn-cancel" id="unfreeze-cancel-btn">
                            <i class="fas fa-times"></i> Batal
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
        const popupModal = $('#popupModal');
        popupModal.find('.popup-title').text(type === 'success' ? 'BERHASIL' : 'PERINGATAN');
        popupModal.find('.popup-message').text(message);
        popupModal.find('.popup-icon')
            .removeClass('success-icon error-icon')
            .addClass(type === 'success' ? 'success-icon' : 'error-icon')
            .find('i')
            .removeClass('fa-check-circle fa-exclamation-circle')
            .addClass(type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
        popupModal.fadeIn(300);
        setTimeout(() => {
            popupModal.fadeOut(300);
            $('#search_query').focus();
        }, 3000);
    }

    function showLoadingSpinner(button) {
        console.log('[DEBUG] Showing loading spinner for button:', button.attr('id'));
        const originalContent = button.html();
        button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
        return {
            restore: () => {
                button.html(originalContent).prop('disabled', false);
            }
        };
    }

    function resetForm() {
        $('#freeze-form')[0].reset();
        $('#siswa_id').val('');
        $('#search_siswa').val('');
        $('#search-results').hide().empty();
        toggleClearButton($('#search_siswa'), $('#clear-btn'));
        $('#freeze-form-card').fadeIn(300);
    }

    function updateFrozenTable(accounts, showNoResultsPopup = false, isSearch = false) {
        frozenAccounts = accounts;
        const tableContainer = $('#frozen-accounts-table');
        const section = $('#frozen-accounts-section');

        // Update hasFrozenAccounts based on accounts length or response
        hasFrozenAccounts = accounts.length > 0 || hasFrozenAccounts;

        // Hide section if no frozen accounts exist
        if (!hasFrozenAccounts) {
            section.hide();
            tableContainer.empty();
            return;
        }

        // Show section if there are frozen accounts
        section.show();
        tableContainer.empty();

        // Render table structure
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
                tableHtml += `
                    <tr data-id="${account.id}">
                        <td>${account.nama}</td>
                        <td>${account.nama_kelas || 'Tidak ada'}</td>
                        <td>${account.nama_jurusan || 'Tidak ada'}</td>
                        <td>${account.alasan || 'Tidak ada'}</td>
                        <td>${account.waktu}</td>
                        <td>
                            <button class="btn btn-unfreeze action-btn" 
                                    data-id="${account.id}" 
                                    data-nama="${account.nama}" 
                                    data-kelas="${account.nama_kelas || 'Tidak ada'}" 
                                    data-jurusan="${account.nama_jurusan || 'Tidak ada'}">
                                <i class="fas fa-unlock"></i> Buka
                            </button>
                        </td>
                    </tr>`;
            });
        } else {
            // Display "Hasil tidak ditemukan" for searches with no results
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

        if (query.length < 1) {
            searchResults.hide().empty();
            siswaIdInput.val('');
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
                    if (response.status === 'success') {
                        if (response.students.length > 0) {
                            response.students.forEach(student => {
                                if (student.is_frozen) return;
                                const displayText = `${student.nama} - ${student.kelas}`;
                                const item = $('<div>')
                                    .addClass('search-result-item')
                                    .html(displayText)
                                    .data('id', student.id)
                                    .data('display', displayText);
                                searchResults.append(item);
                            });
                        } else {
                            searchResults.append('<div class="search-result-item no-results">Tidak ada siswa ditemukan</div>');
                        }
                        searchResults.show();
                    } else {
                        showAlert(response.message || 'Gagal mencari siswa.', 'error');
                        searchResults.hide();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DEBUG] Search AJAX error:', status, error, xhr.responseText);
                    showAlert('Gagal mencari siswa. Coba lagi.', 'error');
                    searchResults.hide();
                }
            });
        }, 300);
    });

    searchResults.on('click', '.search-result-item:not(.no-results)', function() {
        const selected = $(this);
        searchInput.val(selected.data('display'));
        siswaIdInput.val(selected.data('id'));
        searchResults.hide().empty();
        toggleClearButton(searchInput, clearBtn);
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
    });

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
        const button = $('#submitBtn');
        console.log('[DEBUG] Freeze form submission');

        if (!siswaIdInput.val()) {
            showAlert('Harap pilih siswa dari daftar.', 'error');
            return;
        }

        if (!$('#alasan').val()) {
            showAlert('Harap pilih alasan pembekuan.', 'error');
            return;
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

    $(document).on('click', '.btn-unfreeze', function() {
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
            showAlert('Harap pilih alasan pembukaan.', 'error');
            return;
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
                showAlert('Gagal mengaktifkan akun. Coba lagi.', 'error');
            }
        });
    });

    $('#unfreeze-cancel-btn').on('click', function() {
        console.log('[DEBUG] Cancel unfreeze');
        $('#unfreezeModal').fadeOut(300, () => {
            resetForm();
        });
    });

    $('.success-overlay:not(.no-close)').on('click', function(e) {
        if ($(e.target).hasClass('success-overlay')) {
            $(this).fadeOut(300);
        }
    });

    <?php if (isset($_SESSION['show_popup'])): ?>
        showAlert('<?php echo addslashes($_SESSION['show_popup']['message']); ?>', '<?php echo $_SESSION['show_popup']['type']; ?>');
        <?php unset($_SESSION['show_popup']); ?>
    <?php endif; ?>
});
    </script>
</body>
</html>