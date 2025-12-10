<?php
/**
 * Cek Saldo - Adaptive Path Version
 * File: pages/petugas/cek_saldo.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/cek_saldo.php
 * - Hosting: public_html/pages/petugas/cek_saldo.php
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
$username = $_SESSION['username'] ?? 'Petugas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <meta name="format-detection" content="telephone=no">
    <title>Cek Saldo | MY Schobank</title>
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
        .welcome-banner p { opacity: 0.9; margin: 0; font-size: clamp(0.9rem, 2vw, 1rem); }
       
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
       
        .results-card {
            background: var(--bg-table); border-radius: 5px;
            padding: 25px; box-shadow: var(--shadow-sm); margin-bottom: 30px;
        }
        .section-title {
            font-size: clamp(1.1rem, 2.2vw, 1.3rem); color: var(--primary-dark);
            margin-bottom: 22px; display: flex; align-items: center; gap: 10px; font-weight: 700;
        }
        .detail-row {
            display: grid; grid-template-columns: 1fr 2fr; align-items: center;
            padding: 14px 0; gap: 10px; font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid var(--border-color);
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--text-secondary); font-weight: 600; text-align: left; }
        .detail-value { font-weight: 600; color: var(--text-primary); text-align: left; word-break: break-word; }
       
        .balance-display {
            padding: 20px; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-radius: 5px; text-align: center; margin-top: 20px;
            border: 2px solid #bae6fd;
        }
        .balance-label {
            color: var(--text-secondary); font-size: clamp(0.9rem, 1.8vw, 1rem);
            margin-bottom: 10px; font-weight: 500;
        }
        .balance-amount {
            font-size: clamp(1.75rem, 3.5vw, 2.2rem); color: var(--primary-color);
            font-weight: 700; margin: 10px 0; font-family: 'Courier New', monospace;
        }
        .balance-info {
            color: var(--text-secondary); font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            margin-top: 10px; display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .balance-info i { color: var(--primary-color); }
        /* SweetAlert Custom */
        .swal2-popup, .swal2-title, .swal2-html-container, .swal2-confirm, .swal2-cancel { font-family: 'Poppins', sans-serif !important; border-radius: 5px !important; }
       
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .main-content { margin-left: 0; padding: 20px 15px; max-width: 100%; }
            .welcome-banner { padding: 20px; margin-bottom: 20px; border-radius: 8px; }
            .welcome-banner h2 { font-size: 1.3rem; }
           
            .form-section, .results-card { padding: 16px; margin-bottom: 16px; }
            .detail-row { grid-template-columns: 1fr; gap: 6px; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="banner-content">
                <h2><i class="fas fa-wallet"></i> Cek Saldo</h2>
                <p>Cek saldo rekening nasabah dengan mudah dan cepat</p>
            </div>
        </div>
        <div class="form-section">
            <form id="searchForm">
                <div class="form-group">
                    <label for="no_rekening"><i class="fas fa-credit-card"></i> No Rekening (8 digit)</label>
                    <div class="input-wrapper">
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan 8 digit nomor rekening" required maxlength="8" autofocus autocomplete="off">
                        <button type="button" class="clear-btn" id="clearBtn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="btn-container">
                    <button type="submit" class="btn" id="searchBtn"><i class="fas fa-search"></i> Cek Saldo</button>
                </div>
            </form>
        </div>
        <div id="results"></div>
    </div>
    <script>
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
            noRekeningInput.focus();
        }
       
        clearBtn.on('click', function() {
            clearInputAndResults();
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
           
            if (!rekening || rekening.length !== 8) {
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
           
            checkBalance(rekening);
        });
       
        toggleClearBtn();
    });
    function checkBalance(noRekening) {
        Swal.fire({ title: 'Mencari rekening...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
       
        setTimeout(() => {
            $.ajax({
                url: 'proses_cek_saldo.php',
                type: 'POST',
                data: { action: 'check_balance', no_rekening: noRekening },
                dataType: 'json',
                success: function(response) {
                    Swal.close();
                    if (response.success) {
                        $('#results').html(response.html);
                    } else {
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
                        $('#no_rekening').focus();
                    });
                }
            });
        }, 3000);
    }
    </script>
</body>
</html>