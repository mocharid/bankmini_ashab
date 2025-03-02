<?php
session_start();
require_once '../includes/db_connection.php';

$success_message = "";
$error_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    
    // Check if username exists
    $query = "SELECT id, nama, role FROM users WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Generate unique token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Check if a reset record already exists for this user
        $check_query = "SELECT id FROM password_reset WHERE user_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $user['id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Update existing record
            $update_query = "UPDATE password_reset SET token = ?, expiry = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssi", $token, $expiry, $user['id']);
            $update_stmt->execute();
        } else {
            // Insert new record
            $insert_query = "INSERT INTO password_reset (user_id, token, expiry) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("iss", $user['id'], $token, $expiry);
            $insert_stmt->execute();
        }
        
        // In a real-world scenario, you would send an email with a link containing the token
        // For this example, we'll just create a link that can be used directly
        $reset_link = "reset_password.php?token=" . $token;
        
        $success_message = "Permintaan reset password berhasil. Silakan gunakan link berikut: <a href='$reset_link'>Reset Password</a>";
    } else {
        $error_message = "Username tidak ditemukan!";
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
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <i class="fas fa-university logo"></i>
            <div class="bank-name">SCHOBANK</div>
        </div>
        
        <div class="form-description">
            Masukkan username Anda untuk memulai proses reset password.
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
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="username" placeholder="Username" required autocomplete="username">
                </div>
                
                <button type="submit">
                    <i class="fas fa-key"></i> Reset Password
                </button>
            </form>
        <?php endif; ?>
        
        <div class="back-to-login">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman login</a>
        </div>
    </div>

    <script>
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