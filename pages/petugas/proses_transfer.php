<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'petugas'])) {
    $_SESSION['error'] = "Anda tidak memiliki akses untuk melakukan aksi ini.";
    header("Location: login.php");
    exit;
}

// Check if parameters are provided
if (!isset($_GET['id']) || empty($_GET['id']) || !isset($_GET['action']) || empty($_GET['action'])) {
    $_SESSION['error'] = "Parameter tidak valid.";
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer_id = $_GET['id'];
$action = $_GET['action'];

// Validate action
if ($action != 'approve' && $action != 'reject') {
    $_SESSION['error'] = "Aksi tidak valid.";
    header("Location: riwayat_transfer.php");
    exit;
}

// Get transfer details
$stmt = $koneksi->prepare("
    SELECT t.*, r1.no_rekening AS no_rekening_asal, r2.no_rekening AS no_rekening_tujuan, 
           u1.nama AS nama_asal, u1.email AS email_asal, u1.is_frozen AS frozen_asal,
           u2.nama AS nama_tujuan, u2.email AS email_tujuan, u2.is_frozen AS frozen_tujuan,
           r1.user_id AS user_id_asal, r2.user_id AS user_id_tujuan, 
           r1.saldo AS saldo_asal, r2.saldo AS saldo_tujuan 
    FROM transaksi t 
    JOIN rekening r1 ON t.rekening_id = r1.id 
    JOIN rekening r2 ON t.rekening_tujuan_id = r2.id 
    JOIN users u1 ON r1.user_id = u1.id 
    JOIN users u2 ON r2.user_id = u2.id 
    WHERE t.id = ? AND t.jenis_transaksi = 'transfer' AND t.status = 'pending'
");
$stmt->bind_param("i", $transfer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['error'] = "Transfer tidak ditemukan atau sudah diproses.";
    header("Location: riwayat_transfer.php");
    exit;
}

$transfer = $result->fetch_assoc();
$stmt->close();

// Get petugas tugas
$today = date('Y-m-d');
$stmt = $koneksi->prepare("SELECT * FROM petugas_tugas WHERE tanggal = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$petugas_tugas = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Begin transaction
$koneksi->begin_transaction();

try {
    if ($action == 'approve') {
        // Check if source or destination account is frozen
        if ($transfer['frozen_asal']) {
            throw new Exception("Akun pengirim sedang dibekukan dan tidak dapat melakukan transfer.");
        }
        if ($transfer['frozen_tujuan']) {
            throw new Exception("Akun penerima sedang dibekukan dan tidak dapat menerima transfer.");
        }

        // Check if source account has sufficient balance
        if ($transfer['saldo_asal'] < $transfer['jumlah']) {
            throw new Exception("Saldo tidak cukup untuk transfer.");
        }

        // Update source account balance
        $saldo_asal_baru = $transfer['saldo_asal'] - $transfer['jumlah'];
        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
        $stmt->bind_param("di", $saldo_asal_baru, $transfer['rekening_id']);
        $stmt->execute();
        $stmt->close();

        // Update destination account balance
        $saldo_tujuan_baru = $transfer['saldo_tujuan'] + $transfer['jumlah'];
        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
        $stmt->bind_param("di", $saldo_tujuan_baru, $transfer['rekening_tujuan_id']);
        $stmt->execute();
        $stmt->close();

        // Create mutation record for source account
        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
        $jumlah_negative = -$transfer['jumlah'];
        $stmt->bind_param("iidd", $transfer_id, $transfer['rekening_id'], $jumlah_negative, $saldo_asal_baru);
        $stmt->execute();
        $stmt->close();

        // Create mutation record for destination account
        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iidd", $transfer_id, $transfer['rekening_tujuan_id'], $transfer['jumlah'], $saldo_tujuan_baru);
        $stmt->execute();
        $stmt->close();

        // Update transaction status to approved
        $stmt = $koneksi->prepare("UPDATE transaksi SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $transfer_id);
        $stmt->execute();
        $stmt->close();

        // Send notifications
        $message_pengirim = "Transfer Rp " . number_format($transfer['jumlah'], 0, ',', '.') . " ke rekening " . $transfer['no_rekening_tujuan'] . " disetujui. Saldo: Rp " . number_format($saldo_asal_baru, 0, ',', '.') . ".";
        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $transfer['user_id_asal'], $message_pengirim);
        $stmt->execute();
        $stmt->close();

        $message_penerima = "Anda menerima transfer Rp " . number_format($transfer['jumlah'], 0, ',', '.') . " dari rekening " . $transfer['no_rekening_asal'] . ". Saldo: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.') . ".";
        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $transfer['user_id_tujuan'], $message_penerima);
        $stmt->execute();
        $stmt->close();

        // Send emails
        sendTransferEmail(
            [
                'nama' => $transfer['nama_asal'],
                'email' => $transfer['email_asal'],
                'no_rekening' => $transfer['no_rekening_asal'],
                'user_id' => $transfer['user_id_asal']
            ],
            [
                'nama' => $transfer['nama_tujuan'],
                'email' => $transfer['email_tujuan'],
                'no_rekening' => $transfer['no_rekening_tujuan'],
                'user_id' => $transfer['user_id_tujuan']
            ],
            $transfer['jumlah'],
            $transfer['no_transaksi'],
            $saldo_asal_baru,
            $saldo_tujuan_baru,
            $petugas_tugas
        );

        $_SESSION['message'] = "Transfer berhasil disetujui. Nomor transaksi: " . $transfer['no_transaksi'];
    } else {
        // Update transaction status to rejected
        $stmt = $koneksi->prepare("UPDATE transaksi SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $transfer_id);
        $stmt->execute();
        $stmt->close();

        // Send notifications
        $message_pengirim = "Transfer Rp " . number_format($transfer['jumlah'], 0, ',', '.') . " ke rekening " . $transfer['no_rekening_tujuan'] . " ditolak.";
        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $transfer['user_id_asal'], $message_pengirim);
        $stmt->execute();
        $stmt->close();

        $message_penerima = "Transfer Rp " . number_format($transfer['jumlah'], 0, ',', '.') . " dari rekening " . $transfer['no_rekening_asal'] . " ditolak.";
        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt->bind_param("is", $transfer['user_id_tujuan'], $message_penerima);
        $stmt->execute();
        $stmt->close();

        $_SESSION['message'] = "Transfer telah ditolak. Nomor transaksi: " . $transfer['no_transaksi'];
    }

    $koneksi->commit();
    header("Location: riwayat_transfer.php");
    exit;
} catch (Exception $e) {
    $koneksi->rollback();
    $_SESSION['error'] = "Gagal memproses transfer: " . $e->getMessage();
    header("Location: riwayat_transfer.php");
    exit;
}

// Function to send transfer emails
function sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas) {
    $email_status = ['pengirim' => 'none', 'penerima' => 'none'];
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $petugas_info = "";
        if ($petugas_tugas) {
            $petugas_info = "<tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; color: #2c3e50; width: 40%;'>Petugas 1</td><td style='padding: 8px; border-bottom: 1px solid #eee; width: 60%;'>{$petugas_tugas['petugas1_nama']}</td></tr>
                             <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold; color: #2c3e50; width: 40%;'>Petugas 2</td><td style='padding: 8px; border-bottom: 1px solid #eee; width: 60%;'>{$petugas_tugas['petugas2_nama']}</td></tr>";
        }

        // Email to pengirim
        if (!empty($rek_asal['email']) && filter_var($rek_asal['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
            $mail->addAddress($rek_asal['email'], $rek_asal['nama']);
            $mail->isHTML(true);
            $mail->Subject = 'Konfirmasi Transfer Dana - SCHOBANK SYSTEM';
            $mail->Body = "
            <!DOCTYPE html>
            <html lang='id'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Konfirmasi Transfer Dana</title>
                <style>
                    body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333333; background-color: #f4f4f4; margin: 0; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
                    .header { background-color: #0a2e5c; padding: 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 500; }
                    .content { padding: 30px; }
                    .content p { margin: 0 0 15px; }
                    .transaction-details { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; }
                    .transaction-details td { padding: 8px; border-bottom: 1px solid #e0e0e0; font-size: 14px; }
                    .transaction-details tr:last-child td { border-bottom: none; }
                    .transaction-details .label { font-weight: bold; color: #2c3e50; width: 40%; }
                    .transaction-details .value { width: 60%; }
                    .amount { color: #0a2e5c; font-weight: bold; font-size: 16px; }
                    .new-balance { color: #0a2e5c; font-weight: bold; font-size: 15px; }
                    .security-notice { background-color: #f8f8f8; border-left: 4px solid #0a2e5c; padding: 15px; margin: 20px 0; font-size: 13px; color: #555555; }
                    .footer { background-color: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; color: #777777; border-top: 1px solid #e0e0e0; }
                    @media screen and (max-width: 600px) {
                        .container { width: 100%; padding: 10px; }
                        .header h1 { font-size: 20px; }
                        .content { padding: 20px; }
                        .transaction-details td { font-size: 13px; display: block; width: 100%; box-sizing: border-box; }
                        .transaction-details .label { width: 100%; padding-bottom: 0; }
                        .transaction-details .value { width: 100%; padding-top: 0; }
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Konfirmasi Transfer Dana</h1>
                    </div>
                    <div class='content'>
                        <p>Yth. Bapak/Ibu {$rek_asal['nama']},</p>
                        <p>Kami menginformasikan bahwa transaksi transfer dana Anda telah berhasil diproses. Berikut adalah rincian transaksi:</p>
                        <table class='transaction-details'>
                            <tr><td class='label'>Nomor Transaksi</td><td class='value'>{$no_transaksi}</td></tr>
                            <tr><td class='label'>Rekening Asal</td><td class='value'>{$rek_asal['no_rekening']}</td></tr>
                            <tr><td class='label'>Rekening Tujuan</td><td class='value'>{$rek_tujuan['no_rekening']} - {$rek_tujuan['nama']}</td></tr>
                            <tr><td class='label'>Jumlah Transfer</td><td class='value amount'>Rp " . number_format($jumlah, 0, ',', '.') . "</td></tr>
                            <tr><td class='label'>Saldo Akhir</td><td class='value new-balance'>Rp " . number_format($saldo_asal_baru, 0, ',', '.') . "</td></tr>
                            <tr><td class='label'>Tanggal Transaksi</td><td class='value'>" . date('d F Y H:i:s') . " WIB</td></tr>
                            {$petugas_info}
                        </table>
                        <div class='security-notice'>
                            <strong>Informasi Keamanan:</strong> Harap jaga kerahasiaan informasi akun Anda dan jangan bagikan PIN atau detail transaksi kepada pihak lain.
                        </div>
                        <p>Jika Anda memiliki pertanyaan, silakan hubungi kami melalui kanal resmi SCHOBANK SYSTEM.</p>
                    </div>
                    <div class='footer'>
                        <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                        <p>© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            $mail->send();
            $email_status['pengirim'] = 'sent';
            $mail->clearAddresses();
        }

        // Email to penerima
        if (!empty($rek_tujuan['email']) && filter_var($rek_tujuan['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($rek_tujuan['email'], $rek_tujuan['nama']);
            $mail->Subject = 'Konfirmasi Penerimaan Dana - SCHOBANK SYSTEM';
            $mail->Body = "
            <!DOCTYPE html>
            <html lang='id'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Konfirmasi Penerimaan Dana</title>
                <style>
                    body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #333333; background-color: #f4f4f4; margin: 0; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
                    .header { background-color: #0a2e5c; padding: 20px; text-align: center; color: #ffffff; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 500; }
                    .content { padding: 30px; }
                    .content p { margin: 0 0 15px; }
                    .transaction-details { width: 100%; border-collapse: collapse; margin: 20px 0; background-color: #fafafa; border: 1px solid #e0e0e0; border-radius: 4px; }
                    .transaction-details td { padding: 8px; border-bottom: 1px solid #e0e0e0; font-size: 14px; }
                    .transaction-details tr:last-child td { border-bottom: none; }
                    .transaction-details .label { font-weight: bold; color: #2c3e50; width: 40%; }
                    .transaction-details .value { width: 60%; }
                    .amount { color: #0a2e5c; font-weight: bold; font-size: 16px; }
                    .new-balance { color: #0a2e5c; font-weight: bold; font-size: 15px; }
                    .security-notice { background-color: #f8f8f8; border-left: 4px solid #0a2e5c; padding: 15px; margin: 20px 0; font-size: 13px; color: #555555; }
                    .footer { background-color: #f4f4f4; padding: 15px; text-align: center; font-size: 12px; color: #777777; border-top: 1px solid #e0e0e0; }
                    @media screen and (max-width: 600px) {
                        .container { width: 100%; padding: 10px; }
                        .header h1 { font-size: 20px; }
                        .content { padding: 20px; }
                        .transaction-details td { font-size: 13px; display: block; width: 100%; box-sizing: border-box; }
                        .transaction-details .label { width: 100%; padding-bottom: 0; }
                        .transaction-details .value { width: 100%; padding-top: 0; }
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Konfirmasi Penerimaan Dana</h1>
                    </div>
                    <div class='content'>
                        <p>Yth. Bapak/Ibu {$rek_tujuan['nama']},</p>
                        <p>Kami menginformasikan bahwa Anda telah menerima transfer dana. Berikut adalah rincian transaksi:</p>
                        <table class='transaction-details'>
                            <tr><td class='label'>Nomor Transaksi</td><td class='value'>{$no_transaksi}</td></tr>
                            <tr><td class='label'>Rekening Pengirim</td><td class='value'>{$rek_asal['no_rekening']} - {$rek_asal['nama']}</td></tr>
                            <tr><td class='label'>Rekening Tujuan</td><td class='value'>{$rek_tujuan['no_rekening']}</td></tr>
                            <tr><td class='label'>Jumlah Transfer</td><td class='value amount'>Rp " . number_format($jumlah, 0, ',', '.') . "</td></tr>
                            <tr><td class='label'>Saldo Akhir</td><td class='value new-balance'>Rp " . number_format($saldo_tujuan_baru, 0, ',', '.') . "</td></tr>
                            <tr><td class='label'>Tanggal Transaksi</td><td class='value'>" . date('d F Y H:i:s') . " WIB</td></tr>
                            {$petugas_info}
                        </table>
                        <div class='security-notice'>
                            <strong>Informasi Keamanan:</strong> Harap jaga kerahasiaan informasi akun Anda dan jangan bagikan PIN atau detail transaksi kepada pihak lain.
                        </div>
                        <p>Jika Anda memiliki pertanyaan, silakan hubungi kami melalui kanal resmi SCHOBANK SYSTEM.</p>
                    </div>
                    <div class='footer'>
                        <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                        <p>© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            $mail->send();
            $email_status['penerima'] = 'sent';
        }
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        if (empty($rek_asal['email']) || !filter_var($rek_asal['email'], FILTER_VALIDATE_EMAIL)) {
            $email_status['pengirim'] = 'none';
        } else {
            $email_status['pengirim'] = 'failed';
        }
        if (empty($rek_tujuan['email']) || !filter_var($rek_tujuan['email'], FILTER_VALIDATE_EMAIL)) {
            $email_status['penerima'] = 'none';
        } else {
            $email_status['penerima'] = 'failed';
        }
    }
    return $email_status;
}
?>