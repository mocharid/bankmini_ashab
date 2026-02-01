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
    <title>Cek Saldo | KASDIG</title>
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

        .card-body.p-0 {
            padding: 0;
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

        input[type="text"] {
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

        /* Result Styles (Balance Display) */
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 1rem 0;
            border-bottom: 1px solid var(--gray-100);
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
        }

        .balance-display {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            margin-top: 1.5rem;
        }

        .balance-label {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .balance-amount {
            font-size: 2.5rem;
            color: var(--gray-800);
            font-weight: 700;
            margin-bottom: 0.5rem;
            font-family: 'Inter', sans-serif;
        }

        .balance-info {
            color: var(--gray-500);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        @media (max-width: 1024px) {
            .page-hamburger {
                display: block;
            }

            .page-title-section {
                padding: 1rem 1.5rem;
                margin: -1rem -1rem 1.5rem -1rem;
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
                <h1>Cek Saldo</h1>
                <p class="page-subtitle">Informasi saldo rekening nasabah</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-search"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Cek Saldo</h3>
                    <p>Masukkan nomor rekening untuk melihat saldo</p>
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
                        <button type="submit" class="btn" id="searchBtn"><i class="fas fa-search"></i> Cek
                            Saldo</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="results"></div>
    </div>
    <script>
        $(document).ready(function () {
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

            clearBtn.on('click', function () {
                clearInputAndResults();
            });

            // Sidebar logic adapted for new structure
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

            noRekeningInput.on('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
                toggleClearBtn();
                if (this.value.trim().length === 0) {
                    $('#results').html('');
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

                checkBalance(rekening, $('#searchBtn'));
            });

            toggleClearBtn();
        });
        function checkBalance(noRekening, loadingBtn = null) {
            let originalBtnHtml = '';

            if (loadingBtn) {
                originalBtnHtml = loadingBtn.html();
                loadingBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Mencari...');
            } else {
                Swal.fire({ title: 'Mencari rekening...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            }

            setTimeout(() => {
                $.ajax({
                    url: 'proses_cek_saldo.php',
                    type: 'POST',
                    data: { action: 'check_balance', no_rekening: noRekening },
                    dataType: 'json',
                    success: function (response) {
                        if (loadingBtn) {
                            loadingBtn.prop('disabled', false).html(originalBtnHtml);
                        } else {
                            Swal.close();
                        }

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
                    error: function () {
                        if (loadingBtn) {
                            loadingBtn.prop('disabled', false).html(originalBtnHtml);
                        } else {
                            Swal.close();
                        }
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
            }, 1000); // Reduced delay
        }
    </script>
</body>

</html>