<?php
/**
 * Pengaturan Akun Bendahara - Settings Sidebar Layout with PIN
 * File: pages/bendahara/pengaturan.php
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Jakarta');

$current_dir = dirname(__FILE__);
$project_root = dirname(dirname($current_dir));
if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', $project_root);
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

if (session_status() === PHP_SESSION_NONE)
    session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'bendahara') {
    header('Location: ../login.php');
    exit();
}

$bendahara_id = $_SESSION['user_id'];
$nama = $_SESSION['nama'] ?? 'Bendahara';

if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];

$errors = [];
$success = [];

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors['general'] = 'Token CSRF tidak valid.';
    } else {
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'ubah_username':
                $new_username = trim($_POST['new_username'] ?? '');
                if (empty($new_username)) {
                    $errors['username'] = 'Username tidak boleh kosong.';
                } elseif (strlen($new_username) < 3) {
                    $errors['username'] = 'Username minimal 3 karakter.';
                } else {
                    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                    $check->bind_param("si", $new_username, $bendahara_id);
                    $check->execute();
                    if ($check->get_result()->num_rows > 0) {
                        $errors['username'] = 'Username sudah digunakan.';
                    } else {
                        $u = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                        $u->bind_param("si", $new_username, $bendahara_id);
                        if ($u->execute()) {
                            $_SESSION['username'] = $new_username;
                            $success['username'] = 'Username berhasil diubah.';
                        } else {
                            $errors['username'] = 'Gagal mengubah username.';
                        }
                    }
                }
                break;

            case 'ubah_password':
                $old_password = $_POST['old_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';

                if (empty($old_password)) {
                    $errors['password'] = 'Password lama harus diisi.';
                } elseif (strlen($new_password) < 6) {
                    $errors['password'] = 'Password baru minimal 6 karakter.';
                } elseif ($new_password !== $confirm_password) {
                    $errors['password'] = 'Konfirmasi password tidak cocok.';
                } else {
                    $v = $conn->prepare("SELECT password FROM users WHERE id = ?");
                    $v->bind_param("i", $bendahara_id);
                    $v->execute();
                    $user = $v->get_result()->fetch_assoc();
                    if (!password_verify($old_password, $user['password'])) {
                        $errors['password'] = 'Password lama salah.';
                    } else {
                        $hash = password_hash($new_password, PASSWORD_BCRYPT);
                        $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $u->bind_param("si", $hash, $bendahara_id);
                        if ($u->execute()) {
                            $success['password'] = 'Password berhasil diubah.';
                        } else {
                            $errors['password'] = 'Gagal mengubah password.';
                        }
                    }
                }
                break;

            case 'ubah_pin':
                $old_pin = $_POST['old_pin'] ?? '';
                $new_pin = $_POST['new_pin'] ?? '';
                $confirm_pin = $_POST['confirm_pin'] ?? '';

                if (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
                    $errors['pin'] = 'PIN harus 6 digit angka.';
                } elseif ($new_pin !== $confirm_pin) {
                    $errors['pin'] = 'Konfirmasi PIN tidak cocok.';
                } else {
                    $v = $conn->prepare("SELECT pin, has_pin FROM user_security WHERE user_id = ?");
                    $v->bind_param("i", $bendahara_id);
                    $v->execute();
                    $sec = $v->get_result()->fetch_assoc();

                    if ($sec && $sec['pin'] !== null && $sec['has_pin'] == 1) {
                        if (empty($old_pin)) {
                            $errors['pin'] = 'PIN lama harus diisi.';
                        } elseif (!password_verify($old_pin, $sec['pin'])) {
                            $errors['pin'] = 'PIN lama salah.';
                        } else {
                            $hash = password_hash($new_pin, PASSWORD_BCRYPT);
                            $u = $conn->prepare("UPDATE user_security SET pin = ?, has_pin = 1 WHERE user_id = ?");
                            $u->bind_param("si", $hash, $bendahara_id);
                            $u->execute() ? $success['pin'] = 'PIN berhasil diubah.' : $errors['pin'] = 'Gagal mengubah PIN.';
                        }
                    } else {
                        $hash = password_hash($new_pin, PASSWORD_BCRYPT);
                        // Check if user_security record exists
                        $check = $conn->prepare("SELECT user_id FROM user_security WHERE user_id = ?");
                        $check->bind_param("i", $bendahara_id);
                        $check->execute();
                        if ($check->get_result()->num_rows > 0) {
                            $u = $conn->prepare("UPDATE user_security SET pin = ?, has_pin = 1 WHERE user_id = ?");
                        } else {
                            $u = $conn->prepare("INSERT INTO user_security (user_id, pin, has_pin) VALUES (?, ?, 1)");
                        }
                        $u->bind_param("is", $bendahara_id, $hash);
                        $u->execute() ? $success['pin'] = 'PIN berhasil ditambahkan.' : $errors['pin'] = 'Gagal menambahkan PIN.';
                    }
                }
                break;

            case 'ubah_email':
                $new_email = trim($_POST['new_email'] ?? '');
                if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Email tidak valid.';
                } else {
                    if (!empty($new_email)) {
                        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                        $check->bind_param("si", $new_email, $bendahara_id);
                        $check->execute();
                        if ($check->get_result()->num_rows > 0) {
                            $errors['email'] = 'Email sudah digunakan.';
                        }
                    }
                    if (!isset($errors['email'])) {
                        $u = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $u->bind_param("si", $new_email, $bendahara_id);
                        $u->execute() ? $success['email'] = 'Email berhasil diubah.' : $errors['email'] = 'Gagal mengubah email.';
                    }
                }
                break;
        }
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $token = $_SESSION['csrf_token'];
    }
}

// Fetch current data
$query = "SELECT u.username, u.email, u.nama, us.pin, us.has_pin
          FROM users u 
          LEFT JOIN user_security us ON u.id = us.user_id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $bendahara_id);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();

$has_pin = $current_user['has_pin'] ?? 0;
$pin_set = ($current_user['pin'] !== null && $has_pin == 1);

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/schobank';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $base_url ?>/assets/images/tab.png">
    <title>Pengaturan | Dashboard Bendahara</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --bg-light: #f8fafc;
            --success: #059669;
            --danger: #dc2626;
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background: var(--bg-light);
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            max-width: calc(100% - 280px);
            min-height: 100vh;
            background: var(--bg-light);
        }

        .page-title-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
        }

        .content-wrapper {
            padding: 30px;
        }

        .settings-container {
            display: flex;
            background: white;
            border-radius: 12px;
            border: 1px solid var(--gray-200);
            overflow: hidden;
            min-height: 450px;
        }

        .settings-sidebar {
            width: 220px;
            border-right: 1px solid var(--gray-200);
            padding: 1rem 0;
            flex-shrink: 0;
        }

        .sidebar-category {
            padding: 0.5rem 1.25rem;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.75rem 1.25rem;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.9rem;
        }

        .sidebar-item:hover {
            background: var(--gray-100);
            color: var(--gray-800);
        }

        .sidebar-item.active {
            background: var(--gray-100);
            color: var(--gray-800);
            border-left-color: var(--gray-700);
            font-weight: 500;
        }

        .sidebar-item i {
            width: 18px;
            text-align: center;
        }

        .settings-content {
            flex: 1;
            padding: 2rem;
        }

        .content-panel {
            display: none;
        }

        .content-panel.active {
            display: block;
        }

        .panel-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.5rem;
        }

        .panel-desc {
            color: var(--gray-500);
            font-size: 0.875rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
        }

        .form-input {
            width: 100%;
            max-width: 400px;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--gray-500);
            box-shadow: 0 0 0 3px rgba(100, 116, 139, 0.1);
        }

        .form-input:disabled {
            background: var(--gray-100);
            color: var(--gray-500);
        }

        .form-hint {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-top: 0.5rem;
        }

        .divider {
            height: 1px;
            background: var(--gray-200);
            margin: 1.5rem 0;
        }

        .btn-save {
            background: linear-gradient(135deg, var(--gray-700), var(--gray-600));
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-save:hover {
            background: linear-gradient(135deg, var(--gray-800), var(--gray-700));
            transform: translateY(-1px);
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius);
            margin-bottom: 1rem;
            font-size: 0.875rem;
            max-width: 400px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                max-width: 100%;
            }

            .settings-container {
                flex-direction: column;
            }

            .settings-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--gray-200);
                display: flex;
                flex-wrap: wrap;
                padding: 0.5rem;
                gap: 0.5rem;
            }

            .sidebar-category {
                width: 100%;
                padding: 0.5rem;
            }

            .sidebar-item {
                padding: 0.5rem 1rem;
                border-left: none;
                border-radius: 6px;
                font-size: 0.8rem;
            }

            .sidebar-item.active {
                background: var(--gray-700);
                color: white;
            }
        }

        @media (max-width: 768px) {
            .page-hamburger {
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .page-title-section {
                padding: 1rem;
            }

            .content-wrapper {
                padding: 20px;
            }

            .settings-content {
                padding: 1.25rem;
            }

            .form-input {
                max-width: 100%;
            }
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/sidebar_bendahara.php'; ?>

    <div class="main-content">
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Pengaturan</h1>
                <p class="page-subtitle">Kelola akun dan keamanan - <?= htmlspecialchars($nama) ?></p>
            </div>
        </div>

        <div class="content-wrapper">
            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
            <?php endif; ?>

            <div class="settings-container">
                <div class="settings-sidebar">
                    <div class="sidebar-category">Akun</div>
                    <div class="sidebar-item active" data-panel="profile">
                        <i class="fas fa-user"></i> Profil
                    </div>
                    <div class="sidebar-item" data-panel="username">
                        <i class="fas fa-at"></i> Username
                    </div>
                    <div class="sidebar-item" data-panel="email">
                        <i class="fas fa-envelope"></i> Email
                    </div>

                    <div class="sidebar-category">Keamanan</div>
                    <div class="sidebar-item" data-panel="password">
                        <i class="fas fa-lock"></i> Password
                    </div>
                    <div class="sidebar-item" data-panel="pin">
                        <i class="fas fa-key"></i> PIN
                    </div>
                </div>

                <div class="settings-content">
                    <!-- Profile Panel -->
                    <div class="content-panel active" id="panel-profile">
                        <h2 class="panel-title">Profil</h2>
                        <p class="panel-desc">Informasi profil akun bendahara</p>

                        <div class="form-group">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-input"
                                value="<?= htmlspecialchars($current_user['nama'] ?? 'Bendahara') ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-input"
                                value="<?= htmlspecialchars($current_user['username']) ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="text" class="form-input"
                                value="<?= htmlspecialchars($current_user['email'] ?? '-') ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status PIN</label>
                            <input type="text" class="form-input"
                                value="<?= $pin_set ? 'Sudah diatur' : 'Belum diatur' ?>" disabled>
                        </div>
                    </div>

                    <!-- Username Panel -->
                    <div class="content-panel" id="panel-username">
                        <h2 class="panel-title">Ubah Username</h2>
                        <p class="panel-desc">Perbarui username untuk login</p>

                        <?php if (isset($success['username'])): ?>
                            <div class="alert alert-success"><?= $success['username'] ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors['username'])): ?>
                            <div class="alert alert-danger"><?= $errors['username'] ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $token ?>">
                            <input type="hidden" name="action" value="ubah_username">

                            <div class="form-group">
                                <label class="form-label">Username Baru</label>
                                <input type="text" class="form-input" name="new_username"
                                    value="<?= htmlspecialchars($current_user['username']) ?>" required>
                            </div>

                            <button type="submit" class="btn-save">Simpan Username</button>
                        </form>
                    </div>

                    <!-- Email Panel -->
                    <div class="content-panel" id="panel-email">
                        <h2 class="panel-title">Ubah Email</h2>
                        <p class="panel-desc">Perbarui alamat email</p>

                        <?php if (isset($success['email'])): ?>
                            <div class="alert alert-success"><?= $success['email'] ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors['email'])): ?>
                            <div class="alert alert-danger"><?= $errors['email'] ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $token ?>">
                            <input type="hidden" name="action" value="ubah_email">

                            <div class="form-group">
                                <label class="form-label">Email Baru</label>
                                <input type="email" class="form-input" name="new_email"
                                    value="<?= htmlspecialchars($current_user['email'] ?? '') ?>"
                                    placeholder="contoh@email.com">
                            </div>

                            <button type="submit" class="btn-save">Simpan Email</button>
                        </form>
                    </div>

                    <!-- Password Panel -->
                    <div class="content-panel" id="panel-password">
                        <h2 class="panel-title">Ubah Password</h2>
                        <p class="panel-desc">Perbarui password untuk keamanan akun</p>

                        <?php if (isset($success['password'])): ?>
                            <div class="alert alert-success"><?= $success['password'] ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors['password'])): ?>
                            <div class="alert alert-danger"><?= $errors['password'] ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $token ?>">
                            <input type="hidden" name="action" value="ubah_password">

                            <div class="form-group">
                                <label class="form-label">Password Lama</label>
                                <input type="password" class="form-input" name="old_password" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Password Baru</label>
                                <input type="password" class="form-input" name="new_password"
                                    placeholder="Minimal 6 karakter" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Konfirmasi Password</label>
                                <input type="password" class="form-input" name="confirm_password" required>
                            </div>

                            <button type="submit" class="btn-save">Simpan Password</button>
                        </form>
                    </div>

                    <!-- PIN Panel -->
                    <div class="content-panel" id="panel-pin">
                        <h2 class="panel-title"><?= $pin_set ? 'Ubah PIN' : 'Tambah PIN' ?></h2>
                        <p class="panel-desc">PIN digunakan untuk verifikasi transaksi penting</p>

                        <?php if (isset($success['pin'])): ?>
                            <div class="alert alert-success"><?= $success['pin'] ?></div>
                        <?php endif; ?>
                        <?php if (isset($errors['pin'])): ?>
                            <div class="alert alert-danger"><?= $errors['pin'] ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= $token ?>">
                            <input type="hidden" name="action" value="ubah_pin">

                            <?php if ($pin_set): ?>
                                <div class="form-group">
                                    <label class="form-label">PIN Lama (6 digit)</label>
                                    <input type="password" class="form-input" name="old_pin" maxlength="6"
                                        pattern="[0-9]{6}" required>
                                </div>
                            <?php endif; ?>

                            <div class="form-group">
                                <label class="form-label">PIN Baru (6 digit)</label>
                                <input type="password" class="form-input" name="new_pin" maxlength="6"
                                    pattern="[0-9]{6}" placeholder="6 digit angka" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Konfirmasi PIN</label>
                                <input type="password" class="form-input" name="confirm_pin" maxlength="6"
                                    pattern="[0-9]{6}" required>
                            </div>

                            <button type="submit" class="btn-save">Simpan PIN</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle
        document.getElementById('pageHamburgerBtn')?.addEventListener('click', function (e) {
            e.stopPropagation();
            document.getElementById('sidebar')?.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        });

        document.addEventListener('click', function (e) {
            const sidebar = document.getElementById('sidebar');
            const btn = document.getElementById('pageHamburgerBtn');
            if (sidebar?.classList.contains('active') && !sidebar.contains(e.target) && !btn?.contains(e.target)) {
                sidebar.classList.remove('active');
                document.body.classList.remove('sidebar-open');
            }
        });

        // Settings Tab Navigation
        document.querySelectorAll('.sidebar-item').forEach(item => {
            item.addEventListener('click', function () {
                document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                document.querySelectorAll('.content-panel').forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                document.getElementById('panel-' + this.dataset.panel).classList.add('active');
            });
        });

        // Show success/error messages with SweetAlert
        <?php if (!empty($success)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: '<?= reset($success) ?>',
                confirmButtonColor: '#334155'
            });
        <?php endif; ?>

        <?php if (!empty($errors) && !isset($errors['general'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?= reset($errors) ?>',
                confirmButtonColor: '#334155'
            });
        <?php endif; ?>
    </script>
</body>

</html>