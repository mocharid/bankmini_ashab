<?php
/**
 * Rekapitulasi Absensi Petugas - Final Rapih Version (FIXED NAMA & JAM)
 * File: pages/admin/rekap_absensi_petugas.php
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
if (!$project_root) $project_root = $current_dir;

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH')) define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) define('BASE_URL', rtrim(getBaseUrl(), '/'));
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
function formatDate($date) {
    return date('d/m/y', strtotime($date));
}

// Fungsi format tanggal Indonesia
function tgl_indo($date) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $split = explode('-', $date);
    return (int)$split[2] . ' ' . $bulan[(int)$split[1]] . ' ' . $split[0];
}

// ========================================
// HANDLE PDF EXPORT - HARUS DI AWAL SEBELUM OUTPUT APAPUN
// ========================================
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // ✅ Build query untuk PDF (tanpa LIMIT) – gunakan petugas_profiles + petugas_status
    $pdfQuery = "SELECT 
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
    
    $whereClause = [];
    $pdfParams = [];
    $pdfTypes = "";
    
    if (!empty($start_date)) {
        $whereClause[] = "ps.tanggal >= ?";
        $pdfParams[] = $start_date;
        $pdfTypes .= "s";
    }
    
    if (!empty($end_date)) {
        $whereClause[] = "ps.tanggal <= ?";
        $pdfParams[] = $end_date;
        $pdfTypes .= "s";
    }
    
    if (!empty($whereClause)) {
        $pdfQuery .= " WHERE " . implode(" AND ", $whereClause);
    }
    
    $pdfQuery .= " ORDER BY ps.tanggal ASC";
    
    try {
        $pdfStmt = $conn->prepare($pdfQuery);
        if (!$pdfStmt) {
            throw new Exception("Failed to prepare PDF query: " . $conn->error);
        }
        
        if (!empty($pdfParams)) {
            $pdfStmt->bind_param($pdfTypes, ...$pdfParams);
        }
        
        $pdfStmt->execute();
        $pdfResult = $pdfStmt->get_result();
        $pdfData = [];
        while ($row = $pdfResult->fetch_assoc()) {
            $pdfData[] = $row;
        }
        $pdfStmt->close();
        
        if (empty($pdfData)) {
            $_SESSION['error_message'] = 'Tidak ada data untuk diekspor!';
            header('Location: ' . BASE_URL . '/pages/admin/rekapitulasi_absensi.php');
            exit;
        }
        
        // Include TCPDF library
        if (!file_exists(PROJECT_ROOT . '/tcpdf/tcpdf.php')) {
            error_log("DEBUG: TCPDF library not found at " . PROJECT_ROOT . "/tcpdf/tcpdf.php");
            die('TCPDF library not found');
        }
        require_once PROJECT_ROOT . '/tcpdf/tcpdf.php';
        
        // Custom TCPDF class dengan header abu-abu elegan
        class MYPDF extends TCPDF {
            public function Header() {
                $this->SetY(8);

                // Logo pakai adaptive path ASSETS_PATH
                $logo = ASSETS_PATH . '/images/logo.png';
                if (file_exists($logo)) {
                    $this->Image($logo, 15, 9, 24, 24, 'PNG');
                }

                // Kop Surat Abu-abu Elegan
                $this->SetFont('helvetica', 'B', 16);
                $this->SetTextColor(70, 70, 70);
                $this->Cell(0, 8, 'SMK PLUS ASHABULYAMIN', 0, 1, 'C');

                $this->SetFont('helvetica', 'B', 12);
                $this->SetTextColor(50, 50, 50);
                $this->Cell(0, 7, 'SCHOBANK - BANK MINI SEKOLAH', 0, 1, 'C');

                $this->SetFont('helvetica', '', 9.5);
                $this->SetTextColor(100, 100, 100);
                $this->Cell(0, 5, 'Jl. K.H. Saleh No.57A, Cianjur 43212, Jawa Barat', 0, 1, 'C');
                $this->Cell(0, 5, 'Website: schobank.my.id | Email: myschobank@gmail.com', 0, 1, 'C');

                // Garis abu-abu elegan
                $this->SetDrawColor(120, 120, 120);
                $this->SetLineWidth(1.2);
                $this->Line(15, 40, 195, 40);
                $this->SetDrawColor(200, 200, 200);
                $this->SetLineWidth(0.3);
                $this->Line(15, 41.5, 195, 41.5);
            }
            
            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->SetTextColor(130, 130, 130);
                $this->Cell(0, 10, 'Dicetak pada ' . date('d/m/Y H:i') . ' WIB • Halaman ' . $this->getAliasNumPage() . ' dari ' . $this->getAliasNbPages(), 0, 0, 'C');
            }
        }
        
        // Create PDF (Portrait orientation)
        $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Schobank SMK Plus Ashabulyamin');
        $pdf->SetAuthor('Admin');
        $pdf->SetTitle('Rekap Absensi Petugas');
        $pdf->SetSubject('Laporan Absensi');
        
        // Set margins
        $pdf->SetMargins(12, 48, 12);
        $pdf->SetAutoPageBreak(true, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // === JUDUL LAPORAN ===
        $pdf->Ln(6);
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 8, 'REKAP ABSENSI PETUGAS', 0, 1, 'C');
        
        // Periode
        $periode_text = 'Semua Data';
        if (!empty($start_date) && !empty($end_date)) {
            $periode_text = tgl_indo($start_date) . ' — ' . tgl_indo($end_date);
        } elseif (!empty($start_date)) {
            $periode_text = 'Dari ' . tgl_indo($start_date);
        } elseif (!empty($end_date)) {
            $periode_text = 'Sampai ' . tgl_indo($end_date);
        }
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 7, 'Periode: ' . $periode_text, 0, 1, 'C');
        $pdf->Ln(10);
        
        // Section Header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(110, 110, 110);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(186, 8, 'DAFTAR ABSENSI', 0, 1, 'C', true);
        
        // Table Header
        $col = [8, 22, 52, 30, 37, 37]; // Total: 186mm
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($col[0], 7, 'No', 1, 0, 'C', true);
        $pdf->Cell($col[1], 7, 'Tanggal', 1, 0, 'C', true);
        $pdf->Cell($col[2], 7, 'Nama Petugas', 1, 0, 'C', true);
        $pdf->Cell($col[3], 7, 'Status', 1, 0, 'C', true);
        $pdf->Cell($col[4], 7, 'Jam Masuk', 1, 0, 'C', true);
        $pdf->Cell($col[5], 7, 'Jam Keluar', 1, 1, 'C', true);
        
        // Table Body
        $pdf->SetFont('helvetica', '', 8.5);
        
        if (count($pdfData) > 0) {
            $status_map = [
                'hadir' => 'Hadir',
                'tidak_hadir' => 'Tidak Hadir',
                'sakit' => 'Sakit',
                'izin' => 'Izin'
            ];
            
            $no = 1;
            foreach ($pdfData as $record) {
                $tanggal_formatted = date('d/m/Y', strtotime($record['tanggal']));
                
                $p1_status = $record['petugas1_status'] ?? '-';
                $p2_status = $record['petugas2_status'] ?? '-';
                
                $p1_status_display = $status_map[$p1_status] ?? $p1_status;
                $p2_status_display = $status_map[$p2_status] ?? $p2_status;
                
                $p1_masuk  = ($p1_status === 'hadir' && $record['petugas1_masuk'])  ? date('H:i', strtotime($record['petugas1_masuk']))  : '-';
                $p1_keluar = ($p1_status === 'hadir' && $record['petugas1_keluar']) ? date('H:i', strtotime($record['petugas1_keluar'])) : '-';
                $p2_masuk  = ($p2_status === 'hadir' && $record['petugas2_masuk'])  ? date('H:i', strtotime($record['petugas2_masuk']))  : '-';
                $p2_keluar = ($p2_status === 'hadir' && $record['petugas2_keluar']) ? date('H:i', strtotime($record['petugas2_keluar'])) : '-';
                
                // First row - Petugas 1
                $pdf->Cell($col[0], 6.5, $no, 'LTR', 0, 'C');
                $pdf->Cell($col[1], 6.5, $tanggal_formatted, 'LTR', 0, 'C');
                $pdf->Cell($col[2], 6.5, $record['petugas1_nama'] ?? '-', 1, 0, 'L');
                $pdf->Cell($col[3], 6.5, $p1_status_display, 1, 0, 'C');
                $pdf->Cell($col[4], 6.5, $p1_masuk, 1, 0, 'C');
                $pdf->Cell($col[5], 6.5, $p1_keluar, 1, 1, 'C');
                
                // Second row - Petugas 2
                $pdf->Cell($col[0], 6.5, '', 'LBR', 0, 'C');
                $pdf->Cell($col[1], 6.5, '', 'LBR', 0, 'C');
                $pdf->Cell($col[2], 6.5, $record['petugas2_nama'] ?? '-', 1, 0, 'L');
                $pdf->Cell($col[3], 6.5, $p2_status_display, 1, 0, 'C');
                $pdf->Cell($col[4], 6.5, $p2_masuk, 1, 0, 'C');
                $pdf->Cell($col[5], 6.5, $p2_keluar, 1, 1, 'C');
                
                $no++;
            }
        } else {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->Cell(array_sum($col), 10, 'Tidak ada data absensi pada periode ini.', 1, 1, 'C');
        }
        
        // Clean output buffer
        ob_end_clean();
        
        // Output PDF
        $filename = 'Rekap_Absensi_Petugas_' . date('d-m-Y_His') . '.pdf';
        $pdf->Output($filename, 'D');
        
        exit();
        
    } catch (Exception $e) {
        error_log("Error generating PDF: " . $e->getMessage());
        if (ob_get_level()) {
            ob_end_clean();
        }
        die("Error generating PDF: " . $e->getMessage());
    }
}

// ========================================
// PAGINATION & DATA UNTUK TAMPILAN NORMAL
// ========================================
$items_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// ✅ Build query utama – nama dari petugas_profiles, jam dari petugas_status
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

$countQuery = "SELECT COUNT(*) as total 
               FROM petugas_shift ps";

// Add WHERE clauses
$whereClause = [];
$params = [];
$types = "";

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

if (!empty($whereClause)) {
    $whereString = " WHERE " . implode(" AND ", $whereClause);
    $baseQuery .= $whereString;
    $countQuery .= $whereString;
}

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
    <title>Rekap Absensi Petugas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a; --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6; --bg-light: #f0f5ff;
            --text-primary: #333; --text-secondary: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-primary); display: flex; min-height: 100vh; overflow-x: hidden; }
        
        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open { overflow: hidden; }
        body.sidebar-open::before {
            content: ''; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5); z-index: 998; backdrop-filter: blur(5px);
        }
        
        .main-content { flex: 1; margin-left: 280px; padding: 30px; max-width: calc(100% - 280px); position: relative; z-index: 1; }
        
        /* Banner */
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color)); 
            color: white; 
            padding: 30px; 
            border-radius: 8px; 
            margin-bottom: 30px; 
            box-shadow: var(--shadow-sm); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
        }
        .welcome-banner h2 { font-size: 1.5rem; margin: 0; }
        .welcome-banner p { margin: 0; opacity: 0.9; }
        .menu-toggle { 
            display: none; 
            font-size: 1.5rem; 
            cursor: pointer; 
            align-self: center;
            margin-right: auto;
        }

        /* Form & Container */
        .form-card, .jadwal-list { background: white; border-radius: 8px; padding: 25px; box-shadow: var(--shadow-sm); margin-bottom: 30px; }
        .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .form-group { flex: 1; display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); }

        /* INPUT STYLING */
        input[type="text"], input[type="date"], select {
            width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 0.95rem; transition: all 0.3s; background: #fff;
            height: 48px;
        }
        input:focus, select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); outline: none; }
        
        /* Date Picker Wrapper */
        .date-input-wrapper { position: relative; width: 100%; }
        
        /* Input Date Specifics */
        input[type="date"] {
            appearance: none; -webkit-appearance: none;
            position: relative; padding-right: 40px;
        }
        
        /* HIDE Native Calendar Icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
        }
        
        /* Custom Icon */
        .calendar-icon {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--primary-color); font-size: 1.1rem; z-index: 1; pointer-events: none;
        }

        /* Button */
        .btn { background: var(--primary-color); color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; text-decoration: none; }
        .btn:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-cancel { background: #e2e8f0; color: #475569; }
        .btn-cancel:hover { background: #cbd5e1; }
        
        /* Table */
        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #f1f5f9; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-weight: 600; color: var(--text-secondary); border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); }

        .status-hadir { background: #dcfce7; color: #166534; padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .status-tidak_hadir { background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .status-sakit { background: #ffedd5; color: #9a3412; padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .status-izin { background: #dbeafe; color: #1e40af; padding: 4px 10px; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; max-width: 100%; }
            .menu-toggle { display: block; }
            .welcome-banner { 
                flex-direction: row; 
                align-items: center; 
                gap: 15px; 
                padding: 20px; 
            }
            .welcome-banner h2 { 
                font-size: 1.2rem;
                margin: 0; 
            }
            .welcome-banner p { 
                font-size: 0.9rem;
                margin: 0; 
                opacity: 0.9; 
            }
            .form-row { flex-direction: column; gap: 15px; }
        }

        /* SweetAlert Custom Fixes */
        .swal2-input { height: 48px !important; margin: 10px auto !important; }
        .swal-date-wrapper { position: relative; width: 80%; margin: 10px auto; }
        .swal-date-wrapper input { width: 100% !important; box-sizing: border-box !important; margin: 0 !important; }
        .swal-date-wrapper i { right: 15px; }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div>
                <h2>Data Rekap Absensi Petugas</h2>
                <p>Lihat dan kelola data rekap absensi petugas secara terpusat</p>
            </div>
        </div>

        <!-- CARD FILTER -->
        <div class="form-card">
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Dari Tanggal</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Sampai Tanggal</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn">Terapkan</button>
                    <button type="button" id="resetBtn" class="btn btn-cancel">Reset</button>
                </div>
            </form>
        </div>
        
        <!-- CARD TABEL -->
        <div class="jadwal-list">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3>Daftar Absensi Petugas</h3>
                <a id="pdfLink" class="btn" style="padding: 8px 15px; font-size: 0.9rem;">
                    Cetak PDF
                </a>
            </div>

            <div class="table-wrapper">
                <table>
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
                            <tr><td colspan="6" style="text-align:center; padding:30px; color:#999;">Belum ada data absensi.</td></tr>
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
            
            <?php if ($total_pages > 1): ?>
            <div style="margin-top:20px; display:flex; justify-content:center; gap:5px;">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <a href="?page=<?= $i ?><?= !empty($start_date) ? '&start_date='.urlencode($start_date) : '' ?><?= !empty($end_date) ? '&end_date='.urlencode($end_date) : '' ?>" class="btn" style="padding:8px 12px; <?= $i==$current_page ? 'background:var(--primary-dark);' : 'opacity:0.7;' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        const mainContent = document.getElementById('mainContent');

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
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function(e) {
            if (body.classList.contains('sidebar-open') && 
                !sidebar?.contains(e.target) && 
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // ============================================
        // FORM FUNCTIONALITY
        // ============================================
        document.getElementById('resetBtn').addEventListener('click', () => {
            window.location.href = '<?= BASE_URL ?>/pages/admin/rekapitulasi_absensi.php';
        });

        const pdfLink = document.getElementById('pdfLink');
        const totalRecords = <?= $total_records ?>;

        pdfLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (totalRecords === 0) {
                Swal.fire('Tidak ada data', 'Tidak ada absensi untuk diunduh', 'warning');
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

                let url = '?export=pdf';
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                if (startDate) {
                    url += `&start_date=${encodeURIComponent(startDate)}`;
                }
                if (endDate) {
                    url += `&end_date=${encodeURIComponent(endDate)}`;
                }
                
                window.location.href = url;
            }, 2000);
        });

        <?php if (isset($show_error_modal) && $show_error_modal): ?>
            Swal.fire('Gagal', '<?= addslashes($error_message) ?>', 'error');
        <?php endif; ?>
    </script>
</body>
</html>