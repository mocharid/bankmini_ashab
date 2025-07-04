<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;

date_default_timezone_set('Asia/Jakarta');

// Check petugas block status
$query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block = $conn->query($query_block);
$is_blocked = $result_block->fetch_assoc()['is_blocked'] ?? 0;

// Get today's officer schedule
$query_schedule = "SELECT * FROM petugas_tugas WHERE tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();

// Get the names of scheduled officers for today
$petugas1_nama = $schedule ? $schedule['petugas1_nama'] : '';
$petugas2_nama = $schedule ? $schedule['petugas2_nama'] : '';

// Check if both officers have checked in today (for transaction data display)
$both_checked_in = false;
if ($schedule) {
    $query_attendance = "SELECT * FROM absensi 
                        WHERE tanggal = CURDATE() 
                        AND ((petugas_type = 'petugas1' AND petugas1_status = 'hadir')
                             OR (petugas_type = 'petugas2' AND petugas2_status = 'hadir'))";
    $stmt_attendance = $conn->prepare($query_attendance);
    $stmt_attendance->execute();
    $result_attendance = $stmt_attendance->get_result();
    $attendance_records = $result_attendance->fetch_all(MYSQLI_ASSOC);
    
    $petugas1_checked_in = false;
    $petugas2_checked_in = false;
    
    foreach ($attendance_records as $record) {
        if ($record['petugas_type'] === 'petugas1' && $record['petugas1_status'] === 'hadir') {
            $petugas1_checked_in = true;
        }
        if ($record['petugas_type'] === 'petugas2' && $record['petugas2_status'] === 'hadir') {
            $petugas2_checked_in = true;
        }
    }
    
    $both_checked_in = ($petugas1_nama ? $petugas1_checked_in : true) && ($petugas2_nama ? $petugas2_checked_in : true);
}

// Check if both officers are still active (not checked out) for alert message
$both_active = false;
if ($schedule) {
    $query_active = "SELECT * FROM absensi 
                    WHERE tanggal = CURDATE() 
                    AND ((petugas_type = 'petugas1' AND petugas1_status = 'hadir' AND waktu_keluar IS NULL)
                         OR (petugas_type = 'petugas2' AND petugas2_status = 'hadir' AND waktu_keluar IS NULL))";
    $stmt_active = $conn->prepare($query_active);
    $stmt_active->execute();
    $result_active = $stmt_active->get_result();
    $active_records = $result_active->fetch_all(MYSQLI_ASSOC);
    
    $petugas1_active = false;
    $petugas2_active = false;
    
    foreach ($active_records as $record) {
        if ($record['petugas_type'] === 'petugas1' && $record['petugas1_status'] === 'hadir' && is_null($record['waktu_keluar'])) {
            $petugas1_active = true;
        }
        if ($record['petugas_type'] === 'petugas2' && $record['petugas2_status'] === 'hadir' && is_null($record['waktu_keluar'])) {
            $petugas2_active = true;
        }
    }
    
    $both_active = ($petugas1_nama ? $petugas1_active : true) && ($petugas2_nama ? $petugas2_active : true);
}

// Query for transaction data (only if not blocked and both officers checked in)
$total_transaksi = 0;
$uang_masuk = 0;
$uang_keluar = 0;
$saldo_bersih = 0;

if (!$is_blocked && $both_checked_in) {
    $query = "SELECT COUNT(id) as total_transaksi 
              FROM transaksi 
              WHERE DATE(created_at) = CURDATE() 
              AND petugas_id = ? 
              AND jenis_transaksi IN ('setor', 'tarik')
              AND (status = 'approved' OR status IS NULL)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $petugas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_transaksi = $result->fetch_assoc()['total_transaksi'] ?? 0;

    $query = "SELECT SUM(jumlah) as uang_masuk 
              FROM transaksi 
              WHERE jenis_transaksi = 'setor' 
              AND DATE(created_at) = CURDATE() 
              AND petugas_id = ? 
              AND (status = 'approved' OR status IS NULL)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $petugas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $uang_masuk = $result->fetch_assoc()['uang_masuk'] ?? 0;

    $query = "SELECT SUM(jumlah) as uang_keluar 
              FROM transaksi 
              WHERE jenis_transaksi = 'tarik' 
              AND DATE(created_at) = CURDATE() 
              AND petugas_id = ? 
              AND status = 'approved'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $petugas_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $uang_keluar = $result->fetch_assoc()['uang_keluar'] ?? 0;

    $saldo_bersih = $uang_masuk - $uang_keluar;
}

// Function to convert date to Indonesian format
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $hari = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    $tanggal = strtotime($tanggal);
    $hari_ini = $hari[date('l', $tanggal)];
    $tanggal_format = date('d', $tanggal);
    $bulan_ini = $bulan[date('n', $tanggal)];
    $tahun = date('Y', $tanggal);
    return "$hari_ini, $tanggal_format $bulan_ini $tahun";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
            --alert-bg-start: rgba(59, 130, 246, 0.1);
            --alert-bg-end: rgba(30, 58, 138, 0.1);
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

        html {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            transition: background-color 0.3s ease;
            font-size: 0.95rem;
            line-height: 1.6;
            min-height: 100vh;
            touch-action: pan-y;
        }

        /* Sidebar Utama */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            z-index: 100;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
        }

        /* Sidebar Header (Fixed) */
        .sidebar-header {
            padding: 20px;
            background: var(--primary-dark);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sidebar-header .bank-name {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin: 0;
            color: white;
            text-transform: uppercase;
            text-align: center;
        }

        /* Sidebar Content (Scrollable) */
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        /* Sidebar Footer (Fixed) */
        .sidebar-footer {
            background: var(--primary-dark);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            bottom: 0;
            z-index: 101;
            padding: 5px 0;
        }

        /* Scrollbar Styling */
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

        /* Menu Items */
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

        .menu-item a:hover, .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .menu-item a.disabled {
            pointer-events: none;
            color: #ccc;
            cursor: not-allowed;
        }

        .menu-item a.disabled:hover::after {
            content: 'Silakan absen terlebih dahulu';
            position: absolute;
            top: 50%;
            left: 100%;
            transform: translateY(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 100;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        /* Dropdown Menu */
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

        .dropdown-btn:hover, .dropdown-btn.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .dropdown-btn.disabled {
            pointer-events: none;
            color: #ccc;
            cursor: not-allowed;
        }

        .dropdown-btn.disabled:hover::after {
            content: 'Silakan absen terlebih dahulu';
            position: absolute;
            top: 50%;
            left: 100%;
            transform: translateY(-50%);
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 100;
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

        .dropdown-btn.active .arrow {
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

        .dropdown-container a:hover, .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: var(--secondary-color);
            color: white;
        }

        .dropdown-container a.disabled {
            pointer-events: none;
            color: #ccc;
            cursor: not-allowed;
        }

        /* Tombol Logout (Warna Merah) */
        .logout-btn {
            color: var(--danger-color);
            font-weight: 500;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: var(--transition);
            max-width: calc(100% - 280px);
            overflow-y: auto;
            min-height: 100vh;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 18px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            animation: fadeIn 1s ease-in-out;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .welcome-banner .date {
            font-size: 0.9rem;
            font-weight: 400;
            opacity: 0.8;
            display: flex;
            align-items: center;
        }

        .welcome-banner .date i {
            margin-right: 8px;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            position: absolute;
            top: 50%;
            left: 20px;
            transform: translateY(-50%);
            z-index: 1000;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Summary Section */
        .summary-section {
            padding: 10px 0 30px;
        }

        .summary-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .summary-header h2 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-right: 15px;
            position: relative;
        }

        .summary-header h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--secondary-color);
            border-radius: 2px;
        }

        /* Stats Grid Layout */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-box {
            background: white;
            border-radius: 16px;
            padding: 25px;
            position: relative;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideIn 0.5s ease-in-out;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-color));
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 20px;
            transition: var(--transition);
            color: white;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }

        .stat-box:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-title {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--primary-dark);
            letter-spacing: 0.5px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: auto;
            padding-top: 10px;
            font-weight: 400;
        }

        .stat-trend i {
            margin-right: 8px;
        }

        /* Animated Counter */
        .stat-value.counter {
            display: inline-block;
            font-family: 'Poppins', monospace;
            transition: var(--transition);
        }

        /* Alert Styling */
        .alert-message {
            background: linear-gradient(145deg, var(--alert-bg-start) 0%, var(--alert-bg-end) 100%);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-weight: 500;
            animation: fadeIn 1s ease-in-out;
        }

        .alert-message i {
            color: var(--secondary-color);
            font-size: 1.5rem;
        }

        .alert-message:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            transition: var(--transition);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive Design for Tablets (max-width: 768px) */
        @media (max-width: 768px) {
            .menu-toggle {
                display: flex;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.active {
                transform: translateX(0);
                z-index: 999;
            }

            body.sidebar-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
                transition: opacity 0.3s ease;
                opacity: 1;
            }

            body:not(.sidebar-active)::before {
                opacity: 0;
                pointer-events: none;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
                transition: opacity 0.3s ease;
            }

            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }

            .sidebar-header {
                padding: 15px;
            }

            .sidebar-header .bank-name {
                font-size: 1.4rem;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 12px;
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .welcome-banner .content {
                flex: 1;
                padding-left: 50px;
            }

            .welcome-banner h2 {
                font-size: 1.4rem;
            }

            .welcome-banner .date {
                font-size: 0.85rem;
            }

            .summary-header h2 {
                font-size: 1.2rem;
            }

            .summary-header h2::after {
                width: 30px;
                height: 2px;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-box {
                padding: 18px;
                border-radius: 12px;
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.3rem;
                border-radius: 10px;
            }

            .stat-title {
                font-size: 0.85rem;
            }

            .stat-value {
                font-size: 1.4rem;
            }

            .stat-trend {
                font-size: 0.8rem;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .menu-toggle {
                font-size: 1.3rem;
                left: 15px;
            }

            .sidebar {
                width: 240px;
            }

            .sidebar-header {
                padding: 12px;
            }

            .sidebar-header .bank-name {
                font-size: 1.3rem;
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

            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
                border-radius: 10px;
            }

            .welcome-banner .content {
                padding-left: 45px;
            }

            .welcome-banner h2 {
                font-size: 1.2rem;
            }

            .welcome-banner .date {
                font-size: 0.8rem;
            }

            .summary-header h2 {
                font-size: 1.1rem;
            }

            .summary-header h2::after {
                width: 25px;
            }

            .stat-box {
                padding: 15px;
                border-radius: 10px;
            }

            .stat-icon {
                width: 42px;
                height: 42px;
                font-size: 1.2rem;
            }

            .stat-title {
                font-size: 0.8rem;
            }

            .stat-value {
                font-size: 1.3rem;
            }

            .stat-trend {
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1 class="bank-name">SchoBank</h1>
        </div>
        
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Menu Utama</div>
                <div class="menu-item">
                    <a href="dashboard.php" class="<?php echo $both_checked_in && !$is_blocked ? 'active' : 'disabled'; ?>" title="<?php echo $both_checked_in && !$is_blocked ? '' : 'Silakan absen terlebih dahulu'; ?>">
                        <i class="fas fa-home"></i> Beranda
                    </a>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn <?php echo $both_checked_in && !$is_blocked ? '' : 'disabled'; ?>" id="transaksiDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="transaksiDropdownContainer">
                        <?php if ($is_blocked || !$both_checked_in): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Transaksi dinonaktifkan</span>
                        <?php else: ?>
                            <a href="setor_tunai.php">
                                <i class="fas fa-arrow-circle-down"></i> Setor Tunai
                            </a>
                            <a href="tarik_tunai.php">
                                <i class="fas fa-arrow-circle-up"></i> Tarik Tunai
                            </a>
                            <a href="transfer.php">
                                <i class="fas fa-paper-plane"></i> Transfer
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn <?php echo $both_checked_in && !$is_blocked ? '' : 'disabled'; ?>" id="rekeningDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-users-cog"></i> Rekening
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="rekeningDropdownContainer">
                        <?php if ($is_blocked || !$both_checked_in): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Rekening dinonaktifkan</span>
                        <?php else: ?>
                            <a href="tambah_nasabah.php">
                                <i class="fas fa-user-plus"></i> Buka Rekening
                            </a>
                            <a href="tutup_rek.php">
                                <i class="fas fa-user-plus"></i> Tutup Rekening
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn <?php echo $both_checked_in && !$is_blocked ? '' : 'disabled'; ?>" id="searchDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-search"></i> Cek Informasi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="searchDropdownContainer">
                        <?php if ($is_blocked || !$both_checked_in): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Cek Informasi dinonaktifkan</span>
                        <?php else: ?>
                            <a href="cari_mutasi.php">
                                <i class="fas fa-list-alt"></i> Cek Mutasi Rekening
                            </a>
                            <a href="cari_transaksi.php">
                                <i class="fas fa-receipt"></i> Cek Bukti Transaksi
                            </a>
                            <a href="cek_saldo.php">
                                <i class="fas fa-wallet"></i> Cek Saldo
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="menu-label">Menu Lainnya</div>
                <div class="menu-item">
                    <a href="absensi_petugas.php">
                        <i class="fas fa-user-check"></i> Absen
                    </a>
                </div>
                <div class="menu-item">
                    <a href="laporan.php" class="<?php echo $both_checked_in && !$is_blocked ? '' : 'disabled'; ?>">
                        <i class="fas fa-calendar-day"></i> Cetak Laporan
                    </a>
                </div>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <div class="menu-item">
                <a href="../../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Hai, <?= htmlspecialchars($petugas1_nama) ?> & <?= htmlspecialchars($petugas2_nama) ?>!</h2>
                <div class="date">
                    <?= tanggal_indonesia(date('Y-m-d')) ?>
                </div>
            </div>
        </div>

        <?php if ($is_blocked): ?>
            <div class="alert-message">
                <i class="fas fa-exclamation-triangle"></i> 
                Semua aktivitas petugas telah diblokir oleh admin. Silakan hubungi admin untuk informasi lebih lanjut.
            </div>
        <?php elseif (!$schedule): ?>
            <div class="alert-message">
                <i class="fas fa-info-circle"></i> 
                Belum ada petugas yang bertugas.
            </div>
        <?php elseif (!$both_checked_in): ?>
            <div class="alert-message">
                <i class="fas fa-exclamation-circle"></i> 
                Silakan absen terlebih dahulu.
            </div>
        <?php elseif (!$both_active): ?>
            <div class="alert-message">
                <i class="fas fa-check-circle"></i> 
                Terimakasih atas kerja keras kamu hari ini, Good Job!
            </div>
        <?php endif; ?>

        <div class="summary-section">
            <div class="summary-header">
                <h2>Ringkasan Data</h2>
            </div>
            
            <div class="stats-container">
                <div class="stat-box transactions">
                    <div class="stat-icon">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Transaksi</div>
                        <div class="stat-value counter" data-target="<?= $total_transaksi ?>">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-clock"></i>
                            <span>Hari Ini</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box income">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Uang Masuk</div>
                        <div class="stat-value counter" data-target="<?= $uang_masuk ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-arrow-circle-down"></i>
                            <span>Total Setoran</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box expense">
                    <div class="stat-icon">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Uang Keluar</div>
                        <div class="stat-value counter" data-target="<?= $uang_keluar ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-arrow-circle-up"></i>
                            <span>Total Penarikan</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value counter" data-target="<?= $saldo_bersih ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Selisih Masuk-Keluar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent Pinch-to-Zoom
            document.addEventListener('wheel', function(e) {
                if (e.ctrlKey) {
                    e.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '=')) {
                    e.preventDefault();
                }
            });

            // Sidebar toggle for mobile
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', function(e) {
                if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Dropdown functionality (only one open at a time)
            const dropdownButtons = document.querySelectorAll('.dropdown-btn');
            dropdownButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (this.classList.contains('disabled')) return;
                    e.stopPropagation();
                    const dropdownContainer = this.nextElementSibling;
                    const isActive = this.classList.contains('active');

                    dropdownButtons.forEach(btn => {
                        const container = btn.nextElementSibling;
                        btn.classList.remove('active');
                        container.classList.remove('show');
                    });

                    if (!isActive) {
                        this.classList.add('active');
                        dropdownContainer.classList.add('show');
                    }
                });
            });

            document.addEventListener('click', function(e) {
                dropdownButtons.forEach(button => {
                    const dropdownContainer = button.nextElementSibling;
                    if (!button.contains(e.target)) {
                        button.classList.remove('active');
                        dropdownContainer.classList.remove('show');
                    }
                });
            });

            // Animated Counter for Numbers
            const counters = document.querySelectorAll('.counter');
            counters.forEach(counter => {
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const prefix = counter.getAttribute('data-prefix') || '';
                    const count = +counter.innerText.replace(/[^0-9]/g, '') || 0;
                    const increment = target / 100;

                    if (count < target) {
                        let newCount = Math.ceil(count + increment);
                        if (newCount > target) newCount = target;
                        counter.innerText = prefix + newCount.toLocaleString('id-ID');
                        setTimeout(updateCount, 20);
                    } else {
                        counter.innerText = prefix + target.toLocaleString('id-ID');
                    }
                };
                updateCount();
            });
        });
    </script>
</body>
</html>