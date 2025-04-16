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

        if ($user['role'] == 'petugas') {
            $query = "SELECT * FROM active_sessions WHERE user_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user['id']);
            $stmt->execute();
            $session_result = $stmt->get_result();

            if ($session_result->num_rows > 0) {
                $_SESSION['error_message'] = "Petugas sudah login di tempat lain!";
                $_SESSION['username'] = $username;
                $_SESSION['password'] = $password;
            } else {
                date_default_timezone_set('Asia/Jakarta');
                $current_time = date('H:i');
                $start_time = '00:09';
                $end_time = '23:59:00';

                if ($current_time >= $start_time && $current_time <= $end_time) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['nama'] = $user['nama'];

                    $session_id = session_id();
                    $query = "INSERT INTO active_sessions (user_id, session_id, role) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("iss", $user['id'], $session_id, $user['role']);
                    $stmt->execute();

                    header("Location: ../index.php");
                    exit();
                } else {
                    $_SESSION['error_message'] = "Login hanya bisa dilakukan saat jam tugas 07:30 sampai 15:00 WIB!";
                    $_SESSION['username'] = $username;
                    $_SESSION['password'] = $password;
                }
            }
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['nama'] = $user['nama'];
            header("Location: ../index.php");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Username atau password salah!";
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
    }

    header("Location: login.php");
    exit();
}

$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$password = isset($_SESSION['password']) ? $_SESSION['password'] : '';
unset($_SESSION['error_message'], $_SESSION['username'], $_SESSION['password']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - SCHOBANK SYSTEM</title>
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

        .main-container {
            display: flex;
            max-width: 900px;
            width: 100%;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .left-section {
            flex: 1;
            background: linear-gradient(135deg, #1A237E 0%, #00B4D8 100%);
            color: white;
            padding: 3rem 2rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .left-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            opacity: 0.3;
        }

        .left-section h1 {
            font-size: 2.4rem;
            font-weight: 800;
            margin-bottom: 0.8rem;
            letter-spacing: -0.5px;
            line-height: 1.2;
            animation: slideIn 0.8s ease-out;
        }

        .left-section .tagline {
            font-size: 1.2rem;
            font-weight: 300;
            margin-bottom: 1.5rem;
            opacity: 0.9;
            animation: slideIn 0.8s ease-out 0.2s;
            animation-fill-mode: backwards;
        }

        .left-section p {
            font-size: 1rem;
            line-height: 1.6;
            opacity: 0.85;
            animation: slideIn 0.8s ease-out 0.4s;
            animation-fill-mode: backwards;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .left-section:hover {
            background: linear-gradient(135deg, #151c66 0%, #009bb8 100%);
            transition: background 0.4s ease;
        }

        .login-container {
            flex: 1;
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 0 12px 12px 0;
            width: 100%;
            max-width: 400px;
            text-align: center;
            border-left: 1px solid #e8ecef;
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

        .input-group i.toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a5568;
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }

        .input-group i.toggle-password:hover {
            color: #00B4D8;
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

        input[type="password"] {
            padding-right: 50px;
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

        .bank-tagline {
            color: #4a5568;
            font-size: 0.85rem;
            margin-top: 2rem;
            line-height: 1.4;
            font-weight: 400;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 1.5rem;
        }

        .forgot-password a {
            color: #1A237E;
            font-size: 0.9rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .forgot-password a:hover {
            color: #00B4D8;
            text-decoration: underline;
            transform: scale(1.05);
        }

        @media (max-width: 768px) {
            .main-container {
                display: block;
                box-shadow: none;
                background: transparent;
            }

            .left-section {
                display: none;
            }

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

            input[type="password"] {
                padding-right: 45px;
            }

            button {
                padding: 10px;
            }

            .bank-tagline {
                font-size: 0.8rem;
            }

            .input-group i.icon,
            .input-group i.toggle-password {
                font-size: 1rem;
            }

            .error-message {
                font-size: 0.85rem;
                padding: 0.6rem;
            }

            .forgot-password a {
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
    <div class="main-container">
        <!-- Left Section (Wide Screens Only) -->
        <div class="left-section">
            <h1>SCHOBANK DIGITAL SYSTEM</h1>
            <div class="tagline">SMK PLUS ASHABULYAMIN CIANJUR</div>
            <p>Nikmati pengelolaan keuangan yang aman dan inovatif bersama SCHOBANK. Dirancang khusus untuk pelajar dan pendidik, kami mendukung Anda menuju kesuksesan finansial.</p>
        </div>

        <!-- Login Form -->
        <div class="login-container">
            <div class="logo-container">
                <img src="/bankmini/assets/images/lbank.png" alt="SCHOBANK Logo" class="logo">
                <div class="bank-name">SCHOBANK</div>
            </div>

            <?php if (isset($error_message)): ?>
                <div class="error-message" id="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="login-form">
                <div class="input-group">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($username); ?>" required autocomplete="username">
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="password" id="password" placeholder="Password" value="<?php echo htmlspecialchars($password); ?>" required autocomplete="current-password">
                    <i class="fas fa-eye toggle-password" id="togglePassword"></i>
                </div>
                
                <div class="forgot-password">
                    <a href="forgot_password.php">Lupa Password?</a>
                </div>

                <button type="submit">
                    <i class="fas fa-sign-in-alt"></i> Masuk
                </button>
            </form>

            <div class="bank-tagline">
                SMK PLUS ASHABULYAMIN CIANJUR
            </div>
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

        // Password toggle
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        togglePassword.addEventListener('click', function () {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });

        // Error message fade-out and form reset
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const loginForm = document.getElementById('login-form');

            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }

            if (loginForm) {
                loginForm.reset();
            }
        });
    </script>
</body>
</html>