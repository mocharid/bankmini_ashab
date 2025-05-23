<?php
// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Include authentication and database connection
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Prevent output before PDF/Excel generation
ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Validate session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Get parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Query for transactions
$query = "SELECT 
    t.id,
    t.jenis_transaksi,
    t.jumlah,
    t.status,
    t.created_at,
    u.nama as nama_siswa,
    r.no_rekening,
    p.nama as nama_petugas,
    COALESCE(k.nama_kelas, '-') as kelas
    FROM transaksi t 
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN users p ON t.petugas_id = p.id
    LEFT JOIN kelas k ON u.kelas_id = k.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NOT NULL
    ORDER BY t.created_at DESC";
$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Error preparing transaction query: " . $conn->error);
    die("Error preparing transaction query");
}
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

// Calculate totals
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN users p ON t.petugas_id = p.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NOT NULL";
$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    error_log("Error preparing total query: " . $conn->error);
    die("Error preparing total query");
}
$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_debit'] = $totals['total_debit'] ?? 0;
$totals['total_kredit'] = $totals['total_kredit'] ?? 0;
$totals['total_net'] = $totals['total_debit'] - $totals['total_kredit'];
$stmt_total->close();

// Store transactions with masked account numbers
$transactions = [];
while ($row = $result->fetch_assoc()) {
    // Mask account number
    $no_rekening = $row['no_rekening'];
    if (strlen($no_rekening) >= 8) {
        $start = substr($no_rekening, 0, 2);
        $end = substr($no_rekening, -2);
        $row['no_rekening'] = 'REK' . $start . '****' . $end;
    }
    $transactions[] = $row;
}
$stmt->close();

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// PDF Generation
if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public function Header() {
            $image_file = '../../assets/images/logo.png';
            if (file_exists($image_file)) {
                $this->Image($image_file, 15, 5, 20);
            }
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'SCHOBANK', 0, 1, 'C');
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 5, 'Sistem Bank Mini Sekolah', 0, 1, 'C');
            $this->SetFont('helvetica', '', 8);
            $this->Cell(0, 5, 'Jl. K.H. Saleh No.57A, Cianjur, Jawa Barat', 0, 1, 'C');
            $this->Line(15, 30, 195, 30);
        }
    }

    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Rekapan Transaksi');
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'REKAPAN TRANSAKSI PETUGAS', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    // Summary Section
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255);
    $pdf->Cell(180, 8, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0);

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 245, 255);
    $pdf->Cell(45, 6, 'Total Transaksi', 1, 0, 'C', true);
    $pdf->Cell(45, 6, 'Total Debit', 1, 0, 'C', true);
    $pdf->Cell(45, 6, 'Total Kredit', 1, 0, 'C', true);
    $pdf->Cell(45, 6, 'Total Bersih', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(45, 6, $totals['total_transactions'], 1, 0, 'C');
    $pdf->Cell(45, 6, formatRupiah($totals['total_debit']), 1, 0, 'C');
    $pdf->Cell(45, 6, formatRupiah($totals['total_kredit']), 1, 0, 'C');
    $pdf->Cell(45, 6, formatRupiah($totals['total_net']), 1, 1, 'C');

    // Transaction Details
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(30, 58, 138);
    $pdf->SetTextColor(255);
    $pdf->Cell(180, 8, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0);

    $col_width = [10, 25, 25, 40, 25, 25, 30];
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 245, 255);
    $pdf->Cell($col_width[0], 6, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], 6, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], 6, 'No Rekening', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], 6, 'Nama Siswa', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Kelas', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], 6, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[6], 6, 'Jumlah', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $displayType = $row['jenis_transaksi'] == 'setor' ? 'Debit' : 'Kredit';
            $nama_siswa = mb_strlen($row['nama_siswa']) > 20 ? mb_substr($row['nama_siswa'], 0, 18) . '...' : $row['nama_siswa'];
            $pdf->Cell($col_width[0], 5, $no++, 1, 0, 'C');
            $pdf->Cell($col_width[1], 5, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C');
            $pdf->Cell($col_width[2], 5, $row['no_rekening'], 1, 0, 'C');
            $pdf->Cell($col_width[3], 5, $nama_siswa, 1, 0, 'L');
            $pdf->Cell($col_width[4], 5, $row['kelas'], 1, 0, 'C');
            $pdf->Cell($col_width[5], 5, $displayType, 1, 0, 'C');
            $pdf->Cell($col_width[6], 5, formatRupiah($row['jumlah']), 1, 1, 'R');
        }
    } else {
        $pdf->Cell(180, 5, 'Tidak ada transaksi dalam periode ini', 1, 1, 'C');
    }

    ob_end_clean();
    $pdf->Output('laporan_transaksi_' . $start_date . '_' . $end_date . '.pdf', 'D');
    exit;
}

// Excel Generation
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . $start_date . '_to_' . $end_date . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head>';
    echo '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>
        table { border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 5px; font-family: Arial, sans-serif; }
        .title { font-size: 16px; font-weight: bold; text-align: center; }
        .subtitle { font-size: 12px; text-align: center; }
        .header { background-color: #1e3a8a; color: white; font-weight: bold; text-align: center; }
        .subheader { background-color: #f0f5ff; font-weight: bold; text-align: center; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
    </style>';
    echo '</head>';
    echo '<body>';

    echo '<table>';
    echo '<tr><td colspan="7" class="title">SCHOBANK</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Sistem Bank Mini Sekolah</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Jl. K.H. Saleh No.57A, Cianjur, Jawa Barat</td></tr>';
    echo '<tr><td colspan="7" class="title">REKAPAN TRANSAKSI PETUGAS</td></tr>';
    echo '<tr><td colspan="7" class="subtitle">Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)) . '</td></tr>';
    echo '</table>';

    echo '<table>';
    echo '<tr><td colspan="4" class="header">RINGKASAN TRANSAKSI</td></tr>';
    echo '<tr class="subheader">';
    echo '<td>Total Transaksi</td>';
    echo '<td>Total Debit</td>';
    echo '<td>Total Kredit</td>';
    echo '<td>Total Bersih</td>';
    echo '</tr>';
    echo '<tr class="text-center">';
    echo '<td>' . $totals['total_transactions'] . '</td>';
    echo '<td>' . formatRupiah($totals['total_debit']) . '</td>';
    echo '<td>' . formatRupiah($totals['total_kredit']) . '</td>';
    echo '<td>' . formatRupiah($totals['total_net']) . '</td>';
    echo '</tr>';
    echo '</table>';

    echo '<table>';
    echo '<tr><td colspan="7" class="header">DETAIL TRANSAKSI</td></tr>';
    echo '<tr class="subheader">';
    echo '<td>No</td>';
    echo '<td>Tanggal</td>';
    echo '<td>No Rekening</td>';
    echo '<td>Nama Siswa</td>';
    echo '<td>Kelas</td>';
    echo '<td>Jenis</td>';
    echo '<td>Jumlah</td>';
    echo '</tr>';

    if (!empty($transactions)) {
        $no = 1;
        foreach ($transactions as $row) {
            $displayType = $row['jenis_transaksi'] == 'setor' ? 'Debit' : 'Kredit';
            echo '<tr>';
            echo '<td class="text-center">' . $no++ . '</td>';
            echo '<td class="text-center">' . date('d/m/Y', strtotime($row['created_at'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['no_rekening']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_siswa']) . '</td>';
            echo '<td>' . htmlspecialchars($row['kelas']) . '</td>';
            echo '<td class="text-center">' . $displayType . '</td>';
            echo '<td class="text-right">' . formatRupiah($row['jumlah']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="7" class="text-center">Tidak ada transaksi dalam periode ini</td></tr>';
    }
    echo '</table>';

    echo '</body></html>';
    ob_end_flush();
    exit;
}
?>