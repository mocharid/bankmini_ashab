<?php
/**
 * Dashboard Admin - Original Style with Integrated Hamburger
 * File: pages/admin/dashboard.php
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');

// Adaptive Path Detection
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'admin') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} else {
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
    $project_root = $current_dir;
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

if (!file_exists(INCLUDES_PATH . '/auth.php')) {
    die('Error: File auth.php tidak ditemukan di ' . INCLUDES_PATH);
}
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('Error: File db_connection.php tidak ditemukan di ' . INCLUDES_PATH);
}

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

$admin_id = $_SESSION['user_id'] ?? 0;

if ($admin_id <= 0) {
    header('Location: ../login.php');
    exit();
}

function getHariIndonesia($date)
{
    $hari = date('l', strtotime($date));
    $hariIndo = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $hariIndo[$hari];
}

function formatTanggalIndonesia($date)
{
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $bulan[$dateObj->format('F')];
    $year = $dateObj->format('Y');
    return "$day $month $year";
}

function getGreeting()
{
    $hour = (int) date('H');
    if ($hour >= 5 && $hour < 11) {
        return 'Selamat Pagi';
    } elseif ($hour >= 11 && $hour < 15) {
        return 'Selamat Siang';
    } elseif ($hour >= 15 && $hour < 18) {
        return 'Selamat Sore';
    } else {
        return 'Selamat Malam';
    }
}

// Get total students count
$query = "SELECT COUNT(id) as total_siswa FROM users WHERE role = 'siswa'";
$result = $conn->query($query);
$total_siswa = $result->fetch_assoc()['total_siswa'] ?? 0;

// Saldo Kas Administrator = Semua transaksi approved KECUALI transaksi petugas hari ini
// (transaksi petugas hari ini belum disetorkan ke kas admin)
$query = "SELECT 
            COALESCE(SUM(CASE WHEN jenis_transaksi = 'setor' AND status = 'approved' THEN jumlah ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END), 0) as total_saldo
          FROM transaksi t
          LEFT JOIN users p ON t.petugas_id = p.id
          WHERE t.jenis_transaksi IN ('setor', 'tarik')
          AND NOT (DATE(t.created_at) = CURDATE() AND p.role = 'petugas')";
$result = $conn->query($query);
$saldo_kas_admin = $result->fetch_assoc()['total_saldo'] ?? 0;

// Proyeksi Total Kas = Semua transaksi approved (Admin + Petugas) = Saldo Bersih di Rekapitulasi
$query_total = "SELECT 
            COALESCE(SUM(CASE WHEN jenis_transaksi = 'setor' AND status = 'approved' THEN jumlah ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END), 0) as total_saldo
          FROM transaksi
          WHERE jenis_transaksi IN ('setor', 'tarik')";
$result_total = $conn->query($query_total);
$proyeksi_total_kas = $result_total->fetch_assoc()['total_saldo'] ?? 0;

// Get today's transactions for Net Setoran Harian
$query_saldo_harian = "
    SELECT (
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setor' THEN t.jumlah ELSE 0 END), 0) -
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'tarik' THEN t.jumlah ELSE 0 END), 0)
    ) as saldo_harian
    FROM transaksi t
    JOIN users u ON t.petugas_id = u.id
    WHERE t.status = 'approved' 
    AND DATE(t.created_at) = CURDATE()
    AND u.role = 'petugas'
";
$stmt_saldo_harian = $conn->prepare($query_saldo_harian);
$stmt_saldo_harian->execute();
$result_saldo_harian = $stmt_saldo_harian->get_result();
$saldo_harian = floatval($result_saldo_harian->fetch_assoc()['saldo_harian'] ?? 0);

// Query untuk data chart line
$query_chart = "SELECT DATE(created_at) as tanggal, COUNT(id) as jumlah_transaksi 
                FROM transaksi 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
                AND status = 'approved'
                GROUP BY DATE(created_at) 
                ORDER BY DATE(created_at) ASC";
$result_chart = $conn->query($query_chart);
$chart_data = $result_chart->fetch_all(MYSQLI_ASSOC);

// Query untuk data pie chart
$query_pie = "SELECT 
                SUM(CASE WHEN jenis_transaksi = 'setor' THEN 1 ELSE 0 END) as setor_count,
                SUM(CASE WHEN jenis_transaksi = 'tarik' THEN 1 ELSE 0 END) as tarik_count
              FROM transaksi 
              WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
              AND status = 'approved'";
$result_pie = $conn->query($query_pie);
$pie_data = $result_pie->fetch_assoc();
$setor_count = $pie_data['setor_count'] ?? 0;
$tarik_count = $pie_data['tarik_count'] ?? 0;

// Query untuk 10 transaksi terakhir (dari admin dan petugas)
$query_recent = "SELECT 
                    t.id,
                    t.no_transaksi,
                    t.jenis_transaksi,
                    t.jumlah,
                    t.created_at,
                    t.petugas_id,
                    u.nama AS nama_siswa,
                    p.nama AS nama_operator,
                    p.role AS operator_role,
                    r.no_rekening
                FROM transaksi t
                JOIN rekening r ON t.rekening_id = r.id
                JOIN users u ON r.user_id = u.id
                LEFT JOIN users p ON t.petugas_id = p.id
                WHERE t.jenis_transaksi IN ('setor', 'tarik')
                AND t.status = 'approved'
                ORDER BY t.created_at DESC
                LIMIT 5";
$result_recent = $conn->query($query_recent);
$recent_transactions = $result_recent ? $result_recent->fetch_all(MYSQLI_ASSOC) : [];

// Helper function format waktu
function formatWaktu($datetime)
{
    return date('H:i', strtotime($datetime)) . ' WIB';
}

function formatTanggalLengkap($datetime)
{
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
    $d = date('d', strtotime($datetime));
    $m = (int) date('m', strtotime($datetime));
    $y = date('Y', strtotime($datetime));
    return $d . ' ' . $bulan[$m] . ' ' . $y;
}

// Base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));
$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
}
$base_url = $protocol . '://' . $host . $base_path;
?>

<!DOCTYPE html>
<html>

<head>
    <title>Dashboard Administrator | KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: #0f172a;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --dark: #1e293b;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
            -ms-user-select: none;
            user-select: none;
        }

        html,
        body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
        }

        body {
            background: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            transition: var(--transition);
            max-width: calc(100% - 280px);
        }

        /* Page Title Section */
        .page-title-section {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .page-title-content {
            flex: 1;
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
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            position: relative;
        }

        .summary-header h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: var(--gray-500);
            border-radius: 2px;
        }

        /* Stats Grid Layout */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-box {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            position: relative;
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--gray-200);
        }

        .stat-box:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            color: white;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        .stat-box:hover .stat-icon {
            transform: scale(1.05);
        }

        .stat-title {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--gray-800);
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: auto;
            padding-top: 0.5rem;
        }

        .stat-trend i {
            margin-right: 8px;
        }

        /* Chart Section */
        .chart-section {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .chart-container {
            background: white;
            border-radius: var(--radius);
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex: 1;
            border: 1px solid var(--gray-200);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 1rem;
        }

        #transactionChart {
            max-width: 100%;
            height: auto;
        }

        #pieChart {
            max-width: 100%;
            height: 250px;
            max-height: 250px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.9rem;
            padding: 50px 30px;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 2px dashed var(--gray-200);
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .empty-state .empty-icon {
            width: 70px;
            height: 70px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .empty-state .empty-icon i {
            font-size: 1.75rem;
            color: var(--gray-400);
        }

        .empty-state h4 {
            color: var(--gray-700);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray-500);
            font-size: 0.85rem;
            margin: 0;
        }

        /* Transaction List Styles */
        .transaction-list {
            /* No max-height to avoid scrollbar */
        }

        .transaction-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-100);
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .transaction-icon.setor {
            background: linear-gradient(135deg, var(--gray-200) 0%, var(--gray-100) 100%);
            color: var(--gray-600);
        }

        .transaction-icon.tarik {
            background: linear-gradient(135deg, var(--gray-300) 0%, var(--gray-200) 100%);
            color: var(--gray-700);
        }

        .transaction-details {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }

        .transaction-name {
            font-weight: 600;
            color: var(--gray-800);
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .transaction-meta {
            font-size: 0.7rem;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .transaction-operator {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .transaction-operator.admin {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .transaction-operator.petugas {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .transaction-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
            margin-left: 12px;
        }

        .transaction-amount {
            font-weight: 700;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .transaction-amount.setor {
            color: var(--gray-700);
        }

        .transaction-amount.tarik {
            color: var(--gray-500);
        }

        .transaction-time {
            font-size: 0.65rem;
            color: var(--gray-400);
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .transaction-time i {
            font-size: 0.6rem;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            color: var(--gray-600);
            font-size: 1.25rem;
            cursor: pointer;
            transition: transform 0.3s ease;
            position: absolute;
            top: 50%;
            left: 1rem;
            transform: translateY(-50%);
            z-index: 10;
        }

        .menu-toggle:hover {
            color: var(--gray-800);
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 1.6rem;
            font-weight: 600;
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

        .welcome-banner .content {
            flex: 1;
        }

        /* Summary Section */
        .summary-section {
            padding: 10px 0 30px;
        }




        /* Responsive Design for Tablets (max-width: 1024px) */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 1rem;
            }

            .page-hamburger {
                display: flex;
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
                opacity: 1;
            }

            body:not(.sidebar-active)::before {
                opacity: 0;
                pointer-events: none;
            }

            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }

            .chart-section {
                flex-direction: column;
                gap: 1rem;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .stat-box {
                padding: 1.25rem;
            }

            .stat-icon {
                width: 45px;
                height: 45px;
                font-size: 1.1rem;
            }

            .stat-value {
                font-size: 1.35rem;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
            }

            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .stat-box {
                padding: 1rem;
            }

            .stat-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }

            .stat-title {
                font-size: 0.8rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }

            .stat-trend {
                font-size: 0.75rem;
            }

            .chart-container {
                padding: 1rem;
            }

            .chart-title {
                font-size: 1rem;
            }

            #transactionChart {
                max-height: 180px;
            }

            #pieChart {
                height: 180px;
                max-height: 180px;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Dashboard Administrator</h1>
                <p class="page-subtitle">
                    <?= getHariIndonesia(date('Y-m-d')) . ', ' . formatTanggalIndonesia(date('Y-m-d')) ?>
                </p>
            </div>
        </div>

        <div class="summary-section">
            <div class="greeting-header" style="margin-bottom: 1.5rem;">
                <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--gray-800); margin: 0;">
                    <h2 style="font-size: 1.1rem; font-weight: 600; color: var(--gray-800); margin: 0;">
                        <?= getGreeting() ?>, Administrator!
                    </h2>
            </div>

            <div class="summary-header">
                <h2>Ikhtisar Keuangan</h2>
            </div>

            <div class="stats-container">
                <div class="stat-box students">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Rekening Nasabah</div>
                        <div class="stat-value counter" data-target="<?= $total_siswa ?>">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-user-graduate"></i>
                            <span>Rekening Aktif Terdaftar</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Proyeksi Total Kas</div>
                        <div class="stat-value counter" data-target="<?= $proyeksi_total_kas ?>" data-prefix="Rp ">0
                        </div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Saldo Bersih Semua Transaksi</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Kas Administrator</div>
                        <div class="stat-value counter" data-target="<?= $saldo_kas_admin ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Belum Termasuk Setoran Teller Hari Ini</span>
                        </div>
                    </div>
                </div>
                <div class="stat-box setor">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Proyeksi Setoran Teller</div>
                        <div class="stat-value counter" data-target="<?= $saldo_harian ?>" data-prefix="Rp ">0</div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar-day"></i>
                            <span>Saldo Bersih Teller Hari Ini</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="chart-section">
                <div class="chart-container">
                    <h3 class="chart-title">Grafik Transaksi 7 Hari Terakhir</h3>
                    <?php if (empty($chart_data) || array_sum(array_column($chart_data, 'jumlah_transaksi')) == 0): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <h4>Belum Ada Transaksi</h4>
                            <p>Data grafik akan muncul setelah ada transaksi</p>
                        </div>
                    <?php else: ?>
                        <canvas id="transactionChart"></canvas>
                    <?php endif; ?>
                </div>
                <div class="chart-container">
                    <h3 class="chart-title">Riwayat Transaksi Terbaru</h3>
                    <?php if (empty($recent_transactions)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <h4>Belum Ada Transaksi</h4>
                            <p>Data riwayat akan muncul setelah ada transaksi</p>
                        </div>
                    <?php else: ?>
                        <div class="transaction-list">
                            <?php foreach ($recent_transactions as $trans): ?>
                                <div class="transaction-item">
                                    <div class="transaction-info">
                                        <div class="transaction-icon <?= $trans['jenis_transaksi'] ?>">
                                            <i
                                                class="fas fa-<?= $trans['jenis_transaksi'] == 'setor' ? 'arrow-down' : 'arrow-up' ?>"></i>
                                        </div>
                                        <div class="transaction-details">
                                            <div class="transaction-name"><?= htmlspecialchars($trans['nama_siswa']) ?></div>
                                            <div class="transaction-meta">
                                                <span class="transaction-operator <?= $trans['operator_role'] ?? 'admin' ?>">
                                                    <i class="fas fa-user"></i>
                                                    <?= $trans['nama_operator'] ? htmlspecialchars($trans['nama_operator']) : 'Admin' ?>
                                                </span>
                                                <span>•</span>
                                                <span><?= $trans['jenis_transaksi'] == 'setor' ? 'Setor' : 'Tarik' ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="transaction-right">
                                        <div class="transaction-amount <?= $trans['jenis_transaksi'] ?>">
                                            <?= $trans['jenis_transaksi'] == 'setor' ? '+' : '-' ?>Rp
                                            <?= number_format($trans['jumlah'], 0, ',', '.') ?>
                                        </div>
                                        <div class="transaction-time">
                                            <?= formatTanggalLengkap($trans['created_at']) ?>,
                                            <?= formatWaktu($trans['created_at']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if (!empty($chart_data) && array_sum(array_column($chart_data, 'jumlah_transaksi')) > 0): ?>
                // Line Chart Configuration
                const ctxLine = document.getElementById('transactionChart').getContext('2d');
                const lineGradient = ctxLine.createLinearGradient(0, 0, 0, 300);
                lineGradient.addColorStop(0, 'rgba(100, 116, 139, 0.4)');
                lineGradient.addColorStop(0.5, 'rgba(100, 116, 139, 0.15)');
                lineGradient.addColorStop(1, 'rgba(100, 116, 139, 0)');

                new Chart(ctxLine, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode(array_map(function ($row) {
                            return date('d/m/Y', strtotime($row['tanggal']));
                        }, $chart_data)) ?>,
                        datasets: [{
                            label: 'Jumlah Transaksi',
                            data: <?= json_encode(array_column($chart_data, 'jumlah_transaksi')) ?>,
                            backgroundColor: lineGradient,
                            borderColor: 'rgba(71, 85, 105, 1)',
                            borderWidth: 3,
                            pointRadius: 5,
                            pointHoverRadius: 8,
                            pointBackgroundColor: '#ffffff',
                            pointBorderColor: 'rgba(71, 85, 105, 1)',
                            pointBorderWidth: 3,
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        scales: {
                            y: { beginAtZero: true, grid: { color: 'rgba(0, 0, 0, 0.05)' } },
                            x: { grid: { display: false }, ticks: { maxRotation: 45, minRotation: 45 } }
                        },
                        responsive: true,
                        maintainAspectRatio: true,
                        aspectRatio: 2,
                        plugins: { legend: { display: false } }
                    }
                });
            <?php endif; ?>

            // Sidebar toggle for mobile
            const pageHamburgerBtn = document.getElementById('pageHamburgerBtn');
            const sidebar = document.getElementById('sidebar');

            if (pageHamburgerBtn && sidebar) {
                pageHamburgerBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            // Close sidebar when clicking outside
            document.addEventListener('click', function (e) {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !pageHamburgerBtn.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Animated Counter
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

    <!-- Session Auto-Logout -->
    <script>
        window.sessionConfig = {
            timeout: <?php echo $_SESSION['session_timeout'] ?? 300; ?>,
            logoutUrl: '<?php echo $base_url; ?>/logout.php?reason=timeout'
        };
    </script>
    <script src="<?php echo $base_url; ?>/assets/js/session_auto_logout.js"></script>
</body>

</html>