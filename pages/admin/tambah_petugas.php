<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$error = ''; 
$success = ''; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirm'])) {
        // Jika konfirmasi diterima, tambahkan petugas ke database
        $nama = trim($_POST['nama']);
        $username = $_POST['username'];
        $password = $_POST['password']; // Store the plain password temporarily
        
        // Use the same SHA2 function as in login page (through MySQL)
        $query = "INSERT INTO users (username, password, role, nama) 
                  VALUES (?, SHA2(?, 256), 'petugas', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $username, $password, $nama);

        if ($stmt->execute()) {
            $success = 'Petugas berhasil ditambahkan!';
            // Set header untuk redirect setelah beberapa detik
            header("refresh:3;url=dashboard.php");
        } else {
            $error = 'Terjadi kesalahan saat menambahkan petugas. Silakan coba lagi.';
        }
    } else {
        // Jika belum konfirmasi, tampilkan data yang akan ditambahkan
        $nama = trim($_POST['nama']);
        if (empty($nama)) {
            $error = 'Nama tidak boleh kosong.';
        } else {
            $username = generateUsername($nama); // Generate username otomatis
            $password = "12345678"; // Password default seperti diminta
        }
    }
}

// Fungsi untuk generate username otomatis
function generateUsername($nama) {
    $nama = strtolower(str_replace(' ', '', $nama)); // Hilangkan spasi dan ubah ke lowercase
    $randomNumber = rand(100, 999); // Tambahkan angka acak
    return $nama . $randomNumber;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tambah Petugas - SCHOBANK SYSTEM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --primary-light: #edf2ff;
            --primary-dark: #2940b2;
            --secondary-color: #4cc9f0;
            --success-color: #06d6a0;
            --error-color: #ef476f;
            --warning-color: #ffd166;
            --dark-color: #2b2d42;
            --light-color: #f8f9fa;
            --grey-color: #e9ecef;
            --text-color: #495057;
            --border-radius: 8px;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        body {
            background-color: #f5f7ff;
            color: var(--text-color);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: var(--transition);
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            padding: 1.5rem 2rem;
            background: linear-gradient(135deg, #0a2e5c, #154785);
            color: white;
            position: relative;
        }

        .header h2 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .header p {
            opacity: 0.85;
            font-size: 0.95rem;
        }

        .back-btn {
            position: absolute;
            top: 1.5rem;
            right: 2rem;
            color: white;
            background: rgba(255, 255, 255, 0.2);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            text-decoration: none;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .content {
            padding: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
            transition: var(--transition);
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper .icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-color);
        }

        input[type="text"] {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--grey-color);
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
        }

        input[type="text"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px var(--primary-light);
            outline: none;
        }

        input[type="text"]::placeholder {
            color: #adb5bd;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn i {
            font-size: 0.9rem;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background-color: var(--grey-color);
            color: var(--text-color);
        }

        .btn-secondary:hover {
            background-color: #dde2e6;
            transform: translateY(-2px);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            justify-content: flex-end;
        }

        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .alert-error {
            background-color: #fdecf0;
            color: var(--error-color);
            border-left: 4px solid var(--error-color);
        }

        .alert-success {
            background-color: #e0f9f0;
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
            position: relative;
        }

        .redirect-message {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .redirect-loader {
            height: 4px;
            background-color: var(--success-color);
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            animation: progress 3s linear forwards;
        }

        @keyframes progress {
            from { width: 0; }
            to { width: 100%; }
        }

        .confirmation {
            background-color: var(--light-color);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: inset 0 0 0 1px var(--grey-color);
            animation: fadeIn 0.4s ease-out;
        }

        .confirmation-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--dark-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .confirmation-item {
            display: flex;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--grey-color);
            padding-bottom: 1rem;
        }

        .confirmation-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
            padding-bottom: 0;
        }

        .confirmation-label {
            width: 120px;
            font-weight: 500;
            color: var(--text-color);
            opacity: 0.8;
        }

        .confirmation-value {
            flex: 1;
            font-weight: 500;
            color: var(--dark-color);
            word-break: break-word;
        }

        .password-container {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
        }

        .copy-button {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .copy-button:hover {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .copy-notice {
            position: absolute;
            right: 0;
            top: -30px;
            background-color: var(--dark-color);
            color: white;
            padding: 0.5rem 0.75rem;
            border-radius: var(--border-radius);
            font-size: 0.8rem;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .copy-notice:after {
            content: '';
            position: absolute;
            bottom: -8px;
            right: 14px;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid var(--dark-color);
        }

        .show-notice {
            opacity: 1;
            visibility: visible;
            top: -40px;
        }

        .loading {
            position: relative;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .container {
                margin: 1rem;
                width: auto;
            }

            .header, .content {
                padding: 1.25rem;
            }

            .confirmation-item {
                flex-direction: column;
                gap: 0.5rem;
            }

            .confirmation-label {
                width: 100%;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .header h2 {
                font-size: 1.5rem;
                padding-right: 40px;
            }
        }

        /* Animasi loading */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn.loading .fa-spinner {
            animation: spin 1s linear infinite;
        }

        /* Animasi pulsing untuk elemen interaktif */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .btn-primary:active {
            animation: pulse 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h2>Tambah Petugas</h2>
            <p>Data petugas baru untuk sistem</p>
        </div>
        <div class="content">
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-circle-check"></i>
                    <?php echo $success; ?>
                    <span class="redirect-message">
                        Mengalihkan ke Dashboard dalam <span id="countdown">3</span> detik
                        <span class="redirect-loader"></span>
                    </span>
                </div>
            <?php elseif (!isset($_POST['confirm']) && $_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)): ?>
                <!-- Tampilkan konfirmasi data -->
                <div class="confirmation">
                    <div class="confirmation-title">
                        <i class="fas fa-user-check"></i> Detail Petugas Baru
                    </div>
                    <div class="confirmation-item">
                        <div class="confirmation-label">Nama</div>
                        <div class="confirmation-value"><?php echo htmlspecialchars($nama); ?></div>
                    </div>
                    <div class="confirmation-item">
                        <div class="confirmation-label">Username</div>
                        <div class="confirmation-value"><?php echo htmlspecialchars($username); ?></div>
                    </div>
                    <div class="confirmation-item">
                        <div class="confirmation-label">Password</div>
                        <div class="confirmation-value password-container">
                            <span id="password-text"><?php echo htmlspecialchars($password); ?></span>
                            <button type="button" id="copy-password" class="copy-button" title="Salin Password">
                                <i class="fas fa-copy"></i>
                            </button>
                            <span class="copy-notice" id="copy-notice">Password disalin!</span>
                        </div>
                    </div>
                </div>
                <form action="" method="POST" id="confirm-form">
                    <input type="hidden" name="nama" value="<?php echo htmlspecialchars($nama); ?>">
                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                    <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
                    <div class="action-buttons">
                        <button type="submit" name="confirm" class="btn btn-primary" id="confirm-btn">
                            <i class="fas fa-check"></i> Konfirmasi
                        </button>
                        <button type="button" onclick="window.history.back();" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Kembali
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <!-- Form input data -->
                <form action="" method="POST" id="input-form">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap Petugas</label>
                        <div class="input-wrapper">
                            <input type="text" id="nama" name="nama" required placeholder="Masukkan nama lengkap">
                            <i class="fas fa-user icon"></i>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="submit-btn">
                        <i class="fas fa-paper-plane"></i> Lanjutkan
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Event listener untuk tombol copy password
        document.addEventListener('DOMContentLoaded', function() {
            const copyButton = document.getElementById('copy-password');
            const copyNotice = document.getElementById('copy-notice');
            const passwordText = document.getElementById('password-text');
            
            if (copyButton) {
                copyButton.addEventListener('click', function() {
                    // Salin password ke clipboard
                    const textToCopy = passwordText.textContent;
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        // Tampilkan notifikasi
                        copyNotice.classList.add('show-notice');
                        setTimeout(function() {
                            copyNotice.classList.remove('show-notice');
                        }, 2000);
                    });
                });
            }

            // Tambahkan efek loading saat form dikirim
            const inputForm = document.getElementById('input-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (inputForm && submitBtn) {
                inputForm.addEventListener('submit', function() {
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function() {
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
                });
            }

            // Animasi untuk input field
            const inputs = document.querySelectorAll('input');
            
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentNode.parentNode.querySelector('label').style.color = 'var(--primary-color)';
                    this.parentNode.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentNode.parentNode.querySelector('label').style.color = 'var(--text-color)';
                    this.parentNode.style.transform = 'translateY(0)';
                });
            });

            // Countdown timer untuk redirect
            const countdownElement = document.getElementById('countdown');
            if (countdownElement) {
                let seconds = 3;
                const countdown = setInterval(function() {
                    seconds--;
                    countdownElement.textContent = seconds;
                    if (seconds <= 0) {
                        clearInterval(countdown);
                    }
                }, 1000);
            }

            // Efek hover untuk card
            const container = document.querySelector('.container');
            if (container) {
                container.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px)';
                    this.style.boxShadow = '0 8px 24px rgba(0, 0, 0, 0.12)';
                });
                
                container.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'var(--shadow)';
                });
            }

            // Validasi form dengan pesan error yang lebih baik
            const namaInput = document.getElementById('nama');
            if (namaInput && inputForm) {
                inputForm.addEventListener('submit', function(e) {
                    if (namaInput.value.trim().length < 3) {
                        e.preventDefault();
                        alert('Nama harus minimal 3 karakter!');
                        namaInput.focus();
                    }
                });
            }
        });
    </script>
</body>
</html>