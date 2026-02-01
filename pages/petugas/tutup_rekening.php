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
    <title>Tutup Rekening | KASDIG</title>
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
            display: flex;
            align-items: center;
        }

        .input-wrapper input {
            padding-right: 2.5rem;
        }

        .clear-input {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            padding: 4px;
            font-size: 1rem;
            display: none;
            transition: color 0.2s;
        }

        .clear-input:hover {
            color: var(--gray-600);
        }

        .clear-input.show {
            display: block;
        }

        input[type="text"],
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

        /* Details styling */
        .details-wrapper {
            display: none;
            margin-top: 1.5rem;
        }

        .details-wrapper.show {
            display: block;
        }

        .user-details {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1.5rem;
        }

        .user-details h3 {
            color: var(--gray-800);
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
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
        }

        .detail-row .value {
            color: var(--gray-800);
            font-weight: 600;
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

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .step-hidden {
            display: none;
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
                <h1>Tutup Rekening</h1>
                <p class="page-subtitle">Penutupan rekening nasabah secara permanen</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div class="card-header-text">
                    <h3>Form Penutupan</h3>
                    <p>Masukkan nomor rekening untuk memproses penutupan</p>
                </div>
            </div>
            <div class="card-body">
                <div id="step1" class="step-container">
                    <form id="rekeningForm">
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-hashtag"></i> Nomor Rekening (8 Digit)</label>
                            <div class="input-wrapper">
                                <input type="text" id="no_rekening" name="no_rekening" maxlength="8" pattern="\d{8}"
                                    placeholder="Masukkan 8 digit angka" autocomplete="off" required />
                                <button type="button" id="clearInput" class="clear-input" title="Hapus">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                        </div>
                        <div style="margin-top:1.5rem;">
                            <button type="submit" id="checkButton" class="btn">
                                <span><i class="fas fa-search"></i> Cek Rekening</span>
                            </button>
                        </div>
                    </form>
                </div>

                <div id="step2" class="step-container step-hidden">
                    <div id="details-wrapper" class="details-wrapper">
                        <div class="user-details">
                            <h3><i class="fas fa-user-circle"></i> Detail Nasabah</h3>
                            <div id="detail-content"></div>
                        </div>
                    </div>

                    <div id="pin-section" class="step-hidden"
                        style="margin-top: 20px; border-top: 1px solid var(--gray-200); padding-top: 20px;">
                        <div class="form-group">
                            <label class="form-label"><i class="fas fa-key"></i> PIN Siswa untuk Verifikasi</label>
                            <input type="password" id="pinInput" maxlength="6" pattern="\d{6}" inputmode="numeric"
                                placeholder="Masukkan 6 digit PIN siswa" autocomplete="off" />
                        </div>
                        <div style="margin-top:1.5rem;">
                            <button type="button" id="closeButton" class="btn btn-danger">
                                <span><i class="fas fa-user-times"></i> Tutup Rekening Permanen</span>
                            </button>
                        </div>
                    </div>
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
            $('#no_rekening, #pinInput').val('');
            $('#clearInput').removeClass('show');
            $('#step1').removeClass('step-hidden');
            $('#step2, #details-wrapper, #pin-section').removeClass('show').addClass('step-hidden');
            $('#details-wrapper').removeClass('show');
            currentUserId = 0;
            fullNoRekening = '';
            setTimeout(() => $('#no_rekening').focus(), 300);
        }

        // Only allow numbers for no_rekening and toggle clear button
        $('#no_rekening').on('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);
            // Show/hide clear button based on input value
            if (this.value.length > 0) {
                $('#clearInput').addClass('show');
            } else {
                $('#clearInput').removeClass('show');
            }
        });

        // Clear input button handler
        $('#clearInput').on('click', function () {
            $('#no_rekening').val('').focus();
            $(this).removeClass('show');
            // Hide search results
            $('#step2').addClass('step-hidden');
            $('#details-wrapper').removeClass('show');
            $('#pin-section').addClass('step-hidden');
            $('#pinInput').val('');
            currentUserId = 0;
            fullNoRekening = '';
        });

        // Search only when form is submitted (button clicked)
        $('#rekeningForm').on('submit', function (e) {
            e.preventDefault();
            const noRek = $('#no_rekening').val().trim();

            if (!/^\d{8}$/.test(noRek)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid',
                    text: 'Nomor rekening harus 8 digit angka.'
                }).then(() => {
                    $('#no_rekening').val('').focus();
                });
                return;
            }

            // Disable button and show spinner
            const $btn = $('#checkButton');
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Memeriksa...');

            // Add 2-3 second delay with spinner
            setTimeout(function () {
                $.ajax({
                    url: 'proses_tutup_rekening.php',
                    type: 'POST',
                    data: {
                        action: 'check_rekening',
                        no_rekening: noRek,
                        token: '<?php echo $token; ?>'
                    },
                    dataType: 'json',
                    success: function (res) {
                        // Restore button
                        $btn.prop('disabled', false).html(originalHtml);

                        if (res.success) {
                            const d = res.data;
                            fullNoRekening = d.no_rekening;
                            currentUserId = d.user_id;

                            // LOGIKA SALDO MASIH ADA
                            if (res.has_saldo) {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Saldo Masih Tersedia!',
                                    html: `<p>Rekening ini masih memiliki saldo: <strong style="color:#ef4444;font-size:1.2rem;">${formatRupiah(d.saldo)}</strong></p><p>Harap kosongkan saldo sebelum menutup rekening.</p>`,
                                    confirmButtonText: 'Ya, Tarik Saldo',
                                    showCancelButton: true,
                                    cancelButtonText: 'Batal'
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        window.location.href = 'tarik.php?no_rekening=' + fullNoRekening;
                                    } else {
                                        resetForm();
                                    }
                                });
                                return;
                            }

                            // Show results - keep form visible
                            let html = '';
                            const items = [
                                ['No. Rekening', d.no_rekening_display],
                                ['Nama', d.nama],
                                ['Kelas', d.nama_kelas],
                                ['Jurusan', d.nama_jurusan],
                                ['Email', d.email],
                                ['Saldo', formatRupiah(d.saldo)]
                            ];
                            items.forEach(i => html += `<div class="detail-row"><span class="label">${i[0]}</span><span class="value">${i[1]}</span></div>`);
                            $('#detail-content').html(html);
                            $('#details-wrapper').addClass('show');
                            // Keep step1 visible, don't hide it
                            $('#step2').removeClass('step-hidden');

                            if (res.can_close) {
                                $('#pin-section').removeClass('step-hidden');
                                setTimeout(() => $('#pinInput').focus(), 100);
                                // No popup alert - just show the form
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Tidak Dapat Ditutup',
                                    html: res.errors.map(e => `<p><i class="fas fa-times-circle" style="color:#ef4444"></i> ${e}</p>`).join('')
                                }).then(() => resetForm());
                            }
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: res.message
                            }).then(() => {
                                $('#no_rekening').val('').focus();
                            });
                        }
                    },
                    error: () => {
                        // Restore button
                        $btn.prop('disabled', false).html(originalHtml);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Gagal memeriksa rekening.'
                        }).then(() => {
                            $('#no_rekening').val('').focus();
                        });
                    }
                });
            }, 2500); // 2.5 second delay
        });

        $('#closeButton').on('click', function () {
            const pin = $('#pinInput').val().trim();
            if (!pin || !/^\d{6}$/.test(pin)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'PIN Tidak Valid',
                    text: 'Masukkan 6 digit PIN siswa.'
                });
                return $('#pinInput').focus();
            }

            Swal.fire({
                title: 'Konfirmasi Penutupan',
                html: '<p>Apakah Anda yakin ingin <strong>menutup rekening ini secara permanen</strong>?</p><p style="color:#ef4444;">Tindakan ini tidak dapat dibatalkan!</p>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Tutup Rekening',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then(r => {
                if (!r.isConfirmed) return;

                // Disable button and show spinner
                const $closeBtn = $('#closeButton');
                const originalCloseBtnHtml = $closeBtn.html();
                $closeBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Menutup Rekening...');

                // Add 2.5 second delay with spinner
                setTimeout(function () {
                    $.ajax({
                        url: 'proses_tutup_rekening.php',
                        type: 'POST',
                        data: {
                            action: 'close_rekening',
                            user_id: currentUserId,
                            pin: pin,
                            no_rekening: fullNoRekening,
                            token: '<?php echo $token; ?>'
                        },
                        dataType: 'json',
                        success: res => {
                            // Restore button
                            $closeBtn.prop('disabled', false).html(originalCloseBtnHtml);

                            if (res.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    html: res.message + '<br>Rekening telah ditutup permanen.',
                                    timer: 4000
                                }).then(() => resetForm());
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal',
                                    text: res.message
                                });
                                $('#pinInput').val('').focus();
                            }
                        },
                        error: () => {
                            // Restore button
                            $closeBtn.prop('disabled', false).html(originalCloseBtnHtml);
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Gagal menutup rekening.'
                            });
                        }
                    });
                }, 2500); // 2.5 second delay
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