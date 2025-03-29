<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Jakarta');

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
        $query = "SELECT r.id, r.user_id, r.no_rekening, u.nama, u.email 
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
    // Check if tujuan_data is available in session
    if (isset($_SESSION['tujuan_data'])) {
        $rekening_tujuan_id = $_SESSION['tujuan_data']['id'];
        $rekening_tujuan = $_SESSION['tujuan_data']['no_rekening'];
        $rekening_tujuan_nama = $_SESSION['tujuan_data']['nama'];
        $rekening_tujuan_user_id = $_SESSION['tujuan_data']['user_id'];
        $rekening_tujuan_email = $_SESSION['tujuan_data']['email'];
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
            $query = "SELECT pin, email, nama FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();

            if (!$user_data || $user_data['pin'] !== $pin) {
                $error = "PIN yang Anda masukkan salah!";
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
                    
                    // Kirim email bukti transfer ke pengirim
                    try {
                        // Konfigurasi PHPMailer
                        $mail = new PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'mocharid.ip@gmail.com';
                        $mail->Password = 'spjs plkg ktuu lcxh';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 587;
                        $mail->CharSet = 'UTF-8';
                        
                        // Pengaturan email pengirim
                        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
                        $mail->addAddress($user_data['email'], $user_data['nama']);
                        
                        // Subject email
                        $mail->isHTML(true);
                        $mail->Subject = 'Bukti Transfer SCHOBANK - ' . $no_transaksi;
                        
                        // Isi email dengan bukti transfer - Desain baru yang lebih modern
                        $mail->Body = '
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <meta charset="UTF-8">
                            <meta name="viewport" content="width=device-width, initial-scale=1.0">
                            <title>Bukti Transfer - SCHOBANK</title>
                            <style>
                                /* Reset styles */
                                body, html {
                                    margin: 0;
                                    padding: 0;
                                    font-family: "Courier New", monospace;
                                    line-height: 1.5;
                                    color: #333333;
                                    background-color: #f5f5f5;
                                }
                                * {
                                    box-sizing: border-box;
                                }
                                /* Container styles */
                                .receipt-container {
                                    max-width: 600px;
                                    margin: 20px auto;
                                    background-color: #ffffff;
                                    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                                    border-radius: 8px;
                                    overflow: hidden;
                                }
                                /* Header styles */
                                .receipt-header {
                                    background-color: #0047AB;
                                    color: white;
                                    padding: 15px;
                                    text-align: center;
                                    border-bottom: 3px dashed #ffffff;
                                }
                                .receipt-header h1 {
                                    margin: 0;
                                    font-size: 24px;
                                    font-weight: bold;
                                }
                                .receipt-header p {
                                    margin: 5px 0 0;
                                    font-size: 14px;
                                }
                                /* Content styles */
                                .receipt-content {
                                    padding: 20px;
                                    background-image: url("data:image/svg+xml,%3Csvg width=\'40\' height=\'40\' viewBox=\'0 0 40 40\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23f0f0f0\' fill-opacity=\'0.4\' fill-rule=\'evenodd\'%3E%3Cpath d=\'M0 40L40 0H20L0 20M40 40V20L20 40\'/%3E%3C/g%3E%3C/svg%3E");
                                    background-repeat: repeat;
                                }
                                .receipt-paper {
                                    background-color: white;
                                    padding: 20px;
                                    border-radius: 5px;
                                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                                }
                                .receipt-title {
                                    text-align: center;
                                    margin-bottom: 10px;
                                    font-weight: bold;
                                    font-size: 18px;
                                    border-bottom: 1px solid #ddd;
                                    padding-bottom: 10px;
                                }
                                .receipt-date {
                                    text-align: center;
                                    font-size: 14px;
                                    margin-bottom: 20px;
                                    color: #666;
                                }
                                .transaction-status {
                                    text-align: center;
                                    padding: 8px;
                                    margin: 15px 0;
                                    background-color: #e7f3eb;
                                    border: 1px solid #28a745;
                                    border-radius: 4px;
                                    font-weight: bold;
                                    color: #28a745;
                                }
                                .transaction-id {
                                    text-align: center;
                                    font-family: "Courier New", monospace;
                                    font-size: 14px;
                                    background-color: #f8f9fa;
                                    padding: 8px;
                                    border-radius: 4px;
                                    margin-bottom: 20px;
                                    border: 1px dashed #ccc;
                                }
                                .amount-section {
                                    text-align: center;
                                    margin: 20px 0;
                                    padding: 15px;
                                    background-color: #f0f7ff;
                                    border: 2px solid #0047AB;
                                    border-radius: 5px;
                                }
                                .amount-label {
                                    font-size: 14px;
                                    color: #555;
                                    margin-bottom: 5px;
                                }
                                .amount-value {
                                    font-size: 24px;
                                    font-weight: bold;
                                    color: #0047AB;
                                    font-family: "Courier New", monospace;
                                }
                                .details-table {
                                    width: 100%;
                                    border-collapse: collapse;
                                    margin: 20px 0;
                                    font-family: "Courier New", monospace;
                                    font-size: 14px;
                                }
                                .details-table th {
                                    background-color: #f5f5f5;
                                    border-bottom: 2px solid #ddd;
                                    padding: 8px;
                                    text-align: left;
                                    font-weight: bold;
                                }
                                .details-table td {
                                    padding: 10px 8px;
                                    border-bottom: 1px solid #eee;
                                }
                                .detail-label {
                                    font-weight: bold;
                                    color: #555;
                                }
                                .detail-value {
                                    text-align: right;
                                }
                                /* Footer styles */
                                .receipt-footer {
                                    padding: 15px;
                                    text-align: center;
                                    border-top: 3px dashed #ddd;
                                    color: #777;
                                    font-size: 12px;
                                    background-color: #f9f9f9;
                                }
                                .receipt-barcode {
                                    text-align: center;
                                    margin: 20px 0;
                                    padding: 10px;
                                    background-color: #ffffff;
                                }
                                .barcode-img {
                                    width: 80%;
                                    max-width: 300px;
                                    height: 60px;
                                    background-color: #333;
                                    color: white;
                                    margin: 0 auto;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    font-family: "Courier New", monospace;
                                    font-size: 12px;
                                    letter-spacing: 1px;
                                }
                                .security-notice {
                                    margin-top: 20px;
                                    padding: 12px;
                                    background-color: #fff8e1;
                                    border-left: 4px solid #ffc107;
                                    font-size: 12px;
                                }
                                .receipt-divider {
                                    height: 1px;
                                    background: repeating-linear-gradient(to right, #ddd 0, #ddd 5px, transparent 5px, transparent 10px);
                                    margin: 15px 0;
                                }
                                .thank-you {
                                    text-align: center;
                                    margin: 20px 0 10px;
                                    font-weight: bold;
                                    font-size: 16px;
                                    color: #0047AB;
                                }
                            </style>
                        </head>
                        <body>
                            <div class="receipt-container">
                                <div class="receipt-header">
                                    <h1>SCHOBANK</h1>
                                    <p>Bukti Transaksi Transfer</p>
                                </div>
                                
                                <div class="receipt-content">
                                    <div class="receipt-paper">
                                        <div class="receipt-title">BUKTI TRANSFER ELEKTRONIK</div>
                                        <div class="receipt-date">Tanggal: ' . date('d/m/Y') . ' - Waktu: ' . date('H:i:s') . ' WIB</div>
                                        
                                        <div class="transaction-id">
                                            No. Transaksi: <strong>' . $no_transaksi . '</strong>
                                        </div>
                                        
                                        <div class="transaction-status">
                                            ✓ TRANSAKSI BERHASIL
                                        </div>
                                        
                                        <div class="amount-section">
                                            <div class="amount-label">TOTAL TRANSFER</div>
                                            <div class="amount-value">Rp ' . number_format($jumlah, 0, ',', '.') . '</div>
                                        </div>
                                        
                                        <div class="receipt-divider"></div>
                                        
                                        <table class="details-table">
                                            <tr>
                                                <td class="detail-label">Waktu Transaksi</td>
                                                <td class="detail-value">' . date('d/m/Y H:i:s') . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Rekening Sumber</td>
                                                <td class="detail-value">' . $no_rekening . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Nama Pengirim</td>
                                                <td class="detail-value">' . $user_data['nama'] . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Rekening Tujuan</td>
                                                <td class="detail-value">' . $rekening_tujuan . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Nama Penerima</td>
                                                <td class="detail-value">' . $rekening_tujuan_nama . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Jumlah Transfer</td>
                                                <td class="detail-value">Rp ' . number_format($jumlah, 0, ',', '.') . '</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Biaya</td>
                                                <td class="detail-value">Rp 0,-</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Status</td>
                                                <td class="detail-value">BERHASIL</td>
                                            </tr>
                                            <tr>
                                                <td class="detail-label">Saldo Akhir</td>
                                                <td class="detail-value">Rp ' . number_format($saldo_baru_pengirim, 0, ',', '.') . '</td>
                                            </tr>
                                        </table>
                                        
                                        <div class="receipt-divider"></div>
                                        
                                        <div class="receipt-barcode">
                                            <div class="barcode-img">' . $no_transaksi . '</div>
                                        </div>
                                        
                                        <div class="security-notice">
                                            <strong>Pemberitahuan Keamanan:</strong> Bukti transfer ini sah tanpa tanda tangan. 
                                            Jangan membagikan informasi rekening dan PIN Anda kepada siapapun. SCHOBANK tidak pernah meminta
                                            informasi rahasia melalui email atau telepon.
                                        </div>
                                        
                                        <div class="thank-you">
                                            TERIMA KASIH
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="receipt-footer">
                                    <p>Dokumen ini diterbitkan secara elektronik oleh SCHOBANK</p>
                                    <p>Jika Anda tidak melakukan transaksi ini, hubungi layanan pelanggan kami di (021) 12345678</p>
                                    <p>&copy; ' . date('Y') . ' SCHOBANK. All rights reserved.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ';
                        
                        // Versi teks alternatif untuk klien email yang tidak mendukung HTML
                        $mail->AltBody = 'BUKTI TRANSFER SCHOBANK' . PHP_EOL . 
                                        'No. Transaksi: ' . $no_transaksi . PHP_EOL .
                                        'Tanggal: ' . date('d/m/Y H:i:s') . PHP_EOL .
                                        'Rekening Asal: ' . $no_rekening . PHP_EOL .
                                        'Rekening Tujuan: ' . $rekening_tujuan . PHP_EOL .
                                        'Nama Penerima: ' . $rekening_tujuan_nama . PHP_EOL .
                                        'Jumlah: Rp ' . number_format($jumlah, 0, ',', '.') . PHP_EOL . PHP_EOL .
                                        'Terima kasih telah menggunakan layanan SCHOBANK SYSTEM';
                        
                        // Kirim email
                        $mail->send();
                        
                        // Juga kirim notifikasi ke penerima jika email tersedia
                        if (!empty($rekening_tujuan_email)) {
                            $mail->clearAddresses();
                            $mail->addAddress($rekening_tujuan_email, $rekening_tujuan_nama);
                            $mail->Subject = 'Pemberitahuan Transfer Masuk - SCHOBANK';
                            
                            $mail->Body = '
                            <!DOCTYPE html>
                            <html>
                            <head>
                                <meta charset="UTF-8">
                                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                <title>Notifikasi Transfer Masuk - SCHOBANK</title>
                                <style>
                                    /* Reset styles */
                                    body, html {
                                        margin: 0;
                                        padding: 0;
                                        font-family: "Courier New", monospace;
                                        line-height: 1.5;
                                        color: #333333;
                                        background-color: #f5f5f5;
                                    }
                                    * {
                                        box-sizing: border-box;
                                    }
                                    /* Container styles */
                                    .receipt-container {
                                        max-width: 600px;
                                        margin: 20px auto;
                                        background-color: #ffffff;
                                        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                                        border-radius: 8px;
                                        overflow: hidden;
                                    }
                                    /* Header styles */
                                    .receipt-header {
                                        background-color: #28a745;
                                        color: white;
                                        padding: 15px;
                                        text-align: center;
                                        border-bottom: 3px dashed #ffffff;
                                    }
                                    .receipt-header h1 {
                                        margin: 0;
                                        font-size: 24px;
                                        font-weight: bold;
                                    }
                                    .receipt-header p {
                                        margin: 5px 0 0;
                                        font-size: 14px;
                                    }
                                    /* Content styles */
                                    .receipt-content {
                                        padding: 20px;
                                        background-image: url("data:image/svg+xml,%3Csvg width=\'40\' height=\'40\' viewBox=\'0 0 40 40\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Cg fill=\'%23f0f0f0\' fill-opacity=\'0.4\' fill-rule=\'evenodd\'%3E%3Cpath d=\'M0 40L40 0H20L0 20M40 40V20L20 40\'/%3E%3C/g%3E%3C/svg%3E");
                                        background-repeat: repeat;
                                    }
                                    .receipt-paper {
                                        background-color: white;
                                        padding: 20px;
                                        border-radius: 5px;
                                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                                    }
                                    .receipt-title {
                                        text-align: center;
                                        margin-bottom: 10px;
                                        font-weight: bold;
                                        font-size: 18px;
                                        border-bottom: 1px solid #ddd;
                                        padding-bottom: 10px;
                                    }
                                    .receipt-date {
                                        text-align: center;
                                        font-size: 14px;
                                        margin-bottom: 20px;
                                        color: #666;
                                    }
                                    .transaction-status {
                                        text-align: center;
                                        padding: 8px;
                                        margin: 15px 0;
                                        background-color: #e7f3eb;
                                        border: 1px solid #28a745;
                                        border-radius: 4px;
                                        font-weight: bold;
                                        color: #28a745;
                                    }
                                    .transaction-id {
                                        text-align: center;
                                        font-family: "Courier New", monospace;
                                        font-size: 14px;
                                        background-color: #f8f9fa;
                                        padding: 8px;
                                        border-radius: 4px;
                                        margin-bottom: 20px;
                                        border: 1px dashed #ccc;
                                    }
                                    .amount-section {
                                        text-align: center;
                                        margin: 20px 0;
                                        padding: 15px;
                                        background-color: #f0fff5;
                                        border: 2px solid #28a745;
                                        border-radius: 5px;
                                    }
                                    .amount-label {
                                        font-size: 14px;
                                        color: #555;
                                        margin-bottom: 5px;
                                    }
                                    .amount-value {
                                        font-size: 24px;
                                        font-weight: bold;
                                        color: #28a745;
                                        font-family: "Courier New", monospace;
                                    }
                                    .details-table {
                                        width: 100%;
                                        border-collapse: collapse;
                                        margin: 20px 0;
                                        font-family: "Courier New", monospace;
                                        font-size: 14px;
                                    }
                                    .details-table th {
                                        background-color: #f5f5f5;
                                        border-bottom: 2px solid #ddd;
                                        padding: 8px;
                                        text-align: left;
                                        font-weight: bold;
                                    }
                                    .details-table td {
                                        padding: 10px 8px;
                                        border-bottom: 1px solid #eee;
                                    }
                                    .detail-label {
                                        font-weight: bold;
                                        color: #555;
                                    }
                                    .detail-value {
                                        text-align: right;
                                    }
                                    /* Footer styles */
                                    .receipt-footer {
                                        padding: 15px;
                                        text-align: center;
                                        border-top: 3px dashed #ddd;
                                        color: #777;
                                        font-size: 12px;
                                        background-color: #f9f9f9;
                                    }
                                    .balance-info {
                                        text-align: center;
                                        margin: 20px 0;
                                        padding: 12px;
                                        background-color: #f8f9fa;
                                        border: 1px solid #ddd;
                                        border-radius: 5px;
                                    }
                                    .balance-label {
                                        font-size: 14px;
                                        color: #555;
                                    }
                                    .balance-value {
                                        font-size: 18px;
                                        font-weight: bold;
                                        color: #333;
                                        font-family: "Courier New", monospace;
                                    }
                                    .receipt-divider {
                                        height: 1px;
                                        background: repeating-linear-gradient(to right, #ddd 0, #ddd 5px, transparent 5px, transparent 10px);
                                        margin: 15px 0;
                                    }
                                    .security-notice {
                                        margin-top: 20px;
                                        padding: 12px;
                                        background-color: #fff8e1;
                                        border-left: 4px solid #ffc107;
                                        font-size: 12px;
                                    }
                                    .thank-you {
                                        text-align: center;
                                        margin: 20px 0 10px;
                                        font-weight: bold;
                                        font-size: 16px;
                                        color: #28a745;
                                    }
                                </style>
                            </head>
                            <body>
                                <div class="receipt-container">
                                    <div class="receipt-header">
                                        <h1>SCHOBANK</h1>
                                        <p>Notifikasi Transfer Masuk</p>
                                    </div>
                                    
                                    <div class="receipt-content">
                                        <div class="receipt-paper">
                                            <div class="receipt-title">BUKTI PENERIMAAN DANA</div>
                                            <div class="receipt-date">Tanggal: ' . date('d/m/Y') . ' - Waktu: ' . date('H:i:s') . ' WIB</div>
                                            
                                            <div class="transaction-id">
                                                No. Transaksi: <strong>' . $no_transaksi . '</strong>
                                            </div>
                                            
                                            <div class="transaction-status">
                                                ✓ DANA DITERIMA
                                            </div>
                                            
                                            <div class="amount-section">
                                                <div class="amount-label">TOTAL DITERIMA</div>
                                                <div class="amount-value">Rp ' . number_format($jumlah, 0, ',', '.') . '</div>
                                            </div>
                                            
                                            <div class="receipt-divider"></div>
                                            
                                            <table class="details-table">
                                                <tr>
                                                    <td class="detail-label">Waktu Diterima</td>
                                                    <td class="detail-value">' . date('d/m/Y H:i:s') . '</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-label">Dari Rekening</td>
                                                    <td class="detail-value">' . $no_rekening . '</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-label">Nama Pengirim</td>
                                                    <td class="detail-value">' . $user_data['nama'] . '</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-label">Rekening Tujuan</td>
                                                    <td class="detail-value">' . $rekening_tujuan . '</td>
                                                </tr>
                                                <tr>
                                                    <td class="detail-label">Jumlah Diterima</td>
                                                    <td class="detail-value">Rp ' . number_format($jumlah, 0, ',', '.') . '</td>
                                                </tr>
                                            </table>
                                            
                                            <div class="receipt-divider"></div>
                                            
                                            <div class="balance-info">
                                                <div class="balance-label">SALDO AKHIR ANDA</div>
                                                <div class="balance-value">Rp ' . number_format($saldo_baru_penerima, 0, ',', '.') . '</div>
                                            </div>
                                            
                                            <div class="security-notice">
                                                <strong>Pemberitahuan Keamanan:</strong> Bukti transfer ini sah tanpa tanda tangan.
                                                Jika Anda tidak mengenali transaksi ini, silakan hubungi layanan pelanggan kami segera
                                                di (021) 12345678.
                                            </div>
                                            
                                            <div class="thank-you">
                                                TERIMA KASIH
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="receipt-footer">
                                        <p>Dokumen ini diterbitkan secara elektronik oleh SCHOBANK</p>
                                        <p>&copy; ' . date('Y') . ' SCHOBANK. All rights reserved.</p>
                                    </div>
                                </div>
                            </body>
                            </html>
                            ';
                            
                            $mail->AltBody = 'PEMBERITAHUAN TRANSFER MASUK' . PHP_EOL . 
                                            'Tanggal: ' . date('d/m/Y H:i:s') . PHP_EOL .
                                            'No. Transaksi: ' . $no_transaksi . PHP_EOL .
                                            'Dari Rekening: ' . $no_rekening . PHP_EOL .
                                            'Nama Pengirim: ' . $user_data['nama'] . PHP_EOL .
                                            'Jumlah: Rp ' . number_format($jumlah, 0, ',', '.') . PHP_EOL .
                                            'Saldo Anda saat ini: Rp ' . number_format($saldo_baru_penerima, 0, ',', '.') . PHP_EOL . PHP_EOL .
                                            'Terima kasih telah menggunakan layanan SCHOBANK SYSTEM';
                            
                            $mail->send();
                        }
                    } catch (Exception $e) {
                        // Catat error pengiriman email, tapi jangan batalkan transaksi
                        error_log('Email tidak dapat dikirim. Mailer Error: ' . $mail->ErrorInfo);
                    }
        
                    // Success message
                    $success = "Transfer berhasil!";
                    $current_step = 3; // Receipt display step
        
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
                    <div class="email-notification">
                        <i class="fas fa-envelope"></i> Bukti transfer telah dikirim ke email Anda.
                    </div>
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
                <?php if (isset($_SESSION['tujuan_data'])): ?>
                    <div class="recipient-card">
                        <div class="recipient-title">Penerima</div>
                        <div class="recipient-name"><?= htmlspecialchars($_SESSION['tujuan_data']['nama']) ?></div>
                        <div class="recipient-account">No. Rekening: <?= htmlspecialchars($_SESSION['tujuan_data']['no_rekening']) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="transferForm">
                    <input type="hidden" name="action" value="confirm_transfer">
                    <input type="hidden" name="rekening_tujuan" value="<?= htmlspecialchars($_SESSION['tujuan_data']['no_rekening']) ?>">
                    <input type="hidden" name="rekening_tujuan_nama" value="<?= htmlspecialchars($_SESSION['tujuan_data']['nama']) ?>">
                    <input type="hidden" name="rekening_tujuan_id" value="<?= htmlspecialchars($_SESSION['tujuan_data']['id']) ?>">
                    <input type="hidden" name="tujuan_user_id" value="<?= htmlspecialchars($_SESSION['tujuan_data']['user_id']) ?>">
                    
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
            <?php elseif ($current_step == 3): ?>
</div>
<?php endif; ?>
</div>

<!-- Back button form for Step 2 -->
<form id="backForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="go_back">
</form>

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
        
        // Loading animation for verify button
        const verifyForm = document.getElementById('verifyForm');
        if (verifyForm) {
            verifyForm.addEventListener('submit', function() {
                const verifyButton = document.getElementById('verify-button');
                const verifyText = document.getElementById('verify-text');
                const loadingIcon = verifyButton.querySelector('.loading-icon');
                
                verifyText.textContent = 'Memeriksa...';
                loadingIcon.style.display = 'inline-block';
                verifyButton.disabled = true;
            });
        }
        
        // Loading animation for transfer button
        const transferForm = document.getElementById('transferForm');
        if (transferForm) {
            transferForm.addEventListener('submit', function() {
                const transferButton = document.getElementById('transfer-button');
                const transferText = document.getElementById('transfer-text');
                const loadingIcon = transferButton.querySelector('.transfer-loading');
                
                transferText.textContent = 'Memproses...';
                loadingIcon.style.display = 'inline-block';
                transferButton.disabled = true;
            });
        }
    });
</script>

</div>
<?php if (file_exists('../../includes/footer.php')) {
    include '../../includes/footer.php';
} ?>
</body>
</html>