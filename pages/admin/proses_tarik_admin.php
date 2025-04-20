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
            WHERE r.no_rekening = ? AND u.role = 'siswa'
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan atau bukan milik siswa.', 'email_status' => 'none']);
        } else {
            $row = $result->fetch_assoc();
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
        }
    } elseif ($action === 'check_balance') {
        if (empty($no_rekening)) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak valid.', 'email_status' => 'none']);
            exit();
        }
        if ($jumlah < 10000) {
            echo json_encode(['status' => 'error', 'message' => 'Jumlah penarikan minimal Rp 10.000.', 'email_status' => 'none']);
            exit();
        }

        $query = "SELECT saldo FROM rekening WHERE no_rekening = ? AND user_id IN (SELECT id FROM users WHERE role = 'siswa')";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Nomor rekening tidak ditemukan atau bukan milik siswa.', 'email_status' => 'none']);
            exit();
        }

        $row = $result->fetch_assoc();
        $saldo = floatval($row['saldo']);

        if ($saldo < $jumlah) {
            echo json_encode(['status' => 'error', 'message' => 'Saldo tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'Saldo cukup untuk penarikan.', 'email_status' => 'none']);
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
            $user['has_pin'] = 1;
        }

        if (!$user['has_pin'] && empty($user['pin'])) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN', 'email_status' => 'none']);
            exit();
        }

        // Assuming plain text PIN for compatibility with original code
        if ($user['pin'] !== $pin) {
            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
    } elseif ($action === 'tarik_saldo') {
        try {
            $conn->begin_transaction();

            // 1. Validasi input
            if (empty($no_rekening)) {
                throw new Exception('Nomor rekening tidak valid.');
            }
            if ($jumlah <= 0 || $jumlah < 10000) {
                throw new Exception('Jumlah penarikan minimal Rp 10.000 dan harus lebih dari 0.');
            }
            if ($jumlah > 99999999.99) {
                throw new Exception('Jumlah penarikan melebihi batas maksimum Rp 99.999.999,99.');
            }

            // 2. Validasi rekening siswa
            $query = "
                SELECT r.id, r.user_id, r.saldo, u.nama, u.email, j.nama_jurusan AS jurusan, k.nama_kelas AS kelas
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
                throw new Exception('Saldo siswa tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '. Saldo tersedia: Rp ' . number_format($saldo_sekarang, 0, ',', '.'));
            }

            // 3. Validasi saldo sistem (disesuaikan dengan petugas_id IS NULL)
            $admin_id = $_SESSION['user_id'];
            $query_saldo = "SELECT (
                                COALESCE((
                                    SELECT SUM(net_setoran)
                                    FROM (
                                        SELECT DATE(created_at) as tanggal,
                                               SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                                               SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                                        FROM transaksi
                                        WHERE status = 'approved' AND DATE(created_at) < CURDATE()
                                        GROUP BY DATE(created_at)
                                    ) as daily_net
                                ), 0) -
                                COALESCE((
                                    SELECT SUM(jumlah)
                                    FROM transaksi
                                    WHERE jenis_transaksi = 'tarik' AND status = 'approved' 
                                          AND DATE(created_at) = CURDATE() AND petugas_id IS NULL
                                ), 0) -
                                COALESCE((
                                    SELECT SUM(jumlah)
                                    FROM saldo_transfers
                                    WHERE admin_id = ?
                                ), 0)
                            ) as saldo_bersih";
            $stmt_saldo = $conn->prepare($query_saldo);
            if (!$stmt_saldo) {
                throw new Exception('Gagal menyiapkan kueri saldo: ' . $conn->error);
            }
            $stmt_saldo->bind_param("i", $admin_id);
            $stmt_saldo->execute();
            $result_saldo = $stmt_saldo->get_result();
            $saldo_bersih = floatval($result_saldo->fetch_assoc()['saldo_bersih']);

            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo sistem tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '. Saldo tersedia: Rp ' . number_format($saldo_bersih, 0, ',', '.'));
            }

            // 4. Catat transaksi dengan petugas_id = NULL
            $no_transaksi = 'TRXA' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            $petugas_id = null; // Gunakan NULL untuk penarikan admin
            $jumlah = round(floatval($jumlah), 2); // Pastikan jumlah sesuai DECIMAL(10,2)

            // Cek duplikasi no_transaksi
            $query_check_transaksi = "SELECT COUNT(*) as count FROM transaksi WHERE no_transaksi = ?";
            $stmt_check_transaksi = $conn->prepare($query_check_transaksi);
            $stmt_check_transaksi->bind_param('s', $no_transaksi);
            $stmt_check_transaksi->execute();
            $transaksi_count = $stmt_check_transaksi->get_result()->fetch_assoc()['count'];
            if ($transaksi_count > 0) {
                throw new Exception('Nomor transaksi sudah ada, silakan coba lagi.');
            }

            // Log nilai sebelum insert
            error_log("Sebelum insert transaksi: no_transaksi=$no_transaksi (" . gettype($no_transaksi) . "), rekening_id=$rekening_id (" . gettype($rekening_id) . "), jumlah=$jumlah (" . gettype($jumlah) . "), petugas_id=" . ($petugas_id === null ? 'NULL' : $petugas_id) . " (" . gettype($petugas_id) . ")");

            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            if (!$stmt_transaksi) {
                throw new Exception('Gagal menyiapkan statement transaksi: ' . $conn->error);
            }
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi: ' . $stmt_transaksi->error);
            }
            $transaksi_id = $conn->insert_id;

            // 5. Kurangi saldo siswa
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

            // 6. Catat mutasi
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

            // 7. Catat notifikasi
            $message = "Transaksi penarikan saldo sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses oleh admin.";
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

            // 8. Kirim email
            $email_sent = false;
            $email_status = 'none';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Transaksi Penarikan Saldo - SCHOBANK SYSTEM";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset=\"UTF-8\">
                    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                    <title>Bukti Transaksi Penarikan Saldo - SCHOBANK SYSTEM</title>
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
                        <h2>Bukti Transaksi Penarikan Saldo</h2>
                        <p>Yth. {$nama_nasabah},</p>
                        <p>Kami informasikan bahwa transaksi penarikan saldo dari rekening Anda telah berhasil diproses oleh admin. Berikut rincian transaksi:</p>
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
                                <div class=\"label\">Jumlah Penarikan</div>
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
            error_log("Penarikan berhasil: no_transaksi=$no_transaksi, jumlah=$jumlah, no_rekening=$no_rekening, petugas_id=NULL, saldo_bersih=$saldo_bersih, email_status=$email_status");

            $response_message = "Transaksi penarikan saldo untuk {$nama_nasabah} telah berhasil diproses.";
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
            error_log("Penarikan gagal: no_rekening=$no_rekening, jumlah=$jumlah, error: $error_message");
            echo json_encode(['status' => 'error', 'message' => $error_message, 'email_status' => 'none']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Aksi tidak valid.', 'email_status' => 'none']);
    }
}
$conn->close();
?>