<?php
// cetak_mutasi.php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Initialize variables with default values
$user_data = [];
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Error handling function
function handleError($message) {
    die("Error: " . $message);
}

// Get user data with error handling
try {
    $user_query = "SELECT u.nama, r.no_rekening, k.nama_kelas, j.nama_jurusan, r.saldo 
                   FROM users u 
                   JOIN rekening r ON u.id = r.user_id 
                   LEFT JOIN kelas k ON u.kelas_id = k.id 
                   LEFT JOIN jurusan j ON u.jurusan_id = j.id 
                   WHERE u.id = ?";
    
    if (!isset($_SESSION['user_id'])) {
        handleError("User ID not found in session.");
    }
    
    $stmt = $conn->prepare($user_query);
    if (!$stmt) {
        handleError("Failed to prepare user query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    if (!$stmt->execute()) {
        handleError("Failed to execute user query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    
    if (!$user_data) {
        handleError("No user data found.");
    }
    
    $stmt->close();
} catch (Exception $e) {
    handleError("Database error: " . $e->getMessage());
}

// Get rekening_id for the current user
try {
    $rekening_query = "SELECT id FROM rekening WHERE user_id = ?";
    $stmt = $conn->prepare($rekening_query);
    if (!$stmt) {
        handleError("Failed to prepare rekening query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $_SESSION['user_id']);
    if (!$stmt->execute()) {
        handleError("Failed to execute rekening query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $rekening_data = $result->fetch_assoc();
    
    if (!$rekening_data) {
        handleError("No rekening data found.");
    }
    
    $rekening_id = $rekening_data['id'];
    $stmt->close();
} catch (Exception $e) {
    handleError("Rekening query error: " . $e->getMessage());
}

// Get all transactions affecting user's account, including transfers
try {
    // The query now handles three types of transactions:
    // 1. Regular transactions (setor/tarik) on the user's account
    // 2. Outgoing transfers (user is the sender)
    // 3. Incoming transfers (user is the recipient)
    $query = "SELECT 
                t.*, 
                u.nama as petugas_nama,
                CASE 
                    WHEN t.jenis_transaksi = 'transfer' AND t.rekening_id = ? THEN 'transfer_keluar'
                    WHEN t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ? THEN 'transfer_masuk'
                    ELSE t.jenis_transaksi
                END as jenis_mutasi,
                -- Get sender info for incoming transfers
                sender.nama as pengirim,
                sender_rek.no_rekening as rekening_pengirim,
                -- Get recipient info for outgoing transfers
                recipient.nama as penerima,
                recipient_rek.no_rekening as rekening_penerima
              FROM transaksi t
              LEFT JOIN users u ON t.petugas_id = u.id
              -- Joins for sender information (for incoming transfers)
              LEFT JOIN rekening sender_rek ON t.rekening_id = sender_rek.id
              LEFT JOIN users sender ON sender_rek.user_id = sender.id
              -- Joins for recipient information (for outgoing transfers)
              LEFT JOIN rekening recipient_rek ON t.rekening_tujuan_id = recipient_rek.id
              LEFT JOIN users recipient ON recipient_rek.user_id = recipient.id
              WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
              AND t.status = 'approved'
              AND DATE(t.created_at) BETWEEN ? AND ? 
              ORDER BY t.created_at ASC";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        handleError("Failed to prepare transaction query: " . $conn->error);
    }
    
    $stmt->bind_param("iiiiss", $rekening_id, $rekening_id, $rekening_id, $rekening_id, $start_date, $end_date);
    if (!$stmt->execute()) {
        handleError("Failed to execute transaction query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    // Store transactions with running balance
    $transactions = [];
    $running_balance = 0; // Start with 0 as we're not showing opening balance
    
    // Track totals for all transaction types
    $total_setor = 0;
    $total_tarik = 0;
    $total_transfer_keluar = 0;
    $total_transfer_masuk = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Calculate running balance based on transaction type
        switch ($row['jenis_mutasi']) {
            case 'setor':
                $running_balance += $row['jumlah'];
                $total_setor += $row['jumlah'];
                break;
            
            case 'tarik':
                $running_balance -= $row['jumlah'];
                $total_tarik += $row['jumlah'];
                break;
                
            case 'transfer_keluar':
                $running_balance -= $row['jumlah'];
                $total_transfer_keluar += $row['jumlah'];
                break;
                
            case 'transfer_masuk':
                $running_balance += $row['jumlah'];
                $total_transfer_masuk += $row['jumlah'];
                break;
        }
        
        $row['running_balance'] = $running_balance;
        $transactions[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    handleError("Transaction query error: " . $e->getMessage());
}

class MYPDF extends TCPDF {
    public function Header() {
        $this->SetFont('helvetica', 'B', 20);
        
        // Draw a decorative line
        $this->SetLineStyle(array('width' => 0.5, 'color' => array(0, 48, 135)));
        $this->Line(15, 15, $this->getPageWidth() - 15, 15);
        
        // Bank name with custom styling
        $this->SetTextColor(0, 48, 135);
        $this->Cell(0, 15, 'LITTLEBANK', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        $this->Ln(12);
        
        // Subtitle
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(0, 10, 'Mutasi Rekening', 0, false, 'C', 0, '', 0, false, 'M', 'M');
        
        // Bottom decorative line
        $this->Line(15, 35, $this->getPageWidth() - 15, 35);
        $this->Ln(25);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(97, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'L');
        $this->Cell(97, 10, 'Dicetak pada: '.date('d-m-Y H:i:s'), 0, 0, 'R');
    }
}

try {
    // Create new PDF document
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('MINIBANK ASHAB');
    $pdf->SetAuthor('MINIBANK ASHAB');
    $pdf->SetTitle('Laporan Mutasi Rekening - ' . $user_data['nama']);

    // Set margins
    $pdf->SetMargins(15, 45, 15);
    $pdf->SetHeaderMargin(20);
    $pdf->SetFooterMargin(15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Add a page
    $pdf->AddPage();

    // Customer Information Section
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(0, 10, 'INFORMASI NASABAH', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);

    // Add subtle background to customer info
    $pdf->SetFillColor(248, 249, 250);
    $pdf->Rect(15, $pdf->GetY(), 180, 35, 'F');

    // Customer details with better spacing
    $pdf->SetY($pdf->GetY() + 2);
    $customer_info = [
        ['Nama', $user_data['nama']],
        ['No. Rekening', $user_data['no_rekening']],
        ['Kelas', $user_data['nama_kelas']],
        ['Jurusan', $user_data['nama_jurusan']],
        ['Saldo Akhir', 'Rp ' . number_format($user_data['saldo'], 0, ',', '.')]
    ];

    foreach($customer_info as $info) {
        $pdf->Cell(30, 7, $info[0], 0, 0);
        $pdf->Cell(5, 7, ':', 0, 0);
        $pdf->Cell(0, 7, $info[1], 0, 1);
    }

    $pdf->Ln(5);

    // Period information with styling
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(0, 10, 'PERIODE TRANSAKSI', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'L');
    $pdf->Ln(5);

    // Calculate total usable width
    $totalWidth = 180; // Total width in mm
    
    // Table Header - ENHANCED STYLING
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 48, 135);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    
    // Optimized column widths - adjusted to accommodate additional info column
    $col_width = array(10, 25, 45, 25, 30, 25, 20);
    
    // Table header - use the same blue color and white text
    $pdf->SetFont('helvetica', 'B', 9);
    // Keep the same fill color (0, 48, 135) and text color white
    $header_heights = 6;
    $pdf->Cell($col_width[0], $header_heights, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], $header_heights, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], $header_heights, 'No Transaksi', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], $header_heights, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], $header_heights, 'Jumlah', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], $header_heights, 'Saldo', 1, 0, 'C', true);
    $pdf->Cell($col_width[6], $header_heights, 'Keterangan', 1, 1, 'C', true);

    // Reset text color to black for table content
    $pdf->SetTextColor(0, 0, 0);
    
    // Table Content
    $pdf->SetFont('helvetica', '', 8);
    $row_height = 6;
    $no = 1;

    foreach ($transactions as $row) {
        // Set colors and display text based on transaction type
        switch ($row['jenis_mutasi']) {
            case 'setor':
                $typeColor = array(220, 252, 231); // Light green
                $displayType = 'Setoran';
                $keterangan = $row['petugas_nama'] ?? '-';
                break;
                
            case 'tarik':
                $typeColor = array(254, 226, 226); // Light red
                $displayType = 'Penarikan';
                $keterangan = $row['petugas_nama'] ?? '-';
                break;
                
            case 'transfer_keluar':
                $typeColor = array(224, 242, 254); // Light blue
                $displayType = 'Transfer Keluar';
                $keterangan = 'Ke: ' . mb_substr($row['penerima'] ?? '-', 0, 8) . '...';
                break;
                
            case 'transfer_masuk':
                $typeColor = array(220, 252, 231); // Light green (same as setoran)
                $displayType = 'Transfer Masuk';
                $keterangan = 'Dari: ' . mb_substr($row['pengirim'] ?? '-', 0, 8) . '...';
                break;
                
            default:
                $typeColor = array(240, 240, 240); // Light gray
                $displayType = 'Lainnya';
                $keterangan = '-';
                break;
        }
        
        $pdf->Cell($col_width[0], $row_height, $no, 1, 0, 'C');
        $pdf->Cell($col_width[1], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C');
        $pdf->Cell($col_width[2], $row_height, $row['no_transaksi'], 1, 0, 'L');
        
        $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
        $pdf->Cell($col_width[3], $row_height, $displayType, 1, 0, 'C', true);
        
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell($col_width[4], $row_height, 'Rp ' . number_format($row['jumlah'], 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell($col_width[5], $row_height, 'Rp ' . number_format($row['running_balance'], 0, ',', '.'), 1, 0, 'R');
        $pdf->Cell($col_width[6], $row_height, $keterangan, 1, 1, 'L');
        
        $no++;
    }
    
    // Add Total Row
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    
    // Combine the first three columns for the "TOTAL" label
    $combined_width = $col_width[0] + $col_width[1] + $col_width[2];
    $pdf->Cell($combined_width, 7, 'TOTAL KESELURUHAN', 1, 0, 'C', true);
    
    // Jenis column showing summary
    $pdf->Cell($col_width[3], 7, '', 1, 0, 'C', true);
    
    // Calculate net transaction amount
    $net_amount = ($total_setor + $total_transfer_masuk) - ($total_tarik + $total_transfer_keluar);
    
    // Total transaction amount 
    $pdf->Cell($col_width[4], 7, 'Rp ' . number_format($net_amount, 0, ',', '.'), 1, 0, 'R', true);
    
    // Final balance (which should match saldo akhir)
    $pdf->Cell($col_width[5], 7, 'Rp ' . number_format($running_balance, 0, ',', '.'), 1, 0, 'R', true);
    
    // Empty cell for the keterangan column
    $pdf->Cell($col_width[6], 7, '', 1, 1, 'C', true);
    
    // Add breakdown row for total deposits
    $pdf->SetFillColor(220, 252, 231); // Light green for deposits
    $pdf->Cell($combined_width, 6, 'Total Setoran', 1, 0, 'R', false);
    $pdf->Cell($col_width[3], 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Rp ' . number_format($total_setor, 0, ',', '.'), 1, 0, 'R', false);
    $pdf->Cell($col_width[5] + $col_width[6], 6, '', 1, 1, 'C', false);
    
    // Add breakdown row for total withdrawals
    $pdf->SetFillColor(254, 226, 226); // Light red for withdrawals
    $pdf->Cell($combined_width, 6, 'Total Penarikan', 1, 0, 'R', false);
    $pdf->Cell($col_width[3], 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Rp ' . number_format($total_tarik, 0, ',', '.'), 1, 0, 'R', false);
    $pdf->Cell($col_width[5] + $col_width[6], 6, '', 1, 1, 'C', false);
    
    // Add breakdown row for outgoing transfers
    $pdf->SetFillColor(224, 242, 254); // Light blue for transfers
    $pdf->Cell($combined_width, 6, 'Total Transfer Keluar', 1, 0, 'R', false);
    $pdf->Cell($col_width[3], 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Rp ' . number_format($total_transfer_keluar, 0, ',', '.'), 1, 0, 'R', false);
    $pdf->Cell($col_width[5] + $col_width[6], 6, '', 1, 1, 'C', false);
    
    // Add breakdown row for incoming transfers
    $pdf->SetFillColor(220, 252, 231); // Light green for incoming transfers (same as deposits)
    $pdf->Cell($combined_width, 6, 'Total Transfer Masuk', 1, 0, 'R', false);
    $pdf->Cell($col_width[3], 6, '', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Rp ' . number_format($total_transfer_masuk, 0, ',', '.'), 1, 0, 'R', false);
    $pdf->Cell($col_width[5] + $col_width[6], 6, '', 1, 1, 'C', false);

    // Output the PDF
    $pdf->Output('mutasi_rekening_' . $user_data['no_rekening'] . '.pdf', 'I');

} catch (Exception $e) {
    handleError("PDF generation error: " . $e->getMessage());
}
?>