<?php
/**
 * Cek Mutasi - Adaptive Path Version
 * File: pages/petugas/cek_mutasi.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/cek_mutasi.php
 * - Hosting: public_html/pages/petugas/cek_mutasi.php
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
    <title>Cek Mutasi | KASDIG</title>
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
            /* Adapted for standard sidebar width */
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

        input[type="text"],
        input[type="date"] {
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

        /* Date Picker Wrapper */
        .date-input-wrapper {
            position: relative;
            width: 100%;
        }

        input[type="date"] {
            appearance: none;
            -webkit-appearance: none;
            position: relative;
            padding-right: 40px;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 1.1rem;
            z-index: 1;
            pointer-events: none;
        }

        /* Buttons */
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
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-secondary {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
            color: var(--gray-800);
            transform: translateY(-1px);
        }

        /* Filter Section */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            align-items: end;
        }

        .clear-btn {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: var(--gray-500);
            cursor: pointer;
            display: none;
            padding: 4px;
        }

        .clear-btn.show {
            display: block;
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            color: var(--gray-800);
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tbody tr:hover {
            background-color: var(--gray-100);
        }

        /* Status Badges */
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        /* Amount Colors */
        .amount-credit {
            color: #22c55e;
            font-weight: 600;
        }

        .amount-debit {
            color: #ef4444;
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .pagination-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            background: white;
            font-size: 0.95rem;
        }

        .pagination-link:hover {
            background: var(--gray-100);
        }

        .pagination-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-icon {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        .empty-text {
            color: var(--gray-500);
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

            .main-content {
                padding: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- New Page Title Section (Admin Style) -->
        <div class="page-title-section">
            <button class="page-hamburger" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Cek Mutasi</h1>
                <p class="page-subtitle">Cek riwayat transaksi nasabah</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Cek Mutasi</h3>
                    <p>Masukkan nomor rekening untuk melihat mutasi</p>
                </div>
            </div>
            <div class="card-body">
                <form id="searchForm">
                    <div class="form-group">
                        <label class="form-label" for="no_rekening">No Rekening (8 digit)</label>
                        <div style="position: relative;">
                            <input type="text" id="no_rekening" name="no_rekening"
                                placeholder="Masukkan 8 digit nomor rekening" required maxlength="8" autofocus
                                autocomplete="off">
                            <button type="button" class="clear-btn" id="clearBtn">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="btn-container">
                        <button type="submit" class="btn" id="searchBtn"><i class="fas fa-search"></i> Lihat
                            Mutasi</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card" id="filterSection" style="display:none;">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="card-header-text">
                    <h3>Filter Tanggal</h3>
                    <p>Saring mutasi berdasarkan tanggal</p>
                </div>
            </div>
            <div class="card-body">
                <form id="filterForm">
                    <input type="hidden" id="filter_no_rekening" name="no_rekening">
                    <div class="filter-grid">
                        <div class="form-group">
                            <label class="form-label" for="start_date">Tanggal Mulai</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="start_date" name="start_date">
                                <i class="fas fa-calendar-alt calendar-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="end_date">Tanggal Selesai</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="end_date" name="end_date">
                                <i class="fas fa-calendar-alt calendar-icon"></i>
                            </div>
                        </div>
                        <div class="form-group" style="padding-bottom: 2px;">
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
                                <button type="button" class="btn btn-secondary" id="resetFilter"><i
                                        class="fas fa-undo"></i> Reset</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div id="results"></div>
    </div>

    <script>
        let currentNoRekening = '';
        let currentPage = 1;

        $(document).ready(function () {
            const menuToggle = $('#menuToggle');
            const sidebar = $('#sidebar');
            const noRekeningInput = $('#no_rekening');
            const clearBtn = $('#clearBtn');
            const filterSection = $('#filterSection');

            function toggleClearBtn() {
                if (noRekeningInput.val().trim().length > 0) {
                    clearBtn.addClass('show');
                } else {
                    clearBtn.removeClass('show');
                }
            }

            function clearInputAndResults() {
                noRekeningInput.val('');
                clearBtn.removeClass('show');
                $('#results').html('');
                filterSection.hide();
                $('#start_date').val('');
                $('#end_date').val('');
                currentNoRekening = '';
                currentPage = 1;
                noRekeningInput.focus();
            }

            clearBtn.on('click', function () {
                clearInputAndResults();
            });

            $('.date-input-wrapper').on('click', function () {
                $(this).find('input[type="date"]')[0].showPicker();
            });

            // Handled mostly by sidebar itself, but we need the toggle button click
            if (menuToggle.length) {
                menuToggle.on('click', function (e) {
                    e.stopPropagation();
                    // Just toggle the class that sidebar_petugas.php or simple css expects
                    // Based on previous files, it usually toggles 'active' on sidebar 
                    // and 'sidebar-active' on body for mobile overlay
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

            noRekeningInput.on('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
                toggleClearBtn();
                if (this.value.trim().length === 0) {
                    $('#results').html('');
                    filterSection.hide();
                }
            });

            noRekeningInput.on('paste', function (e) {
                e.preventDefault();
                let pastedData = (e.originalEvent || e).clipboardData.getData('text/plain');
                pastedData = pastedData.replace(/[^0-9]/g, '').substring(0, 8);
                const start = this.selectionStart;
                const end = this.selectionEnd;
                const val = this.value;
                this.value = (val.slice(0, start) + pastedData + val.slice(end)).substring(0, 8);
                toggleClearBtn();
            });

            $('#searchForm').on('submit', function (e) {
                e.preventDefault();
                const rekening = noRekeningInput.val().trim();

                if (rekening.length !== 8) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validasi Gagal',
                        text: 'Nomor rekening harus terdiri dari 8 digit'
                    }).then(() => {
                        clearInputAndResults();
                    });
                    return;
                }

                currentNoRekening = rekening;
                currentPage = 1;
                loadMutasi(rekening, 1, '', '', $('#searchBtn'));
            });

            $('#filterForm').on('submit', function (e) {
                e.preventDefault();
                const startDate = $('#start_date').val();
                const endDate = $('#end_date').val();

                if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Validasi Gagal',
                        text: 'Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai'
                    });
                    return;
                }
                currentPage = 1;
                loadMutasi(currentNoRekening, 1, startDate, endDate, $(this).find('button[type="submit"]'));
            });

            $('#resetFilter').on('click', function () {
                $('#start_date').val('');
                $('#end_date').val('');
                currentPage = 1;
                loadMutasi(currentNoRekening, 1, '', '', $(this));
            });

            $(document).on('click', '.pagination-link', function (e) {
                e.preventDefault();
                const page = $(this).data('page');
                const startDate = $('#start_date').val();
                const endDate = $('#end_date').val();
                loadMutasi(currentNoRekening, page, startDate, endDate);
            });

            toggleClearBtn();
        });

        function loadMutasi(noRekening, page = 1, startDate = '', endDate = '', loadingBtn = null) {
            let originalBtnHtml = '';

            if (loadingBtn) {
                originalBtnHtml = loadingBtn.html();
                loadingBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Mencari...');
            } else {
                Swal.fire({ title: 'Memuat data mutasi...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            }

            setTimeout(() => {
                $.ajax({
                    url: 'proses_cek_mutasi.php',
                    type: 'POST',
                    data: { action: 'load_mutasi', no_rekening: noRekening, page: page, start_date: startDate, end_date: endDate },
                    dataType: 'json',
                    success: function (response) {
                        if (loadingBtn) {
                            loadingBtn.prop('disabled', false).html(originalBtnHtml);
                        } else {
                            Swal.close();
                        }

                        if (response.success) {
                            $('#filter_no_rekening').val(noRekening);
                            $('#filterSection').show();
                            $('#results').html(response.html);
                            currentPage = page;
                        } else {
                            if (response.message === 'Rekening tidak ditemukan') {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Tidak Ditemukan',
                                    text: response.message
                                }).then(() => {
                                    $('#no_rekening').val('');
                                    $('#clearBtn').removeClass('show');
                                    $('#results').html('');
                                    $('#filterSection').hide();
                                    $('#start_date').val('');
                                    $('#end_date').val('');
                                    currentNoRekening = '';
                                    currentPage = 1;
                                    $('#no_rekening').focus();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: response.message
                                }).then(() => {
                                    $('#results').html('');
                                    $('#filterSection').hide();
                                });
                            }
                        }
                    },
                    error: function () {
                        if (loadingBtn) {
                            loadingBtn.prop('disabled', false).html(originalBtnHtml);
                        } else {
                            Swal.close();
                        }
                        Swal.fire({
                            icon: 'error',
                            title: 'Kesalahan Server',
                            text: 'Terjadi kesalahan saat memuat data'
                        });
                    }
                });
            }, 2000);
        }
    </script>
</body>

</html>