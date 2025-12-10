<?php
/**
 * Verify OTP Page - Adaptive Path Version (BCRYPT + Normalized DB)
 * File: pages/siswa/verify_otp.php
 * 
 * Compatible with:
 * - Local: localhost/schobank/pages/siswa/verify_otp.php
 * - Hosting: domain.com/pages/siswa/verify_otp.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/siswa' folder
if (basename($current_dir) === 'siswa' && basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
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
    
    // Remove '/pages/siswa' if exists
    $base_path = preg_replace('#/pages/siswa$#', '', $base_path);
    
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
date_default_timezone_set('Asia/Jakarta');

// Initialize message variables
$error = '';
$success = '';
$verification_success = false;
$otp_target = '';
$otp_type = isset($_SESSION['otp_type']) ? $_SESSION['otp_type'] : '';
$current_time = date('H:i:s - d M Y'); // Format time in WIB

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/pages/siswa/profil.php?error=" . urlencode("Sesi tidak valid. Silakan login kembali."));
    exit;
}

// Check for session-stored messages
if (isset($_SESSION['otp_error'])) {
    $error = $_SESSION['otp_error'];
    unset($_SESSION['otp_error']);
}
if (isset($_SESSION['otp_success'])) {
    $success = $_SESSION['otp_success'];
    $verification_success = true;
    unset($_SESSION['otp_success']);
    header("Refresh: 3; url=" . BASE_URL . "/pages/siswa/profil.php");
}

// Determine OTP type and target
if ($otp_type === 'email' && isset($_SESSION['new_email']) && !empty($_SESSION['new_email'])) {
    $otp_target = $_SESSION['new_email'];
} elseif ($otp_type === 'no_wa' && (isset($_SESSION['new_no_wa']) || $_SESSION['new_no_wa'] === '')) {
    if ($_SESSION['new_no_wa'] === '') {
        $query = "SELECT no_wa FROM siswa_profiles WHERE user_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $otp_target = $user['no_wa'] ?? '';
    } else {
        $otp_target = $_SESSION['new_no_wa'];
    }
} else {
    $_SESSION['otp_error'] = "Sesi verifikasi tidak valid. Silakan coba lagi.";
    header("Location: " . BASE_URL . "/pages/siswa/profil.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Combine OTP inputs
    $otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $otp .= isset($_POST['otp' . $i]) ? trim($_POST['otp' . $i]) : '';
    }

    // Validate OTP
    if (empty($otp) || strlen($otp) !== 6 || !ctype_digit($otp)) {
        $_SESSION['otp_error'] = "Kode OTP harus terdiri dari 6 digit angka!";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }

    // Verify OTP
    $query = "SELECT otp, otp_expiry FROM siswa_profiles WHERE user_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && $user['otp'] === $otp && strtotime($user['otp_expiry']) > time()) {
        // OTP valid, update based on otp_type
        if ($otp_type === 'email') {
            $new_email = $_SESSION['new_email'];
            $update_query = "UPDATE users SET email = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_email, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                // Clear OTP
                $clear_otp = $conn->prepare("UPDATE siswa_profiles SET otp = NULL, otp_expiry = NULL WHERE user_id = ?");
                $clear_otp->bind_param("i", $_SESSION['user_id']);
                $clear_otp->execute();

                $_SESSION['otp_success'] = "Email berhasil diperbarui menjadi: " . htmlspecialchars($new_email);
                unset($_SESSION['new_email'], $_SESSION['otp_type']);
            } else {
                error_log("Failed to update email for user {$_SESSION['user_id']}");
                $_SESSION['otp_error'] = "Gagal memperbarui email. Silakan coba lagi.";
            }
        } elseif ($otp_type === 'no_wa') {
            $new_no_wa = $_SESSION['new_no_wa'] === '' ? NULL : $_SESSION['new_no_wa'];
            $update_query = "UPDATE siswa_profiles SET no_wa = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_no_wa, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                // Clear OTP
                $clear_otp = $conn->prepare("UPDATE siswa_profiles SET otp = NULL, otp_expiry = NULL WHERE user_id = ?");
                $clear_otp->bind_param("i", $_SESSION['user_id']);
                $clear_otp->execute();

                $success_message = $new_no_wa === NULL ? "Nomor WhatsApp berhasil dihapus." : "Nomor WhatsApp berhasil diperbarui menjadi: " . htmlspecialchars($new_no_wa);
                $_SESSION['otp_success'] = $success_message;
                unset($_SESSION['new_no_wa'], $_SESSION['otp_type']);
            } else {
                error_log("Failed to update WhatsApp number for user {$_SESSION['user_id']}");
                $_SESSION['otp_error'] = "Gagal memperbarui nomor WhatsApp. Silakan coba lagi.";
            }
        }
    } else {
        $_SESSION['otp_error'] = "Kode OTP tidak valid atau telah kedaluwarsa!";
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Verifikasi OTP - SCHOBANK SYSTEM</title>
    <!-- ADAPTIVE FAVICON PATH -->
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --primary-dark: #0d1452;
            --primary-light: #534bae;
            --secondary-color: #00B4D8;
            --danger-color: #dc2626;
            --success-color: #16a34a;
            --text-primary: #000000;
            --text-secondary: #000000;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --border-medium: #cbd5e1;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --transition: all 0.3s ease;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        html, body {
            height: 100%;
            touch-action: manipulation;
        }
        body {
            background: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            min-height: 100vh;
        }
        .login-container {
            background: var(--bg-white);
            padding: 2.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
            border: 1px solid var(--border-light);
            transition: var(--transition);
        }
        .header-section {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-light);
        }
        .logo-container {
            margin-bottom: 1rem;
        }
        .logo {
            height: auto;
            max-height: 80px;
            width: auto;
            max-width: 100%;
            object-fit: contain;
            transition: var(--transition);
        }
        .logo:hover {
            transform: scale(1.03);
        }
        .subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 400;
        }
        .form-section {
            margin-bottom: 1.5rem;
        }
        .input-group {
            margin-bottom: 1.25rem;
            position: relative;
        }
        .input-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            font-weight: 500;
        }
        .input-wrapper {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.1rem;
            z-index: 2;
        }
        .input-field {
            width: 100%;
            padding: 14px 14px 14px 46px;
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
        .input-field.input-error {
            border-color: var(--danger-color);
            background: #fef2f2;
        }
        .input-field.input-error:focus {
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.12);
        }
        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 4px;
            border-radius: var(--radius-sm);
            transition: var(--transition);
        }
        .toggle-password:hover {
            color: var(--primary-color);
            background: rgba(26, 35, 126, 0.08);
        }
        .error-message {
            color: var(--danger-color);
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }
        .general-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 1rem;
            text-align: center;
            font-size: 0.9rem;
            transition: opacity 0.4s ease;
        }
        .general-error.fade-out {
            opacity: 0;
        }
        .forgot-password {
            text-align: right;
            margin: -0.5rem 0 1.5rem 0;
        }
        .forgot-link {
            color: var(--text-primary);
            font-size: 0.75rem;
            font-weight: 500;
            text-decoration: none;
            transition: var(--transition);
        }
        .forgot-link .link-here {
            color: #2563eb;
            text-decoration: none;
        }
        .forgot-link:hover .link-here {
            color: #1d4ed8;
            text-decoration: none;
        }
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
        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }
        .submit-button:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
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
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .otp-inputs {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 1.5rem;
        }
        .otp-inputs input {
            width: 40px;
            height: 50px;
            text-align: center;
            padding: 0;
            font-size: 18px;
            border: 1px solid #d1d9e6;
            border-radius: 6px;
            background: #fbfdff;
            transition: all 0.3s ease;
        }

        .otp-inputs input:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        .form-description {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 400;
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

        /* Responsif */
        @media (max-width: 480px) {
            body { padding: 15px; }
            .login-container {
                padding: 2rem;
                border-radius: var(--radius-md);
            }
            .logo { max-height: 70px; }
            .input-field { font-size: 14px; padding: 12px 12px 12px 42px; }
            .submit-button { padding: 12px 18px; font-size: 14px; }
            .forgot-link { font-size: 0.7rem; }
        }
        @media (max-height: 600px) and (orientation: landscape) {
            body { align-items: flex-start; padding-top: 20px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Header -->
        <div class="header-section">
            <div class="logo-container">
                <!-- ADAPTIVE LOGO PATH -->
                <img src="<?= ASSETS_URL ?>/images/header.png" alt="SCHOBANK" class="logo">
            </div>
            <p class="subtitle">Verifikasi Kode OTP</p>
        </div>

        <!-- General Error (Auto-hide in 5 seconds) -->
        <?php if (!empty($error)): ?>
            <div class="general-error" id="generalError">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($verification_success): ?>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <div class="form-description">
                <?php echo $otp_type === 'email' ? 
                    'Email Anda telah berhasil diperbarui.' : 
                    'Nomor WhatsApp Anda telah berhasil diperbarui.'; ?>
                Anda akan diarahkan ke halaman profil dalam 3 detik...
            </div>
            <div class="back-to-login">
                <a href="<?= BASE_URL ?>/pages/siswa/profil.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman profil</a>
            </div>
        <?php else: ?>
            <form method="POST" id="otp_form">
                <div class="otp-inputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="tel" inputmode="numeric" name="otp<?php echo $i; ?>" maxlength="1" 
                               class="otp-input" data-index="<?php echo $i; ?>" 
                               pattern="[0-9]" required>
                    <?php endfor; ?>
                </div>
                <button type="submit" class="submit-button" id="submitButton">
                    <i class="fas fa-sign-in-alt"></i>
                    <span id="buttonText">Verifikasi OTP</span>
                    <div class="loading-spinner" id="loadingSpinner"></div>
                </button>
            </form>
            
            <div class="back-to-login">
                <a href="<?= BASE_URL ?>/pages/siswa/profil.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman profil</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('otp_form');
            const submitBtn = document.getElementById('submitButton');
            const btnText = document.getElementById('buttonText');
            const spinner = document.getElementById('loadingSpinner');
            const otpInputs = document.querySelectorAll('.otp-input');

            // Auto-hide general error after 5 seconds
            const generalError = document.getElementById('generalError');
            if (generalError) {
                setTimeout(() => {
                    generalError.classList.add('fade-out');
                    setTimeout(() => generalError.remove(), 400);
                }, 5000);
            }

            // Submit loading
            if (form) {
                form.addEventListener('submit', function() {
                    btnText.style.display = 'none';
                    spinner.style.display = 'block';
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<div class="loading-spinner" style="display:block;"></div>';

                    setTimeout(() => {
                        btnText.style.display = 'inline';
                        spinner.style.display = 'none';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i><span id="buttonText">Verifikasi OTP</span>';
                    }, 5000);
                });
            }

            // OTP input navigation
            otpInputs.forEach((input, index) => {
                input.addEventListener('input', (e) => {
                    const value = e.target.value;
                    if (value && !/^[0-9]$/.test(value)) {
                        e.target.value = '';
                        return;
                    }

                    if (value && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }

                    if (index === otpInputs.length - 1) {
                        input.blur();
                    }
                });

                input.addEventListener('keydown', (e) => {
                    const currentIndex = index;
                    if (e.key === 'Backspace' && !input.value && currentIndex > 0) {
                        otpInputs[currentIndex - 1].focus();
                        otpInputs[currentIndex - 1].value = '';
                    }

                    if (e.key === 'ArrowRight' && currentIndex < otpInputs.length - 1) {
                        e.preventDefault();
                        otpInputs[currentIndex + 1].focus();
                    }

                    if (e.key === 'ArrowLeft' && currentIndex > 0) {
                        e.preventDefault();
                        otpInputs[currentIndex - 1].focus();
                    }
                });

                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text').trim();
                    if (/^\d{1,6}$/.test(pastedData)) {
                        const digits = pastedData.split('');
                        otpInputs.forEach((inp, i) => {
                            inp.value = i < digits.length ? digits[i] : '';
                        });
                        const lastFilledIndex = Math.min(digits.length - 1, otpInputs.length - 1);
                        otpInputs[lastFilledIndex].focus();
                    }
                });
            });

            // Auto-focus first input
            const firstInput = document.querySelector('input[name="otp1"]');
            if (firstInput) {
                firstInput.focus();
            }
        });
    </script>
</body>
</html>