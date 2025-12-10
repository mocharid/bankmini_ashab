<?php
/**
 * Bendahara Generate Tagihan Infaq Bulanan - Adaptive Path Version (Normalized DB)
 * File: pages/bendahara/infaq_generate_tagihan.php
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

// Fallback: Use current directory
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

// Pastikan role adalah bendahara
if ($_SESSION['role'] !== 'bendahara') {
    header("Location: /pages/login.php");
    exit;
}

// ============================================
// HANDLE FORM SUBMISSION (GENERATE TAGIHAN)
// ============================================
$message = '';
$message_type = ''; // success, error
$generate_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_tagihan'])) {
    $bulan = filter_input(INPUT_POST, 'bulan', FILTER_VALIDATE_INT);
    $tahun = filter_input(INPUT_POST, 'tahun', FILTER_VALIDATE_INT);

    if ($bulan && $tahun && $bulan >= 1 && $bulan <= 12 && $tahun >= 1900 && $tahun <= 9999) {
        // Panggil stored procedure
        $stmt = $conn->prepare("CALL sp_generate_tagihan_infaq(?, ?, ?)");
        $stmt->bind_param("iii", $bulan, $tahun, $bendahara_id);
        
        try {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $row = $result->fetch_assoc()) {
                $generate_result = $row;
                $message = 'Tagihan infaq bulanan berhasil digenerate.';
                $message_type = 'success';
            } else {
                $message = 'Gagal mendapatkan hasil generate.';
                $message_type = 'error';
            }
        } catch (mysqli_sql_exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        } finally {
            $stmt->close();
        }
    } else {
        $message = 'Bulan atau tahun tidak valid.';
        $message_type = 'error';
    }
}

// ============================================
// GET GENERATE LOG LIST
// ============================================
$log_list = [];
$query_log = "
    SELECT 
        igl.id,
        igl.bulan,
        igl.tahun,
        u.nama AS nama_bendahara,
        igl.total_siswa_wajib,
        igl.total_siswa_gratis,
        igl.total_siswa_disesuaikan,
        igl.total_tagihan,
        igl.total_nominal,
        igl.created_at
    FROM infaq_generate_log igl
    JOIN users u ON igl.bendahara_id = u.id
    ORDER BY igl.tahun DESC, igl.bulan DESC
";
$stmt_log = $conn->prepare($query_log);
$stmt_log->execute();
$result_log = $stmt_log->get_result();
while ($row = $result_log->fetch_assoc()) {
    $log_list[] = $row;
}

// BASE URL
$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts  = explode('/', trim(dirname($script_name), '/'));
$base_path   = in_array('schobank', $path_parts) ? '/schobank' : '';
$base_url    = $protocol . '://' . $host . $base_path;

// Nama bulan dalam Bahasa Indonesia
$bulan_indonesia = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <title>Generate Tagihan Infaq | SchoBank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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

        /* Card Section */
        .card-section {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-sm);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .card-header-custom.success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .card-header-custom.info {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .card-header-custom h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .card-body-custom {
            padding: var(--space-xl);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: var(--space-lg);
        }

        .form-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: var(--space-xs);
            display: block;
        }

        .form-control, .form-select {
            width: 100%;
            padding: var(--space-sm) var(--space-md);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--gray-900);
            transition: all 0.2s ease;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .btn {
            padding: var(--space-sm) var(--space-lg);
            border: none;
            border-radius: var(--radius);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-1px);
        }

        /* Result List */
        .result-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .result-item {
            padding: var(--space-md);
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .result-item:last-child {
            border-bottom: none;
        }

        .result-label {
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .result-value {
            font-weight: 600;
            color: var(--gray-900);
            font-size: 0.95rem;
        }

        /* Table Section */
        .table-section {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-sm);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .table th {
            background: var(--gray-100);
            padding: 0.65rem 0.75rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--gray-200);
        }

        .table td {
            padding: 0.65rem 0.75rem;
            border-bottom: 1px solid var(--gray-200);
            vertical-align: middle;
            font-size: 0.8rem;
        }

        .table tbody tr:hover {
            background: var(--gray-50);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .text-center {
            text-align: center;
        }

        /* Grid Layout */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: var(--space-xl);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .navbar-menu {
                display: none;
            }
            
            .grid-2 {
                grid-template-columns: 1fr;
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

            .main-container {
                padding: var(--space-lg) var(--space-md);
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

        .card-section,
        .table-section {
            animation: fadeIn 0.4s ease;
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
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="infaq_nominal_setting.php"><i class="fas fa-cog"></i> Set Nominal Infaq</a></li>
                <li><a href="infaq_siswa_status.php"><i class="fas fa-users"></i> Status Infaq Siswa</a></li>
                <li><a href="infaq_generate_tagihan.php" class="active"><i class="fas fa-plus-circle"></i> Buat Tagihan Infaq</a></li>
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
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-plus-circle"></i> Generate Tagihan Infaq Bulanan</h1>
            <p class="page-subtitle">Generate tagihan infaq bulanan untuk semua siswa berdasarkan status pembayaran mereka</p>
        </div>

        <!-- Form and Result Section -->
        <div class="grid-2">
            <!-- Form Generate Tagihan -->
            <div class="card-section">
                <div class="card-header-custom">
                    <i class="fas fa-calendar-plus"></i>
                    <h5>Form Generate Tagihan</h5>
                </div>
                <div class="card-body-custom">
                    <form method="POST" action="" id="formGenerate">
                        <div class="form-group">
                            <label for="bulan" class="form-label">Bulan</label>
                            <select class="form-select" id="bulan" name="bulan" required>
                                <option value="">Pilih Bulan</option>
                                <?php foreach ($bulan_indonesia as $num => $nama): ?>
                                    <option value="<?php echo $num; ?>" <?php echo (date('n') == $num) ? 'selected' : ''; ?>>
                                        <?php echo $nama; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tahun" class="form-label">Tahun</label>
                            <input type="number" class="form-control" id="tahun" name="tahun" value="<?php echo date('Y'); ?>" min="1900" max="9999" required>
                        </div>
                        <button type="submit" name="generate_tagihan" class="btn btn-success">
                            <i class="fas fa-bolt"></i> Generate Tagihan
                        </button>
                    </form>
                </div>
            </div>

            <!-- Result Section -->
            <?php if ($generate_result): ?>
            <div class="card-section">
                <div class="card-header-custom success">
                    <i class="fas fa-check-circle"></i>
                    <h5>Hasil Generate Terbaru</h5>
                </div>
                <div class="card-body-custom" style="padding: 0;">
                    <ul class="result-list">
                        <li class="result-item">
                            <span class="result-label">Total Siswa Wajib Bayar</span>
                            <span class="result-value"><?php echo $generate_result['siswa_wajib']; ?> siswa</span>
                        </li>
                        <li class="result-item">
                            <span class="result-label">Total Siswa Gratis</span>
                            <span class="result-value"><?php echo $generate_result['siswa_gratis']; ?> siswa</span>
                        </li>
                        <li class="result-item">
                            <span class="result-label">Total Siswa Disesuaikan</span>
                            <span class="result-value"><?php echo $generate_result['siswa_disesuaikan']; ?> siswa</span>
                        </li>
                        <li class="result-item">
                            <span class="result-label">Total Tagihan Dibuat</span>
                            <span class="result-value"><?php echo $generate_result['total_tagihan']; ?> tagihan</span>
                        </li>
                        <li class="result-item">
                            <span class="result-label">Total Nominal Tagihan</span>
                            <span class="result-value">Rp <?php echo number_format($generate_result['total_nominal'], 0, ',', '.'); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- History Table Section -->
        <div class="table-section">
            <div class="card-header-custom info">
                <i class="fas fa-history"></i>
                <h5>Riwayat Generate Tagihan</h5>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bulan</th>
                            <th>Tahun</th>
                            <th>Bendahara</th>
                            <th>Siswa Wajib</th>
                            <th>Siswa Gratis</th>
                            <th>Siswa Disesuaikan</th>
                            <th>Total Tagihan</th>
                            <th>Total Nominal</th>
                            <th>Waktu Generate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log_list)): ?>
                        <tr>
                            <td colspan="9" class="text-center">Belum ada riwayat generate tagihan.</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($log_list as $log): ?>
                        <tr>
                            <td><?php echo $bulan_indonesia[$log['bulan']]; ?></td>
                            <td><?php echo $log['tahun']; ?></td>
                            <td><?php echo htmlspecialchars($log['nama_bendahara']); ?></td>
                            <td><?php echo $log['total_siswa_wajib']; ?></td>
                            <td><?php echo $log['total_siswa_gratis']; ?></td>
                            <td><?php echo $log['total_siswa_disesuaikan']; ?></td>
                            <td><?php echo $log['total_tagihan']; ?></td>
                            <td>Rp <?php echo number_format($log['total_nominal'], 0, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
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

        // Form Submit with Confirmation
        $(document).ready(function() {
            $('#formGenerate').on('submit', function(e) {
                e.preventDefault();
                
                var bulan = $('#bulan option:selected').text();
                var tahun = $('#tahun').val();
                
                Swal.fire({
                    title: 'Konfirmasi Generate Tagihan',
                    html: `Anda akan generate tagihan infaq untuk:<br><strong>${bulan} ${tahun}</strong><br><br>Proses ini akan membuat tagihan untuk semua siswa sesuai status pembayaran mereka. Lanjutkan?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Generate',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#64748b'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading
                        Swal.fire({
                            title: 'Memproses...',
                            text: 'Sedang generate tagihan infaq',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        // Submit form
                        this.submit();
                    }
                });
            });

            // Show message if any from PHP
            <?php if ($message): ?>
            Swal.fire({
                title: '<?php echo $message_type === 'success' ? 'Berhasil!' : 'Error!'; ?>',
                text: '<?php echo htmlspecialchars($message); ?>',
                icon: '<?php echo $message_type; ?>',
                confirmButtonColor: '<?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>'
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
