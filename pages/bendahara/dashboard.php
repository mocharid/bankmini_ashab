<?php
/**
 * Bendahara Dashboard - Adaptive Path Version (Normalized DB) - Redesigned
 * File: pages/bendahara/dashboard.php
 * Features: Modern layout, responsive design, profile dropdown (no arrow)
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/bendahara' folder
if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
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

// Fallback
if (!$project_root) {
    $project_root = dirname(dirname($current_dir));
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

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once INCLUDES_PATH . '/db_connection.php';

$username = $_SESSION['username'] ?? 'Bendahara';
$bendahara_id = $_SESSION['user_id'] ?? 0;
$nama_bendahara = $_SESSION['nama'] ?? 'Bendahara';
date_default_timezone_set('Asia/Jakarta');

$is_blocked = false;

// ============================================
// GET BENDAHARA ACCOUNT DETAILS
// ============================================
$saldo_bendahara = 0;
$no_rekening_bendahara = 'Tidak Ada';

$query_rekening = "SELECT r.no_rekening, r.saldo
                   FROM rekening r
                   WHERE r.user_id = ?";
$stmt_rekening = $conn->prepare($query_rekening);
$stmt_rekening->bind_param("i", $bendahara_id);
$stmt_rekening->execute();
$result_rekening = $stmt_rekening->get_result();
if ($rekening = $result_rekening->fetch_assoc()) {
    $no_rekening_bendahara = $rekening['no_rekening'];
    $saldo_bendahara = $rekening['saldo'];
}

// ============================================
// INFAQ CONFIG & STATUS SUMMARY
// ============================================
$nominal_default = 0;
$query_config = "SELECT nominal_per_siswa FROM infaq_nominal_config LIMIT 1";
$stmt_config = $conn->prepare($query_config);
$stmt_config->execute();
$result_config = $stmt_config->get_result();
if ($config = $result_config->fetch_assoc()) {
    $nominal_default = $config['nominal_per_siswa'];
}

$total_siswa_wajib = 0;
$total_siswa_gratis = 0;
$total_siswa_disesuaikan = 0;

$query_status_summary = "SELECT 
    SUM(CASE WHEN status_bayar = 'bayar' THEN 1 ELSE 0 END) AS total_wajib,
    SUM(CASE WHEN status_bayar = 'gratis' THEN 1 ELSE 0 END) AS total_gratis,
    SUM(CASE WHEN status_bayar = 'disesuaikan' THEN 1 ELSE 0 END) AS total_disesuaikan
FROM infaq_siswa_status";
$stmt_status = $conn->prepare($query_status_summary);
$stmt_status->execute();
$result_status = $stmt_status->get_result();
if ($status_summary = $result_status->fetch_assoc()) {
    $total_siswa_wajib = $status_summary['total_wajib'] ?? 0;
    $total_siswa_gratis = $status_summary['total_gratis'] ?? 0;
    $total_siswa_disesuaikan = $status_summary['total_disesuaikan'] ?? 0;
}

// ============================================
// INFAQ MONTHLY SUMMARY
// ============================================
$current_month = (int)date('m');
$current_year = (int)date('Y');

$total_tagihan_bulan_ini = 0;
$total_terbayar_bulan_ini = 0;
$total_outstanding_bulan_ini = 0;
$total_siswa_bulan_ini = 0;
$total_sudah_bayar = 0;
$total_belum_bayar = 0;

$query_monthly = "SELECT * FROM v_infaq_ringkasan_bulanan
                  WHERE bulan = ? AND tahun = ?";
$stmt_monthly = $conn->prepare($query_monthly);
$stmt_monthly->bind_param("ii", $current_month, $current_year);
$stmt_monthly->execute();
$result_monthly = $stmt_monthly->get_result();
if ($monthly_summary = $result_monthly->fetch_assoc()) {
    $total_siswa_bulan_ini = $monthly_summary['total_siswa'] ?? 0;
    $total_sudah_bayar = $monthly_summary['total_sudah_bayar'] ?? 0;
    $total_belum_bayar = $monthly_summary['total_belum_bayar'] ?? 0;
    $total_tagihan_bulan_ini = $monthly_summary['total_nominal_tagihan'] ?? 0;
    $total_terbayar_bulan_ini = $monthly_summary['total_terbayar'] ?? 0;
    $total_outstanding_bulan_ini = $monthly_summary['total_outstanding'] ?? 0;
}

// Calculate percentages
$persentase_pembayaran = $total_siswa_bulan_ini > 0 ? 
    round(($total_sudah_bayar / $total_siswa_bulan_ini) * 100) : 0;
$persentase_nominal = $total_tagihan_bulan_ini > 0 ? 
    round(($total_terbayar_bulan_ini / $total_tagihan_bulan_ini) * 100) : 0;

// ============================================
// TRANSACTION DATA (TODAY)
// ============================================
$total_pembayaran_hari_ini = 0;
$nominal_masuk_hari_ini = 0;

$query_today_payments = "SELECT COUNT(ip.id) as total_pembayaran,
                         COALESCE(SUM(ip.nominal_bayar), 0) as nominal_masuk
                         FROM infaq_pembayaran ip
                         WHERE DATE(ip.tanggal_bayar) = CURDATE()";
$stmt_today = $conn->prepare($query_today_payments);
$stmt_today->execute();
$result_today = $stmt_today->get_result();
if ($today_data = $result_today->fetch_assoc()) {
    $total_pembayaran_hari_ini = $today_data['total_pembayaran'] ?? 0;
    $nominal_masuk_hari_ini = $today_data['nominal_masuk'] ?? 0;
}

// ============================================
// CHART DATA - WEEKLY PAYMENTS
// ============================================
$chart_labels = [];
$chart_pembayaran = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d M', strtotime($date));
    
    $query_daily = "SELECT COALESCE(SUM(ip.nominal_bayar), 0) as pembayaran
                    FROM infaq_pembayaran ip
                    WHERE DATE(ip.tanggal_bayar) = ?";
    
    $stmt_daily = $conn->prepare($query_daily);
    $stmt_daily->bind_param("s", $date);
    $stmt_daily->execute();
    $result_daily = $stmt_daily->get_result();
    
    if ($result_daily && $row = $result_daily->fetch_assoc()) {
        $chart_pembayaran[] = (float)$row['pembayaran'];
    } else {
        $chart_pembayaran[] = 0;
    }
}

// ============================================
// DATE FORMATTING (PHP 8.1+ Compatible)
// ============================================
// Array nama bulan Indonesia
$bulan_indonesia = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];

// Format tanggal untuk display
$bulan_tahun = $bulan_indonesia[(int)date('n')] . ' ' . date('Y');
$hari_ini = date('d') . ' ' . $bulan_indonesia[(int)date('n')] . ' ' . date('Y');

// BASE URL
$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts  = explode('/', trim(dirname($script_name), '/'));
$base_path   = in_array('schobank', $path_parts) ? '/schobank' : '';
$base_url    = $protocol . '://' . $host . $base_path;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <title>Dashboard Bendahara | SchoBank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius-sm: 6px;
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 3rem;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html { font-size:16px; }
        body {
            font-family: var(--font-sans);
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
        }
        /* NAVBAR */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }
        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 var(--space-lg);
            display:flex;
            align-items:center;
            justify-content:space-between;
            height:64px;
        }
        .navbar-brand {
            font-size:1.25rem;
            font-weight:700;
            color:var(--primary);
            text-decoration:none;
            letter-spacing:.02em;
        }
        .navbar-toggle{
            display:none;
            background:none;
            border:none;
            font-size:1.5rem;
            color:var(--gray-700);
            cursor:pointer;
            padding:var(--space-xs);
        }
        .navbar-menu{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            list-style:none;
        }
        .navbar-menu a{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            padding:var(--space-xs) var(--space-md);
            color:var(--gray-600);
            text-decoration:none;
            font-size:0.9rem;
            font-weight:500;
            border-radius:var(--radius);
            transition:color .2s ease;
        }
        .navbar-menu a i{
            font-size:0.95rem;
        }
        .navbar-menu a:hover,
        .navbar-menu a.active{
            color:var(--primary);
        }
        .navbar-user{
            display:flex;
            align-items:center;
            gap:var(--space-md);
            position: relative;
        }
        .user-info{
            display:flex;
            align-items:center;
            gap:var(--space-sm);
            cursor: pointer;
            padding: var(--space-xs);
            border-radius: var(--radius);
            transition: background 0.2s ease;
        }
        .user-info:hover {
            background: var(--gray-100);
        }
        .user-avatar{
            width:36px;
            height:36px;
            border-radius:50%;
            background:var(--primary);
            display:flex;
            align-items:center;
            justify-content:center;
            color:#fff;
            font-weight:600;
            font-size:0.9rem;
        }
        .user-details{
            display:flex;
            flex-direction:column;
        }
        .user-name{
            font-size:0.875rem;
            font-weight:600;
            color:var(--gray-900);
        }
        .user-role{
            font-size:0.75rem;
            color:var(--gray-500);
        }

        /* Profile Dropdown Menu */
        .profile-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            min-width: 220px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            z-index: 1001;
        }
        .profile-dropdown.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        .dropdown-header {
            padding: var(--space-md);
            border-bottom: 1px solid var(--gray-200);
        }
        .dropdown-header-name {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 0.25rem;
        }
        .dropdown-header-role {
            font-size: 0.8rem;
            color: var(--gray-500);
        }
        .dropdown-menu-items {
            padding: var(--space-xs);
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-sm) var(--space-md);
            color: var(--gray-700);
            text-decoration: none;
            border-radius: var(--radius);
            transition: all 0.2s ease;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .dropdown-item:hover {
            background: var(--gray-100);
            color: var(--primary);
        }
        .dropdown-item i {
            font-size: 1rem;
            width: 20px;
            text-align: center;
        }
        .dropdown-item.logout {
            color: var(--danger);
            border-top: 1px solid var(--gray-200);
            margin-top: var(--space-xs);
        }
        .dropdown-item.logout:hover {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* MAIN */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: var(--space-xl) var(--space-lg);
        }

        .page-header {
            margin-bottom: var(--space-xl);
        }

        .page-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: var(--space-xs);
        }

        .page-subtitle {
            font-size: 0.95rem;
            color: var(--gray-600);
        }

        /* Stat Card */
        .stat-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-4px);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
        }

        .stat-card.success .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .stat-card.warning .stat-icon {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .stat-card.danger .stat-icon {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .stat-card.info .stat-icon {
            background: rgba(6, 182, 212, 0.1);
            color: #06b6d4;
        }

        .stat-label {
            font-size: 0.85rem;
            font-weight: 500;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0.5rem;
        }

        .stat-desc {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        /* Progress Bar */
        .progress-wrapper {
            margin-top: 1rem;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .progress {
            height: 8px;
            border-radius: 10px;
            background: var(--gray-200);
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, #2563eb, #1d4ed8);
            transition: width 0.3s ease;
            height: 100%;
        }

        .progress-bar.success {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        /* Section Card */
        .section-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: var(--space-xl);
            transition: all 0.3s ease;
        }

        .section-card:hover {
            box-shadow: var(--shadow-md);
        }

        .section-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 1.25rem var(--space-lg);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .section-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .section-header.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .section-header.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        .section-body {
            padding: var(--space-lg);
        }

        .stat-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-lg);
            margin-bottom: 0;
        }

        .stat-item {
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .stat-item:last-child {
            padding-bottom: 0;
            border-bottom: none;
        }

        .stat-item-label {
            font-size: 0.85rem;
            color: var(--gray-600);
            font-weight: 500;
            margin-bottom: 0.25rem;
        }

        .stat-item-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
        }

        /* Chart Card */
        .chart-card {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-xl);
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: var(--space-lg);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .navbar-menu {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .navbar-container {
                padding: 0 var(--space-md);
            }

            .navbar-toggle {
                display: block;
            }

            .navbar-menu {
                position: fixed;
                top: 64px;
                left: 0;
                right: 0;
                background: #fff;
                flex-direction: column;
                padding: var(--space-md);
                box-shadow: var(--shadow-lg);
                border-top: 1px solid var(--gray-200);
                max-height: 0;
                overflow: hidden;
                transition: max-height .3s ease;
            }

            .navbar-menu.active {
                display: flex;
                max-height: 400px;
            }

            .navbar-menu a {
                width: 100%;
                padding: var(--space-md);
            }

            .user-details {
                display: none;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .stat-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .main-container {
                padding: var(--space-lg) var(--space-md);
            }

            .stat-card {
                padding: 1rem;
            }

            .section-body {
                padding: 1rem;
            }

            .profile-dropdown {
                right: -10px;
                min-width: 200px;
            }
        }

        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stat-card {
            animation: fadeIn 0.3s ease;
        }

        .section-card {
            animation: fadeIn 0.4s ease;
        }

        .chart-card {
            animation: fadeIn 0.5s ease;
        }
    </style>
</head>
<body>
    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="navbar-brand">SchoBank</a>
            <button class="navbar-toggle" id="navbarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="navbar-menu" id="navbarMenu">
                <li><a href="dashboard.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="infaq_nominal_setting.php"><i class="fas fa-cog"></i> Set Nominal Infaq</a></li>
                <li><a href="infaq_siswa_status.php"><i class="fas fa-users"></i> Status Infaq Siswa</a></li>
                <li><a href="infaq_generate_tagihan.php"><i class="fas fa-plus-circle"></i> Buat Tagihan Infaq</a></li>
                <li><a href="infaq_laporan.php"><i class="fas fa-chart-line"></i> Laporan</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-info" id="userProfileBtn">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($nama_bendahara, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                        <div class="user-role">Bendahara</div>
                    </div>
                </div>
                
                <!-- Profile Dropdown Menu -->
                <div class="profile-dropdown" id="profileDropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-header-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                        <div class="dropdown-header-role">Bendahara</div>
                    </div>
                    <div class="dropdown-menu-items">
                        <a href="pengaturan.php" class="dropdown-item">
                            <i class="fas fa-wrench"></i>
                            <span>Pengaturan</span>
                        </a>
                        <div class="dropdown-item logout" onclick="logout()">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Keluar</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-container">
        <div class="container-lg">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-chart-line"></i> Dashboard Bendahara</h1>
                <p class="page-subtitle">Selamat datang! Pantau ringkasan infaq dan transaksi keuangan sekolah Anda</p>
            </div>

            <!-- Top Stats Row -->
            <div class="row mb-4 g-3" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <!-- Total Pembayaran Hari Ini -->
                <div>
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-cash-register"></i>
                        </div>
                        <div class="stat-label">Pembayaran Hari Ini</div>
                        <div class="stat-value">Rp <?php echo number_format($nominal_masuk_hari_ini, 0, ',', '.'); ?></div>
                        <div class="stat-desc"><?php echo $total_pembayaran_hari_ini; ?> transaksi</div>
                    </div>
                </div>

                <!-- Saldo Rekening -->
                <div>
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="stat-label">Saldo Rekening</div>
                        <div class="stat-value">Rp <?php echo number_format($saldo_bendahara, 0, ',', '.'); ?></div>
                        <div class="stat-desc"><?php echo htmlspecialchars($no_rekening_bendahara); ?></div>
                    </div>
                </div>

                <!-- Total Terbayar Bulan Ini -->
                <div>
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-label">Terbayar Bulan Ini</div>
                        <div class="stat-value">Rp <?php echo number_format($total_terbayar_bulan_ini, 0, ',', '.'); ?></div>
                        <div class="stat-desc"><?php echo $total_sudah_bayar; ?> dari <?php echo $total_siswa_bulan_ini; ?> siswa</div>
                    </div>
                </div>

                <!-- Outstanding -->
                <div>
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <div class="stat-label">Belum Terbayar</div>
                        <div class="stat-value">Rp <?php echo number_format($total_outstanding_bulan_ini, 0, ',', '.'); ?></div>
                        <div class="stat-desc"><?php echo $total_belum_bayar; ?> siswa</div>
                    </div>
                </div>
            </div>

            <!-- Account & Config Section -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
                <!-- Informasi Rekening -->
                <div class="section-card">
                    <div class="section-header">
                        <i class="fas fa-credit-card"></i>
                        <h5>Informasi Rekening Bendahara</h5>
                    </div>
                    <div class="section-body">
                        <div class="stat-row">
                            <div class="stat-item">
                                <div class="stat-item-label">Nomor Rekening</div>
                                <div class="stat-item-value"><?php echo htmlspecialchars($no_rekening_bendahara); ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-item-label">Saldo Saat Ini</div>
                                <div class="stat-item-value">Rp <?php echo number_format($saldo_bendahara, 0, ',', '.'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Konfigurasi Infaq -->
                <div class="section-card">
                    <div class="section-header success">
                        <i class="fas fa-gear"></i>
                        <h5>Konfigurasi Infaq</h5>
                    </div>
                    <div class="section-body">
                        <div class="stat-row">
                            <div class="stat-item">
                                <div class="stat-item-label">Nominal Default per Siswa</div>
                                <div class="stat-item-value">Rp <?php echo number_format($nominal_default, 0, ',', '.'); ?></div>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1rem;">
                            <div class="stat-item">
                                <div class="stat-item-label">Wajib Bayar</div>
                                <div class="stat-item-value" style="color: #2563eb;"><?php echo $total_siswa_wajib; ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-item-label">Gratis</div>
                                <div class="stat-item-value" style="color: #10b981;"><?php echo $total_siswa_gratis; ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-item-label">Disesuaikan</div>
                                <div class="stat-item-value" style="color: #f59e0b;"><?php echo $total_siswa_disesuaikan; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Progress Section -->
            <div class="section-card">
                <div class="section-header warning">
                    <i class="fas fa-chart-bar"></i>
                    <h5>Ringkasan Pembayaran Bulan Ini (<?php echo $bulan_tahun; ?>)</h5>
                </div>
                <div class="section-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem;">
                        <!-- Progress Siswa -->
                        <div>
                            <h6 style="font-weight: 600; color: var(--gray-900); margin-bottom: 1rem;">
                                <i class="fas fa-users"></i> Progress Pembayaran Siswa
                            </h6>
                            <div class="progress-wrapper">
                                <div class="progress-label">
                                    <span>Sudah Bayar</span>
                                    <span><?php echo $persentase_pembayaran; ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar success" style="width: <?php echo $persentase_pembayaran; ?>%"></div>
                                </div>
                                <small style="color: var(--gray-500); margin-top: 0.5rem; display: block;">
                                    <?php echo $total_sudah_bayar; ?> dari <?php echo $total_siswa_bulan_ini; ?> siswa telah membayar
                                </small>
                            </div>

                            <!-- Additional Stats -->
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                    <div>
                                        <div class="stat-item-label">Total Ditagih</div>
                                        <div class="stat-item-value" style="font-size: 1.1rem;">
                                            Rp <?php echo number_format($total_tagihan_bulan_ini, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="stat-item-label">Belum Bayar</div>
                                        <div class="stat-item-value" style="font-size: 1.1rem; color: #ef4444;">
                                            Rp <?php echo number_format($total_outstanding_bulan_ini, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Progress Nominal -->
                        <div>
                            <h6 style="font-weight: 600; color: var(--gray-900); margin-bottom: 1rem;">
                                <i class="fas fa-money-bill-wave"></i> Progress Nominal
                            </h6>
                            <div class="progress-wrapper">
                                <div class="progress-label">
                                    <span>Terkumpul</span>
                                    <span><?php echo $persentase_nominal; ?>%</span>
                                </div>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $persentase_nominal; ?>%"></div>
                                </div>
                                <small style="color: var(--gray-500); margin-top: 0.5rem; display: block;">
                                    Rp <?php echo number_format($total_terbayar_bulan_ini, 0, ',', '.'); ?> dari 
                                    Rp <?php echo number_format($total_tagihan_bulan_ini, 0, ',', '.'); ?>
                                </small>
                            </div>

                            <!-- Completion Stats -->
                            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--gray-200);">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                    <div>
                                        <div class="stat-item-label">Target Bulan Ini</div>
                                        <div class="stat-item-value" style="font-size: 1.1rem;">
                                            Rp <?php echo number_format($total_tagihan_bulan_ini, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="stat-item-label">Sisa Target</div>
                                        <div class="stat-item-value" style="font-size: 1.1rem; color: #f59e0b;">
                                            Rp <?php echo number_format($total_outstanding_bulan_ini, 0, ',', '.'); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="chart-card">
                <div class="chart-title">
                    <i class="fas fa-chart-area"></i>
                    Grafik Pembayaran Infaq Mingguan (7 Hari Terakhir)
                </div>
                <canvas id="infaqChart" style="height: 300px; max-height: 400px;"></canvas>
            </div>
        </div>
    </div>

    <script>
        // Navbar Toggle
        $(function() {
            $('#navbarToggle').on('click', function() {
                $('#navbarMenu').toggleClass('active');
                $(this).find('i').toggleClass('fa-bars fa-times');
            });
            
            // Close mobile menu when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.navbar-container').length) {
                    $('#navbarMenu').removeClass('active');
                    $('#navbarToggle i').removeClass('fa-times').addClass('fa-bars');
                }
            });
        });

        // Profile Dropdown Toggle
        $(function() {
            const userProfileBtn = $('#userProfileBtn');
            const profileDropdown = $('#profileDropdown');

            // Toggle dropdown when clicking profile button
            userProfileBtn.on('click', function(e) {
                e.stopPropagation();
                $('.profile-dropdown').removeClass('active');
                userProfileBtn.removeClass('active');
                
                $(this).toggleClass('active');
                profileDropdown.toggleClass('active');
            });

            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.navbar-user').length) {
                    userProfileBtn.removeClass('active');
                    profileDropdown.removeClass('active');
                }
            });

            // Prevent dropdown from closing when clicking inside it
            profileDropdown.on('click', function(e) {
                e.stopPropagation();
            });
        });

        // Logout Function
        function logout() {
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Yakin ingin keluar?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Keluar',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }

        // Chart Configuration
        const ctx = document.getElementById('infaqChart').getContext('2d');
        const infaqChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels); ?>,
                datasets: [{
                    label: 'Pembayaran Infaq (Rp)',
                    data: <?php echo json_encode($chart_pembayaran); ?>,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.05)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#1d4ed8'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            font: { size: 13, weight: '500' },
                            color: '#64748b',
                            usePointStyle: true,
                            padding: 15
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#e2e8f0',
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 12 },
                            color: '#64748b',
                            callback: function(value) {
                                return 'Rp ' + new Intl.NumberFormat('id-ID').format(value);
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            font: { size: 12 },
                            color: '#64748b'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>
