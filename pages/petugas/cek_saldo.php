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
            padding: 15px 20px;
            display: flex;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            position: relative;
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
            margin-right: 10px;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .top-nav h1 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            margin: 0;
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

        .search-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .search-card:hover {
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
            text-align: center;
        }

        .input-group {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
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
            margin: 0 auto;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 10px 0;
            gap: 10px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
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
            cursor: pointer;
        }

        .notification-modal {
            background: white;
            border-radius: 15px;
            padding: 15px;
            text-align: center;
            max-width: 80%;
            width: 300px;
            box-shadow: var(--shadow-md);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.5s ease-out forwards;
        }

        .notification-modal.error {
            border-left: 5px solid var(--danger-color);
            background: #fff1f0;
        }

        .notification-icon {
            font-size: clamp(2rem, 4vw, 2.5rem);
            margin-bottom: 10px;
        }

        .notification-icon.error {
            color: var(--danger-color);
        }

        .notification-modal h3 {
            color: var(--text-primary);
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            margin-bottom: 8px;
            font-weight: 600;
        }

        .notification-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            line-height: 1.5;
        }

        .account-overlay {
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
            cursor: pointer;
        }

        .account-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 15px;
            padding: 20px;
            text-align: left;
            max-width: 80%;
            width: 350px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        .account-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            color: var(--secondary-color);
            margin-bottom: 15px;
            text-align: center;
            animation: bounceIn 0.6s ease-out;
        }

        .account-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
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

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
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
                padding: 12px 15px;
            }

            .back-btn {
                width: 36px;
                height: 36px;
                margin-right: 8px;
            }

            .top-nav h1 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .search-card {
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
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                grid-template-columns: 1fr 1.5fr;
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

            .notification-modal {
                width: 90%;
                max-width: 280px;
                padding: 12px;
            }

            .notification-icon {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            .notification-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .notification-modal p {
                font-size: clamp(0.8rem, 1.6vw, 0.85rem);
            }

            .account-modal {
                width: 90%;
                max-width: 320px;
                padding: 15px;
            }

            .account-icon {
                font-size: clamp(2rem, 4vw, 2.5rem);
            }

            .account-modal h3 {
                font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            }

            .detail-row {
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }

            .balance-amount {
                font-size: clamp(1.25rem, 2.5vw, 1.5rem);
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 10px 12px;
            }

            .back-btn {
                width: 32px;
                height: 32px;
                margin-right: 6px;
            }

            .top-nav h1 {
                font-size: clamp(0.9rem, 2.2vw, 1.1rem);
            }

            .digit-input {
                width: 32px;
                height: 32px;
                font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            }

            .prefix {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            }

            .notification-modal {
                max-width: 260px;
                padding: 10px;
            }

            .account-modal {
                max-width: 280px;
                padding: 12px;
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

            // Show modal for error
            function showModal(message, type) {
                console.log('showModal called:', message, type);
                // Remove existing modals
                const existingModals = document.querySelectorAll('.notification-overlay, .account-overlay');
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
                    </div>
                `;
                document.body.appendChild(overlay);

                overlay.addEventListener('click', () => {
                    console.log('Closing modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modal = overlay.querySelector('.notification-modal');
                    modal.style.animation = 'popInModal 0.5s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                });

                // Auto-close after 5 seconds
                setTimeout(() => {
                    console.log('Auto-closing modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modal = overlay.querySelector('.notification-modal');
                    modal.style.animation = 'popInModal 0.5s ease-out reverse';
                    setTimeout(() => modal.remove(), 500);
                }, 5000);
            }

            // Show account details modal
            function showAccountModal(data) {
                console.log('showAccountModal called:', data);
                // Remove existing modals
                const existingModals = document.querySelectorAll('.notification-overlay, .account-overlay');
                existingModals.forEach(modal => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                });

                const overlay = document.createElement('div');
                overlay.className = 'account-overlay';
                overlay.innerHTML = `
                    <div class="account-modal">
                        <div class="account-icon">
                            <i class="fas fa-user-circle"></i>
                        </div>
                        <h3><i class="fas fa-info-circle"></i> Detail Rekening</h3>
                        <div class="detail-row">
                            <div class="detail-label">Nama Nasabah:</div>
                            <div class="detail-value">${data.nama}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">No. Rekening:</div>
                            <div class="detail-value">${data.no_rekening}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Kelas:</div>
                            <div class="detail-value">${data.nama_kelas || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Jurusan:</div>
                            <div class="detail-value">${data.nama_jurusan || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Tanggal Pembukaan:</div>
                            <div class="detail-value">${data.created_at}</div>
                        </div>
                        <div class="balance-display">
                            <div class="balance-label">Saldo Rekening Saat Ini</div>
                            <div class="balance-amount">Rp ${data.saldo}</div>
                            <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per ${data.update_time} WIB</div>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);

                overlay.addEventListener('click', () => {
                    console.log('Closing account modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modal = overlay.querySelector('.account-modal');
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                });

                // Auto-close after 5 seconds
                setTimeout(() => {
                    console.log('Auto-closing account modal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modal = overlay.querySelector('.account-modal');
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }

            // Handle session-based modals
            <?php if (isset($_SESSION['search_error'])): ?>
                console.log('Showing error modal: <?php echo addslashes($_SESSION['search_error']); ?>');
                showModal('<?php echo addslashes($_SESSION['search_error']); ?>', 'error');
                clearInputs();
                <?php unset($_SESSION['search_error']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['search_success']) && isset($_SESSION['search_result'])): ?>
                console.log('Showing account modal');
                showAccountModal({
                    nama: '<?php echo addslashes($_SESSION['search_result']['nama']); ?>',
                    no_rekening: '<?php echo addslashes($_SESSION['search_result']['no_rekening']); ?>',
                    nama_kelas: '<?php echo addslashes($_SESSION['search_result']['nama_kelas'] ?? 'N/A'); ?>',
                    nama_jurusan: '<?php echo addslashes($_SESSION['search_result']['nama_jurusan'] ?? 'N/A'); ?>',
                    created_at: '<?php echo date('d/m/Y', strtotime($_SESSION['search_result']['created_at'] ?? 'now')); ?>',
                    saldo: '<?php echo number_format($_SESSION['search_result']['saldo'], 0, ',', '.'); ?>',
                    update_time: '<?php echo date('d/m/Y H:i'); ?>'
                });
                <?php
                unset($_SESSION['search_result']);
                unset($_SESSION['search_success']);
                ?>
            <?php endif; ?>

            // Focus first input on load
            clearInputs();
        });
    </script>
</body>
</html>