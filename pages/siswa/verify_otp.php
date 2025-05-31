<?php
session_start();
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta'); // Set timezone to WIB

// Initialize message variables
$error = '';
$success = '';
$verification_success = false;
$otp_target = '';
$otp_type = isset($_SESSION['otp_type']) ? $_SESSION['otp_type'] : '';
$current_time = date('H:i:s - d M Y'); // Format time in WIB

// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../pages/siswa/profil.php?error=" . urlencode("Sesi tidak valid. Silakan login kembali."));
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
    header("Refresh: 3; url=../../pages/siswa/profil.php");
}

// Determine OTP type and target
if ($otp_type === 'email' && isset($_SESSION['new_email']) && !empty($_SESSION['new_email'])) {
    $otp_target = $_SESSION['new_email'];
} elseif ($otp_type === 'no_wa' && (isset($_SESSION['new_no_wa']) || $_SESSION['new_no_wa'] === '')) {
    if ($_SESSION['new_no_wa'] === '') {
        $query = "SELECT no_wa FROM users WHERE id = ?";
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
    header("Location: ../../pages/siswa/profil.php");
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
    $query = "SELECT otp, otp_expiry FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && $user['otp'] === $otp && strtotime($user['otp_expiry']) > time()) {
        // OTP valid, update based on otp_type
        if ($otp_type === 'email') {
            $new_email = $_SESSION['new_email'];
            $update_query = "UPDATE users SET email = ?, otp = NULL, otp_expiry = NULL WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_email, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                $_SESSION['otp_success'] = "Email berhasil diperbarui menjadi: " . htmlspecialchars($new_email);
                unset($_SESSION['new_email'], $_SESSION['otp_type']);
            } else {
                error_log("Failed to update email for user {$_SESSION['user_id']}");
                $_SESSION['otp_error'] = "Gagal memperbarui email. Silakan coba lagi.";
            }
        } elseif ($otp_type === 'no_wa') {
            $new_no_wa = $_SESSION['new_no_wa'] === '' ? NULL : $_SESSION['new_no_wa'];
            $update_query = "UPDATE users SET no_wa = ?, otp = NULL, otp_expiry = NULL WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_no_wa, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
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
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta charset="UTF-8">
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

        .login-container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
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

        .form-description {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 400;
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
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
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

        button.loading .button-text {
            visibility: hidden;
        }

        button.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 3px solid #ffffff;
            border-top: 3px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        .success-message.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
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

        @media (max-width: 768px) {
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

            .otp-inputs input {
                width: 35px;
                height: 45px;
                font-size: 16px;
            }

            button {
                font-size: 14px;
                padding: 10px;
            }

            .form-description {
                font-size: 0.85rem;
            }

            .error-message, .success-message {
                font-size: 0.85rem;
                padding: 0.6rem;
            }

            .back-to-login a {
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
    <div class="login-container">
        <div class="logo-container">
            <img src="/bankmini/assets/images/lbank.png" alt="SCHOBANK Logo" class="logo">
            <div class="bank-name">SCHOBANK</div>
        </div>

        <div class="form-description">
            Masukkan kode OTP yang telah dikirim ke 
            <?php echo $otp_type === 'email' ? 
                'email Anda: ' . htmlspecialchars($otp_target) : 
                'nomor WhatsApp: ' . htmlspecialchars($otp_target); ?>.
        </div>

        <?php if (!empty($error)): ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
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
                <a href="../../pages/siswa/profil.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman profil</a>
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
                <button type="submit" id="verify_button">
                    <span class="button-text">Verifikasi OTP</span>
                </button>
            </form>
            
            <div class="back-to-login">
                <a href="../../pages/siswa/profil.php"><i class="fas fa-arrow-left"></i> Kembali ke halaman profil</a>
            </div>
        <?php endif; ?>
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

        document.addEventListener('touchstart', (e) => {
            if (e.touches.length > 1) e.preventDefault();
        }, { passive: true });

        document.addEventListener('gesturestart', (e) => {
            e.preventDefault();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            const otpForm = document.getElementById('otp_form');
            const verifyButton = document.getElementById('verify_button');
            const otpInputs = document.querySelectorAll('.otp-input');

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

            // OTP form handling
            if (otpForm && verifyButton) {
                otpForm.addEventListener('submit', (e) => {
                    // Validate inputs
                    let isValid = true;
                    otpInputs.forEach(input => {
                        if (!input.value.match(/^[0-9]$/)) {
                            isValid = false;
                        }
                    });

                    if (!isValid) {
                        e.preventDefault();
                        const errorDiv = document.createElement('div');
                        errorDiv.className = 'error-message';
                        errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Masukkan 6 digit angka!';
                        document.querySelector('.login-container').insertBefore(errorDiv, otpForm);
                        setTimeout(() => {
                            errorDiv.classList.add('fade-out');
                            setTimeout(() => errorDiv.remove(), 500);
                        }, 3000);
                        return;
                    }

                    // Show loading spinner
                    verifyButton.classList.add('loading');
                    verifyButton.disabled = true;
                });

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
            }
        });
    </script>
</body>
</html>