<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Set default values for filters and pagination
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$rows_per_page = 10;
$offset = ($page - 1) * $rows_per_page;

// Modified query to include status fields
$baseQuery = "SELECT DISTINCT a.tanggal, 
              MAX(pt.petugas1_nama) AS petugas1_nama, 
              MAX(pt.petugas2_nama) AS petugas2_nama,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas1_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas1_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas2_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas2_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN a.petugas1_status ELSE NULL END) AS petugas1_status,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN a.petugas2_status ELSE NULL END) AS petugas2_status
              FROM absensi a 
              JOIN users u ON a.user_id = u.id 
              LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal";

$countQuery = "SELECT COUNT(DISTINCT a.tanggal) as total 
               FROM absensi a 
               JOIN users u ON a.user_id = u.id 
               LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal";

// Add WHERE clauses for filters
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

// Construct the final queries
if (!empty($whereClause)) {
    $whereString = " WHERE " . implode(" AND ", $whereClause);
    $baseQuery .= $whereString;
    $countQuery .= $whereString;
}

$baseQuery .= " GROUP BY a.tanggal";

// Get total count before adding pagination
$countStmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRows = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRows / $rows_per_page);

// Add ordering and pagination to the main query
$baseQuery .= " ORDER BY a.tanggal ASC LIMIT ? OFFSET ?";
$params[] = $rows_per_page;
$params[] = $offset;

if (empty($types)) {
    $types = "ii";
} else {
    $types .= "ii";
}

// Prepare and execute the main query for data
$stmt = $conn->prepare($baseQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$absensi = [];
while ($row = $result->fetch_assoc()) {
    $absensi[] = $row;
}

// Function to format date to dd/mm/yy
function formatDate($date) {
    return date('d/m/y', strtotime($date));
}

// Handle PDF export
if (isset($_GET['export']) && $_GET['export'] == 'pdf') {
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public function Header() {
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 10, 'Sistem Bank Mini SMK Plus Ashabulyamin', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 8);
            $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A, Sayang, Kec. Cianjur, Kabupaten Cianjur, Jawa Barat', 0, false, 'C', 0);
            $this->Line(15, 35, 195, 35);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/y H:i'), 0, 0, 'L');
            $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Rekap Absensi Petugas');
    $pdf->SetMargins(15, 40, 15);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->AddPage();

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'REKAP ABSENSI PETUGAS', 0, 1, 'C');
    
    $filter_text = "Periode: " . formatDate($start_date) . " s/d " . formatDate($end_date);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, $filter_text, 0, 1, 'C');

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(180, 7, 'DAFTAR ABSENSI', 1, 1, 'C', true);

    // Updated table header for PDF with status column
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(10, 6, 'No', 1, 0, 'C');
    $pdf->Cell(25, 6, 'Tanggal', 1, 0, 'C');
    $pdf->Cell(50, 6, 'Nama Petugas', 1, 0, 'C');
    $pdf->Cell(25, 6, 'Status', 1, 0, 'C');
    $pdf->Cell(35, 6, 'Jam Masuk', 1, 0, 'C');
    $pdf->Cell(35, 6, 'Jam Keluar', 1, 1, 'C');

    $pdf->SetFont('helvetica', '', 8);
    $no = 1;
    $status_map = ['hadir' => 'Hadir', 'tidak_hadir' => 'Tidak Hadir', 'sakit' => 'Sakit', 'izin' => 'Izin'];
    $exportStmt = $conn->prepare(str_replace(" LIMIT ? OFFSET ?", "", $baseQuery));
    if (!empty($params)) {
        array_pop($params);
        array_pop($params);
        if (!empty($params)) {
            $exportStmt->bind_param(substr($types, 0, -2), ...$params);
        }
    }
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    
    while ($row = $exportResult->fetch_assoc()) {
        $petugas1_status = $row['petugas1_status'] ?? '-';
        $petugas2_status = $row['petugas2_status'] ?? '-';
        $petugas1_status_display = $status_map[$petugas1_status] ?? $petugas1_status;
        $petugas2_status_display = $status_map[$petugas2_status] ?? $petugas2_status;

        // First row - Petugas 1
        $pdf->Cell(10, 6, $no, 'LTR', 0, 'C');
        $pdf->Cell(25, 6, formatDate($row['tanggal']), 'LTR', 0, 'C');
        $pdf->Cell(50, 6, $row['petugas1_nama'] ?? '-', 1, 0, 'L');
        $pdf->Cell(25, 6, $petugas1_status_display, 1, 0, 'C');
        $pdf->Cell(35, 6, ($petugas1_status === 'hadir' && $row['petugas1_masuk']) ? $row['petugas1_masuk'] : '-', 1, 0, 'C');
        $pdf->Cell(35, 6, ($petugas1_status === 'hadir' && $row['petugas1_keluar']) ? $row['petugas1_keluar'] : '-', 1, 1, 'C');
        
        // Second row - Petugas 2
        $pdf->Cell(10, 6, '', 'LBR', 0, 'C');
        $pdf->Cell(25, 6, '', 'LBR', 0, 'C');
        $pdf->Cell(50, 6, $row['petugas2_nama'] ?? '-', 1, 0, 'L');
        $pdf->Cell(25, 6, $petugas2_status_display, 1, 0, 'C');
        $pdf->Cell(35, 6, ($petugas2_status === 'hadir' && $row['petugas2_masuk']) ? $row['petugas2_masuk'] : '-', 1, 0, 'C');
        $pdf->Cell(35, 6, ($petugas2_status === 'hadir' && $row['petugas2_keluar']) ? $row['petugas2_keluar'] : '-', 1, 1, 'C');
        
        $no++;
    }

    $pdf->Output('rekap_absen.pdf', 'D');
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rekap Absensi Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="date"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #fff;
            position: relative;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            display: block;
            width: 20px;
            height: 20px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23666"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>') no-repeat center;
            background-size: 20px;
            cursor: pointer;
            opacity: 0.7;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        input[type="date"]::-webkit-datetime-edit {
            padding-right: 30px;
        }

        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .summary-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-title {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .summary-table th, .summary-table td {
            padding: 12px 15px;
            text-align: center;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
        }

        .summary-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        .summary-table td {
            background: white;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px 0;
        }

        .pagination-link {
            background: var(--bg-light);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover:not(.disabled) {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            background: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.2rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .empty-state a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }

        .empty-state a:hover {
            text-decoration: underline;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(350px, 85vw, 450px);
            border-radius: 15px;
            padding: clamp(30px, 5vw, 40px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
        }

        .success-modal {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: white;
        }

        .error-icon {
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.95rem, 2.3vw, 1.05rem);
            margin: 0 0 25px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .summary-container {
                padding: 20px;
            }

            .summary-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .summary-table th, .summary-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .success-modal, .error-modal {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .summary-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            input[type="date"] {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Rekap Absensi Petugas</h2>
            <p>Periode: <?= htmlspecialchars(date('d/m/Y', strtotime($start_date))) ?> - <?= htmlspecialchars(date('d/m/Y', strtotime($end_date))) ?></p>
        </div>

        <div id="alertContainer"></div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($start_date) ?>" required>
                    <span class="tooltip">Pilih tanggal mulai periode</span>
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>" required>
                    <span class="tooltip">Pilih tanggal akhir periode</span>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <span class="btn-content"><i class="fas fa-filter"></i> Filter</span>
                    </button>
                    <button type="button" id="printButton" class="btn">
                        <span class="btn-content"><i class="fas fa-print"></i> Cetak</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Section -->
        <div class="summary-container">
            <h3 class="summary-title">Daftar Absensi</h3>
            <?php if (empty($absensi)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <p>Tidak ada data absensi dalam periode ini</p>
                </div>
            <?php else: ?>
                <table class="summary-table">
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
                        $start_no = ($page - 1) * $rows_per_page + 1;
                        $status_map = ['hadir' => 'Hadir', 'tidak_hadir' => 'Tidak Hadir', 'sakit' => 'Sakit', 'izin' => 'Izin'];
                        foreach ($absensi as $index => $record) {
                            $petugas1_status = $record['petugas1_status'] ?? '-';
                            $petugas2_status = $record['petugas2_status'] ?? '-';
                            $petugas1_status_display = $status_map[$petugas1_status] ?? $petugas1_status;
                            $petugas2_status_display = $status_map[$petugas2_status] ?? $petugas2_status;
                        ?>
                            <tr>
                                <td rowspan="2"><?= $start_no + $index ?></td>
                                <td rowspan="2"><?= formatDate($record['tanggal']) ?></td>
                                <td><?= htmlspecialchars($record['petugas1_nama'] ?? '-') ?></td>
                                <td class="status <?= strtolower(str_replace(' ', '-', $petugas1_status)) ?>">
                                    <?= htmlspecialchars($petugas1_status_display) ?>
                                </td>
                                <td><?= $petugas1_status === 'hadir' && $record['petugas1_masuk'] ? htmlspecialchars($record['petugas1_masuk']) : '-' ?></td>
                                <td><?= $petugas1_status === 'hadir' && $record['petugas1_keluar'] ? htmlspecialchars($record['petugas1_keluar']) : '-' ?></td>
                            </tr>
                            <tr>
                                <td><?= htmlspecialchars($record['petugas2_nama'] ?? '-') ?></td>
                                <td class="status <?= strtolower(str_replace(' ', '-', $petugas2_status)) ?>">
                                    <?= htmlspecialchars($petugas2_status_display) ?>
                                </td>
                                <td><?= $petugas2_status === 'hadir' && $record['petugas2_masuk'] ? htmlspecialchars($record['petugas2_masuk']) : '-' ?></td>
                                <td><?= $petugas2_status === 'hadir' && $record['petugas2_keluar'] ? htmlspecialchars($record['petugas2_keluar']) : '-' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <a href="?page=<?= max(1, $page - 1) ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>" 
                       class="pagination-link <?= $page <= 1 ? 'disabled' : '' ?>">« Sebelumnya</a>
                    <a href="?page=<?= min($totalPages, $page + 1) ?>&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>" 
                       class="pagination-link <?= $page >= $totalPages ? 'disabled' : '' ?>">Selanjutnya »</a>
                </div>
            <?php endif; ?>
            <input type="hidden" id="totalAbsensi" value="<?= count($absensi) ?>">
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
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

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.success-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `success-overlay`;
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
                alertDiv.addEventListener('click', () => {
                    alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alertDiv.remove(), 500);
                });
            }

            // Show loading spinner
            function showLoadingSpinner(element) {
                const originalContent = element.innerHTML;
                element.innerHTML = '<span class="btn-content"><i class="fas fa-spinner fa-spin"></i> Memproses...</span>';
                element.classList.add('loading');
                return {
                    restore: () => {
                        element.innerHTML = originalContent;
                        element.classList.remove('loading');
                    }
                };
            }

            // Ensure date input consistency in Safari
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.style.webkitAppearance = 'none';
                    this.style.appearance = 'none';
                });
                input.addEventListener('blur', function() {
                    this.style.webkitAppearance = 'none';
                    this.style.appearance = 'none';
                });
            });

            // Dynamic date constraints
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const today = new Date().toISOString().split('T')[0];

            startDateInput.setAttribute('max', today);
            endDateInput.setAttribute('max', today);

            startDateInput.addEventListener('change', function() {
                if (this.value) {
                    endDateInput.setAttribute('min', this.value);
                    if (endDateInput.value && endDateInput.value < this.value) {
                        endDateInput.value = this.value;
                    }
                } else {
                    endDateInput.removeAttribute('min');
                }
            });

            endDateInput.addEventListener('change', function() {
                if (this.value) {
                    startDateInput.setAttribute('max', this.value);
                    if (startDateInput.value && startDateInput.value > this.value) {
                        startDateInput.value = this.value;
                    }
                } else {
                    startDateInput.setAttribute('max', today);
                }
            });

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const printButton = document.getElementById('printButton');
            const totalAbsensi = parseInt(document.getElementById('totalAbsensi').value);

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (filterForm.classList.contains('submitting')) return;
                    filterForm.classList.add('submitting');

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal yang valid', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }
                    if (new Date(startDate) > new Date(today)) {
                        showAlert('Tanggal awal tidak boleh melebihi tanggal hari ini', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }
                    if (new Date(endDate) > new Date(today)) {
                        showAlert('Tanggal akhir tidak boleh melebihi tanggal hari ini', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }

                    const spinner = showLoadingSpinner(filterButton);
                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.set('start_date', startDate);
                        url.searchParams.set('end_date', endDate);
                        url.searchParams.set('page', 1);
                        window.location.href = url.toString();
                    }, 1000);
                });
            }

            // Build export URL
            function buildExportUrl() {
                const startDate = startDateInput.value;
                const endDate = endDateInput.value;
                let url = `?export=pdf`;
                if (startDate) url += `&start_date=${encodeURIComponent(startDate)}`;
                if (endDate) url += `&end_date=${encodeURIComponent(endDate)}`;
                return url;
            }

            // Handle PDF export/download
            if (printButton) {
                printButton.addEventListener('click', function() {
                    if (totalAbsensi === 0) {
                        showAlert('Tidak ada data absensi untuk dicetak', 'error');
                        return;
                    }

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal untuk mencetak', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(today)) {
                        showAlert('Tanggal awal tidak boleh melebihi tanggal hari ini', 'error');
                        return;
                    }
                    if (new Date(endDate) > new Date(today)) {
                        showAlert('Tanggal akhir tidak boleh melebihi tanggal hari ini', 'error');
                        return;
                    }

                    const spinner = showLoadingSpinner(printButton);
                    const url = buildExportUrl();
                    setTimeout(() => {
                        spinner.restore();
                        window.location.href = url;
                    }, 1000);
                });
            }

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Keyboard accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                    filterForm.dispatchEvent(new Event('submit'));
                }
            });

            // Ensure responsive table scroll on mobile
            const table = document.querySelector('.summary-table');
            if (table) {
                table.parentElement.style.overflowX = 'auto';
            }

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });
        });
    </script>
</body>
</html>