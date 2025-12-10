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
    <title>Cek Mutasi | MY Schobank</title>
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
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; user-select: none; }
        html { width: 100%; min-height: 100vh; overflow-x: hidden; }
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
        
        /* Welcome Banner - Fixed Layout */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .welcome-banner .banner-content { flex: 1; }
        .welcome-banner h2 {
            margin: 0 0 5px 0;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex; align-items: center; gap: 10px;
        }
        .welcome-banner p { opacity: 0.9; font-size: clamp(0.9rem, 2vw, 1rem); margin: 0; }
        
        .menu-toggle {
            display: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .form-section {
            background: var(--bg-table);
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        .form-group { display: flex; flex-direction: column; gap: 8px; position: relative; }
        label {
            font-weight: 500; color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            display: flex; align-items: center; gap: 6px;
        }
        label i { color: var(--primary-color); font-size: 0.95em; }
        .input-wrapper { position: relative; width: 100%; }
        input[type="text"] {
            width: 100%; padding: 12px 40px 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 5px; font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5; min-height: 44px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            -webkit-user-select: text; user-select: text;
            background-color: #fff;
        }
        .clear-btn {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--text-light);
            cursor: pointer; padding: 5px; display: none;
            align-items: center; justify-content: center;
            width: 24px; height: 24px; border-radius: 50%;
            transition: all 0.3s ease; font-size: 0.9rem;
        }
        .clear-btn:hover { background-color: var(--border-color); color: var(--danger-color); }
        .clear-btn.show { display: flex; }
        
        .date-input-wrapper { position: relative; width: 100%; }
        input[type="date"] {
            width: 100%; padding: 12px 45px 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 5px; font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5; min-height: 44px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            -webkit-appearance: none; appearance: none;
            background-color: #fff; color: var(--text-primary); cursor: pointer;
        }
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute; right: 0; width: 100%; height: 100%;
            margin: 0; padding: 0; opacity: 0; cursor: pointer;
        }
        .calendar-icon {
            position: absolute; right: 15px; top: 50%;
            transform: translateY(-50%); color: var(--primary-color);
            font-size: 1.1rem; pointer-events: none; z-index: 1;
        }
        input:focus { outline: none; border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); }
        
        .btn-container { display: flex; justify-content: center; margin-top: 20px; }
        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white; border: none; padding: 12px 25px;
            border-radius: 5px; cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem); font-weight: 500;
            display: flex; align-items: center; justify-content: center;
            gap: 8px; transition: var(--transition); min-height: 44px;
        }
        .btn:hover { background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%); transform: translateY(-2px); box-shadow: var(--shadow-sm); }
        .btn:active { transform: scale(0.95); }
        .btn-secondary { background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); }
        .btn-secondary:hover { background: linear-gradient(135deg, #4b5563 0%, #374151 100%); }
        
        .filter-section {
            background: var(--bg-table); border-radius: 5px;
            padding: 20px; box-shadow: var(--shadow-sm);
            margin-bottom: 20px; display: none;
        }
        .filter-section.show { display: block; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        
        .table-container {
            background: white; border-radius: 5px;
            padding: 25px; box-shadow: var(--shadow-md); margin-bottom: 20px;
        }
        .table-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-color);
        }
        .table-title { font-size: clamp(1.2rem, 2.5vw, 1.4rem); font-weight: 600; color: var(--primary-dark); letter-spacing: -0.5px; }
        .table-count {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem); font-weight: 600;
            color: var(--text-secondary); background: var(--bg-light);
            padding: 6px 14px; border-radius: 5px;
        }
        .table-wrapper { overflow-x: auto; border-radius: 5px; border: 1px solid var(--border-color); }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        thead { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%); color: white; }
        th {
            padding: 14px 12px; text-align: left; font-weight: 600;
            font-size: 0.8rem; white-space: nowrap; text-transform: uppercase;
            letter-spacing: 0.5px; border-bottom: 2px solid rgba(255, 255, 255, 0.2);
        }
        td { padding: 14px 12px; border-bottom: 1px solid var(--border-color); font-size: 0.85rem; background: white; vertical-align: middle; }
        tbody tr { transition: var(--transition); }
        tbody tr:hover { background: linear-gradient(to right, #f8fafc 0%, #ffffff 100%); box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); }
        tbody tr:last-child td { border-bottom: none; }
        
        .transaction-date { font-weight: 500; color: var(--text-primary); font-size: 0.85rem; }
        .transaction-time { font-size: 0.75rem; color: var(--text-light); margin-top: 2px; }
        .transaction-type { font-weight: 600; color: var(--text-primary); font-size: 0.85rem; }
        .transaction-desc { font-size: 0.78rem; color: var(--text-secondary); margin-top: 2px; }
        .amount-credit { color: var(--success-color); font-weight: 700; font-size: 0.9rem; }
        .amount-debit { color: var(--danger-color); font-weight: 700; font-size: 0.9rem; }
        .balance-amount { font-weight: 700; font-size: 0.9rem; color: var(--text-primary); }
        
        .status-text { font-weight: 600; font-size: 0.8rem; }
        .status-text.approved { color: var(--success-color); }
        .status-text.pending { color: var(--warning-color); }
        .status-text.rejected { color: var(--danger-color); }
        
        .pagination {
            display: flex; justify-content: center; align-items: center;
            gap: 10px; margin-top: 25px; padding-top: 20px;
            border-top: 1px solid var(--border-color); flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            font-weight: 600; font-size: 1rem; color: var(--primary-color);
            text-decoration: none; user-select: none; cursor: pointer;
        }
        .pagination a:hover { text-decoration: underline; }
        .pagination .current-page { color: var(--text-primary); cursor: default; }
        
        .no-data { text-align: center; padding: 60px 20px; color: var(--text-light); }
        .no-data i { font-size: 4rem; margin-bottom: 16px; opacity: 0.3; }

        /* SweetAlert Custom */
        .swal2-popup, .swal2-title, .swal2-html-container, .swal2-confirm, .swal2-cancel { font-family: 'Poppins', sans-serif !important; border-radius: 5px !important; }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .main-content { margin-left: 0; padding: 20px 15px; max-width: 100%; }
            .welcome-banner { padding: 20px; margin-bottom: 20px; border-radius: 8px; }
            .welcome-banner h2 { font-size: 1.3rem; }
            
            .form-section, .filter-section, .table-container { padding: 16px; margin-bottom: 16px; }
            .filter-row { grid-template-columns: 1fr; gap: 12px; }
            .filter-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            .filter-buttons .btn { width: 100%; }
            
            .table-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .pagination { gap: 10px; }
            th, td { font-size: 0.78rem; padding: 12px 8px; }
            
            .transaction-date { font-size: 0.8rem; }
            .transaction-time { font-size: 0.7rem; }
            .transaction-type { font-size: 0.8rem; }
            .transaction-desc { font-size: 0.72rem; }
            .amount-credit, .amount-debit, .balance-amount { font-size: 0.82rem; }
            .status-text { font-size: 0.75rem; }
            .pagination a, .pagination span { font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="banner-content">
                <h2><i class="fas fa-file-invoice-dollar"></i> Mutasi Rekening</h2>
                <p>Lihat riwayat transaksi rekening nasabah</p>
            </div>
        </div>

        <div class="form-section">
            <form id="searchForm">
                <div class="form-group">
                    <label for="no_rekening"><i class="fas fa-hashtag"></i> No Rekening (8 digit)</label>
                    <div class="input-wrapper">
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan 8 digit nomor rekening" required maxlength="8" autofocus autocomplete="off">
                        <button type="button" class="clear-btn" id="clearBtn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="btn-container">
                    <button type="submit" class="btn" id="searchBtn"><i class="fas fa-search"></i> Lihat Mutasi</button>
                </div>
            </form>
        </div>

        <div class="filter-section" id="filterSection">
            <form id="filterForm">
                <input type="hidden" id="filter_no_rekening" name="no_rekening">
                <div class="filter-row">
                    <div class="form-group">
                        <label for="start_date"><i class="fas fa-calendar-alt"></i> Tanggal Mulai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="start_date" name="start_date">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="end_date"><i class="fas fa-calendar-alt"></i> Tanggal Selesai</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="end_date" name="end_date">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filter</button>
                    <button type="button" class="btn btn-secondary" id="resetFilter"><i class="fas fa-redo"></i> Reset</button>
                </div>
            </form>
        </div>

        <div id="results"></div>
    </div>

    <script>
    let currentNoRekening = '';
    let currentPage = 1;
    
    $(document).ready(function() {
        const menuToggle = $('#menuToggle');
        const sidebar = $('#sidebar');
        const noRekeningInput = $('#no_rekening');
        const clearBtn = $('#clearBtn');
        
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
            $('#filterSection').removeClass('show');
            $('#start_date').val('');
            $('#end_date').val('');
            currentNoRekening = '';
            currentPage = 1;
            noRekeningInput.focus();
        }
        
        clearBtn.on('click', function() {
            clearInputAndResults();
        });
        
        $('.date-input-wrapper').on('click', function() {
            $(this).find('input[type="date"]')[0].showPicker();
        });
        
        if (menuToggle.length && sidebar.length) {
            menuToggle.on('click', function(e) {
                e.stopPropagation();
                sidebar.toggleClass('active');
                $('body').toggleClass('sidebar-active');
            });
        }
        
        $(document).on('click', function(e) {
            if (sidebar.hasClass('active') && !sidebar.is(e.target) && sidebar.has(e.target).length === 0 && !menuToggle.is(e.target)) {
                sidebar.removeClass('active');
                $('body').removeClass('sidebar-active');
            }
        });
        
        noRekeningInput.on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
            toggleClearBtn();
            if (this.value.trim().length === 0) {
                $('#results').html('');
                $('#filterSection').removeClass('show');
            }
        });
        
        noRekeningInput.on('paste', function(e) {
            e.preventDefault();
            let pastedData = (e.originalEvent || e).clipboardData.getData('text/plain');
            pastedData = pastedData.replace(/[^0-9]/g, '').substring(0, 8);
            const start = this.selectionStart;
            const end = this.selectionEnd;
            const val = this.value;
            this.value = (val.slice(0, start) + pastedData + val.slice(end)).substring(0, 8);
            toggleClearBtn();
        });
        
        $('#searchForm').on('submit', function(e) {
            e.preventDefault();
            const rekening = noRekeningInput.val().trim();
            
            if (rekening.length !== 8) {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Validasi Gagal', 
                    text: 'Nomor rekening harus terdiri dari 8 digit', 
                    confirmButtonColor: '#1e3a8a' 
                }).then(() => {
                    clearInputAndResults();
                });
                return;
            }
            
            currentNoRekening = rekening;
            currentPage = 1;
            loadMutasi(rekening, 1);
        });
        
        $('#filterForm').on('submit', function(e) {
            e.preventDefault();
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();
            
            if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Validasi Gagal', 
                    text: 'Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai', 
                    confirmButtonColor: '#1e3a8a' 
                });
                return;
            }
            currentPage = 1;
            loadMutasi(currentNoRekening, 1, startDate, endDate);
        });
        
        $('#resetFilter').on('click', function() {
            $('#start_date').val('');
            $('#end_date').val('');
            currentPage = 1;
            loadMutasi(currentNoRekening, 1);
        });
        
        $(document).on('click', '.pagination a', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();
            loadMutasi(currentNoRekening, page, startDate, endDate);
        });
        
        toggleClearBtn();
    });
    
    function loadMutasi(noRekening, page = 1, startDate = '', endDate = '') {
        Swal.fire({ title: 'Memuat data mutasi...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        
        setTimeout(() => {
            $.ajax({
                url: 'proses_cek_mutasi.php',
                type: 'POST',
                data: { action: 'load_mutasi', no_rekening: noRekening, page: page, start_date: startDate, end_date: endDate },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#filter_no_rekening').val(noRekening);
                        $('#filterSection').addClass('show');
                        $('#results').html(response.html);
                        currentPage = page;
                    } else {
                        $('#filterSection').removeClass('show');
                        $('#results').html('');
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Gagal!', 
                            text: response.message, 
                            confirmButtonColor: '#1e3a8a' 
                        }).then(() => {
                            // Clear input setelah alert ditutup
                            $('#no_rekening').val('');
                            $('#clearBtn').removeClass('show');
                            $('#start_date').val('');
                            $('#end_date').val('');
                            currentNoRekening = '';
                            currentPage = 1;
                            $('#no_rekening').focus();
                        });
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Kesalahan Server', 
                        text: 'Terjadi kesalahan saat memuat data', 
                        confirmButtonColor: '#1e3a8a' 
                    }).then(() => {
                        // Clear input setelah alert ditutup
                        $('#no_rekening').val('');
                        $('#clearBtn').removeClass('show');
                        $('#results').html('');
                        $('#filterSection').removeClass('show');
                        $('#start_date').val('');
                        $('#end_date').val('');
                        currentNoRekening = '';
                        currentPage = 1;
                        $('#no_rekening').focus();
                    });
                }
            });
        }, 3000);
    }
    </script>
</body>
</html>