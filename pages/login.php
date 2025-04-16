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

        // Cek role petugas
        if ($user['role'] == 'petugas') {
            // Cek apakah petugas sudah login di tempat lain
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
                // Set timezone ke WIB
                date_default_timezone_set('Asia/Jakarta');
                
                // Cek waktu login
                $current_time = date('H:i');
                $start_time = '00:09';
                $end_time = '23:59:00';

                if ($current_time >= $start_time && $current_time <= $end_time) {
                    // Simpan sesi
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['nama'] = $user['nama'];

                    // Simpan sesi aktif ke database
                    $session_id = session_id();
                    $query = "INSERT INTO active_sessions (user_id, session_id, role) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("iss", $user['id'], $session_id, $user['role']);
                    $stmt->execute();

                    // Redirect untuk mencegah resubmission
                    header("Location: ../index.php");
                    exit();
                } else {
                    $_SESSION['error_message'] = "Login hanya bisa dilakukan saat jam tugas 07:30 sampai 15:00 WIB!";
                    $_SESSION['username'] = $username;
                    $_SESSION['password'] = $password;
                }
            }
        } else {
            // Untuk role selain petugas, langsung login
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

    // Redirect ke halaman login untuk mencegah resubmission
    header("Location: login.php");
    exit();
}

// Ambil data dari sesi jika ada
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$password = isset($_SESSION['password']) ? $_SESSION['password'] : '';

// Bersihkan data sesi setelah digunakan
unset($_SESSION['error_message']);
unset($_SESSION['username']);
unset($_SESSION['password']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Roboto', 'Helvetica', 'Arial', sans-serif;
        }

        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: url('https://www.transparenttextures.com/patterns/geometry2.png');
            background-size: cover;
            background-position: center;
            padding: 20px;
        }

        .login-container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
            text-align: center;
            border: 1px solid #e8ecef;
            animation: fadeIn 0.6s ease-out;
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
            width: 110px;
            height: auto;
        }

        .bank-name {
            font-size: 1.5rem;
            color: #003087;
            font-weight: 700;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-group i.icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #555555;
            font-size: 1.1rem;
        }

        .input-group i.toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #555555;
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }

        .input-group i.toggle-password:hover {
            color: #003087;
        }

        input {
            width: 100%;
            padding: 14px 45px;
            border: 1px solid #d1d9e6;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: #f9fbfd;
        }

        input[type="password"] {
            padding-right: 50px;
        }

        input:focus {
            border-color: #003087;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 48, 135, 0.1);
            background: #ffffff;
        }

        button {
            width: 100%;
            padding: 14px;
            background: #003087;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        button:hover {
            background: #0041b8;
            box-shadow: 0 4px 12px rgba(0, 48, 135, 0.2);
            transform: translateY(-1px);
        }

        button:active {
            background: #002a6e;
            transform: translateY(0);
        }

        .error-message {
            background: #fff4f4;
            color: #a94442;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #e4b7b7;
        }

        .error-message.fade-out {
            opacity: 0;
        }

        .bank-tagline {
            color: #666666;
            font-size: 0.85rem;
            margin-top: 2rem;
            line-height: 1.4;
        }

        .forgot-password {
            text-align: right;
            margin-bottom: 1.5rem;
        }

        .forgot-password a {
            color: #003087;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .forgot-password a:hover {
            color: #0041b8;
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 2rem;
            }

            .logo {
                width: 90px;
            }

            .bank-name {
                font-size: 1.3rem;
            }

            input, button {
                font-size: 15px;
                padding: 12px 40px;
            }

            input[type="password"] {
                padding-right: 45px;
            }

            button {
                padding: 12px;
            }

            .bank-tagline {
                font-size: 0.8rem;
            }
        }

        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            select,
            textarea,
            input {
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .input-group i.icon,
            .input-group i.toggle-password {
                font-size: 1rem;
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
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
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
                <a href="forgot_password.php">Forgot Password?</a>
            </div>

            <button type="submit">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>

        <div class="bank-tagline">
            SMK PLUS ASHABULYAMIN CIANJUR
        </div>
    </div>

    <script>
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