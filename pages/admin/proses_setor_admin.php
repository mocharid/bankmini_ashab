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
                throw new Exception('Jumlah penyetoran minimal Rp 1.000 dan harus lebih dari 0.');
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
            $no_transaksi = 'TRXS' . date('Ymd') . sprintf('%06d', mt_rand(100000, 999999));
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
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Create unique subject with transaction ID to prevent email threading
                $subject = "[{$no_transaksi}] Bukti Penyetoran Saldo - SCHOBANK " . date('Y-m-d H:i:s');

                // Konversi bulan ke bahasa Indonesia
                $bulan = [
                    'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
                    'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
                    'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
                ];
                $tanggal_transaksi = date('d M Y H:i:s');
                foreach ($bulan as $en => $id) {
                    $tanggal_transaksi = str_replace($en, $id, $tanggal_transaksi);
                }

                $message = "
                <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f0f5ff; border-radius: 12px; overflow: hidden;'>
                    <div style='background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px 20px; text-align: center; color: #ffffff;'>
                        <h2 style='font-size: 24px; font-weight: 600; margin: 0;'>SCHOBANK SYSTEM</h2>
                        <p style='font-size: 16px; opacity: 0.8; margin: 5px 0 0;'>Bukti Transaksi Penyetoran Saldo</p>
                    </div>
                    <div style='background: #ffffff; padding: 30px;'>
                        <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama_nasabah}</h3>
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami informasikan bahwa transaksi penyetoran saldo ke rekening Anda telah berhasil diproses oleh admin. Berikut rincian transaksi:</p>
                        <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                            <table style='width: 100%; font-size: 15px; color: #333333;'>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Nomor Transaksi</td>
                                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #1e3a8a;'>{$no_transaksi}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Nomor Rekening</td>
                                    <td style='padding: 8px 0; text-align: right;'>{$no_rekening}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Nama Pemilik</td>
                                    <td style='padding: 8px 0; text-align: right;'>{$nama_nasabah}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Jurusan</td>
                                    <td style='padding: 8px 0; text-align: right;'>{$jurusan}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Kelas</td>
                                    <td style='padding: 8px 0; text-align: right;'>{$kelas}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Jumlah Penyetoran</td>
                                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #059669;'>Rp " . number_format($jumlah, 0, ',', '.') . "</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; font-weight: 500;'>Tanggal Transaksi</td>
                                    <td style='padding: 8px 0; text-align: right;'>{$tanggal_transaksi} WIB</td>
                                </tr>
                            </table>
                        </div>
                        
                        <!-- Email Banner Image -->
                        <div style='text-align: center; margin: 20px 0;'>
                            <img src='cid:emailbanner' alt='SCHOBANK Banner' style='max-width: 100%; height: auto; border-radius: 8px; border: 2px solid #e2e8f0;' />
                        </div>
                        
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Terima kasih telah menggunakan layanan SCHOBANK SYSTEM. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi admin kami.</p>
                        <p style='color: #e74c3c; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;'>
                            <span style='font-size: 18px;'>🔒</span> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain.
                        </p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='mailto:schobanksystem@gmail.com' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Hubungi Kami</a>
                        </div>
                    </div>
                    <div style='background: #f0f5ff; padding: 15px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
                        <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
                        <p style='margin: 5px 0 0;'>Email ini otomatis. Mohon tidak membalas.</p>
                        <p style='margin: 5px 0 0; color: #999999;'>Transaksi ID: {$no_transaksi} | " . date('Y-m-d H:i:s T') . "</p>
                    </div>
                </div>";

                try {
                    // Create new PHPMailer instance for each email
                    $mail = new PHPMailer(true);
                    
                    // Clear any previous recipients and attachments
                    $mail->clearAllRecipients();
                    $mail->clearAttachments();
                    $mail->clearReplyTos();
                    
                    // SMTP Configuration
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'schobanksystem@gmail.com';
                    $mail->Password = 'dgry fzmc mfrd hzzq';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';
                    
                    // Email settings to prevent threading
                    $mail->setFrom('schobanksystem@gmail.com', 'SCHOBANK SYSTEM');
                    $mail->addAddress($email, $nama_nasabah);
                    $mail->addReplyTo('no-reply@schobank.com', 'No Reply');
                    
                    // Add unique Message-ID to prevent email threading
                    $unique_id = uniqid('schobank_', true) . '@schobank.com';
                    $mail->MessageID = '<' . $unique_id . '>';
                    
                    // Add custom headers to prevent threading
                    $mail->addCustomHeader('X-Transaction-ID', $no_transaksi);
                    $mail->addCustomHeader('X-Mailer', 'SCHOBANK-System-v1.0');
                    $mail->addCustomHeader('X-Priority', '1');
                    $mail->addCustomHeader('Importance', 'High');
                    
                    // Attach the banner image
                    $banner_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/emailbanner.jpeg';
                    if (file_exists($banner_path)) {
                        $mail->addEmbeddedImage($banner_path, 'emailbanner', 'emailbanner.jpeg');
                    }

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message));

                    // Send email
                    if ($mail->send()) {
                        $email_status = 'sent';
                    } else {
                        throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
                    }
                    
                    // Clear the mailer for good measure
                    $mail->smtpClose();
                    
                } catch (Exception $e) {
                    error_log("Mail error for transaction {$no_transaksi}: " . $e->getMessage());
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