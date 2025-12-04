<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;

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

// ========================================
// HANDLE PDF EXPORT - HARUS DI AWAL SEBELUM OUTPUT APAPUN
// ========================================
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    
    // Build query untuk PDF (tanpa LIMIT) - PERBAIKAN: Tambah JOIN untuk pi2
    $pdfQuery = "SELECT DISTINCT a.tanggal, 
                  pi1.petugas1_nama, 
                  pi2.petugas2_nama,
                  MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas1_masuk,
                  MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas1_keluar,
                  MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas2_masuk,
                  MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas2_keluar,
                  MAX(CASE WHEN a.petugas_type = 'petugas1' THEN a.petugas1_status ELSE NULL END) AS petugas1_status,
                  MAX(CASE WHEN a.petugas_type = 'petugas2' THEN a.petugas2_status ELSE NULL END) AS petugas2_status
                  FROM absensi a 
                  JOIN users u ON a.user_id = u.id 
                  LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal
                  LEFT JOIN petugas_info pi1 ON pt.petugas1_id = pi1.user_id
                  LEFT JOIN petugas_info pi2 ON pt.petugas2_id = pi2.user_id";
    
    $whereClause = [];
    $pdfParams = [];
    $pdfTypes = "";
    
    if (!empty($start_date)) {
        $whereClause[] = "a.tanggal >= ?";
        $pdfParams[] = $start_date;
        $pdfTypes .= "s";
    }
    
    if (!empty($end_date)) {
        $whereClause[] = "a.tanggal <= ?";
        $pdfParams[] = $end_date;
        $pdfTypes .= "s";
    }
    
    if (!empty($whereClause)) {
        $pdfQuery .= " WHERE " . implode(" AND ", $whereClause);
    }
    
    $pdfQuery .= " GROUP BY a.tanggal, pi1.petugas1_nama, pi2.petugas2_nama ORDER BY a.tanggal ASC";
    
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
        
        // Include TCPDF library
        require_once '../../tcpdf/tcpdf.php';
        
        // Custom TCPDF class
        class MYPDF extends TCPDF {
            public function Header() {
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'MY SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
                $this->Line(15, 35, $this->getPageWidth() - 15, 35);
            }
            
            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('helvetica', 'I', 8);
                $this->Cell(90, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
                $this->Cell(0, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }
        
        // Create PDF (Portrait orientation)
        $pdf = new MYPDF('P', PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('MY SCHOBANK');
        $pdf->SetAuthor('Admin SCHOBANK');
        $pdf->SetTitle('Rekap Absensi Petugas');
        $pdf->SetSubject('Laporan Absensi');
        
        // Set margins
        $pdf->SetMargins(15, 40, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(15);
        
        // Set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, 20);
        
        // Add a page
        $pdf->AddPage();
        
        // Add spacing after header
        $pdf->Ln(5);
        
        // Title
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 7, 'REKAP ABSENSI PETUGAS', 0, 1, 'C');
        
        // Periode
        $periode_text = 'Semua Data';
        if (!empty($start_date) && !empty($end_date)) {
            $periode_text = formatDate($start_date) . ' s/d ' . formatDate($end_date);
        } elseif (!empty($start_date)) {
            $periode_text = 'Dari ' . formatDate($start_date);
        } elseif (!empty($end_date)) {
            $periode_text = 'Sampai ' . formatDate($end_date);
        }
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, 'Periode: ' . $periode_text, 0, 1, 'C');
        $pdf->Ln(5);
        
        // Section Header
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'DAFTAR ABSENSI', 1, 1, 'C', true);
        
        // Table Header
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Tanggal', 1, 0, 'C');
        $pdf->Cell(50, 6, 'Nama Petugas', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Status', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jam Masuk', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jam Keluar', 1, 1, 'C');
        
        // Table Body
        $pdf->SetFont('helvetica', '', 8);
        
        if (count($pdfData) > 0) {
            $status_map = [
                'hadir' => 'Hadir',
                'tidak_hadir' => 'Tidak Hadir',
                'sakit' => 'Sakit',
                'izin' => 'Izin'
            ];
            
            $no = 1;
            foreach ($pdfData as $record) {
                $petugas1_status = $record['petugas1_status'] ?? '-';
                $petugas2_status = $record['petugas2_status'] ?? '-';
                
                $petugas1_status_display = $status_map[$petugas1_status] ?? $petugas1_status;
                $petugas2_status_display = $status_map[$petugas2_status] ?? $petugas2_status;
                
                $tanggal_formatted = formatDate($record['tanggal']);
                
                // First row - Petugas 1
                $pdf->Cell(10, 6, $no, 'LTR', 0, 'C');
                $pdf->Cell(25, 6, $tanggal_formatted, 'LTR', 0, 'C');
                $pdf->Cell(50, 6, $record['petugas1_nama'] ?? '-', 1, 0, 'L');
                $pdf->Cell(25, 6, $petugas1_status_display, 1, 0, 'C');
                $pdf->Cell(35, 6, ($petugas1_status === 'hadir' && $record['petugas1_masuk']) ? $record['petugas1_masuk'] : '-', 1, 0, 'C');
                $pdf->Cell(35, 6, ($petugas1_status === 'hadir' && $record['petugas1_keluar']) ? $record['petugas1_keluar'] : '-', 1, 1, 'C');
                
                // Second row - Petugas 2
                $pdf->Cell(10, 6, '', 'LBR', 0, 'C');
                $pdf->Cell(25, 6, '', 'LBR', 0, 'C');
                $pdf->Cell(50, 6, $record['petugas2_nama'] ?? '-', 1, 0, 'L');
                $pdf->Cell(25, 6, $petugas2_status_display, 1, 0, 'C');
                $pdf->Cell(35, 6, ($petugas2_status === 'hadir' && $record['petugas2_masuk']) ? $record['petugas2_masuk'] : '-', 1, 0, 'C');
                $pdf->Cell(35, 6, ($petugas2_status === 'hadir' && $record['petugas2_keluar']) ? $record['petugas2_keluar'] : '-', 1, 1, 'C');
                
                $no++;
            }
        } else {
            $pdf->Cell(180, 6, 'Tidak ada data absensi', 1, 1, 'C');
        }
        
        // Clean output buffer
        ob_end_clean();
        
        // Output PDF
        $filename = 'rekap_absen.pdf';
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
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Build query - PERBAIKAN: Tambah JOIN untuk pi2
$baseQuery = "SELECT DISTINCT a.tanggal, 
              pi1.petugas1_nama, 
              pi2.petugas2_nama,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas1_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas1_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas2_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas2_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN a.petugas1_status ELSE NULL END) AS petugas1_status,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN a.petugas2_status ELSE NULL END) AS petugas2_status
              FROM absensi a 
              JOIN users u ON a.user_id = u.id 
              LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal
              LEFT JOIN petugas_info pi1 ON pt.petugas1_id = pi1.user_id
              LEFT JOIN petugas_info pi2 ON pt.petugas2_id = pi2.user_id";

$countQuery = "SELECT COUNT(DISTINCT a.tanggal) as total 
               FROM absensi a 
               JOIN users u ON a.user_id = u.id 
               LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal
               LEFT JOIN petugas_info pi1 ON pt.petugas1_id = pi1.user_id
               LEFT JOIN petugas_info pi2 ON pt.petugas2_id = pi2.user_id";

// Add WHERE clauses
$whereClause = [];
$params = [];
$types = "";

if (!empty($start_date)) {
    $whereClause[] = "a.tanggal >= ?";
    $params[] = $start_date;
    $types .= "s";
}

if (!empty($end_date)) {
    $whereClause[] = "a.tanggal <= ?";
    $params[] = $end_date;
    $types .= "s";
}

if (!empty($whereClause)) {
    $whereString = " WHERE " . implode(" AND ", $whereClause);
    $baseQuery .= $whereString;
    $countQuery .= $whereString;
}

$baseQuery .= " GROUP BY a.tanggal, pi1.petugas1_nama, pi2.petugas2_nama";

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
$baseQuery .= " ORDER BY a.tanggal ASC LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;

if (empty($types)) {
    $types = "ii";
} else {
    $types .= "ii";
}

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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Rekapitulasi Absensi Petugas | MY Schobank</title>
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
            --debit-bg: #d1fae5;
            --kredit-bg: #fee2e2;
            --pending-bg: #fef3c7;
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
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
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
            opacity: 0.8;
        }

        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        .filter-section, .summary-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
        }

        .date-input-wrapper {
            position: relative;
            width: 100%;
        }

        input[type="date"] {
            width: 100%;
            padding: 12px 50px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
            text-align: left;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            color: var(--text-primary);
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            cursor: pointer;
            opacity: 0;
            z-index: 2;
        }

        input[type="date"]::before {
            content: attr(data-placeholder);
            color: #999;
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        input[type="date"]:focus::before,
        input[type="date"]:valid::before {
            display: none;
        }

        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #666;
            font-size: 18px;
            z-index: 1;
        }

        input[type="date"]::-webkit-datetime-edit {
            padding-right: 0;
            text-align: left;
            padding-left: 0;
        }

        input[type="date"]::-webkit-datetime-edit-fields-wrapper {
            text-align: left;
            padding: 0;
        }

        input[type="date"]::-webkit-datetime-edit-text {
            padding: 0 3px;
        }

        input[type="date"]::-webkit-datetime-edit-month-field,
        input[type="date"]::-webkit-datetime-edit-day-field,
        input[type="date"]::-webkit-datetime-edit-year-field {
            padding: 0 3px;
        }

        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        input[aria-invalid="true"] {
            border-color: var(--danger-color);
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
            text-align: left;
        }

        .error-message.show {
            display: block;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .summary-title {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .action-buttons {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 15px;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            width: var(--button-width);
            height: var(--button-height);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-secondary {
            background: #f0f0f0;
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            position: relative;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        .absensi-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
            min-width: 800px;
        }

        .absensi-table th, .absensi-table td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }

        .absensi-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .absensi-table td {
            background: white;
        }

        .absensi-table tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }

        .status-hadir {
            background: var(--debit-bg);
            color: #047857;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }

        .status-tidak-hadir {
            background: var(--kredit-bg);
            color: #b91c1c;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }

        .status-sakit, .status-izin {
            background: #fef3c7;
            color: #d97706;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            display: inline-block;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            width: 100%;
        }

        .current-page {
            font-weight: 600;
            color: var(--primary-dark);
            min-width: 50px;
            text-align: center;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
        }

        .pagination-btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(1rem, 2.2vw, 1.1rem);
            font-weight: 500;
            transition: var(--transition);
            width: auto;
            height: var(--button-height);
            padding: 0 16px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pagination-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .pagination-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.03) 0%, rgba(59, 130, 246, 0.03) 100%);
            border-radius: 5px;
            border: 2px dashed rgba(30, 58, 138, 0.2);
            animation: fadeIn 0.6s ease-out;
            margin: 20px 0;
        }

        .empty-state-icon {
            font-size: clamp(3.5rem, 6vw, 4.5rem);
            color: var(--primary-color);
            margin-bottom: 25px;
            opacity: 0.4;
            display: block;
        }

        .empty-state-title {
            color: var(--primary-dark);
            font-size: clamp(1.3rem, 2.8vw, 1.6rem);
            font-weight: 600;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }

        .empty-state-message {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2vw, 1.1rem);
            font-weight: 400;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto 25px;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
                border-radius: 5px;
                align-items: center;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }

            .filter-section, .summary-container {
                padding: 20px;
                border-radius: 5px;
            }

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: row;
                width: 100%;
                gap: 10px;
            }

            .filter-buttons .btn {
                flex: 1;
                width: auto;
                min-width: 0;
            }

            .action-buttons {
                justify-content: center;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .table-container {
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
                touch-action: pan-x pan-y;
                max-height: none;
                border-radius: 5px;
            }

            .absensi-table {
                min-width: 900px;
            }

            .absensi-table th, .absensi-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                gap: 5px;
            }

            .pagination-btn {
                padding: 8px 12px;
                min-width: 40px;
                font-size: clamp(1rem, 2.2vw, 1.1rem);
                border-radius: 5px;
            }

            .current-page {
                font-size: clamp(0.95rem, 2vw, 1rem);
                min-width: 45px;
            }

            .empty-state {
                padding: 40px 20px;
                border-radius: 5px;
            }
        }

        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }

            .filter-buttons {
                flex-direction: row;
                width: auto;
            }

            .filter-buttons .btn {
                width: var(--button-width);
                flex: 0 0 auto;
                border-radius: 5px;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.8vw, 1.2rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }

            .filter-section, .summary-container {
                padding: 15px;
                border-radius: 5px;
            }

            .filter-buttons {
                flex-direction: row;
                gap: 8px;
            }

            .filter-buttons .btn {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                padding: 10px 8px;
                flex: 1;
                border-radius: 5px;
            }

            .pagination-btn {
                padding: 6px 10px;
                min-width: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
                border-radius: 5px;
            }

            .current-page {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                min-width: 40px;
            }

            .absensi-table {
                min-width: 1000px;
            }

            .table-container {
                touch-action: pan-x pan-y;
                overflow-y: visible;
                border-radius: 5px;
            }

            .empty-state {
                padding: 35px 15px;
                border-radius: 5px;
            }
        }

        .swal2-input {
            width: 100% !important;
            max-width: 400px !important;
            padding: 12px 16px !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 1rem !important;
            margin: 10px auto !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }

        .swal2-input:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15) !important;
        }

        .swal2-popup .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }

        .swal2-html-container {
            font-size: 1rem !important;
            margin: 15px 0 !important;
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Rekap Absensi Petugas</h2>
                <p>Periode: <?= htmlspecialchars($periode_display) ?></p>
            </div>
        </div>

        <div class="filter-section">
            <form id="filterForm" class="filter-form" method="GET" action="" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" aria-describedby="start_date-error" data-placeholder="Pilih tanggal">
                        <i class="fas fa-calendar-alt calendar-icon"></i>
                    </div>
                    <span class="error-message" id="start_date-error"></span>
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" aria-describedby="end_date-error" data-placeholder="Pilih tanggal">
                        <i class="fas fa-calendar-alt calendar-icon"></i>
                    </div>
                    <span class="error-message" id="end_date-error"></span>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" id="resetButton" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="summary-container">
            <h3 class="summary-title"><i class="fas fa-list"></i> Daftar Absensi</h3>

            <div class="action-buttons">
                <button id="pdfButton" class="btn">
                    <i class="fas fa-file-pdf"></i> Unduh PDF
                </button>
            </div>

            <?php if ($total_records > 0): ?>
                <div class="table-container">
                    <table class="absensi-table">
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
                            <?php
                            $status_map = ['hadir' => 'Hadir', 'tidak_hadir' => 'Tidak Hadir', 'sakit' => 'Sakit', 'izin' => 'Izin'];
                            $status_class_map = ['hadir' => 'status-hadir', 'tidak_hadir' => 'status-tidak-hadir', 'sakit' => 'status-sakit', 'izin' => 'status-izin'];
                            foreach ($absensi as $index => $record) {
                                $petugas1_status = $record['petugas1_status'] ?? '-';
                                $petugas2_status = $record['petugas2_status'] ?? '-';
                                $petugas1_status_display = $status_map[$petugas1_status] ?? $petugas1_status;
                                $petugas2_status_display = $status_map[$petugas2_status] ?? $petugas2_status;
                                $petugas1_status_class = isset($status_class_map[$petugas1_status]) ? $status_class_map[$petugas1_status] : '';
                                $petugas2_status_class = isset($status_class_map[$petugas2_status]) ? $status_class_map[$petugas2_status] : '';
                                $noUrut = (($current_page - 1) * $items_per_page) + $index + 1;
                            ?>
                                <tr>
                                    <td rowspan="2"><?= $noUrut ?></td>
                                    <td rowspan="2"><?= date('d/m/Y', strtotime($record['tanggal'])) ?></td>
                                    <td><?= htmlspecialchars($record['petugas1_nama'] ?? '-') ?></td>
                                    <td><span class="<?= $petugas1_status_class ?>"><?= htmlspecialchars($petugas1_status_display) ?></span></td>
                                    <td><?= $petugas1_status === 'hadir' && $record['petugas1_masuk'] ? htmlspecialchars($record['petugas1_masuk']) : '-' ?></td>
                                    <td><?= $petugas1_status === 'hadir' && $record['petugas1_keluar'] ? htmlspecialchars($record['petugas1_keluar']) : '-' ?></td>
                                </tr>
                                <tr>
                                    <td><?= htmlspecialchars($record['petugas2_nama'] ?? '-') ?></td>
                                    <td><span class="<?= $petugas2_status_class ?>"><?= htmlspecialchars($petugas2_status_display) ?></span></td>
                                    <td><?= $petugas2_status === 'hadir' && $record['petugas2_masuk'] ? htmlspecialchars($record['petugas2_masuk']) : '-' ?></td>
                                    <td><?= $petugas2_status === 'hadir' && $record['petugas2_keluar'] ? htmlspecialchars($record['petugas2_keluar']) : '-' ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_records > $items_per_page): ?>
                    <div class="pagination">
                        <button id="prev-page" class="pagination-btn" <?= $current_page <= 1 ? 'disabled' : '' ?>>&lt;</button>
                        <span class="current-page"><?= $current_page ?></span>
                        <button id="next-page" class="pagination-btn" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>&gt;</button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt empty-state-icon"></i>
                    <h3 class="empty-state-title">Tidak Ada Data Absensi</h3>
                    <p class="empty-state-message">Tidak ditemukan data absensi dalam periode yang dipilih. Silakan periksa filter tanggal atau coba periode lain.</p>
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" id="totalRecords" value="<?= htmlspecialchars($total_records) ?>">

        <?php if (isset($show_error_modal) && $show_error_modal): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '<?= htmlspecialchars($error_message); ?>',
                    confirmButtonColor: '#1e3a8a'
                });
            </script>
        <?php endif; ?>
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
            const mainContent = document.getElementById('mainContent');

            if (menuToggle && sidebar && mainContent) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });

                document.addEventListener('click', function(e) {
                    if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }

            function showAlert(title, message, type = 'error') {
                Swal.fire({
                    icon: type,
                    title: title,
                    text: message,
                    confirmButtonColor: '#1e3a8a'
                });
            }

            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const startDateError = document.getElementById('start_date-error');
            const endDateError = document.getElementById('end_date-error');

            let isFilterProcessing = false;

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    if (isFilterProcessing) return;

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    startDateInput.setAttribute('aria-invalid', 'false');
                    endDateInput.setAttribute('aria-invalid', 'false');
                    startDateError.textContent = '';
                    endDateError.textContent = '';
                    startDateError.classList.remove('show');
                    endDateError.classList.remove('show');

                    if (!startDate && !endDate) {
                        const url = new URL(window.location);
                        url.searchParams.delete('start_date');
                        url.searchParams.delete('end_date');
                        url.searchParams.delete('page');
                        window.location.href = url.toString();
                        return;
                    }

                    if ((startDate && !endDate) || (!startDate && endDate)) {
                        if (!startDate) {
                            startDateInput.setAttribute('aria-invalid', 'true');
                            startDateError.textContent = 'Silakan pilih tanggal awal';
                            startDateError.classList.add('show');
                        }
                        if (!endDate) {
                            endDateInput.setAttribute('aria-invalid', 'true');
                            endDateError.textContent = 'Silakan pilih tanggal akhir';
                            endDateError.classList.add('show');
                        }
                        showAlert('Error', 'Silakan lengkapi kedua tanggal');
                        return;
                    }

                    if (new Date(startDate) > new Date(endDate)) {
                        startDateInput.setAttribute('aria-invalid', 'true');
                        endDateInput.setAttribute('aria-invalid', 'true');
                        startDateError.textContent = 'Tanggal awal tidak boleh lebih dari tanggal akhir';
                        endDateError.textContent = 'Tanggal awal tidak boleh lebih dari tanggal akhir';
                        startDateError.classList.add('show');
                        endDateError.classList.add('show');
                        showAlert('Error', 'Tanggal awal tidak boleh lebih dari tanggal akhir');
                        return;
                    }

                    isFilterProcessing = true;
                    Swal.fire({
                        title: 'Memproses Filter',
                        html: 'Sedang memuat data absensi...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.set('start_date', startDate);
                        url.searchParams.set('end_date', endDate);
                        url.searchParams.delete('page');
                        window.location.href = url.toString();
                    }, 800);
                });

                filterForm.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.target.matches('textarea')) {
                        e.preventDefault();
                        if (!isFilterProcessing) {
                            filterButton.click();
                        }
                    }
                });
            }

            const resetButton = document.getElementById('resetButton');
            if (resetButton) {
                resetButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    const url = new URL(window.location);
                    url.searchParams.delete('start_date');
                    url.searchParams.delete('end_date');
                    url.searchParams.delete('page');
                    window.location.href = url.toString();
                });
            }

            const pdfButton = document.getElementById('pdfButton');
            const totalRecords = parseInt(document.getElementById('totalRecords').value);

            if (pdfButton) {
                pdfButton.addEventListener('click', function(e) {
                    e.preventDefault();

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    if (totalRecords === 0) {
                        showAlert('Error', 'Tidak ada data absensi untuk diunduh');
                        return;
                    }

                    if (startDate && endDate) {
                        if (new Date(startDate) > new Date(endDate)) {
                            showAlert('Error', 'Tanggal awal tidak boleh lebih dari tanggal akhir');
                            return;
                        }
                    }

                    let url = window.location.pathname + '?export=pdf';
                    
                    if (startDate && endDate) {
                        url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                    } else if (startDate) {
                        url += `&start_date=${encodeURIComponent(startDate)}`;
                    } else if (endDate) {
                        url += `&end_date=${encodeURIComponent(endDate)}`;
                    }

                    Swal.fire({
                        title: 'Membuat PDF',
                        html: 'Mohon tunggu, sedang membuat file PDF...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    setTimeout(() => {
                        window.location.href = url;
                        
                        setTimeout(() => {
                            Swal.close();
                        }, 2000);
                    }, 500);
                });
            }

            const prevPageButton = document.getElementById('prev-page');
            const nextPageButton = document.getElementById('next-page');
            const currentPage = <?= $current_page ?>;
            const totalPages = <?= $total_pages ?>;

            if (prevPageButton) {
                prevPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage > 1 && !prevPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage - 1);
                        window.location.href = url.toString();
                    }
                });
            }

            if (nextPageButton) {
                nextPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage < totalPages && !nextPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage + 1);
                        window.location.href = url.toString();
                    }
                });
            }

            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                tableContainer.style.overflowX = 'auto';
                tableContainer.style.overflowY = 'visible';

                let startX = 0;
                let startY = 0;
                let isScrollingHorizontally = false;

                tableContainer.addEventListener('touchstart', function(e) {
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;
                    isScrollingHorizontally = false;
                }, { passive: true });

                tableContainer.addEventListener('touchmove', function(e) {
                    const currentX = e.touches[0].clientX;
                    const currentY = e.touches[0].clientY;
                    const diffX = Math.abs(currentX - startX);
                    const diffY = Math.abs(currentY - startY);

                    if (diffX > diffY && diffX > 10) {
                        isScrollingHorizontally = true;
                    } else if (diffY > diffX && diffY > 10) {
                        isScrollingHorizontally = false;
                    }
                }, { passive: false });

                tableContainer.addEventListener('touchend', function() {
                    isScrollingHorizontally = false;
                }, { passive: true });
            }

            let lastWindowWidth = window.innerWidth;
            window.addEventListener('resize', () => {
                if (window.innerWidth !== lastWindowWidth) {
                    lastWindowWidth = window.innerWidth;
                    if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                    if (tableContainer) {
                        tableContainer.style.overflowX = 'auto';
                        tableContainer.style.overflowY = 'visible';
                    }
                }
            });

            document.addEventListener('mousedown', (e) => {
                if (!e.target.matches('input, select, textarea')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
