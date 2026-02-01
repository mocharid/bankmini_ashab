<?php
/**
 * Transfer - Step-Based UI Version
 * File: pages/petugas/transfer.php
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

if (basename($current_dir) === 'petugas') {
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
require VENDOR_PATH . '/autoload.php';
require_once INCLUDES_PATH . '/session_validator.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

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
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transfer | KASDIG</title>
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

        /* Search Results */
        .search-wrapper {
            position: relative;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            margin-top: 4px;
            box-shadow: var(--shadow-md);
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--gray-100);
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background-color: var(--gray-50);
        }

        .search-result-name {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 0.95rem;
        }

        .search-result-account {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .search-result-detail {
            font-size: 0.8rem;
            color: var(--gray-400);
            margin-top: 4px;
        }

        .no-results {
            padding: 12px 15px;
            color: var(--gray-500);
            font-size: 0.9rem;
            text-align: center;
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
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

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
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
                <h1>Transfer</h1>
                <p class="page-subtitle">Kirim uang antar rekening KASDIG dengan cepat dan aman</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Transfer</h3>
                    <p>Ikuti langkah-langkah di bawah ini</p>
                </div>
            </div>

            <div class="card-body">
                <!-- Step Indicators -->
                <div class="steps-section">
                    <div class="step-item active" id="step1-indicator">
                        <div class="step-number">1</div>
                        <div class="step-text">Rekening Asal</div>
                    </div>
                    <div class="step-item" id="step2-indicator">
                        <div class="step-number">2</div>
                        <div class="step-text">Tujuan & Nominal</div>
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

                <!-- Step 1: Pilih Rekening Asal -->
                <div id="step1" class="content-step active">
                    <div class="form-group">
                        <label class="form-label">Cari Rekening Pengirim</label>
                        <div class="search-wrapper">
                            <input type="text" id="search_asal" placeholder="Cari nama atau nomor rekening..."
                                autocomplete="off">
                            <div class="search-results" id="results_asal"></div>
                        </div>
                        <input type="hidden" id="rekening_asal">
                        <input type="hidden" id="user_id_asal">
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn" id="btnStep1">
                            <span class="btn-text">Lanjutkan</span>
                        </button>
                    </div>
                </div>

                <!-- Step 2: Rekening Tujuan & Nominal -->
                <div id="step2" class="content-step">
                    <div class="card"
                        style="border: 1px dashed var(--gray-300); background: var(--gray-50); box-shadow: none;">
                        <div class="card-body" style="padding: 1rem;">
                            <div class="detail-row">
                                <span class="detail-label">Nama Pengirim</span>
                                <span class="detail-value" id="detail-nama-asal">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Jurusan</span>
                                <span class="detail-value" id="detail-jurusan-asal">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Kelas</span>
                                <span class="detail-value" id="detail-kelas-asal">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 1.5rem;">
                        <label class="form-label">Cari Rekening Tujuan</label>
                        <div class="search-wrapper">
                            <input type="text" id="search_tujuan" placeholder="Cari nama atau nomor rekening..."
                                autocomplete="off">
                            <div class="search-results" id="results_tujuan"></div>
                        </div>
                        <input type="hidden" id="rekening_tujuan">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Nominal Transfer</label>
                        <input type="text" id="jumlah" placeholder="Rp 0" inputmode="numeric">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Keterangan (Opsional)</label>
                        <input type="text" id="keterangan" placeholder="Tambahkan catatan...">
                    </div>

                    <div class="action-buttons">
                        <button type="button" class="btn" id="btnStep2">
                            <span class="btn-text">Lanjutkan</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="prevStep(1)">Kembali</button>
                    </div>
                </div>

                <!-- Step 3: Verify PIN -->
                <div id="step3" class="content-step">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <i class="fas fa-lock"
                            style="font-size: 3rem; color: var(--gray-400); margin-bottom: 1rem;"></i>
                        <h4 style="color: var(--gray-700); margin-bottom: 0.5rem;">Verifikasi Keamanan</h4>
                        <p style="color: var(--gray-500); font-size: 0.9rem;">Masukkan PIN pengirim untuk melanjutkan
                            transfer</p>
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
                        <h3 style="color: var(--gray-800); margin-bottom: 0.5rem;">Konfirmasi Transfer</h3>
                        <p style="color: var(--gray-500);">Pastikan data berikut sudah benar</p>
                    </div>

                    <div class="card" style="border: 1px solid var(--gray-200); box-shadow: none;">
                        <div class="card-body">
                            <div class="detail-row">
                                <span class="detail-label">Pengirim</span>
                                <span class="detail-value" id="confirm-pengirim">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Penerima</span>
                                <span class="detail-value" id="confirm-penerima">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Nominal Transfer</span>
                                <span class="detail-value" id="confirm-jumlah" style="font-size: 1.1rem;">-</span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Keterangan</span>
                                <span class="detail-value" id="confirm-keterangan">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="action-buttons" style="justify-content: center;">
                        <button type="button" class="btn" id="btnProcess">
                            <span class="btn-text">Proses Transfer</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="prevStep(3)">Batal</button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        let currentData = {
            rekening_asal: '',
            nama_asal: '',
            jurusan_asal: '',
            kelas_asal: '',
            user_id_asal: 0,
            rekening_tujuan: '',
            nama_tujuan: '',
            jumlah: 0,
            keterangan: ''
        };

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
        }

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

        // Search functionality
        let searchTimeout;
        function initSearch(inputId, resultsId, type) {
            const input = document.getElementById(inputId);
            const results = document.getElementById(resultsId);

            if (!input || !results) return;

            input.addEventListener('input', function () {
                const query = this.value.trim();
                if (query.length < 2) {
                    results.classList.remove('show');
                    return;
                }

                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax_action', 'search_account');
                    formData.append('search', query);

                    try {
                        const response = await fetch('proses_transfer.php', { method: 'POST', body: formData });
                        const result = await response.json();

                        if (result.success && result.accounts.length > 0) {
                            results.innerHTML = '';
                            result.accounts.forEach(account => {
                                // Filter out source account in destination search
                                if (type === 'tujuan' && account.no_rekening === currentData.rekening_asal) {
                                    return;
                                }

                                const item = document.createElement('div');
                                item.className = 'search-result-item';
                                item.innerHTML = `
                                    <div class="search-result-name">${account.nama}</div>
                                    <div class="search-result-account">${account.no_rekening}</div>
                                    <div class="search-result-detail">${account.kelas || '-'} • ${account.jurusan || '-'}</div>
                                `;
                                item.addEventListener('click', () => {
                                    input.value = `${account.nama} - ${account.no_rekening}`;
                                    results.classList.remove('show');

                                    if (type === 'asal') {
                                        currentData.rekening_asal = account.no_rekening;
                                        currentData.nama_asal = account.nama;
                                        currentData.jurusan_asal = account.jurusan || '-';
                                        currentData.kelas_asal = account.kelas || '-';
                                        currentData.user_id_asal = account.user_id;
                                        $('#rekening_asal').val(account.no_rekening);
                                        $('#user_id_asal').val(account.user_id);
                                    } else {
                                        currentData.rekening_tujuan = account.no_rekening;
                                        currentData.nama_tujuan = account.nama;
                                        $('#rekening_tujuan').val(account.no_rekening);
                                    }
                                });
                                results.appendChild(item);
                            });
                            results.classList.add('show');
                        } else {
                            results.innerHTML = '<div class="no-results">Tidak ada hasil ditemukan</div>';
                            results.classList.add('show');
                        }
                    } catch (error) {
                        console.error('Search error:', error);
                    }
                }, 300);
            });

            document.addEventListener('click', (e) => {
                if (!input.contains(e.target) && !results.contains(e.target)) {
                    results.classList.remove('show');
                }
            });
        }

        $(document).ready(function () {
            // Sidebar Toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', function (e) {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Init search
            initSearch('search_asal', 'results_asal', 'asal');
            initSearch('search_tujuan', 'results_tujuan', 'tujuan');

            // Rupiah input
            $('#jumlah').on('input', function () {
                let val = parseRupiah($(this).val());
                $(this).val(val > 0 ? formatRupiah(val).replace('Rp', '').trim() : '');
            });

            // PIN Digit Input Handler
            const pinDigits = document.querySelectorAll('.pin-digit');

            pinDigits.forEach((input, index) => {
                input.addEventListener('input', function (e) {
                    this.value = this.value.replace(/[^0-9]/g, '');

                    if (this.value.length === 1) {
                        this.classList.add('filled');
                        if (index < 5) {
                            pinDigits[index + 1].focus();
                        }
                    }
                    updatePinValue();
                });

                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && this.value === '' && index > 0) {
                        pinDigits[index - 1].focus();
                        pinDigits[index - 1].value = '';
                        pinDigits[index - 1].classList.remove('filled');
                    }
                });

                input.addEventListener('paste', function (e) {
                    e.preventDefault();
                    const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);

                    for (let i = 0; i < pastedData.length && i < 6; i++) {
                        pinDigits[i].value = pastedData[i];
                        pinDigits[i].classList.add('filled');
                    }

                    const nextEmpty = Array.from(pinDigits).findIndex(d => d.value === '');
                    if (nextEmpty !== -1) {
                        pinDigits[nextEmpty].focus();
                    } else {
                        pinDigits[5].focus();
                    }

                    updatePinValue();
                });

                input.addEventListener('blur', function () {
                    if (this.value === '') {
                        this.classList.remove('filled');
                    }
                });
            });

            function updatePinValue() {
                let pin = '';
                pinDigits.forEach(input => {
                    pin += input.value;
                });
                $('#pin').val(pin);
            }

            function clearPinInputs() {
                pinDigits.forEach(input => {
                    input.value = '';
                    input.classList.remove('filled');
                });
                $('#pin').val('');
                if (pinDigits.length > 0) {
                    pinDigits[0].focus();
                }
            }

            // STEP 1: Pilih Rekening Asal
            $('#btnStep1').click(function () {
                if (!currentData.rekening_asal) {
                    Swal.fire('Error', 'Pilih rekening pengirim terlebih dahulu', 'warning');
                    return;
                }

                setLoading('#btnStep1', true, 'Mengecek...');

                setTimeout(() => {
                    // Populate step 2 details
                    $('#detail-nama-asal').text(currentData.nama_asal);
                    $('#detail-jurusan-asal').text(currentData.jurusan_asal);
                    $('#detail-kelas-asal').text(currentData.kelas_asal);

                    setLoading('#btnStep1', false);
                    showStep(2);
                    $('#search_tujuan').focus();
                }, 1000);
            });

            // STEP 2: Input Tujuan & Nominal
            $('#btnStep2').click(function () {
                const tujuan = currentData.rekening_tujuan;
                const jumlah = parseRupiah($('#jumlah').val());
                const keterangan = $('#keterangan').val().trim() || '-';

                if (!tujuan) {
                    Swal.fire('Error', 'Pilih rekening tujuan terlebih dahulu', 'warning');
                    return;
                }

                if (jumlah <= 0) {
                    Swal.fire('Error', 'Masukkan nominal yang valid', 'warning');
                    return;
                }

                currentData.jumlah = jumlah;
                currentData.keterangan = keterangan;

                setLoading('#btnStep2', true, 'Mengecek saldo...');

                setTimeout(() => {
                    // Check balance via backend
                    $.ajax({
                        url: 'proses_transfer.php',
                        type: 'POST',
                        data: {
                            ajax_action: 'check_balance',
                            rekening_asal: currentData.rekening_asal,
                            jumlah: jumlah
                        },
                        dataType: 'json',
                        success: function (resp) {
                            setLoading('#btnStep2', false);
                            if (resp.success) {
                                showStep(3);
                                clearPinInputs();
                            } else {
                                Swal.fire('Gagal', resp.message || 'Saldo tidak mencukupi', 'error');
                            }
                        },
                        error: function () {
                            setLoading('#btnStep2', false);
                            Swal.fire('Error', 'Gagal memverifikasi saldo', 'error');
                        }
                    });
                }, 1500);
            });

            // STEP 3: Verify PIN
            $('#pinForm').submit(function (e) {
                e.preventDefault();
                const pin = $('#pin').val().trim();

                if (pin.length !== 6) {
                    Swal.fire('Error', 'PIN harus 6 digit angka', 'warning');
                    return;
                }

                setLoading('#btnPin', true, 'Verifikasi...');

                setTimeout(() => {
                    $.ajax({
                        url: 'proses_transfer.php',
                        type: 'POST',
                        data: {
                            ajax_action: 'validate_pin',
                            rekening_asal: currentData.rekening_asal,
                            pin: pin
                        },
                        dataType: 'json',
                        success: function (resp) {
                            setLoading('#btnPin', false);
                            if (resp.success) {
                                // Populate Confirmation Data
                                $('#confirm-pengirim').text(currentData.nama_asal);
                                $('#confirm-penerima').text(currentData.nama_tujuan);
                                $('#confirm-jumlah').text(formatRupiah(currentData.jumlah));
                                $('#confirm-keterangan').text(currentData.keterangan);
                                showStep(4);
                            } else {
                                Swal.fire('Gagal', resp.message || 'PIN salah', 'error');
                                clearPinInputs();
                            }
                        },
                        error: function () {
                            setLoading('#btnPin', false);
                            Swal.fire('Error', 'Gagal memverifikasi PIN', 'error');
                        }
                    });
                }, 2000);
            });

            // STEP 4: Process Transfer
            $('#btnProcess').click(function () {
                Swal.fire({
                    title: 'Proses Transfer?',
                    text: `Transfer sebesar ${formatRupiah(currentData.jumlah)} ke ${currentData.nama_tujuan}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Proses',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#1e293b'
                }).then((result) => {
                    if (result.isConfirmed) {
                        setLoading('#btnProcess', true, 'Memproses...');

                        setTimeout(() => {
                            $.ajax({
                                url: 'proses_transfer.php',
                                type: 'POST',
                                data: {
                                    ajax_action: 'process_transfer',
                                    rekening_asal: currentData.rekening_asal,
                                    rekening_tujuan: currentData.rekening_tujuan,
                                    jumlah: currentData.jumlah,
                                    keterangan: currentData.keterangan
                                },
                                dataType: 'json',
                                success: function (resp) {
                                    setLoading('#btnProcess', false);
                                    if (resp.success) {
                                        Swal.fire({
                                            title: 'Berhasil!',
                                            text: resp.message || 'Transfer berhasil diproses',
                                            icon: 'success',
                                            confirmButtonColor: '#1e293b'
                                        }).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        Swal.fire('Gagal', resp.message || 'Transfer gagal', 'error');
                                    }
                                },
                                error: function () {
                                    setLoading('#btnProcess', false);
                                    Swal.fire('Error', 'Gagal memproses transfer', 'error');
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