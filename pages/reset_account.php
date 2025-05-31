<?php
session_start();
require_once '../includes/db_connection.php';
require_once '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set zona waktu
date_default_timezone_set('Asia/Jakarta');

// Custom string sanitization function
function sanitize_string($input) {
    if ($input === null || $input === '') {
        return '';
    }
    // Remove harmful characters and trim
    $sanitized = trim(strip_tags($input));
    // Escape special characters to prevent XSS
    $sanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    return $sanitized;
}

// Initialize variables
$token = sanitize_string(filter_input(INPUT_GET, 'token')) ?? '';
$recovery_type = sanitize_string(filter_input(INPUT_GET, 'type')) ?? '';
$errors = [];
$success = '';
$alert_message = '';

if (!isset($conn) || !$conn) {
    $errors[] = 'Koneksi database gagal. Silakan hubungi administrator.';
    error_log('Database connection failed in reset_account.php');
}

// Handle session alert message
if (!empty($_SESSION['alert_message'])) {
    $alert_message = $_SESSION['alert_message'];
    unset($_SESSION['alert_message']);
}

// Fungsi untuk memverifikasi token
function verifyToken($conn, $token, $recovery_type) {
    if (!$conn) {
        error_log('No database connection in verifyToken');
        return false;
    }
    $query = "SELECT user_id, expiry, new_email FROM password_reset WHERE token = ? AND recovery_type = ? AND expiry > NOW()";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log('Prepare failed in verifyToken: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('ss', $token, $recovery_type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("Token tidak ditemukan atau kedaluwarsa: token=$token, type=$recovery_type");
        return false;
    }
    error_log("Token ditemukan: token=$token, type=$recovery_type");
    return $result->fetch_assoc();
}

// Fungsi untuk memverifikasi kredensial
function verifyCredentials($conn, $user_id, $credential_type, $credential_value) {
    if (!$conn || empty($credential_value)) {
        error_log('Invalid input or no database connection in verifyCredentials');
        return false;
    }

    $query = "SELECT username, password, pin, has_pin, email FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log('Prepare failed in verifyCredentials: ' . $conn->error);
        return false;
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        error_log("User tidak ditemukan: user_id=$user_id");
        return false;
    }
    
    $user = $result->fetch_assoc();

    switch ($credential_type) {
        case 'username':
            return $user['username'] === $credential_value;
        case 'password':
            $query = "SELECT id FROM users WHERE id = ? AND password = SHA2(?, 256)";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                error_log('Prepare failed in password verification: ' . $conn->error);
                return false;
            }
            $stmt->bind_param('is', $user_id, $credential_value);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->num_rows > 0;
        case 'pin':
            return $user['has_pin'] && $user['pin'] === $credential_value;
        case 'old_email':
            return $user['email'] === $credential_value;
        default:
            error_log("Invalid credential type: $credential_type");
            return false;
    }
}

// Validasi parameter URL
if (empty($token)) {
    $errors[] = 'Token tidak ditemukan di URL.';
    error_log("Token kosong di URL: type=$recovery_type");
}
if (empty($recovery_type) || !in_array($recovery_type, ['password', 'pin', 'username', 'email'])) {
    $errors[] = 'Jenis pemulihan tidak valid.';
    error_log("Jenis pemulihan tidak valid: type=$recovery_type");
}

// Verifikasi token jika tidak ada error awal
$user_id = null;
$new_email = null;
if (empty($errors) && $conn) {
    $token_data = verifyToken($conn, $token, $recovery_type);
    if (!$token_data) {
        $errors[] = 'Link tidak valid atau telah kedaluwarsa. Silakan minta link baru dari admin.';
    } else {
        $user_id = $token_data['user_id'];
        $new_email = $token_data['new_email'];
        error_log("Token valid untuk user_id=$user_id, type=$recovery_type, new_email=$new_email");
    }
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors) && $conn) {
    if ($recovery_type === 'email') {
        $credential_type = sanitize_string(filter_input(INPUT_POST, 'credential_type')) ?? '';
        $credential_value = '';
        
        switch ($credential_type) {
            case 'username':
                $credential_value = trim(sanitize_string(filter_input(INPUT_POST, 'username_value')) ?? '');
                break;
            case 'password':
                $credential_value = trim(sanitize_string(filter_input(INPUT_POST, 'password_value')) ?? '');
                break;
            case 'pin':
                $credential_value = trim(sanitize_string(filter_input(INPUT_POST, 'pin_value')) ?? '');
                break;
            case 'old_email':
                $credential_value = trim(filter_input(INPUT_POST, 'old_email_value', FILTER_SANITIZE_EMAIL) ?? '');
                break;
            default:
                $errors[] = 'Jenis verifikasi tidak valid.';
                break;
        }

        error_log("Menerima POST: credential_type=$credential_type, credential_value=$credential_value");

        if (empty($credential_value)) {
            $errors[] = 'Kolom verifikasi harus diisi.';
        } elseif (!verifyCredentials($conn, $user_id, $credential_type, $credential_value)) {
            $_SESSION['alert_message'] = 'Kredensial salah. Silakan coba lagi.';
            error_log("Verifikasi kredensial gagal: user_id=$user_id, type=$credential_type");
            header("Location: reset_account.php?token=$token&type=$recovery_type");
            exit();
        } else {
            $new_value = $new_email;
            $confirm_value = $new_email;
        }
    } else {
        $new_value = trim(sanitize_string(filter_input(INPUT_POST, 'new_value')) ?? '');
        $confirm_value = trim(sanitize_string(filter_input(INPUT_POST, 'confirm_value')) ?? '');

        if (empty($new_value) || empty($confirm_value)) {
            $errors[] = 'Semua kolom harus diisi.';
        } elseif ($new_value !== $confirm_value) {
            $errors[] = 'Nilai baru dan konfirmasi tidak cocok.';
        }
    }

    if (empty($errors)) {
        $update_query = '';
        $bind_param = '';
        $value = $new_value;

        switch ($recovery_type) {
            case 'password':
                if (strlen($new_value) < 6) {
                    $errors[] = 'Password minimal 6 karakter.';
                } else {
                    $update_query = "UPDATE users SET password = SHA2(?, 256) WHERE id = ?";
                    $bind_param = 'si';
                }
                break;
            case 'pin':
                if (!preg_match('/^\d{6}$/', $new_value)) {
                    $errors[] = 'PIN harus 6 digit angka.';
                } else {
                    $update_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                    $bind_param = 'si';
                }
                break;
            case 'username':
                if (strlen($new_value) < 4) {
                    $errors[] = 'Username minimal 4 karakter.';
                } else {
                    $query = "SELECT id FROM users WHERE username = ? AND id != ?";
                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param('si', $new_value, $user_id);
                        $stmt->execute();
                        if ($stmt->get_result()->num_rows > 0) {
                            $errors[] = 'Username sudah digunakan.';
                        } else {
                            $update_query = "UPDATE users SET username = ? WHERE id = ?";
                            $bind_param = 'si';
                        }
                        $stmt->close();
                    } else {
                        $errors[] = 'Kesalahan database saat memeriksa username.';
                        error_log('Prepare failed in username check: ' . $conn->error);
                    }
                }
                break;
            case 'email':
                if (!filter_var($new_value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Email tidak valid.';
                } else {
                    $update_query = "UPDATE users SET email = ? WHERE id = ?";
                    $bind_param = 'si';
                }
                break;
        }

        if (empty($errors)) {
            $stmt = $conn->prepare($update_query);
            if (!$stmt) {
                $errors[] = 'Kesalahan database saat mempersiapkan pembaruan.';
                error_log('Prepare failed in update: ' . $conn->error);
            } else {
                $stmt->bind_param($bind_param, $value, $user_id);
                if ($stmt->execute()) {
                    // Hapus token dari password_reset
                    $query = "DELETE FROM password_reset WHERE token = ?";
                    $delete_stmt = $conn->prepare($query);
                    if ($delete_stmt) {
                        $delete_stmt->bind_param('s', $token);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                    }

                    $success = "Data $recovery_type berhasil diubah. Anda akan dialihkan ke halaman login dalam 3 detik.";
                    error_log("Pemulihan berhasil: user_id=$user_id, type=$recovery_type, new_value=$new_value");
                    header("Refresh: 3; url=./login.php");
                    
                    // Clean up session
                    unset($_SESSION['alert_message']);
                } else {
                    $errors[] = "Gagal memperbarui $recovery_type.";
                    error_log("Gagal memperbarui: user_id=$user_id, type=$recovery_type, error=" . $conn->error);
                }
                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Atur Ulang <?php echo ucfirst(htmlspecialchars($recovery_type)); ?> - SCHOBANK</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html, body {
            touch-action: manipulation;
            overscroll-behavior: none;
        }

        body {
            background: linear-gradient(180deg, #e6ecf8 0%, #f5f7fa 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }

        .container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e8ecef;
        }

        .logo {
            width: 100px;
            height: auto;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .bank-name {
            font-size: 1.4rem;
            color: #1A237E;
            font-weight: 700;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .form-description {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 400;
        }

        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-group i.icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a5568;
            font-size: 1.1rem;
        }

        input, select {
            width: 100%;
            padding: 12px 45px;
            border: 1px solid #d1d9e6;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 400;
            transition: all 0.3s ease;
            background: #fbfdff;
        }

        input:focus, select:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #1A237E 0%, #00B4D8 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        button:hover {
            background: linear-gradient(90deg, #151c66 0%, #009bb8 100%);
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
            transform: translateY(-1px);
            scale: 1.02;
        }

        .error {
            background: #fef1f1;
            color: #c53030;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #feb2b2;
        }

        .success {
            background: #e6fffa;
            color: #2c7a7b;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #b2f5ea;
        }

        .btn-back {
            color: #1A237E;
            font-size: 0.9rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            margin-top: 1.5rem;
        }

        .btn-back i {
            margin-right: 5px;
        }

        .btn-back:hover {
            color: #00B4D8;
            text-decoration: underline;
            transform: scale(1.05);
        }

        @media (max-width: 480px) {
            .container {
                padding: 1.5rem;
            }

            .logo {
                width: 80px;
            }

            .bank-name {
                font-size: 1.2rem;
            }

            input, select, button {
                font-size: 14px;
                padding: 10px 40px;
            }

            button {
                padding: 10px;
            }

            .form-description {
                font-size: 0.85rem;
            }

            .form-group i.icon {
                font-size: 1rem;
            }

            .error, .success {
                font-size: 0.85rem;
                padding: 0.6rem;
            }

            .btn-back {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="/bankmini/assets/images/lbank.png" alt="SCHOBANK Logo" class="logo">
            <div class="bank-name">SCHOBANK</div>
        </div>

        <div class="form-description">
            <?php echo $recovery_type === 'email' ? 
                'Verifikasi identitas Anda untuk mengubah email. Email baru: ' . (isset($new_email) ? htmlspecialchars($new_email) : 'tidak tersedia') : 
                'Masukkan dan konfirmasi ' . ucfirst(htmlspecialchars($recovery_type)) . ' baru Anda.'; ?>
        </div>

        <?php if ($success): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            <a href="./login.php" class="btn-back"><i class="fas fa-arrow-left"></i>Kembali ke Login</a>
        <?php else: ?>
            <?php foreach ($errors as $error): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endforeach; ?>
            <?php if (empty($errors)): ?>
                <form method="POST" action="">
                    <?php if ($recovery_type === 'email'): ?>
                        <div class="form-group">
                            <i class="fas fa-list icon"></i>
                            <select id="credential_type" name="credential_type" required>
                                <option value="">Pilih Jenis Verifikasi</option>
                                <option value="username">Username</option>
                                <option value="password">Password</option>
                                <option value="pin">PIN</option>
                                <option value="old_email">Email Lama</option>
                            </select>
                        </div>
                        <div class="form-group" id="username_group" style="display: none;">
                            <i class="fas fa-user icon"></i>
                            <input type="text" id="username_value" name="username_value" value="">
                        </div>
                        <div class="form-group" id="password_group" style="display: none;">
                            <i class="fas fa-lock icon"></i>
                            <input type="password" id="password_value" name="password_value" value="">
                        </div>
                        <div class="form-group" id="pin_group" style="display: none;">
                            <i class="fas fa-key icon"></i>
                            <input type="password" id="pin_value" name="pin_value" value="">
                        </div>
                        <div class="form-group" id="old_email_group" style="display: none;">
                            <i class="fas fa-envelope icon"></i>
                            <input type="email" id="old_email_value" name="old_email_value" value="">
                        </div>
                        <input type="hidden" name="new_value" value="<?php echo isset($new_email) ? htmlspecialchars($new_email) : ''; ?>">
                        <input type="hidden" name="confirm_value" value="<?php echo isset($new_email) ? htmlspecialchars($new_email) : ''; ?>">
                    <?php else: ?>
                        <div class="form-group">
                            <i class="<?php echo $recovery_type === 'password' ? 'fas fa-lock' : 
                                      ($recovery_type === 'pin' ? 'fas fa-key' : 
                                      ($recovery_type === 'username' ? 'fas fa-user' : 'fas fa-envelope')); ?> icon"></i>
                            <input type="<?php echo $recovery_type === 'password' || $recovery_type === 'pin' ? 'password' : 'text'; ?>" 
                                   id="new_value" name="new_value" required value="">
                        </div>
                        <div class="form-group">
                            <i class="<?php echo $recovery_type === 'password' ? 'fas fa-lock' : 
                                      ($recovery_type === 'pin' ? 'fas fa-key' : 
                                      ($recovery_type === 'username' ? 'fas fa-user' : 'fas fa-envelope')); ?> icon"></i>
                            <input type="<?php echo $recovery_type === 'password' || $recovery_type === 'pin' ? 'password' : 'text'; ?>" 
                                   id="confirm_value" name="confirm_value" required value="">
                        </div>
                    <?php endif; ?>
                    <button type="submit">
                        <i class="fas fa-save"></i>
                        <?php echo $recovery_type === 'email' ? 'Verifikasi dan Ubah Email' : 'Simpan'; ?>
                    </button>
                    <a href="./login.php" class="btn-back"><i class="fas fa-arrow-left"></i>Kembali ke Login</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
        // Handle alert message
        <?php if ($alert_message): ?>
            alert(<?php echo json_encode($alert_message); ?>);
        <?php endif; ?>

        // Toggle input fields based on credential type
        const credentialTypeSelect = document.getElementById('credential_type');
        if (credentialTypeSelect) {
            credentialTypeSelect.addEventListener('change', function() {
                document.getElementById('username_group').style.display = this.value === 'username' ? 'block' : 'none';
                document.getElementById('password_group').style.display = this.value === 'password' ? 'block' : 'none';
                document.getElementById('pin_group').style.display = this.value === 'pin' ? 'block' : 'none';
                document.getElementById('old_email_group').style.display = this.value === 'old_email' ? 'block' : 'none';
            });
        }

        // Prevent zoom
        document.addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '0')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>