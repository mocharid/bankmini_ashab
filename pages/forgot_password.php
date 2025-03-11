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
        $expiry = date('Y-m-d H:i:s', strtotime('+24 hours')); // Perpanjang masa berlaku token menjadi 24 jam
        
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
                        h2 { color: #0a2e5c; border-bottom: 2px solid #0a2e5c; padding-bottom: 10px; }
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
                        <p class='expire-note'>Link ini akan kadaluarsa dalam 24 jam (sampai " . date('d M Y H:i', strtotime($expiry)) . " WIB).</p>
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
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0a2e5c, #2c5282);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            padding: 2.5rem;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 450px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 8px;
            background: linear-gradient(90deg, #0a2e5c, #4299e1, #0a2e5c);
            background-size: 200% 100%;
            animation: gradientMove 3s ease infinite;
        }

        @keyframes gradientMove {
            0% {background-position: 0% 50%}
            50% {background-position: 100% 50%}
            100% {background-position: 0% 50%}
        }

        .logo-container {
            margin-bottom: 1.5rem;
        }

        .logo {
            font-size: 3rem;
            color: #0a2e5c;
            margin-bottom: 0.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .bank-name {
            font-size: 1.5rem;
            color: #0a2e5c;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .time-display {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 1.5rem;
            background: #f5f7fa;
            padding: 8px 15px;
            border-radius: 20px;
            display: inline-block;
        }

        .time-display i {
            margin-right: 5px;
            color: #0a2e5c;
        }

        .form-description {
            margin-bottom: 1.8rem;
            color: #555;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.8rem;
        }

        .input-group i.icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #0a2e5c;
            font-size: 1.1rem;
        }

        input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #f8fafc;
        }

        input:focus {
            border-color: #0a2e5c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
            background-color: #fff;
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(90deg, #0a2e5c, #3182ce);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(10, 46, 92, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        button i {
            margin-right: 8px;
        }

        button:hover {
            background: linear-gradient(90deg, #0a2e5c, #2c5282);
            transform: translateY(-2px);
            box-shadow: 0 7px 14px rgba(10, 46, 92, 0.15);
        }

        button:active {
            transform: translateY(0);
        }

        .error-message {
            background: #fff5f5;
            color: #e53e3e;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            border-left: 4px solid #e53e3e;
            text-align: left;
            animation: fadeIn 0.3s ease-in;
            display: flex;
            align-items: center;
        }

        .error-message i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        .success-message {
            background: #f0fff4;
            color: #38a169;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            border-left: 4px solid #38a169;
            text-align: left;
            animation: fadeIn 0.3s ease-in;
            display: flex;
            align-items: center;
        }

        .success-message i {
            margin-right: 10px;
            font-size: 1.1rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .back-to-login {
            margin-top: 2rem;
            font-size: 0.95rem;
            color: #718096;
        }

        .back-to-login a {
            color: #0a2e5c;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
        }

        .back-to-login a i {
            margin-right: 5px;
        }

        .back-to-login a:hover {
            color: #3182ce;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 1.8rem;
            }
            
            .logo {
                font-size: 2.5rem;
            }
            
            .bank-name {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <i class="fas fa-university logo"></i>
            <div class="bank-name">SCHOBANK</div>
        </div>
        
        
        <div class="form-description">
            Masukkan email akun Anda untuk memulai proses reset password. Kami akan mengirimkan link reset ke email Anda.
        </div>

        <?php if ($error_message): ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
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
        // Auto-dismiss messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.style.opacity = '0';
                    errorAlert.style.transform = 'translateY(-10px)';
                    errorAlert.style.transition = 'opacity 0.5s, transform 0.5s';
                }, 5000);

                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 5500);
            }
            
            if (successAlert) {
                setTimeout(() => {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-10px)';
                    successAlert.style.transition = 'opacity 0.5s, transform 0.5s';
                }, 5000);

                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 5500);
            }
        });
    </script>
</body>
</html>