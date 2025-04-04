<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php'; // Sesuaikan path ke autoload PHPMailer
date_default_timezone_set('Asia/Jakarta'); // Set timezone ke WIB

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);

    if ($action === 'cek') {
        // Cek rekening
        $query = "SELECT r.*, u.nama, u.email FROM rekening r 
                  JOIN users u ON r.user_id = u.id 
                  WHERE r.no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.']);
        } else {
            $row = $result->fetch_assoc();
            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'saldo' => floatval($row['saldo']), // Pastikan saldo adalah angka
                'email' => $row['email'] // Tambahkan email ke response
            ]);
        }
    } elseif ($action === 'setor') {
        try {
            $conn->begin_transaction();

            // Cek rekening
            $query = "SELECT r.*, u.nama, u.email FROM rekening r 
                      JOIN users u ON r.user_id = u.id 
                      WHERE r.no_rekening = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }

            $row = $result->fetch_assoc();
            $rekening_id = $row['id'];
            $saldo_sekarang = $row['saldo'];
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id']; // ID siswa
            $email = $row['email'] ?? ''; // Email siswa, with fallback to empty string if null

            // Generate nomor transaksi
            $no_transaksi = 'TRX' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            // Insert transaksi
            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status) 
                               VALUES (?, ?, 'setor', ?, ?, 'approved')";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $petugas_id = $_SESSION['user_id'];
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }

            $transaksi_id = $conn->insert_id;

            // Update saldo
            $saldo_baru = $saldo_sekarang + $jumlah;
            $query_update = "UPDATE rekening SET saldo = ? WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);
            $stmt_update->bind_param('di', $saldo_baru, $rekening_id);
            
            if (!$stmt_update->execute()) {
                throw new Exception('Gagal update saldo.');
            }

            // Insert mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) 
                            VALUES (?, ?, ?, ?)";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }

            // Kirim notifikasi ke siswa
            $message = "Setoran tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.');
            $query_notifikasi = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }

            // Hanya kirim email jika alamat email ada dan valid
            $email_sent = false;
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Transaksi Setor Tunai - SCHOBANK SYSTEM";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset=\"UTF-8\">
                    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                    <title>Bukti Transaksi Setor Tunai - SCHOBANK SYSTEM</title>
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
                        .logo img {
                            height: 60px;
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
                        .btn { 
                            display: inline-block; 
                            background: #0a2e5c; 
                            color: white; 
                            padding: 10px 20px; 
                            text-decoration: none; 
                            border-radius: 5px;
                            margin-top: 15px;
                        }
                        .button-container {
                            text-align: center;
                            margin: 20px 0;
                        }
                        .expire-note { 
                            font-size: 0.9em; 
                            color: #666; 
                            margin-top: 20px; 
                        }
                        .footer { 
                            margin-top: 30px; 
                            font-size: 0.8em; 
                            color: #666; 
                            border-top: 1px solid #ddd; 
                            padding-top: 15px; 
                            text-align: center;
                        }
                        .security-notice {
                            background-color: #fff8e1;
                            border-left: 4px solid #f39c12;
                            padding: 10px 15px;
                            margin: 20px 0;
                            font-size: 0.9em;
                            color: #7f8c8d;
                        }
                        p {
                            color: #34495e;
                        }
                    </style>
                </head>
                <body>
                    <div class=\"container\">
                        <h2>Bukti Transaksi Setor Tunai</h2>
                        
                        <p>Halo, <strong>{$nama_nasabah}</strong>!</p>
                        <p>Setor tunai ke rekening Anda telah berhasil diproses. Berikut adalah rincian transaksi:</p>
                        
                        <div class=\"transaction-details\">
                            <div class=\"transaction-row\">
                                <div class=\"label\">Nomor Transaksi</div>
                                <div class=\"value\">{$no_transaksi}</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Nomor Rekening</div>
                                <div class=\"value\">{$no_rekening}</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Nama Pemilik</div>
                                <div class=\"value\">{$nama_nasabah}</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Jumlah Setoran</div>
                                <div class=\"value amount\">Rp " . number_format($jumlah, 0, ',', '.') . "</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Saldo Baru</div>
                                <div class=\"value new-balance\">Rp " . number_format($saldo_baru, 0, ',', '.') . "</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Tanggal Transaksi</div>
                                <div class=\"value\">" . date('d M Y H:i:s') . " WIB</div>
                            </div>
                        </div>
                        
                        <p>Terima kasih telah menggunakan layanan SCHOBANK. Untuk melihat riwayat transaksi lengkap, silakan kunjungi halaman akun Anda.</p>
                        
                        <div class=\"security-notice\">
                            <strong>Perhatian Keamanan:</strong> Jangan pernah membagikan informasi rekening, kata sandi, atau detail transaksi Anda kepada siapapun. Petugas SCHOBANK tidak akan pernah meminta informasi tersebut melalui email atau telepon.
                        </div>
                        
                        <div class=\"footer\">
                            <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                            <p>Jika Anda memiliki pertanyaan, silakan datang langsung ke Bank Mini SMK Plus Ashabulyamin</p>
                            <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";

                try {
                    // Konfigurasi PHPMailer
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com'; // Ganti dengan SMTP host Anda
                    $mail->SMTPAuth = true;
                    $mail->Username = 'mocharid.ip@gmail.com'; // Ganti dengan email pengirim
                    $mail->Password = 'spjs plkg ktuu lcxh'; // Ganti dengan password email pengirim
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';

                    // Pengirim dan penerima
                    $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM'); // Konsisten dengan username
                    $mail->addAddress($email, $nama_nasabah); // Email penerima

                    // Konten email
                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $message));

                    // Kirim email
                    $mail->send();
                    $email_sent = true;
                } catch (Exception $e) {
                    // Log error but don't stop transaction
                    error_log("Mail error: " . $mail->ErrorInfo);
                    // We don't throw exception here to allow transaction to continue if email fails
                }
            }

            $conn->commit();

            $response_message = "Setoran tunai untuk nasabah {$nama_nasabah} berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.');
            
            // Add email status to response message
            if (empty($email)) {
                $response_message .= " (Email tidak ditemukan, bukti transaksi tidak dikirim)";
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $response_message .= " (Format email tidak valid, bukti transaksi tidak dikirim)";
            } else if (!$email_sent) {
                $response_message .= " (Bukti transaksi gagal dikirim ke email)";
            } else {
                $response_message .= " (Bukti transaksi dikirim ke email " . $email . ")";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>