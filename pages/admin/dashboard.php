<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

// Fungsi untuk mengubah nama hari ke Bahasa Indonesia
function getHariIndonesia($date) {
    $hari = date('l', strtotime($date)); // Ambil nama hari dalam bahasa Inggris
    $hariIndo = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $hariIndo[$hari]; // Kembalikan nama hari dalam Bahasa Indonesia
}

// Get total students count
$query = "SELECT COUNT(id) as total_siswa FROM users WHERE role = 'siswa'";
$result = $conn->query($query);
$total_siswa = $result->fetch_assoc()['total_siswa'];

// Get total balance (saldo keseluruhan)
$query = "SELECT SUM(saldo) as total_saldo FROM rekening";
$result = $conn->query($query);
$total_saldo = $result->fetch_assoc()['total_saldo'] ?? 0;

// Get pending withdrawals
$query = "SELECT COUNT(id) as pending_withdrawals FROM transaksi WHERE jenis_transaksi = 'tarik' AND status = 'pending'";
$result = $conn->query($query);
$pending_withdrawals = $result->fetch_assoc()['pending_withdrawals'];

// Get total approved withdrawals (admin)
$query = "SELECT SUM(jumlah) as total_withdrawals 
          FROM transaksi 
          WHERE jenis_transaksi = 'tarik' 
          AND status = 'approved'";
$result = $conn->query($query);
$total_withdrawals = $result->fetch_assoc()['total_withdrawals'] ?? 0;

// Query untuk total setoran hari ini oleh semua petugas
$query_setor = "SELECT SUM(jumlah) as total_setor 
                FROM transaksi 
                WHERE jenis_transaksi = 'setor' 
                AND DATE(created_at) = CURDATE() 
                AND status = 'approved'";
$result_setor = $conn->query($query_setor);
$total_setor = $result_setor->fetch_assoc()['total_setor'] ?? 0;

// Query untuk total penarikan hari ini oleh semua petugas
$query_tarik = "SELECT SUM(jumlah) as total_tarik 
                FROM transaksi 
                WHERE jenis_transaksi = 'tarik' 
                AND DATE(created_at) = CURDATE() 
                AND status = 'approved'";
$result_tarik = $conn->query($query_tarik);
$total_tarik = $result_tarik->fetch_assoc()['total_tarik'] ?? 0;

// Hitung saldo harian
$saldo_harian = $total_setor - $total_tarik;

// Query untuk mengambil data transaksi 7 hari terakhir
$query_chart = "SELECT DATE(created_at) as tanggal, COUNT(id) as jumlah_transaksi 
                FROM transaksi 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY DATE(created_at) ASC";
$result_chart = $conn->query($query_chart);
$chart_data = $result_chart->fetch_all(MYSQLI_ASSOC);

// Query untuk transaksi terbaru dengan pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$query_transactions = "SELECT t.*, u.nama as petugas_nama, r.no_rekening 
                      FROM transaksi t 
                      JOIN users u ON t.petugas_id = u.id 
                      JOIN rekening r ON t.rekening_id = r.id 
                      ORDER BY t.created_at DESC 
                      LIMIT ? OFFSET ?";
$stmt_transactions = $conn->prepare($query_transactions);
$stmt_transactions->bind_param("ii", $limit, $offset);
$stmt_transactions->execute();
$result_transactions = $stmt_transactions->get_result();
$transactions = $result_transactions->fetch_all(MYSQLI_ASSOC);

// Query untuk total transaksi (untuk pagination)
$query_total_transactions = "SELECT COUNT(id) as total FROM transaksi";
$result_total_transactions = $conn->query($query_total_transactions);
$total_transactions = $result_total_transactions->fetch_assoc()['total'] ?? 0; // Default to 0 if no transactions
$total_pages = $total_transactions > 0 ? ceil($total_transactions / $limit) : 1; // Ensure $total_pages is at least 1
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js" rel="stylesheet">
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
            overflow-x: hidden; /* Mencegah scroll horizontal */
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

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s ease;
            max-width: calc(100% - 280px); /* Mencegah overflow */
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
        
        .welcome-banner p {
            opacity: 0.9;
            font-size: 16px;
            margin-bottom: 15px;
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

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            animation: fadeIn 1s ease-in-out;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            animation: fadeIn 1s ease-in-out;
        }

        .table-container h3 {
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
            color: #0a2e5c;
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e0e0e0;
        }

        .table-container table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
            border-radius: 12px;
            overflow: hidden;
        }

        .table-container th, .table-container td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .table-container th {
            background-color: #0a2e5c;
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .table-container tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .table-container tr:hover {
            background-color: #f1f1f1;
            transition: background-color 0.3s ease;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination a {
            color: #3b82f6;
            padding: 8px 16px;
            text-decoration: none;
            border: 1px solid #e0e0e0;
            margin: 0 4px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
            background-color: white;
        }

        .pagination a.active {
            background-color: #3b82f6;
            color: white;
            border: 1px solid #3b82f6;
        }

        .pagination a:hover:not(.active) {
            background-color: #f1f1f1;
            color: #3b82f6;
            border-color: #3b82f6;
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

        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
                max-width: 100%; /* Mencegah overflow */
            }
            
            .menu-toggle {
                display: block;
                position: fixed;
                top: 20px;
                left: 20px;
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
        }

        @media (max-width: 768px) {
            .bank-logo {
        width: 100px; /* Ukuran lebih kecil untuk mobile */
    }
            .stats-container {
                grid-template-columns: 1fr;
            }

            .welcome-banner h2 {
                font-size: 24px;
            }

            .welcome-banner p {
                font-size: 14px;
            }

            .summary-header h2 {
                font-size: 20px;
            }

            .stat-box {
                padding: 20px;
            }

            .stat-title {
                font-size: 14px;
            }

            .stat-value {
                font-size: 24px;
            }

            .stat-trend {
                font-size: 12px;
            }

            .chart-container, .table-container {
                padding: 15px;
            }

            .table-container table {
                min-width: 100%;
            }

            .table-container th, .table-container td {
                padding: 8px;
            }

            .pagination a {
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: 20px;
            }

            .welcome-banner p {
                font-size: 12px;
            }

            .welcome-banner .date {
                font-size: 12px;
            }

            .summary-header h2 {
                font-size: 18px;
            }

            .stat-box {
                padding: 15px;
            }

            .stat-title {
                font-size: 12px;
            }

            .stat-value {
                font-size: 20px;
            }

            .stat-trend {
                font-size: 10px;
            }

            .chart-container, .table-container {
                padding: 10px;
            }

            .table-container th, .table-container td {
                padding: 6px;
            }

            .pagination a {
                padding: 4px 8px;
            }
        }
        .bank-logo {
    width: 150px; /* Sesuaikan ukuran lebar logo */
    height: auto; /* Menjaga aspek rasio */
    margin-right: 12px; /* Jarak antara logo dan teks */
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
    background: #0a2e5c;
    border-radius: 4px;
}

.sidebar-content::-webkit-scrollbar-thumb {
    background: #4a5568;
    border-radius: 4px;
    border: 2px solid #0a2e5c;
}

.sidebar-content::-webkit-scrollbar-thumb:hover {
    background: #718096;
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
}

@media (max-width: 768px) {
    .sidebar {
        width: 260px;
    }
    
    .sidebar-header .bank-name {
        font-size: 22px;
    }
}
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="sidebar" id="sidebar">
    <!-- Fixed Header -->
    <div class="sidebar-header">
        <h1 class="bank-name">SCHOBANK</h1>
    </div>
    
    <!-- Scrollable Content -->
    <div class="sidebar-content">
        <div class="sidebar-menu">
            <div class="menu-label">Menu Utama</div>
            <div class="menu-item">
                <a href="dashboard.php" class="active">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
            <div class="menu-item">
                <button class="dropdown-btn" id="dataDropdown">
                    <div class="menu-icon">
                        <i class="fas fa-database"></i> Tambah Data
                    </div>
                    <i class="fas fa-chevron-down arrow"></i>
                </button>
                <div class="dropdown-container" id="dataDropdownContainer">
                    <a href="tambah_jurusan.php">
                        <i class="fas fa-graduation-cap"></i> Tambah Jurusan
                    </a>
                    <a href="tambah_kelas.php">
                        <i class="fas fa-chalkboard"></i> Tambah Kelas
                    </a>
                    <a href="tambah_nasabah.php">
                        <i class="fas fa-user-plus"></i> Tambah Nasabah
                    </a>
                    <a href="tambah_petugas.php">
                        <i class="fas fa-user-shield"></i> Tambah Petugas
                    </a>
                </div>
            </div>

            <div class="menu-item">
                <a href="laporan.php">
                    <i class="fas fa-file-alt"></i> Laporan Transaksi
                </a>
            </div>
            <div class="menu-item">
                <a href="buat_jadwal.php">
                    <i class="fas fa-user-cog"></i> Jadwal Petugas
                </a>
            </div>
            <div class="menu-item">
                <a href="rekap_absen.php">
                    <i class="fas fa-user-check"></i> Rekap Absen Petugas
                </a>
            </div>
            <div class="menu-item">
                <a href="data_siswa.php">
                    <i class="fas fa-id-card"></i> Rekap Data Nasabah
                </a>
            </div>

            <div class="menu-label">Pengaturan</div>
            
            <div class="menu-item">
                <a href="pengaturan.php">
                    <i class="fas fa-cog"></i> Pengaturan
                </a>
            </div>
        </div>
    </div>
    
    <!-- Fixed Footer with Logout -->
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
            <h2>Selamat Datang, Admin!</h2>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?= getHariIndonesia(date('Y-m-d')) . ', ' . date('d F Y') ?>
            </div>
        </div>

        <div class="summary-section">
            <div class="summary-header">
                <h2>Ringkasan Data</h2>
            </div>
            
            <div class="stats-container">
                <div class="stat-box students">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Siswa</div>
                        <div class="stat-value"><?= number_format($total_siswa) ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-user-graduate mr-2"></i>
                            <span>Siswa Aktif</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Saldo</div>
                        <div class="stat-value">Rp <?= number_format($total_saldo, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line mr-2"></i>
                            <span>Total Dana Nasabah</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box setor">
                    <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Setor Hari Ini</div>
                        <div class="stat-value">Rp <?= number_format($total_setor, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar-day mr-2"></i>
                            <span>Setoran Hari Ini</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box tarik">
                    <div class="stat-icon">
                    <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Tarik Hari Ini</div>
                        <div class="stat-value">Rp <?= number_format($total_tarik, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar-day mr-2"></i>
                            <span>Penarikan Hari Ini</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="chart-container">
            <canvas id="transactionChart"></canvas>
        </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Chart.js configuration
            const ctx = document.getElementById('transactionChart').getContext('2d');
            const transactionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($chart_data, 'tanggal')) ?>,
                    datasets: [{
                        label: 'Jumlah Transaksi',
                        data: <?= json_encode(array_column($chart_data, 'jumlah_transaksi')) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 2,
                        pointRadius: 5,
                        pointBackgroundColor: 'rgba(75, 192, 192, 1)',
                        pointBorderColor: '#fff',
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: 'rgba(75, 192, 192, 1)',
                        pointHoverBorderColor: '#fff',
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuad'
                    }
                }
            });

            // Dropdown functionality
            const dropdownBtn = document.getElementById('dataDropdown');
            const dropdownContainer = document.getElementById('dataDropdownContainer');

            if (dropdownBtn && dropdownContainer) {
                dropdownBtn.addEventListener('click', function() {
                    dropdownContainer.classList.toggle('show');
                    dropdownBtn.classList.toggle('active');
                });

                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!dropdownBtn.contains(e.target)) {
                        dropdownContainer.classList.remove('show');
                        dropdownBtn.classList.remove('active');
                    }
                });
            }

            // Sidebar toggle for mobile
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            // Close sidebar when clicking main content on mobile
            const mainContent = document.getElementById('mainContent');
            if (mainContent) {
                mainContent.addEventListener('click', function() {
                    if (document.body.classList.contains('sidebar-active')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }
        });
    </script>
</body>
</html>