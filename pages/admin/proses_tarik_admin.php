<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Validasi sesi admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak.', 'email_status' => 'none']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed');
    echo json_encode(['status' => 'error', 'message' => 'Metode request tidak valid.', 'email_status' => 'none']);
    exit();
}

$action = $_POST['action'] ?? '';
$no_rekening = trim($_POST['no_rekening'] ?? '');
$jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;
$user_id = intval($_POST['user_id'] ?? 0);
$pin = trim($_POST['pin'] ?? '');

try {
    switch ($action) {
        case 'cek_rekening':
            if (empty($no_rekening) || strlen($no_rekening) !== 9 || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
                throw new Exception('Nomor rekening tidak valid. Harus REK + 6 digit angka.');
            }

            $query = "
                SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, 
                       j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas, u.is_frozen
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception('Nomor rekening tidak ditemukan atau bukan milik siswa.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
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
            $stmt->close();
            break;

        case 'check_balance':
            if (empty($no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            if ($jumlah < 10000) {
                throw new Exception('Jumlah penarikan minimal Rp 10.000.');
            }
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.');
            }

            $query = "SELECT saldo, is_frozen FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception('Nomor rekening tidak ditemukan.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
            }
            if (floatval($row['saldo']) < $jumlah) {
                throw new Exception('Saldo tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            echo json_encode(['status' => 'success', 'message' => 'Saldo cukup untuk penarikan.', 'email_status' => 'none']);
            $stmt->close();
            break;

        case 'verify_pin':
            if (empty($pin) || strlen($pin) !== 6) {
                throw new Exception('PIN harus 6 digit.');
            }
            if ($user_id <= 0) {
                throw new Exception('ID User tidak valid.');
            }

            $query = "SELECT pin, has_pin, is_frozen, failed_pin_attempts, pin_block_until FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception('User tidak ditemukan.');
            }

            $user = $result->fetch_assoc();
            if ($user['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
            }
            if ($user['pin_block_until'] && strtotime($user['pin_block_until']) > time()) {
                throw new Exception('Akun diblokir sementara hingga ' . $user['pin_block_until'] . '.');
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
                throw new Exception('User belum mengatur PIN.');
            }

            if ($user['pin'] !== $pin) {
                $failed_attempts = $user['failed_pin_attempts'] + 1;
                $update_query = "UPDATE users SET failed_pin_attempts = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param('ii', $failed_attempts, $user_id);
                $update_stmt->execute();
                $update_stmt->close();

                if ($failed_attempts >= 3) {
                    $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $block_query = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                    $block_stmt = $conn->prepare($block_query);
                    $block_stmt->bind_param('si', $block_until, $user_id);
                    $block_stmt->execute();
                    $block_stmt->close();
                    throw new Exception('Akun diblokir sementara selama 1 jam.');
                }

                throw new Exception('PIN salah.');
            }

            $reset_query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $reset_stmt = $conn->prepare($reset_query);
            $reset_stmt->bind_param('i', $user_id);
            $reset_stmt->execute();
            $reset_stmt->close();

            echo json_encode(['status' => 'success', 'message' => 'PIN valid.', 'email_status' => 'none']);
            $stmt->close();
            break;

        case 'tarik_saldo':
            $conn->begin_transaction();

            if (empty($no_rekening) || strlen($no_rekening) !== 9 || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            if ($jumlah < 10000) {
                throw new Exception('Jumlah penarikan minimal Rp 10.000.');
            }
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.');
            }

            // Lock rekening for consistent read
            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, u.is_frozen, 
                       j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas
                FROM rekening r 
                JOIN users u ON r.user_id = u.id 
                LEFT JOIN jurusan j ON u.jurusan_id = j.id
                LEFT JOIN kelas k ON u.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                WHERE r.no_rekening = ? AND u.role = 'siswa'
                FOR UPDATE
            ";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('s', $no_rekening);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception('Nomor rekening tidak ditemukan atau bukan milik siswa.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen']) {
                throw new Exception('Akun ini sedang dibekukan.');
            }
            if ($row['saldo'] < $jumlah) {
                throw new Exception('Saldo rekening tidak mencukupi.');
            }

            $rekening_id = $row['id'];
            $saldo_sekarang = floatval($row['saldo']);
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id'];
            $email = $row['email'] ?? '';
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';
            $stmt->close();

            // Validasi saldo kas sistem (mirip dashboard $total_saldo)
            $today = date('Y-m-d');
            $query_saldo_kas = "
                SELECT (
                    COALESCE((
                        SELECT SUM(net_setoran)
                        FROM (
                            SELECT SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                                   SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                            FROM transaksi
                            WHERE status = 'approved' AND DATE(created_at) < ?
                            GROUP BY DATE(created_at)
                        ) as daily_net
                    ), 0) +
                    COALESCE((
                        SELECT SUM(jumlah)
                        FROM transaksi
                        WHERE jenis_transaksi = 'setor' AND status = 'approved'
                              AND DATE(created_at) = ? AND petugas_id IS NULL
                    ), 0) -
                    COALESCE((
                        SELECT SUM(jumlah)
                        FROM transaksi
                        WHERE jenis_transaksi = 'tarik' AND status = 'approved'
                              AND DATE(created_at) = ? AND petugas_id IS NULL
                    ), 0)
                ) as saldo_kas
            ";
            $stmt_saldo_kas = $conn->prepare($query_saldo_kas);
            $stmt_saldo_kas->bind_param('sss', $today, $today, $today);
            $stmt_saldo_kas->execute();
            $saldo_kas = floatval($stmt_saldo_kas->get_result()->fetch_assoc()['saldo_kas']);
            $stmt_saldo_kas->close();

            if ($saldo_kas < $jumlah) {
                throw new Exception('Saldo kas sistem tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            // Generate unique transaction number
            $no_transaksi = 'TRXA' . date('Ymd') . sprintf('%06d', mt_rand(100000, 999999));
            $query_check_transaksi = "SELECT COUNT(*) as count FROM transaksi WHERE no_transaksi = ?";
            $stmt_check_transaksi = $conn->prepare($query_check_transaksi);
            $stmt_check_transaksi->bind_param('s', $no_transaksi);
            $stmt_check_transaksi->execute();
            if ($stmt_check_transaksi->get_result()->fetch_assoc()['count'] > 0) {
                throw new Exception('Nomor transaksi sudah ada, silakan coba lagi.');
            }
            $stmt_check_transaksi->close();

            // Catat transaksi
            $petugas_id = null; // Admin transaction
            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            $stmt_transaksi->execute();
            $transaksi_id = $conn->insert_id;
            $stmt_transaksi->close();

            // Update saldo
            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            $stmt_update_saldo->execute();
            $stmt_update_saldo->close();

            // Catat mutasi
            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            $stmt_mutasi->execute();
            $stmt_mutasi->close();

            // Catat notifikasi
            $message = "Penarikan tunai Rp " . number_format($jumlah, 0, ',', '.') . " berhasil diproses oleh admin.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            $stmt_notifikasi->execute();
            $stmt_notifikasi->close();

            // Kirim email
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "[{$no_transaksi}] Bukti Penarikan Tunai - SCHOBANK " . date('Y-m-d H:i:s');
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
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Transaksi penarikan tunai Anda telah berhasil diproses oleh admin. Berikut rincian:</p>
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
                        <div style='text-align: center; margin: 20px 0;'>
                            <img src='cid:emailbanner' alt='SCHOBANK Banner' style='max-width: 100%; height: auto; border-radius: 8px; border: 2px solid #e2e8f0;' />
                        </div>
                        <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Terima kasih atas kepercayaan Anda kepada SCHOBANK SYSTEM.</p>
                        <p style='color: #e74c3c; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;'>
                            <span style='font-size: 18px;'>🔒</span> Jangan bagikan informasi sensitif.
                        </p>
                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='mailto:schobanksystem@gmail.com' style='background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Hubungi Kami</a>
                        </div>
                    </div>
                    <div style='background: #f0f5ff; padding: 15px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
                        <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
                        <p style='margin: 5px 0 0;'>Email otomatis, jangan balas.</p>
                    </div>
                </div>";

                $mail = new PHPMailer(true);
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

                $mail->addEmbeddedImage($_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/emailbanner.jpeg', 'emailbanner', 'emailbanner.jpeg');
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $message_email;
                $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message_email));

                if ($mail->send()) {
                    $email_status = 'sent';
                } else {
                    $email_status = 'failed';
                    error_log("Mail error for transaction {$no_transaksi}: " . $mail->ErrorInfo);
                }
                $mail->smtpClose();
            }

            $conn->commit();

            $response_message = "Penarikan tunai berhasil.";
            if ($email_status === 'sent') {
                $response_message .= " Bukti transaksi telah dikirim ke {$email}.";
            } elseif ($email_status === 'failed') {
                $response_message .= " Gagal mengirim bukti transaksi ke email.";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_status' => $email_status,
                'transaction_id' => $no_transaksi,
                'saldo_baru' => number_format($saldo_baru, 0, ',', '.')
            ]);
            break;

        default:
            throw new Exception('Aksi tidak valid.');
    }
} catch (Exception $e) {
    if (isset($conn) && $conn->in_transaction()) {
        $conn->rollback();
    }
    error_log("Error in proses_tarik_admin.php: {$e->getMessage()} | Action: $action | No Rekening: $no_rekening | User ID: $user_id");
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'email_status' => 'none'
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>