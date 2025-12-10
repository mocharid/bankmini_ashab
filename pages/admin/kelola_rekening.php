<?php
/**
 * Kelola Rekening Siswa - Final Rapih Version
 * File: pages/admin/kelola_rekening.php
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

// Ensure TCPDF is included safely
if (!file_exists(PROJECT_ROOT . '/tcpdf/tcpdf.php')) {
    error_log("DEBUG: TCPDF library not found at " . PROJECT_ROOT . "/tcpdf/tcpdf.php");
    die('TCPDF library not found');
}
require_once PROJECT_ROOT . '/tcpdf/tcpdf.php';

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

// Initialize variables
$search = '';
$jurusan_filter = '';
$tingkatan_filter = '';
$kelas_filter = '';
$status_filter = '';
$page = 1;
$limit = 15;
$offset = 0;

// Handle search and filter parameters
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}
if (isset($_GET['jurusan'])) {
    $jurusan_filter = $_GET['jurusan'];
}
if (isset($_GET['tingkatan'])) {
    $tingkatan_filter = $_GET['tingkatan'];
}
if (isset($_GET['kelas'])) {
    $kelas_filter = $_GET['kelas'];
}

// Auto-set jurusan_filter if kelas_filter is set
if (!empty($kelas_filter) && empty($jurusan_filter)) {
    $get_jurusan_query = "SELECT k.jurusan_id FROM kelas k WHERE k.id = ?";
    $get_jurusan_stmt = $conn->prepare($get_jurusan_query);
    if ($get_jurusan_stmt) {
        $get_jurusan_stmt->bind_param("i", $kelas_filter);
        $get_jurusan_stmt->execute();
        $get_jurusan_result = $get_jurusan_stmt->get_result();
        if ($row = $get_jurusan_result->fetch_assoc()) {
            $jurusan_filter = $row['jurusan_id'];
        }
        $get_jurusan_stmt->close();
    }
}

if (isset($_GET['status'])) {
    $status_filter = $_GET['status'];
}

if (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) {
    $page = (int)$_GET['page'];
    $offset = ($page - 1) * $limit;
}

// Function to mask account number: 123***89
function maskAccountNumber($no_rekening) {
    $length = strlen($no_rekening);
    if ($length <= 5) {
        return $no_rekening;
    }
    $first_three = substr($no_rekening, 0, 3);
    $last_two = substr($no_rekening, -2);
    return $first_three . '***' . $last_two;
}

// Fetch jurusan data
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
}

// Fetch tingkatan data
$query_tingkatan = "SELECT * FROM tingkatan_kelas ORDER BY nama_tingkatan";
$result_tingkatan = $conn->query($query_tingkatan);
if (!$result_tingkatan) {
    error_log("Error fetching tingkatan: " . $conn->error);
}

// Fetch kelas data
$query_kelas = "SELECT k.*, j.nama_jurusan, tk.nama_tingkatan 
                FROM kelas k 
                LEFT JOIN jurusan j ON k.jurusan_id = j.id 
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                WHERE 1=1";
if (!empty($jurusan_filter)) {
    $query_kelas .= " AND k.jurusan_id = " . intval($jurusan_filter);
}
if (!empty($tingkatan_filter)) {
    $query_kelas .= " AND k.tingkatan_kelas_id = " . intval($tingkatan_filter);
}
$query_kelas .= " ORDER BY tk.nama_tingkatan, k.nama_kelas";
$result_kelas = $conn->query($query_kelas);
if (!$result_kelas) {
    error_log("Error fetching kelas: " . $conn->error);
}

// Build main query (adjusted for new schema)
$where_conditions = [];
$params = [];
$types = "";

$query = "SELECT r.id as rekening_id, r.no_rekening, r.saldo,
                 u.id as user_id, u.nama, us.is_frozen,
                 j.nama_jurusan, k.nama_kelas, tk.nama_tingkatan
          FROM rekening r
          INNER JOIN users u ON r.user_id = u.id
          INNER JOIN siswa_profiles sp ON u.id = sp.user_id
          LEFT JOIN user_security us ON u.id = us.user_id
          LEFT JOIN jurusan j ON sp.jurusan_id = j.id
          LEFT JOIN kelas k ON sp.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE u.role = 'siswa'";

if (!empty($search)) {
    $where_conditions[] = "(u.nama LIKE ? OR u.username LIKE ? OR r.no_rekening LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($jurusan_filter)) {
    $where_conditions[] = "sp.jurusan_id = ?";
    $params[] = $jurusan_filter;
    $types .= "i";
}

if (!empty($tingkatan_filter)) {
    $where_conditions[] = "k.tingkatan_kelas_id = ?";
    $params[] = $tingkatan_filter;
    $types .= "i";
}

if (!empty($kelas_filter)) {
    $where_conditions[] = "sp.kelas_id = ?";
    $params[] = $kelas_filter;
    $types .= "i";
}

if (!empty($status_filter)) {
    if ($status_filter === 'frozen') {
        $where_conditions[] = "us.is_frozen = 1";
    } elseif ($status_filter === 'active') {
        $where_conditions[] = "us.is_frozen = 0";
    }
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY u.nama ASC";
$base_query = $query;
$base_types = $types;
$base_params = $params;

// Count total (adjusted for new schema)
$count_query = "SELECT COUNT(*) as total FROM rekening r 
                INNER JOIN users u ON r.user_id = u.id 
                INNER JOIN siswa_profiles sp ON u.id = sp.user_id
                LEFT JOIN user_security us ON u.id = us.user_id
                LEFT JOIN jurusan j ON sp.jurusan_id = j.id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE u.role = 'siswa'";
if (!empty($where_conditions)) {
    $count_query .= " AND " . implode(" AND ", $where_conditions);
}

$count_stmt = $conn->prepare($count_query);
if ($count_stmt) {
    if (!empty($params)) {
        $count_stmt->bind_param($types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    error_log("Error preparing count query: " . $conn->error);
    $total_records = 0;
}

$total_pages = ceil($total_records / $limit);

// Pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Execute main query
$stmt = $conn->prepare($query);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rekening_data = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($rekening_data as &$rekening) {
        $rekening['saldo'] = intval($rekening['saldo'] ?? 0);
        unset($rekening);
    }
    $stmt->close();
} else {
    error_log("Error preparing main query: " . $conn->error);
    $rekening_data = [];
}

// PDF Export (adjusted for new schema)
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    $stmt_export = $conn->prepare($base_query);
    if (!empty($base_params)) {
        $stmt_export->bind_param($base_types, ...$base_params);
    }
    $stmt_export->execute();
    $all_rekening_data = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_export->close();
    
    if (empty($all_rekening_data)) {
        $_SESSION['error_message'] = 'Tidak ada data untuk diekspor!';
        header('Location: ' . BASE_URL . '/pages/admin/kelola_rekening.php');
        exit;
    }
    
    try {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 60);
        
        foreach ($all_rekening_data as &$row) {
            $row['saldo'] = intval($row['saldo'] ?? 0);
            unset($row);
        }
        
        $total_saldo_filtered = array_sum(array_column($all_rekening_data, 'saldo'));
        
        // Filter info
        $filter_search = !empty($search) ? "Pencarian: " . htmlspecialchars($search) : "";
        $filter_jurusan = "";
        if (!empty($jurusan_filter)) {
            $stmt = $conn->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
            $stmt->bind_param("i", $jurusan_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $filter_jurusan = "Jurusan: " . $row['nama_jurusan'];
            }
            $stmt->close();
        }
        
        $filter_tingkatan = "";
        if (!empty($tingkatan_filter)) {
            $stmt = $conn->prepare("SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?");
            $stmt->bind_param("i", $tingkatan_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $filter_tingkatan = "Tingkatan: " . $row['nama_tingkatan'];
            }
            $stmt->close();
        }
        
        $filter_kelas = "";
        if (!empty($kelas_filter)) {
            $stmt = $conn->prepare("SELECT CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas_lengkap 
                                    FROM kelas k 
                                    LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                                    WHERE k.id = ?");
            $stmt->bind_param("i", $kelas_filter);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $filter_kelas = "Kelas: " . $row['kelas_lengkap'];
            }
            $stmt->close();
        }
        
        $bulan_indonesia = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $tanggal_pdf = date('j') . ' ' . $bulan_indonesia[date('n')] . ' ' . date('Y');
        
        // Custom PDF class
        class MYPDF extends TCPDF {
            public function trimText($text, $maxLength) {
                if (strlen($text) > $maxLength) {
                    return substr($text, 0, $maxLength - 2) . '..';
                }
                return $text;
            }
            
            public function formatCurrency($amount) {
                $amount = intval($amount);
                return 'Rp ' . number_format($amount, 0, ",", ".");
            }
            
            public function Header() {
                $this->SetFont('helvetica', 'B', 15);
                $this->Cell(0, 8, 'SCHOBANK', 0, false, 'C', 0);
                $this->Ln(5);
                $this->SetFont('helvetica', '', 9);
                $this->Cell(0, 6, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                $this->Ln(4);
                $this->SetFont('helvetica', '', 7);
                $this->Cell(0, 5, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                $this->Line(10, 28, 200, 28);
            }
            
            public function Footer() {
                $this->SetY(-12);
                $this->SetFont('helvetica', 'I', 7);
                $this->Cell(95, 8, 'Dicetak: ' . date('d/m/Y H:i'), 0, 0, 'L');
                $this->Cell(95, 8, 'Hal ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
            
            public function printFilters($filter_kelas, $filter_tingkatan, $filter_jurusan, $filter_search) {
                $this->SetFont('helvetica', 'B', 8);
                $line_height = 4;
                
                if (!empty($filter_kelas)) {
                    $this->Cell(25, $line_height, 'Kelas', 0, 0, 'L');
                    $this->Cell(2, $line_height, ':', 0, 0, 'L');
                    $this->Cell(0, $line_height, substr($filter_kelas, strpos($filter_kelas, ':') + 2), 0, 1, 'L');
                }
                if (!empty($filter_tingkatan)) {
                    $this->Cell(25, $line_height, 'Tingkatan', 0, 0, 'L');
                    $this->Cell(2, $line_height, ':', 0, 0, 'L');
                    $this->Cell(0, $line_height, substr($filter_tingkatan, strpos($filter_tingkatan, ':') + 2), 0, 1, 'L');
                }
                if (!empty($filter_jurusan)) {
                    $this->Cell(25, $line_height, 'Jurusan', 0, 0, 'L');
                    $this->Cell(2, $line_height, ':', 0, 0, 'L');
                    $this->Cell(0, $line_height, substr($filter_jurusan, strpos($filter_jurusan, ':') + 2), 0, 1, 'L');
                }
                if (!empty($filter_search)) {
                    $this->Cell(25, $line_height, 'Pencarian', 0, 0, 'L');
                    $this->Cell(2, $line_height, ':', 0, 0, 'L');
                    $this->Cell(0, $line_height, substr($filter_search, strpos($filter_search, ':') + 2), 0, 1, 'L');
                }
            }
        }
        
        // Initialize PDF
        $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('SCHOBANK');
        $pdf->SetTitle('Data Rekening Siswa');
        $pdf->SetMargins(10, 32, 10);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        $pdf->SetAutoPageBreak(TRUE, 15);
        $pdf->AddPage();
        $pdf->Ln(2);
        
        // Title
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, 'DATA REKENING SISWA', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Tanggal: ' . $tanggal_pdf, 0, 1, 'C');
        $pdf->Ln(3);
        
        // Filters
        $pdf->printFilters($filter_kelas, $filter_tingkatan, $filter_jurusan, $filter_search);
        $pdf->Ln(3);
        
        // Table header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(8, 5, 'No', 1, 0, 'C', true);
        $pdf->Cell(26, 5, 'No. Rekening', 1, 0, 'C', true);
        $pdf->Cell(55, 5, 'Nama Siswa', 1, 0, 'C', true);
        $pdf->Cell(28, 5, 'Kelas', 1, 0, 'C', true);
        $pdf->Cell(50, 5, 'Jurusan', 1, 0, 'C', true);
        $pdf->Cell(23, 5, 'Saldo', 1, 1, 'C', true);
        
        // Table content
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetFillColor(255, 255, 255);
        
        $no = 1;
        foreach ($all_rekening_data as $row) {
            if ($pdf->GetY() > 260) {
                $pdf->AddPage();
                $pdf->Ln(2);
                
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->SetFillColor(220, 220, 220);
                $pdf->Cell(8, 5, 'No', 1, 0, 'C', true);
                $pdf->Cell(26, 5, 'No. Rekening', 1, 0, 'C', true);
                $pdf->Cell(55, 5, 'Nama Siswa', 1, 0, 'C', true);
                $pdf->Cell(28, 5, 'Kelas', 1, 0, 'C', true);
                $pdf->Cell(50, 5, 'Jurusan', 1, 0, 'C', true);
                $pdf->Cell(23, 5, 'Saldo', 1, 1, 'C', true);
                $pdf->SetFont('helvetica', '', 7);
            }
            
            $no_rek_trim = $pdf->trimText($row['no_rekening'], 24);
            $nama_trim = $pdf->trimText($row['nama'], 52);
            $kelas = trim(($row['nama_tingkatan'] ?? '') . ' ' . ($row['nama_kelas'] ?? ''));
            $kelas_trim = $pdf->trimText($kelas ?: '-', 26);
            $jurusan_trim = $pdf->trimText($row['nama_jurusan'] ?: '-', 48);
            $saldo_fmt = $pdf->formatCurrency($row['saldo']);
            
            $pdf->Cell(8, 5, $no, 1, 0, 'C');
            $pdf->Cell(26, 5, $no_rek_trim, 1, 0, 'C');
            $pdf->Cell(55, 5, $nama_trim, 1, 0, 'L');
            $pdf->Cell(28, 5, $kelas_trim, 1, 0, 'L');
            $pdf->Cell(50, 5, $jurusan_trim, 1, 0, 'L');
            $pdf->Cell(23, 5, $saldo_fmt, 1, 1, 'R');
            $no++;
        }
        
        // Total row
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Cell(167, 5, 'Total Saldo Keseluruhan', 1, 0, 'C', true);
        $pdf->Cell(23, 5, $pdf->formatCurrency($total_saldo_filtered), 1, 1, 'R', true);
        
        // Output
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="data_rekening_siswa_' . date('YmdHis') . '.pdf"');
        $pdf->Output('data_rekening_siswa.pdf', 'D');
        exit;
    } catch (Exception $e) {
        error_log("DEBUG: PDF Generation Error: " . $e->getMessage());
        http_response_code(500);
        die("Failed to generate PDF: " . $e->getMessage());
    }
}

// Messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kelola Rekening Siswa | MY Schobank</title>
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
        
        .action-buttons button { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; margin-right: 5px; color: white; }
        .btn-edit { background: var(--secondary-color); }
        .btn-delete { background: #ef4444; }

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
                <h2><i class="fas fa-file-invoice"></i> Data Rekening Siswa</h2>
                <p>Lihat dan kelola data rekening siswa secara terpusat</p>
            </div>
        </div>

        <!-- CARD FILTER -->
        <div class="form-card">
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="search">Pencarian</label>
                        <input type="text" id="search" name="search" placeholder="Nama, Username, No. Rekening" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="jurusan">Jurusan</label>
                        <select id="jurusan" name="jurusan" onchange="updateTingkatanOptions()">
                            <option value="">Semua Jurusan</option>
                            <?php 
                            if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                $result_jurusan->data_seek(0);
                                while ($row = $result_jurusan->fetch_assoc()) { 
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $jurusan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php 
                                }
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tingkatan">Tingkatan</label>
                        <select id="tingkatan" name="tingkatan" onchange="updateKelasOptions()">
                            <option value="">Semua Tingkatan</option>
                            <?php 
                            if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                                $result_tingkatan->data_seek(0);
                                while ($row = $result_tingkatan->fetch_assoc()) { 
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $tingkatan_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                </option>
                            <?php 
                                }
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="kelas">Kelas</label>
                        <select id="kelas" name="kelas">
                            <option value="">Semua Kelas</option>
                            <?php 
                            if ($result_kelas && $result_kelas->num_rows > 0) {
                                $result_kelas->data_seek(0);
                                while ($row = $result_kelas->fetch_assoc()) { 
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $kelas_filter == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_tingkatan'] . ' ' . $row['nama_kelas'] . ' - ' . $row['nama_jurusan']); ?>
                                </option>
                            <?php 
                                }
                            } 
                            ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">Status Rekening</label>
                        <select id="status" name="status">
                            <option value="">Semua Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="frozen" <?php echo $status_filter == 'frozen' ? 'selected' : ''; ?>>Beku</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn"><i class="fas fa-filter"></i> Terapkan</button>
                    <button type="button" id="resetBtn" class="btn btn-cancel"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </form>
        </div>
        
        <!-- CARD TABEL -->
        <div class="jadwal-list">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3><i class="fas fa-table"></i> Daftar Rekening Siswa</h3>
                <a href="<?= BASE_URL ?>/pages/admin/kelola_rekening.php?export=pdf" class="btn" target="_blank" style="padding: 8px 15px; font-size: 0.9rem;">
                    <i class="fas fa-print"></i> Cetak PDF
                </a>
            </div>
            
            <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>No. Rekening</th>
                                <th>Nama Siswa</th>
                                <th>Jurusan/Kelas</th>
                                <th>Saldo</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rekening_data)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:30px; color:#999;">Belum ada data rekening.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rekening_data as $rekening): ?>
                                    <tr>
                                        <td style="font-weight:600;font-family:monospace;font-size:0.8125rem;">
                                            <?php echo htmlspecialchars(maskAccountNumber($rekening['no_rekening'])); ?>
                                        </td>
                                        <td style="font-weight:500;"><?php echo htmlspecialchars($rekening['nama']); ?></td>
                                        <td style="font-size:0.8125rem;">
                                            <?php 
                                            $kelas_lengkap = '';
                                            if (!empty($rekening['nama_tingkatan']) && !empty($rekening['nama_kelas'])) {
                                                $kelas_lengkap = $rekening['nama_tingkatan'] . ' ' . $rekening['nama_kelas'];
                                                if (!empty($rekening['nama_jurusan'])) {
                                                    $kelas_lengkap .= ' - ' . $rekening['nama_jurusan'];
                                                }
                                            }
                                            echo htmlspecialchars($kelas_lengkap ?: '-');
                                            ?>
                                        </td>
                                        <td style="font-weight:600;font-family:monospace;">Rp <?php echo number_format($rekening['saldo'], 0, ",", "."); ?></td>
                                        <td>
                                            <?php if ($rekening['is_frozen']): ?>
                                                <span style="display:inline-block;padding:4px 10px;background:#fef3c7;color:#92400e;border-radius:5px;font-size:0.75rem;font-weight:500;">
                                                    <i class="fas fa-snowflake"></i> Beku
                                                </span>
                                            <?php else: ?>
                                                <span style="display:inline-block;padding:4px 10px;background:#d1fae5;color:#065f46;border-radius:5px;font-size:0.75rem;font-weight:500;">
                                                    <i class="fas fa-check-circle"></i> Aktif
                                                </span>
                                            <?php endif; ?>
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
                    <a href="?page=<?= $i ?>" class="btn" style="padding:8px 12px; <?= $i==$page ? 'background:var(--primary-dark);' : 'opacity:0.7;' ?>"><?= $i ?></a>
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

        // Prevent body scroll when sidebar open (mobile)
        let scrollTop = 0;
        body.addEventListener('scroll', function() {
            if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
                scrollTop = body.scrollTop;
            }
        }, { passive: true });

        // ============================================
        // FORM FUNCTIONALITY
        // ============================================
        document.getElementById('resetBtn').addEventListener('click', () => {
            window.location.href = '<?= BASE_URL ?>/pages/admin/kelola_rekening.php';
        });
            
        <?php if (!empty($success_message)): ?>
            Swal.fire('Berhasil', '<?= addslashes($success_message) ?>', 'success');
        <?php endif; ?>
            
        <?php if (!empty($error_message)): ?>
            Swal.fire('Gagal', '<?= addslashes($error_message) ?>', 'error');
        <?php endif; ?>
            
        window.updateTingkatanOptions = function() {
            const jurusan = document.getElementById('jurusan').value;
            const tingkatanSelect = document.getElementById('tingkatan');
            const selectedTingkatan = tingkatanSelect.value;
                
            tingkatanSelect.innerHTML = '<option value="">Semua Tingkatan</option>';
                
            if (jurusan) {
                fetch(`<?= BASE_URL ?>/includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_tingkatan`)
                    .then(r => r.text())
                    .then(data => {
                        if (data.trim()) {
                            tingkatanSelect.innerHTML += data;
                            if (selectedTingkatan) {
                                const opt = tingkatanSelect.querySelector(`option[value="${selectedTingkatan}"]`);
                                if (opt) tingkatanSelect.value = selectedTingkatan;
                            }
                        }
                        updateKelasOptions();
                    })
                    .catch(() => {});
            } else {
                updateKelasOptions();
            }
        };
            
        window.updateKelasOptions = function() {
            const jurusan = document.getElementById('jurusan').value;
            const tingkatan = document.getElementById('tingkatan').value;
            const kelasSelect = document.getElementById('kelas');
            const selectedKelas = kelasSelect.value;
                
            kelasSelect.innerHTML = '<option value="">Semua Kelas</option>';
                
            if (jurusan) {
                let url = `<?= BASE_URL ?>/includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_kelas`;
                if (tingkatan) {
                    url += `&tingkatan_id=${tingkatan}`;
                }
                fetch(url)
                    .then(r => r.text())
                    .then(data => {
                        if (data.trim()) {
                            kelasSelect.innerHTML += data;
                            if (selectedKelas) {
                                const opt = kelasSelect.querySelector(`option[value="${selectedKelas}"]`);
                                if (opt) kelasSelect.value = selectedKelas;
                            }
                        }
                    })
                    .catch(() => {});
            }
        };
            
        if (document.getElementById('jurusan').value) {
            updateTingkatanOptions();
        }
    </script>
</body>
</html>