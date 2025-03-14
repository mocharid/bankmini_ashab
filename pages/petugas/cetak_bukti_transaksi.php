<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php'; // Pastikan TCPDF sudah diinstal
use TCPDF as TCPDF;

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$error = null;
$success = null;
$transaksi = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['no_transaksi'])) {
    $no_transaksi = trim($_POST['no_transaksi']);

    // Fetch transaction details
    $query = "SELECT t.*, r.no_rekening, u.nama 
              FROM transaksi t 
              JOIN rekening r ON t.rekening_id = r.id 
              JOIN users u ON r.user_id = u.id 
              WHERE t.no_transaksi = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('s', $no_transaksi);
    $stmt->execute();
    $result = $stmt->get_result();
    $transaksi = $result->fetch_assoc();

    if (!$transaksi) {
        $error = "Nomor transaksi tidak ditemukan!";
    }
}

// Function to generate struk
function generateStruk($data) {
    date_default_timezone_set('Asia/Jakarta');

    $pdf = new TCPDF('P', 'mm', array(80, 130), true, 'UTF-8', false);

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    $pdf->SetCreator('SCHOBANK SYSTEM');
    $pdf->SetAuthor('SCHOBANK SYSTEM');
    $pdf->SetTitle('Bukti Transaksi');

    $pdf->SetMargins(7, 7, 7);
    $pdf->SetAutoPageBreak(TRUE, 7);

    // Add a page
    $pdf->AddPage();

    // --- HEADER SECTION ---
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, 'SCHOBANK', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(0, 4, 'BUKTI TRANSAKSI', 0, 1, 'C');
    
    // Add horizontal line
    $pdf->Line(7, $pdf->GetY() + 2, 73, $pdf->GetY() + 2);
    $pdf->Ln(4);

    // --- TRANSACTION DETAILS ---
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(0, 4, 'DETAIL TRANSAKSI', 0, 1, 'C');
    $pdf->Ln(2);

    // Transaction details in table-like format with consistent alignment
    $pdf->SetFont('helvetica', '', 8);
    
    // Define a consistent width for labels and position for colons
    $labelWidth = 28;
    $colonWidth = 4;
    $valueWidth = 34;
    
    // Format each row with aligned colons
    $pdf->Cell($labelWidth, 4, 'TANGGAL', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, date('d/m/Y', strtotime($data['created_at'])), 0, 1, 'R');
    
    $pdf->Cell($labelWidth, 4, 'NO. TRANSAKSI', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['no_transaksi'], 0, 1, 'R');
    
    $pdf->Cell($labelWidth, 4, 'REKENING', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['no_rekening'], 0, 1, 'R');
    
    $pdf->Cell($labelWidth, 4, 'NAMA', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, $data['nama'], 0, 1, 'R');
    
    $pdf->Cell($labelWidth, 4, 'JENIS TRANSAKSI', 0, 0, 'L');
    $pdf->Cell($colonWidth, 4, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 4, ucfirst($data['jenis_transaksi']), 0, 1, 'R');
    
    // Add horizontal line
    $pdf->Line(7, $pdf->GetY() + 2, 73, $pdf->GetY() + 2);
    $pdf->Ln(4);
    
    // Jumlah transfer (bigger and bold) with aligned colon
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->Cell($labelWidth, 5, 'JUMLAH', 0, 0, 'L');
    $pdf->Cell($colonWidth, 5, ':', 0, 0, 'L');
    $pdf->Cell($valueWidth, 5, 'Rp ' . number_format($data['jumlah'], 0, ',', '.'), 0, 1, 'R');
    
    // Add horizontal line
    $pdf->Line(7, $pdf->GetY() + 2, 73, $pdf->GetY() + 2);
    $pdf->Ln(3);
    
    // --- FOOTER SECTION (moved up to ensure single page) ---
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->Cell(0, 3, 'Terima kasih telah menggunakan layanan', 0, 1, 'C');
    $pdf->SetFont('helvetica', 'BI', 8);
    $pdf->Cell(0, 3, 'SCHOBANK SYSTEM', 0, 1, 'C');
    
    // --- QR CODE with only transaction number (smaller size) ---
    $style = array(
        'border' => false,
        'vpadding' => 'auto',
        'hpadding' => 'auto',
        'fgcolor' => array(0, 0, 0),
        'bgcolor' => false,
        'module_width' => 1, // width of a single module in points
        'module_height' => 1 // height of a single module in points
    );

    // MODIFIED: Only use transaction number for QR code
    $qrContent = $data['no_transaksi'];

    // Generate QR code - centered on the receipt (smaller size: 25x25)
    $pdf->write2DBarcode($qrContent, 'QRCODE,L', 27.5, $pdf->GetY(), 25, 25, $style, 'N');
    
    // MODIFIED: Reduced line spacing after QR code to position text closer
    $pdf->Ln(1);
    
    // Add current time with WIB time zone and note with reduced spacing
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(0, 3, 'Dicetak pada: ' . date('d/m/Y H:i:s') . ' WIB', 0, 1, 'C');
    
    // Add note
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->MultiCell(0, 3, 'Bukti transaksi ini merupakan bukti yang sah dan tidak memerlukan tanda tangan.', 0, 'C');
    
    // Simpan PDF ke folder yang dapat diakses oleh browser
    $pdfFolder = __DIR__ . '/struk/'; // Path ke folder struk
    if (!is_dir($pdfFolder)) {
        if (mkdir($pdfFolder, 0755, true)) {
            echo "Folder struk berhasil dibuat: " . $pdfFolder . "<br>";
        } else {
            echo "Gagal membuat folder struk!<br>";
        }
    }

    // Path lengkap ke file PDF
    $pdfFilePath = $pdfFolder . 'struk_' . $data['no_transaksi'] . '.pdf';

    // Simpan file PDF
    $pdf->Output($pdfFilePath, 'F');

    // URL untuk mengakses file PDF
    $pdfUrl = '/bankmini/pages/petugas/struk/struk_' . $data['no_transaksi'] . '.pdf';

    return [
        'file_path' => $pdfFilePath,
        'file_url' => $pdfUrl
    ];
}

// Generate struk if transaction is found
if ($transaksi) {
    $pdfData = generateStruk($transaksi);
    $pdfUrl = $pdfData['file_url'];
    $success = "Bukti transaksi berhasil dibuat!";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Cetak Bukti Transaksi - SCHOBANK SYSTEM</title>
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
    animation: slideIn 0.5s forwards, slideOut 0.5s 3s forwards;
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
</style><style>
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
    animation: slideIn 0.5s forwards, slideOut 0.5s 3s forwards;
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
</style><style>
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
    animation: slideIn 0.5s forwards, slideOut 0.5s 3s forwards;
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
            <h2><i class="fas fa-print"></i> Cetak Bukti Transaksi</h2>
            <p>Cetak bukti transaksi berdasarkan nomor transaksi</p>
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
                    <a href="<?= $pdfUrl ?>" target="_blank" class="btn-view">
                        <i class="fas fa-eye"></i> Lihat Bukti Transaksi
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Form Pencarian Transaksi -->
        <div class="transfer-card">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="no_transaksi">
                        <i class="fas fa-search"></i> Nomor Transaksi
                    </label>
                    <input type="text" id="no_transaksi" name="no_transaksi" class="form-control" 
                           placeholder="Masukkan nomor transaksi" required>
                </div>
                <button type="submit" class="btn-verify" id="verify-button">
                    <i class="fas fa-search"></i> <span id="verify-text">Cari Transaksi</span>
                </button>
            </form>
        </div>
    </div>
    <script>
document.addEventListener('DOMContentLoaded', function() {
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
        }, 5000);
    }

    if (successAlert) {
        setTimeout(function() {
            successAlert.style.opacity = '0';
            successAlert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                successAlert.style.display = 'none';
            }, 500);
        }, 5000);
    }

    if (notifBadge) {
        setTimeout(function() {
            notifBadge.style.opacity = '0';
            setTimeout(() => {
                notifBadge.style.display = 'none';
            }, 500);
        }, 5000);
    }
});
</script>
</body>
</html>