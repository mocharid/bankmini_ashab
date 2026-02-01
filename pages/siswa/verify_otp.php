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
function getBaseUrl()
{
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
} elseif ($otp_type === 'username' && isset($_SESSION['new_username']) && !empty($_SESSION['new_username'])) {
    $otp_target = $_SESSION['new_username'];
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
        } elseif ($otp_type === 'username') {
            $new_username = $_SESSION['new_username'];
            $update_query = "UPDATE users SET username = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                // Clear OTP
                $clear_otp = $conn->prepare("UPDATE siswa_profiles SET otp = NULL, otp_expiry = NULL WHERE user_id = ?");
                $clear_otp->bind_param("i", $_SESSION['user_id']);
                $clear_otp->execute();

                // Destroy session and redirect to login
                unset($_SESSION['new_username'], $_SESSION['otp_type']);
                session_destroy();
                header("Location: " . BASE_URL . "/pages/login.php?success=" . urlencode("Username berhasil diubah! Silakan login dengan username baru."));
                exit;
            } else {
                error_log("Failed to update username for user {$_SESSION['user_id']}");
                $_SESSION['otp_error'] = "Gagal memperbarui username. Silakan coba lagi.";
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
    <title>Verifikasi OTP - KASDIG</title>
    <!-- ADAPTIVE FAVICON PATH -->
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --success: #10b981;
            --danger: #ef4444;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.1);
            --radius: 12px;
            --radius-lg: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            width: 100%;
            max-width: 400px;
        }

        .card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 32px 28px;
            box-shadow: var(--shadow-lg);
        }

        /* Header */
        .header {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            height: 50px;
            width: auto;
            margin-bottom: 20px;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--gray-500);
            line-height: 1.5;
        }

        .target-info {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 12px 16px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .target-icon {
            width: 36px;
            height: 36px;
            background: var(--elegant-dark);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 14px;
        }

        .target-text {
            flex: 1;
        }

        .target-label {
            font-size: 11px;
            color: var(--gray-500);
            margin-bottom: 2px;
        }

        .target-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-900);
        }

        /* OTP Inputs */
        .otp-section {
            margin-bottom: 24px;
        }

        .otp-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 12px;
            text-align: center;
        }

        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .otp-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            color: var(--gray-900);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--white);
            transition: all 0.2s ease;
            outline: none;
        }

        .otp-input:focus {
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
        }

        .otp-input:not(:placeholder-shown) {
            border-color: var(--elegant-dark);
            background: var(--gray-50);
        }

        /* Buttons */
        .btn {
            width: 100%;
            padding: 14px 20px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: transparent;
            color: var(--gray-600);
            margin-top: 12px;
        }

        .btn-secondary:hover {
            color: var(--elegant-dark);
            background: var(--gray-50);
        }

        /* Loading Spinner */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid transparent;
            border-top-color: var(--white);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Messages */
        .alert {
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 13px;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #059669;
        }

        .alert i {
            font-size: 16px;
            margin-top: 1px;
        }

        .alert-content {
            flex: 1;
            line-height: 1.5;
        }

        /* Success State */
        .success-state {
            text-align: center;
            padding: 20px 0;
        }

        .success-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: var(--white);
            font-size: 28px;
        }

        .success-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 8px;
        }

        .success-desc {
            font-size: 13px;
            color: var(--gray-500);
            line-height: 1.6;
        }

        .redirect-info {
            margin-top: 20px;
            padding: 12px;
            background: var(--gray-50);
            border-radius: var(--radius);
            font-size: 12px;
            color: var(--gray-500);
        }

        /* Responsive */
        @media (max-width: 420px) {
            .card {
                padding: 24px 20px;
            }

            .otp-input {
                width: 42px;
                height: 50px;
                font-size: 18px;
            }

            .otp-inputs {
                gap: 8px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="card">
            <!-- Header -->
            <div class="header">
                <h1 class="title">Verifikasi OTP</h1>
                <p class="subtitle">Masukkan 6 digit kode verifikasi yang telah dikirim ke email Anda.</p>
            </div>

            <!-- Error Alert -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-error" id="alertError">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="alert-content"><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if ($verification_success): ?>
                <!-- Success State -->
                <div class="success-state">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h2 class="success-title">Verifikasi Berhasil!</h2>
                    <p class="success-desc"><?= htmlspecialchars($success) ?></p>
                    <div class="redirect-info">
                        <i class="fas fa-spinner fa-spin"></i>
                        Mengarahkan ke halaman profil dalam 3 detik...
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/pages/siswa/profil.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            <?php else: ?>
                <!-- OTP Form -->
                <form method="POST" id="otp_form">
                    <div class="otp-section">
                        <div class="otp-label">Kode OTP</div>
                        <div class="otp-inputs">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input type="tel" inputmode="numeric" name="otp<?= $i ?>" maxlength="1" class="otp-input"
                                    data-index="<?= $i ?>" pattern="[0-9]" required placeholder="•">
                            <?php endfor; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <span id="btnText">Verifikasi</span>
                        <div class="spinner" id="spinner"></div>
                    </button>
                </form>

                <a href="<?= BASE_URL ?>/pages/siswa/profil.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('otp_form');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('spinner');
            const otpInputs = document.querySelectorAll('.otp-input');

            // Auto-hide error after 5 seconds
            const alertError = document.getElementById('alertError');
            if (alertError) {
                setTimeout(() => {
                    alertError.style.opacity = '0';
                    alertError.style.transition = 'opacity 0.4s ease';
                    setTimeout(() => alertError.remove(), 400);
                }, 5000);
            }

            // Submit loading
            if (form && submitBtn) {
                form.addEventListener('submit', function () {
                    if (btnText) btnText.style.display = 'none';
                    if (spinner) spinner.style.display = 'block';
                    submitBtn.disabled = true;

                    setTimeout(() => {
                        if (btnText) btnText.style.display = 'inline';
                        if (spinner) spinner.style.display = 'none';
                        submitBtn.disabled = false;
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