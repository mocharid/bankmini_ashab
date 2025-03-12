<?php
session_start();
require_once '../includes/db_connection.php';
require '../vendor/autoload.php'; // Sesuaikan path ke autoload PHPMailer
date_default_timezone_set('Asia/Jakarta'); // Set timezone ke WIB

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$success_message = "";
$error_message = "";
$current_time = date('H:i:s - d M Y'); // Format waktu WIB

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    
    // Check if email exists
    $query = "SELECT id, nama, email FROM users WHERE email = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Generate unique token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes')); // Ubah masa berlaku token menjadi 5 menit
        
        // Hapus token lama jika ada untuk user ini
        $delete_query = "DELETE FROM password_reset WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $user['id']);
        $delete_stmt->execute();
        
        // Insert new record
        $insert_query = "INSERT INTO password_reset (user_id, token, expiry) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("iss", $user['id'], $token, $expiry);
        $success = $insert_stmt->execute();
        
        if (!$success) {
            // Log database error
            error_log("Database error: " . $conn->error);
            $error_message = "Terjadi kesalahan database. Silakan coba lagi nanti.";
        } else {
            // Dapatkan hostname/base URL dari request saat ini
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $baseUrl = $protocol . "://" . $host;
            $path = dirname($_SERVER['PHP_SELF']);
            
            // Buat reset link yang absolut
            $reset_link = $baseUrl . $path . "/reset_password.php?token=" . $token;
            
            $subject = "Reset Password - SCHOBANK SYSTEM";
            $message = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                        h2 { color:rgb(4, 20, 41); border-bottom: 2px solidrgb(243, 244, 245); padding-bottom: 10px; }
                        .btn { display: inline-block; background: #0a2e5c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
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
                            <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                        </div>
                    </div>
                </body>
                </html>
            ";
            
            try {
                // Konfigurasi PHPMailer
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com'; // Ganti dengan SMTP host Anda
                $mail->SMTPAuth = true;
                $mail->Username = 'mocharid.ip@gmail.com'; // Ganti dengan email pengirim
                $mail->Password = 'spjs plkg ktuu lcxh'; // Ganti dengan password email pengirim
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                // Pengirim dan penerima
                $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM'); // Konsisten dengan username
                $mail->addAddress($user['email'], $user['nama']); // Email penerima

                // Konten email
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $message;
                $mail->AltBody = strip_tags(str_replace('<br>', "\n", $message));

                // Kirim email
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
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Lupa Password - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: url('https://www.transparenttextures.com/patterns/geometry.png');
            background-size: cover;
            background-position: center;
            padding: 20px;
        }

        .login-container {
            background: rgba(255, 255, 255, 0.9);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            backdrop-filter: blur(5px);
        }

        .logo-container {
            margin-bottom: 1.5rem;
        }

        .logo {
            width: 100px;
            height: auto;
            margin-bottom: 0.2rem;
        }

        .bank-name {
            font-size: 1.3rem;
            color: #0a2e5c;
            font-weight: bold;
            margin-bottom: 1.2rem;
        }

        .form-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 1.2rem;
            line-height: 1.4;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.2rem;
        }

        .input-group i.icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #0a2e5c;
        }

        input {
            width: 100%;
            padding: 12px 40px;
            border: 2px solid #e1e5ee;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            -webkit-appearance: none;
            appearance: none;
        }

        input:focus {
            border-color: #0a2e5c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
        }

        button {
            width: 100%;
            padding: 12px;
            background: #0a2e5c;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-appearance: none;
            appearance: none;
        }

        button i {
            margin-right: 8px;
        }

        button:hover, button:active {
            background: #154785;
        }

        .error-message {
            background: #ffe5e5;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .error-message.fade-out {
            opacity: 0;
        }

        .success-message {
            background: #e5ffe5;
            color: #27ae60;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .success-message.fade-out {
            opacity: 0;
        }

        .back-to-login {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-to-login a {
            color: #0a2e5c;
            font-size: 0.9rem;
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .back-to-login a i {
            margin-right: 5px;
        }

        .back-to-login a:hover {
            color: #154785;
            text-decoration: underline;
        }

        /* Responsive adjustments */
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
                font-size: 16px;
                padding: 10px 40px;
            }
            
            button {
                padding: 10px;
            }
        }

        /* Fix for iOS zoom on input focus */
        @media screen and (-webkit-min-device-pixel-ratio: 0) { 
            select,
            textarea,
            input {
                font-size: 16px;
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
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success_message) && !empty($success_message)): ?>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i>
                <?php echo $success_message; ?>
            </div>
        <?php else: ?>
            <form method="POST">
                <div class="input-group">
                    <i class="fas fa-envelope icon"></i>
                    <input type="email" name="email" placeholder="Masukkan email Anda" required autocomplete="email">
                </div>
                
                <button type="submit">
                    <i class="fas fa-paper-plane"></i> Kirim Link Reset
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
        </div>
    </div>

    <script>
        // Auto-dismiss messages after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            
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
        });
    </script>
</body>
</html>