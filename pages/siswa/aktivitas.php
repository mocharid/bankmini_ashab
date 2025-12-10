<?php
/**
 * Aktivitas - Adaptive Path Version
 * File: pages/user/aktivitas.php or similar
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


// Ambil info siswa dan rekening
$siswa_info = null;
$mutasi_data = [];
$error_message = '';
$success_message = '';


// Filter parameters
$filter_jenis = $_GET['jenis'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';


$where_conditions = "WHERE rek.user_id = ?";
$params = [$user_id];
$types = "i";


if (!empty($filter_jenis)) {
    if ($filter_jenis === 'transfer_masuk') {
        $where_conditions .= " AND (t.jenis_transaksi IS NULL OR (t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_tujuan_id))";
    } elseif ($filter_jenis === 'transfer_keluar') {
        $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_id";
    } elseif ($filter_jenis === 'qr_masuk') {
        $where_conditions .= " AND t.jenis_transaksi = 'transaksi_qr' AND m.rekening_id = t.rekening_tujuan_id";
    } elseif ($filter_jenis === 'qr_keluar') {
        $where_conditions .= " AND t.jenis_transaksi = 'transaksi_qr' AND m.rekening_id = t.rekening_id";
    } else {
        $where_conditions .= " AND t.jenis_transaksi = ?";
        $params[] = $filter_jenis;
        $types .= "s";
    }
}


if (!empty($filter_status)) {
    $where_conditions .= " AND t.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}


if (!empty($filter_start_date)) {
    $where_conditions .= " AND m.created_at >= ?";
    $params[] = $filter_start_date . ' 00:00:00';
    $types .= "s";
}


if (!empty($filter_end_date)) {
    $where_conditions .= " AND m.created_at <= ?";
    $params[] = $filter_end_date . ' 23:59:59';
    $types .= "s";
}


// Query disesuaikan dengan database baru - tanpa siswa_profiles.nis_nisn
$stmt = $conn->prepare("
    SELECT u.id, u.nama, sp.nis_nisn, r.no_rekening, r.saldo, r.id as rekening_id
    FROM users u
    LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN rekening r ON u.id = r.user_id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$siswa_info = $result->fetch_assoc();
$stmt->close();


if (!$siswa_info) {
    $error_message = "Akun tidak ditemukan.";
} else {
    $user_rekening_id = $siswa_info['rekening_id'];
    
    // Ambil mutasi dengan filter
    $query = "
        SELECT m.id, m.jumlah, m.saldo_akhir, m.created_at, m.rekening_id,
              t.jenis_transaksi, t.no_transaksi, t.status, t.keterangan,
              t.rekening_id as transaksi_rekening_id, t.rekening_tujuan_id,
              u_petugas.nama as petugas_nama,
              r1.no_rekening as rekening_asal, r2.no_rekening as rekening_tujuan,
              u1.nama as nama_pengirim, u2.nama as nama_penerima
        FROM mutasi m
        LEFT JOIN transaksi t ON m.transaksi_id = t.id
        LEFT JOIN rekening rek ON m.rekening_id = rek.id
        LEFT JOIN users u_petugas ON t.petugas_id = u_petugas.id
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        LEFT JOIN users u1 ON r1.user_id = u1.id
        LEFT JOIN users u2 ON r2.user_id = u2.id
        $where_conditions
        ORDER BY m.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $mutasi_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}


$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Aktivitas - SCHOBANK</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            scroll-padding-top: 70px;
            scroll-behavior: smooth;
        }
        
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --primary-light: #A5D8FF;
            --secondary: #00C2FF;
            --success: #00D084;
            --warning: #FFB020;
            --danger: #FF3B30;
            --dark: #1C1C1E;
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 100px;
        }
        
        /* Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        
        /* Sticky Header */
        .sticky-header {
            position: -webkit-sticky;
            position: sticky;
            top: 70px;
            left: 0;
            right: 0;
            background: var(--gray-50);
            z-index: 99;
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }
        
        .sticky-header.stuck {
            box-shadow: var(--shadow-md);
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 20px;
            background: var(--gray-50);
        }
        
        /* Page Header - Modified */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 0;
            letter-spacing: -0.5px;
        }
        
        /* Filter Icon Button - New */
        .filter-icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--white);
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }
        
        .filter-icon-btn:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
            box-shadow: var(--shadow-md);
        }
        
        .filter-icon-btn:active {
            transform: scale(0.95);
        }
        
        .filter-icon-btn i {
            color: var(--gray-700);
            font-size: 18px;
        }
        
        /* Transaction List - Grouped by Date */
        .date-group {
            margin-bottom: 24px;
        }
        
        .date-header {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 12px;
            padding-left: 4px;
        }
        
        /* Transaction Card - New Layout with Direct Image */
        .transaction-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .transaction-card:active {
            transform: translateY(0) scale(0.98);
        }
        
        /* Transaction Image - DIRECT IMAGE WITHOUT CONTAINER */
        .transaction-image {
            width: 50px;
            height: 50px;
            flex-shrink: 0;
            border-radius: var(--radius);
            object-fit: cover;
            object-position: center;
        }
        
        /* Fallback icon style */
        .transaction-icon-fallback {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--primary-light);
            font-size: 24px;
            background: var(--gray-100);
            border-radius: var(--radius);
        }
        
        /* Transaction Content */
        .transaction-content {
            flex: 1;
            min-width: 0;
        }
        
        .transaction-type {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            word-break: break-word;
        }
        
        .transaction-merchant {
            font-size: 11px;
            color: var(--gray-600);
            margin-bottom: 6px;
            white-space: normal;
            overflow: visible;
            text-overflow: unset;
            word-break: break-word;
        }
        
        .transaction-amount {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
        }
        
        .transaction-amount.debit {
            color: var(--danger);
        }
        
        .transaction-amount.credit {
            color: var(--primary-light);
        }
        
        .transaction-amount.credit.transfer-masuk {
            color: var(--gray-900);
        }
        
        .transaction-date {
            font-size: 10px;
            color: var(--gray-500);
        }
        
        /* Status Badge */
        .status-badge {
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-weight: 600;
            flex-shrink: 0;
            text-align: center;
            min-width: 70px;
        }
        
        .status-badge.success {
            background: var(--primary-light);
            color: var(--primary-dark);
        }
        
        .status-badge.pending {
            background: var(--primary);
            color: var(--white);
        }
        
        .status-badge.rejected {
            background: var(--primary-dark);
            color: var(--white);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .empty-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-700);
        }
        
        .empty-text {
            font-size: 14px;
        }
        
        /* Filter Modal */
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
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control, .form-select {
            padding: 12px 14px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 15px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.2s ease;
            background: var(--white);
            color: var(--gray-900);
            width: 100%;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            height: 48px;
        }
        
        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }
        
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
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
            font-family: 'Poppins', sans-serif;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            height: 48px;
        }
        
        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        
        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .btn-secondary:hover {
            background: var(--gray-300);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }
            .container {
                padding: 16px;
            }
            .page-title {
                font-size: 20px;
            }
            .filter-content {
                max-height: 90vh;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
            .filter-icon-btn {
                width: 56px;
                height: 56px;
            }
        }
        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }
            .nav-logo {
                height: 32px;
                max-width: 120px;
            }
            .transaction-amount {
                font-size: 14px;
            }
            .transaction-type {
                font-size: 14px;
            }
            .filter-icon-btn {
                width: 52px;
                height: 52px;
            }
            .filter-icon-btn i {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="<?php echo $base_url; ?>/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>

    <!-- Sticky Header -->
    <?php if ($siswa_info): ?>
        <div class="sticky-header" id="stickyHeader">
            <!-- Page Header with Filter Icon -->
            <div class="page-header">
                <h1 class="page-title">Aktivitas</h1>
                <button class="filter-icon-btn" onclick="openFilterModal()">
                    <i class="fas fa-sort"></i>
                </button>
            </div>
        </div>

        <!-- Main Container untuk Transaksi -->
        <div class="container">
            <!-- Transaction List -->
            <?php if (empty($mutasi_data)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <h3 class="empty-title">Belum Ada Aktivitas</h3>
                    <p class="empty-text">Akun Anda belum memiliki riwayat aktivitas</p>
                </div>
            <?php else: ?>
                <?php
                // Group transactions by date
                $grouped_data = [];
                foreach ($mutasi_data as $mutasi) {
                    $date = date('d M Y', strtotime($mutasi['created_at'] ?? 'now'));
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
                            $is_debit = false;
                            $jenis_text = '';
                            $merchant_text = '';
                            $icon_image = '';
                            $status_text = 'Sukses';
                            $status_class = 'success';
                            $amount_extra_class = '';
                            
                            // Determine transaction type and icon image
                            if ($mutasi['jenis_transaksi'] === 'transfer') {
                                $is_debit = ($mutasi['rekening_id'] == $mutasi['transaksi_rekening_id']);
                                if ($is_debit) {
                                    $jenis_text = 'Transfer Keluar';
                                    $merchant_text = htmlspecialchars($mutasi['nama_penerima'] ?? 'N/A') . ' - ' . htmlspecialchars($mutasi['rekening_tujuan'] ?? 'N/A');
                                    $icon_image = 'tfkeluar.png';
                                } else {
                                    $jenis_text = 'Transfer Masuk';
                                    $merchant_text = htmlspecialchars($mutasi['nama_pengirim'] ?? 'N/A') . ' - ' . htmlspecialchars($mutasi['rekening_asal'] ?? 'N/A');
                                    $icon_image = 'tfmasuk.png';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
                                $is_debit = ($mutasi['rekening_id'] == $mutasi['transaksi_rekening_id']);
                                $jenis_text = 'Pembelian QRIS';
                                $merchant_text = htmlspecialchars($mutasi['keterangan'] ?? 'QRIS Payment');
                                $icon_image = 'tfkeluar.png';
                            } elseif ($mutasi['jenis_transaksi'] === 'setor') {
                                $jenis_text = 'Setor Tunai';
                                $merchant_text = htmlspecialchars($mutasi['petugas_nama'] ?? $mutasi['keterangan'] ?? 'Setor ke Rekening');
                                $icon_image = 'setor.png';
                                $is_debit = false;
                            } elseif ($mutasi['jenis_transaksi'] === 'tarik') {
                                $jenis_text = 'Tarik Tunai';
                                $merchant_text = htmlspecialchars($mutasi['petugas_nama'] ?? $mutasi['keterangan'] ?? 'Penarikan dari Rekening');
                                $icon_image = 'tarik.png';
                                $is_debit = true;
                            } elseif ($mutasi['jenis_transaksi'] === null) {
                                $jenis_text = 'Transfer Masuk';
                                $merchant_text = htmlspecialchars($mutasi['nama_pengirim'] ?? 'N/A') . ' - ' . htmlspecialchars($mutasi['rekening_asal'] ?? 'N/A');
                                $icon_image = 'tfmasuk.png';
                                $is_debit = false;
                                $amount_extra_class = 'transfer-masuk';
                            } else {
                                $jenis_text = ucfirst($mutasi['jenis_transaksi']);
                                $merchant_text = htmlspecialchars($mutasi['keterangan'] ?? '-');
                                $icon_image = 'tfkeluar.png';
                                $is_debit = ($mutasi['jenis_transaksi'] === 'tarik');
                            }
                            
                            // Override icons based on keterangan for special merchants
                            $keterangan_lower = strtolower($mutasi['keterangan'] ?? '');
                            if (stripos($keterangan_lower, 'shopee') !== false) {
                                $jenis_text = 'BRIVA';
                                $icon_image = 'tfkeluar.png';
                            } elseif (stripos($keterangan_lower, 'tokopedia') !== false || stripos($keterangan_lower, 'pt tokopedia') !== false) {
                                $jenis_text = 'Pembelian QRIS';
                                $icon_image = 'tfkeluar.png';
                            } elseif (stripos($keterangan_lower, 'briva') !== false) {
                                $jenis_text = 'BRIVA';
                                $icon_image = 'tfkeluar.png';
                            } elseif (stripos($keterangan_lower, 'warkap') !== false) {
                                $jenis_text = 'Pembelian QRIS';
                                $icon_image = 'tfkeluar.png';
                            }
                            
                            // Status handling
                            if ($mutasi['status'] === 'pending') {
                                $status_text = 'Pending';
                                $status_class = 'pending';
                            } elseif ($mutasi['status'] === 'rejected') {
                                $status_text = 'Ditolak';
                                $status_class = 'rejected';
                            } else {
                                $status_text = 'Sukses';
                                $status_class = 'success';
                            }
                            ?>
                            
                            <a href="struk_transaksi.php?no_transaksi=<?= htmlspecialchars($mutasi['no_transaksi']) ?>" class="transaction-card">
                                <?php if ($icon_image): ?>
                                    <img src="<?php echo $base_url; ?>/assets/images/<?= htmlspecialchars($icon_image); ?>" 
                                         alt="<?= htmlspecialchars($jenis_text); ?>" 
                                         class="transaction-image"
                                         loading="lazy">
                                <?php else: ?>
                                    <div class="transaction-icon-fallback">
                                        <i class="fas fa-question"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="transaction-content">
                                    <div class="transaction-type"><?php echo $jenis_text; ?></div>
                                    <div class="transaction-merchant"><?php echo $merchant_text; ?></div>
                                    <div class="transaction-amount <?php echo $is_debit ? 'debit' : 'credit'; ?> <?php echo $amount_extra_class; ?>">
                                        <?php
                                        $sign = $is_debit ? '- ' : '';
                                        $jumlah = abs($mutasi['jumlah'] ?? 0);
                                        echo $sign . 'Rp' . number_format($jumlah, 0, ',', '.');
                                        ?>
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
                        <option value="transfer" <?php echo ($filter_jenis === 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                        <option value="transfer_masuk" <?php echo ($filter_jenis === 'transfer_masuk') ? 'selected' : ''; ?>>Transfer Masuk</option>
                        <option value="transfer_keluar" <?php echo ($filter_jenis === 'transfer_keluar') ? 'selected' : ''; ?>>Transfer Keluar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="approved" <?php echo ($filter_status === 'approved') ? 'selected' : ''; ?>>Berhasil</option>
                        <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Menunggu</option>
                        <option value="rejected" <?php echo ($filter_status === 'rejected') ? 'selected' : ''; ?>>Ditolak</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Akhir</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>" class="form-control">
                </div>
                <div class="filter-actions">
                    <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Terapkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation - Included from separate file -->
    <?php include 'bottom_navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Detect Sticky Header
        let stickyHeader = document.getElementById('stickyHeader');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 70) {
                stickyHeader.classList.add('stuck');
            } else {
                stickyHeader.classList.remove('stuck');
            }
        });
        
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
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeFilterModal();
            }
        });
    </script>
</body>
</html>
