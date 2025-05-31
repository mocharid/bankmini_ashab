<?php
session_start();
require_once '../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta'); // Set timezone to WIB

$error_message = "";
$success_message = "";
$current_time = date('H:i:s - d M Y'); // Format time in WIB

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error_message = "Password baru tidak cocok!";
    } else {
        // Check token and expiry
        $query = "SELECT user_id FROM password_reset WHERE token = ? AND expiry > NOW()";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $user_id = $row['user_id'];

            // Hash new password with SHA2-256
            $hashed_password = hash('sha256', $new_password);

            // Update password in database
            $update_query = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            $update_stmt->execute();

            // Delete reset token
            $delete_query = "DELETE FROM password_reset WHERE token = ?";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bind_param("s", $token);
            $delete_stmt->execute();

            $success_message = "Password berhasil direset! Silakan login dengan password baru Anda.";
        } else {
            $error_message = "Token tidak valid atau sudah kadaluarsa!";
        }
    }
} elseif (isset($_GET['token'])) {
    $token = $_GET['token'];
} else {
    header("Location: forgot_password.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
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

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a5568;
            cursor: pointer;
            font-size: 1.1rem;
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

            .input-group i.icon, .password-toggle {
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
            Silahkan masukkan password baru untuk akun Anda.
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
            <div class="back-to-login">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
            </div>
        <?php else: ?>
            <form method="POST" id="reset-password-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" id="new_password" name="new_password" placeholder="Masukkan Password Baru" required>
                    <span class="password-toggle" onclick="togglePassword('new_password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Konfirmasi Password Baru" required>
                    <span class="password-toggle" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <button type="submit">
                    <i class="fas fa-key"></i> Reset Password
                </button>
            </form>
            
            <div class="back-to-login">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
            </div>
        <?php endif; ?>
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

        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // Auto-dismiss messages and form reset
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            const resetPasswordForm = document.getElementById('reset-password-form');
            
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

            if (resetPasswordForm) {
                resetPasswordForm.reset();
            }
        });
    </script>
</body>
</html>