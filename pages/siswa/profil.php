<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

function logout() {
    session_start();
    session_unset();
    session_destroy();
    header("Location: ../../index.php");
    exit();
}

$message = '';
$error = '';

// Ambil data user lengkap dan rekening
$query = "SELECT u.username, u.nama, u.role, k.nama_kelas, j.nama_jurusan, 
          r.no_rekening, r.saldo, u.created_at as tanggal_bergabung, u.pin
          FROM users u 
          LEFT JOIN rekening r ON u.id = r.user_id 
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("Data pengguna tidak ditemukan.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_username'])) {
        // Username update code remains unchanged
        $new_username = trim($_POST['new_username']);
        
        if (empty($new_username)) {
            $error = "Username tidak boleh kosong!";
        } else {
            $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = "Username sudah digunakan!";
            } else {
                $update_query = "UPDATE users SET username = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
                
                if ($update_stmt->execute()) {
                    $message = "Username berhasil diubah!";
                    $user['username'] = $new_username;
                    logout();
                } else {
                    $error = "Gagal mengubah username!";
                }
            }
        }
    } elseif (isset($_POST['update_password'])) {
        // Password update code remains unchanged
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Semua field password harus diisi!";
        } elseif ($new_password !== $confirm_password) {
            $error = "Password baru tidak cocok!";
        } elseif (strlen($new_password) < 6) {
            $error = "Password baru minimal 6 karakter!";
        } else {
            // Get current password from database
            $verify_query = "SELECT password FROM users WHERE id = ?";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bind_param("i", $_SESSION['user_id']);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            $user_data = $verify_result->fetch_assoc();
            
            if (!$user_data || !isset($user_data['password'])) {
                $error = "Tidak dapat memverifikasi password, silakan coba lagi nanti.";
            } else {
                $stored_hash = $user_data['password'];
                
                // Check if the hash is a SHA-256 hash (64 characters in length)
                $is_sha256 = (strlen($stored_hash) == 64 && ctype_xdigit($stored_hash));
                
                // Verify the password based on hash type
                $password_verified = false;
                
                if ($is_sha256) {
                    // For SHA-256 hashed passwords
                    $password_verified = (hash('sha256', $current_password) === $stored_hash);
                } else {
                    // For passwords hashed with password_hash()
                    $password_verified = password_verify($current_password, $stored_hash);
                }
                
                if ($password_verified) {
                    // FIX: Use consistent hashing method - if original was SHA-256, use SHA-256 for new password too
                    if ($is_sha256) {
                        $hashed_password = hash('sha256', $new_password);
                    } else {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    }
                    
                    $update_query = "UPDATE users SET password = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                    
                    if ($update_stmt->execute()) {
                        $message = "Password berhasil diubah! Anda dapat menggunakan password baru untuk login selanjutnya.";
                        logout();
                    } else {
                        $error = "Gagal mengubah password!";
                    }
                } else {
                    $error = "Password saat ini tidak valid!";
                }
            }
        }
    } elseif (isset($_POST['update_pin'])) {
        // Modified PIN update code with fix for undefined array key
        $new_pin = $_POST['new_pin'];
        $confirm_pin = $_POST['confirm_pin'];

        if (empty($new_pin) || empty($confirm_pin)) {
            $error = "Semua field PIN harus diisi!";
        } elseif ($new_pin !== $confirm_pin) {
            $error = "PIN baru tidak cocok!";
        } elseif (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
            $error = "PIN harus terdiri dari 6 digit angka!";
        } else {
            // Verify current PIN first if user already has a PIN
            if (!empty($user['pin'])) {
                // Check if current_pin exists in POST
                if (!isset($_POST['current_pin']) || empty($_POST['current_pin'])) {
                    $error = "PIN saat ini harus diisi!";
                } elseif ($_POST['current_pin'] !== $user['pin']) {
                    $error = "PIN saat ini tidak valid!";
                } else {
                    // Current PIN is correct, proceed with update
                    $update_query = "UPDATE users SET pin = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

                    if ($update_stmt->execute()) {
                        $message = "PIN berhasil diubah!";
                        $user['pin'] = $new_pin;
                    } else {
                        $error = "Gagal mengubah PIN!";
                    }
                }
            } else {
                // User doesn't have a PIN yet, allow creating new PIN without verification
                $update_query = "UPDATE users SET pin = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

                if ($update_stmt->execute()) {
                    $message = "PIN berhasil dibuat!";
                    $user['pin'] = $new_pin;
                } else {
                    $error = "Gagal membuat PIN!";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Profil Pengguna - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
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
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
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
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            text-decoration: none;
            z-index: 2;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .profile-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }

        .profile-card, .form-section {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            overflow: hidden;
        }

        .profile-card:hover, .form-section:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .profile-header i {
            margin-right: 10px;
            font-size: 20px;
        }

        .profile-info {
            padding: 20px;
        }

        .info-item {
            padding: 12px 0;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item i {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary-color);
            border-radius: 10px;
            margin-right: 15px;
            font-size: 16px;
        }

        .info-item .label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 3px;
        }

        .info-item .value {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .saldo-value {
            color: var(--secondary-color);
            font-weight: 600;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            background: var(--primary-light);
            color: var(--primary-color);
        }

        .form-section {
            margin-bottom: 25px;
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .form-header i {
            margin-right: 10px;
            font-size: 20px;
        }

        .form-content {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 40px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            transition: var(--transition);
        }

        .form-group i.input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 16px;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .form-group .password-toggle {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
        }

        .form-group .password-toggle:hover {
            color: var(--primary-color);
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 15px;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-align: center;
            width: 100%;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn i {
            margin-right: 8px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            animation: slideIn 0.5s ease-out;
            display: flex;
            align-items: center;
            max-width: 350px;
            box-shadow: var(--shadow-md);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .alert.hide {
            animation: slideOut 0.5s ease-in forwards;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 5px solid #34d399;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 5px solid #f87171;
        }

        .alert i {
            margin-right: 15px;
            font-size: 20px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content p {
            margin: 0;
        }

        .alert .close-alert {
            background: none;
            border: none;
            color: inherit;
            font-size: 16px;
            cursor: pointer;
            margin-left: 15px;
            opacity: 0.7;
            transition: var(--transition);
        }

        .alert .close-alert:hover {
            opacity: 1;
        }

        .tab-buttons {
            display: flex;
            margin-bottom: 25px;
            padding: 0 5px;
        }

        .tab-button {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: white;
            border: none;
            border-bottom: 3px solid #eee;
            color: var(--text-secondary);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* Loader animation for buttons */
        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to {
                transform: rotate(360deg);
            }
        }
        </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>
    
    <div class="main-content">
        <div class="welcome-banner">
            <h2>Profil Pengguna</h2>
            <p>Kelola informasi dan pengaturan profil Anda</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div id="successAlert" class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
                <button class="close-alert" onclick="dismissAlert(this.parentElement)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div id="errorAlert" class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
                <button class="close-alert" onclick="dismissAlert(this.parentElement)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="tab-buttons">
            <button class="tab-button active" data-tab="profile">
                <i class="fas fa-user"></i> Profil
            </button>
            <button class="tab-button" data-tab="account">
                <i class="fas fa-cog"></i> Pengaturan
            </button>
        </div>

        <!-- Profile Tab -->
        <div id="profile-tab" class="tab-content active">
            <div class="profile-container">
                <div class="profile-card">
                    <div class="profile-header">
                        <i class="fas fa-user-circle"></i> Informasi Pribadi
                    </div>
                    <div class="profile-info">
                        <div class="info-item">
                            <i class="fas fa-user"></i>
                            <div>
                                <div class="label">Nama Lengkap</div>
                                <div class="value"><?= htmlspecialchars($user['nama']) ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-user-tag"></i>
                            <div>
                                <div class="label">Username</div>
                                <div class="value"><?= htmlspecialchars($user['username']) ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-user-shield"></i>
                            <div>
                                <div class="label">Role</div>
                                <div class="value">
                                    <span class="badge"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <div class="label">Bergabung Sejak</div>
                                <div class="value"><?= date('d F Y', strtotime($user['tanggal_bergabung'])) ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-lock"></i>
                            <div>
                                <div class="label">Status PIN</div>
                                <div class="value">
                                    <?= !empty($user['pin']) ? 'PIN sudah diatur' : 'PIN belum diatur' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Account Settings Tab -->
        <div id="account-tab" class="tab-content">
            <div class="profile-container">
                <!-- Form untuk mengubah username -->
                <div class="form-section">
                    <div class="form-header">
                        <i class="fas fa-user-edit"></i> Ubah Username
                    </div>
                    <div class="form-content">
                        <form method="POST" action="" id="usernameForm">
                            <div class="form-group">
                                <label for="new_username">Username Baru</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" id="new_username" name="new_username" required placeholder="Masukkan username baru">
                                </div>
                            </div>
                            <button type="submit" name="update_username" class="btn btn-primary" id="usernameBtn">
                                <i class="fas fa-save"></i> <span>Simpan Username Baru</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Form untuk mengubah password -->
                <div class="form-section">
                    <div class="form-header">
                        <i class="fas fa-lock"></i> Ubah Password
                    </div>
                    <div class="form-content">
                        <form method="POST" action="" id="passwordForm">
                            <div class="form-group">
                                <label for="current_password">Password Saat Ini</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-key input-icon"></i>
                                    <input type="password" id="current_password" name="current_password" required placeholder="Password saat ini">
                                    <i class="fas fa-eye password-toggle" data-target="current_password"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="new_password">Password Baru</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" id="new_password" name="new_password" required placeholder="Masukkan password baru">
                                    <i class="fas fa-eye password-toggle" data-target="new_password"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password">Konfirmasi Password Baru</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-check-circle input-icon"></i>
                                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password baru">
                                    <i class="fas fa-eye password-toggle" data-target="confirm_password"></i>
                                </div>
                            </div>
                            <button type="submit" name="update_password" class="btn btn-primary" id="passwordBtn">
                                <i class="fas fa-save"></i> <span>Simpan Password Baru</span>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Modified Form untuk membuat/mengubah PIN -->
                <div class="form-section">
                    <div class="form-header">
                        <i class="fas fa-lock"></i> Buat/Ubah PIN
                    </div>
                    <div class="form-content">
                        <form method="POST" action="" id="pinForm">
                            <?php if (!empty($user['pin'])): ?>
                            <!-- Show current PIN field only if the user already has a PIN -->
                            <div class="form-group">
                                <label for="current_pin">PIN Saat Ini</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-key input-icon"></i>
                                    <input type="password" id="current_pin" name="current_pin" required placeholder="Masukkan PIN saat ini" maxlength="6">
                                    <i class="fas fa-eye password-toggle" data-target="current_pin"></i>
                                </div>
                            </div>
                            <?php endif; ?>
                            <div class="form-group">
                                <label for="new_pin">PIN Baru (6 digit angka)</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" id="new_pin" name="new_pin" required placeholder="Masukkan PIN baru" maxlength="6">
                                    <i class="fas fa-eye password-toggle" data-target="new_pin"></i>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="confirm_pin">Konfirmasi PIN Baru</label>
                                <div class="input-wrapper">
                                    <i class="fas fa-check-circle input-icon"></i>
                                    <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN baru" maxlength="6">
                                    <i class="fas fa-eye password-toggle" data-target="confirm_pin"></i>
                                </div>
                            </div>
                            <button type="submit" name="update_pin" class="btn btn-primary" id="pinBtn">
                                <i class="fas fa-save"></i> <span>Simpan PIN Baru</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle alerts
        function dismissAlert(alert) {
            alert.classList.add('hide');
            setTimeout(() => {
                alert.remove();
            }, 500);
        }

        // Auto dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    dismissAlert(alert);
                }, 5000);
            });

            // Redirect to login page after successful update
            if (window.location.href.indexOf('success') > -1) {
                setTimeout(() => {
                    window.location.href = '../../login.php';
                }, 3000);
            }

            // Tab switching functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');

            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // Update active button
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show active tab content
                    tabContents.forEach(content => content.classList.remove('active'));
                    document.getElementById(tabId + '-tab').classList.add('active');
                });
            });

            // Toggle password visibility
            const toggles = document.querySelectorAll('.password-toggle');
            toggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    }
                });
            });

            // Form submission with loading animation
            document.getElementById('usernameForm').addEventListener('submit', function() {
                const button = document.getElementById('usernameBtn');
                button.classList.add('btn-loading');
            });

            document.getElementById('passwordForm').addEventListener('submit', function() {
                const button = document.getElementById('passwordBtn');
                button.classList.add('btn-loading');
            });
            
            document.getElementById('pinForm').addEventListener('submit', function() {
                const button = document.getElementById('pinBtn');
                button.classList.add('btn-loading');
            });
        });
    </script>
</body>
</html>