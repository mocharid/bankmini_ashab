<?php
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Initialize variables
$token = filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '';
$token = trim(preg_replace('/[^a-f0-9]/i', '', $token)); // Sanitize to alphanumeric (hex) only
$error_message = '';
$success_message = '';
$user_data = null;
$verification_success = false;

// Check for session-stored messages from previous submission
session_start();
if (isset($_SESSION['otp_success'])) {
    $success_message = $_SESSION['otp_success'];
    $verification_success = true;
    unset($_SESSION['otp_success']);
    // Set redirect header for success case
    header("Refresh: 3; url=../login.php");
} elseif (isset($_SESSION['otp_error'])) {
    $error_message = $_SESSION['otp_error'];
    unset($_SESSION['otp_error']);
}

// Verify token
if (empty($token)) {
    $error_message = "Token tidak valid atau telah kedaluarsa.";
} elseif (strlen($token) !== 64 || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $error_message = "Format token tidak valid.";
} else {
    // Check token in database
    $query = "SELECT pr.*, u.nama, u.id AS user_id, u.email, u.otp, u.otp_expiry 
              FROM password_reset pr
              JOIN users u ON pr.user_id = u.id
              WHERE pr.token = ? AND pr.expiry > NOW()";
    $stmt = $conn->prepare($query);
    
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
    } else {
        $stmt->bind_param("s", $token);
        if (!$stmt->execute()) {
            error_log("Execute failed: " . $stmt->error);
            $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
        } else {
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                error_log("Invalid or expired token: $token");
                $error_message = "Token tidak valid atau telah kedaluarsa.";
            } else {
                $user_data = $result->fetch_assoc();
                if (empty($user_data['new_email']) || empty($user_data['otp'])) {
                    $error_message = "Data pemulihan tidak lengkap. Silakan ulangi proses.";
                }
            }
        }
        $stmt->close();
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($user_data)) {
    // Combine all OTP inputs into one string
    $entered_otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $entered_otp .= $_POST['otp' . $i] ?? '';
    }
    
    // Validate OTP
    if (empty($entered_otp) || !preg_match('/^[0-9]{6}$/', $entered_otp)) {
        $_SESSION['otp_error'] = "OTP harus berupa 6 digit angka.";
    } else {
        // Check rate limit for OTP attempts
        $cooldown_query = "SELECT COUNT(*) as attempts 
                          FROM reset_request_cooldown 
                          WHERE user_id = ? AND method = 'otp' 
                          AND last_reset_request > NOW() - INTERVAL 1 HOUR";
        $cooldown_stmt = $conn->prepare($cooldown_query);
        if ($cooldown_stmt === false) {
            error_log("Prepare failed for cooldown check: " . $conn->error);
            $_SESSION['otp_error'] = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
        } else {
            $cooldown_stmt->bind_param("i", $user_data['user_id']);
            $cooldown_stmt->execute();
            $cooldown_result = $cooldown_stmt->get_result()->fetch_assoc();
            
            if ($cooldown_result['attempts'] >= 5) {
                $_SESSION['otp_error'] = "Terlalu banyak percobaan OTP. Silakan coba lagi dalam satu jam.";
            } else {
                // Log OTP attempt
                $upsert_query = "INSERT INTO reset_request_cooldown (user_id, method, last_reset_request) 
                                VALUES (?, 'otp', NOW()) 
                                ON DUPLICATE KEY UPDATE last_reset_request = NOW()";
                $upsert_stmt = $conn->prepare($upsert_query);
                if ($upsert_stmt === false) {
                    error_log("Prepare failed for cooldown upsert: " . $conn->error);
                    $_SESSION['otp_error'] = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                } else {
                    $upsert_stmt->bind_param("i", $user_data['user_id']);
                    $upsert_stmt->execute();
                    $upsert_stmt->close();
                    
                    // Verify OTP
                    if ($user_data['otp_expiry'] < date('Y-m-d H:i:s')) {
                        $_SESSION['otp_error'] = "OTP telah kedaluarsa. Silakan ulangi proses pemulihan.";
                    } elseif ($user_data['otp'] !== $entered_otp) {
                        $_SESSION['otp_error'] = "OTP tidak valid.";
                    } else {
                        // Update email
                        $update_query = "UPDATE users SET email = ?, otp = NULL, otp_expiry = NULL WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        if ($update_stmt === false) {
                            error_log("Prepare failed for email update: " . $conn->error);
                            $_SESSION['otp_error'] = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                        } else {
                            $update_stmt->bind_param("si", $user_data['new_email'], $user_data['user_id']);
                            if ($update_stmt->execute()) {
                                // Log activity
                                $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan) 
                                             VALUES (?, ?, 'email', ?, NOW(), 'Pemulihan email melalui OTP')";
                                $log_stmt = $conn->prepare($log_query);
                                if ($log_stmt) {
                                    $petugas_id = $user_data['user_id']; // User acts as their own petugas
                                    $log_stmt->bind_param("iis", $petugas_id, $user_data['user_id'], $user_data['new_email']);
                                    $log_stmt->execute();
                                    $log_stmt->close();
                                } else {
                                    error_log("Prepare failed for log_aktivitas: " . $conn->error);
                                }
                                
                                // Clear password_reset entry
                                $delete_query = "DELETE FROM password_reset WHERE token = ?";
                                $delete_stmt = $conn->prepare($delete_query);
                                if ($delete_stmt) {
                                    $delete_stmt->bind_param("s", $token);
                                    $delete_stmt->execute();
                                    $delete_stmt->close();
                                } else {
                                    error_log("Prepare failed for token deletion: " . $conn->error);
                                }
                                
                                $_SESSION['otp_success'] = "Email berhasil diubah menjadi: " . htmlspecialchars($user_data['new_email']);
                            } else {
                                error_log("Failed to update email for user_id {$user_data['user_id']}: " . $update_stmt->error);
                                $_SESSION['otp_error'] = "Gagal mengubah email. Silakan coba lagi.";
                            }
                            $update_stmt->close();
                        }
                    }
                }
            }
            $cooldown_stmt->close();
        }
    }
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF'] . "?token=" . urlencode($token));
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verifikasi OTP - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1A237E;
            --secondary-color: #00B4D8;
            --danger-color: #c53030;
            --danger-dark: #a12828;
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
            padding: 20px;
            overflow-x: hidden;
        }

        .otp-container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            width: 100%;
            max-width: 400px;
            text-align: center;
            border: 1px solid #e8ecef;
            animation: fadeIn 0.8s ease-out;
            position: relative;
            overflow: hidden;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .otp-container h2 {
            color: var(--text-primary);
            font-size: 1.8rem;
            font-weight: 600;
            margin-bottom: 1rem;
            letter-spacing: -0.5px;
        }

        .otp-container p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }

        .otp-inputs {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .otp-inputs input {
            width: 48px;
            height: 48px;
            text-align: center;
            font-size: 18px;
            font-weight: 500;
            border: 1px solid #d1d9e6;
            border-radius: 8px;
            background: #fbfdff;
            transition: var(--transition);
        }

        .otp-inputs input:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        button:hover {
            background: linear-gradient(90deg, #151c66 0%, #009bb8 100%);
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
            transform: translateY(-1px);
        }

        button:active {
            transform: translateY(0);
        }

        button.loading .button-text {
            opacity: 0;
        }

        button.loading::after {
            content: '\f1ce';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.2rem;
            color: white;
            animation: spin 1s linear infinite;
        }

        .back-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, var(--danger-color) 0%, var(--danger-dark) 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-align: center;
            text-decoration: none;
            display: inline-block;
            margin-top: 1rem;
            position: relative;
            overflow: hidden;
        }

        .back-button:hover {
            background: linear-gradient(90deg, #a12828 0%, #8b2323 100%);
            box-shadow: 0 4px 12px rgba(197, 48, 48, 0.3);
            transform: translateY(-1px);
        }

        .back-button:active {
            transform: translateY(0);
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .error-message, .success-message {
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .error-message {
            background: linear-gradient(145deg, #ffffff, #ffe6e6);
            color: var(--danger-color);
            box-shadow: 0 4px 12px rgba(197, 48, 48, 0.1);
        }

        .success-message {
            background: linear-gradient(145deg, #ffffff, #e6f7ff);
            color: #27ae60;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.1);
        }

        .error-message i, .success-message i {
            margin-right: 8px;
            font-size: 1.2rem;
        }

        .error-message.fade-out, .success-message.fade-out {
            opacity: 0;
        }

        .success-icon {
            font-size: 3.5rem;
            color: #27ae60;
            margin: 1rem 0;
            animation: bounceIn 0.6s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        @media (max-width: 480px) {
            .otp-container {
                padding: 1.5rem;
            }

            .otp-container h2 {
                font-size: 1.5rem;
            }

            .otp-container p {
                font-size: 0.85rem;
            }

            .otp-inputs input {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            button, .back-button {
                padding: 10px;
                font-size: 14px;
            }

            .success-icon {
                font-size: 3rem;
            }
        }
    </style>
</head>
<body>
    <div class="otp-container">
        <h2>Verifikasi OTP</h2>

        <?php if ($verification_success): ?>
            <i class="fas fa-check-circle success-icon"></i>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <p>Email Anda telah berhasil diubah. Anda akan diarahkan ke halaman login dalam 3 detik...</p>
            <a href="../login.php" class="back-button">Kembali ke Login</a>
        <?php elseif ($user_data): ?>
            <p>Masukkan kode OTP yang telah dikirim ke email baru Anda, <strong><?php echo htmlspecialchars($user_data['nama']); ?></strong>.</p>

            <?php if (!empty($error_message)): ?>
                <div class="error-message" id="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="otpForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?token=' . urlencode($token)); ?>">
                <div class="otp-inputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="tel" inputmode="numeric" name="otp<?php echo $i; ?>" maxlength="1" oninput="moveToNext(this, <?php echo $i; ?>)" onkeydown="moveToPrevious(this, <?php echo $i; ?>)" pattern="[0-9]" required>
                    <?php endfor; ?>
                </div>
                <button type="submit" id="verifyButton">
                    <span class="button-text">Verifikasi</span>
                </button>
            </form>
            <a href="../login.php" class="back-button">Batal</a>
        <?php else: ?>
            <p>Link verifikasi tidak valid atau telah kedaluarsa.</p>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <a href="../../index.php" class="back-button">Kembali ke Beranda</a>
        <?php endif; ?>
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

            document.addEventListener('touchstart', (e) => {
                if (e.touches.length > 1) e.preventDefault();
            }, { passive: false });

            document.addEventListener('gesturestart', (e) => {
                e.preventDefault();
            });

            document.addEventListener('doubletap', (e) => {
                e.preventDefault();
            });

            // Form handling
            const otpForm = document.getElementById('otpForm');
            const verifyButton = document.getElementById('verifyButton');
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');

            if (otpForm && verifyButton) {
                otpForm.addEventListener('submit', function() {
                    verifyButton.classList.add('loading');
                    verifyButton.disabled = true;
                });
            }

            // Auto-dismiss messages
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }

            if (successAlert) {
                setTimeout(() => {
                    successAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 3500);
            }
        });

        function moveToNext(input, currentIndex) {
            if (input.value.length === 1 && /[0-9]/.test(input.value)) {
                const nextInput = document.querySelector(`input[name="otp${currentIndex + 1}"]`);
                if (nextInput) {
                    nextInput.focus();
                }
            } else {
                input.value = ''; // Clear non-numeric input
            }
        }

        function moveToPrevious(input, currentIndex) {
            if (event.key === 'Backspace' && input.value.length === 0) {
                const previousInput = document.querySelector(`input[name="otp${currentIndex - 1}"]`);
                if (previousInput) {
                    previousInput.focus();
                }
            }
        }
    </script>
</body>
</html>