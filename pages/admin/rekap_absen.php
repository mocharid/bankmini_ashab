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
$petugas = isset($_GET['petugas']) ? $_GET['petugas'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$rows_per_page = 10;
$offset = ($page - 1) * $rows_per_page;

// Get all petugas for dropdown filter
$petugasQuery = "SELECT id, nama FROM users WHERE role = 'petugas' ORDER BY nama ASC";
$petugasResult = $conn->query($petugasQuery);

// Base query
$baseQuery = "SELECT a.*, u.nama, pt.petugas1_nama, pt.petugas2_nama 
              FROM absensi a 
              JOIN users u ON a.user_id = u.id 
              LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal";
$countQuery = "SELECT COUNT(*) as total 
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

if (!empty($petugas)) {
    $whereClause[] = "a.user_id = ?";
    $params[] = $petugas;
    $types .= "i";
}

// Add search functionality for petugas name
if (!empty($search)) {
    $whereClause[] = "u.nama LIKE ?";
    $params[] = "%$search%";
    $types .= "s";
}

// Construct the final queries
if (!empty($whereClause)) {
    $whereString = " WHERE " . implode(" AND ", $whereClause);
    $baseQuery .= $whereString;
    $countQuery .= $whereString;
}

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
$baseQuery .= " ORDER BY a.tanggal DESC, a.waktu_masuk DESC LIMIT ? OFFSET ?";
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

// Handle export requests
if (isset($_GET['export']) && in_array($_GET['export'], ['pdf', 'excel'])) {
    // For export, we need all results without pagination
    $exportQuery = "SELECT a.*, u.nama, pt.petugas1_nama, pt.petugas2_nama 
                    FROM absensi a 
                    JOIN users u ON a.user_id = u.id 
                    LEFT JOIN petugas_tugas pt ON a.tanggal = pt.tanggal";
    
    // Add filters if they exist
    if (!empty($whereClause)) {
        $exportQuery .= " WHERE " . implode(" AND ", $whereClause);
    }
    
    $exportQuery .= " ORDER BY a.tanggal DESC, a.waktu_masuk DESC";
    
    // Prepare the export query
    $exportStmt = $conn->prepare($exportQuery);
    
    // Bind parameters only if filters are applied
    if (!empty($params)) {
        // Remove pagination parameters (last two)
        $exportParams = array_slice($params, 0, -2);
        $exportTypes = substr($types, 0, -2);
        
        // Bind parameters if there are any
        if (!empty($exportTypes) && !empty($exportParams)) {
            $exportStmt->bind_param($exportTypes, ...$exportParams);
        }
    }
    
    // Execute the export query
    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();
    
    // Handle export based on type
    if ($_GET['export'] === 'excel') {
        exportToExcel($exportResult);
    } else {
        exportToPDF($exportResult);
    }
    exit;
}

// Function to export data to Excel with improved styling and consistent widths
function exportToExcel($result) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="rekap_absensi.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { font-family: Arial, sans-serif; }
            table { border-collapse: collapse; width: 100%; max-width: 700px; margin: 0 auto; }
            th, td { border: 1px solid #000; padding: 6px; text-align: center; }
            th { background-color: #0c4da2; color: white; font-weight: bold; }
            .title { text-align: center; font-size: 16pt; font-weight: bold; margin-bottom: 15px; }
            .subtitle { text-align: center; font-size: 12pt; margin-bottom: 20px; }
            .col-no { width: 40px; }
            .col-tanggal { width: 90px; }
            .col-waktu { width: 90px; }
            .col-petugas { width: 110px; }
            .print-date { text-align: right; font-style: italic; font-size: 10pt; margin-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class="title">REKAP ABSENSI PETUGAS</div>
        <div class="subtitle">MINI BANK SEKOLAH</div>
        <div class="print-date">Tanggal Cetak: ' . date('d-m-Y') . '</div>
        <table>
            <thead>
                <tr>
                    <th class="col-no">No</th>
                    <th class="col-tanggal">Tanggal</th>
                    <th class="col-waktu">Waktu Masuk</th>
                    <th class="col-waktu">Waktu Keluar</th>
                    <th class="col-petugas">Petugas 1</th>
                    <th class="col-petugas">Petugas 2</th>
                </tr>
            </thead>
            <tbody>';
            
    $no = 1;
    while ($row = $result->fetch_assoc()) {
        // Format tanggal to dd-mm-yyyy for better readability
        $tanggal = date('d-m-Y', strtotime($row['tanggal']));
        
        echo '<tr>
                <td class="col-no">' . $no++ . '</td>
                <td class="col-tanggal">' . $tanggal . '</td>
                <td class="col-waktu">' . htmlspecialchars($row['waktu_masuk'] ?? '-') . '</td>
                <td class="col-waktu">' . htmlspecialchars($row['waktu_keluar'] ?? '-') . '</td>
                <td class="col-petugas" style="text-align: left;">' . htmlspecialchars($row['petugas1_nama'] ?? '-') . '</td>
                <td class="col-petugas" style="text-align: left;">' . htmlspecialchars($row['petugas2_nama'] ?? '-') . '</td>
              </tr>';
    }
            
    echo '</tbody>
        </table>
        <div style="text-align: right; margin-top: 20px; font-size: 10pt;">
            <p style="margin-bottom: 60px;">Kepala Sekolah</p>
            <p><strong>____________________</strong></p>
        </div>
    </body>
    </html>';
}

// Function to export data to PDF with consistent column widths
function exportToPDF($result) {
    // Require the TCPDF library
    require_once '../../tcpdf/tcpdf.php';
    
    // Create new TCPDF object with Portrait orientation
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    
    // Set document information
    $pdf->SetCreator('Mini Bank Sekolah');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Rekap Absensi');
    $pdf->SetSubject('Rekap Absensi Petugas');
    
    // Set margins to ensure content fits on page
    $pdf->SetMargins(20, 20, 20);  // Adjusted margins for better balance
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Create a header with logo and title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'REKAP ABSENSI PETUGAS', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'MINI BANK SEKOLAH', 0, 1, 'C');
    
    // Add current date
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 8, 'Tanggal Cetak: ' . date('d-m-Y'), 0, 1, 'R');
    $pdf->Ln(5);
    
    // Define column widths (total width is now 170mm with balanced proportions)
    $colNo = 15;        // "No" column width
    $colTanggal = 30;   // "Tanggal" column width
    $colWaktu = 30;     // "Waktu" columns width
    $colPetugas = 35;   // "Petugas" columns width
    
    // Table header
    $pdf->SetFillColor(12, 77, 162); // Primary blue color
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->SetDrawColor(200, 200, 200); // Light gray borders
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('helvetica', 'B', 10);
    
    $pdf->Cell($colNo, 10, 'No', 1, 0, 'C', 1);
    $pdf->Cell($colTanggal, 10, 'Tanggal', 1, 0, 'C', 1);
    $pdf->Cell($colWaktu, 10, 'Waktu Masuk', 1, 0, 'C', 1);
    $pdf->Cell($colWaktu, 10, 'Waktu Keluar', 1, 0, 'C', 1);
    $pdf->Cell($colPetugas, 10, 'Petugas 1', 1, 0, 'C', 1);
    $pdf->Cell($colPetugas, 10, 'Petugas 2', 1, 1, 'C', 1);
    
    // Table data
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0); // Black text
    
    $no = 1;
    $row_color = false; // For alternating row colors
    
    while ($row = $result->fetch_assoc()) {
        // Format tanggal to dd-mm-yyyy for better readability
        $tanggal = date('d-m-Y', strtotime($row['tanggal']));
        
        // Alternating row colors
        if ($row_color) {
            $pdf->SetFillColor(240, 248, 255); // Light blue for even rows
        } else {
            $pdf->SetFillColor(255, 255, 255); // White for odd rows
        }
        
        // Add ellipsis for long text and limit cell content
        $petugas1 = isset($row['petugas1_nama']) ? (strlen($row['petugas1_nama']) > 18 ? substr($row['petugas1_nama'], 0, 15).'...' : $row['petugas1_nama']) : '-';
        $petugas2 = isset($row['petugas2_nama']) ? (strlen($row['petugas2_nama']) > 18 ? substr($row['petugas2_nama'], 0, 15).'...' : $row['petugas2_nama']) : '-';
        
        $pdf->Cell($colNo, 8, $no++, 1, 0, 'C', 1);
        $pdf->Cell($colTanggal, 8, $tanggal, 1, 0, 'C', 1);
        $pdf->Cell($colWaktu, 8, $row['waktu_masuk'] ?? '-', 1, 0, 'C', 1);
        $pdf->Cell($colWaktu, 8, $row['waktu_keluar'] ?? '-', 1, 0, 'C', 1);
        $pdf->Cell($colPetugas, 8, $petugas1, 1, 0, 'L', 1);
        $pdf->Cell($colPetugas, 8, $petugas2, 1, 1, 'L', 1);
        
        $row_color = !$row_color; // Toggle for next row
    }
    
    // Add signature section
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(($colNo + $colTanggal + $colWaktu*2), 5, '', 0, 0, 'L', 0);
    $pdf->Cell(($colPetugas*2), 5, date('d F Y'), 0, 1, 'C', 0);
    
    $pdf->Cell(($colNo + $colTanggal + $colWaktu*2), 5, '', 0, 0, 'L', 0);
    $pdf->Cell(($colPetugas*2), 5, 'Kepala Sekolah', 0, 1, 'C', 0);
    
    $pdf->Ln(15); // Space for signature
    
    $pdf->Cell(($colNo + $colTanggal + $colWaktu*2), 5, '', 0, 0, 'L', 0);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(($colPetugas*2), 5, '____________________', 0, 1, 'C', 0);
    
    // Add footer with page number
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(0, 10, 'Halaman ' . $pdf->getAliasNumPage() . ' dari ' . $pdf->getAliasNbPages(), 0, 1, 'C');
    
    // Close and output PDF
    $pdf->Output('rekap_absensi.pdf', 'D');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absen - SCHOBANK SYSTEM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            line-height: 1.6;
        }

        .main-content {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 20px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .transfer-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-verify, .btn-back {
            flex: 1;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-verify {
            background-color: var(--primary-color);
        }

        .btn-back {
            background-color: #6c757d;
        }

        .actions-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            gap: 10px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        table th, table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }

        table th {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 15px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: var(--text-primary);
            border-radius: 4px;
        }

        .pagination .active {
            background-color: var(--primary-color);
            color: white;
        }

        @media screen and (max-width: 768px) {
            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .actions-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .export-buttons {
                flex-direction: column;
            }

            .filter-buttons {
                flex-direction: column;
            }

            table {
                font-size: 12px;
            }

            table th, table td {
                padding: 6px;
            }

            .pagination {
                flex-wrap: wrap;
            }
        }
        .export-buttons {
            display: flex;
            gap: 10px;
        }

        .btn-export {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }

        .btn-export-excel {
            background-color:rgb(22, 9, 86);
            color: white;
        }

        .btn-export-excel:hover {
            background-color:rgb(22, 9, 86);
            transform: translateY(-2px);
        }

        .btn-export-pdf {
            background-color:rgb(22, 9, 86);
            color: white;
        }

        .btn-export-pdf:hover {
            background-color:rgb(22, 9, 86);
            transform: translateY(-2px);
        }

        /* Responsive adjustments for export buttons */
        @media screen and (max-width: 768px) {
            .export-buttons {
                flex-direction: column;
            }

            .btn-export {
                width: 100%;
            }
        }
        </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-calendar-alt"></i> Rekap Absensi Petugas</h2>
            <p>Lihat dan kelola data absensi petugas dengan mudah</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <div class="transfer-card">
            <form method="get" action="" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Tanggal Mulai:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="form-group">
                    <label for="end_date">Tanggal Akhir:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                                
                <div class="form-group filter-buttons">
                    <button type="submit" class="btn-verify">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    
                    <a href="rekap_absen.php" class="btn-back">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <div class="actions-bar">
            <div class="count-info">
                Menampilkan <?php echo $result->num_rows; ?> dari <?php echo $totalRows; ?> total data
            </div>
            
            <div class="export-buttons">
                <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=excel'; ?>" class="btn-export btn-export-excel">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                
                <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=pdf'; ?>" class="btn-export btn-export-pdf">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
            </div>
        </div>
        
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>No</th>
                        <th>Nama Petugas</th>
                        <th>Tanggal</th>
                        <th>Waktu Masuk</th>
                        <th>Waktu Keluar</th>
                        <th>Petugas 1</th>
                        <th>Petugas 2</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php $no = $offset + 1; ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($row['nama']); ?></td>
                                <td><?php echo htmlspecialchars($row['tanggal']); ?></td>
                                <td><?php echo htmlspecialchars($row['waktu_masuk'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['waktu_keluar'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas1_nama'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['petugas2_nama'] ?? '-'); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center;">Tidak ada data absensi yang ditemukan.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?><?php echo !empty($petugas) ? '&petugas='.$petugas : ''; ?><?php echo !empty($search) ? '&search='.$search : ''; ?>" aria-label="Halaman pertama">
                        <i class="fas fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?php echo $page-1; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?><?php echo !empty($petugas) ? '&petugas='.$petugas : ''; ?><?php echo !empty($search) ? '&search='.$search : ''; ?>" aria-label="Halaman sebelumnya">
                        <i class="fas fa-angle-left"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-angle-double-left"></i></span>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-angle-left"></i></span>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                // Always show at least 5 pages if available
                if ($endPage - $startPage < 4 && $totalPages > 4) {
                    if ($startPage == 1) {
                        $endPage = min(5, $totalPages);
                    } elseif ($endPage == $totalPages) {
                        $startPage = max(1, $totalPages - 4);
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="active" aria-current="page"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="?page=<?php echo $i; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?><?php echo !empty($petugas) ? '&petugas='.$petugas : ''; ?><?php echo !empty($search) ? '&search='.$search : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?><?php echo !empty($petugas) ? '&petugas='.$petugas : ''; ?><?php echo !empty($search) ? '&search='.$search : ''; ?>" aria-label="Halaman berikutnya">
                        <i class="fas fa-angle-right"></i>
                    </a>
                    <a href="?page=<?php echo $totalPages; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?><?php echo !empty($petugas) ? '&petugas='.$petugas : ''; ?><?php echo !empty($search) ? '&search='.$search : ''; ?>" aria-label="Halaman terakhir">
                        <i class="fas fa-angle-double-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-angle-right"></i></span>
                    <span class="disabled" aria-hidden="true"><i class="fas fa-angle-double-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Get the table container
    const tableContainer = document.querySelector('.table-responsive');
    
    // Add touch event handlers for mobile scrolling
    if ('ontouchstart' in document.documentElement) {
        let startX, startY, startScrollLeft, startScrollTop;
        let isScrolling = false;
        
        // Touch start event
        tableContainer.addEventListener('touchstart', function(e) {
            isScrolling = true;
            startX = e.touches[0].pageX;
            startY = e.touches[0].pageY;
            startScrollLeft = tableContainer.scrollLeft;
            startScrollTop = tableContainer.scrollTop;
        }, { passive: true });
        
        // Touch move event
        tableContainer.addEventListener('touchmove', function(e) {
            if (!isScrolling) return;
            
            // Prevent default only if we're scrolling horizontally
            const touchX = e.touches[0].pageX;
            const touchY = e.touches[0].pageY;
            const diffX = startX - touchX;
            const diffY = startY - touchY;
            
            // If horizontal scrolling is more prominent than vertical scrolling
            if (Math.abs(diffX) > Math.abs(diffY)) {
                e.preventDefault();
            }
            
            // Update the scroll position
            tableContainer.scrollLeft = startScrollLeft + diffX;
            tableContainer.scrollTop = startScrollTop + diffY;
        }, { passive: false });
        
        // Touch end event
        tableContainer.addEventListener('touchend', function() {
            isScrolling = false;
        }, { passive: true });
        
        // Touch cancel event
        tableContainer.addEventListener('touchcancel', function() {
            isScrolling = false;
        }, { passive: true });
    }
    
    // Add visual indicator for scrollable table on mobile
    function addScrollIndicators() {
        const isMobile = window.innerWidth <= 768;
        
        if (isMobile) {
            // Check if table is wider than its container
            const tableWidth = tableContainer.querySelector('table').offsetWidth;
            const containerWidth = tableContainer.offsetWidth;
            
            if (tableWidth > containerWidth) {
                // Add scroll indicators if not already present
                if (!document.querySelector('.scroll-indicator')) {
                    const leftIndicator = document.createElement('div');
                    leftIndicator.className = 'scroll-indicator scroll-left';
                    leftIndicator.innerHTML = '<i class="fas fa-chevron-left"></i>';
                    
                    const rightIndicator = document.createElement('div');
                    rightIndicator.className = 'scroll-indicator scroll-right';
                    rightIndicator.innerHTML = '<i class="fas fa-chevron-right"></i>';
                    
                    tableContainer.parentNode.insertBefore(leftIndicator, tableContainer);
                    tableContainer.parentNode.insertBefore(rightIndicator, tableContainer.nextSibling);
                    
                    // Add CSS for indicators
                    const style = document.createElement('style');
                    style.textContent = `
                        .scroll-indicator {
                            position: absolute;
                            top: 50%;
                            transform: translateY(-50%);
                            background-color: rgba(52, 152, 219, 0.7);
                            color: white;
                            width: 30px;
                            height: 30px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            cursor: pointer;
                            z-index: 100;
                            opacity: 0.7;
                            transition: opacity 0.3s;
                        }
                        .scroll-indicator:hover {
                            opacity: 1;
                        }
                        .scroll-left {
                            left: 5px;
                        }
                        .scroll-right {
                            right: 5px;
                        }
                        .table-responsive {
                            position: relative;
                        }
                    `;
                    document.head.appendChild(style);
                    
                    // Add click handlers for indicators
                    leftIndicator.addEventListener('click', function() {
                        tableContainer.scrollBy({ left: -100, behavior: 'smooth' });
                    });
                    
                    rightIndicator.addEventListener('click', function() {
                        tableContainer.scrollBy({ left: 100, behavior: 'smooth' });
                    });
                    
                    // Update indicator visibility on scroll
                    tableContainer.addEventListener('scroll', updateIndicatorVisibility);
                    
                    // Initial update
                    updateIndicatorVisibility();
                }
            }
        } else {
            // Remove indicators on desktop
            const indicators = document.querySelectorAll('.scroll-indicator');
            indicators.forEach(indicator => indicator.remove());
        }
    }
    
    // Update indicator visibility based on scroll position
    function updateIndicatorVisibility() {
        const leftIndicator = document.querySelector('.scroll-left');
        const rightIndicator = document.querySelector('.scroll-right');
        
        if (leftIndicator && rightIndicator) {
            leftIndicator.style.opacity = tableContainer.scrollLeft > 10 ? '0.7' : '0';
            rightIndicator.style.opacity = 
                (tableContainer.scrollWidth - tableContainer.scrollLeft - tableContainer.clientWidth) > 10 ? '0.7' : '0';
        }
    }
    
    // Initialize indicators
    addScrollIndicators();
    
    // Update on window resize
    window.addEventListener('resize', function() {
        addScrollIndicators();
    });
    
    // Add swipe animation to hint at scrollability
    function addScrollHint() {
        if (window.innerWidth <= 768) {
            const tableWidth = tableContainer.querySelector('table').offsetWidth;
            const containerWidth = tableContainer.offsetWidth;
            
            if (tableWidth > containerWidth && tableContainer.scrollLeft === 0) {
                // Add a brief auto-scroll to indicate scrollability
                setTimeout(() => {
                    tableContainer.scrollTo({
                        left: 30,
                        behavior: 'smooth'
                    });
                    
                    setTimeout(() => {
                        tableContainer.scrollTo({
                            left: 0,
                            behavior: 'smooth'
                        });
                    }, 700);
                }, 1000);
            }
        }
    }
    
    // Run the hint animation when the page loads
    setTimeout(addScrollHint, 500);
    
    // Adjust table and headers for better mobile display
    function optimizeTableForMobile() {
        if (window.innerWidth <= 480) {
            const tableHeaders = tableContainer.querySelectorAll('th');
            const tableCells = tableContainer.querySelectorAll('td');
            
            // Make some columns narrower on mobile
            if (tableHeaders.length >= 5) {
                tableHeaders[0].style.width = '40px'; // No column
                tableHeaders[2].style.width = '80px'; // Date column
                tableHeaders[3].style.width = '70px'; // Time in
                tableHeaders[4].style.width = '70px'; // Time out
            }
            
            // Add this class to enable horizontal scrolling specifically for the table
            tableContainer.classList.add('mobile-table-scroll');
            
            // Add CSS for mobile optimization
            if (!document.getElementById('mobile-table-styles')) {
                const style = document.createElement('style');
                style.id = 'mobile-table-styles';
                style.textContent = `
                    @media (max-width: 480px) {
                        .mobile-table-scroll {
                            -webkit-overflow-scrolling: touch;
                            scroll-snap-type: x proximity;
                        }
                        .mobile-table-scroll table {
                            min-width: 500px;
                        }
                        .mobile-table-scroll th, .mobile-table-scroll td {
                            white-space: nowrap;
                            overflow: hidden;
                            text-overflow: ellipsis;
                            max-width: 120px;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        } else {
            // Remove mobile-specific styles on larger screens
            const mobileStyles = document.getElementById('mobile-table-styles');
            if (mobileStyles) {
                mobileStyles.remove();
            }
            tableContainer.classList.remove('mobile-table-scroll');
        }
    }
    
    // Initialize mobile optimization
    optimizeTableForMobile();
    
    // Update on window resize
    window.addEventListener('resize', function() {
        optimizeTableForMobile();
    });
});
    </script>
</body>
</html>