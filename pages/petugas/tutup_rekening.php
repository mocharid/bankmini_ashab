<?php
/**
 * Tutup Rekening - Adaptive Path Version
 * File: pages/petugas/tutup_rekening.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/tutup_rekening.php
 * - Hosting: public_html/pages/petugas/tutup_rekening.php
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

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;
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
    <title>Tutup Rekening | MY Schobank</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --radius: 5px;
        }
        
        * { 
            margin: 0; padding: 0; box-sizing: border-box; 
            font-family: 'Poppins', sans-serif; 
            user-select: none;
        }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        body { 
            background-color: var(--bg-light); 
            color: var(--text-primary); 
            display: flex; 
            min-height: 100vh;
            touch-action: pan-y;
        }
        
        .main-content { 
            flex: 1; 
            margin-left: 280px; 
            padding: 30px;
            max-width: calc(100% - 280px);
            width: 100%;
            transition: margin-left 0.3s ease;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }
        
        /* Welcome Banner */
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
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .form-card { 
            background: white; 
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm); 
            margin-bottom: 35px;
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
        
        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: 12px 15px;
            border: 1px solid #ddd; 
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
        
        .details-wrapper {
            display: none; 
            gap: 20px; 
            margin: 20px 0;
        }
        
        .details-wrapper.show { 
            display: grid; 
            grid-template-columns: 1fr; 
        }
        
        .user-details { 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            border-radius: 5px; 
            padding: 20px;
        }
        
        .user-details h3 { 
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
            border-bottom: 1px solid #e2e8f0; 
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
            -webkit-tap-highlight-color: transparent;
        }
        
        .btn:hover { 
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%); 
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        
        .btn:active { 
            transform: scale(0.95);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger { 
            background: linear-gradient(135deg, var(--danger-color) 0%, #c0392b 100%); 
        }
        
        .btn-danger:hover { 
            background: linear-gradient(135deg, #c0392b 0%, var(--danger-color) 100%); 
        }
        
        .step-hidden { display: none; }
        
        /* SweetAlert Custom */
        .swal2-popup, .swal2-title, .swal2-html-container, .swal2-confirm, .swal2-cancel { 
            font-family: 'Poppins', sans-serif !important; 
            border-radius: 5px !important; 
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
            
            .detail-row { 
                flex-direction: column; 
                gap: 4px; 
                align-items: flex-start; 
            }
            
            .detail-row .value { 
                text-align: left; 
                width: 100%; 
            }
            
            .btn { max-width: 100%; }
        }
        
        @media (max-width: 480px) {
            .welcome-banner, .form-card, .user-details { padding: 15px; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-user-times"></i> Tutup Rekening Siswa</h2>
                <p>Masukkan nomor rekening untuk menutup akun siswa secara permanen</p>
            </div>
        </div>
        
        <div class="form-card">
            <div id="step1" class="step-container">
                <form id="rekeningForm">
                    <input type="hidden" name="token" value="<?php echo $token; ?>">
                    <div class="form-group">
                        <label><i class="fas fa-hashtag"></i> Nomor Rekening (8 Digit)</label>
                        <input type="text" id="no_rekening" name="no_rekening" maxlength="8" pattern="\d{8}" placeholder="Masukkan 8 digit angka" autocomplete="off" required />
                    </div>
                    <button type="submit" id="checkButton" class="btn">
                        <span><i class="fas fa-search"></i> Cek Rekening</span>
                    </button>
                </form>
            </div>
            
            <div id="step2" class="step-container step-hidden">
                <div id="details-wrapper" class="details-wrapper">
                    <div class="user-details">
                        <h3><i class="fas fa-user-circle"></i> Detail Nasabah</h3>
                        <div id="detail-content"></div>
                    </div>
                </div>
                
                <div id="password-section" class="step-hidden" style="margin-top: 20px;">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password Siswa untuk Verifikasi</label>
                        <input type="password" id="passwordInput" placeholder="Masukkan password siswa" autocomplete="off" />
                    </div>
                    <button type="button" id="closeButton" class="btn btn-danger">
                        <span><i class="fas fa-user-times"></i> Tutup Rekening</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = 0;
        let fullNoRekening = '';

        // Fungsi Format Rupiah
        function formatRupiah(angka) {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR' }).format(angka);
        }

        function resetForm() {
            $('#no_rekening, #passwordInput').val('');
            $('#step1').removeClass('step-hidden');
            $('#step2, #details-wrapper, #password-section').removeClass('show').addClass('step-hidden');
            $('#details-wrapper').removeClass('show');
            currentUserId = 0;
            fullNoRekening = '';
            setTimeout(() => $('#no_rekening').focus(), 300);
        }

        // Only allow numbers for no_rekening - NO AUTO SEARCH
        $('#no_rekening').on('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
        });

        // Search only when form is submitted (button clicked)
        $('#rekeningForm').on('submit', function(e) {
            e.preventDefault();
            const noRek = $('#no_rekening').val().trim();

            if (!/^\d{8}$/.test(noRek)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid',
                    text: 'Nomor rekening harus 8 digit angka.',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#no_rekening').val('').focus();
                });
                return;
            }

            Swal.fire({
                title: 'Memeriksa...',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: () => Swal.showLoading()
            });

            $.ajax({
                url: 'proses_tutup_rekening.php',
                type: 'POST',
                data: {
                    action: 'check_rekening', 
                    no_rekening: noRek, 
                    token: '<?php echo $token; ?>'
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        const d = res.data;
                        fullNoRekening = d.no_rekening;
                        currentUserId = d.user_id;

                        // LOGIKA SALDO MASIH ADA
                        if (res.has_saldo) {
                            Swal.close();
                            Swal.fire({
                                icon: 'warning',
                                title: 'Saldo Masih Tersedia!',
                                html: `<p>Rekening ini masih memiliki saldo: <strong style="color:#e74c3c;font-size:1.2rem;">${formatRupiah(d.saldo)}</strong></p><p>Harap kosongkan saldo sebelum menutup rekening.</p>`,
                                confirmButtonText: 'Ya, Tarik Saldo',
                                confirmButtonColor: '#1e3a8a',
                                showCancelButton: false,
                                allowOutsideClick: false,
                                allowEscapeKey: false
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    Swal.fire({
                                        title: 'Mengalihkan...',
                                        text: 'Mohon tunggu sebentar',
                                        timer: 2000,
                                        allowOutsideClick: false,
                                        allowEscapeKey: false,
                                        didOpen: () => {
                                            Swal.showLoading();
                                        }
                                    }).then(() => {
                                        window.location.href = 'tarik.php?no_rekening=' + fullNoRekening;
                                    });
                                }
                            });
                            return;
                        }

                        Swal.close();

                        let html = '';
                        const items = [
                            ['No. Rekening', d.no_rekening_display],
                            ['Nama', d.nama],
                            ['Kelas', d.nama_kelas],
                            ['Jurusan', d.nama_jurusan],
                            ['Email', d.email],
                            ['Saldo', formatRupiah(d.saldo)]
                        ];
                        items.forEach(i => html += `<div class="detail-row"><span class="label">${i[0]}:</span><span class="value">${i[1]}</span></div>`);
                        $('#detail-content').html(html);
                        $('#details-wrapper').addClass('show');
                        $('#step1').addClass('step-hidden');
                        $('#step2').removeClass('step-hidden');

                        if (res.can_close) {
                            $('#password-section').removeClass('step-hidden');
                            setTimeout(() => $('#passwordInput').focus(), 100);
                            Swal.fire({
                                icon: 'success',
                                title: 'Siap Ditutup',
                                html: 'Masukkan password siswa untuk melanjutkan.',
                                confirmButtonColor: '#10b981'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Tidak Dapat Ditutup',
                                html: res.errors.map(e => `<p><i class="fas fa-times-circle" style="color:#e74c3c"></i> ${e}</p>`).join(''),
                                confirmButtonColor: '#e74c3c'
                            }).then(() => resetForm());
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: res.message,
                            confirmButtonColor: '#e74c3c'
                        }).then(() => {
                            $('#no_rekening').val('').focus();
                        });
                    }
                },
                error: () => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Gagal memeriksa rekening.',
                        confirmButtonColor: '#e74c3c'
                    }).then(() => {
                        $('#no_rekening').val('').focus();
                    });
                }
            });
        });

        $('#closeButton').on('click', function() {
            const pass = $('#passwordInput').val().trim();
            if (!pass) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Kosong',
                    text: 'Masukkan password siswa.',
                    confirmButtonColor: '#f59e0b'
                });
                return $('#passwordInput').focus();
            }

            Swal.fire({
                title: 'Konfirmasi Penutupan',
                html: '<p>Apakah Anda yakin ingin <strong>menutup rekening ini secara permanen</strong>?</p><p style="color:#e74c3c;">Tindakan ini tidak dapat dibatalkan!</p>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Tutup Rekening',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#e74c3c',
                cancelButtonColor: '#6b7280',
                reverseButtons: true
            }).then(r => {
                if (!r.isConfirmed) return;

                Swal.fire({
                    title: 'Menutup Rekening...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                $.ajax({
                    url: 'proses_tutup_rekening.php',
                    type: 'POST',
                    data: {
                        action: 'close_rekening', 
                        user_id: currentUserId, 
                        password: pass, 
                        no_rekening: fullNoRekening, 
                        token: '<?php echo $token; ?>'
                    },
                    dataType: 'json',
                    success: res => {
                        if (res.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                html: res.message + '<br>Rekening telah ditutup permanen.',
                                timer: 4000,
                                confirmButtonColor: '#10b981'
                            }).then(() => resetForm());
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal',
                                text: res.message,
                                confirmButtonColor: '#e74c3c'
                            });
                            $('#passwordInput').val('').focus();
                        }
                    },
                    error: () => {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Gagal menutup rekening.',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                });
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            $('#no_rekening').focus();
            
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', e => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
            
            document.addEventListener('click', e => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle?.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
        });
    </script>
</body>
</html>