<?php
/**
 * Dashboard Siswa - Modern Digital Banking UI
 * File: pages/siswa/dashboard.php
 * Features: Premium design, Quick Actions, Enhanced Balance Card
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

if (basename($current_dir) === 'user' || basename($current_dir) === 'siswa') {
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

$user_id = $_SESSION['user_id'];

// Fetch user data
$query = "SELECT
    u.nama, u.username, u.email,
    COALESCE(us.is_frozen, 0) as is_frozen,
    COALESCE(us.pin_block_until, '1970-01-01') as pin_block_until,
    COALESCE(us.pin, '') as pin,
    COALESCE(r.saldo, 0) as saldo,
    r.id as rekening_id,
    r.no_rekening,
    sp.tanggal_lahir,
    sp.jenis_kelamin,
    sp.nis_nisn,
    sp.alamat_lengkap,
    sp.avatar
FROM users u
LEFT JOIN user_security us ON u.id = us.user_id
LEFT JOIN rekening r ON u.id = r.user_id
LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
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

function getAccountStatus($user_data, $current_time)
{
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

// Function to determine card level and style based on balance - TIERED MEMBERSHIP SYSTEM
function getCardLevelAndStyle($saldo)
{
    // Warna kartu sama untuk semua level (warna asli)
    $defaultStyle = [
        'gradient' => 'linear-gradient(135deg, #1a252f 0%, #2c3e50 50%, #34495e 100%)',
        'glow' => 'rgba(44, 62, 80, 0.5)'
    ];

    if ($saldo <= 50000) {
        // BRONZE: Saldo 0 - 50.000
        return array_merge($defaultStyle, ['level' => 'BRONZE']);
    } elseif ($saldo <= 200000) {
        // SILVER: Saldo 50.001 - 200.000
        return array_merge($defaultStyle, ['level' => 'SILVER']);
    } elseif ($saldo <= 500000) {
        // GOLD: Saldo 200.001 - 500.000
        return array_merge($defaultStyle, ['level' => 'GOLD']);
    }
    // PLATINUM: Saldo > 500.000
    return array_merge($defaultStyle, ['level' => 'PLATINUM']);
}

$card_info = getCardLevelAndStyle($saldo);

// Calculate Profile Completion Percentage
$profile_fields = [
    'tanggal_lahir' => !empty($user_data['tanggal_lahir']),
    'jenis_kelamin' => !empty($user_data['jenis_kelamin']),
    'nis_nisn' => !empty($user_data['nis_nisn']),
    'alamat_lengkap' => !empty($user_data['alamat_lengkap']),
    'pin' => !empty($user_data['pin'])
];

$completed_fields = array_filter($profile_fields);
$profile_completion = count($completed_fields);
$total_fields = count($profile_fields);
$profile_percentage = round(($profile_completion / $total_fields) * 100);

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

// Recent transactions
$recent_transactions = [];
if ($rekening_id) {
    $query = "SELECT
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
        WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?
        ORDER BY t.created_at DESC
        LIMIT 5";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $rekening_id, $rekening_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['status'] = match ($row['status']) {
            'approved' => 'berhasil',
            'pending' => 'menunggu',
            'rejected' => 'gagal',
            default => $row['status']
        };
        $recent_transactions[] = $row;
    }
}

// Function to format date in Indonesian
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
    return $dateObj->format('d') . ' ' . $months[(int) $dateObj->format('m')] . ' ' . $dateObj->format('Y, H:i') . ' WIB';
}

// Determine greeting based on time
$hour = date('H');
if ($hour >= 0 && $hour < 11) {
    $time_greeting = 'Selamat Pagi';
    $greeting_icon = '☀️';
} elseif ($hour >= 11 && $hour < 15) {
    $time_greeting = 'Selamat Siang';
    $greeting_icon = '🌤️';
} elseif ($hour >= 15 && $hour < 18) {
    $time_greeting = 'Selamat Sore';
    $greeting_icon = '🌅';
} else {
    $time_greeting = 'Selamat Malam';
    $greeting_icon = '🌙';
}

// Get first name only
$nama_parts = explode(' ', $user_data['nama']);
$first_name = ucfirst(strtolower($nama_parts[0]));

// Get initials for avatar (first name + second/middle name if exists)
$initials = strtoupper(substr($nama_parts[0], 0, 1));
if (count($nama_parts) > 1) {
    // Always use second name (middle name), not last name
    $initials .= strtoupper(substr($nama_parts[1], 0, 1));
}

// Current date in Indonesian
$bulan_indonesia = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$hari_indonesia = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
$hari_ini = $hari_indonesia[date('w')] . ', ' . date('d') . ' ' . $bulan_indonesia[(int) date('n')] . ' ' . date('Y');
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Dashboard - KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Original Dark/Elegant Theme */
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
            background: #f1f5f9;
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            padding-top: 0;
            padding-bottom: 100px;
            min-height: 100vh;
        }

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f1f5f9 100%);
            padding: 20px 20px 140px;
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
            margin-bottom: 24px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            color: var(--white);
            border: 2px solid rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .user-greeting {
            color: var(--white);
        }

        .greeting-text {
            font-size: 13px;
            opacity: 0.9;
            font-weight: 400;
        }

        .user-name {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            backdrop-filter: blur(10px);
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .header-btn i {
            font-size: 18px;
        }

        .notification-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: var(--danger);
            color: var(--white);
            width: 18px;
            height: 18px;
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--primary);
        }

        .header-date {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Connection Quality Indicator */
        .connection-indicator {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            backdrop-filter: blur(10px);
        }

        .connection-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #10b981;
            transition: all 0.3s ease;
            box-shadow: 0 0 8px currentColor;
        }

        .connection-dot.good {
            background: #10b981;
            box-shadow: 0 0 8px #10b981;
            animation: pulse-good 2s infinite;
        }

        .connection-dot.slow {
            background: #f59e0b;
            box-shadow: 0 0 8px #f59e0b;
            animation: pulse-slow 1.5s infinite;
        }

        .connection-dot.poor {
            background: #ef4444;
            box-shadow: 0 0 8px #ef4444;
            animation: pulse-poor 1s infinite;
        }

        .connection-dot.offline {
            background: #6b7280;
            box-shadow: none;
        }

        @keyframes pulse-good {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.1);
                opacity: 0.8;
            }
        }

        @keyframes pulse-slow {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.15);
                opacity: 0.7;
            }
        }

        @keyframes pulse-poor {

            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.2);
                opacity: 0.6;
            }
        }

        /* Header Balance Section */
        .header-balance {
            margin-top: 16px;
        }

        .header-balance-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }

        .header-balance-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-balance-amount {
            color: var(--white);
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
            transition: font-size 0.3s ease;
        }

        .header-balance-amount.digits-8 {
            font-size: 18px;
        }

        .header-balance-amount.digits-9 {
            font-size: 17px;
        }

        .header-balance-amount.digits-10 {
            font-size: 16px;
        }

        .header-balance-amount.digits-11 {
            font-size: 15px;
        }

        .header-balance-amount.digits-12 {
            font-size: 14px;
        }

        .balance-decimal {
            font-size: 0.6em;
            opacity: 0.8;
            font-weight: 500;
        }

        .header-balance-toggle {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: var(--white);
            width: 28px;
            height: 28px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            backdrop-filter: blur(10px);
        }

        .header-balance-toggle:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .header-balance-toggle i {
            font-size: 12px;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -40px;
            position: relative;
            z-index: 10;
        }

        /* ============================================ */
        /* ACCOUNT INFO SECTION - CLEAN DESIGN */
        /* ============================================ */
        .balance-card {
            background: linear-gradient(135deg, #1a252f 0%, #2c3e50 50%, #34495e 100%);
            border-radius: var(--radius-xl);
            padding: 20px 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .balance-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 60%);
            pointer-events: none;
        }

        .card-content {
            position: relative;
            z-index: 2;
            color: var(--white);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .account-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px dashed var(--gray-200);
        }

        .card-brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-logo {
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: var(--white);
            margin-bottom: 16px;
        }

        .card-level {
            background: rgba(255, 255, 255, 0.15);
            color: var(--white);
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 10px;
            font-weight: 600;
            align-self: flex-end;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .account-number {
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 1px;
            color: var(--white);
        }

        .account-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-100) 0%, var(--gray-200) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 18px;
        }

        .card-bottom {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .card-holder {
            flex: 1;
        }

        .holder-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.7;
            margin-bottom: 4px;
            color: rgba(255, 255, 255, 0.7);
        }

        .holder-name {
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            transition: font-size 0.3s ease;
            word-break: break-word;
        }

        /* Responsive font sizes for long holder names */
        .holder-name.long-name {
            font-size: 13px;
        }

        .holder-name.very-long-name {
            font-size: 12px;
        }

        .card-balance {
            text-align: right;
        }

        .card-balance-top {
            margin-bottom: 16px;
        }

        .card-balance-top .balance-label {
            margin-bottom: 6px;
        }

        .card-balance-top .balance-amount {
            font-size: 28px;
        }

        .balance-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.7;
            margin-bottom: 4px;
        }

        .balance-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .balance-amount {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -0.5px;
            transition: font-size 0.3s ease;
        }

        /* Responsive font sizes for large balance amounts */
        .balance-amount.digits-8 {
            font-size: 20px;
        }

        .balance-amount.digits-9 {
            font-size: 18px;
        }

        .balance-amount.digits-10 {
            font-size: 16px;
        }

        .balance-amount.digits-11 {
            font-size: 14px;
        }

        .balance-amount.digits-12 {
            font-size: 13px;
        }

        .balance-toggle {
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: var(--white);
            width: 32px;
            height: 32px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .balance-toggle:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .balance-toggle i {
            font-size: 14px;
        }

        /* ============================================ */
        /* QUICK ACTIONS - EXISTING MENUS ONLY */
        /* ============================================ */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 28px;
        }

        .action-item {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--gray-700);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
        }

        .action-item:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .action-item:active {
            transform: translateY(-2px);
        }

        .action-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-size: 20px;
        }

        .action-icon.transfer {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .action-icon.payment {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .action-icon.history {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .action-icon.activity {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        /* ============================================ */
        /* LEADERBOARD BANNER */
        /* ============================================ */
        .leaderboard-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-top: 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            text-decoration: none;
        }

        .leaderboard-banner:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .leaderboard-icon {
            width: 50px;
            height: 50px;
            border-radius: var(--radius);
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .leaderboard-content {
            flex: 1;
        }

        .leaderboard-title {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .leaderboard-desc {
            font-size: 12px;
            color: var(--gray-600);
        }

        .leaderboard-arrow {
            color: var(--gray-500);
            font-size: 16px;
        }

        .action-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-700);
        }

        /* ============================================ */
        /* PROFILE COMPLETION BANNER */
        /* ============================================ */
        .profile-banner {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid var(--gray-200);
            text-decoration: none;
        }

        .profile-banner:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .profile-progress {
            position: relative;
            width: 50px;
            height: 50px;
            flex-shrink: 0;
        }

        .profile-progress svg {
            transform: rotate(-90deg);
            width: 50px;
            height: 50px;
        }

        .profile-progress .circle-bg {
            fill: none;
            stroke: rgba(0, 0, 0, 0.1);
            stroke-width: 5;
        }

        .profile-progress .circle-fill {
            fill: none;
            stroke: var(--elegant-dark);
            stroke-width: 5;
            stroke-linecap: round;
        }

        .profile-progress .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: 700;
            color: var(--gray-800);
        }

        .profile-info {
            flex: 1;
        }

        .profile-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .profile-title i {
            font-size: 12px;
        }

        .profile-desc {
            font-size: 11px;
            color: var(--gray-600);
        }

        .profile-arrow {
            color: var(--gray-600);
            font-size: 14px;
        }

        /* ============================================ */
        /* SECTION TITLE */
        /* ============================================ */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .section-link {
            font-size: 12px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .section-link:hover {
            text-decoration: underline;
        }

        /* ============================================ */
        /* TRANSACTION LIST - COMPACT CARD STYLE */
        /* ============================================ */
        .transaction-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
            border: 1px solid var(--gray-100);
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .transaction-item:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            border-color: var(--gray-200);
        }

        .transaction-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
        }

        .transaction-icon.in {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .transaction-icon.out {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .transaction-icon.infaq {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: var(--gray-600);
        }

        .transaction-details {
            flex: 1;
            min-width: 0;
        }

        .transaction-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .transaction-meta {
            font-size: 10px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .transaction-meta i {
            font-size: 9px;
        }

        .transaction-right {
            text-align: right;
            flex-shrink: 0;
        }

        .transaction-amount {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .transaction-amount.in {
            color: var(--gray-900);
        }

        .transaction-amount.out {
            color: var(--gray-900);
        }

        .transaction-status {
            font-size: 10px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 20px;
            display: inline-block;
        }

        .transaction-status.berhasil {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-700);
        }

        .transaction-status.menunggu {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning);
        }

        .transaction-status.gagal {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        /* ============================================ */
        /* SECURITY ALERT BANNER - INTERACTIVE */
        /* ============================================ */
        .security-alert {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: var(--radius);
            padding: 14px 16px;
            margin-bottom: 20px;
            border: 1px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .security-alert:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .security-alert-header {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .security-alert-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(100, 116, 139, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--gray-600);
            font-size: 14px;
            animation: pulse-icon 2s infinite;
        }

        @keyframes pulse-icon {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .security-alert-content {
            flex: 1;
            min-width: 0;
        }

        .security-alert-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .security-alert-title .tip-number {
            background: var(--gray-600);
            color: white;
            font-size: 9px;
            padding: 1px 6px;
            border-radius: 10px;
        }

        .security-alert-slider {
            position: relative;
            height: 32px;
            overflow: hidden;
        }

        .security-tip {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            font-size: 11px;
            color: var(--gray-600);
            line-height: 1.5;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.4s ease;
        }

        .security-tip.active {
            opacity: 1;
            transform: translateY(0);
        }

        .security-alert-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px dashed var(--gray-300);
        }

        .security-dots {
            display: flex;
            gap: 6px;
        }

        .security-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--gray-300);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .security-dot.active {
            background: var(--gray-600);
            width: 18px;
            border-radius: 3px;
        }

        .security-nav-btn {
            background: none;
            border: none;
            color: var(--gray-600);
            font-size: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .security-nav-btn:hover {
            background: rgba(100, 116, 139, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--white);
            border-radius: var(--radius-xl);
            border: 2px dashed var(--gray-200);
        }

        .empty-icon {
            font-size: 56px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        .empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }

        /* ============================================ */
        /* NOTIFICATION DROPDOWN - ORIGINAL STYLE */
        /* ============================================ */
        .notification-dropdown {
            position: relative;
        }

        .notification-panel {
            position: fixed;
            top: 70px;
            right: 20px;
            width: 380px;
            max-width: calc(100vw - 40px);
            max-height: 500px;
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

        .notif-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 700;
            font-size: 15px;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .notif-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 700;
        }

        .notif-title i {
            color: var(--elegant-dark);
            font-size: 16px;
        }

        .notif-count {
            background: var(--elegant-dark);
            color: var(--white);
            font-size: 10px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
        }

        .notif-body {
            max-height: 400px;
            overflow-y: auto;
        }

        .notif-body::-webkit-scrollbar {
            width: 6px;
        }

        .notif-body::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .notif-body::-webkit-scrollbar-thumb {
            background: var(--gray-300);
            border-radius: 10px;
        }

        .notif-item {
            padding: 14px 20px;
            border-bottom: 1px solid var(--gray-100);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .notif-item:hover {
            background: var(--gray-50);
        }

        .notif-item.unread {
            background: rgba(44, 62, 80, 0.03);
        }

        .notif-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 14px;
            background: linear-gradient(135deg, var(--elegant-gray) 0%, var(--elegant-dark) 100%);
            color: var(--white);
        }

        .notif-content {
            flex: 1;
            min-width: 0;
        }

        .notif-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 4px;
            gap: 8px;
        }

        .notif-category {
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

        .notif-status {
            font-size: 9px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .notif-status.read {
            background: rgba(107, 114, 128, 0.1);
            color: var(--gray-600);
        }

        .notif-status.unread {
            background: rgba(44, 62, 80, 0.15);
            color: var(--elegant-dark);
        }

        .notif-message {
            font-size: 12px;
            color: var(--gray-800);
            margin-bottom: 6px;
            line-height: 1.5;
            font-weight: 500;
        }

        .notif-time {
            font-size: 10px;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .notif-time i {
            font-size: 9px;
        }

        .notif-action {
            font-size: 11px;
            color: var(--secondary);
            font-weight: 600;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
        }

        .notif-action:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .notif-action i {
            font-size: 10px;
        }

        .notif-empty {
            padding: 60px 24px;
            text-align: center;
        }

        .notif-empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.2;
        }

        .notif-empty-text {
            font-size: 14px;
            color: var(--gray-500);
        }

        @media (max-width: 768px) {
            .notification-panel {
                right: 10px;
                left: 10px;
                width: auto;
                top: 65px;
            }

            .notif-header {
                padding: 16px 18px;
            }

            .notif-item {
                padding: 12px 16px;
            }

            .notif-icon {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .notif-message {
                font-size: 11px;
            }
        }

        /* ============================================ */
        /* RESPONSIVE */
        /* ============================================ */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .top-header {
                padding: 16px 16px 70px;
            }

            .user-avatar {
                width: 42px;
                height: 42px;
                font-size: 14px;
            }

            .user-name {
                font-size: 16px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
            }

            .main-container {
                padding: 0 16px;
            }

            .balance-card {
                padding: 20px;
                border-radius: var(--radius-lg);
            }

            .account-number {
                font-size: 16px;
                letter-spacing: 2px;
            }

            .balance-amount {
                font-size: 20px;
            }

            .quick-actions {
                gap: 10px;
            }

            .action-item {
                padding: 14px 6px;
            }

            .action-icon {
                width: 42px;
                height: 42px;
                font-size: 18px;
            }

            .action-label {
                font-size: 10px;
            }

            .transaction-item {
                padding: 14px 16px;
            }

            .transaction-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
        }

        @media (max-width: 480px) {
            .top-header {
                padding: 14px 14px 65px;
            }

            .user-avatar {
                width: 38px;
                height: 38px;
            }

            .greeting-text {
                font-size: 12px;
            }

            .user-name {
                font-size: 15px;
            }

            .balance-card {
                padding: 18px;
            }

            .account-number {
                font-size: 14px;
                letter-spacing: 1.5px;
            }

            .balance-amount {
                font-size: 18px;
            }

            .balance-amount.digits-8 {
                font-size: 16px;
            }

            .balance-amount.digits-9 {
                font-size: 15px;
            }

            .balance-amount.digits-10 {
                font-size: 14px;
            }

            .balance-amount.digits-11 {
                font-size: 12px;
            }

            .balance-amount.digits-12 {
                font-size: 11px;
            }

            .holder-name {
                font-size: 11px;
            }

            .holder-name.long-name {
                font-size: 10px;
            }

            .holder-name.very-long-name {
                font-size: 9px;
            }

            .card-chip {
                width: 35px;
                height: 26px;
            }

            .action-icon {
                width: 38px;
                height: 38px;
                font-size: 16px;
                margin-bottom: 8px;
            }

            .action-label {
                font-size: 9px;
            }
        }

        @media (max-width: 360px) {
            .quick-actions {
                gap: 8px;
            }

            .action-item {
                padding: 12px 4px;
            }

            .action-icon {
                width: 34px;
                height: 34px;
                font-size: 14px;
            }

            .balance-amount {
                font-size: 16px;
            }

            .balance-amount.digits-8 {
                font-size: 14px;
            }

            .balance-amount.digits-9 {
                font-size: 13px;
            }

            .balance-amount.digits-10 {
                font-size: 12px;
            }

            .balance-amount.digits-11 {
                font-size: 11px;
            }

            .balance-amount.digits-12 {
                font-size: 10px;
            }

            .holder-name.very-long-name {
                font-size: 8px;
            }
        }

        /* ============================================ */
        /* ANIMATIONS */
        /* ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .balance-card {
            animation: fadeInUp 0.5s ease;
        }

        .quick-actions {
            animation: fadeInUp 0.5s ease 0.1s both;
        }

        .transaction-list {
            animation: fadeInUp 0.5s ease 0.2s both;
        }

        /* Shimmer effect for card */
        .balance-card .shimmer {
            position: absolute;
            top: 0;
            left: -150%;
            width: 50%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.08), transparent);
            animation: shimmer 3s infinite;
        }

        @keyframes shimmer {
            0% {
                left: -150%;
            }

            50% {
                left: 150%;
            }

            100% {
                left: 150%;
            }
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="top-header">
        <div class="header-content">
            <div class="header-top">
                <div class="user-info">
                    <?php
                    $avatar_style = $user_data['avatar'] ?? 'lorelei';
                    $avatar_seed = urlencode($user_data['nama']);
                    ?>
                    <div class="user-avatar">
                        <?php if ($avatar_style === 'initials'): ?>
                            <?php echo $initials; ?>
                        <?php else: ?>
                            <?php $avatar_url = "https://api.dicebear.com/7.x/{$avatar_style}/svg?seed={$avatar_seed}"; ?>
                            <img src="<?php echo $avatar_url; ?>" alt="Avatar"
                                style="width: 100%; height: 100%; border-radius: 50%;">
                        <?php endif; ?>
                    </div>
                    <div class="user-greeting">
                        <div class="greeting-text"><?php echo $time_greeting; ?> <?php echo $greeting_icon; ?></div>
                        <div class="user-name"><?php echo htmlspecialchars($user_data['nama']); ?></div>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="header-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_notifications > 0): ?>
                            <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                        <?php endif; ?>
                    </button>
                </div>
            </div>
            <!-- Balance Display in Header -->
            <div class="header-balance">
                <div class="header-balance-label">Saldo Anda</div>
                <div class="header-balance-row">
                    <?php
                    // Determine font size class based on balance digit count
                    $saldo_formatted = number_format($saldo, 0, ',', '.');
                    $saldo_length = strlen(str_replace([',', '.'], '', $saldo_formatted));
                    $balance_class = 'header-balance-amount';
                    if ($saldo_length >= 12) {
                        $balance_class .= ' digits-12';
                    } elseif ($saldo_length >= 11) {
                        $balance_class .= ' digits-11';
                    } elseif ($saldo_length >= 10) {
                        $balance_class .= ' digits-10';
                    } elseif ($saldo_length >= 9) {
                        $balance_class .= ' digits-9';
                    } elseif ($saldo_length >= 8) {
                        $balance_class .= ' digits-8';
                    }
                    ?>
                    <div class="<?php echo $balance_class; ?>" id="balanceAmount">Rp
                        <?php echo $saldo_formatted; ?><span class="balance-decimal">,00</span>
                    </div>
                    <button class="header-balance-toggle" onclick="toggleBalance()" id="balanceToggle">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Balance Card -->
        <div class="balance-card"
            style="background: <?php echo $card_info['gradient']; ?>; box-shadow: 0 20px 40px <?php echo $card_info['glow']; ?>;">
            <div class="shimmer"></div>
            <div class="card-content">
                <div class="card-logo">KASDIG <?php echo htmlspecialchars($card_info['level']); ?></div>
                <div class="account-row">
                    <div class="account-number"><?php echo htmlspecialchars($formatted_no_rekening); ?></div>
                </div>
                <div class="card-bottom">
                    <div class="card-holder">
                        <div class="holder-label">Pemilik Rekening</div>
                        <?php
                        // Determine font size class based on name length
                        $nama_length = strlen($user_data['nama']);
                        $holder_class = 'holder-name';
                        if ($nama_length > 25) {
                            $holder_class .= ' very-long-name';
                        } elseif ($nama_length > 18) {
                            $holder_class .= ' long-name';
                        }
                        ?>
                        <div class="<?php echo $holder_class; ?>"><?php echo htmlspecialchars($user_data['nama']); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions - ONLY EXISTING MENUS -->
        <div class="quick-actions">
            <a href="transfer.php" class="action-item">
                <div class="action-icon transfer">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <div class="action-label">Transfer</div>
            </a>
            <a href="pembayaran.php" class="action-item">
                <div class="action-icon payment">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="action-label">Tagihan</div>
            </a>
            <a href="mutasi.php" class="action-item">
                <div class="action-icon history">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="action-label">Mutasi</div>
            </a>
            <a href="aktivitas.php" class="action-item">
                <div class="action-icon activity">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="action-label">Aktivitas</div>
            </a>
        </div>

        <!-- Security Alert Banner (Interactive) -->
        <div class="security-alert" id="securityAlert">
            <div class="security-alert-header">
                <div class="security-alert-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="security-alert-content">
                    <div class="security-alert-title">
                        Tips Keamanan
                        <span class="tip-number" id="tipNumber">1/4</span>
                    </div>
                    <div class="security-alert-slider">
                        <div class="security-tip active" data-tip="0">Jangan pernah membagikan PIN, password, atau
                            kode
                            OTP kepada siapapun.</div>
                        <div class="security-tip" data-tip="1">Pastikan alamat website benar sebelum memasukkan data
                            login.</div>
                        <div class="security-tip" data-tip="2">Ganti password secara berkala untuk keamanan akun
                            Anda.
                        </div>
                        <div class="security-tip" data-tip="3">Segera hubungi admin jika ada aktivitas mencurigakan
                            di
                            akun Anda.</div>
                    </div>
                </div>
            </div>
            <div class="security-alert-nav">
                <div class="security-dots">
                    <span class="security-dot active" data-index="0"></span>
                    <span class="security-dot" data-index="1"></span>
                    <span class="security-dot" data-index="2"></span>
                    <span class="security-dot" data-index="3"></span>
                </div>
                <button class="security-nav-btn" onclick="nextSecurityTip()">
                    Selanjutnya <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </div>

        <!-- Leaderboard Banner -->
        <a href="leaderboard.php" class="leaderboard-banner">
            <div class="leaderboard-icon">🏆</div>
            <div class="leaderboard-content">
                <div class="leaderboard-title">
                    Leaderboard <i class="fas fa-arrow-right"></i>
                </div>
                <div class="leaderboard-desc">Lihat peringkat tabungan kamu</div>
            </div>
        </a>

        <!-- Profile Completion Banner -->
        <?php if ($profile_percentage < 100):
            $circumference = 100.53;
            $offset = $circumference - ($profile_percentage / 100) * $circumference;
            ?>
            <a href="profil.php" class="profile-banner">
                <div class="profile-progress">
                    <svg viewBox="0 0 36 36">
                        <circle class="circle-bg" cx="18" cy="18" r="16"></circle>
                        <circle class="circle-fill" cx="18" cy="18" r="16" stroke-dasharray="<?php echo $circumference; ?>"
                            stroke-dashoffset="<?php echo $offset; ?>">
                        </circle>
                    </svg>
                    <div class="progress-text"><?php echo $profile_percentage; ?>%</div>
                </div>
                <div class="profile-info">
                    <div class="profile-title">
                        Lengkapi Profil Kamu <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="profile-desc"><?php echo $profile_completion; ?>/<?php echo $total_fields; ?> data sudah
                        lengkap</div>
                </div>
            </a>
        <?php endif; ?>

        <!-- Recent Transactions -->
        <div class="section-header">
            <h2 class="section-title">Transaksi Terakhir</h2>
            <a href="mutasi.php" class="section-link">
                Lihat Semua <i class="fas fa-chevron-right"></i>
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

                    $nama_penerima = $transaction['nama_penerima'] ?? '';
                    $is_transfer_to_bendahara = (stripos($nama_penerima, 'bendahar') !== false);

                    if ($is_transfer_to_bendahara && $transaction['jenis_transaksi'] == 'transfer' && !$is_incoming) {
                        $icon_class = 'infaq';
                        $icon = 'fa-hand-holding-heart';
                        $description = 'Infaq Bulanan';
                        $amount_class = 'out';
                        $amount_prefix = '-';
                    } elseif ($transaction['jenis_transaksi'] == 'infaq') {
                        $icon_class = 'infaq';
                        $icon = 'fa-hand-holding-heart';
                        $amount_class = $transaction['rekening_asal'] == $no_rekening ? 'out' : 'in';
                        $amount_prefix = $transaction['rekening_asal'] == $no_rekening ? '-' : '+';
                        $description = $transaction['rekening_asal'] == $no_rekening ?
                            'Infaq ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A') :
                            'Infaq dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A');
                    } elseif ($transaction['jenis_transaksi'] == 'transaksi_qr') {
                        $icon_class = $is_incoming ? 'in' : 'out';
                        $icon = 'fa-qrcode';
                        $amount_class = $is_incoming ? 'in' : 'out';
                        $amount_prefix = $is_incoming ? '+' : '-';
                        $description = $transaction['rekening_asal'] == $no_rekening ?
                            'Pembayaran QR' : 'Terima via QR';
                    } else {
                        $icon_class = $is_incoming ? 'in' : 'out';

                        if ($transaction['jenis_transaksi'] == 'setor') {
                            $icon = 'fa-arrow-down';
                        } elseif ($transaction['jenis_transaksi'] == 'tarik') {
                            $icon = 'fa-arrow-up';
                        } elseif ($transaction['jenis_transaksi'] == 'transfer') {
                            $icon = $is_incoming ? 'fa-arrow-down' : 'fa-arrow-up';
                        } else {
                            $icon = $is_incoming ? 'fa-arrow-down' : 'fa-arrow-up';
                        }

                        $amount_class = $is_incoming ? 'in' : 'out';
                        $amount_prefix = $is_incoming ? '+' : '-';

                        $description = match ($transaction['jenis_transaksi']) {
                            'setor' => 'Setoran Tunai',
                            'tarik' => 'Penarikan Tunai',
                            'bayar' => (function () use ($transaction) {
                                    $keterangan = $transaction['keterangan'] ?? '';
                                    if (strpos($keterangan, 'Pembayaran ') === 0) {
                                        return $keterangan; // Sudah format "Pembayaran NamaItem"
                                    }
                                    return 'Pembayaran Tagihan';
                                })(),
                            'transfer' => $transaction['rekening_asal'] == $no_rekening ?
                            'Transfer ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A') :
                            'Transfer dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A'),
                            default => 'Transaksi'
                        };
                    }
                    ?>
                    <div class="transaction-item">
                        <div class="transaction-icon <?php echo $icon_class; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="transaction-details">
                            <div class="transaction-title"><?php echo $description; ?></div>
                            <div class="transaction-meta">
                                <?php echo formatIndonesianDate($transaction['created_at']); ?>
                            </div>
                        </div>
                        <div class="transaction-right">
                            <div class="transaction-amount <?php echo $amount_class; ?>">
                                <?php echo $amount_prefix; ?>Rp<?php echo number_format($transaction['jumlah'], 0, ',', '.'); ?>
                            </div>
                            <div class="transaction-status <?php echo strtolower($transaction['status']); ?>">
                                <?php echo ucfirst($transaction['status']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <div class="empty-text">Belum ada transaksi</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <?php include 'bottom_navbar.php'; ?>

    <!-- Notification Dropdown -->
    <div class="notification-dropdown">
        <div class="notification-panel" id="notificationPanel">
            <div class="notif-header">
                <div class="notif-title">
                    <i class="fas fa-bell"></i>
                    Notifikasi
                    <?php if ($unread_notifications > 0): ?>
                        <span
                            style="background: var(--primary); color: white; font-size: 10px; padding: 2px 8px; border-radius: 20px;">
                            <?php echo $unread_notifications; ?> Baru
                        </span>
                    <?php endif; ?>
                </div>
                <span style="cursor: pointer; font-size: 18px; color: var(--gray-500); font-weight: 300;"
                    onclick="closeNotifications()">✕</span>
            </div>
            <div class="notif-body">
                <?php if ($notifications_result->num_rows > 0): ?>
                    <?php
                    $notifications_result->data_seek(0);
                    while ($row = $notifications_result->fetch_assoc()):
                        $is_read = $row['is_read'] == 1;
                        $read_status_text = $is_read ? 'Dibaca' : 'Belum Dibaca';
                        $read_status_class = $is_read ? 'read' : 'unread';

                        // Reset link for each notification - only tagihan has link
                        $notif_link = null;

                        // Determine category based on message content
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
                            $notif_icon = 'fa-shield-alt';
                        } elseif (
                            stripos($message_lower, 'setor') !== false ||
                            stripos($message_lower, 'tarik') !== false ||
                            stripos($message_lower, 'transfer') !== false ||
                            stripos($message_lower, 'qr') !== false ||
                            stripos($message_lower, 'pembayaran') !== false ||
                            stripos($message_lower, 'berhasil') !== false ||
                            stripos($message_lower, 'menerima') !== false
                        ) {
                            $category = 'transaksi';
                            $notif_icon = 'fa-exchange-alt';
                        } elseif (
                            stripos($message_lower, 'tagihan') !== false ||
                            stripos($message_lower, 'jatuh tempo') !== false ||
                            stripos($message_lower, 'bayar tepat waktu') !== false
                        ) {
                            $category = 'informasi';
                            $notif_icon = 'fa-file-invoice-dollar';
                            $notif_link = 'pembayaran.php'; // ONLY tagihan notifications have link
                        } elseif (stripos($message_lower, 'info') !== false) {
                            $category = 'info';
                            $notif_icon = 'fa-info-circle';
                        } else {
                            $category = 'pengumuman';
                            $notif_icon = 'fa-bullhorn';
                        }
                        ?>
                        <div class="notif-item <?php echo !$is_read ? 'unread' : ''; ?>"
                            onclick="handleNotificationClick(<?php echo $row['id']; ?>, '<?php echo $notif_link; ?>')">
                            <div class="notif-icon">
                                <i class="fas <?php echo $notif_icon; ?>"></i>
                            </div>
                            <div class="notif-content">
                                <div class="notif-top">
                                    <span class="notif-category"><?php echo htmlspecialchars($category); ?></span>
                                    <span
                                        class="notif-status <?php echo $read_status_class; ?>"><?php echo $read_status_text; ?></span>
                                </div>
                                <div class="notif-message"><?php echo htmlspecialchars($row['message']); ?></div>
                                <?php if ($notif_link): ?>
                                    <div class="notif-action">
                                        <i class="fas fa-arrow-right"></i> Klik untuk membayar
                                    </div>
                                <?php endif; ?>
                                <div class="notif-time">
                                    <i class="far fa-clock"></i>
                                    <?php echo formatIndonesianDate($row['created_at']); ?>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="notif-empty">
                        <div class="notif-empty-icon">🔔</div>
                        <div>Tidak ada notifikasi</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Connection Quality Detection
        function checkConnectionQuality() {
            const dot = document.getElementById('connectionDot');
            const indicator = document.getElementById('connectionIndicator');

            if (!navigator.onLine) {
                updateConnectionStatus('offline', 'Tidak ada koneksi');
                return;
            }

            // Use Network Information API if available
            const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

            if (connection) {
                const effectiveType = connection.effectiveType;
                const downlink = connection.downlink;

                if (effectiveType === '4g' && downlink >= 5) {
                    updateConnectionStatus('good', 'Koneksi Baik (' + downlink.toFixed(1) + ' Mbps)');
                } else if (effectiveType === '4g' || effectiveType === '3g') {
                    updateConnectionStatus('slow', 'Koneksi Sedang (' + effectiveType.toUpperCase() + ')');
                } else {
                    updateConnectionStatus('poor', 'Koneksi Lambat (' + effectiveType.toUpperCase() + ')');
                }
            } else {
                // Fallback: measure latency
                measureLatency();
            }
        }

        function measureLatency() {
            const startTime = performance.now();

            fetch(window.location.href, { method: 'HEAD', cache: 'no-cache' })
                .then(() => {
                    const latency = performance.now() - startTime;

                    if (latency < 300) {
                        updateConnectionStatus('good', 'Koneksi Baik (' + Math.round(latency) + 'ms)');
                    } else if (latency < 800) {
                        updateConnectionStatus('slow', 'Koneksi Sedang (' + Math.round(latency) + 'ms)');
                    } else {
                        updateConnectionStatus('poor', 'Koneksi Lambat (' + Math.round(latency) + 'ms)');
                    }
                })
                .catch(() => {
                    updateConnectionStatus('poor', 'Koneksi Bermasalah');
                });
        }

        function updateConnectionStatus(status, title) {
            const dot = document.getElementById('connectionDot');
            const indicator = document.getElementById('connectionIndicator');

            dot.className = 'connection-dot ' + status;
            indicator.title = title;
        }

        // Check connection on load and periodically
        document.addEventListener('DOMContentLoaded', () => {
            checkConnectionQuality();
            setInterval(checkConnectionQuality, 10000); // Check every 10 seconds
        });

        // Listen for online/offline events
        window.addEventListener('online', checkConnectionQuality);
        window.addEventListener('offline', () => updateConnectionStatus('offline', 'Tidak ada koneksi'));

        // Listen for connection changes (if supported)
        if (navigator.connection) {
            navigator.connection.addEventListener('change', checkConnectionQuality);
        }

        // Balance visibility
        let balanceVisible = true;
        const originalBalance = '<?php echo number_format($saldo, 0, ',', '.'); ?>';

        function toggleBalance() {
            const balanceEl = document.getElementById('balanceAmount');
            const toggleBtn = document.getElementById('balanceToggle');

            if (balanceVisible) {
                balanceEl.innerHTML = 'Rp ••••••••';
                toggleBtn.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                balanceEl.innerHTML = 'Rp ' + originalBalance + '<span class="balance-decimal">,00</span>';
                toggleBtn.innerHTML = '<i class="fas fa-eye"></i>';
            }
            balanceVisible = !balanceVisible;
        }

        // Security Tips Carousel
        let currentTip = 0;
        const totalTips = 4;
        let tipAutoRotate;

        function showSecurityTip(index) {
            currentTip = index;

            // Update tips
            document.querySelectorAll('.security-tip').forEach((tip, i) => {
                tip.classList.toggle('active', i === index);
            });

            // Update dots
            document.querySelectorAll('.security-dot').forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });

            // Update number
            document.getElementById('tipNumber').textContent = `${index + 1}/${totalTips}`;
        }

        function nextSecurityTip() {
            const next = (currentTip + 1) % totalTips;
            showSecurityTip(next);
            resetAutoRotate();
        }

        function resetAutoRotate() {
            clearInterval(tipAutoRotate);
            tipAutoRotate = setInterval(() => {
                nextSecurityTip();
            }, 5000);
        }

        // Initialize security tips carousel
        document.addEventListener('DOMContentLoaded', () => {
            // Click on dots
            document.querySelectorAll('.security-dot').forEach(dot => {
                dot.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const index = parseInt(dot.dataset.index);
                    showSecurityTip(index);
                    resetAutoRotate();
                });
            });

            // Start auto-rotate
            tipAutoRotate = setInterval(() => {
                nextSecurityTip();
            }, 5000);
        });

        // Notifications - Dropdown Style
        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            panel.classList.toggle('show');
        }

        function closeNotifications() {
            const panel = document.getElementById('notificationPanel');
            panel.classList.remove('show');
        }

        // Close notification when clicking outside
        document.addEventListener('click', (e) => {
            const panel = document.getElementById('notificationPanel');
            const btn = e.target.closest('.header-btn');

            if (!panel.contains(e.target) && !btn) {
                panel.classList.remove('show');
            }
        });

        // Close notification on scroll (outside of notification panel)
        window.addEventListener('scroll', () => {
            const panel = document.getElementById('notificationPanel');
            if (panel.classList.contains('show')) {
                panel.classList.remove('show');
            }
        }, { passive: true });

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

        function handleNotificationClick(notificationId, link) {
            fetch('mark_notification_as_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + notificationId
            })
                .then(response => response.json())
                .then(data => {
                    if (link && link !== '' && link !== 'null') {
                        window.location.href = link;
                    } else {
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
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Logout',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '<?php echo $base_url; ?>/logout.php';
                }
            });
        }

        // Animate balance on load
        function animateBalance() {
            const balanceElement = document.getElementById('balanceAmount');
            const finalBalance = <?php echo $saldo; ?>;
            let currentBalance = 0;
            const increment = finalBalance / 40;
            const duration = 800;
            const stepTime = duration / 40;

            function updateBalance() {
                if (currentBalance < finalBalance) {
                    currentBalance += increment;
                    if (currentBalance > finalBalance) {
                        currentBalance = finalBalance;
                    }
                    balanceElement.innerHTML = 'Rp ' + Math.floor(currentBalance).toLocaleString('id-ID') + '<span class="balance-decimal">,00</span>';
                    setTimeout(updateBalance, stepTime);
                } else {
                    balanceElement.innerHTML = 'Rp ' + originalBalance + '<span class="balance-decimal">,00</span>';
                }
            }

            updateBalance();
        }

        document.addEventListener('DOMContentLoaded', () => {
            animateBalance();
        });

        // Prevent double-tap zoom
        document.addEventListener('touchstart', (e) => {
            if (e.touches.length > 1) {
                e.preventDefault();
            }
        }, { passive: false });
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