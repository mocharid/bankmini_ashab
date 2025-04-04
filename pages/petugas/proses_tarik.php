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
    $user_id = intval($_POST['user_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';

    if ($action === 'cek_rekening') {
        // Cek rekening
        $query = "SELECT r.*, u.nama, u.email, u.has_pin, u.id as user_id, r.id as rekening_id 
                  FROM rekening r 
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
                'saldo' => floatval($row['saldo']),
                'email' => $row['email'],
                'user_id' => $row['user_id'],
                'rekening_id' => $row['rekening_id'],
                'has_pin' => (bool)$row['has_pin']
            ]);
        }
    } 
    elseif ($action === 'verify_pin') {
        // Verifikasi PIN nasabah
        if (empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'PIN harus diisi']);
            exit();
        }

        // Pastikan user_id valid
        if (empty($user_id) || $user_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID User tidak valid']);
            exit();
        }

        // Dapatkan PIN yang di-hash dari database
        $query = "SELECT pin, has_pin FROM users WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'User tidak ditemukan']);
            exit();
        }

        $user = $result->fetch_assoc();
        
        // Periksa apakah user sudah set PIN
        if (!$user['has_pin']) {
            echo json_encode(['status' => 'error', 'message' => 'User belum mengatur PIN']);
            exit();
        }
        
        // Verifikasi PIN (asumsi PIN disimpan sebagai plain text untuk demo)
        // Dalam produksi, gunakan password_verify() jika PIN di-hash
        if ($user['pin'] !== $pin) {
            echo json_encode(['status' => 'error', 'message' => 'PIN yang Anda masukkan salah']);
            exit();
        }

        echo json_encode(['status' => 'success', 'message' => 'PIN valid']);
    }
    elseif ($action === 'tarik_tunai') {
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
            $user_id = $row['user_id'];
            $email = $row['email'];

            // Validasi saldo nasabah
            if ($saldo_sekarang < $jumlah) {
                throw new Exception('Saldo nasabah tidak mencukupi untuk melakukan penarikan.');
            }

            // Cek saldo bersih petugas hari ini
            $today = date('Y-m-d');
            $petugas_id = $_SESSION['user_id'];
            
            // Hitung total setoran hari ini (masuk)
            $query_setoran = "SELECT COALESCE(SUM(jumlah), 0) as total_setoran 
                             FROM transaksi 
                             WHERE petugas_id = ? 
                             AND jenis_transaksi = 'setor' 
                             AND status = 'approved' 
                             AND DATE(created_at) = ?";
            $stmt_setoran = $conn->prepare($query_setoran);
            $stmt_setoran->bind_param('is', $petugas_id, $today);
            $stmt_setoran->execute();
            $result_setoran = $stmt_setoran->get_result();
            $total_setoran = floatval($result_setoran->fetch_assoc()['total_setoran']);

            // Hitung total penarikan hari ini (keluar)
            $query_penarikan = "SELECT COALESCE(SUM(jumlah), 0) as total_penarikan 
                               FROM transaksi 
                               WHERE petugas_id = ? 
                               AND jenis_transaksi = 'tarik' 
                               AND status = 'approved' 
                               AND DATE(created_at) = ?";
            $stmt_penarikan = $conn->prepare($query_penarikan);
            $stmt_penarikan->bind_param('is', $petugas_id, $today);
            $stmt_penarikan->execute();
            $result_penarikan = $stmt_penarikan->get_result();
            $total_penarikan = floatval($result_penarikan->fetch_assoc()['total_penarikan']);

            // Hitung saldo bersih petugas
            $saldo_bersih = $total_setoran - $total_penarikan;

            // Cek apakah saldo bersih petugas mencukupi untuk penarikan ini
            if ($saldo_bersih < $jumlah) {
                throw new Exception('Saldo kas petugas hari ini tidak mencukupi untuk penarikan sebesar Rp ' . number_format($jumlah, 0, ',', '.') . '. Saldo bersih saat ini: Rp ' . number_format($saldo_bersih, 0, ',', '.'));
            }

            // Generate nomor transaksi
            $no_transaksi = 'TRX' . date('Ymd') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);

            // Insert transaksi
            $query_transaksi = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, petugas_id, status) 
                               VALUES (?, ?, 'tarik', ?, ?, 'approved')";
            $stmt_transaksi = $conn->prepare($query_transaksi);
            $stmt_transaksi->bind_param('sidi', $no_transaksi, $rekening_id, $jumlah, $petugas_id);
            
            if (!$stmt_transaksi->execute()) {
                throw new Exception('Gagal menyimpan transaksi.');
            }

            $transaksi_id = $conn->insert_id;

            // Update saldo
            $saldo_baru = $saldo_sekarang - $jumlah;
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
            $message = "Penarikan tunai sebesar Rp " . number_format($jumlah, 0, ',', '.') . " berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.');
            $query_notifikasi = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
            $stmt_notifikasi = $conn->prepare($query_notifikasi);
            $stmt_notifikasi->bind_param('is', $user_id, $message);
            
            if (!$stmt_notifikasi->execute()) {
                throw new Exception('Gagal mengirim notifikasi.');
            }

            // Cek apakah email tersedia dan valid sebelum mencoba mengirim email
            $email_sent = false;
            if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                // Kirim email bukti transaksi
                $subject = "Bukti Transaksi Penarikan Tunai - SCHOBANK SYSTEM";
                $message = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset=\"UTF-8\">
                    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                    <title>Bukti Transaksi Penarikan Tunai - SCHOBANK SYSTEM</title>
                    <style>
                        /* CSS email tetap sama */
                    </style>
                </head>
                <body>
                    <div class=\"container\">
                        <h2>Bukti Transaksi Penarikan Tunai</h2>
                        
                        <p>Halo, <strong>{$nama_nasabah}</strong>!</p>
                        <p>Penarikan tunai dari rekening Anda telah berhasil diproses. Berikut adalah rincian transaksi:</p>
                        
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
                                <div class=\"label\">Jumlah Penarikan</div>
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
                    error_log("Mail error: " . $mail->ErrorInfo);
                    // Tidak throw exception lagi, transaksi harus tetap berjalan
                    // hanya log errornya saja
                }
            }

            $conn->commit();

            $response_message = "Penarikan tunai untuk nasabah {$nama_nasabah} berhasil. Saldo baru: Rp " . number_format($saldo_baru, 0, ',', '.');
            if (!empty($email) && !$email_sent) {
                $response_message .= " Bukti transaksi tidak dapat dikirim ke email.";
            } elseif (empty($email)) {
                $response_message .= " Email tidak terdaftar, bukti transaksi tidak dikirim.";
            }

            echo json_encode([
                'status' => 'success',
                'message' => $response_message,
                'email_sent' => $email_sent,
                'saldo_bersih' => $saldo_bersih - $jumlah // Update saldo bersih setelah transaksi
            ]);
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollback();
            }
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
?>