<?php
/**
 * Halaman Daftar Tagihan (Summary View)
 * File: pages/bendahara/daftar_tagihan.php
 * 
 * Fitur:
 * - Menampilkan list JENIS TAGIHAN (Item)
 * - Statistik per item (Total siswa, Lunas, Belum Bayar)
 * - Hapus Item Tagihan
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
// HANDLE ACTION (DELETE ITEM)
// ============================================
$success_msg = '';
$error_msg = '';

if (isset($_POST['delete_item_id'])) {
    $delete_id = intval($_POST['delete_item_id']);

    // Begin Transaction
    $conn->begin_transaction();
    try {
        // Cek apakah ada pembayaran masuk (tranksaksi) untuk item ini?
        // Jika sudah ada uang masuk, sebaiknya jangan di-delete hard, tapi soft delete (is_aktif=0).
        // Tapi user minta Hapus, kita asumsikan hapus bersih jika masih draft/baru, atau reject jika ada duit.

        $check_trans = $conn->query("SELECT count(*) as total FROM pembayaran_tagihan pt 
                                   WHERE pt.item_id = $delete_id AND pt.status = 'sudah_bayar'");
        $row_trans = $check_trans->fetch_assoc();

        if ($row_trans['total'] > 0) {
            throw new Exception("Tidak dapat menghapus tagihan ini karena sudah ada siswa yang membayar. Silakan non-aktifkan saja jika ingin menutup.");
        }

        // 1. Hapus Tagihan Siswa (Cascade biasanya handle ini, tapi kita explicit biar aman)
        $conn->query("DELETE FROM pembayaran_tagihan WHERE item_id = $delete_id");

        // 2. Hapus Status Siswa
        $conn->query("DELETE FROM pembayaran_siswa_status WHERE item_id = $delete_id");

        // 3. Hapus Item
        $conn->query("DELETE FROM pembayaran_item WHERE id = $delete_id");

        $conn->commit();
        $success_msg = "Item tagihan berhasil dihapus beserta data tagihannya.";

    } catch (Exception $e) {
        $conn->rollback();
        $error_msg = $e->getMessage();
    }
}

// ============================================
// PREPARE DATA & PAGINATION
// ============================================
$search_query = trim($_GET['q'] ?? '');
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 5;
$offset = ($page - 1) * $limit;

// Where Clause
$where_clauses = ["is_aktif = 1"]; // Tampilkan yang aktif saja? Atau semua? Asumsi semua yang created.
$params = [];
$types = "";

if (!empty($search_query)) {
    $where_clauses[] = "nama_item LIKE ?";
    $params[] = "%$search_query%";
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Count Total - hanya tagihan yang belum jatuh tempo
$sql_count = "SELECT COUNT(*) as total FROM (
                SELECT pi.id
                FROM pembayaran_item pi
                LEFT JOIN pembayaran_tagihan pt ON pi.id = pt.item_id
                WHERE $where_sql
                GROUP BY pi.id
                HAVING MAX(pt.tanggal_jatuh_tempo) >= CURDATE() OR MAX(pt.tanggal_jatuh_tempo) IS NULL
              ) as filtered";
$stmt_count = $conn->prepare($sql_count);
if (!empty($params))
    $stmt_count->bind_param($types, ...$params);
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Main Query with Stats
// Disini kita JOIN ke pembayaran_tagihan untuk hitung statistik
// Filter: hanya tampilkan tagihan yang belum jatuh tempo (tanggal_jatuh_tempo >= hari ini)
$sql_data = "SELECT 
                pi.id, 
                pi.nama_item, 
                pi.nominal_default, 
                pi.created_at,
                COUNT(pt.id) as total_siswa,
                SUM(CASE WHEN pt.status = 'sudah_bayar' THEN 1 ELSE 0 END) as total_lunas,
                SUM(CASE WHEN pt.status IN ('belum_bayar', 'tertunggak') THEN 1 ELSE 0 END) as total_belum,
                MAX(pt.tanggal_jatuh_tempo) as jatuh_tempo
             FROM pembayaran_item pi
             LEFT JOIN pembayaran_tagihan pt ON pi.id = pt.item_id
             WHERE $where_sql
             GROUP BY pi.id
             HAVING MAX(pt.tanggal_jatuh_tempo) >= CURDATE() OR MAX(pt.tanggal_jatuh_tempo) IS NULL
             ORDER BY pi.created_at DESC
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
    <title>Daftar Tagihan Pembayaran - KASDIG</title>
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
            --primary-dark: #082f49;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            --radius-sm: 6px;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
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
            border-bottom: none;
        }

        .page-title-content {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
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

        /* Action Button */
        .btn {
            padding: 0.875rem 1.75rem;
            border: none;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(12, 74, 110, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(12, 74, 110, 0.4);
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

        /* Filter Bar */
        .filter-bar {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            gap: 1rem;
            background: var(--gray-50);
        }

        .search-box {
            position: relative;
            flex: 1;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 0.95rem;
            font-family: inherit;
            transition: all 0.2s ease;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(12, 74, 110, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
        }

        /* Data Table */
        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
            background: var(--gray-50);
        }

        .data-table th {
            background: var(--gray-50);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.75rem;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.8rem;
            vertical-align: middle;
        }

        .data-table tr:hover {
            background: var(--gray-50);
        }

        .data-table tr:last-child td {
            border-bottom: none;
        }

        /* Item Name */
        .item-name {
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }

        .item-meta {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .item-nominal {
            font-weight: 700;
            color: var(--gray-700);
        }

        /* Stat Badges */
        .stat-badges {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .stat-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 600;
            gap: 0.35rem;
        }

        .stat-success {
            background: linear-gradient(to right, var(--gray-100), var(--gray-200));
            color: var(--gray-700);
        }

        .stat-danger {
            background: linear-gradient(to right, var(--gray-100), var(--gray-200));
            color: var(--gray-600);
        }

        /* Progress Bar - Interactive */
        .progress-container {
            position: relative;
            cursor: pointer;
        }

        .progress-bar {
            height: 24px;
            background: var(--gray-200);
            border-radius: 12px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--gray-500) 0%, var(--gray-400) 100%);
            border-radius: 12px;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        .progress-fill::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }

            100% {
                transform: translateX(100%);
            }
        }

        .progress-container:hover .progress-bar {
            transform: scaleY(1.3);
            box-shadow: 0 2px 8px rgba(100, 116, 139, 0.3);
        }

        .progress-bar {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .progress-text {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--gray-700);
            z-index: 2;
            text-shadow: 0 0 3px rgba(255, 255, 255, 0.8);
        }

        .progress-container:hover .progress-bar {
            box-shadow: 0 2px 8px rgba(100, 116, 139, 0.3);
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-content {
            background: white;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            /* Increased from 600px */
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: modalSlideIn 0.3s ease;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(to right, var(--gray-50), white);
        }

        .modal-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-header h2 i {
            color: var(--gray-600);
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            border: none;
            background: var(--gray-100);
            color: var(--gray-500);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .modal-close:hover {
            background: var(--gray-300);
            color: var(--gray-700);
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            padding: 1rem;
            border-radius: var(--radius);
            text-align: center;
        }

        .stat-card.total {
            background: linear-gradient(135deg, var(--gray-100), var(--gray-200));
            color: var(--gray-700);
        }

        .stat-card.lunas {
            background: linear-gradient(135deg, var(--gray-100), var(--gray-200));
            color: var(--gray-700);
        }

        .stat-card.belum {
            background: linear-gradient(135deg, var(--gray-100), var(--gray-200));
            color: var(--gray-600);
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            display: block;
        }

        .stat-card .stat-label {
            font-size: 0.8rem;
            font-weight: 500;
        }

        .student-list-header {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .student-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .student-table th {
            background: var(--gray-50);
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--gray-600);
            border-bottom: 2px solid var(--gray-200);
        }

        .student-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
        }

        .student-table tr:hover {
            background: var(--gray-50);
        }

        .status-lunas {
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

        .status-belum {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--gray-200);
            color: var(--gray-600);
        }

        .loading-spinner {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }

        .loading-spinner i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            background: var(--gray-100);
            color: var(--gray-500);
        }

        .btn-icon:hover {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-icon.btn-delete:hover {
            background: var(--gray-300);
            color: var(--gray-700);
        }

        .btn-icon.btn-view:hover {
            background: var(--gray-300);
            color: var(--gray-700);
        }

        .btn-icon:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Date Badge */
        .date-badge {
            font-size: 0.85rem;
            color: var(--gray-600);
        }

        /* Pagination */
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-100);
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
            border-color: var(--gray-300);
        }

        .page-link.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
            border-color: var(--gray-200);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
            color: var(--gray-300);
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                padding-top: 75px;
            }

            .page-title-section {
                padding: 1rem;
                margin: -1rem -1rem 1rem -1rem;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .data-table {
                display: block;
                overflow-x: auto;
            }

            .modal-content {
                max-width: 95%;
                margin: 1rem;
            }

            .modal-stats {
                grid-template-columns: repeat(3, 1fr);
                gap: 0.5rem;
            }
        }

        /* Hide Hamburger Button as requested initially, but we are enabling it now based on logic */
        /* But wait, the previous logic had .mobile-navbar display:none !important */

        .mobile-navbar {
            display: none !important;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding-top: 1rem !important;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }

            .filter-bar {
                flex-direction: column;
            }

            .modal-stats {
                grid-template-columns: 1fr;
            }

            .stat-card .stat-number {
                font-size: 1.5rem;
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

            .modal-header {
                padding: 1rem;
            }

            .modal-body {
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
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <div>
                    <h1 class="page-title">Daftar Tagihan</h1>
                    <p class="page-subtitle">Kelola item tagihan pembayaran siswa</p>
                </div>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: '<?php echo $success_msg; ?>',
                    confirmButtonColor: '#0c4a6e'
                });
            </script>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '<?php echo $error_msg; ?>',
                    confirmButtonColor: '#ef4444'
                });
            </script>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="card-header-text">
                    <h3>Riwayat Tagihan Pembayaran</h3>
                    <p>Daftar semua tagihan pembayaran yang telah dibuat</p>
                </div>
            </div>

            <!-- Search -->
            <form method="GET" class="filter-bar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Cari nama tagihan pembayaran..."
                        value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
            </form>

            <!-- Table -->
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Nama Tagihan Pembayaran</th>
                            <th style="width: 12%;">Nominal</th>
                            <th style="width: 30%;">Progress Pembayaran</th>
                            <th style="width: 13%;">Tanggal Dibuat</th>
                            <th style="width: 13%;">Jatuh Tempo</th>
                            <th style="width: 10%;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                $percent = $row['total_siswa'] > 0 ? ($row['total_lunas'] / $row['total_siswa']) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="item-name"><?php echo htmlspecialchars($row['nama_item']); ?></div>
                                        <div class="item-meta">Total <?php echo $row['total_siswa']; ?> Siswa Ditagih</div>
                                    </td>
                                    <td>
                                        <span class="item-nominal">Rp
                                            <?php echo number_format($row['nominal_default'], 0, ',', '.'); ?></span>
                                    </td>
                                    <td>
                                        <div class="progress-container"
                                            onclick="viewDetail(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_item']); ?>')"
                                            title="Klik untuk lihat detail">
                                            <span class="progress-text"><?php echo number_format($percent, 1); ?>%</span>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $percent; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                        $bulan_indo = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
                                        $tgl = strtotime($row['created_at']);
                                        $tanggal_indo = date('d', $tgl) . ' ' . $bulan_indo[(int) date('n', $tgl)] . ' ' . date('Y', $tgl);
                                        ?>
                                        <span class="date-badge"><?php echo $tanggal_indo; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        if (!empty($row['jatuh_tempo'])) {
                                            $tgl_jt = strtotime($row['jatuh_tempo']);
                                            $jt_indo = date('d', $tgl_jt) . ' ' . $bulan_indo[(int) date('n', $tgl_jt)] . ' ' . date('Y', $tgl_jt);
                                            $is_overdue = strtotime($row['jatuh_tempo']) < strtotime('today');
                                        } else {
                                            $jt_indo = '-';
                                            $is_overdue = false;
                                        }
                                        ?>
                                        <span class="date-badge" style="<?php echo $is_overdue ? 'color: #ef4444;' : ''; ?>">
                                            <?php echo $jt_indo; ?>
                                            <?php if ($is_overdue): ?>
                                                <i class="fas fa-exclamation-circle" style="margin-left: 4px;"></i>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <!-- Detail Button -->
                                            <button type="button" class="btn-icon btn-view" title="Lihat Detail"
                                                onclick="viewDetail(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_item']); ?>')">
                                                <i class="fas fa-eye"></i>
                                            </button>

                                            <!-- Delete Button -->
                                            <?php if ($row['total_lunas'] > 0): ?>
                                                <button type="button" class="btn-icon" disabled
                                                    title="Tidak bisa dihapus karena sudah ada yang bayar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn-icon btn-delete" title="Hapus"
                                                    onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_item']); ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Hidden form for delete -->
                                        <form id="deleteForm<?php echo $row['id']; ?>" method="POST" style="display:none;">
                                            <input type="hidden" name="delete_item_id" value="<?php echo $row['id']; ?>">
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-folder-open"></i>
                                        <p>Belum ada tagihan pembayaran yang dibuat.</p>
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
                    $q = !empty($search_query) ? '&q=' . urlencode($search_query) : '';
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
                    <span class=" page-link active">
                        <?= $page ?></span>

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

    <!-- Detail Modal -->
    <div class="modal-overlay" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> <span id="modalTitle">Detail Tagihan</span></h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <!-- Fixed Search Bar -->
                <div
                    style="padding: 1rem 1.5rem; border-bottom: 1px solid var(--gray-100); background: var(--gray-50);">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="detailSearchInput" placeholder="Cari nama siswa..."
                            onkeyup="debounceSearchDetail()" style="background: white;">
                    </div>
                </div>

                <!-- Dynamic Content (Stats + Table) -->
                <div id="modalContent" style="padding: 1.5rem;">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner"></i>
                        <p>Memuat data...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

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

        // Global variables for Detail Modal
        let currentDetailItemId = 0;
        let currentDetailPage = 1;
        let searchTimeout = null;

        // View Detail Function
        function viewDetail(id, name) {
            currentDetailItemId = id;
            currentDetailPage = 1;

            // Reset Modal UI
            document.getElementById('detailModal').classList.add('active');
            document.getElementById('modalTitle').textContent = name;
            document.getElementById('detailSearchInput').value = ''; // Reset search

            // Load Data
            loadDetailData();
        }

        // Debounce Search
        function debounceSearchDetail() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentDetailPage = 1; // Reset to page 1 on new search
                loadDetailData();
            }, 500);
        }

        // Change Page
        function changeDetailPage(page) {
            currentDetailPage = page;
            loadDetailData();
        }

        // Fetch Data via AJAX
        function loadDetailData() {
            const search = document.getElementById('detailSearchInput').value;
            const container = document.getElementById('modalContent');

            // Show skeleton/loading only if it's first load or major change
            if (currentDetailPage === 1 && search === '') {
                container.innerHTML = `
                    <div class="loading-spinner">
                        <i class="fas fa-spinner"></i>
                        <p>Memuat data...</p>
                    </div>
                `;
            }

            const url = `ajax_get_tagihan_detail.php?item_id=${currentDetailItemId}&page=${currentDetailPage}&search=${encodeURIComponent(search)}`;

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderDetailModal(data);
                    } else {
                        container.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-circle"></i>
                                <p>${data.message || 'Gagal memuat data'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <p>Terjadi kesalahan saat memuat data</p>
                        </div>
                    `;
                });
        }

        // Render Modal Content
        function renderDetailModal(data) {
            const container = document.getElementById('modalContent');
            const percent = data.total_siswa > 0 ? ((data.total_lunas / data.total_siswa) * 100).toFixed(1) : 0;

            // Generate Student Rows
            let studentsHtml = '';
            if (data.students && data.students.length > 0) {
                // Calculate starting number based on page
                const startNo = (data.current_page - 1) * 10 + 1;

                data.students.forEach((student, index) => {
                    const statusClass = student.status === 'sudah_bayar' ? 'status-lunas' : 'status-belum';
                    const statusText = student.status === 'sudah_bayar' ? 'Lunas' : 'Belum Bayar';
                    const statusIcon = student.status === 'sudah_bayar' ? 'fa-check-circle' : 'fa-clock';

                    studentsHtml += `
                        <tr>
                            <td>${startNo + index}</td>
                            <td>${student.nama}</td>
                            <td>${student.kelas || '-'}</td>
                            <td>Rp ${new Intl.NumberFormat('id-ID').format(student.nominal)}</td>
                            <td><span class="${statusClass}"><i class="fas ${statusIcon}"></i> ${statusText}</span></td>
                            <td>${student.tanggal_bayar || '-'}</td>
                        </tr>
                    `;
                });
            } else {
                studentsHtml = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #94a3b8;">Data tidak ditemukan</td></tr>`;
            }

            // Generate Pagination HTML
            let paginationHtml = '';
            if (data.total_pages > 1) {
                const prevDisabled = data.current_page == 1 ? 'disabled' : '';
                const nextDisabled = data.current_page == data.total_pages ? 'disabled' : '';

                paginationHtml = `
                    <div class="pagination" style="margin-top: 1.5rem; background: transparent; padding: 0;">
                        <button class="page-link page-arrow ${prevDisabled}" 
                                onclick="${prevDisabled ? '' : `changeDetailPage(${data.current_page - 1})`}"
                                ${prevDisabled ? 'disabled' : ''}>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        
                        <span class="page-link active">${data.current_page} / ${data.total_pages}</span>
                        
                        <button class="page-link page-arrow ${nextDisabled}" 
                                onclick="${nextDisabled ? '' : `changeDetailPage(${data.current_page + 1})`}"
                                ${nextDisabled ? 'disabled' : ''}>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                `;
            }

            // Update DOM
            container.innerHTML = `
                <div class="modal-stats">
                    <div class="stat-card total">
                        <span class="stat-number">${data.total_siswa}</span>
                        <span class="stat-label">Total Siswa</span>
                    </div>
                    <div class="stat-card lunas">
                        <span class="stat-number">${data.total_lunas}</span>
                        <span class="stat-label">Sudah Bayar</span>
                    </div>
                    <div class="stat-card belum">
                        <span class="stat-number">${data.total_belum}</span>
                        <span class="stat-label">Belum Bayar</span>
                    </div>
                </div>
                
                <div class="progress-container" style="margin-bottom: 1.5rem; cursor: default;">
                    <span class="progress-text" style="opacity: 1;">${percent}% Terbayar</span>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: ${percent}%"></div>
                    </div>
                </div>
                
                <div class="student-list-header" style="justify-content: space-between;">
                    <div><i class="fas fa-users"></i> Daftar Siswa</div>
                    <small class="text-muted">Menampilkan ${data.students.length} dari ${data.total_filtered} data filtered</small>
                </div>
                
                <table class="student-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Nominal</th>
                            <th>Status</th>
                            <th>Tgl Bayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${studentsHtml}
                    </tbody>
                </table>
                
                ${paginationHtml}
            `;
        }

        // Close Modal
        function closeModal() {
            document.getElementById('detailModal').classList.remove('active');
        }

        // Close modal when clicking outside
        document.getElementById('detailModal').addEventListener('click', function (e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        // Confirm Delete Function
        function confirmDelete(id, name) {
            Swal.fire({
                title: 'Hapus Tagihan?',
                html: `<p>Apakah Anda yakin ingin menghapus tagihan:</p><p><strong>${name}</strong></p><p style="color: #ef4444; font-size: 0.9rem;">Data siswa yang belum bayar akan ikut terhapus.</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('deleteForm' + id).submit();
                }
            });
        }
    </script>

</body>

</html>