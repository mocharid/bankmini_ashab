<?php
/**
 * Pengaturan Akun Petugas - Settings Sidebar Layout
 * File: pages/petugas/pengaturan_akun.php
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
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
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
    <title>Pengaturan Akun | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-50: #f9fafb;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --border-light: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --radius: 0.5rem;
            --transition: all 0.3s ease;
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar State */
        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-active::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            transition: opacity 0.3s ease;
            opacity: 1;
        }

        body:not(.sidebar-active):not(.sidebar-open)::before {
            opacity: 0;
            pointer-events: none;
        }

        /* Layout */
        .main-content {
            margin-left: 280px;
            padding: 2rem;
            flex: 1;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
            background: var(--bg-light);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
        }

        /* Page Title */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content h1 {
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
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        /* Cards & Containers */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        /* Settings Specific Layout */
        .settings-container {
            display: flex;
            background: white;
            border-radius: 16px;
            border: 1px solid var(--gray-200);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            min-height: 500px;
        }

        .settings-sidebar {
            width: 240px;
            border-right: 1px solid var(--gray-200);
            padding: 1.5rem 0;
            flex-shrink: 0;
            background: var(--gray-50);
        }

        .sidebar-category {
            padding: 0.5rem 1.5rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 0.5rem;
        }

        .sidebar-category:first-child {
            margin-top: 0;
        }

        .sidebar-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.75rem 1.5rem;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .sidebar-item:hover {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .sidebar-item.active {
            background: white;
            color: var(--gray-900);
            border-left-color: var(--gray-800);
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .sidebar-item i {
            width: 20px;
            text-align: center;
        }

        .settings-content {
            flex: 1;
            padding: 2.5rem;
        }

        .content-panel {
            display: none;
        }

        .content-panel.active {
            display: block;
        }

        .panel-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .panel-desc {
            color: var(--gray-500);
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--gray-700);
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
            color: var(--gray-800);
            height: 48px;
        }

        .form-input:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        .form-input:disabled {
            background: var(--gray-100);
            color: var(--gray-500);
            cursor: not-allowed;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 0.5rem;
        }

        .btn-save {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn-save:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .divider {
            height: 1px;
            background: var(--gray-200);
            margin: 2rem 0;
        }

        @media (max-width: 1024px) {
            .page-hamburger {
                display: block;
            }

            .page-title-section {
                padding: 1rem 1.5rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .settings-container {
                flex-direction: column;
            }

            .settings-sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--gray-200);
                padding: 1rem;
                display: flex;
                overflow-x: auto;
                gap: 0.5rem;
                background: white;
            }

            .sidebar-category {
                display: none;
            }

            .sidebar-item {
                border-left: none;
                border-bottom: 2px solid transparent;
                padding: 0.5rem 1rem;
                flex-shrink: 0;
                border-radius: 8px;
                background: var(--gray-50);
            }

            .sidebar-item.active {
                border-left: none;
                background: var(--gray-800);
                color: white;
            }

            .settings-content {
                padding: 1.5rem;
            }

            .form-input {
                max-width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- New Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Pengaturan Akun</h1>
                <p class="page-subtitle">Kelola informasi profil dan keamanan akun Anda</p>
            </div>
        </div>

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
                    <h2 class="panel-title">Profil Saya</h2>
                    <p class="panel-desc">Informasi dasar akun Anda yang terdaftar di sistem.</p>

                    <div class="form-group">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($current_user['nama']) ?>"
                            disabled>
                        <p class="form-hint">Nama sesuai data pegawai. Hubungi admin jika terdapat kesalahan.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-input" value="<?= htmlspecialchars($current_user['username']) ?>"
                            disabled>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Email Terdaftar</label>
                        <input type="text" class="form-input"
                            value="<?= htmlspecialchars($current_user['email'] ?? '-') ?>" disabled>
                    </div>
                </div>

                <!-- Username Panel -->
                <div class="content-panel" id="panel-username">
                    <h2 class="panel-title">Ubah Username</h2>
                    <p class="panel-desc">Ganti username yang digunakan untuk masuk ke dalam sistem.</p>

                    <form id="formUsername">
                        <div class="form-group">
                            <label class="form-label">Username Baru</label>
                            <input type="text" class="form-input" name="new_value"
                                value="<?= htmlspecialchars($current_user['username']) ?>" required
                                placeholder="Masukkan username baru">
                        </div>

                        <div class="divider"></div>

                        <div class="form-group">
                            <label class="form-label">Konfirmasi Password</label>
                            <input type="password" class="form-input" name="password_lama"
                                placeholder="Masukkan password Anda saat ini" required>
                            <p class="form-hint">Kami memerlukan password Anda untuk memverifikasi perubahan ini.</p>
                        </div>

                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Simpan Username</button>
                    </form>
                </div>

                <!-- Email Panel -->
                <div class="content-panel" id="panel-email">
                    <h2 class="panel-title">Ubah Email</h2>
                    <p class="panel-desc">Perbarui alamat email untuk notifikasi dan pemulihan akun.</p>

                    <form id="formEmail">
                        <div class="form-group">
                            <label class="form-label">Email Baru</label>
                            <input type="email" class="form-input" name="new_value"
                                value="<?= htmlspecialchars($current_user['email'] ?? '') ?>"
                                placeholder="contoh@email.com">
                        </div>

                        <div class="divider"></div>

                        <div class="form-group">
                            <label class="form-label">Konfirmasi Password</label>
                            <input type="password" class="form-input" name="password_lama"
                                placeholder="Masukkan password Anda saat ini" required>
                        </div>

                        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Simpan Email</button>
                    </form>
                </div>

                <!-- Password Panel -->
                <div class="content-panel" id="panel-password">
                    <h2 class="panel-title">Ubah Password</h2>
                    <p class="panel-desc">Pastikan akun Anda tetap aman dengan password yang kuat.</p>

                    <form id="formPassword">
                        <div class="form-group">
                            <label class="form-label">Password Baru</label>
                            <input type="password" class="form-input" name="new_value"
                                placeholder="Minimal 8 karakter, huruf kapital, dan angka" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Ulangi Password Baru</label>
                            <input type="password" class="form-input" name="confirm_value"
                                placeholder="Ketik ulang password baru" required>
                        </div>

                        <div class="divider"></div>

                        <div class="form-group">
                            <label class="form-label">Password Lama</label>
                            <input type="password" class="form-input" name="password_lama"
                                placeholder="Masukkan password lama Anda" required>
                        </div>

                        <button type="submit" class="btn-save"><i class="fas fa-key"></i> Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Sidebar Toggle Mobile
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        if (menuToggle) {
            menuToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                // Toggle sidebar classes used in sidebar_petugas.php
                sidebar.classList.toggle('active');
                document.body.classList.toggle('sidebar-active');
            });
        }

        document.addEventListener('click', function (e) {
            if (sidebar && sidebar.classList.contains('active') &&
                !sidebar.contains(e.target) &&
                (!menuToggle || !menuToggle.contains(e.target))) {
                sidebar.classList.remove('active');
                document.body.classList.remove('sidebar-active');
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