<?php
/**
 * AJAX Endpoint: Get Tagihan Detail
 * File: pages/bendahara/ajax_get_tagihan_detail.php
 * 
 * Returns JSON with tagihan details and student list
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

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Set header to JSON
header('Content-Type: application/json');

// Function to format date in Indonesian
function formatTanggalIndo($date)
{
    if (!$date)
        return null;

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

    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = (int) date('n', $timestamp);
    $year = date('Y', $timestamp);

    return $day . ' ' . $bulan[$month] . ' ' . $year;
}

// Cek Role Bendahara
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get item_id from request
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10; // Fixed 10 items per page
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if ($item_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'ID tagihan tidak valid']);
    exit;
}

try {
    // 1. Get tagihan item info
    $stmt_item = $conn->prepare("SELECT id, nama_item, nominal_default, created_at FROM pembayaran_item WHERE id = ?");
    $stmt_item->bind_param("i", $item_id);
    $stmt_item->execute();
    $item_result = $stmt_item->get_result();

    if ($item_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Tagihan tidak ditemukan']);
        exit;
    }

    $item = $item_result->fetch_assoc();

    // 2. Get Global Stats (Total, Lunas, Belum) - Unaffected by search/pagination
    // Efficiently using SQL COUNT and SUM
    $sql_stats = "SELECT 
                    COUNT(*) as total_siswa,
                    SUM(CASE WHEN status = 'sudah_bayar' THEN 1 ELSE 0 END) as total_lunas,
                    SUM(CASE WHEN status != 'sudah_bayar' THEN 1 ELSE 0 END) as total_belum
                  FROM pembayaran_tagihan
                  WHERE item_id = ?";
    $stmt_stats = $conn->prepare($sql_stats);
    $stmt_stats->bind_param("i", $item_id);
    $stmt_stats->execute();
    $stats = $stmt_stats->get_result()->fetch_assoc();

    // Handle nulls if table empty
    $total_siswa = (int) ($stats['total_siswa'] ?? 0);
    $total_lunas = (int) ($stats['total_lunas'] ?? 0);
    $total_belum = (int) ($stats['total_belum'] ?? 0);

    // 3. Get Student List with Pagination & Search
    // Build WHERE clause
    $where_clauses = ["pt.item_id = ?"];
    $params = [$item_id];
    $types = "i";

    if (!empty($search)) {
        $where_clauses[] = "(u.nama LIKE ? OR k.nama_kelas LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "ss";
    }

    $where_sql = implode(" AND ", $where_clauses);

    // Count Total Filtered records (for pagination)
    $sql_count_filtered = "SELECT COUNT(*) as total 
                           FROM pembayaran_tagihan pt
                           LEFT JOIN users u ON pt.siswa_id = u.id
                           LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                           LEFT JOIN kelas k ON sp.kelas_id = k.id
                           WHERE $where_sql";

    $stmt_count = $conn->prepare($sql_count_filtered);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_filtered = $stmt_count->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_filtered / $limit);

    // Fetch Data
    $sql_students = "SELECT 
                        pt.id as tagihan_id,
                        pt.nominal,
                        pt.status,
                        pt.tanggal_bayar,
                        u.nama,
                        k.nama_kelas
                     FROM pembayaran_tagihan pt
                     LEFT JOIN users u ON pt.siswa_id = u.id
                     LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
                     LEFT JOIN kelas k ON sp.kelas_id = k.id
                     WHERE $where_sql
                     ORDER BY pt.status ASC, k.nama_kelas ASC, u.nama ASC
                     LIMIT ? OFFSET ?";

    // Add limit/offset params
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt_students = $conn->prepare($sql_students);
    $stmt_students->bind_param($types, ...$params);
    $stmt_students->execute();
    $students_result = $stmt_students->get_result();

    $students = [];
    while ($row = $students_result->fetch_assoc()) {
        $students[] = [
            'nama' => $row['nama'],
            'kelas' => $row['nama_kelas'],
            'nominal' => $row['nominal'],
            'status' => $row['status'],
            'tanggal_bayar' => formatTanggalIndo($row['tanggal_bayar'])
        ];
    }

    // Return JSON response
    echo json_encode([
        'success' => true,
        'item_id' => $item['id'],
        'nama_item' => $item['nama_item'],
        'nominal_default' => $item['nominal_default'],
        'created_at' => formatTanggalIndo($item['created_at']),

        // Global Stats
        'total_siswa' => $total_siswa,
        'total_lunas' => $total_lunas,
        'total_belum' => $total_belum,

        // Pagination & filtered data
        'students' => $students,
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_filtered' => $total_filtered
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan: ' . $e->getMessage()]);
}
