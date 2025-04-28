<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;

    if ($action === 'cek_rekening') {
        $query = "
            SELECT r.no_rekening, u.nama, u.email, u.id as user_id, r.id as rekening_id, r.saldo, 
                   j.nama_jurusan AS jurusan, k.nama_kelas AS kelas, u.is_frozen
            FROM rekening r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id
            LEFT JOIN kelas k ON u.kelas_id = k.id
            WHERE r.no_rekening = ? AND u.role = 'siswa'
        ";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'Gagal menyiapkan kueri: ' . $conn->error, 'email_status' => 'none']);
            exit();
        }
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan atau bukan milik siswa.', 'email_status' => 'none']);
        } else {
            $row = $result->fetch_assoc();
            // Check if account is frozen
            if ($row['is_frozen'] == 1) {
                echo json_encode(['status' => 'error', 'message' => 'Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.', 'email_status' => 'none']);
                exit();
            }
            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'jurusan' => $row['jurusan'] ?: '-',
                'kelas' => $row['kelas'] ?: '-',
                'email' => $row['email'],
                'user_id' => $row['user_id'],
                'rekening_id' => $row['rekening_id'],
                'saldo' => floatval($row['saldo']),
                'email_status' => 'none'
            ]);
        }
    } elseif ($action === 'setor_saldo') {
        try {
            $conn->begin_transaction();

            // 1. Validasi input
            if (empty($no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            if ($jumlah <= 0 || $jumlah < 1000) {
                throw new Exception('Jumlah penyetoran minimal Rp 10.000 dan harus lebih dari 0.');
            }
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penyetoran melebihi batas maksimum Rp 99.999.999,99.');
            }

            // 2. Validasi rekening siswa dan status frozen
            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, j.nama_jurusan AS jurusan, k.nama_kelas AS kelas, u.is_frozen
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
            ";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan kueri rekening: ' . $conn->error);
            }
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan atau bukan milik siswa.');
            }

            $row = $result->fetch_assoc();
            // Check if account is frozen
            if ($row['is_frozen'] == 1) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
            }
            $rekening_id = intval($row['id']);
            $saldo_sekarang = floatval($row['saldo']);
            $nama_nasabah = $row['nama'];
            $user_id = intval($row['user_id']);
            $email = $row['email'];
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';

            if ($rekening_id <= 0) {
                throw new Exception('ID rekening tidak valid.');
            }

            // 3. Catat transaksi dengan petugas_id = NULL
            $no_transaksi = 'TRXS' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $petugas_id = null; // Gunakan NULL untuk penyetoran admin
            $jumlah = round(floatval($jumlah), 2); // Pastikan jumlah sesuai DECIMAL(10,2)

            // Cek duplikasi no_transaksi
            $query_check_transaksi = "SELECT COUNT(*) as count FROM transaksi WHERE no_transaksi = ?";
            $stmt_check_transaksi = $conn->prepare($query_check_transaksi);
            if (!$stmt_check_transaksi) {
                throw new Exception('Gagal menyiapkan kueri cek transaksi: ' . $conn->error);
            }
            $stmt_check_transaksi->bind_param('s', $no_transaksi);
            $stmt_check_transaksi->execute();
            $transaksi_count = $stmt_check_transaksi->get_result()->fetch_assoc()['count'];
            if ($transaksi_count > 0) {
                throw new Exception('Nomor transaksi sudah ada, silakan coba lagi.');
            }

            // Log nilai sebelum insert
            error_log("Sebelum insert transaksi: no_transaksi=$no_transaksi (" . gettype($no_transaksi) . "), rekening_id=$rekening_id (" . gettype($rekening_id) . "), jumlah=$jumlah (" . gettype($jumlah) . "), petugas_id=" . ($petugas_id === null ? 'NULL' : $petugas_id) . " (" . gettype($petugas_id) . ")");

            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'setor', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Gagal menyiapkan statement transaksi: ' . $conn->error);
            }
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi: ' . $stmt_transaksi->error);
            }
            $transaksi_id = $conn->insert_id;

            // 4. Tambah saldo siswa
            $saldo_baru = $saldo_sekarang + $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            if (!$stmt_update_saldo) {
                throw new Exception('Gagal menyiapkan update saldo: ' . $conn->error);
            }
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo siswa: ' . $stmt_update_saldo->error);
            }

            // 5. Catat mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            if (!$stmt_mutasi) {
                throw new Exception('Gagal menyiapkan mutasi: ' . $conn->error);
            }
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi: ' . $stmt_mutasi->error);
            }

            // 6. Catat notifikasi
            $message = "Transaksi penyetoran saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh admin.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            if (!$stmt_notifikasi) {
                error_log("Gagal menyiapkan notifikasi: " . $conn->error);
            } else {
                $stmt_notifikasi->bind_param('is', $user_id, $message);
                if (!$stmt_notifikasi->execute()) {
                    error_log("Gagal menyimpan notifikasi untuk user_id: $user_id, no_transaksi: $no_transaksi, error: " . $stmt_notifikasi->error);
                }
            }

            // 7. Kirim email
            $email_sent = false;
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Transaksi Penyetoran Saldo - SCHOBANK SYSTEM";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset=\"UTF-8\">
                    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                    <title>Bukti Transaksi Penyetoran Saldo - SCHOBANK SYSTEM</title>
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
                        <h2>Bukti Transaksi Penyetoran Saldo</h2>
                        <p>Yth. {$nama_nasabah},</p>
                        <p>Kami informasikan bahwa transaksi penyetoran saldo ke rekening Anda telah berhasil diproses oleh admin. Berikut rincian transaksi:</p>
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
                                <div class=\"label\">Jurusan</div>
                                <div class=\"value\">{$jurusan}</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Kelas</div>
                                <div class=\"value\">{$kelas}</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Jumlah Penyetoran</div>
                                <div class=\"value amount\">Rp " . number_format($jumlah, 0, ',', '.') . "</div>
                            </div>
                            <div class=\"transaction-row\">
                                <div class=\"label\">Tanggal Transaksi</div>
                                <div class=\"value\">" . date('d M Y H:i:s') . " WIB</div>
                            </div>
                        </div>
                        <p>Terima kasih telah menggunakan layanan SCHOBANK SYSTEM. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi admin kami.</p>
                        <div class=\"security-notice\">
                            <strong>Perhatian Keamanan:</strong> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain. Admin SCHOBANK tidak akan meminta informasi tersebut melalui email atau telepon.
                        </div>
                        <div class=\"footer\">
                            <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                            <p>Jika Anda memiliki pertanyaan, silakan hubungi Bank Mini SMK Plus Ashabulyamin.</p>
                            <p>© " . date('Y') . " SCHOBANK SYSTEM - Hak cipta dilindungi.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";

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
                    $mail->addAddress($email, $nama_nasabah);

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $message));

                    $mail->send();
                    $email_sent = true;
                    $email_status = 'sent';
                } catch (Exception $e) {
                    error_log("Mail error: no_transaksi=$no_transaksi, error: " . $mail->ErrorInfo);
                    $email_status = 'failed';
                }
            }

            $conn->commit();

            // Log transaksi berhasil
            error_log("Penyetoran berhasil: no_transaksi=$no_transaksi, jumlah=$jumlah, no_rekening=$no_rekening, petugas_id=NULL, email_status=$email_status");

            $response_message = "Transaksi penyetoran saldo untuk {$nama_nasabah} telah berhasil diproses.";
            if ($email_status === 'failed') {
                $response_message .= " (Gagal mengirim bukti transaksi ke email)";
            } else if ($email_status === 'sent') {
                $response_message .= " (Bukti transaksi telah dikirim ke {$email})";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_status' => $email_status,
                'data' => [
                    'no_transaksi' => $no_transaksi,
                    'no_rekening' => $no_rekening,
                    'nama_nasabah' => $nama_nasabah,
                    'jumlah' => number_format($jumlah, 0, ',', '.'),
                    'saldo_sebelum' => number_format($saldo_sekarang, 0, ',', '.'),
                    'saldo_sesudah' => number_format($saldo_baru, 0, ',', '.'),
                    'tanggal' => date('d M Y H:i:s')
                ]
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
            error_log("Penyetoran gagal: no_rekening=$no_rekening, jumlah=$jumlah, error: $error_message");
            echo json_encode(['status' => 'error', 'message' => $error_message, 'email_status' => 'none']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
    }
}
$conn->close();
?>