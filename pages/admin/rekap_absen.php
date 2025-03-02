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
$baseQuery = "SELECT a.*, u.nama FROM absensi a JOIN users u ON a.user_id = u.id";
$countQuery = "SELECT COUNT(*) as total FROM absensi a JOIN users u ON a.user_id = u.id";

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
    $exportQuery = "SELECT a.*, u.nama FROM absensi a JOIN users u ON a.user_id = u.id";
    
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

// Function to export data to Excel
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
            table { border-collapse: collapse; width: 100%; }
            th, td { border: 1px solid #000; padding: 8px; text-align: center; }
            th { background-color: #f2f2f2; }
        </style>
    </head>
    <body>
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Nama Petugas</th>
                    <th>Tanggal</th>
                    <th>Waktu Masuk</th>
                    <th>Waktu Keluar</th>
                </tr>
            </thead>
            <tbody>';
            
    $no = 1;
    while ($row = $result->fetch_assoc()) {
        echo '<tr>
                <td>' . $no++ . '</td>
                <td>' . htmlspecialchars($row['nama']) . '</td>
                <td>' . htmlspecialchars($row['tanggal']) . '</td>
                <td>' . htmlspecialchars($row['waktu_masuk'] ?? '-') . '</td>
                <td>' . htmlspecialchars($row['waktu_keluar'] ?? '-') . '</td>
            </tr>';
    }
            
    echo '</tbody>
        </table>
    </body>
    </html>';
}

// Function to export data to PDF with improved styling
function exportToPDF($result) {
    // Require the TCPDF library
    require_once '../../tcpdf/tcpdf.php';
    
    // Create new TCPDF object
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    
    // Set document information
    $pdf->SetCreator('Mini Bank Sekolah');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Rekap Absensi');
    $pdf->SetSubject('Rekap Absensi Petugas');
    
    // Set margins
    $pdf->SetMargins(15, 20, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    // Add a page
    $pdf->AddPage();
    
    // Create a colorful header
    $pdf->SetFillColor(52, 152, 219); // Blue header background
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 15, 'REKAP ABSENSI PETUGAS', 0, 1, 'C', 1);
    
    // Add current date
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->SetTextColor(100, 100, 100); // Gray text
    $pdf->Cell(0, 8, 'Tanggal Cetak: ' . date('d-m-Y'), 0, 1, 'R');
    $pdf->Ln(5);
    
    // Table header
    $pdf->SetFillColor(41, 128, 185); // Slightly darker blue for header
    $pdf->SetTextColor(255, 255, 255); // White text
    $pdf->SetDrawColor(200, 200, 200); // Light gray borders
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('helvetica', 'B', 11);
    
    $pdf->Cell(10, 10, 'No', 1, 0, 'C', 1);
    $pdf->Cell(50, 10, 'Nama Petugas', 1, 0, 'C', 1);
    $pdf->Cell(40, 10, 'Tanggal', 1, 0, 'C', 1);
    $pdf->Cell(40, 10, 'Waktu Masuk', 1, 0, 'C', 1);
    $pdf->Cell(40, 10, 'Waktu Keluar', 1, 1, 'C', 1);
    
    // Table data
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0); // Black text
    
    $no = 1;
    $row_color = false; // For alternating row colors
    
    while ($row = $result->fetch_assoc()) {
        // Alternating row colors
        if ($row_color) {
            $pdf->SetFillColor(240, 248, 255); // Light blue for even rows
        } else {
            $pdf->SetFillColor(255, 255, 255); // White for odd rows
        }
        
        $pdf->Cell(10, 8, $no++, 1, 0, 'C', 1);
        $pdf->Cell(50, 8, $row['nama'], 1, 0, 'L', 1);
        $pdf->Cell(40, 8, $row['tanggal'], 1, 0, 'C', 1);
        $pdf->Cell(40, 8, $row['waktu_masuk'] ?? '-', 1, 0, 'C', 1);
        $pdf->Cell(40, 8, $row['waktu_keluar'] ?? '-', 1, 1, 'C', 1);
        
        $row_color = !$row_color; // Toggle for next row
    }
    
    // Add footer with page number
    $pdf->Ln(10);
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
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #f39c12;
            --danger-color: #e74c3c;
            --text-color: #333;
            --light-bg: #f8f9fa;
            --border-radius: 8px;
            --shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f2f7ff;
            margin: 0;
            padding: 0;
            color: var(--text-color);
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            padding: 20px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        
        h1 {
            color: var(--primary-color);
            text-align: center;
            margin-bottom: 20px;
            font-size: 28px;
        }
        
        .card {
            background-color: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
        }
        
        .btn-success {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #27ae60;
        }
        
        .btn-warning {
            background-color: var(--accent-color);
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
        }
        
        .btn-default {
            background-color: #95a5a6;
            color: white;
        }
        
        .btn-default:hover {
            background-color: #7f8c8d;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }
        
        th {
            background-color: var(--light-bg);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 1px 0 #ddd;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        tr:hover {
            background-color: #f1f1f1;
        }
        
        .table-responsive {
            overflow-x: auto;
            box-shadow: var(--shadow);
            border-radius: var(--border-radius);
            background: white;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 14px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: var(--primary-color);
            background-color: white;
            border-radius: var(--border-radius);
            min-width: 16px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .pagination a:hover {
            background-color: var(--light-bg);
        }
        
        .pagination .active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination .disabled {
            color: #aaa;
            cursor: not-allowed;
        }
        
        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .count-info {
            margin-bottom: 10px;
            font-style: italic;
            color: #666;
        }
        
        .header-with-close {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-box {
            position: relative;
            width: 100%;
        }
        
        .search-box input {
            padding-left: 35px;
            width: 100%;
            box-sizing: border-box;
        }
        
        .search-box i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        /* Mobile Responsive Styles */
        @media (max-width: 768px) {
            h1 {
                font-size: 22px;
                margin-bottom: 15px;
                width: 100%;
            }
            
            .header-with-close {
                justify-content: center;
            }
            
            .btn-close {
                margin-left: auto;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .form-group {
                margin-bottom: 0;
            }
            
            .actions-bar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .count-info {
                width: 100%;
                text-align: center;
                margin-bottom: 12px;
            }
            
            .export-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .export-buttons .btn {
                flex: 1;
                padding: 8px 10px;
                font-size: 13px;
            }
            
            .pagination {
                gap: 4px;
            }
            
            .pagination a, .pagination span {
                padding: 6px 10px;
                font-size: 13px;
            }
            
            .filter-buttons {
                width: 100%;
                justify-content: space-between;
            }
            
            .filter-buttons .btn {
                flex: 1;
                white-space: nowrap;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 10px;
            }
            
            .card {
                padding: 15px;
            }
            
            .btn {
                padding: 8px 12px;
                font-size: 13px;
            }
            
            th, td {
                padding: 8px 5px;
                font-size: 13px;
            }
            
            .form-control {
                padding: 8px 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-with-close">
            <h1>Rekap Absensi Petugas</h1>
            <a href="dashboard.php" class="btn btn-danger btn-close">
                <i class="fas fa-sign-out-alt"></i> 
            </a>
        </div>
        
        <div class="card">
            <form method="get" action="" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Tanggal Mulai:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="form-group">
                    <label for="end_date">Tanggal Akhir:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                                
                <div class="form-group">
                    <label for="search">Cari Nama Petugas:</label>
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="search" name="search" class="form-control" placeholder="Ketik nama petugas..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="form-group filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    
                    <a href="rekap_absen.php" class="btn btn-default">
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
                <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=excel'; ?>" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                
                <a href="<?php echo $_SERVER['REQUEST_URI'] . (strpos($_SERVER['REQUEST_URI'], '?') !== false ? '&' : '?') . 'export=pdf'; ?>" class="btn btn-warning">
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
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">Tidak ada data absensi yang ditemukan.</td>
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