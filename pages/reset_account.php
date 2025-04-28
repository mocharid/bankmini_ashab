<?php
session_start();
require_once '../includes/db_connection.php';
require '../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set zona waktu
date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk memverifikasi token
function verifyToken($conn, $token, $recovery_type) {
    $query = "SELECT user_id, expiry, new_email FROM password_reset WHERE token = ? AND recovery_type = ? AND expiry > NOW()";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $token, $recovery_type);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 0) {
        error_log("Token tidak ditemukan atau kedaluwarsa: token=$token, type=$recovery_type");
    } else {
        error_log("Token ditemukan: token=$token, type=$recovery_type");
    }
    return $result->num_rows > 0 ? $result->fetch_assoc() : false;
}

// Fungsi untuk memverifikasi kredensial
function verifyCredentials($conn, $user_id, $credential_type, $credential_value) {
    $query = "SELECT username, password, pin, has_pin, email FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($credential_type === 'username' && $credential_value && $user['username'] === $credential_value) {
        return true;
    }
    if ($credential_type === 'password' && $credential_value && password_verify($credential_value, $user['password'])) {
        return true;
    }
    if ($credential_type === 'pin' && $credential_value && $user['has_pin'] && $user['pin'] === $credential_value) {
        return true;
    }
    if ($credential_type === 'old_email' && $credential_value && $user['email'] === $credential_value) {
        return true;
    }
    return false;
}

$token = $_GET['token'] ?? '';
$recovery_type = $_GET['type'] ?? '';
$errors = [];
$success = '';
$alert_message = '';

if (!empty($_SESSION['alert_message'])) {
    $alert_message = $_SESSION['alert_message'];
    unset($_SESSION['alert_message']);
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

if (empty($errors)) {
    $token_data = verifyToken($conn, $token, $recovery_type);
    if (!$token_data) {
        $errors[] = 'Link tidak valid atau telah kedaluwarsa. Silakan minta link baru dari admin.';
    } else {
        $user_id = $token_data['user_id'];
        $new_email = $token_data['new_email'];
        error_log("Token valid untuk user_id=$user_id, type=$recovery_type, new_email=$new_email");
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($errors)) {
    if ($recovery_type === 'email') {
        $credential_type = $_POST['credential_type'] ?? '';
        // Ambil nilai berdasarkan jenis kredensial
        $credential_value = '';
        if ($credential_type === 'username') {
            $credential_value = trim($_POST['username_value'] ?? '');
        } elseif ($credential_type === 'password') {
            $credential_value = trim($_POST['password_value'] ?? '');
        } elseif ($credential_type === 'pin') {
            $credential_value = trim($_POST['pin_value'] ?? '');
        } elseif ($credential_type === 'old_email') {
            $credential_value = trim($_POST['old_email_value'] ?? '');
        }

        error_log("Menerima POST: credential_type=$credential_type, credential_value=$credential_value");

        if (empty($credential_type) || !in_array($credential_type, ['username', 'password', 'pin', 'old_email'])) {
            $errors[] = 'Jenis verifikasi tidak valid.';
        } elseif (empty($credential_value)) {
            $errors[] = 'Kolom verifikasi harus diisi.';
        } elseif (!verifyCredentials($conn, $user_id, $credential_type, $credential_value)) {
            $_SESSION['alert_message'] = 'Kredensial salah. Silakan coba lagi.';
            error_log("Verifikasi kredensial gagal: user_id=$user_id, type=$credential_type, value=$credential_value");
            header("Location: reset_account.php?token=$token&type=$recovery_type");
            exit();
        } else {
            // Verifikasi berhasil, set new_value untuk email
            $new_value = $new_email;
            $confirm_value = $new_email;
        }
    } else {
        $new_value = trim($_POST['new_value'] ?? '');
        $confirm_value = trim($_POST['confirm_value'] ?? '');

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

        if ($recovery_type === 'password') {
            if (strlen($new_value) < 6) {
                $errors[] = 'Password minimal 6 karakter.';
            } else {
                $value = password_hash($new_value, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $bind_param = 'si';
            }
        } elseif ($recovery_type === 'pin') {
            if (!preg_match('/^\d{6}$/', $new_value)) {
                $errors[] = 'PIN harus 6 digit angka.';
            } else {
                $value = $new_value;
                $update_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                $bind_param = 'si';
            }
        } elseif ($recovery_type === 'username') {
            if (strlen($new_value) < 4) {
                $errors[] = 'Username minimal 4 karakter.';
            } else {
                $query = "SELECT id FROM users WHERE username = ? AND id != ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('si', $new_value, $user_id);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $errors[] = 'Username sudah digunakan.';
                } else {
                    $update_query = "UPDATE users SET username = ? WHERE id = ?";
                    $bind_param = 'si';
                }
            }
        } elseif ($recovery_type === 'email') {
            if (!filter_var($new_value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Email tidak valid.';
            } else {
                $update_query = "UPDATE users SET email = ? WHERE id = ?";
                $bind_param = 'si';
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param($bind_param, $value, $user_id);
            if ($stmt->execute()) {
                // Hapus token dari password_reset
                $query = "DELETE FROM password_reset WHERE token = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param('s', $token);
                $stmt->execute();

                $success = "Data $recovery_type berhasil diubah. Anda akan dialihkan ke halaman login dalam 3 detik.";
                error_log("Pemulihan berhasil: user_id=$user_id, type=$recovery_type, new_value=$new_value");
                header("Refresh: 3; url=./login.php");
            } else {
                $errors[] = "Gagal memperbarui $recovery_type.";
                error_log("Gagal memperbarui: user_id=$user_id, type=$recovery_type, error=" . $conn->error);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang <?php echo ucfirst($recovery_type); ?> - SCHOBANK</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <div class="container">
        <h2>Atur Ulang <?php echo ucfirst($recovery_type); ?></h2>
        <?php if ($success): ?>
            <p class="success"><?php echo $success; ?></p>
            <a href="./login.php">Kembali ke Login</a>
        <?php else: ?>
            <?php foreach ($errors as $error): ?>
                <p class="error"><?php echo $error; ?></p>
            <?php endforeach; ?>
            <?php if (empty($errors)): ?>
                <form method="POST" action="">
                    <?php if ($recovery_type === 'email'): ?>
                        <p>Silakan pilih jenis verifikasi dan masukkan nilai yang sesuai untuk memverifikasi identitas Anda.</p>
                        <p>Setelah verifikasi, email Anda akan diubah menjadi: <strong><?php echo htmlspecialchars($new_email); ?></strong></p>
                        <div class="form-group">
                            <label for="credential_type">Pilih Jenis Verifikasi</label>
                            <select id="credential_type" name="credential_type" required>
                                <option value="">Pilih Jenis</option>
                                <option value="username">Username</option>
                                <option value="password">Password</option>
                                <option value="pin">PIN</option>
                                <option value="old_email">Email Lama</option>
                            </select>
                        </div>
                        <div class="form-group" id="username_group" style="display: none;">
                            <label for="username_value">Username</label>
                            <input type="text" id="username_value" name="username_value">
                        </div>
                        <div class="form-group" id="password_group" style="display: none;">
                            <label for="password_value">Password</label>
                            <input type="password" id="password_value" name="password_value">
                        </div>
                        <div class="form-group" id="pin_group" style="display: none;">
                            <label for="pin_value">PIN</label>
                            <input type="password" id="pin_value" name="pin_value">
                        </div>
                        <div class="form-group" id="old_email_group" style="display: none;">
                            <label for="old_email_value">Email Lama</label>
                            <input type="email" id="old_email_value" name="old_email_value">
                        </div>
                        <input type="hidden" name="new_value" value="<?php echo htmlspecialchars($new_email); ?>">
                        <input type="hidden" name="confirm_value" value="<?php echo htmlspecialchars($new_email); ?>">
                    <?php else: ?>
                        <div class="form-group">
                            <label for="new_value"><?php echo ucfirst($recovery_type); ?> Baru</label>
                            <input type="<?php echo $recovery_type === 'password' || $recovery_type === 'pin' ? 'password' : 'text'; ?>" 
                                   id="new_value" name="new_value" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_value">Konfirmasi <?php echo ucfirst($recovery_type); ?></label>
                            <input type="<?php echo $recovery_type === 'password' || $recovery_type === 'pin' ? 'password' : 'text'; ?>" 
                                   id="confirm_value" name="confirm_value" required>
                        </div>
                    <?php endif; ?>
                    <button type="submit"><?php echo $recovery_type === 'email' ? 'Verifikasi dan Ubah Email' : 'Simpan'; ?></button>
                    <a href="./login.php" class="btn-back">Back</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <script>
        <?php if ($alert_message): ?>
            alert("<?php echo $alert_message; ?>");
        <?php endif; ?>

        document.getElementById('credential_type')?.addEventListener('change', function() {
            document.getElementById('username_group').style.display = this.value === 'username' ? 'block' : 'none';
            document.getElementById('password_group').style.display = this.value === 'password' ? 'block' : 'none';
            document.getElementById('pin_group').style.display = this.value === 'pin' ? 'block' : 'none';
            document.getElementById('old_email_group').style.display = this.value === 'old_email' ? 'block' : 'none';
        });
    </script>
    <style>
        .btn-back {
            display: inline-block;
            background: #666;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 15px;
            margin-left: 10px;
        }
    </style>
</body>
</html>