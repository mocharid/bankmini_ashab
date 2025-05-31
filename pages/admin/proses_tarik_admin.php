<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Validasi sesi admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = trim($_POST['pin'] ?? '');

    if ($action === 'cek_rekening') {
        // Validasi format nomor rekening
        if (empty($no_rekening) || strlen($no_rekening) !== 9 || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak valid. Harus REK + 6 digit angka.', 'email_status' => 'none']);
            exit();
        }

        $query = "
            SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, 
                   j.nama_jurusan AS jurusan, k.nama_kelas AS kelas, u.is_frozen
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
            if ($row['is_frozen'] == 1) {
                echo json_encode(['status' => 'error', 'message' => 'Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.', 'email_status' => 'none']);
                exit();
            }
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

        $query = "SELECT saldo, is_frozen FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan.', 'email_status' => 'none']);
            exit();
        }

        $row = $result->fetch_assoc();
        if ($row['is_frozen'] == 1) {
            echo json_encode(['status' => 'error', 'message' => 'Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.', 'email_status' => 'none']);
            exit();
        }
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

        $query = "SELECT pin, has_pin, is_frozen, failed_pin_attempts, pin_block_until FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan', 'email_status' => 'none']);
            exit();
        }

        $user = $result->fetch_assoc();
        if ($user['is_frozen'] == 1) {
            echo json_encode(['status' => 'error', 'message' => 'Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.', 'email_status' => 'none']);
            exit();
        }

        if ($user['pin_block_until'] && strtotime($user['pin_block_until']) > time()) {
            echo json_encode(['status' => 'error', 'message' => 'Akun diblokir sementara karena terlalu banyak percobaan PIN salah. Coba lagi setelah ' . $user['pin_block_until'] . '.', 'email_status' => 'none']);
            exit();
        }

        if (!empty($user['pin']) && !$user['has_pin']) {
            $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param('i', $user_id);
            $update_stmt->execute();
            $update_stmt->close();
            $user['has_pin'] = 1;
        }

        if (!$user['has_pin'] || empty($user['pin'])) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN', 'email_status' => 'none']);
            exit();
        }

        if ($user['pin'] !== $pin) {
            $query_update_attempts = "UPDATE users SET failed_pin_attempts = failed_pin_attempts + 1 WHERE id = ?";
            $stmt_update_attempts = $conn->prepare($query_update_attempts);
            $stmt_update_attempts->bind_param('i', $user_id);
            $stmt_update_attempts->execute();
            $stmt_update_attempts->close();

            $failed_attempts = $user['failed_pin_attempts'] + 1;
            if ($failed_attempts >= 3) {
                $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $query_block = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                $stmt_block = $conn->prepare($query_block);
                $stmt_block->bind_param('si', $block_until, $user_id);
                $stmt_block->execute();
                $stmt_block->close();
                echo json_encode(['status' => 'error', 'message' => 'Akun diblokir sementara karena terlalu banyak percobaan PIN salah. Coba lagi setelah 1 jam.', 'email_status' => 'none']);
                exit();
            }

            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah', 'email_status' => 'none']);
            exit();
        }

        $query_reset_attempts = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
        $stmt_reset_attempts = $conn->prepare($query_reset_attempts);
        $stmt_reset_attempts->bind_param('i', $user_id);
        $stmt_reset_attempts->execute();
        $stmt_reset_attempts->close();

        echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
        $stmt->close();
    } elseif ($action === 'tarik_saldo') {
        try {
            $conn->begin_transaction();

            // Validasi input
            if (empty($no_rekening) || strlen($no_rekening) !== 9 || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            if ($jumlah <= 0 || $jumlah < 10000) {
                throw new Exception('Jumlah penarikan minimal Rp 10.000.');
            }
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.');
            }

            // Periksa status is_frozen dan data rekening
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

            // Validasi saldo sistem
            $today = date('Y-m-d');
            $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total_setoran 
                             FROM transaksi 
                             WHERE jenis_transaksi = 'setor' 
                             AND status = 'approved' 
                             AND DATE(created_at) = ?";
            $stmt_setoran = $conn->prepare($query_setoran);
            $stmt_setoran->bind_param('s', $today);
            $stmt_setoran->execute();
            $total_setoran = floatval($stmt_setoran->get_result()->fetch_assoc()['total_setoran']);
            $stmt_setoran->close();

            $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total_penarikan 
                               FROM transaksi 
                               WHERE jenis_transaksi = 'tarik' 
                               AND status = 'approved' 
                               AND DATE(created_at) = ? 
                               AND petugas_id IS NULL";
            $stmt_penarikan = $conn->prepare($query_penarikan);
            $stmt_penarikan->bind_param('s', $today);
            $stmt_penarikan->execute();
            $total_penarikan = floatval($stmt_penarikan->get_result()->fetch_assoc()['total_penarikan']);
            $stmt_penarikan->close();

            $saldo_bersih = $total_setoran - $total_penarikan;
            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas sistem hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            // Generate unique transaction number
            $no_transaksi = 'TRXA' . date('Ymd') . sprintf('%06d', mt_rand(100000, 999999));
            $query_check_transaksi = "SELECT COUNT(*) as count FROM transaksi WHERE no_transaksi = ?";
            $stmt_check_transaksi = $conn->prepare($query_check_transaksi);
            $stmt_check_transaksi->bind_param('s', $no_transaksi);
            $stmt_check_transaksi->execute();
            $transaksi_count = $stmt_check_transaksi->get_result()->fetch_assoc()['count'];
            $stmt_check_transaksi->close();

            if ($transaksi_count > 0) {
                throw new Exception('Nomor transaksi sudah ada, silakan coba lagi.');
            }

            // Catat transaksi
            $petugas_id = null; // Admin transaction
            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            // Update saldo
            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo.');
            }
            $stmt_update_saldo->close();

            // Catat mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }
            $stmt_mutasi->close();

            // Catat notifikasi
            $message = "Transaksi penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh admin.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            if (!$stmt_notifikasi->execute()) {
                error_log("Gagal menyimpan notifikasi untuk user_id: $user_id, no_transaksi: $no_transaksi");
            }
            $stmt_notifikasi->close();

            // Kirim email
            $email_status = 'success';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Create unique subject with transaction ID
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
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami informasikan bahwa transaksi penarikan tunai dari rekening Anda telah berhasil diproses oleh admin. Berikut rincian transaksi:</p>
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
                    $mail = new PHPMailer(true);
                    $mail->clearAllRecipients();
                    $mail->clearAttachments();
                    $mail->clearReplyTos();

                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'schobanksystem@gmail.com';
                    $mail->Password = 'dgry fzmc mfrd hzzq';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('schobanksystem@gmail.com', 'SCHOBANK SYSTEM');
                    $mail->addAddress($email, $nama_nasabah);
                    $mail->addReplyTo('no-reply@schobank.com', 'No Reply');

                    $unique_id = uniqid('schobank_', true) . '@schobank.com';
                    $mail->MessageID = '<' . $unique_id . '>';
                    $mail->addCustomHeader('X-Transaction-ID', $no_transaksi);
                    $mail->addCustomHeader('X-Mailer', 'SCHOBANK-System-v1.0');
                    $mail->addCustomHeader('X-Priority', '1');
                    $mail->addCustomHeader('Importance', 'High');

                    $banner_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/emailbanner.jpeg';
                    if (file_exists($banner_path)) {
                        $mail->addEmbeddedImage($banner_path, 'emailbanner', 'emailbanner.jpeg');
                    }

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message_email;
                    $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message_email));

                    if ($mail->send()) {
                        $email_status = 'success';
                    } else {
                        throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
                    }

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
            error_log("Error in tarik1.php: " . $e->getMessage() . " | Action: $action | No Rekening: $no_rekening | User ID: $user_id");
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