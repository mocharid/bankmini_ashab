<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;

date_default_timezone_set('Asia/Jakarta');

// Query untuk mengambil total transaksi, uang masuk, dan uang keluar
$query = "SELECT COUNT(id) as total_transaksi 
          FROM transaksi 
          WHERE DATE(created_at) = CURDATE() 
          AND petugas_id = ? 
          AND (status = 'approved' OR status IS NULL)";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $petugas_id);
$stmt->execute();
$result = $stmt->get_result();
$total_transaksi = $result->fetch_assoc()['total_transaksi'];

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

// Get additional balance from admin transfers
$query = "SELECT SUM(jumlah) as saldo_tambahan 
          FROM saldo_transfers 
          WHERE petugas_id = ? 
          AND tanggal = CURDATE()";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $petugas_id);
$stmt->execute();
$result = $stmt->get_result();
$saldo_tambahan = $result->fetch_assoc()['saldo_tambahan'] ?? 0;

$saldo_bersih = ($uang_masuk - $uang_keluar) + $saldo_tambahan;

// Check if user is logged in
$petugas_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$user_name = isset($_SESSION['name']) ? $_SESSION['name'] : null;

// Get current date for comparison
$current_date = date('Y-m-d');

// Get today's officer schedule
$query_schedule = "SELECT * FROM petugas_tugas WHERE tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();

// Get the names of scheduled officers for today
$petugas1_nama = $schedule ? $schedule['petugas1_nama'] : '';
$petugas2_nama = $schedule ? $schedule['petugas2_nama'] : '';

// Check for old attendance records
$query_check_old = "SELECT * FROM absensi WHERE tanggal < CURDATE() AND waktu_keluar IS NULL";
$stmt_check_old = $conn->prepare($query_check_old);
$stmt_check_old->execute();
$result_check_old = $stmt_check_old->get_result();

// Get attendance records for both officers for today
$attendance_petugas1 = null;
$attendance_petugas2 = null;

$query_attendance1 = "SELECT * FROM absensi 
                     WHERE petugas_type = 'petugas1' AND tanggal = CURDATE()";
$stmt_attendance1 = $conn->prepare($query_attendance1);
$stmt_attendance1->execute();
$attendance_petugas1 = $stmt_attendance1->get_result()->fetch_assoc();

$query_attendance2 = "SELECT * FROM absensi 
                     WHERE petugas_type = 'petugas2' AND tanggal = CURDATE()";
$stmt_attendance2 = $conn->prepare($query_attendance2);
$stmt_attendance2->execute();
$attendance_petugas2 = $stmt_attendance2->get_result()->fetch_assoc();

// Handle attendance form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_in_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), NOW(), 'hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
    } elseif (isset($_POST['check_out_petugas1'])) {
        $query = "UPDATE absensi SET waktu_keluar = NOW() 
                 WHERE petugas_type = 'petugas1' AND tanggal = CURDATE()";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        header("Refresh:0");
    } elseif (isset($_POST['check_in_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), NOW(), 'hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
    } elseif (isset($_POST['check_out_petugas2'])) {
        $query = "UPDATE absensi SET waktu_keluar = NOW() 
                 WHERE petugas_type = 'petugas2' AND tanggal = CURDATE()";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        header("Refresh:0");
    } elseif (isset($_POST['absent_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), 'tidak_hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
    } elseif (isset($_POST['absent_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), 'tidak_hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
    }
}

// Store the last visited date in the session
$session_last_date = isset($_SESSION['last_attendance_date']) ? $_SESSION['last_attendance_date'] : '';
if ($session_last_date != $current_date) {
    $_SESSION['last_attendance_date'] = $current_date;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo "<script>window.location.reload();</script>";
    }
}

// Fungsi untuk mengonversi tanggal ke bahasa Indonesia
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    $hari = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            touch-action: pan-y;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }

        body {
            background-color: #f0f5ff;
            color: #333;
            display: flex;
            transition: background-color 0.3s ease;
        }

        /* Sidebar Utama */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0a2e5c 0%, #154785 100%);
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
            padding: 25px 20px;
            background: #0a2e5c;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            text-align: center;
        }

        .sidebar-header .bank-name {
            font-size: 26px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 0;
            color: white;
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
            background: #0a2e5c;
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
            background: #f8fafc;
            border-radius: 4px;
        }

        .sidebar-content::-webkit-scrollbar-thumb {
            background: #bfdbfe;
            border-radius: 4px;
            border: 2px solid #f8fafc;
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover {
            background: #93c5fd;
        }

        /* Menu Items */
        .sidebar-menu {
            padding: 10px 0;
        }

        .menu-label {
            padding: 15px 25px 10px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
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
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .menu-item a:hover, .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #38bdf8;
            color: white;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 18px;
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
            font-size: 16px;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .dropdown-btn:hover, .dropdown-btn.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #38bdf8;
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
            font-size: 18px;
        }

        .dropdown-btn .arrow {
            transition: transform 0.3s ease;
            font-size: 14px;
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
            transition: all 0.3s ease;
            font-size: 14px;
            border-left: 4px solid transparent;
        }

        .dropdown-container a:hover, .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: #38bdf8;
            color: white;
        }

        /* Tombol Logout (Warna Merah) */
        .logout-btn {
            color: #ff3b3b;
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s ease;
            max-width: calc(100% - 280px);
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #2563eb 100%);
            color: white;
            padding: 30px;
            border-radius: 18px;
            margin-bottom: 35px;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.15);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            animation: fadeIn 1s ease-in-out;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .welcome-banner .date {
            font-size: 15px;
            opacity: 0.8;
            display: flex;
            align-items: center;
        }

        .welcome-banner .date i {
            margin-right: 8px;
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
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
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
            background: #3498db;
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
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
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
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.8), rgba(59, 130, 246, 0.3));
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            color: white;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .stat-box:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-title {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 10px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1a365d;
            letter-spacing: 0.5px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #64748b;
            margin-top: auto;
            padding-top: 10px;
        }

        .stat-trend i {
            margin-right: 8px;
        }

        /* Attendance Container */
        .attendance-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            animation: fadeIn 1s ease-in-out;
        }

        .attendance-title {
            font-size: 20px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 20px;
        }

        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-radius: 8px;
            overflow: hidden;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }

        .attendance-table th {
            background-color: #0a2e5c;
            color: white;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
        }

        .attendance-table tr:last-child td {
            border-bottom: none;
        }

        .attendance-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .attendance-table tr:hover {
            background-color: #ebf5ff;
            transition: background-color 0.2s ease;
        }

        /* Status Indicators */
        .present-status {
            background-color: #e6f7ed;
            color: #2ecc71;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 15px;
            display: inline-block;
        }

        .absent-status {
            background-color: #fdeaea;
            color: #e74c3c;
            font-weight: 600;
            padding: 5px 10px;
            border-radius: 15px;
            display: inline-block;
        }

        /* Buttons */
        .btn {
            padding: 8px 15px;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin: 3px;
            transition: all 0.3s ease;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: #3b82f6;
        }

        .btn i {
            margin-right: 5px;
        }

        .btn:hover {
            background-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-danger {
            background-color: #e74c3c;
        }

        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(231, 76, 60, 0.3);
        }

        /* Empty Message */
        .empty-state {
            text-align: center;
            color: #64748b;
            font-size: 15px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .empty-state i {
            font-size: 2.5rem;
            color: #95a5a6;
            margin-bottom: 15px;
            display: block;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .menu-toggle:hover {
            transform: scale(1.1);
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
                position: absolute;
                top: 50%;
                left: 20px;
                transform: translateY(-50%);
                z-index: 1000;
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

            /* Welcome Banner */
            .welcome-banner {
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 6px 15px rgba(37, 99, 235, 0.2);
                position: relative;
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
                font-size: 22px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .welcome-banner .date {
                font-size: 12px;
                font-weight: 500;
            }

            /* Summary Section */
            .summary-header h2 {
                font-size: 20px;
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
                box-shadow: 0 6px 15px rgba(0, 0, 0, 0.06);
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 22px;
                border-radius: 10px;
            }

            .stat-title {
                font-size: 13px;
                font-weight: 500;
            }

            .stat-value {
                font-size: 22px;
                font-weight: 700;
            }

            .stat-trend {
                font-size: 12px;
            }

            /* Attendance Container */
            .attendance-container {
                padding: 15px;
                border-radius: 12px;
            }

            .attendance-title {
                font-size: 18px;
                margin-bottom: 15px;
            }

            .attendance-table {
                border: 0;
                box-shadow: none;
            }

            .attendance-table thead {
                display: none;
            }

            .attendance-table tr {
                display: block;
                margin-bottom: 20px;
                border-radius: 8px;
                box-shadow: 0 3px 10px rgba(0, 0, 0, 0.08);
                overflow: hidden;
            }

            .attendance-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                text-align: right;
                padding: 12px 15px;
                border-bottom: 1px solid #e9ecef;
            }

            .attendance-table td:before {
                content: attr(data-label);
                font-weight: 600;
                text-align: left;
                color: #34495e;
            }

            .attendance-table td:last-child {
                border-bottom: 0;
            }

            .btn {
                width: 100%;
                margin: 3px 0;
            }

            form[style="display: inline;"] {
                display: block !important;
                margin-bottom: 5px;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .menu-toggle {
                font-size: 20px;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
            }

            .sidebar {
                width: 240px;
            }

            .sidebar-header .bank-name {
                font-size: 22px;
            }

            .menu-item a, .dropdown-btn {
                padding: 12px 20px;
                font-size: 14px;
            }

            .dropdown-container a {
                padding: 10px 15px 10px 50px;
                font-size: 13px;
            }

            .main-content {
                padding: 10px;
            }

            /* Welcome Banner */
            .welcome-banner {
                padding: 15px;
                border-radius: 10px;
            }

            .welcome-banner .content {
                padding-left: 45px;
            }

            .welcome-banner h2 {
                font-size: 18px;
            }

            .welcome-banner .date {
                font-size: 11px;
            }

            /* Summary Section */
            .summary-header h2 {
                font-size: 18px;
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
                font-size: 20px;
            }

            .stat-title {
                font-size: 12px;
            }

            .stat-value {
                font-size: 20px;
            }

            .stat-trend {
                font-size: 11px;
            }

            /* Attendance Container */
            .attendance-container {
                padding: 12px;
            }

            .attendance-title {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1 class="bank-name">SCHOBANK</h1>
        </div>
        
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Menu Utama</div>
                <div class="menu-item">
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="transaksiDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="transaksiDropdownContainer">
                        <a href="setor_tunai.php">
                            <i class="fas fa-arrow-circle-down"></i> Setor Tunai
                        </a>
                        <a href="tarik_tunai.php">
                            <i class="fas fa-arrow-circle-up"></i> Tarik Tunai
                        </a>
                        <a href="transfer.php">
                            <i class="fas fa-paper-plane"></i> Transfer
                        </a>
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="rekeningDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-users-cog"></i> Rekening
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="rekeningDropdownContainer">
                        <a href="data_siswa.php">
                            <i class="fas fa-user-plus"></i> Buka Rekening
                        </a>
                        <!-- <a href="tutup_rek.php">
                            <i class="fas fa-user-slash"></i> Tutup Rekening
                        </a> -->
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="searchDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-search"></i> Cek Informasi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="searchDropdownContainer">
                        <a href="cari_mutasi.php">
                            <i class="fas fa-list-alt"></i> Cek Mutasi Rekening
                        </a>
                        <a href="cari_transaksi.php">
                            <i class="fas fa-receipt"></i> Cek Bukti Transaksi
                        </a>
                        <a href="cek_saldo.php">
                            <i class="fas fa-wallet"></i> Cek Saldo
                        </a>
                    </div>
                </div>

                <div class="menu-label">Menu Lainnya</div>

                <div class="menu-item">
                    <a href="riwayat_transfer.php">
                        <i class="fas fa-history"></i> Riwayat Transfer
                    </a>
                </div>

                <div class="menu-item">
                    <a href="laporan.php">
                        <i class="fas fa-calendar-day"></i> Cetak Laporan
                    </a>
                </div>

                <div class="menu-label">Pengaturan</div>

                <div class="menu-item">
                    <a href="profil.php">
                        <i class="fas fa-user-cog"></i> Pengaturan Akun
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

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Hai, <?= htmlspecialchars($petugas1_nama) ?> & <?= htmlspecialchars($petugas2_nama) ?>!</h2>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?= tanggal_indonesia(date('Y-m-d')) ?>
                </div>
            </div>
        </div>

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
                        <div class="stat-value"><?= number_format($total_transaksi) ?></div>
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
                        <div class="stat-value">Rp <?= number_format($uang_masuk, 0, ',', '.') ?></div>
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
                        <div class="stat-value">Rp <?= number_format($uang_keluar, 0, ',', '.') ?></div>
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
                        <div class="stat-value">Rp <?= number_format($saldo_bersih, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Selisih Masuk-Keluar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="attendance-container">
            <h3 class="attendance-title">Jadwal & Absensi Petugas Hari Ini</h3>
            
            <?php if ($schedule): ?>
                <table class="attendance-table">
                    <thead>
                        <tr>
                            <th>Petugas</th>
                            <th>Nama Petugas</th>
                            <th>Status</th>
                            <th>Jam Masuk</th>
                            <th>Jam Keluar</th>
                            <th>Absensi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Petugas 1 Row -->
                        <tr>
                            <td data-label="Petugas">Petugas 1</td>
                            <td data-label="Nama Petugas"><?= htmlspecialchars($petugas1_nama) ?></td>
                            <td data-label="Status">
                                <?php if ($attendance_petugas1): ?>
                                    <?php if ($attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                        <span class="present-status">Hadir</span>
                                    <?php else: ?>
                                        <span class="absent-status">Tidak Hadir</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Jam Masuk">
                                <?php if ($attendance_petugas1 && $attendance_petugas1['waktu_masuk'] && $attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                    <?= date('H:i', strtotime($attendance_petugas1['waktu_masuk'])) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Jam Keluar">
                                <?php if ($attendance_petugas1 && $attendance_petugas1['waktu_keluar'] && $attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                    <?= date('H:i', strtotime($attendance_petugas1['waktu_keluar'])) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Absensi">
                                <?php if (!$attendance_petugas1): ?>
                                    <form method="post" style="display: inline;">
                                        <button type="submit" name="check_in_petugas1" class="btn">
                                            <i class="fas fa-fingerprint"></i> Hadir
                                        </button>
                                    </form>
                                    <form method="post" style="display: inline;">
                                        <button type="submit" name="absent_petugas1" class="btn btn-danger">
                                            <i class="fas fa-times"></i> Tidak Hadir
                                        </button>
                                    </form>
                                <?php elseif ($attendance_petugas1 && !$attendance_petugas1['waktu_keluar'] && 
                                            $attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                    <form method="post">
                                        <button type="submit" name="check_out_petugas1" class="btn">
                                            <i class="fas fa-sign-out-alt"></i> Absen Pulang
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span>Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Petugas 2 Row -->
                        <tr>
                            <td data-label="Petugas">Petugas 2</td>
                            <td data-label="Nama Petugas"><?= htmlspecialchars($petugas2_nama) ?></td>
                            <td data-label="Status">
                                <?php if ($attendance_petugas2): ?>
                                    <?php if ($attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                        <span class="present-status">Hadir</span>
                                    <?php else: ?>
                                        <span class="absent-status">Tidak Hadir</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Jam Masuk">
                                <?php if ($attendance_petugas2 && $attendance_petugas2['waktu_masuk'] && $attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                    <?= date('H:i', strtotime($attendance_petugas2['waktu_masuk'])) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Jam Keluar">
                                <?php if ($attendance_petugas2 && $attendance_petugas2['waktu_keluar'] && $attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                    <?= date('H:i', strtotime($attendance_petugas2['waktu_keluar'])) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td data-label="Absensi">
                                <?php if (!$attendance_petugas2): ?>
                                    <form method="post" style="display: inline;">
                                        <button type="submit" name="check_in_petugas2" class="btn">
                                            <i class="fas fa-fingerprint"></i> Hadir
                                        </button>
                                    </form>
                                    <form method="post" style="display: inline;">
                                        <button type="submit" name="absent_petugas2" class="btn btn-danger">
                                            <i class="fas fa-times"></i> Tidak Hadir
                                        </button>
                                    </form>
                                <?php elseif ($attendance_petugas2 && !$attendance_petugas2['waktu_keluar'] && 
                                            $attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                    <form method="post">
                                        <button type="submit" name="check_out_petugas2" class="btn">
                                            <i class="fas fa-sign-out-alt"></i> Absen Pulang
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span>Selesai</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-info-circle"></i> Belum ada jadwal petugas untuk hari ini.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
        });
    </script>
</body>
</html>