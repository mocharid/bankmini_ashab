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
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN a.waktu_masuk ELSE NULL END) AS petugas1_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas1' THEN a.waktu_keluar ELSE NULL END) AS petugas1_keluar,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN a.waktu_masuk ELSE NULL END) AS petugas2_masuk,
              MAX(CASE WHEN a.petugas_type = 'petugas2' THEN a.waktu_keluar ELSE NULL END) AS petugas2_keluar
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
            $pdf->Cell(25, 6, formatDate($row['tanggal']), 'LTR', 0, 'C'); // Format date
            $pdf->Cell(65, 6, $row['petugas1_nama'] ?? '-', 1, 0, 'L');
            $pdf->Cell(40, 6, $row['petugas1_masuk'] ?? '-', 1, 0, 'C');
            $pdf->Cell(40, 6, $row['petugas1_keluar'] ?? '-', 1, 1, 'C');
            
            // Second row - Petugas 2
            $pdf->Cell(10, 6, '', 'LBR', 0, 'C');
            $pdf->Cell(25, 6, '', 'LBR', 0, 'C');
            $pdf->Cell(65, 6, $row['petugas2_nama'] ?? '-', 1, 0, 'L');
            $pdf->Cell(40, 6, $row['petugas2_masuk'] ?? '-', 1, 0, 'C');
            $pdf->Cell(40, 6, $row['petugas2_keluar'] ?? '-', 1, 1, 'C');
            
            $no++; // Increment only once per date
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
            echo '<td class="text-center" rowspan="2">' . formatDate($row['tanggal']) . '</td>'; // Format date
            echo '<td class="text-left">' . ($row['petugas1_nama'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas1_masuk'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas1_keluar'] ?? '-') . '</td>';
            echo '</tr>';
            
            // For petugas 2
            echo '<tr>';
            // No need to repeat the number and date cells as they use rowspan="2"
            echo '<td class="text-left">' . ($row['petugas2_nama'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas2_masuk'] ?? '-') . '</td>';
            echo '<td class="text-center">' . ($row['petugas2_keluar'] ?? '-') . '</td>';
            echo '</tr>';
            
            $no++; // Increment only once per date
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absen - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background: #0a2e5c;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(10, 46, 92, 0.15);
            position: relative;
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .close-btn {
            position: absolute;
            top: 15px;
            right: 15px;
            background: none;
            border: none;
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .close-btn:hover {
            transform: scale(1.2);
        }

        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            position: relative;
        }

        .filters {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 20px;
        }

        .filters form {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
        }

        .filters input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .filters button {
            padding: 8px 16px;
            background-color: #0a2e5c;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.3s;
        }

        .filters button:hover {
            background-color: #093b7a;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .export-buttons a {
            padding: 8px 16px;
            background-color: #0a2e5c;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background-color 0.3s;
        }

        .export-buttons a:hover {
            background-color: #154785;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            background: white;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #f8fafc;
            color: #0a2e5c;
            font-weight: 600;
            white-space: nowrap;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination-btn {
            background: #0a2e5c;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .pagination-btn:hover {
            background: #154785;
        }

        .pagination-btn.active {
            background: #154785;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
            }

            .nav-buttons {
                gap: 10px;
            }

            .main-content {
                padding: 15px;
            }

            .filters form {
                flex-direction: column;
                align-items: flex-start;
            }

            .filters input {
                width: 100%;
            }

            .export-buttons {
                flex-direction: column;
            }

            .export-buttons a {
                width: 100%;
                justify-content: center;
            }

            th, td {
                padding: 10px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-calendar-alt"></i> Rekap Absensi Petugas</h2>
            <p>Rekap absensi petugas berdasarkan tanggal</p>
            <button class="close-btn" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="report-card">
            <div class="filters">
                <form method="GET" action="">
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>" placeholder="Tanggal Mulai">
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>" placeholder="Tanggal Selesai">
                    <button type="submit">Filter</button>
                </form>
            </div>

            <div class="export-buttons">
                <a href="?export=pdf<?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>">
                    <i class="fas fa-file-pdf"></i> Unduh PDF
                </a>
                <a href="?export=excel<?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>">
                    <i class="fas fa-file-excel"></i> Unduh Excel
                </a>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 5%;">No</th>
                            <th style="width: 15%;">Tanggal</th>
                            <th style="width: 40%;">Nama Petugas</th>
                            <th style="width: 20%;">Jam Masuk</th>
                            <th style="width: 20%;">Jam Keluar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php $no = $offset + 1; ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td rowspan="2" data-label="No"><?php echo $no++; ?></td>
                                    <td rowspan="2" data-label="Tanggal"><?php echo formatDate($row['tanggal']); ?></td>
                                    <td data-label="Nama Petugas"><?php echo htmlspecialchars($row['petugas1_nama'] ?? '-'); ?></td>
                                    <td data-label="Jam Masuk"><?php echo htmlspecialchars($row['petugas1_masuk'] ?? '-'); ?></td>
                                    <td data-label="Jam Keluar"><?php echo htmlspecialchars($row['petugas1_keluar'] ?? '-'); ?></td>
                                </tr>
                                <tr>
                                    <td data-label="Nama Petugas"><?php echo htmlspecialchars($row['petugas2_nama'] ?? '-'); ?></td>
                                    <td data-label="Jam Masuk"><?php echo htmlspecialchars($row['petugas2_masuk'] ?? '-'); ?></td>
                                    <td data-label="Jam Keluar"><?php echo htmlspecialchars($row['petugas2_keluar'] ?? '-'); ?></td>
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
                        <a href="?page=1<?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>" class="pagination-btn">Pertama</a>
                        <a href="?page=<?php echo $page-1; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>" class="pagination-btn">Sebelumnya</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <?php if ($i == $page): ?>
                            <span class="pagination-btn active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>" class="pagination-btn"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page+1; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>" class="pagination-btn">Selanjutnya</a>
                        <a href="?page=<?php echo $totalPages; ?><?php echo !empty($start_date) ? '&start_date='.$start_date : ''; ?><?php echo !empty($end_date) ? '&end_date='.$end_date : ''; ?>" class="pagination-btn">Terakhir</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>