<?php
session_start();
require_once '../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta'); // Set timezone ke WIB

$error_message = "";
$success_message = "";
$current_time = date('H:i:s - d M Y'); // Format waktu WIB

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $token = $_POST['token'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error_message = "Password baru tidak cocok!";
    } else {
        // Cek token dan waktu kadaluarsa
        $query = "SELECT user_id FROM password_reset WHERE token = ? AND expiry > NOW()";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $user_id = $row['user_id'];

            // Hash password baru dengan SHA2-256
            $hashed_password = hash('sha256', $new_password);

            // Update password di database
            $update_query = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $hashed_password, $user_id);
            $update_stmt->execute();

            // Hapus token reset
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

    .login-container h2 {
        font-size: 2rem;
        margin-bottom: 1.5rem;
        color: #0a2e5c;
        font-weight: 600;
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

    .input-group {
        margin-bottom: 1.8rem;
        text-align: left;
        position: relative;
    }

    .input-group label {
        display: block;
        margin-bottom: 0.7rem;
        font-size: 0.95rem;
        color: #555;
        font-weight: 500;
    }

    .input-group input {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background-color: #f8fafc;
    }

    .input-group input:focus {
        border-color: #0a2e5c;
        outline: none;
        box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
        background-color: #fff;
    }

    .password-toggle {
        position: absolute;
        right: 15px;
        top: 43px;
        color: #718096;
        cursor: pointer;
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
    }

    .back-to-login a:hover {
        color: #3182ce;
    }
</style>
</head>
<body>
    <div class="login-container">
        <h2>Reset Password</h2>
        <div class="time-display">
            <i class="fas fa-clock"></i> <?= $current_time ?> WIB
        </div>
        <?php if ($error_message): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
            </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?= $success_message ?>
            </div>
            <div class="back-to-login">
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Kembali ke halaman login</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="input-group">
                    <label for="new_password"><i class="fas fa-lock"></i> Password Baru</label>
                    <input type="password" id="new_password" name="new_password" required>
                    <span class="password-toggle" onclick="togglePassword('new_password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <div class="input-group">
                    <label for="confirm_password"><i class="fas fa-lock"></i> Konfirmasi Password Baru</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                    <span class="password-toggle" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>
                <button type="submit"><i class="fas fa-key"></i> Reset Password</button>
            </form>
            <div class="back-to-login">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
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
    </script>
</body>
</html>