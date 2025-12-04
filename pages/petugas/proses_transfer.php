<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] == 'search_account') {
        $search = $_POST['search'] ?? '';
        $stmt = $conn->prepare("SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening LIKE ? OR u.nama LIKE ? LIMIT 10");
        $searchTerm = "%$search%";
        $stmt->bind_param("ss", $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'accounts' => $accounts]);
        exit;
    }
    
    if ($_POST['ajax_action'] == 'check_balance') {
        sleep(2);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $jumlah = preg_replace('/[^0-9]/', '', $_POST['jumlah'] ?? '0');
        
        $stmt = $conn->prepare("SELECT r.*, u.nama, u.is_frozen, u.pin_block_until FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$rek_asal) {
            echo json_encode(['success' => false, 'message' => 'Rekening asal tidak ditemukan']);
            exit;
        }
        
        $is_blocked = $rek_asal['pin_block_until'] && strtotime($rek_asal['pin_block_until']) > time();
        $is_frozen = (int)$rek_asal['is_frozen'];
        
        if ($is_frozen || $is_blocked) {
            echo json_encode(['success' => false, 'message' => "Gagal\nrekening pengirim sedang di bekukan/di blokir sementara."]);
            exit;
        }
        
        if ($rek_asal['saldo'] < $jumlah) {
            echo json_encode(['success' => false, 'message' => 'Saldo tidak mencukupi']);
        } else {
            echo json_encode(['success' => true, 'saldo' => $rek_asal['saldo']]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] == 'validate_pin') {
        sleep(3);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $pin = $_POST['pin'] ?? '';
        $pin_hash = hash('sha256', $pin);
        
        $stmt = $conn->prepare("SELECT r.user_id, u.pin FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$result) {
            echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan']);
        } elseif ($result['pin'] !== $pin_hash) {
            echo json_encode(['success' => false, 'message' => 'PIN yang Anda masukkan salah']);
        } else {
            echo json_encode(['success' => true]);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] == 'process_transfer') {
        sleep(2);
        $rekening_asal = $_POST['rekening_asal'] ?? '';
        $rekening_tujuan = $_POST['rekening_tujuan'] ?? '';
        $jumlah = preg_replace('/[^0-9]/', '', $_POST['jumlah'] ?? '0');
        $keterangan = trim($_POST['keterangan'] ?? '');
        
        $stmt = $conn->prepare("SELECT r.*, u.nama, u.email, u.is_frozen, u.pin_block_until, j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas FROM rekening r JOIN users u ON r.user_id = u.id LEFT JOIN jurusan j ON u.jurusan_id = j.id LEFT JOIN kelas k ON u.kelas_id = k.id LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stmt = $conn->prepare("SELECT r.*, u.nama, u.email, j.nama_jurusan AS jurusan, CONCAT(tk.nama_tingkatan, ' ', k.nama_kelas) AS kelas FROM rekening r JOIN users u ON r.user_id = u.id LEFT JOIN jurusan j ON u.jurusan_id = j.id LEFT JOIN kelas k ON u.kelas_id = k.id LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_tujuan);
        $stmt->execute();
        $rek_tujuan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$rek_asal || !$rek_tujuan) {
            echo json_encode(['success' => false, 'message' => 'Rekening tidak ditemukan']);
            exit;
        }
        
        $is_blocked = $rek_asal['pin_block_until'] && strtotime($rek_asal['pin_block_until']) > time();
        $is_frozen = (int)$rek_asal['is_frozen'];
        
        if ($is_frozen || $is_blocked) {
            echo json_encode(['success' => false, 'message' => "Gagal\nrekening pengirim sedang di bekukan/di blokir sementara."]);
            exit;
        }
        
        $today = date('Y-m-d');
        $stmt = $conn->prepare("SELECT * FROM petugas_tugas WHERE tanggal = ?");
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $petugas_tugas = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$petugas_tugas) {
            echo json_encode(['success' => false, 'message' => 'Tidak ada petugas yang bertugas hari ini']);
            exit;
        }
        
        $conn->begin_transaction();
        try {
            // Generate ID Transaksi
            do {
                $date_prefix = date('ymd');
                $random_8digit = sprintf('%08d', mt_rand(10000000, 99999999));
                $id_transaksi = $date_prefix . $random_8digit;
                
                $check_id_query = "SELECT id FROM transaksi WHERE id_transaksi = ?";
                $check_id_stmt = $conn->prepare($check_id_query);
                $check_id_stmt->bind_param('s', $id_transaksi);
                $check_id_stmt->execute();
                $check_id_result = $check_id_stmt->get_result();
                $id_exists = $check_id_result->num_rows > 0;
                $check_id_stmt->close();
            } while ($id_exists);

            // Generate Nomor Referensi
            do {
                $date_prefix = date('ymd');
                $random_6digit = sprintf('%06d', mt_rand(100000, 999999));
                $no_transaksi = 'TRXTFP' . $date_prefix . $random_6digit;
                
                $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param('s', $no_transaksi);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->num_rows > 0;
                $check_stmt->close();
            } while ($exists);
            
            $stmt = $conn->prepare("INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, keterangan, petugas_id, status) VALUES (?, ?, ?, ?, 'transfer', ?, ?, ?, 'approved')");
            $stmt->bind_param("ssiidsi", $id_transaksi, $no_transaksi, $rek_asal['id'], $rek_tujuan['id'], $jumlah, $keterangan, $_SESSION['user_id']);
            $stmt->execute();
            $transaksi_id = $conn->insert_id;
            $stmt->close();
            
            $saldo_asal_baru = $rek_asal['saldo'] - $jumlah;
            $stmt = $conn->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
            $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
            $stmt->execute();
            $stmt->close();
            
            $saldo_tujuan_baru = $rek_tujuan['saldo'] + $jumlah;
            $stmt = $conn->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
            $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
            $jumlah_negative = -$jumlah;
            $stmt->bind_param("iidd", $transaksi_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
            $stmt->execute();
            $stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iidd", $transaksi_id, $rek_tujuan['id'], $jumlah, $saldo_tujuan_baru);
            $stmt->execute();
            $stmt->close();
            
            $ket_part = $keterangan ? " - $keterangan" : '';
            $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rek_tujuan['no_rekening'] . "$ket_part BERHASIL dilakukan. Saldo baru: Rp " . number_format($saldo_asal_baru, 0, ',', '.');
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt->bind_param('is', $rek_asal['user_id'], $message_pengirim);
            $stmt->execute();
            $stmt->close();
            
            $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $rek_asal['no_rekening'] . "$ket_part. Saldo baru: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.');
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt->bind_param('is', $rek_tujuan['user_id'], $message_penerima);
            $stmt->execute();
            $stmt->close();
            
            sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $id_transaksi, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas, $keterangan);
            
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Transfer berhasil']);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Transfer gagal: ' . $e->getMessage()]);
        }
        exit;
    }
}

function sendTransferEmail($rek_asal, $rek_tujuan, $jumlah, $id_transaksi, $no_transaksi, $saldo_asal_baru, $saldo_tujuan_baru, $petugas_tugas, $keterangan) {
    $mail = new PHPMailer(true);
    
    $bulan = [
        'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
        'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
        'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
    ];
    $tanggal_transaksi = date('d M Y, H:i');
    foreach ($bulan as $en => $id) {
        $tanggal_transaksi = str_replace($en, $id, $tanggal_transaksi);
    }
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'myschobank@gmail.com';
        $mail->Password = 'xpni zzju utfu mkth';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        // Email untuk pengirim (jika ada email)
        if (!empty($rek_asal['email']) && filter_var($rek_asal['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
            $mail->addAddress($rek_asal['email'], $rek_asal['nama']);
            $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
            
            $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
            $mail->MessageID = '<' . $unique_id . '>';
            
            $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
            $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
            $mail->addCustomHeader('X-Mailer', 'Schobank-System-v1.0');
            $mail->addCustomHeader('X-Priority', '1');
            $mail->addCustomHeader('Importance', 'High');
            
            $header_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/header.png';
            if (file_exists($header_path)) {
                $mail->addEmbeddedImage($header_path, 'header_img', 'header.png');
            }
            
            $mail->isHTML(true);
            $mail->Subject = "Bukti Transfer Keluar {$no_transaksi}";
            $mail->Body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
</head>
<body style='margin:0; padding:0; font-family:Poppins,Arial,sans-serif; background:#f5f5f5;'>
    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%'>
        <tr>
            <td style='padding:40px 20px;'>
                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='600' style='margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
                    <!-- Header -->
                    <tr>
                        <td style='padding:40px 40px 30px; text-align:center; background:#ffffff;'>
                            <img src='cid:header_img' alt='Schobank' style='max-width:260px; width:100%; height:auto;' />
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style='padding:0 40px 40px;'>
                            <h2 style='margin:0 0 12px; font-size:20px; font-weight:600; color:#1a1a1a;'>Halo <strong>{$rek_asal['nama']}</strong>,</h2>
                            <p style='margin:0 0 24px; font-size:15px; line-height:1.6; color:#4a4a4a;'>
                                Kamu berhasil transfer <strong style='color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</strong> ke rekening {$rek_tujuan['nama']}.
                            </p>

                            <hr style='border:none; border-top:1px solid #e5e5e5; margin:30px 0;' />

                            <h3 style='margin:0 0 20px; font-size:17px; font-weight:700; color:#1a1a1a;'>Detail Transaksi</h3>

                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Transfer</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Biaya Admin</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#22c55e;'>Gratis</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Total</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Sumber</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_asal['nama']}<br><span style='font-size:14px; color:#808080;'>Schobank • {$rek_asal['no_rekening']}</span></p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_tujuan['nama']}<br><span style='font-size:14px; color:#808080;'>Schobank • {$rek_tujuan['no_rekening']}</span></p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . ($keterangan ?: 'Tidak ada') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal & Waktu</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
                            </div>
                            <div style='margin-bottom:0;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
                                <p style='margin:0; font-size:16px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style='padding:30px 40px; text-align:center; border-top:1px solid #e5e5e5; background:#ffffff;'>
                            <p style='margin:0 0 8px; font-size:13px; color:#6b7280;'>
                                © " . date('Y') . " Schobank Student Digital Banking. All rights reserved.
                            </p>
                            <p style='margin:0 0 8px; font-size:12px; color:#9ca3af;'>
                                Email ini dikirim secara otomatis. Mohon tidak membalas email ini.
                            </p>
                            <p style='margin:0; font-size:11px; color:#d1d5db;'>
                                Ref: {$no_transaksi} • ID: {$id_transaksi}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
            $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $mail->Body));
            
            $mail->send();
        }
        
        // Email untuk penerima (jika ada email)
        if (!empty($rek_tujuan['email']) && filter_var($rek_tujuan['email'], FILTER_VALIDATE_EMAIL)) {
            $mail->clearAddresses();
            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->clearReplyTos();
            
            $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
            $mail->addAddress($rek_tujuan['email'], $rek_tujuan['nama']);
            $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
            
            $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
            $mail->MessageID = '<' . $unique_id . '>';
            
            $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
            $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
            $mail->addCustomHeader('X-Mailer', 'Schobank-System-v1.0');
            $mail->addCustomHeader('X-Priority', '1');
            $mail->addCustomHeader('Importance', 'High');
            
            $header_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/header.png';
            if (file_exists($header_path)) {
                $mail->addEmbeddedImage($header_path, 'header_img', 'header.png');
            }
            
            $mail->Subject = "Bukti Transfer Masuk {$no_transaksi}";
            $mail->Body = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap' rel='stylesheet'>
</head>
<body style='margin:0; padding:0; font-family:Poppins,Arial,sans-serif; background:#f5f5f5;'>
    <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='100%'>
        <tr>
            <td style='padding:40px 20px;'>
                <table role='presentation' cellspacing='0' cellpadding='0' border='0' width='600' style='margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 4px 20px rgba(0,0,0,0.08);'>
                    <!-- Header -->
                    <tr>
                        <td style='padding:40px 40px 30px; text-align:center; background:#ffffff;'>
                            <img src='cid:header_img' alt='Schobank' style='max-width:260px; width:100%; height:auto;' />
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style='padding:0 40px 40px;'>
                            <h2 style='margin:0 0 12px; font-size:20px; font-weight:600; color:#1a1a1a;'>Halo <strong>{$rek_tujuan['nama']}</strong>,</h2>
                            <p style='margin:0 0 24px; font-size:15px; line-height:1.6; color:#4a4a4a;'>
                                Kamu menerima transfer <strong style='color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</strong> dari rekening {$rek_asal['nama']}.
                            </p>

                            <hr style='border:none; border-top:1px solid #e5e5e5; margin:30px 0;' />

                            <h3 style='margin:0 0 20px; font-size:17px; font-weight:700; color:#1a1a1a;'>Detail Transaksi</h3>

                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nominal Transfer</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Biaya Admin</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#22c55e;'>Gratis</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Total</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>Rp" . number_format($jumlah, 0, ',', '.') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Sumber</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_asal['nama']}<br><span style='font-size:14px; color:#808080;'>Schobank • {$rek_asal['no_rekening']}</span></p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Rekening Tujuan</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$rek_tujuan['nama']}<br><span style='font-size:14px; color:#808080;'>Schobank • {$rek_tujuan['no_rekening']}</span></p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Keterangan</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>" . ($keterangan ?: 'Tidak ada') . "</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Tanggal & Waktu</p>
                                <p style='margin:0; font-size:16px; font-weight:600; color:#1a1a1a;'>{$tanggal_transaksi} WIB</p>
                            </div>
                            <div style='margin-bottom:18px;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>Nomor Referensi</p>
                                <p style='margin:0; font-size:17px; font-weight:700; color:#1a1a1a;'>{$no_transaksi}</p>
                            </div>
                            <div style='margin-bottom:0;'>
                                <p style='margin:0 0 6px; font-size:13px; color:#808080;'>ID Transaksi</p>
                                <p style='margin:0; font-size:16px; font-weight:700; color:#1a1a1a;'>{$id_transaksi}</p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style='padding:30px 40px; text-align:center; border-top:1px solid #e5e5e5; background:#ffffff;'>
                            <p style='margin:0 0 8px; font-size:13px; color:#6b7280;'>
                                © " . date('Y') . " Schobank Student Digital Banking. All rights reserved.
                            </p>
                            <p style='margin:0 0 8px; font-size:12px; color:#9ca3af;'>
                                Email ini dikirim secara otomatis. Mohon tidak membalas email ini.
                            </p>
                            <p style='margin:0; font-size:11px; color:#d1d5db;'>
                                Ref: {$no_transaksi} • ID: {$id_transaksi}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";
            $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $mail->Body));
            
            $mail->send();
        }
        
    } catch (Exception $e) {
        error_log("Email error: " . $mail->ErrorInfo);
    }
}
$conn->close();
?>