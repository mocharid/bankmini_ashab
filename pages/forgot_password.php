<?php
session_start();
require_once '../includes/db_connection.php';
require '../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success_message = "";
$error_message = "";

// Fungsi untuk cek cooldown periode
function checkCooldownPeriod($conn, $user_id, $method) {
    $query = "SELECT last_reset_request FROM reset_request_cooldown WHERE user_id = ? AND method = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $user_id, $method);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_request_time = strtotime($row['last_reset_request']);
        $current_time = time();
        $cooldown_period = 15 * 60; // 15 menit cooldown
        
        if (($current_time - $last_request_time) < $cooldown_period) {
            $remaining_seconds = $cooldown_period - ($current_time - $last_request_time);
            $remaining_minutes = ceil($remaining_seconds / 60);
            return $remaining_minutes;
        }
    }
    return 0;
}

// Fungsi untuk update waktu permintaan reset
function updateResetRequestTime($conn, $user_id, $method) {
    $current_time = date('Y-m-d H:i:s');
    
    $check_query = "SELECT id FROM reset_request_cooldown WHERE user_id = ? AND method = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("is", $user_id, $method);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $update_query = "UPDATE reset_request_cooldown SET last_reset_request = ? WHERE user_id = ? AND method = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("sis", $current_time, $user_id, $method);
        $update_stmt->execute();
    } else {
        $insert_query = "INSERT INTO reset_request_cooldown (user_id, method, last_reset_request) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iss", $user_id, $method, $current_time);
        $insert_stmt->execute();
    }
}

// Cek tabel reset_request_cooldown
$check_table_query = "SHOW TABLES LIKE 'reset_request_cooldown'";
$check_table_result = $conn->query($check_table_query);
if ($check_table_result->num_rows == 0) {
    $create_table_query = "CREATE TABLE reset_request_cooldown (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        method VARCHAR(20) NOT NULL,
        last_reset_request DATETIME NOT NULL,
        INDEX (user_id, method)
    )";
    $conn->query($create_table_query);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $identifier = trim($_POST['identifier']);
    $method = 'email';
    
    $query = "SELECT id, nama, email FROM users WHERE email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        $cooldown_minutes = checkCooldownPeriod($conn, $user['id'], $method);
        if ($cooldown_minutes > 0) {
            $error_message = "Tunggu {$cooldown_minutes} menit sebelum mengirim permintaan baru.";
        } else {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            $delete_query = "DELETE FROM password_reset WHERE user_id = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("i", $user['id']);
            $delete_stmt->execute();
            
            $insert_query = "INSERT INTO password_reset (user_id, token, expiry) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("iss", $user['id'], $token, $expiry);
            $success = $insert_stmt->execute();
            
            if (!$success) {
                error_log("Database error: " . $conn->error);
                $error_message = "Gagal memproses permintaan. Coba lagi nanti.";
            } else {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $baseUrl = $protocol . "://" . $host;
                $path = dirname($_SERVER['PHP_SELF']);
                $reset_link = $baseUrl . $path . "/reset_password.php?token=" . $token;
                
                $subject = "Reset Password";
                $message = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
           
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Permintaan Reset Password</h2>
           
            <p>Halo <strong>{$user['nama']}</strong>,</p>
           
            <p>Anda telah meminta reset password. Klik tombol di bawah ini untuk melanjutkan:</p>
           
            <div style='text-align: left; margin: 30px 0;'>
                <a href='{$reset_link}' style='background-color: #1e3a8a; color: white; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;'>Reset Password Sekarang</a>
            </div>
           
            <p style='margin-bottom: 5px;'>Atau gunakan link berikut:</p>
            <p><a href='{$reset_link}'>{$reset_link}</a></p>
           
            <p class='expire-note' style='font-size: 13px; color: #666; margin-top: 20px;'>Link ini akan kadaluarsa dalam 15 menit (hingga " . date('d M Y H:i:s', strtotime($expiry)) . " WIB).</p>
           
            <p>Jika bukan Anda yang meminta, abaikan email ini.</p>
           
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
           
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem Schobank Student Digital Banking.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>
                ";
                
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'myschobank@gmail.com';
                    $mail->Password = 'xpni zzju utfu mkth';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
                    $mail->addAddress($user['email'], $user['nama']);

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    
                    $labels = [
                        'Nama' => $user['nama'],
                        'Link Reset' => $reset_link,
                        'Kadaluarsa' => date('d M Y H:i:s', strtotime($expiry)) . ' WIB'
                    ];
                    $max_label_length = max(array_map('strlen', array_keys($labels)));
                    $max_label_length = max($max_label_length, 20);
                    $text_rows = [];
                    foreach ($labels as $label => $value) {
                        $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
                    }
                    $text_rows[] = "\nJika bukan Anda, abaikan email ini.";
                    $text_rows[] = "\nHormat kami,\nTim Schobank Student Digital Banking";
                    $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
                    $altBody = implode("\n", $text_rows);
                    $mail->AltBody = $altBody;

                    $mail->send();
                    
                    updateResetRequestTime($conn, $user['id'], $method);
                    
                    // PESAN SUKSES DIPERBARUI DI SINI
                    $success_message = "Link Reset Berhasil Dikirim ke email mu";
                } catch (Exception $e) {
                    error_log("Mail error: " . $mail->ErrorInfo);
                    $error_message = "Gagal mengirim email. Coba lagi atau hubungi admin.";
                }
            }
        }
    } else {
        $error_message = "Email tidak ditemukan di sistem.";
    }

    $_SESSION['success_message'] = $success_message;
    $_SESSION['error_message'] = $error_message;
    header("Location: forgot_password.php");
    exit();
}

$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Lupa Password | My Schobank</title>
    <link rel="icon" type="image/jpeg" href="/schobank/assets/images/logo.jpg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --primary-dark: #0d1452;
            --danger-color: #dc2626;
            --success-color: #16a34a;
            --text-primary: #000000;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-lg: 0.75rem;
            --radius-md: 0.5rem;
            --transition: all 0.3s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; touch-action: manipulation; }
        body {
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            min-height: 100vh;
        }
        .login-container {
            position: relative;
            background: var(--bg-white);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border-light);
        }
        .close-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
            border-radius: 50%;
        }
        .header-section {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-light);
        }
        .logo-container { margin-bottom: 1rem; }
        .logo {
            height: auto;
            max-height: 80px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            transition: var(--transition);
        }
        .logo:hover { transform: scale(1.03); }
        .description {
            color: var(--text-primary);
            font-size: 0.85rem;
            font-weight: 400;
            line-height: 1.5;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .input-group { margin-bottom: 1.25rem; position: relative; }
        .input-wrapper { position: relative; }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1.1rem;
            z-index: 2;
        }
        .input-field {
            width: 100%;
            padding: 14px 14px 14px 46px;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-md);
            font-size: 15px;
            background: #fdfdfd;
            transition: var(--transition);
            color: var(--text-primary);
        }
        .input-field:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.12);
        }
        .error-message, .success-message {
            padding: 12px 16px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.875rem;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: opacity 0.4s ease;
            font-weight: 500;
        }
        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--danger-color);
        }
        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: var(--success-color);
        }
        .error-message.fade-out, .success-message.fade-out { opacity: 0; }
        .submit-button {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }
        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .submit-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        @media (max-width: 480px) {
            body { padding: 15px; }
            .login-container { padding: 2rem; }
            .logo { max-height: 70px; }
            .input-field { font-size: 14px; padding: 12px 12px 12px 42px; }
            .submit-button { padding: 12px 18px; font-size: 14px; }
            .description { font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <button type="button" class="close-btn" onclick="window.location.href='login.php'">
            <i class="fas fa-times"></i>
        </button>

        <div class="header-section">
            <div class="logo-container">
                <img src="/schobank/assets/images/header.png" alt="My Schobank Logo" class="logo">
            </div>
        </div>

        <p class="description">
            Masukkan email Anda untuk memulai proses reset password akun My Schobank. Kami akan mengirimkan link reset ke email tersebut.
        </p>

        <?php if (!empty($error_message)): ?>
            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="success-message" id="successMessage">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
            <script>
                setTimeout(function() {
                    window.location.href = 'login.php';
                }, 3000);
            </script>
        <?php else: ?>
            <form method="POST" id="forgotForm">
                <div class="input-group">
                    <div class="input-wrapper">
                        <input type="email" name="identifier" id="identifier" class="input-field" 
                               placeholder="contoh@gmail.com" required autocomplete="email">
                        <i class="fas fa-envelope input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="submit-button" id="submitButton">
                    <i class="fas fa-paper-plane" id="btnIcon"></i>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                    <span id="buttonText">Kirim Link Reset</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotForm');
            const submitBtn = document.getElementById('submitButton');
            const btnText = document.getElementById('buttonText');
            const btnIcon = document.getElementById('btnIcon');
            const spinner = document.getElementById('loadingSpinner');

            const errorEl = document.getElementById('errorMessage');
            if (errorEl) {
                setTimeout(() => {
                    errorEl.classList.add('fade-out');
                    setTimeout(() => errorEl.remove(), 400);
                }, 3000);
            }

            if (form) {
                form.addEventListener('submit', function() {
                    if (btnIcon) btnIcon.style.display = 'none';
                    if (spinner) spinner.style.display = 'block';
                    if (btnText) btnText.textContent = 'Mengirim...';
                    submitBtn.disabled = true;
                });
            }
        });
    </script>
</body>
</html>