<?php
/**
 * Sidebar Admin - Harmonized Version
 * File: includes/sidebar_admin.php
 */

// ============================================
// BASE URL DETECTION
// ============================================
if (!defined('BASE_URL')) {
    function getBaseUrlAdmin()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script = $_SERVER['SCRIPT_NAME'];
        $base_path = dirname($script);

        // Remove '/pages/admin', '/pages/petugas', etc.
        $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);

        if ($base_path !== '/' && !empty($base_path)) {
            $base_path = '/' . ltrim($base_path, '/');
        }

        return $protocol . $host . $base_path;
    }
    define('BASE_URL', rtrim(getBaseUrlAdmin(), '/'));
}

// ============================================
// LOAD AUTH
// ============================================
// Adjust path to auth.php based on location (assuming this file is in includes/)
$auth_path = __DIR__ . '/auth.php';
if (file_exists($auth_path)) {
    require_once $auth_path;
} else {
    // Fallback if not found directly
    $root_path = dirname(__DIR__);
    if (file_exists($root_path . '/includes/auth.php')) {
        require_once $root_path . '/includes/auth.php';
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
$admin_id = $_SESSION['user_id'] ?? 0;

// Ensure strictly admin
if ($admin_id <= 0 || (isset($_SESSION['role']) && $_SESSION['role'] !== 'admin')) {
    // Optional: Redirect if needed, or handle in the page itself
}
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
            /* Font sizes synchronized with sidebar_petugas.php */
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
                <div class="menu-label">Menu Utama</div>

                <!-- Dashboard -->
                <div class="menu-item">
                    <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>

                <!-- Transaksi -->
                <div class="menu-item">
                    <?php
                    $transaksi_pages = ['setor.php', 'tarik.php'];
                    $is_transaksi_active = in_array($current_page, $transaksi_pages);
                    ?>
                    <button class="dropdown-btn <?php echo $is_transaksi_active ? 'active-dropdown' : ''; ?>"
                        id="transaksiDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container <?php echo $is_transaksi_active ? 'show' : ''; ?>"
                        id="transaksiDropdownContainer">
                        <a href="setor.php" class="<?php echo $current_page == 'setor.php' ? 'active' : ''; ?>">
                            <i class="fas fa-arrow-circle-down"></i> Setor
                        </a>
                        <a href="tarik.php" class="<?php echo $current_page == 'tarik.php' ? 'active' : ''; ?>">
                            <i class="fas fa-arrow-circle-up"></i> Tarik
                        </a>
                    </div>
                </div>

                <div class="menu-label">Kelola Data</div>

                <!-- Data Rekening Siswa -->
                <div class="menu-item">
                    <a href="data_rekening_siswa.php"
                        class="<?php echo $current_page == 'data_rekening_siswa.php' ? 'active' : ''; ?>">
                        <i class="fas fa-address-card"></i> Data Rekening Siswa
                    </a>
                </div>

                <!-- Manajemen Akun Siswa -->
                <div class="menu-item">
                    <a href="manajemen_akun.php"
                        class="<?php echo $current_page == 'manajemen_akun.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users-cog"></i> Manajemen Akun Siswa
                    </a>
                </div>

                <!-- Manajemen Petugas -->
                <div class="menu-item">
                    <?php
                    $petugas_pages = ['kelola_akun_petugas.php', 'kelola_jadwal.php'];
                    $is_petugas_active = in_array($current_page, $petugas_pages);
                    ?>
                    <button class="dropdown-btn <?php echo $is_petugas_active ? 'active-dropdown' : ''; ?>"
                        id="petugasDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-user-tie"></i> Manajemen Petugas
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container <?php echo $is_petugas_active ? 'show' : ''; ?>"
                        id="petugasDropdownContainer">
                        <a href="kelola_akun_petugas.php"
                            class="<?php echo $current_page == 'kelola_akun_petugas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-id-card"></i> Kelola Akun Petugas
                        </a>
                        <a href="kelola_jadwal.php"
                            class="<?php echo $current_page == 'kelola_jadwal.php' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-check"></i> Kelola Jadwal Petugas
                        </a>
                    </div>
                </div>

                <!-- Data Master Sekolah -->
                <div class="menu-item">
                    <?php
                    $master_pages = ['kelola_jurusan.php', 'kelola_tingkatan.php', 'kelola_kelas.php'];
                    $is_master_active = in_array($current_page, $master_pages);
                    ?>
                    <button class="dropdown-btn <?php echo $is_master_active ? 'active-dropdown' : ''; ?>"
                        id="dataMasterDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-database"></i> Data Master Sekolah
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container <?php echo $is_master_active ? 'show' : ''; ?>"
                        id="dataMasterDropdownContainer">
                        <a href="kelola_jurusan.php"
                            class="<?php echo $current_page == 'kelola_jurusan.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book-open"></i> Kelola Jurusan
                        </a>
                        <a href="kelola_tingkatan.php"
                            class="<?php echo $current_page == 'kelola_tingkatan.php' ? 'active' : ''; ?>">
                            <i class="fas fa-layer-group"></i> Kelola Tingkatan Kelas
                        </a>
                        <a href="kelola_kelas.php"
                            class="<?php echo $current_page == 'kelola_kelas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-chalkboard"></i> Kelola Kelas
                        </a>
                    </div>
                </div>

                <!-- Laporan Keuangan -->
                <div class="menu-item">
                    <?php
                    $laporan_pages = ['data_riwayat_transaksi_admin.php', 'data_riwayat_transaksi_petugas.php', 'rekapitulasi_transaksi.php', 'download_rekap_transaksi.php'];
                    $is_laporan_active = in_array($current_page, $laporan_pages);
                    ?>
                    <button class="dropdown-btn <?php echo $is_laporan_active ? 'active-dropdown' : ''; ?>"
                        id="laporanDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-file-invoice-dollar"></i> Laporan Keuangan
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container <?php echo $is_laporan_active ? 'show' : ''; ?>"
                        id="laporanDropdownContainer">
                        <a href="data_riwayat_transaksi_admin.php"
                            class="<?php echo $current_page == 'data_riwayat_transaksi_admin.php' ? 'active' : ''; ?>">
                            <i class="fas fa-receipt"></i> Riwayat Transaksi Admin
                        </a>
                        <a href="data_riwayat_transaksi_petugas.php"
                            class="<?php echo $current_page == 'data_riwayat_transaksi_petugas.php' ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice"></i> Riwayat Transaksi Petugas
                        </a>
                        <a href="rekapitulasi_transaksi.php"
                            class="<?php echo $current_page == 'rekapitulasi_transaksi.php' ? 'active' : ''; ?>">
                            <i class="fas fa-calculator"></i> Rekapitulasi Transaksi
                        </a>
                    </div>
                </div>

                <!-- Data Kehadiran Petugas -->
                <div class="menu-item">
                    <a href="data_kehadiran_petugas.php"
                        class="<?php echo $current_page == 'data_kehadiran_petugas.php' ? 'active' : ''; ?>">
                        <i class="fas fa-clipboard-check"></i> Data Kehadiran Petugas
                    </a>
                </div>

                <div class="menu-label">Lainnya</div>

                <!-- Pengaturan Akun -->
                <div class="menu-item">
                    <a href="pengaturan.php" class="<?php echo $current_page == 'pengaturan.php' ? 'active' : ''; ?>">
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
                        e.preventDefault();
                        const container = this.nextElementSibling;
                        const isOpen = container.classList.contains('show');

                        // Close other dropdowns
                        document.querySelectorAll('.dropdown-container').forEach(c => {
                            if (c !== container) c.classList.remove('show');
                        });

                        document.querySelectorAll('.dropdown-btn').forEach(b => {
                            if (b !== this) b.classList.remove('active-dropdown');
                        });

                        // Toggle current
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
                        // Assuming logout.php is in the root
                        window.location.href = '<?= BASE_URL ?>/logout.php';
                    }
                });
            }
        </script>
    </div>
</body>

</html>