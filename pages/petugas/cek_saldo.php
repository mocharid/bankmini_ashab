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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Saldo - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" onerror="console.error('Font Awesome failed to load')">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
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

        .search-card, .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .search-card:hover, .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .input-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prefix {
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 600;
            color: var(--text-primary);
            padding: 12px 15px;
            background: #f0f0f0;
            border-radius: 10px 0 0 10px;
            border: 1px solid #ddd;
            border-right: none;
        }

        .digit-inputs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .digit-input {
            width: 40px;
            height: 40px;
            padding: 0;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            transition: var(--transition);
            background: white;
        }

        .digit-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.05);
        }

        .digit-input:invalid {
            border-color: var(--danger-color);
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            width: fit-content;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        .modal-close-btn {
            background-color: var(--danger-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 15px;
            font-size: clamp(0.85rem, 2vw, 0.95rem);
            transition: var(--transition);
        }

        .modal-close-btn:hover {
            background-color: #d32f2f;
            transform: translateY(-2px);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
            gap: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
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
            padding: 25px;
            background: linear-gradient(to right, #f8faff, #f0f5ff);
            border-radius: 12px;
            text-align: center;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.03);
            margin-top: 20px;
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
            font-size: clamp(1rem, 2vw, 1.1rem);
            margin-bottom: 15px;
            font-weight: 500;
        }

        .balance-amount {
            font-size: clamp(2rem, 4vw, 2.25rem);
            color: var(--primary-color);
            font-weight: 700;
            margin: 15px 0;
            position: relative;
            display: inline-block;
            padding: 8px 25px;
            border-radius: 50px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .balance-info {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            margin-top: 15px;
        }

        .notification-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        .notification-modal {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            max-width: 90%;
            width: 350px;
            box-shadow: var(--shadow-md);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.5s ease-out forwards;
        }

        .notification-modal.error {
            border-left: 5px solid var(--danger-color);
            background: #fff1f0;
        }

        .notification-modal.success {
            border-left: 5px solid var(--primary-color);
            background: var(--primary-light);
        }

        .notification-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            margin-bottom: 15px;
        }

        .notification-icon.error {
            color: var(--danger-color);
        }

        .notification-icon.success {
            color: var(--primary-color);
        }

        .notification-modal h3 {
            color: var(--text-primary);
            font-size: clamp(1.2rem, 2.5vw, 1.3rem);
            margin-bottom: 10px;
            font-weight: 600;
        }

        .notification-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
        }

        .success-overlay {
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
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
            min-width: 100px;
        }

        .btn-loading .btn-content {
            visibility: hidden;
        }

        .btn-loading::after {
            content: ". . .";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            animation: dots 1.5s infinite;
            white-space: nowrap;
        }

        @keyframes dots {
            0% { content: "."; }
            33% { content: ". ."; }
            66% { content: ". . ."; }
            100% { content: "."; }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .search-card, .results-card {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .search-form {
                gap: 15px;
            }

            .digit-input {
                width: 35px;
                height: 35px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            button {
                width: 100%;
                justify-content: center;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .balance-label {
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            .balance-amount {
                font-size: clamp(1.75rem, 3.5vw, 2rem);
                padding: 6px 20px;
            }

            .balance-info {
                font-size: clamp(0.8rem, 1.8vw, 0.85rem);
            }

            .notification-modal {
                width: 90%;
                padding: 15px;
            }

            .notification-icon {
                font-size: clamp(2rem, 4vw, 2.5rem);
            }

            .notification-modal h3 {
                font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            }

            .notification-modal p {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }

            .success-icon {
                font-size: clamp(3.5rem, 7vw, 4rem);
            }

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2vw, 1rem);
            }
        }
    </style>
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
            <h2><i class="fas fa-wallet"></i> Cek Saldo</h2>
            <p>Cek saldo rekening nasabah dengan mudah dan cepat</p>
        </div>

        <div class="search-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
            <form id="searchForm" action="" method="POST" class="search-form">
                <div>
                    <label for="no_rekening">No Rekening:</label>
                    <div class="input-group">
                        <span class="prefix">REK</span>
                        <div class="digit-inputs">
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                            <input type="tel" class="digit-input" maxlength="1" pattern="[0-9]" inputmode="numeric" required>
                        </div>
                        <input type="hidden" id="no_rekening" name="no_rekening">
                    </div>
                </div>
                <button type="submit" id="searchBtn">
                    <span class="btn-content">
                        <i class="fas fa-search"></i>
                        <span>Cek Saldo</span>
                    </span>
                </button>
            </form>
        </div>

        <?php
        if (isset($_SESSION['search_result'])) {
            $row = $_SESSION['search_result'];
            ?>
            <div class="results-card" id="resultsContainer">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                <div class="detail-row">
                    <div class="detail-label">Nama Nasabah:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($row['nama']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">No. Rekening:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($row['no_rekening']); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($row['nama_kelas'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($row['nama_jurusan'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Tanggal Pembukaan:</div>
                    <div class="detail-value"><?php echo date('d/m/Y', strtotime($row['created_at'] ?? 'now')); ?></div>
                </div>
                <div class="balance-display">
                    <div class="balance-label">Saldo Rekening Saat Ini</div>
                    <div class="balance-amount">Rp <?php echo number_format($row['saldo'], 0, ',', '.'); ?></div>
                    <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per <?php echo date('d/m/Y H:i'); ?> WIB</div>
                </div>
            </div>
            <?php
            // Clear session data
            unset($_SESSION['search_result']);
            unset($_SESSION['search_success']);
        }
        ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        // Fallback for jQuery
        if (typeof jQuery === 'undefined') {
            console.error('jQuery not loaded, loading fallback');
            document.write('<script src="/path/to/local/jquery.min.js"><\/script>');
        }

        // Prevent multi-touch zoom and gestures
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(event) {
            event.preventDefault();
        });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, initializing script');
            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const digitInputs = document.querySelectorAll('.digit-input');
            const noRekeningInput = document.getElementById('no_rekening');
            const resultsContainer = document.getElementById('resultsContainer');
            const prefix = "REK";

            // Initialize digit inputs
            digitInputs.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    const value = this.value.replace(/[^0-9]/g, '');
                    this.value = value;
                    if (value && index < digitInputs.length - 1) {
                        digitInputs[index + 1].focus();
                    }
                    updateNoRekening();
                });

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.value && index > 0) {
                        digitInputs[index - 1].focus();
                        digitInputs[index - 1].value = '';
                        updateNoRekening();
                    }
                });

                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                    let currentIndex = index;
                    for (let i = 0; i < pastedData.length && currentIndex < digitInputs.length; i++) {
                        digitInputs[currentIndex].value = pastedData[i];
                        currentIndex++;
                    }
                    if (currentIndex < digitInputs.length) {
                        digitInputs[currentIndex].focus();
                    }
                    updateNoRekening();
                });
            });

            // Update hidden no_rekening input
            function updateNoRekening() {
                let noRekening = prefix;
                digitInputs.forEach(input => {
                    noRekening += input.value || '';
                });
                noRekeningInput.value = noRekening;
            }

            // Clear inputs and reset focus
            function clearInputs() {
                console.log('Clearing inputs');
                digitInputs.forEach(input => input.value = '');
                noRekeningInput.value = prefix;
                digitInputs[0].focus();
            }

            // Initialize hidden input
            noRekeningInput.value = prefix;

            // Search form handling
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const noRekening = noRekeningInput.value;
                console.log('Form submitted, no_rekening:', noRekening);
                if (noRekening === prefix || noRekening.length < prefix.length + digitInputs.length) {
                    console.log('Invalid input, showing error modal');
                    showModal('Masukkan nomor rekening lengkap (6 digit)', 'error');
                    digitInputs[0].classList.add('form-error');
                    setTimeout(() => digitInputs.forEach(input => input.classList.remove('form-error')), 400);
                    clearInputs();
                    return;
                }
                searchBtn.classList.add('btn-loading');
                setTimeout(() => {
                    searchForm.submit();
                }, 1000);
            });

            // Show modal for error or success
            function showModal(message, type) {
                console.log('showModal called:', message, type);
                // Remove existing modals
                const existingModals = document.querySelectorAll('.notification-overlay, .success-overlay');
                existingModals.forEach(modal => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                });

                const overlay = document.createElement('div');
                overlay.className = 'notification-overlay';
                const icon = type === 'error' ? 'exclamation-circle' : 'check-circle';
                const title = type === 'error' ? 'Error' : 'Berhasil';
                overlay.innerHTML = `
                    <div class="notification-modal ${type}">
                        <div class="notification-icon ${type}">
                            <i class="fas fa-${icon}"></i>
                        </div>
                        <h3>${title}</h3>
                        <p>${message}</p>
                        <button class="modal-close-btn" onclick="this.closest('.notification-overlay').click()">Tutup</button>
                    </div>
                `;
                document.body.appendChild(overlay);

                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay || e.target.classList.contains('modal-close-btn')) {
                        console.log('Closing modal');
                        overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        const modal = overlay.querySelector('.notification-modal');
                        modal.style.animation = 'popInModal 0.5s ease-out reverse';
                        setTimeout(() => overlay.remove(), 500);
                    }
                });

                // Auto-close after 5 seconds
                setTimeout(() => {
                    console.log('Auto-closing modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modal = overlay.querySelector('.notification-modal');
                    modal.style.animation = 'popInModal 0.5s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }

            // Show success animation with confetti
            function showSuccessAnimation(message) {
                console.log('showSuccessAnimation called:', message);
                // Remove existing modals
                const existingModals = document.querySelectorAll('.notification-overlay, .success-overlay');
                existingModals.forEach(modal => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                });

                const overlay = document.createElement('div');
                overlay.className = 'success-overlay';
                overlay.innerHTML = `
                    <div class="success-modal">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Pencarian Berhasil!</h3>
                        <p>${message}</p>
                        <button class="modal-close-btn" onclick="this.closest('.success-overlay').click()">Tutup</button>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.success-modal');
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    modal.appendChild(confetti);
                }

                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay || e.target.classList.contains('modal-close-btn')) {
                        console.log('Closing success modal');
                        overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        modal.style.animation = 'popInModal 0.7s ease-out reverse';
                        setTimeout(() => overlay.remove(), 500);
                    }
                });

                // Auto-close after 5 seconds
                setTimeout(() => {
                    console.log('Auto-closing success modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }

            // Animate results container
            if (resultsContainer) {
                console.log('Animating results container');
                resultsContainer.style.display = 'none';
                setTimeout(() => {
                    resultsContainer.style.display = 'block';
                    resultsContainer.style.opacity = '0';
                    resultsContainer.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        resultsContainer.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                        resultsContainer.style.opacity = '1';
                        resultsContainer.style.transform = 'translateY(0)';
                    }, 100);
                }, 100);
            }

            // Enter key support on last input
            digitInputs[digitInputs.length - 1].addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && this.value) {
                    console.log('Enter key pressed, submitting form');
                    searchBtn.click();
                }
            });

            // Handle session-based modals
            <?php if (isset($_SESSION['search_error'])): ?>
                console.log('Showing error modal: <?php echo addslashes($_SESSION['search_error']); ?>');
                showModal('<?php echo addslashes($_SESSION['search_error']); ?>', 'error');
                clearInputs();
                <?php unset($_SESSION['search_error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['search_success'])): ?>
                console.log('Showing success modal');
                showSuccessAnimation('Informasi saldo berhasil ditemukan');
            <?php endif; ?>

            // Focus first input on load
            clearInputs();
        });
    </script>
</body>
</html>