<?php
/**
 * Halaman Arsip Riwayat Pembayaran
 * File: pages/bendahara/arsip_riwayat.php
 * 
 * Fitur:
 * - Menampilkan tagihan yang sudah lewat jatuh tempo (baik sudah dibayar maupun belum)
 * - Status: Lunas / Melewati Jatuh Tempo
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
// PREPARE DATA & FILTERS
// ============================================
$search_query = trim($_GET['q'] ?? '');
$item_id_filter = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get list of tagihan items for dropdown (only items with past jatuh tempo)
$tagihan_items = [];
$res_items = $conn->query("
    SELECT DISTINCT pi.id, pi.nama_item 
    FROM pembayaran_item pi
    JOIN pembayaran_tagihan pt ON pi.id = pt.item_id
    WHERE pt.tanggal_jatuh_tempo < CURDATE()
    ORDER BY pi.nama_item ASC
");
while ($item_row = $res_items->fetch_assoc()) {
    $tagihan_items[] = $item_row;
}

// Build Where Clauses for UNION query
$item_filter_sql = "";
$search_filter_sql = "";

if ($item_id_filter > 0) {
    $item_filter_sql = " AND pi.id = " . intval($item_id_filter);
}

if (!empty($search_query)) {
    $search_escaped = $conn->real_escape_string($search_query);
    $search_filter_sql = " AND (u.nama LIKE '%$search_escaped%' OR pt.no_tagihan LIKE '%$search_escaped%')";
}

// Combined query using UNION to get both paid and unpaid overdue bills
$sql_data = "
    (SELECT 
        pt.id,
        pt.no_tagihan AS no_ref,
        pt.tanggal_jatuh_tempo AS tanggal,
        pt.nominal,
        u.nama AS nama_siswa, 
        tk.nama_tingkatan, 
        k.nama_kelas,
        pi.nama_item,
        pt.status,
        NULL AS no_pembayaran,
        NULL AS tanggal_bayar,
        'belum_bayar' AS tipe
    FROM pembayaran_tagihan pt
    JOIN users u ON pt.siswa_id = u.id
    LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN kelas k ON sp.kelas_id = k.id
    LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
    JOIN pembayaran_item pi ON pt.item_id = pi.id
    WHERE pt.tanggal_jatuh_tempo < CURDATE() 
      AND pt.status = 'belum_bayar'
      $item_filter_sql
      $search_filter_sql)
    
    UNION ALL
    
    (SELECT 
        pt.id,
        ptr.no_pembayaran AS no_ref,
        ptr.tanggal_bayar AS tanggal,
        ptr.nominal_bayar AS nominal,
        u.nama AS nama_siswa, 
        tk.nama_tingkatan, 
        k.nama_kelas,
        pi.nama_item,
        pt.status,
        ptr.no_pembayaran,
        ptr.tanggal_bayar,
        'sudah_bayar' AS tipe
    FROM pembayaran_transaksi ptr
    JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
    JOIN users u ON ptr.siswa_id = u.id
    LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN kelas k ON sp.kelas_id = k.id
    LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
    JOIN pembayaran_item pi ON pt.item_id = pi.id
    WHERE pt.tanggal_jatuh_tempo < CURDATE()
      $item_filter_sql
      $search_filter_sql)
    
    ORDER BY tanggal DESC
    LIMIT $limit OFFSET $offset
";

$result = $conn->query($sql_data);

// Count Total
$sql_count = "
    SELECT (
        (SELECT COUNT(*) FROM pembayaran_tagihan pt 
         JOIN pembayaran_item pi ON pt.item_id = pi.id
         JOIN users u ON pt.siswa_id = u.id
         WHERE pt.tanggal_jatuh_tempo < CURDATE() AND pt.status = 'belum_bayar' $item_filter_sql $search_filter_sql)
        +
        (SELECT COUNT(*) FROM pembayaran_transaksi ptr 
         JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
         JOIN pembayaran_item pi ON pt.item_id = pi.id
         JOIN users u ON ptr.siswa_id = u.id
         WHERE pt.tanggal_jatuh_tempo < CURDATE() $item_filter_sql $search_filter_sql)
    ) AS total
";
$result_count = $conn->query($sql_count);
$total_rows = $result_count->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_rows / $limit);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arsip Riwayat - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">

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
            --success: #10b981;
            --danger: #ef4444;
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

        /* Data Table */
        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-50);
            padding: 0.5rem 0.75rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
            font-size: 0.8rem;
        }

        .data-table tr:hover {
            background: var(--gray-50);
        }

        .cell-main {
            font-weight: 600;
            color: var(--gray-800);
        }

        .cell-sub {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 2px;
        }

        .nominal {
            font-weight: 700;
            color: var(--gray-700);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-lunas {
            background: #dcfce7;
            color: #166534;
        }

        ``` .status-overdue {
            background: #fee2e2;
            color: #991b1b;
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

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            display: block;
            color: var(--gray-300);
        }

        @media (max-width: 1024px) {
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

            .filter-actions {
                width: 100%;
                display: flex;
                gap: 0.5rem;
            }

            .filter-actions .btn {
                flex: 1;
                justify-content: center;
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

            .pagination {
                padding: 1rem;
                flex-wrap: wrap;
            }

            .page-link {
                padding: 0.4rem 0.75rem;
                font-size: 0.85rem;
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
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Arsip Riwayat</h1>
                <p class="page-subtitle">Tagihan yang sudah lewat jatuh tempo (lunas & belum dibayar)</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon"><i class="fas fa-filter"></i></div>
                <div class="card-header-text">
                    <h3>Filter Arsip</h3>
                    <p>Menampilkan <?php echo $total_rows; ?> data</p>
                </div>
            </div>

            <form method="GET" class="filter-bar" id="filterForm">
                <div class="filter-group">
                    <label>Tagihan (Arsip)</label>
                    <select name="item_id" id="itemFilter" class="form-control">
                        <option value="">-- Semua Tagihan --</option>
                        <?php foreach ($tagihan_items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" <?php echo $item_id_filter == $item['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($item['nama_item']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex: 1;">
                    <label>Cari (Siswa / No. Tagihan)</label>
                    <input type="text" name="q" class="form-control" placeholder="Ketik kata kunci..."
                        value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                    <a href="arsip_riwayat.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </form>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No. Referensi</th>
                            <th>Tanggal</th>
                            <th>Siswa</th>
                            <th>Tagihan</th>
                            <th>Nominal</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                        <?php
                        $bulan_indo = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
                        $tgl = strtotime($row['tanggal']);
                        $tanggal_indo = date('d', $tgl) . ' ' . $bulan_indo[(int) date('n', $tgl)] . ' ' . date('Y', $tgl);
                        $is_paid = $row['tipe'] === 'sudah_bayar';
                        ?>
                        <tr>
                            <td>
                                <div class="cell-main"><?php echo htmlspecialchars($row['no_ref']); ?></div>
                                <div class="cell-sub"><?php echo $is_paid ? 'Pembayaran' : 'Tagihan'; ?></div>
                            </td>
                            <td>
                                <div class="cell-main"><?php echo $tanggal_indo; ?></div>
                                <div class="cell-sub"><?php echo $is_paid ? 'Tgl Bayar' : 'Jatuh Tempo'; ?></div>
                            </td>
                            <td>
                                <div class="cell-main"><?php echo htmlspecialchars($row['nama_siswa']); ?></div>
                                <div class="cell-sub">
                                    <?php echo htmlspecialchars(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? '-')); ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['nama_item']); ?></td>
                            <td class="nominal">Rp <?php echo number_format($row['nominal'], 0, ',', '.'); ?></td>
                            <td>
                                <?php if ($is_paid): ?>
                                <span class="status-badge status-lunas"><i class="fas fa-check-circle"></i> Lunas</span>
                                <?php else: ?>
                                <span class="status-badge status-overdue"><i class="fas fa-exclamation-circle"></i>
                                    Melewati
                                    Jatuh Tempo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fas fa-folder-open"></i>
                                    <p>Tidak ada data arsip.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php
                $q = '&q=' . urlencode($search_query) . ($item_id_filter ? '&item_id=' . $item_id_filter : '');
                ?>

                <!-- Previous -->
                <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?><?= $q ?>" class="page-link page-arrow">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php else: ?>
                <span class="page-link page-arrow disabled">
                    <i class="fas fa-chevron-left"></i>
                </span>
                <?php endif; ?>

                <!-- Current -->
                <span class="page-link active"><?= $page ?></span>

                <!-- Next -->
                <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page + 1 ?><?= $q ?>" class="page-link page-arrow">
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