<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Get report date (default: today)
$date = $_GET['date'] ?? date('Y-m-d');
$format = $_GET['format'] ?? 'pdf';

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die("Format tanggal tidak valid.");
}

// Get current petugas ID
$user_id = $_SESSION['user_id'] ?? 0;

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
    $officer1_name = $officer_data['petugas1_nama'] ?? 'Tidak Ada Data';
    $officer2_name = $officer_data['petugas2_nama'] ?? 'Tidak Ada Data';
}

// Query to get transaction data including student name, class, and department
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

// Query to calculate total admin transfers for this petugas on the date
$admin_transfers_query = "SELECT SUM(jumlah) as total_admin_transfers 
                         FROM saldo_transfers 
                         WHERE petugas_id = ? AND DATE(tanggal) = ?";
$stmt_admin = $conn->prepare($admin_transfers_query);
$stmt_admin->bind_param("is", $user_id, $date);
$stmt_admin->execute();
$total_admin_transfers = $stmt_admin->get_result()->fetch_assoc()['total_admin_transfers'] ?? 0;

// Calculate saldo_bersih as total_setoran - total_penarikan
$saldo_bersih = ($totals['total_setoran'] ?? 0) - ($totals['total_penarikan'] ?? 0);
$total_amount = $saldo_bersih;

// Initialize variables for transaction data check
$has_transactions = false;

// Prepare transaction data for display
$transactions = [];
$row_number = 1;
while ($row = $result->fetch_assoc()) {
    $has_transactions = true;
    $row['no'] = $row_number++;
    $row['display_jenis'] = $row['jenis_transaksi'] == 'setor' ? 'Setoran' : 'Penarikan';
    $row['display_kelas'] = $row['nama_kelas'] ?? 'N/A';
    $row['display_jurusan'] = $row['nama_jurusan'] ?? 'N/A';
    $row['display_nama'] = $row['nama_siswa_source'] ?? 'N/A';
    $transactions[] = $row;
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Generate PDF report
if ($format === 'pdf') {
    if (!file_exists('../../tcpdf/tcpdf.php')) {
        die("Error: TCPDF library not found.");
    }
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
            $this->Line(10, 35, 200, 35);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 8);
            $this->Cell(100, 10, 'Dicetak pada: ' . date('d/m/Y H:i'), 0, 0, 'L');
            $this->Cell(90, 10, 'Halaman ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    }

    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Harian Transaksi');
    $pdf->SetMargins(10, 40, 10);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(15);
    $pdf->SetAutoPageBreak(TRUE, 20);
    $pdf->SetCellPadding(1.5); // Improved spacing inside cells
    $pdf->AddPage();

    // Title
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'LAPORAN HARIAN TRANSAKSI', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Tanggal: ' . date('d/m/Y', strtotime($date)), 0, 1, 'C');

    // Officer Information
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(190, 7, 'INFORMASI PETUGAS', 1, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 6, 'Petugas 1: ' . $officer1_name, 1, 0, 'L', false);
    $pdf->Cell(95, 6, 'Petugas 2: ' . $officer2_name, 1, 1, 'L', false);

    // Transaction Summary
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(190, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 8);

    if ($has_transactions || $total_admin_transfers > 0) {
        // Row 1: Total Transaksi, Setoran, Penarikan
        $pdf->Cell(63, 7, 'Total Transaksi: ' . ($totals['total_transactions'] ?? 0), 1, 0, 'L', false);
        $pdf->Cell(63, 7, 'Setoran: ' . formatRupiah($totals['total_setoran'] ?? 0), 1, 0, 'L', false);
        $pdf->Cell(64, 7, 'Penarikan: ' . formatRupiah($totals['total_penarikan'] ?? 0), 1, 1, 'L', false);
        // Row 2: Penambahan Admin, Saldo Bersih
        $pdf->Cell(63, 7, 'Penambahan Admin: ' . formatRupiah($total_admin_transfers), 1, 0, 'L', false);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor($saldo_bersih < 0 ? 200 : 0, 0, 0);
        $pdf->Cell(127, 7, 'Saldo Bersih: ' . formatRupiah($saldo_bersih), 1, 1, 'L', false);
        $pdf->SetTextColor(0);
        $pdf->SetFont('helvetica', '', 8);
    } else {
        $pdf->Cell(190, 7, 'Tidak ada aktivitas transaksi atau penambahan admin hari ini', 1, 1, 'C', false);
    }

    // Transaction Details
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);
    $pdf->Cell(190, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);

    if ($has_transactions) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell(10, 6, 'No', 1, 0, 'C', true);
        $pdf->Cell(60, 6, 'Nama', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Kelas', 1, 0, 'C', true);
        $pdf->Cell(40, 6, 'Jurusan', 1, 0, 'C', true);
        $pdf->Cell(25, 6, 'Jenis', 1, 0, 'C', true);
        $pdf->Cell(30, 6, 'Jumlah', 1, 1, 'C', true);

        $pdf->SetFont('helvetica', '', 8);
        $row_count = 0;
        foreach ($transactions as $row) {
            $bg_color = $row_count % 2 == 0 ? 245 : 255;
            $pdf->SetFillColor($bg_color, $bg_color, $bg_color);
            $displayName = mb_strlen($row['display_nama']) > 30 ? 
                mb_substr($row['display_nama'], 0, 27) . '...' : $row['display_nama'];
            $displayJurusan = mb_strlen($row['display_jurusan']) > 20 ? 
                mb_substr($row['display_jurusan'], 0, 17) . '...' : $row['display_jurusan'];
                
            $pdf->Cell(10, 6, $row['no'], 1, 0, 'C', true);
            $pdf->Cell(60, 6, $displayName, 1, 0, 'L', true);
            $pdf->Cell(25, 6, $row['display_kelas'], 1, 0, 'C', true);
            $pdf->Cell(40, 6, $displayJurusan, 1, 0, 'L', true);
            $pdf->Cell(25, 6, $row['display_jenis'], 1, 0, 'C', true);
            $pdf->Cell(30, 6, formatRupiah($row['jumlah']), 1, 1, 'R', true);
            $row_count++;
        }

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Cell(160, 6, 'Total', 1, 0, 'C', true);
        $pdf->Cell(30, 6, formatRupiah($total_amount), 1, 1, 'R', true);
    } else {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(190, 6, 'Tidak ada transaksi setoran atau penarikan hari ini', 1, 1, 'C', false);
    }

    // Note
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetFillColor(240, 248, 255);
    $pdf->Cell(190, 6, 'Catatan: Laporan ini hanya mencakup setoran dan penarikan. Transfer antar siswa tidak disertakan.', 1, 1, 'L', true);

    $pdf->Output('laporan_transaksi_' . $date . '.pdf', 'D');
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="laporan_transaksi_' . $date . '.xls"');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
    echo '<style>
            body { font-family: Arial, sans-serif; }
            .text-left { text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            td, th { padding: 8px; border: 1px solid #ddd; }
            .title { font-size: 16pt; font-weight: bold; text-align: center; }
            .subtitle { font-size: 11pt; text-align: center; }
            .section-header { background-color: #e6e6e6; font-weight: bold; text-align: center; }
            .total-row { font-weight: bold; background-color: #f0f0f0; }
            .row-even { background-color: #f9f9f9; }
            .row-odd { background-color: #ffffff; }
            .col-no { width: 50px; }
            .col-nama { width: 200px; }
            .col-kelas { width: 80px; }
            .col-jurusan { width: 150px; }
            .col-jenis { width: 90px; }
            .col-jumlah { width: 120px; }
            .col-summary { width: 130px; }
            .note { font-size: 9pt; color: #333; background-color: #f0f8ff; padding: 8px; }
            .negative { color: red; }
            .spacer { height: 12px; }
          </style></head><body>';

    // Title
    echo '<table border="0" cellpadding="5">';
    echo '<tr><td colspan="6" class="title">LAPORAN HARIAN TRANSAKSI</td></tr>';
    echo '<tr><td colspan="6" class="subtitle">Tanggal: ' . date('d/m/Y', strtotime($date)) . '</td></tr>';
    echo '</table>';

    // Spacer
    echo '<table border="0"><tr><td class="spacer"></td></tr></table>';

    // Officer Information
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th colspan="2" class="section-header">INFORMASI PETUGAS</th></tr>';
    echo '<tr><td class="text-left col-label" style="width: 200px;">Petugas 1</td><td class="text-left col-value" style="width: 150px;">' . htmlspecialchars($officer1_name) . '</td></tr>';
    echo '<tr><td class="text-left col-label">Petugas 2</td><td class="text-left col-value">' . htmlspecialchars($officer2_name) . '</td></tr>';
    echo '</table>';

    // Spacer
    echo '<table border="0"><tr><td class="spacer"></td></tr></table>';

    // Transaction Summary
    echo '<table border="1" cellpadding="8">';
    echo '<tr><th colspan="3" class="section-header">RINGKASAN TRANSAKSI</th></tr>';
    if ($has_transactions || $total_admin_transfers > 0) {
        echo '<tr>';
        echo '<td class="text-left col-summary">Total Transaksi: ' . ($totals['total_transactions'] ?? 0) . '</td>';
        echo '<td class="text-left col-summary">Setoran: ' . formatRupiah($totals['total_setoran'] ?? 0) . '</td>';
        echo '<td class="text-left col-summary">Penarikan: ' . formatRupiah($totals['total_penarikan'] ?? 0) . '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td class="text-left col-summary">Penambahan Admin: ' . formatRupiah($total_admin_transfers) . '</td>';
        echo '<td class="text-left col-summary ' . ($saldo_bersih < 0 ? 'negative' : '') . '" colspan="2">Saldo Bersih: ' . formatRupiah($saldo_bersih) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="3" class="text-center">Tidak ada aktivitas transaksi atau penambahan admin hari ini</td></tr>';
    }
    echo '</table>';

    // Spacer
    echo '<table border="0"><tr><td class="spacer"></td></tr></table>';

    // Transaction Details
    echo '<table border="1" cellpadding="5">';
    echo '<tr><th colspan="6" class="section-header">DETAIL TRANSAKSI</th></tr>';
    if ($has_transactions) {
        echo '<tr>';
        echo '<th class="col-no">No</th>';
        echo '<th class="col-nama">Nama</th>';
        echo '<th class="col-kelas">Kelas</th>';
        echo '<th class="col-jurusan">Jurusan</th>';
        echo '<th class="col-jenis">Jenis</th>';
        echo '<th class="col-jumlah">Jumlah</th>';
        echo '</tr>';
        
        $row_count = 0;
        foreach ($transactions as $row) {
            $row_class = $row_count % 2 == 0 ? 'row-even' : 'row-odd';
            echo '<tr class="' . $row_class . '">';
            echo '<td class="text-center">' . $row['no'] . '</td>';
            echo '<td class="text-left">' . htmlspecialchars($row['display_nama']) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($row['display_kelas']) . '</td>';
            echo '<td class="text-left">' . htmlspecialchars($row['display_jurusan']) . '</td>';
            echo '<td class="text-center">' . htmlspecialchars($row['display_jenis']) . '</td>';
            echo '<td class="text-right">' . formatRupiah($row['jumlah']) . '</td>';
            echo '</tr>';
            $row_count++;
        }
        
        echo '<tr class="total-row">';
        echo '<td colspan="5" class="text-center">Total</td>';
        echo '<td class="text-right">' . formatRupiah($total_amount) . '</td>';
        echo '</tr>';
    } else {
        echo '<tr><td colspan="6" class="text-center">Tidak ada transaksi setoran atau penarikan hari ini</td></tr>';
    }
    echo '</table>';

    // Spacer
    echo '<table border="0"><tr><td class="spacer"></td></tr></table>';

    // Note
    echo '<table border="1" cellpadding="5">';
    echo '<tr><td class="note">Catatan: Laporan ini hanya mencakup setoran dan penarikan. Transfer antar siswa tidak disertakan.</td></tr>';
    echo '</table>';

    echo '</body></html>';
} else {
    die("Format tidak valid. Pilih 'pdf' atau 'excel'.");
}

// Close database connections
$officer_stmt->close();
$stmt->close();
$stmt_total->close();
$stmt_admin->close();
$conn->close();
?>