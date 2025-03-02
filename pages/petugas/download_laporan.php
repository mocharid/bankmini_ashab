<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Get report date (default: today)
$date = $_GET['date'] ?? date('Y-m-d'); 
$format = $_GET['format'] ?? 'pdf'; 

// Get officer information from session
$officer1_name = $_SESSION['officer1_name'] ?? 'Tidak Ada Data';
$officer1_class = $_SESSION['officer1_class'] ?? 'Tidak Ada Data';
$officer2_name = $_SESSION['officer2_name'] ?? 'Tidak Ada Data';
$officer2_class = $_SESSION['officer2_class'] ?? 'Tidak Ada Data';

// Query to calculate opening balance - CORRECTED to avoid reference to non-existent r.id
$balance_query = "SELECT 
    COALESCE(
        (SELECT 
            SUM(CASE 
                WHEN jenis_transaksi = 'setor' THEN jumlah 
                WHEN jenis_transaksi = 'tarik' THEN -jumlah 
                WHEN jenis_transaksi = 'transfer' AND rekening_id = rekening.id THEN -jumlah
                WHEN jenis_transaksi = 'transfer' AND rekening_tujuan_id = rekening.id THEN jumlah
                ELSE 0 
            END)
        FROM transaksi 
        JOIN rekening ON transaksi.rekening_id = rekening.id
        WHERE DATE(transaksi.created_at) < ?), 
    0) as opening_balance";
$stmt_balance = $conn->prepare($balance_query);
$stmt_balance->bind_param("s", $date);
$stmt_balance->execute();
$opening_balance = $stmt_balance->get_result()->fetch_assoc()['opening_balance'];

// Query to get transaction data - enhanced to handle transfers
$query = "SELECT 
            t.*, 
            u_source.nama as nama_siswa_source,
            u_dest.nama as nama_siswa_dest,
            CASE 
                WHEN t.jenis_transaksi = 'transfer' THEN 
                    CONCAT(
                        (SELECT no_rekening FROM rekening WHERE id = t.rekening_id), 
                        ' → ', 
                        (SELECT no_rekening FROM rekening WHERE id = t.rekening_tujuan_id)
                    )
                ELSE NULL
            END as transfer_detail
          FROM transaksi t 
          LEFT JOIN rekening r_source ON t.rekening_id = r_source.id
          LEFT JOIN users u_source ON r_source.user_id = u_source.id
          LEFT JOIN rekening r_dest ON t.rekening_tujuan_id = r_dest.id
          LEFT JOIN users u_dest ON r_dest.user_id = u_dest.id
          WHERE DATE(t.created_at) = ?
          ORDER BY t.created_at ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $date);
$stmt->execute();
$result = $stmt->get_result();

// Query to calculate transaction totals - enhanced to include transfers
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as total_penarikan,
    SUM(CASE WHEN jenis_transaksi = 'transfer' THEN jumlah ELSE 0 END) as total_transfer
    FROM transaksi 
    WHERE DATE(created_at) = ?";
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("s", $date);
$stmt_total->execute();
$totals = $stmt_total->get_result()->fetch_assoc();

// Calculate net saldo (net balance) - only deposits and withdrawals affect bank's net saldo
$net_saldo = $totals['total_setoran'] - $totals['total_penarikan'];

// Initialize variables for transaction data check
$has_transactions = false;

// Prepare transaction data for display
$transactions = [];
$running_balance = $opening_balance;
while ($row = $result->fetch_assoc()) {
    $has_transactions = true;
    
    // Handle balance calculation based on transaction type
    if ($row['jenis_transaksi'] == 'setor') {
        $running_balance += $row['jumlah'];
        $row['display_jenis'] = 'Setoran';
        $row['display_nama'] = $row['nama_siswa_source'];
    } else if ($row['jenis_transaksi'] == 'tarik') {
        $running_balance -= $row['jumlah'];
        $row['display_jenis'] = 'Penarikan';
        $row['display_nama'] = $row['nama_siswa_source'];
    } else if ($row['jenis_transaksi'] == 'transfer') {
        // For transfers, the net balance of the bank doesn't change
        // We simply display the transfer in the report without modifying running_balance
        $row['display_jenis'] = 'Transfer';
        $row['display_nama'] = $row['nama_siswa_source'] . ' → ' . $row['nama_siswa_dest'];
    }
    
    // Handle transaction number truncation (keep last 6 digits)
    $trx_number = $row['no_transaksi'];
    if (strlen($trx_number) > 6) {
        $row['display_no_transaksi'] = '...' . substr($trx_number, -6);
    } else {
        $row['display_no_transaksi'] = $trx_number;
    }
    
    $row['running_balance'] = $running_balance;
    $transactions[] = $row;
}

// Generate PDF report
if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public function Header() {
            $image_file = '../../assets/images/logo.png';
            if (file_exists($image_file)) {
                $this->Image($image_file, 15, 5, 20, '', 'PNG');
            }

            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'SCHOBANK', 0, false, 'C', 0);
            $this->Ln(6);
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 10, 'Sistem Mini Bank Sekolah', 0, false, 'C', 0);
            $this->Line(15, 25, 195, 25);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
            $this->Cell(90, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    // Create PDF object
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Harian Transaksi');

    // Set margins
    $pdf->SetMargins(15, 30, 15);
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

    // Calculate total usable width (same for all tables)
    $totalWidth = 180; // Total width in mm for all tables
    
    // Optimized table column widths for transaction details table
    $col_width = array(18, 52, 20, 30, 35, 25); // Adjusted column widths for main table
    
    // Officer Information
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($totalWidth, 7, 'INFORMASI PETUGAS', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Cell($totalWidth/2, 6, 'Nama Petugas 1: ' . $officer1_name, 1, 0, 'L');
    $pdf->Cell($totalWidth/2, 6, 'Kelas Petugas 1: ' . $officer1_class, 1, 1, 'R');
    $pdf->Cell($totalWidth/2, 6, 'Nama Petugas 2: ' . $officer2_name, 1, 0, 'L');
    $pdf->Cell($totalWidth/2, 6, 'Kelas Petugas 2: ' . $officer2_class, 1, 1, 'R');

    // Transaction Summary
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($totalWidth, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);
    
    // Adjusted transaction summary to add transfer information
    if ($has_transactions) {
        $pdf->Cell($totalWidth/5, 6, 'Total Transaksi: ' . $totals['total_transactions'], 1, 0, 'L');
        $pdf->Cell($totalWidth/5, 6, 'Setoran: Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'C');
        $pdf->Cell($totalWidth/5, 6, 'Penarikan: Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'C');
        $pdf->Cell($totalWidth/5, 6, 'Transfer: Rp ' . number_format($totals['total_transfer'], 0, ',', '.'), 1, 0, 'C');
        
        // Add Net Saldo with appropriate background color
        $netSaldoBgColor = $net_saldo >= 0 ? array(220, 252, 231) : array(254, 226, 226);
        $pdf->SetFillColor($netSaldoBgColor[0], $netSaldoBgColor[1], $netSaldoBgColor[2]);
        $pdf->Cell($totalWidth/5, 6, 'Net Saldo: Rp ' . number_format($net_saldo, 0, ',', '.'), 1, 1, 'R', true);
    } else {
        // If no transactions found
        $pdf->Cell($totalWidth, 6, 'Tidak ada transaksi pada tanggal ini', 1, 1, 'C');
    }

    // Transaction Details
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);

    if ($has_transactions) {
        // Table header settings
        $pdf->SetFillColor(220, 220, 220);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        
        // Table Headers
        $header_heights = 6;
        $pdf->Cell($col_width[0], $header_heights, 'No Trx', 1, 0, 'C', true);
        $pdf->Cell($col_width[1], $header_heights, 'Nama Siswa', 1, 0, 'C', true);
        $pdf->Cell($col_width[2], $header_heights, 'Jenis', 1, 0, 'C', true);
        $pdf->Cell($col_width[3], $header_heights, 'Jumlah', 1, 0, 'C', true);
        $pdf->Cell($col_width[4], $header_heights, 'Saldo', 1, 0, 'C', true);
        $pdf->Cell($col_width[5], $header_heights, 'Waktu', 1, 1, 'C', true);

        // Table content settings
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);

        // Table Content
        $row_height = 5;
        foreach ($transactions as $row) {
            // Set background color based on transaction type
            if ($row['jenis_transaksi'] == 'setor') {
                $typeColor = array(220, 252, 231); // Light green for deposits
            } else if ($row['jenis_transaksi'] == 'tarik') {
                $typeColor = array(254, 226, 226); // Light red for withdrawals
            } else {
                $typeColor = array(226, 232, 240); // Light blue for transfers
            }
            
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_width[0], $row_height, $row['display_no_transaksi'], 1, 0, 'L');
            
            // Handle long student names by truncating if necessary
            $student_name = mb_strlen($row['display_nama']) > 25 ? mb_substr($row['display_nama'], 0, 23) . '...' : $row['display_nama'];
            $pdf->Cell($col_width[1], $row_height, $student_name, 1, 0, 'L');
            
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[2], $row_height, $row['display_jenis'], 1, 0, 'C', true);
            
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_width[3], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 0, 'R');
            $pdf->Cell($col_width[4], $row_height, 'Rp ' . number_format($row['running_balance'], 0, ',', '.'), 1, 0, 'R');
            $pdf->Cell($col_width[5], $row_height, date('H:i', strtotime($row['created_at'])), 1, 1, 'C');
        }
    } else {
        // If no transactions
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell($totalWidth, 6, 'Tidak ada transaksi pada tanggal ini', 1, 1, 'C');
    }
    
    // Add Signature section
    $pdf->Ln(15);
    $pdf->SetFont('helvetica', '', 10);
        
    // Create signature layout
    $signatureWidth = $totalWidth / 2;
    
    // Petugas 1 signature
    $pdf->Cell($signatureWidth, 6, 'Petugas 1', 0, 0, 'C');
    
    // Petugas 2 signature
    $pdf->Cell($signatureWidth, 6, 'Petugas 2', 0, 1, 'C');
    
    // Add space for actual signatures
    $pdf->Ln(15);
    
    // Name under signature line for Petugas 1
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell($signatureWidth, 6, $officer1_name, 0, 0, 'C');
    
    // Name under signature line for Petugas 2
    $pdf->Cell($signatureWidth, 6, $officer2_name, 0, 1, 'C');
    
    // Add Petugas Class info
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell($signatureWidth, 6, 'Kelas: ' . $officer1_class, 0, 0, 'C');
    $pdf->Cell($signatureWidth, 6, 'Kelas: ' . $officer2_class, 0, 1, 'C');

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
            .signature-area { margin-top: 30px; }
            .signature-table { width: 100%; border-collapse: collapse; }
            .signature-cell { width: 50%; text-align: center; vertical-align: top; }
            .signature-line { border-bottom: 1px solid black; margin: 25px auto 5px auto; width: 60%; }
            .officer-name { font-weight: bold; }
            
            /* Make sure all tables have the same width */
            table.main-table { 
                width: 100%; 
                table-layout: fixed; 
                border-collapse: collapse; 
                margin-top: 5px;
            }
            
            /* Column widths for detail table */
            th.no-transaksi { width: 10%; }
            th.nama-siswa { width: 30%; }
            th.jenis { width: 10%; }
            th.jumlah { width: 15%; }
            th.saldo { width: 20%; }
            th.waktu { width: 15%; }
            
            /* Styling for net saldo */
            .positive-net { background-color: #dcfce7; }
            .negative-net { background-color: #fee2e2; }
            
            /* Styling for transaction types */
            .type-setor { background-color: #dcfce7; }
            .type-tarik { background-color: #fee2e2; }
            .type-transfer { background-color: #e2e8f0; }
          </style>';
    echo '</head>';
    echo '<body>';

    echo '<table class="main-table" border="0" cellpadding="3">';
    echo '<tr><td colspan="6" class="title">LAPORAN HARIAN TRANSAKSI</td></tr>';
    echo '<tr><td colspan="6" class="subtitle">Tanggal: ' . date('d/m/Y', strtotime($date)) . '</td></tr>';
    echo '</table>';

    // Officer Information - using the same table width as detail table
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr><th colspan="6" class="section-header">INFORMASI PETUGAS</th></tr>';
    echo '<tr>';
    echo '<td class="text-left" colspan="3">Nama Petugas 1: ' . $officer1_name . '</td>';
    echo '<td class="text-left" colspan="3">Kelas Petugas 1: ' . $officer1_class . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="text-left" colspan="3">Nama Petugas 2: ' . $officer2_name . '</td>';
    echo '<td class="text-left" colspan="3">Kelas Petugas 2: ' . $officer2_class . '</td>';
    echo '</tr>';
    echo '</table>';

    // Transaction Summary - using the same table width as detail table
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr><th colspan="6" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    
    if ($has_transactions) {
        echo '<tr>';
        // Updated to include transfer information
        echo '<td class="text-left" colspan="1">Total: ' . $totals['total_transactions'] . '</td>';
        echo '<td class="text-center" colspan="1">Setoran: Rp ' . number_format($totals['total_setoran'], 0, ',', '.') . '</td>';
        echo '<td class="text-center" colspan="1">Penarikan: Rp ' . number_format($totals['total_penarikan'], 0, ',', '.') . '</td>';
        echo '<td class="text-center" colspan="2">Transfer: Rp ' . number_format($totals['total_transfer'], 0, ',', '.') . '</td>';
        
        // Add Net Saldo with appropriate background color
        $netSaldoClass = $net_saldo >= 0 ? 'positive-net' : 'negative-net';
        echo '<td class="text-right ' . $netSaldoClass . '" colspan="1">Net Saldo: Rp ' . number_format($net_saldo, 0, ',', '.') . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="6" class="text-center">Tidak ada transaksi pada tanggal ini</td></tr>';
    }
    echo '</table>';

    // Transaction Details - main table
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr class="section-header">';
    echo '<th colspan="6">DETAIL TRANSAKSI</th>';
    echo '</tr>';
    
    if ($has_transactions) {
        echo '<tr class="section-header">';
        echo '<th class="no-transaksi">No Trx</th>';
        echo '<th class="nama-siswa">Nama Siswa</th>';
        echo '<th class="jenis">Jenis</th>';
        echo '<th class="jumlah">Jumlah</th>';
        echo '<th class="saldo">Saldo</th>';
        echo '<th class="waktu">Waktu</th>';
        echo '</tr>';
        
        foreach ($transactions as $row) {
            if ($row['jenis_transaksi'] == 'setor') {
                $bgColor = '#dcfce7'; // Light green for deposits
                $typeClass = 'type-setor';
            } else if ($row['jenis_transaksi'] == 'tarik') {
                $bgColor = '#fee2e2'; // Light red for withdrawals
                $typeClass = 'type-tarik';
            } else {
                $bgColor = '#e2e8f0'; // Light blue for transfers
                $typeClass = 'type-transfer';
            }
            
            echo '<tr>';
            echo '<td class="text-left">' . $row['display_no_transaksi'] . '</td>';
            echo '<td class="text-left">' . $row['display_nama'] . '</td>';
            echo '<td class="text-center ' . $typeClass . '" bgcolor="' . $bgColor . '">' . $row['display_jenis'] . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['running_balance'], 0, ',', '.') . '</td>';
            echo '<td class="text-center">' . date('H:i', strtotime($row['created_at'])) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center">Tidak ada transaksi pada tanggal ini</td></tr>';
    }
    echo '</table>';
    
    // Signature section
    echo '<table class="main-table signature-table" border="0" cellpadding="3" style="margin-top:30px;">';
    echo '<tr>';
    echo '<td class="signature-cell">Petugas 1</td>';
    echo '<td class="signature-cell">Petugas 2</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="signature-cell" style="padding-top:40px;"><div class="officer-name">' . $officer1_name . '</div></td>';
    echo '<td class="signature-cell" style="padding-top:40px;"><div class="officer-name">' . $officer2_name . '</div></td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="signature-cell">Kelas: ' . $officer1_class . '</td>';
    echo '<td class="signature-cell">Kelas: ' . $officer2_class . '</td>';
    echo '</tr>';
    echo '</table>';
        
    echo '</body></html>';
} else {
    die("Format tidak valid. Pilih 'pdf' atau 'excel'.");
}
?>