<?php
// sidebar.php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username   = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;
$current_page = basename($_SERVER['PHP_SELF']);

// Cek status blokir operasional bank mini
$query_block   = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block  = $conn->query($query_block);
$is_blocked    = $result_block->fetch_assoc()['is_blocked'] ?? 0;

// Jadwal petugas teller hari ini + join dengan informasi petugas
$query_schedule = "SELECT pt.*, pi.petugas1_nama, pi.petugas2_nama 
                   FROM petugas_tugas pt
                   LEFT JOIN petugas_info pi ON pt.petugas1_id = pi.user_id
                   WHERE pt.tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();
$stmt_schedule->close();

// Nama petugas teller terjadwal hari ini
$petugas1_nama = $schedule ? $schedule['petugas1_nama'] : '';
$petugas2_nama = $schedule ? $schedule['petugas2_nama'] : '';

// Data absensi hari ini
$attendance_petugas1 = null;
$attendance_petugas2 = null;

$query_attendance1 = "SELECT * FROM absensi WHERE petugas_type = 'petugas1' AND tanggal = CURDATE()";
$stmt_attendance1 = $conn->prepare($query_attendance1);
if ($stmt_attendance1) {
    $stmt_attendance1->execute();
    $attendance_petugas1 = $stmt_attendance1->get_result()->fetch_assoc();
    $stmt_attendance1->close();
}

$query_attendance2 = "SELECT * FROM absensi WHERE petugas_type = 'petugas2' AND tanggal = CURDATE()";
$stmt_attendance2 = $conn->prepare($query_attendance2);
if ($stmt_attendance2) {
    $stmt_attendance2->execute();
    $attendance_petugas2 = $stmt_attendance2->get_result()->fetch_assoc();
    $stmt_attendance2->close();
}

// Logika status kehadiran & penutupan layanan
$at_least_one_present = false;
$all_checked_out      = false;
$show_thank_you       = false;

if ($schedule) {
    // Minimal satu petugas teller hadir dan belum cek-out
    $petugas1_present = $petugas1_nama && $attendance_petugas1 && 
                        $attendance_petugas1['petugas1_status'] == 'hadir' && 
                        !$attendance_petugas1['waktu_keluar'];

    $petugas2_present = $petugas2_nama && $attendance_petugas2 && 
                        $attendance_petugas2['petugas2_status'] == 'hadir' && 
                        !$attendance_petugas2['waktu_keluar'];

    // Sudah melakukan absensi (status apa pun)
    $petugas1_checked_in = $petugas1_nama && $attendance_petugas1 && $attendance_petugas1['petugas1_status'];
    $petugas2_checked_in = $petugas2_nama && $attendance_petugas2 && $attendance_petugas2['petugas2_status'];

    // Petugas hadir + sudah cek-out
    $petugas1_checked_out = $petugas1_nama && $attendance_petugas1 && 
                            $attendance_petugas1['petugas1_status'] == 'hadir' && 
                            $attendance_petugas1['waktu_keluar'];

    $petugas2_checked_out = $petugas2_nama && $attendance_petugas2 && 
                            $attendance_petugas2['petugas2_status'] == 'hadir' && 
                            $attendance_petugas2['waktu_keluar'];

    $at_least_one_present = $petugas1_present || $petugas2_present;

    // Semua petugas yang terjadwal sudah selesai (cek-out atau status non-hadir)
    $petugas1_done = !$petugas1_nama || $petugas1_checked_in && 
                     ($attendance_petugas1['petugas1_status'] != 'hadir' || $petugas1_checked_out);

    $petugas2_done = !$petugas2_nama || $petugas2_checked_in && 
                     ($attendance_petugas2['petugas2_status'] != 'hadir' || $petugas2_checked_out);

    $all_checked_out = $petugas1_done && $petugas2_done;

    // Tampilkan pesan terima kasih jika seluruh siklus layanan sudah selesai
    $show_thank_you = $all_checked_out && ($petugas1_checked_in || $petugas2_checked_in);
}

// Pengaturan enable/disable menu berdasarkan status operasional
if ($show_thank_you) {
    // Setelah penutupan operasional harian:
    // hanya Dashboard, Absensi, dan Laporan yang aktif
    $transaksi_enabled = false;
    $rekening_enabled  = false;
    $search_enabled    = false;
    $laporan_enabled   = !$is_blocked;
    $absensi_enabled   = true;
} else {
    // Mode operasional normal
    $transaksi_enabled = !$is_blocked && $at_least_one_present;
    $rekening_enabled  = !$is_blocked && $at_least_one_present;
    $search_enabled    = !$is_blocked && $at_least_one_present;
    $laporan_enabled   = !$is_blocked && ($petugas1_checked_in || $petugas2_checked_in);
    $absensi_enabled   = true;
}

$transaksi_pages = ['setor.php', 'tarik.php', 'transfer.php'];
$rekening_pages  = ['buka_rekening.php', 'tutup_rekening.php', 'ubah_jenis_rekening.php'];
$search_pages    = ['cek_mutasi.php', 'validasi_transaksi.php', 'cek_saldo.php'];

$is_transaksi_active = in_array($current_page, $transaksi_pages);
$is_rekening_active  = in_array($current_page, $rekening_pages);
$is_search_active    = in_array($current_page, $search_pages);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
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
            height: 70px;
            text-align: center;
        }

        .logo-text {
            font-size: 1.8rem;
            font-weight: 700;
            color: white;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            margin-bottom: 4px;
        }

        .logo-subtitle {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.8);
            letter-spacing: 2px;
            text-transform: uppercase;
            font-weight: 400;
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

        .sidebar-content::-webkit-scrollbar { width: 8px; }
        .sidebar-content::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 4px; }
        .sidebar-content::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; border: 2px solid var(--scrollbar-track); }
        .sidebar-content::-webkit-scrollbar-thumb:hover { background: var(--scrollbar-thumb-hover); }

        .sidebar-menu { padding: 10px 0; }

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

        .dropdown-btn .menu-icon { display: flex; align-items: center; }
        .dropdown-btn .menu-icon i:first-child { margin-right: 12px; width: 20px; text-align: center; font-size: 1.1rem; }
        .dropdown-btn .arrow { transition: transform 0.3s ease; font-size: 0.8rem; }
        .dropdown-btn.active-dropdown .arrow { transform: rotate(180deg); }

        .dropdown-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.15);
        }

        .dropdown-container.show { max-height: 300px; }

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
            display: block; padding: 10px 20px;
            color: rgba(255, 255, 255, 0.5); font-size: 0.8rem; font-style: italic;
        }

        .logout-btn { color: var(--danger-color); font-weight: 500; }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: 260px; z-index: 1000; }
            .sidebar.active { transform: translateX(0); }
            body.sidebar-active::before {
                content: ''; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0, 0, 0, 0.5); z-index: 999; transition: opacity 0.3s ease; opacity: 1;
            }
            body:not(.sidebar-active)::before { opacity: 0; pointer-events: none; }
        }

        @media (max-width: 480px) {
            .sidebar { width: 240px; }
            .sidebar-header { padding: 10px 15px 5px; min-height: 75px; }
            .logo-container { height: 55px; }
            .logo-text { font-size: 1.5rem; }
            .logo-subtitle { font-size: 0.7rem; }
            .menu-item a, .dropdown-btn { padding: 12px 20px; font-size: 0.85rem; }
            .dropdown-container a { padding: 10px 15px 10px 50px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <div class="logo-text">My Schobank</div>
                <div class="logo-subtitle">Panel Petugas</div>
            </div>
        </div>

        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Menu Operasional</div>

                <!-- Dashboard -->
                <div class="menu-item">
                    <a href="dashboard.php" class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>

                <!-- Menu Transaksi -->
                <div class="menu-item">
                    <button 
                        class="dropdown-btn <?php echo $is_transaksi_active ? 'active-dropdown' : ($transaksi_enabled ? '' : 'disabled'); ?>" 
                        id="transaksiDropdown"
                        title="<?php echo $transaksi_enabled ? 'Layanan transaksi aktif' : 'Layanan transaksi ditutup'; ?>"
                    >
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_transaksi_active ? ' show' : ''; ?>" id="transaksiDropdownContainer">
                        <?php if (!$transaksi_enabled): ?>
                            <span>Layanan transaksi ditutup.</span>
                        <?php else: ?>
                            <a href="setor.php" class="<?php echo $current_page == 'setor.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-circle-down"></i> Setoran Tunai</a>
                            <a href="tarik.php" class="<?php echo $current_page == 'tarik.php' ? 'active' : ''; ?>"><i class="fas fa-arrow-circle-up"></i> Penarikan Tunai</a>
                            <a href="transfer.php" class="<?php echo $current_page == 'transfer.php' ? 'active' : ''; ?>"><i class="fas fa-paper-plane"></i> Transfer Antar Tabungan</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu Rekening -->
                <div class="menu-item">
                    <button 
                        class="dropdown-btn <?php echo $is_rekening_active ? 'active-dropdown' : ($rekening_enabled ? '' : 'disabled'); ?>" 
                        id="rekeningDropdown"
                        title="<?php echo $rekening_enabled ? 'Layanan administrasi rekening aktif' : 'Layanan administrasi rekening ditutup'; ?>"
                    >
                        <div class="menu-icon">
                            <i class="fas fa-users-cog"></i> Rekening 
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_rekening_active ? ' show' : ''; ?>" id="rekeningDropdownContainer">
                        <?php if (!$rekening_enabled): ?>
                            <span>Administrasi rekening ditutup.</span>
                        <?php else: ?>
                            <a href="buka_rekening.php" class="<?php echo $current_page == 'buka_rekening.php' ? 'active' : ''; ?>"><i class="fas fa-user-plus"></i> Pembukaan Rekening</a>
                            <a href="tutup_rekening.php" class="<?php echo $current_page == 'tutup_rekening.php' ? 'active' : ''; ?>"><i class="fas fa-user-times"></i> Penutupan Rekening</a>
                            <a href="ubah_jenis_rekening.php" class="<?php echo $current_page == 'ubah_jenis_rekening.php' ? 'active' : ''; ?>"><i class="fas fa-exchange-alt"></i> Perubahan Jenis Rekening</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu Informasi Rekening -->
                <div class="menu-item">
                    <button 
                        class="dropdown-btn <?php echo $is_search_active ? 'active-dropdown' : ($search_enabled ? '' : 'disabled'); ?>" 
                        id="searchDropdown"
                        title="<?php echo $search_enabled ? 'Layanan informasi rekening aktif' : 'Layanan informasi rekening ditutup'; ?>"
                    >
                        <div class="menu-icon">
                            <i class="fas fa-search"></i> Informasi Rekening
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>

                    <div class="dropdown-container<?php echo $is_search_active ? ' show' : ''; ?>" id="searchDropdownContainer">
                        <?php if (!$search_enabled): ?>
                            <span>Akses informasi rekening ditutup.</span>
                        <?php else: ?>
                            <a href="cek_mutasi.php" class="<?php echo $current_page == 'cek_mutasi.php' ? 'active' : ''; ?>"><i class="fas fa-list-alt"></i> Mutasi Rekening</a>
                            <a href="validasi_transaksi.php" class="<?php echo $current_page == 'validasi_transaksi.php' ? 'active' : ''; ?>"><i class="fas fa-receipt"></i> Validasi Bukti</a>
                            <a href="cek_saldo.php" class="<?php echo $current_page == 'cek_saldo.php' ? 'active' : ''; ?>"><i class="fas fa-wallet"></i> Informasi Saldo</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="menu-label">Menu Pendukung</div>

                <div class="menu-item">
                    <a href="absensi.php" class="<?php echo $current_page == 'absensi.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i> Absensi Petugas
                    </a>
                </div>

                <div class="menu-item">
                    <a href="laporan_transaksi_harian.php" class="<?php echo $laporan_enabled && $current_page == 'laporan_transaksi_harian.php' ? 'active' : ($laporan_enabled ? '' : 'disabled'); ?>">
                        <i class="fas fa-calendar-day"></i> Laporan Harian
                    </a>
                </div>

                <!-- Menu Tunggal: Pengaturan Akun -->
                <div class="menu-item">
                    <a href="pengaturan_akun.php" class="<?php echo $current_page == 'ganti_password_petugas.php' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Pengaturan Akun
                    </a>
                </div>

            </div>
        </div>

        <div class="sidebar-footer">
            <div class="menu-item">
                <a href="../../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Keluar dari Sistem
                </a>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dropdownBtns = document.querySelectorAll('.dropdown-btn');

            dropdownBtns.forEach(btn => {
                btn.addEventListener('click', function(e) {
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
        </script>
    </div>
</body>
</html>
