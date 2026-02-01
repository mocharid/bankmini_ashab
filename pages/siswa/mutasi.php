<?php
/**
 * Cek Mutasi - Adaptive Path Version
 * File: pages/siswa/mutasi.php
 *
 * Compatible with:
 * - Local: schobank/pages/siswa/mutasi.php
 * - Hosting: public_html/pages/siswa/mutasi.php
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
// Strategy 1: jika di folder 'siswa' atau 'pages'
if (basename($current_dir) === 'siswa') {
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
// Pastikan user adalah siswa
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    header("Location: $base_url/unauthorized.php");
    exit();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: $base_url/login.php");
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

// ============================================
// AMBIL DATA USER DAN REKENING
// ============================================
$stmt = $conn->prepare("
    SELECT u.id, u.nama, sp.nis_nisn, r.saldo, r.id as rekening_id, r.no_rekening
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
    $rekening_id = $siswa_info['rekening_id'];
    $no_rekening = $siswa_info['no_rekening'];

    // ============================================
    // ✅ QUERY TRANSAKSI DENGAN FILTER YANG BENAR
    // ============================================
    $where_conditions = "WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)";
    $params = [$rekening_id, $rekening_id];
    $types = "ii";

    // Filter jenis transaksi - PERBAIKAN LOGIKA
    if (!empty($filter_jenis)) {
        if ($filter_jenis === 'setor') {
            $where_conditions .= " AND t.jenis_transaksi = 'setor'";
            // Tidak perlu tambah parameter karena jenis_transaksi = 'setor' tidak pakai parameter
        } elseif ($filter_jenis === 'tarik') {
            $where_conditions .= " AND t.jenis_transaksi = 'tarik'";
        } elseif ($filter_jenis === 'transfer') {
            // Filter untuk semua transfer (masuk dan keluar)
            $where_conditions .= " AND t.jenis_transaksi = 'transfer'";
        } elseif ($filter_jenis === 'bayar') {
            $where_conditions .= " AND t.jenis_transaksi = 'bayar'";
        } elseif ($filter_jenis === 'infaq') {
            $where_conditions .= " AND t.jenis_transaksi = 'infaq'";
        } elseif ($filter_jenis === 'transaksi_qr') {
            $where_conditions .= " AND t.jenis_transaksi = 'transaksi_qr'";
        } elseif ($filter_jenis === 'transfer_masuk') {
            $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ?";
            $params[] = $rekening_id;
            $types .= "i";
        } elseif ($filter_jenis === 'transfer_keluar') {
            $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND t.rekening_id = ?";
            $params[] = $rekening_id;
            $types .= "i";
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

    // Query yang sama dengan dashboard
    $query = "
        SELECT
            t.no_transaksi, t.jenis_transaksi, t.jumlah, t.created_at, t.status,
            t.rekening_tujuan_id, t.rekening_id, t.keterangan,
            r1.no_rekening as rekening_asal,
            r2.no_rekening as rekening_tujuan,
            u1.nama as nama_pengirim,
            u2.nama as nama_penerima,
            pi.nama_item as nama_tagihan
        FROM transaksi t
        LEFT JOIN rekening r1 ON t.rekening_id = r1.id
        LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
        LEFT JOIN users u1 ON r1.user_id = u1.id
        LEFT JOIN users u2 ON r2.user_id = u2.id
        LEFT JOIN pembayaran_tagihan pt ON t.no_transaksi = pt.no_transaksi
        LEFT JOIN pembayaran_item pi ON pt.item_id = pi.id
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
        // ✅ Konversi status sama dengan dashboard
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

// ============================================
// CALCULATE WEEKLY STATISTICS FOR CHART
// ============================================
$weekly_data = [];
$total_pengeluaran = 0;
$total_pemasukan = 0;

// Get current month range
$current_month = date('n');
$current_year = date('Y');
$days_in_month = date('t');

// Initialize week data (5 weeks max)
$weeks = [
    '1-7' => ['pengeluaran' => 0, 'pemasukan' => 0],
    '8-14' => ['pengeluaran' => 0, 'pemasukan' => 0],
    '15-21' => ['pengeluaran' => 0, 'pemasukan' => 0],
    '22-28' => ['pengeluaran' => 0, 'pemasukan' => 0],
    '29-31' => ['pengeluaran' => 0, 'pemasukan' => 0],
];

// Calculate totals from transaction data
foreach ($mutasi_data as $trx) {
    $trx_date = new DateTime($trx['created_at']);
    $trx_month = (int) $trx_date->format('n');
    $trx_year = (int) $trx_date->format('Y');
    $trx_day = (int) $trx_date->format('j');

    // Only process current month's transactions
    if ($trx_month == $current_month && $trx_year == $current_year) {
        $is_incoming = ($trx['jenis_transaksi'] == 'setor' ||
            ($trx['jenis_transaksi'] == 'transfer' && $trx['rekening_tujuan'] == $no_rekening) ||
            ($trx['jenis_transaksi'] == 'transaksi_qr' && $trx['rekening_tujuan'] == $no_rekening) ||
            ($trx['jenis_transaksi'] == 'infaq' && $trx['rekening_tujuan'] == $no_rekening));

        $amount = abs($trx['jumlah']);

        // Determine week
        if ($trx_day >= 1 && $trx_day <= 7) {
            $week_key = '1-7';
        } elseif ($trx_day >= 8 && $trx_day <= 14) {
            $week_key = '8-14';
        } elseif ($trx_day >= 15 && $trx_day <= 21) {
            $week_key = '15-21';
        } elseif ($trx_day >= 22 && $trx_day <= 28) {
            $week_key = '22-28';
        } else {
            $week_key = '29-31';
        }

        if ($is_incoming) {
            $weeks[$week_key]['pemasukan'] += $amount;
            $total_pemasukan += $amount;
        } else {
            $weeks[$week_key]['pengeluaran'] += $amount;
            $total_pengeluaran += $amount;
        }
    }
}

// Prepare data for Chart.js
$chart_labels = json_encode(array_keys($weeks));
$chart_pengeluaran = json_encode(array_column($weeks, 'pengeluaran'));
$chart_pemasukan = json_encode(array_column($weeks, 'pemasukan'));

// Indonesian month names
$bulan_indo = [
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
$current_month_name = $bulan_indo[$current_month];

// ============================================
// ✅ FUNCTION BULAN BAHASA INDONESIA
// ============================================
function formatDateIndonesian($date)
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
    $month_indonesian = $months[(int) $dateObj->format('n')];

    return $dateObj->format('d') . ' ' . $month_indonesian . ' ' . $dateObj->format('Y');
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
    <title>Mutasi - KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            /* Original Dark/Elegant Theme - Matching Transfer Page */
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
            margin-bottom: 24px;
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
            text-decoration: none;
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

        /* ============================================ */
        /* TABS IN HEADER - WHITE THEME */
        /* ============================================ */
        .header-tabs {
            display: flex;
            gap: 0;
            background: rgba(255, 255, 255, 0.15);
            border-radius: var(--radius-xl);
            padding: 4px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }

        .header-tabs::before {
            content: '';
            position: absolute;
            top: 4px;
            bottom: 4px;
            width: calc(50% - 4px);
            background: var(--white);
            border-radius: calc(var(--radius-xl) - 2px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1;
        }

        .header-tabs.tab-right::before {
            left: calc(50% + 2px);
        }

        .header-tabs.tab-left::before {
            left: 4px;
        }

        .header-tab {
            flex: 1;
            padding: 12px 20px;
            border-radius: calc(var(--radius-xl) - 2px);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.7);
            position: relative;
            z-index: 2;
        }

        .header-tab:hover:not(.active) {
            color: rgba(255, 255, 255, 0.9);
        }

        .header-tab.active {
            color: var(--primary);
        }

        .header-tab i {
            margin-right: 8px;
            font-size: 13px;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        /* ============================================ */
        /* ACCOUNT & FILTER CARD */
        /* ============================================ */
        .account-filter-card {
            background: var(--white);
            border-radius: var(--radius-2xl);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .account-filter-row {
            display: flex;
            gap: 12px;
            align-items: stretch;
        }

        /* Account Info */
        .account-info-box {
            flex: 1;
            background: var(--gray-50);
            border-radius: var(--radius-lg);
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .account-label {
            font-size: 11px;
            color: var(--gray-500);
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .account-number {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        /* Filter Button */
        .filter-icon-btn {
            width: 52px;
            height: 52px;
            background: var(--gray-50);
            border: none;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .filter-icon-btn:hover {
            background: var(--gray-100);
        }

        .filter-icon-btn:active {
            transform: scale(0.98);
        }

        .filter-icon-btn i {
            font-size: 18px;
            color: var(--gray-600);
        }

        /* ============================================ */
        /* CHART CARD */
        /* ============================================ */
        .chart-card {
            background: var(--white);
            border-radius: var(--radius-2xl);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--gray-800);
        }

        .month-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--gray-100);
            padding: 8px 14px;
            border-radius: var(--radius-full);
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .month-badge i {
            font-size: 14px;
        }

        .chart-container {
            height: 200px;
            margin-bottom: 20px;
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .summary-card {
            padding: 16px;
            border-radius: var(--radius-lg);
            background: var(--gray-50);
        }

        .summary-card.pengeluaran {
            /* No border-left */
        }

        .summary-card.pemasukan {
            /* No border-left */
        }

        .summary-label {
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 4px;
        }

        .summary-amount {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-800);
            word-break: break-all;
        }

        .summary-amount.pengeluaran-text {
            color: var(--gray-800);
        }

        .summary-amount.pemasukan-text {
            color: var(--gray-800);
        }

        /* ============================================ */
        /* TRANSACTION LIST - NEW DESIGN */
        /* ============================================ */
        .trx-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
        }

        .trx-icon.debit {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .trx-icon.credit {
            background: var(--gray-100);
            color: var(--gray-600);
        }

        .trx-main-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 3px;
            line-height: 1.3;
        }

        .trx-sub-info {
            font-size: 10px;
            color: var(--gray-500);
            line-height: 1.2;
        }

        .trx-bank {
            font-weight: 600;
            color: var(--gray-600);
        }

        .trx-amount-label {
            font-size: 10px;
            color: var(--gray-400);
            margin-top: 2px;
        }

        .trx-amount-label.uang-keluar {
            color: var(--gray-500);
        }

        .trx-amount-label.uang-masuk {
            color: var(--gray-500);
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
        /* TRANSACTION LIST */
        /* ============================================ */
        .date-group {
            margin-bottom: 24px;
        }

        .date-header {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-500);
            margin-bottom: 12px;
            padding-left: 4px;
        }

        .transaction-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
            border: 1px solid rgba(0, 0, 0, 0.04);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .transaction-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            transform: translateY(-1px);
        }

        /* Transaction Image Icon */
        .transaction-image {
            width: 48px;
            height: 48px;
            flex-shrink: 0;
            border-radius: var(--radius);
            object-fit: cover;
            object-position: center;
            background: var(--gray-50);
        }

        /* Fallback icon */
        .transaction-icon-fallback {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--gray-400);
            font-size: 22px;
            background: var(--gray-100);
            border-radius: var(--radius);
        }

        .transaction-content {
            flex: 1;
            min-width: 0;
        }

        .transaction-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .transaction-left {
            flex: 1;
        }

        .transaction-type {
            font-size: 13px;
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0;
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.4;
        }

        .transaction-no {
            font-size: 12px;
            font-family: 'Courier New', monospace;
            color: var(--gray-400);
        }

        .transaction-right {
            text-align: right;
        }

        .transaction-amount {
            font-size: 14px;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 2px;
        }

        .transaction-amount.debit {
            color: var(--gray-800);
        }

        .transaction-time {
            font-size: 12px;
            color: var(--gray-400);
        }

        /* ============================================ */
        /* EMPTY STATE */
        /* ============================================ */
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

        /* ============================================ */
        /* RESPONSIVE */
        /* ============================================ */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

            .main-container {
                padding: 0 16px;
            }

            .top-header {
                padding: 16px 16px 70px;
            }

            .page-title {
                font-size: 20px;
            }

            .page-subtitle {
                font-size: 12px;
            }

            .header-btn {
                width: 40px;
                height: 40px;
            }

            .account-filter-card {
                padding: 16px;
            }

            .account-info-box {
                padding: 10px 14px;
            }

            .account-number {
                font-size: 15px;
            }

            .filter-icon-btn {
                width: 48px;
                height: 48px;
            }

            .header-tab {
                padding: 10px 16px;
                font-size: 13px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }

            .transaction-amount {
                font-size: 14px;
            }

            .transaction-type {
                font-size: 14px;
            }

            .account-info-box {
                padding: 8px 12px;
            }

            .account-label {
                font-size: 10px;
            }

            .account-number {
                font-size: 14px;
            }

            .filter-icon-btn {
                width: 44px;
                height: 44px;
            }

            .filter-icon-btn i {
                font-size: 16px;
            }

            .header-tab i {
                display: none;
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
                        <h1 class="page-title">Mutasi</h1>
                        <p class="page-subtitle">Riwayat transaksi rekening Anda</p>
                    </div>
                </div>
                <!-- Tabs in Header -->
                <div class="header-tabs tab-left">
                    <button class="header-tab active"><i class="fas fa-list-alt"></i>Mutasi</button>
                    <button class="header-tab" onclick="switchTab('e_statement.php')"><i
                            class="fas fa-file-invoice"></i>e-Statement</button>
                </div>
            </div>
        </header>

        <!-- Main Container -->
        <div class="main-container">
            <!-- Account & Filter Card -->
            <div class="account-filter-card">
                <div class="account-filter-row">
                    <div class="account-info-box">
                        <div class="account-label">Sumber Rekening</div>
                        <div class="account-number"><?php echo htmlspecialchars($siswa_info['no_rekening']); ?></div>
                    </div>
                    <button class="filter-icon-btn" onclick="openFilterModal()">
                        <i class="fas fa-sliders-h"></i>
                    </button>
                </div>
            </div>

            <!-- Chart Card -->
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Statistik Transaksi</span>
                    <div class="month-badge">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo $current_month_name . ' ' . $current_year; ?>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="transactionChart"></canvas>
                </div>
                <div class="summary-cards">
                    <div class="summary-card pengeluaran">
                        <div class="summary-label">Pengeluaran</div>
                        <div class="summary-amount pengeluaran-text">Rp
                            <?php echo number_format($total_pengeluaran, 0, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="summary-card pemasukan">
                        <div class="summary-label">Pemasukan</div>
                        <div class="summary-amount pemasukan-text">Rp
                            <?php echo number_format($total_pemasukan, 0, ',', '.'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transaction List -->
            <?php if (empty($mutasi_data)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <h3 class="empty-title">Belum Ada Transaksi</h3>
                    <p class="empty-text">
                        <?php if (!empty($filter_jenis) || !empty($filter_start_date) || !empty($filter_end_date)): ?>
                            Tidak ada transaksi yang sesuai dengan filter yang dipilih.
                        <?php else: ?>
                            Akun Anda belum memiliki riwayat transaksi.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php
                // Group transactions by date
                $grouped_data = [];
                foreach ($mutasi_data as $mutasi) {
                    $date = formatDateIndonesian($mutasi['created_at'] ?? 'now');
                    if (!isset($grouped_data[$date])) {
                        $grouped_data[$date] = [];
                    }
                    $grouped_data[$date][] = $mutasi;
                }
                ?>
                <?php foreach ($grouped_data as $date => $transactions): ?>
                    <div class="date-group">
                        <!-- ✅ PERUBAHAN: BULAN BAHASA INDONESIA -->
                        <div class="date-header"><?php echo $date; ?></div>
                        <?php foreach ($transactions as $mutasi): ?>
                            <?php
                            // ✅ LOGIC YANG SAMA DENGAN DASHBOARD
                            $is_incoming = ($mutasi['jenis_transaksi'] == 'setor' ||
                                ($mutasi['jenis_transaksi'] == 'transfer' && $mutasi['rekening_tujuan'] == $no_rekening) ||
                                ($mutasi['jenis_transaksi'] == 'transaksi_qr' && $mutasi['rekening_tujuan'] == $no_rekening) ||
                                ($mutasi['jenis_transaksi'] == 'infaq' && $mutasi['rekening_tujuan'] == $no_rekening));

                            $is_debit = !$is_incoming;

                            // ✅ PERUBAHAN: Untuk transfer ke bendahar (nama penerima mengandung "bendahar")
                            $nama_penerima = $mutasi['nama_penerima'] ?? '';
                            $is_transfer_to_bendahar = (stripos($nama_penerima, 'bendahar') !== false);

                            // ✅ Tentukan deskripsi lengkap dengan nomor rekening dan no transaksi
                            $no_transaksi = $mutasi['no_transaksi'] ?? '-';
                            $rekening_asal = $mutasi['rekening_asal'] ?? '-';
                            $rekening_tujuan = $mutasi['rekening_tujuan'] ?? '-';
                            // Ambil nama tagihan dari JOIN, atau dari keterangan transaksi sebagai fallback
                            $nama_tagihan = $mutasi['nama_tagihan'] ?? '';
                            if (empty($nama_tagihan) && !empty($mutasi['keterangan'])) {
                                // Extract nama dari keterangan (format: "Pembayaran NamaItem")
                                $nama_tagihan = str_replace('Pembayaran ', '', $mutasi['keterangan']);
                            }

                            if ($is_transfer_to_bendahar && $mutasi['jenis_transaksi'] == 'transfer' && !$is_incoming) {
                                $description = "Infaq Bulanan";
                            } elseif ($mutasi['jenis_transaksi'] === 'infaq') {
                                if ($mutasi['rekening_asal'] == $no_rekening) {
                                    $description = 'Infaq';
                                } else {
                                    $description = 'Terima Infaq';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
                                if ($mutasi['rekening_asal'] == $no_rekening) {
                                    $description = 'Pembayaran QRIS';
                                } else {
                                    $description = 'Terima QRIS';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'transfer') {
                                $nama_target = $is_incoming ? ($mutasi['nama_pengirim'] ?? '') : ($mutasi['nama_penerima'] ?? '');
                                if ($is_incoming) {
                                    $description = 'Transfer dari ' . ($nama_target ?: 'Rekening Lain');
                                } else {
                                    $description = 'Transfer ke ' . ($nama_target ?: 'Rekening Lain');
                                }
                            } elseif ($mutasi['jenis_transaksi'] === 'setor') {
                                $description = 'Setoran Tunai';
                            } elseif ($mutasi['jenis_transaksi'] === 'tarik') {
                                $description = 'Penarikan Tunai';
                            } elseif ($mutasi['jenis_transaksi'] === 'bayar') {
                                $nama_item = !empty($nama_tagihan) ? $nama_tagihan : 'Tagihan';
                                // Deteksi sumber pembayaran berdasarkan prefix no_transaksi
                                if (strpos($no_transaksi, 'PAYADM') === 0) {
                                    $description = 'Pembayaran ' . $nama_item . ' Via Bendahara';
                                } else {
                                    $description = 'Pembayaran ' . $nama_item . ' Via KASDIG';
                                }
                                $is_debit = true;
                            } else {
                                $description = 'Transaksi';
                            }

                            // Determine transaction icon based on type
                            $trx_icon = 'fa-receipt';
                            if ($mutasi['jenis_transaksi'] === 'transfer') {
                                $trx_icon = $is_debit ? 'fa-arrow-up' : 'fa-arrow-down';
                            } elseif ($mutasi['jenis_transaksi'] === 'setor') {
                                $trx_icon = 'fa-plus';
                            } elseif ($mutasi['jenis_transaksi'] === 'tarik') {
                                $trx_icon = 'fa-minus';
                            } elseif ($mutasi['jenis_transaksi'] === 'bayar') {
                                $trx_icon = 'fa-file-invoice-dollar';
                            } elseif ($mutasi['jenis_transaksi'] === 'infaq') {
                                $trx_icon = 'fa-hand-holding-heart';
                            } elseif ($mutasi['jenis_transaksi'] === 'transaksi_qr') {
                                $trx_icon = 'fa-qrcode';
                            }

                            // Format datetime in Indonesian
                            $bulan_short = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                            $trx_date = new DateTime($mutasi['created_at'] ?? 'now');
                            $trx_datetime = $trx_date->format('d') . ' ' . $bulan_short[(int) $trx_date->format('n') - 1] . ' ' . $trx_date->format('Y H:i') . ' WIB';
                            ?>
                            <div class="transaction-card">
                                <div class="trx-icon <?php echo $is_debit ? 'debit' : 'credit'; ?>">
                                    <i class="fas <?php echo $trx_icon; ?>"></i>
                                </div>
                                <div class="transaction-content">
                                    <div class="transaction-header-row">
                                        <div class="transaction-left">
                                            <div class="trx-main-title">
                                                <?php echo htmlspecialchars($description); ?>
                                            </div>
                                            <div class="trx-sub-info">
                                                <span class="trx-bank">KASDIG</span> • <?php echo $trx_datetime; ?>
                                            </div>
                                        </div>
                                        <div class="transaction-right">
                                            <div class="transaction-amount <?php echo $is_debit ? 'debit' : ''; ?>">
                                                <?php
                                                $sign = $is_debit ? '- ' : '+ ';
                                                $jumlah = abs($mutasi['jumlah'] ?? 0);
                                                echo $sign . 'Rp ' . number_format($jumlah, 0, ',', '.');
                                                ?>
                                            </div>
                                            <div class="trx-amount-label <?php echo $is_debit ? 'uang-keluar' : 'uang-masuk'; ?>">
                                                <?php echo $is_debit ? 'Uang Keluar' : 'Uang Masuk'; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
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
                <h3 class="filter-title">Filter Transaksi</h3>
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
        // Detect Sticky Header
        let stickyHeader = document.getElementById('stickyHeader');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 70) {
                stickyHeader.classList.add('stuck');
            } else {
                stickyHeader.classList.remove('stuck');
            }
        });
        // Smooth Tab Switch Function
        function switchTab(url) {
            const tabs = document.querySelector('.header-tabs');
            if (tabs) {
                tabs.classList.remove('tab-left');
                tabs.classList.add('tab-right');
            }

            // Wait for animation to complete before navigating
            setTimeout(() => {
                window.location.href = url;
            }, 300);
        }

        // Filter Modal Functions
        function openFilterModal() {
            document.getElementById('filterModal').classList.add('active');
        }
        function closeFilterModal(event) {
            if (!event || event.target.id === 'filterModal') {
                document.getElementById('filterModal').classList.remove('active');
            }
        }



        // Set max date to today
        document.addEventListener('DOMContentLoaded', function () {
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');

            dateInputs.forEach(input => {
                input.max = today;
            });

            // Initialize Transaction Chart
            const ctx = document.getElementById('transactionChart');
            if (ctx) {
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo $chart_labels; ?>,
                        datasets: [
                            {
                                label: 'Pengeluaran',
                                data: <?php echo $chart_pengeluaran; ?>,
                                backgroundColor: '#475569',
                                borderRadius: 6,
                                borderSkipped: false,
                            },
                            {
                                label: 'Pemasukan',
                                data: <?php echo $chart_pemasukan; ?>,
                                backgroundColor: '#94a3b8',
                                borderRadius: 6,
                                borderSkipped: false,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (context) {
                                        return context.dataset.label + ': Rp ' + context.raw.toLocaleString('id-ID');
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            y: {
                                grid: {
                                    borderDash: [3, 3],
                                    color: '#e2e8f0'
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    },
                                    callback: function (value) {
                                        if (value >= 1000000) {
                                            return 'Rp' + (value / 1000000) + 'jt';
                                        } else if (value >= 1000) {
                                            return 'Rp' + (value / 1000) + 'rb';
                                        }
                                        return 'Rp' + value;
                                    }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
</body>

</html>