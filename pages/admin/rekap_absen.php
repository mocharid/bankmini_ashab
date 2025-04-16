<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// Set default values for filters and pagination
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$rows_per_page = 10;
$offset = ($page - 1) * $rows_per_page;

// Modified query to handle ONLY_FULL_GROUP_BY mode
$baseQuery = "SELECT DISTINCT a.tanggal, 
              MAX(pt.petugas1_nama) AS petugas1_nama, 
              MAX(pt.petugas2_nama) AS petugas2_nama,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas1_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas1_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_masuk, '23:59:00') ELSE NULL END) AS petugas2_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN NULLIF(a.waktu_keluar, '23:59:00') ELSE NULL END) AS petugas2_keluar
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

// Group by tanggal to avoid duplicates
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

// Ensure $types always contains the pagination parameters
if (empty($types)) {
    $types = "ii"; // Only pagination parameters
} else {
    $types .= "ii"; // Append pagination parameters to existing types
}

// Prepare and execute the main query for data
$stmt = $conn->prepare($baseQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Store data in array for display
$absensi = [];
while ($row = $result->fetch_assoc()) {
    $absensi[] = $row;
}

// Function to format date to dd/mm/yy
function formatDate($date) {
    return date('d/m/y', strtotime($date));
}

// Handle PDF and Excel export
if (isset($_GET['export'])) {
    if ($_GET['export'] == 'pdf') {
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
        
        // Show filter dates in subtitle with dd/mm/yy format
        $filter_text = "Tanggal: " . date('d/m/y');
        if (!empty($start_date) && !empty($end_date)) {
            $filter_text = "Periode: " . formatDate($start_date) . " s/d " . formatDate($end_date);
        } elseif (!empty($start_date)) {
            $filter_text = "Periode: Dari " . formatDate($start_date);
        } elseif (!empty($end_date)) {
            $filter_text = "Periode: Sampai " . formatDate($end_date);
        }
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 7, $filter_text, 0, 1, 'C');

        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->Cell(180, 7, 'DAFTAR ABSENSI', 1, 1, 'C', true);

        // Modified table header for PDF with centered column headers
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Tanggal', 1, 0, 'C');
        $pdf->Cell(65, 6, 'Nama Petugas', 1, 0, 'C');
        $pdf->Cell(40, 6, 'Jam Masuk', 1, 0, 'C');
        $pdf->Cell(40, 6, 'Jam Keluar', 1, 1, 'C');

        $pdf->SetFont('helvetica', '', 8);
        $no = 1;
        // Execute the query again for PDF export
        $exportStmt = $conn->prepare(str_replace(" LIMIT ? OFFSET ?", "", $baseQuery));
        if (!empty($params)) {
            // Remove the last two parameters (LIMIT and OFFSET)
            array_pop($params);
            array_pop($params);
            if (!empty($params)) {
                $exportStmt->bind_param(substr($types, 0, -2), ...$params);
            }
        }
        $exportStmt->execute();
        $exportResult = $exportStmt->get_result();
        
        while ($row = $exportResult->fetch_assoc()) {
            // First row - Petugas 1
            $pdf->Cell(10, 6, $no, 'LTR', 0, 'C');
            $pdf->Cell(25, 6, formatDate($row['tanggal']), 'LTR', 0, 'C');
            $pdf->Cell(65, 6, $row['petugas1_nama'] ?? '-', 1, 0, 'L');
            $pdf->Cell(40, 6, $row['petugas1_masuk'] ?? '-', 1, 0, 'C');
            $pdf->Cell(40, 6, $row['petugas1_keluar'] ?? '-', 1, 1, 'C');
            
            // Second row - Petugas 2
            $pdf->Cell(10, 6, '', 'LBR', 0, 'C');
            $pdf->Cell(25, 6, '', 'LBR', 0, 'C');
            $pdf->Cell(65, 6, $row['petugas2_nama'] ?? '-', 1, 0, 'L');
            $pdf->Cell(40, 6, $row['petugas2_masuk'] ?? '-', 1, 0, 'C');
            $pdf->Cell(40, 6, $row['petugas2_keluar'] ?? '-', 1, 1, 'C');
            
            $no++;
        }

        $pdf->Output('rekap_absen.pdf', 'D');
        exit();
    } elseif ($_GET['export'] == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="rekap_absen.xls"');

        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head>';
        echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
        echo '<style>
                body { font-family: Arial, sans-serif; }
                .text-left { text-align: left; }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                td, th { padding: 4px; }
                .title { font-size: 14pt; font-weight: bold; text-align: center; }
                .subtitle { font-size: 10pt; text-align: center; }
                .section-header { background-color: #f0f0f0; font-weight: bold; text-align: center; }
              </style>';
        echo '</head>';
        echo '<body>';

        echo '<table border="0" cellpadding="3">';
        echo '<tr><td colspan="5" class="title">REKAP ABSENSI PETUGAS</td></tr>';
        
        // Show filter dates in subtitle with dd/mm/yy format
        $filter_text = "Tanggal: " . date('d/m/y');
        if (!empty($start_date) && !empty($end_date)) {
            $filter_text = "Periode: " . formatDate($start_date) . " s/d " . formatDate($end_date);
        } elseif (!empty($start_date)) {
            $filter_text = "Periode: Dari " . formatDate($start_date);
        } elseif (!empty($end_date)) {
            $filter_text = "Periode: Sampai " . formatDate($end_date);
        }
        
        echo '<tr><td colspan="5" class="subtitle">' . $filter_text . '</td></tr>';
        echo '</table>';

        echo '<table border="1" cellpadding="3">';
        echo '<tr><th colspan="5" class="section-header">DAFTAR ABSENSI</th></tr>';
        echo '<tr>';
        echo '<th>No</th>';
        echo '<th>Tanggal</th>';
        echo '<th>Nama Petugas</th>';
        echo '<th>Masuk</th>';
        echo '<th>Keluar</th>';
        echo '</tr>';

        $no = 1;
        // Execute the query again for Excel export
        $exportStmt = $conn->prepare(str_replace(" LIMIT ? OFFSET ?", "", $baseQuery));
        if (!empty($params)) {
            // Remove the last two parameters (LIMIT and OFFSET)
            array_pop($params);
            array_pop($params);
            if (!empty($params)) {
                $exportStmt->bind_param(substr($types, 0, -2), ...$params);
            }
        }
        $exportStmt->execute();
        $exportResult = $exportStmt->get_result();
        
        while ($row = $exportResult->fetch_assoc()) {
            // For petugas 1
            echo '<tr>';
            echo '<td class="text-center" rowspan="2">' . $no . '</td>';
            echo '<td class="text-center" rowspan="2">' . formatDate($row['tanggal']) . '</td>';
            echo '<td class="text-left">' . ($row['petugas1_nama'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas1_masuk'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas1_keluar'] ?? '-') . '</td>';
            echo '</tr>';
            
            // For petugas 2
            echo '<tr>';
            echo '<td class="text-left">' . ($row['petugas2_nama'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas2_masuk'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas2_keluar'] ?? '-') . '</td>';
            echo '</tr>';
            
            $no++;
        }
        echo '</table>';

        echo '</body></html>';
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rekap Absensi Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
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
            transition: var(--transition);
        }

        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-print {
            background-color: var(--secondary-color);
        }

        .btn-print:hover {
            background-color: var(--secondary-dark);
        }

        .transactions-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .transactions-title {
            background: var(--primary-dark);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: center;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background: #f0f4ff;
            font-weight: 600;
            color: var(--text-primary);
        }

        td {
            background: white;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.5rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px 0;
        }

        .pagination-link {
            background-color: var(--primary-light);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover:not(.disabled) {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            background-color: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 5px solid #81c784;
        }

        .loading .btn {
            pointer-events: none;
            background-color: #cccccc;
        }

        .loading .btn i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

            .transactions-container {
                padding: 20px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                flex-wrap: wrap;
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

            .transactions-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 4vw, 2rem);
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
            <h2><i class="fas fa-calendar-alt"></i> Rekap Absensi Petugas</h2>
            <p>Rekap absensi petugas berdasarkan tanggal</p>
        </div>

        <div id="alertContainer"></div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>">
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" id="printButton" class="btn btn-print">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="transactions-container">
            <h3 class="transactions-title">Daftar Absensi</h3>
            <?php if (empty($absensi)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-alt"></i>
                    <p>Tidak ada data absensi dalam periode ini</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>Nama Petugas</th>
                            <th>Jam Masuk</th>
                            <th>Jam Keluar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $no = $offset + 1;
                        foreach ($absensi as $index => $record):
                        ?>
                            <tr>
                                <td rowspan="2"><?= $no ?></td>
                                <td rowspan="2"><?= formatDate($record['tanggal']) ?></td>
                                <td><?= htmlspecialchars($record['petugas1_nama'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['petugas1_masuk'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['petugas1_keluar'] ?? '-') ?></td>
                            </tr>
                            <tr>
                                <td><?= htmlspecialchars($record['petugas2_nama'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['petugas2_masuk'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($record['petugas2_keluar'] ?? '-') ?></td>
                            </tr>
                            <?php $no++; ?>
                        <?php endforeach; ?>
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
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming
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
                const existingAlerts = alertContainer.querySelectorAll('.alert');
                existingAlerts.forEach(alert => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                let icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
                alertDiv.innerHTML = `
                    <i class="fas fa-${icon}"></i>
                    <span>${message}</span>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.classList.add('hide');
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
            }

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const printButton = document.getElementById('printButton');

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }

                    filterButton.classList.add('loading');
                    filterButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';

                    const url = new URL(window.location);
                    if (startDate) url.searchParams.set('start_date', startDate);
                    else url.searchParams.delete('start_date');
                    if (endDate) url.searchParams.set('end_date', endDate);
                    else url.searchParams.delete('end_date');
                    url.searchParams.set('page', 1);
                    window.location.href = url.toString();
                });
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }

                    printButton.classList.add('loading');
                    printButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';

                    let url = '?export=pdf';
                    if (startDate) url += `&start_date=${startDate}`;
                    if (endDate) url += `&end_date=${endDate}`;
                    window.open(url, '_blank');

                    // Reset button state
                    setTimeout(() => {
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                    }, 1000);
                });
            }
        });
    </script>
</body>
</html>