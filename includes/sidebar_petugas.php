<?php
/**
 * Sidebar Petugas - Adaptive Path Version (Normalized DB)
 * File: includes/sidebar_petugas.php
 * Re-created and Harmonized for Compatibility
 */

// ============================================
// ADAPTIVE PATH DETECTION (if not already defined)
// ============================================
if (!defined('PROJECT_ROOT')) {
    $current_file = __FILE__;
    $current_dir = dirname($current_file);
    $project_root = null;

    // Strategy 1: Check if we're in 'includes' folder
    if (basename($current_dir) === 'includes') {
        $project_root = dirname($current_dir);
    }
    // Strategy 2: Check if includes/ exists in current dir
    elseif (is_dir($current_dir . '/includes')) {
        $project_root = $current_dir;
    }
    // Strategy 3: Search upward for includes/ folder (max 5 levels)
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

    if (!$project_root) {
        $project_root = dirname($current_dir);
    }

    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('BASE_URL')) {
    function getBaseUrlSidebar()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'];
        $base_path = dirname($script);

        // Remove '/pages/petugas', '/pages/admin', etc.
        $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);

        if ($base_path !== '/' && !empty($base_path)) {
            $base_path = '/' . ltrim($base_path, '/');
        }

        return $protocol . $host . $base_path;
    }
    define('BASE_URL', rtrim(getBaseUrlSidebar(), '/'));
}

// ============================================
// LOAD AUTH & DB
// ============================================
if (!isset($conn)) {
    require_once PROJECT_ROOT . '/includes/auth.php';
    require_once PROJECT_ROOT . '/includes/db_connection.php';
}

$username = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;
$current_page = basename($_SERVER['PHP_SELF']);

// ============================================
// STATUS BLOKIR (Default: tidak ada blocking karena tabel tidak ada)
// ============================================
$is_blocked = false;

// ============================================
// JADWAL PETUGAS HARI INI (petugas_shift)
// AMBIL NAMA DARI petugas_profiles
// ============================================
$schedule = null;
$petugas1_nama = '';
$petugas2_nama = '';

try {
    $query_schedule = "SELECT ps.*, 
                       COALESCE(pp1.petugas1_nama, pp1.petugas2_nama) AS petugas1_nama,
                       COALESCE(pp2.petugas2_nama, pp2.petugas1_nama) AS petugas2_nama
                       FROM petugas_shift ps
                       LEFT JOIN petugas_profiles pp1 ON ps.petugas1_id = pp1.user_id
                       LEFT JOIN petugas_profiles pp2 ON ps.petugas2_id = pp2.user_id
                       WHERE ps.tanggal = CURDATE()";
    $stmt_schedule = $conn->prepare($query_schedule);
    $stmt_schedule->execute();
    $result_schedule = $stmt_schedule->get_result();
    $schedule = $result_schedule->fetch_assoc();
    $stmt_schedule->close();

    $petugas1_nama = $schedule ? ($schedule['petugas1_nama'] ?? '') : '';
    $petugas2_nama = $schedule ? ($schedule['petugas2_nama'] ?? '') : '';
} catch (mysqli_sql_exception $e) {
    error_log("Sidebar Error: " . $e->getMessage());
}

// ============================================
// DATA ABSENSI HARI INI (petugas_status)
// ============================================
$attendance_petugas1 = null;
$attendance_petugas2 = null;

if ($schedule) {
    try {
        // Ambil status petugas1
        $query_attendance1 = "SELECT * FROM petugas_status 
                             WHERE petugas_shift_id = ? 
                             AND petugas_type = 'petugas1'";
        $stmt_attendance1 = $conn->prepare($query_attendance1);
        $stmt_attendance1->bind_param("i", $schedule['id']);
        $stmt_attendance1->execute();
        $attendance_petugas1 = $stmt_attendance1->get_result()->fetch_assoc();
        $stmt_attendance1->close();

        // Ambil status petugas2
        $query_attendance2 = "SELECT * FROM petugas_status 
                             WHERE petugas_shift_id = ? 
                             AND petugas_type = 'petugas2'";
        $stmt_attendance2 = $conn->prepare($query_attendance2);
        $stmt_attendance2->bind_param("i", $schedule['id']);
        $stmt_attendance2->execute();
        $attendance_petugas2 = $stmt_attendance2->get_result()->fetch_assoc();
        $stmt_attendance2->close();
    } catch (mysqli_sql_exception $e) {
        error_log("Sidebar Attendance Error: " . $e->getMessage());
    }
}

// ============================================
// LOGIKA STATUS KEHADIRAN & PENUTUPAN LAYANAN
// ============================================
$at_least_one_present = false;
$all_checked_out = false;
$show_thank_you = false;

if ($schedule) {
    // Petugas1 hadir (status = 'hadir')
    // Karena tidak ada kolom waktu_keluar di petugas_status, 
    // kita anggap petugas aktif jika status = 'hadir'
    $petugas1_present = $petugas1_nama && $attendance_petugas1 &&
        $attendance_petugas1['status'] == 'hadir';

    $petugas2_present = $petugas2_nama && $attendance_petugas2 &&
        $attendance_petugas2['status'] == 'hadir';

    // Sudah melakukan absensi (status apa pun)
    $petugas1_checked_in = $petugas1_nama && $attendance_petugas1 && $attendance_petugas1['status'];
    $petugas2_checked_in = $petugas2_nama && $attendance_petugas2 && $attendance_petugas2['status'];

    $at_least_one_present = $petugas1_present || $petugas2_present;

    // Logika checkout: Jika tidak ada petugas yang hadir (semua non-hadir atau tidak ada status)
    $petugas1_done = !$petugas1_nama || ($petugas1_checked_in && $attendance_petugas1['status'] != 'hadir');
    $petugas2_done = !$petugas2_nama || ($petugas2_checked_in && $attendance_petugas2['status'] != 'hadir');

    $all_checked_out = $petugas1_done && $petugas2_done;

    // Tampilkan pesan terima kasih jika seluruh siklus layanan sudah selesai
    $show_thank_you = $all_checked_out && ($petugas1_checked_in || $petugas2_checked_in);
}

// ============================================
// CHECK CURRRENT USER CHECKOUT STATUS
// ============================================
$current_user_done = false;
if ($schedule && isset($_SESSION['user_id'])) {
    // Check Petugas 1
    if (
        $schedule['petugas1_id'] == $_SESSION['user_id'] &&
        $attendance_petugas1 &&
        $attendance_petugas1['status'] == 'hadir' &&
        $attendance_petugas1['waktu_keluar']
    ) {
        $current_user_done = true;
    }
    // Check Petugas 2
    elseif (
        $schedule['petugas2_id'] == $_SESSION['user_id'] &&
        $attendance_petugas2 &&
        $attendance_petugas2['status'] == 'hadir' &&
        $attendance_petugas2['waktu_keluar']
    ) {
        $current_user_done = true;
    }
}

// ============================================
// PENGATURAN ENABLE/DISABLE MENU
// ============================================
$dashboard_enabled = true;
$settings_enabled = true;

if ($show_thank_you || $current_user_done) {
    // Setelah penutupan operasional harian ATAU petugas saat ini sudah pulang:
    // HANYA Absensi, Laporan, dan Keluar yang aktif
    $transaksi_enabled = false;
    $rekening_enabled = false;
    $search_enabled = !$is_blocked;
    $laporan_enabled = !$is_blocked;
    $absensi_enabled = true;

    // Dashboard & Settings remain enabled
    $dashboard_enabled = true;
    $settings_enabled = true;
} else {
    // Mode operasional normal
    $transaksi_enabled = !$is_blocked && $at_least_one_present;
    $rekening_enabled = !$is_blocked && $at_least_one_present;
    $search_enabled = !$is_blocked && $at_least_one_present;
    $laporan_enabled = !$is_blocked && ($attendance_petugas1 || $attendance_petugas2);
    $absensi_enabled = true;
    $dashboard_enabled = true;
    $settings_enabled = true;
}

$transaksi_pages = ['setor.php', 'tarik.php', 'transfer.php'];
$rekening_pages = ['buka_rekening.php', 'tutup_rekening.php'];
$search_pages = ['cek_mutasi.php', 'validasi_transaksi.php', 'cek_saldo.php'];

$is_transaksi_active = in_array($current_page, $transaksi_pages);
$is_rekening_active = in_array($current_page, $rekening_pages);
$is_search_active = in_array($current_page, $search_pages);
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
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
            --secondary-dark: var(--gray-700);
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8fafc;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --scrollbar-track: var(--gray-900);
            --scrollbar-thumb: var(--gray-600);
            --scrollbar-thumb-hover: var(--gray-500);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            z-index: 1000;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
            top: 0;
            left: 0;
        }

        .sidebar-header {
            padding: 15px 20px 10px;
            background: var(--primary-dark);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 90px;
        }

        .logo-container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            width: 100%;
            text-align: center;
        }

        .logo-img {
            max-width: 160px;
            height: auto;
            object-fit: contain;
            filter: brightness(0) invert(1);
        }

        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        .sidebar-footer {
            background: var(--primary-dark);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            bottom: 0;
            z-index: 101;
            padding: 5px 0;
        }

        .sidebar-content::-webkit-scrollbar {
            width: 8px;
        }

        .sidebar-content::-webkit-scrollbar-track {
            background: var(--scrollbar-track);
            border-radius: 4px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: var(--scrollbar-thumb);
            border-radius: 4px;
            border: 2px solid var(--scrollbar-track);
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
        }

        .sidebar-menu {
            padding: 10px 0;
        }

        .menu-label {
            padding: 15px 25px 10px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 500;
            margin-top: 10px;
        }

        .menu-item {
            position: relative;
            margin: 5px 0;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
            font-size: 0.9rem;
        }

        .menu-item a:hover,
        .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .menu-item a.disabled {
            color: rgba(255, 255, 255, 0.4);
            cursor: not-allowed;
            pointer-events: none;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .dropdown-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 25px;
            width: 100%;
            text-align: left;
            background: none;
            color: rgba(255, 255, 255, 0.85);
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
        }

        .dropdown-btn:hover,
        .dropdown-btn.active-dropdown {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .dropdown-btn.disabled {
            color: rgba(255, 255, 255, 0.4);
            cursor: not-allowed;
            pointer-events: none;
        }

        .dropdown-btn .menu-icon {
            display: flex;
            align-items: center;
        }

        .dropdown-btn .menu-icon i:first-child {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .dropdown-btn .arrow {
            transition: transform 0.3s ease;
            font-size: 0.8rem;
        }

        .dropdown-btn.active-dropdown .arrow {
            transform: rotate(180deg);
        }

        .dropdown-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.15);
        }

        .dropdown-container.show {
            max-height: 300px;
        }

        .dropdown-container a {
            padding: 12px 20px 12px 60px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.85rem;
            border-left: 4px solid transparent;
        }

        .dropdown-container a:hover,
        .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .dropdown-container span {
            display: block;
            padding: 10px 20px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.8rem;
            font-style: italic;
        }

        .logout-btn {
            color: var(--danger-color);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
                z-index: 1000;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            /* Compatibility for harmonized pages (Admin style toggle) */
            body.sidebar-open .sidebar {
                transform: translateX(0);
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

            body.sidebar-open::before {
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
        }

        @media (max-width: 480px) {
            .sidebar {
                width: 240px;
            }

            .sidebar-header {
                padding: 10px 15px 5px;
                min-height: 75px;
            }

            .logo-img {
                max-width: 130px;
            }

            .menu-item a,
            .dropdown-btn {
                padding: 12px 20px;
                font-size: 0.85rem;
            }

            .dropdown-container a {
                padding: 10px 15px 10px 50px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <img src="<?= BASE_URL ?>/assets/images/side.png" alt="KASDIG" class="logo-img">
            </div>
        </div>

        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Menu Operasional</div>

                <!-- Dashboard -->
                <div class="menu-item">
                    <a href="dashboard.php"
                        class="<?php echo $current_page == 'dashboard.php' ? 'active' : ($dashboard_enabled ? '' : 'disabled'); ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>

                <!-- Menu Transaksi -->
                <div class="menu-item">
                    <button
                        class="dropdown-btn <?php echo $is_transaksi_active ? 'active-dropdown' : ($transaksi_enabled ? '' : 'disabled'); ?>"
                        id="transaksiDropdown"
                        title="<?php echo $transaksi_enabled ? 'Layanan transaksi aktif' : 'Layanan transaksi ditutup'; ?>">
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_transaksi_active ? ' show' : ''; ?>"
                        id="transaksiDropdownContainer">
                        <?php if (!$transaksi_enabled): ?>
                            <span>Layanan transaksi ditutup.</span>
                        <?php else: ?>
                            <a href="setor.php" class="<?php echo $current_page == 'setor.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-arrow-circle-down"></i> Setor</a>
                            <a href="tarik.php" class="<?php echo $current_page == 'tarik.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-arrow-circle-up"></i> Tarik</a>
                            <a href="transfer.php" class="<?php echo $current_page == 'transfer.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-paper-plane"></i> Transfer</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu Rekening -->
                <div class="menu-item">
                    <button
                        class="dropdown-btn <?php echo $is_rekening_active ? 'active-dropdown' : ($rekening_enabled ? '' : 'disabled'); ?>"
                        id="rekeningDropdown"
                        title="<?php echo $rekening_enabled ? 'Layanan administrasi rekening aktif' : 'Layanan administrasi rekening ditutup'; ?>">
                        <div class="menu-icon">
                            <i class="fas fa-users-cog"></i> Rekening
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_rekening_active ? ' show' : ''; ?>"
                        id="rekeningDropdownContainer">
                        <?php if (!$rekening_enabled): ?>
                            <span>Administrasi rekening ditutup.</span>
                        <?php else: ?>
                            <a href="buka_rekening.php"
                                class="<?php echo $current_page == 'buka_rekening.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-user-plus"></i> Buka Rekening</a>
                            <a href="tutup_rekening.php"
                                class="<?php echo $current_page == 'tutup_rekening.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-user-times"></i> Tutup Rekening</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu Informasi Rekening -->
                <div class="menu-item">
                    <button
                        class="dropdown-btn <?php echo $is_search_active ? 'active-dropdown' : ($search_enabled ? '' : 'disabled'); ?>"
                        id="searchDropdown"
                        title="<?php echo $search_enabled ? 'Layanan informasi rekening aktif' : 'Layanan informasi rekening ditutup'; ?>">
                        <div class="menu-icon">
                            <i class="fas fa-search"></i> Informasi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_search_active ? ' show' : ''; ?>"
                        id="searchDropdownContainer">
                        <?php if (!$search_enabled): ?>
                            <span>Akses informasi rekening ditutup.</span>
                        <?php else: ?>
                            <a href="cek_mutasi.php"
                                class="<?php echo $current_page == 'cek_mutasi.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-list-alt"></i> Mutasi Rekening</a>
                            <a href="validasi_transaksi.php"
                                class="<?php echo $current_page == 'validasi_transaksi.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-receipt"></i> Validasi Transaksi</a>
                            <a href="cek_saldo.php"
                                class="<?php echo $current_page == 'cek_saldo.php' ? 'active' : ''; ?>"><i
                                    class="fas fa-wallet"></i> Saldo Rekening</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="menu-label">Menu Pendukung</div>

                <div class="menu-item">
                    <a href="absensi.php" class="<?php echo $current_page == 'absensi.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i> Absensi
                    </a>
                </div>

                <div class="menu-item">
                    <a href="laporan_transaksi_harian.php"
                        class="<?php echo $laporan_enabled && $current_page == 'laporan_transaksi_harian.php' ? 'active' : ($laporan_enabled ? '' : 'disabled'); ?>">
                        <i class="fas fa-calendar-day"></i> Laporan Transaksi Harian
                    </a>
                </div>

                <!-- Menu Tunggal: Pengaturan Akun -->
                <div class="menu-item">
                    <a href="pengaturan_akun.php"
                        class="<?php echo $current_page == 'pengaturan_akun.php' ? 'active' : ($settings_enabled ? '' : 'disabled'); ?>">
                        <i class="fas fa-cog"></i> Pengaturan Akun
                    </a>
                </div>

            </div>
        </div>

        <div class="sidebar-footer">
            <div class="menu-item">
                <a href="javascript:void(0)" onclick="confirmLogout()" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Keluar
                </a>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                const dropdownBtns = document.querySelectorAll('.dropdown-btn');

                dropdownBtns.forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        if (this.classList.contains('disabled')) return;

                        e.preventDefault();
                        const container = this.nextElementSibling;
                        const isOpen = container.classList.contains('show');

                        document.querySelectorAll('.dropdown-container').forEach(c => {
                            if (c !== container) c.classList.remove('show');
                        });

                        document.querySelectorAll('.dropdown-btn').forEach(b => {
                            if (b !== this) b.classList.remove('active-dropdown');
                        });

                        if (!isOpen) {
                            container.classList.add('show');
                            this.classList.add('active-dropdown');
                        } else {
                            container.classList.remove('show');
                            this.classList.remove('active-dropdown');
                        }
                    });
                });
            });

            function confirmLogout() {
                Swal.fire({
                    title: 'Konfirmasi Keluar',
                    text: 'Apakah Anda yakin ingin keluar dari sistem?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-sign-out-alt"></i> Ya, Keluar',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    reverseButtons: false,
                    customClass: {
                        popup: 'swal-popup-custom',
                        title: 'swal-title-custom',
                        confirmButton: 'swal-confirm-custom',
                        cancelButton: 'swal-cancel-custom'
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = '<?= BASE_URL ?>/logout.php';
                    }
                });
            }
        </script>
    </div>
</body>

</html>