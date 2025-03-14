<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php'; 
use TCPDF as TCPDF;

function generateStruk($data) {
    date_default_timezone_set('Asia/Jakarta');

    // Create custom TCPDF class to add decorative elements
    class ReceiptPDF extends TCPDF {
        public function Header() {
            // Empty header
        }
        
        public function Footer() {
            // Add decorative footer pattern (receipt-like)
            $this->SetY(-5);
            $pattern_width = 2;
            $page_width = $this->getPageWidth();
            $pattern_count = floor($page_width / $pattern_width);
            
            $this->SetDrawColor(200, 200, 200);
            $this->SetLineWidth(0.1);
            
            for ($i = 0; $i < $pattern_count; $i++) {
                $this->Line(
                    $i * $pattern_width, 
                    $this->GetY(), 
                    $i * $pattern_width + $pattern_width/2, 
                    $this->GetY() + 2
                );
            }
        }
    }

    // Create new receipt-style PDF
    $pdf = new ReceiptPDF('P', 'mm', array(80, 140), true, 'UTF-8', false);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true); // Enable our custom footer

    $pdf->SetCreator('SCHOBANK SYSTEM');
    $pdf->SetAuthor('SCHOBANK SYSTEM');
    $pdf->SetTitle('Bukti Transaksi');

    // Set receipt-style margins (slightly narrower)
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetAutoPageBreak(TRUE, 10);

    // Add background pattern (subtle thermal paper texture)
    $pdf->SetFillColor(252, 252, 250);

    // Add a page
    $pdf->AddPage();
    $pdf->Rect(0, 0, $pdf->getPageWidth(), $pdf->getPageHeight(), 'F');

    // Add decorative top border
    $pdf->SetDrawColor(100, 100, 100);
    $pdf->SetLineWidth(0.2);
    $width = $pdf->getPageWidth() - 10;
    $startX = 5;
    
    // Decorative top pattern
    $pdf->SetLineStyle(array('dash' => '1,1', 'color' => array(150, 150, 150)));
    $pdf->Line($startX, 4, $startX + $width, 4);
    
    // --- HEADER SECTION ---
    // Logo or bank name with more professional styling
    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, 'SCHOBANK', 0, 1, 'C');
    
    // Add receipt subtitle with decorative elements
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, '* * * BUKTI TRANSFER * * *', 0, 1, 'C');
    
    // Add store-like address (common in real receipts)
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(0, 3, 'Jl. K.H. Saleh No.57A', 0, 1, 'C');
    $pdf->Cell(0, 3, 'Pabuaran Cianjur 43212 ', 0, 1, 'C');
    $pdf->Cell(0, 3, 'Telp: (62) 263 267740', 0, 1, 'C');
    
    // Add separating dashed line (like real receipts)
    $pdf->Ln(1);
    $pdf->SetLineStyle(array('dash' => '0.5,0.5', 'color' => array(100, 100, 100)));
    $pdf->Line($startX, $pdf->GetY(), $startX + $width, $pdf->GetY());
    $pdf->Ln(3);

    // --- TRANSACTION DETAILS ---
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 5, 'DETAIL TRANSAKSI', 0, 1, 'C', true);
    $pdf->Ln(2);

    // Transaction details in table-like format with styling
    $pdf->SetFont('courier', '', 8); // Courier for that authentic receipt look
    $pdf->SetTextColor(40, 40, 40);
    
    // Define a consistent width for labels and position for colons
    $labelWidth = 28;
    $colonWidth = 2;
    $valueWidth = 40;
    
    // Format each row with aligned colons - receipt style
    $pdf->Cell($labelWidth, 4, 'TANGGAL', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, date('d/m/Y', strtotime($data['tanggal'])), 0, 1, 'L');
    
    
    $pdf->Cell($labelWidth, 4, 'NO. TRANSAKSI', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['no_transaksi'], 0, 1, 'L');
    
    $pdf->SetLineStyle(array('dash' => '0.2,0.2', 'color' => array(150, 150, 150)));
    $pdf->Line($startX, $pdf->GetY() + 1, $startX + $width, $pdf->GetY() + 1);
    $pdf->Ln(3);
    
    $pdf->Cell($labelWidth, 4, 'REKENING ASAL', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['rekening_asal'], 0, 1, 'L');
    
    $pdf->Cell($labelWidth, 4, 'REKENING TUJUAN', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['rekening_tujuan'], 0, 1, 'L');
    
    $pdf->Cell($labelWidth, 4, 'NAMA PENERIMA', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['nama_penerima'], 0, 1, 'L');
    
    // Add horizontal line with receipt styling
    $pdf->SetLineStyle(array('dash' => '0.2,0.2', 'color' => array(150, 150, 150)));
    $pdf->Line($startX, $pdf->GetY() + 1, $startX + $width, $pdf->GetY() + 1);
    $pdf->Ln(3);
    
    // Amount section with highlight box
    $pdf->SetDrawColor(100, 100, 100);
    $pdf->SetLineWidth(0.2);
    $pdf->Rect($startX, $pdf->GetY(), $width, 8, 'D');
    $pdf->SetFillColor(245, 245, 245);
    $pdf->Rect($startX + 0.2, $pdf->GetY() + 0.2, $width - 0.4, 7.6, 'F');
    
    // Amount text with better formatting
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($labelWidth, 7, ' JUMLAH', 0, 0, 'L');
    $pdf->Cell($colonWidth, 7, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 7, 'Rp ' . number_format($data['jumlah'], 0, ',', '.'), 0, 1, 'L');
    $pdf->Ln(2);
    
    // Add horizontal closing line
    $pdf->SetLineStyle(array('width' => 0.3, 'color' => array(100, 100, 100)));
    $pdf->Line($startX, $pdf->GetY(), $startX + $width, $pdf->GetY());
    $pdf->Ln(3);
    
    // --- FOOTER SECTION ---
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(0, 3, 'Terima kasih telah menggunakan layanan', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'BI', 8);
    $pdf->Cell(0, 4, 'SCHOBANK SYSTEM', 0, 1, 'C');
    
    // --- QR CODE with more professional design ---
    $style = array(
        'border' => false,
        'vpadding' => 'auto',
        'hpadding' => 'auto',
        'fgcolor' => array(0, 0, 0),
        'bgcolor' => false,
        'module_width' => 1,
        'module_height' => 1
    );

    // QR code with verification text
    $pdf->SetFont('helvetica', '', 6);
    $pdf->Cell(0, 3, 'SCAN UNTUK VERIFIKASI', 0, 1, 'C');
    
    // Generate centered QR code
    $qrContent = $data['no_transaksi'];
    $pdf->write2DBarcode($qrContent, 'QRCODE,L', 27.5, $pdf->GetY(), 25, 25, $style, 'N');
    $pdf->Ln(0);
    
    // Add verification number below QR code
    $pdf->SetFont('courier', 'B', 7);
    $pdf->Cell(0, 3, $data['no_transaksi'], 0, 1, 'C');
    $pdf->Ln(1);
    
    
    // Add legal note with authentic styling
    $pdf->SetFont('helvetica', 'I', 6);
    $pdf->MultiCell(0, 3, 'Bukti transfer ini merupakan bukti yang sah dan tidak memerlukan tanda tangan.', 0, 'C');
    
    // Add final decorative element
    $pdf->Ln(1);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, '* * * * * * * * * * * * * *', 0, 1, 'C');
    
    // Path management for saving PDF
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    $app_path = '/bankmini';
    $upload_dir = $doc_root . $app_path . '/uploads/struk/';
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            error_log("Gagal membuat folder struk: $upload_dir");
            $upload_dir = sys_get_temp_dir() . '/struk/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
        }
    }
    
    // File path and URL
    $filename = 'struk_' . $data['no_transaksi'] . '.pdf';
    $filepath = $upload_dir . $filename;
    
    // Save the PDF file
    $pdf->Output($filepath, 'F');
    
    // Return file information
    return [
        'file_path' => $filepath,
        'file_url' => $app_path . '/uploads/struk/' . $filename
    ];
}

// Pastikan user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user's rekening data
$query = "SELECT r.id, r.saldo, r.no_rekening 
          FROM rekening r 
          WHERE r.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$rekening_data = $result->fetch_assoc();

if ($rekening_data) {
    $saldo = $rekening_data['saldo'];
    $no_rekening = $rekening_data['no_rekening'];
    $rekening_id = $rekening_data['id'];
} else {
    $saldo = 0;
    $no_rekening = 'N/A';
    $rekening_id = null;
}

// Initialize variables
$current_step = 1;
$tujuan_data = null;
$input_jumlah = "";
$transfer_amount = null;
$transfer_rekening = null;
$transfer_name = null;
$error = null;
$success = null;
$pdfUrl = null;

// Handle account number verification (Step 1)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'verify_account') {
    $rekening_tujuan = trim($_POST['rekening_tujuan']);

    // Validate input
    if (empty($rekening_tujuan)) {
        $error = "Nomor rekening tujuan harus diisi!";
    } elseif ($rekening_tujuan == $no_rekening) {
        $error = "Tidak dapat transfer ke rekening sendiri!";
    } else {
        // Check if tujuan rekening exists and get user details
        $query = "SELECT r.id, r.user_id, r.no_rekening, u.nama 
                 FROM rekening r 
                 JOIN users u ON r.user_id = u.id 
                 WHERE r.no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $rekening_tujuan);
        $stmt->execute();
        $result = $stmt->get_result();
        $tujuan_data = $result->fetch_assoc();

        if ($tujuan_data) {
            $current_step = 2; // Move to step 2
            // Store tujuan data in session for persistence between steps
            $_SESSION['tujuan_data'] = $tujuan_data;
        } else {
            $error = "Nomor rekening tujuan tidak ditemukan!";
        }
    }
}

// Handle back button action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'go_back') {
    $current_step = 1;
    // Clear any stored tujuan data
    if (isset($_SESSION['tujuan_data'])) {
        unset($_SESSION['tujuan_data']);
    }
}

// Restore tujuan data from session if we're on step 2 or 3
if (($current_step == 2 || $current_step == 3) && isset($_SESSION['tujuan_data'])) {
    $tujuan_data = $_SESSION['tujuan_data'];
}

// Handle transfer submission (Step 2)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'confirm_transfer') {
    // Check if tujuan_data is available either from form or session
    if (isset($_POST['rekening_tujuan_id']) && !empty($_POST['rekening_tujuan_id'])) {
        $rekening_tujuan_id = $_POST['rekening_tujuan_id'];
        $rekening_tujuan = $_POST['rekening_tujuan'];
        $rekening_tujuan_nama = $_POST['rekening_tujuan_nama'];
        
        // Make sure we have user_id for recipient
        if (isset($_POST['rekening_tujuan_user_id']) && !empty($_POST['rekening_tujuan_user_id'])) {
            $rekening_tujuan_user_id = $_POST['rekening_tujuan_user_id'];
        } elseif (isset($_SESSION['tujuan_data']['user_id'])) {
            $rekening_tujuan_user_id = $_SESSION['tujuan_data']['user_id'];
        } else {
            // If still not available, query the database
            $query = "SELECT user_id FROM rekening WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $rekening_tujuan_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            
            if ($user_data && isset($user_data['user_id'])) {
                $rekening_tujuan_user_id = $user_data['user_id'];
            } else {
                $error = "Tidak dapat menemukan data rekening tujuan!";
                $current_step = 2;
            }
        }
    } elseif (isset($_SESSION['tujuan_data'])) {
        $rekening_tujuan_id = $_SESSION['tujuan_data']['id'];
        $rekening_tujuan = $_SESSION['tujuan_data']['no_rekening'];
        $rekening_tujuan_nama = $_SESSION['tujuan_data']['nama'];
        $rekening_tujuan_user_id = $_SESSION['tujuan_data']['user_id'];
    } else {
        $error = "Data rekening tujuan tidak tersedia!";
        $current_step = 1;
    }

    // Get data from form
    $jumlah = floatval(str_replace(',', '.', $_POST['jumlah']));
    $input_jumlah = $jumlah;
    $pin = trim($_POST['pin']);

    // Continue with validation if we have the required data
    if (!isset($error)) {
        // Validate input
        if (empty($rekening_tujuan) || $jumlah <= 0 || empty($pin)) {
            $error = "Semua field harus diisi dengan benar!";
            $current_step = 2;
        } elseif ($jumlah > $saldo) {
            $error = "Saldo tidak mencukupi!";
            $current_step = 2;
        } else {
            // Verify PIN
            $query = "SELECT pin FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();

            if (!$user_data || $user_data['pin'] !== $pin) {
                $error = "PIN yang Anda masukkan salah!";
                $current_step = 2;
            } else {
                // Verify that rekening_tujuan_id exists in the rekening table
                $check_query = "SELECT id FROM rekening WHERE id = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("i", $rekening_tujuan_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows == 0) {
                    $error = "Rekening tujuan tidak valid!";
                    $current_step = 2;
                } else {
                    // Process transfer
                    try {
                        $conn->begin_transaction();
            
                        // Generate unique transaction number
                        $no_transaksi = 'TF' . date('YmdHis') . rand(1000, 9999);
            
                        // Insert transaction record
                        $query = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, rekening_tujuan_id, status, created_at) 
                                VALUES (?, ?, 'transfer', ?, ?, 'approved', NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("sidi", $no_transaksi, $rekening_id, $jumlah, $rekening_tujuan_id);
                        
                        if (!$stmt->execute()) {
                            throw new Exception("Error inserting transaction: " . $stmt->error);
                        }
            
                        // Update sender's balance
                        $query = "UPDATE rekening SET saldo = saldo - ? WHERE id = ? AND saldo >= ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("did", $jumlah, $rekening_id, $jumlah);
                        
                        if (!$stmt->execute() || $stmt->affected_rows == 0) {
                            throw new Exception("Error updating sender balance: " . $stmt->error);
                        }
            
                        // Update recipient's balance
                        $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("di", $jumlah, $rekening_tujuan_id);
                        
                        if (!$stmt->execute() || $stmt->affected_rows == 0) {
                            throw new Exception("Error updating recipient balance: " . $stmt->error);
                        }
            
                        // Get sender's updated balance
                        $query = "SELECT saldo FROM rekening WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $rekening_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $updated_rekening = $result->fetch_assoc();
                        
                        if (!$updated_rekening) {
                            throw new Exception("Error fetching updated sender balance");
                        }
                        
                        $saldo_baru_pengirim = $updated_rekening['saldo'];
            
                        // Get recipient's updated balance
                        $query = "SELECT saldo FROM rekening WHERE id = ?";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("i", $rekening_tujuan_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $updated_rekening_tujuan = $result->fetch_assoc();
                        
                        if (!$updated_rekening_tujuan) {
                            throw new Exception("Error fetching updated recipient balance");
                        }
                        
                        $saldo_baru_penerima = $updated_rekening_tujuan['saldo'];
            
                        // Kirim notifikasi ke pengirim
                        $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rekening_tujuan . " atas nama " . $rekening_tujuan_nama . " berhasil. Saldo baru: Rp " . number_format($saldo_baru_pengirim, 0, ',', '.');
                        $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                        $stmt_notifikasi_pengirim = $conn->prepare($query_notifikasi_pengirim);
                        $stmt_notifikasi_pengirim->bind_param('is', $user_id, $message_pengirim);
                        
                        if (!$stmt_notifikasi_pengirim->execute()) {
                            throw new Exception("Error inserting sender notification: " . $stmt_notifikasi_pengirim->error);
                        }
            
                        // Kirim notifikasi ke penerima
                        $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $no_rekening . ". Saldo baru: Rp " . number_format($saldo_baru_penerima, 0, ',', '.');
                        $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                        $stmt_notifikasi_penerima = $conn->prepare($query_notifikasi_penerima);
                        $stmt_notifikasi_penerima->bind_param('is', $rekening_tujuan_user_id, $message_penerima);
                        
                        if (!$stmt_notifikasi_penerima->execute()) {
                            throw new Exception("Error inserting recipient notification: " . $stmt_notifikasi_penerima->error);
                        }
            
                        // Commit transaction
                        $conn->commit();
            
                        // Success message
                        $success = "Transfer berhasil!";
                        $current_step = 3; // Receipt display step
            
                        // Generate struk
                        $strukData = [
                            'no_transaksi' => $no_transaksi,
                            'tanggal' => date('Y-m-d'),
                            'rekening_asal' => $no_rekening,
                            'rekening_tujuan' => $rekening_tujuan,
                            'nama_penerima' => $rekening_tujuan_nama,
                            'jumlah' => $jumlah,
                        ];
                        $pdfData = generateStruk($strukData);
                        $pdfFilePath = $pdfData['file_path'];
                        $pdfUrl = $pdfData['file_url'];
            
                        // Store transfer details for display
                        $transfer_amount = $jumlah;
                        $transfer_rekening = $rekening_tujuan;
                        $transfer_name = $rekening_tujuan_nama;
                        
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollback();
                        $error = "Error: " . $e->getMessage();
                        $current_step = 2; // Return to transfer form
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Transfer - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
                :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            text-decoration: none;
            z-index: 2;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .transfer-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 30px;
        }

        .transfer-card:hover {
            box-shadow: var(--shadow-md);
        }

        .saldo-display {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            text-align: center;
        }

        .saldo-title {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }

        .saldo-amount {
            font-size: 28px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .btn-transfer, .btn-verify, .btn-back {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: var(--transition);
        }

        .btn-transfer:hover, .btn-verify:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
        }

        .btn-back {
            background-color: #6c757d;
            margin-bottom: 10px;
        }

        .btn-back:hover {
            background-color: #5a6268;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #f87171;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid #4ade80;
        }

        /* Success animation */
        .success-animation {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes popIn {
            0% {
                opacity: 0;
                transform: scale(0.5);
            }
            70% {
                transform: scale(1.1);
            }
            100% {
                opacity: 1;
                transform: scale(1);
            }
        }

        .checkmark {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: block;
            background-color: #4ade80;
            position: relative;
            margin-bottom: 15px;
        }

        .checkmark::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 25px;
            height: 15px;
            border-bottom: 4px solid white;
            border-right: 4px solid white;
            transform: translate(-50%, -60%) rotate(45deg);
            animation: checkAnim 0.5s forwards;
        }

        @keyframes checkAnim {
            0% {
                width: 0;
                height: 0;
                opacity: 0;
            }
            100% {
                width: 25px;
                height: 15px;
                opacity: 1;
            }
        }

        .waves {
            position: relative;
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .waves::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: rgba(74, 222, 128, 0.3);
            animation: wave 2s ease-out infinite;
        }

        @keyframes wave {
            0% {
                transform: scale(0);
                opacity: 1;
            }
            100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }

        .success-title {
            font-size: 20px;
            font-weight: 600;
            color: #166534;
        }

        .success-details {
            font-size: 16px;
            color: var(--text-secondary);
            margin-top: 5px;
            text-align: center;
        }

        .notif-badge {
        position: fixed;
        top: 20px;
        right: 20px;
        background-color: var(--secondary-color);
        color: white;
        padding: 10px 15px;
        border-radius: 8px;
        box-shadow: var(--shadow-md);
        z-index: 1000;
        transform: translateX(120%);
        animation: slideIn 0.5s forwards, slideOut 0.5s 3s forwards; /* Changed from 5s to 3s */
        display: flex;
        align-items: center;
        gap: 10px;
    }

        @keyframes slideIn {
            to {
                transform: translateX(0);
            }
        }

        @keyframes slideOut {
            to {
                transform: translateX(120%);
            }
        }

        .notif-badge i {
            animation: slideIn 0.5s forwards, slideOut 0.5s 5s forwards;
        }

        /* Steps indicator */
        .steps-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            flex: 1;
            max-width: 150px;
        }

        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 3px;
            background-color: #ddd;
            z-index: 1;
        }

        .step.active:not(:last-child)::after {
            background-color: var(--primary-color);
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #ddd;
            color: #666;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-bottom: 5px;
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .step.active .step-number {
            background-color: var(--primary-color);
            color: white;
        }

        .step-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-align: center;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 500;
        }

        /* Recipient info card */
        .recipient-card {
            background-color: var(--primary-light);
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .recipient-title {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 5px;
        }

        .recipient-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 5px;
        }

        .recipient-account {
            font-size: 14px;
            color: var(--text-secondary);
        }

        /* Confirmation container */
        .confirmation-container {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .confirmation-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px dashed #ddd;
        }

        .confirmation-item:last-child {
            border-bottom: none;
        }

        .confirmation-label {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .confirmation-value {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-primary);
        }
        /* PIN Input styling */
        .input-wrapper {
            position: relative;
            width: 100%;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 15px 12px 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
            transition: var(--transition);
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition);
        }

        .password-toggle:hover {
            color: var(--primary-color);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: 22px;
            }

            .transfer-card {
                padding: 20px;
            }

            .saldo-amount {
                font-size: 24px;
            }
            
            .steps-indicator {
                padding: 0 10px;
            }
            
            .step:not(:last-child)::after {
                width: 70%;
                right: -35%;
            }
        }
        .btn-view {
            display: inline-block;
            margin-top: 15px;
            padding: 10px 20px;
            background-color: #4CAF50; /* Warna hijau */
            color: white;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }

        .btn-view:hover {
            background-color: #45a049; /* Warna hijau lebih gelap */
            transform: translateY(-2px);
        }

        .btn-view i {
            margin-right: 8px;
        }

</style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-exchange-alt"></i> Transfer Lokal</h2>
            <p>Kirim dana ke rekening lain dengan cepat dan aman</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <!-- Step indicator -->
        <div class="steps-indicator">
            <div class="step <?= $current_step >= 1 ? 'active' : '' ?>">
                <div class="step-number">1</div>
                <div class="step-label">Masukkan Rekening</div>
            </div>
            <div class="step <?= $current_step >= 2 ? 'active' : '' ?>">
                <div class="step-number">2</div>
                <div class="step-label">Masukkan Nominal</div>
            </div>
            <div class="step <?= $current_step >= 3 ? 'active' : '' ?>">
                <div class="step-number">3</div>
                <div class="step-label">Konfirmasi</div>
            </div>
        </div>

        <!-- Notifikasi Error -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger" id="error-alert">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Notifikasi Sukses -->
        <?php if (isset($success)): ?>
            <div class="alert alert-success" id="success-alert">
                <div class="success-animation">
                    <div class="waves"><span class="checkmark"></span></div>
                    <div class="success-title"><?= $success ?></div>
                    <div class="success-details">Rp <?= number_format($transfer_amount, 0, ',', '.') ?> telah ditransfer ke rekening <?= $transfer_rekening ?> atas nama <?= $transfer_name ?></div>
                    <?php if (file_exists($pdfFilePath)): ?>
                    <a href="<?= $pdfUrl ?>" target="_blank" class="btn-view">
                        <i class="fas fa-eye"></i> Lihat Bukti Transfer
                    </a>
                    <?php else: ?>
                        <div class="alert alert-danger">File bukti transfer tidak ditemukan.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="notif-badge">
                <i class="fas fa-check-circle"></i>
                <span>Transfer Berhasil!</span>
            </div>
        <?php endif; ?>

        <!-- Form Transfer -->
        <div class="transfer-card">
            <div class="saldo-display">
                <div class="saldo-title">Saldo Anda</div>
                <div class="saldo-amount">Rp <?= number_format($saldo, 0, ',', '.') ?></div>
            </div>

            <?php if ($current_step == 1): ?>
                <!-- Step 1: Enter account number -->
                <form method="POST" action="" id="verifyForm">
                    <input type="hidden" name="action" value="verify_account">
                    <div class="form-group">
                        <label for="rekening_tujuan">
                            <i class="fas fa-user"></i> Nomor Rekening Tujuan
                        </label>
                        <input type="text" id="rekening_tujuan" name="rekening_tujuan" class="form-control" 
                               placeholder="Masukkan nomor rekening tujuan" required>
                    </div>
                    <button type="submit" class="btn-verify" id="verify-button">
                        <i class="fas fa-search"></i> <span id="verify-text">Cek Rekening</span>
                        <span class="loading-icon" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                </form>
            <?php elseif ($current_step == 2): ?>
                <!-- Step 2: Enter amount and PIN -->
                <?php if ($tujuan_data): ?>
                    <div class="recipient-card">
                        <div class="recipient-title">Penerima</div>
                        <div class="recipient-name"><?= htmlspecialchars($tujuan_data['nama']) ?></div>
                        <div class="recipient-account">No. Rekening: <?= htmlspecialchars($tujuan_data['no_rekening']) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="transferForm">
                    <input type="hidden" name="action" value="confirm_transfer">
                    <input type="hidden" name="rekening_tujuan" value="<?= htmlspecialchars($tujuan_data['no_rekening']) ?>">
                    <input type="hidden" name="rekening_tujuan_nama" value="<?= htmlspecialchars($tujuan_data['nama']) ?>">
                    <input type="hidden" name="rekening_tujuan_id" value="<?= htmlspecialchars($tujuan_data['id']) ?>">
                    <input type="hidden" name="tujuan_user_id" value="<?= htmlspecialchars($tujuan_data['user_id']) ?>">
                    
                    <div class="form-group">
                        <label for="jumlah">
                            <i class="fas fa-money-bill-wave"></i> Jumlah Transfer
                        </label>
                        <input type="number" id="jumlah" name="jumlah" class="form-control" 
                               placeholder="Masukkan jumlah transfer" required value="<?= htmlspecialchars($input_jumlah) ?>">
                    </div>

                    <div class="form-group">
                        <label for="pin">
                            <i class="fas fa-lock"></i> Masukkan PIN
                        </label>
                        <div class="input-wrapper">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="pin" name="pin" required placeholder="Masukkan PIN Anda" maxlength="6">
                            <i class="fas fa-eye password-toggle" id="toggle-pin" data-target="pin"></i>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-back" id="backButton">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </button>
                    
                    <button type="submit" class="btn-transfer" id="transfer-button">
                        <i class="fas fa-paper-plane" id="transfer-icon"></i> <span id="transfer-text">Lanjutkan</span>
                        <span class="transfer-loading" style="display:none;"><i class="fas fa-spinner fa-spin"></i></span>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
        
            // Set durasi notifikasi menjadi 10 detik (10000 milidetik)
            const notificationDismissTime = 10000;

            // Auto-dismiss alerts setelah waktu tertentu
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            const notifBadge = document.querySelector('.notif-badge');

            if (errorAlert) {
                setTimeout(function() {
                    errorAlert.style.opacity = '0';
                    errorAlert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        errorAlert.style.display = 'none';
                    }, 500);
                }, notificationDismissTime);
            }

            if (successAlert) {
                setTimeout(function() {
                    successAlert.style.opacity = '0';
                    successAlert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        successAlert.style.display = 'none';
                    }, 500);
                }, notificationDismissTime);
            }

            if (notifBadge) {
                setTimeout(function() {
                    notifBadge.style.opacity = '0';
                    setTimeout(() => {
                        notifBadge.style.display = 'none';
                    }, 500);
                }, notificationDismissTime);
            }

            // Handle toggle password visibility
            const togglePasswordButtons = document.querySelectorAll('.password-toggle');
            togglePasswordButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    }
                });
            });

            // Handle back button
            const backButton = document.getElementById('backButton');
            if (backButton) {
                backButton.addEventListener('click', function() {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'go_back';
                    form.appendChild(actionInput);
                    document.body.appendChild(form);
                    form.submit();
                });
            }
        });
    </script>
</body>
</html>