<?php
/**
 * Halaman Laporan Pembayaran
 * File: pages/bendahara/laporan_pembayaran.php
 * 
 * Fitur: Filter Laporan per Periode dan Export PDF via TCPDF
 */

// ============================================
// ADAPTIVE PATH DETECTION & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} else {
    $project_root = dirname(dirname($current_dir));
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

// ================= BASE URL =================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
if (!defined('ASSETS_URL'))
    define('ASSETS_URL', BASE_URL . '/assets');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Cek Role Bendahara
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    header("Location: ../../login.php");
    exit;
}

// ============================================
// PREPARE DATA
// ============================================
$start_date = $_GET['start'] ?? date('Y-m-01');
$end_date = $_GET['end'] ?? date('Y-m-t');
$item_id_filter = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

// Get list of tagihan items for dropdown
$tagihan_items = [];
$res_items = $conn->query("SELECT id, nama_item FROM pembayaran_item WHERE is_aktif = 1 ORDER BY nama_item ASC");
while ($item_row = $res_items->fetch_assoc()) {
    $tagihan_items[] = $item_row;
}

// Build Where Clause
$where_clauses = ["1=1"];
$params = [];
$types = "";

if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "DATE(ptr.tanggal_bayar) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

if ($item_id_filter > 0) {
    $where_clauses[] = "pi.id = ?";
    $params[] = $item_id_filter;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Main Query
$sql = "SELECT 
            ptr.no_pembayaran, 
            ptr.tanggal_bayar, 
            ptr.nominal_bayar, 
            u.nama AS nama_siswa,
            k.nama_kelas,
            tk.nama_tingkatan,
            j.nama_jurusan,
            pi.nama_item
        FROM pembayaran_transaksi ptr
        JOIN users u ON ptr.siswa_id = u.id
        LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        LEFT JOIN jurusan j ON sp.jurusan_id = j.id
        JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
        JOIN pembayaran_item pi ON pt.item_id = pi.id
        WHERE $where_sql
        ORDER BY ptr.tanggal_bayar DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$total_amount = 0;
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
    $total_amount += $row['nominal_bayar'];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Pembayaran - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">

    <!-- CSS Dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />

    <style>
        :root {
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        .page-title-section {
            position: sticky;
            top: 0;
            z-index: 100;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            padding: 1.5rem 0;
            margin: -2rem -2rem 1.5rem -2rem;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: none;
        }

        .page-title-content {
            flex: 1;
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

        .page-hamburger:active {
            transform: scale(0.95);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--gray-600);
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: linear-gradient(to right, var(--gray-50), white);
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

        .filter-bar {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 1.25rem 1.5rem;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-100);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-control {
            padding: 0.6rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.9rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--gray-400);
            box-shadow: 0 0 0 4px rgba(100, 116, 139, 0.1);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-600);
        }

        .btn-secondary:hover {
            background: var(--gray-300);
        }

        .table-wrapper {
            max-height: 500px;
            overflow-y: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
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
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--gray-200);
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.8rem;
        }

        .data-table tbody tr:hover {
            background-color: var(--gray-50);
        }

        .cell-main {
            font-weight: 600;
            color: var(--gray-800);
        }

        .cell-sub {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .nominal {
            font-weight: 600;
            color: var(--gray-700);
        }

        .summary-box {
            padding: 1.5rem;
            background: var(--gray-50);
            border-top: 2px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .summary-label {
            font-size: 0.9rem;
            color: var(--gray-600);
        }

        .summary-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gray-300);
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--gray-400);
            font-size: 0.9rem;
        }

        @media screen and (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 75px;
            }

            .page-title-section {
                margin: -1rem -1rem 1rem -1rem;
                padding: 1rem;
            }

            .page-hamburger {
                display: flex;
            }

            .filter-bar {
                flex-direction: column;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }

            .filter-group {
                width: 100%;
            }

            .form-control {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
                padding-top: 70px;
            }

            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .card-header {
                padding: 1rem;
            }

            .filter-bar {
                padding: 1rem;
            }

            .summary-box {
                flex-direction: column;
                gap: 0.75rem;
                text-align: center;
            }

            .summary-value {
                font-size: 1.1rem;
            }
        }

        /* Hide Hamburger Button as requested */
        .mobile-navbar {
            display: none !important;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding-top: 1rem !important;
            }
        }
    </style>
</head>

<body>
    <?php include __DIR__ . '/sidebar_bendahara.php'; ?>

    <div class="main-content">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Laporan Pembayaran</h1>
                <p class="page-subtitle">Lihat dan cetak laporan pembayaran</p>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="card-header-text">
                    <h3>Filter Laporan</h3>
                    <p>Pilih periode dan jenis tagihan</p>
                </div>
            </div>
            <form method="GET" class="filter-bar" id="filterForm">
                <div class="filter-group">
                    <label>Tagihan</label>
                    <select name="item_id" id="itemFilter" class="form-control">
                        <option value="">-- Semua Tagihan --</option>
                        <?php foreach ($tagihan_items as $item): ?>
                            <option value="<?php echo $item['id']; ?>" <?php echo $item_id_filter == $item['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($item['nama_item']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Dari Tanggal</label>
                    <input type="date" name="start" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="end" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Tampilkan</button>
                <a href="export_laporan_pdf.php?item_id=<?php echo $item_id_filter; ?>&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>"
                    class="btn btn-secondary" target="_blank">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </a>
            </form>
        </div>

        <!-- Data Table Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="card-header-text">
                    <h3>Data Pembayaran</h3>
                    <p>Menampilkan <?php echo count($data); ?> transaksi</p>
                </div>
            </div>

            <?php if (count($data) > 0): ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>No. Pembayaran</th>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Tagihan</th>
                                <th>Nominal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no = 1;
                            foreach ($data as $row): ?>
                                <tr>
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['tanggal_bayar'])); ?></td>
                                    <td><span class="cell-main"><?php echo $row['no_pembayaran']; ?></span></td>
                                    <td>
                                        <div class="cell-main"><?php echo htmlspecialchars($row['nama_siswa']); ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-main">
                                            <?php echo trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? '')) ?: '-'; ?>
                                        </div>
                                        <div class="cell-sub"><?php echo htmlspecialchars($row['nama_jurusan'] ?? '-'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['nama_item']); ?></td>
                                    <td class="nominal">Rp <?php echo number_format($row['nominal_bayar'], 0, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="summary-box">
                    <div>
                        <div class="summary-label">Total <?php echo count($data); ?> Transaksi</div>
                    </div>
                    <div>
                        <div class="summary-label">Total Pemasukan</div>
                        <div class="summary-value">Rp <?php echo number_format($total_amount, 0, ',', '.'); ?></div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h3>Tidak Ada Data</h3>
                    <p>Tidak ada transaksi pembayaran pada periode yang dipilih.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Auto-submit when tagihan dropdown changes
        document.getElementById('itemFilter').addEventListener('change', function () {
            document.getElementById('filterForm').submit();
        });

        // Page Hamburger Button functionality
        document.addEventListener('DOMContentLoaded', function () {
            var pageHamburgerBtn = document.getElementById('pageHamburgerBtn');

            if (pageHamburgerBtn) {
                pageHamburgerBtn.addEventListener('click', function () {
                    var sidebar = document.getElementById('sidebar');
                    var overlay = document.getElementById('sidebarOverlay');

                    if (sidebar && overlay) {
                        sidebar.classList.add('active');
                        overlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }
        });
    </script>
</body>

</html>