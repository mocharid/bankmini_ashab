<?php
session_start();
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Validate admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Generate or validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Temporary debug logging
function debugLog($message) {
    error_log("[DEBUG kode_akses_petugas.php] " . $message);
}

$admin_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$error = null;
$success = null;
$existing_code = null;
$show_confirm_popup = false;

// Check for existing code
try {
    $code_query = "SELECT code FROM petugas_login_codes WHERE valid_date = ? AND admin_id = ?";
    $code_stmt = $conn->prepare($code_query);
    if (!$code_stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $code_stmt->bind_param("si", $current_date, $admin_id);
    $code_stmt->execute();
    $code_result = $code_stmt->get_result();
    if ($code_result->num_rows > 0) {
        $existing_code = $code_result->fetch_assoc()['code'];
    }
    $code_stmt->close();
} catch (Exception $e) {
    $error = "Gagal memeriksa kode: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    debugLog("Code check error: " . $e->getMessage());
}

// Handle initial code generation form submission with PRG pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_confirm']) && !isset($_POST['ajax_view'])) {
    debugLog("Initial code generation submission: " . json_encode($_POST));
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Token CSRF tidak valid.'];
        header("Location: kode_akses_petugas.php");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'generate') {
        $_SESSION['code_confirm_data'] = ['action' => $existing_code ? 'replace' : 'create'];
        header("Location: kode_akses_petugas.php");
        exit();
    } else {
        $_SESSION['show_popup'] = ['type' => 'error', 'message' => 'Aksi tidak valid.'];
        header("Location: kode_akses_petugas.php");
        exit();
    }
}

// Handle AJAX code generation confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_confirm'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'error', 'message' => '', 'code' => ''];

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response['message'] = 'Token CSRF tidak valid.';
        echo json_encode($response);
        exit();
    }

    try {
        $conn->begin_transaction();

        // Generate a random 4-digit code
        $code = sprintf("%04d", mt_rand(0, 9999));

        // Insert or update the code
        $query = "INSERT INTO petugas_login_codes (code, valid_date, admin_id) VALUES (?, ?, ?) 
                  ON DUPLICATE KEY UPDATE code = ?, updated_at = CURRENT_TIMESTAMP";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("ssis", $code, $current_date, $admin_id, $code);
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $conn->commit();
        debugLog("Success: Code generated/updated for admin ID $admin_id, code: $code");
        $response['status'] = 'success';
        $response['message'] = $affected_rows > 0 ? "Kode berhasil dibuat atau diperbarui!" : "Tidak ada perubahan pada kode.";
        $response['code'] = $code;
    } catch (Exception $e) {
        $conn->rollback();
        debugLog("Error during code generation: " . $e->getMessage());
        $response['message'] = "Gagal membuat kode: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }

    echo json_encode($response);
    exit();
}

// Handle AJAX view code request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_view'])) {
    header('Content-Type: application/json');
    $response = ['status' => 'success', 'code' => $existing_code];
    
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $response = ['status' => 'error', 'message' => 'Token CSRF tidak valid.'];
    }

    echo json_encode($response);
    exit();
}

// Check for session data to show confirmation popup
if (isset($_SESSION['code_confirm_data'])) {
    $show_confirm_popup = true;
    $action = $_SESSION['code_confirm_data']['action'];
    unset($_SESSION['code_confirm_data']);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kode Akses Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
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
            overflow-x: hidden;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            position: relative;
            z-index: 10;
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
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
        }

        .back-btn:active {
            transform: translateY(0) scale(0.98);
        }

        .main-content {
            flex: 1;
            padding: 20px 15px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.6s ease-out;
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
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.3rem, 3vw, 1.6rem);
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
            font-size: clamp(0.85rem, 2vw, 0.95rem);
            line-height: 1.5;
        }

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 25px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .button-container {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            width: 100%;
            max-width: 250px;
            opacity: 0;
            animation: fadeInButton 0.4s ease forwards;
            box-shadow: 0 4px 8px rgba(30, 58, 138, 0.2);
            position: relative;
            overflow: hidden;
        }

        .btn::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 5px;
            height: 5px;
            background: rgba(255, 255, 255, 0.5);
            opacity: 0;
            border-radius: 100%;
            transform: scale(1, 1) translate(-50%, -50%);
            transform-origin: 50% 50%;
        }

        .btn:focus:not(:active)::after {
            animation: ripple 0.6s ease-out;
        }

        @keyframes ripple {
            0% {
                transform: scale(0, 0);
                opacity: 0.5;
            }
            100% {
                transform: scale(40, 40);
                opacity: 0;
            }
        }

        @keyframes fadeInButton {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 12px rgba(30, 58, 138, 0.25);
        }

        .btn:active {
            transform: translateY(1px);
            box-shadow: 0 2px 5px rgba(30, 58, 138, 0.2);
        }

        .btn-confirm {
            background: var(--secondary-color);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.2);
        }

        .btn-confirm:hover {
            background: var(--secondary-dark);
            box-shadow: 0 6px 12px rgba(59, 130, 246, 0.25);
        }

        .btn:disabled {
            background: #999;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            backdrop-filter: blur(5px);
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
            width: clamp(320px, 85vw, 420px);
            border-radius: 18px;
            padding: clamp(25px, 5vw, 35px);
            box-shadow: var(--shadow-md);
            transform: scale(0.9);
            opacity: 0;
            animation: popInModal 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            margin: 0 15px;
        }

        .success-modal {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.9); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(3.5rem, 8vw, 4.5rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: white;
        }

        .error-icon {
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.15); }
            80% { transform: scale(0.95); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 15px;
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.9rem, 2.3vw, 1rem);
            margin: 0 0 20px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .code-display {
            font-size: clamp(1.8rem, 4vw, 2.2rem);
            font-weight: 700;
            color: white;
            margin: 15px 0;
            padding: 12px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            letter-spacing: 0.3rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            animation: fadeInCode 0.5s ease-out 0.4s both;
        }

        @keyframes fadeInCode {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 12px;
        }

        .modal-buttons .btn-confirm {
            padding: 10px 20px;
            max-width: 150px;
        }

        /* Improved mobile responsiveness */
        @media (max-width: 768px) {
            .top-nav {
                padding: 12px 15px;
            }

            .main-content {
                padding: 15px 10px;
            }

            .welcome-banner {
                padding: 18px;
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .form-section {
                padding: 18px;
            }

            .button-container {
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }

            .btn {
                padding: 12px 20px;
                max-width: 100%;
                border-radius: 10px;
            }

            .success-modal, .error-modal {
                width: clamp(300px, 90vw, 380px);
                padding: clamp(20px, 5vw, 30px);
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 7vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.8vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.2vw, 0.95rem);
            }

            .code-display {
                font-size: clamp(1.6rem, 3.5vw, 2rem);
                padding: 10px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 10px 12px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 3vw, 1.3rem);
                gap: 8px;
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-section {
                padding: 15px;
            }

            .button-container {
                gap: 10px;
            }

            .btn {
                padding: 10px 18px;
                font-size: 0.9rem;
            }

            .success-modal, .error-modal {
                width: clamp(280px, 92vw, 350px);
                padding: clamp(18px, 4vw, 25px);
                border-radius: 16px;
            }

            .success-icon, .error-icon {
                font-size: clamp(2.8rem, 6.5vw, 3.5rem);
                margin-bottom: 15px;
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.1rem, 2.7vw, 1.3rem);
                margin-bottom: 12px;
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.8rem, 2.1vw, 0.9rem);
                margin-bottom: 18px;
            }

            .code-display {
                font-size: clamp(1.4rem, 3vw, 1.8rem);
                letter-spacing: 0.2rem;
                margin: 12px 0;
            }

            .modal-buttons {
                margin-top: 15px;
            }

            .modal-buttons .btn-confirm {
                padding: 8px 16px;
                max-width: 120px;
            }
        }
    </style>
    <script>
        // Prevent double-tap and pinch-zoom
        let lastTouchEnd = 0;
        document.addEventListener('touchend', (event) => {
            const now = new Date().getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, false);

        document.addEventListener('gesturestart', (event) => {
            event.preventDefault();
        }, false);

        document.addEventListener('touchmove', (event) => {
            if (event.scale !== 1) {
                event.preventDefault();
            }
        }, { passive: false });
    </script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'" aria-label="Kembali">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-key"></i> Kode Akses Petugas</h2>
            <p>Fitur ini memungkinkan admin untuk menghasilkan kode akses harian (4 digit) yang digunakan oleh petugas untuk masuk ke dashboard SCHOBANK, meningkatkan keamanan akses sistem.</p>
        </div>

        <div class="success-overlay" id="popupModal" style="display: none;">
            <div class="success-modal">
                <div class="popup-icon">
                    <i class="fas"></i>
                </div>
                <h3 class="popup-title"></h3>
                <p class="popup-message"></p>
                <div class="code-display" id="popup-code" style="display: none;"></div>
            </div>
        </div>

        <div class="form-section" id="code-form-card">
            <form action="" method="POST" id="code-form">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="button-container">
                    <button type="submit" class="btn" id="generate-btn">
                        <i class="fas <?php echo $existing_code ? 'fa-sync-alt' : 'fa-plus'; ?>"></i> <?php echo $existing_code ? 'Ganti Kode' : 'Buat Kode Baru'; ?>
                    </button>
                    <?php if ($existing_code): ?>
                        <button type="button" class="btn btn-confirm" id="view-code-btn">
                            <i class="fas fa-eye"></i> Lihat Kode
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if ($show_confirm_popup): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <h3>Konfirmasi Pembuatan Kode</h3>
                    <p>Apakah Anda yakin ingin <?php echo $action === 'replace' ? 'mengganti kode' : 'membuat kode baru'; ?> untuk hari ini? <?php echo $action === 'replace' ? 'Kode sebelumnya akan diganti.' : ''; ?></p>
                    <form id="confirm-form">
                        <input type="hidden" name="ajax_confirm" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">Konfirmasi</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
$(document).ready(function() {
    let autoCloseTimer = null;
    let isProcessing = false;
    let isViewCodePopup = false;
    let isConfirmPopup = <?php echo $show_confirm_popup ? 'true' : 'false'; ?>;
    
    // Initialize confirm modal if needed
    if (isConfirmPopup) {
        $('#confirmModal').css('display', 'flex');
    }

    // Enhanced debounce function
    function debounce(func, wait, immediate) {
        let timeout;
        return function() {
            const context = this, args = arguments;
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    }

    // Show alert with smooth animations
    function showAlert(message, type, code = null, isViewCode = false) {
        isViewCodePopup = isViewCode;
        const popupModal = $('#popupModal');
        const modalContent = popupModal.find('.success-modal');
        
        // Set modal type and content
        modalContent.removeClass('error-modal').addClass(type === 'success' ? 'success-modal' : 'error-modal');
        popupModal.find('.popup-title').text(type === 'success' ? (isViewCode ? 'Kode Akses Hari Ini' : 'Kode Berhasil Dibuat!') : 'Peringatan');
        popupModal.find('.popup-message').text(message);
        popupModal.find('.popup-icon')
            .removeClass('error-icon')
            .addClass(type === 'success' ? 'success-icon' : 'error-icon')
            .find('i')
            .removeClass('fa-exclamation-circle')
            .addClass(type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');

        // Handle code display
        const codeDisplay = popupModal.find('#popup-code');
        if (type === 'success' && code) {
            codeDisplay.text(code).show();
        } else {
            codeDisplay.hide();
        }

        // Reset and animate modal
        popupModal.stop(true, true).hide();
        modalContent.css({ transform: 'scale(0.9)', opacity: 0 });
        
        popupModal.css('display', 'flex').animate({ opacity: 1 }, 300, function() {
            modalContent.css({ animation: 'popInModal 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards' });
        });

        // Auto-close for non-view-code popups
        if (autoCloseTimer) clearTimeout(autoCloseTimer);
        if (!isViewCode) {
            autoCloseTimer = setTimeout(() => {
                closePopup('#popupModal');
            }, 5000);
        }
    }

    // Close popup with animation
    function closePopup(modalId) {
        const modal = $(modalId);
        modal.css({ animation: 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards' });
        setTimeout(() => {
            modal.hide().css({ animation: '' });
            if (modalId === '#popupModal') {
                isViewCodePopup = false;
            } else if (modalId === '#confirmModal') {
                isConfirmPopup = false;
            }
            if (autoCloseTimer) {
                clearTimeout(autoCloseTimer);
                autoCloseTimer = null;
            }
        }, 400);
    }

    // Show loading spinner on buttons
    function showLoadingSpinner(button) {
        const originalContent = button.html();
        button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
        return {
            restore: () => {
                button.html(originalContent).prop('disabled', false);
            }
        };
    }

    // Update button container after code generation
    function updateButtonContainer() {
        const buttonContainer = $('.button-container');
        const generateButton = $('#generate-btn');

        // Update generate button
        generateButton.html('<i class="fas fa-sync-alt"></i> Ganti Kode')
            .css({ opacity: 0 })
            .animate({ opacity: 1 }, 300);

        // Add view button if it doesn't exist
        if ($('#view-code-btn').length === 0) {
            const viewButton = $('<button>')
                .attr({
                    type: 'button',
                    id: 'view-code-btn'
                })
                .addClass('btn btn-confirm')
                .html('<i class="fas fa-eye"></i> Lihat Kode')
                .css({ opacity: 0 });
            
            buttonContainer.append(viewButton);
            viewButton.animate({ opacity: 1 }, 300);
            viewButton.on('click', debounce(viewCodeHandler, 300));
        }
    }

    // Form submission handler
    $('#code-form').on('submit', function(e) {
        e.preventDefault();
        if (isProcessing) return;
        isProcessing = true;

        const button = $('#generate-btn');
        const spinner = showLoadingSpinner(button);

        // Add slight delay for better UX
        setTimeout(() => {
            spinner.restore();
            isProcessing = false;
            this.submit();
        }, 500);
    });

    // Confirm form submission handler
    $('#confirm-form').on('submit', function(e) {
        e.preventDefault();
        if (isProcessing) return;
        isProcessing = true;

        const button = $('#confirm-btn');
        const spinner = showLoadingSpinner(button);

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: $(this).serialize(),
            dataType: 'json',
            success: function(response) {
                spinner.restore();
                isProcessing = false;
                closePopup('#confirmModal');
                
                if (response.status === 'success') {
                    showAlert(response.message, 'success', response.code);
                    updateButtonContainer();
                } else {
                    showAlert(response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                spinner.restore();
                isProcessing = false;
                closePopup('#confirmModal');
                showAlert('Terjadi kesalahan. Silakan coba lagi.', 'error');
                console.error('AJAX Error:', status, error);
            }
        });
    });

    // View code handler with debounce
    const viewCodeHandler = debounce(function() {
        if (isProcessing) return;
        isProcessing = true;

        const button = $('#view-code-btn');
        const spinner = showLoadingSpinner(button);

        $.ajax({
            url: window.location.href,
            method: 'POST',
            data: { 
                ajax_view: 1, 
                csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>' 
            },
            dataType: 'json',
            success: function(response) {
                spinner.restore();
                isProcessing = false;
                
                if (response.status === 'success') {
                    showAlert('Kode akses hari ini:', 'success', response.code, true);
                } else {
                    showAlert(response.message || 'Gagal memuat kode.', 'error');
                }
            },
            error: function(xhr, status, error) {
                spinner.restore();
                isProcessing = false;
                showAlert('Gagal memuat kode. Silakan coba lagi.', 'error');
                console.error('AJAX Error:', status, error);
            }
        });
    }, 300, true);

    // Attach event handlers
    $('#view-code-btn').on('click', viewCodeHandler);

    // Close modal when clicking outside
    $(document).on('click', function(e) {
        if ($(e.target).hasClass('success-overlay')) {
            const popupModal = $('#popupModal');
            const confirmModal = $('#confirmModal');
            
            if (popupModal.is(':visible')) {
                closePopup('#popupModal');
            }
            if (confirmModal.is(':visible')) {
                closePopup('#confirmModal');
            }
        }
    });

    // Show any session popup messages
    <?php if (isset($_SESSION['show_popup'])): ?>
        showAlert('<?php echo addslashes($_SESSION['show_popup']['message']); ?>', '<?php echo $_SESSION['show_popup']['type']; ?>');
        <?php unset($_SESSION['show_popup']); ?>
    <?php endif; ?>
});
    </script>
</body>
</html>