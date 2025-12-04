<?php
/**
 * Download Laporan Transaksi Admin - Adaptive Path Version
 * File: pages/download_laporan_admin.php
 * 
 * Compatible with:
 * - Local: schobank/pages/download_laporan_admin.php
 * - Hosting: public_html/pages/download_laporan_admin.php
 */

// ============================================
// ERROR HANDLING CONFIGURATION
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages' folder
if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: Check if includes/ exists in current directory
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: Search upward for includes/ folder (max 5 levels)
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

// Fallback: Use current directory if still not found
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

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

if (!defined('TCPDF_PATH')) {
    define('TCPDF_PATH', PROJECT_ROOT . '/tcpdf');
}

// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/auth.php')) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'File auth.php tidak ditemukan.',
        'debug' => [
            'includes_path' => INCLUDES_PATH,
            'project_root' => PROJECT_ROOT
        ]
    ]);
    exit;
}

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// ============================================
// AUTHORIZATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Akses ditolak. Silakan login sebagai admin.']);
    exit;
}

// ============================================
// PARAMETER VALIDATION
// ============================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Validate dates
if (empty($start_date) || empty($end_date) || !strtotime($start_date) || !strtotime($end_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tanggal tidak valid']);
    exit;
}

if (strtotime($start_date) > strtotime($end_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tanggal awal tidak boleh lebih dari tanggal akhir']);
    exit;
}

// ============================================
// QUERY: TRANSACTION TOTALS
// ============================================
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status = 'pending') THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NULL";

$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menyiapkan query total: ' . $conn->error]);
    exit;
}

$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran'] = $totals['total_setoran'] ?? 0;
$totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
$totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];
$stmt_total->close();

// ============================================
// QUERY: TRANSACTION DETAILS
// ============================================
$query = "SELECT 
            t.id,
            t.jenis_transaksi,
            t.jumlah,
            t.status,
            t.created_at,
            u.nama as nama_siswa,
            r.no_rekening,
            COALESCE(k.nama_kelas, '-') as nama_kelas,
            COALESCE(tk.nama_tingkatan, '-') as nama_tingkatan
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
          AND t.jenis_transaksi IN ('setor', 'tarik')
          AND t.petugas_id IS NULL
          ORDER BY t.created_at DESC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menyiapkan query transaksi: ' . $conn->error]);
    exit;
}

$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// ============================================
// PROCESS TRANSACTION DATA
// ============================================
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $no_rekening = $row['no_rekening'] ?? 'N/A';
    
    // Format rekening: show first 2 and last 2 digits with *** in between
    if (strlen($no_rekening) >= 4) {
        $first_two = substr($no_rekening, 0, 2);
        $last_two = substr($no_rekening, -2);
        $row['no_rekening_display'] = $first_two . '***' . $last_two;
    } else {
        $row['no_rekening_display'] = $no_rekening;
    }
    
    // Combine nama_tingkatan and nama_kelas
    $row['kelas'] = (trim($row['nama_tingkatan']) && trim($row['nama_kelas']) && 
                    $row['nama_tingkatan'] !== '-' && $row['nama_kelas'] !== '-') 
        ? htmlspecialchars($row['nama_tingkatan'] . ' ' . $row['nama_kelas'])
        : 'N/A';
    
    $transactions[] = $row;
}
$stmt->close();

// ============================================
// PDF GENERATION
// ============================================
if ($format === 'pdf') {
    
    // ---- TCPDF LOADING WITH MULTIPLE FALLBACK PATHS ----
    $tcpdf_file = null;
    $possible_tcpdf_paths = [
        TCPDF_PATH . '/tcpdf.php',
        PROJECT_ROOT . '/vendor/tecnickcom/tcpdf/tcpdf.php',
        PROJECT_ROOT . '/lib/tcpdf/tcpdf.php',
        '/usr/share/php/TCPDF/tcpdf.php',
        dirname(PROJECT_ROOT) . '/tcpdf/tcpdf.php', // Parent directory fallback
    ];
    
    // Search for TCPDF in possible paths
    foreach ($possible_tcpdf_paths as $path) {
        if (file_exists($path)) {
            $tcpdf_file = $path;
            break;
        }
    }
    
    // Try Composer autoloader if TCPDF not found
    if (!$tcpdf_file && file_exists(PROJECT_ROOT . '/vendor/autoload.php')) {
        require_once PROJECT_ROOT . '/vendor/autoload.php';
        if (class_exists('TCPDF')) {
            $tcpdf_file = 'composer';
        }
    }
    
    // Check if TCPDF is available
    if (!$tcpdf_file) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Modul PDF (TCPDF) tidak tersedia. Hubungi administrator.',
            'debug_info' => [
                'searched_paths' => $possible_tcpdf_paths,
                'project_root' => PROJECT_ROOT,
                'tcpdf_path' => TCPDF_PATH
            ]
        ]);
        exit;
    }
    
    // Load TCPDF if not already loaded
    if ($tcpdf_file !== 'composer' && !class_exists('TCPDF')) {
        require_once $tcpdf_file;
    }
    
    // Verify TCPDF class is available
    if (!class_exists('TCPDF')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Class TCPDF tidak ditemukan setelah include.']);
        exit;
    }

    // ---- CUSTOM PDF CLASS ----
    class MYPDF extends TCPDF {
        public $isFirstPage = true;
        public $logo_path = '';

        public function Header() {
            if ($this->isFirstPage) {
                // Try to load logo if exists
                if (!empty($this->logo_path) && file_exists($this->logo_path)) {
                    $this->Image($this->logo_path, 15, 5, 20, '', 'PNG');
                }
                
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'MY SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini Sekolah', 0, false, 'C', 0);
                $this->Ln(5);
                
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A 43212 Cianjur Jawa Barat', 0, false, 'C', 0);
                $this->Line(15, 30, 195, 30);
                
                $this->isFirstPage = false;
            } else {
                // Header for subsequent pages
                $this->SetY(15);
                $col_width = [10, 25, 25, 35, 25, 35, 25];
                $this->SetFont('helvetica', 'B', 8);
                $this->SetFillColor(128, 128, 128);
                $this->SetTextColor(255, 255, 255);
                
                $this->Cell($col_width[0], 6, 'No', 1, 0, 'C', true);
                $this->Cell($col_width[1], 6, 'Tanggal', 1, 0, 'C', true);
                $this->Cell($col_width[2], 6, 'No Rekening', 1, 0, 'C', true);
                $this->Cell($col_width[3], 6, 'Nama Siswa', 1, 0, 'C', true);
                $this->Cell($col_width[4], 6, 'Kelas', 1, 0, 'C', true);
                $this->Cell($col_width[5], 6, 'Jenis', 1, 0, 'C', true);
                $this->Cell($col_width[6], 6, 'Jumlah', 1, 1, 'C', true);
                
                $this->SetTextColor(0, 0, 0);
            }
        }
    }

    // ---- LOGO PATH DETECTION ----
    $logo_paths = [
        ASSETS_PATH . '/images/logo.png',
        PROJECT_ROOT . '/assets/images/logo.png',
        dirname(PROJECT_ROOT) . '/assets/images/logo.png',
        $current_dir . '/../assets/images/logo.png'
    ];
    
    $logo_file = null;
    foreach ($logo_paths as $path) {
        if (file_exists($path)) {
            $logo_file = $path;
            break;
        }
    }

    // ---- CREATE PDF INSTANCE ----
    ob_start();
    
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set logo if found
    if ($logo_file) {
        $pdf->logo_path = $logo_file;
    }
    
    $pdf->SetCreator('MY SCHOBANK');
    $pdf->SetAuthor('MY SCHOBANK');
    $pdf->SetTitle('Rekapan Transaksi Admin');
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // ---- TITLE & PERIOD ----
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'REKAPAN TRANSAKSI ADMIN', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    $totalWidth = 180;

    // ---- SUMMARY TABLE ----
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(128, 128, 128);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    
    $colWidth = $totalWidth / 4;
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($colWidth, 6, 'Total Transaksi', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Setoran', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Penarikan', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Bersih', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell($colWidth, 6, $totals['total_transactions'], 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'C');

    // ---- DETAILS TABLE ----
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(128, 128, 128);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    // Table headers
    $col_width = [10, 25, 25, 35, 25, 35, 25];
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $header_heights = 6;
    
    $pdf->Cell($col_width[0], $header_heights, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], $header_heights, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], $header_heights, 'No Rekening', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], $header_heights, 'Nama Siswa', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], $header_heights, 'Kelas', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], $header_heights, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[6], $header_heights, 'Jumlah', 1, 1, 'C', true);

    // Table rows
    $pdf->SetFont('helvetica', '', 8);
    $row_height = 5;
    
    $total_setor = 0;
    $total_tarik = 0;
    
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $displayType = 'Setor';
                $total_setor += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $displayType = 'Tarik';
                $total_tarik += $row['jumlah'];
            }
            
            $pdf->Cell($col_width[0], $row_height, $no++, 1, 0, 'C');
            $pdf->Cell($col_width[1], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C');
            $pdf->Cell($col_width[2], $row_height, htmlspecialchars($row['no_rekening_display']), 1, 0, 'L');
            
            // Truncate long names
            $nama_siswa = $row['nama_siswa'] ?? 'N/A';
            $nama_siswa = mb_strlen($nama_siswa) > 18 ? mb_substr($nama_siswa, 0, 16) . '...' : $nama_siswa;
            
            $pdf->Cell($col_width[3], $row_height, htmlspecialchars($nama_siswa), 1, 0, 'L');
            $pdf->Cell($col_width[4], $row_height, htmlspecialchars($row['kelas']), 1, 0, 'L');
            $pdf->Cell($col_width[5], $row_height, $displayType, 1, 0, 'C');
            $pdf->Cell($col_width[6], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R');
        }
        
        // Total row
        $total = $total_setor - $total_tarik;
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(array_sum(array_slice($col_width, 0, 6)), $row_height, 'Total:', 1, 0, 'C', true);
        $pdf->Cell($col_width[6], $row_height, 'Rp ' . number_format($total, 0, ',', '.'), 1, 1, 'R', true);
    } else {
        $pdf->Cell(array_sum($col_width), $row_height, 'Tidak ada transaksi admin dalam periode ini.', 1, 1, 'C');
    }

    ob_end_clean();
    
    // ---- OUTPUT PDF ----
    $filename = 'laporan_transaksi_admin_' . date('Ymd_His') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ============================================
// INVALID FORMAT
// ============================================
header('Content-Type: application/json');
echo json_encode(['error' => 'Format tidak valid']);
exit;
?>
