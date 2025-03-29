<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Get report date (default: today)
$date = $_GET['date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf';

// Get officer information from database for the specific date
$officer_query = "SELECT petugas1_nama, petugas2_nama 
                 FROM petugas_tugas 
                 WHERE tanggal = ?";
$officer_stmt = $conn->prepare($officer_query);
$officer_stmt->bind_param("s", $date);
$officer_stmt->execute();
$officer_result = $officer_stmt->get_result();

// Set default values
$officer1_name = 'Tidak Ada Data';
$officer2_name = 'Tidak Ada Data';

// If officer data exists in database for this date, use it
if ($officer_result->num_rows > 0) {
    $officer_data = $officer_result->fetch_assoc();
    $officer1_name = $officer_data['petugas1_nama'];
    $officer2_name = $officer_data['petugas2_nama'];
}

// Modified query to get transaction data including student name, class, and department
$query = "SELECT 
            t.*, 
            r_source.no_rekening as no_rekening_source,
            u_source.nama as nama_siswa_source,
            k.nama_kelas,
            j.nama_jurusan
          FROM transaksi t 
          LEFT JOIN rekening r_source ON t.rekening_id = r_source.id
          LEFT JOIN users u_source ON r_source.user_id = u_source.id
          LEFT JOIN kelas k ON u_source.kelas_id = k.id
          LEFT JOIN jurusan j ON u_source.jurusan_id = j.id
          WHERE DATE(t.created_at) = ? AND t.jenis_transaksi != 'transfer'
          ORDER BY t.created_at ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();

// Query to calculate transaction totals - exclude transfers
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi 
    WHERE DATE(created_at) = ? AND jenis_transaksi != 'transfer'";
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("s", $date);
$stmt_total->execute();
$totals = $stmt_total->get_result()->fetch_assoc();

// Calculate net saldo (net balance) - only deposits and withdrawals affect bank's net saldo
$net_saldo = $totals['total_setoran'] - $totals['total_penarikan'];

// Calculate total amount - MODIFIED: using same logic as net saldo (deposits add, withdrawals subtract)
$total_amount = $net_saldo;

// Initialize variables for transaction data check
$has_transactions = false;

// Prepare transaction data for display
$transactions = [];
$row_number = 1; // Initialize row number counter
while ($row = $result->fetch_assoc()) {
    $has_transactions = true;

    // Add row number to each transaction
    $row['no'] = $row_number++;

    // Handle transaction type and display name
    if ($row['jenis_transaksi'] == 'setor') {
        $row['display_jenis'] = 'Setoran';
    } else if ($row['jenis_transaksi'] == 'tarik') {
        $row['display_jenis'] = 'Penarikan';
    }

    // Format classroom and department display
    $row['display_kelas'] = $row['nama_kelas'] ?? 'N/A';
    $row['display_jurusan'] = $row['nama_jurusan'] ?? 'N/A';
    $row['display_nama'] = $row['nama_siswa_source'] ?? 'N/A';

    $transactions[] = $row;
}

// Generate PDF report
if ($format === 'pdf') {
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
            $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
            $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    // Create PDF object
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Harian Transaksi');

    // Set margins
    $pdf->SetMargins(15, 40, 15);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->AddPage();

    // Report Title
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'LAPORAN HARIAN TRANSAKSI', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Tanggal: ' . date('d/m/Y', strtotime($date)), 0, 1, 'C');

    // Officer Information - Modified to match the image layout
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(180, 7, 'INFORMASI PETUGAS', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    // First row with column headers
    $pdf->Cell(90, 6, 'Petugas 1', 1, 0, 'C');
    $pdf->Cell(90, 6, 'Petugas 2', 1, 1, 'C');
    
    // Second row with officer names
    $pdf->Cell(90, 6, $officer1_name, 1, 0, 'C');
    $pdf->Cell(90, 6, $officer2_name, 1, 1, 'C');

    // Transaction Summary
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(180, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);

    if ($has_transactions) {
        $pdf->Cell(60, 6, 'Total Transaksi: ' . $totals['total_transactions'], 1, 0, 'L');
        $pdf->Cell(60, 6, 'Setoran: Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'C');
        $pdf->Cell(60, 6, 'Penarikan: Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 1, 'C');
        $pdf->SetFillColor(200, 200, 255); // Warna latar belakang untuk net saldo
        $pdf->Cell(180, 6, 'Net Saldo: Rp ' . number_format($net_saldo, 0, ',', '.'), 1, 1, 'C', true);
    } else {
        $pdf->Cell(180, 6, 'Tidak ada transaksi pada tanggal ini', 1, 1, 'C');
    }

    // Transaction Details - Modified to show name, class, and department with adjusted widths
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell(180, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);

    if ($has_transactions) {
        // Column headers - Modified with adjusted widths
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C'); // Added "No" column
        $pdf->Cell(55, 6, 'Nama', 1, 0, 'C'); // Adjusted width for other columns
        $pdf->Cell(20, 6, 'Kelas', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jurusan', 1, 0, 'C');
        $pdf->Cell(25, 6, 'Jenis', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Jumlah', 1, 1, 'C');

        // Transaction rows - Modified with adjusted widths
        $pdf->SetFont('helvetica', '', 8);
        foreach ($transactions as $row) {
            // Trim long names to prevent overflow
            $displayName = mb_strlen($row['display_nama']) > 28 ? 
                mb_substr($row['display_nama'], 0, 25) . '...' : 
                $row['display_nama'];
                
            $displayJurusan = mb_strlen($row['display_jurusan']) > 18 ? 
                mb_substr($row['display_jurusan'], 0, 15) . '...' : 
                $row['display_jurusan'];
                
            $pdf->Cell(10, 6, $row['no'], 1, 0, 'C'); // Added "No" column
            $pdf->Cell(55, 6, $displayName, 1, 0, 'L'); // Adjusted width
            $pdf->Cell(20, 6, $row['display_kelas'], 1, 0, 'C');
            $pdf->Cell(35, 6, $displayJurusan, 1, 0, 'L'); // Adjusted width
            $pdf->Cell(25, 6, $row['display_jenis'], 1, 0, 'C');
            $pdf->Cell(35, 6, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 1, 'R');
        }
        
        // Total row
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(145, 6, 'Total', 1, 0, 'C');
        $pdf->Cell(35, 6, 'Rp ' . number_format($total_amount, 0, ',', '.'), 1, 1, 'R');
    } else {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(180, 6, 'Tidak ada transaksi pada tanggal ini', 1, 1, 'C');
    }

    // Output PDF
    $pdf->Output('laporan_transaksi_' . $date . '.pdf', 'D');
} elseif ($format === 'excel') {
    // Excel report generation
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . $date . '.xls"');

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
            .net-saldo { background-color: #c8c8ff; } /* Warna latar belakang untuk net saldo */
            .total-row { font-weight: bold; }
            
            /* Adjusted column widths */
            .col-no { width: 40px; }
            .col-nama { width: 180px; }
            .col-kelas { width: 70px; }
            .col-jurusan { width: 140px; }
            .col-jenis { width: 80px; }
            .col-jumlah { width: 120px; }
          </style>';
    echo '</head>';
    echo '<body>';

    echo '<table border="0" cellpadding="3">';
    echo '<tr><td colspan="6" class="title">LAPORAN HARIAN TRANSAKSI</td></tr>';
    echo '<tr><td colspan="6" class="subtitle">Tanggal: ' . date('d/m/Y', strtotime($date)) . '</td></tr>';
    echo '</table>';

    // Officer Information - Modified to match the image layout
    echo '<table border="1" cellpadding="3">';
    echo '<tr><th colspan="2" class="section-header">INFORMASI PETUGAS</th></tr>';
    echo '<tr>';
    echo '<td class="text-center">Petugas 1</td>';
    echo '<td class="text-center">Petugas 2</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="text-center">' . $officer1_name . '</td>';
    echo '<td class="text-center">' . $officer2_name . '</td>';
    echo '</tr>';
    echo '</table>';

    // Transaction Summary
    echo '<table border="1" cellpadding="3">';
    echo '<tr><th colspan="4" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    if ($has_transactions) {
        echo '<tr>';
        echo '<td class="text-left" colspan="1">Total Transaksi: ' . $totals['total_transactions'] . '</td>';
        echo '<td class="text-center" colspan="1">Setoran: Rp ' . number_format($totals['total_setoran'], 0, ',', '.') . '</td>';
        echo '<td class="text-center" colspan="1">Penarikan: Rp ' . number_format($totals['total_penarikan'], 0, ',', '.') . '</td>';
        echo '<td class="text-right net-saldo" colspan="1">Net Saldo: Rp ' . number_format($net_saldo, 0, ',', '.') . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="4" class="text-center">Tidak ada transaksi pada tanggal ini</td></tr>';
    }
    echo '</table>';

    // Transaction Details - Modified to show name, class, and department with adjusted widths
    echo '<table border="1" cellpadding="3">';
    echo '<tr><th colspan="6" class="section-header">DETAIL TRANSAKSI</th></tr>'; // Updated colspan to 6
    if ($has_transactions) {
        echo '<tr>';
        echo '<th class="col-no">No</th>'; // Added "No" column
        echo '<th class="col-nama">Nama</th>';
        echo '<th class="col-kelas">Kelas</th>';
        echo '<th class="col-jurusan">Jurusan</th>';
        echo '<th class="col-jenis">Jenis</th>';
        echo '<th class="col-jumlah">Jumlah</th>';
        echo '</tr>';
        
        foreach ($transactions as $row) {
            echo '<tr>';
            echo '<td class="text-center">' . $row['no'] . '</td>'; // Added "No" column
            echo '<td class="text-left">' . $row['display_nama'] . '</td>';
            echo '<td class="text-center">' . $row['display_kelas'] . '</td>';
            echo '<td class="text-left">' . $row['display_jurusan'] . '</td>';
            echo '<td class="text-center">' . $row['display_jenis'] . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</td>';
            echo '</tr>';
        }
        
        // Total row
        echo '<tr class="total-row">';
        echo '<td colspan="5" class="text-center">Total</td>'; // Updated colspan to 5
        echo '<td class="text-right">Rp ' . number_format($total_amount, 0, ',', '.') . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="6" class="text-center">Tidak ada transaksi pada tanggal ini</td></tr>'; // Updated colspan to 6
    }
    echo '</table>';

    echo '</body></html>';
} else {
    die("Format tidak valid. Pilih 'pdf' atau 'excel'.");
}
?>