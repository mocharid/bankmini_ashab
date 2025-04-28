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

// Get parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Query for transaction details
$query = "SELECT 
            t.id,
            t.jenis_transaksi,
            t.jumlah,
            t.status,
            t.created_at,
            u.nama as nama_siswa,
            u.no_rekening as no_rekening_siswa,
            r.no_rekening as no_rekening_db,
            COALESCE(k.nama_kelas, '-') as kelas,
            COALESCE(j.nama_jurusan, '-') as jurusan
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
          AND t.jenis_transaksi IN ('setor', 'tarik')
          AND t.petugas_id IS NULL
          ORDER BY t.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Calculate totals
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NULL";
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();

// Handle null values in totals array
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran'] = $totals['total_setoran'] ?? 0;
$totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
$totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];

// Store transactions in array
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $row['no_rekening'] = !empty($row['no_rekening_siswa']) ? $row['no_rekening_siswa'] : 
                         (!empty($row['no_rekening_db']) ? $row['no_rekening_db'] : 'N/A'); 
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
                $this->Image($image_file, 15, 5, 20, '', 'PNG');
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
            $this->Line(15, 30, 195, 30);
        }
    }

    // Use output buffering to prevent any output before PDF generation
    ob_start();
    
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Rekapan Transaksi Admin');
    
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    $pdf->AddPage();

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'REKAPAN TRANSAKSI ADMIN', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    $totalWidth = 180;

    // Summary section
    $pdf->Ln(3);
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
    $pdf->Cell($colWidth, 6, 'Total Bersih', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell($colWidth, 6, $totals['total_transactions'], 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'C');
    
    $netColor = $totals['total_net'] >= 0 ? [220, 252, 231] : [254, 226, 226];
    $pdf->SetFillColor($netColor[0], $netColor[1], $netColor[2]);
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'C', true);

    // Transaction details section
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $col_width = array(10, 25, 25, 35, 25, 35, 25);

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $header_heights = 6;
    $pdf->Cell($col_width[0], $header_heights, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], $header_heights, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], $header_heights, 'No Rekening', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], $header_heights, 'Nama Siswa', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], $header_heights, 'Kelas', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], $header_heights, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[6], $header_heights, 'Jumlah', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $row_height = 5;
    
    $total_setor = 0;
    $total_tarik = 0;
    
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $typeColor = [255, 255, 255];
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $typeColor = [220, 252, 231];
                $displayType = 'Setoran';
                $total_setor += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $typeColor = [254, 226, 226];
                $displayType = 'Penarikan';
                $total_tarik += $row['jumlah'];
            }
            
            $pdf->Cell($col_width[0], $row_height, $no++, 1, 0, 'C');
            $pdf->Cell($col_width[1], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C');
            $pdf->Cell($col_width[2], $row_height, $row['no_rekening'], 1, 0, 'L');
            
            $nama_siswa = $row['nama_siswa'] ?? 'N/A';
            $nama_siswa = mb_strlen($nama_siswa) > 18 ? mb_substr($nama_siswa, 0, 16) . '...' : $nama_siswa;
            $pdf->Cell($col_width[3], $row_height, $nama_siswa, 1, 0, 'L');
            
            $pdf->Cell($col_width[4], $row_height, $row['kelas'], 1, 0, 'L');
            
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[5], $row_height, $displayType, 1, 0, 'C', true);
            
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_width[6], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R');
        }
    } else {
        $pdf->Cell(array_sum($col_width), $row_height, 'Tidak ada transaksi admin dalam periode ini.', 1, 1, 'C');
    }

    // Clean the output buffer
    ob_end_clean();
    
    // Output the PDF
    $pdf->Output('laporan_transaksi_admin_' . $start_date . '_' . $end_date . '.pdf', 'D');
    exit;
}

// If Excel format is requested
elseif ($format === 'excel') {
    ob_start();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_transaksi_admin_' . $start_date . '_to_' . $end_date . '.xls"');
    
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>
            body { font-family: Arial, sans-serif; }
            .text-left { text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            td, th { padding: 4px; border: 1px solid #000; }
            .title { font-size: 14pt; font-weight: bold; text-align: center; }
            .subtitle { font-size: 10pt; text-align: center; }
            .school-info { font-size: 8pt; text-align: center; padding-bottom: 10px; }
            .section-header { background-color: #000000; color: #ffffff; font-weight: bold; text-align: center; }
            .sum-header { background-color: #f0f0f0; font-weight: bold; text-align: center; }
            .positive { background-color: #dcfce7; }
            .negative { background-color: #fee2e2; }
            table.main-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-top: 5px;
            }
            th.no { width: 5%; }
            th.tanggal { width: 10%; }
            th.no-rekening { width: 12%; }
            th.nama-siswa { width: 20%; }
            th.kelas { width: 15%; }
            th.jenis { width: 10%; }
            th.jumlah { width: 12%; }
            .table-footer { font-weight: bold; }
          </style>';
    echo '</head>';
    echo '<body>';

    // Header section
    echo '<table class="main-table" border="0" cellpadding="3">';
    echo '<tr><td colspan="7" class="title">SCHOBANK</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Sistem Bank Mini Sekolah</td></tr>';
    echo '<tr><td colspan="7" class="school-info">Jl. K.H. Saleh No.57A 43212 Cianjur Jawa Barat</td></tr>';
    echo '<tr><td colspan="7" class="title">REKAPAN TRANSAKSI ADMIN</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)) . '</td></tr>';
    echo '</table>';
    
    // Summary section
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr><th colspan="4" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    
    echo '<tr class="sum-header">';
    echo '<th>Total Transaksi</th>';
    echo '<th>Total Setoran</th>';
    echo '<th>Total Penarikan</th>';
    echo '<th>Total Bersih</th>';
    echo '</tr>';
    
    $netClass = $totals['total_net'] >= 0 ? 'positive' : 'negative';
    echo '<tr>';
    echo '<td class="text-center">' . $totals['total_transactions'] . '</td>';
    echo '<td class="text-center">Rp ' . number_format($totals['total_setoran'], 0, ',', '.') . '</td>';
    echo '<td class="text-center">Rp ' . number_format($totals['total_penarikan'], 0, ',', '.') . '</td>';
    echo '<td class="text-center ' . $netClass . '">Rp ' . number_format($totals['total_net'], 0, ',', '.') . '</td>';
    echo '</tr>';
    echo '</table>';

    // Detail section
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr class="section-header">';
    echo '<th colspan="7">DETAIL TRANSAKSI</th>';
    echo '</tr>';
    
    echo '<tr class="sum-header">';
    echo '<th class="no">No</th>';
    echo '<th class="tanggal">Tanggal</th>'; 
    echo '<th class="no-rekening">No Rekening</th>';
    echo '<th class="nama-siswa">Nama Siswa</th>';
    echo '<th class="kelas">Kelas</th>';
    echo '<th class="jenis">Jenis</th>';
    echo '<th class="jumlah">Jumlah</th>';
    echo '</tr>';
    
    $total_setor = 0;
    $total_tarik = 0;
    
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $bgColor = '#FFFFFF';
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $bgColor = '#dcfce7';
                $displayType = 'Setoran';
                $total_setor += $row['jumlah'];
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $bgColor = '#fee2e2';
                $displayType = 'Penarikan';
                $total_tarik += $row['jumlah'];
            }
            
            echo '<tr>';
            echo '<td class="text-center">' . $no++ . '</td>';
            echo '<td class="text-center">' . date('d/m/Y', strtotime($row['created_at'])) . '</td>';
            echo '<td class="text-left">' . htmlspecialchars($row['no_rekening']) . '</td>';
            echo '<td class="text-left">' . htmlspecialchars($row['nama_siswa']) . '</td>';
            echo '<td class="text-left">' . htmlspecialchars($row['kelas']) . '</td>';
            echo '<td class="text-center" bgcolor="' . $bgColor . '">' . $displayType . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7" class="text-center">Tidak ada transaksi admin dalam periode ini.</td></tr>';
    }
    echo '</table>';
    
    echo '</body></html>';
    
    ob_end_flush();
    exit;
}
?>