<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php'; // Include PHPMailer

date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Database connection check
if (!isset($koneksi) || $koneksi === null) {
    $koneksi = new mysqli("localhost", "root", "", "mini_bank_sekolah");
    if ($koneksi->connect_error) {
        die("Connection failed: " . $koneksi->connect_error);
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user details
$stmt = $koneksi->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get user's account if student
$rekening = null;
if ($role == 'siswa') {
    $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rekening = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Process transfer form
$message = '';
$error = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $form_data = [
        'rekening_asal' => $_POST['rekening_asal'] ?? '',
        'rekening_tujuan' => $_POST['rekening_tujuan'] ?? '',
        'jumlah' => $_POST['jumlah'] ?? '',
    ];

    $rekening_asal = $_POST['rekening_asal'] ?? '';
    $rekening_tujuan = $_POST['rekening_tujuan'] ?? '';
    $jumlah = preg_replace('/[^0-9]/', '', $_POST['jumlah'] ?? '0');
    $pin = $_POST['pin'] ?? '';

    if (empty($rekening_asal) || empty($rekening_tujuan) || $jumlah <= 0 || empty($pin)) {
        $error = "Semua field harus diisi dengan benar.";
    } elseif ($rekening_asal == $rekening_tujuan) {
        $error = "Nomor rekening tujuan tidak boleh sama dengan rekening asal.";
    } elseif ($jumlah < 1000) {
        $error = "Jumlah transfer minimal Rp 1.000.";
    } elseif (strlen($pin) !== 6) {
        $error = "PIN harus 6 digit.";
    } else {
        // Get rekening details
        $stmt = $koneksi->prepare("SELECT r.*, u.nama, u.pin, u.email FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Verify PIN
        $stmt = $koneksi->prepare("SELECT pin, email, nama FROM users WHERE id = ?");
        $stmt->bind_param("i", $rek_asal['user_id']);
        $stmt->execute();
        $user_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user_data || $user_data['pin'] !== $pin) {
            $error = "PIN yang Anda masukkan salah!";
        } else {
            $stmt = $koneksi->prepare("SELECT r.*, u.nama, u.email FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
            $stmt->bind_param("s", $rekening_tujuan);
            $stmt->execute();
            $rek_tujuan = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$rek_asal) {
                $error = "Rekening asal tidak ditemukan.";
            } elseif (!$rek_tujuan) {
                $error = "Rekening tujuan tidak ditemukan.";
            } elseif ($rek_asal['saldo'] < $jumlah) {
                $error = "Saldo tidak mencukupi untuk transfer.";
            } else {
                // Validate petugas_tugas for today
                $today = date('Y-m-d');
                $stmt = $koneksi->prepare("SELECT * FROM petugas_tugas WHERE tanggal = ?");
                $stmt->bind_param("s", $today);
                $stmt->execute();
                $petugas_tugas = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$petugas_tugas) {
                    $error = "Tidak ada petugas yang bertugas hari ini. Silakan hubungi admin.";
                } else {
                    $koneksi->begin_transaction();
                    try {
                        $no_transaksi = 'TRF' . date('YmdHis') . rand(100, 999);

                        $stmt = $koneksi->prepare("INSERT INTO transaksi (no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, petugas_id, status) VALUES (?, ?, ?, 'transfer', ?, ?, 'approved')");
                        $stmt->bind_param("siidi", $no_transaksi, $rek_asal['id'], $rek_tujuan['id'], $jumlah, $user_id);
                        $stmt->execute();
                        $transaksi_id = $koneksi->insert_id;
                        $stmt->close();

                        $saldo_asal_baru = $rek_asal['saldo'] - $jumlah;
                        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                        $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
                        $stmt->execute();
                        $stmt->close();

                        $saldo_tujuan_baru = $rek_tujuan['saldo'] + $jumlah;
                        $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                        $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                        $jumlah_negative = -$jumlah;
                        $stmt->bind_param("iidd", $transaksi_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
                        $stmt->execute();
                        $stmt->close();

                        $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                        $stmt->bind_param("iidd", $transaksi_id, $rek_tujuan['id'], $jumlah, $saldo_tujuan_baru);
                        $stmt->execute();
                        $stmt->close();

                        // Corrected notification for sender
                        $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rek_tujuan['no_rekening'] . " BERHASIL dilakukan oleh PETUGAS. Saldo baru: Rp " . number_format($saldo_asal_baru, 0, ',', '.');
                        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        $stmt->bind_param('is', $rek_asal['user_id'], $message_pengirim);
                        $stmt->execute();
                        $stmt->close();

                        // Notification for receiver
                        $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $rek_asal['no_rekening'] . ". Saldo baru: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.');
                        $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                        $stmt->bind_param('is', $rek_tujuan['user_id'], $message_penerima);
                        $stmt->execute();
                        $stmt->close();

                        sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas);

                        $koneksi->commit();

                        // Redirect to prevent form resubmission
                        $_SESSION['transfer_success'] = true;
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit;
                    } catch (Exception $e) {
                        $koneksi->rollback();
                        $error = "Transfer gagal: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Check for success message in session
if (isset($_SESSION['transfer_success']) && $_SESSION['transfer_success']) {
    $message = "Transfer Berhasil";
    unset($_SESSION['transfer_success']);
    $form_data = []; // Reset form data after successful transfer
}

// Function to send transfer emails
function sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas) {
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

        // Sender Email
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
            <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
            <style>
                body { 
                    font-family: 'Poppins', Helvetica, Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1f2937; 
                    background-color: #f3f4f6; 
                    margin: 0; 
                    padding: 25px; 
                }
                .container { 
                    max-width: 650px; 
                    margin: 0 auto; 
                    background-color: #ffffff; 
                    border-radius: 12px; 
                    box-shadow: 0 4px 16px rgba(0,0,0,0.08); 
                    overflow: hidden; 
                }
                .header { 
                    background-color: #1e3a8a; 
                    background-image: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
                    padding: 28px 20px; 
                    text-align: center; 
                    color: #ffffff; 
                }
                .header h1 { 
                    margin: 0; 
                    font-size: 26px; 
                    font-weight: 600; 
                    letter-spacing: 0.5px;
                }
                .content { 
                    padding: 35px; 
                }
                .content p { 
                    margin: 0 0 20px; 
                    font-size: 15px;
                    font-weight: 400;
                }
                .greeting {
                    font-size: 16px;
                    font-weight: 500;
                    margin-bottom: 24px;
                }
                .transaction-details { 
                    width: 100%; 
                    border-collapse: separate; 
                    border-spacing: 0;
                    margin: 25px 0; 
                    background-color: #ffffff; 
                    border: 1px solid #e5e7eb; 
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.03);
                }
                .transaction-details td { 
                    padding: 12px 15px; 
                    border-bottom: 1px solid #e5e7eb; 
                    font-size: 14px; 
                    vertical-align: middle;
                }
                .transaction-details .label { 
                    font-weight: 600; 
                    color: #1e3a8a; 
                    width: 40%;
                    background-color: #f9fafb;
                }
                .transaction-details .value { 
                    width: 60%; 
                }
                .amount { 
                    color: #1e3a8a; 
                    font-weight: 700; 
                    font-size: 16px; 
                }
                .security-notice { 
                    background-color: #f3f4f6; 
                    border-left: 4px solid #1e3a8a; 
                    padding: 18px; 
                    margin: 25px 0; 
                    font-size: 14px; 
                    color: #374151;
                    border-radius: 0 8px 8px 0;
                }
                .security-notice strong {
                    display: block;
                    margin-bottom: 5px;
                    color: #1e3a8a;
                }
                .contact {
                    background-color: #f9fafb;
                    padding: 18px;
                    border-radius: 8px;
                    margin-top: 25px;
                    font-size: 14px;
                }
                .footer { 
                    background-color: #f9fafb; 
                    padding: 20px; 
                    text-align: center; 
                    font-size: 13px; 
                    color: #6b7280; 
                    border-top: 1px solid #e5e7eb; 
                }
                .footer p {
                    margin: 5px 0;
                }
                @media screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .container { width: 100%; }
                    .header h1 { font-size: 22px; }
                    .content { padding: 20px; }
                    .transaction-details td { padding: 10px; }
                    .security-notice { padding: 15px; }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Konfirmasi Transfer Dana</h1>
                </div>
                <div class='content'>
                    <p class='greeting'>Yth. Bapak/Ibu {$rek_asal['nama']},</p>
                    <p>Kami menginformasikan bahwa transaksi transfer dana Anda telah berhasil diproses. Berikut adalah rincian transaksi:</p>
                    <table class='transaction-details'>
                        <tr><td class='label'>Nomor Transaksi</td><td class='value'>{$no_transaksi}</td></tr>
                        <tr><td class='label'>Rekening Pengirim</td><td class='value'>{$rek_asal['no_rekening']}</td></tr>
                        <tr><td class='label'>Nama Pengirim</td><td class='value'>{$rek_asal['nama']}</td></tr>
                        <tr><td class='label'>Rekening Penerima</td><td class='value'>{$rek_tujuan['no_rekening']}</td></tr>
                        <tr><td class='label'>Nama Penerima</td><td class='value'>{$rek_tujuan['nama']}</td></tr>
                        <tr><td class='label'>Jumlah Transfer</td><td class='value amount'>Rp " . number_format($jumlah, 0, ',', '.') . "</td></tr>
                        <tr><td class='label'>Tanggal Transaksi</td><td class='value'>" . date('d F Y H:i:s') . " WIB</td></tr>
                    </table>
                    <div class='security-notice'>
                        <strong>Informasi Keamanan:</strong>
                        Harap jaga kerahasiaan informasi akun Anda dan jangan bagikan PIN atau detail transaksi kepada pihak lain. Periksa selalu rincian transaksi secara teliti.
                    </div>
                    <div class='contact'>
                        <p>Jika Anda memiliki pertanyaan lebih lanjut, silakan hubungi kami melalui kanal resmi SCHOBANK SYSTEM atau kunjungi kantor cabang terdekat.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                    <p>© " . date('Y') . " SCHOBANK SYSTEM. Seluruh hak cipta dilindungi undang-undang.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        $mail->send();

        // Receiver Email
        $mail->clearAddresses();
        $mail->addAddress($rek_tujuan['email'], $rek_tujuan['nama']);
        $mail->Subject = 'Konfirmasi Penerimaan Dana - SCHOBANK SYSTEM';
        $mail->Body = "
        <!DOCTYPE html>
        <html lang='id'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Konfirmasi Penerimaan Dana</title>
            <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
            <style>
                body { 
                    font-family: 'Poppins', Helvetica, Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #1f2937; 
                    background-color: #f3f4f6; 
                    margin: 0; 
                    padding: 25px; 
                }
                .container { 
                    max-width: 650px; 
                    margin: 0 auto; 
                    background-color: #ffffff; 
                    border-radius: 12px; 
                    box-shadow: 0 4px 16px rgba(0,0,0,0.08); 
                    overflow: hidden; 
                }
                .header { 
                    background-color: #1e3a8a; 
                    background-image: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
                    padding: 28px 20px; 
                    text-align: center; 
                    color: #ffffff; 
                }
                .header h1 { 
                    margin: 0; 
                    font-size: 26px; 
                    font-weight: 600; 
                    letter-spacing: 0.5px;
                }
                .content { 
                    padding: 35px; 
                }
                .content p { 
                    margin: 0 0 20px; 
                    font-size: 15px;
                    font-weight: 400;
                }
                .greeting {
                    font-size: 16px;
                    font-weight: 500;
                    margin-bottom: 24px;
                }
                .transaction-details { 
                    width: 100%; 
                    border-collapse: separate; 
                    border-spacing: 0;
                    margin: 25px 0; 
                    background-color: #ffffff; 
                    border: 1px solid #e5e7eb; 
                    border-radius: 8px;
                    overflow: hidden;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.03);
                }
                .transaction-details td { 
                    padding: 12px 15px; 
                    border-bottom: 1px solid #e5e7eb; 
                    font-size: 14px; 
                    vertical-align: middle;
                }
                .transaction-details .label { 
                    font-weight: 600; 
                    color: #1e3a8a; 
                    width: 40%;
                    background-color: #f9fafb;
                }
                .transaction-details .value { 
                    width: 60%; 
                }
                .amount { 
                    color: #1e3a8a; 
                    font-weight: 700; 
                    font-size: 16px; 
                }
                .security-notice { 
                    background-color: #f3f4f6; 
                    border-left: 4px solid #1e3a8a; 
                    padding: 18px; 
                    margin: 25px 0; 
                    font-size: 14px; 
                    color: #374151;
                    border-radius: 0 8px 8px 0;
                }
                .security-notice strong {
                    display: block;
                    margin-bottom: 5px;
                    color: #1e3a8a;
                }
                .contact {
                    background-color: #f9fafb;
                    padding: 18px;
                    border-radius: 8px;
                    margin-top: 25px;
                    font-size: 14px;
                }
                .footer { 
                    background-color: #f9fafb; 
                    padding: 20px; 
                    text-align: center; 
                    font-size: 13px; 
                    color: #6b7280; 
                    border-top: 1px solid #e5e7eb; 
                }
                .footer p {
                    margin: 5px 0;
                }
                @media screen and (max-width: 600px) {
                    body { padding: 10px; }
                    .container { width: 100%; }
                    .header h1 { font-size: 22px; }
                    .content { padding: 20px; }
                    .transaction-details td { padding: 10px; }
                    .security-notice { padding: 15px; }
                }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Konfirmasi Penerimaan Dana</h1>
                </div>
                <div class='content'>
                    <p class='greeting'>Yth. Bapak/Ibu {$rek_tujuan['nama']},</p>
                    <p>Kami menginformasikan bahwa Anda telah menerima transfer dana. Berikut adalah rincian transaksi:</p>
                    <table class='transaction-details'>
                        <tr><td class='label'>Nomor Transaksi</td><td class='value'>{$no_transaksi}</td></tr>
                        <tr><td class='label'>Rekening Pengirim</td><td class='value'>{$rek_asal['no_rekening']}</td></tr>
                        <tr><td class='label'>Nama Pengirim</td><td class='value'>{$rek_asal['nama']}</td></tr>
                        <tr><td class='label'>Rekening Penerima</td><td class='value'>{$rek_tujuan['no_rekening']}</td></tr>
                        <tr><td class='label'>Nama Penerima</td><td class='value'>{$rek_tujuan['nama']}</td></tr>
                        <tr><td class='label'>Jumlah Transfer</td><td class='value amount'>Rp " . number_format($jumlah, 0, ',', '.') . "</td></tr>
                        <tr><td class='label'>Tanggal Transaksi</td><td class='value'>" . date('d F Y H:i:s') . " WIB</td></tr>
                    </table>
                    <div class='security-notice'>
                        <strong>Informasi Keamanan:</strong>
                        Harap jaga kerahasiaan informasi akun Anda dan jangan bagikan PIN atau detail transaksi kepada pihak lain. Periksa selalu rincian transaksi secara teliti.
                    </div>
                    <div class='contact'>
                        <p>Jika Anda memiliki pertanyaan lebih lanjut, silakan hubungi kami melalui kanal resmi SCHOBANK SYSTEM atau kunjungi kantor cabang terdekat.</p>
                    </div>
                </div>
                <div class='footer'>
                    <p>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                    <p>© " . date('Y') . " SCHOBANK SYSTEM. Seluruh hak cipta dilindungi undang-undang.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
    }
}

// Get accounts for admin/officer
$accounts = [];
if ($role == 'admin' || $role == 'petugas') {
    $query = "SELECT r.*, u.nama, u.email, k.nama_kelas 
              FROM rekening r 
              JOIN users u ON r.user_id = u.id 
              LEFT JOIN kelas k ON u.kelas_id = k.id 
              ORDER BY u.nama";
    $result = $koneksi->query($query);
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transfer - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
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
            font-size: clamp(0.9rem, 2vw, 1rem);
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
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
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

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        input[readonly] {
            background-color: #f8fafc;
            cursor: default;
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--text-secondary);
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .search-box {
            position: relative;
        }

        .search-box input {
            padding-left: 40px;
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .custom-select-wrapper {
            position: relative;
        }

        .custom-select {
            cursor: pointer;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 12px 15px;
            background-color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .custom-select:hover {
            border-color: var(--primary-color);
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            margin-top: 4px;
            box-shadow: var(--shadow-md);
            z-index: 100;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .custom-select-dropdown.active {
            display: block;
        }

        .custom-select-option {
            padding: 12px 15px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: background-color 0.2s;
        }

        .custom-select-option:hover {
            background-color: var(--bg-light);
        }

        .custom-select-option.selected {
            background-color: var(--bg-light);
        }

        .custom-select-no-results {
            padding: 12px 15px;
            color: var(--text-secondary);
            font-style: italic;
            font-size: clamp(0.9rem, 2vw, 1rem);
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
            animation: shake 0.4s ease;
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
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--bg-light);
            animation: bouncePin 0.3s ease;
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: default;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(300px, 80vw, 400px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 15px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: default;
            overflow: hidden;
            max-height: 90vh;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            margin: 0 auto 10px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 10px;
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0 0 15px;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .pin-container {
                gap: 8px;
            }

            .pin-input {
                width: 45px;
                height: 45px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .success-modal, .error-modal {
                width: clamp(280px, 85vw, 360px);
                padding: clamp(12px, 3vw, 15px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2.3rem, 4.5vw, 2.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input, select {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(260px, 90vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
                margin: 8px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2rem, 4vw, 2.2rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
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
            <h2>Transfer Antar Rekening</h2>
            <p>Kirim uang antar rekening SCHOBANK</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form method="POST" action="" id="transferForm" class="form-container">
                <div class="form-column">
                    <?php if ($role == 'siswa' && $rekening): ?>
                        <div class="form-group">
                            <label for="rekening_asal">Rekening Asal</label>
                            <input type="text" id="rekening_asal" name="rekening_asal" value="<?= htmlspecialchars($rekening['no_rekening']) ?>" readonly>
                            <span class="error-message" id="rekening_asal-error"></span>
                            <small>Saldo: Rp <?= number_format($rekening['saldo'], 2, ',', '.') ?></small>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label for="rekening_asal">Pilih Rekening Asal</label>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-asal" placeholder="Cari berdasarkan nama atau kelas" value="">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-asal-select">
                                    <span><?= !empty($form_data['rekening_asal']) ? htmlspecialchars($form_data['rekening_asal']) : '-- Pilih Rekening --' ?></span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-asal-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php 
                                        $kelas_info = $account['nama_kelas'] ? $account['nama_kelas'] : 'Belum ditentukan';
                                        $display_text = "{$account['nama']} - {$kelas_info}";
                                        ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= htmlspecialchars($account['no_rekening']) ?>"
                                             data-search="<?= htmlspecialchars($account['nama'] . ' ' . $kelas_info) ?>"
                                             data-display="<?= htmlspecialchars($display_text) ?>"
                                             <?= (!empty($form_data['rekening_asal']) && $form_data['rekening_asal'] == $account['no_rekening']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($display_text) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_asal" id="rekening-asal-input" value="<?= htmlspecialchars($form_data['rekening_asal'] ?? '') ?>" required>
                            </div>
                            <span class="error-message" id="rekening_asal-error"></span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label for="rekening_tujuan">Rekening Tujuan</label>
                        <?php if ($role == 'siswa'): ?>
                            <input type="text" id="rekening_tujuan" name="rekening_tujuan" required 
                                   placeholder="Masukkan nomor rekening tujuan" 
                                   value="<?= htmlspecialchars($form_data['rekening_tujuan'] ?? '') ?>">
                        <?php else: ?>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-tujuan" placeholder="Cari berdasarkan nama atau kelas" value="">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-tujuan-select">
                                    <span><?= !empty($form_data['rekening_tujuan']) ? htmlspecialchars($form_data['rekening_tujuan']) : '-- Pilih Rekening --' ?></span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-tujuan-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php 
                                        $kelas_info = $account['nama_kelas'] ? $account['nama_kelas'] : 'Belum ditentukan';
                                        $display_text = "{$account['nama']} - {$kelas_info}";
                                        ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= htmlspecialchars($account['no_rekening']) ?>"
                                             data-search="<?= htmlspecialchars($account['nama'] . ' ' . $kelas_info) ?>"
                                             data-display="<?= htmlspecialchars($display_text) ?>"
                                             <?= (!empty($form_data['rekening_tujuan']) && $form_data['rekening_tujuan'] == $account['no_rekening']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($display_text) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_tujuan" id="rekening-tujuan-input" value="<?= htmlspecialchars($form_data['rekening_tujuan'] ?? '') ?>" required>
                            </div>
                        <?php endif; ?>
                        <span class="error-message" id="rekening_tujuan-error"></span>
                    </div>
                </div>
                
                <div class="form-column">
                    <div class="form-group">
                        <label for="jumlah">Jumlah Transfer</label>
                        <input type="text" id="jumlah" name="jumlah" required 
                               inputmode="numeric" pattern="[0-9\.]*" 
                               placeholder="10.000" 
                               value="<?= !empty($form_data['jumlah']) ? number_format((int)preg_replace('/[^0-9]/', '', $form_data['jumlah']), 0, ',', '.') : '' ?>">
                        <span class="error-message" id="jumlah-error"></span>
                    </div>
                    
                    <div class="form-group pin-group">
                        <label for="pin-value">Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="pin-input" maxlength="1" inputmode="numeric" pattern="[0-9]*">
                        </div>
                        <input type="hidden" name="pin" id="pin-value">
                        <span class="error-message" id="pin-error"></span>
                    </div>
                </div>
                
                <div class="form-buttons">
                    <button type="submit" class="btn" id="transferButton">
                        <span class="btn-content"><i class="fas fa-paper-plane"></i> Transfer</span>
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($message)): ?>
            <div id="successModal" class="modal-overlay">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Transfer Berhasil!</h3>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div id="errorModal" class="modal-overlay">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(event) {
            event.preventDefault();
        });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            const transferForm = document.getElementById('transferForm');
            const transferButton = document.getElementById('transferButton');
            const amountInput = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const pinContainer = document.querySelector('.pin-container');
            const pinValue = document.getElementById('pin-value');
            const alertContainer = document.getElementById('alertContainer');
            const prefix = "REK";

            // Initialize inputs
            if (document.getElementById('search-rekening-asal')) {
                document.getElementById('search-rekening-asal').value = '';
            }
            if (document.getElementById('search-rekening-tujuan')) {
                document.getElementById('search-rekening-tujuan').value = '';
            }
            const inputRekTujuan = document.querySelector('input[name="rekening_tujuan"]');
            if (inputRekTujuan && inputRekTujuan.value === '') {
                inputRekTujuan.value = prefix;
            }

            // Amount input handling with thousand separators
            if (amountInput) {
                function formatNumber(value) {
                    value = value.replace(/[^0-9]/g, '');
                    if (!value) return '';
                    return parseInt(value).toLocaleString('id-ID', { minimumFractionDigits: 0 });
                }

                amountInput.addEventListener('input', function(e) {
                    let value = this.value.replace(/[^0-9]/g, '');
                    this.value = formatNumber(value);
                });

                amountInput.addEventListener('focus', function() {
                    if (this.value === '') {
                        this.value = '';
                    }
                });

                amountInput.addEventListener('blur', function() {
                    if (this.value === '') {
                        this.value = '';
                    } else {
                        this.value = formatNumber(this.value.replace(/[^0-9]/g, ''));
                    }
                });

                amountInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                    this.value = formatNumber(pastedData);
                });
            }

            // Rekening tujuan input handling
            if (inputRekTujuan) {
                inputRekTujuan.addEventListener('input', function(e) {
                    let value = this.value;
                    if (!value.startsWith(prefix)) {
                        this.value = prefix + value.replace(prefix, '');
                    }
                    let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '');
                    this.value = prefix + userInput;
                });

                inputRekTujuan.addEventListener('keydown', function(e) {
                    let cursorPos = this.selectionStart;
                    if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                        e.preventDefault();
                    }
                });

                inputRekTujuan.addEventListener('paste', function(e) {
                    e.preventDefault();
                    let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                    let currentValue = this.value.slice(prefix.length);
                    let newValue = prefix + (currentValue + pastedData);
                    this.value = newValue;
                });

                inputRekTujuan.addEventListener('focus', function() {
                    if (this.value === prefix) {
                        this.setSelectionRange(prefix.length, prefix.length);
                    }
                });

                inputRekTujuan.addEventListener('click', function(e) {
                    if (this.selectionStart < prefix.length) {
                        this.setSelectionRange(prefix.length, prefix.length);
                    }
                });
            }

            // PIN input handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function() {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.classList.add('filled');
                        this.dataset.originalValue = value;
                        this.value = '*';
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        this.classList.remove('filled');
                        this.dataset.originalValue = '';
                        this.value = '';
                    }
                    updatePinValue();
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.dataset.originalValue && index > 0) {
                        pinInputs[index - 1].focus();
                        pinInputs[index - 1].classList.remove('filled');
                        pinInputs[index - 1].dataset.originalValue = '';
                        pinInputs[index - 1].value = '';
                        updatePinValue();
                    }
                });
                input.addEventListener('focus', function() {
                    if (this.classList.contains('filled')) {
                        this.value = this.dataset.originalValue || '';
                    }
                });
                input.addEventListener('blur', function() {
                    if (this.value && !this.classList.contains('filled')) {
                        let value = this.value.replace(/[^0-9]/g, '');
                        if (value) {
                            this.dataset.originalValue = value;
                            this.value = '*';
                            this.classList.add('filled');
                        }
                    }
                });
            });

            function updatePinValue() {
                pinValue.value = Array.from(pinInputs).map(i => i.dataset.originalValue || '').join('');
            }

            function clearPinInputs() {
                pinInputs.forEach(i => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                pinValue.value = '';
                pinInputs[0].focus();
            }

            function shakePinContainer() {
                pinContainer.classList.add('error');
                setTimeout(() => pinContainer.classList.remove('error'), 400);
            }

            function resetForm() {
                transferForm.reset();
                clearPinInputs();
                if (inputRekTujuan) inputRekTujuan.value = prefix;
                amountInput.value = '';
                if (document.getElementById('rekening-asal-select')) {
                    document.getElementById('rekening-asal-select').querySelector('span').textContent = '-- Pilih Rekening --';
                    document.getElementById('rekening-asal-input').value = '';
                    document.getElementById('search-rekening-asal').value = '';
                }
                if (document.getElementById('rekening-tujuan-select')) {
                    document.getElementById('rekening-tujuan-select').querySelector('span').textContent = '-- Pilih Rekening --';
                    document.getElementById('rekening-tujuan-input').value = '';
                    document.getElementById('search-rekening-tujuan').value = '';
                }
            }

            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Transfer Berhasil!' : 'Gagal'}</h3>
                        ${type === 'error' ? `<p>${message}</p>` : ''}
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => closeModal(alertDiv.id), 5000);
                alertDiv.addEventListener('click', (e) => {
                    if (e.target.classList.contains('modal-overlay')) {
                        closeModal(alertDiv.id);
                        if (type === 'success') {
                            resetForm();
                        } else {
                            clearPinInputs(); // Clear PIN only on error
                        }
                    }
                });
                alertDiv.id = 'alert-' + Date.now();
            }

            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                }
            }

            function initCustomSelect(selectId, dropdownId, inputId, searchId) {
                const selectElement = document.getElementById(selectId);
                const dropdownElement = document.getElementById(dropdownId);
                const inputElement = document.getElementById(inputId);
                const searchElement = document.getElementById(searchId);
                
                if (!selectElement || !dropdownElement || !inputElement || !searchElement) return;
                
                selectElement.addEventListener('click', () => {
                    dropdownElement.classList.toggle('active');
                });
                
                document.addEventListener('click', e => {
                    if (!selectElement.contains(e.target) && !dropdownElement.contains(e.target) && 
                        !searchElement.contains(e.target)) {
                        dropdownElement.classList.remove('active');
                    }
                });
                
                const options = dropdownElement.querySelectorAll('.custom-select-option');
                options.forEach(option => {
                    option.addEventListener('click', function() {
                        const value = this.getAttribute('data-value');
                        const displayText = this.getAttribute('data-display') || '-- Pilih Rekening --';
                        inputElement.value = value;
                        selectElement.querySelector('span').textContent = value ? value : '-- Pilih Rekening --';
                        searchElement.value = value ? displayText : ''; // Update search input with name and class
                        options.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                        dropdownElement.classList.remove('active');
                    });
                });
                
                searchElement.addEventListener('input', function() {
                    const searchValue = this.value.toLowerCase();
                    let hasResults = false;
                    const existingNoResults = dropdownElement.querySelector('.custom-select-no-results');
                    if (existingNoResults) {
                        dropdownElement.removeChild(existingNoResults);
                    }
                    
                    options.forEach(option => {
                        if (option.getAttribute('data-value') === '') return;
                        const searchText = option.getAttribute('data-search').toLowerCase();
                        if (searchText.includes(searchValue)) {
                            option.style.display = 'block';
                            hasResults = true;
                        } else {
                            option.style.display = 'none';
                        }
                    });
                    
                    dropdownElement.classList.add('active');
                    if (!hasResults && searchValue !== '') {
                        const noResultsDiv = document.createElement('div');
                        noResultsDiv.className = 'custom-select-no-results';
                        noResultsDiv.textContent = 'Tidak ada rekening yang cocok dengan pencarian';
                        dropdownElement.appendChild(noResultsDiv);
                    }
                });
                
                selectElement.addEventListener('click', () => searchElement.focus());
                
                const selectedOption = dropdownElement.querySelector('.custom-select-option[selected]');
                if (selectedOption) {
                    selectedOption.click();
                }
            }

            if (document.getElementById('rekening-asal-select')) {
                initCustomSelect(
                    'rekening-asal-select', 
                    'rekening-asal-dropdown', 
                    'rekening-asal-input',
                    'search-rekening-asal'
                );
            }
            
            if (document.getElementById('rekening-tujuan-select')) {
                initCustomSelect(
                    'rekening-tujuan-select', 
                    'rekening-tujuan-dropdown', 
                    'rekening-tujuan-input',
                    'search-rekening-tujuan'
                );
            }

            if (transferForm && transferButton) {
                transferButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    const pin = pinValue.value;
                    const jumlah = amountInput ? amountInput.value.replace(/[^0-9]/g, '') : '';
                    const rekeningAsal = document.querySelector('input[name="rekening_asal"]').value;
                    const rekeningTujuan = document.querySelector('input[name="rekening_tujuan"]').value;
                    
                    if (!rekeningAsal) {
                        showAlert('Rekening asal harus diisi.', 'error');
                        document.getElementById('rekening_asal-error').textContent = 'Rekening asal harus diisi.';
                        document.getElementById('rekening_asal-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('rekening_asal-error').classList.remove('show');
                    }

                    if (rekeningTujuan === prefix || !rekeningTujuan) {
                        showAlert('Rekening tujuan harus diisi.', 'error');
                        document.getElementById('rekening_tujuan-error').textContent = 'Rekening tujuan harus diisi.';
                        document.getElementById('rekening_tujuan-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('rekening_tujuan-error').classList.remove('show');
                    }

                    if (rekeningAsal === rekeningTujuan) {
                        showAlert('Rekening tujuan tidak boleh sama dengan rekening asal.', 'error');
                        document.getElementById('rekening_tujuan-error').textContent = 'Rekening tujuan tidak boleh sama dengan rekening asal.';
                        document.getElementById('rekening_tujuan-error').classList.add('show');
                        return;
                    }

                    if (!jumlah || Number(jumlah) < 1000) {
                        showAlert('Jumlah transfer minimal Rp 1.000.', 'error');
                        document.getElementById('jumlah-error').textContent = 'Jumlah transfer minimal Rp 1.000.';
                        document.getElementById('jumlah-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('jumlah-error').classList.remove('show');
                    }

                    if (!pin || pin.length !== 6) {
                        shakePinContainer();
                        showAlert('PIN harus 6 digit.', 'error');
                        document.getElementById('pin-error').textContent = 'PIN harus 6 digit.';
                        document.getElementById('pin-error').classList.add('show');
                        return;
                    } else {
                        document.getElementById('pin-error').classList.remove('show');
                    }

                    this.classList.add('loading');
                    setTimeout(() => {
                        transferForm.submit();
                    }, 500);
                });
            }

            // Modal close handling
            ['successModal', 'errorModal'].forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.addEventListener('click', (e) => {
                        if (e.target.classList.contains('modal-overlay')) {
                            closeModal(modalId);
                            if (modalId === 'successModal') {
                                resetForm();
                            } else {
                                clearPinInputs(); // Clear PIN only on error
                            }
                        }
                    });
                    setTimeout(() => {
                        closeModal(modalId);
                        if (modalId === 'successModal') {
                            resetForm();
                        } else {
                            clearPinInputs(); // Clear PIN only on error
                        }
                    }, 5000);
                }
            });
        });
    </script>
</body>
</html>