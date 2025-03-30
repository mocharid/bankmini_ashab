<?php
// cetak_mutasi.php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Initialize variables with default values
$user_data = [];
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$tabType = $_GET['tab'] ?? 'all'; // Default to 'all' if no tab type is specified

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

try {
    $query = "SELECT 
                t.*, 
                u.nama as petugas_nama,
                CASE 
                    WHEN t.jenis_transaksi = 'transfer' AND t.rekening_id = ? THEN 'transfer_keluar'
                    WHEN t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ? THEN 'transfer_masuk'
                    ELSE t.jenis_transaksi
                END as jenis_mutasi,
                sender.nama as pengirim,
                sender_rek.no_rekening as rekening_pengirim,
                recipient.nama as penerima,
                recipient_rek.no_rekening as rekening_penerima
              FROM transaksi t
              LEFT JOIN users u ON t.petugas_id = u.id
              LEFT JOIN rekening sender_rek ON t.rekening_id = sender_rek.id
              LEFT JOIN users sender ON sender_rek.user_id = sender.id
              LEFT JOIN rekening recipient_rek ON t.rekening_tujuan_id = recipient_rek.id
              LEFT JOIN users recipient ON recipient_rek.user_id = recipient.id
              WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
              AND t.status = 'approved'
              AND DATE(t.created_at) BETWEEN ? AND ?";

    // Tambahkan filter berdasarkan tab type
    if ($tabType === 'transfer') {
        $query .= " AND t.jenis_transaksi = 'transfer'";
    } elseif ($tabType === 'masuk') {
        $query .= " AND (t.jenis_transaksi = 'setor' OR (t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ?))";
    } elseif ($tabType === 'keluar') {
        $query .= " AND (t.jenis_transaksi = 'tarik' OR (t.jenis_transaksi = 'transfer' AND t.rekening_id = ?))";
    }

    $query .= " ORDER BY t.created_at ASC";

    $stmt = $conn->prepare($query);

    if ($tabType === 'masuk' || $tabType === 'keluar') {
        $stmt->bind_param("iiiissi", $rekening_id, $rekening_id, $rekening_id, $rekening_id, $start_date, $end_date, $rekening_id);
    } else {
        $stmt->bind_param("iiiiss", $rekening_id, $rekening_id, $rekening_id, $rekening_id, $start_date, $end_date);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    // Store transactions with running balance
    $transactions = [];
    $running_balance = 0; // Start with 0 as we're not showing opening balance

    while ($row = $result->fetch_assoc()) {
        // Calculate running balance based on transaction type
        switch ($row['jenis_mutasi']) {
            case 'setor':
                $running_balance += $row['jumlah'];
                break;

            case 'tarik':
                $running_balance -= $row['jumlah'];
                break;

            case 'transfer_keluar':
                $running_balance -= $row['jumlah'];
                break;

            case 'transfer_masuk':
                $running_balance += $row['jumlah'];
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
        // Add bank logo - use a placeholder rectangle with gradient for now
        $this->Rect(15, 10, 30, 15, 'F', array(), array(0, 71, 171, 0, 48, 135));
        
        // Add "SCHOBANK" text next to logo
        $this->SetFont('helvetica', 'B', 22);
        $this->SetTextColor(0, 48, 135);
        $this->SetXY(50, 10);
        $this->Cell(60, 15, 'SCHOBANK', 0, 0, 'L');
        
        // Add tagline
        $this->SetFont('helvetica', 'I', 10);
        $this->SetTextColor(100, 100, 100);
        $this->SetXY(50, 20);
        $this->Cell(60, 5, 'Banking for Your Future', 0, 0, 'L');
        
        // Add decorative element on the right
        $this->SetLineStyle(array('width' => 0.5, 'color' => array(0, 48, 135)));
        $this->Rect(160, 10, 35, 15, 'D');
        $this->SetFont('helvetica', 'B', 12);
        $this->SetTextColor(0, 48, 135);
        $this->SetXY(160, 10);
        $this->Cell(35, 15, 'E-Statement', 0, 0, 'C');
        
        // Add horizontal divider with gradient effect
        $this->SetLineStyle(array('width' => 1, 'color' => array(0, 48, 135)));
        $this->Line(15, 30, 195, 30);
        $this->SetLineStyle(array('width' => 0.5, 'color' => array(180, 180, 180)));
        $this->Line(15, 32, 195, 32);
        
        // Add statement title
        $this->SetFont('helvetica', 'B', 14);
        $this->SetTextColor(70, 70, 70);
        $this->SetXY(0, 35);
        $this->Cell(0, 10, 'LAPORAN MUTASI REKENING', 0, 0, 'C');
    }

    public function Footer() {
        // Footer with gradient top border
        $this->SetLineStyle(array('width' => 0.5, 'color' => array(180, 180, 180)));
        $this->Line(15, $this->getPageHeight() - 18, 195, $this->getPageHeight() - 18);
        $this->SetLineStyle(array('width' => 1, 'color' => array(0, 48, 135)));
        $this->Line(15, $this->getPageHeight() - 16, 195, $this->getPageHeight() - 16);
        
        // Footer content
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);
        $this->SetTextColor(70, 70, 70);
        $this->Cell(97, 10, 'Halaman '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, 0, 'L');
        $this->Cell(97, 10, 'Dicetak pada: '.date('d-m-Y H:i:s'), 0, 0, 'R');
        
        // Contact information
        $this->SetY(-10);
        $this->SetFont('helvetica', 'I', 7);
        $this->Cell(0, 10, 'Customer Service: 0800-123-SCHOBANK | www.schobank.co.id', 0, 0, 'C');
    }
}

try {
    // Create new PDF document
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Mutasi Rekening - ' . $user_data['nama']);

    // Set margins
    $pdf->SetMargins(15, 55, 15); // Increased top margin for enhanced header
    $pdf->SetHeaderMargin(20);
    $pdf->SetFooterMargin(15);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);

    // Add a page
    $pdf->AddPage();

    // Create a gradient box for the customer information section
    $pdf->SetFillColor(240, 242, 245);
    $pdf->RoundedRect(15, $pdf->GetY(), 180, 48, 3.50, '1111', 'F');

    // Customer Information Section with section header
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(180, 8, 'INFORMASI NASABAH', 0, 1, 'L', false);
    
    // Set up 2-column layout for customer info
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetY($pdf->GetY() + 2);
    $left_column = [
        ['Nama', $user_data['nama']],
        ['No. Rekening', $user_data['no_rekening']],
        ['Kelas', $user_data['nama_kelas']]
    ];
    
    $right_column = [
        ['Jurusan', $user_data['nama_jurusan']],
        ['Saldo Akhir', 'Rp ' . number_format($user_data['saldo'], 0, ',', '.')],
        ['Status', 'Aktif']
    ];
    
    $y_position = $pdf->GetY();
    
    // Left column
    foreach($left_column as $info) {
        $pdf->SetXY(20, $y_position);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(30, 7, $info[0], 0, 0);
        $pdf->Cell(5, 7, ':', 0, 0);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(50, 7, $info[1], 0, 1);
        $y_position += 7;
    }
    
    $y_position = $pdf->GetY() - 21; // Reset to the top of the right column
    
    // Right column
    foreach($right_column as $info) {
        $pdf->SetXY(110, $y_position);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(30, 7, $info[0], 0, 0);
        $pdf->Cell(5, 7, ':', 0, 0);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(50, 7, $info[1], 0, 1);
        $y_position += 7;
    }
    
    // Reset position after the customer info box
    $pdf->SetY($pdf->GetY() + 7);
    
    // Period information with styling
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(90, 10, 'PERIODE TRANSAKSI', 0, 0, 'L');
    $pdf->Cell(90, 10, 'JENIS TRANSAKSI', 0, 1, 'L');
    
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(90, 7, date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 0, 'L');
    $pdf->Cell(90, 7, ucfirst($tabType === 'all' ? 'Semua Transaksi' : $tabType), 0, 1, 'L');
    
    $pdf->Ln(5);

    // Calculate total usable width
    $totalWidth = 180; // Total width in mm

    // Transaction Table with Modern Design
    // Create header background with gradient
    $pdf->SetFillColor(0, 48, 135);
    $pdf->RoundedRect(15, $pdf->GetY(), $totalWidth, 8, 2.00, '1111', 'F');
    
    // Main Header Text
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($totalWidth, 8, 'RINCIAN TRANSAKSI', 0, 1, 'C');
    
    // Define column structure
    $col_width = array(10, 25, 40, 35, 35, 35);
    
    // Subheader Row
    $pdf->SetFillColor(240, 242, 245);
    $pdf->RoundedRect(15, $pdf->GetY(), $totalWidth, 7, 0, '0000', 'F');
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell($col_width[0], 7, 'No', 1, 0, 'C');
    $pdf->Cell($col_width[1], 7, 'Tanggal', 1, 0, 'C');
    $pdf->Cell($col_width[2], 7, 'No Transaksi', 1, 0, 'C');
    $pdf->Cell($col_width[3], 7, 'Jenis', 1, 0, 'C');
    $pdf->Cell($col_width[4], 7, 'Nominal', 1, 0, 'C');
    $pdf->Cell($col_width[5], 7, 'Saldo', 1, 1, 'C');

    // Table Content
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 8);
    $row_height = 6;
    $no = 1;
    $alternate = false;

    foreach ($transactions as $row) {
        // Set alternate row background for better readability
        if ($alternate) {
            $pdf->SetFillColor(248, 248, 248);
            $fill = true;
        } else {
            $pdf->SetFillColor(255, 255, 255);
            $fill = true;
        }
        $alternate = !$alternate;
        
        // Set transaction type text (without amount)
        switch ($row['jenis_mutasi']) {
            case 'setor':
                $displayType = 'SETORAN';
                $textColor = array(0, 128, 0); // Dark green for positive
                $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                break;
                
            case 'tarik':
                $displayType = 'PENARIKAN';
                $textColor = array(192, 0, 0); // Dark red for negative
                $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                break;

            case 'transfer_keluar':
                $displayType = 'TRF KELUAR';
                $textColor = array(192, 0, 0); // Dark red for negative
                $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                break;

            case 'transfer_masuk':
                $displayType = 'TRF MASUK';
                $textColor = array(0, 128, 0); // Dark green for positive
                $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                break;

            default:
                $displayType = 'LAINNYA';
                $textColor = array(0, 0, 0); // Black for others
                $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                break;
        }

        $pdf->Cell($col_width[0], $row_height, $no, 1, 0, 'C', $fill);
        $pdf->Cell($col_width[1], $row_height, date('d/m/Y', strtotime($row['created_at'])), 1, 0, 'C', $fill);
        $pdf->Cell($col_width[2], $row_height, $row['no_transaksi'], 1, 0, 'L', $fill);
        
        $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);
        $pdf->Cell($col_width[3], $row_height, $displayType, 1, 0, 'L', $fill);
        $pdf->Cell($col_width[4], $row_height, $amount, 1, 0, 'R', $fill);
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell($col_width[5], $row_height, 'Rp ' . number_format($row['running_balance'], 0, ',', '.'), 1, 1, 'R', $fill);

        $no++;
    }

    // Add Total Row with modern styling
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(220, 226, 240);
    
    // Combine the first columns for the "TOTAL" label
    $combined_width = $col_width[0] + $col_width[1] + $col_width[2] + $col_width[3] + $col_width[4];
    $pdf->Cell($combined_width, 7, 'SALDO AKHIR', 1, 0, 'R', true);
    
    // Final balance (which should match saldo akhir)
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell($col_width[5], 7, 'Rp ' . number_format($running_balance, 0, ',', '.'), 1, 1, 'R', true);
    
    // Add a disclaimer note at the bottom
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, 'Catatan: Laporan ini merupakan dokumen resmi yang diterbitkan oleh SCHOBANK. Apabila terdapat perbedaan saldo, harap segera menghubungi Petugas kami. Terima kasih telah menjadi nasabah setia SCHOBANK.', 0, 'L');

    // Output the PDF
    $pdf->Output('mutasi_rekening_' . $user_data['no_rekening'] . '.pdf', 'I');

} catch (Exception $e) {
    handleError("PDF generation error: " . $e->getMessage());
}
?>