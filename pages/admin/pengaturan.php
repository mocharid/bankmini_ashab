<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$message = '';
$error = '';

$query = "SELECT username, email FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    $error = "Data pengguna tidak ditemukan.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Handle password update first if applicable
    $password_verified = true; // Default to true if no password update
    if (!empty($new_password) || !empty($confirm_password) || !empty($current_password)) {
        if (empty($current_password)) {
            $error = "Password saat ini harus diisi!";
        } else {
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
                $is_sha256 = (strlen($stored_hash) == 64 && ctype_xdigit($stored_hash));
                $password_verified = false;

                if ($is_sha256) {
                    $password_verified = (hash('sha256', $current_password) === $stored_hash);
                } else {
                    $password_verified = password_verify($current_password, $stored_hash);
                }

                if (!$password_verified) {
                    $_SESSION['error_popup'] = "Password saat ini salah!";
                }
            }
        }
    }

    // Proceed with other validations only if password is verified or no password update
    if ($password_verified && empty($error) && empty($_SESSION['error_popup'])) {
        // Validate username
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
            }
        }

        // Validate email
        if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format email tidak valid!";
        } else {
            $email_check_query = "SELECT id FROM users WHERE email = ? AND id != ? AND email != ''";
            $email_check_stmt = $conn->prepare($email_check_query);
            $email_check_stmt->bind_param("si", $new_email, $_SESSION['user_id']);
            $email_check_stmt->execute();
            $email_check_result = $email_check_stmt->get_result();

            if ($email_check_result->num_rows > 0) {
                $error = "Email sudah digunakan!";
            }
        }

        // Validate new password fields
        if (!empty($new_password) || !empty($confirm_password)) {
            if (empty($new_password)) {
                $error = "Password baru harus diisi!";
            } elseif (empty($confirm_password)) {
                $error = "Konfirmasi password harus diisi!";
            } elseif ($new_password !== $confirm_password) {
                $error = "Password baru tidak cocok dengan konfirmasi!";
            } elseif (strlen($new_password) < 6) {
                $error = "Password baru minimal 6 karakter!";
            }
        }

        // Execute update if no errors
        if (empty($error)) {
            $updates = ["username = ?"];
            $types = "s";
            $params = [$new_username];

            // Add email to update
            $updates[] = "email = ?";
            $types .= "s";
            $params[] = $new_email ?: '';

            // Add password to update if applicable
            if (!empty($new_password) && !empty($confirm_password) && $password_verified) {
                if ($is_sha256) {
                    $updates[] = "password = ?";
                    $types .= "s";
                    $params[] = hash('sha256', $new_password);
                } else {
                    $updates[] = "password = ?";
                    $types .= "s";
                    $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                }
            }

            $params[] = $_SESSION['user_id'];
            $types .= "i";

            $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                $_SESSION['username'] = $new_username;
                $_SESSION['email'] = $new_email;
                $_SESSION['update_success'] = !empty($new_password) ? 'password' : (!empty($new_email) && $new_email !== $user['email'] ? 'email' : 'username');
            } else {
                $error = "Gagal memperbarui pengaturan: " . $conn->error;
            }
        }
    }

    // Set no username popup if applicable
    if ($error === "Username tidak boleh kosong!") {
        $_SESSION['no_username_popup'] = true;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Profil - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            --transition: all 0.3s ease;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
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
            -webkit-text-size-adjust: none;
            zoom: 1;
        }

        .top-nav {
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
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
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 800px;
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
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .profile-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .profile-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-separator {
            margin: 30px 0;
            border-top: 1px solid #e2e8f0;
            position: relative;
        }

        .form-separator span {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 0 15px;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .input-wrapper {
            position: relative;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            background: white;
            color: var(--text-primary);
        }

        input[type="text"]:hover,
        input[type="email"]:hover,
        input[type="password"]:hover {
            border-color: var(--primary-color);
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            transform: scale(1.02);
        }

        .password-field {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            background: none;
            border: none;
            font-size: clamp(1rem, 2vw, 1.1rem);
        }

        input[type="password"] {
            padding-right: 45px;
        }

        .help-text {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            color: var(--text-secondary);
            margin-top: 5px;
        }

        button[type="submit"] {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
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
            width: 100%;
            justify-content: center;
        }

        button[type="submit"]:hover {
            background: linear-gradient(90deg, var(--secondary-dark), var(--primary-dark));
            transform: translateY(-2px);
        }

        button[type="submit"]:active {
            transform: scale(0.95);
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

        .alert-error {
            background-color: #fef2f2;
            color: var(--danger-color);
            border-left: 5px solid var(--danger-color);
        }

        .alert i {
            font-size: clamp(1rem, 2vw, 1.1rem);
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
            background: linear-gradient(145deg, #ffffff, var(--bg-light));
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: var(--shadow-md);
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

        .success-icon, .warning-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .warning-icon {
            color: var(--danger-color);
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

        .password-strength {
            height: 4px;
            margin-top: 8px;
            border-radius: 2px;
            overflow: hidden;
            transition: var(--transition);
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-match-indicator {
            margin-top: 5px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            display: none;
        }

        .match-success {
            color: #10b981;
        }

        .match-error {
            color: var(--danger-color);
        }

        .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: clamp(1rem, 2vw, 1.2rem);
            cursor: pointer;
            transition: var(--transition);
        }

        .close-btn:hover {
            color: var(--primary-color);
            transform: scale(1.1);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .profile-card {
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

            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }

            .success-icon, .warning-icon {
                font-size: clamp(3.5rem, 7vw, 4rem);
            }

            .success-modal h3 {
                font-size: clamp(1.3rem, 3vw, 1.5rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2vw, 1rem);
            }
        }

        @media (max-width: 480px) {
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .form-group {
                margin-bottom: 15px;
            }

            .form-separator {
                margin: 20px 0;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="dashboard.php" class="back-btn">
            <i class="fas fa-xmark"></i>
        </a>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Profil Administrator</h2>
            <p>Kelola informasi akun Anda</p>
        </div>

        <div class="profile-card">
            <?php if (!empty($error) && $error !== "Password salah!" && $error !== "Password baru tidak cocok dengan konfirmasi!" && $error !== "Konfirmasi password harus diisi!" && $error !== "Password baru harus diisi!"): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <h3 class="section-title"><i class="fas fa-user"></i> Edit Informasi Profil</h3>
            <form action="" method="POST" class="profile-form">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username'] ?? '') ?>" required>
                    </div>
                    <div class="help-text">Username digunakan untuk login ke aplikasi.</div>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                    </div>
                    <div class="help-text">Masukkan email aktif Anda. Biarkan kosong jika tidak ingin mengubah.</div>
                </div>
                
                <div class="form-separator">
                    <span>Ubah Password</span>
                </div>
                
                <div class="form-group">
                    <label for="current_password">Password Saat Ini:</label>
                    <div class="password-field">
                        <input type="password" id="current_password" name="current_password">
                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                            <i class="fas fa-eye" id="current_password_icon"></i>
                        </button>
                    </div>
                    <div class="help-text">Masukkan password saat ini untuk verifikasi.</div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Password Baru:</label>
                    <div class="password-field">
                        <input type="password" id="new_password" name="new_password" oninput="checkPasswordStrength()">
                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                            <i class="fas fa-eye" id="new_password_icon"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-meter" id="password-meter"></div>
                    </div>
                    <div class="help-text">Minimal 6 karakter. Biarkan kosong jika tidak ingin mengubah password.</div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Konfirmasi Password Baru:</label>
                    <div class="password-field">
                        <input type="password" id="confirm_password" name="confirm_password" oninput="checkPasswordMatch()">
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="fas fa-eye" id="confirm_password_icon"></i>
                        </button>
                    </div>
                    <div class="password-match-indicator" id="password-match">
                        <i class="fas fa-check-circle match-success"></i> Password cocok
                    </div>
                    <div class="password-match-indicator" id="password-mismatch">
                        <i class="fas fa-exclamation-circle match-error"></i> Password tidak cocok
                    </div>
                    <div class="help-text">Masukkan ulang password baru untuk konfirmasi.</div>
                </div>
                
                <button type="submit" name="update_profile">
                    <i class="fas fa-save"></i> Simpan Perubahan
                </button>
            </form>
        </div>
    </div>

    <?php if (isset($_SESSION['no_username_popup']) && $_SESSION['no_username_popup']): ?>
        <div class="success-overlay" id="noUsernameModal">
            <div class="success-modal">
                <button class="close-btn" onclick="closeModal('noUsernameModal')"><i class="fas fa-times"></i></button>
                <div class="warning-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3>Peringatan</h3>
                <p id="countdownTextNoUsername">Username tidak boleh kosong!<br>Akan keluar dalam <span id="countdownNoUsername">3</span> detik</p>
            </div>
        </div>
        <?php unset($_SESSION['no_username_popup']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_popup'])): ?>
        <div class="success-overlay" id="errorModal">
            <div class="success-modal">
                <button class="close-btn" onclick="closeModal('errorModal')"><i class="fas fa-times"></i></button>
                <div class="warning-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h3>Peringatan</h3>
                <p><?= htmlspecialchars($_SESSION['error_popup']) ?></p>
            </div>
        </div>
        <?php unset($_SESSION['error_popup']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['update_success'])): ?>
        <div class="success-overlay" id="successModal">
            <div class="success-modal">
                <button class="close-btn" onclick="closeModal('successModal')"><i class="fas fa-times"></i></button>
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>Ubah <?php echo $_SESSION['update_success'] == 'password' ? 'Password' : ($_SESSION['update_success'] == 'email' ? 'Email' : 'Username'); ?> Berhasil!</h3>
                <p id="countdownText">Akan keluar dalam <span id="countdown">3</span> detik</p>
                <?php for ($i = 0; $i < 20; $i++): ?>
                    <div class="confetti" style="left: <?= rand(0, 100) ?>%; top: <?= rand(-50, -10) ?>%; animation-delay: <?= rand(0, 2000) / 1000 ?>s;"></div>
                <?php endfor; ?>
            </div>
        </div>
        <?php unset($_SESSION['update_success']); ?>
    <?php endif; ?>

    <script>
        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '_icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function checkPasswordStrength() {
            const password = document.getElementById('new_password').value;
            const meter = document.getElementById('password-meter');
            let strength = 0;

            if (password.length >= 6) strength += 20;
            if (password.match(/[A-Z]/)) strength += 20;
            if (password.match(/[a-z]/)) strength += 20;
            if (password.match(/[0-9]/)) strength += 20;
            if (password.match(/[^A-Za-z0-9]/)) strength += 20;

            meter.style.width = strength + '%';
            meter.style.backgroundColor = strength < 40 ? '#ef4444' : strength < 80 ? '#f59e0b' : '#10b981';
        }

        function checkPasswordMatch() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const match = document.getElementById('password-match');
            const mismatch = document.getElementById('password-mismatch');

            if (newPassword && confirmPassword) {
                if (newPassword === confirmPassword) {
                    match.style.display = 'block';
                    mismatch.style.display = 'none';
                } else {
                    match.style.display = 'none';
                    mismatch.style.display = 'block';
                }
            } else {
                match.style.display = 'none';
                mismatch.style.display = 'none';
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 500);
            }
        }

        function startCountdown(elementId, redirectUrl) {
            let seconds = 3;
            const countdownElement = document.getElementById(elementId);
            if (countdownElement) {
                const interval = setInterval(() => {
                    countdownElement.textContent = seconds;
                    seconds--;
                    if (seconds < 0) {
                        clearInterval(interval);
                        window.location.href = redirectUrl;
                    }
                }, 1000);
            }
        }

        // Start countdown for no username modal
        if (document.getElementById('noUsernameModal')) {
            startCountdown('countdownNoUsername', 'profile.php');
        }

        // Start countdown for success modal
        if (document.getElementById('successModal')) {
            const updateType = '<?php echo isset($_SESSION['update_success']) ? $_SESSION['update_success'] : ''; ?>';
            const redirectUrl = updateType === 'email' ? '../admin/pengaturan.php' : '../login.php';
            startCountdown('countdown', redirectUrl);
        }

        // Auto-close error modal after 2 seconds
        if (document.getElementById('errorModal')) {
            setTimeout(() => {
                closeModal('errorModal');
            }, 2000);
        }
    </script>
</body>
</html>