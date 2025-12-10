<?php
/**
 * Bendahara Infaq Siswa Status Setting - Adaptive Path Version (Normalized DB)
 * File: pages/bendahara/infaq_siswa_status.php
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
// HANDLE FORM SUBMISSION (SINGLE UPDATE)
// ============================================
$message = '';
$message_type = ''; // success, error

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['siswa_id'])) {
    $siswa_id = filter_input(INPUT_POST, 'siswa_id', FILTER_VALIDATE_INT);
    $status_bayar = filter_input(INPUT_POST, 'status_bayar', FILTER_DEFAULT);
    $nominal_custom = filter_input(INPUT_POST, 'nominal_custom', FILTER_VALIDATE_FLOAT);
    $keterangan = trim(htmlspecialchars(filter_input(INPUT_POST, 'keterangan', FILTER_DEFAULT), ENT_QUOTES, 'UTF-8'));

    if ($siswa_id && in_array($status_bayar, ['bayar', 'gratis', 'disesuaikan'])) {
        if ($status_bayar === 'disesuaikan' && ($nominal_custom === false || $nominal_custom < 0)) {
            $message = 'Nominal custom harus berupa angka non-negatif.';
            $message_type = 'error';
        } else {
            if ($status_bayar !== 'disesuaikan') {
                $nominal_custom = null;
            }

            // Cek jika sudah ada record
            $query_check = "SELECT id FROM infaq_siswa_status WHERE siswa_id = ?";
            $stmt_check = $conn->prepare($query_check);
            $stmt_check->bind_param("i", $siswa_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                // Update existing
                $query_update = "UPDATE infaq_siswa_status SET status_bayar = ?, nominal_custom = ?, keterangan = ? WHERE siswa_id = ?";
                $stmt_update = $conn->prepare($query_update);
                $stmt_update->bind_param("sdsi", $status_bayar, $nominal_custom, $keterangan, $siswa_id);
                if ($stmt_update->execute()) {
                    $message = 'Status siswa berhasil diperbarui.';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal memperbarui status siswa: ' . $conn->error;
                    $message_type = 'error';
                }
            } else {
                // Insert new (meskipun trigger seharusnya sudah buat, tapi untuk safety)
                $query_insert = "INSERT INTO infaq_siswa_status (siswa_id, status_bayar, nominal_custom, keterangan) VALUES (?, ?, ?, ?)";
                $stmt_insert = $conn->prepare($query_insert);
                $stmt_insert->bind_param("isds", $siswa_id, $status_bayar, $nominal_custom, $keterangan);
                if ($stmt_insert->execute()) {
                    $message = 'Status siswa berhasil diset.';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal menyet status siswa: ' . $conn->error;
                    $message_type = 'error';
                }
            }
        }
    } else {
        $message = 'Data tidak valid.';
        $message_type = 'error';
    }
}

// ============================================
// HANDLE BULK UPDATE TO 'BAYAR'
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_set_bayar'])) {
    $query_bulk = "UPDATE infaq_siswa_status SET status_bayar = 'bayar', nominal_custom = NULL, keterangan = 'Reset ke status bayar secara massal'";
    if ($conn->query($query_bulk)) {
        $message = 'Semua status siswa berhasil direset ke "Bayar".';
        $message_type = 'success';
    } else {
        $message = 'Gagal mereset semua status: ' . $conn->error;
        $message_type = 'error';
    }
}

// ============================================
// GET SISWA LIST WITH MANUAL QUERY (GABUNGAN TINGKATAN DAN KELAS)
// ============================================
$siswa_list = [];
$query_siswa = "
    SELECT 
        u.id AS siswa_id,
        u.nama AS nama_siswa,
        sp.nis_nisn,
        CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas_gabungan,
        j.nama_jurusan,
        COALESCE(iss.status_bayar, 'bayar') AS status_bayar,
        iss.nominal_custom,
        iss.keterangan,
        CASE
            WHEN COALESCE(iss.status_bayar, 'bayar') = 'bayar' THEN inc.nominal_per_siswa
            WHEN COALESCE(iss.status_bayar, 'bayar') = 'gratis' THEN 0
            WHEN COALESCE(iss.status_bayar, 'bayar') = 'disesuaikan' THEN iss.nominal_custom
        END AS nominal_akan_ditagih,
        r.no_rekening,
        r.saldo
    FROM users u
    JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN kelas k ON sp.kelas_id = k.id
    LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
    LEFT JOIN jurusan j ON sp.jurusan_id = j.id
    LEFT JOIN infaq_siswa_status iss ON u.id = iss.siswa_id
    LEFT JOIN rekening r ON u.id = r.user_id
    CROSS JOIN infaq_nominal_config inc
    WHERE u.role = 'siswa'
    ORDER BY u.nama
";
$stmt_siswa = $conn->prepare($query_siswa);
$stmt_siswa->execute();
$result_siswa = $stmt_siswa->get_result();
while ($row = $result_siswa->fetch_assoc()) {
    $siswa_list[] = $row;
}

// ============================================
// GET KELAS LIST (GABUNGAN TINGKATAN DAN KELAS)
// ============================================
$kelas_list = [];
$query_kelas = "SELECT DISTINCT CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas_gabungan 
               FROM kelas k 
               LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
               ORDER BY kelas_gabungan";
$stmt_kelas = $conn->prepare($query_kelas);
$stmt_kelas->execute();
$result_kelas = $stmt_kelas->get_result();
while ($row = $result_kelas->fetch_assoc()) {
    $kelas_list[] = $row['kelas_gabungan'];
}

// ============================================
// GET JURUSAN LIST
// ============================================
$jurusan_list = [];
$query_jurusan = "SELECT DISTINCT nama_jurusan FROM jurusan ORDER BY nama_jurusan";
$stmt_jurusan = $conn->prepare($query_jurusan);
$stmt_jurusan->execute();
$result_jurusan = $stmt_jurusan->get_result();
while ($row = $result_jurusan->fetch_assoc()) {
    $jurusan_list[] = $row['nama_jurusan'];
}

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
    <title>Setting Status Infaq Siswa | SchoBank</title>
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

        /* Filter Section */
        .filter-section {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: var(--space-lg);
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-sm);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--space-md);
            margin-bottom: var(--space-md);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: var(--space-xs);
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--gray-700);
        }

        .filter-input {
            padding: var(--space-sm) var(--space-md);
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            color: var(--gray-900);
            transition: all 0.2s ease;
            width: 100%;
        }

        .filter-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .filter-actions {
            display: flex;
            gap: var(--space-sm);
            flex-wrap: wrap;
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

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d97706;
        }

        .btn-primary {
            background: var(--primary);
            color: #fff;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #dc2626;
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

        .table-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: var(--space-lg);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .table-header h5 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1200px;
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

        .form-select {
            width: 100%;
            padding: 0.4rem 0.65rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.75rem;
            color: var(--gray-900);
            background: white;
            transition: all 0.2s ease;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .form-control {
            width: 100%;
            padding: 0.4rem 0.65rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.75rem;
            color: var(--gray-900);
            transition: all 0.2s ease;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 32px;
        }

        .btn-sm {
            padding: 0.4rem 0.75rem;
            font-size: 0.75rem;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.45rem;
            border-radius: var(--radius-sm);
            font-size: 0.65rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-bayar-badge {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }

        .status-gratis-badge {
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }

        .status-disesuaikan-badge {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }

        .d-flex {
            display: flex;
        }

        .align-items-center {
            align-items: center;
        }

        .gap-2 {
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

            .main-container {
                padding: var(--space-lg) var(--space-md);
            }

            .filter-section {
                padding: 1rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
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

        .filter-section,
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
                <li><a href="infaq_siswa_status.php" class="active"><i class="fas fa-users"></i> Status Infaq Siswa</a></li>
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
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-users"></i> Setting Status Infaq Siswa</h1>
            <p class="page-subtitle">Atur status pembayaran infaq per siswa (bayar/gratis/disesuaikan)</p>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-grid">
                <div class="filter-group">
                    <label class="filter-label">Cari Nama atau NIS/NISN</label>
                    <input type="text" id="searchInput" class="filter-input" placeholder="Masukkan kata kunci...">
                </div>
                <div class="filter-group">
                    <label class="filter-label">Filter Kelas</label>
                    <select id="filterKelas" class="filter-input">
                        <option value="">Semua Kelas</option>
                        <?php foreach ($kelas_list as $kelas): ?>
                            <option value="<?php echo htmlspecialchars($kelas); ?>"><?php echo htmlspecialchars($kelas); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Filter Jurusan</label>
                    <select id="filterJurusan" class="filter-input">
                        <option value="">Semua Jurusan</option>
                        <?php foreach ($jurusan_list as $jurusan): ?>
                            <option value="<?php echo htmlspecialchars($jurusan); ?>"><?php echo htmlspecialchars($jurusan); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Filter Status</label>
                    <select id="filterStatus" class="filter-input">
                        <option value="">Semua Status</option>
                        <option value="bayar">Bayar</option>
                        <option value="gratis">Gratis</option>
                        <option value="disesuaikan">Disesuaikan</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button id="resetFilter" class="btn btn-secondary">
                    <i class="fas fa-redo"></i> Reset Filter
                </button>
                <button id="setAllBayar" class="btn btn-warning">
                    <i class="fas fa-check-circle"></i> Set Semua ke Bayar
                </button>
                <button id="updateAll" class="btn btn-primary">
                    <i class="fas fa-save"></i> Simpan Semua Perubahan
                </button>
            </div>
        </div>

        <!-- Table Section -->
        <div class="table-section">
            <div class="table-header">
                <i class="fas fa-list"></i>
                <h5>Daftar Siswa</h5>
            </div>
            <div class="table-responsive">
                <table class="table" id="siswaTable">
                    <thead>
                        <tr>
                            <th>Nama Siswa</th>
                            <th>NIS/NISN</th>
                            <th>Kelas</th>
                            <th>Jurusan</th>
                            <th>Status Bayar</th>
                            <th>Nominal Custom</th>
                            <th>Keterangan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($siswa_list as $siswa): ?>
                        <?php 
                            // Tentukan badge class berdasarkan status
                            $status_class = '';
                            if ($siswa['status_bayar'] === 'bayar') {
                                $status_class = 'status-bayar-badge';
                            } elseif ($siswa['status_bayar'] === 'gratis') {
                                $status_class = 'status-gratis-badge';
                            } elseif ($siswa['status_bayar'] === 'disesuaikan') {
                                $status_class = 'status-disesuaikan-badge';
                            }
                        ?>
                        <tr data-siswa-id="<?php echo $siswa['siswa_id']; ?>" 
                            data-kelas="<?php echo htmlspecialchars($siswa['kelas_gabungan'] ?? ''); ?>"
                            data-jurusan="<?php echo htmlspecialchars($siswa['nama_jurusan'] ?? ''); ?>"
                            data-status="<?php echo htmlspecialchars($siswa['status_bayar']); ?>">
                            <td><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                            <td><?php echo htmlspecialchars($siswa['nis_nisn'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($siswa['kelas_gabungan'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($siswa['nama_jurusan'] ?? '-'); ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo htmlspecialchars($siswa['status_bayar']); ?>
                                    </span>
                                    <select class="form-select status-bayar" name="status_bayar" style="min-width: 110px;">
                                        <option value="bayar" <?php echo $siswa['status_bayar'] === 'bayar' ? 'selected' : ''; ?>>Bayar</option>
                                        <option value="gratis" <?php echo $siswa['status_bayar'] === 'gratis' ? 'selected' : ''; ?>>Gratis</option>
                                        <option value="disesuaikan" <?php echo $siswa['status_bayar'] === 'disesuaikan' ? 'selected' : ''; ?>>Disesuaikan</option>
                                    </select>
                                </div>
                            </td>
                            <td>
                                <input type="number" class="form-control nominal-custom" name="nominal_custom" 
                                       value="<?php echo htmlspecialchars($siswa['nominal_custom'] ?? ''); ?>" 
                                       step="0.01" min="0" placeholder="0"
                                       style="display: <?php echo $siswa['status_bayar'] === 'disesuaikan' ? 'block' : 'none'; ?>; min-width: 100px;">
                            </td>
                            <td>
                                <textarea class="form-control keterangan" name="keterangan" rows="1" placeholder="Keterangan..."><?php echo htmlspecialchars($siswa['keterangan'] ?? ''); ?></textarea>
                            </td>
                            <td>
                                <button class="btn btn-primary btn-sm save-status">
                                    <i class="fas fa-save"></i> Simpan
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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

        $(document).ready(function() {
            function applyFilters() {
                var searchValue = $('#searchInput').val().toLowerCase();
                var kelasValue = $('#filterKelas').val().toLowerCase();
                var jurusanValue = $('#filterJurusan').val().toLowerCase();
                var statusValue = $('#filterStatus').val().toLowerCase();

                $('#siswaTable tbody tr').filter(function() {
                    var textMatch = $(this).text().toLowerCase().indexOf(searchValue) > -1;
                    var kelasMatch = kelasValue === '' || $(this).data('kelas').toLowerCase() === kelasValue;
                    var jurusanMatch = jurusanValue === '' || $(this).data('jurusan').toLowerCase() === jurusanValue;
                    var statusMatch = statusValue === '' || $(this).data('status').toLowerCase() === statusValue;
                    
                    $(this).toggle(textMatch && kelasMatch && jurusanMatch && statusMatch);
                });
            }

            // Search and filter events
            $('#searchInput').on('keyup', applyFilters);
            $('#filterKelas').on('change', applyFilters);
            $('#filterJurusan').on('change', applyFilters);
            $('#filterStatus').on('change', applyFilters);

            // Reset button
            $('#resetFilter').on('click', function() {
                $('#searchInput').val('');
                $('#filterKelas').val('');
                $('#filterJurusan').val('');
                $('#filterStatus').val('');
                applyFilters();
            });

            // Show/hide nominal custom based on status
            $(document).on('change', '.status-bayar', function() {
                var row = $(this).closest('tr');
                var nominalInput = row.find('.nominal-custom');
                var statusBadge = row.find('.status-badge');
                
                if ($(this).val() === 'disesuaikan') {
                    nominalInput.show();
                    statusBadge.removeClass('status-bayar-badge status-gratis-badge status-disesuaikan-badge')
                               .addClass('status-disesuaikan-badge')
                               .text('disesuaikan');
                } else if ($(this).val() === 'gratis') {
                    nominalInput.hide().val('');
                    statusBadge.removeClass('status-bayar-badge status-gratis-badge status-disesuaikan-badge')
                               .addClass('status-gratis-badge')
                               .text('gratis');
                } else {
                    nominalInput.hide().val('');
                    statusBadge.removeClass('status-bayar-badge status-gratis-badge status-disesuaikan-badge')
                               .addClass('status-bayar-badge')
                               .text('bayar');
                }
                
                // Update data-status attribute for filtering
                row.data('status', $(this).val());
            });

            // Save button click (single row)
            $(document).on('click', '.save-status', function() {
                var row = $(this).closest('tr');
                var siswa_id = row.data('siswa-id');
                var status_bayar = row.find('.status-bayar').val();
                var nominal_custom = row.find('.nominal-custom').val();
                var keterangan = row.find('.keterangan').val();

                // Validasi jika status disesuaikan tapi nominal kosong
                if (status_bayar === 'disesuaikan' && (!nominal_custom || nominal_custom <= 0)) {
                    Swal.fire({
                        title: 'Perhatian!',
                        text: 'Untuk status "Disesuaikan", nominal custom harus diisi dengan angka positif.',
                        icon: 'warning',
                        confirmButtonColor: '#f59e0b'
                    });
                    return;
                }

                $.ajax({
                    type: 'POST',
                    url: window.location.href,
                    data: {
                        siswa_id: siswa_id,
                        status_bayar: status_bayar,
                        nominal_custom: nominal_custom,
                        keterangan: keterangan
                    },
                    success: function(response) {
                        // Reload page to show message and updated data
                        location.reload();
                    },
                    error: function() {
                        Swal.fire({
                            title: 'Error!',
                            text: 'Gagal menyimpan data.',
                            icon: 'error',
                            confirmButtonColor: '#ef4444'
                        });
                    }
                });
            });

            // Set All to Bayar button
            $('#setAllBayar').on('click', function() {
                Swal.fire({
                    title: 'Konfirmasi',
                    text: 'Apakah Anda yakin ingin mereset semua status siswa menjadi "Bayar"? Ini akan menghapus nominal custom dan keterangan.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Reset Semua',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#f59e0b',
                    cancelButtonColor: '#64748b'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.ajax({
                            type: 'POST',
                            url: window.location.href,
                            data: {
                                bulk_set_bayar: true
                            },
                            success: function(response) {
                                // Reload page to show message and updated data
                                location.reload();
                            },
                            error: function() {
                                Swal.fire({
                                    title: 'Error!',
                                    text: 'Gagal mereset semua status.',
                                    icon: 'error',
                                    confirmButtonColor: '#ef4444'
                                });
                            }
                        });
                    }
                });
            });

            // Update All button (bulk save changes for visible/edited rows)
            $('#updateAll').on('click', function() {
                // Validasi semua baris yang terlihat
                var hasError = false;
                var errorMessage = '';
                
                $('#siswaTable tbody tr:visible').each(function() {
                    var row = $(this);
                    var status_bayar = row.find('.status-bayar').val();
                    var nominal_custom = row.find('.nominal-custom').val();
                    
                    if (status_bayar === 'disesuaikan' && (!nominal_custom || nominal_custom <= 0)) {
                        hasError = true;
                        var namaSiswa = row.find('td:first').text().trim();
                        errorMessage = 'Siswa "' + namaSiswa + '" memiliki status "Disesuaikan" tetapi nominal custom kosong atau tidak valid.';
                        return false; // Keluar dari loop each
                    }
                });
                
                if (hasError) {
                    Swal.fire({
                        title: 'Validasi Error!',
                        text: errorMessage,
                        icon: 'error',
                        confirmButtonColor: '#ef4444'
                    });
                    return;
                }
                
                Swal.fire({
                    title: 'Konfirmasi',
                    text: 'Apakah Anda yakin ingin memperbarui semua perubahan pada siswa yang terlihat?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Update Semua',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#2563eb',
                    cancelButtonColor: '#64748b'
                }).then((result) => {
                    if (result.isConfirmed) {
                        var updates = [];
                        $('#siswaTable tbody tr:visible').each(function() {
                            var row = $(this);
                            var siswa_id = row.data('siswa-id');
                            var status_bayar = row.find('.status-bayar').val();
                            var nominal_custom = row.find('.nominal-custom').val();
                            var keterangan = row.find('.keterangan').val();
                            updates.push({
                                siswa_id: siswa_id,
                                status_bayar: status_bayar,
                                nominal_custom: nominal_custom,
                                keterangan: keterangan
                            });
                        });

                        // Kirim satu per satu untuk menghindari timeout
                        function sendUpdates(index) {
                            if (index >= updates.length) {
                                Swal.fire({
                                    title: 'Berhasil!',
                                    text: 'Semua data telah berhasil diperbarui.',
                                    icon: 'success',
                                    confirmButtonColor: '#10b981'
                                }).then(() => {
                                    location.reload();
                                });
                                return;
                            }
                            
                            var update = updates[index];
                            $.ajax({
                                type: 'POST',
                                url: window.location.href,
                                data: update,
                                success: function() {
                                    sendUpdates(index + 1);
                                },
                                error: function() {
                                    Swal.fire({
                                        title: 'Error!',
                                        text: 'Gagal memperbarui data untuk siswa ID: ' + update.siswa_id,
                                        icon: 'error',
                                        confirmButtonColor: '#ef4444'
                                    });
                                }
                            });
                        }
                        
                        // Mulai pengiriman
                        sendUpdates(0);
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
