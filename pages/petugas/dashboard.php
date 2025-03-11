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

$saldo_bersih = $uang_masuk - $uang_keluar;

// Simpan informasi petugas yang bertugas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['officer1_name'])) {
    $petugas1_name = $_POST['officer1_name'];
    $petugas1_class = $_POST['officer1_class'];
    $petugas2_name = $_POST['officer2_name'];
    $petugas2_class = $_POST['officer2_class'];
    $tanggal = date('Y-m-d');

    $query = "INSERT INTO petugas_tugas (petugas1_name, petugas1_class, petugas2_name, petugas2_class, tanggal) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sssss", $petugas1_name, $petugas1_class, $petugas2_name, $petugas2_class, $tanggal);
    $stmt->execute();
}
// [1] Ambil jadwal petugas hari ini
$query_schedule = "SELECT * FROM petugas_tugas WHERE tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();

// [2] Cek absensi petugas
$attendance = null;
if ($petugas_id) {
    $query_attendance = "SELECT * FROM absensi 
                       WHERE user_id = ? AND tanggal = CURDATE()";
    $stmt_attendance = $conn->prepare($query_attendance);
    $stmt_attendance->bind_param("i", $petugas_id);
    $stmt_attendance->execute();
    $attendance = $stmt_attendance->get_result()->fetch_assoc();
}

// [3] Handle form absensi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_in'])) {
        $query = "INSERT INTO absensi (user_id, tanggal, waktu_masuk) 
                 VALUES (?, CURDATE(), NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
    } 
    elseif (isset($_POST['check_out'])) {
        $query = "UPDATE absensi SET waktu_keluar = NOW() 
                 WHERE user_id = ? AND tanggal = CURDATE()";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $petugas_id);
        $stmt->execute();
        header("Refresh:0");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 0;
            position: fixed;
            height: 100%;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 100;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
        }
        .logout-btn {
            color: red !important; /* Warna teks merah */
        }
        .attendance-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-top: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .attendance-status {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid #28a745;
        }

        .attendance-status p {
            margin: 5px 0;
            color: #333;
        }

        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            display: flex;
            align-items: center; 
            justify-content: center;
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            margin-bottom: 10px;
            background: rgba(0, 0, 0, 0.1);
        }

        .bank-icon {
            font-size: 32px; 
            color: #fff; 
            margin-right: 12px;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .bank-title {
            color: #fff; 
            font-size: 26px; 
            font-weight: bold;
            letter-spacing: 1px;
        }
        
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

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s ease;
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
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 16px;
            max-width: 80%;
        }
        
        .welcome-banner .date {
            margin-top: 15px;
            font-size: 15px;
            opacity: 0.8;
            display: flex;
            align-items: center;
        }
        
        .welcome-banner .date i {
            margin-right: 8px;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 100%;
            background: url('https://cdnjs.cloudflare.com/ajax/libs/simple-icons/6.0.0/simpleicons.svg#bank') no-repeat;
            background-position: right center;
            background-size: contain;
            opacity: 0.1;
        }

        /* Enhanced Summary Section */
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
    border: 1px solid rgba(0, 0, 0, 0.1);
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

        /* Specific styles for each stat box */
        .stat-box.transactions::before {
            background: linear-gradient(90deg, #9333ea, #d8b4fe);
        }

        .stat-box.transactions .stat-icon {
            background: linear-gradient(135deg, #9333ea, #d8b4fe);
        }

        .stat-box.income::before {
            background: linear-gradient(90deg, #22c55e, #86efac);
        }

        .stat-box.income .stat-icon {
            background: linear-gradient(135deg, #22c55e, #86efac);
        }

        .stat-box.expense::before {
            background: linear-gradient(90deg, #ef4444, #fca5a5);
        }

        .stat-box.expense .stat-icon {
            background: linear-gradient(135deg, #ef4444, #fca5a5)
        }

        .stat-box.balance::before {
            background: linear-gradient(90deg, #3b82f6, #93c5fd);
        }

        .stat-box.balance .stat-icon {
            background: linear-gradient(135deg, #3b82f6, #93c5fd);
        }

        /* Animated background for stat boxes */
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .stat-box::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                45deg,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0.8) 50%,
                rgba(255, 255, 255, 0) 100%
            );
            transform: translateX(-100%);
            transition: transform 0.6s;
        }

        .stat-box:hover::after {
            transform: translateX(50%);
        }

    /* Officer Section Styles */
    .officer-section {
        margin-top: 30px;
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        border: 1px solid #e0e0e0;
        transition: all 0.3s ease;
    }

    .officer-section:hover {
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }

    .officer-section h2 {
        font-size: 24px;
        font-weight: 600;
        color: #1a365d;
        margin-bottom: 25px;
        position: relative;
        padding-bottom: 10px;
    }

    .officer-section h2::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 50px;
        height: 3px;
        background: #2563eb;
        border-radius: 2px;
    }

    .officer-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 25px;
        margin-bottom: 25px;
    }

    .officer-box {
        background: #f8fafc;
        padding: 25px;
        border-radius: 10px;
        border-left: 4px solid #2563eb;
        transition: all 0.3s ease;
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
    }

    .officer-box:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
    }

    .officer-box h3 {
        color: #1a365d;
        margin-bottom: 20px;
        font-size: 20px;
        font-weight: 600;
        position: relative;
        padding-bottom: 10px;
    }

    .officer-box h3::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 40px;
        height: 3px;
        background: #2563eb;
        border-radius: 2px;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #4a5568;
        font-size: 14px;
        font-weight: 500;
    }

    .form-group input {
        width: 100%;
        padding: 12px 15px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
        background-color: white;
        color: #1a365d;
    }

    .form-group input:focus {
        border-color: #2563eb;
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .save-btn {
        background: linear-gradient(to right, #2563eb, #1d4ed8);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 20px;
        box-shadow: 0 4px 8px rgba(37, 99, 235, 0.2);
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .save-btn i {
        margin-right: 8px;
    }

    .save-btn:hover {
        background: linear-gradient(to right, #1d4ed8, #1e40af);
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(37, 99, 235, 0.25);
    }

    .save-btn:active {
        transform: translateY(0);
    }

        /* Mobile menu toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            right: 20px;
            background: #0a2e5c;
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            z-index: 1000;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            font-size: 24px;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .menu-toggle {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }
            
            .welcome-banner h2 {
                font-size: 24px;
            }
            
            .welcome-banner p {
                max-width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .officer-grid {
        grid-template-columns: 1fr;
    }

    .officer-box {
        margin-bottom: 15px;
    }

    .officer-section {
        padding: 25px;
    }
        }

        
        /* Enhanced Money Transfer Section */
        .money-transfer-section {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
            margin-bottom: 30px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .money-transfer-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
        }

        .money-transfer-section h2 {
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 20px;
            position: relative;
        }

        .money-transfer-section h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: #3498db;
            border-radius: 2px;
        }

        .money-transfer-section .form-group {
            margin-bottom: 20px;
        }

        .money-transfer-section .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .money-transfer-section .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background-color: white;
        }

        .money-transfer-section .form-group input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .money-transfer-section .transfer-btn {
            background: linear-gradient(to right, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .money-transfer-section .transfer-btn i {
            margin-right: 10px;
        }

        .money-transfer-section .transfer-btn:hover {
            background: linear-gradient(to right, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
        }

        .money-transfer-section .transfer-btn:active {
            transform: translateY(0);
        }

        /* Enhanced Officer Information Section */
        .officer-info-section {
            background: white;
            border-radius: 18px;
            padding: 30px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
            margin-bottom: 30px;
            border: 1px solid rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }

        .officer-info-section:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0, 0, 0, 0.1);
        }

        .officer-info-section h2 {
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 20px;
            position: relative;
        }

        .officer-info-section h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: #3498db;
            border-radius: 2px;
        }

        .officer-info-section .officer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .officer-info-section .officer-box {
            background: #f8fafc;
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid #3b82f6;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.02);
        }

        .officer-info-section .officer-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }

        .officer-info-section .officer-box h3 {
            color: #0f172a;
            margin-bottom: 18px;
            font-size: 20px;
            font-weight: 600;
            position: relative;
            padding-bottom: 10px;
        }

        .officer-info-section .officer-box h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 3px;
            background: #3b82f6;
            border-radius: 2px;
        }

        .officer-info-section .form-group {
            margin-bottom: 20px;
        }

        .officer-info-section .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
        }

        .officer-info-section .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background-color: white;
        }

        .officer-info-section .form-group input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .officer-info-section .save-btn {
            background: linear-gradient(to right, #3b82f6, #2563eb);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 15px;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .officer-info-section .save-btn i {
            margin-right: 10px;
        }

        .officer-info-section .save-btn:hover {
            background: linear-gradient(to right, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
        }

        .officer-info-section .save-btn:active {
            transform: translateY(0);
        }
        .transfer-btn {
    background: linear-gradient(to right, #3b82f6, #2563eb);
    color: white;
    border: none;
    padding: 12px 20px; /* Ukuran padding yang lebih kecil */
    border-radius: 8px; /* Border-radius yang lebih kecil */
    font-size: 14px; /* Ukuran font yang lebih kecil */
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    margin: 10px 0; /* Margin yang lebih kecil */
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: auto; /* Lebar menyesuaikan konten */
    max-width: 200px; /* Lebar maksimal */
}

.transfer-btn:hover {
    background: linear-gradient(to right, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(37, 99, 235, 0.25);
}

.transfer-btn:active {
    transform: translateY(0);
}

.transfer-btn i {
    margin-right: 8px; /* Jarak antara ikon dan teks */
}
/* Attendance Container */
.attendance-container {
    background: white;
    border-radius: 16px;
    padding: 25px;
    margin-top: 30px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    border: 1px solid #e0e0e0;
    transition: all 0.3s ease;
}

.attendance-container:hover {
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
}

.attendance-container h2 {
    font-size: 24px;
    font-weight: 600;
    color: #1a365d;
    margin-bottom: 25px;
    position: relative;
    padding-bottom: 10px;
}

.attendance-container h2::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 50px;
    height: 3px;
    background: #2563eb;
    border-radius: 2px;
}

/* Attendance Grid */
.attendance-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
}

/* Attendance Box */
.attendance-box {
    background: #f8fafc;
    padding: 25px;
    border-radius: 12px;
    border-left: 4px solid #2563eb;
    transition: all 0.3s ease;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
}

.attendance-box:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.1);
}

.attendance-box h3 {
    color: #1a365d;
    margin-bottom: 20px;
    font-size: 20px;
    font-weight: 600;
    position: relative;
    padding-bottom: 10px;
}

.attendance-box h3::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 40px;
    height: 3px;
    background: #2563eb;
    border-radius: 2px;
}

.attendance-box p {
    margin: 10px 0;
    color: #4a5568;
    font-size: 16px;
}

.attendance-box .btn {
    background: linear-gradient(to right, #2563eb, #1d4ed8);
    color: white;
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
    margin-top: 20px;
    box-shadow: 0 4px 8px rgba(37, 99, 235, 0.2);
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.attendance-box .btn i {
    margin-right: 8px;
}

.attendance-box .btn:hover {
    background: linear-gradient(to right, #1d4ed8, #1e40af);
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(37, 99, 235, 0.25);
}

.attendance-box .btn:active {
    transform: translateY(0);
}

.attendance-status {
    background: #f0f4f8;
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid #28a745;
}

.attendance-status p {
    margin: 5px 0;
    color: #333;
    font-size: 14px;
}
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h2 class="bank-title">SCHOBANK</h2>
        </div>

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
                <button class="dropdown-btn" id="searchDropdown">
                    <div class="menu-icon">
                        <i class="fas fa-search"></i> Cek Info
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </button>
                <div class="dropdown-container" id="searchDropdownContainer">
                <a href="riwayat_transfer.php">
                    <i class="fas fa-history"></i> Riwayat Transfer
                </a>
                    <a href="cari_mutasi.php">
                        <i class="fas fa-list-alt"></i> Mutasi Rekening
                    </a>
                    <a href="cari_transaksi.php">
                        <i class="fas fa-receipt"></i> Cek Bukti Transaksi
                    </a>
                    <a href="cari_nasabah.php">
                        <i class="fas fa-address-card"></i> Profil Nasabah
                    </a>
                    <a href="cek_saldo.php">
                        <i class="fas fa-wallet"></i> Cek Saldo
                    </a>
                </div>
            </div>
                        
            <div class="menu-item">
                <a href="data_siswa.php">
                    <i class="fas fa-user-plus"></i> Buka Rekening
                </a>
            </div>
            <div class="menu-item">
                <a href="tutup_rek.php">
                    <i class="fas fa-user-slash"></i> Tutup Rekening
                </a>
            </div>
            
            <div class="menu-item">
                <a href="laporan.php">
                    <i class="fas fa-calendar-day"></i> Laporan 
                </a>
            </div>
            
            <div class="menu-label">Pengaturan</div>
            
            <div class="menu-item">
                <a href="profil.php">
                    <i class="fas fa-user-cog"></i> Pengaturan Akun
                </a>
            </div>
        
            <div class="menu-item">
                <a href="../../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>

        </div>
    </div>
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <h2>Hai, <?= htmlspecialchars($username) ?>!</h2>
            <p>Akses semua layanan perbankan untuk siswa dengan mudah dan cepat.</p>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?= tanggal_indonesia(date('Y-m-d')) ?>
            </div>
        </div>
        <div class="dashboard-container">
    <!-- Financial Stats Section -->
    <div class="stats-container">
    <!-- Transaction Count Box -->
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
    <!-- Income Box -->
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
    <!-- Expense Box -->
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
    <!-- Net Balance Box -->
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
<!-- Tambahkan di bagian main-content setelah statistik -->
<div class="attendance-container">
    <h2>Jadwal & Absensi Petugas Hari Ini</h2>
    <div class="attendance-grid">
        <!-- Jadwal Petugas -->
        <div class="attendance-box">
            <h3>Jadwal Petugas</h3>
            <?php if ($schedule): ?>
                <div class="schedule-info">
                    <p><strong>Petugas Utama:</strong> <?= htmlspecialchars($schedule['petugas1_nama']) ?></p>
                    <p><strong>Petugas Pendamping:</strong> <?= htmlspecialchars($schedule['petugas2_nama']) ?></p>
                </div>
            <?php else: ?>
                <p>Belum ada jadwal petugas untuk hari ini.</p>
            <?php endif; ?>
        </div>

        <!-- Absensi Harian -->
        <div class="attendance-box">
            <h3>Absensi Harian</h3>
            <?php if ($schedule): ?>
                <?php if (!$attendance): ?>
                    <form method="post">
                        <button type="submit" name="check_in" class="btn">
                            <i class="fas fa-fingerprint"></i> Check In
                        </button>
                    </form>
                <?php elseif ($attendance && !$attendance['waktu_keluar']): ?>
                    <form method="post">
                        <button type="submit" name="check_out" class="btn">
                            <i class="fas fa-sign-out-alt"></i> Check Out
                        </button>
                    </form>
                <?php else: ?>
                    <div class="attendance-status">
                        <p>✅ Anda telah absen hari ini</p>
                        <p><strong>Check In:</strong> <?= date('H:i', strtotime($attendance['waktu_masuk'])) ?></p>
                        <p><strong>Check Out:</strong> <?= date('H:i', strtotime($attendance['waktu_keluar'])) ?></p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p>Tidak ada jadwal absen hari ini.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle menu for mobile view
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            document.body.classList.toggle('sidebar-active');
        });
    }
    
    // Setup all dropdowns
    setupDropdowns();
    
    // Set active menu based on current page
    setActiveMenu();
    
    // Close sidebar when clicking main content on mobile
    const mainContent = document.getElementById('mainContent');
    if (mainContent) {
        mainContent.addEventListener('click', function(e) {
            if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                document.body.classList.remove('sidebar-active');
            }
        });
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.matches('.dropdown-btn') && 
            !e.target.closest('.dropdown-btn') && 
            !e.target.closest('.dropdown-container')) {
            
            closeAllDropdowns();
        }
    });
    
    // Functions
    function setupDropdowns() {
        // Get all dropdown buttons
        const dropdownBtns = document.querySelectorAll('.dropdown-btn');
        
        dropdownBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Toggle active class on the button
                this.classList.toggle('active');
                
                // Get the next sibling which is the dropdown container
                const dropdownContainer = this.nextElementSibling;
                
                // Toggle show class on the dropdown container
                if (dropdownContainer) {
                    dropdownContainer.classList.toggle('show');
                    
                    // Close other dropdowns
                    const allDropdowns = document.querySelectorAll('.dropdown-container');
                    allDropdowns.forEach(dropdown => {
                        if (dropdown !== dropdownContainer && dropdown.classList.contains('show')) {
                            dropdown.classList.remove('show');
                            const dropBtn = dropdown.previousElementSibling;
                            if (dropBtn) dropBtn.classList.remove('active');
                        }
                    });
                }
            });
        });
    }
    
    function closeAllDropdowns() {
        const allDropdowns = document.querySelectorAll('.dropdown-container');
        allDropdowns.forEach(dropdown => {
            dropdown.classList.remove('show');
            const dropBtn = dropdown.previousElementSibling;
            if (dropBtn) dropBtn.classList.remove('active');
        });
    }
    
    function setActiveMenu() {
        // Get current page pathname
        const currentPage = window.location.pathname.split('/').pop();
        
        // Get all menu links
        const menuLinks = document.querySelectorAll('.sidebar-menu a');
        
        menuLinks.forEach(link => {
            const href = link.getAttribute('href');
            
            if (href === currentPage) {
                // Add active class to current link
                link.classList.add('active');
                
                // If link is inside dropdown, open that dropdown
                const parentDropdown = link.closest('.dropdown-container');
                if (parentDropdown) {
                    parentDropdown.classList.add('show');
                    const parentBtn = parentDropdown.previousElementSibling;
                    if (parentBtn) parentBtn.classList.add('active');
                }
            }
        });
    }
});

// For officer information form
document.getElementById('officerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = {};
    formData.forEach((value, key) => {
        data[key] = value;
    });
    
    try {
        const response = await fetch('save_officer_info.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            alert('Informasi petugas berhasil disimpan.');
        } else {
            alert('Gagal menyimpan informasi petugas: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menyimpan informasi petugas.');
    }
});

// For money transfer form
document.getElementById('serahkanUangForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Get saldo bersih value from the input field
    const saldoBersihInput = document.getElementById('saldo_bersih');
    let saldoBersih = 0;
    
    if (saldoBersihInput) {
        // Extract numeric value from formatted string (e.g., "Rp 100.000")
        const saldoValue = saldoBersihInput.value.replace(/[^\d]/g, '');
        saldoBersih = parseInt(saldoValue, 10);
    }
    
    // Validate saldo bersih
    if (isNaN(saldoBersih) || saldoBersih <= 0) {
        alert('Saldo bersih tidak valid.');
        return;
    }
    
    try {
        const response = await fetch('serahkan_uang.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ saldo_bersih: saldoBersih }),
        });
        
        const result = await response.json();
        if (result.status === 'success') {
            alert('Uang berhasil diserahkan.');
            window.location.reload(); // Reload page to update data
        } else {
            alert('Gagal menyerahkan uang: ' + (result.message || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat menyerahkan uang.');
    }
});
    </script>
</body>
</html>