<?php
/**
 * Reset Password Page - Adaptive Path Version (BCRYPT + Normalized DB)
 * File: pages/reset_password.php
 * 
 * Compatible with:
 * - Local: localhost/schobank/pages/reset_password.php
 * - Hosting: domain.com/pages/reset_password.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages' folder
if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: Check if includes/ exists in current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 3: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 4: Search upward for includes/ folder (max 5 levels)
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback: Use current directory
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
// DEFINE WEB BASE URL (for browser access)
// ============================================
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    
    // Remove filename and get directory
    $base_path = dirname($script);
    
    // Remove '/pages' if exists
    $base_path = preg_replace('#/pages$#', '', $base_path);
    
    // Ensure base_path starts with /
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    
    return $protocol . $host . $base_path;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}

// Asset URLs for browser
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// START SESSION & LOAD DB
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta'); // Set timezone to WIB

$error_message = "";
$success_message = "";
$current_time = date('H:i:s - d M Y'); // Format time in WIB

// 1. VALIDASI AWAL TOKEN (GET & POST)
$token = isset($_GET['token']) ? $_GET['token'] : (isset($_POST['token']) ? $_POST['token'] : '');

if (empty($token)) {
    header("Location: " . BASE_URL . "/pages/forgot_password.php");
    exit();
}

// Cek apakah token masih valid di database sebelum menampilkan halaman/memproses
$check_query = "SELECT user_id FROM password_reset WHERE token = ? AND expiry > NOW()";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("s", $token);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows == 0) {
    // Jika token tidak ditemukan atau expired, redirect atau tampilkan error
    // Sebaiknya jangan tampilkan form reset password jika token invalid
    $token_invalid = true;
    $error_message = "Link reset password tidak valid atau sudah kedaluwarsa.";
} else {
    $token_invalid = false;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($token_invalid) {
        // Double check saat POST
        $error_message = "Token tidak valid. Silakan minta link reset baru.";
    } else {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        // Validasi Server Side
        if ($new_password !== $confirm_password) {
            $error_message = "Password baru tidak cocok!";
        } elseif (strlen($new_password) < 8 || !preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $error_message = "Password tidak memenuhi persyaratan!";
        } else {
            // Ambil User ID (sudah divalidasi di atas, tapi ambil lagi untuk keamanan concurrency)
            $row = $check_result->fetch_assoc();
            $user_id = $row['user_id'];

            // Hash new password with BCRYPT
            $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);

            // Start Transaction
            $conn->begin_transaction();

            try {
                // Update password in database
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $hashed_password, $user_id);
                $update_stmt->execute();

                // HAPUS TOKEN AGAR TIDAK BISA DIPAKAI LAGI
                $delete_query = "DELETE FROM password_reset WHERE token = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("s", $token);
                $delete_stmt->execute();

                // Juga hapus request cooldown jika ada agar bersih
                $clear_cooldown = "DELETE FROM reset_request_cooldown WHERE user_id = ? AND method = 'email'";
                $cooldown_stmt = $conn->prepare($clear_cooldown);
                $cooldown_stmt->bind_param("i", $user_id);
                $cooldown_stmt->execute();

                $conn->commit();
                $success_message = "Password berhasil direset! Mengalihkan ke login...";
                
                // Invalidate token flag untuk UI
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
    <title>Reset Password - My Schobank</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --primary-dark: #0d1452;
            --danger-color: #dc2626;
            --success-color: #16a34a;
            --text-primary: #000000;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-lg: 0.75rem;
            --radius-md: 0.5rem;
            --transition: all 0.3s ease;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        html, body { height: 100%; touch-action: manipulation; }
        body {
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            min-height: 100vh;
        }
        .login-container {
            position: relative;
            background: var(--bg-white);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border-light);
        }
        .close-btn {
            position: absolute;
            top: 16px;
            right: 16px;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #64748b;
            cursor: pointer;
            padding: 4px;
            border-radius: 50%;
        }
        .header-section {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-light);
        }
        .logo-container { margin-bottom: 1rem; }
        .logo {
            height: auto;
            max-height: 80px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            transition: var(--transition);
        }
        .description {
            color: var(--text-primary);
            font-size: 0.85rem;
            font-weight: 400;
            line-height: 1.5;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .input-group { margin-bottom: 0.5rem; position: relative; }
        .input-wrapper { position: relative; margin-bottom: 5px; }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 1.1rem;
            z-index: 2;
        }
        .input-field {
            width: 100%;
            padding: 14px 45px 14px 46px;
            border: 2px solid var(--border-light);
            border-radius: var(--radius-md);
            font-size: 15px;
            background: #fdfdfd;
            transition: var(--transition);
            color: var(--text-primary);
        }
        .input-field:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.12);
        }
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 5px;
            z-index: 3;
        }
        
        /* Vertical Password Hints */
        .password-hint-list {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 1.25rem;
            padding-left: 10px;
        }
        .hint-item {
            font-size: 0.75rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.3s ease;
        }
        .hint-item i { font-size: 0.7rem; width: 14px; text-align: center; }
        .hint-item.valid { color: var(--success-color); font-weight: 500; }
        .hint-item.invalid { color: #94a3b8; }

        .match-status {
            font-size: 0.8rem;
            margin-top: 5px;
            margin-bottom: 1.25rem;
            padding-left: 5px;
            display: none;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        .match-status.match { color: var(--success-color); display: flex; }
        .match-status.mismatch { color: var(--danger-color); display: flex; }

        .submit-button {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            position: relative;
            overflow: hidden;
        }
        .submit-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .submit-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            background: #94a3b8;
        }
        
        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .error-message, .success-message {
            padding: 12px 16px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.875rem;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: opacity 0.4s ease;
            font-weight: 500;
        }
        .error-message { background: #fef2f2; border: 1px solid #fecaca; color: var(--danger-color); }
        .success-message { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--success-color); }
        .error-message.fade-out { opacity: 0; }

        .invalid-token-container {
            text-align: center;
            padding: 20px;
        }
        .invalid-token-icon {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }

        @media (max-width: 480px) {
            body { padding: 15px; }
            .login-container { padding: 2rem; }
            .logo { max-height: 70px; }
            .input-field { font-size: 14px; padding: 12px 40px 12px 42px; }
            .submit-button { padding: 12px 18px; font-size: 14px; }
            .description { font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <button type="button" class="close-btn" onclick="window.location.href='<?= BASE_URL ?>/pages/login.php'">
            <i class="fas fa-times"></i>
        </button>

        <div class="header-section">
            <div class="logo-container">
                <img src="<?= ASSETS_URL ?>/images/header.png" alt="My Schobank Logo" class="logo">
            </div>
        </div>

        <!-- ERROR: Token Invalid / Expired -->
        <?php if ($token_invalid && empty($success_message)): ?>
            <div class="invalid-token-container">
                <div class="invalid-token-icon"><i class="fas fa-link-slash"></i></div>
                <div class="error-message" style="display:flex;">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
                <p class="description">Link ini sudah tidak berlaku karena telah digunakan atau kedaluwarsa.</p>
                
            </div>

        <!-- SUCCESS: Password Changed -->
        <?php elseif (!empty($success_message)): ?>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
            </div>
            <script> setTimeout(function() { window.location.href = '<?= BASE_URL ?>/pages/login.php'; }, 3000); </script>

        <!-- FORM: Reset Password -->
        <?php else: ?>
            <p class="description">Silakan masukkan password baru untuk akun Anda.</p>

            <?php if (!empty($error_message)): ?>
                <div class="error-message" id="error-alert">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="reset-password-form">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <!-- Password Field -->
                <div class="input-group">
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="new_password" name="new_password" class="input-field" placeholder="Password Baru" required oninput="validatePassword()">
                        <span class="password-toggle" onclick="togglePassword('new_password')"><i class="fas fa-eye"></i></span>
                    </div>
                    <!-- Vertical Requirements List -->
                    <div class="password-hint-list">
                        <span class="hint-item" id="hint-length"><i class="fas fa-circle"></i> Minimal 8 Karakter</span>
                        <span class="hint-item" id="hint-upper"><i class="fas fa-circle"></i> Minimal 1 Huruf Kapital</span>
                        <span class="hint-item" id="hint-lower"><i class="fas fa-circle"></i> Minimal 1 Huruf Kecil</span>
                        <span class="hint-item" id="hint-number"><i class="fas fa-circle"></i> Minimal 1 Angka</span>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="input-group" style="margin-bottom: 5px;">
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="confirm_password" name="confirm_password" class="input-field" placeholder="Konfirmasi Password" required oninput="validatePassword()">
                        <span class="password-toggle" onclick="togglePassword('confirm_password')"><i class="fas fa-eye"></i></span>
                    </div>
                </div>
                
                <!-- Match Status -->
                <div id="match-status" class="match-status"></div>

                <button type="submit" class="submit-button" id="submitButton" disabled>
                    <i class="fas fa-key" id="btnIcon"></i>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                    <span id="btnText">Reset Password</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function togglePassword(inputId) {
            const input = document.getElementById(inputId);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
            }
        }

        function validatePassword() {
            const pwd = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const btn = document.getElementById('submitButton');
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
                    el.classList.add('valid'); el.classList.remove('invalid');
                    icon.className = 'fas fa-check-circle';
                } else {
                    el.classList.remove('valid'); el.classList.add('invalid');
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
            if (allReqsMet && isMatch) {
                btn.disabled = false;
            } else {
                btn.disabled = true;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const form = document.getElementById('reset-password-form');
            const btn = document.getElementById('submitButton');
            const btnIcon = document.getElementById('btnIcon');
            const spinner = document.getElementById('loadingSpinner');
            const btnText = document.getElementById('btnText');
            
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                    setTimeout(() => errorAlert.style.display = 'none', 500);
                }, 3000);
            }

            if (form) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if(btnIcon) btnIcon.style.display = 'none';
                    if(spinner) spinner.style.display = 'block';
                    if(btnText) btnText.textContent = 'Memproses...';
                    btn.disabled = true;
                    setTimeout(function() { form.submit(); }, 3000);
                });
            }
        });
    </script>
</body>
</html>