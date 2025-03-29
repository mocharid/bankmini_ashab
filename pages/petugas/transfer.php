<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php'; // Include PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if the database connection exists
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

// Get user's account if they're a student
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
$form_data = []; // To retain form data on error

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Store form data for repopulation
    $form_data = [
        'rekening_asal' => $_POST['rekening_asal'] ?? '',
        'rekening_tujuan' => $_POST['rekening_tujuan'] ?? '',
        'jumlah' => $_POST['jumlah'] ?? 0,
    ];
    
    // Validate form data
    $rekening_asal = $_POST['rekening_asal'] ?? '';
    $rekening_tujuan = $_POST['rekening_tujuan'] ?? '';
    $jumlah = $_POST['jumlah'] ?? 0;
    $pin = $_POST['pin'] ?? '';
    
    if (empty($rekening_asal) || empty($rekening_tujuan) || $jumlah <= 0 || empty($pin)) {
        $error = "Semua field harus diisi dengan benar.";
    } else if ($rekening_asal == $rekening_tujuan) {
        $error = "Nomor rekening tujuan tidak boleh sama dengan rekening asal.";
    } else {
        // Get rekening details
        $stmt = $koneksi->prepare("SELECT r.*, u.nama, u.pin, u.email FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Verify PIN - Changed to match the reference implementation
        $stmt = $koneksi->prepare("SELECT pin, email, nama FROM users WHERE id = ?");
        $stmt->bind_param("i", $rek_asal['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        $stmt->close();

        if (!$user_data || $user_data['pin'] !== $pin) {
            $error = "PIN yang Anda masukkan salah!";
        } else {
            $stmt = $koneksi->prepare("SELECT r.*, u.nama, u.email FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
            $stmt->bind_param("s", $rekening_tujuan);
            $stmt->execute();
            $rek_tujuan = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Validate accounts
            if (!$rek_asal) {
                $error = "Rekening asal tidak ditemukan.";
            } else if (!$rek_tujuan) {
                $error = "Rekening tujuan tidak ditemukan.";
            } else if ($rek_asal['saldo'] < $jumlah) {
                $error = "Saldo tidak mencukupi untuk transfer.";
            } else {
                // Begin transaction
                $koneksi->begin_transaction();
                
                try {
                    // Generate transaction number
                    $no_transaksi = 'TRF' . date('YmdHis') . rand(100, 999);
                    
                    // Get petugas on duty today
                    $today = date('Y-m-d');
                    $stmt = $koneksi->prepare("SELECT * FROM petugas_tugas WHERE tanggal = ?");
                    $stmt->bind_param("s", $today);
                    $stmt->execute();
                    $petugas_tugas = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    
                    // Insert transaction record
                    $stmt = $koneksi->prepare("INSERT INTO transaksi (no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, petugas_id, status) VALUES (?, ?, ?, 'transfer', ?, ?, 'approved')");
                    $stmt->bind_param("siidi", $no_transaksi, $rek_asal['id'], $rek_tujuan['id'], $jumlah, $user_id);
                    $stmt->execute();
                    $transaksi_id = $koneksi->insert_id;
                    $stmt->close();
                    
                    // Update source account balance
                    $saldo_asal_baru = $rek_asal['saldo'] - $jumlah;
                    $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                    $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Update destination account balance
                    $saldo_tujuan_baru = $rek_tujuan['saldo'] + $jumlah;
                    $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                    $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Create mutation record for source account
                    $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                    $jumlah_negative = -$jumlah;
                    $stmt->bind_param("iidd", $transaksi_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Create mutation record for destination account
                    $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("iidd", $transaksi_id, $rek_tujuan['id'], $jumlah, $saldo_tujuan_baru);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Send notification to sender
                    $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rek_tujuan['no_rekening'] . " berhasil. Saldo baru: Rp " . number_format($saldo_asal_baru, 0, ',', '.');
                    $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $stmt->bind_param('is', $rek_asal['user_id'], $message_pengirim);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Send notification to receiver
                    $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $rek_asal['no_rekening'] . ". Saldo baru: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.');
                    $stmt = $koneksi->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $stmt->bind_param('is', $rek_tujuan['user_id'], $message_penerima);
                    $stmt->execute();
                    $stmt->close();
                    
                    // Send emails to both parties
                    sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas);
                    
                    $message = "Transfer berhasil dilakukan! Nomor transaksi: " . $no_transaksi;
                    
                    // Refresh the account data after transfer
                    if ($role == 'siswa') {
                        $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE user_id = ?");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $rekening = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                    }
                    
                    $koneksi->commit();
                    
                    // Clear form data after successful transfer
                    $form_data = [];
                    
                } catch (Exception $e) {
                    $koneksi->rollback();
                    $error = "Transfer gagal: " . $e->getMessage();
                }
            }
        }
    }
}

// Function to send transfer emails
function sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        // Petugas information
        $petugas_info = "";
        if ($petugas_tugas) {
            $petugas_info = "<div class='transaction-row'>
                <div class='label'>Petugas 1</div>
                <div class='value'>{$petugas_tugas['petugas1_nama']}</div>
            </div>
            <div class='transaction-row'>
                <div class='label'>Petugas 2</div>
                <div class='value'>{$petugas_tugas['petugas2_nama']}</div>
            </div>";
        }

        // Recipients - Sender
        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($rek_asal['email'], $rek_asal['nama']);
        
        // Content - Sender
        $mail->isHTML(true);
        $mail->Subject = 'Bukti Transfer - SCHOBANK SYSTEM';
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"UTF-8\">
            <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
            <title>Bukti Transfer - SCHOBANK SYSTEM</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    background-color: #f5f5f5;
                    margin: 0;
                    padding: 20px;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    padding: 30px; 
                    border: 1px solid #ddd; 
                    border-radius: 8px;
                    background-color: white;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                h2 { 
                    color: #0a2e5c; 
                    border-bottom: 2px solid #0a2e5c; 
                    padding-bottom: 10px; 
                    margin-top: 0;
                    text-align: center;
                }
                .logo {
                    text-align: center;
                    margin-bottom: 20px;
                }
                .transaction-details {
                    background-color: #f9f9f9;
                    border: 1px solid #eee;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .transaction-row {
                    display: flex;
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                }
                .transaction-row:last-child {
                    border-bottom: none;
                }
                .label {
                    font-weight: bold;
                    color: #2c3e50;
                    width: 40%;
                    position: relative;
                    padding-right: 10px;
                }
                .label::after {
                    content: ':';
                    position: absolute;
                    right: 10px;
                }
                .value {
                    width: 60%;
                    padding-left: 10px;
                    text-align: left;
                }
                .amount {
                    font-size: 1.2em;
                    color: #2980b9;
                    font-weight: bold;
                }
                .new-balance {
                    font-size: 1.1em;
                    color:rgb(20, 6, 89);
                    font-weight: bold;
                }
                .security-notice {
                    background-color: #fff8e1;
                    border-left: 4px solid #f39c12;
                    padding: 10px 15px;
                    margin: 20px 0;
                    font-size: 0.9em;
                    color: #7f8c8d;
                }
                .footer { 
                    margin-top: 30px; 
                    font-size: 0.8em; 
                    color: #666; 
                    border-top: 1px solid #ddd; 
                    padding-top: 15px; 
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <h2>Bukti Transfer</h2>
                
                <p>Halo, <strong>{$rek_asal['nama']}</strong>!</p>
                <p>Anda telah berhasil melakukan transfer. Berikut adalah rincian transaksi:</p>
                
                <div class=\"transaction-details\">
                    <div class=\"transaction-row\">
                        <div class=\"label\">Nomor Transaksi</div>
                        <div class=\"value\">{$no_transaksi}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Rekening Asal</div>
                        <div class=\"value\">{$rek_asal['no_rekening']}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Rekening Tujuan</div>
                        <div class=\"value\">{$rek_tujuan['no_rekening']} - {$rek_tujuan['nama']}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Jumlah Transfer</div>
                        <div class=\"value amount\">Rp " . number_format($jumlah, 0, ',', '.') . "</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Saldo Baru</div>
                        <div class=\"value new-balance\">Rp " . number_format($saldo_asal_baru, 0, ',', '.') . "</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Tanggal Transaksi</div>
                        <div class=\"value\">" . date('d M Y H:i:s') . " WIB</div>
                    </div>
                    {$petugas_info}
                </div>
                
                <div class=\"security-notice\">
                    <strong>Perhatian Keamanan:</strong> Jangan pernah membagikan informasi rekening, PIN, atau detail transaksi Anda kepada siapapun.
                </div>
                
                <div class=\"footer\">
                    <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                    <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        
        // Recipients - Receiver
        $mail->clearAddresses();
        $mail->addAddress($rek_tujuan['email'], $rek_tujuan['nama']);
        
        // Content - Receiver
        $mail->Subject = 'Penerimaan Transfer - SCHOBANK SYSTEM';
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset=\"UTF-8\">
            <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
            <title>Penerimaan Transfer - SCHOBANK SYSTEM</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    line-height: 1.6; 
                    color: #333; 
                    background-color: #f5f5f5;
                    margin: 0;
                    padding: 20px;
                }
                .container { 
                    max-width: 600px; 
                    margin: 0 auto; 
                    padding: 30px; 
                    border: 1px solid #ddd; 
                    border-radius: 8px;
                    background-color: white;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                h2 { 
                    color: #0a2e5c; 
                    border-bottom: 2px solid #0a2e5c; 
                    padding-bottom: 10px; 
                    margin-top: 0;
                    text-align: center;
                }
                .logo {
                    text-align: center;
                    margin-bottom: 20px;
                }
                .transaction-details {
                    background-color: #f9f9f9;
                    border: 1px solid #eee;
                    border-radius: 5px;
                    padding: 15px;
                    margin: 20px 0;
                }
                .transaction-row {
                    display: flex;
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                }
                .transaction-row:last-child {
                    border-bottom: none;
                }
                .label {
                    font-weight: bold;
                    color: #2c3e50;
                    width: 40%;
                    position: relative;
                    padding-right: 10px;
                }
                .label::after {
                    content: ':';
                    position: absolute;
                    right: 10px;
                }
                .value {
                    width: 60%;
                    padding-left: 10px;
                    text-align: left;
                }
                .amount {
                    font-size: 1.2em;
                    color: #2980b9;
                    font-weight: bold;
                }
                .new-balance {
                    font-size: 1.1em;
                    color:rgb(20, 6, 89);
                    font-weight: bold;
                }
                .security-notice {
                    background-color: #fff8e1;
                    border-left: 4px solid #f39c12;
                    padding: 10px 15px;
                    margin: 20px 0;
                    font-size: 0.9em;
                    color: #7f8c8d;
                }
                .footer { 
                    margin-top: 30px; 
                    font-size: 0.8em; 
                    color: #666; 
                    border-top: 1px solid #ddd; 
                    padding-top: 15px; 
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <h2>Penerimaan Transfer</h2>
                
                <p>Halo, <strong>{$rek_tujuan['nama']}</strong>!</p>
                <p>Anda telah menerima transfer dana. Berikut adalah rincian transaksi:</p>
                
                <div class=\"transaction-details\">
                    <div class=\"transaction-row\">
                        <div class=\"label\">Nomor Transaksi</div>
                        <div class=\"value\">{$no_transaksi}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Rekening Pengirim</div>
                        <div class=\"value\">{$rek_asal['no_rekening']} - {$rek_asal['nama']}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Rekening Tujuan</div>
                        <div class=\"value\">{$rek_tujuan['no_rekening']}</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Jumlah Transfer</div>
                        <div class=\"value amount\">Rp " . number_format($jumlah, 0, ',', '.') . "</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Saldo Baru</div>
                        <div class=\"value new-balance\">Rp " . number_format($saldo_tujuan_baru, 0, ',', '.') . "</div>
                    </div>
                    <div class=\"transaction-row\">
                        <div class=\"label\">Tanggal Transaksi</div>
                        <div class=\"value\">" . date('d M Y H:i:s') . " WIB</div>
                    </div>
                    {$petugas_info}
                </div>
                
                <div class=\"security-notice\">
                    <strong>Perhatian Keamanan:</strong> Jangan pernah membagikan informasi rekening, PIN, atau detail transaksi Anda kepada siapapun.
                </div>
                
                <div class=\"footer\">
                    <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                    <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
        // Don't throw exception, just log the error
    }
}

// Get all accounts with user and class information for admin/officer selection
$accounts = [];
if ($role == 'admin' || $role == 'petugas') {
    $query = "SELECT r.*, u.nama, u.email, k.nama_kelas, j.nama_jurusan 
              FROM rekening r 
              JOIN users u ON r.user_id = u.id 
              LEFT JOIN kelas k ON u.kelas_id = k.id 
              LEFT JOIN jurusan j ON u.jurusan_id = j.id 
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer - SCHOBANK SYSTEM</title>
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

        .transaction-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 30px;
        }

        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .input-group {
            display: flex;
            align-items: center;
        }

        .input-group-prepend {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-right: none;
            padding: 10px 15px;
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }

        .input-group .form-control {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: var(--transition);
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid #166534;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #b91c1c;
        }

        small {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.3);
            animation: modalFadeIn 0.3s;
        }

        @keyframes modalFadeIn {
            from {opacity: 0; transform: translateY(-50px);}
            to {opacity: 1; transform: translateY(0);}
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-dark);
        }

        .modal-body {
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
        }

        /* Styles for the search box */
        .search-box {
            position: relative;
            margin-bottom: 10px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px;
            padding-left: 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        /* Styles for the select2-like dropdown */
        .custom-select-wrapper {
            position: relative;
        }

        .custom-select {
            cursor: pointer;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 15px;
            background-color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-top: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            z-index: 100;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .custom-select-dropdown.active {
            display: block;
        }

        .custom-select-option {
            padding: 10px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .custom-select-option:hover {
            background-color: var(--primary-light);
        }

        .custom-select-option.selected {
            background-color: var(--primary-light);
        }

        .custom-select-no-results {
            padding: 10px 15px;
            color: var(--text-secondary);
            font-style: italic;
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

        @media (max-width: 768px) {
            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: 24px;
            }

            .transaction-card {
                padding: 15px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .modal-content {
                margin: 20% auto;
                width: 95%;
            }
        }
       /* Button with loading spinner */
       .btn-primary {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .btn-primary.loading {
            padding-left: 40px;
            pointer-events: none;
            opacity: 0.8;
        }
        
        .btn-spinner {
            position: absolute;
            left: 15px;
            top: 50%;
            margin-top: -7px;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.5);
            border-top: 2px solid #ffffff;
            border-radius: 50%;
            animation: button-spin 1s linear infinite;
            display: none;
        }
        
        .btn-primary.loading .btn-spinner {
            display: block;
        }
        
        @keyframes button-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Alert styles with fade animation */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            transition: opacity 0.5s ease-in-out;
            opacity: 1;
        }
        
        .alert-success {
            background-color: #dff0d8;
            color: #3c763d;
            border: 1px solid #d6e9c6;
        }
        
        .alert-danger {
            background-color: #f2dede;
            color: #a94442;
            border: 1px solid #ebccd1;
        }
        
        .alert.fade-out {
            opacity: 0;
        }
        
        /* Success modal style update */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; }
        }
        
        .modal.fade-out {
            animation: fadeOut 0.3s ease-in-out forwards;
        }
    </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-paper-plane"></i> Transfer</h2>
            <p>Kirim uang ke rekening lain dengan mudah dan cepat</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-success" id="successAlert">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" id="errorAlert">
                <?= $error ?>
            </div>
        <?php endif; ?>
        
        <div class="transaction-card">
            <div class="card-body">
                <form method="POST" action="" id="transferForm">
                    <?php if ($role == 'siswa' && $rekening): ?>
                        <div class="form-group">
                            <label>Rekening Asal:</label>
                            <input type="text" name="rekening_asal" value="<?= $rekening['no_rekening'] ?>" readonly class="form-control">
                            <small>Saldo: Rp <?= number_format($rekening['saldo'], 2, ',', '.') ?></small>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Pilih Rekening Asal:</label>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-asal" placeholder="Cari rekening berdasarkan nama atau kelas" class="form-control">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-asal-select">
                                    <span><?= !empty($form_data['rekening_asal']) ? $form_data['rekening_asal'] : '-- Pilih Rekening --' ?></span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-asal-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php 
                                        $kelas_info = $account['nama_kelas'] ? $account['nama_kelas'] : 'Belum ditentukan';
                                        $jurusan_info = $account['nama_jurusan'] ? $account['nama_jurusan'] : '';
                                        $display_text = "{$account['nama']} - {$kelas_info}";
                                        if ($jurusan_info) {
                                            $display_text .= " ({$jurusan_info})";
                                        }
                                        ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= $account['no_rekening'] ?>"
                                             data-search="<?= $account['nama'] ?> <?= $kelas_info ?> <?= $jurusan_info ?>"
                                             <?= (!empty($form_data['rekening_asal']) && $form_data['rekening_asal'] == $account['no_rekening']) ? 'selected' : '' ?>>
                                            <?= $display_text ?> 
                                            (Saldo: Rp <?= number_format($account['saldo'], 2, ',', '.') ?>)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_asal" id="rekening-asal-input" value="<?= $form_data['rekening_asal'] ?? '' ?>" required>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Rekening Tujuan:</label>
                        <?php if ($role == 'siswa'): ?>
                            <input type="text" name="rekening_tujuan" class="form-control" required 
                                   placeholder="Masukkan nomor rekening tujuan" value="<?= $form_data['rekening_tujuan'] ?? '' ?>">
                        <?php else: ?>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-tujuan" placeholder="Cari rekening berdasarkan nama atau kelas" class="form-control">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-tujuan-select">
                                    <span><?= !empty($form_data['rekening_tujuan']) ? $form_data['rekening_tujuan'] : '-- Pilih Rekening --' ?></span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-tujuan-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <?php 
                                        $kelas_info = $account['nama_kelas'] ? $account['nama_kelas'] : 'Belum ditentukan';
                                        $jurusan_info = $account['nama_jurusan'] ? $account['nama_jurusan'] : '';
                                        $display_text = "{$account['nama']} - {$kelas_info}";
                                        if ($jurusan_info) {
                                            $display_text .= " ({$jurusan_info})";
                                        }
                                        ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= $account['no_rekening'] ?>"
                                             data-search="<?= $account['nama'] ?> <?= $kelas_info ?> <?= $jurusan_info ?>"
                                             <?= (!empty($form_data['rekening_tujuan']) && $form_data['rekening_tujuan'] == $account['no_rekening']) ? 'selected' : '' ?>>
                                            <?= $display_text ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_tujuan" id="rekening-tujuan-input" value="<?= $form_data['rekening_tujuan'] ?? '' ?>" required>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Jumlah Transfer:</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">Rp</span>
                            </div>
                            <input type="number" name="jumlah" class="form-control" required min="1000" step="1000"
                                   placeholder="Masukkan jumlah transfer" value="<?= $form_data['jumlah'] ?? '' ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>PIN Pengirim:</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" name="pin" class="form-control" required 
                                   placeholder="Masukkan PIN Anda" maxlength="6">
                            <i class="fas fa-eye password-toggle" id="toggle-pin"></i>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="transferButton">
                        <div class="btn-spinner"></div>
                        <i class="fas fa-paper-plane"></i> Transfer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <?php if (!empty($message)): ?>
    <div id="successModal" class="modal" style="display: block;">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">
                    <i class="fas fa-check-circle" style="color: #4caf50; margin-right: 10px;"></i>
                    Transfer Berhasil
                </div>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p><?= $message ?></p>
                <p>Anda akan diarahkan kembali dalam beberapa detik...</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeModal()">Tutup</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Handle auto-dismissing alerts
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            
            if (successAlert || errorAlert) {
                setTimeout(function() {
                    if (successAlert) {
                        successAlert.classList.add('fade-out');
                        setTimeout(() => {
                            successAlert.style.display = 'none';
                        }, 500);
                    }
                    if (errorAlert) {
                        errorAlert.classList.add('fade-out');
                        setTimeout(() => {
                            errorAlert.style.display = 'none';
                        }, 500);
                    }
                }, 5000); // Hide alerts after 5 seconds
            }
            
            // Function to initialize custom select dropdowns
            function initCustomSelect(selectId, dropdownId, inputId, searchId) {
                const selectElement = document.getElementById(selectId);
                const dropdownElement = document.getElementById(dropdownId);
                const inputElement = document.getElementById(inputId);
                const searchElement = document.getElementById(searchId);
                
                if (!selectElement || !dropdownElement || !inputElement || !searchElement) return;
                
                // Toggle dropdown when clicking on select
                selectElement.addEventListener('click', function() {
                    dropdownElement.classList.toggle('active');
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!selectElement.contains(e.target) && !dropdownElement.contains(e.target) && 
                        !searchElement.contains(e.target)) {
                        dropdownElement.classList.remove('active');
                    }
                });
                
                // Handle option selection
                const options = dropdownElement.querySelectorAll('.custom-select-option');
                options.forEach(option => {
                    option.addEventListener('click', function() {
                        const value = this.getAttribute('data-value');
                        inputElement.value = value;
                        
                        // Update the display text
                        selectElement.querySelector('span').textContent = value ? this.textContent : '-- Pilih Rekening --';
                        
                        // Mark as selected
                        options.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                        
                        // Close dropdown
                        dropdownElement.classList.remove('active');
                    });
                });
                
                // Handle search filtering
                searchElement.addEventListener('input', function() {
                    const searchValue = this.value.toLowerCase();
                    let hasResults = false;
                    
                    // Remove any existing "no results" message
                    const existingNoResults = dropdownElement.querySelector('.custom-select-no-results');
                    if (existingNoResults) {
                        dropdownElement.removeChild(existingNoResults);
                    }
                    
                    options.forEach(option => {
                        if (option.getAttribute('data-value') === '') return; // Skip the placeholder
                        
                        const searchText = option.getAttribute('data-search').toLowerCase();
                        if (searchText.includes(searchValue)) {
                            option.style.display = 'block';
                            hasResults = true;
                        } else {
                            option.style.display = 'none';
                        }
                    });
                    
                    // Show dropdown when searching
                    dropdownElement.classList.add('active');
                    
                    // Show "no results" message if no options match
                    if (!hasResults && searchValue !== '') {
                        const noResultsDiv = document.createElement('div');
                        noResultsDiv.className = 'custom-select-no-results';
                        noResultsDiv.textContent = 'Tidak ada rekening yang cocok dengan pencarian';
                        dropdownElement.appendChild(noResultsDiv);
                    }
                });
                
                // Focus on search input when dropdown opens
                selectElement.addEventListener('click', function() {
                    searchElement.focus();
                });
                
                // Initialize selected option if exists
                const selectedOption = dropdownElement.querySelector('.custom-select-option[selected]');
                if (selectedOption) {
                    selectedOption.click();
                }
            }
            
            // Initialize both dropdowns if they exist
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
            
            // Handle form submission with button loading animation
            const transferForm = document.getElementById('transferForm');
            const transferButton = document.getElementById('transferButton');
            
            if (transferForm && transferButton) {
                transferForm.addEventListener('submit', function(e) {
                    // Add loading state to button
                    transferButton.classList.add('loading');
                    transferButton.disabled = true;
                    
                    // Allow form submission
                    return true;
                });
            }
            
            // Auto-close success modal after 5 seconds with animation
            const successModal = document.getElementById('successModal');
            if (successModal) {
                setTimeout(function() {
                    successModal.classList.add('fade-out');
                    setTimeout(function() {
                        window.location.href = 'dashboard.php';
                    }, 300); // Redirect after fade animation
                }, 5000);
            }
            
            // Toggle password visibility
            const togglePin = document.getElementById('toggle-pin');
            if (togglePin) {
                togglePin.addEventListener('click', function() {
                    const pinInput = this.parentNode.querySelector('input');
                    if (pinInput.type === 'password') {
                        pinInput.type = 'text';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    } else {
                        pinInput.type = 'password';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    }
                });
            }
        });
        
        function closeModal() {
            const modal = document.getElementById('successModal');
            modal.classList.add('fade-out');
            setTimeout(function() {
                modal.style.display = 'none';
                window.location.href = 'dashboard.php';
            }, 300);
        }
    </script>
</body>
</html>