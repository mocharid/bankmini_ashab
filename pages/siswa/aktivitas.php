<?php
/**
 * Aktivitas - Adaptive Path Version (Simplified)
 * File: pages/user/aktivitas.php
 *
 * Compatible with:
 * - Local: schobank/pages/user/aktivitas.php
 * - Hosting: public_html/pages/user/aktivitas.php
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: jika di folder 'pages' atau 'user'
if (basename($current_dir) === 'user') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
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

// Fallback: pakai current dir
if (!$project_root) {
    $project_root = $current_dir;
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
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// ============================================
// DETECT BASE URL FOR ASSETS
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));

// Deteksi base path (schobank atau public_html)
$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;

// ============================================
// SESSION VALIDATION
// ============================================
if (!isset($_SESSION['user_id'])) {
    header("Location: $base_url/login.php");
    exit();
}

// Pastikan user adalah siswa
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    header("Location: $base_url/unauthorized.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ============================================
// AMBIL DATA USER
// ============================================
$query_user = "SELECT 
    u.id, u.nama, u.role,
    COALESCE(us.is_frozen, 0) as is_frozen,
    COALESCE(r.saldo, 0) as saldo,
    r.id as rekening_id,
    r.no_rekening
FROM users u
LEFT JOIN user_security us ON u.id = us.user_id
LEFT JOIN rekening r ON u.id = r.user_id
WHERE u.id = ?";

$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

if (!$user_data) {
    header("Location: $base_url/login.php");
    exit();
}

$siswa_info = $user_data;
$rekening_id = $siswa_info['rekening_id'] ?? null;
$no_rekening = $siswa_info['no_rekening'] ?? 'N/A';

// ============================================
// FILTER PARAMETERS (Hanya jenis dan tanggal)
// ============================================
$filter_jenis = $_GET['jenis'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';

// ============================================
// QUERY TRANSAKSI
// ============================================
$mutasi_data = [];
$error_message = '';

if ($rekening_id) {
    $where_conditions = "WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)";
    $params = [$rekening_id, $rekening_id];
    $types = "ii";

    // Filter jenis transaksi (setor, tarik, transfer, bayar)
    if (!empty($filter_jenis)) {
        if ($filter_jenis === 'setor') {
            $where_conditions .= " AND t.jenis_transaksi = 'setor'";
        } elseif ($filter_jenis === 'tarik') {
            $where_conditions .= " AND t.jenis_transaksi = 'tarik'";
        } elseif ($filter_jenis === 'transfer') {
            $where_conditions .= " AND t.jenis_transaksi = 'transfer'";
        } elseif ($filter_jenis === 'bayar') {
            $where_conditions .= " AND t.jenis_transaksi = 'bayar'";
        }
    }

    // Filter tanggal
    if (!empty($filter_start_date)) {
        $where_conditions .= " AND DATE(t.created_at) >= ?";
        $params[] = $filter_start_date;
        $types .= "s";
    }

    if (!empty($filter_end_date)) {
        $where_conditions .= " AND DATE(t.created_at) <= ?";
        $params[] = $filter_end_date;
        $types .= "s";
    }

    $query = "
        SELECT
            t.no_transaksi, t.jenis_transaksi, t.jumlah, t.created_at, t.status,
            t.rekening_tujuan_id, t.rekening_id, t.keterangan,
            r1.no_rekening as rekening_asal,
            r2.no_rekening as rekening_tujuan,
            u1.nama as nama_pengirim,
            u2.nama as nama_penerima
        FROM transaksi t
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        LEFT JOIN users u1 ON r1.user_id = u1.id
        LEFT JOIN users u2 ON r2.user_id = u2.id
        $where_conditions
        ORDER BY t.created_at DESC
    ";

    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        // Konversi status
        $row['status'] = match ($row['status']) {
            'approved' => 'berhasil',
            'pending' => 'menunggu',
            'rejected' => 'gagal',
            default => $row['status']
        };
        $mutasi_data[] = $row;
    }
    $stmt->close();
}

$conn->close();

// Function untuk format tanggal
function formatIndonesianDate($date)
{
    $months = [
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

    $dateObj = new DateTime($date);
    return $dateObj->format('d') . ' ' . $months[(int) $dateObj->format('m')] . ' ' . $dateObj->format('Y H:i');
}

// Function untuk grouping date
function formatDateOnly($date)
{
    $months = [
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

    $dateObj = new DateTime($date);
    return $dateObj->format('d') . ' ' . $months[(int) $dateObj->format('m')] . ' ' . $dateObj->format('Y');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Aktivitas | KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        :root {
            /* Dark/Elegant Theme */
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            /* Neutral Colors */
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
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
            --white: #ffffff;

            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

            /* Border Radius */
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-2xl: 24px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 0;
            padding-bottom: 100px;
            min-height: 100vh;
        }

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
            padding: 20px 20px 120px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-info {
            color: var(--white);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .page-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 20px;
            position: relative;
            z-index: 10;
        }

        /* Header Actions */
        .header-actions {
            display: flex;
            gap: 10px;
        }

        /* Filter Button in Header */
        .header-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(255, 255, 255, 0.15);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .header-btn i {
            font-size: 18px;
        }

        /* ============================================ */
        /* TRANSACTION LIST - GROUPED BY DATE */
        /* ============================================ */
        .date-group {
            margin-bottom: 24px;
        }

        .date-header {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 12px;
            padding-left: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Transaction Card - White Bubble with Consistent Style */
        .transaction-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 14px;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .transaction-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        .transaction-card:active {
            transform: translateY(0) scale(0.98);
        }

        /* Transaction Icon Wrapper */
        .transaction-icon-wrapper {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }

        /* Transaction Content */
        .transaction-content {
            flex: 1;
            min-width: 0;
        }

        .transaction-type {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            word-break: break-word;
            line-height: 1.3;
        }

        .transaction-amount {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }

        .transaction-amount.debit {
            color: var(--gray-900);
        }

        .transaction-amount.credit {
            color: var(--gray-900);
        }

        .transaction-date {
            font-size: 11px;
            color: var(--gray-500);
        }

        /* Status Badge */
        .status-badge {
            padding: 8px 14px;
            border-radius: var(--radius);
            font-size: 11px;
            font-weight: 600;
            flex-shrink: 0;
            text-align: center;
            min-width: 70px;
        }

        .status-badge.success {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .status-badge.pending {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        .status-badge.rejected {
            background: var(--gray-100);
            color: var(--gray-900);
        }

        /* ============================================ */
        /* EMPTY STATE - CENTERED */
        /* ============================================ */
        .empty-state-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 50vh;
            text-align: center;
            padding: 40px 20px;
        }

        .empty-state {
            max-width: 400px;
            width: 100%;
        }

        .empty-icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--gray-700);
        }

        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
            line-height: 1.6;
        }

        /* ============================================ */
        /* FILTER MODAL */
        /* ============================================ */
        .filter-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-end;
        }

        .filter-modal.active {
            display: flex;
        }

        .filter-content {
            background: var(--white);
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            padding: 24px 20px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }

            to {
                transform: translateY(0);
            }
        }

        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .close-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--gray-100);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .close-btn:hover {
            background: var(--gray-200);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 16px;
            transition: all 0.2s ease;
            background: var(--gray-50);
            color: var(--gray-900);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--gray-400);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }

        /* iOS/Safari Date Input Fix */
        input[type="date"] {
            min-height: 50px;
            line-height: 1.5;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%23666666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            background-size: 20px 20px;
            padding-right: 44px;
        }

        /* Safari/iOS specific fixes */
        @supports (-webkit-touch-callout: none) {
            input[type="date"] {
                min-height: 50px;
                font-size: 16px !important;
                padding: 14px 44px 14px 16px;
                -webkit-appearance: none;
                appearance: none;
                background-color: var(--gray-50);
            }

            input[type="date"]::-webkit-date-and-time-value {
                text-align: left;
                padding: 0;
            }

            input[type="date"]::-webkit-calendar-picker-indicator {
                opacity: 0;
                width: 100%;
                height: 100%;
                position: absolute;
                right: 0;
                top: 0;
                cursor: pointer;
            }
        }

        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            padding: 14px 20px;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #434343 0%, #000000 100%);
            color: var(--white);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #555555 0%, #1a1a1a 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
        }

        .btn-secondary {
            background: transparent;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            border-color: var(--gray-400);
            color: var(--gray-700);
        }

        /* ============================================ */
        /* RESPONSIVE */
        /* ============================================ */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .main-container {
                padding: 16px;
            }

            .top-header {
                padding: 16px 16px 24px;
            }

            .page-title {
                font-size: 20px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
            }

            .header-btn i {
                font-size: 16px;
            }

            .page-subtitle {
                font-size: 12px;
            }

            .transaction-card {
                padding: 14px;
            }

            .transaction-image {
                width: 44px;
                height: 44px;
            }

            .transaction-icon-fallback {
                width: 44px;
                height: 44px;
                font-size: 20px;
            }

            .empty-icon {
                font-size: 64px;
            }

            .empty-title {
                font-size: 18px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }

            .transaction-type {
                font-size: 13px;
            }

            .transaction-amount {
                font-size: 12px;
            }

            .transaction-date {
                font-size: 10px;
            }

            .date-header {
                font-size: 10px;
            }

            .status-badge {
                font-size: 10px;
                padding: 6px 10px;
                min-width: 60px;
            }

            .empty-icon {
                font-size: 56px;
            }

            .empty-title {
                font-size: 16px;
            }

            .empty-text {
                font-size: 13px;
            }
        }
    </style>
</head>

<body>
    <?php if ($siswa_info): ?>
        <!-- Top Header - Dark Theme -->
        <header class="top-header">
            <div class="header-content">
                <div class="header-top">
                    <div class="page-info">
                        <h1 class="page-title">Aktivitas</h1>
                        <p class="page-subtitle">Riwayat transaksi Anda</p>
                    </div>
                    <div class="header-actions">
                        <button class="header-btn" onclick="openFilterModal()">
                            <i class="fas fa-sliders-h"></i>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Container -->
        <div class="main-container">
            <!-- Transaction List -->
            <?php if (empty($mutasi_data)): ?>
                <div class="empty-state-container">
                    <div class="empty-state">
                        <div class="empty-icon">ðŸ“­</div>
                        <h3 class="empty-title">Belum Ada Aktivitas</h3>
                        <p class="empty-text">
                            <?php if (!empty($filter_jenis) || !empty($filter_start_date) || !empty($filter_end_date)): ?>
                                Tidak ada aktivitas yang sesuai dengan filter yang dipilih.
                            <?php else: ?>
                                Akun Anda belum memiliki riwayat aktivitas.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <?php
                // Group transactions by date
                $grouped_data = [];
                foreach ($mutasi_data as $mutasi) {
                    $date = formatDateOnly($mutasi['created_at'] ?? 'now');
                    if (!isset($grouped_data[$date])) {
                        $grouped_data[$date] = [];
                    }
                    $grouped_data[$date][] = $mutasi;
                }
                ?>

                <?php foreach ($grouped_data as $date => $transactions): ?>
                    <div class="date-group">
                        <div class="date-header"><?php echo $date; ?></div>
                        <?php foreach ($transactions as $mutasi): ?>
                            <?php
                            // Logic sama dengan dashboard
                            $is_incoming = ($mutasi['jenis_transaksi'] == 'setor' ||
                                ($mutasi['jenis_transaksi'] == 'transfer' && $mutasi['rekening_tujuan'] == $no_rekening) ||
                                ($mutasi['jenis_transaksi'] == 'transaksi_qr' && $mutasi['rekening_tujuan'] == $no_rekening) ||
                                ($mutasi['jenis_transaksi'] == 'infaq' && $mutasi['rekening_tujuan'] == $no_rekening));

                            $is_debit = !$is_incoming;

                            $nama_penerima = $mutasi['nama_penerima'] ?? '';
                            $is_transfer_to_bendahar = (stripos($nama_penerima, 'bendahar') !== false);

                            $icon_class = 'fa-exchange-alt';
                            $icon_bg = 'var(--gray-100)';
                            $icon_color = 'var(--gray-600)';

                            if ($is_transfer_to_bendahar && $mutasi['jenis_transaksi'] == 'transfer' && !$is_incoming) {
                                $jenis_text = "Pembayaran Infaq Bulanan";
                                $merchant_text = htmlspecialchars($mutasi['nama_penerima'] ?? 'Bendahara Sekolah');
                                $icon_class = 'fa-hand-holding-heart';
                                $icon_bg = 'rgba(16, 185, 129, 0.1)';
                                $icon_color = 'var(--success)';
                            } elseif ($mutasi['jenis_transaksi'] === 'infaq') {
                                $jenis_text = 'Infaq';
                                $icon_class = 'fa-hand-holding-heart';
                                $icon_bg = 'rgba(16, 185, 129, 0.1)';
                                $icon_color = 'var(--success)';
                                if ($mutasi['rekening_asal'] == $no_rekening) {
                                    $merchant_text = 'Infaq ke ' . htmlspecialchars($mutasi['nama_penerima'] ?? 'N/A');
                                } else {
                                    $jenis_text = 'Penerimaan Infaq';
                                    $merchant_text = 'Dari ' . htmlspecialchars($mutasi['nama_pengirim'] ?? 'N/A');
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
                                $jenis_text = 'Transaksi QR';
                                $icon_class = 'fa-qrcode';
                                if ($mutasi['rekening_asal'] == $no_rekening) {
                                    $merchant_text = 'Pembayaran QR ke ' . htmlspecialchars($mutasi['nama_penerima'] ?? 'N/A');
                                    $icon_bg = 'rgba(239, 68, 68, 0.1)';
                                    $icon_color = 'var(--danger)';
                                } else {
                                    $jenis_text = 'Penerimaan QR';
                                    $merchant_text = 'Dari ' . htmlspecialchars($mutasi['nama_pengirim'] ?? 'N/A');
                                    $icon_bg = 'rgba(16, 185, 129, 0.1)';
                                    $icon_color = 'var(--success)';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'transfer') {
                                $icon_class = 'fa-paper-plane';
                                if ($is_incoming) {
                                    $jenis_text = 'Transfer Masuk';
                                    $merchant_text = 'Transfer Masuk dari ' . htmlspecialchars($mutasi['nama_pengirim'] ?? 'N/A');
                                    $icon_bg = 'rgba(16, 185, 129, 0.1)';
                                    $icon_color = 'var(--success)';
                                    // Optionally rotate for incoming? fa-rotate-90 or similar if needed, but paper plane usually implies sending.
                                    // For incoming, maybe keep arrow-down? User asked "untuk transfer pakai icon pewsawat kertas".
                                    // I'll use paper-plane for both but maybe flip it for incoming? Or just use it for all transfers.
                                    // Let's use paper-plane for outgoing and maybe a different one or rotated for incoming?
                                    // Actually, standard paper plane is "sending". Receiving might better remain arrow-down or a rotated plane.
                                    // However, user said "untuk transfer pakai icon pewsawat". I will apply it specificially to "Transfer" logic.
                                    // Let's stick to paper-plane for the category.
                                } else {
                                    $jenis_text = 'Transfer Keluar';
                                    $merchant_text = 'Transfer Keluar ke ' . htmlspecialchars($mutasi['nama_penerima'] ?? 'N/A');
                                    $icon_bg = 'rgba(239, 68, 68, 0.1)';
                                    $icon_color = 'var(--danger)';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'setor') {
                                $jenis_text = 'Setoran Tunai';
                                $merchant_text = 'Setor ke Rekening';
                                $icon_class = 'fa-arrow-down';
                                $icon_bg = 'rgba(16, 185, 129, 0.1)';
                                $icon_color = 'var(--success)';
                            } elseif ($mutasi['jenis_transaksi'] === 'tarik') {
                                $jenis_text = 'Penarikan Tunai';
                                $merchant_text = 'Penarikan dari Rekening';
                                $icon_class = 'fa-arrow-up';
                                $icon_bg = 'rgba(239, 68, 68, 0.1)';
                                $icon_color = 'var(--danger)';
                            } elseif ($mutasi['jenis_transaksi'] === 'bayar') {
                                // Ambil nama tagihan dari keterangan - bersihkan prefix dan suffix
                                $keterangan = $mutasi['keterangan'] ?? '';
                                $nama_tagihan = $keterangan;

                                // Hapus prefix
                                if (strpos($nama_tagihan, 'Pembayaran Tagihan ') === 0) {
                                    $nama_tagihan = substr($nama_tagihan, 19);
                                } elseif (strpos($nama_tagihan, 'Pembayaran - ') === 0) {
                                    $nama_tagihan = substr($nama_tagihan, 13);
                                } elseif (strpos($nama_tagihan, 'Pembayaran ') === 0) {
                                    $nama_tagihan = substr($nama_tagihan, 11);
                                }

                                // Hapus suffix Via...
                                $nama_tagihan = preg_replace('/\s*Via\s+(Bendahara|KASDIG|Schobank|Aplikasi).*$/i', '', $nama_tagihan);
                                $nama_tagihan = trim($nama_tagihan) ?: 'Tagihan';

                                $jenis_text = 'Pembayaran ' . htmlspecialchars($nama_tagihan);
                                $merchant_text = htmlspecialchars($nama_tagihan);
                                $icon_class = 'fa-file-invoice-dollar';
                                $icon_bg = 'rgba(245, 158, 11, 0.1)';
                                $icon_color = 'var(--warning)';
                                $is_debit = true;
                            } else {
                                $jenis_text = 'Transaksi';
                                $merchant_text = htmlspecialchars($mutasi['keterangan'] ?? 'Transaksi');
                                $icon_class = 'fa-exchange-alt';
                                $icon_bg = 'rgba(52, 152, 219, 0.1)';
                                $icon_color = 'var(--secondary)';
                            }

                            // Status handling
                            if ($mutasi['status'] === 'menunggu') {
                                $status_text = 'Menunggu';
                                $status_class = 'pending';
                            } elseif ($mutasi['status'] === 'gagal') {
                                $status_text = 'Gagal';
                                $status_class = 'rejected';
                            } else {
                                $status_text = 'Sukses';
                                $status_class = 'success';
                            }
                            ?>

                            <a href="struk_transaksi.php?no_transaksi=<?= htmlspecialchars($mutasi['no_transaksi']) ?>"
                                class="transaction-card">
                                <div class="transaction-icon-wrapper" style="background: <?= $icon_bg ?>; color: <?= $icon_color ?>;">
                                    <i class="fas <?= $icon_class ?>"></i>
                                </div>
                                <div class="transaction-content">
                                    <div class="transaction-type"><?php echo $jenis_text; ?></div>
                                    <div class="transaction-amount <?php echo $is_debit ? 'debit' : 'credit'; ?>">
                                        <?php
                                        $sign = $is_debit ? '- ' : '+ ';
                                        $jumlah = abs($mutasi['jumlah'] ?? 0);
                                        echo $sign . 'Rp ' . number_format($jumlah, 0, ',', '.');
                                        ?>
                                    </div>
                                    <div class="transaction-date">
                                        <?php echo date('H:i', strtotime($mutasi['created_at'] ?? 'now')); ?> WIB
                                    </div>
                                </div>
                                <div class="status-badge <?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Modal -->
    <div class="filter-modal" id="filterModal" onclick="closeFilterModal(event)">
        <div class="filter-content" onclick="event.stopPropagation()">
            <div class="filter-header">
                <h3 class="filter-title">Filter Aktivitas</h3>
                <button class="close-btn" onclick="closeFilterModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="GET">
                <div class="form-group">
                    <label class="form-label">Jenis Transaksi</label>
                    <select name="jenis" class="form-select">
                        <option value="">Semua Jenis</option>
                        <option value="setor" <?php echo ($filter_jenis === 'setor') ? 'selected' : ''; ?>>Setor</option>
                        <option value="tarik" <?php echo ($filter_jenis === 'tarik') ? 'selected' : ''; ?>>Tarik</option>
                        <option value="transfer" <?php echo ($filter_jenis === 'transfer') ? 'selected' : ''; ?>>Transfer
                        </option>
                        <option value="bayar" <?php echo ($filter_jenis === 'bayar') ? 'selected' : ''; ?>>Pembayaran
                        </option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>"
                        class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Akhir</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>"
                        class="form-control">
                </div>
                <div class="filter-actions">
                    <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>"
                        class="btn btn-secondary">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Terapkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <?php
    $bottom_nav_path = __DIR__ . '/bottom_navbar.php';
    if (file_exists($bottom_nav_path)) {
        include $bottom_nav_path;
    }
    ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Filter Modal Functions
        function openFilterModal() {
            document.getElementById('filterModal').classList.add('active');
        }

        function closeFilterModal(event) {
            if (!event || event.target.id === 'filterModal') {
                document.getElementById('filterModal').classList.remove('active');
            }
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeFilterModal();
            }
        });

        // Set max date to today for date inputs
        document.addEventListener('DOMContentLoaded', function () {
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');

            dateInputs.forEach(input => {
                input.max = today;
            });

            // Smart Header - Hide on scroll down, show on scroll up
            const header = document.querySelector('.top-header');
            let lastScrollY = window.scrollY;
            let ticking = false;

            function updateHeader() {
                const currentScrollY = window.scrollY;

                if (currentScrollY > lastScrollY && currentScrollY > 100) {
                    // Scrolling down & past 100px - hide header
                    header.classList.add('header-hidden');
                } else {
                    // Scrolling up - show header
                    header.classList.remove('header-hidden');
                }

                lastScrollY = currentScrollY;
                ticking = false;
            }

            window.addEventListener('scroll', function () {
                if (!ticking) {
                    requestAnimationFrame(updateHeader);
                    ticking = true;
                }
            });
        });
    </script>
</body>

</html>