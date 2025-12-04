<?php
/* KELOLA REKENING SISWA */
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Ensure TCPDF is included safely
if (!file_exists('../../tcpdf/tcpdf.php')) {
    error_log("DEBUG: TCPDF library not found at ../../tcpdf/tcpdf.php");
    die('TCPDF library not found');
}
require_once '../../tcpdf/tcpdf.php';

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
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

// Build main query
$where_conditions = [];
$params = [];
$types = "";

$query = "SELECT r.id as rekening_id, r.no_rekening, r.saldo,
                 u.id as user_id, u.nama, u.is_frozen,
                 j.nama_jurusan, k.nama_kelas, tk.nama_tingkatan
          FROM rekening r
          INNER JOIN users u ON r.user_id = u.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
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
    $where_conditions[] = "u.jurusan_id = ?";
    $params[] = $jurusan_filter;
    $types .= "i";
}

if (!empty($tingkatan_filter)) {
    $where_conditions[] = "k.tingkatan_kelas_id = ?";
    $params[] = $tingkatan_filter;
    $types .= "i";
}

if (!empty($kelas_filter)) {
    $where_conditions[] = "u.kelas_id = ?";
    $params[] = $kelas_filter;
    $types .= "i";
}

if (!empty($status_filter)) {
    if ($status_filter === 'frozen') {
        $where_conditions[] = "u.is_frozen = 1";
    } elseif ($status_filter === 'active') {
        $where_conditions[] = "u.is_frozen = 0";
    }
}

if (!empty($where_conditions)) {
    $query .= " AND " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY u.nama ASC";
$base_query = $query;
$base_types = $types;
$base_params = $params;

// Count total
$count_query = "SELECT COUNT(*) as total FROM rekening r 
                INNER JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
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

// PDF Export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    try {
        ini_set('memory_limit', '256M');
        ini_set('max_execution_time', 60);
        
        $stmt_export = $conn->prepare($base_query);
        if (!empty($base_params)) {
            $stmt_export->bind_param($base_types, ...$base_params);
        }
        $stmt_export->execute();
        $all_rekening_data = $stmt_export->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_export->close();
        
        foreach ($all_rekening_data as &$row) {
            $row['saldo'] = intval($row['saldo'] ?? 0);
            unset($row);
        }
        
        if (empty($all_rekening_data)) {
            http_response_code(404);
            die("No data found for export");
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Kelola Rekening Siswa | MY SCHOBANK</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --button-width: 140px;
            --button-height: 44px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            touch-action: pan-y;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
        }

        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .welcome-banner .content {
            flex: 1;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.9;
        }

        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        .card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            border: 1px solid #e5e7eb;
        }

        .card-title {
            font-size: clamp(1.1rem, 2.5vw, 1.25rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .filter-group {
            flex: 1;
            min-width: 180px;
        }

        .filter-group label {
            display: block;
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 8px;
            font-size: 0.875rem;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 0.9375rem;
            -webkit-user-select: text;
            user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: white;
            min-height: 44px;
            transition: border-color 0.2s;
        }

        .filter-group select {
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 18px;
            padding-right: 36px;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.08);
        }

        .button-group {
            display: flex;
            gap: 10px;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 11px 20px;
            cursor: pointer;
            font-size: 0.9375rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            -webkit-tap-highlight-color: transparent;
            transition: opacity 0.2s;
            width: var(--button-width);
            height: var(--button-height);
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-secondary {
            background: #e5e7eb;
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background: #d1d5db;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            gap: 10px;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            margin-bottom: 18px;
            border-radius: 8px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e5e7eb;
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            scrollbar-width: thin;
            scrollbar-color: #d1d5db #f3f4f6;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f3f4f6;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
            min-width: 800px;
        }

        th, td {
            padding: 12px 14px;
            text-align: left;
            font-size: 0.875rem;
            border-bottom: 1px solid #e5e7eb;
            white-space: nowrap;
        }

        th {
            background: #f3f4f6;
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8125rem;
            letter-spacing: 0.3px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        td {
            background: white;
            color: var(--text-primary);
        }

        tr:hover td {
            background-color: #f9fafb;
        }

        .empty-state {
            text-align: center;
            padding: 50px 30px;
            background: #f9fafb;
            border-radius: 8px;
            border: 2px dashed #e5e7eb;
            margin: 18px 0;
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--text-secondary);
            opacity: 0.4;
            margin-bottom: 12px;
        }

        .empty-state p {
            font-size: 1.0625rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 8px;
        }

        .empty-state .subtext {
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 400;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 18px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 9px 13px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 0.875rem;
            min-width: 42px;
            min-height: 42px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination span.current {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
            }

            .menu-toggle {
                display: block;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 8px;
            }

            .card {
                padding: 18px;
                border-radius: 8px;
            }

            .filter-container {
                flex-direction: column;
            }

            .filter-group {
                min-width: 100%;
            }

            .button-group {
                width: 100%;
            }

            .button-group .btn {
                flex: 1;
                width: auto;
                min-width: 0;
            }

            table {
                min-width: 900px;
            }

            th, td {
                font-size: 0.8125rem;
                padding: 11px 12px;
            }
        }

        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }

            .button-group .btn {
                width: var(--button-width);
                flex: 0 0 auto;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 16px;
            }

            .card {
                padding: 15px;
            }

            .button-group {
                gap: 8px;
            }

            .button-group .btn {
                font-size: 0.875rem;
                padding: 10px 8px;
                flex: 1;
            }

            table {
                min-width: 1000px;
            }

            th, td {
                font-size: 0.8125rem;
                padding: 10px 11px;
            }

            .filter-group input,
            .filter-group select,
            .btn {
                font-size: 16px !important;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .filter-group input,
            .filter-group select,
            .btn {
                min-height: 44px;
                font-size: 16px;
            }

            .menu-toggle {
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-file-invoice"></i> Data Rekening Siswa</h2>
                <p>Lihat dan kelola data rekening siswa secara terpusat</p>
            </div>
        </div>

        <!-- CARD FILTER -->
        <div class="card">
            <h3 class="card-title"><i class="fas fa-filter"></i> Filter Data</h3>
            <form method="GET" action="">
                <div class="filter-container">
                    <div class="filter-group">
                        <label for="search">Pencarian</label>
                        <input type="text" id="search" name="search" placeholder="Nama, Username, No. Rekening" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <div class="filter-group">
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
                    
                    <div class="filter-group">
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
                    
                    <div class="filter-group">
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
                    
                    <div class="filter-group">
                        <label for="status">Status Rekening</label>
                        <select id="status" name="status">
                            <option value="">Semua Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif</option>
                            <option value="frozen" <?php echo $status_filter == 'frozen' ? 'selected' : ''; ?>>Beku</option>
                        </select>
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Terapkan</button>
                    <button type="button" id="resetBtn" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</button>
                </div>
            </form>
        </div>
        
        <!-- CARD TABEL -->
        <div class="card">
            <h3 class="card-title">
                <i class="fas fa-table"></i> Daftar Rekening Siswa
                <span style="font-size:0.875rem;color:var(--text-secondary);font-weight:normal;margin-left:8px;">
                    (<?php echo number_format($total_records, 0, ",", "."); ?> data)
                </span>
            </h3>
            <input type="hidden" id="totalRecords" value="<?php echo $total_records; ?>">
            
            <div class="action-buttons">
                <button class="btn btn-primary export-pdf-btn">
                    <i class="fas fa-file-pdf"></i> Unduh
                </button>
            </div>
            
            <div class="table-container">
                <?php if (empty($rekening_data)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>Tidak ada data yang ditemukan</p>
                        <p class="subtext">Silakan ubah filter atau kata kunci pencarian</p>
                    </div>
                <?php else: ?>
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
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <?php if ($total_records > 15): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Sebelumnya
                        </a>
                    <?php endif; ?>
                    
                    <?php 
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    if ($start > 1) {
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a>';
                        if ($start > 2) echo '<span>...</span>';
                    }
                    
                    for ($i = $start; $i <= $end; $i++): 
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php endif; 
                    endfor; ?>
                    
                    <?php
                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span>...</span>';
                        echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a>';
                    }
                    ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Selanjutnya <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
            
            document.addEventListener('click', (e) => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            
            document.getElementById('resetBtn').addEventListener('click', () => {
                window.location.href = 'kelola_rekening.php';
            });
            
            <?php if (!empty($success_message)): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    html: '<?php echo addslashes($success_message); ?>',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
            
            <?php if (!empty($error_message)): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    html: '<?php echo addslashes($error_message); ?>',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
            
            window.updateTingkatanOptions = function() {
                const jurusan = document.getElementById('jurusan').value;
                const tingkatanSelect = document.getElementById('tingkatan');
                const selectedTingkatan = tingkatanSelect.value;
                
                tingkatanSelect.innerHTML = '<option value="">Semua Tingkatan</option>';
                
                if (jurusan) {
                    fetch(`../../includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_tingkatan`)
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
                    let url = `../../includes/get_kelas_by_jurusan_dan_tingkatan.php?jurusan_id=${jurusan}&action=get_kelas`;
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
            
            document.querySelectorAll('.export-pdf-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const totalRecords = parseInt(document.getElementById('totalRecords').value);
                    
                    if (totalRecords === 0) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Tidak ada data',
                            text: 'Tidak ada data untuk dicetak ke PDF.'
                        });
                        return;
                    }
                    
                    let url = '?export=pdf';
                    ['search', 'jurusan', 'tingkatan', 'kelas', 'status'].forEach(id => {
                        const input = document.getElementById(id);
                        if (input && input.value) {
                            url += `&${id}=${encodeURIComponent(input.value)}`;
                        }
                    });
                    
                    window.open(url, '_blank');
                });
            });
            
            if (document.getElementById('jurusan').value) {
                updateTingkatanOptions();
            }
        });
    </script>
</body>
</html>
