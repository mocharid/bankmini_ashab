<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Configuration: Set to true if PINs are stored as SHA-256 hashes, false for plain text
$use_sha256_pin = false; // Change to true if your database uses SHA-256 hashed PINs

// Fetch user data
$query = "SELECT u.nama, u.email, u.has_pin, u.pin, u.is_frozen, u.failed_pin_attempts, u.pin_block_until, r.no_rekening, r.id AS rekening_id, r.saldo 
          FROM users u 
          JOIN rekening r ON u.id = r.user_id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();

if (!$user_data || !$user_data['has_pin']) {
    header("Location: dashboard.php");
    exit();
}

// Initialize popup variables
$show_error_popup = false;
$error_message = '';
$show_success_popup = false;
$success_message = '';
$form_token = bin2hex(random_bytes(32));
$_SESSION['qr_payment_form_token'] = $form_token;

// Clear previous transaction result on page load
unset($_SESSION['transaction_result']);

// Check for transaction result in session (set only after a transaction attempt)
if (isset($_SESSION['transaction_result'])) {
    if ($_SESSION['transaction_result']['status'] === 'error') {
        $show_error_popup = true;
        $error_message = $_SESSION['transaction_result']['message'];
    } elseif ($_SESSION['transaction_result']['status'] === 'success') {
        $show_success_popup = true;
        $success_message = $_SESSION['transaction_result']['message'];
    }
    // Clear the transaction result after capturing it
    unset($_SESSION['transaction_result']);
}

// Check if account is frozen or PIN is blocked (set error only for transaction attempts)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_pin_validation'])) {
    if ($user_data['is_frozen']) {
        $_SESSION['transaction_result'] = [
            'status' => 'error',
            'message' => 'Akun Anda sedang dibekukan. Silakan hubungi petugas.'
        ];
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $_SESSION['transaction_result']['message'], 'blocked' => true]);
        exit();
    } elseif ($user_data['pin_block_until'] && new DateTime() < new DateTime($user_data['pin_block_until'])) {
        $_SESSION['transaction_result'] = [
            'status' => 'error',
            'message' => 'PIN Anda diblokir hingga ' . date('d-m-Y H:i:s', strtotime($user_data['pin_block_until'])) . '.'
        ];
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $_SESSION['transaction_result']['message'], 'blocked' => true]);
        exit();
    }
}

// Handle form submission for payment via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_pin_validation'])) {
    header('Content-Type: application/json');
    $no_rekening_tujuan = trim($_POST['no_rekening_tujuan'] ?? '');
    $jumlah = trim($_POST['jumlah_raw'] ?? '');
    $pin = trim($_POST['pin'] ?? '');

    // Remove any non-numeric characters for processing
    $jumlah = preg_replace('/[^0-9]/', '', $jumlah);

    // Validate input
    if (empty($no_rekening_tujuan) || empty($jumlah) || empty($pin)) {
        echo json_encode(['success' => false, 'message' => 'Nomor rekening tujuan, nominal, dan PIN harus diisi.']);
        exit();
    } elseif (!is_numeric($jumlah) || $jumlah <= 0) {
        echo json_encode(['success' => false, 'message' => 'Nominal harus berupa angka positif.']);
        exit();
    } elseif ($jumlah > $user_data['saldo']) {
        echo json_encode(['success' => false, 'message' => 'Saldo tidak mencukupi.']);
        exit();
    } else {
        // Verify PIN
        $entered_pin = $use_sha256_pin ? hash('sha256', $pin) : $pin;
        if ($entered_pin !== $user_data['pin']) {
            $failed_attempts = $user_data['failed_pin_attempts'] + 1;
            $max_attempts = 3;
            $block_duration = 5 * 60; // 5 minutes in seconds

            // Update failed attempts
            $query = "UPDATE users SET failed_pin_attempts = ? WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ii", $failed_attempts, $user_id);
            $stmt->execute();
            $stmt->close();

            if ($failed_attempts >= $max_attempts) {
                $block_until = date('Y-m-d H:i:s', time() + $block_duration);
                $query = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("si", $block_until, $user_id);
                $stmt->execute();
                $stmt->close();
                $_SESSION['transaction_result'] = [
                    'status' => 'error',
                    'message' => 'PIN salah. Akun Anda diblokir hingga ' . date('d-m-Y H:i:s', strtotime($block_until)) . '.'
                ];
                echo json_encode(['success' => false, 'message' => $_SESSION['transaction_result']['message'], 'blocked' => true]);
                exit();
            } else {
                $attempts_left = $max_attempts - $failed_attempts;
                $message = 'PIN salah. Sisa percobaan: ' . $attempts_left . '.';
                if ($attempts_left === 1) {
                    $message .= ' Jika salah lagi, PIN akan diblokir sementara.';
                }
                echo json_encode(['success' => false, 'message' => $message, 'attempts_left' => $attempts_left]);
                exit();
            }
        } else {
            // Reset failed attempts on successful PIN
            $query = "UPDATE users SET failed_pin_attempts = 0 WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();

            // Check if destination account exists
            $query = "SELECT r.id, r.saldo, r.user_id, u.nama 
                      FROM rekening r 
                      JOIN users u ON r.user_id = u.id 
                      WHERE r.no_rekening = ? AND r.user_id != ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $no_rekening_tujuan, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $tujuan_data = $result->fetch_assoc();
            $stmt->close();

            if (!$tujuan_data) {
                echo json_encode(['success' => false, 'message' => 'Nomor rekening tujuan tidak ditemukan.']);
                exit();
            } else {
                // Generate unique transaction number
                $no_transaksi = 'SQR' . date('YmdHis') . rand(100, 999);
                $jumlah = (float)$jumlah;

                // Begin transaction
                $conn->begin_transaction();
                try {
                    // Insert transaction
                    $query = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, status, rekening_tujuan_id) 
                              VALUES (?, ?, 'transaksi_qr', ?, 'approved', ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("sidi", $no_transaksi, $user_data['rekening_id'], $jumlah, $tujuan_data['id']);
                    $stmt->execute();
                    $transaksi_id = $conn->insert_id;
                    $stmt->close();

                    // Update sender's balance
                    $new_saldo_pengirim = $user_data['saldo'] - $jumlah;
                    $query = "UPDATE rekening SET saldo = ? WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("di", $new_saldo_pengirim, $user_data['rekening_id']);
                    $stmt->execute();
                    $stmt->close();

                    // Update receiver's balance
                    $new_saldo_penerima = $tujuan_data['saldo'] + $jumlah;
                    $query = "UPDATE rekening SET saldo = ? WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("di", $new_saldo_penerima, $tujuan_data['id']);
                    $stmt->execute();
                    $stmt->close();

                    // Insert mutation for sender
                    $query = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $jumlah_negatif = -$jumlah;
                    $stmt->bind_param("iidd", $transaksi_id, $user_data['rekening_id'], $jumlah_negatif, $new_saldo_pengirim);
                    $stmt->execute();
                    $stmt->close();

                    // Insert mutation for receiver
                    $query = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("iidd", $transaksi_id, $tujuan_data['id'], $jumlah, $new_saldo_penerima);
                    $stmt->execute();
                    $stmt->close();

                    // Insert notification for sender
                    $message = "Yey, transaksi QR sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke " . $tujuan_data['nama'] . " berhasil dilakukan. Cek saldo kamu yu!";
                    $query = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("is", $user_id, $message);
                    $stmt->execute();
                    $stmt->close();

                    // Insert notification for receiver
                    $message = "Yey, kamu menerima saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari " . $user_data['nama'] . ".";
                    $query = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("is", $tujuan_data['user_id'], $message);
                    $stmt->execute();
                    $stmt->close();

                    // Commit transaction
                    $conn->commit();
                    $_SESSION['transaction_result'] = [
                        'status' => 'success',
                        'message' => "Transaksi QR sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke " . $tujuan_data['nama'] . " berhasil!"
                    ];
                    echo json_encode(['success' => true, 'redirect' => 'struk_transaksi.php?no_transaksi=' . urlencode($no_transaksi)]);
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['transaction_result'] = [
                        'status' => 'error',
                        'message' => 'Gagal melakukan transaksi QR: ' . $e->getMessage()
                    ];
                    echo json_encode(['success' => false, 'message' => $_SESSION['transaction_result']['message']]);
                    exit();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaksi QR - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
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
            --success-color: #34a853;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --button-bg: #1e3a8a;
            --button-bg-hover: #1e1b4b;
            --button-text: #ffffff;
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
            color: var(--button-text);
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

        .back-btn:active {
            transform: scale(0.95);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
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
            width: 100%;
            max-width: 600px;
            text-align: center;
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
            justify-content: center;
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

        .mode-selection {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
            width: 100%;
            max-width: 600px;
        }

        .mode-box {
            background: var(--bg-table);
            border: 1px solid var(--border-color);
            border-radius: 15px;
            padding: 20px;
            cursor: pointer;
            width: 100%;
            max-width: 300px;
            text-align: center;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .mode-box:hover {
            background: var(--button-bg);
            color: var(--button-text);
            border-color: var(--button-bg);
            transform: translateY(-2px);
        }

        .mode-box:active {
            transform: scale(0.98);
        }

        .mode-box i {
            font-size: 2rem;
        }

        .mode-box.active {
            background: var(--button-bg);
            color: var(--button-text);
            border-color: var(--button-bg);
        }

        .qr-section {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
            display: none;
            width: 100%;
            max-width: 400px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .qr-section.active {
            display: flex;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .qr-content {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .qr-content.active {
            display: flex;
            animation: fadeInContent 0.5s ease-out;
        }

        @keyframes fadeInContent {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .qr-code {
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 20px auto;
            padding: 10px;
            background: var(--bg-light);
            border-radius: 10px;
            box-shadow: var(--shadow-sm);
        }

        .qr-code canvas {
            width: clamp(180px, 50vw, 250px);
            height: clamp(180px, 50vw, 250px);
            border: 5px solid var(--bg-light);
            border-radius: 10px;
        }

        .qr-info {
            text-align: center;
            margin-top: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-secondary);
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

        .success-modal, .error-modal, .amount-modal, .pin-modal, .qr-modal {
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

        .success-modal::before, .error-modal::before, .amount-modal::before, .pin-modal::before, .qr-modal::before {
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

        .success-icon, .error-icon, .amount-icon, .pin-icon, .qr-icon {
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

        .success-modal h3, .error-modal h3, .amount-modal h3, .pin-modal h3, .qr-modal h3 {
            color: white;
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
            letter-spacing: 0.02em;
            margin-bottom: 8px;
        }

        .success-modal p, .error-modal p, .amount-modal p, .pin-modal p, .qr-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            line-height: 1.5;
            padding: 0 10px;
        }

        .amount-modal form, .pin-modal form {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 15px;
        }

        .amount-modal .form-group, .pin-modal .form-group {
            position: relative;
        }

        .amount-modal input[type="text"],
        .amount-modal input[type="number"] {
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

        .amount-modal input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .pin-modal .pin-inputs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .pin-modal input[type="password"] {
            width: 40px;
            height: 40px;
            text-align: center;
            font-size: 1.2rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: var(--transition);
            background-color: #fff;
            -webkit-user-select: text;
            user-select: text;
        }

        .pin-modal input[type="password"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .pin-modal input[type="password"].error {
            border-color: var(--error-color);
        }

        .amount-modal .error-message, .pin-modal .error-message {
            color: var(--error-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .amount-modal .error-message.show, .pin-modal .error-message.show {
            display: block;
        }

        .btn {
            background: var(--button-bg);
            color: var(--button-text);
            border: none;
            padding: 12px 20px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            background: var(--button-bg-hover);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 3px solid var(--button-text);
            border-top: 3px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .qr-modal video {
            width: 100%;
            border-radius: 8px;
            margin-top: 10px;
        }

        .form-error {
            animation: shake 0.8s ease;
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

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .mode-selection {
                flex-direction: column;
                align-items: center;
            }

            .mode-box {
                width: 100%;
                max-width: 300px;
            }

            .qr-section {
                max-width: 350px;
            }

            .qr-code canvas {
                width: clamp(150px, 45vw, 200px);
                height: clamp(150px, 45vw, 200px);
            }

            .success-modal, .error-modal, .amount-modal, .pin-modal, .qr-modal {
                width: clamp(260px, 80vw, 340px);
                padding: clamp(12px, 3vw, 15px);
            }

            .success-icon, .error-icon, .amount-icon, .pin-icon, .qr-icon {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .pin-modal input[type="password"] {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .qr-section {
                max-width: 300px;
            }

            .qr-code canvas {
                width: clamp(120px, 40vw, 160px);
                height: clamp(120px, 40vw, 160px);
            }

            .success-modal, .error-modal, .amount-modal, .pin-modal, .qr-modal {
                width: clamp(240px, 85vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
            }

            .pin-modal input[type="password"] {
                width: 30px;
                height: 30px;
                font-size: 0.9rem;
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
            <h2><i class="fas fa-qrcode"></i> Transaksi QR</h2>
            <p>Pilih untuk memindai QR code atau menampilkan QR code untuk transaksi</p>
        </div>

        <div id="alertContainer"></div>

        <div class="mode-selection">
            <div class="mode-box scan-mode" data-mode="scan">
                <i class="fas fa-camera"></i>
                <span>Scan QR Code</span>
            </div>
            <div class="mode-box generate-mode" data-mode="generate">
                <i class="fas fa-qrcode"></i>
                <span>Tampilkan QR Code</span>
            </div>
        </div>

        <div class="qr-section" id="generate-qr">
            <div class="qr-content" id="qrContent">
                <div class="qr-code" id="qrcode"></div>
                <div class="qr-info">
                    <p>Nomor Rekening: <?php echo htmlspecialchars($user_data['no_rekening']); ?></p>
                    <p>Nama: <?php echo htmlspecialchars($user_data['nama']); ?></p>
                </div>
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

            const generateQr = document.getElementById('generate-qr');
            const qrContent = document.getElementById('qrContent');
            const alertContainer = document.getElementById('alertContainer');
            const modeBoxes = document.querySelectorAll('.mode-box');
            let stream = null;
            let scanning = false;

            // Function to format number to Rupiah
            function formatRupiah(angka) {
                let number_string = angka.toString().replace(/[^,\d]/g, ''),
                    split = number_string.split(','),
                    sisa = split[0].length % 3,
                    rupiah = split[0].substr(0, sisa),
                    ribuan = split[0].substr(sisa).match(/\d{3}/gi);

                if (ribuan) {
                    separator = sisa ? '.' : '';
                    rupiah += separator + ribuan.join('.');
                }

                return rupiah ? rupiah : '';
            }

            // Function to clean Rupiah format to numeric
            function cleanRupiah(rupiah) {
                return rupiah.replace(/[^0-9]/g, '');
            }

            // Helper function for clamping values
            function clamp(min, val, max) {
                return Math.min(Math.max(val, min), max);
            }

            // Generate QR code for receiving payments
            function generateQRCode() {
                const qrContainer = document.getElementById('qrcode');
                qrContainer.innerHTML = '';
                new QRCode(qrContainer, {
                    text: "<?php echo htmlspecialchars($user_data['no_rekening']); ?>",
                    width: clamp(180, 50 * window.innerWidth / 100, 250),
                    height: clamp(180, 50 * window.innerWidth / 100, 250),
                    colorDark: "#000000",
                    colorLight: "#ffffff",
                    correctLevel: QRCode.CorrectLevel.H
                });
            }

            // Mode selection
            modeBoxes.forEach(box => {
                box.addEventListener('click', function() {
                    modeBoxes.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    const mode = this.dataset.mode;
                    generateQr.classList.toggle('active', mode === 'generate');
                    qrContent.classList.remove('active'); // Hide QR content when switching modes
                    if (mode === 'generate') {
                        qrContent.classList.add('active');
                        generateQRCode();
                    } else if (mode === 'scan') {
                        startQrScan();
                    }
                });
            });

            // QR Code Scanning
            function startQrScan() {
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
                        <p>Arahkan kamera ke QR code rekening tujuan</p>
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

                        if (code && code.data.length <= 20) {
                            // Fetch recipient's name via AJAX
                            $.ajax({
                                url: 'get_recipient_name.php',
                                method: 'POST',
                                data: { no_rekening: code.data },
                                dataType: 'json',
                                success: function(response) {
                                    if (response.success) {
                                        showAmountModal(code.data, response.nama);
                                    } else {
                                        showAlert('Nomor rekening tidak ditemukan.', 'error');
                                    }
                                    closeModal(modal.id);
                                    stopCamera();
                                },
                                error: function() {
                                    showAlert('Gagal memverifikasi nomor rekening.', 'error');
                                    closeModal(modal.id);
                                    stopCamera();
                                }
                            });
                            return;
                        } else if (code) {
                            showAlert('QR Code tidak valid. Maksimum 20 karakter.', 'error');
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
            }

            // Amount input modal
            function showAmountModal(noRekening, namaPenerima) {
                const modal = document.createElement('div');
                modal.className = 'modal-overlay';
                modal.id = 'amount-modal-' + Date.now();
                modal.innerHTML = `
                    <div class="amount-modal">
                        <div class="amount-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3>Masukkan Nominal</h3>
                        <p>Transaksi QR ke: ${namaPenerima}</p>
                        <form id="amountForm">
                            <input type="hidden" name="no_rekening_tujuan" value="${noRekening}">
                            <input type="hidden" name="jumlah_raw" id="jumlah_raw">
                            <div class="form-group">
                                <input type="text" id="jumlah" placeholder="0" required inputmode="numeric">
                                <span class="error-message" id="jumlah-error"></span>
                            </div>
                            <button type="submit" class="btn" id="submitAmountBtn">
                                <span class="btn-content"><i class="fas fa-arrow-right"></i> Lanjut</span>
                            </button>
                        </form>
                    </div>
                `;
                alertContainer.appendChild(modal);

                const amountForm = document.getElementById('amountForm');
                const jumlahInput = document.getElementById('jumlah');
                const jumlahRawInput = document.getElementById('jumlah_raw');
                const submitAmountBtn = document.getElementById('submitAmountBtn');
                let isSubmitting = false;

                // Format input as Rupiah
                jumlahInput.addEventListener('input', function() {
                    let value = cleanRupiah(this.value);
                    if (value && !isNaN(value) && value > 0) {
                        this.value = formatRupiah(value);
                        jumlahRawInput.value = value;
                        document.getElementById('jumlah-error').classList.remove('show');
                    } else {
                        this.value = '';
                        jumlahRawInput.value = '';
                    }
                });

                // Ensure numeric input only
                jumlahInput.addEventListener('keypress', function(e) {
                    const charCode = e.which ? e.which : e.keyCode;
                    if (charCode < 48 || charCode > 57) {
                        e.preventDefault();
                    }
                });

                amountForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    isSubmitting = true;

                    const jumlah = parseFloat(jumlahRawInput.value);

                    if (!jumlah || jumlah <= 0) {
                        showAlert('Nominal harus angka positif.', 'error');
                        jumlahInput.classList.add('form-error');
                        setTimeout(() => jumlahInput.classList.remove('form-error'), 800);
                        document.getElementById('jumlah-error').classList.add('show');
                        document.getElementById('jumlah-error').textContent = 'Nominal harus angka positif.';
                        isSubmitting = false;
                        return;
                    }

                    submitAmountBtn.classList.add('loading');
                    setTimeout(() => {
                        closeModal(modal.id);
                        showPinModal(noRekening, namaPenerima, jumlah);
                        isSubmitting = false;
                    }, 1000);
                });

                jumlahInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        submitAmountBtn.click();
                    }
                });

                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(modal.id);
                    }
                });
            }

            // PIN input modal
            function showPinModal(noRekening, namaPenerima, jumlah) {
                const modal = document.createElement('div');
                modal.className = 'modal-overlay';
                modal.id = 'pin-modal-' + Date.now();
                modal.innerHTML = `
                    <div class="pin-modal">
                        <div class="pin-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3>Masukkan PIN</h3>
                        <p>Transaksi QR ke: ${namaPenerima}</p>
                        <p>Nominal: Rp ${formatRupiah(jumlah)}</p>
                        <form id="pinForm">
                            <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($form_token); ?>">
                            <input type="hidden" name="no_rekening_tujuan" value="${noRekening}">
                            <input type="hidden" name="jumlah_raw" value="${jumlah}">
                            <input type="hidden" name="pin" id="pin">
                            <div class="form-group">
                                <div class="pin-inputs">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                    <input type="password" maxlength="1" class="pin-input" inputmode="numeric">
                                </div>
                                <span class="error-message" id="pin-error"></span>
                            </div>
                            <button type="submit" class="btn" id="submitPinBtn">
                                <span class="btn-content"><i class="fas fa-paper-plane"></i> Kirim</span>
                            </button>
                        </form>
                    </div>
                `;
                alertContainer.appendChild(modal);

                const pinForm = document.getElementById('pinForm');
                const pinInputs = document.querySelectorAll('.pin-input');
                const pinHiddenInput = document.getElementById('pin');
                const submitPinBtn = document.getElementById('submitPinBtn');
                const pinError = document.getElementById('pin-error');
                let isSubmitting = false;
                let clearTimeoutId = null;

                // Handle PIN input
                pinInputs.forEach((input, index) => {
                    input.addEventListener('input', function() {
                        if (this.value.length === 1 && index < pinInputs.length - 1) {
                            pinInputs[index + 1].focus();
                        }
                        updatePinValue();
                    });

                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && this.value === '' && index > 0) {
                            pinInputs[index - 1].focus();
                        }
                    });

                    input.addEventListener('keypress', function(e) {
                        const charCode = e.which ? e.which : e.keyCode;
                        if (charCode < 48 || charCode > 57) {
                            e.preventDefault();
                        }
                    });

                    // Handle paste event
                    input.addEventListener('paste', function(e) {
                        e.preventDefault();
                        const pastedData = e.clipboardData.getData('text').replace(/\D/g, '');
                        if (pastedData.length > 0) {
                            for (let i = 0; i < Math.min(pastedData.length, pinInputs.length); i++) {
                                pinInputs[i].value = pastedData[i] || '';
                            }
                            updatePinValue();
                            if (pastedData.length >= pinInputs.length) {
                                submitPinBtn.focus();
                            } else {
                                pinInputs[Math.min(pastedData.length, pinInputs.length - 1)].focus();
                            }
                        }
                    });
                });

                // Update hidden PIN input and clear error on new input
                function updatePinValue() {
                    const pinValue = Array.from(pinInputs).map(input => input.value).join('');
                    pinHiddenInput.value = pinValue;
                    if (pinValue.length > 0) {
                        pinError.classList.remove('show');
                        pinInputs.forEach(input => input.classList.remove('error', 'form-error'));
                        if (clearTimeoutId) {
                            clearTimeout(clearTimeoutId);
                            clearTimeoutId = null;
                        }
                    }
                    if (pinValue.length === 6) {
                        pinError.classList.remove('show');
                        pinInputs.forEach(input => input.classList.remove('error', 'form-error'));
                    }
                }

                // Clear PIN inputs
                function clearPinInputs() {
                    pinInputs.forEach(input => {
                        input.value = '';
                        input.classList.remove('error', 'form-error');
                    });
                    pinHiddenInput.value = '';
                    pinError.classList.remove('show');
                    pinInputs[0].focus();
                }

                pinForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    isSubmitting = true;

                    const pin = pinHiddenInput.value;

                    if (pin.length !== 6 || !/^\d{6}$/.test(pin)) {
                        pinError.classList.add('show');
                        pinError.textContent = 'PIN harus 6 digit angka.';
                        pinInputs.forEach(input => {
                            input.classList.add('error', 'form-error');
                        });
                        clearTimeoutId = setTimeout(() => {
                            pinInputs.forEach(input => input.classList.remove('form-error'));
                            clearPinInputs();
                        }, 15000); // 15 seconds
                        isSubmitting = false;
                        return;
                    }

                    submitPinBtn.classList.add('loading');

                    // Validate PIN via AJAX
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            ajax_pin_validation: 1,
                            form_token: '<?php echo htmlspecialchars($form_token); ?>',
                            no_rekening_tujuan: noRekening,
                            jumlah_raw: jumlah,
                            pin: pin
                        },
                        dataType: 'json',
                        success: function(response) {
                            submitPinBtn.classList.remove('loading');
                            isSubmitting = false;

                            if (response.success) {
                                closeModal(modal.id);
                                window.location.href = response.redirect;
                            } else {
                                pinError.classList.add('show');
                                pinError.textContent = response.message;
                                pinInputs.forEach(input => {
                                    input.classList.add('error', 'form-error');
                                });
                                clearTimeoutId = setTimeout(() => {
                                    pinInputs.forEach(input => input.classList.remove('form-error'));
                                    clearPinInputs();
                                }, 15000); // 15 seconds
                                if (response.blocked) {
                                    closeModal(modal.id);
                                    showAlert(response.message, 'error');
                                }
                            }
                        },
                        error: function() {
                            submitPinBtn.classList.remove('loading');
                            isSubmitting = false;
                            pinError.classList.add('show');
                            pinError.textContent = 'Gagal memvalidasi PIN. Coba lagi.';
                            pinInputs.forEach(input => {
                                input.classList.add('error', 'form-error');
                            });
                            clearTimeoutId = setTimeout(() => {
                                pinInputs.forEach(input => input.classList.remove('form-error'));
                                clearPinInputs();
                            }, 15000); // 15 seconds
                        }
                    });
                });

                // Handle modal close (clicking outside)
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal(modal.id);
                    }
                });

                pinInputs[0].focus();
            }

            // Show alerts
            <?php if ($show_error_popup): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($error_message); ?>', 'error');
                }, 500);
            <?php endif; ?>
            <?php if ($show_success_popup): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($success_message); ?>', 'success');
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
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>