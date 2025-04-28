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

        // Assuming plain text PIN for demo; use password_verify() if hashed
        if ($user['pin'] !== $pin) {
            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah', 'email_status' => 'none']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid', 'email_status' => 'none']);
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
                throw new Exception('Akun ini sedang dibekukan dan tidak dapat melakukan penarikan.Hubungi Admin');
            }

            $rekening_id = $row['id'];
            $saldo_sekarang = $row['saldo'];
            $nama_nasabah = $row['nama'];
            $user_id = $row['user_id'];
            $email = $row['email'];
            $jurusan = $row['jurusan'] ?? '-';
            $kelas = $row['kelas'] ?? '-';

            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk melakukan penarikan.');
            }

            $today = date('Y-m-d');
            $petugas_id = $_SESSION['user_id'];

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

            $query_saldo_tambahan = "SELECT COALESCE(SUM(jumlah), 0) as saldo_tambahan 
                                    FROM saldo_transfers 
                                    WHERE petugas_id = ? 
                                    AND DATE(tanggal) = ?";
            $stmt_saldo_tambahan = $conn->prepare($query_saldo_tambahan);
            $stmt_saldo_tambahan->bind_param('is', $petugas_id, $today);
            $stmt_saldo_tambahan->execute();
            $saldo_tambahan = floatval($stmt_saldo_tambahan->get_result()->fetch_assoc()['saldo_tambahan']);

            $saldo_bersih = ($total_setoran - $total_penarikan) + $saldo_tambahan;

            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas petugas hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '.');
            }

            $no_transaksi = 'TRXP' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status, created_at) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved', NOW())";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }
            $transaksi_id = $conn->insert_id;

            $saldo_baru = $saldo_sekarang - $jumlah;
            $query_update_saldo = "UPDATE rekening SET saldo = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update_saldo = $conn->prepare($query_update_saldo);
            $stmt_update_saldo->bind_param('di', $saldo_baru, $rekening_id);
            if (!$stmt_update_saldo->execute()) {
                throw new Exception('Gagal memperbarui saldo.');
            }

            $query_mutasi = "INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir, created_at) 
                            VALUES (?, ?, ?, ?, NOW())";
            $stmt_mutasi = $conn->prepare($query_mutasi);
            $stmt_mutasi->bind_param('iidd', $transaksi_id, $rekening_id, $jumlah, $saldo_baru);
            if (!$stmt_mutasi->execute()) {
                throw new Exception('Gagal mencatat mutasi.');
            }

            $message = "Transaksi penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " telah berhasil diproses.";
            $query_notifikasi = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }

            $email_status = 'success';
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $subject = "Bukti Transaksi Penarikan Tunai - SCHOBANK SYSTEM";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset=\"UTF-8\">
                    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                    <title>Bukti Transaksi Penarikan Tunai - SCHOBANK SYSTEM</title>
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
                        <h2>Bukti Transaksi Penarikan Tunai</h2>
                        <p>Yth. {$nama_nasabah},</p>
                        <p>Kami informasikan bahwa transaksi penarikan tunai dari rekening Anda telah berhasil diproses. Berikut rincian transaksi:</p>
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
                        <p>Terima kasih telah menggunakan layanan SCHOBANK SYSTEM. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi petugas kami.</p>
                        <div class=\"security-notice\">
                            <strong>Perhatian Keamanan:</strong> Jangan bagikan informasi rekening, kata sandi, atau detail transaksi kepada pihak lain. Petugas SCHOBANK tidak akan meminta informasi tersebut melalui email atau telepon.
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
                    $mail->addReplyTo('no-reply@schobank.com', 'No Reply');

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message;
                    $mail->AltBody = strip_tags($message);

                    $mail->send();
                    $email_status = 'success';
                } catch (Exception $e) {
                    $email_status = 'failed';
                }
            }

            $conn->commit();
            echo json_encode([
                'status' => 'success',
                'message' => 'Penarikan tunai berhasil diproses.',
                'email_status' => $email_status
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