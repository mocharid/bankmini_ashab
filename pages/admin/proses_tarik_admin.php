<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Validasi sesi admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Harus login sebagai admin.', 'email_status' => 'none']);
    exit();
}

// Validasi metode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan.', 'email_status' => 'none']);
    exit();
}

$action = $_POST['action'] ?? '';
$no_rekening = trim($_POST['no_rekening'] ?? '');
$jumlah = isset($_POST['jumlah']) ? floatval(str_replace(',', '', $_POST['jumlah'])) : 0;
$user_id = intval($_POST['user_id'] ?? 0);
$pin = trim($_POST['pin'] ?? '');

try {
    // Mulai transaksi database
    $conn->begin_transaction();

    switch ($action) {
        case 'cek_rekening':
            // Validasi format nomor rekening
            if (empty($no_rekening) || strlen($no_rekening) !== 9 || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
                throw new Exception('Nomor rekening tidak valid. Harus REK + 6 digit angka.');
            }

            // Query untuk memeriksa rekening
            $query = "
                SELECT r.no_rekening, u.nama, u.email, u.has_pin, u.pin, u.id as user_id, r.id as rekening_id, r.saldo, 
                       j.nama_jurusan AS jurusan, k.nama_kelas AS kelas, u.is_frozen
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

            if ($result->num_rows === 0) {
                throw new Exception('Nomor rekening tidak ditemukan atau bukan milik siswa.');
            }

            $row = $result->fetch_assoc();
            if ($row['is_frozen'] == 1) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
            }

            // Update status has_pin jika diperlukan
            if (!empty($row['pin']) && !$row['has_pin']) {
                $update_query = "UPDATE users SET has_pin = 1 WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param('i', $row['user_id']);
                $update_stmt->execute();
                $row['has_pin'] = 1;
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
                'has_pin' => (bool)$row['has_pin'],
                'saldo' => floatval($row['saldo']),
                'email_status' => 'none'
            ]);
            break;

        case 'verify_pin':
            // Validasi PIN
            if (empty($pin) || strlen($pin) !== 6 || !preg_match('/^[0-9]{6}$/', $pin)) {
                throw new Exception('PIN harus 6 digit angka.');
            }
            if ($user_id <= 0) {
                throw new Exception('ID User tidak valid.');
            }

            // Query untuk memeriksa PIN
            $query = "SELECT pin, has_pin, is_frozen, failed_pin_attempts, pin_block_until FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                throw new Exception('Gagal menyiapkan kueri PIN: ' . $conn->error);
            }
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                throw new Exception('User tidak ditemukan.');
            }

            $user = $result->fetch_assoc();
            if ($user['is_frozen'] == 1) {
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan transaksi.');
            }

            // Cek apakah akun diblokir
            if ($user['pin_block_until'] && strtotime($user['pin_block_until']) > time()) {
                throw new Exception('Akun diblokir sementara karena terlalu banyak percobaan PIN salah. Coba lagi setelah ' . $user['pin_block_until'] . '.');
            }

            if (!$user['has_pin'] || empty($user['pin'])) {
                throw new Exception('User belum mengatur PIN.');
            }

            if ($user['pin'] !== $pin) {
                // Tambah jumlah percobaan PIN gagal
                $query_update_attempts = "UPDATE users SET failed_pin_attempts = failed_pin_attempts + 1 WHERE id = ?";
                $stmt_update_attempts = $conn->prepare($query_update_attempts);
                $stmt_update_attempts->bind_param('i', $user_id);
                $stmt_update_attempts->execute();

                // Cek apakah akun harus diblokir
                $failed_attempts = $user['failed_pin_attempts'] + 1;
                if ($failed_attempts >= 3) {
                    $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $query_block = "UPDATE users SET pin_block_until = ?, failed_pin_attempts = 0 WHERE id = ?";
                    $stmt_block = $conn->prepare($query_block);
                    $stmt_block->bind_param('si', $block_until, $user_id);
                    $stmt_block->execute();
                    throw new Exception('Akun diblokir sementara karena terlalu banyak percobaan PIN salah. Coba lagi setelah 1 jam.');
                }

                throw new Exception('PIN yang Anda masukkan salah.');
            }

            // Reset failed attempts pada PIN yang benar
            $query_reset_attempts = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $stmt_reset_attempts = $conn->prepare($query_reset_attempts);
            $stmt_reset_attempts->bind_param('i', $user_id);
            $stmt_reset_attempts->execute();

            echo json_encode(['status' => 'success', 'message' => 'PIN valid.', 'email_status' => 'none']);
            break;

        case 'tarik_saldo':
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

            // Validasi rekening siswa
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

            if ($result->num_rows === 0) {
                throw new Exception('Nomor rekening tidak ditemukan atau bukan milik siswa.');
            }

            $row = $result->fetch_assoc();
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

            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo siswa tidak mencukupi. Saldo tersedia: Rp ' . number_format($saldo_sekarang, 0, ',', '.'));
            }

            // Validasi saldo sistem
            $query_saldo = "
                SELECT (
                    COALESCE((
                        SELECT SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                               SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END)
                        FROM transaksi
                        WHERE status = 'approved' AND DATE(created_at) < CURDATE()
                    ), 0) -
                    COALESCE((
                        SELECT SUM(jumlah)
                        FROM transaksi
                        WHERE jenis_transaksi = 'tarik' AND status = 'approved' 
                              AND DATE(created_at) = CURDATE() AND petugas_id IS NULL
                    ), 0)
                ) as saldo_bersih";
            $stmt_saldo = $conn->prepare($query_saldo);
            if (!$stmt_saldo) {
                throw new Exception('Gagal menyiapkan kueri saldo: ' . $conn->error);
            }
            $stmt_saldo->execute();
            $result_saldo = $stmt_saldo->get_result();
            $saldo_bersih = floatval($result_saldo->fetch_assoc()['saldo_bersih']);

            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo sistem tidak mencukupi. Saldo tersedia: Rp ' . number_format($saldo_bersih, 0, ',', '.'));
            }

            // Generate nomor transaksi unik
            $no_transaksi = '';
            $attempts = 0;
            $max_attempts = 10;
            do {
                $no_transaksi = 'TRXA' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                $query_check_transaksi = "SELECT COUNT(*) as count FROM transaksi WHERE no_transaksi = ?";
                $stmt_check_transaksi = $conn->prepare($query_check_transaksi);
                $stmt_check_transaksi->bind_param('s', $no_transaksi);
                $stmt_check_transaksi->execute();
                $transaksi_count = $stmt_check_transaksi->get_result()->fetch_assoc()['count'];
                $attempts++;
            } while ($transaksi_count > 0 && $attempts < $max_attempts);

            if ($transaksi_count > 0) {
                throw new Exception('Gagal menghasilkan nomor transaksi unik setelah beberapa percobaan.');
            }

            // Catat transaksi
            $petugas_id = null; // Penarikan oleh admin
            $jumlah = round(floatval($jumlah), 2);
            $query_transaksi = "
                INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())
            ";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Gagal menyiapkan statement transaksi: ' . $conn->error);
            }
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi: ' . $stmt_transaksi->error);
            }
            $transaksi_id = $conn->insert_id;

            // Kurangi saldo siswa
            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            if (!$stmt_update_saldo) {
                throw new Exception('Gagal menyiapkan update saldo: ' . $conn->error);
            }
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo siswa: ' . $stmt_update_saldo->error);
            }

            // Catat mutasi
            $query_mutasi = "
                INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            if (!$stmt_mutasi) {
                throw new Exception('Gagal menyiapkan mutasi: ' . $conn->error);
            }
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi: ' . $stmt_mutasi->error);
            }

            // Catat notifikasi
            $message = "Penarikan saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil diproses oleh admin.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            if ($stmt_notifikasi) {
                $stmt_notifikasi->bind_param('is', $user_id, $message);
                if (!$stmt_notifikasi->execute()) {
                    error_log("Gagal menyimpan notifikasi untuk user_id: $user_id, no_transaksi: $no_transaksi, error: " . $stmt_notifikasi->error);
                }
            } else {
                error_log("Gagal menyiapkan notifikasi: " . $conn->error);
            }

            // Kirim email notifikasi
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'mocharid.ip@gmail.com'; // Kredensial dari kode penyetoran
                    $mail->Password = 'spjs plkg ktuu lcxh'; // Kata sandi aplikasi dari kode penyetoran
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;
                    $mail->CharSet = 'UTF-8';

                    $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
                    $mail->addAddress($email, $nama_nasabah);
                    $mail->isHTML(true);
                    $mail->Subject = 'Bukti Transaksi Penarikan Saldo - SCHOBANK SYSTEM';
                    $mail->Body = "
                        <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f0f5ff; border-radius: 12px; overflow: hidden;'>
                            <div style='background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); padding: 30px 20px; text-align: center; color: #ffffff;'>
                                <h2 style='font-size: 24px; font-weight: 600; margin: 0;'>SCHOBANK SYSTEM</h2>
                                <p style='font-size: 16px; opacity: 0.8; margin: 5px 0 0;'>Bukti Transaksi Penarikan Saldo</p>
                            </div>
                            <div style='background: #ffffff; padding: 30px;'>
                                <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama_nasabah}</h3>
                                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami informasikan bahwa transaksi penarikan saldo dari rekening Anda telah berhasil diproses oleh admin. Berikut rincian transaksi:</p>
                                <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                                    <table style='width: 100%; font-size: 15px; color: #333333;'>
                                        <tr>
                                            <td style='padding: 8px 0; font-weight: 500;'>Nomor Transaksi</td>
                                            <td style='padding: 8px 0; text-align: right;'>{$no_transaksi}</td>
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
                                            <td style='padding: 8px 0; text-align: right;'>Rp " . number_format($jumlah, 0, ',', '.') . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; font-weight: 500;'>Saldo Akhir</td>
                                            <td style='padding: 8px 0; text-align: right;'>Rp " . number_format($saldo_baru, 0, ',', '.') . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 8px 0; font-weight: 500;'>Tanggal Transaksi</td>
                                            <td style='padding: 8px 0; text-align: right;'>" . date('d M Y H:i:s') . " WIB</td>
                                        </tr>
                                    </table>
                                </div>
                                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Terima kasih telah menggunakan layanan SCHOBANK SYSTEM. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi admin kami.</p>
                                <p style='color: #e74c3c; font-size: 14px; font-weight: 500; margin-bottom: 20px; display: flex; align-items: center; gap: 8px;'>
                                    <span style='font-size: 18px;'>🔒</span> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain.
                                </p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='mailto:support@schobank.xai' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Hubungi Kami</a>
                                </div>
                            </div>
                            <div style='background: #f0f5ff; padding: 15px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. All rights reserved.</p>
                                <p style='margin: 5px 0 0;'>Email ini otomatis. Mohon tidak membalas.</p>
                            </div>
                        </div>";
                    $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mail->Body));

                    $mail->send();
                    $email_status = 'sent';
                    error_log("Email berhasil dikirim untuk no_transaksi: $no_transaksi, email: $email");
                } catch (Exception $e) {
                    $email_status = 'failed';
                    error_log("Gagal mengirim email untuk no_transaksi: $no_transaksi, email: $email, error: " . $mail->ErrorInfo);
                }
            } else {
                error_log("Email tidak dikirim untuk no_transaksi: $no_transaksi, alasan: email kosong atau tidak valid ($email)");
            }

            // Commit transaksi
            $conn->commit();

            $response_message = "Penarikan sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil.";
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
            break;

        default:
            throw new Exception('Aksi tidak valid.');
    }
} catch (Exception $e) {
    // Rollback transaksi jika terjadi error
    $conn->rollback();

    // Log error untuk debugging
    error_log("Error di proses_tarik_admin.php: " . $e->getMessage() . " | Action: $action | No Rekening: $no_rekening | User ID: $user_id");

    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'email_status' => 'none'
    ]);
} finally {
    // Tutup semua statement
    if (isset($stmt)) $stmt->close();
    if (isset($stmt_saldo)) $stmt_saldo->close();
    if (isset($stmt_transaksi)) $stmt_transaksi->close();
    if (isset($stmt_update_saldo)) $stmt_update_saldo->close();
    if (isset($stmt_mutasi)) $stmt_mutasi->close();
    if (isset($stmt_notifikasi)) $stmt_notifikasi->close();
    if (isset($stmt_check_transaksi)) $stmt_check_transaksi->close();
    if (isset($stmt_update_attempts)) $stmt_update_attempts->close();
    if (isset($stmt_block)) $stmt_block->close();
    if (isset($stmt_reset_attempts)) $stmt_reset_attempts->close();
    $conn->close();
}
?>