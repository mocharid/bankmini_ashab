<?php
// Start output buffering to capture any unintended output
ob_start();

// Suppress warnings during PDF generation
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/tecnickcom/tcpdf/tcpdf.php';

// Initialize variables with default values
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$tabType = $_GET['tab'] ?? 'all';

// Error handling function
function handleError($message) {
    ob_end_clean();
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

    $transactions = [];
    $running_balance = 0;
    $total_setor = 0;
    $total_tarik = 0;
    $total_transfer_masuk = 0;
    $total_transfer_keluar = 0;

    while ($row = $result->fetch_assoc()) {
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
        // Logo
        $image_file = '../../schobank/assets/images/lbank.png';
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

    public function Footer() {
        // Empty footer
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
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(0);

    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 10);

    // Add a page
    $pdf->AddPage();

    // Title and Period
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 7, 'LAPORAN MUTASI REKENING', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 7, 'Periode: ' . date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date)), 0, 1, 'C');

    // Customer Information (Vertical List)
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(180, 7, 'INFORMASI NASABAH', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $customer_info = [
        ['Nama', $user_data['nama']],
        ['No. Rekening', $user_data['no_rekening']],
        ['Kelas', $user_data['nama_kelas'] ?? 'N/A'],
        ['Jurusan', $user_data['nama_jurusan'] ?? 'N/A']
    ];

    $pdf->SetFont('helvetica', '', 9);
    foreach ($customer_info as $info) {
        $pdf->Cell(40, 6, $info[0], 1, 0, 'L');
        $pdf->Cell(140, 6, $info[1], 1, 1, 'L');
    }

    // Summary Section
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(180, 7, 'RINGKASAN TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $colWidth = 180 / 4;
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($colWidth, 6, 'Total Setoran', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Penarikan', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Transfer Masuk', 1, 0, 'C', true);
    $pdf->Cell($colWidth, 6, 'Total Transfer Keluar', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($total_setor, 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($total_tarik, 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($total_transfer_masuk, 0, ',', '.'), 1, 0, 'C');
    $pdf->Cell($colWidth, 6, 'Rp ' . number_format($total_transfer_keluar, 0, ',', '.'), 1, 1, 'C');

    // Transaction Details Section
    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(0, 0, 0);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(180, 7, 'DETAIL TRANSAKSI', 1, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);

    $col_width = [10, 30, 40, 30, 35, 35]; // No, Tanggal, No Transaksi, Jenis, Nominal, Saldo

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($col_width[0], 6, 'No', 1, 0, 'C', true);
    $pdf->Cell($col_width[1], 6, 'Tanggal', 1, 0, 'C', true);
    $pdf->Cell($col_width[2], 6, 'No Transaksi', 1, 0, 'C', true);
    $pdf->Cell($col_width[3], 6, 'Jenis', 1, 0, 'C', true);
    $pdf->Cell($col_width[4], 6, 'Nominal', 1, 0, 'C', true);
    $pdf->Cell($col_width[5], 6, 'Saldo', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 8);
    $row_height = 5;

    if (empty($transactions)) {
        $pdf->Cell(180, $row_height, 'Tidak ada transaksi dalam periode ini.', 1, 1, 'C');
    } else {
        $no = 1;
        foreach ($transactions as $row) {
            $typeColor = [255, 255, 255];
            $displayType = '';

            switch ($row['jenis_mutasi']) {
                case 'setor':
                case 'transfer_masuk':
                    $typeColor = [220, 252, 231]; // Light green
                    $displayType = 'Kredit';
                    break;
                case 'tarik':
                case 'transfer_keluar':
                    $typeColor = [254, 226, 226]; // Light red
                    $displayType = 'Debit';
                    break;
                default:
                    $typeColor = [255, 255, 255];
                    $displayType = 'Lainnya';
                    break;
            }

            $pdf->Cell($col_width[0], $row_height, $no++, 1, 0, 'C');
            $pdf->Cell($col_width[1], $row_height, date('d/m/Y H:i', strtotime($row['created_at'])), 1, 0, 'C');
            $pdf->Cell($col_width[2], $row_height, $row['no_transaksi'], 1, 0, 'L');
            
            $pdf->SetFillColor($typeColor[0], $typeColor[1], $typeColor[2]);
            $pdf->Cell($col_width[3], $row_height, $displayType, 1, 0, 'C', true);
            
            // Nominal Column: Rp left, amount right
            $pdf->SetFillColor(255, 255, 255);
            $x_start = $pdf->GetX();
            $pdf->Cell($col_width[4], $row_height, '', 1, 0, 'C', true); // Draw the cell border
            $pdf->SetXY($x_start, $pdf->GetY());
            $pdf->Cell(10, $row_height, 'Rp', 0, 0, 'L'); // Rp on the left
            $pdf->SetXY($x_start + $col_width[4] - 20, $pdf->GetY()); // Adjust position for amount
            $pdf->Cell(20, $row_height, number_format($row['jumlah'], 0, ',', '.'), 0, 0, 'R');
            
            // Saldo Column: Rp left, amount right
            $x_start = $pdf->GetX();
            $pdf->Cell($col_width[5], $row_height, '', 1, 0, 'C', true); // Draw the cell border
            $pdf->SetXY($x_start, $pdf->GetY());
            $pdf->Cell(10, $row_height, 'Rp', 0, 0, 'L'); // Rp on the left
            $pdf->SetXY($x_start + $col_width[5] - 20, $pdf->GetY()); // Adjust position for amount
            $pdf->Cell(20, $row_height, number_format($row['running_balance'], 0, ',', '.'), 0, 1, 'R');
        }
    }

    // Final Balance
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);
    $combined_width = array_sum(array_slice($col_width, 0, 5));
    $pdf->Cell($combined_width, 6, 'Saldo Akhir', 1, 0, 'C', true);
    
    // Saldo Akhir Value: Rp left, amount right
    $x_start = $pdf->GetX();
    $pdf->Cell($col_width[5], 6, '', 1, 0, 'C', true); // Draw the cell border
    $pdf->SetXY($x_start, $pdf->GetY());
    $pdf->Cell(10, 6, 'Rp', 0, 0, 'L'); // Rp on the left
    $pdf->SetXY($x_start + $col_width[5] - 20, $pdf->GetY()); // Adjust position for amount
    $pdf->Cell(20, 6, number_format($running_balance, 0, ',', '.'), 0, 1, 'R');

    // Clear output buffer and send PDF
    ob_end_clean();
    $pdf->Output('mutasi_rekening_' . $user_data['no_rekening'] . '_' . date('Ymd') . '.pdf', 'D');

} catch (Exception $e) {
    ob_end_clean();
    handleError("PDF generation error: " . $e->getMessage());
}
?>