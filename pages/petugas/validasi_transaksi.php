<?php
/**
 * Validasi Transaksi - Adaptive Path Version
 * File: pages/petugas/validasi_transaksi.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/validasi_transaksi.php
 * - Hosting: public_html/pages/petugas/validasi_transaksi.php
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
    <title>Validasi Transaksi | MY Schobank</title>
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
        .results-card.status-approved { background: linear-gradient(135deg, rgba(16, 185, 129, 0.02) 0%, rgba(5, 150, 105, 0.02) 100%); }
        .results-card.status-rejected { background: linear-gradient(135deg, rgba(239, 68, 68, 0.02) 0%, rgba(220, 38, 38, 0.02) 100%); }
        .results-card.status-pending { background: linear-gradient(135deg, rgba(245, 158, 11, 0.02) 0%, rgba(217, 119, 6, 0.02) 100%); }
        
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
        .detail-divider { border-top: 1px solid var(--border-color); margin: 18px 0; }
        .detail-subheading {
            color: var(--primary-dark); font-size: clamp(0.95rem, 1.8vw, 1.05rem);
            font-weight: 700; display: flex; align-items: center; gap: 8px; margin: 20px 0 16px 0;
        }
        
        .badge {
            display: inline-block; padding: 6px 12px; border-radius: 5px;
            font-size: clamp(0.7rem, 1.4vw, 0.8rem); font-weight: 700; text-transform: uppercase;
        }
        .badge-setor, .badge-approved { background-color: #d1fae5; color: #065f46; }
        .badge-tarik, .badge-pending { background-color: #fef3c7; color: #92400e; }
        .badge-transfer { background-color: #dbeafe; color: #0c4a6e; }
        .badge-transaksi_qr { background-color: #f3e8ff; color: #6b21a8; }
        .badge-rejected { background-color: #fee2e2; color: #991b1b; }
        .amount-positive { color: var(--success-color); font-weight: 700; }
        
        .action-buttons { display: flex; gap: 10px; margin-top: 22px; flex-wrap: wrap; }
        .action-buttons .btn { flex: 1; min-width: 140px; }
        .btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .btn-success:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
        .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .btn-danger:hover { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }
        
        .info-box, .warning-box, .error-box {
            border-radius: 5px; padding: 14px 16px; margin-top: 18px;
            font-size: 0.9rem; display: flex; align-items: center; gap: 10px; font-weight: 500;
        }
        .info-box { background: linear-gradient(135deg, #f0fdf4 0%, #f1f5fe 100%); color: #065f46; }
        .info-box i { color: var(--success-color); font-size: 1.2rem; }
        .warning-box { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); color: #78350f; }
        .warning-box i { color: var(--warning-color); font-size: 1.2rem; }
        .error-box { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%); color: #7c2d12; }
        .error-box i { color: var(--danger-color); font-size: 1.2rem; }

        /* SweetAlert Custom */
        .swal2-popup, .swal2-title, .swal2-html-container, .swal2-confirm, .swal2-cancel { font-family: 'Poppins', sans-serif !important; border-radius: 5px !important; }
        
        @media (max-width: 768px) {
            .menu-toggle { display: block; }
            .main-content { margin-left: 0; padding: 20px 15px; max-width: 100%; }
            .welcome-banner { padding: 20px; margin-bottom: 20px; border-radius: 8px; }
            .welcome-banner h2 { font-size: 1.3rem; }
            
            .form-section, .results-card { padding: 16px; margin-bottom: 16px; }
            .detail-row { grid-template-columns: 1fr; gap: 6px; }
            .action-buttons { flex-direction: column; }
            .action-buttons .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="banner-content">
                <h2><i class="fas fa-search"></i> Validasi Transaksi</h2>
                <p>Cari dan verifikasi transaksi berdasarkan nomor transaksi</p>
            </div>
        </div>

        <div class="form-section">
            <form id="searchForm">
                <div class="form-group">
                    <label for="no_transaksi"><i class="fas fa-receipt"></i> No Transaksi</label>
                    <div class="input-wrapper">
                        <input type="text" id="no_transaksi" name="no_transaksi" placeholder="Masukkan nomor transaksi..." required maxlength="20" autofocus autocomplete="off">
                        <button type="button" class="clear-btn" id="clearBtn">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div class="btn-container">
                    <button type="submit" class="btn" id="searchBtn"><i class="fas fa-search"></i> Cari</button>
                </div>
            </form>
        </div>

        <div id="results"></div>
    </div>

    <script>
    $(document).ready(function() {
        const menuToggle = $('#menuToggle');
        const sidebar = $('#sidebar');
        const noTransaksiInput = $('#no_transaksi');
        const clearBtn = $('#clearBtn');
        
        function toggleClearBtn() {
            if (noTransaksiInput.val().trim().length > 0) {
                clearBtn.addClass('show');
            } else {
                clearBtn.removeClass('show');
            }
        }
        
        function clearInputAndResults() {
            noTransaksiInput.val('');
            clearBtn.removeClass('show');
            $('#results').html('');
            noTransaksiInput.focus();
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
        
        noTransaksiInput.on('input', function() {
            this.value = this.value.substring(0, 20);
            toggleClearBtn();
            if (this.value.trim().length === 0) {
                $('#results').html('');
            }
        });
        
        noTransaksiInput.on('paste', function(e) {
            e.preventDefault();
            let pastedData = (e.originalEvent || e).clipboardData.getData('text/plain');
            pastedData = pastedData.trim().substring(0, 20);
            const start = this.selectionStart;
            const end = this.selectionEnd;
            const val = this.value;
            this.value = (val.slice(0, start) + pastedData + val.slice(end)).substring(0, 20);
            toggleClearBtn();
        });
        
        $('#searchForm').on('submit', function(e) {
            e.preventDefault();
            const transaksi = noTransaksiInput.val().trim();
            
            if (!transaksi || transaksi.length > 20) {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Validasi Gagal', 
                    text: 'No Transaksi tidak valid. Maksimum 20 karakter.', 
                    confirmButtonColor: '#1e3a8a' 
                }).then(() => {
                    clearInputAndResults();
                });
                return;
            }
            loadTransaction(transaksi);
        });
        
        toggleClearBtn();
    });

    function loadTransaction(noTransaksi) {
        Swal.fire({ title: 'Mencari transaksi...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
        
        setTimeout(() => {
            $.ajax({
                url: 'proses_validasi_transaksi.php',
                type: 'POST',
                data: { action: 'load_transaction', no_transaksi: noTransaksi },
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
                            $('#no_transaksi').val('');
                            $('#clearBtn').removeClass('show');
                            $('#no_transaksi').focus();
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
                        $('#no_transaksi').val('');
                        $('#clearBtn').removeClass('show');
                        $('#no_transaksi').focus();
                    });
                }
            });
        }, 3000);
    }

    function approveTransaction(transactionId) {
        Swal.fire({
            title: 'Setujui Transaksi?', text: 'Apakah Anda yakin ingin menyetujui transaksi ini?', icon: 'question',
            showCancelButton: true, confirmButtonText: 'Ya, Setujui', cancelButtonText: 'Batal',
            confirmButtonColor: '#10b981', cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) updateTransactionStatus(transactionId, 'approved');
        });
    }

    function rejectTransaction(transactionId) {
        Swal.fire({
            title: 'Tolak Transaksi?', text: 'Apakah Anda yakin ingin menolak transaksi ini?', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Ya, Tolak', cancelButtonText: 'Batal',
            confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280'
        }).then((result) => {
            if (result.isConfirmed) updateTransactionStatus(transactionId, 'rejected');
        });
    }

    function updateTransactionStatus(transactionId, status) {
        Swal.fire({ title: 'Memproses...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        fetch('../../includes/update_transaction_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + transactionId + '&status=' + status
        })
        .then(response => response.json())
        .then(data => {
            Swal.close();
            if (data.success) {
                Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Status transaksi telah diperbarui', confirmButtonColor: '#1e3a8a' })
                .then(() => { location.reload(); });
            } else {
                Swal.fire({ icon: 'error', title: 'Gagal!', text: data.message || 'Terjadi kesalahan', confirmButtonColor: '#1e3a8a' });
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire({ icon: 'error', title: 'Kesalahan!', text: 'Terjadi kesalahan: ' + error, confirmButtonColor: '#1e3a8a' });
        });
    }
    </script>
</body>
</html>