<?php
/**
 * Dashboard - Adaptive Path Version
 * File: pages/user/dashboard.php or similar
 *
 * Compatible with:
 * - Local: schobank/pages/user/dashboard.php
 * - Hosting: public_html/pages/user/dashboard.php
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
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
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
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: $base_url/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// ✅ FIXED: Single query ambil semua data dengan JOIN
$query = "SELECT
    u.nama, u.username, u.email,
    COALESCE(us.is_frozen, 0) as is_frozen,
    COALESCE(us.pin_block_until, '1970-01-01') as pin_block_until,
    COALESCE(us.pin, '') as pin,
    COALESCE(r.saldo, 0) as saldo,
    r.id as rekening_id,
    r.no_rekening
FROM users u
LEFT JOIN user_security us ON u.id = us.user_id
LEFT JOIN rekening r ON u.id = r.user_id
WHERE u.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

if (!$user_data) {
    header("Location: $base_url/login.php");
    exit();
}

// Determine account status
$current_time = date('Y-m-d H:i:s');

function getAccountStatus($user_data, $current_time) {
    if ($user_data['is_frozen']) {
        return 'dibekukan';
    } elseif ($user_data['pin_block_until'] != null && $user_data['pin_block_until'] > $current_time) {
        return 'terblokir-sementara';
    }
    return 'aktif';
}

$account_status = getAccountStatus($user_data, $current_time);

// Fetch balance and rekening ID
$saldo = $user_data['saldo'];
$no_rekening = $user_data['no_rekening'] ?? 'N/A';
$rekening_id = $user_data['rekening_id'] ?? null;

// Format account number with spaces
$formatted_no_rekening = $no_rekening != 'N/A' ? chunk_split(trim($no_rekening), 4, ' ') : 'N/A';

// Function to determine card level and style based on balance
function getCardLevelAndStyle($saldo) {
    if ($saldo < 100000) {
        return [
            'level' => 'Tunas',
            'gradient' => 'linear-gradient(135deg, #434343 0%, #000000 100%)',
            'glow' => 'rgba(0, 0, 0, 0.5)'
        ];
    } elseif ($saldo < 250000) {
        return [
            'level' => 'Tumbuh',
            'gradient' => 'linear-gradient(135deg, #3a3a3a 0%, #1a1a1a 100%)',
            'glow' => 'rgba(58, 58, 58, 0.5)'
        ];
    }
    return [
        'level' => 'Cakrawala',
        'gradient' => 'linear-gradient(135deg, #2c3e50 0%, #1a1a1a 100%)',
        'glow' => 'rgba(44, 62, 80, 0.5)'
    ];
}

$card_info = getCardLevelAndStyle($saldo);

// ✅ FIXED: Check PIN dan email dari tabel baru
$has_pin = !empty($user_data['pin']);
$has_email = !empty($user_data['email']);

// Fetch notifications
$query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications_result = $stmt->get_result();

// Count unread notifications
$query_unread = "SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0";
$stmt_unread = $conn->prepare($query_unread);
$stmt_unread->bind_param("i", $user_id);
$stmt_unread->execute();
$unread_notifications = $stmt_unread->get_result()->fetch_assoc()['unread'];

// ✅ FIXED: Recent transactions dengan JOIN users yang benar
$recent_transactions = [];
if ($rekening_id) {
    $query = "SELECT
        t.no_transaksi, t.jenis_transaksi, t.jumlah, t.created_at, t.status,
        t.rekening_tujuan_id, t.rekening_id,
        r1.no_rekening as rekening_asal,
        r2.no_rekening as rekening_tujuan,
        u1.nama as nama_pengirim,
        u2.nama as nama_penerima
        FROM transaksi t
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        LEFT JOIN users u1 ON r1.user_id = u1.id
        LEFT JOIN users u2 ON r2.user_id = u2.id
        WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?
        ORDER BY t.created_at DESC
        LIMIT 5";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $rekening_id, $rekening_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row['status'] = match($row['status']) {
            'approved' => 'berhasil',
            'pending' => 'menunggu',
            'rejected' => 'gagal',
            default => $row['status']
        };
        $recent_transactions[] = $row;
    }
}

// Function to format date in Indonesian
function formatIndonesianDate($date) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $dateObj = new DateTime($date);
    return $dateObj->format('d') . ' ' . $months[(int)$dateObj->format('m')] . ' ' . $dateObj->format('Y H:i');
}

// Determine greeting based on time
$hour = date('H');
if ($hour >= 0 && $hour < 11) {
    $time_greeting = 'Selamat Pagi';
} elseif ($hour >= 11 && $hour < 15) {
    $time_greeting = 'Selamat Siang';
} elseif ($hour >= 15 && $hour < 18) {
    $time_greeting = 'Selamat Sore';
} else {
    $time_greeting = 'Selamat Malam';
}

// Random daily greeting
$greetings = [
    'Semangat hari ini!',
    'Selamat beraktivitas!',
    'Hari yang indah!',
    'Tetap produktif!',
    'Nikmati harimu!',
    'Jaga kesehatan!',
    'Sukses selalu!',
    'Tetap semangat!',
    'Hari penuh berkah!',
    'Jadilah yang terbaik!'
];
$daily_index = date('z') % count($greetings);
$daily_greeting = $greetings[$daily_index];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Dashboard - SCHOBANK</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
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
            --infaq-blue: #1a237e;
            --infaq-blue-dark: #0d1456;
            --infaq-blue-light: #283593;
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

        /* Top Navigation - NO BORDER, MATCH BACKGROUND */
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

        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--white);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }

        .nav-btn:hover {
            background: var(--white);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
            transform: scale(1.05);
        }

        .nav-btn:active {
            transform: scale(0.95);
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: var(--white);
            width: 18px;
            height: 18px;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--gray-50);
        }

        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Welcome Section */
        .welcome-section {
            margin-bottom: 24px;
        }

        .welcome-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }

        .welcome-subtitle {
            font-size: 13px;
            color: var(--gray-500);
            font-weight: 400;
        }

        /* Card Balance - Reduced Border Radius */
        .balance-card {
            background: <?php echo $card_info['gradient']; ?>;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 20px 40px <?php echo $card_info['glow']; ?>;
            position: relative;
            overflow: hidden;
            min-height: 180px;
            height: 180px;
        }

        .balance-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
        }

        .balance-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 50%;
        }

        /* Add metallic shine effect */
        .balance-card .card-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 50%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.08), transparent);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0%, 100% { left: -100%; }
            50% { left: 150%; }
        }

        .card-content {
            position: relative;
            z-index: 2;
            color: var(--white);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .card-logo {
            font-size: 13px;
            font-weight: 700;
            opacity: 0.95;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .card-level {
            background: rgba(255, 255, 255, 0.15);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .account-number {
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 2px;
            margin-bottom: 16px;
            font-family: 'Courier New', monospace;
            opacity: 0.95;
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .account-holder {
            font-size: 11px;
            opacity: 0.7;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 400;
        }

        .holder-name {
            font-size: 13px;
            font-weight: 600;
        }

        .balance-label {
            font-size: 11px;
            opacity: 0.7;
            margin-bottom: 4px;
            font-weight: 400;
        }

        .balance-amount {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -0.5px;
            font-family: 'Inter', 'Courier New', monospace;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 180px;
        }

        /* Alert Messages */
        .alert {
            background: var(--white);
            border-radius: var(--radius);
            padding: 14px;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            box-shadow: var(--shadow-sm);
        }

        .alert-icon {
            width: 18px;
            height: 18px;
            color: var(--warning);
            flex-shrink: 0;
        }

        .alert-content {
            flex: 1;
            font-size: 12px;
            color: var(--gray-700);
            font-weight: 400;
        }

        .alert-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .alert-close {
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            padding: 0;
            width: 18px;
            height: 18px;
        }

        /* Payment Section - PERBAIKAN LAYOUT INFAQ */
        .payment-section {
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 12px;
        }

        /* ✅ PERBAIKAN: Layout Infaq yang Rapi */
        .infaq-feature {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-left: 32px; /* Spacing lebih luas dari pinggir */
            width: fit-content;
        }

        .infaq-feature:hover {
            transform: scale(1.05);
        }

        .infaq-feature:active {
            transform: scale(0.98);
        }

        .infaq-feature.disabled {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
        }

        .infaq-icon {
            width: 80px;
            height: 80px;
            margin-bottom: 8px;
            display: block;
        }

        .infaq-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            text-align: center; /* Nama di tengah */
        }

        /* Transaction List - NO BORDERS BETWEEN ITEMS */
        .transaction-section {
            margin-bottom: 40px;
        }

        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .transaction-list {
            background: transparent;
            border-radius: 0;
            overflow: visible;
            box-shadow: none;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 0;
            border-bottom: none;
            transition: all 0.2s ease;
            background: transparent;
        }

        .transaction-item:hover {
            padding-left: 8px;
            padding-right: 8px;
            margin-left: -8px;
            margin-right: -8px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 12px;
        }

        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }

        .transaction-icon.in {
            background: rgba(0, 208, 132, 0.1);
            color: var(--success);
        }

        .transaction-icon.out {
            background: rgba(255, 59, 48, 0.1);
            color: var(--danger);
        }

        .transaction-icon.qr {
            background: rgba(0, 102, 255, 0.1);
            color: var(--primary);
        }

        .transaction-icon.infaq {
            background: rgba(26, 35, 126, 0.1);
            color: var(--infaq-blue);
        }

        .transaction-details {
            flex: 1;
            min-width: 0;
        }

        .transaction-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .transaction-time {
            font-size: 11px;
            color: var(--gray-500);
            font-weight: 400;
        }

        .transaction-amount {
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            font-family: 'Poppins', sans-serif;
        }

        .transaction-amount.in {
            color: var(--success);
        }

        .transaction-amount.out {
            color: var(--danger);
        }

        .transaction-amount.infaq {
            color: var(--infaq-blue);
        }

        .transaction-status {
            font-size: 10px;
            font-weight: 500;
            color: var(--gray-600);
            margin-top: 2px;
            display: block;
        }

        .transaction-status.berhasil {
            color: var(--gray-600);
        }

        .transaction-status.menunggu {
            color: var(--warning);
        }

        .transaction-status.gagal {
            color: var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: var(--gray-500);
        }

        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .view-all-link {
            font-size: 12px;
            color: var(--elegant-dark);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Notification Dropdown - With Read Status */
        .notification-dropdown {
            position: relative;
        }

        .notification-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            width: 420px;
            max-width: calc(100vw - 40px);
            max-height: 550px;
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: none;
            z-index: 200;
            border: 1px solid var(--gray-200);
        }

        .notification-panel.show {
            display: block;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notification-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 700;
            font-size: 15px;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notification-header-title {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification-header-title i {
            color: var(--elegant-dark);
            font-size: 16px;
        }

        .notification-count {
            background: var(--elegant-dark);
            color: var(--white);
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .notification-body {
            max-height: 450px;
            overflow-y: auto;
        }

        .notification-body::-webkit-scrollbar {
            width: 6px;
        }

        .notification-body::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .notification-body::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 10px;
        }

        .notification-body::-webkit-scrollbar-thumb:hover {
            background: var(--gray-400);
        }

        .notification-item {
            padding: 14px 20px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .notification-item:hover {
            background: var(--gray-50);
        }

        .notification-item.unread {
            background: rgba(44, 62, 80, 0.03);
        }

        .notification-icon-wrapper {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            background: linear-gradient(135deg, var(--elegant-gray) 0%, var(--elegant-dark) 100%);
            color: var(--white);
        }

        .notification-content {
            flex: 1;
            min-width: 0;
        }

        .notification-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 4px;
            gap: 8px;
        }

        .notification-category {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            background: rgba(44, 62, 80, 0.1);
            color: var(--elegant-dark);
        }

        .notification-read-status {
            font-size: 9px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .notification-read-status.read {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }

        .notification-read-status.unread {
            background: rgba(44, 62, 80, 0.15);
            color: var(--elegant-dark);
        }

        .notification-message {
            font-size: 11px;
            color: var(--gray-900);
            margin-bottom: 6px;
            line-height: 1.5;
            font-weight: 500;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .notification-time {
            font-size: 10px;
            color: var(--gray-500);
            font-weight: 400;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .notification-time i {
            font-size: 9px;
        }

        .notification-empty {
            padding: 60px 24px;
            text-align: center;
        }

        .notification-empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.2;
        }

        .notification-empty-text {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .top-nav {
                padding: 8px 16px;
            }

            .nav-logo {
                height: 38px;
                max-width: 140px;
            }

            .container {
                padding: 16px;
            }

            .welcome-title {
                font-size: 18px;
            }

            .welcome-subtitle {
                font-size: 12px;
            }

            .balance-card {
                padding: 20px;
                min-height: 170px;
                height: 170px;
                border-radius: 14px;
            }

            .card-logo {
                font-size: 12px;
            }

            .card-level {
                font-size: 9px;
                padding: 4px 9px;
            }

            .account-number {
                font-size: 14px;
                letter-spacing: 1.5px;
            }

            .balance-amount {
                font-size: 16px;
                max-width: 160px;
            }

            .holder-name {
                font-size: 12px;
            }

            .section-title {
                font-size: 15px;
            }

            /* ✅ RESPONSIVE: Infaq spacing lebih kecil di mobile */
            .infaq-feature {
                margin-left: 24px;
            }

            .infaq-icon {
                width: 70px;
                height: 70px;
            }

            .infaq-label {
                font-size: 13px;
            }

            .transaction-item {
                padding: 12px 0;
            }

            .transaction-icon {
                width: 38px;
                height: 38px;
                font-size: 15px;
            }

            .transaction-title {
                font-size: 12px;
            }

            .transaction-time {
                font-size: 10px;
            }

            .transaction-amount {
                font-size: 12px;
            }

            .notification-panel {
                right: 10px;
                left: 10px;
                width: auto;
            }

            .notification-header {
                padding: 16px 18px;
                font-size: 14px;
            }

            .notification-item {
                padding: 12px 16px;
            }

            .notification-icon-wrapper {
                width: 34px;
                height: 34px;
                font-size: 13px;
            }

            .notification-message {
                font-size: 10px;
            }

            .notification-time {
                font-size: 9px;
            }

            .notification-read-status {
                font-size: 8px;
                padding: 2px 5px;
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

            .nav-actions {
                gap: 8px;
            }

            .balance-amount {
                font-size: 15px;
                max-width: 150px;
            }

            .balance-card {
                border-radius: 12px;
                padding: 18px;
                min-height: 160px;
                height: 160px;
            }

            /* ✅ RESPONSIVE: Spacing lebih kecil lagi di layar kecil */
            .infaq-feature {
                margin-left: 20px;
            }

            .infaq-icon {
                width: 60px;
                height: 60px;
            }

            .infaq-label {
                font-size: 12px;
            }

            .transaction-title {
                font-size: 11px;
            }

            .transaction-amount {
                font-size: 11px;
            }
        }

        @media (max-width: 360px) {
            .nav-logo {
                height: 28px;
                max-width: 100px;
            }

            .nav-actions {
                gap: 8px;
            }

            .balance-amount {
                font-size: 14px;
                max-width: 130px;
            }

            .balance-card {
                min-height: 150px;
                height: 150px;
            }

            /* ✅ RESPONSIVE: Spacing minimal di layar sangat kecil */
            .infaq-feature {
                margin-left: 16px;
            }

            .infaq-icon {
                width: 50px;
                height: 50px;
            }

            .infaq-label {
                font-size: 11px;
            }
        }

        /* Animations */
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .balance-card,
        .infaq-feature,
        .transaction-list {
            animation: slideUp 0.5s ease;
        }

        /* Loading States */
        .skeleton {
            background: linear-gradient(90deg, var(--gray-200) 25%, var(--gray-100) 50%, var(--gray-200) 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }

        /* Disabled State */
        .disabled-card {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="<?php echo $base_url; ?>/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
        <div class="nav-actions">
            <button class="nav-btn" onclick="toggleNotifications()">
                <i class="fas fa-bell"></i>
                <?php if ($unread_notifications > 0): ?>
                <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                <?php endif; ?>
            </button>
            <button class="nav-btn" onclick="confirmLogout()">
                <i class="fas fa-sign-out-alt"></i>
            </button>
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h1 class="welcome-title"><?php echo $time_greeting; ?>, <?php echo htmlspecialchars(ucfirst($user_data['nama'])); ?>!</h1>
            <p class="welcome-subtitle"><?php echo $daily_greeting; ?></p>
        </div>

        <!-- Balance Card -->
        <div class="balance-card">
            <div class="card-content">
                <div class="card-header">
                    <div class="card-logo">SCHOBANK</div>
                    <div class="card-level"><?php echo htmlspecialchars($card_info['level']); ?></div>
                </div>
                <div class="account-number"><?php echo htmlspecialchars($formatted_no_rekening); ?></div>
                <div class="card-footer">
                    <div>
                        <div class="account-holder">Pemilik Rekening</div>
                        <div class="holder-name"><?php echo htmlspecialchars(strtoupper($user_data['nama'])); ?></div>
                    </div>
                    <div>
                        <div class="balance-label">Saldo</div>
                        <div class="balance-amount" id="balanceAmount">Rp <?php echo number_format($saldo, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!$has_pin): ?>
        <div class="alert">
            <i class="fas fa-exclamation-triangle alert-icon"></i>
            <div class="alert-content">
                <strong>PIN Belum Diatur!</strong> Segera atur PIN untuk keamanan akun Anda. <a href="profil.php#account-tab" class="alert-link">Atur Sekarang</a>
            </div>
            <button class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <?php if (!$has_email): ?>
        <div class="alert">
            <i class="fas fa-exclamation-triangle alert-icon"></i>
            <div class="alert-content">
                <strong>Email Belum Ditambahkan!</strong> Tambahkan email untuk keamanan ekstra. <a href="profil.php#account-tab" class="alert-link">Tambahkan Email</a>
            </div>
            <button class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php endif; ?>

        <!-- Payment Section -->
        <div class="payment-section">
            <h2 class="section-title">Mau Bayar Apa?</h2>
            <div class="infaq-feature <?php echo !$has_pin ? 'disabled' : ''; ?>"
                 onclick="<?php echo $has_pin ? 'goToInfaq()' : 'showPinAlert()'; ?>">
                <img src="<?php echo $base_url; ?>/assets/images/infaq.png" alt="Bayar Infaq Sekolah" class="infaq-icon">
                <span class="infaq-label">Infaq</span>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="transaction-section">
            <div class="transaction-header">
                <h2 class="section-title">Transaksi Terakhir</h2>
                <a href="cek_mutasi.php" class="view-all-link">
                    Lihat Semua <i class="fas fa-arrow-right"></i>
                </a>
            </div>

            <div class="transaction-list">
                <?php if (count($recent_transactions) > 0): ?>
                    <?php foreach ($recent_transactions as $transaction): ?>
                        <?php
                        $is_incoming = ($transaction['jenis_transaksi'] == 'setor' ||
                                        ($transaction['jenis_transaksi'] == 'transfer' && $transaction['rekening_tujuan'] == $no_rekening) ||
                                        ($transaction['jenis_transaksi'] == 'transaksi_qr' && $transaction['rekening_tujuan'] == $no_rekening) ||
                                        ($transaction['jenis_transaksi'] == 'infaq' && $transaction['rekening_tujuan'] == $no_rekening));
                        
                        $icon_class = '';
                        $icon = '';
                        $amount_class = '';
                        $amount_prefix = '';
                        
                        if ($transaction['jenis_transaksi'] == 'infaq') {
                            $icon_class = 'infaq';
                            $icon = 'fa-hand-holding-heart';
                            $amount_class = 'infaq';
                            $amount_prefix = $transaction['rekening_asal'] == $no_rekening ? '-' : '+';
                        } elseif ($transaction['jenis_transaksi'] == 'transaksi_qr') {
                            $icon_class = 'qr';
                            $icon = 'fa-qrcode';
                            $amount_class = $is_incoming ? 'in' : 'out';
                            $amount_prefix = $is_incoming ? '+' : '-';
                        } else {
                            $icon_class = $is_incoming ? 'in' : 'out';
                            $icon = $is_incoming ? 'fa-arrow-down' : 'fa-arrow-up';
                            $amount_class = $is_incoming ? 'in' : 'out';
                            $amount_prefix = $is_incoming ? '+' : '-';
                        }
                        
                        $description = match($transaction['jenis_transaksi']) {
                            'setor' => 'Setoran Tunai',
                            'tarik' => 'Penarikan Tunai',
                            'transfer' => $transaction['rekening_asal'] == $no_rekening ?
                                        'Transfer ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A') :
                                        'Transfer dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A'),
                            'transaksi_qr' => $transaction['rekening_asal'] == $no_rekening ?
                                            'Pembayaran QR ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A') :
                                            'Penerimaan QR dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A'),
                            'infaq' => $transaction['rekening_asal'] == $no_rekening ?
                                     'Infaq ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A') :
                                     'Penerimaan Infaq dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A'),
                            default => 'Transaksi'
                        };
                        ?>
                        <div class="transaction-item">
                            <div class="transaction-icon <?php echo $icon_class; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                            </div>
                            <div class="transaction-details">
                                <div class="transaction-title"><?php echo $description; ?></div>
                                <div class="transaction-time"><?php echo formatIndonesianDate($transaction['created_at']); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div class="transaction-amount <?php echo $amount_class; ?>">
                                    <?php echo $amount_prefix; ?> Rp<?php echo number_format($transaction['jumlah'], 0, ',', '.'); ?>
                                </div>
                                <span class="transaction-status <?php echo strtolower($transaction['status']); ?>">
                                    <?php echo ucfirst($transaction['status']); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <div>Belum ada transaksi</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation - Included from separate file -->
    <?php include 'bottom_navbar.php'; ?>

    <!-- Notification Panel -->
    <div class="notification-dropdown">
        <div class="notification-panel" id="notificationPanel">
            <div class="notification-header">
                <div class="notification-header-title">
                    <i class="fas fa-bell"></i>
                    <span>Notifikasi</span>
                </div>
                <?php if ($unread_notifications > 0): ?>
                <span class="notification-count"><?php echo $unread_notifications; ?> Baru</span>
                <?php endif; ?>
            </div>
            <div class="notification-body">
                <?php if ($notifications_result->num_rows > 0): ?>
                    <?php
                    $notifications_result->data_seek(0);
                    while ($row = $notifications_result->fetch_assoc()):
                        // Notification logic
                        $message_lower = strtolower($row['message']);
                        if (
                            stripos($message_lower, 'username') !== false ||
                            stripos($message_lower, 'password') !== false ||
                            stripos($message_lower, 'pin') !== false ||
                            stripos($message_lower, 'email') !== false ||
                            stripos($message_lower, 'alamat') !== false ||
                            stripos($message_lower, 'keamanan') !== false ||
                            stripos($message_lower, 'kelas') !== false ||
                            stripos($message_lower, 'diubah') !== false ||
                            stripos($message_lower, 'diperbarui') !== false ||
                            stripos($message_lower, 'dibuat') !== false
                        ) {
                            $category = 'keamanan';
                        }
                        elseif (
                            stripos($message_lower, 'setor') !== false ||
                            stripos($message_lower, 'tarik') !== false ||
                            stripos($message_lower, 'transfer') !== false ||
                            stripos($message_lower, 'qr') !== false ||
                            stripos($message_lower, 'pembayaran') !== false ||
                            stripos($message_lower, 'berhasil') !== false ||
                            stripos($message_lower, 'menerima') !== false
                        ) {
                            $category = 'transaksi';
                        }
                        elseif (stripos($message_lower, 'info') !== false) {
                            $category = 'info';
                        } else {
                            $category = 'pengumuman';
                        }
                        
                        $icon = 'fa-exchange-alt';
                        $is_read = $row['is_read'] == 1;
                        $read_status_text = $is_read ? 'Dibaca' : 'Belum Dibaca';
                        $read_status_class = $is_read ? 'read' : 'unread';
                    ?>
                    <div class="notification-item <?php echo !$is_read ? 'unread' : ''; ?>" onclick="markAsRead(<?php echo $row['id']; ?>)">
                        <div class="notification-icon-wrapper">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="notification-content">
                            <div class="notification-top">
                                <span class="notification-category"><?php echo htmlspecialchars($category); ?></span>
                                <span class="notification-read-status <?php echo $read_status_class; ?>"><?php echo $read_status_text; ?></span>
                            </div>
                            <div class="notification-message"><?php echo htmlspecialchars($row['message']); ?></div>
                            <div class="notification-time">
                                <i class="far fa-clock"></i>
                                <?php echo formatIndonesianDate($row['created_at']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="notification-empty">
                        <div class="notification-empty-icon">🔔</div>
                        <div class="notification-empty-text">Tidak ada notifikasi</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Prevent double-tap zoom
        document.addEventListener('touchstart', (e) => {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, { passive: false });

        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            panel.classList.toggle('show');
        }

        document.addEventListener('click', (e) => {
            const panel = document.getElementById('notificationPanel');
            const btn = e.target.closest('.nav-btn');
            
            if (!panel.contains(e.target) && !btn) {
                panel.classList.remove('show');
            }
        });

        function markAsRead(notificationId) {
            fetch('mark_notification_as_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function confirmLogout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Anda yakin ingin keluar dari akun?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2c3e50',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Ya, Logout',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '<?php echo $base_url; ?>/logout.php';
                }
            });
        }

        function showPinAlert() {
            Swal.fire({
                icon: 'warning',
                title: 'PIN Diperlukan',
                text: 'Harap atur PIN terlebih dahulu untuk menggunakan fitur Infaq.',
                confirmButtonColor: '#2c3e50',
                confirmButtonText: 'Mengerti'
            });
        }

        function goToInfaq() {
            window.location.href = 'infaq.php';
        }

        function animateBalance() {
            const balanceElement = document.getElementById('balanceAmount');
            const finalBalance = <?php echo $saldo; ?>;
            const text = balanceElement.textContent;
            let currentBalance = 0;
            const increment = finalBalance / 60;
            const duration = 1500;
            const stepTime = duration / 60;

            function updateBalance() {
                if (currentBalance < finalBalance) {
                    currentBalance += increment;
                    if (currentBalance > finalBalance) {
                        currentBalance = finalBalance;
                    }
                    balanceElement.textContent = 'Rp ' + Math.floor(currentBalance).toLocaleString('id-ID');
                    setTimeout(updateBalance, stepTime);
                } else {
                    balanceElement.textContent = text;
                }
            }

            updateBalance();
        }

        document.addEventListener('DOMContentLoaded', () => {
            animateBalance();
        });
    </script>
</body>
</html>
