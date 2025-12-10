<?php
/**
 * Pengaturan Akun Admin - Adaptive Path Version
 * File: pages/admin/pengaturan.php
 *
 * Compatible with:
 * - Local: schobank/pages/admin/pengaturan.php
 * - Hosting: public_html/pages/admin/pengaturan.php
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages' atau 'admin'
if (basename($current_dir) === 'admin') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
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
// Fallback: pakai current dir
if (!$project_root) {
    $project_root = $current_dir;
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
if (!file_exists(INCLUDES_PATH . '/auth.php')) {
    die('Error: File auth.php tidak ditemukan di ' . INCLUDES_PATH);
}
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('Error: File db_connection.php tidak ditemukan di ' . INCLUDES_PATH);
}
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
// ============================================
// AUTHORIZATION CHECK
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
// Generate or validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['csrf_token'];
// Fetch user data
$query = "SELECT username, email, nama, created_at FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$current_user = $result->fetch_assoc();
if (!$current_user) {
    die("Data pengguna tidak ditemukan.");
}
// Function Masking Username
function maskUsername($username) {
    if (strlen($username) <= 2) return $username;
    $first = $username[0];
    $last = substr($username, -1);
    $len = strlen($username) - 2;
    return $first . str_repeat('*', max(3, $len)) . $last;
}
$masked_username = maskUsername($current_user['username']);
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_account') {
    header('Content-Type: application/json');
  
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Token CSRF tidak valid']);
        exit();
    }
  
    $new_username = trim($_POST['username'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $password_lama = trim($_POST['password_lama'] ?? '');
    $password_baru = trim($_POST['password_baru'] ?? '');
    $konfirmasi_password = trim($_POST['konfirmasi_password'] ?? '');
    // Gunakan data lama jika input kosong
    if (empty($new_username)) $new_username = $current_user['username'];
    if (empty($new_email)) $new_email = $current_user['email'];
    // Validasi format email
    if (!empty($new_email) && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'message' => 'Format email tidak valid!']);
        exit;
    }
    if (empty($password_lama)) {
        echo json_encode(['status' => 'error', 'message' => 'Password Lama wajib diisi untuk konfirmasi!']);
        exit;
    }
    // Verifikasi Password Lama
    $q_check = "SELECT password FROM users WHERE id = ?";
    $stmt_check = $conn->prepare($q_check);
    $stmt_check->bind_param("i", $_SESSION['user_id']);
    $stmt_check->execute();
    $user_data = $stmt_check->get_result()->fetch_assoc();
    $stored_pass = $user_data['password'];
    if (!password_verify($password_lama, $stored_pass)) {
        echo json_encode(['status' => 'error', 'message' => 'Password saat ini salah!']);
        exit;
    }
    // Cek Unik Username & Email
    $check_query = "SELECT id FROM users WHERE (username = ? OR (email = ? AND email != '')) AND id != ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ssi", $new_username, $new_email, $_SESSION['user_id']);
    $check_stmt->execute();
    if ($check_stmt->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username atau Email sudah digunakan!']);
        exit;
    }
    $conn->begin_transaction();
    try {
        $update_sql = "UPDATE users SET username = ?, email = ?";
        $types = "ss";
        $params = [$new_username, $new_email];
        // Update Password jika diisi
        if (!empty($password_baru)) {
            if (strlen($password_baru) < 8) throw new Exception("Password baru minimal 8 karakter.");
            if (!preg_match('/[A-Z]/', $password_baru)) throw new Exception("Password harus ada huruf kapital.");
            if (!preg_match('/[0-9]/', $password_baru)) throw new Exception("Password harus ada angka.");
            if ($password_baru !== $konfirmasi_password) throw new Exception("Konfirmasi password tidak cocok.");
          
            $new_hash = password_hash($password_baru, PASSWORD_BCRYPT);
          
            $update_sql .= ", password = ?";
            $types .= "s";
            $params[] = $new_hash;
        }
        $update_sql .= " WHERE id = ?";
        $types .= "i";
        $params[] = $_SESSION['user_id'];
        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param($types, ...$params);
      
        if (!$stmt_update->execute()) {
            throw new Exception("Gagal mengupdate data.");
        }
        $conn->commit();
        session_destroy();
      
        echo json_encode(['status' => 'success', 'message' => 'Profil berhasil diperbarui! Silakan login kembali.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
// ============================================
// DETECT BASE URL FOR ASSETS
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));
// Deteksi base path (schobank atau public_html)
$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pengaturan Akun | Dashboard Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --text-light: #718096;
            --bg-light: #f7fafc;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #f59e0b;
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --radius: 8px;
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
            display: flex;
            min-height: 100vh;
            font-size: 14px;
            overflow-x: hidden;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            transition: margin-left 0.3s ease;
            width: 100%;
        }
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color));
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .welcome-banner h2 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-banner p {
            margin: 4px 0 0 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        .menu-toggle {
            display: none;
            font-size: 1.5rem;
            cursor: pointer;
            align-self: center;
            margin-right: auto;
        }
        /* Form Container */
        .form-section {
            background: var(--bg-table);
            border-radius: var(--radius);
            padding: 35px;
            box-shadow: var(--shadow-md);
            max-width: 1100px;
            margin: 0 auto;
            width: 100%;
        }
        .section-header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 12px;
        }
        .section-header h3 {
            color: var(--primary-color);
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }
        /* Form Grid - 3 Kolom per Baris */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        label {
            display: block;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        label i {
            color: var(--primary-color);
            width: 18px;
            text-align: center;
            margin-right: 6px;
        }
        .input-wrapper {
            position: relative;
            width: 100%;
        }
        input[type="text"],
        input[type="password"],
        input[type="email"] {
            width: 100%;
            padding: 12px 45px 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: border-color 0.3s, box-shadow 0.3s;
            height: 45px;
            box-sizing: border-box;
        }
        input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        .toggle-pass {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text-light);
            font-size: 1.05rem;
            transition: color 0.3s;
        }
        .toggle-pass:hover { color: var(--primary-color); }
        .username-edit-link {
            margin-top: 6px;
            text-align: right;
        }
        .username-edit-link a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s;
        }
        .username-edit-link a:hover {
            color: var(--secondary-color);
            text-decoration: underline;
        }
        .divider {
            height: 1px;
            background: var(--border-color);
            margin: 30px 0 25px 0;
            position: relative;
        }
        .divider span {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--bg-table);
            padding: 0 15px;
            color: var(--text-light);
            font-size: 0.85rem;
            white-space: nowrap;
            font-weight: 500;
        }
        /* Password Indicator */
        .password-indicator {
            margin-top: 8px;
            font-size: 0.8rem;
            color: var(--text-light);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .indicator-item {
            display: flex;
            align-items: center;
            gap: 6px;
            transition: color 0.3s;
        }
        .indicator-item i { font-size: 0.7rem; }
        .indicator-item.valid { color: var(--success-color); }
        .indicator-item.invalid { color: var(--text-light); }
        /* Button Section - Centered */
        .btn-section {
            text-align: center;
            margin-top: 30px;
        }
        .btn {
            padding: 0 40px;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1rem;
            height: 48px;
            min-width: 200px;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color));
            color: white;
        }
        .btn-primary:hover {
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
            transform: translateY(-2px);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        /* RESPONSIVE */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100vw;
            }
          
            .welcome-banner {
                flex-direction: row;
                align-items: center;
                gap: 15px;
                padding: 20px;
            }
            .welcome-banner h2 {
                font-size: 1.25rem;
            }
            .welcome-banner p {
                font-size: 0.85rem;
            }
          
            .menu-toggle {
                display: block;
            }
            .form-section {
                padding: 20px 15px;
            }
            /* Mobile: 1 Kolom */
            .form-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }
          
            input[type="text"],
            input[type="password"],
            input[type="email"] {
                font-size: 16px; /* Prevent iOS zoom */
            }
            .btn {
                width: 100%;
                min-width: unset;
            }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="banner-content">
                <h2><i class="fas fa-user-shield"></i> Pengaturan Akun Admin</h2>
                <p>Kelola profil dan keamanan akun administrator Anda</p>
            </div>
        </div>
        <div class="form-section">
            <form id="accountForm">
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <div class="section-header">
                    <h3><i class="fas fa-id-card"></i> Informasi Profil</h3>
                </div>
                <!-- BARIS 1: Nama Lengkap | Email | Username -->
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nama Lengkap</label>
                        <div class="input-wrapper">
                            <input type="text" value="<?= htmlspecialchars($current_user['nama'] ?? 'Admin') ?>" disabled style="background-color: #f8fafc; color: #64748b; padding-right: 15px;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <div class="input-wrapper">
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($current_user['email'] ?? '') ?>" placeholder="Email Admin">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username"><i class="fas fa-user-tag"></i> Username</label>
                        <div class="input-wrapper">
                            <input type="text" id="username_display" value="<?= $masked_username ?>" disabled style="background-color: #f8fafc; color: #64748b; padding-right: 15px;">
                            <input type="hidden" name="username" id="username_real" value="<?= htmlspecialchars($current_user['username']) ?>">
                        </div>
                        <div class="username-edit-link">
                            <a href="#" id="editUsernameBtn">Ubah Username?</a>
                        </div>
                    </div>
                </div>
                <div class="divider"><span>Keamanan Password</span></div>
                <!-- BARIS 2: Password Baru | Ulangi Password | Password Lama -->
                <div class="form-grid">
                    <div class="form-group">
                        <label for="password_baru"><i class="fas fa-key"></i> Password Baru</label>
                        <div class="input-wrapper">
                            <input type="password" id="password_baru" name="password_baru" placeholder="Biarkan kosong jika tidak ubah" autocomplete="new-password">
                            <div class="toggle-pass" onclick="togglePassword('password_baru')">
                                <i class="fas fa-eye"></i>
                            </div>
                        </div>
                        <div class="password-indicator" id="passwordRules">
                            <div class="indicator-item invalid" id="rule-length"><i class="fas fa-circle"></i> Min. 8 Karakter</div>
                            <div class="indicator-item invalid" id="rule-number"><i class="fas fa-circle"></i> Mengandung Angka</div>
                            <div class="indicator-item invalid" id="rule-capital"><i class="fas fa-circle"></i> Huruf Kapital</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="konfirmasi_password"><i class="fas fa-check-double"></i> Ulangi Password</label>
                        <div class="input-wrapper">
                            <input type="password" id="konfirmasi_password" name="konfirmasi_password" placeholder="Konfirmasi password baru" autocomplete="new-password">
                            <div class="toggle-pass" onclick="togglePassword('konfirmasi_password')">
                                <i class="fas fa-eye"></i>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password_lama" style="color: var(--danger-color);"><i class="fas fa-lock" style="color: var(--danger-color);"></i> Password Lama <span style="color: var(--danger-color);">(Wajib)</span></label>
                        <div class="input-wrapper">
                            <input type="password" id="password_lama" name="password_lama" placeholder="Masukkan password saat ini" required autocomplete="current-password" style="border-color: #fed7aa; background: #fff7ed;">
                            <div class="toggle-pass" onclick="togglePassword('password_lama')">
                                <i class="fas fa-eye"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- TOMBOL SIMPAN - CENTER -->
                <div class="btn-section">
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', e => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
                document.addEventListener('click', e => {
                    if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }
        });
        // Toggle Edit Username
        $('#editUsernameBtn').on('click', function(e) {
            e.preventDefault();
            const displayInput = $('#username_display');
            const realInput = $('#username_real');
          
            if (displayInput.prop('disabled')) {
                displayInput.prop('disabled', false)
                    .val(realInput.val())
                    .focus()
                    .css({
                        'background-color': '#fff',
                        'color': 'var(--text-primary)',
                        'padding-right': '15px'
                    });
                $(this).text('Batal Ubah');
            } else {
                displayInput.prop('disabled', true)
                    .val('<?= $masked_username ?>')
                    .css({
                        'background-color': '#f8fafc',
                        'color': '#64748b'
                    });
                realInput.val('<?= htmlspecialchars($current_user['username']) ?>');
                $(this).text('Ubah Username?');
            }
        });
        $('#username_display').on('input', function() {
            $('#username_real').val($(this).val());
        });
        function togglePassword(id) {
            const input = document.getElementById(id);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        $('#password_baru').on('input', function() {
            const val = $(this).val();
            updateIndicator('rule-length', val.length >= 8);
            updateIndicator('rule-number', /[0-9]/.test(val));
            updateIndicator('rule-capital', /[A-Z]/.test(val));
        });
        function updateIndicator(id, isValid) {
            const el = $('#' + id);
            const icon = el.find('i');
            if (isValid) {
                el.addClass('valid').removeClass('invalid');
                icon.removeClass('fa-circle').addClass('fa-check-circle');
            } else {
                el.removeClass('valid').addClass('invalid');
                icon.removeClass('fa-check-circle').addClass('fa-circle');
            }
        }
        $('#accountForm').on('submit', function(e) {
            e.preventDefault();
          
            const passBaru = $('#password_baru').val();
            const passKonf = $('#konfirmasi_password').val();
            if (passBaru) {
                if (passBaru.length < 8) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Password Lemah',
                        text: 'Password minimal 8 karakter.',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }
                if (!/[0-9]/.test(passBaru) || !/[A-Z]/.test(passBaru)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Password Lemah',
                        text: 'Harus mengandung angka dan huruf kapital.',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }
                if (passBaru !== passKonf) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Tidak Cocok',
                        text: 'Konfirmasi password tidak sesuai.',
                        confirmButtonColor: '#dc2626'
                    });
                    return;
                }
            }
            Swal.fire({
                title: 'Simpan Perubahan?',
                text: 'Anda akan otomatis logout setelah berhasil.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan & Logout',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#64748b'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Menyimpan...',
                        allowOutsideClick: false,
                        didOpen: () => Swal.showLoading()
                    });
                    $.ajax({
                        url: '',
                        type: 'POST',
                        data: $(this).serialize(),
                        dataType: 'json',
                        success: function(res) {
                            if (res.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: res.message,
                                    confirmButtonColor: '#10b981',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.href = '../login.php';
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal',
                                    text: res.message,
                                    confirmButtonColor: '#dc2626'
                                });
                            }
                        },
                        error: function() {
                            Swal.close();
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Terjadi kesalahan server.',
                                confirmButtonColor: '#dc2626'
                            });
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>