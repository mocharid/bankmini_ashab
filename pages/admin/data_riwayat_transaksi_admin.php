<?php
/**
 * Data Riwayat Transaksi Admin - Harmonized Version
 * File: pages/admin/data_riwayat_transaksi_admin.php
 */

// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
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
if (!$project_root)
    $project_root = $current_dir;

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path))
        $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// LOGIC & DATABASE
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Validate session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Get user information from session
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Filter tanggal
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Check if this is an AJAX pagination request
$is_ajax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// Pagination settings
$per_page = 10;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Build WHERE clause based on date filter
$date_where = '';
$date_params = [];
$date_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $date_where = " AND DATE(t.created_at) BETWEEN ? AND ?";
    $date_params = [$start_date, $end_date];
    $date_types = 'ss';
}

// Calculate summary statistics
$total_query = "SELECT
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN kelas k ON sp.kelas_id = k.id
    LEFT JOIN users p ON t.petugas_id = p.id
    WHERE t.jenis_transaksi IN ('setor', 'tarik')
    AND (t.petugas_id IS NULL OR p.role = 'admin')" . $date_where;

$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    error_log("Error preparing total query: " . $conn->error);
    die("Error preparing total query");
}

if (!empty($date_params)) {
    $stmt_total->bind_param($date_types, ...$date_params);
}
$stmt_total->execute();
$totals = $stmt_total->get_result()->fetch_assoc();
$stmt_total->close();

$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_debit'] = (float) ($totals['total_debit'] ?? 0);
$totals['total_kredit'] = (float) ($totals['total_kredit'] ?? 0);

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN users p ON t.petugas_id = p.id
    WHERE t.jenis_transaksi IN ('setor', 'tarik')
    AND (t.petugas_id IS NULL OR p.role = 'admin')" . $date_where;

$stmt_count = $conn->prepare($count_query);
if (!empty($date_params))
    $stmt_count->bind_param($date_types, ...$date_params);
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_records / $per_page);

// Fetch transactions with class information and no_rekening
try {
    $query = "SELECT
        t.no_transaksi,
        r.no_rekening,
        t.jenis_transaksi,
        t.jumlah,
        t.created_at,
        t.status,
        u.nama AS nama_siswa,
        k.nama_kelas,
        tk.nama_tingkatan
        FROM transaksi t
        JOIN rekening r ON t.rekening_id = r.id
        JOIN users u ON r.user_id = u.id
        JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        LEFT JOIN users p ON t.petugas_id = p.id
        WHERE t.jenis_transaksi IN ('setor', 'tarik')
        AND (t.petugas_id IS NULL OR p.role = 'admin')" . $date_where . "
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?";

    $stmt_trans = $conn->prepare($query);
    if (!$stmt_trans) {
        throw new Exception("Failed to prepare transaction query: " . $conn->error);
    }

    if (!empty($date_params)) {
        $params = array_merge($date_params, [$per_page, $offset]);
        $stmt_trans->bind_param($date_types . 'ii', ...$params);
    } else {
        $stmt_trans->bind_param('ii', $per_page, $offset);
    }

    $stmt_trans->execute();
    $result = $stmt_trans->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    $stmt_trans->close();
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
    $error_message = 'Gagal mengambil data transaksi: ' . $e->getMessage();
    $show_error_modal = true;
}

// Handle AJAX pagination request
if ($is_ajax) {
    // Build table rows HTML
    $tableHtml = '';
    if (empty($transactions)) {
        $tableHtml = '<tr><td colspan="8" style="text-align:center; padding:3rem; color:var(--gray-500);">Belum ada data transaksi.</td></tr>';
    } else {
        $no = $offset + 1;
        foreach ($transactions as $transaction) {
            $tableHtml .= '<tr>';
            $tableHtml .= '<td>' . $no++ . '</td>';
            $tableHtml .= '<td>' . formatTanggalIndonesia($transaction['created_at']) . '</td>';
            $tableHtml .= '<td><span style="font-family:monospace; color:var(--gray-600);">' . htmlspecialchars($transaction['no_transaksi']) . '</span></td>';
            $tableHtml .= '<td style="font-weight: 500;">' . htmlspecialchars($transaction['nama_siswa']) . '</td>';
            $tableHtml .= '<td>' . htmlspecialchars(maskAccountNumber($transaction['no_rekening'])) . '</td>';
            $tableHtml .= '<td>' . htmlspecialchars($transaction['nama_tingkatan'] . ' ' . $transaction['nama_kelas']) . '</td>';
            $tableHtml .= '<td>' . ucfirst(htmlspecialchars($transaction['jenis_transaksi'])) . '</td>';
            $tableHtml .= '<td style="font-weight: 600; color: var(--gray-800);">' . formatRupiah($transaction['jumlah']) . '</td>';
            $tableHtml .= '</tr>';
        }
    }

    // Build pagination HTML
    $paginationHtml = '';
    if ($total_pages > 1) {
        // Previous button
        if ($page > 1) {
            $paginationHtml .= '<a href="#" class="page-link page-arrow" data-page="' . ($page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
        } else {
            $paginationHtml .= '<span class="page-link page-arrow disabled"><i class="fas fa-chevron-left"></i></span>';
        }

        // Current page
        $paginationHtml .= '<span class="page-link active">' . $page . '</span>';

        // Next button
        if ($page < $total_pages) {
            $paginationHtml .= '<a href="#" class="page-link page-arrow" data-page="' . ($page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
        } else {
            $paginationHtml .= '<span class="page-link page-arrow disabled"><i class="fas fa-chevron-right"></i></span>';
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'tableHtml' => $tableHtml,
        'paginationHtml' => $paginationHtml,
        'currentPage' => $page,
        'totalPages' => $total_pages
    ]);
    exit();
}

// Function to format Rupiah
function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Function to mask account number
function maskAccountNumber($accountNumber)
{
    $length = strlen($accountNumber);
    if ($length <= 5) {
        return $accountNumber;
    }
    $first3 = substr($accountNumber, 0, 3);
    $last2 = substr($accountNumber, -2);
    $maskLength = $length - 5;
    $mask = str_repeat('*', $maskLength);
    return $first3 . $mask . $last2;
}

// Function to format date in Indonesian
function formatTanggalIndonesia($date)
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
    $day = date('d', strtotime($date));
    $month = (int) date('m', strtotime($date));
    $year = date('Y', strtotime($date));
    return $day . ' ' . $bulan[$month] . ' ' . $year;
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Data Riwayat Transaksi Admin | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            backdrop-filter: blur(5px);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100% - 280px);
            position: relative;
            z-index: 1;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
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

        /* Cards */
        .card,
        .form-card,
        .jadwal-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text {
            flex: 1;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* INPUT STYLING */
        input[type="text"],
        input[type="date"],
        select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            height: 48px;
            color: var(--gray-800);
        }

        input:focus,
        select:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        /* Date Picker Wrapper */
        .date-input-wrapper {
            position: relative;
            width: 100%;
        }

        /* Input Date Specifics */
        input[type="date"] {
            appearance: none;
            -webkit-appearance: none;
            position: relative;
            padding-right: 40px;
        }

        /* HIDE Native Calendar Icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
            z-index: 2;
        }

        /* Custom Icon */
        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-500);
            font-size: 1.1rem;
            z-index: 1;
            pointer-events: none;
        }

        /* Button */
        .btn {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
        }

        /* Data Table */
        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tr:hover {
            background: var(--gray-100) !important;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.5rem;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            min-width: 40px;
            text-align: center;
        }

        .page-link:hover:not(.disabled) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
        }

        .page-link.disabled {
            color: var(--gray-300);
            pointer-events: none;
            cursor: not-allowed;
        }

        .pagination-info {
            color: var(--gray-500);
            font-size: 0.875rem;
            margin: 0 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                padding: 1.25rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }

            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .card-body {
                padding: 1rem;
            }

            .card-header {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .data-table {
                min-width: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .pagination {
                flex-wrap: wrap;
                padding: 1rem;
            }

            .page-link {
                padding: 0.4rem 0.75rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 640px) {
            .page-title {
                font-size: 1.25rem;
            }

            .page-subtitle {
                font-size: 0.85rem;
            }

            .btn {
                width: 100%;
            }

            .card-header .btn {
                width: auto;
            }
        }

        /* SweetAlert Custom Fixes */
        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Riwayat Transaksi Admin</h1>
                <p class="page-subtitle">Pantau semua transaksi setor dan tarik tunai</p>
            </div>
        </div>

        <!-- Card Filter Tanggal -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="card-header-text">
                    <h3>Filter Tanggal</h3>
                    <p>Pilih rentang tanggal untuk melihat transaksi</p>
                </div>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date">Dari Tanggal</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="start_date" name="start_date"
                                    value="<?= htmlspecialchars($start_date) ?>">
                                <i class="fas fa-calendar-alt calendar-icon"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="end_date">Sampai Tanggal</label>
                            <div class="date-input-wrapper">
                                <input type="date" id="end_date" name="end_date"
                                    value="<?= htmlspecialchars($end_date) ?>">
                                <i class="fas fa-calendar-alt calendar-icon"></i>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <button type="submit" class="btn" style="width: auto;">
                            <i class="fas fa-filter"></i> Terapkan
                        </button>
                        <button type="button" id="resetBtn" class="btn btn-cancel" style="width: auto;">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Daftar Transaksi -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div class="card-header-text">
                    <h3>Daftar Transaksi</h3>
                    <p>Riwayat transaksi setor dan tarik tunai</p>
                </div>
                <a id="pdfLink" class="btn btn-pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>No Transaksi</th>
                            <th>Nama Siswa</th>
                            <th>No Rekening</th>
                            <th>Kelas</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody id="transactionTableBody">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" style="text-align:center; padding:3rem; color:var(--gray-500);">Belum ada
                                    data transaksi.</td>
                            </tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; ?>

                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= formatTanggalIndonesia($transaction['created_at']) ?></td>
                                    <td><span
                                            style="font-family:monospace; color:var(--gray-600);"><?= htmlspecialchars($transaction['no_transaksi']) ?></span>
                                    </td>
                                    <td style="font-weight: 500;"><?= htmlspecialchars($transaction['nama_siswa']) ?></td>
                                    <td><?= htmlspecialchars(maskAccountNumber($transaction['no_rekening'])) ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_tingkatan'] . ' ' . $transaction['nama_kelas']) ?>
                                    </td>
                                    <td>
                                        <?= ucfirst(htmlspecialchars($transaction['jenis_transaksi'])) ?>
                                    </td>
                                    <td style="font-weight: 600; color: var(--gray-800);">
                                        <?= formatRupiah($transaction['jumlah']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination" id="paginationContainer">
                    <!-- Previous button -->
                    <?php if ($page > 1): ?>
                        <a href="#" class="page-link page-arrow" data-page="<?= $page - 1 ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Current page indicator -->
                    <span class="page-link active">
                        <?= $page ?>
                    </span>

                    <!-- Next button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="#" class="page-link page-arrow" data-page="<?= $page + 1 ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('pageHamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;

        function openSidebar() {
            body.classList.add('sidebar-open');
            if (sidebar) sidebar.classList.add('active');
        }

        function closeSidebar() {
            body.classList.remove('sidebar-open');
            if (sidebar) sidebar.classList.remove('active');
        }

        // Toggle Sidebar
        if (menuToggle) {
            menuToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function (e) {
            if (body.classList.contains('sidebar-open') &&
                !sidebar?.contains(e.target) &&
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Prevent body scroll when sidebar open (mobile)
        let scrollTop = 0;
        body.addEventListener('scroll', function () {
            if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
                scrollTop = body.scrollTop;
            }
        }, { passive: true });

        // ============================================
        // FORM FUNCTIONALITY
        // ============================================
        document.getElementById('resetBtn').addEventListener('click', () => {
            window.location.href = '<?= BASE_URL ?>/pages/admin/data_riwayat_transaksi_admin.php';
        });

        const pdfLink = document.getElementById('pdfLink');
        const totalRecords = <?= $total_records ?>;

        pdfLink.addEventListener('click', function (e) {
            e.preventDefault();
            if (totalRecords === 0) {
                Swal.fire({
                    title: 'Tidak ada data',
                    text: 'Tidak ada transaksi untuk diunduh',
                    icon: 'warning',
                    confirmButtonColor: '#475569'
                });
                return;
            }

            Swal.fire({
                title: 'Sedang memproses...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            setTimeout(() => {
                Swal.close();

                let url = '<?= BASE_URL ?>/pages/admin/download_laporan_admin.php?format=pdf';
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;

                if (startDate && endDate) {
                    url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                }

                window.open(url, '_blank');
            }, 2000);
        });

        // ============================================
        // AJAX PAGINATION
        // ============================================
        function loadPage(pageNum) {
            const tableBody = document.getElementById('transactionTableBody');
            const paginationContainer = document.getElementById('paginationContainer');
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;

            // Build URL
            let url = '<?= basename($_SERVER['SCRIPT_NAME']) ?>?ajax=1&page=' + pageNum;
            if (startDate) url += '&start_date=' + encodeURIComponent(startDate);
            if (endDate) url += '&end_date=' + encodeURIComponent(endDate);

            // Show loading state
            tableBody.style.opacity = '0.5';

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update table body
                        tableBody.innerHTML = data.tableHtml;

                        // Update pagination
                        if (paginationContainer) {
                            paginationContainer.innerHTML = data.paginationHtml;
                            // Re-attach event listeners
                            attachPaginationEvents();
                        }

                        // Update URL without refresh
                        const newUrl = new URL(window.location.href);
                        newUrl.searchParams.set('page', pageNum);
                        window.history.pushState({}, '', newUrl);
                    }
                    tableBody.style.opacity = '1';
                })
                .catch(error => {
                    console.error('Pagination error:', error);
                    tableBody.style.opacity = '1';
                });
        }

        function attachPaginationEvents() {
            const paginationLinks = document.querySelectorAll('#paginationContainer a[data-page]');
            paginationLinks.forEach(link => {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    const pageNum = this.getAttribute('data-page');
                    if (pageNum) {
                        loadPage(parseInt(pageNum));
                    }
                });
            });
        }

        // Initial attachment
        attachPaginationEvents();
    </script>
</body>

</html>
```