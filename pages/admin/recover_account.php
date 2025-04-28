<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set zona waktu
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../index.php');
    exit();
}

// Fungsi untuk menghasilkan token
function generateToken() {
    return bin2hex(random_bytes(32));
}

// Fungsi untuk menyimpan token reset
function saveResetToken($conn, $user_id, $token, $recovery_type, $new_email = null, $expiry_hours = 24) {
    $expiry = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
    $query = "INSERT INTO password_reset (user_id, token, recovery_type, new_email, expiry) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('issss', $user_id, $token, $recovery_type, $new_email, $expiry);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Gagal menyimpan token: user_id=$user_id, token=$token, type=$recovery_type, new_email=$new_email, error=" . $conn->error);
    }
    return $success;
}

// Fungsi untuk mencatat aktivitas
function logActivity($conn, $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan) {
    $query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iisss', $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Gagal mencatat aktivitas: petugas_id=$petugas_id, siswa_id=$siswa_id, type=$jenis_pemulihan, error=" . $conn->error);
    }
    return $success;
}

// Fungsi untuk mengirim email
function sendEmail($toEmail, $toName, $subject, $htmlContent) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = strip_tags($htmlContent);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Gagal mengirim email ke $toEmail: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk membuat template email
function generateEmailTemplate($title, $greeting, $content, $buttonText = null, $buttonLink = null) {
    $buttonHtml = $buttonText && $buttonLink ? "
        <div class=\"button-container\">
            <a href=\"{$buttonLink}\" class=\"btn\">{$buttonText}</a>
        </div>" : '';

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
        <title>{$title}</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                background-color: #f5f5f5;
                margin: 0;
                padding: 20px;
            }
            .container { 
                max-width: 600px; 
                margin: 0 auto; 
                padding: 30px; 
                border: 1px solid #ddd; 
                border-radius: 8px;
                background-color: white;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            h2 { 
                color: #0a2e5c; 
                border-bottom: 2px solid #0a2e5c; 
                padding-bottom: 10px; 
                margin-top: 0;
                text-align: center;
            }
            .btn { 
                display: inline-block; 
                background: #0a2e5c; 
                color: white; 
                padding: 10px 20px; 
                text-decoration: none; 
                border-radius: 5px;
                margin-top: 15px;
            }
            .button-container {
                text-align: center;
                margin: 20px 0;
            }
            .security-notice {
                background-color: #fff8e1;
                border-left: 4px solid #f39c12;
                padding: 10px 15px;
                margin: 20px 0;
                font-size: 0.9em;
                color: #7f8c8d;
            }
            .footer { 
                margin-top: 30px; 
                font-size: 0.8em; 
                color: #666; 
                border-top: 1px solid #ddd; 
                padding-top: 15px; 
                text-align: center;
            }
            p {
                color: #34495e;
            }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <h2>{$title}</h2>
            <p>{$greeting}</p>
            <p>{$content}</p>
            {$buttonHtml}
            <div class=\"security-notice\">
                <strong>Perhatian Keamanan:</strong> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain. Petugas SCHOBANK tidak akan meminta informasi tersebut melalui email atau telepon.
            </div>
            <div class=\"footer\">
                <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                <p>Jika Anda memiliki pertanyaan, silakan hubungi Bank Mini SMK Plus Ashabulyamin.</p>
                <p>© " . date('Y') . " SCHOBANK SYSTEM - Hak cipta dilindungi.</p>
            </div>
        </div>
    </body>
    </html>";
}

// Inisialisasi array pesan
$messages = ['success' => '', 'errors' => []];

// Cek pesan dari session
if (isset($_SESSION['messages'])) {
    $messages = $_SESSION['messages'];
    unset($_SESSION['messages']); // Hapus pesan setelah ditampilkan
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recovery_option = $_POST['recovery_option'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $recovery_type = $_POST['recovery_type'] ?? '';
    $new_email = trim($_POST['new_email'] ?? '');
    $alasan = trim($_POST['alasan'] ?? '');

    if (empty($recovery_option) || !in_array($recovery_option, ['reset', 'email'])) {
        $messages['errors'][] = 'Pilihan pemulihan tidak valid.';
    }
    if (empty($no_rekening)) {
        $messages['errors'][] = 'Nomor rekening harus diisi.';
    } elseif (!preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
        $messages['errors'][] = 'Nomor rekening harus berformat REK diikuti 6 digit angka.';
    }
    if ($recovery_option === 'reset' && (empty($recovery_type) || !in_array($recovery_type, ['password', 'pin', 'username']))) {
        $messages['errors'][] = 'Jenis pemulihan tidak valid.';
    }
    if ($recovery_option === 'email' && empty($new_email)) {
        $messages['errors'][] = 'Email baru harus diisi.';
    }
    if (empty($alasan) || !in_array($alasan, ['Lupa kata sandi', 'Lupa PIN', 'Lupa username', 'Perubahan email', 'Lainnya'])) {
        $messages['errors'][] = 'Alasan pemulihan tidak valid.';
    }

    if (empty($messages['errors'])) {
        // Cek nomor rekening
        $query = "SELECT u.id, u.nama, u.email, u.role FROM users u 
                  JOIN rekening r ON u.id = r.user_id 
                  WHERE r.no_rekening = ? AND u.role = 'siswa'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            $messages['errors'][] = 'Nomor rekening tidak ditemukan atau bukan akun siswa.';
            error_log("Nomor rekening tidak ditemukan: no_rekening=$no_rekening");
        } else {
            $user = $result->fetch_assoc();
            $user_id = $user['id'];
            $nama = $user['nama'];
            $email = $user['email'];

            if ($recovery_option === 'reset') {
                // Untuk pemulihan username, password, pin
                if (empty($email)) {
                    $messages['errors'][] = 'Akun ini tidak memiliki email. Silakan gunakan pemulihan email terlebih dahulu.';
                } else {
                    // Generate token dan simpan
                    $token = generateToken();
                    if (saveResetToken($conn, $user_id, $token, $recovery_type)) {
                        // Log aktivitas
                        $nilai_baru = 'Link reset dikirim';
                        logActivity($conn, $_SESSION['user_id'], $user_id, $recovery_type, $nilai_baru, $alasan);

                        // Kirim email ke email lama
                        $reset_link = "http://localhost/Schobank/pages/reset_account.php?token=$token&type=$recovery_type";
                        $subject = "Pemulihan Akun - SCHOBANK SYSTEM";
                        $greeting = "Yth. $nama,";
                        $content = "Admin telah memulai proses pemulihan $recovery_type Anda. Silakan klik tombol di bawah untuk mengatur ulang $recovery_type Anda.";
                        $html = generateEmailTemplate(
                            "Pemulihan Akun",
                            $greeting,
                            $content,
                            "Atur Ulang Sekarang",
                            $reset_link
                        );

                        error_log("Mengirim email ke: $email dengan token: $token, type: $recovery_type, link: $reset_link");

                        if (sendEmail($email, $nama, $subject, $html)) {
                            $messages['success'] = "Link pemulihan telah dikirim ke email siswa.";
                        } else {
                            $messages['errors'][] = "Gagal mengirim email. Silakan coba lagi.";
                        }
                    } else {
                        $messages['errors'][] = "Gagal menyimpan token pemulihan.";
                    }
                }
            } else {
                // Untuk pemulihan email
                if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $messages['errors'][] = 'Email baru tidak valid.';
                } else {
                    // Generate token dan simpan email baru
                    $token = generateToken();
                    if (saveResetToken($conn, $user_id, $token, 'email', $new_email)) {
                        // Log aktivitas
                        $nilai_baru = $new_email;
                        logActivity($conn, $_SESSION['user_id'], $user_id, 'email', $nilai_baru, $alasan);

                        // Kirim email ke email baru
                        $reset_link = "http://localhost/Schobank/pages/reset_account.php?token=$token&type=email";
                        $subject = "Verifikasi Perubahan Email - SCHOBANK SYSTEM";
                        $greeting = "Yth. $nama,";
                        $content = "Admin telah memulai proses perubahan email Anda ke $new_email. Silakan klik tombol di bawah untuk memverifikasi identitas Anda dengan memilih salah satu dari username, password, PIN, atau email lama.";
                        $html = generateEmailTemplate(
                            "Verifikasi Perubahan Email",
                            $greeting,
                            $content,
                            "Verifikasi Sekarang",
                            $reset_link
                        );

                        error_log("Mengirim email ke: $new_email dengan token: $token, type: email, link: $reset_link");

                        if (sendEmail($new_email, $nama, $subject, $html)) {
                            $messages['success'] = "Link verifikasi telah dikirim ke email baru: $new_email.";
                        } else {
                            $messages['errors'][] = "Gagal mengirim email ke email baru. Silakan coba lagi.";
                        }
                    } else {
                        $messages['errors'][] = "Gagal menyimpan token pemulihan.";
                    }
                }
            }
        }
    }

    // Jika request adalah AJAX, kembalikan JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($messages);
        exit();
    }

    // Untuk non-AJAX, simpan pesan di session dan redirect
    $_SESSION['messages'] = $messages;
    header('Location: recover_account.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=0">
    <title>Pemulihan Akun - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 0.125rem 0.625rem rgba(0, 0, 0, 0.05);
            --shadow-md: 0 0.3125rem 0.9375rem rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --spacing-sm: 0.75rem;
            --spacing-md: 1.25rem;
            --spacing-lg: 2rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-text-size-adjust: 100%;
            -webkit-user-select: none;
            user-select: none;
        }

        html {
            -webkit-text-size-adjust: none;
            -moz-text-size-adjust: none;
            text-size-adjust: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.875rem, 2.5vw, 1rem);
            line-height: 1.5;
            touch-action: pan-y !important; /* Allow vertical scrolling, block pinch-zoom */
            zoom: 1 !important;
            transform: scale(1) !important;
            -webkit-text-size-adjust: 100% !important;
            -webkit-tap-highlight-color: transparent;
            overflow-y: auto; /* Enable vertical scrolling */
            overflow-x: hidden; /* Disable horizontal scrolling */
        }

        button, input, select, textarea, a, .btn {
            touch-action: none !important; /* Prevent zooming on interactive elements */
            -webkit-text-size-adjust: 100% !important;
            font-size: clamp(0.875rem, 2.5vw, 1rem) !important;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: var(--spacing-md) var(--spacing-lg);
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
        }

        .top-nav h1 {
            font-size: clamp(1.25rem, 3vw, 1.5rem);
            font-weight: 600;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: var(--spacing-sm);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: clamp(2rem, 5vw, 2.5rem);
            height: clamp(2rem, 5vw, 2.5rem);
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-0.125rem);
        }

        .main-content {
            flex: 1;
            padding: var(--spacing-lg);
            width: 100%;
            max-width: 75rem;
            margin: 0 auto;
            min-height: calc(100vh - 5rem); /* Ensure enough height for scrolling */
            display: flex;
            flex-direction: column;
            padding-bottom: var(--spacing-lg); /* Add bottom padding for scrollable space */
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: var(--spacing-lg);
            border-radius: 0.9375rem;
            margin-bottom: var(--spacing-lg);
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
            from { opacity: 0; transform: translateY(-1.25rem); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            font-size: clamp(1.25rem, 3.5vw, 1.75rem);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            position: relative;
            z-index: 1;
        }

        .form-card {
            background: white;
            border-radius: 0.9375rem;
            padding: var(--spacing-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-1.25rem); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-md);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: var(--spacing-sm);
            position: relative;
            width: 100%;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.8vw, 0.875rem);
        }

        input[type="text"],
        input[type="email"] {
            width: 100%;
            padding: var(--spacing-sm);
            border: 1px solid #ddd;
            border-radius: 0.625rem;
            font-size: clamp(0.875rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: #fff;
        }

        input[type="text"]:focus,
        input[type="email"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.1875rem rgba(12, 77, 162, 0.1);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .input-prefix {
            position: absolute;
            left: var(--spacing-sm);
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: clamp(0.875rem, 2vw, 1rem);
            pointer-events: none;
        }

        input.with-prefix {
            padding-left: 3rem;
        }

        button[type="submit"],
        .btn {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: 0.625rem;
            cursor: pointer;
            font-size: clamp(0.875rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: var(--spacing-sm);
            text-decoration: none;
            justify-content: center;
        }

        button[type="submit"]:hover,
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-0.125rem);
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

        .success-modal {
            background: white;
            border-radius: 1.25rem;
            padding: var(--spacing-lg);
            text-align: center;
            max-width: 90%;
            width: clamp(18.75rem, 80vw, 28.125rem);
            box-shadow: 0 0.9375rem 2.5rem rgba(0, 0, 0, 0.2);
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon,
        .error-icon {
            font-size: clamp(3rem, 8vw, 4rem);
            margin-bottom: var(--spacing-md);
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .error-icon {
            color: var(--danger-color);
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: var(--spacing-md);
            font-size: clamp(1.25rem, 3vw, 1.5rem);
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.875rem, 2.2vw, 1rem);
            margin-bottom: var(--spacing-md);
            line-height: 1.5;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        select {
            width: 100%;
            padding: var(--spacing-sm);
            font-size: clamp(0.875rem, 2vw, 1rem);
            color: #333;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 0.625rem;
            outline: none;
            appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg fill="%23333" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>');
            background-repeat: no-repeat;
            background-position: right var(--spacing-sm) center;
            background-size: 16px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            cursor: pointer;
        }

        select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.1875rem rgba(12, 77, 162, 0.1);
        }

        select:hover {
            border-color: #999;
        }

        select:invalid {
            color: #999;
        }

        select option {
            color: #333;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: var(--spacing-sm) var(--spacing-md);
            }

            .main-content {
                padding: var(--spacing-md);
                min-height: calc(100vh - 4rem); /* Adjust for smaller nav */
            }

            .welcome-banner h2 {
                font-size: clamp(1.125rem, 3vw, 1.5rem);
            }

            .form-card {
                padding: var(--spacing-md);
            }

            button[type="submit"],
            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.75rem, 2.5vw, 0.875rem);
            }

            .top-nav h1 {
                font-size: clamp(1rem, 2.5vw, 1.25rem);
            }

            .welcome-banner {
                padding: var(--spacing-md);
            }

            .success-modal h3 {
                font-size: clamp(1.125rem, 2.5vw, 1.25rem);
            }

            .success-modal p {
                font-size: clamp(0.75rem, 2vw, 0.875rem);
            }

            select {
                font-size: clamp(0.75rem, 2vw, 0.875rem);
                padding: var(--spacing-sm);
            }

            .main-content {
                min-height: calc(100vh - 3.5rem); 
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
        <div style="width: clamp(2rem, 5vw, 2.5rem);"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Pemulihan Akun Siswa</h2>
        </div>

        <div class="success-overlay" id="popupModal" style="display: none;">
            <div class="success-modal">
                <div class="popup-icon">
                    <i class="fas"></i>
                </div>
                <h3 class="popup-title"></h3>
                <p class="popup-message"></p>
            </div>
        </div>

        <div class="form-card">
            <form method="POST" action="" id="recovery-form">
                <div class="form-group">
                    <label for="recovery_option">Pilih Jenis Pemulihan</label>
                    <select id="recovery_option" name="recovery_option" required>
                        <option value="" disabled selected>Pilih Opsi</option>
                        <option value="reset">Reset Username/Password/PIN</option>
                        <option value="email">Ubah Email</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="no_rekening">Nomor Rekening</label>
                    <div class="input-container">
                        <span class="input-prefix">REK</span>
                        <input type="text" id="no_rekening" class="with-prefix" inputmode="numeric" pattern="[0-9]*" required placeholder="Masukkan 6 digit angka" maxlength="6" autocomplete="off">
                        <input type="hidden" id="no_rekening_hidden" name="no_rekening">
                    </div>
                </div>
                <div class="form-group" id="reset_group" style="display: none;">
                    <label for="recovery_type">Jenis Pemulihan</label>
                    <select id="recovery_type" name="recovery_type">
                        <option value="" disabled selected>Pilih Jenis</option>
                        <option value="password">Password</option>
                        <option value="pin">PIN</option>
                        <option value="username">Username</option>
                    </select>
                </div>
                <div class="form-group" id="email_group" style="display: none;">
                    <label for="new_email">Email Baru</label>
                    <input type="email" id="new_email" name="new_email" placeholder="Masukkan email baru">
                </div>
                <div class="form-group">
                    <label for="alasan">Alasan Pemulihan</label>
                    <select id="alasan" name="alasan" required>
                        <option value="" disabled selected>Pilih Alasan</option>
                        <option value="Lupa kata sandi">Lupa kata sandi</option>
                        <option value="Lupa PIN">Lupa PIN</option>
                        <option value="Lupa username">Lupa username</option>
                        <option value="Perubahan email">Perubahan email</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>
                <button type="submit" id="submitBtn" class="btn">
                    <i class="fas fa-paper-plane"></i> Kirim Permintaan
                </button>
            </form>
        </div>
        <div style="height: var(--spacing-lg);"></div> 
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            
            document.addEventListener('gesturestart', function(e) {
                e.preventDefault();
            }, { passive: false });

            document.addEventListener('gesturechange', function(e) {
                e.preventDefault();
            }, { passive: false });

            // Prevent double-tap zoom
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(e) {
                const now = Date.now();
                if (now - lastTouchEnd <= 300) {
                    e.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            // Prevent browser zoom (Ctrl +/-, Cmd +/-)
            document.addEventListener('wheel', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=')) {
                    e.preventDefault();
                }
            }, { passive: false });

            // Toggle reset/email groups based on recovery option
            $('#recovery_option').on('change', function() {
                $('#reset_group').css('display', this.value === 'reset' ? 'block' : 'none');
                $('#email_group').css('display', this.value === 'email' ? 'block' : 'none');
                if (this.value === 'reset') {
                    $('#recovery_type').prop('required', true);
                    $('#new_email').prop('required', false);
                } else if (this.value === 'email') {
                    $('#new_email').prop('required', true);
                    $('#recovery_type').prop('required', false);
                }
            });

            // Handle no_rekening input formatting
            $('#no_rekening').on('input', function() {
                let value = $(this).val().replace(/[^0-9]/g, ''); // Only allow numbers
                if (value.length > 6) {
                    value = value.slice(0, 6); // Limit to 6 digits
                }
                $(this).val(value);
                // Update hidden input for form submission
                $('#no_rekening_hidden').val('REK' + value);
            });

            // Show alert popup
            function showAlert(message, type) {
                const popupModal = $('#popupModal');
                popupModal.find('.popup-title').text(type === 'success' ? 'BERHASIL' : 'PERINGATAN');
                popupModal.find('.popup-message').text(message);
                popupModal.find('.popup-icon')
                    .removeClass('success-icon error-icon')
                    .addClass(type === 'success' ? 'success-icon' : 'error-icon')
                    .find('i')
                    .removeClass('fa-check-circle fa-exclamation-circle')
                    .addClass(type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
                popupModal.fadeIn(300);
                setTimeout(() => {
                    popupModal.fadeOut(300, () => {
                        if (type === 'success') {
                            $('#recovery-form')[0].reset();
                            $('#reset_group, #email_group').hide();
                            $('#recovery_option').val('').focus();
                            $('#no_rekening_hidden').val('');
                        }
                    });
                }, 3000);
            }

            // Show loading spinner
            function showLoadingSpinner(button) {
                console.log('[DEBUG] Showing loading spinner for button:', button.attr('id'));
                const originalContent = button.html();
                button.html('<i class="fas fa-spinner fa-spin"></i>').prop('disabled', true);
                return {
                    restore: () => {
                        button.html(originalContent).prop('disabled', false);
                    }
                };
            }

            // Form submission with AJAX
            $('#recovery-form').on('submit', function(e) {
                e.preventDefault();
                const button = $('#submitBtn');
                console.log('[DEBUG] Recovery form submission');

                const recoveryOption = $('#recovery_option').val();
                const noRekening = $('#no_rekening').val().trim();
                const recoveryType = $('#recovery_type').val();
                const newEmail = $('#new_email').val().trim();
                const alasan = $('#alasan').val();

                // Client-side validation
                if (!recoveryOption) {
                    showAlert('Harap pilih jenis pemulihan.', 'error');
                    return;
                }
                if (!noRekening) {
                    showAlert('Nomor rekening harus diisi.', 'error');
                    return;
                }
                if (!/^\d{6}$/.test(noRekening)) {
                    showAlert('Nomor rekening harus 6 digit angka.', 'error');
                    return;
                }
                if (recoveryOption === 'reset' && !recoveryType) {
                    showAlert('Harap pilih jenis pemulihan (password/pin/username).', 'error');
                    return;
                }
                if (recoveryOption === 'email' && !newEmail) {
                    showAlert('Email baru harus diisi.', 'error');
                    return;
                }
                if (!alasan) {
                    showAlert('Harap pilih alasan pemulihan.', 'error');
                    return;
                }

                const spinner = showLoadingSpinner(button);

                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        spinner.restore();
                        console.log('[DEBUG] Recovery submission response:', response);
                        if (response.success) {
                            showAlert(response.success, 'success');
                        } else if (response.errors && response.errors.length > 0) {
                            showAlert(response.errors.join(' '), 'error');
                        } else {
                            showAlert('Terjadi kesalahan. Silakan coba lagi.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        spinner.restore();
                        console.error('[DEBUG] Recovery AJAX error:', status, error, xhr.responseText);
                        showAlert('Gagal memproses permintaan. Periksa koneksi atau coba lagi.', 'error');
                    }
                });
            });

            // Handle session messages
            <?php if ($messages['success'] || !empty($messages['errors'])): ?>
                <?php if ($messages['success']): ?>
                    showAlert('<?php echo addslashes($messages['success']); ?>', 'success');
                <?php elseif (!empty($messages['errors'])): ?>
                    showAlert('<?php echo addslashes(implode(' ', $messages['errors'])); ?>', 'error');
                <?php endif; ?>
            <?php endif; ?>

            // Close popup when clicking outside
            $('.success-overlay').on('click', function(e) {
                if ($(e.target).hasClass('success-overlay')) {
                    $(this).fadeOut(300);
                }
            });
        });
    </script>
</body>
</html>