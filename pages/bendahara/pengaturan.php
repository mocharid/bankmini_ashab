<?php
/**
 * Pengaturan Akun Bendahara - Adaptive Path Version (BCRYPT + Normalized DB)
 * File: pages/bendahara/pengaturan.php
 * 
 * Compatible with:
 * - Local: schobank/pages/bendahara/pengaturan.php
 * - Hosting: public_html/pages/bendahara/pengaturan.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/bendahara' folder
if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: Search upward for includes/ folder (max 5 levels)
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback: Use current directory
if (!$project_root) {
    $project_root = dirname(dirname($current_dir));
}

// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once INCLUDES_PATH . '/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

// ============================================
// ANTI-CSRF TOKEN GENERATION
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// AUTH CHECK - HANYA BENDAHARA
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bendahara') {
    header("Location: " . BASE_URL . "/pages/login.php");
    exit();
}

$bendahara_id = $_SESSION['user_id'];
$nama = $_SESSION['nama'] ?? 'Bendahara';

// ============================================
// HANDLER UBAH DATA
// ============================================
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors['general'] = 'Invalid request. Silakan coba lagi.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'ubah_username':
                $new_username = trim($_POST['new_username'] ?? '');
                if (empty($new_username)) {
                    $errors['username'] = 'Username baru tidak boleh kosong.';
                } elseif (strlen($new_username) < 3) {
                    $errors['username'] = 'Username minimal 3 karakter.';
                } else {
                    // Cek username unique
                    $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("si", $new_username, $bendahara_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($check_result->num_rows > 0) {
                        $errors['username'] = 'Username sudah digunakan.';
                    } else {
                        $update_query = "UPDATE users SET username = ? WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("si", $new_username, $bendahara_id);
                        if ($update_stmt->execute()) {
                            $_SESSION['username'] = $new_username;
                            $success['username'] = 'Username berhasil diubah.';
                        } else {
                            $errors['username'] = 'Gagal mengubah username.';
                        }
                        $update_stmt->close();
                    }
                    $check_stmt->close();
                }
                break;

            case 'ubah_password':
                $old_password = $_POST['old_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';

                if (empty($old_password)) {
                    $errors['password'] = 'Password lama harus diisi.';
                } elseif (empty($new_password) || strlen($new_password) < 6) {
                    $errors['password'] = 'Password baru minimal 6 karakter.';
                } elseif ($new_password !== $confirm_password) {
                    $errors['password'] = 'Konfirmasi password tidak cocok.';
                } else {
                    // Verifikasi password lama
                    $verify_query = "SELECT password FROM users WHERE id = ?";
                    $verify_stmt = $conn->prepare($verify_query);
                    $verify_stmt->bind_param("i", $bendahara_id);
                    $verify_stmt->execute();
                    $verify_result = $verify_stmt->get_result();
                    $user = $verify_result->fetch_assoc();

                    if (!password_verify($old_password, $user['password'])) {
                        $errors['password'] = 'Password lama salah.';
                    } else {
                        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                        $update_query = "UPDATE users SET password = ? WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("si", $hashed_password, $bendahara_id);
                        if ($update_stmt->execute()) {
                            $success['password'] = 'Password berhasil diubah.';
                        } else {
                            $errors['password'] = 'Gagal mengubah password.';
                        }
                        $update_stmt->close();
                    }
                    $verify_stmt->close();
                }
                break;

            case 'ubah_pin':
                $old_pin = $_POST['old_pin'] ?? '';
                $new_pin = $_POST['new_pin'] ?? '';
                $confirm_pin = $_POST['confirm_pin'] ?? '';

                if (empty($new_pin) || strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
                    $errors['pin'] = 'PIN harus 6 digit angka.';
                } elseif ($new_pin !== $confirm_pin) {
                    $errors['pin'] = 'Konfirmasi PIN tidak cocok.';
                } else {
                    // Cek apakah sudah set PIN atau belum
                    $verify_query = "SELECT pin, has_pin FROM user_security WHERE user_id = ?";
                    $verify_stmt = $conn->prepare($verify_query);
                    $verify_stmt->bind_param("i", $bendahara_id);
                    $verify_stmt->execute();
                    $verify_result = $verify_stmt->get_result();
                    $user_sec = $verify_result->fetch_assoc();

                    if ($user_sec['pin'] !== null && $user_sec['has_pin'] == 1) {
                        // Sudah punya PIN, verifikasi old_pin
                        if (empty($old_pin)) {
                            $errors['pin'] = 'PIN lama harus diisi.';
                        } elseif (!password_verify($old_pin, $user_sec['pin'])) {
                            $errors['pin'] = 'PIN lama salah.';
                        } else {
                            // Lanjut ubah
                            $hashed_pin = password_hash($new_pin, PASSWORD_BCRYPT);
                            $update_query = "UPDATE user_security SET pin = ?, has_pin = 1 WHERE user_id = ?";
                            $update_stmt = $conn->prepare($update_query);
                            $update_stmt->bind_param("si", $hashed_pin, $bendahara_id);
                            if ($update_stmt->execute()) {
                                $success['pin'] = 'PIN berhasil diubah.';
                            } else {
                                $errors['pin'] = 'Gagal mengubah PIN.';
                            }
                            $update_stmt->close();
                        }
                    } else {
                        // Belum punya PIN, langsung set baru tanpa old_pin
                        $hashed_pin = password_hash($new_pin, PASSWORD_BCRYPT);
                        $update_query = "UPDATE user_security SET pin = ?, has_pin = 1 WHERE user_id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("si", $hashed_pin, $bendahara_id);
                        if ($update_stmt->execute()) {
                            $success['pin'] = 'PIN berhasil ditambahkan.';
                        } else {
                            $errors['pin'] = 'Gagal menambahkan PIN.';
                        }
                        $update_stmt->close();
                    }
                    $verify_stmt->close();
                }
                break;

            case 'ubah_email':
                $new_email = trim($_POST['new_email'] ?? '');
                if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Email tidak valid.';
                } else {
                    // Cek email unique
                    $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param("si", $new_email, $bendahara_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($check_result->num_rows > 0) {
                        $errors['email'] = 'Email sudah digunakan.';
                    } else {
                        $update_query = "UPDATE users SET email = ?, email_status = 'terisi' WHERE id = ?";
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param("si", $new_email, $bendahara_id);
                        if ($update_stmt->execute()) {
                            $success['email'] = 'Email berhasil diubah.';
                        } else {
                            $errors['email'] = 'Gagal mengubah email.';
                        }
                        $update_stmt->close();
                    }
                    $check_stmt->close();
                }
                break;
        }

        // Regenerate CSRF token after submit
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ============================================
// AMBIL DATA SAAT INI
// ============================================
$query = "SELECT u.username, u.email, us.pin, us.has_pin
          FROM users u 
          LEFT JOIN user_security us ON u.id = us.user_id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $bendahara_id);
$stmt->execute();
$result = $stmt->get_result();
$current_data = $result->fetch_assoc();
$stmt->close();

// Jika belum punya PIN, form tidak tampilkan field old_pin
$has_pin = $current_data['has_pin'] ?? 0;
$pin_set = ($current_data['pin'] !== null && $has_pin == 1);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Akun - Schobank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo ASSETS_PATH; ?>/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">Pengaturan Akun Bendahara</h1>
                <p>Selamat datang, <?php echo htmlspecialchars($nama); ?>!</p>
            </div>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($errors['general']); ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <!-- Ubah Username -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Ubah Username</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="ubah_username">
                            <div class="mb-3">
                                <label for="new_username" class="form-label">Username Baru</label>
                                <input type="text" class="form-control" id="new_username" name="new_username" value="<?php echo htmlspecialchars($current_data['username']); ?>" required>
                            </div>
                            <?php if (isset($errors['username'])): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($errors['username']); ?></div>
                            <?php endif; ?>
                            <?php if (isset($success['username'])): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success['username']); ?></div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Simpan Username</button>
                        </form>
                    </div>
                </div>

                <!-- Ubah Password -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Ubah Password</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="ubah_password">
                            <div class="mb-3">
                                <label for="old_password" class="form-label">Password Lama</label>
                                <input type="password" class="form-control" id="old_password" name="old_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="new_password" class="form-label">Password Baru</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Konfirmasi Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($errors['password']); ?></div>
                            <?php endif; ?>
                            <?php if (isset($success['password'])): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success['password']); ?></div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Simpan Password</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <!-- Ubah PIN -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><?php echo $pin_set ? 'Ubah PIN' : 'Tambah PIN'; ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="ubah_pin">
                            <?php if ($pin_set): ?>
                                <div class="mb-3">
                                    <label for="old_pin" class="form-label">PIN Lama (6 digit)</label>
                                    <input type="password" class="form-control" id="old_pin" name="old_pin" maxlength="6" required>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label for="new_pin" class="form-label">PIN Baru (6 digit)</label>
                                <input type="password" class="form-control" id="new_pin" name="new_pin" maxlength="6" required>
                            </div>
                            <div class="mb-3">
                                <label for="confirm_pin" class="form-label">Konfirmasi PIN</label>
                                <input type="password" class="form-control" id="confirm_pin" name="confirm_pin" maxlength="6" required>
                            </div>
                            <?php if (isset($errors['pin'])): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($errors['pin']); ?></div>
                            <?php endif; ?>
                            <?php if (isset($success['pin'])): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success['pin']); ?></div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Simpan PIN</button>
                        </form>
                    </div>
                </div>

                <!-- Ubah Email -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Ubah Email</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="ubah_email">
                            <div class="mb-3">
                                <label for="new_email" class="form-label">Email Baru</label>
                                <input type="email" class="form-control" id="new_email" name="new_email" value="<?php echo htmlspecialchars($current_data['email'] ?? ''); ?>" required>
                            </div>
                            <?php if (isset($errors['email'])): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($errors['email']); ?></div>
                            <?php endif; ?>
                            <?php if (isset($success['email'])): ?>
                                <div class="alert alert-success"><?php echo htmlspecialchars($success['email']); ?></div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary">Simpan Email</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>