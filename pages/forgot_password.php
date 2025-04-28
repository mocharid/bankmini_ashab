<?php
session_start();
require_once '../includes/db_connection.php';
require '../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success_message = "";
$error_message = "";
$current_time = date('H:i:s - d M Y');

// Fungsi untuk mengirim pesan WhatsApp menggunakan Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $curl = curl_init();
    
    // Pastikan format nomor benar (awalan 62)
    if (substr($phone_number, 0, 2) === '08') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $phone_number,
            'message' => $message
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: dCjq3fJVf9p2DAfVDVED'
        ),
    ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    return $response;
}

// Fungsi untuk cek cooldown periode pada nomor/email
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
        $cooldown_period = 15 * 60; // 15 menit dalam detik
        
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
    
    // Cek apakah sudah ada record untuk user_id dan method ini
    $check_query = "SELECT id FROM reset_request_cooldown WHERE user_id = ? AND method = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("is", $user_id, $method);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        // Update record yang sudah ada
        $update_query = "UPDATE reset_request_cooldown SET last_reset_request = ? WHERE user_id = ? AND method = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("sis", $current_time, $user_id, $method);
        $update_stmt->execute();
    } else {
        // Buat record baru
        $insert_query = "INSERT INTO reset_request_cooldown (user_id, method, last_reset_request) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iss", $user_id, $method, $current_time);
        $insert_stmt->execute();
    }
}

// Cek apakah tabel reset_request_cooldown sudah ada, jika belum maka buat
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
    $method = trim($_POST['reset_method']);
    
    // Query berdasarkan metode yang dipilih (email atau no_wa)
    if ($method == 'email') {
        $query = "SELECT id, nama, email, no_wa FROM users WHERE email = ?";
    } else {
        $query = "SELECT id, nama, email, no_wa FROM users WHERE no_wa = ?";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $identifier);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Cek cooldown period untuk permintaan reset
        $cooldown_minutes = checkCooldownPeriod($conn, $user['id'], $method);
        if ($cooldown_minutes > 0) {
            $error_message = "Anda harus menunggu {$cooldown_minutes} menit lagi sebelum mengirim permintaan reset baru.";
        } else {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes')); // Ubah menjadi 15 menit
            
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
                $error_message = "Terjadi kesalahan database. Silakan coba lagi nanti.";
            } else {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $baseUrl = $protocol . "://" . $host;
                $path = dirname($_SERVER['PHP_SELF']);
                $reset_link = $baseUrl . $path . "/reset_password.php?token=" . $token;
                
                // Proses sesuai metode yang dipilih
                if ($method == 'email') {
                    // Kirim reset password melalui Email
                    $subject = "Reset Password - SCHOBANK SYSTEM";
                    $message = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                                .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                                h2 { color: #1A237E; border-bottom: 2px solid #e8ecef; padding-bottom: 10px; }
                                .btn { display: inline-block; background: linear-gradient(90deg, #1A237E 0%, #00B4D8 100%); color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
                                .expire-note { font-size: 0.9em; color: #666; margin-top: 20px; }
                                .footer { margin-top: 30px; font-size: 0.8em; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <h2>Reset Password SCHOBANK</h2>
                                <p>Halo, <strong>{$user['nama']}</strong>!</p>
                                <p>Anda telah meminta untuk melakukan reset password akun SCHOBANK Anda. Silakan klik tombol di bawah ini untuk melanjutkan:</p>
                                <p style='text-align: center;'><a class='btn' href='{$reset_link}'>Reset Password Saya</a></p>
                                <p>Atau gunakan link berikut:</p>
                                <p><a href='{$reset_link}'>{$reset_link}</a></p>
                                <p class='expire-note'>Link ini akan kadaluarsa dalam 15 menit (sampai " . date('d M Y H:i:s', strtotime($expiry)) . " WIB).</p>
                                <p>Jika Anda tidak melakukan permintaan ini, abaikan email ini dan password Anda tidak akan berubah.</p>
                                <div class='footer'>
                                    <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                                    <p>© " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
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
                        $mail->addAddress($user['email'], $user['nama']);

                        $mail->isHTML(true);
                        $mail->Subject = $subject;
                        $mail->Body = $message;
                        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $message));

                        $mail->send();
                        
                        // Update cooldown time
                        updateResetRequestTime($conn, $user['id'], $method);
                        
                        $success_message = "Email reset password telah dikirim ke {$user['email']}. Silakan cek inbox dan folder spam Anda. Link akan berlaku selama 15 menit.";
                    } catch (Exception $e) {
                        error_log("Mail error: " . $mail->ErrorInfo);
                        $error_message = "Gagal mengirim email. Silakan coba lagi nanti atau hubungi admin.";
                    }
                } else {
                    // Kirim reset password melalui WhatsApp dengan format yang lebih menarik
                    $expire_time = date('d M Y H:i:s', strtotime($expiry));
                    $wa_message = "
*📱 SCHOBANK SYSTEM - RESET PASSWORD* 
━━━━━━━━━━━━━━━━━━

Halo, *{$user['nama']}* 👋

Kami menerima permintaan untuk reset password akun SCHOBANK Anda. 

*🔐 LINK RESET PASSWORD:*
{$reset_link}

⏰ *PENTING*: Link ini akan *KADALUARSA* dalam 15 menit
(sampai {$expire_time} WIB)

🛡️ *KEAMANAN*: 
Jika bukan Anda yang meminta reset password ini, abaikan pesan ini dan akun Anda tetap aman.

━━━━━━━━━━━━━━━━━━
_Pesan ini dikirim secara otomatis oleh sistem_
*SCHOBANK* © " . date('Y') . "
_Solusi Perbankan Digital Anda_
";
                    
                    try {
                        $response = sendWhatsAppMessage($user['no_wa'], $wa_message);
                        $response_data = json_decode($response, true);
                        
                        if (isset($response_data['status']) && $response_data['status'] === true) {
                            // Update cooldown time
                            updateResetRequestTime($conn, $user['id'], $method);
                            
                            $masked_number = substr($user['no_wa'], 0, 4) . "xxxx" . substr($user['no_wa'], -4);
                            $success_message = "Link reset password telah dikirim ke WhatsApp nomor {$masked_number}. Link akan berlaku selama 15 menit.";
                        } else {
                            $error_message = "Gagal mengirim pesan WhatsApp. Silakan coba lagi nanti atau gunakan metode email.";
                            error_log("WhatsApp API error: " . $response);
                        }
                    } catch (Exception $e) {
                        error_log("WhatsApp error: " . $e->getMessage());
                        $error_message = "Gagal mengirim pesan WhatsApp. Silakan coba lagi nanti atau hubungi admin.";
                    }
                }
            }
        }
    } else {
        if ($method == 'email') {
            $error_message = "Email tidak terdaftar dalam sistem kami.";
        } else {
            $error_message = "Nomor WhatsApp tidak terdaftar dalam sistem kami.";
        }
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
<html>
<head>
    <title>Lupa Password - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html, body {
            touch-action: manipulation;
            overscroll-behavior: none;
        }

        body {
            background: linear-gradient(180deg, #e6ecf8 0%, #f5f7fa 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }

        .login-container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-container {
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e8ecef;
        }

        .logo {
            width: 100px;
            height: auto;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .bank-name {
            font-size: 1.4rem;
            color: #1A237E;
            font-weight: 700;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .form-description {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 400;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-group i.icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a5568;
            font-size: 1.1rem;
        }

        input {
            width: 100%;
            padding: 12px 45px;
            border: 1px solid #d1d9e6;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 400;
            transition: all 0.3s ease;
            background: #fbfdff;
        }

        input:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        .method-selector {
            display: flex;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #d1d9e6;
        }

        .method-option {
            flex: 1;
            padding: 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #f8f9fa;
            color: #4a5568;
            border-right: 1px solid #d1d9e6;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .method-option:last-child {
            border-right: none;
        }

        .method-option.active {
            background: linear-gradient(90deg, #1A237E 0%, #00B4D8 100%);
            color: white;
        }

        .method-option i {
            margin-right: 6px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #1A237E 0%, #00B4D8 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        button i {
            margin-right: 6px;
        }

        button:hover {
            background: linear-gradient(90deg, #151c66 0%, #009bb8 100%);
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
            transform: translateY(-1px);
            scale: 1.02;
        }

        button:active {
            background: #151c66;
            transform: translateY(0);
            scale: 1;
        }

        .error-message {
            background: #fef1f1;
            color: #c53030;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #feb2b2;
            box-shadow: 0 1px 6px rgba(254, 178, 178, 0.15);
        }

        .error-message.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .success-message {
            background: #e6fffa;
            color: #2c7a7b;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #b2f5ea;
            box-shadow: 0 1px 6px rgba(178, 245, 234, 0.15);
        }

        .success-message.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .back-to-login {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-to-login a {
            color: #1A237E;
            font-size: 0.9rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .back-to-login a i {
            margin-right: 5px;
        }

        .back-to-login a:hover {
            color: #00B4D8;
            text-decoration: underline;
            transform: scale(1.05);
        }

        /* Spinner styles */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        button.loading .spinner {
            display: inline-block;
        }

        button.loading .button-content {
            display: none;
        }

        @media (max-width: 768px) {
            .login-container {
                max-width: 400px;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
                border: 1px solid #e8ecef;
                margin: 0 auto;
                padding: 2rem;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
            }

            .logo {
                width: 80px;
            }

            .bank-name {
                font-size: 1.2rem;
            }

            input, button {
                font-size: 14px;
                padding: 10px 40px;
            }

            button {
                padding: 10px;
            }

            .form-description {
                font-size: 0.85rem;
            }

            .input-group i.icon {
                font-size: 1rem;
            }

            .error-message, .success-message {
                font-size: 0.85rem;
                padding: 0.6rem;
            }

            .back-to-login a {
                font-size: 0.85rem;
            }
            
            .method-option {
                padding: 8px 5px;
                font-size: 12px;
            }
            
            .method-option i {
                margin-right: 3px;
            }

            .spinner {
                width: 18px;
                height: 18px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-top: 2px solid #ffffff;
            }
        }

        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            select,
            textarea,
            input {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="/bankmini/assets/images/lbank.png" alt="SCHOBANK Logo" class="logo">
            <div class="bank-name">SCHOBANK</div>
        </div>

        <div class="form-description">
            Pilih metode untuk memulai proses reset password akun Anda. Kami akan mengirimkan link reset ke kontak yang Anda pilih.
        </div>

        <?php if (isset($error_message) && !empty($error_message)): ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success_message) && !empty($success_message)): ?>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php else: ?>
            <form method="POST" id="forgot-password-form">
                <!-- Selector untuk metode reset -->
                <div class="method-selector">
                    <div class="method-option active" data-method="email" id="email-option">
                        <i class="fas fa-envelope"></i> Email
                    </div>
                    <div class="method-option" data-method="whatsapp" id="wa-option">
                        <i class="fab fa-whatsapp"></i> WhatsApp
                    </div>
                </div>
                
                <input type="hidden" name="reset_method" id="reset_method" value="email">
                
                <div class="input-group" id="email-input-group">
                    <i class="fas fa-envelope icon"></i>
                    <input type="text" name="identifier" id="email-input" value="@gmail.com" required autocomplete="email">
                </div>
                
                <div class="input-group" id="wa-input-group" style="display:none;">
                    <i class="fab fa-whatsapp icon"></i>
                    <input type="tel" name="identifier" id="wa-input" value="08" pattern="08[0-9]{8,11}" disabled>
                </div>
                
                <button type="submit" id="submit-button">
                    <span class="button-content">
                        <i class="fas fa-paper-plane"></i> <span id="button-text">Kirim Link Reset via Email</span>
                    </span>
                    <span class="spinner"></span>
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke Halaman Login</a>
        </div>
    </div>

    <script>
        // Prevent zoom
        document.addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '0')) {
                e.preventDefault();
            }
        });

        // Message fade-out and form reset
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            const forgotPasswordForm = document.getElementById('forgot-password-form');
            const emailOption = document.getElementById('email-option');
            const waOption = document.getElementById('wa-option');
            const emailInputGroup = document.getElementById('email-input-group');
            const waInputGroup = document.getElementById('wa-input-group');
            const emailInput = document.getElementById('email-input');
            const waInput = document.getElementById('wa-input');
            const resetMethodInput = document.getElementById('reset_method');
            const buttonText = document.getElementById('button-text');
            const submitButton = document.getElementById('submit-button');

            // Fade out messages
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }

            if (successAlert) {
                setTimeout(() => {
                    successAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 3500);
            }

            if (forgotPasswordForm) {
                forgotPasswordForm.reset();
                // Set initial values after reset
                emailInput.value = '@gmail.com';
                waInput.value = '08';
            }

            // Toggle metode reset
            if (emailOption && waOption) {
                emailOption.addEventListener('click', function() {
                    emailOption.classList.add('active');
                    waOption.classList.remove('active');
                    emailInputGroup.style.display = 'block';
                    waInputGroup.style.display = 'none';
                    emailInput.required = true;
                    emailInput.disabled = false;
                    waInput.required = false;
                    waInput.disabled = true;
                    resetMethodInput.value = 'email';
                    buttonText.innerHTML = 'Kirim Link Reset via Email';
                    // Set cursor position before @gmail.com
                    setTimeout(() => {
                        emailInput.setSelectionRange(0, 0);
                    }, 0);
                });

                waOption.addEventListener('click', function() {
                    waOption.classList.add('active');
                    emailOption.classList.remove('active');
                    waInputGroup.style.display = 'block';
                    emailInputGroup.style.display = 'none';
                    waInput.required = true;
                    waInput.disabled = false;
                    emailInput.required = false;
                    emailInput.disabled = true;
                    resetMethodInput.value = 'whatsapp';
                    buttonText.innerHTML = 'Kirim Link Reset via WhatsApp';
                    // Set cursor position after 08
                    setTimeout(() => {
                        waInput.setSelectionRange(2, 2);
                    }, 0);
                });
            }

            // Email input handling
            emailInput.addEventListener('input', function() {
                const suffix = '@gmail.com';
                if (!this.value.endsWith(suffix)) {
                    this.value = this.value.replace(/@gmail\.com$/, '') + suffix;
                }
                // Allow only alphanumeric, dots, and underscores before @gmail.com
                const prefix = this.value.slice(0, -suffix.length);
                this.value = prefix.replace(/[^a-zA-Z0-9._]/g, '') + suffix;
            });

            emailInput.addEventListener('keydown', function(e) {
                const suffix = '@gmail.com';
                const cursorPos = this.selectionStart;
                const prefixLength = this.value.length - suffix.length;

                // Prevent deleting @gmail.com
                if (e.key === 'Backspace' && cursorPos <= prefixLength) {
                    if (this.value.slice(0, -suffix.length) === '') {
                        e.preventDefault();
                    }
                }
                // Prevent cursor moving into @gmail.com
                if (e.key === 'ArrowRight' && cursorPos >= prefixLength) {
                    e.preventDefault();
                }
                if (e.key === 'ArrowLeft' && cursorPos > prefixLength) {
                    this.setSelectionRange(prefixLength, prefixLength);
                }
            });

            emailInput.addEventListener('click', function() {
                const suffix = '@gmail.com';
                const prefixLength = this.value.length - suffix.length;
                if (this.selectionStart > prefixLength) {
                    this.setSelectionRange(prefixLength, prefixLength);
                }
            });

            // WhatsApp input handling
            waInput.addEventListener('input', function() {
                const prefix = '08';
                if (!this.value.startsWith(prefix)) {
                    this.value = prefix + this.value.replace(/^08/, '');
                }
                // Allow only numbers after 08
                const suffix = this.value.slice(2);
                this.value = prefix + suffix.replace(/[^0-9]/g, '');
                // Limit to 8-11 digits after 08 (total 10-13 digits)
                if (this.value.length > 13) {
                    this.value = this.value.slice(0, 13);
                }
            });

            waInput.addEventListener('keydown', function(e) {
                const prefix = '08';
                const cursorPos = this.selectionStart;

                // Prevent deleting 08
                if (e.key === 'Backspace' && cursorPos <= 2) {
                    e.preventDefault();
                }
                // Prevent cursor moving before 08
                if (e.key === 'ArrowLeft' && cursorPos <= 2) {
                    e.preventDefault();
                }
                if (e.key === 'ArrowRight' && cursorPos < 2) {
                    this.setSelectionRange(2, 2);
                }
            });

            waInput.addEventListener('click', function() {
                if (this.selectionStart < 2) {
                    this.setSelectionRange(2, 2);
                }
            });

            // Ensure cursor starts at correct position
            emailInput.addEventListener('focus', function() {
                const suffix = '@gmail.com';
                const prefixLength = this.value.length - suffix.length;
                this.setSelectionRange(prefixLength, prefixLength);
            });

            waInput.addEventListener('focus', function() {
                this.setSelectionRange(2, 2);
            });

            // Handle form submission with spinner
            if (forgotPasswordForm) {
                forgotPasswordForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevent immediate submission
                    submitButton.classList.add('loading');
                    submitButton.disabled = true; // Disable button to prevent multiple clicks
                    
                    // Add a small delay to ensure spinner is visible
                    setTimeout(() => {
                        this.submit(); // Submit the form after delay
                    }, 1000); // 1 second delay for visibility
                });
            }
        });
    </script>
</body>
</html>