<?php
session_start();
require_once '../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

// Handle AJAX request to check user role
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'check_role') {
    $username = $_POST['username'];
    $query = "SELECT role FROM users WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        echo json_encode(['role' => $user['role']]);
    } else {
        echo json_encode(['role' => null]);
    }
    
    $stmt->close();
    exit();
}

$is_petugas = false; // Flag to determine if the user is a petugas

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['action'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $code = isset($_POST['code']) ? $_POST['code'] : '';

    // Query user credentials
    $query = "SELECT * FROM users WHERE username = ? AND password = SHA2(?, 256)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Check if user is petugas and validate code
        if ($user['role'] === 'petugas') {
            $current_date = date('Y-m-d');
            $query_code = "SELECT code FROM petugas_login_codes WHERE valid_date = ? AND code = ?";
            $stmt_code = $conn->prepare($query_code);
            $stmt_code->bind_param("ss", $current_date, $code);
            $stmt_code->execute();
            $result_code = $stmt_code->get_result();

            if ($result_code->num_rows === 0) {
                $_SESSION['error_message'] = "Kode akses petugas salah atau tidak valid!";
                $_SESSION['username'] = $username;
                $_SESSION['password'] = $password;
                $_SESSION['code'] = $code;
                $_SESSION['is_petugas'] = true; // Keep is_petugas true for petugas role
                header("Location: login.php");
                exit();
            }
        }

        // Set session variables and redirect
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['nama'] = $user['nama'];
        header("Location: ../index.php");
        exit();
    } else {
        // Check if the username exists to determine if it's a petugas
        $query_role = "SELECT role FROM users WHERE username = ?";
        $stmt_role = $conn->prepare($query_role);
        $stmt_role->bind_param("s", $username);
        $stmt_role->execute();
        $result_role = $stmt_role->get_result();
        if ($result_role->num_rows > 0) {
            $user = $result_role->fetch_assoc();
            $is_petugas = $user['role'] === 'petugas';
        }

        $_SESSION['error_message'] = "Username atau password salah!";
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
        $_SESSION['code'] = $code;
        $_SESSION['is_petugas'] = $is_petugas;
        header("Location: login.php");
        exit();
    }
}

// Retrieve session data
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
$username = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$password = isset($_SESSION['password']) ? $_SESSION['password'] : '';
$code = isset($_SESSION['code']) ? $_SESSION['code'] : '';
$is_petugas = isset($_SESSION['is_petugas']) ? $_SESSION['is_petugas'] : false;
$show_error_popup = !empty($error_message);
unset($_SESSION['error_message'], $_SESSION['username'], $_SESSION['password'], $_SESSION['code'], $_SESSION['is_petugas']);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --secondary-color: #00B4D8;
            --danger-color: #c53030;
            --text-primary: #1A237E;
            --text-secondary: #4a5568;
            --bg-light: #f5f7fa;
            --shadow-sm: 0 8px 24px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
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
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .left-section {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
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
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
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
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
            color: var(--text-primary);
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
            color: var(--text-secondary);
            font-size: 1.1rem;
        }

        .input-group i.toggle-icon, .input-group i.info-icon {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            transition: color 0.3s ease;
        }

        .input-group i.toggle-icon:hover, .input-group i.info-icon:hover {
            color: var(--secondary-color);
        }

        input {
            width: 100%;
            padding: 12px 40px;
            border: 1px solid #d1d9e6;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 400;
            transition: var(--transition);
            background: #fbfdff;
        }

        input:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        input[type="password"], input[type="tel"]#code {
            padding-right: 50px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        button:hover {
            background: linear-gradient(90deg, #151c66 0%, #009bb8 100%);
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
            transform: translateY(-1px);
        }

        button:active {
            transform: translateY(0);
        }

        .error-overlay, .info-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: pointer;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .error-modal, .info-modal {
            background: linear-gradient(145deg, #ffffff, #ffe6e6);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        .info-modal {
            background: linear-gradient(145deg, #ffffff, #e6f7ff);
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .error-icon, .info-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--danger-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .info-icon {
            color: var(--secondary-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .error-modal h3, .info-modal h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
        }

        .error-modal p, .info-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            line-height: 1.5;
        }

        .bank-tagline {
            color: var(--text-secondary);
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
            color: var(--primary-color);
            font-size: 0.9rem;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .forgot-password a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }

        #code-group {
            display: none;
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
                box-shadow: var(--shadow-sm);
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

            input[type="password"], input[type="tel"]#code {
                padding-right: 45px;
            }

            button {
                padding: 10px;
            }

            .bank-tagline {
                font-size: 0.8rem;
            }

            .input-group i.icon,
            .input-group i.toggle-icon,
            .input-group i.info-icon {
                font-size: 1rem;
            }

            .forgot-password a {
                font-size: 0.85rem;
            }

            .error-modal, .info-modal {
                width: 90%;
                padding: 30px;
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

            <form method="POST" id="login-form">
                <div class="input-group">
                    <i class="fas fa-user icon"></i>
                    <input type="text" name="username" id="username" placeholder="Username" value="<?= htmlspecialchars($username) ?>" required autocomplete="username">
                </div>
                
                <div class="input-group">
                    <i class="fas fa-lock icon"></i>
                    <input type="password" name="password" id="password" placeholder="Password" value="<?= htmlspecialchars($password) ?>" required autocomplete="current-password">
                    <i class="fas fa-eye toggle-icon" id="togglePassword"></i>
                </div>
                
                <div class="input-group" id="code-group" style="display: <?= $is_petugas ? 'block' : 'none' ?>;">
                    <i class="fas fa-key icon"></i>
                    <input type="tel" name="code" id="code" placeholder="Kode Petugas (4 digit)" value="<?= htmlspecialchars($code) ?>" maxlength="4" pattern="\d{4}" inputmode="numeric" onkeypress="return (event.charCode !=8 && event.charCode ==0 || (event.charCode >= 48 && event.charCode <= 57))" <?= $is_petugas ? 'required' : '' ?>>
                    <i class="fas fa-exclamation-circle info-icon" id="codeInfoIcon" title="Informasi Kode Petugas"></i>
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

    <!-- Error Modal -->
    <?php if ($show_error_popup): ?>
        <div class="error-overlay" id="errorModal">
            <div class="error-modal">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3>Kesalahan</h3>
                <p><?= htmlspecialchars($error_message) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Info Modal -->
    <div class="info-overlay" id="infoModal" style="display: none;">
        <div class="info-modal">
            <div class="info-icon">
                <i class="fas fa-info-circle"></i>
            </div>
            <h3>Informasi</h3>
            <p>Kode ini didapat dari admin.</p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zoom
            document.addEventListener('wheel', (e) => {
                if (e.ctrlKey) e.preventDefault();
            }, { passive: false });

            document.addEventListener('keydown', (e) => {
                if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '0')) {
                    e.preventDefault();
                }
            });

            // Toggle visibility for password
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');
            
            if (togglePassword && passwordInput) {
                togglePassword.addEventListener('click', function() {
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    this.classList.toggle('fa-eye');
                    this.classList.toggle('fa-eye-slash');
                });
            }

            // Code input validation
            const codeInput = document.getElementById('code');
            if (codeInput) {
                // Prevent non-numeric input
                codeInput.addEventListener('input', function() {
                    this.value = this.value.replace(/[^0-9]/g, '');
                    if (this.value.length > 4) {
                        this.value = this.value.slice(0, 4);
                    }
                });
            }

            // Show/hide code field based on user role
            const usernameInput = document.getElementById('username');
            const codeGroup = document.getElementById('code-group');
            const isPetugas = <?= $is_petugas ? 'true' : 'false' ?>; // Get initial state from PHP
            
            if (usernameInput && codeGroup) {
                // Set initial state based on PHP
                if (isPetugas) {
                    codeGroup.style.display = 'block';
                    codeInput.setAttribute('required', 'required');
                }

                usernameInput.addEventListener('input', function() {
                    const username = this.value.trim();
                    if (username.length > 0) {
                        // Make AJAX request to check user role
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=check_role&username=' + encodeURIComponent(username)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.role === 'petugas') {
                                codeGroup.style.display = 'block';
                                codeInput.setAttribute('required', 'required');
                            } else if (!isPetugas) { // Only hide if not already set as petugas by PHP
                                codeGroup.style.display = 'none';
                                codeInput.removeAttribute('required');
                            }
                        })
                        .catch(error => {
                            console.error('Error checking role:', error);
                            if (!isPetugas) { // Only hide if not already set as petugas by PHP
                                codeGroup.style.display = 'none';
                                codeInput.removeAttribute('required');
                            }
                        });
                    } else if (!isPetugas) { // Only hide if not already set as petugas by PHP
                        codeGroup.style.display = 'none';
                        codeInput.removeAttribute('required');
                    }
                });
            }

            // Code info icon click handler
            const codeInfoIcon = document.getElementById('codeInfoIcon');
            const infoModal = document.getElementById('infoModal');
            if (codeInfoIcon && infoModal) {
                codeInfoIcon.addEventListener('click', function() {
                    infoModal.style.display = 'flex';
                    // Auto-close after 5 seconds
                    setTimeout(() => {
                        infoModal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        setTimeout(() => {
                            infoModal.style.display = 'none';
                            infoModal.style.animation = ''; // Reset animation
                        }, 500);
                    }, 5000);
                    // Close on click anywhere
                    infoModal.addEventListener('click', function() {
                        this.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        setTimeout(() => {
                            this.style.display = 'none';
                            this.style.animation = ''; // Reset animation
                        }, 500);
                    }, { once: true });
                });
            }

            // Form submission feedback
            const loginForm = document.getElementById('login-form');
            if (loginForm) {
                loginForm.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                        submitBtn.disabled = true;
                    }
                });
            }

            // Auto-close error popup and close on click
            const errorModal = document.getElementById('errorModal');
            if (errorModal) {
                // Auto-close after 5 seconds
                setTimeout(() => {
                    errorModal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => errorModal.remove(), 500);
                }, 5000);

                // Close on click anywhere
                errorModal.addEventListener('click', function() {
                    this.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => this.remove(), 500);
                });
            }
        });
    </script>
</body>
</html>