<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$message = '';
$error = '';

$query = "SELECT username FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("Data pengguna tidak ditemukan.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $new_username = trim($_POST['username']);
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

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
            
                $updates = ["username = ?"];
                $types = "s";
                $params = [$new_username];
                
                if (!empty($new_password) || !empty($confirm_password)) {
                    if (empty($current_password)) {
                        $error = "Password saat ini harus diisi!";
                    } elseif (empty($new_password)) {
                        $error = "Password baru harus diisi!";
                    } elseif (empty($confirm_password)) {
                        $error = "Konfirmasi password harus diisi!";
                    } elseif ($new_password !== $confirm_password) {
                        $error = "Password baru tidak cocok dengan konfirmasi!";
                    } elseif (strlen($new_password) < 6) {
                        $error = "Password baru minimal 6 karakter!";
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
                            
                            if ($password_verified) {
                                if ($is_sha256) {
                                    $updates[] = "password = ?";
                                    $types .= "s";
                                    $params[] = hash('sha256', $new_password);
                                } else {
                                    $updates[] = "password = ?";
                                    $types .= "s";
                                    $params[] = password_hash($new_password, PASSWORD_DEFAULT);
                                }
                            } else {
                                $error = "Password saat ini tidak valid!";
                            }
                        }
                    }
                }
   
                if (empty($error)) {
                    $params[] = $_SESSION['user_id'];
                    $types .= "i";
         
                    $query = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param($types, ...$params);
                    
                    if ($stmt->execute()) {
                        $_SESSION['username'] = $new_username; 
                        $message = "Pengaturan berhasil diperbarui!";
                        $user['username'] = $new_username;

                        session_unset(); 
                        session_destroy(); 
                        header("Location: ../../index.php"); 
                        exit();
                    } else {
                        $error = "Gagal memperbarui pengaturan: " . $conn->error;
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Profil - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        body {
            background-color: #f8fafc;
            color: #334155;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background: #0a2e5c;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .main-content {
            flex: 1;
            padding: 1.5rem;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 2rem;
            border-radius: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(21, 71, 133, 0.2);
        }
        
        .welcome-banner h2 {
            font-size: 1.75rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-card {
            background: white;
            border-radius: 1rem;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-separator {
            margin: 2rem 0;
            border-top: 1px solid #e2e8f0;
            position: relative;
        }

        .form-separator span {
            position: absolute;
            top: -0.75rem;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 0 1rem;
            color: #64748b;
            font-size: 0.875rem;
            font-weight: 500;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #334155;
            font-size: 0.9375rem;
        }

        .input-wrapper {
            position: relative;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: all 0.2s ease;
            color: #1e293b;
            background-color: #fff;
        }

        input[type="text"]:hover,
        input[type="password"]:hover {
            border-color: #cbd5e1;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .password-field {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0.25rem;
            font-size: 1rem;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:focus {
            outline: none;
        }

        /* Fix for the blue highlight issue */
        input[type="password"] {
            padding-right: 2.5rem;
        }

        .help-text {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.375rem;
        }

        button[type="submit"] {
            background-color: #0a2e5c;
            color: white;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: 0.5rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        button[type="submit"] i {
            margin-right: 0.5rem;
        }

        button[type="submit"]:hover {
            background-color: #154785;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        button[type="submit"]:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        .profile-heading {
            color: #0a2e5c;
            font-size: 1.25rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #eee;
            font-weight: 700;
        }

        .alert {
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            display: flex;
            align-items: center;
            animation: fadeIn 0.4s ease-out;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .alert-success {
            background-color: #ecfdf5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert-error {
            background-color: #fef2f2;
            color: #b91c1c;
            border-left: 4px solid #ef4444;
        }

        .alert i {
            margin-right: 0.75rem;
            font-size: 1.125rem;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-0.5rem); }
            to { opacity: 1; transform: translateY(0); }
        }

        .password-strength {
            height: 4px;
            margin-top: 0.5rem;
            border-radius: 2px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        .password-match-indicator {
            margin-top: 0.375rem;
            font-size: 0.8125rem;
            display: none;
        }

        .match-success {
            color: #10b981;
        }

        .match-error {
            color: #ef4444;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
            }

            .nav-buttons {
                gap: 10px;
            }

            .main-content {
                padding: 1rem;
            }

            .profile-card {
                padding: 1.5rem 1rem;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <h1>SCHOBANK</h1>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-user-edit"></i> Profil Petugas</h2>
        </div>

        <div class="profile-card">
            <?php if (!empty($message)): ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error" id="errorAlert">
                    <i class="fas fa-exclamation-circle"></i>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <h3 class="profile-heading">Edit Informasi Profil</h3>
            <form action="" method="POST" class="profile-form">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                    </div>
                    <div class="help-text">Username digunakan untuk login ke aplikasi.</div>
                </div>
                
                <div class="form-separator">
                    <span>Ubah Password</span>
                </div>
                
                <div class="form-group">
                    <label for="current_password">Password Saat Ini:</label>
                    <div class="password-field">
                        <input type="password" id="current_password" name="current_password">
                        <button type="button" class="password-toggle" onclick="togglePassword('current_password', event)">
                            <i class="fas fa-eye" id="current_password_icon"></i>
                        </button>
                    </div>
                    <div class="help-text">Masukkan password saat ini untuk verifikasi.</div>
                </div>
                
                <div class="form-group">
                    <label for="new_password">Password Baru:</label>
                    <div class="password-field">
                        <input type="password" id="new_password" name="new_password">
                        <button type="button" class="password-toggle" onclick="togglePassword('new_password', event)">
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
                        <input type="password" id="confirm_password" name="confirm_password">
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', event)">
                            <i class="fas fa-eye" id="confirm_password_icon"></i>
                        </button>
                    </div>
                    <div class="password-match-indicator" id="password-match">
                        <i class="fas fa-check-circle"></i> Password cocok
                    </div>
                    <div class="password-match-indicator" id="password-mismatch">
                        <i class="fas fa-exclamation-circle"></i> Password tidak cocok
                    </div>
                    <div class="help-text">Masukkan ulang password baru untuk konfirmasi.</div>
                </div>
                
                <button type="submit" name="update_profile">
                    <i class="fas fa-save"></i> Simpan Perubahan
                </button>
            </form>
        </div>
    </div>

    <script>
        function togglePassword(inputId, e) {
            e.preventDefault();
            
            const input = document.getElementById(inputId);
            const icon = document.getElementById(inputId + '_icon');
            
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

        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const passwordMeter = document.getElementById('password-meter');
        const passwordMatch = document.getElementById('password-match');
        const passwordMismatch = document.getElementById('password-mismatch');
        
        newPassword.addEventListener('input', function() {
            updatePasswordStrength(this.value);
            validatePasswordMatch();
        });
        
        confirmPassword.addEventListener('input', function() {
            validatePasswordMatch();
        });
        
        function updatePasswordStrength(password) {
            let strength = 0;
            let width = "0%";
            let color = "#eee";
            
            if (!password) {
                passwordMeter.style.width = width;
                passwordMeter.style.backgroundColor = color;
                return;
            }
            
            if (password.length >= 6) strength += 1;
            if (password.length >= 10) strength += 1;
            
            if (/[A-Z]/.test(password)) strength += 1;
            if (/[0-9]/.test(password)) strength += 1;
            if (/[^A-Za-z0-9]/.test(password)) strength += 1;
            
            switch(strength) {
                case 0:
                    width = "0%";
                    color = "#eee";
                    break;
                case 1:
                    width = "20%";
                    color = "#ef4444"; 
                    break;
                case 2:
                    width = "40%";
                    color = "#f97316";
                    break;
                case 3:
                    width = "60%";
                    color = "#eab308"; 
                    break;
                case 4:
                    width = "80%";
                    color = "#84cc16"; 
                    break;
                case 5:
                    width = "100%";
                    color = "#10b981"; 
                    break;
            }
            
            passwordMeter.style.width = width;
            passwordMeter.style.backgroundColor = color;
        }
        
        function validatePasswordMatch() {
            if (confirmPassword.value && newPassword.value) {
                if (newPassword.value === confirmPassword.value) {
                    passwordMatch.style.display = "block";
                    passwordMismatch.style.display = "none";
                    confirmPassword.style.borderColor = "#10b981";
                } else {
                    passwordMatch.style.display = "none";
                    passwordMismatch.style.display = "block";
                    confirmPassword.style.borderColor = "#ef4444";
                }
            } else {
                passwordMatch.style.display = "none";
                passwordMismatch.style.display = "none";
                confirmPassword.style.borderColor = "#e2e8f0";
            }
        }
       
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    alert.style.transition = 'all 0.5s ease';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 500);
                }, 5000);
            });
            
            updatePasswordStrength(newPassword.value);
            validatePasswordMatch();
        });
    </script>
</body>
</html>