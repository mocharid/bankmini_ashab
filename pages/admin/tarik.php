<?php
/**
 * Tarik Saldo Admin - Harmonized Version
 * File: pages/admin/tarik.php
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

if (basename($current_dir) === 'admin') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

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
    <title>Tarik Saldo | KASDIG</title>
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
        input[type="number"],
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

        .btn:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none;
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

        .content-step {
            display: none;
            animation: slideIn 0.3s ease-out;
        }

        .content-step.active {
            display: block;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .error-msg {
            color: #ef4444;
            font-size: 0.85rem;
            margin-top: 0.5rem;
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        /* PIN Input Boxes */
        .pin-input-container {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
            max-width: 300px;
            margin-left: auto;
            margin-right: auto;
        }

        .pin-digit {
            width: 42px;
            height: 48px;
            text-align: center;
            font-size: 1.25rem;
            font-weight: 600;
            border: 2px solid var(--gray-300);
            border-radius: var(--radius);
            background: white;
            color: var(--gray-800);
            transition: all 0.2s;
        }

        .pin-digit:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 3px rgba(71, 85, 105, 0.15);
            outline: none;
        }

        .pin-digit.filled {
            border-color: var(--gray-500);
            background: var(--gray-50);
        }

        @media (max-width: 480px) {
            .pin-digit {
                width: 40px;
                height: 48px;
                font-size: 1.25rem;
            }

            .pin-input-container {
                gap: 0.35rem;
            }
        }

        @media (max-width: 768px) {
            .page-title-section {
                padding: 1.25rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin: -1rem -1rem 1rem -1rem;
            }

            .page-hamburger {
                display: flex;
            }

            .steps-section {
                gap: 1rem;
                overflow-x: auto;
                justify-content: flex-start;
                padding-bottom: 1rem;
            }

            .step-text {
                display: none;
            }

            .step-item.active .step-text {
                display: block;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Tarik Saldo</h1>
                <p class="page-subtitle">Layanan penarikan saldo untuk siswa secara cepat dan aman</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Penarikan</h3>
                    <p>Ikuti langkah-langkah berikut</p>
                </div>
            </div>

            <div class="card-body">
                <!-- Step Indicators -->
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
                        <div class="step-text">Verifikasi PIN</div>
                    </div>
                    <div class="step-item" id="step4-indicator">
                        <div class="step-number">4</div>
                        <div class="step-text">Konfirmasi</div>
                    </div>
                </div>

                <!-- Step 1: Check Account -->
                <div id="step1" class="content-step active">
                    <form id="checkForm">
                        <div class="form-group">
                            <label class="form-label">Nomor Rekening</label>
                            <div class="input-wrapper">
                                <input type="text" id="no_rekening" maxlength="8"
                                    placeholder="Masukkan 8 digit nomor rekening" inputmode="numeric">
                                <button type="button" class="clear-btn" id="clearNoRek" title="Hapus">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="error-msg" id="error-rekening">Nomor rekening tidak ditemukan</div>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn" id="btnCheck">
                                <span class="btn-text">Cek Rekening</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 2: Input Amount -->
                <div id="step2" class="content-step">
                    <div class="card"
                        style="border: 1px dashed var(--gray-300); background: var(--gray-50); box-shadow: none;">
                        <div class="card-body" style="padding: 1rem;">
                            <div class="detail-row">
                                <span class="detail-label">Nama Pemilik</span>
                                <span class="detail-value" id="detail-nama">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Jurusan</span>
                                <span class="detail-value" id="detail-jurusan">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Kelas</span>
                                <span class="detail-value" id="detail-kelas">-</span>
                            </div>
                        </div>
                    </div>

                    <form id="amountForm">
                        <div class="form-group">
                            <label class="form-label">Nominal Penarikan</label>
                            <input type="text" id="jumlah" placeholder="Rp 0" class="input-currency">
                            <div class="quick-amounts">
                                <button type="button" class="quick-amount-btn" data-value="10000">Rp 10.000</button>
                                <button type="button" class="quick-amount-btn" data-value="20000">Rp 20.000</button>
                                <button type="button" class="quick-amount-btn" data-value="50000">Rp 50.000</button>
                                <button type="button" class="quick-amount-btn" data-value="100000">Rp 100.000</button>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <button type="submit" class="btn" id="btnAmount">
                                <span class="btn-text">Lanjutkan</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="prevStep(1)">Kembali</button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: Verify PIN -->
                <div id="step3" class="content-step">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <i class="fas fa-lock"
                            style="font-size: 3rem; color: var(--gray-400); margin-bottom: 1rem;"></i>
                        <h4 style="color: var(--gray-700); margin-bottom: 0.5rem;">Verifikasi Keamanan</h4>
                        <p style="color: var(--gray-500); font-size: 0.9rem;">Masukkan PIN akun untuk melanjutkan
                            transaksi</p>
                    </div>

                    <form id="pinForm">
                        <div class="form-group">
                            <label class="form-label" style="text-align: center; display: block;">Masukkan PIN 6
                                Digit</label>
                            <div class="pin-input-container">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="0">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="1">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="2">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="3">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="4">
                                <input type="password" class="pin-digit" maxlength="1" inputmode="numeric"
                                    data-index="5">
                            </div>
                            <input type="hidden" id="pin" name="pin">
                        </div>
                        <div class="action-buttons" style="justify-content: center;">
                            <button type="submit" class="btn" id="btnPin">
                                <span class="btn-text">Verifikasi PIN</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="prevStep(2)">Kembali</button>
                        </div>
                    </form>
                </div>

                <!-- Step 4: Confirmation -->
                <div id="step4" class="content-step">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <div
                            style="width: 80px; height: 80px; background: var(--gray-100); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem;">
                            <i class="fas fa-check-circle" style="font-size: 2.5rem; color: var(--primary-color);"></i>
                        </div>
                        <h3 style="color: var(--gray-800); margin-bottom: 0.5rem;">Konfirmasi Penarikan</h3>
                        <p style="color: var(--gray-500);">Pastikan data berikut sudah benar</p>
                    </div>

                    <div class="card" style="border: 1px solid var(--gray-200); box-shadow: none;">
                        <div class="card-body">
                            <div class="detail-row">
                                <span class="detail-label">Rekening</span>
                                <span class="detail-value" id="confirm-rekening">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Nama</span>
                                <span class="detail-value" id="confirm-nama">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Nominal Penarikan</span>
                                <span class="detail-value" id="confirm-jumlah" style="font-size: 1.1rem;">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons" style="justify-content: center;">
                        <button type="button" class="btn" id="btnProcess">
                            <span class="btn-text">Proses Sekarang</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="prevStep(3)">Batal</button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        let currentData = {
            no_rekening: '',
            nama: '',
            saldo: 0,
            user_id: 0,
            amount: 0
        };

        // Format Rupiah
        const formatRupiah = (angka) => {
            return new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            }).format(angka);
        };

        const parseRupiah = (str) => {
            return parseInt(str.replace(/[^0-9]/g, '')) || 0;
        };

        // Navigation
        function showStep(step) {
            $('.content-step').removeClass('active');
            $(`#step${step}`).addClass('active');

            $('.step-item').removeClass('active completed');
            for (let i = 1; i <= 4; i++) {
                if (i < step) $(`#step${i}-indicator`).addClass('completed');
                if (i === step) $(`#step${i}-indicator`).addClass('active');
            }
        }

        function prevStep(step) {
            showStep(step);
            if (step === 1) {
                $('#no_rekening').focus();
            }
        }

        // Loading State Helper
        function setLoading(btnId, isLoading, text = '') {
            const btn = $(btnId);
            const originalText = btn.data('original-text');

            if (isLoading) {
                if (!originalText) btn.data('original-text', btn.find('.btn-text').text());
                btn.prop('disabled', true);
                btn.find('.btn-text').html(`<i class="fas fa-spinner fa-spin"></i> ${text}`);
            } else {
                btn.prop('disabled', false);
                btn.find('.btn-text').text(originalText || btn.text());
            }
        }

        $(document).ready(function () {
            // Sidebar Toggle
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.getElementById('pageHamburgerBtn');

            if (toggleBtn) {
                toggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    document.body.classList.toggle('sidebar-active');
                    sidebar.classList.toggle('active');
                });

                document.addEventListener('click', (e) => {
                    if (window.innerWidth <= 1024 &&
                        document.body.classList.contains('sidebar-active') &&
                        !sidebar.contains(e.target) &&
                        !toggleBtn.contains(e.target)) {
                        document.body.classList.remove('sidebar-active');
                        sidebar.classList.remove('active');
                    }
                });
            }

            // Input Rupiah Listener
            $('#jumlah').on('input', function () {
                let val = parseRupiah($(this).val());
                $(this).val(val > 0 ? formatRupiah(val).replace('Rp', '').trim() : '');
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
                $('#error-rekening').hide();
            });

            // Quick Amounts
            $('.quick-amount-btn').click(function () {
                let val = $(this).data('value');
                $('#jumlah').val(formatRupiah(val).replace('Rp', '').trim());
            });

            // PIN Digit Input Handler
            const pinDigits = document.querySelectorAll('.pin-digit');

            pinDigits.forEach((input, index) => {
                // Handle input
                input.addEventListener('input', function (e) {
                    // Only allow numbers
                    this.value = this.value.replace(/[^0-9]/g, '');

                    if (this.value.length === 1) {
                        this.classList.add('filled');
                        // Move to next input
                        if (index < 5) {
                            pinDigits[index + 1].focus();
                        }
                    }

                    // Update hidden PIN field
                    updatePinValue();
                });

                // Handle backspace
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && this.value === '' && index > 0) {
                        pinDigits[index - 1].focus();
                        pinDigits[index - 1].value = '';
                        pinDigits[index - 1].classList.remove('filled');
                    }
                });

                // Handle paste
                input.addEventListener('paste', function (e) {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);

                    for (let i = 0; i < pastedData.length && i < 6; i++) {
                        pinDigits[i].value = pastedData[i];
                        pinDigits[i].classList.add('filled');
                    }

                    // Focus on next empty or last
                    const nextEmpty = Array.from(pinDigits).findIndex(d => d.value === '');
                    if (nextEmpty !== -1) {
                        pinDigits[nextEmpty].focus();
                    } else {
                        pinDigits[5].focus();
                    }

                    updatePinValue();
                });

                // Clear filled class when empty
                input.addEventListener('blur', function () {
                    if (this.value === '') {
                        this.classList.remove('filled');
                    }
                });
            });

            // Function to update hidden PIN value
            function updatePinValue() {
                let pin = '';
                pinDigits.forEach(input => {
                    pin += input.value;
                });
                $('#pin').val(pin);
            }

            // Function to clear PIN inputs
            window.clearPinInputs = function () {
                pinDigits.forEach(input => {
                    input.value = '';
                    input.classList.remove('filled');
                });
                $('#pin').val('');
                pinDigits[0].focus();
            };

            // STEP 1: Cek Rekening
            $('#checkForm').submit(function (e) {
                e.preventDefault();
                const btn = '#btnCheck';
                const rekening = $('#no_rekening').val().trim();

                if (rekening.length !== 8) {
                    $('#no_rekening').addClass('error-input');
                    Swal.fire('Error', 'Nomor rekening harus 8 digit', 'error');
                    return;
                }

                setLoading(btn, true, 'Mengecek...');

                // Simulate loading delay 2s
                setTimeout(() => {
                    $.ajax({
                        url: 'proses_tarik_admin.php',
                        type: 'POST',
                        data: {
                            action: 'cek_rekening',
                            no_rekening: rekening
                        },
                        success: function (resp) {
                            setLoading(btn, false);
                            if (resp.status === 'success') {
                                currentData = {
                                    ...currentData,
                                    no_rekening: resp.no_rekening,
                                    nama: resp.nama,
                                    saldo: resp.saldo,
                                    user_id: resp.user_id,
                                    jurusan: resp.jurusan, // Capture jurusan if needed
                                    kelas: resp.kelas      // Capture kelas if needed
                                };

                                $('#detail-nama').text(resp.nama);
                                $('#detail-jurusan').text(resp.jurusan);
                                $('#detail-kelas').text(resp.kelas);


                                showStep(2);
                                $('#jumlah').focus();
                            } else {
                                Swal.fire('Gagal', resp.message, 'error');
                            }
                        },
                        error: function () {
                            setLoading(btn, false);
                            Swal.fire('Error', 'Terjadi kesalahan sistem', 'error');
                        }
                    });
                }, 2000);
            });

            // STEP 2: Input Amount
            $('#amountForm').submit(function (e) {
                e.preventDefault();
                const btn = '#btnAmount';
                const amount = parseRupiah($('#jumlah').val());

                if (amount <= 0) {
                    Swal.fire('Error', 'Masukkan nominal yang valid', 'warning');
                    return;
                }

                if (amount > currentData.saldo) {
                    Swal.fire('Saldo Kurang', 'Saldo nasabah tidak mencukupi untuk penarikan ini', 'error');
                    return;
                }

                setLoading(btn, true, 'Mengecek saldo....');

                // Check Balance via Backend (Double Check)
                setTimeout(() => {
                    $.ajax({
                        url: 'proses_tarik_admin.php',
                        type: 'POST',
                        data: {
                            action: 'check_balance',
                            no_rekening: currentData.no_rekening,
                            jumlah: amount
                        },
                        success: function (resp) {
                            setLoading(btn, false);
                            if (resp.status === 'success') {
                                currentData.amount = amount;
                                showStep(3);
                                clearPinInputs();
                            } else {
                                Swal.fire('Gagal', resp.message, 'error');
                            }
                        },
                        error: function () {
                            setLoading(btn, false);
                            Swal.fire('Error', 'Gagal memverifikasi saldo', 'error');
                        }
                    });
                }, 2000);
            });

            // STEP 3: Verify PIN
            $('#pinForm').submit(function (e) {
                e.preventDefault();
                const btn = '#btnPin';
                const pin = $('#pin').val().trim();

                if (pin.length !== 6) {
                    Swal.fire('Error', 'PIN harus 6 digit angka', 'warning');
                    return;
                }

                setLoading(btn, true, 'Verifikasi...');

                setTimeout(() => {
                    $.ajax({
                        url: 'proses_tarik_admin.php',
                        type: 'POST',
                        data: {
                            action: 'verify_pin',
                            user_id: currentData.user_id,
                            pin: pin
                        },
                        success: function (resp) {
                            setLoading(btn, false);
                            if (resp.status === 'success') {
                                // Populate Confirmation Data
                                $('#confirm-rekening').text(currentData.no_rekening);
                                $('#confirm-nama').text(currentData.nama);
                                $('#confirm-jumlah').text(formatRupiah(currentData.amount));
                                showStep(4);
                            } else {
                                Swal.fire('Gagal', resp.message, 'error');
                                clearPinInputs();
                            }
                        },
                        error: function () {
                            setLoading(btn, false);
                            Swal.fire('Error', 'Gagal memverifikasi PIN', 'error');
                        }
                    });
                }, 2000);
            });

            // STEP 4: Process Withdrawal
            $('#btnProcess').click(function () {
                const btn = '#btnProcess';

                Swal.fire({
                    title: 'Proses Penarikan?',
                    text: `Tarik tunai sebesar ${formatRupiah(currentData.amount)}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Proses',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#1e293b'
                }).then((result) => {
                    if (result.isConfirmed) {
                        setLoading(btn, true, 'Memproses...');

                        setTimeout(() => {
                            $.ajax({
                                url: 'proses_tarik_admin.php',
                                type: 'POST',
                                data: {
                                    action: 'tarik_saldo',
                                    no_rekening: currentData.no_rekening,
                                    jumlah: currentData.amount,
                                    // petugas_id is handled in session PHP side
                                },
                                success: function (resp) {
                                    setLoading(btn, false);
                                    if (resp.status === 'success') {
                                        Swal.fire({
                                            title: 'Berhasil!',
                                            text: 'Penarikan tunai berhasil diproses',
                                            icon: 'success',
                                            confirmButtonColor: '#1e293b'
                                        }).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        Swal.fire('Gagal', resp.message, 'error');
                                    }
                                },
                                error: function () {
                                    setLoading(btn, false);
                                    Swal.fire('Error', 'Gagal memproses transaksi', 'error');
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