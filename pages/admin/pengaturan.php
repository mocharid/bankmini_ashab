<?php
/**
 * Pengaturan Akun Admin - Settings Sidebar Layout
 * File: pages/admin/pengaturan.php
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
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];

$query = "SELECT username, email, nama FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$current_user = $stmt->get_result()->fetch_assoc();

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Token tidak valid']);
        exit;
    }

    $password_lama = trim($_POST['password_lama'] ?? '');
    $q = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $q->bind_param("i", $_SESSION['user_id']);
    $q->execute();
    $stored = $q->get_result()->fetch_assoc()['password'];

    if (!password_verify($password_lama, $stored)) {
        echo json_encode(['status' => 'error', 'message' => 'Password saat ini salah!']);
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'update_username') {
        $val = trim($_POST['new_value'] ?? '');
        if (empty($val)) {
            echo json_encode(['status' => 'error', 'message' => 'Username tidak boleh kosong!']);
            exit;
        }
        $c = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $c->bind_param("si", $val, $_SESSION['user_id']);
        $c->execute();
        if ($c->get_result()->num_rows > 0) {
            echo json_encode(['status' => 'error', 'message' => 'Username sudah digunakan!']);
            exit;
        }
        $u = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $u->bind_param("si", $val, $_SESSION['user_id']);
        $u->execute();
        session_destroy();
        echo json_encode(['status' => 'success', 'message' => 'Username berhasil diperbarui! Silakan login kembali.', 'logout' => true]);
        exit;
    }

    if ($action === 'update_email') {
        $val = trim($_POST['new_value'] ?? '');
        if (!empty($val) && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Format email tidak valid!']);
            exit;
        }
        $u = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        $u->bind_param("si", $val, $_SESSION['user_id']);
        $u->execute();
        echo json_encode(['status' => 'success', 'message' => 'Email berhasil diperbarui!', 'reload' => true]);
        exit;
    }

    if ($action === 'update_password') {
        $val = trim($_POST['new_value'] ?? '');
        $confirm = trim($_POST['confirm_value'] ?? '');
        if (strlen($val) < 8) {
            echo json_encode(['status' => 'error', 'message' => 'Password minimal 8 karakter!']);
            exit;
        }
        if (!preg_match('/[A-Z]/', $val)) {
            echo json_encode(['status' => 'error', 'message' => 'Password harus ada huruf kapital!']);
            exit;
        }
        if (!preg_match('/[0-9]/', $val)) {
            echo json_encode(['status' => 'error', 'message' => 'Password harus ada angka!']);
            exit;
        }
        if ($val !== $confirm) {
            echo json_encode(['status' => 'error', 'message' => 'Konfirmasi password tidak cocok!']);
            exit;
        }
        $hash = password_hash($val, PASSWORD_BCRYPT);
        $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $u->bind_param("si", $hash, $_SESSION['user_id']);
        $u->execute();
        session_destroy();
        echo json_encode(['status' => 'success', 'message' => 'Password berhasil diperbarui!', 'logout' => true]);
        exit;
    }
    exit;
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$base_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/schobank';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $base_url ?>/assets/images/tab.png">
    <title>Pengaturan | Dashboard Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            --bg-white: #ffffff;
            --bg-light: #f8fafc;
            --border-light: #e5e7eb;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
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

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
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
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
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
            padding: 1.5rem 2rem;
        }

        /* Settings Container - Card Style */
        .settings-container {
            display: flex;
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-100);
            overflow: hidden;
            min-height: 450px;
        }

        /* Sidebar Menu */
        .settings-sidebar {
            width: 220px;
            background: var(--gray-100);
            border-right: 1px solid var(--gray-200);
            padding: 1.25rem 0;
            flex-shrink: 0;
        }

        .sidebar-category {
            padding: 0.75rem 1.25rem 0.5rem;
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
            background: rgba(255, 255, 255, 0.7);
            color: var(--gray-800);
        }

        .sidebar-item.active {
            background: white;
            color: var(--gray-800);
            border-left-color: var(--gray-600);
            font-weight: 500;
        }

        .sidebar-item i {
            width: 18px;
            text-align: center;
            font-size: 0.85rem;
        }

        /* Content Panel */
        .settings-content {
            flex: 1;
            padding: 2rem;
            background: white;
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
            background: #fff;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
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
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-save:hover {
            background: linear-gradient(135deg, var(--gray-800), var(--gray-700));
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .main-content {
                margin-left: 0;
                max-width: 100%;
            }

            .content-wrapper {
                padding: 1rem;
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
                padding: 0.75rem;
                gap: 0.5rem;
            }

            .sidebar-category {
                width: 100%;
                padding: 0.5rem 0.75rem;
            }

            .sidebar-item {
                padding: 0.5rem 1rem;
                border-left: none;
                border-radius: 6px;
                font-size: 0.8rem;
            }

            .sidebar-item.active {
                background: var(--gray-600);
                color: white;
                border-left-color: transparent;
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

            .page-title {
                font-size: 1.25rem;
            }

            .page-subtitle {
                font-size: 0.85rem;
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
            backdrop-filter: blur(5px);
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Pengaturan</h1>
                <p class="page-subtitle">Kelola informasi akun dan keamanan</p>
            </div>
        </div>

        <div class="content-wrapper">
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
                        <i class="fas fa-lock"></i> Ubah Password
                    </div>
                </div>

                <div class="settings-content">
                    <!-- Profile Panel -->
                    <div class="content-panel active" id="panel-profile">
                        <h2 class="panel-title">Profil</h2>
                        <p class="panel-desc">Informasi profil akun administrator</p>

                        <div class="form-group">
                            <label class="form-label">Nama Lengkap</label>
                            <input type="text" class="form-input"
                                value="<?= htmlspecialchars($current_user['nama'] ?? 'Administrator') ?>" disabled>
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
                    </div>

                    <!-- Username Panel -->
                    <div class="content-panel" id="panel-username">
                        <h2 class="panel-title">Ubah Username</h2>
                        <p class="panel-desc">Perbarui username untuk login akun administrator</p>

                        <form id="formUsername">
                            <div class="form-group">
                                <label class="form-label">Username Baru</label>
                                <input type="text" class="form-input" name="new_value"
                                    value="<?= htmlspecialchars($current_user['username']) ?>" required>
                            </div>

                            <div class="divider"></div>

                            <div class="form-group">
                                <label class="form-label">Password Saat Ini</label>
                                <input type="password" class="form-input" name="password_lama"
                                    placeholder="Masukkan password untuk konfirmasi" required>
                                <p class="form-hint">Wajib diisi untuk keamanan</p>
                            </div>

                            <button type="submit" class="btn-save">Simpan Perubahan</button>
                        </form>
                    </div>

                    <!-- Email Panel -->
                    <div class="content-panel" id="panel-email">
                        <h2 class="panel-title">Ubah Email</h2>
                        <p class="panel-desc">Perbarui alamat email administrator</p>

                        <form id="formEmail">
                            <div class="form-group">
                                <label class="form-label">Email Baru</label>
                                <input type="email" class="form-input" name="new_value"
                                    value="<?= htmlspecialchars($current_user['email'] ?? '') ?>"
                                    placeholder="contoh@email.com">
                            </div>

                            <div class="divider"></div>

                            <div class="form-group">
                                <label class="form-label">Password Saat Ini</label>
                                <input type="password" class="form-input" name="password_lama"
                                    placeholder="Masukkan password untuk konfirmasi" required>
                            </div>

                            <button type="submit" class="btn-save">Simpan Perubahan</button>
                        </form>
                    </div>

                    <!-- Password Panel -->
                    <div class="content-panel" id="panel-password">
                        <h2 class="panel-title">Ubah Password</h2>
                        <p class="panel-desc">Perbarui password untuk keamanan akun</p>

                        <form id="formPassword">
                            <div class="form-group">
                                <label class="form-label">Password Baru</label>
                                <input type="password" class="form-input" name="new_value"
                                    placeholder="Minimal 8 karakter, huruf kapital, dan angka" required>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Konfirmasi Password Baru</label>
                                <input type="password" class="form-input" name="confirm_value"
                                    placeholder="Ulangi password baru" required>
                            </div>

                            <div class="divider"></div>

                            <div class="form-group">
                                <label class="form-label">Password Saat Ini</label>
                                <input type="password" class="form-input" name="password_lama"
                                    placeholder="Masukkan password lama untuk konfirmasi" required>
                            </div>

                            <button type="submit" class="btn-save">Simpan Perubahan</button>
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

        const csrfToken = '<?= $token ?>';

        function submitForm(action, formData) {
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);

            $.ajax({
                url: '',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function (res) {
                    if (res.status === 'success') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: res.message,
                            confirmButtonColor: '#334155'
                        }).then(() => {
                            if (res.logout) window.location.href = '../login.php';
                            else if (res.reload) location.reload();
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: res.message,
                            confirmButtonColor: '#334155'
                        });
                    }
                }
            });
        }

        $('#formUsername').submit(function (e) {
            e.preventDefault();
            submitForm('update_username', new FormData(this));
        });

        $('#formEmail').submit(function (e) {
            e.preventDefault();
            submitForm('update_email', new FormData(this));
        });

        $('#formPassword').submit(function (e) {
            e.preventDefault();
            submitForm('update_password', new FormData(this));
        });
    </script>
</body>

</html>