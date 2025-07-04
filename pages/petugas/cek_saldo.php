<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to petugas and admin roles
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['no_rekening'])) {
    $no_rekening = $conn->real_escape_string($_POST['no_rekening']);
    $query = "SELECT users.nama, rekening.no_rekening, rekening.saldo, rekening.created_at, 
                     kelas.nama_kelas, jurusan.nama_jurusan
              FROM rekening 
              JOIN users ON rekening.user_id = users.id 
              LEFT JOIN kelas ON users.kelas_id = kelas.id 
              LEFT JOIN jurusan ON users.jurusan_id = jurusan.id 
              WHERE rekening.no_rekening = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['search_error'] = 'Terjadi kesalahan server. Silakan coba lagi.';
        header('Location: cek_saldo.php');
        exit();
    }
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $_SESSION['search_result'] = $result->fetch_assoc();
        $_SESSION['search_success'] = true;
    } else {
        error_log("No account found for no_rekening: $no_rekening");
        $_SESSION['search_error'] = 'Rekening tidak ditemukan. Silakan periksa kembali nomor rekening.';
    }
    $stmt->close();

    // Redirect to clear POST data
    header('Location: cek_saldo.php');
    exit();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Saldo - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --bg-light: #f7fafc;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --error-color: #e74c3c;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
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

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .form-section {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .error-message {
            color: var(--error-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .results-card {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            padding: 12px 15px;
            gap: 8px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row:hover {
            background: #f1f5f9;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: left;
        }

        .balance-display {
            padding: 20px;
            background: linear-gradient(to right, #f8faff, #f0f5ff);
            border-radius: 12px;
            text-align: center;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.03);
            margin-top: 15px;
            position: relative;
            overflow: hidden;
        }

        .balance-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 0%, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0) 70%);
            pointer-events: none;
        }

        .balance-label {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 1.8vw, 1rem);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .balance-amount {
            font-size: clamp(1.75rem, 3.5vw, 2rem);
            color: var(--primary-color);
            font-weight: 700;
            margin: 10px 0;
            position: relative;
            display: inline-block;
            padding: 6px 20px;
            border-radius: 50px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .balance-info {
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.6vw, 0.85rem);
            margin-top: 10px;
        }

        .modal-overlay {
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
            animation: fadeInOverlay 0.4s ease-in-out forwards;
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

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(280px, 70vw, 360px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 12px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.8);
            opacity: 0;
            animation: popInModal 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: pointer;
            overflow: hidden;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.8); opacity: 0; }
            80% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(2rem, 4.5vw, 2.5rem);
            margin: 0 auto 10px;
            color: white;
            animation: bounceIn 0.5s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 8px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            line-height: 1.5;
            padding: 0 10px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .balance-label {
                font-size: clamp(0.9rem, 1.8vw, 0.95rem);
            }

            .balance-amount {
                font-size: clamp(1.5rem, 3vw, 1.75rem);
                padding: 5px 15px;
            }

            .balance-info {
                font-size: clamp(0.75rem, 1.6vw, 0.8rem);
            }

            .success-modal, .error-modal {
                width: clamp(260px, 80vw, 340px);
                padding: clamp(12px, 3vw, 15px);
            }

            .success-icon, .error-icon {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input {
                min-height: 40px;
            }

            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                padding: 8px 10px;
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .success-modal, .error-modal {
                width: clamp(240px, 85vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
            }

            .success-icon, .error-icon {
                font-size: clamp(1.6rem, 3.5vw, 1.8rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Cek Saldo</h2>
            <p>Cek saldo rekening nasabah dengan mudah dan cepat</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form id="searchForm" action="" method="POST" class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="no_rekening">No Rekening</label>
                        <div class="input-container">
                            <input type="text" id="no_rekening" name="no_rekening" placeholder="REK diikuti 6 angka" required autofocus>
                        </div>
                        <span class="error-message" id="no_rekening-error"></span>
                    </div>
                </div>
                <div class="form-buttons">
                    <button type="submit" class="btn" id="searchBtn">
                        <span class="btn-content"><i class="fas fa-search"></i> Cek Saldo</span>
                    </button>
                </div>
            </form>
        </div>

        <div id="results">
            <?php if (isset($_SESSION['search_success']) && isset($_SESSION['search_result'])): ?>
                <?php
                $data = $_SESSION['search_result'];
                ?>
                <div class="results-card">
                    <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($data['nama']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">No. Rekening:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($data['no_rekening']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Kelas:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($data['nama_kelas'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jurusan:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($data['nama_jurusan'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Tanggal Pembukaan:</div>
                        <div class="detail-value"><?php echo date('d/m/Y', strtotime($data['created_at'])); ?></div>
                    </div>
                    <div class="balance-display">
                        <div class="balance-label">Saldo Rekening Saat Ini</div>
                        <div class="balance-amount">Rp <?php echo number_format($data['saldo'], 0, ',', '.'); ?></div>
                        <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per <?php echo date('d/m/Y H:i'); ?> WIB</div>
                    </div>
                </div>
                <?php
                unset($_SESSION['search_result']);
                unset($_SESSION['search_success']);
                ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const inputNoRek = document.getElementById('no_rekening');
            const alertContainer = document.getElementById('alertContainer');
            const results = document.getElementById('results');
            const prefix = "REK";

            // Initialize rekening input
            if (!inputNoRek.value) {
                inputNoRek.value = prefix;
            }

            // Restrict rekening input to exactly 6 digits after prefix
            inputNoRek.addEventListener('input', function() {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    value = prefix + value.replace(prefix, '');
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + userInput;
                document.getElementById('no_rekening-error').classList.remove('show');
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + pastedData;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                let userInput = this.value.slice(prefix.length);
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
                if (userInput.length >= 6 && e.key.match(/[0-9]/) && cursorPos >= prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('focus', function() {
                if (this.value === prefix) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoRek.addEventListener('click', function() {
                if (this.selectionStart < prefix.length) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            // Search form handling
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (searchForm.classList.contains('submitting')) return;
                searchForm.classList.add('submitting');

                const rekening = inputNoRek.value.trim();
                if (rekening === prefix || rekening.length !== prefix.length + 6) {
                    showAlert('Masukkan nomor rekening yang valid (REK diikuti 6 angka)', 'error');
                    inputNoRek.classList.add('form-error');
                    setTimeout(() => inputNoRek.classList.remove('form-error'), 400);
                    inputNoRek.value = prefix;
                    inputNoRek.focus();
                    document.getElementById('no_rekening-error').classList.add('show');
                    document.getElementById('no_rekening-error').textContent = 'Masukkan nomor rekening yang valid (REK diikuti 6 angka)';
                    searchForm.classList.remove('submitting');
                    return;
                }

                searchBtn.classList.add('loading');
                searchBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
                setTimeout(() => {
                    searchForm.submit();
                }, 1000);
            });

            // Show success popup only on initial search
            <?php if (isset($_SESSION['search_success']) && isset($_SESSION['search_result'])): ?>
                setTimeout(() => showAlert('Rekening berhasil ditemukan', 'success'), 500);
            <?php endif; ?>

            // Show error popup if there's an error
            <?php if (isset($_SESSION['search_error'])): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($_SESSION['search_error']); ?>', 'error');
                    document.getElementById('no_rekening').value = prefix;
                }, 500);
                <?php unset($_SESSION['search_error']); ?>
            <?php endif; ?>

            // Alert function
            function showAlert(message, type) {
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 400);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                alertDiv.id = 'alert-' + Date.now();
                alertDiv.addEventListener('click', () => {
                    closeModal(alertDiv.id);
                });
                setTimeout(() => closeModal(alertDiv.id), 5000);
            }

            // Modal close handling
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 400);
                }
            }

            // Animate results
            const resultRows = document.querySelectorAll('.detail-row, .balance-display');
            if (resultRows.length > 0) {
                results.style.display = 'none';
                setTimeout(() => {
                    results.style.display = 'block';
                    resultRows.forEach((row, index) => {
                        row.style.opacity = '0';
                        row.style.transform = 'translateY(10px)';
                        setTimeout(() => {
                            row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                            row.style.opacity = '1';
                            row.style.transform = 'translateY(0)';
                        }, index * 80);
                    });
                }, 100);
            }

            // Enter key support
            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchBtn.click();
                }
            });

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });

            // Focus input
            inputNoRek.focus();
        });
    </script>
</body>
</html>