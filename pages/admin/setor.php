<?php
/**
 * Setor Saldo Admin - Adaptive Path Version
 * File: pages/admin/setor.php
 * 
 * Compatible with:
 * - Local: schobank/pages/admin/setor.php
 * - Hosting: public_html/pages/admin/setor.php
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
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Admin';

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
    <title>Setor | KASDIG</title>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
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
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
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

        /* Steps Section */
        .steps-section {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px dashed var(--gray-200);
            position: relative;
        }

        .step-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            opacity: 0.5;
            padding: 0.5rem 1rem;
            border-radius: 99px;
            transition: var(--transition);
        }

        .step-item.active {
            opacity: 1;
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
        }

        .step-item.completed {
            opacity: 1;
            color: var(--primary-color);
        }

        .step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background-color: var(--gray-200);
            color: var(--gray-600);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
        }

        .step-item.completed .step-number {
            background-color: var(--primary-color);
            color: white;
        }

        .step-text {
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Forms */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        input[type="text"],
        input[type="number"] {
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

        .btn {
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
            width: auto;
            min-width: 130px;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            background: var(--gray-100);
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
        }

        .btn-cancel:hover {
            background: var(--gray-200);
            color: var(--gray-800);
            transform: translateY(-1px);
        }

        /* Quick Amounts */
        .quick-amounts {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
        }

        .quick-amount-btn {
            background: white;
            border: 1px solid var(--gray-300);
            color: var(--gray-600);
            padding: 0.5rem 1rem;
            border-radius: 99px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .quick-amount-btn:hover {
            border-color: var(--gray-600);
            color: var(--gray-800);
        }

        /* Detail Rows */
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.95rem;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--gray-500);
            font-weight: 500;
        }

        .detail-value {
            color: var(--gray-800);
            font-weight: 600;
            text-align: right;
        }

        .account-details,
        .deposit-card {
            animation: slideIn 0.3s ease-out;
        }

        .account-details[style*="display: none"] {
            display: none !important;
        }

        @keyframes slideIn {
            from {
                transform: translateY(10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
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

        /* Input with clear button */
        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper input {
            padding-right: 40px;
        }

        .clear-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 24px;
            height: 24px;
            border: none;
            background: var(--gray-300);
            color: var(--gray-600);
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            transition: all 0.2s ease;
        }

        .clear-btn:hover {
            background: var(--gray-400);
            color: white;
        }

        .clear-btn.visible {
            display: flex;
        }

        .confirm-buttons {
            display: flex;
            justify-content: flex-start;
            gap: 1rem;
            margin-top: 2rem;
        }

        /* Confirmation Page Icon */
        .confirmation-icon {
            width: 60px;
            height: 60px;
            background: #dbeafe;
            /* Light Blue */
            color: #1e40af;
            /* Dark Blue */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem auto;
            font-size: 1.5rem;
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

        @media (max-width: 640px) {
            .steps-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding-left: 1rem;
            }

            .step-text {
                display: inline-block;
            }

            .confirm-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title -->
        <div class="page-title-section">
            <button class="page-hamburger" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Setor Saldo </h1>
                <p class="page-subtitle">Layanan penyetoran saldo untuk siswa secara cepat dan aman</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-wallet"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Penyetoran</h3>
                    <p>Ikuti langkah-langkah di bawah ini</p>
                </div>
            </div>

            <div class="card-body">
                <!-- Steps Indicator -->
                <div class="steps-section">
                    <div class="step-item active" id="step1-indicator">
                        <div class="step-number">1</div>
                        <div class="step-text">Cek Rekening</div>
                    </div>
                    <div class="step-item" id="step2-indicator">
                        <div class="step-number">2</div>
                        <div class="step-text">Input Nominal</div>
                    </div>
                    <div class="step-item" id="step3-indicator">
                        <div class="step-number">3</div>
                        <div class="step-text">Konfirmasi</div>
                    </div>
                </div>

                <div class="transaction-container">
                    <!-- Step 1: Check Account -->
                    <div class="deposit-card" id="checkAccountStep">
                        <form id="cekRekening" novalidate>
                            <div class="form-group">
                                <label class="form-label" for="no_rekening">Nomor Rekening</label>
                                <div class="input-wrapper">
                                    <input type="text" id="no_rekening" class="form-control" maxlength="8"
                                        inputmode="numeric" pattern="[0-9]*"
                                        placeholder="Masukkan 8 digit nomor rekening" required>
                                    <button type="button" class="clear-btn" id="clearNoRek" title="Hapus">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <span class="error-message" id="rek-error"></span>
                            </div>
                            <button type="submit" id="cekButton" class="btn">
                                Cek Rekening
                            </button>
                        </form>
                    </div>

                    <!-- Step 2: Input Amount -->
                    <div class="account-details" id="amountDetails" style="display: none;">
                        <h4 style="margin-bottom: 1rem; color: var(--gray-800); font-weight:600;">Detail Rekening</h4>
                        <div class="detail-row">
                            <div class="detail-label">Nomor Rekening</div>
                            <div class="detail-value" id="displayNoRek">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Nama Pemilik</div>
                            <div class="detail-value" id="displayNama">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Jurusan</div>
                            <div class="detail-value" id="displayJurusan">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Kelas</div>
                            <div class="detail-value" id="displayKelas">-</div>
                        </div>

                        <div style="margin-top: 2rem;"></div>

                        <form id="formPenyetoran" novalidate>
                            <div class="form-group">
                                <label class="form-label" for="jumlah">Nominal Penyetoran (Rp)</label>
                                <input type="text" id="jumlah" name="jumlah" class="form-control" placeholder="0"
                                    inputmode="numeric" required>
                                <div class="quick-amounts">
                                    <button type="button" class="quick-amount-btn" data-amount="5000">Rp 5.000</button>
                                    <button type="button" class="quick-amount-btn" data-amount="10000">Rp
                                        10.000</button>
                                    <button type="button" class="quick-amount-btn" data-amount="20000">Rp
                                        20.000</button>
                                    <button type="button" class="quick-amount-btn" data-amount="50000">Rp
                                        50.000</button>
                                    <button type="button" class="quick-amount-btn" data-amount="100000">Rp
                                        100.000</button>
                                </div>
                                <span class="error-message" id="jumlah-error"></span>
                            </div>
                            <div class="confirm-buttons">
                                <button type="button" id="confirmAmount" class="btn">
                                    Lanjutkan
                                </button>
                                <button type="button" id="cancelAmount" class="btn btn-cancel">
                                    Batal
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Step 3: Confirmation -->
                    <div class="account-details" id="confirmationDetails" style="display: none;">
                        <div style="text-align: center; margin-bottom: 2rem;">
                            <!-- Improved Icon using class -->
                            <div class="confirmation-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <h3 style="color: var(--gray-800); font-weight: 600;">Konfirmasi Penyetoran</h3>
                            <p style="color: var(--gray-500); font-size: 0.9rem;">Pastikan data di bawah ini benar
                                sebelum memproses</p>
                        </div>

                        <div class="detail-row">
                            <div class="detail-label">Nomor Rekening</div>
                            <div class="detail-value" id="confirmNoRek">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Nama Pemilik</div>
                            <div class="detail-value" id="confirmNama">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Jurusan</div>
                            <div class="detail-value" id="confirmJurusan">-</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Kelas</div>
                            <div class="detail-value" id="confirmKelas">-</div>
                        </div>
                        <div class="detail-row"
                            style="background: var(--gray-50); margin-top: 1rem; padding: 1rem; border-radius: var(--radius); border: 1px solid var(--gray-200);">
                            <div class="detail-label" style="font-weight: 600; color: var(--gray-800);">Total Nominal
                            </div>
                            <div class="detail-value" id="confirmJumlah"
                                style="font-size: 1.1rem; color: var(--primary-color);">-</div>
                        </div>

                        <div class="confirm-buttons">
                            <button type="button" id="processDeposit" class="btn">
                                Proses Setor Tunai
                            </button>
                            <button type="button" id="backToInput" class="btn btn-cancel">
                                Kembali
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).ready(function () {
            // Sidebar Toggle Logic
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

            // Clear button functionality
            $('#no_rekening').on('input', function () {
                if ($(this).val().length > 0) {
                    $('#clearNoRek').addClass('visible');
                } else {
                    $('#clearNoRek').removeClass('visible');
                }
            });

            $('#clearNoRek').on('click', function () {
                $('#no_rekening').val('').focus();
                $(this).removeClass('visible');
                $('#no_rekening').removeClass('error-input');
                $('#rek-error').hide();
            });

            // Format Currency
            function formatRupiah(angka) {
                var number_string = angka.replace(/[^,\d]/g, '').toString(),
                    split = number_string.split(','),
                    sisa = split[0].length % 3,
                    rupiah = split[0].substr(0, sisa),
                    ribuan = split[0].substr(sisa).match(/\d{3}/gi);

                if (ribuan) {
                    separator = sisa ? '.' : '';
                    rupiah += separator + ribuan.join('.');
                }

                return split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
            }

            // Input Validation - Numbers Only
            $('#no_rekening, #jumlah').on('input', function () {
                // Keep only numbers
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            // Quick Amount Buttons
            $('.quick-amount-btn').on('click', function () {
                const amount = $(this).data('amount');
                $('#jumlah').val(formatRupiah(amount.toString()));
            });

            // Format amount while typing
            $('#jumlah').on('keyup', function () {
                $(this).val(formatRupiah($(this).val()));
            });

            // --- STEP 1: CHECK REKENING ---
            $('#cekRekening').on('submit', function (e) {
                e.preventDefault();
                const noRek = $('#no_rekening').val().trim();
                const submitBtn = $('#cekButton');

                if (noRek.length !== 8) {
                    $('#rek-error').text('Nomor rekening harus 8 digit').show();
                    $('#no_rekening').addClass('error-input');
                    return;
                }

                $('#rek-error').hide();
                $('#no_rekening').removeClass('error-input');
                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memeriksa...');

                submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memeriksa...');

                setTimeout(() => {
                    $.ajax({
                        url: 'proses_setor_admin.php',
                        type: 'POST',
                        data: { action: 'check_account', no_rekening: noRek },
                        dataType: 'json',
                        success: function (response) {
                            submitBtn.prop('disabled', false).text('Cek Rekening');
                            if (response.status === 'success') {
                                const data = response.data;
                                if (data.status_akun !== 'active') {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Akun Tidak Aktif',
                                        text: 'Status akun: ' + data.status_akun
                                    });
                                    return;
                                }

                                // Show Details
                                $('#displayNoRek').text(data.no_rekening);
                                $('#displayNama').text(data.nama_lengkap);
                                $('#displayJurusan').text(data.jurusan);
                                $('#displayKelas').text(data.kelas);

                                $('#checkAccountStep').slideUp();
                                $('#amountDetails').slideDown();
                                $('#step1-indicator').removeClass('active').addClass('completed');
                                $('#step2-indicator').addClass('active');

                                // Focus amount input
                                setTimeout(() => $('#jumlah').focus(), 500);

                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Tidak Ditemukan',
                                    text: response.message
                                });
                            }
                        },
                        error: function () {
                            submitBtn.prop('disabled', false).text('Cek Rekening');
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Terjadi kesalahan sistem'
                            });
                        }
                    });
                }, 2000);
            });

            // Cancel Step 2
            $('#cancelAmount').on('click', function () {
                $('#amountDetails').slideUp();
                $('#checkAccountStep').slideDown();
                $('#step2-indicator').removeClass('active');
                $('#step1-indicator').removeClass('completed').addClass('active');
                $('#jumlah').val('');
            });

            // Confirm Amount (Step 2 -> 3)
            $('#confirmAmount').on('click', function () {
                const rawAmount = $('#jumlah').val().replace(/\./g, '');
                if (!rawAmount || parseInt(rawAmount) < 1) {
                    $('#jumlah-error').text('Minimal setor Rp 1').show();
                    return;
                }

                $('#jumlah-error').hide();

                // Set Confirmation Vals
                $('#confirmNoRek').text($('#displayNoRek').text());
                $('#confirmNama').text($('#displayNama').text());
                $('#confirmJurusan').text($('#displayJurusan').text());
                $('#confirmKelas').text($('#displayKelas').text());
                $('#confirmJumlah').text('Rp ' + formatRupiah(rawAmount));

                $('#amountDetails').slideUp();
                $('#confirmationDetails').slideDown();

                $('#step2-indicator').removeClass('active').addClass('completed');
                $('#step3-indicator').addClass('active');
            });

            // Back from Step 3 -> 2
            $('#backToInput').on('click', function () {
                $('#confirmationDetails').slideUp();
                $('#amountDetails').slideDown();
                $('#step3-indicator').removeClass('active');
                $('#step2-indicator').removeClass('completed').addClass('active');
            });

            // Process Deposit (Step 3 -> Finish)
            $('#processDeposit').on('click', function () {
                const noRek = $('#displayNoRek').text();
                const rawAmount = $('#jumlah').val().replace(/\./g, '');
                const btn = $(this);

                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memproses...');

                setTimeout(() => {
                    $.ajax({
                        url: 'proses_setor_admin.php',
                        type: 'POST',
                        data: {
                            action: 'process_deposit',
                            no_rekening: noRek,
                            jumlah: rawAmount,
                        },
                        dataType: 'json',
                        success: function (response) {
                            if (response.status === 'success') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: 'Setor tunai berhasil diproses',
                                    showConfirmButton: false,
                                    timer: 2000
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                btn.prop('disabled', false).text('Proses Setor Tunai');
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal',
                                    text: response.message
                                });
                            }
                        },
                        error: function () {
                            btn.prop('disabled', false).text('Proses Setor Tunai');
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Gagal menghubungi server'
                            });
                        }
                    });
                }, 2000);
            });
        });
    </script>
</body>

</html>