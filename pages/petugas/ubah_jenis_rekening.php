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
    <title>Ubah Jenis Rekening | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
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

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

        /* Mobile Overlay for Sidebar */
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

        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }


        /* Form Controls */
        .form-group {
            margin-bottom: 1.25rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .input-wrapper {
            position: relative;
            width: 100%;
        }

        .clear-btn {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            padding: 5px;
            display: none;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            transition: var(--transition);
        }

        .clear-btn:hover {
            color: var(--gray-600);
        }

        .clear-btn.show {
            display: flex;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            height: 48px;
            color: var(--gray-800);
        }

        input:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        input.error-input {
            border-color: #ef4444;
            animation: shake 0.5s;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            10%,
            30%,
            50%,
            70%,
            90% {
                transform: translateX(-5px);
            }

            20%,
            40%,
            60%,
            80% {
                transform: translateX(5px);
            }
        }

        .error-message {
            color: #ef4444;
            font-size: 0.85rem;
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        /* Buttons */
        .btn,
        .btn-search {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
            width: auto;
            min-width: 150px;
        }

        .btn:hover,
        .btn-search:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn:disabled,
        .btn-search:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-container {
            margin-top: 1.5rem;
        }


        .details-wrapper {
            display: none;
            gap: 1.5rem;
            margin-top: 2rem;
            grid-template-columns: 1fr 1fr;
        }

        .details-wrapper.show {
            display: grid;
        }

        .user-details,
        .new-tipe-section {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1.5rem;
        }

        .user-details h3,
        .new-tipe-section h3 {
            color: var(--gray-800);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
            font-size: 0.95rem;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-row .label {
            color: var(--gray-500);
            font-weight: 500;
            flex-shrink: 0;
        }

        .detail-row .value {
            color: var(--gray-800);
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }

        .tipe-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.85rem;
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
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            text-align: center;
            margin: 1rem 0;
        }

        .new-tipe-display .tipe-badge {
            font-size: 1.1rem;
            padding: 0.5rem 1.5rem;
        }

        .info-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: var(--radius);
            padding: 1rem;
            margin: 1rem 0;
            display: none;
        }

        .info-box.show {
            display: block;
        }

        .info-box h4 {
            color: #92400e;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-box ul {
            margin: 0.5rem 0 0 1.5rem;
            padding: 0;
            list-style: none;
        }

        .info-box li {
            color: #78350f;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 0.25rem;
            position: relative;
        }

        .info-box li::before {
            content: '✓';
            color: #f59e0b;
            font-weight: bold;
            margin-right: 0.5rem;
        }

        .verification-section {
            display: none;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }

        .verification-section.show {
            display: block;
        }



        /* Responsive */
        @media (max-width: 1024px) {
            .page-hamburger {
                display: block;
            }

            .page-title-section {
                padding: 1rem 1.5rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }
        }

        @media (max-width: 768px) {
            .details-wrapper {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Ubah Jenis Rekening</h1>
                <p class="page-subtitle">Ubah tipe rekening nasabah (Fisik / Digital)</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Perubahan</h3>
                    <p>Cari nasabah dan tentukan jenis rekening baru</p>
                </div>
            </div>

            <div class="card-body">
                <form id="searchForm">
                    <div class="form-group">
                        <label class="form-label" for="no_rekening">Nomor Rekening</label>
                        <div class="input-wrapper">
                            <input type="text" id="no_rekening" name="no_rekening"
                                placeholder="Masukkan nomor rekening (8 digit)" maxlength="8" required
                                autocomplete="off" autofocus>
                            <button type="button" class="clear-btn" id="clearBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <span class="error-message" id="no-rekening-error"></span>
                    </div>

                    <div class="btn-container">
                        <button type="submit" class="btn-search" id="searchBtn">
                            <i class="fas fa-search"></i> Cari Nasabah
                        </button>
                    </div>
                </form>

                <form id="ubahForm" style="display:none;">
                    <input type="hidden" name="token" id="form_token" value="<?php echo htmlspecialchars($token); ?>">
                    <input type="hidden" id="user_id" name="user_id" value="">
                    <input type="hidden" id="new_tipe_rekening" name="new_tipe_rekening" value="">
                    <input type="hidden" id="hidden_no_rekening" name="no_rekening" value="">
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
                                        <label class="form-label" for="email_siswa">Email Siswa (Wajib)</label>
                                        <input type="email" id="email_siswa" name="email_siswa"
                                            placeholder="Masukkan email siswa untuk menerima kredensial" disabled
                                            autocomplete="off">
                                        <span class="error-message" id="email-error"></span>
                                    </div>
                                </div>

                                <!-- PIN input (for fisik → digital) -->
                                <div id="pin-input-wrapper" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label" for="pin_input">PIN Siswa (6 digit)</label>
                                        <input type="password" id="pin_input" name="pin_input"
                                            placeholder="Masukkan PIN siswa untuk verifikasi" maxlength="6" disabled
                                            autocomplete="off">
                                        <span class="error-message" id="pin-error"></span>
                                    </div>
                                </div>

                                <!-- Password input (for digital → fisik) -->
                                <div id="password-input-wrapper" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label" for="password_input">Password Siswa</label>
                                        <input type="password" id="password_input" name="password_input"
                                            placeholder="Masukkan password siswa untuk verifikasi" disabled
                                            autocomplete="off">
                                        <span class="error-message" id="password-error"></span>
                                    </div>
                                </div>
                            </div>

                            <div class="btn-container">
                                <button type="submit" class="btn" id="submitBtn" disabled>
                                    <i class="fas fa-save"></i> Simpan Perubahan
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            const menuToggle = $('#menuToggle');
            const sidebar = $('#sidebar');

            if (menuToggle.length) {
                menuToggle.on('click', function (e) {
                    e.stopPropagation();
                    $('#sidebar').toggleClass('active');
                    $('body').toggleClass('sidebar-active');
                });
            }

            $(document).on('click', function (e) {
                if (sidebar.hasClass('active') && !sidebar.is(e.target) && sidebar.has(e.target).length === 0 && !menuToggle.is(e.target)) {
                    sidebar.removeClass('active');
                    $('body').removeClass('sidebar-active');
                }
            });

            // Input Validation and Limiting
            $('#no_rekening, #pin_input').on('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            // Clear Button Logic
            $('#no_rekening').on('input', function () {
                if (this.value.length > 0) {
                    $('#clearBtn').addClass('show');
                } else {
                    $('#clearBtn').removeClass('show');
                }
                $('#no-rekening-error').removeClass('show').text('');
                $(this).removeClass('error-input');
            });

            $('#clearBtn').on('click', function () {
                $('#no_rekening').val('').focus();
                $(this).removeClass('show');
                $('#ubahForm').hide();
                $('#details-wrapper').removeClass('show');
                $('#searchBtn').prop('disabled', false);
            });

            // Search Form Submit
            $('#searchForm').on('submit', function (e) {
                e.preventDefault();
                const noRekening = $('#no_rekening').val().trim();
                const searchBtn = $('#searchBtn');

                if (noRekening.length !== 8) {
                    $('#no_rekening').addClass('error-input').focus();
                    $('#no-rekening-error').text('Nomor rekening harus 8 digit angka.').addClass('show');
                    return;
                }

                searchBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Mencari...');

                setTimeout(function () {
                    $.ajax({
                        url: 'ubah_jenis_rekening.php',
                        type: 'GET',
                        data: { action: 'fetch_user', no_rekening: noRekening },
                        dataType: 'json',
                        success: function (response) {
                            searchBtn.prop('disabled', false).html('<i class="fas fa-search"></i> Cari Nasabah');

                            if (response.success) {
                                const user = response.user;
                                const currentTipe = response.current_tipe;
                                const newTipe = currentTipe === 'digital' ? 'fisik' : 'digital';

                                $('#user_id').val(user.id);
                                $('#new_tipe_rekening').val(newTipe);
                                $('#hidden_no_rekening').val(user.no_rekening);
                                $('#current_tipe_value').val(currentTipe);

                                // Render User Details
                                let detailsHtml = `
                                    <div class="detail-row">
                                        <span class="label">Nama Lengkap</span>
                                        <span class="value">${user.nama}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">NIS/NISN</span>
                                        <span class="value">${user.nis_nisn || '-'}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Kelas / Jurusan</span>
                                        <span class="value">${user.nama_tingkatan || ''} ${user.nama_kelas || ''} / ${user.nama_jurusan || '-'}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="label">Jenis Rekening Saat Ini</span>
                                        <span class="value"><span class="tipe-badge ${currentTipe}">${currentTipe.toUpperCase()}</span></span>
                                    </div>
                                `;
                                $('#detail-content').html(detailsHtml);

                                // Set New Tipe Badge
                                $('#new-tipe-badge').html(`<span class="tipe-badge ${newTipe}">${newTipe.toUpperCase()}</span>`);

                                // Toggle Info Box & Inputs based on transition
                                if (currentTipe === 'digital') { // Switching to Fisik
                                    $('#fisik-info-box').addClass('show');
                                    $('#email-input-wrapper').hide().find('input').prop('disabled', true);
                                    $('#pin-input-wrapper').show().find('input').prop('disabled', false);
                                    $('#password-input-wrapper').hide().find('input').prop('disabled', true);
                                } else { // Switching to Digital
                                    $('#fisik-info-box').removeClass('show');
                                    $('#email-input-wrapper, #pin-input-wrapper').show().find('input').prop('disabled', false);
                                    $('#password-input-wrapper').hide().find('input').prop('disabled', true);

                                    // Pre-fill email if available
                                    if (user.email && user.email !== '-') {
                                        $('#email_siswa').val(user.email);
                                    }
                                }

                                $('#ubahForm').show();
                                $('#details-wrapper').addClass('show');
                                $('#verification-section').addClass('show');
                                $('#submitBtn').prop('disabled', false);

                                // Scroll to details
                                $('html, body').animate({
                                    scrollTop: $("#details-wrapper").offset().top - 100
                                }, 500);

                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Tidak Ditemukan',
                                    text: response.error
                                });
                            }
                        },
                        error: function () {
                            searchBtn.prop('disabled', false).html('<i class="fas fa-search"></i> Cari Nasabah');
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Terjadi kesalahan saat mencari data.'
                            });
                        }
                    });
                }, 2000);
            });

            // Final Submit
            $('#ubahForm').on('submit', function (e) {
                e.preventDefault();

                // Client-side validation
                const newTipe = $('#new_tipe_rekening').val();
                let isValid = true;

                $('.error-message').removeClass('show').text('');
                $('input').removeClass('error-input');

                if (newTipe === 'digital') {
                    const email = $('#email_siswa').val().trim();
                    const pin = $('#pin_input').val().trim();

                    if (!email) {
                        $('#email_siswa').addClass('error-input');
                        $('#email-error').text('Email wajib diisi untuk akun digital.').addClass('show');
                        isValid = false;
                    }
                    if (pin.length !== 6) {
                        $('#pin_input').addClass('error-input');
                        $('#pin-error').text('PIN harus 6 digit angka.').addClass('show');
                        isValid = false;
                    }
                } else {
                    // Digital → Fisik: require PIN verification
                    const pin = $('#pin_input').val().trim();
                    if (pin.length !== 6) {
                        $('#pin_input').addClass('error-input');
                        $('#pin-error').text('PIN harus 6 digit angka untuk verifikasi.').addClass('show');
                        isValid = false;
                    }
                }

                if (!isValid) return;

                Swal.fire({
                    title: 'Konfirmasi Perubahan',
                    text: 'Apakah Anda yakin ingin mengubah jenis rekening nasabah ini?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Ubah Rekening',
                    cancelButtonText: 'Batal'
                }).then((result) => {
                    if (result.isConfirmed) {
                        const submitBtn = $('#submitBtn');
                        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memproses...');

                        setTimeout(function () {
                            $.ajax({
                                url: 'proses_ubah_jenis_rekening.php',
                                type: 'POST',
                                data: $('#ubahForm').serialize(),
                                dataType: 'json',
                                success: function (response) {
                                    submitBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Perubahan');

                                    if (response.success) {
                                        Swal.fire({
                                            icon: 'success',
                                            title: 'Berhasil!',
                                            text: response.message
                                        }).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        Swal.fire({
                                            icon: 'error',
                                            title: 'Gagal',
                                            text: response.message
                                        });
                                    }
                                },
                                error: function () {
                                    submitBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Simpan Perubahan');
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Error',
                                        text: 'Terjadi kesalahan sistem.'
                                    });
                                }
                            });
                        }, 2000);
                    }
                });
            });
        });
    </script>
</body>

</html>