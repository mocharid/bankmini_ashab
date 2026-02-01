<?php
/**
 * Data Kehadiran Petugas - Harmonized Version
 * File: pages/admin/data_kehadiran_petugas.php
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

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Validate and sanitize date inputs
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])
    ? $_GET['start_date']
    : '';

$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])
    ? $_GET['end_date']
    : '';

// Ensure start_date <= end_date
if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Function to format date to dd/mm/yy
function formatDate($date)
{
    return date('d/m/y', strtotime($date));
}

// Fungsi format tanggal Indonesia
function tgl_indo($date)
{
    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];
    $split = explode('-', $date);
    return (int) $split[2] . ' ' . $bulan[(int) $split[1]] . ' ' . $split[0];
}


// ========================================
// PAGINATION & DATA UNTUK TAMPILAN NORMAL
// ========================================
$items_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// ✅ Build query utama – nama dari petugas_profiles, jam dari petugas_status
// Hanya tampilkan jadwal yang SUDAH ADA DATA ABSENSI (petugas sudah melakukan tugas)
$baseQuery = "SELECT 
                ps.tanggal, 
                COALESCE(pp1.petugas1_nama, pp1.petugas2_nama) AS petugas1_nama,
                COALESCE(pp2.petugas2_nama, pp2.petugas1_nama) AS petugas2_nama,
                ps1.status AS petugas1_status,
                ps1.waktu_masuk AS petugas1_masuk,
                ps1.waktu_keluar AS petugas1_keluar,
                ps2.status AS petugas2_status,
                ps2.waktu_masuk AS petugas2_masuk,
                ps2.waktu_keluar AS petugas2_keluar
             FROM petugas_shift ps
             LEFT JOIN petugas_profiles pp1 ON ps.petugas1_id = pp1.user_id
             LEFT JOIN petugas_profiles pp2 ON ps.petugas2_id = pp2.user_id
             LEFT JOIN petugas_status ps1 
                ON ps.id = ps1.petugas_shift_id AND ps1.petugas_type = 'petugas1'
             LEFT JOIN petugas_status ps2 
                ON ps.id = ps2.petugas_shift_id AND ps2.petugas_type = 'petugas2'";

// Count query juga harus filter yang sudah lewat/hari ini
$countQuery = "SELECT COUNT(*) as total 
               FROM petugas_shift ps";

// Add WHERE clauses
$whereClause = [];
$params = [];
$types = "";

// ✅ Filter hanya jadwal yang tanggalnya sudah lewat atau hari ini (bukan jadwal masa depan)
$whereClause[] = "ps.tanggal <= CURDATE()";

if (!empty($start_date)) {
    $whereClause[] = "ps.tanggal >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if (!empty($end_date)) {
    $whereClause[] = "ps.tanggal <= ?";
    $params[] = $end_date;
    $types .= "s";
}

// Selalu ada minimal 1 kondisi (tanggal <= CURDATE()), jadi langsung tambahkan WHERE
$whereString = " WHERE " . implode(" AND ", $whereClause);
$baseQuery .= $whereString;
$countQuery .= $whereString;

// Get total count
try {
    $countStmt = $conn->prepare($countQuery);
    if (!$countStmt) {
        error_log("Error preparing count query: " . $conn->error);
        die("Error preparing count query");
    }
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $total_records = $countResult->fetch_assoc()['total'];
    $countStmt->close();
} catch (Exception $e) {
    error_log("Database error in count query: " . $e->getMessage());
    $total_records = 0;
}

$total_pages = ceil($total_records / $items_per_page);

// Add pagination
$baseQuery .= " ORDER BY ps.tanggal ASC LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= "ii";

// Execute main query
try {
    $stmt = $conn->prepare($baseQuery);
    if (!$stmt) {
        throw new Exception("Failed to prepare absensi query: " . $conn->error);
    }
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $absensi = [];
    while ($row = $result->fetch_assoc()) {
        $absensi[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching absensi: " . $e->getMessage());
    $absensi = [];
    $error_message = 'Gagal mengambil data absensi: ' . $e->getMessage();
    $show_error_modal = true;
}

// Format periode display
$periode_display = 'Semua Data';
if (!empty($start_date) && !empty($end_date)) {
    $periode_display = date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date));
} elseif (!empty($start_date)) {
    $periode_display = 'Dari ' . date('d/m/Y', strtotime($start_date));
} elseif (!empty($end_date)) {
    $periode_display = 'Sampai ' . date('d/m/Y', strtotime($end_date));
}

// Map status untuk badge (dipakai di HTML)
$status_map = [
    'hadir' => 'Hadir',
    'tidak_hadir' => 'Tidak Hadir',
    'sakit' => 'Sakit',
    'izin' => 'Izin',
    '-' => '-'
];

$status_class_map = [
    'hadir' => 'status-hadir',
    'tidak_hadir' => 'status-tidak_hadir',
    'sakit' => 'status-sakit',
    'izin' => 'status-izin',
    '' => '',
    '-' => ''
];
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Data Kehadiran Petugas | KASDIG</title>
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
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
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

        .status-hadir {
            background: #dcfce7;
            color: #166534;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-tidak_hadir {
            background: #fee2e2;
            color: #991b1b;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-sakit {
            background: #ffedd5;
            color: #9a3412;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-izin {
            background: #dbeafe;
            color: #1e40af;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
            cursor: default;
        }

        .page-link.page-arrow {
            padding: 0.5rem 0.75rem;
        }

        .page-link.disabled {
            color: var(--gray-300);
            cursor: not-allowed;
            background: var(--gray-50);
            pointer-events: none;
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
                <h1 class="page-title">Data Kehadiran Petugas</h1>
                <p class="page-subtitle">Lihat data kehadiran petugas secara terpusat</p>
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
                    <p>Pilih rentang tanggal untuk melihat kehadiran</p>
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

        <!-- Card Daftar Kehadiran -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
                <div class="card-header-text">
                    <h3>Daftar Absensi Petugas</h3>
                    <p>Riwayat kehadiran petugas shift</p>
                </div>
                <a href="download_kehadiran_petugas.php?<?= http_build_query(array_filter(['start_date' => $start_date, 'end_date' => $end_date])) ?>"
                    class="btn btn-pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Nama Petugas</th>
                            <th>Status</th>
                            <th>Jam Masuk</th>
                            <th>Jam Keluar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($absensi)): ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:3rem; color:var(--gray-500);">Belum ada
                                    data
                                    absensi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; ?>
                            <?php foreach ($absensi as $record): ?>
                                <tr>
                                    <td rowspan="2"><?= $no++ ?></td>
                                    <td rowspan="2"><?= date('d/m/Y', strtotime($record['tanggal'])) ?></td>
                                    <td><?= htmlspecialchars($record['petugas1_nama'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $s1 = $record['petugas1_status'] ?? '-';
                                        $s1_label = $status_map[$s1] ?? $s1;
                                        $s1_class = $status_class_map[$s1] ?? '';
                                        ?>
                                        <span class="<?= $s1_class ?>"><?= htmlspecialchars($s1_label) ?></span>
                                    </td>
                                    <td>
                                        <?= ($record['petugas1_status'] === 'hadir' && $record['petugas1_masuk'])
                                            ? htmlspecialchars(date('H:i', strtotime($record['petugas1_masuk'])))
                                            : '-' ?>
                                    </td>
                                    <td>
                                        <?= ($record['petugas1_status'] === 'hadir' && $record['petugas1_keluar'])
                                            ? htmlspecialchars(date('H:i', strtotime($record['petugas1_keluar'])))
                                            : '-' ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><?= htmlspecialchars($record['petugas2_nama'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $s2 = $record['petugas2_status'] ?? '-';
                                        $s2_label = $status_map[$s2] ?? $s2;
                                        $s2_class = $status_class_map[$s2] ?? '';
                                        ?>
                                        <span class="<?= $s2_class ?>"><?= htmlspecialchars($s2_label) ?></span>
                                    </td>
                                    <td>
                                        <?= ($record['petugas2_status'] === 'hadir' && $record['petugas2_masuk'])
                                            ? htmlspecialchars(date('H:i', strtotime($record['petugas2_masuk'])))
                                            : '-' ?>
                                    </td>
                                    <td>
                                        <?= ($record['petugas2_status'] === 'hadir' && $record['petugas2_keluar'])
                                            ? htmlspecialchars(date('H:i', strtotime($record['petugas2_keluar'])))
                                            : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <?php
                $q = '';
                if (!empty($start_date))
                    $q .= '&start_date=' . urlencode($start_date);
                if (!empty($end_date))
                    $q .= '&end_date=' . urlencode($end_date);
                ?>
                <div class="pagination">
                    <!-- Previous -->
                    <?php if ($current_page > 1): ?>
                        <a href="?page=<?= $current_page - 1 ?><?= $q ?>" class="page-link page-arrow">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Current -->
                    <span class="page-link active"><?= $current_page ?></span>

                    <!-- Next -->
                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=<?= $current_page + 1 ?><?= $q ?>" class="page-link page-arrow">
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
        const resetBtn = document.getElementById('resetBtn');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                window.location.href = '<?= BASE_URL ?>/pages/admin/data_kehadiran_petugas.php';
            });
        }


        <?php if (isset($show_error_modal) && $show_error_modal): ?>
            Swal.fire({
                title: 'Gagal',
                text: '<?= addslashes($error_message) ?>',
                icon: 'error',
                confirmButtonColor: '#475569'
            });
        <?php endif; ?>
    </script>
</body>

</html>