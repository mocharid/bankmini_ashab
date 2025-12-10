<?php
/**
 * OTP Email Verification Page - Adaptive Path Version
 * Contoh penempatan: pages/otp_email.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir  = dirname($current_file);
$project_root = null;

// Jika file ada di folder pages atau turunan pages
if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    // Jika langsung punya includes
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    // Jika includes di parent
    $project_root = dirname($current_dir);
} else {
    // Cari ke atas max 5 level
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback
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
// DEFINE WEB BASE URL (mirip forgot_password)
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ? 'https://'
        : 'http://';

    $host   = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];

    // Ambil direktori tanpa nama file
    $base_path = dirname($script);

    // Hilangkan /pages di ujung kalau ada
    $base_path = preg_replace('#/pages$#', '', $base_path);

    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }

    return $protocol . $host . $base_path;
}

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}

if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '/assets');
}

// ============================================
// START SESSION & DB
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// ============================================
// LOGIC VERIFIKASI OTP
// ============================================
$token               = filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '';
$token               = trim(preg_replace('/[^a-f0-9]/i', '', $token));
$error_message       = '';
$success_message     = '';
$user_data           = null;
$verification_success = false;

// Ambil pesan dari session (hasil submit sebelumnya)
if (isset($_SESSION['otp_success'])) {
    $success_message      = $_SESSION['otp_success'];
    $verification_success = true;
    unset($_SESSION['otp_success']);

    // Redirect ke login 3 detik
    header('Refresh: 3; url=' . BASE_URL . '/pages/login.php');
} elseif (isset($_SESSION['otp_error'])) {
    $error_message = $_SESSION['otp_error'];
    unset($_SESSION['otp_error']);
}

// Validasi token
if (empty($token)) {
    $error_message = 'Token tidak valid atau telah kedaluarsa.';
} elseif (strlen($token) !== 64 || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $error_message = 'Format token tidak valid.';
} else {
    // Cek token di database (struktur sama seperti di file lama)
    $query = "SELECT pr.*, u.nama, u.id AS user_id, u.email, u.otp, u.otp_expiry, pr.new_email
              FROM password_reset pr
              JOIN users u ON pr.user_id = u.id
              WHERE pr.token = ? AND pr.expiry > NOW()";
    $stmt = $conn->prepare($query);

    if ($stmt === false) {
        error_log('Prepare failed: ' . $conn->error);
        $error_message = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
    } else {
        $stmt->bind_param('s', $token);
        if (!$stmt->execute()) {
            error_log('Execute failed: ' . $stmt->error);
            $error_message = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
        } else {
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                error_log("Invalid or expired token: $token");
                $error_message = 'Token tidak valid atau telah kedaluarsa.';
            } else {
                $user_data = $result->fetch_assoc();
                if (empty($user_data['new_email']) || empty($user_data['otp'])) {
                    $error_message = 'Data pemulihan tidak lengkap. Silakan ulangi proses.';
                }
            }
        }
        $stmt->close();
    }
}

// PROSES SUBMIT OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($user_data)) {
    // Gabungkan 6 input OTP
    $entered_otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $entered_otp .= $_POST['otp' . $i] ?? '';
    }

    if (empty($entered_otp) || !preg_match('/^[0-9]{6}$/', $entered_otp)) {
        $_SESSION['otp_error'] = 'OTP harus berupa 6 digit angka.';
    } else {
        // Batasi percobaan OTP (pakai tabel reset_request_cooldown seperti sebelumnya)
        $cooldown_query = "SELECT COUNT(*) AS attempts
                           FROM reset_request_cooldown
                           WHERE user_id = ? AND method = 'otp'
                             AND last_reset_request > NOW() - INTERVAL 1 HOUR";
        $cooldown_stmt = $conn->prepare($cooldown_query);

        if ($cooldown_stmt === false) {
            error_log('Prepare failed for cooldown check: ' . $conn->error);
            $_SESSION['otp_error'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
        } else {
            $cooldown_stmt->bind_param('i', $user_data['user_id']);
            $cooldown_stmt->execute();
            $cooldown_result = $cooldown_stmt->get_result()->fetch_assoc();

            if ($cooldown_result['attempts'] >= 5) {
                $_SESSION['otp_error'] = 'Terlalu banyak percobaan OTP. Silakan coba lagi dalam satu jam.';
            } else {
                // Catat percobaan OTP
                $upsert_query = "INSERT INTO reset_request_cooldown (user_id, method, last_reset_request)
                                 VALUES (?, 'otp', NOW())
                                 ON DUPLICATE KEY UPDATE last_reset_request = NOW()";
                $upsert_stmt = $conn->prepare($upsert_query);

                if ($upsert_stmt === false) {
                    error_log('Prepare failed for cooldown upsert: ' . $conn->error);
                    $_SESSION['otp_error'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
                } else {
                    $upsert_stmt->bind_param('i', $user_data['user_id']);
                    $upsert_stmt->execute();
                    $upsert_stmt->close();

                    // Cek masa berlaku & kecocokan OTP
                    if ($user_data['otp_expiry'] < date('Y-m-d H:i:s')) {
                        $_SESSION['otp_error'] = 'OTP telah kedaluarsa. Silakan ulangi proses pemulihan.';
                    } elseif ($user_data['otp'] !== $entered_otp) {
                        $_SESSION['otp_error'] = 'OTP tidak valid.';
                    } else {
                        // OTP benar → update email
                        $update_query = "UPDATE users
                                         SET email = ?, otp = NULL, otp_expiry = NULL
                                         WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);

                        if ($update_stmt === false) {
                            error_log('Prepare failed for email update: ' . $conn->error);
                            $_SESSION['otp_error'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
                        } else {
                            $update_stmt->bind_param('si', $user_data['new_email'], $user_data['user_id']);
                            if ($update_stmt->execute()) {
                                // Log aktivitas (opsional sama seperti sebelumnya)
                                $log_query = "INSERT INTO log_aktivitas
                                              (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan)
                                              VALUES (?, ?, 'email', ?, NOW(), 'Pemulihan email melalui OTP')";
                                $log_stmt = $conn->prepare($log_query);
                                if ($log_stmt) {
                                    $petugas_id = $user_data['user_id'];
                                    $log_stmt->bind_param('iis', $petugas_id, $user_data['user_id'], $user_data['new_email']);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                }

                                // Hapus token
                                $delete_query = "DELETE FROM password_reset WHERE token = ?";
                                $delete_stmt  = $conn->prepare($delete_query);
                                if ($delete_stmt) {
                                    $delete_stmt->bind_param('s', $token);
                                    $delete_stmt->execute();
                                    $delete_stmt->close();
                                }

                                $_SESSION['otp_success'] =
                                    'Email berhasil diubah menjadi: ' . htmlspecialchars($user_data['new_email']);
                            } else {
                                error_log(
                                    "Failed to update email for user_id {$user_data['user_id']}: " .
                                    $update_stmt->error
                                );
                                $_SESSION['otp_error'] = 'Gagal mengubah email. Silakan coba lagi.';
                            }
                            $update_stmt->close();
                        }
                    }
                }
            }

            $cooldown_stmt->close();
        }
    }

    // Hindari resubmit form
    header('Location: ' . $_SERVER['PHP_SELF'] . '?token=' . urlencode($token));
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Verifikasi OTP | My Schobank</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
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
            --text-secondary: #64748b;
            --bg-white: #ffffff;
            --border-light: #e2e8f0;
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --radius-lg: 0.75rem;
            --radius-md: 0.5rem;
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

        .otp-container {
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
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .close-btn:hover {
            color: var(--primary-color);
            background: rgba(26, 35, 126, 0.08);
        }

        .header-section {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1.25rem;
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

        h2 {
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.4rem;
        }

        .description {
            color: var(--text-secondary);
            font-size: 0.85rem;
            line-height: 1.5;
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .description strong {
            color: var(--text-primary);
        }

        .otp-inputs {
            display: flex;
            gap: 8px;
            margin-bottom: 1.5rem;
        }

        .otp-inputs input {
            flex: 1;
            height: 52px;
            text-align: center;
            font-size: 20px;
            font-weight: 600;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-light);
            background: #fdfdfd;
            transition: var(--transition);
            color: var(--text-primary);
        }

        .otp-inputs input:focus {
            outline: none;
            border-color: var(--primary-color);
            background: var(--bg-white);
            box-shadow: 0 0 0 3px rgba(26, 35, 126, 0.12);
        }

        .error-message, .success-message {
            padding: 12px 16px;
            margin-bottom: 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: opacity 0.4s ease;
        }

        .error-message {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--danger-color);
        }

        .success-message {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: var(--success-color);
        }

        .error-message.fade-out,
        .success-message.fade-out {
            opacity: 0;
        }

        .submit-button,
        .back-button {
            width: 100%;
            padding: 14px 20px;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            transition: var(--transition);
            margin-top: 0.25rem;
        }

        .submit-button {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: #ffffff;
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

        .back-button {
            background: #f8fafc;
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            text-decoration: none;
        }

        .back-button:hover {
            background: #e2e8f0;
        }

        .loading-spinner {
            display: none;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid transparent;
            border-top: 2px solid #ffffff;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            .otp-container {
                padding: 2rem;
            }
            .logo {
                max-height: 70px;
            }
            .otp-inputs input {
                height: 46px;
                font-size: 18px;
            }
            .submit-button,
            .back-button {
                font-size: 14px;
                padding: 12px 18px;
            }
            .description {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
<div class="otp-container">
    <button type="button" class="close-btn" onclick="window.location.href='<?= BASE_URL ?>/pages/login.php'">
        <i class="fas fa-times"></i>
    </button>

    <div class="header-section">
        <div class="logo-container">
            <img src="<?= ASSETS_URL ?>/images/header.png" alt="My Schobank Logo" class="logo">
        </div>
        <h2>Verifikasi OTP</h2>
    </div>

    <?php if ($verification_success): ?>
        <div class="success-message" id="successMessage">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($success_message) ?>
        </div>
        <p class="description">
            Email Anda berhasil diubah. Anda akan diarahkan ke halaman login dalam beberapa detik.
        </p>
        <a href="<?= BASE_URL ?>/pages/login.php" class="back-button">
            <i class="fas fa-sign-in-alt"></i>
            Kembali ke Login
        </a>

    <?php elseif ($user_data): ?>

        <p class="description">
            Masukkan 6 digit kode OTP yang telah dikirim ke email baru Anda,
            <strong><?= htmlspecialchars($user_data['nama']) ?></strong>.
        </p>

        <?php if (!empty($error_message)): ?>
            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="otpForm" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] . '?token=' . urlencode($token)) ?>">
            <div class="otp-inputs">
                <?php for ($i = 1; $i <= 6; $i++): ?>
                    <input
                        type="tel"
                        inputmode="numeric"
                        name="otp<?= $i ?>"
                        maxlength="1"
                        pattern="[0-9]"
                        required
                        oninput="moveToNext(this, <?= $i ?>)"
                        onkeydown="moveToPrevious(event, this, <?= $i ?>)"
                    >
                <?php endfor; ?>
            </div>
            <button type="submit" class="submit-button" id="submitButton">
                <i class="fas fa-check" id="btnIcon"></i>
                <div class="loading-spinner" id="loadingSpinner"></div>
                <span id="buttonText">Verifikasi</span>
            </button>
        </form>

        <a href="<?= BASE_URL ?>/pages/login.php" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Batal & Kembali ke Login
        </a>

    <?php else: ?>

        <p class="description">
            Link verifikasi tidak valid atau sudah kedaluarsa. Silakan ulangi proses pemulihan email.
        </p>
        <?php if (!empty($error_message)): ?>
            <div class="error-message" id="errorMessage">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/index.php" class="back-button">
            <i class="fas fa-home"></i>
            Kembali ke Beranda
        </a>

    <?php endif; ?>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const otpForm       = document.getElementById('otpForm');
        const submitBtn     = document.getElementById('submitButton');
        const btnText       = document.getElementById('buttonText');
        const btnIcon       = document.getElementById('btnIcon');
        const spinner       = document.getElementById('loadingSpinner');
        const errorEl       = document.getElementById('errorMessage');
        const successEl     = document.getElementById('successMessage');
        const firstOtpInput = document.querySelector('.otp-inputs input[name="otp1"]');

        if (firstOtpInput) {
            firstOtpInput.focus();
        }

        if (errorEl) {
            setTimeout(() => {
                errorEl.classList.add('fade-out');
                setTimeout(() => errorEl.remove(), 400);
            }, 3000);
        }

        if (successEl) {
            setTimeout(() => {
                successEl.classList.add('fade-out');
            }, 3000);
        }

        if (otpForm && submitBtn) {
            otpForm.addEventListener('submit', function () {
                if (btnIcon) btnIcon.style.display = 'none';
                if (spinner) spinner.style.display = 'block';
                if (btnText) btnText.textContent = 'Memverifikasi...';
                submitBtn.disabled = true;
            });
        }
    });

    function moveToNext(input, index) {
        if (input.value.length === 1 && /[0-9]/.test(input.value)) {
            const next = document.querySelector('input[name="otp' + (index + 1) + '"]');
            if (next) {
                next.focus();
            }
        } else if (input.value && !/[0-9]/.test(input.value)) {
            input.value = '';
        }
    }

    function moveToPrevious(event, input, index) {
        if (event.key === 'Backspace' && input.value.length === 0) {
            const prev = document.querySelector('input[name="otp' + (index - 1) + '"]');
            if (prev) {
                prev.focus();
            }
        }
    }
</script>
</body>
</html>
