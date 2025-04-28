<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Prevent any output before PDF generation
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Validate and sanitize inputs
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) 
    ? $_GET['start_date'] 
    : date('Y-m-01');
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) 
    ? $_GET['end_date'] 
    : date('Y-m-d');
$format = isset($_GET['format']) && in_array($_GET['format'], ['pdf', 'excel']) 
    ? $_GET['format'] 
    : 'pdf';

// Ensure start_date <= end_date
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Query for transaction details (no no_rekening, no status)
$query = "SELECT 
            t.id,
            t.jenis_transaksi,
            t.jumlah,
            t.created_at,
            u.nama as nama_siswa,
            COALESCE(p.nama, 'Admin') as nama_petugas,
            COALESCE(k.nama_kelas, '-') as kelas,
            COALESCE(j.nama_jurusan, '-') as jurusan
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN users p ON t.petugas_id = p.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
          AND t.jenis_transaksi IN ('setor', 'tarik')
          AND (
              t.petugas_id IS NULL
              OR (t.petugas_id IS NOT NULL AND p.role = 'petugas')
          )
          ORDER BY t.created_at DESC";
try {
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    exit('Terjadi kesalahan saat mengambil data transaksi.');
}

// Calculate totals
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN t.jenis_transaksi = 'setor' THEN t.jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN t.jenis_transaksi = 'tarik' AND t.status = 'approved' THEN t.jumlah ELSE 0 END) as total_penarikan
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users p ON t.petugas_id = p.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND (
        t.petugas_id IS NULL
        OR (t.petugas_id IS NOT NULL AND p.role = 'petugas')
    )";
try {
    $stmt_total = $conn->prepare($total_query);
    $stmt_total->bind_param("ss", $start_date, $end_date);
    $stmt_total->execute();
    $totals_result = $stmt_total->get_result();
    $totals = $totals_result->fetch_assoc();
    
    // Handle null values
    $totals['total_transactions'] = $totals['total_transactions'] ?? 0;
    $totals['total_setoran'] = $totals['total_setoran'] ?? 0;
    $totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
    $totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];
} catch (Exception $e) {
    $totals = ['total_transactions' => 0, 'total_setoran' => 0, 'total_penarikan' => 0, 'total_net' => 0];
}

// Store transactions in array
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $transactions[] = $row;
}

// If PDF format is requested
if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        protected $footer_text = '';
        
        public function setFooterText($text) {
            $this->footer_text = $text;
        }
        
        public function Header() {
            // Logo
            $image_file = '../../assets/images/logo.png';
            if (file_exists($image_file)) {
                $this->Image($image_file, 10, 8, 20, '', 'PNG');
            }
            
            // Header text
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 10, 'Sistem Bank Mini Sekolah', 0, false, 'C', 0);
            $this->Ln(5);
            $this->SetFont('helvetica', '', 8);
            $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A 43212 Cianjur Jawa Barat', 0, false, 'C', 0);
            $this->Line(10, 32, 200, 32);
        }
    }

    // Use output buffering
    ob_start();
    
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Rekapitulasi Transaksi');
    
    // Set equal margins
    $pdf->SetMargins(10, 40, 10);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, 15);
    
    $pdf->AddPage();

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'REKAPITULASI TRANSAKSI', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    $totalWidth = 190; // 210mm page width - 10mm left - 10mm right

    // Summary section
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    
    $colWidth = $totalWidth / 4;
    
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($colWidth, 6, 'Total Transaksi', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Setoran', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Penarikan', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Saldo Bersih', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell($colWidth, 6, $totals['total_transactions'], 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'R');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'R');
    
    $netColor = $totals['total_net'] >= 0 ? [220, 252, 231] : [254, 226, 226];
    $pdf->SetFillColor($netColor[0], $netColor[1], $netColor[2]);
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'R', true);

    // Transaction details section
    $pdf->Ln(8);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    // Custom column widths for 7 columns (adjusted to fit 190mm)
    $colWidths = [
        'no' => 12,        // No (6.3%)
        'tanggal' => 22,   // Tanggal (11.6%)
        'nama_siswa' => 48, // Nama Siswa (25.3%)
        'kelas' => 22,     // Kelas (11.6%)
        'petugas' => 38,   // Petugas (20.0%)
        'jenis' => 18,     // Jenis (9.5%)
        'jumlah' => 30     // Jumlah (15.8%)
    ];

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $header_heights = 6;
    $pdf->Cell($colWidths['no'], $header_heights, 'No', 1, 0, 'C', true);
    $pdf->Cell($colWidths['tanggal'], $header_heights, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($colWidths['nama_siswa'], $header_heights, 'Nama Siswa', 1, 0, 'C', true);
    $pdf->Cell($colWidths['kelas'], $header_heights, 'Kelas', 1, 0, 'C', true);
    $pdf->Cell($colWidths['petugas'], $header_heights, 'Petugas', 1, 0, 'C', true);
    $pdf->Cell($colWidths['jenis'], $header_heights, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($colWidths['jumlah'], $header_heights, 'Jumlah', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $row_height = 5;
    
    $total_net = 0;
    
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $typeColor = [255, 255, 255];
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $typeColor = [220, 252, 231];
                $displayType = 'Setoran';
                $total_net += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $typeColor = [254, 226, 226];
                $displayType = 'Penarikan';
                $total_net -= $row['jumlah'];
            }
            
            $pdf->Cell($colWidths['no'], $row_height, $no++, 1, 0, 'C');
            $pdf->Cell($colWidths['tanggal'], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C');
            $pdf->Cell($colWidths['nama_siswa'], $row_height, $row['nama_siswa'] ?? 'N/A', 1, 0, 'L');
            $pdf->Cell($colWidths['kelas'], $row_height, $row['kelas'], 1, 0, 'L');
            $pdf->Cell($colWidths['petugas'], $row_height, $row['nama_petugas'] ?? 'Admin', 1, 0, 'L');
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($colWidths['jenis'], $row_height, $displayType, 1, 0, 'C', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($colWidths['jumlah'], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R');
        }
        
        // Total row
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell($colWidths['no'] + $colWidths['tanggal'] + $colWidths['nama_siswa'] + $colWidths['kelas'] + $colWidths['petugas'] + $colWidths['jenis'], $row_height, 'Total', 1, 0, 'R', true);
        $pdf->Cell($colWidths['jumlah'], $row_height, 'Rp ' . number_format($total_net, 0, ',', '.'), 1, 1, 'R', true);
    } else {
        $pdf->Cell($totalWidth, $row_height, 'Tidak ada transaksi dalam periode ini.', 1, 1, 'C');
    }

    // Clean the output buffer
    ob_end_clean();
    
    // Output the PDF
    $pdf->Output('rekap_transaksi_' . $start_date . '_' . $end_date . '.pdf', 'D');
    exit;
}

// If Excel format is requested
elseif ($format === 'excel') {
    ob_start();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="rekap_transaksi_' . $start_date . '_to_' . $end_date . '.xls"');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>
            body { font-family: Arial, sans-serif; font-size: 10pt; margin: 10px; }
            .text-left { text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            td, th { padding: 6px; border: 1px solid #000; }
            .title { font-size: 14pt; font-weight: bold; text-align: center; padding: 8px; }
            .subtitle { font-size: 10pt; text-align: center; padding: 5px; }
            .school-info { font-size: 8pt; text-align: center; padding-bottom: 10px; }
            .section-header { background-color: #000000; color: #ffffff; font-weight: bold; text-align: center; padding: 6px; }
            .sum-header { background-color: #f0f0f0; font-weight: bold; text-align: center; }
            .positive { background-color: #dcfce7; }
            .negative { background-color: #fee2e2; }
            table.main-table { 
                width: 100%; 
                max-width: 100%; 
                border-collapse: collapse; 
                margin: 10px auto; 
                box-sizing: border-box; 
            }
            th.col-no, td.col-no { width: 5%; }
            th.col-tanggal, td.col-tanggal { width: 10%; }
            th.col-nama, td.col-nama { width: 30%; }
            th.col-kelas, td.col-kelas { width: 15%; }
            th.col-petugas, td.col-petugas { width: 25%; }
            th.col-jenis, td.col-jenis { width: 10%; }
            th.col-jumlah, td.col-jumlah { width: 15%; }
            .table-footer { font-weight: bold; background-color: #f0f0f0; }
            .spacer { height: 15px; }
            .content { max-width: 1200px; margin: 0 auto; }
          </style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="content">';

    // Header section
    echo '<table class="main-table" border="0" cellpadding="3">';
    echo '<tr><td colspan="7" class="title">SCHOBANK</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Sistem Bank Mini Sekolah</td></tr>';
    echo '<tr><td colspan="7" class="school-info">Jl. K.H. Saleh No.57A 43212 Cianjur Jawa Barat</td></tr>';
    echo '<tr><td colspan="7" class="title">REKAPITULASI TRANSAKSI</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)) . '</td></tr>';
    echo '</table>';
    
    // Spacer
    echo '<div class="spacer"></div>';

    // Summary section
    echo '<table class="main-table" border="1" cellpadding="6">';
    echo '<tr><th colspan="4" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    
    echo '<tr class="sum-header">';
    echo '<th>Total Transaksi</th>';
    echo '<th>Total Setoran</th>';
    echo '<th>Total Penarikan</th>';
    echo '<th>Saldo Bersih</th>';
    echo '</tr>';
    
    $netClass = $totals['total_net'] >= 0 ? 'positive' : 'negative';
    echo '<tr>';
    echo '<td class="text-center">' . $totals['total_transactions'] . '</td>';
    echo '<td class="text-right">Rp ' . number_format($totals['total_setoran'], 0, ',', '.') . '</td>';
    echo '<td class="text-right">Rp ' . number_format($totals['total_penarikan'], 0, ',', '.') . '</td>';
    echo '<td class="text-right ' . $netClass . '">Rp ' . number_format($totals['total_net'], 0, ',', '.') . '</td>';
    echo '</tr>';
    echo '</table>';

    // Spacer
    echo '<div class="spacer"></div>';

    // Detail section
    echo '<table class="main-table" border="1" cellpadding="6">';
    echo '<tr class="section-header">';
    echo '<th colspan="7">DETAIL TRANSAKSI</th>';
    echo '</tr>';
    
    echo '<tr class="sum-header">';
    echo '<th class="col-no">No</th>';
    echo '<th class="col-tanggal">Tanggal</th>';
    echo '<th class="col-nama">Nama Siswa</th>';
    echo '<th class="col-kelas">Kelas</th>';
    echo '<th class="col-petugas">Petugas</th>';
    echo '<th class="col-jenis">Jenis</th>';
    echo '<th class="col-jumlah">Jumlah</th>';
    echo '</tr>';
    
    $total_net = 0;
    
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $bgColor = '#FFFFFF';
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $bgColor = '#dcfce7';
                $displayType = 'Setoran';
                $total_net += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $bgColor = '#fee2e2';
                $displayType = 'Penarikan';
                $total_net -= $row['jumlah'];
            }
            
            echo '<tr>';
            echo '<td class="text-center col-no">' . $no++ . '</td>';
            echo '<td class="text-center col-tanggal">' . date('d/m/Y', strtotime($row['created_at'])) . '</td>';
            echo '<td class="text-left col-nama">' . htmlspecialchars($row['nama_siswa']) . '</td>';
            echo '<td class="text-left col-kelas">' . htmlspecialchars($row['kelas']) . '</td>';
            echo '<td class="text-left col-petugas">' . htmlspecialchars($row['nama_petugas']) . '</td>';
            echo '<td class="text-center col-jenis" bgcolor="' . $bgColor . '">' . $displayType . '</td>';
            echo '<td class="text-right col-jumlah">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
        
        // Total row
        echo '<tr class="table-footer">';
        echo '<td colspan="6" class="text-right">Total</td>';
        echo '<td class="text-right">Rp ' . number_format($total_net, 0, ',', '.') . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="7" class="text-center">Tidak ada transaksi dalam periode ini.</td></tr>';
    }
    
    // Verify totals consistency
    if (abs($totals['total_net'] - $total_net) > 0) {
        // Log discrepancy (optional)
        // error_log("Net total discrepancy detected: Summary net={$totals['total_net']}, Detail net={$total_net}");
    }
    
    echo '</table>';
    
    echo '</div>';
    echo '</body></html>';
    
    ob_end_flush();
    exit;
}
?>