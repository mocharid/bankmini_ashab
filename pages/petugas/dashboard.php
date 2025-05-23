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

// Query untuk mengambil total transaksi, uang masuk, dan uang keluar (only if not blocked)
$total_transaksi = 0;
$uang_masuk = 0;
$uang_keluar = 0;
$saldo_bersih = 0;

if (!$is_blocked) {
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

    $saldo_bersih = $uang_masuk - $uang_keluar;
}

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

// Handle attendance form submissions (only if not blocked)
if (!$is_blocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $success_message = '';
    if (isset($_POST['check_in_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), NOW(), 'hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Absen masuk Petugas 1 berhasil!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['check_out_petugas1'])) {
        $query = "UPDATE absensi SET waktu_keluar = NOW() 
                 WHERE petugas_type = 'petugas1' AND tanggal = CURDATE()";
        $stmt = $conn->prepare($query);
        if ($stmt->execute()) {
            $success_message = 'Absen pulang Petugas 1 berhasil!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['check_in_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, waktu_masuk, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), NOW(), 'hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Absen masuk Petugas 2 berhasil!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['check_out_petugas2'])) {
        $query = "UPDATE absensi SET waktu_keluar = NOW() 
                 WHERE petugas_type = 'petugas2' AND tanggal = CURDATE()";
        $stmt = $conn->prepare($query);
        if ($stmt->execute()) {
            $success_message = 'Absen pulang Petugas 2 berhasil!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['absent_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), 'tidak_hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 1 ditandai tidak hadir!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['absent_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), 'tidak_hadir')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 2 ditandai tidak hadir!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['sakit_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), 'sakit')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 1 ditandai sakit!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['sakit_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), 'sakit')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 2 ditandai sakit!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['izin_petugas1'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas1_status) 
                 VALUES (?, 'petugas1', CURDATE(), 'izin')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 1 ditandai izin!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
    } elseif (isset($_POST['izin_petugas2'])) {
        $query = "INSERT INTO absensi (user_id, petugas_type, tanggal, petugas2_status) 
                 VALUES (?, 'petugas2', CURDATE(), 'izin')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        if ($stmt->execute()) {
            $success_message = 'Petugas 2 ditandai izin!';
        }
        header("Location: dashboard.php?attendance_success=" . urlencode($success_message));
        exit();
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

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: none; /* Mencegah penyesuaian ukuran teks otomatis */
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            transition: background-color 0.3s ease;
            font-size: 0.95rem;
            line-height: 1.6;
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
            padding: 25px 20px;
            background: var(--primary-dark);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            text-align: center;
        }

        .sidebar-header .bank-name {
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: 1.2px;
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

        /* Attendance Container */
        .attendance-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
            animation: fadeIn 1s ease-in-out;
        }

        .attendance-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
        }

        .attendance-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            box-shadow: var(--shadow-sm);
            border-radius: 8px;
            overflow: hidden;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 15px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.9rem;
            font-weight: 400;
        }

        .attendance-table th {
            background-color: var(--primary-dark);
            color: white;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            font-weight: 500;
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
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 15px;
            display: inline-block;
            font-size: 0.85rem;
        }

        .absent-status {
            background-color: #fdeaea;
            color: var(--danger-color);
            font-weight: 500;
            padding: 5px 10px;
            border-radius: 15px;
            display: inline-block;
            font-size: 0.85rem;
        }

        /* Buttons */
        .btn {
            padding: 8px 15px;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin: 3px;
            transition: var(--transition);
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background-color: var(--secondary-color);
            box-shadow: var(--shadow-sm);
        }

        .btn i {
            margin-right: 5px;
        }

        .btn:hover {
            background-color: var(--secondary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-danger {
            background-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
            transform: translateY(-2px);
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            box-shadow: var(--shadow-sm);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        /* Empty Message */
        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-weight: 400;
        }

        .empty-state i {
            font-size: 2rem;
            color: #95a5a6;
            margin-bottom: 15px;
            display: block;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            color: white;
            font-size: 1.5rem;
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

        @keyframes cardFadeIn {
            0% { opacity: 0; transform: translateY(20px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Success Modal Animations */
        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Success Modal Styling */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 16px;
            padding: 30px 25px;
            text-align: center;
            max-width: 90%;
            width: 400px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }

        .success-icon {
            font-size: 3.5rem;
            color: var(--secondary-color);
            margin-bottom: 15px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 400;
            margin: 0;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
            max-width: 80%;
        }

        .modal-content-confirm {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 15px;
            border-bottom: 1px solid #eee;
            font-size: 0.9rem;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
            width: 100%;
        }
        .sakit-status {
            color: #e67e22; /* Orange for Sakit */
            font-weight: 500;
        }
        .izin-status {
            color: #3498db; /* Blue for Izin */
            font-weight: 500;
        }
        .btn-warning {
            background: linear-gradient(135deg, #e67e22 0%, #d35400 100%);
            color: white;
        }
        .btn-warning:hover {
            background: linear-gradient(135deg, #d35400 0%, #e67e22 100%);
        }
        .btn-info {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
        }
        .btn-info:hover {
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%);
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                box-shadow: var(--shadow-md);
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
                font-size: 1.4rem;
                font-weight: 600;
            }

            .welcome-banner .date {
                font-size: 0.85rem;
                font-weight: 400;
            }

            /* Summary Section */
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
                box-shadow: var(--shadow-sm);
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.3rem;
                border-radius: 10px;
            }

            .stat-title {
                font-size: 0.85rem;
                font-weight: 500;
            }

            .stat-value {
                font-size: 1.4rem;
                font-weight: 600;
            }

            .stat-trend {
                font-size: 0.8rem;
            }

            /* Attendance Container */
            .attendance-container {
                padding: 15px;
                border-radius: 12px;
                background: #ffffff;
                box-shadow: var(--shadow-sm);
            }

            .attendance-title {
                font-size: 1.1rem;
                margin-bottom: 20px;
                color: var(--primary-dark);
                font-weight: 600;
                text-align: center;
            }

            .attendance-table {
                display: block;
                margin-top: 0;
                box-shadow: none;
            }

            .attendance-table thead {
                display: none;
            }

            .attendance-table tbody {
                display: flex;
                flex-direction: column;
                gap: 15px;
            }

            .attendance-table tr {
                display: flex;
                flex-direction: column;
                background: #ffffff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 15px;
                box-shadow: var(--shadow-sm);
                transition: var(--transition);
                animation: cardFadeIn 0.6s ease-out forwards;
                opacity: 0;
            }

            .attendance-table tr:nth-child(1) {
                animation-delay: 0.1s;
            }

            .attendance-table tr:nth-child(2) {
                animation-delay: 0.2s;
            }

            .attendance-table tr:hover {
                box-shadow: var(--shadow-md);
                transform: translateY(-2px);
            }

            .attendance-table td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border-bottom: none;
                font-size: 0.9rem;
                color: var(--text-primary);
                font-weight: 400;
            }

            .attendance-table td::before {
                content: attr(data-label);
                font-weight: 500;
                color: var(--primary-dark);
                text-transform: uppercase;
                font-size: 0.8rem;
                letter-spacing: 0.5px;
            }

            .attendance-table td[data-label="Absensi"] {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 10px 0;
            }

            .attendance-table .present-status, 
            .attendance-table .absent-status {
                padding: 6px 12px;
                font-size: 0.85rem;
                border-radius: 4px;
                font-weight: 500;
                border: 1px solid #ccc;
            }

            .attendance-table .present-status {
                background: #e6f7ed;
                color: #2ecc71;
                border-color: #2ecc71;
            }

            .attendance-table .absent-status {
                background: #fdeaea;
                color: var(--danger-color);
                border-color: var(--danger-color);
            }

            .attendance-table .btn {
                width: 100%;
                padding: 10px;
                font-size: 0.9rem;
                border-radius: 8px;
                background: var(--primary-dark);
                color: #ffffff;
                border: 1px solid var(--primary-dark);
                box-shadow: var(--shadow-sm);
                transition: var(--transition);
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-weight: 500;
            }

            .attendance-table .btn:hover {
                background: #0f1e3d;
                border-color: #0f1e3d;
                transform: scale(1.02);
                box-shadow: var(--shadow-md);
            }

            .attendance-table .btn-danger {
                background: var(--danger-color);
                border-color: var(--danger-color);
            }

            .attendance-table .btn-danger:hover {
                background: #c0392b;
                border-color: #c0392b;
                transform: scale(1.02);
                box-shadow: var(--shadow-md);
            }

            form[style="display: inline;"] {
                display: block !important;
                width: 100%;
            }

            .success-modal {
                width: 90%;
                padding: 25px;
            }

            .success-icon {
                font-size: 3rem;
            }

            .success-modal h3 {
                font-size: 1.25rem;
            }

            .success-modal p {
                font-size: 0.9rem;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .menu-toggle {
                font-size: 1.3rem;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
            }

            .sidebar {
                width: 240px;
            }

            .sidebar-header .bank-name {
                font-size: 1.4rem;
            }

            .menu-item a, .dropdown-btn {
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

            /* Welcome Banner */
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

            /* Summary Section */
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

            /* Attendance Container */
            .attendance-container {
                padding: 12px;
            }

            .attendance-title {
                font-size: 1rem;
            }

            .success-modal {
                padding: 20px;
            }

            .success-icon {
                font-size: 2.5rem;
            }

            .success-modal h3 {
                font-size: 1.15rem;
            }

            .success-modal p {
                font-size: 0.85rem;
            }
        }

        /* Alert for Blocked Petugas Activities */
        .alert-blocked {
            background: linear-gradient(145deg, #fff5f5, #ffe6e6);
            border-left: 4px solid var(--danger-color);
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

        .alert-blocked i {
            color: var(--danger-color);
            font-size: 1.5rem;
        }

        .alert-blocked:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
            transition: var(--transition);
        }

        /* Responsive adjustments for alert */
        @media (max-width: 768px) {
            .alert-blocked {
                padding: 12px 15px;
                font-size: 0.9rem;
                gap: 10px;
                margin-bottom: 20px;
            }

            .alert-blocked i {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 480px) {
            .alert-blocked {
                padding: 10px 12px;
                font-size: 0.85rem;
                gap: 8px;
                margin-bottom: 15px;
            }

            .alert-blocked i {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
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
                    <button class="dropdown-btn" id="transaksiDropdown" <?php echo $is_blocked ? 'disabled' : ''; ?>>
                        <div class="menu-icon">
                            <i class="fas fa-exchange-alt"></i> Transaksi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="transaksiDropdownContainer">
                        <?php if ($is_blocked): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Transaksi dinonaktifkan oleh admin</span>
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
                    <button class="dropdown-btn" id="rekeningDropdown" <?php echo $is_blocked ? 'disabled' : ''; ?>>
                        <div class="menu-icon">
                            <i class="fas fa-users-cog"></i> Rekening
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="rekeningDropdownContainer">
                        <?php if ($is_blocked): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Rekening dinonaktifkan oleh admin</span>
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

                <div class="menu-label">Menu Lainnya</div>
                <div class="menu-item">
                    <a href="laporan.php" <?php echo $is_blocked ? 'style="pointer-events: none; color: #ccc;"' : ''; ?>>
                        <i class="fas fa-calendar-day"></i> Cetak Laporan
                    </a>
                </div>
                <div class="menu-item">
                    <button class="dropdown-btn" id="searchDropdown" <?php echo $is_blocked ? 'disabled' : ''; ?>>
                        <div class="menu-icon">
                            <i class="fas fa-search"></i> Cek Informasi
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="searchDropdownContainer">
                        <?php if ($is_blocked): ?>
                            <span style="padding: 10px 20px; color: #ccc;">Cek Informasi dinonaktifkan oleh admin</span>
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
                    <i class="far fa-calendar-alt"></i> <?= tanggal_indonesia(date('Y-m-d')) ?>
                </div>
            </div>
        </div>

        <?php if ($is_blocked): ?>
            <div class="alert-blocked">
                <i class="fas fa-exclamation-triangle"></i> 
                Semua aktivitas petugas telah diblokir oleh admin. Silakan hubungi admin untuk informasi lebih lanjut.
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
                                        <?php if ($attendance_petugas1['waktu_masuk'] && !$attendance_petugas1['waktu_keluar']): ?>
                                            <span>-</span>
                                        <?php else: ?>
                                            <span class="present-status">Hadir</span>
                                        <?php endif; ?>
                                    <?php elseif ($attendance_petugas1['petugas1_status'] == 'tidak_hadir'): ?>
                                        <span class="absent-status">Tidak Hadir</span>
                                    <?php elseif ($attendance_petugas1['petugas1_status'] == 'sakit'): ?>
                                        <span class="sakit-status">Sakit</span>
                                    <?php elseif ($attendance_petugas1['petugas1_status'] == 'izin'): ?>
                                        <span class="izin-status">Izin</span>
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
                                <?php if (!$is_blocked): ?>
                                    <?php if (!$attendance_petugas1): ?>
                                        <button type="button" class="btn" onclick="showAttendanceModal('check_in_petugas1', 'Absen Masuk Petugas 1', '<?= htmlspecialchars($petugas1_nama) ?>')">
                                            Hadir
                                        </button>
                                        <button type="button" class="btn btn-danger" onclick="showAttendanceModal('absent_petugas1', 'Tandai Tidak Hadir Petugas 1', '<?= htmlspecialchars($petugas1_nama) ?>')">
                                            Tidak Hadir
                                        </button>
                                        <button type="button" class="btn btn-warning" onclick="showAttendanceModal('sakit_petugas1', 'Tandai Sakit Petugas 1', '<?= htmlspecialchars($petugas1_nama) ?>')">
                                            Sakit
                                        </button>
                                        <button type="button" class="btn btn-info" onclick="showAttendanceModal('izin_petugas1', 'Tandai Izin Petugas 1', '<?= htmlspecialchars($petugas1_nama) ?>')">
                                            Izin
                                        </button>
                                    <?php elseif ($attendance_petugas1 && !$attendance_petugas1['waktu_keluar'] && $attendance_petugas1['petugas1_status'] == 'hadir'): ?>
                                        <button type="button" class="btn" onclick="showAttendanceModal('check_out_petugas1', 'Absen Pulang Petugas 1', '<?= htmlspecialchars($petugas1_nama) ?>')">
                                            <i class="fas fa-sign-out-alt"></i> Absen Pulang
                                        </button>
                                    <?php else: ?>
                                        <span>Selesai</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #dc3545;">Absensi dinonaktifkan</span>
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
                                        <?php if ($attendance_petugas2['waktu_masuk'] && !$attendance_petugas2['waktu_keluar']): ?>
                                            <span>-</span>
                                        <?php else: ?>
                                            <span class="present-status">Hadir</span>
                                        <?php endif; ?>
                                    <?php elseif ($attendance_petugas2['petugas2_status'] == 'tidak_hadir'): ?>
                                        <span class="absent-status">Tidak Hadir</span>
                                    <?php elseif ($attendance_petugas2['petugas2_status'] == 'sakit'): ?>
                                        <span class="sakit-status">Sakit</span>
                                    <?php elseif ($attendance_petugas2['petugas2_status'] == 'izin'): ?>
                                        <span class="izin-status">Izin</span>
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
                                <?php if (!$is_blocked): ?>
                                    <?php if (!$attendance_petugas2): ?>
                                        <button type="button" class="btn" onclick="showAttendanceModal('check_in_petugas2', 'Absen Masuk Petugas 2', '<?= htmlspecialchars($petugas2_nama) ?>')">
                                            Hadir
                                        </button>
                                        <button type="button" class="btn btn-danger" onclick="showAttendanceModal('absent_petugas2', 'Tandai Tidak Hadir Petugas 2', '<?= htmlspecialchars($petugas2_nama) ?>')">
                                            Tidak Hadir
                                        </button>
                                        <button type="button" class="btn btn-warning" onclick="showAttendanceModal('sakit_petugas2', 'Tandai Sakit Petugas 2', '<?= htmlspecialchars($petugas2_nama) ?>')">
                                            Sakit
                                        </button>
                                        <button type="button" class="btn btn-info" onclick="showAttendanceModal('izin_petugas2', 'Tandai Izin Petugas 2', '<?= htmlspecialchars($petugas2_nama) ?>')">
                                            Izin
                                        </button>
                                    <?php elseif ($attendance_petugas2 && !$attendance_petugas2['waktu_keluar'] && $attendance_petugas2['petugas2_status'] == 'hadir'): ?>
                                        <button type="button" class="btn" onclick="showAttendanceModal('check_out_petugas2', 'Absen Pulang Petugas 2', '<?= htmlspecialchars($petugas2_nama) ?>')">
                                            <i class="fas fa-sign-out-alt"></i> Absen Pulang
                                        </button>
                                    <?php else: ?>
                                        <span>Selesai</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #dc3545;">Absensi dinonaktifkan</span>
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

        <!-- Success Modal for Attendance -->
        <?php if (isset($_GET['attendance_success'])): ?>
            <div class="success-overlay" id="attendanceSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p><?= htmlspecialchars(urldecode($_GET['attendance_success'])) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Confirmation Modal for Attendance -->
        <div class="success-overlay" id="attendanceConfirmModal" style="display: none;">
            <div class="success-modal">
                <div class="success-icon">
                </div>
                <h3 id="modalTitle">Konfirmasi Absensi</h3>
                <div class="modal-content-confirm">
                    <div class="modal-row">
                        <span class="modal-label">Nama Petugas</span>
                        <span class="modal-value" id="modalPetugasName"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Aksi</span>
                        <span class="modal-value" id="modalAction"></span>
                    </div>
                </div>
                <form action="" method="POST" id="attendanceConfirmForm">
                    <input type="hidden" name="action" id="modalActionInput">
                    <div class="modal-buttons">
                        <button type="submit" class="btn btn-confirm" id="confirm-btn">
                            <i class="fas fa-check"></i> Konfirmasi
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="hideAttendanceModal()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mencegah Pinch-to-Zoom
            document.addEventListener('touchmove', function(e) {
                if (e.scale !== 1) {
                    e.preventDefault();
                }
            }, { passive: false });

            // Mencegah Double-Tap Zoom
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(e) {
                const now = Date.now();
                if (now - lastTouchEnd <= 300) {
                    e.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            // Mencegah Zoom melalui Keyboard (Ctrl + +/-, Ctrl + Scroll)
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

            // Mencegah Zoom melalui Gesture Lain
            document.addEventListener('gesturestart', function(e) {
                e.preventDefault();
            });

            document.addEventListener('gesturechange', function(e) {
                e.preventDefault();
            });

            document.addEventListener('gestureend', function(e) {
                e.preventDefault();
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
                    if (this.disabled) return; // Prevent action if disabled
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

            // Success modal handling (no confetti)
            const successModal = document.querySelector('#attendanceSuccessModal .success-modal');
            if (successModal) {
                setTimeout(() => {
                    const overlay = successModal.closest('.success-overlay');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        overlay.remove();
                        window.location.href = 'dashboard.php';
                    }, 500);
                }, 2000);
            }

            // Confirm form handling
            const confirmForm = document.getElementById('attendanceConfirmForm');
            const confirmBtn = document.getElementById('confirm-btn');
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function() {
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                });
            }

            // Animated Counter for Numbers
            const counters = document.querySelectorAll('.counter');
            counters.forEach(counter => {
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const prefix = counter.getAttribute('data-prefix') || '';
                    const count = +counter.innerText.replace(/[^0-9]/g, '') || 0;
                    const increment = target / 100; // Adjust speed by dividing target

                    if (count < target) {
                        let newCount = Math.ceil(count + increment);
                        if (newCount > target) newCount = target;
                        counter.innerText = prefix + newCount.toLocaleString('id-ID');
                        setTimeout(updateCount, 20); // Adjust speed of animation
                    } else {
                        counter.innerText = prefix + target.toLocaleString('id-ID');
                    }
                };
                updateCount();
            });
        });

        // Attendance Modal Functions
        function showAttendanceModal(action, title, petugasName) {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalPetugasName').textContent = petugasName;
            document.getElementById('modalAction').textContent = title;
            document.getElementById('modalActionInput').name = action;
            document.getElementById('attendanceConfirmModal').style.display = 'flex';
        }

        function hideAttendanceModal() {
            document.getElementById('attendanceConfirmModal').style.display = 'none';
        }
    </script>
</body>
</html>