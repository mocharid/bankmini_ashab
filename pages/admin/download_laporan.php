<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Prevent any output before PDF generation
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

// Get parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf';

// NEW: Get rekening_id parameter; use a default if not provided
$rekening_id = $_GET['rekening_id'] ?? null;

// If rekening_id is not provided, get the first rekening from the database
if (!$rekening_id) {
    $query_first_rekening = "SELECT id FROM rekening LIMIT 1";
    $result_first_rekening = $conn->query($query_first_rekening);
    if ($result_first_rekening && $result_first_rekening->num_rows > 0) {
        $rekening_id = $result_first_rekening->fetch_assoc()['id'];
    } else {
        // If no rekening found, use a default value to prevent null errors
        $rekening_id = 0;
    }
}

// Hitung opening balance - MODIFIED to only consider setor and tarik
$balance_query = "SELECT 
    COALESCE(
        (SELECT 
            SUM(CASE 
                WHEN jenis_transaksi = 'setor' THEN jumlah 
                WHEN jenis_transaksi = 'tarik' THEN -jumlah 
                ELSE 0 
            END)
        FROM transaksi 
        WHERE DATE(created_at) < ? AND rekening_id = ?), 
    0) as opening_balance";

$stmt_balance = $conn->prepare($balance_query);
$stmt_balance->bind_param("si", $start_date, $rekening_id);
$stmt_balance->execute();
$balance_result = $stmt_balance->get_result();
$opening_balance = $balance_result->fetch_assoc()['opening_balance'] ?? 0;

// Ambil data transaksi dengan informasi lebih lengkap
$query = "SELECT 
            t.*, 
            u_source.nama as nama_siswa, 
            u_dest.nama as nama_tujuan,
            CASE 
                WHEN t.jenis_transaksi = 'transfer' AND t.rekening_id = ? THEN 'keluar'
                WHEN t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ? THEN 'masuk'
                ELSE t.jenis_transaksi
            END as tipe_transaksi
          FROM transaksi t 
          JOIN users u_source ON t.rekening_id IN (SELECT id FROM rekening WHERE user_id = u_source.id)
          LEFT JOIN users u_dest ON t.rekening_tujuan_id IN (SELECT id FROM rekening WHERE user_id = u_dest.id)
          WHERE DATE(t.created_at) BETWEEN ? AND ? 
            AND (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
          ORDER BY t.created_at DESC";
          
$stmt = $conn->prepare($query);
$stmt->bind_param("iissii", $rekening_id, $rekening_id, $start_date, $end_date, $rekening_id, $rekening_id);
$stmt->execute();
$result = $stmt->get_result();

// Hitung total transaksi, setoran, penarikan, dan transfer
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as total_penarikan,
    SUM(CASE WHEN jenis_transaksi = 'transfer' THEN jumlah ELSE 0 END) as total_transfer
    FROM transaksi 
    WHERE DATE(created_at) BETWEEN ? AND ?
    AND (rekening_id = ? OR rekening_tujuan_id = ?)";
    
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("ssii", $start_date, $end_date, $rekening_id, $rekening_id);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();

// Handle null values in totals array
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran'] = $totals['total_setoran'] ?? 0;
$totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
$totals['total_transfer'] = $totals['total_transfer'] ?? 0;
$totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];

// Inisialisasi variabel untuk menyimpan transaksi
$transactions = [];
$transactions_chronological = [];
$running_balance = $opening_balance;

// Ambil semua transaksi dan hitung saldo berjalan
$all_transactions = [];
while ($row = $result->fetch_assoc()) {
    $all_transactions[] = $row;
}

// Jika ada transaksi, hitung saldo berjalan - MODIFIED to ignore transfers in balance calculation
if (!empty($all_transactions)) {
    $all_transactions_chronological = array_reverse($all_transactions);
    foreach ($all_transactions_chronological as $row) {
        // Tentukan perubahan saldo berdasarkan jenis transaksi
        $balance_change = 0;
        
        if ($row['jenis_transaksi'] == 'setor') {
            $balance_change = $row['jumlah'];
        } elseif ($row['jenis_transaksi'] == 'tarik') {
            $balance_change = -$row['jumlah'];
        }
        // For transfers, balance_change remains 0 since they don't affect overall balance
        
        $running_balance += $balance_change;
        $row['running_balance'] = $running_balance;
        $transactions_chronological[] = $row;
    }
    $transactions = array_reverse($transactions_chronological);
}

// Jika format PDF
if ($format === 'pdf') {
    require_once '../../tcpdf/tcpdf.php';

    class MYPDF extends TCPDF {
        public function Header() {
            // Logo
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

    // NEW: Use output buffering to prevent any output before PDF generation
    ob_start();
    
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Transaksi');
    
    $pdf->SetMargins(15, 30, 15);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->AddPage();

    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'LAPORAN TRANSAKSI', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    $totalWidth = 180; 

    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($totalWidth, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    // Tampilkan baris pertama ringkasan
    $pdf->Cell($totalWidth/2, 6, 'Total Transaksi: ' . $totals['total_transactions'], 1, 0, 'L');
    $pdf->Cell($totalWidth/2, 6, 'Total Net: Rp ' . number_format($totals['total_net'], 0, ',', '.'), 1, 1, 'R');
    
    // Tampilkan baris kedua ringkasan
    $pdf->Cell($totalWidth/3, 6, 'Total Setoran: Rp ' . number_format($totals['total_setoran'], 0, ',', '.'), 1, 0, 'L');
    $pdf->Cell($totalWidth/3, 6, 'Total Penarikan: Rp ' . number_format($totals['total_penarikan'], 0, ',', '.'), 1, 0, 'L');
    $pdf->Cell($totalWidth/3, 6, 'Total Transfer: Rp ' . number_format($totals['total_transfer'], 0, ',', '.'), 1, 1, 'L');

    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(220, 220, 220);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);

    $col_width = array(25, 40, 25, 30, 30, 30); 

    $pdf->SetFont('helvetica', 'B', 8);
    $header_heights = 6;
    $pdf->Cell($col_width[0], $header_heights, 'No Transaksi', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], $header_heights, 'Nama', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], $header_heights, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], $header_heights, 'Jumlah', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], $header_heights, 'Saldo', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], $header_heights, 'Tanggal', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $row_height = 5;
    if (!empty($transactions)) {
        foreach ($transactions as $row) {
            // Tentukan warna berdasarkan jenis transaksi
            $typeColor = [255, 255, 255]; // Default putih
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $typeColor = [220, 252, 231]; // Hijau muda
                $displayType = 'Setoran';
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $typeColor = [254, 226, 226]; // Merah muda
                $displayType = 'Penarikan';
            } elseif ($row['jenis_transaksi'] == 'transfer') {
                if ($row['tipe_transaksi'] == 'keluar') {
                    $typeColor = [254, 240, 226]; // Orange muda
                    $displayType = 'Transfer Keluar';
                } else {
                    $typeColor = [226, 240, 254]; // Biru muda
                    $displayType = 'Transfer Masuk';
                }
            }
            
            // Batasi panjang No Transaksi
            $no_transaksi = mb_strlen($row['no_transaksi']) > 10 ? mb_substr($row['no_transaksi'], 0, 8) . '...' : $row['no_transaksi'];
            $pdf->Cell($col_width[0], $row_height, $no_transaksi, 1, 0, 'L');
            
            // Tampilkan nama yang relevan berdasarkan jenis transaksi
            $display_name = $row['nama_siswa'] ?? 'N/A';
            if ($row['jenis_transaksi'] == 'transfer') {
                if ($row['tipe_transaksi'] == 'keluar') {
                    $nama_tujuan = $row['nama_tujuan'] ?? 'N/A';
                    $display_name = 'Ke: ' . (mb_strlen($nama_tujuan) > 15 ? mb_substr($nama_tujuan, 0, 13) . '...' : $nama_tujuan);
                } else {
                    $nama_siswa = $row['nama_siswa'] ?? 'N/A';
                    $display_name = 'Dari: ' . (mb_strlen($nama_siswa) > 15 ? mb_substr($nama_siswa, 0, 13) . '...' : $nama_siswa);
                }
            } else {
                $display_name = mb_strlen($display_name) > 18 ? mb_substr($display_name, 0, 16) . '...' : $display_name;
            }
            $pdf->Cell($col_width[1], $row_height, $display_name, 1, 0, 'L');
            
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[2], $row_height, $displayType, 1, 0, 'C', true);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell($col_width[3], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 0, 'R');
            $pdf->Cell($col_width[4], $row_height, 'Rp ' . number_format($row['running_balance'], 0, ',', '.'), 1, 0, 'R');
            $pdf->Cell($col_width[5], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 1, 'C');
        }
    } else {
        $pdf->Cell($totalWidth, $row_height, 'Tidak ada transaksi dalam periode ini.', 1, 1, 'C');
    }

    // Clean the output buffer
    ob_end_clean();
    
    // Output the PDF
    $pdf->Output('laporan_transaksi_' . $start_date . '_' . $end_date . '.pdf', 'D');
    exit; // Ensure no further output
}

// Jika format Excel
elseif ($format === 'excel') {
    // Start output buffering to prevent any premature output
    ob_start();
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . $start_date . '_to_' . $end_date . '.xls"');
    
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
            
            /* Make sure all tables have the same width */
            table.main-table { 
                width: 100%; 
                table-layout: fixed; 
                border-collapse: collapse; 
                margin-top: 5px;
            }
            
            /* Column widths for detail table */
            th.no-transaksi { width: 15%; }
            th.nama-siswa { width: 25%; }
            th.jenis { width: 10%; }
            th.jumlah { width: 15%; }
            th.saldo { width: 15%; }
            th.tanggal { width: 10%; }
          </style>';
    echo '</head>';
    echo '<body>';

    echo '<table class="main-table" border="0" cellpadding="3">';
    echo '<tr><td colspan="6" class="title">LAPORAN TRANSAKSI</td></tr>';
    echo '<tr><td colspan="6" class="subtitle">Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)) . '</td></tr>';
    echo '</table>';
    
    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr><th colspan="6" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    echo '<tr>';
    echo '<td class="text-left" colspan="3">Total Transaksi: ' . $totals['total_transactions'] . '</td>';
    echo '<td class="text-right" colspan="3">Total Net: Rp ' . number_format($totals['total_net'], 0, ',', '.') . '</td>';
    echo '</tr>';
    echo '<tr>';
    echo '<td class="text-left" colspan="2">Total Setoran:<br>Rp ' . number_format($totals['total_setoran'], 0, ',', '.') . '</td>';
    echo '<td class="text-left" colspan="2">Total Penarikan:<br>Rp ' . number_format($totals['total_penarikan'], 0, ',', '.') . '</td>';
    echo '<td class="text-left" colspan="2">Total Transfer:<br>Rp ' . number_format($totals['total_transfer'], 0, ',', '.') . '</td>';
    echo '</tr></table>';

    echo '<table class="main-table" border="1" cellpadding="3">';
    echo '<tr class="section-header">';
    echo '<th colspan="6">DETAIL TRANSAKSI</th>';
    echo '</tr>';
    
    echo '<tr class="section-header">';
    echo '<th class="no-transaksi">No Transaksi</th>';
    echo '<th class="nama-siswa">Nama</th>';
    echo '<th class="jenis">Jenis</th>';
    echo '<th class="jumlah">Jumlah</th>';
    echo '<th class="saldo">Saldo</th>';
    echo '<th class="tanggal">Tanggal</th>';
    echo '</tr>';
    
    if (!empty($transactions)) {
        foreach ($transactions as $row) {
            $bgColor = '#FFFFFF'; // Default putih
            $displayType = '';
            
            if ($row['jenis_transaksi'] == 'setor') {
                $bgColor = '#dcfce7'; // Hijau muda
                $displayType = 'Setoran';
            } elseif ($row['jenis_transaksi'] == 'tarik') {
                $bgColor = '#fee2e2'; // Merah muda
                $displayType = 'Penarikan';
            } elseif ($row['jenis_transaksi'] == 'transfer') {
                if ($row['tipe_transaksi'] == 'keluar') {
                    $bgColor = '#fef0e2'; // Orange muda
                    $displayType = 'Transfer Keluar';
                } else {
                    $bgColor = '#e2f0fe'; // Biru muda
                    $displayType = 'Transfer Masuk';
                }
            }
            
            // Tampilkan nama yang relevan berdasarkan jenis transaksi
            $display_name = $row['nama_siswa'] ?? 'N/A';
            if ($row['jenis_transaksi'] == 'transfer') {
                if ($row['tipe_transaksi'] == 'keluar') {
                    $nama_tujuan = $row['nama_tujuan'] ?? 'N/A';
                    $display_name = 'Ke: ' . (mb_strlen($nama_tujuan) > 15 ? mb_substr($nama_tujuan, 0, 13) . '...' : $nama_tujuan);
                } else {
                    $nama_siswa = $row['nama_siswa'] ?? 'N/A';
                    $display_name = 'Dari: ' . (mb_strlen($nama_siswa) > 15 ? mb_substr($nama_siswa, 0, 13) . '...' : $nama_siswa);
                }
            }
            
            echo '<tr>';
            echo '<td class="text-left">' . (mb_strlen($row['no_transaksi']) > 15 ? mb_substr($row['no_transaksi'], 0, 13) . '...' : $row['no_transaksi']) . '</td>';
            echo '<td class="text-left">' . $display_name . '</td>';
            echo '<td class="text-center" bgcolor="' . $bgColor . '">' . $displayType . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</td>';
            echo '<td class="text-right">Rp ' . number_format($row['running_balance'], 0, ',', '.') . '</td>';
            echo '<td class="text-center">' . date('d/m/Y', strtotime($row['created_at'])) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center">Tidak ada transaksi dalam periode ini.</td></tr>';
    }
    echo '</table>';
    
    echo '</body></html>';
    
    // End output buffering and send to browser
    ob_end_flush();
    exit; // Ensure no further output
}
?>