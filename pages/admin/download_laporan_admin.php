<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Prevent any output before PDF generation
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Akses ditolak. Silakan login sebagai admin.']);
    exit;
}

// Get parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Validate dates
if (empty($start_date) || empty($end_date) || !strtotime($start_date) || !strtotime($end_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tanggal tidak valid']);
    exit;
}
if (strtotime($start_date) > strtotime($end_date)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tanggal awal tidak boleh lebih dari tanggal akhir']);
    exit;
}

// Query for totals
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status = 'pending') THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NULL";
$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menyiapkan query total: ' . $conn->error]);
    exit;
}
$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran'] = $totals['total_setoran'] ?? 0;
$totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
$totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];
$stmt_total->close();

// Query for transaction details with tingkatan_kelas
$query = "SELECT 
            t.id,
            t.jenis_transaksi,
            t.jumlah,
            t.status,
            t.created_at,
            u.nama as nama_siswa,
            r.no_rekening,
            COALESCE(k.nama_kelas, '-') as nama_kelas,
            COALESCE(tk.nama_tingkatan, '-') as nama_tingkatan
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id
          JOIN users u ON r.user_id = u.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          WHERE DATE(t.created_at) BETWEEN ? AND ?
          AND t.jenis_transaksi IN ('setor', 'tarik')
          AND t.petugas_id IS NULL
          ORDER BY t.created_at DESC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Gagal menyiapkan query transaksi: ' . $conn->error]);
    exit;
}
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Store transactions with formatted no_rekening and combined class
$transactions = [];
while ($row = $result->fetch_assoc()) {
    $no_rekening = $row['no_rekening'] ?? 'N/A';
    // Format no_rekening as REK4****6
    if ($no_rekening !== 'N/A' && strlen($no_rekening) >= 6 && is_string($no_rekening)) {
        $row['no_rekening'] = 'REK' . substr($no_rekening, 3, 1) . '****' . substr($no_rekening, 5, 1);
    } else {
        $row['no_rekening'] = 'N/A';
    }
    // Combine nama_tingkatan and nama_kelas
    $row['kelas'] = trim($row['nama_tingkatan']) && trim($row['nama_kelas']) && $row['nama_tingkatan'] !== '-' && $row['nama_kelas'] !== '-' 
        ? htmlspecialchars($row['nama_tingkatan'] . ' ' . $row['nama_kelas'])
        : 'N/A';
    $transactions[] = $row;
}
$stmt->close();

// If PDF format is requested
if ($format === 'pdf') {
    // Check if TCPDF exists
    if (!file_exists('../../tcpdf/tcpdf.php')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Modul PDF tidak tersedia. Hubungi administrator.']);
        exit;
    }
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public $isFirstPage = true;

        public function Header() {
            if ($this->isFirstPage) {
                $image_file = '../../assets/images/logo.png';
                if (file_exists($image_file)) {
                    $this->Image($image_file, 15, 5, 20, '', 'PNG');
                }
                $this->SetFont('helvetica', 'B', 16);
                $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
                $this->Ln(6);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 10, 'Sistem Bank Mini Sekolah', 0, false, 'C', 0);
                $this->Ln(5);
                $this->SetFont('helvetica', '', 8);
                $this->Cell(0, 10, 'Jl. K.H. Saleh No.57A 43212 Cianjur Jawa Barat', 0, false, 'C', 0);
                $this->Line(15, 30, 195, 30);
                $this->isFirstPage = false; // Set to false after first page
            } else {
                // For subsequent pages, only show the table header
                $this->SetY(15); // Adjust starting Y position for table header
                $col_width = [10, 25, 25, 35, 25, 35, 25];
                $this->SetFont('helvetica', 'B', 8);
                $this->SetFillColor(128, 128, 128); // Gray background for header
                $this->SetTextColor(255, 255, 255);
                $this->Cell($col_width[0], 6, 'No', 1, 0, 'C', true);
                $this->Cell($col_width[1], 6, 'Tanggal', 1, 0, 'C', true);
                $this->Cell($col_width[2], 6, 'No Rekening', 1, 0, 'C', true);
                $this->Cell($col_width[3], 6, 'Nama Siswa', 1, 0, 'C', true);
                $this->Cell($col_width[4], 6, 'Kelas', 1, 0, 'C', true);
                $this->Cell($col_width[5], 6, 'Jenis', 1, 0, 'C', true);
                $this->Cell($col_width[6], 6, 'Jumlah', 1, 1, 'C', true);
                $this->SetTextColor(0, 0, 0);
            }
        }
    }

    // Start output buffering
    ob_start();
    
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Rekapan Transaksi Admin');
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->AddPage();

    // First page content
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'REKAPAN TRANSAKSI ADMIN', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    $totalWidth = 180;

    // Summary section
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(128, 128, 128); // Gray background for header
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
    $pdf->SetFillColor(128, 128, 128); // Gray background for header
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $col_width = [10, 25, 25, 35, 25, 35, 25];
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
            $pdf->Cell($col_width[2], $row_height, htmlspecialchars($row['no_rekening']), 1, 0, 'L');
            $nama_siswa = $row['nama_siswa'] ?? 'N/A';
            $nama_siswa = mb_strlen($nama_siswa) > 18 ? mb_substr($nama_siswa, 0, 16) . '...' : $nama_siswa;
            $pdf->Cell($col_width[3], $row_height, htmlspecialchars($nama_siswa), 1, 0, 'L');
            $pdf->Cell($col_width[4], $row_height, htmlspecialchars($row['kelas']), 1, 0, 'L');
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[5], $row_height, $displayType, 1, 0, 'C', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_width[6], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R');
        }
        
        // Total row
        $total = $total_setor - $total_tarik;
        $totalColor = $total >= 0 ? [220, 252, 231] : [254, 226, 226];
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(array_sum(array_slice($col_width, 0, 6)), $row_height, 'Total:', 1, 0, 'C');
        $pdf->SetFillColor($totalColor[0], $totalColor[1], $totalColor[2]);
        $pdf->Cell($col_width[6], $row_height, 'Rp ' . number_format($total, 0, ',', '.'), 1, 1, 'R', true);
    } else {
        $pdf->Cell(array_sum($col_width), $row_height, 'Tidak ada transaksi admin dalam periode ini.', 1, 1, 'C');
    }

    // Clean the output buffer
    ob_end_clean();
    
    // Output the PDF
    $pdf->Output('laporan_transaksi_admin_' . $start_date . '_' . $end_date . '.pdf', 'D');
    exit;
}

// If format is invalid
header('Content-Type: application/json');
echo json_encode(['error' => 'Format tidak valid']);
exit;
?>