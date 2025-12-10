<?php
/**
 * Ubah Jenis Rekening - Adaptive Path Version
 * File: pages/petugas/ubah_jenis_rekening.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/ubah_jenis_rekening.php
 * - Hosting: public_html/pages/petugas/ubah_jenis_rekening.php
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
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
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
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Handle AJAX fetch user
if (isset($_GET['action']) && $_GET['action'] === 'fetch_user' && isset($_GET['no_rekening'])) {
    header('Content-Type: application/json');
    $no_rekening = trim($_GET['no_rekening']);
    
    if (empty($no_rekening)) {
        echo json_encode(['error' => 'Nomor rekening tidak boleh kosong!']);
        exit;
    }
    
    $query = "SELECT u.*, r.no_rekening, j.nama_jurusan, tk.nama_tingkatan, k.nama_kelas 
              FROM users u 
              JOIN rekening r ON u.id = r.user_id 
              JOIN siswa_profiles sp ON u.id = sp.user_id 
              LEFT JOIN jurusan j ON sp.jurusan_id = j.id 
              LEFT JOIN kelas k ON sp.kelas_id = k.id 
              LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
              WHERE r.no_rekening = ? AND u.role = 'siswa'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['error' => 'Nasabah dengan nomor rekening tersebut tidak ditemukan!']);
    } else {
        $user = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'user' => $user,
            'current_tipe' => $user['email_status'] === 'terisi' ? 'digital' : 'fisik'
        ]);
    }
    $stmt->close();
    exit;
}

$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <meta name="format-detection" content="telephone=no">
    <title>Ubah Jenis Rekening | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
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
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        * {
            margin: 0; padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            user-select: none;
        }
        html {
            width: 100%; min-height: 100vh; overflow-x: hidden;
        }
        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            font-size: clamp(0.9rem, 2vw, 1rem);
            min-height: 100vh;
        }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            overflow-y: auto;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }
        
        /* Welcome Banner - Updated */
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
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }
        
        .form-card {
            background: var(--bg-table);
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        label i {
            color: var(--primary-color);
            font-size: 0.95em;
        }
        
        .input-wrapper {
            position: relative;
            width: 100%;
        }
        
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
        }
        
        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        input.error-input {
            border-color: var(--danger-color);
            animation: shake 0.5s;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }
        
        .error-message.show {
            display: block;
        }
        
        .btn-search {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            max-width: 200px;
            margin: 15px auto 0;
            transition: var(--transition);
        }
        
        .btn-search:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
        }
        
        .btn-search:active {
            transform: scale(0.95);
        }
        
        .btn-search:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .details-wrapper {
            display: none;
            gap: 20px;
            margin: 20px 0;
            grid-template-columns: 1fr 1fr;
        }
        
        .details-wrapper.show {
            display: grid;
        }
        
        .user-details, .new-tipe-section {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            padding: 20px;
        }
        
        .user-details h3, .new-tipe-section h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            gap: 10px;
        }
        
        .detail-row:last-child { border-bottom: none; }
        
        .detail-row .label {
            color: var(--text-secondary);
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .detail-row .value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }
        
        .tipe-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            font-weight: 600;
        }
        
        .tipe-badge.digital {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .tipe-badge.fisik {
            background: #fef3c7;
            color: #92400e;
        }
        
        .new-tipe-display {
            background: white;
            border: 2px solid var(--primary-color);
            border-radius: 5px;
            padding: 15px;
            text-align: center;
            margin: 15px 0;
        }
        
        .new-tipe-display .tipe-badge {
            font-size: clamp(0.95rem, 2vw, 1.1rem);
            padding: 8px 20px;
        }
        
        .info-box {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
            display: none;
        }
        
        .info-box.show { display: block; }
        
        .info-box h4 {
            color: #92400e;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 600;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .info-box ul {
            margin: 8px 0 0 20px;
            padding: 0;
            list-style: none;
        }
        
        .info-box li {
            color: #78350f;
            font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            line-height: 1.6;
            margin-bottom: 5px;
            position: relative;
            padding-left: 18px;
        }
        
        .info-box li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #f59e0b;
            font-weight: bold;
        }
        
        .verification-section {
            display: none;
            margin-top: 15px;
        }
        
        .verification-section.show { display: block; }
        
        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            max-width: 200px;
            margin: 20px auto;
            transition: var(--transition);
        }
        
        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
        }
        
        .btn:active { transform: scale(0.95); }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            
            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
            }
            
            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }
            
            .form-card { padding: 16px; }
            
            .details-wrapper.show {
                grid-template-columns: 1fr;
            }
            
            .detail-row {
                flex-direction: column;
                gap: 4px;
                align-items: flex-start;
            }
            
            .detail-row .value {
                text-align: left;
                width: 100%;
            }
            
            .btn, .btn-search { max-width: 100%; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-exchange-alt"></i> Ubah Jenis Rekening</h2>
                <p>Ubah jenis rekening nasabah dari Digital ke Fisik atau sebaliknya</p>
            </div>
        </div>

        <div class="form-card">
            <form id="searchForm">
                <div class="form-group">
                    <label for="no_rekening"><i class="fas fa-hashtag"></i> Nomor Rekening</label>
                    <div class="input-wrapper">
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan nomor rekening (8 digit)" maxlength="8" required autocomplete="off" autofocus>
                    </div>
                    <span class="error-message" id="no-rekening-error"></span>
                </div>
                
                <button type="submit" class="btn-search" id="searchBtn">
                    <i class="fas fa-search"></i> Cari Nasabah
                </button>
            </form>
            
            <form id="ubahForm" style="display:none;">
                <input type="hidden" name="token" id="form_token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" id="user_id" name="user_id" value="">
                <input type="hidden" id="new_tipe_rekening" name="new_tipe_rekening" value="">
                <input type="hidden" id="current_tipe_value" value="">

                <div id="details-wrapper" class="details-wrapper">
                    <div class="user-details">
                        <h3><i class="fas fa-user-circle"></i> Detail Nasabah</h3>
                        <div id="detail-content"></div>
                    </div>

                    <div class="new-tipe-section">
                        <h3><i class="fas fa-arrow-right"></i> Jenis Rekening Baru</h3>
                        <div class="new-tipe-display">
                            <div id="new-tipe-badge"></div>
                        </div>

                        <div id="fisik-info-box" class="info-box">
                            <h4><i class="fas fa-info-circle"></i> Rekening Fisik</h4>
                            <ul>
                                <li>Tidak bisa login ke sistem online</li>
                                <li>Dilengkapi dengan buku cetak</li>
                                <li>Transaksi hanya melalui petugas</li>
                                <li>Cocok untuk siswa tanpa email/smartphone</li>
                            </ul>
                        </div>

                        <!-- Verification Section -->
                        <div id="verification-section" class="verification-section">
                            <!-- Email input (for fisik → digital) -->
                            <div id="email-input-wrapper" style="display: none;">
                                <div class="form-group">
                                    <label for="email_siswa"><i class="fas fa-envelope"></i> Email Siswa (Wajib)</label>
                                    <input type="email" id="email_siswa" name="email_siswa" placeholder="Masukkan email siswa untuk menerima kredensial" disabled autocomplete="off">
                                    <span class="error-message" id="email-error"></span>
                                </div>
                            </div>

                            <!-- PIN input (for fisik → digital) -->
                            <div id="pin-input-wrapper" style="display: none;">
                                <div class="form-group">
                                    <label for="pin_input"><i class="fas fa-key"></i> PIN Siswa (6 digit)</label>
                                    <input type="password" id="pin_input" name="pin_input" placeholder="Masukkan PIN siswa untuk verifikasi" maxlength="6" disabled autocomplete="off">
                                    <span class="error-message" id="pin-error"></span>
                                </div>
                            </div>

                            <!-- Password input (for digital → fisik) -->
                            <div id="password-input-wrapper" style="display: none;">
                                <div class="form-group">
                                    <label for="password_input"><i class="fas fa-lock"></i> Password Siswa</label>
                                    <input type="password" id="password_input" name="password_input" placeholder="Masukkan password siswa untuk verifikasi" disabled autocomplete="off">
                                    <span class="error-message" id="password-error"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" id="submit-btn" class="btn" disabled>
                    <span><i class="fas fa-save"></i> Ubah Jenis</span>
                </button>
            </form>
        </div>
    </div>

    <script>
        let currentTipe = null;
        let currentUserData = null;

        function resetForm() {
            $('#no_rekening').val('');
            $('#user_id').val('');
            $('#new_tipe_rekening').val('');
            $('#current_tipe_value').val('');
            $('#email_siswa').val('');
            $('#pin_input').val('');
            $('#password_input').val('');
            $('#details-wrapper').removeClass('show');
            $('#verification-section').removeClass('show');
            $('#fisik-info-box').hide();
            $('#email-input-wrapper, #pin-input-wrapper, #password-input-wrapper').hide();
            $('#email_siswa, #pin_input, #password_input').prop('disabled', true).prop('required', false);
            $('#submit-btn').prop('disabled', true);
            $('.error-message').text('').removeClass('show');
            $('input').removeClass('error-input');
            $('#searchForm').show();
            $('#ubahForm').hide();
            currentTipe = null;
            currentUserData = null;

            setTimeout(() => {
                $('#no_rekening').focus();
            }, 300);
        }

        function clearVerificationOnly() {
            const newTipe = $('#new_tipe_rekening').val();
            
            if (newTipe === 'digital') {
                $('#email_siswa').val('').removeClass('error-input');
                $('#pin_input').val('').removeClass('error-input');
                $('#email-error').text('').removeClass('show');
                $('#pin-error').text('').removeClass('show');
                $('#email_siswa').focus();
            } else {
                $('#password_input').val('').removeClass('error-input').focus();
                $('#password-error').text('').removeClass('show');
            }
            
            $('#submit-btn').prop('disabled', true);
        }

        <?php if ($success): ?>
        Swal.fire({
            icon: 'success',
            title: 'Berhasil!',
            text: '<?php echo addslashes($success); ?>',
            confirmButtonColor: '#1e3a8a'
        }).then(() => {
            resetForm();
        });
        <?php endif; ?>

        // Only allow numbers for no_rekening
        $('#no_rekening').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
        });

        // Search form submit
        $('#searchForm').on('submit', function(e) {
            e.preventDefault();
            const noRek = $('#no_rekening').val().trim();
            
            if (noRek.length !== 8) {
                Swal.fire({
                    icon: 'error',
                    title: 'Validasi Gagal',
                    text: 'Nomor rekening harus 8 digit!',
                    confirmButtonColor: '#1e3a8a'
                }).then(() => {
                    $('#no_rekening').val('').focus();
                });
                return;
            }
            
            // Disable search button and show loading
            $('#searchBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Mencari...');
            
            $.ajax({
                url: '?action=fetch_user&no_rekening=' + encodeURIComponent(noRek),
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#searchBtn').prop('disabled', false).html('<i class="fas fa-search"></i> Cari Nasabah');
                    
                    if (data.success) {
                        const user = data.user;
                        currentTipe = data.current_tipe;
                        currentUserData = user;
                        $('#user_id').val(user.id);
                        $('#current_tipe_value').val(currentTipe);

                        let detailHtml = '';
                        detailHtml += `<div class="detail-row"><span class="label">No. Rekening:</span><span class="value">${user.no_rekening}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Nama:</span><span class="value">${user.nama}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Kelas:</span><span class="value">${user.nama_tingkatan} ${user.nama_kelas}</span></div>`;
                        detailHtml += `<div class="detail-row"><span class="label">Jurusan:</span><span class="value">${user.nama_jurusan || '-'}</span></div>`;

                        if (currentTipe === 'digital') {
                            detailHtml += `<div class="detail-row"><span class="label">Email:</span><span class="value">${user.email || '-'}</span></div>`;
                        }

                        const tipeBadge = currentTipe === 'digital'
                            ? '<span class="tipe-badge digital">Digital</span>'
                            : '<span class="tipe-badge fisik">Fisik</span>';
                        detailHtml += `<div class="detail-row"><span class="label">Jenis Rekening:</span><span class="value">${tipeBadge}</span></div>`;

                        $('#detail-content').html(detailHtml);

                        const oppositeTipe = currentTipe === 'digital' ? 'fisik' : 'digital';
                        $('#new_tipe_rekening').val(oppositeTipe);

                        const newTipeBadge = oppositeTipe === 'digital'
                            ? '<span class="tipe-badge digital">Digital (Online)</span>'
                            : '<span class="tipe-badge fisik">Fisik (Offline)</span>';
                        $('#new-tipe-badge').html(newTipeBadge);

                        $('#details-wrapper').addClass('show');
                        $('#verification-section').addClass('show');

                        // Hide all input wrappers first
                        $('#email-input-wrapper, #pin-input-wrapper, #password-input-wrapper').hide();
                        $('#email_siswa, #pin_input, #password_input').prop('disabled', true).prop('required', false).val('');

                        if (oppositeTipe === 'digital') {
                            $('#fisik-info-box').hide();
                            $('#email-input-wrapper').show();
                            $('#pin-input-wrapper').show();
                            $('#email_siswa').prop('disabled', false).prop('required', true);
                            $('#pin_input').prop('disabled', false).prop('required', true);
                            $('#submit-btn').prop('disabled', true);
                            setTimeout(() => $('#email_siswa').focus(), 100);
                        } else {
                            $('#fisik-info-box').show();
                            $('#password-input-wrapper').show();
                            $('#password_input').prop('disabled', false).prop('required', true);
                            $('#submit-btn').prop('disabled', true);
                            setTimeout(() => $('#password_input').focus(), 100);
                        }

                        $('#no-rekening-error').text('').removeClass('show');
                        
                        // Hide search form, show ubah form
                        $('#searchForm').hide();
                        $('#ubahForm').show();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Nasabah Ditemukan!',
                            text: `Data ${user.nama} berhasil dimuat`,
                            confirmButtonColor: '#1e3a8a',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Nasabah Tidak Ditemukan',
                            text: data.error,
                            confirmButtonColor: '#dc2626',
                            footer: '<small>Pastikan nomor rekening sudah benar</small>'
                        }).then(() => {
                            $('#no_rekening').val('').focus();
                        });
                    }
                },
                error: function() {
                    $('#searchBtn').prop('disabled', false).html('<i class="fas fa-search"></i> Cari Nasabah');
                    Swal.fire({
                        icon: 'error',
                        title: 'Kesalahan Server',
                        text: 'Gagal memuat data nasabah. Silakan coba lagi.',
                        confirmButtonColor: '#dc2626'
                    }).then(() => {
                        $('#no_rekening').val('').focus();
                    });
                }
            });
        });

        // Only allow numbers for PIN
        $('#pin_input').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 6);
            validateForm();
        });

        // Validate email
        $('#email_siswa').on('input', function() {
            validateForm();
        });

        // Validate password
        $('#password_input').on('input', function() {
            validateForm();
        });

        function validateForm() {
            const newTipe = $('#new_tipe_rekening').val();
            const submitBtn = $('#submit-btn');

            if (newTipe === 'digital') {
                const email = $('#email_siswa').val().trim();
                const pin = $('#pin_input').val().trim();
                const emailValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                const pinValid = pin.length === 6;

                if (emailValid && pinValid) {
                    submitBtn.prop('disabled', false);
                } else {
                    submitBtn.prop('disabled', true);
                }
            } else {
                const password = $('#password_input').val().trim();
                if (password.length > 0) {
                    submitBtn.prop('disabled', false);
                } else {
                    submitBtn.prop('disabled', true);
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', (e) => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            $('#ubahForm').on('submit', function(e) {
                e.preventDefault();

                const noRek = $('#no_rekening').val().trim();
                const tipe = $('#new_tipe_rekening').val();
                const userId = $('#user_id').val();
                const token = $('#form_token').val();

                if (!noRek || !userId) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Data Tidak Lengkap',
                        text: 'Masukkan nomor rekening yang valid!',
                        confirmButtonColor: '#dc2626'
                    }).then(() => {
                        resetForm();
                    });
                    return;
                }

                if (!tipe) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Jenis Belum Dipilih',
                        text: 'Pilih jenis rekening baru!',
                        confirmButtonColor: '#dc2626'
                    });
                    return;
                }

                if (!token) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Token Tidak Valid',
                        text: 'Session expired. Silakan refresh halaman.',
                        confirmButtonColor: '#dc2626'
                    }).then(() => {
                        location.reload();
                    });
                    return;
                }

                let validationError = null;

                if (tipe === 'digital') {
                    const email = $('#email_siswa').val().trim();
                    const pin = $('#pin_input').val().trim();

                    if (!email) {
                        validationError = { title: 'Email Wajib Diisi', text: 'Email wajib untuk rekening digital!', focus: '#email_siswa' };
                    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                        validationError = { title: 'Email Tidak Valid', text: 'Format email tidak sesuai!', focus: '#email_siswa' };
                    } else if (!pin) {
                        validationError = { title: 'PIN Wajib Diisi', text: 'PIN wajib untuk verifikasi!', focus: '#pin_input' };
                    } else if (pin.length !== 6) {
                        validationError = { title: 'PIN Tidak Valid', text: 'PIN harus 6 digit angka!', focus: '#pin_input' };
                    }
                } else {
                    const password = $('#password_input').val().trim();
                    if (!password) {
                        validationError = { title: 'Password Wajib Diisi', text: 'Password wajib untuk verifikasi!', focus: '#password_input' };
                    }
                }

                if (validationError) {
                    Swal.fire({
                        icon: 'error',
                        title: validationError.title,
                        text: validationError.text,
                        confirmButtonColor: '#dc2626'
                    }).then(() => {
                        $(validationError.focus).focus();
                    });
                    return;
                }

                const nama = currentUserData.nama;
                const email = $('#email_siswa').val().trim();

                let confirmMessage = '';
                if (tipe === 'digital') {
                    confirmMessage = `
                        <div style='text-align: left; padding: 10px;'>
                            <p style='margin-bottom: 15px;'>Ubah jenis rekening <strong>${nama}</strong> (${noRek}) menjadi <strong>Digital</strong>?</p>
                            <div style='background: #dbeafe; border: 1px solid #93c5fd; padding: 12px; border-radius: 5px;'>
                                <p style='margin: 0; font-size: 0.9rem;'><strong>📝 Informasi:</strong></p>
                                <ul style='margin: 5px 0 0 20px; font-size: 0.85rem; line-height: 1.6;'>
                                    <li>Username & password akan digenerate otomatis</li>
                                    <li>Kredensial akan dikirim ke email: <strong>${email}</strong></li>
                                    <li>Nasabah dapat login ke sistem online</li>
                                    <li>Dapat melakukan transaksi mandiri</li>
                                </ul>
                            </div>
                        </div>
                    `;
                } else {
                    confirmMessage = `
                        <div style='text-align: left; padding: 10px;'>
                            <p style='margin-bottom: 15px;'>Ubah jenis rekening <strong>${nama}</strong> (${noRek}) menjadi <strong>Fisik</strong>?</p>
                            <div style='background: #fef2f2; border: 1px solid #fecaca; padding: 12px; border-radius: 5px;'>
                                <p style='margin: 0; font-size: 0.9rem;'><strong>⚠️ Peringatan:</strong></p>
                                <ul style='margin: 5px 0 0 20px; font-size: 0.85rem; line-height: 1.6;'>
                                    <li>Email akan dihapus dari sistem</li>
                                    <li>Username akan dihapus</li>
                                    <li>Password akan dihapus</li>
                                    <li>Nasabah tidak bisa login ke sistem online</li>
                                    <li>Transaksi hanya melalui petugas</li>
                                </ul>
                            </div>
                        </div>
                    `;
                }

                Swal.fire({
                    title: 'Konfirmasi Perubahan',
                    html: confirmMessage,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Ya, Ubah Sekarang!',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#6b7280',
                    width: window.innerWidth < 600 ? '95%' : '600px'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Memproses...',
                            html: 'Sedang memverifikasi dan mengubah jenis rekening.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });

                        $('#submit-btn').html('<span><i class="fas fa-spinner fa-spin"></i> Memproses...</span>').prop('disabled', true);

                        const formData = {
                            token: token,
                            no_rekening: noRek,
                            user_id: userId,
                            new_tipe_rekening: tipe
                        };

                        if (tipe === 'digital') {
                            formData.email_siswa = $('#email_siswa').val().trim();
                            formData.pin_input = $('#pin_input').val().trim();
                        } else {
                            formData.password_input = $('#password_input').val().trim();
                        }

                        $.ajax({
                            url: 'proses_ubah_jenis_rekening.php',
                            type: 'POST',
                            data: formData,
                            dataType: 'json',
                            success: function(response) {
                                Swal.close();
                                $('#submit-btn').html('<span><i class="fas fa-save"></i> Ubah Jenis</span>').prop('disabled', false);

                                if (response.success) {
                                    Swal.fire({
                                        icon: 'success',
                                        title: 'Berhasil!',
                                        text: response.message,
                                        confirmButtonColor: '#1e3a8a'
                                    }).then(() => {
                                        resetForm();
                                    });
                                } else {
                                    if (response.error_type === 'verification') {
                                        if (tipe === 'digital') {
                                            $('#email_siswa').addClass('error-input');
                                            $('#pin_input').addClass('error-input');
                                            $('#email-error').text(response.message).addClass('show');
                                            $('#pin-error').text(response.message).addClass('show');
                                        } else {
                                            $('#password_input').addClass('error-input');
                                            $('#password-error').text(response.message).addClass('show');
                                        }
                                        
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Verifikasi Gagal',
                                            text: response.message,
                                            confirmButtonColor: '#dc2626',
                                            footer: '<small>Silakan coba lagi</small>'
                                        }).then(() => {
                                            clearVerificationOnly();
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Gagal!',
                                            text: response.message,
                                            confirmButtonColor: '#dc2626'
                                        }).then(() => {
                                            resetForm();
                                        });
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                Swal.close();
                                $('#submit-btn').html('<span><i class="fas fa-save"></i> Ubah Jenis</span>').prop('disabled', false);
                                
                                console.error('AJAX Error:', status, error);
                                console.error('Response:', xhr.responseText);
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Kesalahan Server',
                                    text: 'Terjadi kesalahan saat memproses. Silakan coba lagi.',
                                    confirmButtonColor: '#dc2626'
                                }).then(() => {
                                    resetForm();
                                });
                            }
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>