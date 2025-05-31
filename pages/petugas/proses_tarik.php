<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'petugas') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

// Validasi keberadaan petugas1_nama dan petugas2_nama di jadwal hari ini
$query_schedule = "SELECT petugas1_nama, petugas2_nama FROM petugas_tugas WHERE tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();

if (!$schedule || empty($schedule['petugas1_nama']) || empty($schedule['petugas2_nama'])) {
    echo json_encode(['status' => 'error', 'message' => 'Transaksi tidak dapat dilakukan karena tidak ada petugas.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = floatval($_POST['jumlah'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';

    if ($action === 'cek_rekening') {
        $query = "
            SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, 
                   j.nama_jurusan AS jurusan, k.nama_kelas AS kelas
            FROM rekening r 
            JOIN users u ON r.user_id = u.id 
            LEFT JOIN jurusan j ON u.jurusan_id = j.id
            LEFT JOIN kelas k ON u.kelas_id = k.id
            WHERE r.no_rekening = ?
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
        } else {
            $row = $result->fetch_assoc();
            if (!empty($row['pin']) && !$row['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param('i', $row['user_id']);
                $update_stmt->execute();
                $update_stmt->close();
                $row['has_pin'] = 1;
            }
            echo json_encode([
                'status' => 'success',
                'no_rekening' => $row['no_rekening'],
                'nama' => $row['nama'],
                'jurusan' => $row['jurusan'] ?: '-',
                'kelas' => $row['kelas'] ?: '-',
                'email' => $row['email'] ?: '',
                'user_id' => $row['user_id'],
                'rekening_id' => $row['rekening_id'],
                'has_pin' => (bool)$row['has_pin'],
                'saldo' => floatval($row['saldo']),
                'email_status' => 'none'
            ]);
        }
        $stmt->close();
    } elseif ($action === 'check_balance') {
        if (empty($no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak valid.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah < 10000) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan minimal Rp 10.000.', 'email_status' => 'none']);
            exit();
        }

        $query = "SELECT saldo FROM rekening WHERE no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
            exit();
        }

        $row = $result->fetch_assoc();
        $saldo = floatval($row['saldo']);

        if ($saldo < $jumlah) {
            echo json_encode(['status' => 'error', 'message' => 'Saldo tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'Saldo cukup untuk penarikan.', 'email_status' => 'none']);
        $stmt->close();
    } elseif ($action === 'verify_pin') {
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'PIN harus diisi', 'email_status' => 'none']);
            exit();
        }

        if (empty($user_id) || $user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID User tidak valid', 'email_status' => 'none']);
            exit();
        }

        $query = "SELECT pin, has_pin FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan', 'email_status' => 'none']);
            exit();
        }

        $user = $result->fetch_assoc();

        if (!empty($user['pin']) && !$user['has_pin']) {
            $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $user_id);
            $update_stmt->execute();
            $update_stmt->close();
            $user['has_pin'] = 1;
        }

        if (!$user['has_pin'] && empty($user['pin'])) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN', 'email_status' => 'none']);
            exit();
        }

        // Assuming plain text PIN for demo; use password_verify() if hashed
        if ($user['pin'] !== $pin) {
            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
        $stmt->close();
    } elseif ($action === 'tarik_tunai') {
        try {
            $conn->begin_transaction();

            // Periksa status is_frozen
            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, u.is_frozen, 
                       j.nama_jurusan AS jurusan, k.nama_kelas AS kelas
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                WHERE r.no_rekening = ?
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows == 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan penarikan, Hubungi Admin!');
            }

            $rekening_id = $row['id'];
            $saldo_sekarang = $row['saldo'];
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id'];
            $email = $row['email'] ?? '';
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';
            $stmt->close();

            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk melakukan penarikan.');
            }

            $today = date('Y-m-d');
            $petugas_id = $_SESSION['user_id'];

            // Hitung total setoran dan penarikan petugas hari ini
            $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total_setoran 
                             FROM transaksi 
                             WHERE petugas_id = ? 
                             AND jenis_transaksi = 'setor' 
                             AND status = 'approved' 
                             AND DATE(created_at) = ?";
            $stmt_setoran = $conn->prepare($query_setoran);
            $stmt_setoran->bind_param('is', $petugas_id, $today);
            $stmt_setoran->execute();
            $total_setoran = floatval($stmt_setoran->get_result()->fetch_assoc()['total_setoran']);
            $stmt_setoran->close();

            $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total_penarikan 
                               FROM transaksi 
                               WHERE petugas_id = ? 
                               AND jenis_transaksi = 'tarik' 
                               AND status = 'approved' 
                               AND DATE(created_at) = ?";
            $stmt_penarikan = $conn->prepare($query_penarikan);
            $stmt_penarikan->bind_param('is', $petugas_id, $today);
            $stmt_penarikan->execute();
            $total_penarikan = floatval($stmt_penarikan->get_result()->fetch_assoc()['total_penarikan']);
            $stmt_penarikan->close();

            // Hitung saldo bersih petugas (setoran - penarikan)
            $saldo_bersih = $total_setoran - $total_penarikan;

            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas petugas hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            // Generate unique transaction number with more entropy
            $no_transaksi = 'TRXP' . date('Ymd') . sprintf('%06d', mt_rand(100000, 999999));

            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo.');
            }
            $stmt_update_saldo->close();

            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }
            $stmt_mutasi->close();

            $message = "Transaksi penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh petugas.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }
            $stmt_notifikasi->close();

            $email_status = 'success';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Create unique subject with transaction ID to prevent email threading
                $subject = "[{$no_transaksi}] Bukti Penarikan Tunai - SCHOBANK " . date('Y-m-d H:i:s');
                
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

                $message_email = "
                <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f0f5ff; border-radius: 12px; overflow: hidden;'>
                    <div style='background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px 20px; text-align: center; color: #ffffff;'>
                        <h2 style='font-size: 24px; font-weight: 600; margin: 0;'>SCHOBANK SYSTEM</h2>
                        <p style='font-size: 16px; opacity: 0.8; margin: 5px 0 0;'>Bukti Transaksi Penarikan Tunai</p>
                    </div>
                    <div style='background: #ffffff; padding: 30px;'>
                        <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama_nasabah}</h3>
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami informasikan bahwa transaksi penarikan tunai dari rekening Anda telah berhasil diproses oleh petugas. Berikut rincian transaksi:</p>
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
                                    <td style='padding: 8px 0; font-weight: 500;'>Jumlah Penarikan</td>
                                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #dc2626;'>Rp " . number_format($jumlah, 0, ',', '.') . "</td>
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
                        
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Terima kasih telah menggunakan layanan SCHOBANK SYSTEM. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi petugas kami.</p>
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
                    $mail->Body = $message_email;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message_email));

                    // Send email
                    if ($mail->send()) {
                        $email_status = 'success';
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

            $response_message = "Penarikan tunai berhasil diproses.";
            if ($email_status === 'failed') {
                $response_message = "Transaksi berhasil, tetapi gagal mengirim bukti transaksi ke email.";
            } elseif ($email_status === 'success') {
                $response_message = "Penarikan tunai berhasil diproses dan bukti transaksi telah dikirim ke email.";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_status' => $email_status,
                'transaction_id' => $no_transaksi,
                'saldo_baru' => number_format($saldo_baru, 0, ',', '.')
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'email_status' => 'none'
            ]);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.', 'email_status' => 'none']);
}
$conn->close();
?>