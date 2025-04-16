<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user's account details
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

// Check daily transfer limit
$today = date('Y-m-d');
$query = "SELECT SUM(jumlah) as total_transfer 
          FROM transaksi 
          WHERE rekening_id = ? 
          AND jenis_transaksi = 'transfer' 
          AND DATE(created_at) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("is", $rekening_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$transfer_data = $result->fetch_assoc();
$daily_transfer_total = $transfer_data['total_transfer'] ?? 0;
$daily_limit = 500000; // Rp 500,000
$remaining_limit = $daily_limit - $daily_transfer_total;

// Initialize variables
$current_step = isset($_SESSION['current_step']) ? $_SESSION['current_step'] : 1;
$tujuan_data = isset($_SESSION['tujuan_data']) ? $_SESSION['tujuan_data'] : null;
$input_jumlah = "";
$transfer_amount = null;
$transfer_rekening = null;
$transfer_name = null;
$error = null;
$success = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'verify_account':
                $rekening_tujuan = trim($_POST['rekening_tujuan']);
                if (empty($rekening_tujuan) || $rekening_tujuan === 'REK') {
                    $error = "Nomor rekening tujuan harus diisi!";
                } elseif ($rekening_tujuan == $no_rekening) {
                    $error = "Tidak dapat transfer ke rekening sendiri!";
                } else {
                    // Check if destination account exists
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
                        $_SESSION['tujuan_data'] = $tujuan_data;
                        $_SESSION['current_step'] = 2;
                        $current_step = 2;
                    } else {
                        $error = "Nomor rekening tujuan tidak ditemukan!";
                    }
                }
                break;

            case 'go_back':
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                if (isset($_SESSION['tujuan_data'])) {
                    unset($_SESSION['tujuan_data']);
                }
                $tujuan_data = null;
                break;

            case 'confirm_transfer':
                if (!isset($_SESSION['tujuan_data'])) {
                    $error = "Data rekening tujuan tidak tersedia!";
                    $_SESSION['current_step'] = 1;
                    $current_step = 1;
                    break;
                }

                $tujuan_data = $_SESSION['tujuan_data'];
                $rekening_tujuan_id = $tujuan_data['id'];
                $rekening_tujuan = $tujuan_data['no_rekening'];
                $rekening_tujuan_nama = $tujuan_data['nama'];
                $rekening_tujuan_user_id = $tujuan_data['user_id'];
                $rekening_tujuan_email = $tujuan_data['email'];

                $jumlah = floatval(str_replace(',', '.', str_replace('.', '', $_POST['jumlah'])));
                $input_jumlah = $_POST['jumlah'];
                $pin = trim($_POST['pin']);

                if (empty($rekening_tujuan) || $jumlah <= 0 || empty($pin)) {
                    $error = "Semua field harus diisi dengan benar!";
                    $_SESSION['current_step'] = 2;
                    $current_step = 2;
                } elseif ($jumlah < 1000) {
                    $error = "Jumlah transfer minimal Rp 1.000!";
                    $_SESSION['current_step'] = 2;
                    $current_step = 2;
                } elseif ($jumlah > $saldo) {
                    $error = "Saldo tidak mencukupi!";
                    $_SESSION['current_step'] = 2;
                    $current_step = 2;
                } elseif ($jumlah > $remaining_limit) {
                    $error = "Melebihi batas transfer harian Rp 500.000!";
                    $_SESSION['current_step'] = 2;
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
                        $_SESSION['current_step'] = 2;
                        $current_step = 2;
                    } else {
                        try {
                            $conn->begin_transaction();

                            // Generate transaction number
                            $no_transaksi = 'TF' . date('YmdHis') . rand(1000, 9999);

                            // Insert transaction record
                            $query = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, rekening_tujuan_id, status, created_at) 
                                      VALUES (?, ?, 'transfer', ?, ?, 'approved', NOW())";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("sidi", $no_transaksi, $rekening_id, $jumlah, $rekening_tujuan_id);
                            $stmt->execute();

                            // Deduct sender's balance
                            $query = "UPDATE rekening SET saldo = saldo - ? WHERE id = ? AND saldo >= ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("did", $jumlah, $rekening_id, $jumlah);
                            $stmt->execute();
                            if ($stmt->affected_rows == 0) {
                                throw new Exception("Saldo tidak mencukupi atau rekening tidak valid!");
                            }

                            // Add to recipient's balance
                            $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("di", $jumlah, $rekening_tujuan_id);
                            $stmt->execute();
                            if ($stmt->affected_rows == 0) {
                                throw new Exception("Gagal memperbarui saldo penerima!");
                            }

                            // Fetch updated balances
                            $query = "SELECT saldo FROM rekening WHERE id = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $rekening_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $updated_rekening = $result->fetch_assoc();
                            $saldo_baru_pengirim = $updated_rekening['saldo'];

                            $query = "SELECT saldo FROM rekening WHERE id = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("i", $rekening_tujuan_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $updated_rekening_tujuan = $result->fetch_assoc();
                            $saldo_baru_penerima = $updated_rekening_tujuan['saldo'];

                            // Insert notifications
                            $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rekening_tujuan . " atas nama " . $rekening_tujuan_nama . " berhasil. Saldo baru: Rp " . number_format($saldo_baru_pengirim, 0, ',', '.');
                            $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                            $stmt_notifikasi_pengirim = $conn->prepare($query_notifikasi_pengirim);
                            $stmt_notifikasi_pengirim->bind_param('is', $user_id, $message_pengirim);
                            $stmt_notifikasi_pengirim->execute();

                            $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $no_rekening . ". Saldo baru: Rp " . number_format($saldo_baru_penerima, 0, ',', '.');
                            $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                            $stmt_notifikasi_penerima = $conn->prepare($query_notifikasi_penerima);
                            $stmt_notifikasi_penerima->bind_param('is', $rekening_tujuan_user_id, $message_penerima);
                            $stmt_notifikasi_penerima->execute();

                            $conn->commit();

                            // Send email notifications
                            try {
                                $mail = new PHPMailer(true);
                                $mail->isSMTP();
                                $mail->Host = 'smtp.gmail.com';
                                $mail->SMTPAuth = true;
                                $mail->Username = 'mocharid.ip@gmail.com';
                                $mail->Password = 'spjs plkg ktuu lcxh';
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port = 587;
                                $mail->CharSet = 'UTF-8';
                                $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
                                $mail->addAddress($user_data['email'], $user_data['nama']);
                                $mail->isHTML(true);
                                $mail->Subject = 'Bukti Transfer SCHOBANK - ' . $no_transaksi;
                                $mail->Body = '
                                <!DOCTYPE html>
                                <html>
                                <head>
                                    <meta charset="UTF-8">
                                    <meta name="viewport" content="width=device-width, initial-scale=1.0">
                                    <title>Bukti Transfer - SCHOBANK</title>
                                    <style>
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
                                        .receipt-container {
                                            max-width: 600px;
                                            margin: 20px auto;
                                            background-color: #ffffff;
                                            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                                            border-radius: 8px;
                                            overflow: hidden;
                                        }
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
                                            <p>© ' . date('Y') . ' SCHOBANK. All rights reserved.</p>
                                        </div>
                                    </div>
                                </body>
                                </html>
                                ';
                                $mail->AltBody = 'BUKTI TRANSFER SCHOBANK' . PHP_EOL . 
                                                'No. Transaksi: ' . $no_transaksi . PHP_EOL .
                                                'Tanggal: ' . date('d/m/Y H:i:s') . PHP_EOL .
                                                'Rekening Asal: ' . $no_rekening . PHP_EOL .
                                                'Rekening Tujuan: ' . $rekening_tujuan . PHP_EOL .
                                                'Nama Penerima: ' . $rekening_tujuan_nama . PHP_EOL .
                                                'Jumlah: Rp ' . number_format($jumlah, 0, ',', '.') . PHP_EOL . PHP_EOL .
                                                'Terima kasih telah menggunakan layanan SCHOBANK SYSTEM';
                                $mail->send();

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
                                            .receipt-container {
                                                max-width: 600px;
                                                margin: 20px auto;
                                                background-color: #ffffff;
                                                box-shadow: 0 4px 10px rgba(0,0,0,0.15);
                                                border-radius: 8px;
                                                overflow: hidden;
                                            }
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
                                                <p>© ' . date('Y') . ' SCHOBANK. All rights reserved.</p>
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
                                error_log('Email tidak dapat dikirim. Mailer Error: ' . $mail->ErrorInfo);
                            }

                            $success = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rekening_tujuan . " atas nama " . $rekening_tujuan_nama . " berhasil.";
                            $transfer_amount = $jumlah;
                            $transfer_rekening = $rekening_tujuan;
                            $transfer_name = $rekening_tujuan_nama;
                            $_SESSION['current_step'] = 3;
                            $current_step = 3;

                            // Clear session data after successful transfer
                            unset($_SESSION['tujuan_data']);
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error = "Gagal memproses transfer: " . $e->getMessage();
                            $_SESSION['current_step'] = 2;
                            $current_step = 2;
                        }
                    }
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Transfer - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-text-size-adjust: none;
            zoom: 1;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            transition: var(--transition);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
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
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s cubic-bezier(0.4, 0, 0.2, 1);
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .steps-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            animation: slideInSteps 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideInSteps {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            padding: 0 20px;
            transition: var(--transition);
        }

        .step-number {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #666;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
            transition: var(--transition);
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .step-text {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            color: #666;
            text-align: center;
            transition: var(--transition);
        }

        .step-connector {
            position: absolute;
            top: 17px;
            height: 3px;
            background-color: #e0e0e0;
            width: 100%;
            left: 50%;
            z-index: 0;
            transition: var(--transition);
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .step-item.active .step-text {
            color: var(--primary-color);
            font-weight: 500;
        }

        .step-item.completed .step-number {
            background-color: var(--secondary-color);
            color: white;
        }

        .step-item.completed .step-connector {
            background-color: var(--secondary-color);
        }

        .transaction-container {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .transfer-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            flex: 1;
            min-width: 300px;
            max-width: 650px;
            transition: var(--transition);
        }

        .transfer-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .saldo-details {
            display: none;
        }

        .saldo-details.visible {
            display: block;
            animation: slideStep 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .transfer-form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .currency-input {
            position: relative;
        }

        .currency-prefix {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        input[type="text"],
        input.currency {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
            caret-color: var(--primary-color);
        }

        input.currency {
            padding-left: 45px !important;
        }

        input[type="text"]:focus,
        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        .pin-group {
            text-align: center;
            margin: 30px 0;
        }

        .pin-container {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .pin-container.error {
            animation: shake 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            border: 1px solid #ddd;
            border-radius: 10px;
            background: white;
            transition: var(--transition);
            caret-color: var(--primary-color);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--primary-light);
            animation: bouncePin 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            width: fit-content;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px) scale(1.05);
        }

        button:active {
            transform: scale(0.95);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
            gap: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: left;
        }

        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: var(--danger-color);
        }

        .btn-cancel:hover {
            background-color: #d32f2f;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            opacity: 1;
            transition: opacity 0.5s ease-out;
        }

        .alert.hide {
            opacity: 0;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-info {
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: pointer;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            animation: slideUpText 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .transfer-card {
                padding: 20px;
            }

            .steps-container {
                flex-direction: column;
                align-items: center;
                margin-bottom: 20px;
            }

            .step-item {
                flex-direction: row;
                width: 100%;
                margin-bottom: 10px;
            }

            .step-connector {
                display: none;
            }

            .step-number {
                margin-bottom: 0;
                margin-right: 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .step-text {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            }

            button {
                width: 100%;
                justify-content: center;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .confirm-buttons {
                flex-direction: column;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pin-container {
                gap: 8px;
            }

            .pin-input {
                width: 45px;
                height: 45px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-exchange-alt"></i> Transfer Lokal</h2>
            <p>Kirim dana ke rekening lain dengan cepat dan aman</p>
        </div>

        <div class="steps-container">
            <div class="step-item <?= $current_step >= 1 ? 'active' : '' ?>" id="step1-indicator">
                <div class="step-number">1</div>
                <div class="step-text">Cek Rekening</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item <?= $current_step >= 2 ? ($current_step > 2 ? 'completed' : 'active') : '' ?>" id="step2-indicator">
                <div class="step-number">2</div>
                <div class="step-text">Input Nominal</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item <?= $current_step >= 3 ? 'active' : '' ?>" id="step3-indicator">
                <div class="step-number">3</div>
                <div class="step-text">Konfirmasi</div>
            </div>
        </div>

        <div class="transaction-container">
            <!-- Step 1: Check Account -->
            <div class="transfer-card" id="checkAccountStep" style="display: <?= $current_step == 1 ? 'block' : 'none' ?>">
                <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening Tujuan</h3>
                <div id="alertContainer"></div>
                <form id="cekRekening" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="verify_account">
                    <div>
                        <label for="rekening_tujuan">No Rekening:</label>
                        <input type="text" id="rekening_tujuan" name="rekening_tujuan" placeholder="REK..." required autofocus>
                    </div>
                    <button type="submit" id="cekButton">
                        <i class="fas fa-search"></i>
                        <span>Cek Rekening</span>
                    </button>
                </form>
            </div>

            <!-- Step 2: Input Amount and PIN -->
            <div class="transfer-card saldo-details <?= $current_step == 2 ? 'visible' : '' ?>" id="amountDetails">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Transfer</h3>
                <div id="alertContainerStep2"></div>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['no_rekening']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Penerima:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['nama']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Saldo Anda:</div>
                    <div class="detail-value"><?= formatRupiah($saldo) ?></div>
                </div>
                <form id="formTransfer" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="confirm_transfer">
                    <input type="hidden" name="rekening_tujuan" value="<?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['no_rekening']) : '' ?>">
                    <div class="currency-input">
                        <label for="jumlah">Jumlah Transfer (Rp):</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="1,000" required value="<?= htmlspecialchars($input_jumlah) ?>">
                    </div>
                    <div class="pin-group">
                        <label>Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" data-index="1" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="2" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="3" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="4" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="5" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="6" pattern="[0-9]*" inputmode="numeric" autocomplete="off">
                        </div>
                        <input type="hidden" id="pin" name="pin">
                    </div>
                    <div class="confirm-buttons">
                        <button type="submit" id="confirmTransfer" class="btn-confirm">
                            <i class="fas fa-check-circle"></i>
                            <span>Lanjutkan</span>
                        </button>
                        <button type="button" id="cancelTransfer" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Prevent pinch-to-zoom and double-tap zoom
        document.addEventListener('touchstart', (event) => {
            if (event.touches.length > 1) event.preventDefault();
        }, { passive: false });

        document.addEventListener('gesturestart', (event) => event.preventDefault());
        document.addEventListener('wheel', (event) => {
            if (event.ctrlKey) event.preventDefault();
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', () => {
            // DOM Elements
            const cekForm = document.getElementById('cekRekening');
            const transferForm = document.getElementById('formTransfer');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const amountDetails = document.getElementById('amountDetails');
            const alertContainer = document.getElementById('alertContainer');
            const alertContainerStep2 = document.getElementById('alertContainerStep2');
            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');
            const inputNoRek = document.getElementById('rekening_tujuan');
            const inputJumlah = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const pinContainer = document.querySelector('.pin-container');
            const hiddenPin = document.getElementById('pin');
            const cekButton = document.getElementById('cekButton');
            const confirmTransfer = document.getElementById('confirmTransfer');
            const cancelTransfer = document.getElementById('cancelTransfer');

            const prefix = 'REK';
            let isSubmitting = false;

            // Initialize input with prefix
            inputNoRek.value = prefix;

            // Debounce utility
            const debounce = (func, wait) => {
                let timeout;
                return (...args) => {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            };

            // Account number input handling
            inputNoRek.addEventListener('input', debounce((e) => {
                let value = e.target.value;
                if (!value.startsWith(prefix)) {
                    value = prefix + value.replace(prefix, '');
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '');
                e.target.value = prefix + userInput;
            }, 100));

            inputNoRek.addEventListener('keydown', (e) => {
                const cursorPos = e.target.selectionStart;
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                const currentValue = e.target.value.slice(prefix.length);
                e.target.value = prefix + (currentValue + pastedData);
            });

            inputNoRek.addEventListener('focus', () => {
                if (inputNoRek.value === prefix) {
                    inputNoRek.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoRek.addEventListener('click', (e) => {
                if (e.target.selectionStart < prefix.length) {
                    e.target.setSelectionRange(prefix.length, prefix.length);
                }
            });

            // Amount input formatting
            inputJumlah.addEventListener('input', debounce((e) => {
                let value = e.target.value.replace(/[^0-9]/g, '');
                if (value === '') {
                    e.target.value = '';
                    return;
                }
                e.target.value = formatRupiahInput(value);
            }, 100));

            // PIN input handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', () => {
                    const value = input.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        input.classList.add('filled');
                        input.dataset.originalValue = value;
                        input.value = '*';
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        input.classList.remove('filled');
                        input.dataset.originalValue = '';
                        input.value = '';
                    }
                    updateHiddenPin();
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !input.dataset.originalValue && index > 0) {
                        pinInputs[index - 1].focus();
                        pinInputs[index - 1].classList.remove('filled');
                        pinInputs[index - 1].dataset.originalValue = '';
                        pinInputs[index - 1].value = '';
                        updateHiddenPin();
                    }
                });

                input.addEventListener('focus', () => {
                    if (input.classList.contains('filled')) {
                        input.value = input.dataset.originalValue || '';
                    }
                });

                input.addEventListener('blur', () => {
                    if (input.value && !input.classList.contains('filled')) {
                        const value = input.value.replace(/[^0-9]/g, '');
                        if (value) {
                            input.dataset.originalValue = value;
                            input.value = '*';
                            input.classList.add('filled');
                        }
                    }
                });
            });

            // Update step indicators
            const updateStepIndicators = (activeStep) => {
                step1Indicator.className = 'step-item';
                step2Indicator.className = 'step-item';
                step3Indicator.className = 'step-item';

                if (activeStep >= 1) {
                    step1Indicator.className = activeStep === 1 ? 'step-item active' : 'step-item completed';
                }
                if (activeStep >= 2) {
                    step2Indicator.className = activeStep === 2 ? 'step-item active' : 'step-item completed';
                }
                if (activeStep === 3) {
                    step3Indicator.className = 'step-item active';
                }

                checkAccountStep.style.display = activeStep === 1 ? 'block' : 'none';
                amountDetails.style.display = activeStep === 2 ? 'block' : 'none';
                amountDetails.classList.toggle('visible', activeStep === 2);
            };

            // Update hidden PIN
            const updateHiddenPin = () => {
                hiddenPin.value = Array.from(pinInputs)
                    .map((i) => i.dataset.originalValue || '')
                    .join('');
            };

            // Clear PIN inputs
            const clearPinInputs = () => {
                pinInputs.forEach((i) => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                hiddenPin.value = '';
                pinInputs[0].focus();
            };

            // Shake PIN container
            const shakePinContainer = () => {
                pinContainer.classList.add('error');
                setTimeout(() => pinContainer.classList.remove('error'), 400);
            };

            // Reset form
            const resetForm = () => {
                inputNoRek.value = prefix;
                inputJumlah.value = '';
                clearPinInputs();
                updateStepIndicators(1);
                checkAccountStep.style.display = 'block';
                amountDetails.style.display = 'none';
                amountDetails.classList.remove('visible');
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
            };

            // Form submission for checking account
            cekForm.addEventListener('submit', (e) => {
                e.preventDefault();
                if (isSubmitting) return;
                const rekening = inputNoRek.value.trim();
                if (rekening === prefix) {
                    showAlert('Silakan masukkan nomor rekening lengkap', 'error', 1);
                    return;
                }
                isSubmitting = true;
                cekButton.classList.add('btn-loading');
                cekForm.submit();
            });

            // Form submission for transfer confirmation
            confirmTransfer.addEventListener('click', (e) => {
                if (isSubmitting) return;
                let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                jumlah = parseFloat(jumlah);
                const pin = hiddenPin.value;

                if (!jumlah || jumlah < 1000) {
                    showAlert('Jumlah transfer minimal Rp 1,000', 'error', 2);
                    inputJumlah.focus();
                    return;
                }
                if (jumlah > <?= $saldo ?>) {
                    showAlert('Saldo tidak mencukupi', 'error', 2);
                    inputJumlah.focus();
                    return;
                }
                if (jumlah > <?= $remaining_limit ?>) {
                    showAlert('Melebihi batas transfer harian Rp 500,000', 'error', 2);
                    inputJumlah.focus();
                    return;
                }
                if (pin.length !== 6) {
                    shakePinContainer();
                    showAlert('PIN harus 6 digit', 'error', 2);
                    return;
                }

                isSubmitting = true;
                confirmTransfer.classList.add('btn-loading');
                transferForm.submit();
            });

            // Cancel transfer
            cancelTransfer.addEventListener('click', () => {
                if (isSubmitting) return;
                resetForm();
            });

            // Format Rupiah for display
            const formatRupiah = (amount) => {
                return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
            };

            // Format Rupiah for input
            const formatRupiahInput = (value) => {
                value = value.replace(/^0+/, '');
                if (!value) return '';
                const number = parseInt(value);
                return new Intl.NumberFormat('id-ID').format(number);
            };

            // Show success animation
            const showSuccessAnimation = (message) => {
                const overlay = document.createElement('div');
                overlay.className = 'success-overlay';
                overlay.innerHTML = `
                    <div class="success-modal">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Transfer Berhasil!</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.success-modal');
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    modal.appendChild(confetti);
                }

                overlay.addEventListener('click', closeSuccessModal);

                function closeSuccessModal() {
                    overlay.style.animation = 'fadeOutOverlay 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                    modal.style.animation = 'popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) reverse';
                    setTimeout(() => {
                        overlay.remove();
                        resetForm();
                    }, 500);
                }

                setTimeout(closeSuccessModal, 5000);
            };

            // Show alert
            const showAlert = (message, type, step = 1) => {
                const targetContainer = step === 1 ? alertContainer : alertContainerStep2;
                targetContainer.innerHTML = ''; // Clear existing alerts
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle';
                alertDiv.innerHTML = `
                    <i class="fas fa-${icon}"></i>
                    <span>${message}</span>
                `;
                targetContainer.appendChild(alertDiv);
                               // Automatically remove alert after 5 seconds
                               setTimeout(() => {
                    alertDiv.classList.add('hide');
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
            };

            // Initialize step indicators based on current step
            updateStepIndicators(<?= $current_step ?>);

            // Handle PHP alerts
            <?php if ($error): ?>
                showAlert('<?= addslashes($error) ?>', 'error', <?= $current_step ?>);
                <?php if ($current_step == 1): ?>
                    inputNoRek.focus();
                <?php elseif ($current_step == 2): ?>
                    <?php if (strpos($error, 'PIN') !== false): ?>
                        shakePinContainer();
                        clearPinInputs();
                    <?php elseif (strpos($error, 'Jumlah') !== false || strpos($error, 'Saldo') !== false || strpos($error, 'batas') !== false): ?>
                        inputJumlah.focus();
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($success): ?>
                showSuccessAnimation('<?= addslashes($success) ?>');
            <?php endif; ?>

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }

            // Ensure proper focus on PIN inputs
            pinInputs.forEach((input) => {
                input.addEventListener('click', () => {
                    if (!input.classList.contains('filled')) {
                        input.focus();
                    }
                });
            });

            // Prevent copy-paste on PIN inputs
            pinInputs.forEach((input) => {
                input.addEventListener('paste', (e) => e.preventDefault());
                input.addEventListener('copy', (e) => e.preventDefault());
                input.addEventListener('cut', (e) => e.preventDefault());
            });

            // Handle keyboard navigation for PIN inputs
            pinInputs.forEach((input, index) => {
                input.addEventListener('keydown', (e) => {
                    if (e.key >= '0' && e.key <= '9') {
                        input.value = '';
                        input.classList.remove('filled');
                        input.dataset.originalValue = '';
                    }
                    if (e.key === 'ArrowRight' && index < 5) {
                        pinInputs[index + 1].focus();
                    }
                    if (e.key === 'ArrowLeft' && index > 0) {
                        pinInputs[index - 1].focus();
                    }
                });
            });

            // Ensure amount input retains cursor position
            inputJumlah.addEventListener('keydown', (e) => {
                const cursorPos = inputJumlah.selectionStart;
                if (e.key === 'Backspace' && cursorPos > 0) {
                    setTimeout(() => {
                        inputJumlah.setSelectionRange(cursorPos - 1, cursorPos - 1);
                    }, 0);
                }
            });

            // Prevent invalid characters in amount input
            inputJumlah.addEventListener('keypress', (e) => {
                if (!/[0-9]/.test(e.key)) {
                    e.preventDefault();
                }
            });

            // Reset isSubmitting on page load completion
            window.addEventListener('load', () => {
                isSubmitting = false;
                cekButton.classList.remove('btn-loading');
                confirmTransfer.classList.remove('btn-loading');
            });
        });
    </script>
</body>
</html>

<?php
// Helper function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>