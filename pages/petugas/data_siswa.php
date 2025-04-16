<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);

$showConfirmation = false;
$formData = [];
$successMessage = null;
$show_success_popup = false; // Flag untuk pop-up BERHASIL

function generateUsername($nama) {
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    return $username;
}

function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password) {
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
        $mail->addAddress($email, $nama);
        
        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening Berhasil - SCHOBANK SYSTEM';
        
        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 10px;'>
            <div style='text-align: center; padding: 10px; background-color: #f5f5f5; margin-bottom: 20px;'>
                <h2 style='color: #0a2e5c;'>SCHOBANK SYSTEM</h2>
                <p style='font-size: 18px; font-weight: bold;'>Pembukaan Rekening Berhasil</p>
            </div>
            <p>Dear <strong>{$nama}</strong>,</p>
            <p>Kami dengan senang hati menginformasikan bahwa rekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah rincian akun Anda:</p>
            <div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                <p><strong>Nomor Rekening:</strong> {$no_rekening}</p>
                <p><strong>Username:</strong> {$username}</p>
                <p><strong>Password:</strong> {$password}</p>
                <p><strong>Saldo Awal:</strong> Rp 0</p>
            </div>
            <p style='color: #d35400;'><strong>Catatan Penting:</strong> Harap ubah kata sandi Anda setelah login pertama untuk menjaga keamanan akun Anda.</p>
            <p>Jika Anda memiliki pertanyaan, silakan hubungi tim kami.</p>
            <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #777; font-size: 12px;'>
                <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                <p>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
            </div>
        </div>";
        
        $mail->Body = $emailBody;
        $mail->AltBody = "Pembukaan Rekening Berhasil\n\nDear {$nama},\n\nRekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah rincian akun Anda:\n\nNomor Rekening: {$no_rekening}\nUsername: {$username}\nPassword: {$password}\nSaldo Awal: Rp 0\n\nCatatan Penting: Harap ubah kata sandi Anda setelah login pertama untuk menjaga keamanan akun Anda.\n\nJika Anda memiliki pertanyaan, silakan hubungi tim kami.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirm'])) {
        $nama = trim($_POST['nama']);
        $username = $_POST['username'];
        $password = '12345';
        $password_hash = hash('sha256', $password);
        $jurusan_id = $_POST['jurusan_id'];
        $kelas_id = $_POST['kelas_id'];
        $no_rekening = $_POST['no_rekening'];
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $username = $username . rand(100, 999);
        }

        $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id, email) 
                VALUES (?, ?, 'siswa', ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssii", $username, $password_hash, $nama, $jurusan_id, $kelas_id, $email);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;
            $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0)";
            $stmt_rekening = $conn->prepare($query_rekening);
            $stmt_rekening->bind_param("si", $no_rekening, $user_id);
            
            if ($stmt_rekening->execute()) {
                $email_sent = false;
                if (!empty($email)) {
                    $email_sent = sendEmailConfirmation($email, $nama, $username, $no_rekening, $password);
                }
                
                $show_success_popup = true; // Set flag untuk pop-up BERHASIL
                header('refresh:3;url=data_siswa.php');
            } else {
                $error = "Error saat membuat rekening!";
            }
        } else {
            $error = "Error saat menambah user!";
        }
    } elseif (isset($_POST['cancel'])) {
        header('Location: dashboard.php');
        exit();
    } else {
        $showConfirmation = true;
        $nama = trim($_POST['nama']);
        $username = generateUsername($nama);
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $username = $username . rand(100, 999);
        }
        
        $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        
        $formData = [
            'nama' => $nama,
            'username' => $username,
            'password' => '12345',
            'jurusan_id' => $_POST['jurusan_id'],
            'kelas_id' => $_POST['kelas_id'],
            'no_rekening' => $no_rekening,
            'email' => $email
        ];
        
        $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
        $stmt_jurusan = $conn->prepare($query_jurusan_name);
        $stmt_jurusan->bind_param("i", $formData['jurusan_id']);
        $stmt_jurusan->execute();
        $result_jurusan_name = $stmt_jurusan->get_result();
        $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
        
        $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
        $stmt_kelas = $conn->prepare($query_kelas_name);
        $stmt_kelas->bind_param("i", $formData['kelas_id']);
        $stmt_kelas->execute();
        $result_kelas_name = $stmt_kelas->get_result();
        $kelas_name = $result_kelas_name->fetch_assoc()['nama_kelas'];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tambah Nasabah - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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

        .deposit-card {
            background: white;
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

        .deposit-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        select {
            appearance: none;
            background: white url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="%23666"><polygon points="0,0 14,0 7,14"/></svg>') no-repeat right 15px center;
        }

        .btn {
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
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: var(--danger-color);
        }

        .btn-cancel:hover {
            background-color: #d32f2f;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
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

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
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
            animation: slideUpText 0.5s ease-out 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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

        .modal-content {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 5rem;
        }

        .loading-spinner {
            width: 2.5rem;
            height: 2.5rem;
            border: 0.3rem solid var(--primary-light);
            border-top: 0.3rem solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

            .deposit-card {
                padding: 20px;
            }

            .deposit-form {
                gap: 15px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .success-modal {
                width: 90%;
                padding: 30px;
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

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-user-plus"></i> Tambah Nasabah</h2>
            <p>Layanan pembukaan rekening untuk siswa baru</p>
        </div>

        <div id="alertContainer">
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Input Form -->
        <?php if (!$showConfirmation && !$show_success_popup): ?>
            <div class="deposit-card">
                <form action="" method="POST" id="nasabahForm" class="deposit-form">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap</label>
                        <input type="text" id="nama" name="nama" required value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" placeholder="Masukkan nama lengkap">
                    </div>
                    <div class="form-group">
                        <label for="email">Email (Opsional)</label>
                        <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="Masukkan email">
                    </div>
                    <div class="form-group">
                        <label for="jurusan_id">Jurusan</label>
                        <select id="jurusan_id" name="jurusan_id" required onchange="getKelasByJurusan(this.value)">
                            <option value="">Pilih Jurusan</option>
                            <?php 
                            if($result_jurusan->num_rows > 0) {
                                $result_jurusan->data_seek(0);
                            }
                            while ($row = $result_jurusan->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="kelas_id">Kelas</label>
                        <select id="kelas_id" name="kelas_id" required>
                            <option value="">Pilih Kelas</option>
                        </select>
                        <div id="kelas-loading" class="loading" style="display: none;">
                            <div class="loading-spinner"></div>
                        </div>
                    </div>
                    <button type="submit" class="btn" id="submit-btn">
                        <i class="fas fa-user-plus"></i> Lanjutkan
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Confirmation Modal -->
        <?php if ($showConfirmation && !$show_success_popup): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Konfirmasi Data Nasabah</h3>
                    <div class="modal-content">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['nama']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['username']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Password</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['password']); ?></span>
                        </div>
                        <?php if (!empty($formData['email'])): ?>
                        <div class="modal-row">
                            <span class="modal-label">Email</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['email']); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($jurusan_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($kelas_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['no_rekening']); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars($formData['nama']); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($formData['jurusan_id']); ?>">
                        <input type="hidden" name="kelas_id" value="<?php echo htmlspecialchars($formData['kelas_id']); ?>">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                        <?php if (!empty($formData['email'])): ?>
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                        <?php endif; ?>
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.history.back();">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if ($show_success_popup): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Pembukaan rekening berhasil!</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming
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

            // Alert auto-hide
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            // Modal confetti for confirmation modal
            const confirmModal = document.querySelector('#confirmModal .success-modal');
            if (confirmModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    confirmModal.appendChild(confetti);
                }
            }

            // Modal confetti for success modal
            const successModal = document.querySelector('#successModal .success-modal');
            if (successModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    successModal.appendChild(confetti);
                }
                // Auto-close success modal after 2 seconds
                setTimeout(() => {
                    const overlay = document.querySelector('#successModal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 2000);
            }

            // Loading effect for forms
            const nasabahForm = document.getElementById('nasabahForm');
            const submitBtn = document.getElementById('submit-btn');
            
            if (nasabahForm && submitBtn) {
                nasabahForm.addEventListener('submit', function() {
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function() {
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                });
            }

            // Form validation
            if (nasabahForm) {
                nasabahForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        const alertContainer = document.getElementById('alertContainer');
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-error';
                        alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Nama harus minimal 3 karakter!';
                        alertContainer.appendChild(alertDiv);
                        document.getElementById('nama').focus();
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Lanjutkan';
                        setTimeout(() => {
                            alertDiv.classList.add('hide');
                            setTimeout(() => alertDiv.remove(), 500);
                        }, 5000);
                        return;
                    }

                    const email = document.getElementById('email').value.trim();
                    if (email !== '') {
                        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailPattern.test(email)) {
                            e.preventDefault();
                            const alertContainer = document.getElementById('alertContainer');
                            const alertDiv = document.createElement('div');
                            alertDiv.className = 'alert alert-error';
                            alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Format email tidak valid!';
                            alertContainer.appendChild(alertDiv);
                            document.getElementById('email').focus();
                            submitBtn.classList.remove('loading');
                            submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Lanjutkan';
                            setTimeout(() => {
                                alertDiv.classList.add('hide');
                                setTimeout(() => alertDiv.remove(), 500);
                            }, 5000);
                        }
                    }
                });
            }

            // AJAX for kelas
            function getKelasByJurusan(jurusan_id) {
                if (jurusan_id) {
                    const kelasLoading = document.getElementById('kelas-loading');
                    const kelasSelect = document.getElementById('kelas_id');
                    kelasLoading.style.display = 'flex';
                    kelasSelect.style.display = 'none';
                    
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: jurusan_id },
                        success: function(response) {
                            kelasSelect.innerHTML = response;
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                        },
                        error: function(xhr, status, error) {
                            console.error('Error:', error);
                            const alertContainer = document.getElementById('alertContainer');
                            const alertDiv = document.createElement('div');
                            alertDiv.className = 'alert alert-error';
                            alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Terjadi kesalahan saat mengambil data kelas!';
                            alertContainer.appendChild(alertDiv);
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                            setTimeout(() => {
                                alertDiv.classList.add('hide');
                                setTimeout(() => alertDiv.remove(), 500);
                            }, 5000);
                        }
                    });
                } else {
                    document.getElementById('kelas_id').innerHTML = '<option value="">Pilih Kelas</option>';
                }
            }

            // Inisialisasi kelas jika jurusan sudah dipilih
            <?php if (isset($_POST['jurusan_id']) && !$showConfirmation): ?>
                getKelasByJurusan(<?php echo $_POST['jurusan_id']; ?>);
            <?php endif; ?>

            // Pasang event listener untuk jurusan
            const jurusanSelect = document.getElementById('jurusan_id');
            if (jurusanSelect) {
                jurusanSelect.addEventListener('change', function() {
                    getKelasByJurusan(this.value);
                });
            }
        });
    </script>
</body>
</html>