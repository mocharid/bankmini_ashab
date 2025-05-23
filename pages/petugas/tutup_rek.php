<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check for Composer autoloader (for PHPMailer)
$autoload_path = '../../vendor/autoload.php';
$phpmailer_available = false;
if (file_exists($autoload_path)) {
    require_once $autoload_path;
    $phpmailer_available = true;
} else {
    error_log("Composer autoloader not found at $autoload_path. Email sending will be disabled.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Session check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

// Clear formData on initial page load (GET request)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['formData']);
}

// Initialize variables
$error = '';
$success = false;
$nasabah = null;
$email_status = '';
$showConfirmation = false;
$formData = [];

// Function to obscure email
function obscureEmail($email) {
    if (empty($email)) {
        return '-';
    }
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return $email; // Invalid email format
    }
    $username = $parts[0];
    $domain = $parts[1];
    if (strlen($username) <= 2) {
        return $username . '@' . $domain;
    }
    return substr($username, 0, 1) . '****' . substr($username, -1) . '@' . $domain;
}

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = true;
    $email_status = $_SESSION['email_status'] ?? '';
    unset($_SESSION['success_message'], $_SESSION['email_status']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['nasabah'])) {
    $nasabah = $_SESSION['nasabah'];
    $showConfirmation = true;
    $formData = $_SESSION['nasabah'];
    unset($_SESSION['nasabah']);
}

// Load form data from session if available (after POST with errors)
if (isset($_SESSION['formData'])) {
    $formData = $_SESSION['formData'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['cancel'])) {
        unset($_SESSION['formData']);
        header('Location: tutup_rek.php');
        exit();
    }

    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $pin = $_POST['pin'] ?? '';

    // Store form data in session
    $_SESSION['formData'] = [
        'no_rekening' => $no_rekening,
        'pin' => $pin
    ];

    // Validate inputs
    $errors = [];
    if (empty($no_rekening) || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
        $errors['no_rekening'] = 'Nomor rekening tidak valid. Format: REK + 6 digit angka.';
    }
    if (empty($pin)) {
        $errors['pin'] = 'PIN wajib diisi.';
    } elseif (!preg_match('/^\d{6}$/', $pin)) {
        $errors['pin'] = 'PIN harus terdiri dari 6 digit angka.';
    }

    if (empty($errors)) {
        // Fetch account details
        $query = "SELECT r.id AS rekening_id, r.saldo, r.user_id, u.nama, u.username, u.tanggal_lahir, u.email, 
                         u.jurusan_id, u.kelas_id, j.nama_jurusan, k.nama_kelas, r.no_rekening, u.pin, u.has_pin 
                  FROM rekening r 
                  JOIN users u ON r.user_id = u.id 
                  LEFT JOIN jurusan j ON u.jurusan_id = j.id
                  LEFT JOIN kelas k ON u.kelas_id = k.id
                  WHERE r.no_rekening = ? AND u.is_frozen = 0 AND u.role = 'siswa'";
        
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            $_SESSION['error_message'] = 'Kesalahan server: Gagal mempersiapkan query.';
            error_log("Prepare failed for account lookup: " . $conn->error);
            header('Location: tutup_rek.php');
            exit();
        }

        $stmt->bind_param("s", $no_rekening);
        if (!$stmt->execute()) {
            $_SESSION['error_message'] = 'Kesalahan server: Gagal menjalankan query.';
            error_log("Execute failed for account lookup: " . $stmt->error);
            header('Location: tutup_rek.php');
            exit();
        }

        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $nasabah = $result->fetch_assoc();

            // Verify PIN
            if (!$nasabah['has_pin']) {
                $_SESSION['error_message'] = 'Penghapusan gagal: Pengguna belum mengatur PIN.';
                header('Location: tutup_rek.php');
                exit();
            }
            if ($nasabah['pin'] !== $pin) {
                $_SESSION['error_message'] = 'PIN yang dimasukkan salah.';
                header('Location: tutup_rek.php');
                exit();
            }

            $_SESSION['nasabah'] = $nasabah;
            $showConfirmation = true;
            $formData = [
                'user_id' => $nasabah['user_id'],
                'no_rekening' => $no_rekening,
                'nama' => $nasabah['nama'],
                'username' => $nasabah['username'],
                'tanggal_lahir' => $nasabah['tanggal_lahir'],
                'email' => $nasabah['email'],
                'nama_jurusan' => $nasabah['nama_jurusan'],
                'nama_kelas' => $nasabah['nama_kelas'],
                'saldo' => $nasabah['saldo'],
                'rekening_id' => $nasabah['rekening_id']
            ];
            $_SESSION['formData'] = $formData;

            // Handle account and user deletion
            if (isset($_POST['confirmed'])) {
                $rekening_id = $nasabah['rekening_id'];
                $user_id = $nasabah['user_id'];
                $admin_id = $_SESSION['user_id'];
                $saldo = $nasabah['saldo'];
                $email = $nasabah['email'];
                $reason = trim($_POST['reason'] ?? '');

                // Validate input data
                if (empty($reason)) {
                    $_SESSION['error_message'] = 'Alasan penghapusan wajib diisi.';
                    header('Location: tutup_rek.php');
                    exit();
                }
                if (!is_numeric($rekening_id) || !is_numeric($user_id) || !is_numeric($admin_id)) {
                    $_SESSION['error_message'] = 'Kesalahan data: ID rekening, pengguna, atau admin tidak valid.';
                    error_log("Invalid input data: rekening_id=$rekening_id, user_id=$user_id, admin_id=$admin_id");
                    header('Location: tutup_rek.php');
                    exit();
                }
                if ($saldo > 0) {
                    $_SESSION['error_message'] = 'Penghapusan gagal: Saldo Rp ' . number_format($saldo, 0, ',', '.') . ' masih ada. Kosongkan saldo terlebih dahulu.';
                    header('Location: tutup_rek.php');
                    exit();
                }

                $conn->begin_transaction();
                try {
                    // Delete related mutasi
                    $query = "DELETE m FROM mutasi m 
                             JOIN transaksi t ON m.transaksi_id = t.id 
                             WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for mutasi deletion: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $rekening_id, $rekening_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed for mutasi deletion: " . $stmt->error);
                    }
                    $stmt->close();

                    // Delete related transaksi
                    $query = "DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for transaksi deletion: " . $conn->error);
                    }
                    $stmt->bind_param("ii", $rekening_id, $rekening_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed for transaksi deletion: " . $stmt->error);
                    }
                    $stmt->close();

                    // Delete rekening
                    $query = "DELETE FROM rekening WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for rekening deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $rekening_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed for rekening deletion: " . $stmt->error);
                    }
                    $stmt->close();

                    // Delete related user data
                    $tables = [
                        'active_sessions' => ['column' => 'user_id', 'id' => $user_id],
                        'password_reset' => ['column' => 'user_id', 'id' => $user_id],
                        'absensi' => ['column' => 'user_id', 'id' => $user_id],
                        'notifications' => ['column' => 'user_id', 'id' => $user_id],
                        'log_aktivitas' => ['column' => 'siswa_id', 'id' => $user_id],
                        'account_freeze_log' => ['column' => 'siswa_id', 'id' => $user_id],
                        'reset_request_cooldown' => ['column' => 'user_id', 'id' => $user_id],
                    ];

                    foreach ($tables as $table => $info) {
                        $result = $conn->query("SHOW TABLES LIKE '$table'");
                        if ($result->num_rows === 0) {
                            error_log("Table $table does not exist. Skipping deletion.");
                            continue;
                        }

                        $query = "DELETE FROM $table WHERE {$info['column']} = ?";
                        $stmt = $conn->prepare($query);
                        if (!$stmt) {
                            throw new Exception("Prepare failed for $table deletion: " . $conn->error);
                        }
                        $stmt->bind_param("i", $info['id']);
                        if (!$stmt->execute()) {
                            throw new Exception("Execute failed for $table deletion: " . $stmt->error);
                        }
                        $stmt->close();
                    }

                    // Delete user
                    $query = "DELETE FROM users WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for users deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $user_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed for users deletion: " . $stmt->error);
                    }
                    $stmt->close();

                    // Send email notification
                    if ($phpmailer_available && !empty($email)) {
                        if (sendEmailNotification($email, $nasabah['nama'], $no_rekening, $saldo)) {
                            $email_status = 'success';
                        } else {
                            $email_status = 'failed';
                            error_log("Email sending failed for $email");
                        }
                    } else {
                        $email_status = $phpmailer_available ? 'no_email' : 'phpmailer_unavailable';
                        if (!$phpmailer_available) {
                            error_log("PHPMailer not available for sending email.");
                        }
                    }

                    $conn->commit();
                    $_SESSION['success_message'] = true;
                    $_SESSION['email_status'] = $email_status;
                    unset($_SESSION['nasabah'], $_SESSION['formData']);
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['error_message'] = 'Gagal menutup rekening: ' . $e->getMessage();
                    error_log("Account and user deletion error: " . $e->getMessage());
                }
                header('Location: tutup_rek.php');
                exit();
            }
        } else {
            $_SESSION['error_message'] = 'Nomor rekening tidak ditemukan atau pengguna tidak valid.';
            header('Location: tutup_rek.php');
            exit();
        }
        $stmt->close();
    } else {
        $_SESSION['error_message'] = implode(' ', $errors);
        header('Location: tutup_rek.php');
        exit();
    }
}

function sendEmailNotification($email, $nama, $no_rekening, $saldo) {
    global $phpmailer_available;
    if (!$phpmailer_available) {
        error_log("PHPMailer not available. Skipping email sending.");
        return false;
    }

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
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');
        
        $mail->isHTML(true);
        $mail->Subject = 'Penutupan Rekening - SCHOBANK SYSTEM';
        
        $saldo_text = $saldo > 0 ? "Sisa saldo Rp " . number_format($saldo, 0, ',', '.') . " akan dibayarkan sesuai prosedur." : "Tidak ada sisa saldo.";
        
        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e0e0e0;'>
            <div style='background-color: #1e3a8a; padding: 20px; text-align: center;'>
                <h2 style='color: #ffffff; margin: 0; font-size: 24px; font-weight: 600;'>SCHOBANK SYSTEM</h2>
            </div>
            <div style='padding: 30px;'>
                <h3 style='color: #1e3a8a; font-size: 20px; margin-bottom: 20px; font-weight: 600;'>Penutupan Rekening</h3>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Yth. {$nama},</p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>
                    Kami menginformasikan bahwa rekening Anda dengan nomor <strong>{$no_rekening}</strong> telah ditutup dan akun Anda telah dihapus dari sistem SCHOBANK SYSTEM. Tindakan ini bersifat permanen dan tidak dapat dikembalikan.
                </p>
                <table style='width: 100%; border-collapse: collapse; font-size: 14px; color: #333333; margin-bottom: 20px;'>
                    <tbody>
                        <tr style='background-color: #f8fafc;'>
                            <td style='padding: 12px 15px; font-weight: 500; width: 150px;'>Nomor Rekening</td>
                            <td style='padding: 12px 15px;'>{$no_rekening}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 15px; font-weight: 500;'>Status Saldo</td>
                            <td style='padding: 12px 15px;'>{$saldo_text}</td>
                        </tr>
                    </tbody>
                </table>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>
                    Jika Anda memiliki pertanyaan atau memerlukan informasi lebih lanjut, silakan hubungi kami melalui:
                </p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>
                    Email: <a href='mailto:bantuan.schobank@gmail.com' style='color: #1e3a8a; text-decoration: none; font-weight: 500;'>bantuan.schobank@gmail.com</a>
                </p>
                <p style='color: #333333; font-size: 16px; line-height: 1.6;'>Hormat kami,<br><strong>Tim SCHOBANK SYSTEM</strong></p>
            </div>
            <div style='text-align: center; padding: 15px; background-color: #f8fafc; color: #666666; font-size: 12px; border-top: 1px solid #e0e0e0;'>
                <p style='margin: 0;'>Email ini bersifat otomatis. Mohon untuk tidak membalas email ini.</p>
                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
            </div>
        </div>";
        
        $mail->Body = $emailBody;
        $mail->AltBody = strip_tags("SCHOBANK SYSTEM\n\nYth. {$nama},\n\nKami menginformasikan bahwa rekening Anda dengan nomor {$no_rekening} telah ditutup dan akun Anda telah dihapus dari sistem SCHOBANK SYSTEM. Tindakan ini bersifat permanen dan tidak dapat dikembalikan.\n\nNomor Rekening: {$no_rekening}\nStatus Saldo: {$saldo_text}\n\nJika Anda memiliki pertanyaan atau memerlukan informasi lebih lanjut, silakan hubungi tim kami melalui kanal resmi SCHOBANK SYSTEM.\n\nHormat kami,\nTim SCHOBANK SYSTEM\n\n© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.");
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed for $email: " . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tutup Rekening - SCHOBANK SYSTEM</title>
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
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
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

        .pin-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 30px 0;
            width: 100%;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, textarea {
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

        input:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        input[readonly] {
            background-color: #f8fafc;
            cursor: default;
        }

        textarea {
            resize: vertical;
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .pin-container {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .pin-container.error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            border: 1px solid #ddd;
            border-radius: 10px;
            background: white;
            transition: var(--transition);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--bg-light);
            animation: bouncePin 0.3s ease;
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
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

        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .btn-confirm:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-cancel {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #d32f2f 0%, var(--danger-color) 100%);
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
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
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal, .confirm-modal {
            position: relative;
            text-align: center;
            width: clamp(300px, 80vw, 400px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 15px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            max-height: 90vh;
            cursor: default;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .confirm-modal {
            width: clamp(340px, 85vw, 480px);
            min-height: 480px;
            display: flex;
            flex-direction: column;
            padding-bottom: 20px;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon, .confirm-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            margin: 0 auto 10px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3, .confirm-modal h3 {
            color: white;
            margin: 0 0 10px;
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p, .confirm-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0 0 15px;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .modal-row {
            display: grid;
            grid-template-columns: 130px 1fr;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            opacity: 0;
            animation: fadeInRow 0.5s ease-out forwards;
            animation-delay: calc(0.1s * var(--row-index));
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        @keyframes fadeInRow {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-label {
            font-weight: 500;
            color: white;
            font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            text-align: left;
        }

        .modal-value {
            font-weight: 400;
            color: white;
            font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            text-align: right;
            word-break: break-word;
        }

        .modal-form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 10px;
        }

        .modal-form-group label {
            font-weight: 500;
            color: white;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
        }

        .modal-form-group textarea {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
            resize: vertical;
            min-height: 60px;
            max-height: 100px;
            transition: var(--transition);
        }

        .modal-form-group textarea:focus {
            border-color: white;
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.2);
            transform: scale(1.01);
        }

        .modal-form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .modal-form-group .error-message {
            color: var(--danger-color);
            font-size: clamp(0.7rem, 1.4vw, 0.75rem);
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 8px;
        }

        .modal-buttons .btn {
            flex: 1;
            max-width: 160px;
            padding: 8px 16px;
            font-size: clamp(0.8rem, 1.7vw, 0.9rem);
            border-radius: 8px;
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

            .pin-container {
                gap: 8px;
            }

            .pin-input {
                width: 45px;
                height: 45px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .success-modal, .error-modal {
                width: clamp(280px, 85vw, 360px);
                padding: clamp(12px, 3vw, 15px);
                margin: 10px auto;
            }

            .confirm-modal {
                width: clamp(300px, 95vw, 400px);
                min-height: 450px;
                padding: clamp(12px, 2vw, 15px);
                margin: 8px auto;
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(2.3rem, 4.5vw, 2.5rem);
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .modal-row {
                grid-template-columns: 110px 1fr;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.7rem, 1.5vw, 0.8rem);
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

            input, textarea {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(260px, 90vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
                margin: 8px auto;
            }

            .confirm-modal {
                width: clamp(280px, 95vw, 360px);
                min-height: 420px;
                padding: clamp(10px, 1.5vw, 12px);
                margin: 6px auto;
            }

            .success-icon, .error-icon, .confirm-icon {
                font-size: clamp(2rem, 4vw, 2.2rem);
            }

            .success-modal h3, .error-modal h3, .confirm-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p, .confirm-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }

            .modal-row {
                grid-template-columns: 100px 1fr;
            }

            .modal-label, .modal-value {
                font-size: clamp(0.65rem, 1.4vw, 0.75rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            <h2>Tutup Rekening</h2>
            <p>Nonaktifkan rekening dan hapus pengguna beserta semua data terkait dari sistem</p>
        </div>

        <div id="alertContainer">
            <?php if (!$phpmailer_available): ?>
                <div class="modal-overlay" id="phpmailer-error">
                    <div class="error-modal">
                        <div class="error-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h3>PHPMailer Tidak Tersedia</h3>
                        <p>Email konfirmasi penutupan rekening tidak akan dikirim. Silakan instal Composer dan PHPMailer.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$showConfirmation && !$success): ?>
            <div class="form-section">
                <form action="" method="POST" id="tutupRekForm" class="form-container">
                    <div class="form-column">
                        <div class="form-group">
                            <label for="no_rekening">Nomor Rekening</label>
                            <input type="text" id="no_rekening" name="no_rekening" required 
                                   placeholder="Masukkan nomor rekening (REKxxxxxx)" 
                                   inputmode="numeric" pattern="[0-9]*"
                                   value="<?php echo htmlspecialchars($formData['no_rekening'] ?? 'REK'); ?>">
                            <span class="error-message" id="no_rekening-error"></span>
                        </div>
                        <div class="form-group">
                            <label for="nama">Nama</label>
                            <input type="text" id="nama" name="nama" readonly placeholder="Nama akan terisi otomatis" value="<?php echo htmlspecialchars($formData['nama'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" readonly placeholder="Email akan terisi otomatis" value="<?php echo htmlspecialchars(obscureEmail($formData['email'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="form-column">
                        <div class="form-group">
                            <label for="tanggal_lahir">Tanggal Lahir</label>
                            <input type="text" id="tanggal_lahir" name="tanggal_lahir" readonly placeholder="Tanggal lahir akan terisi otomatis" value="<?php echo htmlspecialchars($formData['tanggal_lahir'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="jurusan">Jurusan</label>
                            <input type="text" id="jurusan" name="jurusan" readonly placeholder="Jurusan akan terisi otomatis" value="<?php echo htmlspecialchars($formData['nama_jurusan'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="kelas">Kelas</label>
                            <input type="text" id="kelas" name="kelas" readonly placeholder="Kelas akan terisi otomatis" value="<?php echo htmlspecialchars($formData['nama_kelas'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="form-group pin-group">
                        <label for="pin-value">Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        </div>
                        <input type="hidden" name="pin" id="pin-value">
                        <span class="error-message" id="pin-error"></span>
                    </div>
                    <div class="form-buttons">
                        <button type="submit" class="btn" id="submit-btn">
                            <span class="btn-content"><i class="fas fa-search"></i> Verifikasi</span>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($showConfirmation && empty($error)): ?>
            <div class="modal-overlay" id="confirmModal">
                <div class="confirm-modal">
                    <div class="confirm-icon">
                        <i class="fas fa-trash-alt"></i>
                    </div>
                    <h3>Konfirmasi Penutupan</h3>
                    <p>Periksa data berikut. Tindakan ini permanen.</p>
                    <div class="modal-content">
                        <?php
                        $rows = [
                            ['label' => 'Nama', 'value' => $formData['nama']],
                            ['label' => 'No Rekening', 'value' => $formData['no_rekening']],
                            ['label' => 'Username', 'value' => $formData['username']],
                            ['label' => 'Tanggal Lahir', 'value' => $formData['tanggal_lahir']],
                            ['label' => 'Email', 'value' => obscureEmail($formData['email'])],
                            ['label' => 'Jurusan', 'value' => $formData['nama_jurusan'] ?: '-'],
                            ['label' => 'Kelas', 'value' => $formData['nama_kelas'] ?: '-'],
                            ['label' => 'Saldo', 'value' => 'Rp ' . number_format($formData['saldo'], 0, ',', '.')]
                        ];
                        foreach ($rows as $index => $row):
                        ?>
                            <div class="modal-row" style="--row-index: <?php echo $index; ?>;">
                                <span class="modal-label"><?php echo htmlspecialchars($row['label']); ?></span>
                                <span class="modal-value"><?php echo htmlspecialchars($row['value']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($formData['user_id']); ?>">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                        <input type="hidden" name="confirmed" value="1">
                        <div class="modal-form-group">
                            <label for="reason">Alasan Penghapusan</label>
                            <textarea id="reason" name="reason" rows="2" placeholder="Masukkan alasan"></textarea>
                            <span class="error-message" id="reason-error"></span>
                        </div>
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="submit" name="cancel" class="btn btn-cancel">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="modal-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Penutupan Berhasil</h3>
                    <p>Data pengguna dan rekening telah dihapus.
                        <?php if ($email_status === 'success'): ?>
                            Email konfirmasi dikirim.
                        <?php elseif ($email_status === 'failed'): ?>
                            Gagal mengirim email.
                        <?php elseif ($email_status === 'no_email'): ?>
                            Tidak ada email pengguna.
                        <?php elseif ($email_status === 'phpmailer_unavailable'): ?>
                            PHPMailer tidak tersedia.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="modal-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(event) {
            event.preventDefault();
        });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            const tutupRekForm = document.getElementById('tutupRekForm');
            const submitBtn = document.getElementById('submit-btn');
            const noRekeningInput = document.getElementById('no_rekening');
            const pinInputs = document.querySelectorAll('.pin-input');
            const pinContainer = document.querySelector('.pin-container');
            const pinValue = document.getElementById('pin-value');
            const alertContainer = document.getElementById('alertContainer');
            const prefix = "REK";
            const AUTO_CLOSE_DELAY = 5000; // 5 seconds for auto-close

            // Initialize no_rekening input
            if (noRekeningInput && noRekeningInput.value === '') {
                noRekeningInput.value = prefix;
            }

            // Clear any stale modals
            const existingModals = alertContainer.querySelectorAll('.modal-overlay');
            existingModals.forEach(modal => modal.remove());

            // Setup modal auto-close
            function setupModalAutoClose(modalId, isSuccess = false, isPinError = false) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    addModalCloseListener(modal);
                    setTimeout(() => {
                        closeModal(modalId);
                        if (isSuccess) {
                            resetForm();
                        } else if (isPinError) {
                            clearPinInputs();
                        }
                    }, AUTO_CLOSE_DELAY);
                }
            }

            // No Rekening input handling
            if (noRekeningInput) {
                noRekeningInput.addEventListener('input', function(e) {
                    let value = this.value;
                    if (!value.startsWith(prefix)) {
                        value = prefix + value.replace(prefix, '');
                    }
                    let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '').slice(0, 6);
                    this.value = prefix + userInput;
                    if (/^REK[0-9]{6}$/.test(this.value)) {
                        fetchAccountDetails(this.value);
                    } else {
                        clearAccountFields();
                    }
                });

                noRekeningInput.addEventListener('keydown', function(e) {
                    let cursorPos = this.selectionStart;
                    if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                        e.preventDefault();
                    }
                    // Allow only numeric keys, backspace, delete, and navigation keys
                    if (!/^[0-9]$/.test(e.key) && e.key !== 'Backspace' && e.key !== 'Delete' && 
                        !['ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });

                noRekeningInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                    this.value = prefix + pastedData;
                    if (/^REK[0-9]{6}$/.test(this.value)) {
                        fetchAccountDetails(this.value);
                    } else {
                        clearAccountFields();
                    }
                });

                noRekeningInput.addEventListener('focus', function() {
                    if (this.value === prefix) {
                        this.setSelectionRange(prefix.length, prefix.length);
                    }
                });

                noRekeningInput.addEventListener('click', function(e) {
                    if (this.selectionStart < prefix.length) {
                        this.setSelectionRange(prefix.length, prefix.length);
                    }
                });

                // Trigger initial fetch if formData has no_rekening
                if (noRekeningInput.value.match(/^REK[0-9]{6}$/)) {
                    fetchAccountDetails(noRekeningInput.value);
                }
            }

            // PIN input handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function() {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.classList.add('filled');
                        this.dataset.originalValue = value;
                        this.value = '*';
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        this.classList.remove('filled');
                        this.dataset.originalValue = '';
                        this.value = '';
                    }
                    updatePinValue();
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.dataset.originalValue && index > 0) {
                        pinInputs[index - 1].focus();
                        pinInputs[index - 1].classList.remove('filled');
                        pinInputs[index - 1].dataset.originalValue = '';
                        pinInputs[index - 1].value = '';
                        updatePinValue();
                    }
                });
                input.addEventListener('focus', function() {
                    if (this.classList.contains('filled')) {
                        this.value = this.dataset.originalValue || '';
                    }
                });
                input.addEventListener('blur', function() {
                    if (this.value && !this.classList.contains('filled')) {
                        let value = this.value.replace(/[^0-9]/g, '');
                        if (value) {
                            this.dataset.originalValue = value;
                            this.value = '*';
                            this.classList.add('filled');
                        }
                    }
                });
            });

            function updatePinValue() {
                pinValue.value = Array.from(pinInputs).map(i => i.dataset.originalValue || '').join('');
            }

            function clearPinInputs() {
                pinInputs.forEach(i => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                pinValue.value = '';
                pinInputs[0].focus();
            }

            function shakePinContainer() {
                pinContainer.classList.add('error');
                setTimeout(() => pinContainer.classList.remove('error'), 400);
            }

            function resetForm() {
                tutupRekForm.reset();
                clearPinInputs();
                noRekeningInput.value = prefix;
                clearAccountFields();
            }

            function clearAccountFields() {
                $('#nama').val('');
                $('#email').val('-');
                $('#tanggal_lahir').val('');
                $('#jurusan').val('');
                $('#kelas').val('');
            }

            function showAlert(message, type) {
                // Remove existing modals to prevent overlap
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
                        <h3>${type === 'success' ? 'Penutupan Berhasil!' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                alertDiv.id = 'alert-' + Date.now();
                setupModalAutoClose(alertDiv.id, type === 'success', message === 'PIN yang dimasukkan salah.');
            }

            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                }
            }

            function obscureEmail(email) {
                if (!email) return '-';
                const [username, domain] = email.split('@');
                if (!domain || username.length <= 2) return email;
                return `${username[0]}****${username[username.length - 1]}@${domain}`;
            }

            function fetchAccountDetails(no_rekening) {
                $.ajax({
                    url: 'get_account_details.php',
                    method: 'POST',
                    data: { no_rekening: no_rekening },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            $('#nama').val(response.nama);
                            $('#email').val(obscureEmail(response.email));
                            $('#tanggal_lahir').val(response.tanggal_lahir);
                            $('#jurusan').val(response.nama_jurusan || '-');
                            $('#kelas').val(response.nama_kelas || '-');
                            $('#no_rekening-error').removeClass('show');
                        } else {
                            showAlert(response.message, 'error');
                            clearAccountFields();
                        }
                    },
                    error: function() {
                        showAlert('Kesalahan server: Gagal mengambil data rekening.', 'error');
                        clearAccountFields();
                    }
                });
            }

            function addModalCloseListener(modalOverlay) {
                modalOverlay.addEventListener('click', function(event) {
                    if (event.target === modalOverlay) {
                        closeModal(modalOverlay.id);
                        const modalType = modalOverlay.querySelector('.success-modal, .error-modal, .confirm-modal')?.classList;
                        if (modalType?.contains('success-modal')) {
                            resetForm();
                        } else if (modalType?.contains('error-modal')) {
                            if (modalOverlay.querySelector('p')?.textContent === 'PIN yang dimasukkan salah.') {
                                clearPinInputs();
                            }
                        }
                    }
                });
            }

            // Setup auto-close for existing modals
            ['successModal', 'errorModal', 'phpmailer-error'].forEach(modalId => {
                const isPinError = modalId === 'errorModal' && document.getElementById('errorModal')?.querySelector('p')?.textContent === 'PIN yang dimasukkan salah.';
                setupModalAutoClose(modalId, modalId === 'successModal', isPinError);
            });

            if (tutupRekForm && submitBtn) {
                submitBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const noRekening = noRekeningInput.value;
                    const pin = pinValue.value;

                    if (!noRekening || !/^REK[0-9]{6}$/.test(noRekening)) {
                        showAlert('Nomor rekening tidak valid. Format: REK + 6 digit angka.', 'error');
                        document.getElementById('no_rekening-error').textContent = 'Nomor rekening tidak valid. Format: REK + 6 digit angka.';
                        document.getElementById('no_rekening-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('no_rekening-error').classList.remove('show');
                    }

                    if (!pin || pin.length !== 6) {
                        shakePinContainer();
                        showAlert('PIN harus 6 digit.', 'error');
                        document.getElementById('pin-error').textContent = 'PIN harus 6 digit.';
                        document.getElementById('pin-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('pin-error').classList.remove('show');
                    }

                    this.classList.add('loading');
                    setTimeout(() => {
                        tutupRekForm.submit();
                    }, 500);
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');

            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    if (confirmForm.classList.contains('submitting')) return;
                    confirmForm.classList.add('submitting');

                    if (e.submitter.name === 'confirm') {
                        const reason = document.getElementById('reason').value.trim();
                        if (!reason) {
                            e.preventDefault();
                            document.getElementById('reason-error').textContent = 'Alasan penghapusan wajib diisi.';
                            document.getElementById('reason-error').classList.add('show');
                            document.getElementById('reason').focus();
                            confirmForm.classList.remove('submitting');
                            return;
                        } else {
                            document.getElementById('reason-error').classList.remove('show');
                            confirmBtn.classList.add('loading');
                            confirmBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Menghapus...</span>';
                        }
                    } else if (e.submitter.name === 'cancel') {
                        // Allow cancel without validation
                        document.getElementById('reason-error').classList.remove('show');
                    }
                });
            }

            <?php if (!empty($error)): ?>
                document.getElementById('errorModal').querySelector('p').textContent = '<?php echo addslashes($error); ?>';
            <?php endif; ?>
        });
    </script>
</body>
</html>