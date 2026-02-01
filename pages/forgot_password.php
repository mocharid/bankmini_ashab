<?php
/**
 * Forgot Password Page - Clean Banking Design
 * File: pages/forgot_password.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
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

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// DEFINE WEB BASE URL
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = preg_replace('#/pages$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}

define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// START SESSION & LOAD DEPENDENCIES
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
require PROJECT_ROOT . '/vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success_message = "";
$error_message = "";

// Fungsi untuk cek cooldown periode
function checkCooldownPeriod($conn, $user_id, $method)
{
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
function updateResetRequestTime($conn, $user_id, $method)
{
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
                $reset_link = BASE_URL . "/pages/reset_password.php?token=" . $token;

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
                Ini adalah pesan otomatis dari sistem KASDIG.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>
                ";

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->SMTPDebug = 0; // Disable debug untuk production
                    $mail->Host = 'mail.kasdig.web.id';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'noreply@kasdig.web.id';
                    $mail->Password = 'BtRjT4wP8qeTL5M';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $mail->Port = 465;
                    $mail->Timeout = 30; // Timeout 30 detik
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('noreply@kasdig.web.id', 'KASDIG');
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
                    $text_rows[] = "\nHormat kami,\nTim KASDIG";
                    $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";
                    $altBody = implode("\n", $text_rows);
                    $mail->AltBody = $altBody;

                    $mail->send();

                    updateResetRequestTime($conn, $user['id'], $method);

                    $success_message = "Link Reset Berhasil Dikirim ke email mu";
                } catch (Exception $e) {
                    error_log("Mail error: " . $mail->ErrorInfo);
                    $error_message = "Gagal: " . $mail->ErrorInfo;
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
    <title>Lupa Password | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: #ffffff;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo-icon img {
            height: 150px;
            width: auto;
        }

        /* Description */
        .description {
            text-align: center;
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            color: #888;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 14px 40px 14px 0;
            border: none;
            border-bottom: 1.5px solid #e0e0e0;
            font-size: 1rem;
            color: #1a1a2e;
            background: transparent;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-bottom-color: #1a1a2e;
        }

        .form-input::placeholder {
            color: #c0c0c0;
        }

        .form-input.input-error {
            border-bottom-color: #e74c3c;
        }

        .input-icon {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            color: #3498db;
            font-size: 1rem;
        }

        .error-text {
            color: #e74c3c;
            font-size: 0.75rem;
            margin-top: 6px;
        }

        /* General Error & Success */
        .general-error,
        .general-success {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .general-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .general-error i {
            color: #e74c3c;
        }

        .general-error span {
            color: #991b1b;
            font-size: 0.85rem;
        }

        .general-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .general-success i {
            color: #16a34a;
        }

        .general-success span {
            color: #166534;
            font-size: 0.85rem;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: #1a1a2e;
            color: #ffffff;
            border: none;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 10px;
        }

        .submit-btn:hover {
            background: #2d2d4a;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(26, 26, 46, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Back Link */
        .back-link {
            text-align: center;
            margin-top: 25px;
        }

        .back-link a {
            color: #8e8e8e;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.3s ease;
        }

        .back-link a:hover {
            color: #1a1a2e;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 20px;
            }

            .login-container {
                padding: 20px;
            }

            .logo-section {
                margin-bottom: 20px;
            }

            .logo-icon img {
                height: 120px;
            }

            .description {
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-icon">
                <img src="<?= ASSETS_URL ?>/images/header.png" alt="KASDIG" draggable="false">
            </div>
        </div>

        <!-- Description -->
        <p class="description">
            Masukkan email Anda untuk menerima link reset password.
        </p>

        <!-- Error Message -->
        <?php if (!empty($error_message)): ?>
            <div class="general-error" id="generalError">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error_message) ?></span>
            </div>
        <?php endif; ?>

        <!-- Success Message -->
        <?php if (!empty($success_message)): ?>
            <div class="general-success" id="generalSuccess">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
            <script>
                setTimeout(function () {
                    window.location.href = 'login.php';
                }, 3000);
            </script>
        <?php else: ?>
            <!-- Forgot Password Form -->
            <form method="POST" id="forgot-form">
                <!-- Email -->
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <div class="input-wrapper">
                        <input type="email" name="identifier" id="identifier" class="form-input"
                            placeholder="Masukkan email Anda" required autocomplete="email">
                        <i class="fas fa-envelope input-icon" style="color: #8e8e8e;"></i>
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="submit-btn" id="submitBtn">
                    <span id="btnText">Kirim Link Reset</span>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                </button>
            </form>
        <?php endif; ?>

        <!-- Back to Login -->
        <div class="back-link">
            <a href="<?= BASE_URL ?>/pages/login.php">
                <i class="fas fa-arrow-left"></i>
                Kembali ke halaman masuk
            </a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('forgot-form');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('loadingSpinner');

            // Auto-hide error message
            const generalError = document.getElementById('generalError');
            if (generalError) {
                setTimeout(() => {
                    generalError.style.opacity = '0';
                    generalError.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => generalError.remove(), 400);
                }, 5000);
            }

            // Form submit with loading
            form?.addEventListener('submit', function () {
                btnText.textContent = 'Mengirim...';
                spinner.style.display = 'block';
                submitBtn.disabled = true;

                setTimeout(() => {
                    btnText.textContent = 'Kirim Link Reset';
                    spinner.style.display = 'none';
                    submitBtn.disabled = false;
                }, 10000);
            });
        });
    </script>
</body>

</html>