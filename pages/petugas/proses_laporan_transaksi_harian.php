<?php
/**
 * Proses Laporan Transaksi Harian - Adaptive Path Version
 * File: pages/petugas/proses_laporan_transaksi_harian.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_laporan_transaksi_harian.php
 * - Hosting: public_html/pages/petugas/proses_laporan_transaksi_harian.php
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
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
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

// Validate session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user information from session
$username = $_SESSION['username'] ?? 'Petugas';
$user_id = $_SESSION['user_id'] ?? 0;

// Get today's date
$today = date('Y-m-d');

// Pagination settings
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Calculate summary statistics for today
function getSummaryStatistics($conn, $today) {
    $total_query = "SELECT 
        COUNT(id) as total_transactions,
        SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
        SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
        FROM transaksi 
        WHERE DATE(created_at) = ? 
        AND jenis_transaksi != 'transfer'
        AND petugas_id IS NOT NULL";
    
    $stmt = $conn->prepare($total_query);
    if (!$stmt) {
        error_log("Error preparing total query: " . $conn->error);
        return ['total_transactions' => 0, 'total_debit' => 0, 'total_kredit' => 0];
    }
    
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $totals;
}

// Calculate total records for pagination
function getTotalRecords($conn, $today) {
    $total_records_query = "SELECT COUNT(*) as total 
                           FROM transaksi 
                           WHERE DATE(created_at) = ? 
                           AND jenis_transaksi != 'transfer'
                           AND petugas_id IS NOT NULL";
    
    $stmt_total = $conn->prepare($total_records_query);
    if (!$stmt_total) {
        error_log("Error preparing total records query: " . $conn->error);
        return 0;
    }
    
    $stmt_total->bind_param("s", $today);
    $stmt_total->execute();
    $total_records = $stmt_total->get_result()->fetch_assoc()['total'];
    $stmt_total->close();
    
    return $total_records;
}

// Fetch transactions with class information
function getTransactions($conn, $today, $limit, $offset) {
    try {
        $query = "SELECT 
            t.no_transaksi,
            t.jenis_transaksi,
            t.jumlah,
            t.created_at,
            u.nama AS nama_siswa,
            CONCAT_WS(' ', tk.nama_tingkatan, k.nama_kelas) AS nama_kelas
            FROM transaksi t 
            JOIN rekening r ON t.rekening_id = r.id 
            JOIN users u ON r.user_id = u.id 
            JOIN siswa_profiles sp ON u.id = sp.user_id
            LEFT JOIN kelas k ON sp.kelas_id = k.id 
            LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
            WHERE DATE(t.created_at) = ? 
            AND t.jenis_transaksi != 'transfer' 
            AND t.petugas_id IS NOT NULL 
            AND (t.status = 'approved' OR t.status IS NULL)
            ORDER BY t.created_at DESC 
            LIMIT ? OFFSET ?";
        
        $stmt_trans = $conn->prepare($query);
        if (!$stmt_trans) {
            throw new Exception("Failed to prepare transaction query: " . $conn->error);
        }
        
        $stmt_trans->bind_param("sii", $today, $limit, $offset);
        $stmt_trans->execute();
        $result = $stmt_trans->get_result();
        
        $transactions = [];
        while ($row = $result->fetch_assoc()) {
            $transactions[] = $row;
        }
        
        $stmt_trans->close();
        return $transactions;
        
    } catch (Exception $e) {
        error_log("Error fetching transactions: " . $e->getMessage());
        return [];
    }
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Get data
$totals = getSummaryStatistics($conn, $today);
$total_records = getTotalRecords($conn, $today);
$total_pages = ceil($total_records / $limit);
$transactions = getTransactions($conn, $today, $limit, $offset);

// Calculate net balance
$saldo_bersih = ($totals['total_debit'] ?? 0) - ($totals['total_kredit'] ?? 0);
$saldo_bersih = max(0, $saldo_bersih);

// Return data for UI
return [
    'username' => $username,
    'user_id' => $user_id,
    'today' => $today,
    'page' => $page,
    'limit' => $limit,
    'offset' => $offset,
    'totals' => $totals,
    'total_records' => $total_records,
    'total_pages' => $total_pages,
    'transactions' => $transactions,
    'saldo_bersih' => $saldo_bersih
];
?>