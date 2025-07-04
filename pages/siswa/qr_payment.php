<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/qr_generator.php'; // Assume a QR code generator library is included

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user details
$query = "SELECT u.nama, u.email, u.pin FROM users WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

// Check if PIN is set
$has_pin = !empty($user_data['pin']);
if (!$has_pin) {
    header("Location: dashboard.php");
    exit();
}

// Fetch rekening details
$query = "SELECT r.id, r.no_rekening, r.saldo FROM rekening WHERE r.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rekening_data = $stmt->get_result()->fetch_assoc();

$no_rekening = $rekening_data ? $rekening_data['no_rekening'] : 'N/A';
$saldo = $rekening_data ? $rekening_data['saldo'] : 0;
$rekening_id = $rekening_data ? $rekening_data['id'] : null;

// Handle QR scan submission
$show_error_popup = false;
$error_message = '';
$success_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_code'])) {
    $qr_code = trim($_POST['qr_code']);
    $amount = floatval($_POST['amount']);
    $pin = $_POST['pin'];

    // Verify PIN
    if (hash('sha256', $pin) !== $user_data['pin']) {
        $show_error_popup = true;
        $error_message = "PIN salah!";
    } else {
        // Decode QR code (assuming QR contains JSON with recipient's rekening number)
        $qr_data = json_decode($qr_code, true);
        if ($qr_data && isset($qr_data['no_rekening'])) {
            $recipient_no_rekening = $qr_data['no_rekening'];

            // Fetch recipient rekening
            $query = "SELECT r.id, r.user_id FROM rekening r WHERE r.no_rekening = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $recipient_no_rekening);
            $stmt->execute();
            $recipient_data = $stmt->get_result()->fetch_assoc();

            if ($recipient_data && $recipient_data['user_id'] != $user_id) {
                if ($saldo >= $amount && $amount > 0) {
                    // Start transaction
                    $conn->begin_transaction();
                    try {
                        // Create transaction
                        $no_transaksi = 'TX' . time() . rand(1000, 9999);
                        $query = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, status, rekening_tujuan_id) 
                                  VALUES (?, ?, 'transfer', ?, 'pending', ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("sidi", $no_transaksi, $rekening_id, $amount, $recipient_data['id']);
                        $stmt->execute();

                        // Update sender balance
                        $new_saldo = $saldo - $amount;
                        $query = "UPDATE rekening SET saldo = ? WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("di", $new_saldo, $rekening_id);
                        $stmt->execute();

                        // Update recipient balance
                        $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("di", $amount, $recipient_data['id']);
                        $stmt->execute();

                        // Create mutation record for sender
                        $query = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                                  VALUES (LAST_INSERT_ID(), ?, ?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("idd", $rekening_id, $amount, $new_saldo);
                        $stmt->execute();

                        // Create mutation record for recipient
                        $query = "SELECT saldo FROM rekening WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $recipient_data['id']);
                        $stmt->execute();
                        $recipient_new_saldo = $stmt->get_result()->fetch_assoc()['saldo'];
                        $query = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                                  VALUES (LAST_INSERT_ID(), ?, ?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("idd", $recipient_data['id'], $amount, $recipient_new_saldo);
                        $stmt->execute();

                        // Create notification for sender
                        $message = "Transfer Rp " . number_format($amount, 0, ',', '.') . " ke $recipient_no_rekening berhasil.";
                        $query = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("is", $user_id, $message);
                        $stmt->execute();

                        // Create notification for recipient
                        $recipient_message = "Menerima transfer Rp " . number_format($amount, 0, ',', '.') . " dari $no_rekening.";
                        $query = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("is", $recipient_data['user_id'], $recipient_message);
                        $stmt->execute();

                        $conn->commit();
                        $success_message = "Transfer berhasil!";
                        $saldo = $new_saldo; // Update displayed balance
                    } catch (Exception $e) {
                        $conn->rollback();
                        $show_error_popup = true;
                        $error_message = "Transfer gagal: " . $e->getMessage();
                    }
                } else {
                    $show_error_popup = true;
                    $error_message = "Saldo tidak cukup atau jumlah tidak valid!";
                }
            } else {
                $show_error_popup = true;
                $error_message = "Rekening tujuan tidak valid atau tidak boleh transfer ke diri sendiri!";
            }
        } else {
            $show_error_popup = true;
            $error_message = "QR code tidak valid!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Pembayaran QR - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', 'DM Sans', 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            font-size: clamp(14px, 2.5vw, 16px);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-user-select: none;
            user-select: none;
        }

        .wrapper {
            flex: 1;
            padding: clamp(1rem, 2vw, 1.5rem);
        }

        :root {
            --font-size-xs: clamp(12px, 2vw, 14px);
            --font-size-sm: clamp(14px, 2.5vw, 16px);
            --font-size-md: clamp(16px, 3vw, 18px);
            --font-size-lg: clamp(18px, 3.5vw, 22px);
            --font-size-xl: clamp(24px, 4vw, 28px);
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #3b82f6;
            --error-color: #e74c3c;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: clamp(1rem, 3vw, 1.5rem) clamp(1.5rem, 4vw, 2rem);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
            margin-bottom: clamp(1.5rem, 3vw, 2rem);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: var(--font-size-xl);
            font-weight: 600;
            letter-spacing: -0.5px;
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

        .tab-container {
            display: flex;
            justify-content: center;
            margin: clamp(1rem, 2vw, 1.5rem) auto;
            max-width: clamp(300px, 80vw, 400px);
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .tab {
            flex: 1;
            padding: clamp(0.8rem, 2vw, 1rem);
            text-align: center;
            cursor: pointer;
            font-size: var(--font-size-md);
            font-weight: 500;
            color: #333;
            transition: var(--transition);
        }

        .tab.active {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
        }

        .tab:hover {
            background: rgba(30, 60, 114, 0.1);
        }

        .tab-content {
            display: none;
            margin: clamp(1.5rem, 3vw, 2rem) auto;
            max-width: clamp(300px, 80vw, 400px);
            background: white;
            border-radius: 16px;
            padding: clamp(1rem, 2vw, 1.5rem);
            box-shadow: var(--shadow-sm);
        }

        .tab-content.active {
            display: block;
        }

        .form-group {
            margin-bottom: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        .form-group label {
            font-size: var(--font-size-sm);
            font-weight: 500;
            color: #333;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: clamp(0.6rem, 1.5vw, 0.8rem);
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: var(--font-size-sm);
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .input-container {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .qr-btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 10px;
            cursor: pointer;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .qr-btn:hover {
            background: var(--secondary-color);
            transform: translateY(-2px);
        }

        .btn {
            display: block;
            width: 100%;
            padding: clamp(0.8rem, 2vw, 1rem);
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: var(--font-size-md);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn:hover {
            background: linear-gradient(135deg, #2a5298 0%, #1e3c72 100%);
            transform: translateY(-2px);
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
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .qr-image-container {
            text-align: center;
            margin: clamp(1rem, 2vw, 1.5rem) 0;
        }

        .qr-image {
            max-width: 100%;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .alert {
            padding: clamp(0.8rem, 2vw, 1rem);
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: var(--font-size-sm);
        }

        .alert-success {
            background-color: #e6f4ea;
            color: #28a745;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #dc3545;
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

        .success-modal, .error-modal, .qr-modal {
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
            cursor: default;
            overflow: hidden;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
        }

        .success-modal::before, .error-modal::before, .qr-modal::before {
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

        .success-icon, .error-icon, .qr-icon {
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

        .success-modal h3, .error-modal h3, .qr-modal h3 {
            color: white;
            margin: 0 0 8px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
        }

        .success-modal p, .error-modal p, .qr-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            line-height: 1.5;
            padding: 0 10px;
        }

        .qr-modal video {
            width: 100%;
            border-radius: 8px;
            margin-top: 10px;
        }

        .qr-modal .btn {
            margin-top: 10px;
            width: 100%;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        @media (max-width: 576px) {
            .header h1 {
                font-size: var(--font-size-lg);
            }

            .tab-container {
                max-width: clamp(280px, 90vw, 340px);
            }

            .tab {
                font-size: var(--font-size-sm);
                padding: clamp(0.6rem, 1.5vw, 0.8rem);
            }

            .tab-content {
                max-width: clamp(280px, 90vw, 340px);
                padding: clamp(0.8rem, 1.5vw, 1rem);
            }

            .success-modal, .error-modal, .qr-modal {
                width: clamp(260px, 80vw, 340px);
                padding: clamp(12px, 3vw, 15px);
            }

            .success-icon, .error-icon, .qr-icon {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3, .error-modal h3, .qr-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p, .qr-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .qr-btn {
                width: 40px;
                height: 40px;
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="wrapper">
        <div class="header">
            <button class="back-btn" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-arrow-left"></i>
            </button>
            <h1>Pembayaran QR - SchoBank</h1>
            <div style="width: 40px;"></div>
        </div>

        <div id="alertContainer"></div>

        <div class="tab-container">
            <div class="tab active" data-tab="scan">Scan QR</div>
            <div class="tab" data-tab="show">Tampilkan QR</div>
        </div>

        <div class="tab-content active" id="scan-tab">
            <form id="qrForm" method="POST" action="">
                <?php if ($success_message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error_message); ?></div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="qr_code">Kode QR (Masukkan kode JSON dari QR)</label>
                    <div class="input-container">
                        <textarea id="qr_code" name="qr_code" rows="4" placeholder="Tempel kode QR di sini" required></textarea>
                        <button type="button" class="qr-btn" id="scanQrBtn" title="Scan QR Code">
                            <i class="fas fa-qrcode"></i>
                        </button>
                    </div>
                    <span class="error-message" id="qr_code-error"></span>
                </div>
                <div class="form-group">
                    <label for="amount">Jumlah Transfer (Rp)</label>
                    <input type="number" id="amount" name="amount" min="1000" step="1000" placeholder="Masukkan jumlah" required>
                </div>
                <div class="form-group">
                    <label for="pin">PIN Keamanan</label>
                    <input type="password" id="pin" name="pin" maxlength="6" placeholder="Masukkan PIN" required>
                </div>
                <button type="submit" class="btn" id="submitBtn">
                    <span class="btn-content"><i class="fas fa-paper-plane"></i> Kirim Transfer</span>
                </button>
            </form>
        </div>

        <div class="tab-content" id="show-tab">
            <div class="qr-image-container">
                <?php
                // Generate QR code
                $qr_data = json_encode(['no_rekening' => $no_rekening]);
                $qr_image = generateQRCode($qr_data); // Function from qr_generator.php
                ?>
                <img src="<?php echo $qr_image; ?>" alt="QR Code" class="qr-image">
            </div>
            <div class="form-group">
                <label>Nomor Rekening Anda</label>
                <input type="text" value="<?php echo htmlspecialchars($no_rekening); ?>" readonly>
            </div>
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

            // Tab switching
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabs.forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById(`${tab.dataset.tab}-tab`).classList.add('active');
                });
            });

            // Form handling
            const qrForm = document.getElementById('qrForm');
            const submitBtn = document.getElementById('submitBtn');
            const qrCodeInput = document.getElementById('qr_code');
            const scanQrBtn = document.getElementById('scanQrBtn');
            const amountInput = document.getElementById('amount');
            const pinInput = document.getElementById('pin');
            const alertContainer = document.getElementById('alertContainer');
            let stream = null;
            let scanning = false;
            let isSubmitting = false;

            // Clear form inputs on load
            qrCodeInput.value = '';
            amountInput.value = '';
            pinInput.value = '';

            // Handle QR code input
            qrCodeInput.addEventListener('input', function() {
                this.value = this.value.trim();
                document.getElementById('qr_code-error').classList.remove('show');
            });

            qrCodeInput.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text');
                this.value = pastedData;
            });

            // Form submission
            qrForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (isSubmitting) return;
                isSubmitting = true;

                const qrCode = qrCodeInput.value.trim();
                const amount = parseFloat(amountInput.value);
                const pin = pinInput.value;

                if (!qrCode) {
                    showAlert('Kode QR tidak boleh kosong!', 'error');
                    qrCodeInput.classList.add('form-error');
                    setTimeout(() => qrCodeInput.classList.remove('form-error'), 400);
                    qrCodeInput.focus();
                    isSubmitting = false;
                    return;
                }

                if (!amount || amount < 1000) {
                    showAlert('Jumlah transfer harus minimal Rp 1.000!', 'error');
                    amountInput.classList.add('form-error');
                    setTimeout(() => amountInput.classList.remove('form-error'), 400);
                    amountInput.focus();
                    isSubmitting = false;
                    return;
                }

                if (!pin || pin.length !== 6) {
                    showAlert('PIN harus 6 digit!', 'error');
                    pinInput.classList.add('form-error');
                    setTimeout(() => pinInput.classList.remove('form-error'), 400);
                    pinInput.focus();
                    isSubmitting = false;
                    return;
                }

                submitBtn.classList.add('loading');
                submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
                setTimeout(() => {
                    qrForm.submit();
                }, 1000);
            });

            // QR Code Scanning
            scanQrBtn.addEventListener('click', function() {
                if (scanning) return;
                scanning = true;

                const modal = document.createElement('div');
                modal.className = 'modal-overlay';
                modal.id = 'qr-modal-' + Date.now();
                modal.innerHTML = `
                    <div class="qr-modal">
                        <div class="qr-icon">
                            <i class="fas fa-qrcode"></i>
                        </div>
                        <h3>Scan QR Code</h3>
                        <p>Arahkan kamera ke QR code transaksi</p>
                        <video id="qr-video" autoplay playsinline></video>
                        <canvas id="qr-canvas" style="display: none;"></canvas>
                        <button class="btn" id="closeQrBtn">
                            <span class="btn-content"><i class="fas fa-times"></i> Tutup</span>
                        </button>
                    </div>
                `;
                alertContainer.appendChild(modal);

                const video = document.getElementById('qr-video');
                const canvas = document.getElementById('qr-canvas');
                const canvasContext = canvas.getContext('2d', { willReadFrequently: true });
                const closeQrBtn = document.getElementById('closeQrBtn');

                // Start camera
                navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' }
                }).then(function(s) {
                    stream = s;
                    video.srcObject = stream;
                    video.play();
                    scanQrCode();
                }).catch(function(err) {
                    showAlert('Gagal mengakses kamera: ' + err.message, 'error');
                    closeModal(modal.id);
                    scanning = false;
                });

                // QR code scanning function
                function scanQrCode() {
                    if (!scanning) return;
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                        canvas.height = video.videoHeight;
                        canvas.width = video.videoWidth;
                        canvasContext.drawImage(video, 0, 0, canvas.width, canvas.height);
                        const imageData = canvasContext.getImageData(0, 0, canvas.width, canvas.height);
                        const code = jsQR(imageData.data, imageData.width, imageData.height, {
                            inversionAttempts: 'dontInvert'
                        });

                        if (code) {
                            try {
                                const qrData = JSON.parse(code.data);
                                if (qrData.no_rekening) {
                                    qrCodeInput.value = code.data;
                                    showAlert('QR Code berhasil discan!', 'success');
                                    closeModal(modal.id);
                                    stopCamera();
                                    amountInput.focus();
                                    return;
                                } else {
                                    showAlert('QR Code tidak valid: Tidak mengandung nomor rekening!', 'error');
                                }
                            } catch (e) {
                                showAlert('QR Code tidak valid: Format JSON salah!', 'error');
                            }
                        }
                    }
                    requestAnimationFrame(scanQrCode);
                }

                // Close QR modal
                closeQrBtn.addEventListener('click', function() {
                    closeModal(modal.id);
                    stopCamera();
                });
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(modal.id);
                        stopCamera();
                    }
                });

                // Stop camera function
                function stopCamera() {
                    if (stream) {
                        stream.getTracks().forEach(track => track.stop());
                        stream = null;
                    }
                    scanning = false;
                }
            });

            // Show error or success popup
            <?php if ($show_error_popup): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($error_message); ?>', 'error');
                    document.getElementById('qr_code').value = '';
                    document.getElementById('amount').value = '';
                    document.getElementById('pin').value = '';
                }, 500);
            <?php endif; ?>
            <?php if ($success_message): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($success_message); ?>', 'success');
                    document.getElementById('qr_code').value = '';
                    document.getElementById('amount').value = '';
                    document.getElementById('pin').value = '';
                }, 500);
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

            // Enter key support
            pinInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    submitBtn.click();
                }
            });

            // Focus on QR code input
            qrCodeInput.focus();
        });
    </script>
</body>
</html>