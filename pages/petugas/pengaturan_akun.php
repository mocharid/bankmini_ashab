<?php
// pengaturan_akun.php (Petugas)
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php'; 

// 1. Validasi Role Petugas
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
date_default_timezone_set('Asia/Jakarta');

// 2. CSRF Token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// 3. Ambil Data User Saat Ini
$stmt_user = $conn->prepare("SELECT username, nama, email FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$current_user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

// Function Masking Username
function maskUsername($username) {
    if (strlen($username) <= 2) return $username;
    $first = $username[0];
    $last = substr($username, -1);
    $len = strlen($username) - 2;
    return $first . str_repeat('*', max(3, $len)) . $last;
}

$masked_username = maskUsername($current_user['username']);

// 4. Handle AJAX Update Akun
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_account') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['token']) || $_POST['token'] !== $_SESSION['form_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Token keamanan tidak valid!']);
        exit;
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
    $stmt_check = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt_check->bind_param("i", $user_id);
    $stmt_check->execute();
    $db_pass = $stmt_check->get_result()->fetch_assoc()['password'];
    $stmt_check->close();

    // Cek tipe hash (SHA256 manual atau BCRYPT default)
    $is_sha256 = (strlen($db_pass) == 64 && ctype_xdigit($db_pass));
    $verified = $is_sha256 ? (hash('sha256', $password_lama) === $db_pass) : password_verify($password_lama, $db_pass);

    if (!$verified) {
        echo json_encode(['status' => 'error', 'message' => 'Password saat ini salah!']);
        exit;
    }

    // Cek Unik Username & Email
    $stmt_uniq = $conn->prepare("SELECT id FROM users WHERE (username = ? OR (email = ? AND email != '')) AND id != ?");
    $stmt_uniq->bind_param("ssi", $new_username, $new_email, $user_id);
    $stmt_uniq->execute();
    if ($stmt_uniq->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Username atau Email sudah digunakan.']);
        exit;
    }
    $stmt_uniq->close();

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
            
            // Hashing password baru (SHA256 sesuai preferensi Anda)
            $hashed_new = hash('sha256', $password_baru);
            
            $update_sql .= ", password = ?";
            $types .= "s";
            $params[] = $hashed_new;
        }

        $update_sql .= " WHERE id = ?";
        $types .= "i";
        $params[] = $user_id;

        $stmt_update = $conn->prepare($update_sql);
        $stmt_update->bind_param($types, ...$params);
        
        if (!$stmt_update->execute()) {
            throw new Exception("Gagal mengupdate data akun.");
        }
        $stmt_update->close();

        $conn->commit();
        session_destroy(); // Logout otomatis

        echo json_encode(['status' => 'success', 'message' => 'Akun berhasil diperbarui! Silakan login kembali.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <title>Pengaturan Akun | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --text-light: #718096;
            --bg-light: #f0f5ff;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --success-color: #059669;
            --danger-color: #dc2626;
            --warning-color: #f59e0b;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            user-select: none;
        }
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
        }
        body { 
            background-color: var(--bg-light); 
            color: var(--text-primary); 
            display: flex; 
            min-height: 100vh; 
            font-size: 14px;
            touch-action: pan-y;
        }
        
        .main-content {
            flex: 1; 
            margin-left: 280px; 
            padding: 30px;
            max-width: calc(100% - 280px); 
            transition: margin-left 0.3s ease;
            width: 100%;
            overflow-y: auto;
            min-height: 100vh;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }

        /* Welcome Banner - Updated to match previous syntax */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .welcome-banner .content {
            flex: 1;
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
            margin: 0;
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Form Container */
        .form-section {
            background: var(--bg-table); 
            border-radius: 5px;
            padding: 35px; 
            box-shadow: var(--shadow-sm);
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
            border-radius: 5px;
            font-size: 0.95rem; 
            transition: border-color 0.3s, box-shadow 0.3s;
            height: 45px;
            box-sizing: border-box;
            -webkit-user-select: text;
            user-select: text;
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
            border-radius: 5px;
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
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3); 
            transform: translateY(-2px); 
        }
        .btn-primary:active {
            transform: translateY(0);
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .main-content { 
                margin-left: 0; 
                padding: 20px; 
                max-width: 100%;
            }
            
            .form-section {
                padding: 25px;
            }
        }

        @media (max-width: 768px) {
            .menu-toggle { 
                display: block; 
            }
            
            .main-content { 
                margin-left: 0;
                padding: 15px; 
                max-width: 100%; 
            }
            
            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                margin-bottom: 25px;
                align-items: center;
            }
            
            .welcome-banner h2 { 
                font-size: clamp(1.3rem, 3vw, 1.4rem); 
            }
            
            .welcome-banner p { 
                font-size: clamp(0.8rem, 2vw, 0.85rem); 
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
        
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.8vw, 1.3rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-user-cog"></i> Pengaturan Akun</h2>
                <p>Kelola informasi login dan keamanan akun Anda</p>
            </div>
        </div>

        <div class="form-section">
            <form id="accountForm">
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="token" value="<?= $token ?>">

                <div class="section-header">
                    <h3><i class="fas fa-id-card"></i> Informasi Profil</h3>
                </div>

                <!-- BARIS 1: Nama Lengkap | Email | Username -->
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Nama Lengkap</label>
                        <div class="input-wrapper">
                            <input type="text" value="<?= htmlspecialchars($current_user['nama']) ?>" disabled style="background-color: #f8fafc; color: #64748b; padding-right: 15px;">
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email</label>
                        <div class="input-wrapper">
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($current_user['email'] ?? '') ?>" placeholder="Email (Opsional)">
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
                    if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
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
