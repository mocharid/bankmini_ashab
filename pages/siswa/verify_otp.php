<?php
session_start();
require_once '../../includes/db_connection.php';

$error = '';
$success = '';
$verification_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gabungkan semua input OTP menjadi satu string
    $otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $otp .= $_POST['otp' . $i] ?? '';
    }

    if (empty($otp)) {
        $error = "Kode OTP tidak boleh kosong!";
    } else {
        // Ambil OTP dari database
        $query = "SELECT otp, otp_expiry FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && $user['otp'] === $otp && strtotime($user['otp_expiry']) > time()) {
            // OTP valid, update email
            $new_email = $_SESSION['new_email']; // Email baru yang disimpan di session
            $update_query = "UPDATE users SET email = ?, otp = NULL, otp_expiry = NULL WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_email, $_SESSION['user_id']);

            if ($update_stmt->execute()) {
                $success = "Email berhasil diperbarui menjadi: " . $new_email;
                $verification_success = true;
                unset($_SESSION['new_email']); // Hapus session email baru

                // Redirect ke halaman profil setelah 3 detik
                header("Refresh: 3; url=../../pages/siswa/profil.php");
            } else {
                $error = "Gagal memperbarui email. Silakan coba lagi.";
            }
        } else {
            $error = "Kode OTP tidak valid atau telah kedaluwarsa!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Verifikasi OTP - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f0f2f5;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-image: url('https://www.transparenttextures.com/patterns/geometry.png');
            background-size: cover;
            background-position: center;
            padding: 20px;
        }

        .otp-container {
            background: rgba(255, 255, 255, 0.9);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            backdrop-filter: blur(5px);
        }

        .otp-container h2 {
            color: #0a2e5c;
            margin-bottom: 1.5rem;
        }

        .otp-container p {
            color: #666;
            margin-bottom: 1.5rem;
        }

        .otp-inputs {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        .otp-inputs input {
            width: 40px;
            height: 40px;
            text-align: center;
            font-size: 18px;
            border: 2px solid #e1e5ee;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .otp-inputs input:focus {
            border-color: #0a2e5c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
        }

        button {
            width: 100%;
            padding: 12px;
            background: #0a2e5c;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            -webkit-appearance: none;
            appearance: none;
            margin-bottom: 10px;
            text-decoration: none;
        }

        button i {
            margin-right: 8px;
        }

        button:hover, button:active {
            background: #154785;
        }

        .error-message {
            background: #ffe5e5;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .error-message.fade-out {
            opacity: 0;
        }

        .success-message {
            background: #e5ffe5;
            color: #27ae60;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .success-message.fade-out {
            opacity: 0;
        }
        
        .back-button {
            background: #0a2e5c;
            margin-top: 20px;
            text-decoration: none;
        }
        
        a {
            text-decoration: none;
        }
        
        .success-icon {
            font-size: 60px;
            color: #27ae60;
            margin: 20px 0;
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
                <?php echo $success; ?>
            </div>
            <p>Email Anda telah berhasil diperbarui. Anda akan diarahkan ke halaman profil dalam 3 detik...</p>
        <?php else: ?>
            <p>Masukkan kode OTP yang telah dikirim ke email Anda.</p>

            <?php if (isset($error) && !empty($error)): ?>
                <div class="error-message" id="error-alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="otpForm">
                <div class="otp-inputs">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" name="otp<?= $i ?>" maxlength="1" oninput="moveToNext(this, <?= $i ?>)" onkeydown="moveToPrevious(this, <?= $i ?>)" required>
                    <?php endfor; ?>
                </div>
                <button type="submit">
                    <i class="fas fa-check"></i> Verifikasi
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Auto-dismiss error messages after 3 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);

                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }
        });

        // Fungsi untuk berpindah ke input berikutnya
        function moveToNext(input, currentIndex) {
            if (input.value.length === 1) {
                const nextInput = document.querySelector(`input[name="otp${currentIndex + 1}"]`);
                if (nextInput) {
                    nextInput.focus();
                }
            }
        }

        // Fungsi untuk berpindah ke input sebelumnya saat tombol backspace ditekan
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