<?php
/**
 * Proses Cek Saldo - Adaptive Path Version
 * File: pages/petugas/proses_cek_saldo.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_cek_saldo.php
 * - Hosting: public_html/pages/petugas/proses_cek_saldo.php
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
require_once INCLUDES_PATH . '/db_connection.php';
// Set JSON header
header('Content-Type: application/json');
// Check authentication
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Akses tidak diizinkan'
    ]);
    exit();
}
// Function to convert month number to Indonesian month name
function getIndonesianMonth($monthNum) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei',
        6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September',
        10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return $months[$monthNum] ?? $monthNum;
}
// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
   
    if ($action === 'check_balance') {
        $no_rekening = isset($_POST['no_rekening']) ? trim($_POST['no_rekening']) : '';
       
        // Validate input
        if (empty($no_rekening)) {
            echo json_encode([
                'success' => false,
                'message' => 'Nomor rekening tidak boleh kosong'
            ]);
            exit();
        }
       
        if (!preg_match('/^[0-9]{8}$/', $no_rekening)) {
            echo json_encode([
                'success' => false,
                'message' => 'Nomor rekening harus terdiri dari 8 digit'
            ]);
            exit();
        }
       
        // Sanitize input
        $no_rekening = $conn->real_escape_string($no_rekening);
       
        // Query to fetch account details (adjusted for new DB structure)
        $query = "SELECT u.nama, r.no_rekening, r.saldo, r.created_at,
                         CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS nama_kelas,
                         j.nama_jurusan
                  FROM rekening r
                  JOIN users u ON r.user_id = u.id
                  JOIN siswa_profiles sp ON u.id = sp.user_id
                  LEFT JOIN kelas k ON sp.kelas_id = k.id
                  LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                  LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                  WHERE r.no_rekening = ?";
       
        $stmt = $conn->prepare($query);
       
        if (!$stmt) {
            error_log("Prepare failed: " . $conn->error);
            echo json_encode([
                'success' => false,
                'message' => 'Terjadi kesalahan server. Silakan coba lagi.'
            ]);
            exit();
        }
       
        $stmt->bind_param("s", $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();
       
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
           
            // Format dates
            $created_at = new DateTime($data['created_at']);
            $formatted_date = $created_at->format('d') . ' ' . getIndonesianMonth($created_at->format('n')) . ' ' . $created_at->format('Y');
           
            $current_time = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
            $formatted_current_time = $current_time->format('d') . ' ' . getIndonesianMonth($current_time->format('n')) . ' ' . $current_time->format('Y H:i') . ' WIB';
           
            // Generate HTML
            $html = '
            <div class="results-card">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                <div class="detail-row">
                    <div class="detail-label">Nama Nasabah</div>
                    <div class="detail-value">' . htmlspecialchars($data['nama']) . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">No. Rekening</div>
                    <div class="detail-value">' . htmlspecialchars($data['no_rekening']) . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas</div>
                    <div class="detail-value">' . htmlspecialchars($data['nama_kelas'] ?? 'N/A') . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan</div>
                    <div class="detail-value">' . htmlspecialchars($data['nama_jurusan'] ?? 'N/A') . '</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Tanggal Pembukaan</div>
                    <div class="detail-value">' . $formatted_date . '</div>
                </div>
                <div class="balance-display">
                    <div class="balance-label">Saldo Rekening Saat Ini</div>
                    <div class="balance-amount">Rp ' . number_format($data['saldo'], 0, ',', '.') . '</div>
                    <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per ' . $formatted_current_time . '</div>
                </div>
            </div>';
           
            echo json_encode([
                'success' => true,
                'html' => $html
            ]);
        } else {
            error_log("No account found for no_rekening: $no_rekening");
            echo json_encode([
                'success' => false,
                'message' => 'Rekening tidak ditemukan. Silakan periksa kembali nomor rekening.'
            ]);
        }
       
        $stmt->close();
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Action tidak valid'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Request tidak valid'
    ]);
}
if (isset($conn)) {
    $conn->close();
}
?>