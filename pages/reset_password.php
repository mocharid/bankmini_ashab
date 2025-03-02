<?php
session_start();
require_once '../includes/db_connection.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
$success_message = "";
$error_message = "";
$token_valid = false;
$user_id = null;

// Check if token is valid
if ($token) {
    $current_time = date('Y-m-d H:i:s');
    $query = "SELECT user_id FROM password_reset WHERE token = ? AND expiry > ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $token, $current_time);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $user_id = $row['user_id'];
        $token_valid = true;
    } else {
        $error_message = "Token tidak valid atau sudah kedaluwarsa!";
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $token_valid) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    if ($password !== $confirm_password) {
        $error_message = "Password dan konfirmasi password tidak cocok!";
    } else {
        // Update password using the same SHA2 encryption as in the login script
        $update_query = "UPDATE users SET password = SHA2(?, 256) WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("si", $password, $user_id);
        $update_stmt->execute();
        
        // Delete the reset token
        $delete_query = "DELETE FROM password_reset WHERE user_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $user_id);
        $delete_stmt->execute();
        
        $success_message = "Password berhasil diubah! Silahkan <a href='login.php'>login</a> dengan password baru Anda.";
        $token_valid = false; // Hide the form
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - Bank Mini Sekolah</title>
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
            font-size: 2.5rem;
            color: #0a2e5c;
            margin-bottom: 0.5rem;
        }

        .bank-name {
            font-size: 1.3rem;
            color: #0a2e5c;
            font-weight: bold;
            margin-bottom: 1.2rem;
        }

        .form-description {
            margin-bottom: 1.5rem;
            color: #555;
            font-size: 0.9rem;
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

        .input-group i.toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #0a2e5c;
            cursor: pointer;
            padding: 5px;
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

        input[type="password"] {
            padding-right: 45px;
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
            -webkit-appearance: none;
            appearance: none;
        }

        button:hover, button:active {
            background: #154785;
        }

        button:focus {
            outline: none;
        }

        .error-message {
            background: #ffe5e5;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .success-message {
            background: #e5ffe7;
            color: #27ae60;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }
        
        .back-to-login {
            margin-top: 1.5rem;
            font-size: 0.9rem;
        }
        
        .back-to-login a {
            color: #0a2e5c;
            text-decoration: none;
            font-weight: bold;
        }
        
        .back-to-login a:hover {
            text-decoration: underline;
        }
        
        .password-requirements {
            text-align: left;
            margin-bottom: 1.2rem;
            font-size: 0.8rem;
            color: #555;
            padding: 0.5rem;
            border-radius: 5px;
            background-color: #f9f9f9;
        }
        
        .password-requirements ul {
            margin-left: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <i class="fas fa-university logo"></i>
            <div class="bank-name">SCHOBANK</div>
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
        <?php endif; ?>
        
        <?php if ($token_valid): ?>
            <div class="form-description">
                Masukkan password baru Anda di bawah ini.
            </div>
            
            <form method="POST">
                <div class="password-requirements">
                    <strong>Password sebaiknya:</strong>
                    <ul>
                        <li>Minimal 8 karakter</li>
                        <li>Kombinasi huruf dan angka</li>
                        <li>Mengandung karakter khusus</li>
                    </ul>
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="password" id="password" placeholder="Password Baru" required minlength="8">
                    <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Konfirmasi Password" required minlength="8">
                    <i class="fas fa-eye toggle-password" id="toggleConfirmPassword"></i>
                </div>
                
                <button type="submit">
                    <i class="fas fa-save"></i> Simpan Password Baru
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        const togglePassword = document.getElementById('togglePassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');

        if (togglePassword) {
            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                
                if (type === 'text') {
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                } else {
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                }
            });
        }

        if (toggleConfirmPassword) {
            toggleConfirmPassword.addEventListener('click', function () {
                const type = confirmPassword.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPassword.setAttribute('type', type);
                
                if (type === 'text') {
                    this.classList.remove('fa-eye');
                    this.classList.add('fa-eye-slash');
                } else {
                    this.classList.remove('fa-eye-slash');
                    this.classList.add('fa-eye');
                }
            });
        }

        // Error Message Auto Dismiss
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.style.opacity = '0';
                }, 3000);

                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }
        });
    </script>
</body>
</html>