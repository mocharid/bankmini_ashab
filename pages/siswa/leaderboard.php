<?php
/**
 * Leaderboard Mingguan - Peringkat Saldo Siswa
 * File: pages/siswa/leaderboard.php
 * Features: Weekly leaderboard based on account balance
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

// ============================================
// FETCH CURRENT USER DATA
// ============================================
$query_user = "SELECT 
    u.id, u.nama,
    COALESCE(r.saldo, 0) as saldo,
    r.id as rekening_id,
    r.no_rekening
FROM users u
LEFT JOIN rekening r ON u.id = r.user_id
WHERE u.id = ?";

$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$current_user = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();

if (!$current_user) {
    header("Location: $base_url/login.php");
    exit();
}

// ============================================
// CALCULATE LAST UPDATED DATE
// ============================================
$today = new DateTime();

// Indonesian month names
$bulan_indonesia = [
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

$last_updated = $today->format('d') . ' ' . $bulan_indonesia[(int) $today->format('m')] . ' ' . $today->format('Y') . ', ' . $today->format('H:i') . ' WIB';

// ============================================
// FETCH LEADERBOARD DATA (TOP 10 BY BALANCE)
// ============================================
$query_leaderboard = "SELECT 
    u.id as user_id,
    u.nama,
    r.saldo,
    r.no_rekening,
    CONCAT(COALESCE(tk.nama_tingkatan, ''), ' ', COALESCE(k.nama_kelas, '')) as kelas
FROM users u
INNER JOIN rekening r ON u.id = r.user_id
LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
LEFT JOIN kelas k ON sp.kelas_id = k.id
LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
WHERE u.role = 'siswa' 
    AND r.saldo >= 0
ORDER BY r.saldo DESC
LIMIT 10";

$result_leaderboard = $conn->query($query_leaderboard);
$leaderboard_data = [];
$rank = 1;
while ($row = $result_leaderboard->fetch_assoc()) {
    $row['rank'] = $rank;
    $row['kelas'] = trim($row['kelas']) ?: '-';
    $leaderboard_data[] = $row;
    $rank++;
}

// ============================================
// FIND CURRENT USER RANK
// ============================================
$query_user_rank = "SELECT COUNT(*) + 1 as user_rank
FROM rekening r
INNER JOIN users u ON r.user_id = u.id
WHERE u.role = 'siswa' 
    AND r.saldo >= 0
    AND r.saldo > (
        SELECT COALESCE(r2.saldo, 0) 
        FROM rekening r2 
        WHERE r2.user_id = ?
    )";

$stmt_rank = $conn->prepare($query_user_rank);
$stmt_rank->bind_param("i", $user_id);
$stmt_rank->execute();
$user_rank_result = $stmt_rank->get_result()->fetch_assoc();
$current_user_rank = $user_rank_result['user_rank'] ?? 0;
$stmt_rank->close();

// Check if user is in top 10
$is_in_top_10 = false;
foreach ($leaderboard_data as $entry) {
    if ($entry['user_id'] == $user_id) {
        $is_in_top_10 = true;
        break;
    }
}

// ============================================
// GET TOTAL PARTICIPANTS
// ============================================
$query_total = "SELECT COUNT(*) as total FROM users u 
INNER JOIN rekening r ON u.id = r.user_id 
WHERE u.role = 'siswa' AND r.saldo >= 0";
$total_participants = $conn->query($query_total)->fetch_assoc()['total'];

$conn->close();

// ============================================
// HELPER FUNCTIONS
// ============================================
function getInitials($name)
{
    $parts = explode(' ', $name);
    $initials = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        // Always use second name (middle name), not last name
        $initials .= strtoupper(substr($parts[1], 0, 1));
    }
    return $initials;
}

function getRankBadge($rank)
{
    return match ($rank) {
        1 => '🥇',
        2 => '🥈',
        3 => '🥉',
        default => '#' . $rank
    };
}

function getRankClass($rank)
{
    return match ($rank) {
        1 => 'gold',
        2 => 'silver',
        3 => 'bronze',
        default => 'normal'
    };
}

function maskBalance($saldo)
{
    $formatted = number_format($saldo, 0, ',', '.');
    $len = strlen($formatted);
    if ($len <= 2) {
        return $formatted;
    }
    $first = substr($formatted, 0, 1);
    $last = substr($formatted, -1);
    $middle = str_repeat('*', min($len - 2, 4));
    return $first . $middle . $last;
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
    <title>Leaderboard - KASDIG</title>
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
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
            --secondary: #3498db;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            --gold: #FFD700;
            --silver: #C0C0C0;
            --bronze: #CD7F32;

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

            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 8px 25px -5px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px -10px rgba(0, 0, 0, 0.15);

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
            padding-bottom: 100px;
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '✨ 🏆 ⭐ 🌟 💫 ✨ 🏆 ⭐';
            position: fixed;
            top: 280px;
            left: 0;
            right: 0;
            font-size: 40px;
            text-align: center;
            opacity: 0.06;
            pointer-events: none;
            z-index: 0;
            letter-spacing: 20px;
        }

        body::after {
            content: '⭐ 💫 ✨ 🏆 🌟 ⭐ 💫 ✨';
            position: fixed;
            top: 450px;
            left: 0;
            right: 0;
            font-size: 35px;
            text-align: center;
            opacity: 0.04;
            pointer-events: none;
            z-index: 0;
            letter-spacing: 15px;
        }

        /* ============================================ */
        /* HEADER */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
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
            margin-bottom: 20px;
        }

        .back-btn {
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
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .back-btn i {
            font-size: 18px;
        }

        .header-title-area {
            text-align: center;
            color: var(--white);
        }

        .header-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .header-title {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .header-subtitle {
            font-size: 13px;
            opacity: 0.85;
        }

        .header-period {
            margin-top: 16px;
            background: rgba(255, 255, 255, 0.15);
            padding: 10px 20px;
            border-radius: var(--radius-full);
            display: inline-block;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -80px;
            position: relative;
            z-index: 10;
        }

        /* ============================================ */
        /* YOUR RANK CARD */
        /* ============================================ */
        .your-rank-card {
            background: linear-gradient(135deg, #1a252f 0%, #2c3e50 50%, #34495e 100%);
            border-radius: var(--radius-xl);
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-lg);
            color: var(--white);
            position: relative;
            overflow: hidden;
        }

        .your-rank-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 60%);
            pointer-events: none;
        }

        .your-rank-header {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            margin-bottom: 12px;
        }

        .your-rank-content {
            display: flex;
            align-items: center;
            gap: 16px;
            position: relative;
            z-index: 2;
        }

        .your-rank-number {
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, rgba(255, 255, 255, 0.8) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .your-rank-info {
            flex: 1;
        }

        .your-rank-name {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .your-rank-balance {
            font-size: 13px;
            opacity: 0.9;
        }

        .your-rank-total {
            text-align: right;
            font-size: 11px;
            opacity: 0.7;
        }

        /* ============================================ */
        /* LEADERBOARD LIST */
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

        .leaderboard-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .leaderboard-item {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-100);
            transition: all 0.2s ease;
        }

        .leaderboard-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .leaderboard-item.is-you {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 2px solid var(--primary);
        }

        .leaderboard-item.gold {
            background: linear-gradient(135deg, #fffdf0 0%, #fff9e6 100%);
            border: 2px solid var(--gold);
        }

        .leaderboard-item.silver {
            background: linear-gradient(135deg, #fafafa 0%, #f0f0f0 100%);
            border: 2px solid var(--silver);
        }

        .leaderboard-item.bronze {
            background: linear-gradient(135deg, #fdf8f3 0%, #f9f0e7 100%);
            border: 2px solid var(--bronze);
        }

        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            flex-shrink: 0;
        }

        .rank-badge.gold {
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            color: #7B5800;
            font-size: 20px;
        }

        .rank-badge.silver {
            background: linear-gradient(135deg, #E8E8E8 0%, #C0C0C0 100%);
            color: #555;
            font-size: 20px;
        }

        .rank-badge.bronze {
            background: linear-gradient(135deg, #DEB887 0%, #CD7F32 100%);
            color: #5D3A1A;
            font-size: 20px;
        }

        .rank-badge.normal {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: var(--radius-full);
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 16px;
            flex-shrink: 0;
        }

        .user-info {
            flex: 1;
            min-width: 0;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-tag {
            font-size: 11px;
            color: var(--primary);
            font-weight: 600;
        }

        .user-class {
            font-size: 11px;
            color: var(--gray-500);
        }

        .user-balance {
            text-align: right;
            flex-shrink: 0;
        }

        .balance-amount {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .balance-label {
            font-size: 10px;
            color: var(--gray-500);
        }

        /* ============================================ */
        /* HIDDEN RANKS & TOGGLE */
        /* ============================================ */
        .leaderboard-item.hidden-rank {
            display: none;
        }

        .leaderboard-list.expanded .leaderboard-item.hidden-rank {
            display: flex;
        }

        .show-more-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 12px;
            background: transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 4px;
        }

        .show-more-toggle:hover {
            opacity: 0.7;
        }

        .toggle-text {
            font-size: 12px;
            font-weight: 500;
            color: var(--gray-600);
        }

        .toggle-icon {
            font-size: 12px;
            color: var(--gray-500);
            transition: transform 0.3s ease;
        }

        .show-more-toggle.expanded .toggle-icon {
            transform: rotate(180deg);
        }

        /* ============================================ */
        /* EMPTY STATE */
        /* ============================================ */
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
        /* INFO BUTTON */
        /* ============================================ */
        .info-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.15);
            border: none;
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            backdrop-filter: blur(10px);
        }

        .info-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.05);
        }

        .info-btn:active {
            transform: scale(0.95);
        }

        .info-btn i {
            font-size: 14px;
            color: var(--white);
        }

        .info-btn span {
            font-size: 12px;
            font-weight: 600;
            color: var(--white);
        }

        /* ============================================ */
        /* RESPONSIVE */
        /* ============================================ */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .top-header {
                padding: 16px 16px 120px;
            }

            .main-container {
                padding: 0 16px;
            }

            .header-title {
                font-size: 20px;
            }

            .your-rank-number {
                font-size: 32px;
            }
        }

        @media (max-width: 480px) {
            .header-icon {
                font-size: 40px;
            }

            .header-title {
                font-size: 18px;
            }

            .your-rank-card {
                padding: 16px;
            }

            .your-rank-number {
                font-size: 28px;
            }

            .leaderboard-item {
                padding: 14px;
            }

            .user-avatar {
                width: 46px;
                height: 46px;
                font-size: 14px;
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

        .your-rank-card {
            animation: fadeInUp 0.5s ease;
        }

        .leaderboard-item {
            animation: fadeInUp 0.5s ease;
        }

        .leaderboard-item:nth-child(1) {
            animation-delay: 0.05s;
        }

        .leaderboard-item:nth-child(2) {
            animation-delay: 0.1s;
        }

        .leaderboard-item:nth-child(3) {
            animation-delay: 0.15s;
        }

        .leaderboard-item:nth-child(4) {
            animation-delay: 0.2s;
        }

        .leaderboard-item:nth-child(5) {
            animation-delay: 0.25s;
        }

        .leaderboard-item:nth-child(6) {
            animation-delay: 0.3s;
        }

        .leaderboard-item:nth-child(7) {
            animation-delay: 0.35s;
        }

        .leaderboard-item:nth-child(8) {
            animation-delay: 0.4s;
        }

        .leaderboard-item:nth-child(9) {
            animation-delay: 0.45s;
        }

        .leaderboard-item:nth-child(10) {
            animation-delay: 0.5s;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="top-header">
        <div class="header-content">
            <div class="header-top">
                <a href="dashboard.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <button class="info-btn" onclick="showLeaderboardInfo()">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Info</span>
                </button>
            </div>
            <div class="header-title-area">
                <div class="header-icon">🏆</div>
                <h1 class="header-title">Leaderboard</h1>
                <p class="header-subtitle">Terakhir diperbaharui: <?php echo $last_updated; ?></p>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Your Rank Card -->
        <div class="your-rank-card">
            <div class="your-rank-header">Peringkat Kamu</div>
            <div class="your-rank-content">
                <div class="your-rank-number">#
                    <?php echo $current_user_rank; ?>
                </div>
                <div class="your-rank-info">
                    <div class="your-rank-name">
                        <?php echo htmlspecialchars($current_user['nama']); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Leaderboard Section -->
        <div class="section-header">
            <h2 class="section-title">Top 10 Penabung 💰</h2>
        </div>

        <div class="leaderboard-list">
            <?php if (count($leaderboard_data) > 0): ?>
                <?php foreach ($leaderboard_data as $entry): ?>
                    <?php
                    $is_current_user = ($entry['user_id'] == $user_id);
                    $rank_class = getRankClass($entry['rank']);
                    $item_class = 'leaderboard-item ' . $rank_class;
                    if ($is_current_user) {
                        $item_class .= ' is-you';
                    }
                    // Hide ranks 6-10 initially
                    if ($entry['rank'] > 5) {
                        $item_class .= ' hidden-rank';
                    }
                    ?>
                    <div class="<?php echo $item_class; ?>">
                        <div class="rank-badge <?php echo $rank_class; ?>">
                            <?php echo getRankBadge($entry['rank']); ?>
                        </div>
                        <div class="user-info">
                            <div class="user-name">
                                <?php echo htmlspecialchars($entry['nama']); ?>
                            </div>
                            <?php if ($is_current_user): ?>
                                <div class="user-tag">Ini Kamu! 🎉</div>
                            <?php else: ?>
                                <div class="user-class"><?php echo htmlspecialchars($entry['kelas']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (count($leaderboard_data) > 5): ?>
                    <div class="show-more-toggle" onclick="toggleMoreRanks()">
                        <span class="toggle-text">Lihat lainnya</span>
                        <i class="fas fa-chevron-down toggle-icon"></i>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📊</div>
                    <div class="empty-text">Belum ada data leaderboard</div>
                </div>
            <?php endif; ?>
        </div>


    </div>

    <!-- Bottom Navigation -->
    <?php include 'bottom_navbar.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function toggleMoreRanks() {
            const list = document.querySelector('.leaderboard-list');
            const toggle = document.querySelector('.show-more-toggle');
            const text = toggle.querySelector('.toggle-text');

            list.classList.toggle('expanded');
            toggle.classList.toggle('expanded');

            if (list.classList.contains('expanded')) {
                text.textContent = 'Sembunyikan';
            } else {
                text.textContent = 'Lihat lainnya';
            }
        }

        function showLeaderboardInfo() {
            Swal.fire({
                title: '🏆 Tentang Leaderboard',
                html: `
                    <div style="text-align: left; font-size: 14px; line-height: 1.8;">
                        <p><strong>Apa itu Leaderboard?</strong></p>
                        <p>Leaderboard adalah papan peringkat yang menampilkan <b>Top 10 siswa</b> dengan saldo tabungan tertinggi.</p>
                        <br>
                        <p><strong>Bagaimana cara naik peringkat?</strong></p>
                        <ul style="margin-left: 20px; margin-top: 8px;">
                            <li>Rutin menabung setiap minggu</li>
                            <li>Hindari penarikan yang tidak perlu</li>
                            <li>Tetap konsisten! 💪</li>
                        </ul>
                        <br>
                        <p><strong>Kapan diperbarui?</strong></p>
                        <p>Peringkat diperbarui secara <b>real-time</b> setiap kali ada transaksi.</p>
                    </div>
                `,
                icon: 'info',
                confirmButtonText: 'Mengerti!',
                confirmButtonColor: '#2c3e50',
                showClass: {
                    popup: 'animate__animated animate__fadeInUp animate__faster'
                },
                hideClass: {
                    popup: 'animate__animated animate__fadeOutDown animate__faster'
                }
            });
        }

        // Sparkle/Confetti effect on page load
        function createSparkle() {
            const sparkleContainer = document.createElement('div');
            sparkleContainer.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;overflow:hidden;';
            document.body.appendChild(sparkleContainer);

            const colors = ['#FFD700', '#FFA500', '#FF6347', '#87CEEB', '#98FB98', '#DDA0DD', '#F0E68C'];
            const emojis = ['✨', '⭐', '🌟', '💫', '🏆'];

            for (let i = 0; i < 50; i++) {
                setTimeout(() => {
                    const sparkle = document.createElement('div');
                    const isEmoji = Math.random() > 0.7;

                    if (isEmoji) {
                        sparkle.textContent = emojis[Math.floor(Math.random() * emojis.length)];
                        sparkle.style.fontSize = Math.random() * 20 + 15 + 'px';
                    } else {
                        sparkle.style.width = Math.random() * 8 + 4 + 'px';
                        sparkle.style.height = sparkle.style.width;
                        sparkle.style.background = colors[Math.floor(Math.random() * colors.length)];
                        sparkle.style.borderRadius = '50%';
                    }

                    sparkle.style.cssText += 'position:absolute;left:' + (Math.random() * 100) + '%;top:-20px;opacity:1;animation:sparkle-fall ' + (Math.random() * 2 + 1.5) + 's ease-out forwards;';
                    sparkleContainer.appendChild(sparkle);

                    setTimeout(() => sparkle.remove(), 3500);
                }, i * 30);
            }

            setTimeout(() => sparkleContainer.remove(), 4000);
        }

        // Add sparkle animation keyframes
        const style = document.createElement('style');
        style.textContent = '@keyframes sparkle-fall { 0% { transform: translateY(0) rotate(0deg); opacity: 1; } 100% { transform: translateY(100vh) rotate(720deg); opacity: 0; } }';
        document.head.appendChild(style);

        // Trigger sparkle effect on page load
        window.addEventListener('load', () => {
            setTimeout(createSparkle, 300);
        });
    </script>

</body>

</html>