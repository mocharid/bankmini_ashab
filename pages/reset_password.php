<?php
/**
 * Reset Password Page - Clean Banking Design
 * File: pages/reset_password.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

if (!$project_root) {
    $project_root = $current_dir;
}

// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// DEFINE WEB BASE URL
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = preg_replace('#/pages$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}

define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// START SESSION & LOAD DB
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

$error_message = "";
$success_message = "";

// Validasi Token
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');

if (empty($token)) {
    header("Location: " . BASE_URL . "/pages/forgot_password.php");
    exit();
}

// Cek token valid di database
$check_query = "SELECT user_id FROM password_reset WHERE token = ? AND expiry > NOW()";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("s", $token);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows == 0) {
    $token_invalid = true;
    $error_message = "Link reset password tidak valid atau sudah kedaluwarsa.";
} else {
    $token_invalid = false;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($token_invalid) {
        $error_message = "Token tidak valid. Silakan minta link reset baru.";
    } else {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error_message = "Password baru tidak cocok!";
        } elseif (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $error_message = "Password tidak memenuhi persyaratan!";
        } else {
            $row = $check_result->fetch_assoc();
            $user_id = $row['user_id'];
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

            $conn->begin_transaction();

            try {
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $hashed_password, $user_id);
                $update_stmt->execute();

                $delete_query = "DELETE FROM password_reset WHERE token = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("s", $token);
                $delete_stmt->execute();

                $clear_cooldown = "DELETE FROM reset_request_cooldown WHERE user_id = ? AND method = 'email'";
                $cooldown_stmt = $conn->prepare($clear_cooldown);
                $cooldown_stmt->bind_param("i", $user_id);
                $cooldown_stmt->execute();

                $conn->commit();
                $success_message = "Password berhasil direset! Mengalihkan ke login...";
                $token_invalid = true;

            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <title>Reset Password | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            height: 100%;
            font-family: 'Poppins', sans-serif;
            background: #ffffff;
        }

        body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 24px;
        }

        .logo-icon img {
            height: 150px;
            width: auto;
        }

        /* Description */
        .description {
            text-align: center;
            color: #666;
            font-size: 0.85rem;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            color: #888;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .input-wrapper {
            position: relative;
        }

        .form-input {
            width: 100%;
            padding: 14px 40px 14px 0;
            border: none;
            border-bottom: 1.5px solid #e0e0e0;
            font-size: 1rem;
            color: #1a1a2e;
            background: transparent;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-input:focus {
            outline: none;
            border-bottom-color: #1a1a2e;
        }

        .form-input::placeholder {
            color: #c0c0c0;
        }

        .form-input.input-error {
            border-bottom-color: #e74c3c;
        }

        .toggle-password {
            position: absolute;
            right: 0;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8e8e8e;
            cursor: pointer;
            font-size: 1rem;
            padding: 5px;
        }

        .toggle-password:hover {
            color: #1a1a2e;
        }

        /* Password Hints */
        .password-hints {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 10px;
            margin-bottom: 20px;
        }

        .hint-item {
            font-size: 0.75rem;
            color: #b0b0b0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.3s ease;
        }

        .hint-item i {
            font-size: 0.7rem;
            width: 14px;
            text-align: center;
        }

        .hint-item.valid {
            color: #16a34a;
        }

        .hint-item.valid i {
            color: #16a34a;
        }

        /* Match Status */
        .match-status {
            font-size: 0.8rem;
            margin-top: 8px;
            display: none;
            align-items: center;
            gap: 5px;
        }

        .match-status.match {
            color: #16a34a;
            display: flex;
        }

        .match-status.mismatch {
            color: #e74c3c;
            display: flex;
        }

        /* General Error & Success */
        .general-error,
        .general-success {
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .general-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
        }

        .general-error i {
            color: #e74c3c;
        }

        .general-error span {
            color: #991b1b;
            font-size: 0.85rem;
        }

        .general-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
        }

        .general-success i {
            color: #16a34a;
        }

        .general-success span {
            color: #166534;
            font-size: 0.85rem;
        }

        /* Invalid Token */
        .invalid-token {
            text-align: center;
            padding: 20px 0;
        }

        .invalid-token-icon {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 15px;
        }

        /* Submit Button */
        .submit-btn {
            width: 100%;
            padding: 16px;
            background: #1a1a2e;
            color: #ffffff;
            border: none;
            border-radius: 30px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 25px;
        }

        .submit-btn:hover:not(:disabled) {
            background: #2d2d4a;
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(26, 26, 46, 0.3);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            background: #94a3b8;
        }

        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid #ffffff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Back Link */
        .back-link {
            text-align: center;
            margin-top: 25px;
        }

        .back-link a {
            color: #8e8e8e;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.3s ease;
        }

        .back-link a:hover {
            color: #1a1a2e;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 20px;
            }

            .login-container {
                padding: 20px;
            }

            .logo-section {
                margin-bottom: 20px;
            }

            .logo-icon img {
                height: 120px;
            }

            .description {
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-icon">
                <img src="<?= ASSETS_URL ?>/images/header.png" alt="KASDIG" draggable="false">
            </div>
        </div>

        <!-- Token Invalid -->
        <?php if ($token_invalid && empty($success_message)): ?>
            <div class="invalid-token">
                <div class="invalid-token-icon">
                    <i class="fas fa-link-slash"></i>
                </div>
                <div class="general-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
                <p class="description">Link ini sudah tidak berlaku karena telah digunakan atau kedaluwarsa.</p>
            </div>

            <!-- Success Message -->
        <?php elseif (!empty($success_message)): ?>
            <div class="general-success" id="generalSuccess">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($success_message) ?></span>
            </div>
            <script>
                setTimeout(function () {
                    window.location.href = '<?= BASE_URL ?>/pages/login.php';
                }, 3000);
            </script>

            <!-- Reset Password Form -->
        <?php else: ?>
            <p class="description">
                Silakan masukkan password baru untuk akun Anda.
            </p>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="general-error" id="generalError">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="reset-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                <!-- New Password -->
                <div class="form-group">
                    <label class="form-label">Password Baru</label>
                    <div class="input-wrapper">
                        <input type="password" name="new_password" id="new_password" class="form-input"
                            placeholder="Masukkan password baru" required oninput="validatePassword()">
                        <button type="button" class="toggle-password" onclick="togglePassword('new_password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <!-- Password Hints -->
                    <div class="password-hints">
                        <span class="hint-item" id="hint-length"><i class="fas fa-circle"></i> Minimal 8 Karakter</span>
                        <span class="hint-item" id="hint-upper"><i class="fas fa-circle"></i> Minimal 1 Huruf Kapital</span>
                        <span class="hint-item" id="hint-lower"><i class="fas fa-circle"></i> Minimal 1 Huruf Kecil</span>
                        <span class="hint-item" id="hint-number"><i class="fas fa-circle"></i> Minimal 1 Angka</span>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label class="form-label">Konfirmasi Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input"
                            placeholder="Masukkan ulang password" required oninput="validatePassword()">
                        <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="match-status" class="match-status"></div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    <span id="btnText">Reset Password</span>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                </button>
            </form>
        <?php endif; ?>

        <!-- Back to Login -->
        <div class="back-link">
            <a href="<?= BASE_URL ?>/pages/login.php">
                <i class="fas fa-arrow-left"></i>
                Kembali ke halaman masuk
            </a>
        </div>
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

        function validatePassword() {
            const pwd = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const btn = document.getElementById('submitBtn');
            const matchStatus = document.getElementById('match-status');

            // Requirements
            const reqs = {
                length: pwd.length >= 8,
                upper: /[A-Z]/.test(pwd),
                lower: /[a-z]/.test(pwd),
                number: /[0-9]/.test(pwd)
            };

            // Update Hints
            const updateHint = (id, valid) => {
                const el = document.getElementById(id);
                const icon = el.querySelector('i');
                if (valid) {
                    el.classList.add('valid');
                    icon.className = 'fas fa-check-circle';
                } else {
                    el.classList.remove('valid');
                    icon.className = 'fas fa-circle';
                }
            };

            updateHint('hint-length', reqs.length);
            updateHint('hint-upper', reqs.upper);
            updateHint('hint-lower', reqs.lower);
            updateHint('hint-number', reqs.number);

            // Match Logic
            const allReqsMet = Object.values(reqs).every(Boolean);
            let isMatch = false;

            if (confirm.length > 0) {
                if (pwd === confirm) {
                    matchStatus.className = 'match-status match';
                    matchStatus.innerHTML = '<i class="fas fa-check-circle"></i> Password cocok';
                    isMatch = true;
                } else {
                    matchStatus.className = 'match-status mismatch';
                    matchStatus.innerHTML = '<i class="fas fa-times-circle"></i> Password tidak cocok';
                    isMatch = false;
                }
            } else {
                matchStatus.className = 'match-status';
                matchStatus.innerHTML = '';
                isMatch = false;
            }

            // Button State
            btn.disabled = !(allReqsMet && isMatch);
        }

        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('reset-form');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('loadingSpinner');

            // Auto-hide error message
            const generalError = document.getElementById('generalError');
            if (generalError) {
                setTimeout(() => {
                    generalError.style.opacity = '0';
                    generalError.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => generalError.remove(), 400);
                }, 5000);
            }

            // Form submit with loading
            form?.addEventListener('submit', function (e) {
                e.preventDefault();
                btnText.textContent = 'Memproses...';
                spinner.style.display = 'block';
                submitBtn.disabled = true;
                setTimeout(() => form.submit(), 1000);
            });
        });
    </script>
</body>

</html>