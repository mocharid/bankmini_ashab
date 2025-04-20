<?php
// Start output buffering to capture any unintended output
ob_start();

// Suppress warnings during PDF generation
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'C:/laragon/www/schobank/logs/php_errors.log');

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Initialize variables with default values
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$tabType = $_GET['tab'] ?? 'all'; // Default to 'all' if no tab type is specified

// Error handling function
function handleError($message) {
    ob_end_clean(); // Clear output buffer
    die("Error: " . htmlspecialchars($message));
}

// Validate session
if (!isset($_SESSION['user_id'])) {
    handleError("User ID not found in session.");
}
$user_id = $_SESSION['user_id'];

// Get user data
try {
    $user_query = "SELECT u.nama, r.no_rekening, k.nama_kelas, j.nama_jurusan, r.saldo 
                   FROM users u 
                   JOIN rekening r ON u.id = r.user_id 
                   LEFT JOIN kelas k ON u.kelas_id = k.id 
                   LEFT JOIN jurusan j ON u.jurusan_id = j.id 
                   WHERE u.id = ?";
    
    $stmt = $conn->prepare($user_query);
    if (!$stmt) {
        handleError("Failed to prepare user query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
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

// Get rekening data
try {
    $rekening_query = "SELECT id, no_rekening FROM rekening WHERE user_id = ?";
    $stmt = $conn->prepare($rekening_query);
    if (!$stmt) {
        handleError("Failed to prepare rekening query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        handleError("Failed to execute rekening query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $rekening_data = $result->fetch_assoc();
    
    if (!$rekening_data) {
        handleError("No rekening data found.");
    }
    
    $rekening_id = $rekening_data['id'];
    $no_rekening = $rekening_data['no_rekening'];
    $stmt->close();
} catch (Exception $e) {
    handleError("Rekening query error: " . $e->getMessage());
}

// Fetch transactions
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

    // Add filter based on tab type
    if ($tabType === 'setor') {
        $query .= " AND t.jenis_transaksi = 'setor'";
    } elseif ($tabType === 'tarik') {
        $query .= " AND t.jenis_transaksi = 'tarik'";
    } elseif ($tabType === 'transfer') {
        $query .= " AND t.jenis_transaksi = 'transfer'";
    } elseif ($tabType === 'masuk') {
        $query .= " AND (t.jenis_transaksi = 'setor' OR (t.jenis_transaksi = 'transfer' AND t.rekening_tujuan_id = ?))";
    } elseif ($tabType === 'keluar') {
        $query .= " AND (t.jenis_transaksi = 'tarik' OR (t.jenis_transaksi = 'transfer' AND t.rekening_id = ?))";
    }

    $query .= " ORDER BY t.created_at ASC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        handleError("Failed to prepare transaction query: " . $conn->error);
    }

    if ($tabType === 'masuk' || $tabType === 'keluar') {
        $stmt->bind_param("iiiissi", $rekening_id, $rekening_id, $rekening_id, $rekening_id, $start_date, $end_date, $rekening_id);
    } else {
        $stmt->bind_param("iiiiss", $rekening_id, $rekening_id, $rekening_id, $rekening_id, $start_date, $end_date);
    }

    if (!$stmt->execute()) {
        handleError("Failed to execute transaction query: " . $stmt->error);
    }

    $result = $stmt->get_result();

    // Store transactions and calculate summary
    $transactions = [];
    $running_balance = 0; // Start with 0 for running balance
    $total_setor = 0;
    $total_tarik = 0;
    $total_transfer_masuk = 0;
    $total_transfer_keluar = 0;

    while ($row = $result->fetch_assoc()) {
        // Calculate running balance and summary totals
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
        // Solid dark blue background
        $this->SetFillColor(0, 48, 135);
        $this->Rect(0, 0, 210, 25, 'F');
        
        // Logo
        $logo_path = '../../schobank/assets/images/lbank.png';
        $this->Image($logo_path, 15, 10, 25, 0, 'PNG', '', 'T', false, 300, '', false, false, 0, false, false, false);
        
        // Bank name
        $this->SetFont('helvetica', 'B', 16);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(45, 5);
        $this->Cell(135, 8, 'SCHOBANK', 0, 1, 'C');
        
        // Statement title
        $this->SetFont('helvetica', 'B', 10);
        $this->SetTextColor(255, 255, 255);
        $this->SetXY(45, 13);
        $this->Cell(135, 6, 'LAPORAN MUTASI REKENING', 0, 1, 'C');
    }

    public function Footer() {
        // Empty footer as per requirement
    }
}

try {
    // Create new PDF document
    $pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('SCHOBANK');
    $pdf->SetAuthor('SCHOBANK');
    $pdf->SetTitle('Laporan Mutasi Rekening - ' . $user_data['nama']);
    $pdf->SetSubject('Mutasi Rekening');
    $pdf->SetKeywords('SCHOBANK, Mutasi, Rekening, E-Statement');

    // Set margins
    $pdf->SetMargins(15, 40, 15); // Adjusted for smaller header
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(0);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 10);

    // Add a page
    $pdf->AddPage();

    // Customer Information Section
    $pdf->SetFillColor(248, 250, 255);
    $pdf->RoundedRect(15, $pdf->GetY(), 180, 50, 4, '1111', 'F');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(180, 7, 'INFORMASI NASABAH', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetY($pdf->GetY() + 2);
    $customer_info = [
        ['Nama', $user_data['nama']],
        ['No. Rekening', $user_data['no_rekening']],
        ['Kelas', $user_data['nama_kelas'] ?? 'N/A'],
        ['Jurusan', $user_data['nama_jurusan'] ?? 'N/A'],
        ['Saldo Akhir', 'Rp ' . number_format($user_data['saldo'], 0, ',', '.')]
    ];
    
    $y_position = $pdf->GetY();
    foreach ($customer_info as $info) {
        $pdf->SetXY(20, $y_position);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(30, 6, $info[0], 0, 0);
        $pdf->Cell(5, 6, ':', 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(100, 6, $info[1], 0, 1);
        $y_position += 6;
    }
    
    $pdf->SetY($pdf->GetY() + 7);
    
    // Period and Transaction Type
    $pdf->SetFillColor(248, 250, 255);
    $pdf->RoundedRect(15, $pdf->GetY(), 180, 18, 4, '1111', 'F');
    
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(90, 6, 'PERIODE TRANSAKSI', 0, 0, 'L');
    $pdf->Cell(90, 6, 'JENIS TRANSAKSI', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(90, 6, date('d/m/Y', strtotime($start_date)) . ' s/d ' . date('d/m/Y', strtotime($end_date)), 0, 0, 'L');
    $display_tab = match($tabType) {
        'all' => 'Semua Transaksi',
        'setor' => 'Setoran',
        'tarik' => 'Penarikan',
        'transfer' => 'Transfer',
        'masuk' => 'Transaksi Masuk',
        'keluar' => 'Transaksi Keluar',
        default => 'Semua Transaksi'
    };
    $pdf->Cell(90, 6, $display_tab, 0, 1, 'L');
    
    $pdf->Ln(5);

    // Summary Section
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell(180, 7, 'RINGKASAN TRANSAKSI', 0, 1, 'L');
    
    $pdf->SetFillColor(248, 250, 255);
    $pdf->RoundedRect(15, $pdf->GetY(), 180, 30, 4, '1111', 'F');
    
    $summary_items = [
        ['Total Setoran', 'Rp ' . number_format($total_setor, 0, ',', '.'), [16, 185, 129]],
        ['Total Penarikan', 'Rp ' . number_format($total_tarik, 0, ',', '.'), [244, 67, 54]],
        ['Total Transfer Masuk', 'Rp ' . number_format($total_transfer_masuk, 0, ',', '.'), [30, 136, 229]],
        ['Total Transfer Keluar', 'Rp ' . number_format($total_transfer_keluar, 0, ',', '.'), [255, 152, 0]]
    ];
    
    $y_position = $pdf->GetY() + 2;
    $x_position = 20;
    $item_width = 85;
    
    foreach ($summary_items as $index => $item) {
        $pdf->SetXY($x_position, $y_position);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(40, 6, $item[0], 0, 0);
        $pdf->Cell(5, 6, ':', 0, 0);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor($item[2][0], $item[2][1], $item[2][2]);
        $pdf->Cell(40, 6, $item[1], 0, 1);
        
        if ($index % 2 == 1) {
            $y_position += 6;
            $x_position = 20;
        } else {
            $x_position += $item_width;
        }
    }
    
    $pdf->SetY($y_position + 7);
    
    // Transaction Table
    $pdf->SetFillColor(0, 48, 135);
    $pdf->Rect(15, $pdf->GetY(), 180, 7, 'F'); // Sharp-edged rectangle
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(180, 7, 'RINCIAN TRANSAKSI', 0, 1, 'C');
    
    // Define column widths (without Detail column)
    $col_width = [10, 30, 40, 30, 35, 35]; // No, Date, No Transaksi, Jenis, Nominal, Saldo
    
    // Subheader
    $pdf->SetFillColor(240, 242, 245);
    $pdf->Rect(15, $pdf->GetY(), 180, 6, 'F'); // Sharp-edged rectangle
    
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell($col_width[0], 6, 'No', 1, 0, 'C', 1);
    $pdf->Cell($col_width[1], 6, 'Tanggal', 1, 0, 'C', 1);
    $pdf->Cell($col_width[2], 6, 'No Transaksi', 1, 0, 'C', 1);
    $pdf->Cell($col_width[3], 6, 'Jenis', 1, 0, 'C', 1);
    $pdf->Cell($col_width[4], 6, 'Nominal', 1, 0, 'C', 1);
    $pdf->Cell($col_width[5], 6, 'Saldo', 1, 1, 'C', 1);

    // Table Content
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(0, 0, 0);
    $row_height = 7;
    $no = 1;
    $alternate = false;

    if (empty($transactions)) {
        $pdf->SetFont('helvetica', 'I', 9);
        $pdf->Cell(180, 10, 'Tidak ada transaksi dalam periode ini', 0, 1, 'C');
    } else {
        foreach ($transactions as $row) {
            // Set alternate row background
            $pdf->SetFillColor($alternate ? 248 : 255, $alternate ? 248 : 255, $alternate ? 248 : 255);
            $alternate = !$alternate;
            
            // Determine transaction type and colors
            switch ($row['jenis_mutasi']) {
                case 'setor':
                    $displayType = 'SETORAN';
                    $typeColor = [16, 185, 129]; // Green for Jenis
                    $amountColor = [0, 0, 0]; // Black for Nominal
                    $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                    break;
                case 'tarik':
                    $displayType = 'PENARIKAN';
                    $typeColor = [244, 67, 54]; // Red for Jenis
                    $amountColor = [244, 67, 54]; // Red for Nominal
                    $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                    break;
                case 'transfer_keluar':
                    $displayType = 'TRF KELUAR';
                    $typeColor = [244, 67, 54]; // Red for Jenis
                    $amountColor = [244, 67, 54]; // Red for Nominal
                    $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                    break;
                case 'transfer_masuk':
                    $displayType = 'TRF MASUK';
                    $typeColor = [16, 185, 129]; // Green for Jenis
                    $amountColor = [0, 0, 0]; // Black for Nominal
                    $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                    break;
                default:
                    $displayType = 'LAINNYA';
                    $typeColor = [0, 0, 0]; // Black for Jenis
                    $amountColor = [0, 0, 0]; // Black for Nominal
                    $amount = 'Rp ' . number_format($row['jumlah'], 0, ',', '.');
                    break;
            }

            $pdf->Cell($col_width[0], $row_height, $no, 1, 0, 'C', 1);
            $pdf->Cell($col_width[1], $row_height, date('d/m/Y H:i', strtotime($row['created_at'])), 1, 0, 'C', 1);
            $pdf->Cell($col_width[2], $row_height, $row['no_transaksi'], 1, 0, 'L', 1);
            
            $pdf->SetTextColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[3], $row_height, $displayType, 1, 0, 'L', 1);
            
            $pdf->SetTextColor($amountColor[0], $amountColor[1], $amountColor[2]);
            $pdf->Cell($col_width[4], $row_height, $amount, 1, 0, 'R', 1);
            
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell($col_width[5], $row_height, 'Rp ' . number_format($row['running_balance'], 0, ',', '.'), 1, 1, 'R', 1);

            $no++;
        }
    }

    // Final Balance
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(220, 226, 240);
    $combined_width = array_sum(array_slice($col_width, 0, 5));
    $pdf->Cell($combined_width, 6, 'SALDO AKHIR', 1, 0, 'R', 1);
    $pdf->SetTextColor(0, 48, 135);
    $pdf->Cell($col_width[5], 6, 'Rp ' . number_format($running_balance, 0, ',', '.'), 1, 1, 'R', 1);
    
    // Disclaimer
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 5, 'Catatan: Laporan ini adalah dokumen resmi dari SCHOBANK. Harap verifikasi saldo Anda secara berkala. Untuk pertanyaan atau ketidaksesuaian, silakan hubungi Customer Service kami di 0800-123-SCHOBANK atau email support@schobank.co.id. Terima kasih atas kepercayaan Anda kepada SCHOBANK.', 0, 'L');

    // Clear output buffer and send PDF
    ob_end_clean();
    $pdf->Output('mutasi_rekening_' . $user_data['no_rekening'] . '_' . date('Ymd') . '.pdf', 'I');

} catch (Exception $e) {
    ob_end_clean();
    handleError("PDF generation error: " . $e->getMessage());
}
?>