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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    $query = "SELECT id, nama, email FROM users WHERE email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
        
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
                        <p class='expire-note'>Link ini akan kadaluarsa dalam 5 menit (sampai " . date('d M Y H:i:s', strtotime($expiry)) . " WIB).</p>
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
                $success_message = "Email reset password telah dikirim ke {$user['email']}. Silakan cek inbox dan folder spam Anda.";
            } catch (Exception $e) {
                error_log("Mail error: " . $mail->ErrorInfo);
                $error_message = "Gagal mengirim email. Silakan coba lagi nanti atau hubungi admin.";
            }
        }
    } else {
        $error_message = "Email tidak terdaftar dalam sistem kami.";
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
            Masukkan email akun Anda untuk memulai proses reset password. Kami akan mengirimkan link reset ke email Anda.
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
                <div class="input-group">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email" placeholder="Masukkan Email Anda" required autocomplete="email">
                </div>
                
                <button type="submit">
                    <i class="fas fa-paper-plane"></i> Kirim Link Reset
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
            }
        });
    </script>
</body>
</html>