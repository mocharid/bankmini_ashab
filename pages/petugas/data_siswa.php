<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php'; // Sesuaikan path ke autoload PHPMailer
// Include PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ambil data jurusan
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);

// Mode konfirmasi
$showConfirmation = false;
$formData = [];

function generateUsername($nama) {
    // Ubah nama menjadi lowercase dan hilangkan karakter khusus
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    return $username;
}

// Function to send email notification
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password) {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com'; // Email pengirim
        $mail->Password = 'spjs plkg ktuu lcxh'; // Password email pengirim
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        // Recipient
        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email, $nama);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening Berhasil - SCHOBANK SYSTEM';
        
        // Email template
        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
            <div style='text-align: center; padding: 10px; background-color: #f5f5f5; margin-bottom: 20px;'>
                <h2 style='color: #2e7eed;'>SCHOBANK SYSTEM</h2>
                <p style='font-size: 18px; font-weight: bold;'>Pembukaan Rekening Berhasil</p>
            </div>
            
            <p>Halo <strong>{$nama}</strong>,</p>
            
            <p>Selamat! Rekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah detail akun Anda:</p>
            
            <div style='background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                <p><strong>Nomor Rekening:</strong> {$no_rekening}</p>
                <p><strong>Username:</strong> {$username}</p>
                <p><strong>Password:</strong> {$password}</p>
                <p><strong>Saldo Awal:</strong> Rp 0</p>
            </div>
            
            <p style='color: #d35400;'><strong>Catatan Penting:</strong> Segera ubah password Anda setelah login pertama untuk keamanan akun Anda.</p>
            
            <p>Jika Anda memiliki pertanyaan lebih lanjut, silakan hubungi petugas kami.</p>
            
            <div style='text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #777; font-size: 12px;'>
                <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                <p>&copy; " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
            </div>
        </div>
        ";
        
        $mail->Body = $emailBody;
        $mail->AltBody = "Pembukaan Rekening Berhasil\n\n"
                       . "Halo {$nama},\n\n"
                       . "Selamat! Rekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah detail akun Anda:\n\n"
                       . "Nomor Rekening: {$no_rekening}\n"
                       . "Username: {$username}\n"
                       . "Password: {$password}\n"
                       . "Saldo Awal: Rp 0\n\n"
                       . "Catatan Penting: Segera ubah password Anda setelah login pertama untuk keamanan akun Anda.\n\n"
                       . "Jika Anda memiliki pertanyaan lebih lanjut, silakan hubungi petugas kami.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['confirm'])) {
        // Proses penyimpanan data setelah konfirmasi
        $nama = trim($_POST['nama']);
        $username = $_POST['username']; // Ambil username dari form yang sudah di-generate sebelumnya
        $password = '12345'; // Password default
        $password_hash = hash('sha256', $password); // Password default hashed
        $jurusan_id = $_POST['jurusan_id'];
        $kelas_id = $_POST['kelas_id'];
        $no_rekening = $_POST['no_rekening']; // Ambil nomor rekening dari form hidden
        $email = isset($_POST['email']) ? trim($_POST['email']) : null; // Email opsional
        
        // Cek username sudah ada atau belum
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            // Jika username sudah ada, tambahkan angka random
            $username = $username . rand(100, 999);
        }

        // Tambahkan user baru
        $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id, email) 
                VALUES (?, ?, 'siswa', ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssssis", $username, $password_hash, $nama, $jurusan_id, $kelas_id, $email);
        
        if ($stmt->execute()) {
            $user_id = $conn->insert_id;

            // Buat rekening baru
            $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) 
                            VALUES (?, ?, 0)";
            $stmt_rekening = $conn->prepare($query_rekening);
            $stmt_rekening->bind_param("si", $no_rekening, $user_id);
            
            if ($stmt_rekening->execute()) {
                // Email notification if email is provided
                $email_sent = false;
                if (!empty($email)) {
                    $email_sent = sendEmailConfirmation($email, $nama, $username, $no_rekening, $password);
                }
                
                $_SESSION['success_message'] = "Pembukaan rekening berhasil!<br>Username: $username<br>Password default: 12345";
                
                if ($email_sent) {
                    $_SESSION['success_message'] .= "<br><br>Informasi detail rekening telah dikirim ke email: $email";
                }
                
                header('Location: dashboard.php');
                exit();
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
        // Tampilkan konfirmasi
        $showConfirmation = true;
        $nama = trim($_POST['nama']);
        $username = generateUsername($nama);
        $email = isset($_POST['email']) ? trim($_POST['email']) : null;
        
        // Cek jika username sudah ada
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $username = $username . rand(100, 999);
        }
        
        // Generate nomor rekening
        $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        
        $formData = [
            'nama' => $nama,
            'username' => $username,
            'password' => '12345', // Password default
            'jurusan_id' => $_POST['jurusan_id'],
            'kelas_id' => $_POST['kelas_id'],
            'no_rekening' => $no_rekening,
            'email' => $email
        ];
        
        // Dapatkan nama jurusan dan kelas untuk ditampilkan
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0a2e5c;
            --primary-light: #154785;
            --secondary: #2b6cb0;
            --accent: #4299e1;
            --success: #48bb78;
            --error: #e53e3e;
            --bg-light: #f7fafc;
            --text-dark: #2d3748;
            --text-light: #718096;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.08);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.05);
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: var(--bg-light);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-content {
            width: 90%;
            max-width: 800px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            animation: fadeIn 0.5s ease;
            position: relative;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 25px;
            border-radius: var(--radius-md);
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }
        
        .welcome-banner h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            flex: 1;
        }
        
        .welcome-banner i {
            font-size: 24px;
            background: rgba(255, 255, 255, 0.2);
            height: 50px;
            width: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        
        .close-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            color: white;
            border: none;
            width: 36px;
            height: 36px;
            border-radius: 0%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            font-size: 16px;
            text-decoration: none; /* Removes underline if it's a link */
        }

        form {
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
            font-weight: 600;
            color: var(--text-dark);
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        label i {
            color: var(--primary-light);
        }
        
        input, select {
            padding: 14px 16px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 15px;
            transition: var(--transition);
            background-color: var(--bg-light);
            width: 100%;
            height: 55px;
            color: var(--text-dark);
            outline: none;
            box-shadow: var(--shadow-sm);
        }
        
        input:focus, select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 46, 92, 0.1);
            background-color: white;
        }
        
        input::placeholder {
            color: #a0aec0;
            opacity: 0.7;
        }
        
        button {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: white;
            padding: 14px;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            height: 55px;
            box-shadow: var(--shadow-sm);
        }
        
        button:hover {
            background: linear-gradient(to right, var(--secondary), var(--primary));
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .error {
            color: var(--error);
            margin-bottom: 20px;
            padding: 15px;
            background-color: #fff5f5;
            border-left: 4px solid var(--error);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-sm);
        }
        
        .success {
            background-color: #f0fff4;
            color: var(--success);
            padding: 20px;
            margin-bottom: 25px;
            border-radius: var(--radius-sm);
            border-left: 4px solid var(--success);
            font-weight: 500;
            animation: fadeOut 0.5s ease 5s forwards;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-sm);
        }
        
        @keyframes fadeOut {
            from {opacity: 1;}
            to {opacity: 0; display: none;}
        }
        
        .confirmation-container {
            background-color: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
        }
        
        .confirmation-container h3 {
            margin-top: 0;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
            color: var(--primary);
            font-size: 18px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .confirmation-item {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .confirmation-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .confirmation-label {
            font-weight: 600;
            width: 150px;
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .confirmation-label i {
            color: var(--primary-light);
            font-size: 14px;
        }
        
        .confirmation-value {
            flex: 1;
            color: var(--text-dark);
            font-weight: 500;
        }
        
        .buttons-container {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }
        
        .cancel-button {
            background: linear-gradient(to right, var(--error), #c53030);
        }
        
        .cancel-button:hover {
            background: linear-gradient(to right, #c53030, #9b2c2c);
        }
        
        .info-text {
            color: var(--text-light);
            font-size: 0.9em;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .info-text i {
            color: var(--primary-light);
        }
        
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100px;
        }
        
        .loading i {
            color: var(--primary);
            font-size: 24px;
            animation: spin 1s infinite linear;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .section-title {
            margin-bottom: 20px;
            color: var(--primary);
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-divider {
            height: 1px;
            background-color: var(--border-color);
            margin: 10px 0;
        }
        
        .input-wrapper {
            position: relative;
            transition: var(--transition);
        }
        
        .input-wrapper:focus-within {
            transform: translateY(-2px);
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-light);
            font-size: 16px;
            z-index: 2;
            transition: var(--transition);
        }
        
        .input-with-icon {
            padding-left: 46px;
        }
        
        /* Floating label effect */
        .floating-label {
            position: relative;
            margin-bottom: 16px;
        }
        
        .floating-label label {
            position: absolute;
            left: 46px;
            top: 50%;
            transform: translateY(-50%);
            background-color: transparent;
            transition: all 0.2s ease-out;
            pointer-events: none;
            padding: 0 5px;
            color: var(--text-light);
            z-index: 1;
        }
        
        .floating-label input:focus ~ label,
        .floating-label input:not(:placeholder-shown) ~ label,
        .floating-label select:focus ~ label,
        .floating-label select:not([value=""]):not(:focus) ~ label {
            top: 0;
            font-size: 12px;
            color: var(--primary);
            font-weight: 600;
            background-color: white;
        }
        
        .floating-label input:focus,
        .floating-label select:focus {
            border-color: var(--primary);
        }
        
        .floating-label .input-icon {
            color: var(--text-light);
        }
        
        .floating-label input:focus ~ .input-icon,
        .floating-label select:focus ~ .input-icon {
            color: var(--primary);
        }
        
        /* Optional field styling */
        .optional-field {
            position: relative;
        }
        
        .optional-badge {
            position: absolute;
            right: 10px;
            top: -10px;
            background-color: var(--accent);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            z-index: 2;
        }
        
        /* Responsive design improvements */
        @media (max-width: 768px) {
            .main-content {
                width: 95%;
                padding: 20px;
                margin: 15px auto;
                border-radius: var(--radius-md);
            }
            
            .welcome-banner {
                padding: 15px;
                text-align: center;
                gap: 10px;
            }
            
            .welcome-banner i {
                font-size: 20px;
                height: 40px;
                width: 40px;
            }
            
            .confirmation-item {
                flex-direction: column;
                gap: 5px;
            }
            
            .confirmation-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .buttons-container {
                flex-direction: column;
            }
            
            .floating-label label {
                left: 42px;
                font-size: 14px;
            }
            
            input, select, button {
                height: 50px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .main-content {
                padding: 15px;
                width: 100%;
                margin: 10px auto;
                border-radius: 0;
                box-shadow: none;
            }
            
            .welcome-banner h2 {
                font-size: 18px;
            }
            
            .confirmation-container {
                padding: 15px;
            }
            
            .form-group {
                gap: 5px;
            }
            
            .input-wrapper {
                margin-bottom: 15px;
            }
            
            label {
                font-size: 14px;
            }
            
            .info-text {
                font-size: 12px;
            }
            
            .floating-label label {
                font-size: 13px;
            }
            
            input, select {
                padding: 12px 16px;
                font-size: 14px;
                height: 48px;
            }
            
            .input-icon {
                font-size: 14px;
                left: 14px;
            }
            
            .input-with-icon {
                padding-left: 40px;
            }
            
        }
        </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <i class="<?php echo $showConfirmation ? 'fas fa-check-circle' : 'fas fa-user-plus'; ?>"></i>
            <h2><?php echo $showConfirmation ? 'Konfirmasi Data Nasabah' : 'Tambah Nasabah Baru'; ?></h2>
            <a href="dashboard.php" class="close-btn">
                <i class="fas fa-times"></i>
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="error" id="error-message">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success" id="success-message">
                <i class="fas fa-check-circle"></i> 
                <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($showConfirmation): ?>
            <div class="confirmation-container">
                <h3><i class="fas fa-clipboard-check"></i> Rekapitulasi Data Nasabah</h3>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-user"></i> Nama:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($formData['nama']); ?></div>
                </div>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-user-tag"></i> Username:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($formData['username']); ?></div>
                </div>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-key"></i> Password:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($formData['password']); ?></div>
                </div>
                
                <?php if (!empty($formData['email'])): ?>
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-envelope"></i> Email:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($formData['email']); ?></div>
                </div>
                <div class="confirmation-item">
                    <div class="email-notification-info">
                        <i class="fas fa-info-circle"></i> Informasi detail rekening akan dikirim ke email ini
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-graduation-cap"></i> Jurusan:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($jurusan_name); ?></div>
                </div>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-chalkboard"></i> Kelas:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($kelas_name); ?></div>
                </div>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-credit-card"></i> No. Rekening:</div>
                    <div class="confirmation-value"><?php echo htmlspecialchars($formData['no_rekening']); ?></div>
                </div>
                
                <div class="confirmation-item">
                    <div class="confirmation-label"><i class="fas fa-coins"></i> Saldo Awal:</div>
                    <div class="confirmation-value">Rp 0</div>
                </div>
            </div>
            
            <form action="" method="POST">
                <input type="hidden" name="nama" value="<?php echo htmlspecialchars($formData['nama']); ?>">
                <input type="hidden" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>">
                <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($formData['jurusan_id']); ?>">
                <input type="hidden" name="kelas_id" value="<?php echo htmlspecialchars($formData['kelas_id']); ?>">
                <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                <?php if (!empty($formData['email'])): ?>
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                <?php endif; ?>
                
                <div class="buttons-container">
                    <button type="submit" name="confirm" value="1">
                        <i class="fas fa-check"></i> Konfirmasi
                    </button>
                    <button type="submit" name="cancel" value="1" class="cancel-button">
                        <i class="fas fa-times"></i> Batal
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="section-title">
                <i class="fas fa-user-edit"></i> Form Data Nasabah
            </div>
            
            <form action="" method="POST">
                <div class="floating-label">
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="nama" name="nama" class="input-with-icon" required value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" placeholder=" ">
                        <label for="nama">Nama Lengkap</label>
                    </div>
                    <div class="info-text">
                        <i class="fas fa-info-circle"></i> Username akan dibuat otomatis dari nama
                    </div>
                </div>
                
                <div class="floating-label">
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" class="input-with-icon" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder=" ">
                        <label for="email">Email</label>
                    </div>
                    <div class="info-text">
                        <i class="fas fa-info-circle"></i> Jika diisi, detail rekening akan dikirim ke email ini
                    </div>
                </div>
                
                <div class="form-divider"></div>
                
                <div class="floating-label">
                    <div class="input-wrapper">
                        <i class="fas fa-graduation-cap input-icon"></i>
                        <select id="jurusan_id" name="jurusan_id" class="input-with-icon" required onchange="getKelasByJurusan(this.value)">
                            <option value=""></option>
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
                        <label for="jurusan_id">Pilih Jurusan</label>
                    </div>
                </div>
                
                <div class="floating-label">
                    <div class="input-wrapper">
                        <i class="fas fa-chalkboard input-icon"></i>
                        <select id="kelas_id" name="kelas_id" class="input-with-icon" required>
                            <option value=""></option>
                        </select>
                        <label for="kelas_id">Pilih Kelas</label>
                    </div>
                    <div id="kelas-loading" class="loading" style="display: none;">
                        <i class="fas fa-spinner"></i>
                    </div>
                </div>
                
                <button type="submit">
                    <i class="fas fa-user-plus"></i> Tambah Nasabah
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        function getKelasByJurusan(jurusan_id) {
            if (jurusan_id) {
                $('#kelas-loading').show();
                $('#kelas_id').hide();
                
                $.ajax({
                    url: 'get_kelas_by_jurusan.php',
                    type: 'GET',
                    data: { jurusan_id: jurusan_id },
                    success: function(response) {
                        $('#kelas_id').html(response);
                        $('#kelas-loading').hide();
                        $('#kelas_id').fadeIn();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error:', error);
                        alert('Terjadi kesalahan saat mengambil data kelas');
                        $('#kelas-loading').hide();
                        $('#kelas_id').fadeIn();
                    }
                });
            } else {
                $('#kelas_id').html('<option value=""></option>');
            }
        }

        <?php if (isset($_POST['jurusan_id']) && !$showConfirmation): ?>
        // Load kelas if jurusan was selected
        $(document).ready(function() {
            getKelasByJurusan(<?php echo $_POST['jurusan_id']; ?>);
        });
        <?php endif; ?>

        // Auto hide notifications after 5 seconds
        $(document).ready(function() {
            setTimeout(function() {
                $("#success-message, #error-message").fadeOut('slow');
            }, 5000);
            
            // Form validation
            $('form').on('submit', function(e) {
                const nama = $('#nama').val().trim();
                if (nama.length < 3) {
                    e.preventDefault();
                    alert('Nama harus memiliki minimal 3 karakter');
                    $('#nama').focus();
                }
                
                // Email validation if entered
                const email = $('#email').val().trim();
                if (email !== '') {
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailPattern.test(email)) {
                        e.preventDefault();
                        alert('Format email tidak valid');
                        $('#email').focus();
                    }
                }
            });
            
            // Add touch-friendly features for mobile
            if ('ontouchstart' in window) {
                $('select, button').css('padding', '14px');
            }
        });
    </script>
</body>
</html>