<?php
session_start();
require_once '../includes/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username = ? AND password = SHA2(?, 256)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['nama'] = $user['nama']; // Add this line to store the name
        header("Location: ../index.php");
        exit();
    } else {
        $error_message = "Username atau password salah!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - SCHOBANK SYSTEM</title>
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
            width: 100px; /* Ukuran logo yang ditampilkan di halaman */
            height: auto; /* Menjaga aspek rasio */
            margin-bottom: 0.2rem; /* Jarak antara logo dan teks di bawahnya */
        }

        .bank-name {
            font-size: 1.3rem;
            color: #0a2e5c;
            font-weight: bold;
            margin-bottom: 1.2rem;
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
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .error-message.fade-out {
            opacity: 0;
        }

        .bank-tagline {
            color: #666;
            font-size: 0.8rem;
            margin-top: 1.5rem;
            line-height: 1.3;
        }
        
        .forgot-password {
            text-align: right;
            margin-bottom: 1.2rem;
        }
        
        .forgot-password a {
            color: #0a2e5c;
            font-size: 0.85rem;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .forgot-password a:hover {
            color: #154785;
            text-decoration: underline;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
            }
            
            .logo {
                font-size: 2rem;
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
            
            .bank-tagline {
                font-size: 0.75rem;
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

        /* Fix for touch targets */
        @media (max-width: 480px) {
            .input-group i.toggle-password {
                padding: 10px;
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

        <?php if (isset($error_message)): ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="input-group">
                <i class="fas fa-user icon"></i>
                <input type="text" name="username" placeholder="Username" required autocomplete="username">
            </div>
            
            <div class="input-group">
                <i class="fas fa-lock icon"></i>
                <input type="password" name="password" id="password" placeholder="Password" required autocomplete="current-password">
                <i class="fas fa-eye toggle-password" id="togglePassword"></i>
            </div>
            
            <div class="forgot-password">
                <a href="forgot_password.php">Lupa password?</a>
            </div>

            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>

        <div class="bank-tagline">
        SMK PLUS ASHABULYAMIN CIANJUR
        </div>
    </div>

    <script>
        // Toggle Password Visibility
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');

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

        // Error Message Auto Dismiss
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);

                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }
        });
    </script>
</body>
</html>