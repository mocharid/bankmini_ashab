<?php
/**
 * Halaman Riwayat Pembayaran
 * File: pages/bendahara/riwayat_pembayaran.php
 * 
 * Fitur:
 * - Menampilkan daftar riwayat transaksi pembayaran
 * - Filter Tanggal (Dari - Sampai)
 * - Pencarian (Nama Siswa, No Pembayaran)
 * - Pagination
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
    $project_root = dirname(dirname($current_dir)); // Fallback
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
$date_start = $_GET['start'] ?? date('Y-m-01'); // Default awal bulan ini
$date_end = $_GET['end'] ?? date('Y-m-d');     // Default hari ini
$item_id_filter = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;

$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get list of tagihan items for dropdown (only items with active/future jatuh tempo)
$tagihan_items = [];
$res_items = $conn->query("
    SELECT DISTINCT pi.id, pi.nama_item 
    FROM pembayaran_item pi
    JOIN pembayaran_tagihan pt ON pi.id = pt.item_id
    WHERE pi.is_aktif = 1 
      AND pt.tanggal_jatuh_tempo >= CURDATE()
    ORDER BY pi.nama_item ASC
");
while ($item_row = $res_items->fetch_assoc()) {
    $tagihan_items[] = $item_row;
}

// Build Where Clause
$where_clauses = ["1=1"];
$params = [];
$types = "";

// Filter Date
if (!empty($date_start) && !empty($date_end)) {
    $where_clauses[] = "DATE(ptr.tanggal_bayar) BETWEEN ? AND ?";
    $params[] = $date_start;
    $params[] = $date_end;
    $types .= "ss";
}

// Filter Search (hanya nama dan no pembayaran)
if (!empty($search_query)) {
    $where_clauses[] = "(u.nama LIKE ? OR ptr.no_pembayaran LIKE ?)";
    $like_query = "%$search_query%";
    $params[] = $like_query;
    $params[] = $like_query;
    $types .= "ss";
}

// Filter by Tagihan Item
if ($item_id_filter > 0) {
    $where_clauses[] = "pi.id = ?";
    $params[] = $item_id_filter;
    $types .= "i";
}

$where_sql = implode(" AND ", $where_clauses);

// Count Total
$sql_count = "SELECT COUNT(*) as total 
              FROM pembayaran_transaksi ptr
              JOIN users u ON ptr.siswa_id = u.id
              JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
              JOIN pembayaran_item pi ON pt.item_id = pi.id
              WHERE $where_sql";

$stmt_count = $conn->prepare($sql_count);
if (!empty($params))
    $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Main Query
$sql_data = "SELECT 
                ptr.id, 
                ptr.no_pembayaran, 
                ptr.tanggal_bayar, 
                ptr.nominal_bayar, 
                ptr.metode_bayar,
                ptr.keterangan,
                u.nama AS nama_siswa, 
                tk.nama_tingkatan, 
                k.nama_kelas,
                pi.nama_item
             FROM pembayaran_transaksi ptr
             JOIN users u ON ptr.siswa_id = u.id
             LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
             LEFT JOIN kelas k ON sp.kelas_id = k.id
             LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
             JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
             JOIN pembayaran_item pi ON pt.item_id = pi.id
             WHERE $where_sql
             ORDER BY ptr.tanggal_bayar DESC
             LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt_data = $conn->prepare($sql_data);
$stmt_data->bind_param($types, ...$params);
$stmt_data->execute();
$result = $stmt_data->get_result();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riwayat Pembayaran - KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">

    <!-- CSS Dependencies -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --primary: #0c4a6e;
            --primary-light: #0369a1;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --radius-sm: 6px;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
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

        /* Page Title Section */
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
            margin-bottom: 0.25rem;
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

        /* Cards */
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

        .card-body {
            padding: 1.5rem;
        }

        /* Filter Bar */
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

        /* Buttons */
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
            font-size: 0.85rem;
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

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--gray-200);
            color: var(--gray-700);
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

        /* Empty State */
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

                .pagination {
                    padding: 1rem;
                    flex-wrap: wrap;
                }

                .page-link {
                    padding: 0.4rem 0.75rem;
                    font-size: 0.85rem;
                }

                .nominal {
                    font-size: 0.8rem;
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
                <h1 class="page-title">Riwayat Pembayaran</h1>
                <p class="page-subtitle">Daftar semua transaksi pembayaran yang telah diproses</p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-filter"></i>
                </div>
                <div class="card-header-text">
                    <h3>Filter Data</h3>
                    <p>Cari dan filter riwayat pembayaran</p>
                </div>
            </div>

            <form method="GET" class="filter-bar" id="filterForm">
                <div class="filter-group">
                    <label>Tagihan Pembayaran</label>
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
                    <label>Tanggal Awal</label>
                    <input type="date" name="start" id="startDate" class="form-control"
                        value="<?php echo $date_start; ?>">
                </div>
                <div class="filter-group">
                    <label>Tanggal Akhir</label>
                    <input type="date" name="end" id="endDate" class="form-control" value="<?php echo $date_end; ?>">
                </div>
                <div class="filter-group" style="flex: 1;">
                    <label>Cari (Siswa / No. Bayar)</label>
                    <input type="text" name="q" id="searchInput" class="form-control" placeholder="Ketik kata kunci..."
                        value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
                <a href="riwayat_pembayaran.php" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
            </form>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 18%;">No. Pembayaran</th>
                            <th style="width: 12%;">Tanggal</th>
                            <th style="width: 25%;">Siswa</th>
                            <th style="width: 25%;">Tagihan</th>
                            <th style="width: 15%;">Nominal</th>
                            <th style="width: 5%;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                $bulan_indo = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'];
                                $tgl = strtotime($row['tanggal_bayar']);
                                $tanggal_indo = date('d', $tgl) . ' ' . $bulan_indo[(int) date('n', $tgl)] . ' ' . date('Y H:i', $tgl);
                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-main"><?php echo $row['no_pembayaran']; ?></div>
                                        <div class="cell-sub">
                                            <?php echo isset($row['metode_bayar']) ? ucwords(str_replace('_', ' ', $row['metode_bayar'])) : '-'; ?>
                                        </div>
                                    </td>
                                    <td><?php echo $tanggal_indo; ?></td>
                                    <td>
                                        <div class="cell-main"><?php echo htmlspecialchars($row['nama_siswa']); ?></div>
                                        <div class="cell-sub">
                                            <?php echo htmlspecialchars(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? '-')); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($row['nama_item']); ?>
                                    </td>
                                    <td class="nominal">
                                        Rp <?php echo number_format($row['nominal_bayar'], 0, ',', '.'); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge"><i class="fas fa-check-circle"></i> Sukses</span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <p>Tidak ada data riwayat pembayaran.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $q = '&start=' . urlencode($date_start) .
                        '&end=' . urlencode($date_end) .
                        '&q=' . urlencode($search_query) .
                        ($item_id_filter ? '&item_id=' . $item_id_filter : '');
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
        // Auto-submit when tagihan dropdown changes
        document.getElementById('itemFilter').addEventListener('change', function () {
            document.getElementById('filterForm').submit();
        });

        // Auto-submit when typing in search (with debounce)
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function () {
                document.getElementById('filterForm').submit();
            }, 500); // Submit after 500ms of no typing
        });
    </script>
    <script>
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