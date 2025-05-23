<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set zona waktu
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../../index.php');
    exit();
}

// Fungsi untuk menghasilkan token
function generateToken() {
    return bin2hex(random_bytes(32));
}

// Fungsi untuk menyimpan token reset
function saveResetToken($conn, $user_id, $token, $recovery_type, $expiry_hours = 24) {
    $expiry = date('Y-m-d H:i:s', strtotime("+{$expiry_hours} hours"));
    $query = "INSERT INTO password_reset (user_id, token, recovery_type, expiry) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('isss', $user_id, $token, $recovery_type, $expiry);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Gagal menyimpan token: user_id=$user_id, token=$token, type=$recovery_type, error=" . $conn->error);
    }
    return $success;
}

// Fungsi untuk mencatat aktivitas
function logActivity($conn, $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan) {
    $query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, alasan, waktu) 
              VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('iisss', $petugas_id, $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan);
    $success = $stmt->execute();
    if (!$success) {
        error_log("Gagal mencatat aktivitas: petugas_id=$petugas_id, siswa_id=$siswa_id, type=$jenis_pemulihan, error=" . $conn->error);
    }
    return $success;
}

// Fungsi untuk mengirim email
function sendEmail($toEmail, $toName, $subject, $htmlContent) {
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
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlContent;
        $mail->AltBody = strip_tags($htmlContent);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Gagal mengirim email ke $toEmail: " . $e->getMessage());
        return false;
    }
}

// Fungsi untuk mengirim pesan WhatsApp menggunakan Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $curl = curl_init();
    
    // Pastikan format nomor benar (awalan 62)
    if (substr($phone_number, 0, 2) === '08') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $phone_number,
            'message' => $message
        ),
        CURLOPT_HTTPHEADER => array(
            'Authorization: dCjq3fJVf9p2DAfVDVED'
        ),
    ));
    
    $response = curl_exec($curl);
    $curl_error = curl_error($curl);
    curl_close($curl);
    
    if ($curl_error) {
        error_log("CURL Error in sendWhatsAppMessage: $curl_error");
        return false;
    }
    return $response;
}

// Fungsi untuk cek cooldown periode
function checkCooldownPeriod($conn, $user_id, $method, $recovery_type) {
    $query = "SELECT last_reset_request FROM reset_request_cooldown WHERE user_id = ? AND method = ? AND recovery_type = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $user_id, $method, $recovery_type);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_request_time = strtotime($row['last_reset_request']);
        $current_time = time();
        $cooldown_period = 15 * 60; // 15 menit dalam detik
        
        if (($current_time - $last_request_time) < $cooldown_period) {
            $remaining_seconds = $cooldown_period - ($current_time - $last_request_time);
            $remaining_minutes = ceil($remaining_seconds / 60);
            return $remaining_minutes;
        }
    }
    return 0;
}

// Fungsi untuk update waktu permintaan reset
function updateResetRequestTime($conn, $user_id, $method, $recovery_type) {
    $current_time = date('Y-m-d H:i:s');
    
    $check_query = "SELECT id FROM reset_request_cooldown WHERE user_id = ? AND method = ? AND recovery_type = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("iss", $user_id, $method, $recovery_type);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $update_query = "UPDATE reset_request_cooldown SET last_reset_request = ? WHERE user_id = ? AND method = ? AND recovery_type = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("siss", $current_time, $user_id, $method, $recovery_type);
        $update_stmt->execute();
    } else {
        $insert_query = "INSERT INTO reset_request_cooldown (user_id, method, recovery_type, last_reset_request) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("isss", $user_id, $method, $recovery_type, $current_time);
        $insert_stmt->execute();
    }
}

// Cek apakah tabel reset_request_cooldown sudah ada, jika belum maka buat
$check_table_query = "SHOW TABLES LIKE 'reset_request_cooldown'";
$check_table_result = $conn->query($check_table_query);
if ($check_table_result->num_rows == 0) {
    $create_table_query = "CREATE TABLE reset_request_cooldown (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        method VARCHAR(20) NOT NULL,
        recovery_type VARCHAR(20) NOT NULL,
        last_reset_request DATETIME NOT NULL,
        INDEX idx_cooldown (user_id, method, recovery_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if (!$conn->query($create_table_query)) {
        error_log("Gagal membuat tabel reset_request_cooldown: " . $conn->error);
    }
} else {
    // Cek apakah kolom recovery_type sudah ada, jika belum tambahkan
    $check_column_query = "SHOW COLUMNS FROM reset_request_cooldown LIKE 'recovery_type'";
    $check_column_result = $conn->query($check_column_query);
    if ($check_column_result->num_rows == 0) {
        // Cek apakah index idx_cooldown ada
        $check_index_query = "SHOW INDEX FROM reset_request_cooldown WHERE Key_name = 'idx_cooldown'";
        $check_index_result = $conn->query($check_index_query);
        $index_exists = $check_index_result->num_rows > 0;

        // Bangun query ALTER TABLE
        $alter_query = "ALTER TABLE reset_request_cooldown ADD COLUMN recovery_type VARCHAR(20) NOT NULL DEFAULT 'password'";
        if ($index_exists) {
            $alter_query .= ", DROP INDEX idx_cooldown";
        }
        $alter_query .= ", ADD INDEX idx_cooldown (user_id, method, recovery_type)";

        if (!$conn->query($alter_query)) {
            error_log("Gagal mengubah tabel reset_request_cooldown: " . $conn->error);
        } else {
            // Update existing records to set recovery_type to 'password'
            $update_query = "UPDATE reset_request_cooldown SET recovery_type = 'password' WHERE recovery_type = '' OR recovery_type IS NULL";
            if (!$conn->query($update_query)) {
                error_log("Gagal mengupdate recovery_type pada reset_request_cooldown: " . $conn->error);
            }
        }
    }
}

// Fungsi untuk membuat template email
function generateEmailTemplate($title, $greeting, $content, $buttonText = null, $buttonLink = null) {
    $buttonHtml = $buttonText && $buttonLink ? "
        <div class=\"button-container\">
            <a href=\"{$buttonLink}\" class=\"btn\">{$buttonText}</a>
        </div>" : '';

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset=\"UTF-8\">
        <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
        <title>{$title}</title>
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
            .security-notice {
                background-color: #fff8e1;
                border-left: 4px solid #f39c12;
                padding: 10px 15px;
                margin: 20px 0;
                font-size: 0.9em;
                color: #7f8c8d;
            }
            .footer { 
                margin-top: 30px; 
                font-size: 0.8em; 
                color: #666; 
                border-top: 1px solid #ddd; 
                padding-top: 15px; 
                text-align: center;
            }
            p {
                color: #34495e;
            }
        </style>
    </head>
    <body>
        <div class=\"container\">
            <h2>{$title}</h2>
            <p>{$greeting}</p>
            <p>{$content}</p>
            {$buttonHtml}
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
    </html>";
}

// Inisialisasi array pesan
$messages = ['success' => '', 'errors' => []];

// Cek pesan dari session
if (isset($_SESSION['messages'])) {
    $messages = $_SESSION['messages'];
    unset($_SESSION['messages']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $recovery_type = $_POST['recovery_type'] ?? '';
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $delivery_method = $_POST['delivery_method'] ?? '';
    $alasan = trim($_POST['alasan'] ?? '');
    $custom_alasan = trim($_POST['custom_alasan'] ?? '');

    // Gunakan alasan kustom jika disediakan
    $alasan_pemulihan = $alasan === 'Lainnya' && !empty($custom_alasan) ? $custom_alasan : $alasan;

    // Validasi input
    if (empty($recovery_type) || !in_array($recovery_type, ['password', 'pin', 'username', 'email'])) {
        $messages['errors'][] = 'Jenis pemulihan tidak valid.';
    }
    if (empty($no_rekening)) {
        $messages['errors'][] = 'Nomor rekening harus diisi.';
    } elseif (!preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
        $messages['errors'][] = 'Nomor rekening harus berformat REK diikuti 6 digit angka.';
    }
    if (empty($delivery_method) || !in_array($delivery_method, ['email', 'whatsapp'])) {
        $messages['errors'][] = 'Metode pengiriman tidak valid.';
    }
    if ($recovery_type === 'email' && $delivery_method !== 'whatsapp') {
        $messages['errors'][] = 'Pemulihan email hanya dapat dikirim melalui WhatsApp.';
    }
    if (empty($alasan_pemulihan)) {
        $messages['errors'][] = 'Alasan pemulihan harus diisi.';
    }

    if (empty($messages['errors'])) {
        // Cek nomor rekening
        $query = "SELECT u.id, u.nama, u.email, u.no_wa, u.role FROM users u 
                  JOIN rekening r ON u.id = r.user_id 
                  WHERE r.no_rekening = ? AND u.role = 'siswa'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $no_rekening);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows == 0) {
            $messages['errors'][] = 'Nomor rekening tidak ditemukan atau bukan akun siswa.';
            error_log("Nomor rekening tidak ditemukan: no_rekening=$no_rekening");
        } else {
            $user = $result->fetch_assoc();
            $user_id = $user['id'];
            $nama = $user['nama'];
            $email = $user['email'];
            $no_wa = $user['no_wa'];

            // Validasi metode pengiriman
            if ($delivery_method === 'email' && empty($email) && $recovery_type !== 'email') {
                $messages['errors'][] = 'Akun ini tidak memiliki email. Silakan pilih metode WhatsApp atau hubungi admin.';
            } elseif ($delivery_method === 'whatsapp' && empty($no_wa)) {
                $messages['errors'][] = 'Akun ini tidak memiliki nomor WhatsApp. Silakan pilih metode email atau hubungi admin.';
            } else {
                // Cek cooldown berdasarkan user_id, delivery_method, dan recovery_type
                $cooldown_minutes = checkCooldownPeriod($conn, $user_id, $delivery_method, $recovery_type);
                if ($cooldown_minutes > 0) {
                    $messages['errors'][] = "Anda harus menunggu {$cooldown_minutes} menit lagi sebelum mengirim permintaan reset {$recovery_type} baru.";
                } else {
                    // Generate token dan simpan
                    $token = generateToken();
                    if (saveResetToken($conn, $user_id, $token, $recovery_type, 0.25)) { // 15 menit
                        // Log aktivitas
                        $nilai_baru = $delivery_method === 'email' ? 'Link reset dikirim via email' : 'Link reset dikirim via WhatsApp';
                        if ($recovery_type === 'email') {
                            $nilai_baru = 'Link verifikasi dikirim ke WhatsApp';
                        }
                        logActivity($conn, $_SESSION['user_id'], $user_id, $recovery_type, $nilai_baru, $alasan_pemulihan);

                        // Generate dynamic reset link
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                        $host = $_SERVER['HTTP_HOST'];
                        $path = dirname($_SERVER['PHP_SELF'], 3); // Navigate three levels up to reach /Schobank/
                        $reset_link = $protocol . "://" . $host . $path . "/pages/siswa/reset_akun.php?token=$token&type=$recovery_type";
                        $expire_time = date('d M Y H:i:s', strtotime('+15 minutes'));

                        if ($delivery_method === 'email') {
                            // Kirim email
                            $subject = $recovery_type === 'email' ? "Perubahan Email - SCHOBANK SYSTEM" : "Pemulihan " . ucfirst($recovery_type) . " - SCHOBANK SYSTEM";
                            $greeting = "Yth. $nama,";
                            $content = $recovery_type === 'email' ? 
                                "Admin telah memulai proses perubahan email Anda. Silakan klik tombol di bawah untuk memasukkan email baru. Link ini akan kadaluarsa dalam 15 menit." :
                                "Admin telah memulai proses pemulihan $recovery_type Anda. Silakan klik tombol di bawah untuk mengatur ulang $recovery_type Anda. Link ini akan kadaluarsa dalam 15 menit.";
                            $button_text = $recovery_type === 'email' ? 'Ubah Email Sekarang' : 'Atur Ulang Sekarang';
                            $html = generateEmailTemplate(
                                $recovery_type === 'email' ? "Perubahan Email" : "Pemulihan " . ucfirst($recovery_type),
                                $greeting,
                                $content,
                                $button_text,
                                $reset_link
                            );

                            error_log("Mengirim email ke: $email dengan token: $token, type: $recovery_type, link: $reset_link");

                            if (sendEmail($email, $nama, $subject, $html)) {
                                updateResetRequestTime($conn, $user_id, 'email', $recovery_type);
                                $messages['success'] = "Link pemulihan telah dikirim ke email siswa.";
                            } else {
                                $messages['errors'][] = "Gagal mengirim email. Silakan coba lagi.";
                            }
                        } else {
                            // Kirim WhatsApp
                            $type_label = strtoupper($recovery_type === 'email' ? 'PERUBAHAN EMAIL' : $recovery_type);
                            $action_text = $recovery_type === 'email' ? 
                                "mengubah email akun Anda. Anda akan diminta untuk memasukkan email baru di halaman yang ditautkan" : 
                                "mereset {$recovery_type}";
                            $wa_message = "
*📱 SCHOBANK SYSTEM - " . ($recovery_type === 'email' ? 'PERUBAHAN EMAIL' : 'RESET ' . strtoupper($recovery_type)) . "* 
━━━━━━━━━━━━━━━━━━

Halo, *{$nama}* 👋

Kami menerima permintaan untuk $action_text. 

*🔐 LINK " . ($recovery_type === 'email' ? 'VERIFIKASI' : 'RESET') . ":*
{$reset_link}

⏰ *PENTING*: Link ini akan *KADALUARSA* dalam 15 menit
(sampai {$expire_time} WIB)

🛡️ *KEAMANAN*: 
Jika bukan Anda yang meminta pemulihan ini, abaikan pesan ini.

━━━━━━━━━━━━━━━━━━
_Pesan ini dikirim otomatis oleh sistem_
*SCHOBANK* © " . date('Y') . "
_Solusi Perbankan Digital Anda_
";

                            error_log("Mengirim WhatsApp ke: $no_wa dengan token: $token, type: $recovery_type, link: $reset_link");

                            try {
                                $response = sendWhatsAppMessage($no_wa, $wa_message);
                                $response_data = json_decode($response, true);

                                if (isset($response_data['status']) && $response_data['status'] === true) {
                                    updateResetRequestTime($conn, $user_id, 'whatsapp', $recovery_type);
                                    $masked_number = substr($no_wa, 0, 4) . "xxxx" . substr($no_wa, -4);
                                    $messages['success'] = "Link pemulihan telah dikirim ke WhatsApp nomor {$masked_number}.";
                                } else {
                                    $messages['errors'][] = "Gagal mengirim pesan WhatsApp. Silakan coba lagi.";
                                    error_log("WhatsApp API error: " . $response);
                                }
                            } catch (Exception $e) {
                                $messages['errors'][] = "Gagal mengirim pesan WhatsApp. Silakan coba lagi.";
                                error_log("WhatsApp error: " . $e->getMessage());
                            }
                        }
                    } else {
                        $messages['errors'][] = "Gagal menyimpan token pemulihan.";
                    }
                }
            }
        }
    }

    // Jika request adalah AJAX, kembalikan JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($messages);
        exit();
    }

    // Untuk non-AJAX, simpan pesan di session dan redirect
    $_SESSION['messages'] = $messages;
    header('Location: recover_account.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pemulihan Akun - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .form-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        textarea {
            resize: vertical;
            min-height: 80px;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .input-prefix {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: clamp(0.9rem, 2vw, 1rem);
            pointer-events: none;
            font-weight: 500;
        }

        input.with-prefix {
            padding-left: 3rem;
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        select {
            appearance: none;
            position: relative;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: var(--text-secondary);
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .method-selector {
            display: flex;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid #ddd;
        }

        .method-option {
            flex: 1;
            padding: 12px;
            text-align: center;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            background: #f8f9fa;
            color: var(--text-secondary);
            border-right: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .method-option:last-child {
            border-right: none;
        }

        .method-option.active {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
        }

        .method-option.disabled {
            background: #e9ecef;
            color: #adb5bd;
            cursor: not-allowed;
            pointer-events: none;
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: default;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(300px, 80vw, 400px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 15px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: default;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(2.5rem, 5vw, 3rem);
            margin: 0 auto 10px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 10px;
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0 0 15px;
            line-height: 1.5;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .success-modal, .error-modal {
                width: clamp(280px, 85vw, 360px);
                padding: clamp(12px, 3vw, 15px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2.3rem, 4.5vw, 2.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input, select, textarea {
                min-height: 40px;
            }

            .success-modal, .error-modal {
                width: clamp(260px, 90vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
                margin: 8px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(2rem, 4vw, 2.2rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Pemulihan Akun Siswa</h2>
            <p>Fitur ini memungkinkan admin untuk membantu siswa mengatur ulang kata sandi, PIN, username, atau memperbarui alamat email, memastikan akses akun yang aman dan terjamin.</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form action="" method="POST" id="recoveryForm" class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="recovery_type">Jenis Pemulihan</label>
                        <div class="select-wrapper">
                            <select id="recovery_type" name="recovery_type" required>
                                <option value="">Pilih Opsi</option>
                                <option value="password">Reset Password</option>
                                <option value="pin">Reset PIN</option>
                                <option value="username">Reset Username</option>
                                <option value="email">Ubah Email</option>
                            </select>
                            <span class="tooltip">Pilih jenis pemulihan yang diinginkan</span>
                        </div>
                        <span class="error-message" id="recovery_type-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="no_rekening">Nomor Rekening</label>
                        <div class="input-container">
                            <span class="input-prefix">REK</span>
                            <input type="text" id="no_rekening" class="with-prefix" inputmode="numeric" pattern="[0-9]*" required placeholder="6 digit angka" maxlength="6" autocomplete="off">
                            <input type="hidden" id="no_rekening_hidden" name="no_rekening">
                            <span class="tooltip">Masukkan 6 digit angka nomor rekening</span>
                        </div>
                        <span class="error-message" id="no_rekening-error"></span>
                    </div>
                </div>
                <div class="form-column">
                    <div class="form-group">
                        <label for="delivery_method">Metode Pengiriman</label>
                        <div class="method-selector">
                            <div class="method-option active" data-method="email" id="email-option">
                                <i class="fas fa-envelope"></i> Email
                            </div>
                            <div class="method-option" data-method="whatsapp" id="wa-option">
                                <i class="fab fa-whatsapp"></i> WhatsApp
                            </div>
                        </div>
                        <input type="hidden" id="delivery_method" name="delivery_method" value="email">
                        <span class="error-message" id="delivery_method-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="alasan">Alasan Pemulihan</label>
                        <div class="select-wrapper">
                            <select id="alasan" name="alasan" required>
                                <option value="">Pilih Alasan</option>
                            </select>
                            <span class="tooltip">Pilih alasan pemulihan</span>
                        </div>
                        <span class="error-message" id="alasan-error"></span>
                    </div>
                    <div class="form-group" id="custom_alasan_group" style="display: none;">
                        <label for="custom_alasan">Alasan Lainnya</label>
                        <textarea id="custom_alasan" name="custom_alasan" placeholder="Masukkan alasan lainnya"></textarea>
                        <span class="tooltip">Masukkan alasan pemulihan secara rinci</span>
                        <span class="error-message" id="custom_alasan-error"></span>
                    </div>
                </div>
                <div class="form-buttons">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content">Kirim Permintaan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            // Initialize reason options on page load
            updateReasonOptions('');

            // Toggle delivery method and reasons on recovery type change
            $('#recovery_type').on('change', function() {
                const value = this.value;
                updateReasonOptions(value);
                updateDeliveryMethod(value);
                $('#alasan-error, #custom_alasan-error').removeClass('show').text('');
            });

            // Toggle custom reason input
            $('#alasan').on('change', function() {
                const isCustom = this.value === 'Lainnya';
                $('#custom_alasan_group').toggle(isCustom);
                $('#custom_alasan').prop('required', isCustom);
                $('#alasan-error, #custom_alasan-error').removeClass('show').text('');
            });

            // Handle delivery method selection
            $('.method-option').on('click', function() {
                if (!$(this).hasClass('disabled')) {
                    $('.method-option').removeClass('active');
                    $(this).addClass('active');
                    $('#delivery_method').val($(this).data('method'));
                    $('#delivery_method-error').removeClass('show').text('');
                }
            });

            // Handle no_rekening input
            $('#no_rekening').on('input paste', function(e) {
                let value = $(this).val().replace(/[^0-9]/g, '');
                if (value.length > 6) value = value.slice(0, 6);
                $(this).val(value);
                $('#no_rekening_hidden').val(value ? 'REK' + value : '');
                $('#no_rekening-error').removeClass('show').text('');
            });

            // Function to update reason options
            function updateReasonOptions(recovery_type) {
                const alasanSelect = $('#alasan');
                alasanSelect.empty().append('<option value="">Pilih Alasan</option>');

                const reasons = {
                    'password': ['Lupa kata sandi', 'Keamanan akun', 'Lainnya'],
                    'pin': ['Lupa PIN', 'Keamanan akun', 'Lainnya'],
                    'username': ['Lupa username', 'Perubahan identitas', 'Lainnya'],
                    'email': ['Perubahan email', 'Email lama tidak aktif', 'Lupa email', 'Lainnya'],
                    '': ['Lainnya']
                };

                const selectedReasons = reasons[recovery_type] || ['Lainnya'];
                selectedReasons.forEach(reason => {
                    const option = new Option(reason, reason);
                    alasanSelect.append(option);
                });

                alasanSelect.val('');
                $('#custom_alasan_group').hide();
                $('#custom_alasan').val('').prop('required', false);
                console.log(`Updated reasons for recovery_type: ${recovery_type}, options: ${selectedReasons.join(', ')}`);
            }

            // Function to update delivery method
            function updateDeliveryMethod(recovery_type) {
                if (recovery_type === 'email') {
                    $('#email-option').addClass('disabled').removeClass('active');
                    $('#wa-option').addClass('active').removeClass('disabled');
                    $('#delivery_method').val('whatsapp');
                } else {
                    $('#email-option').removeClass('disabled');
                    $('#wa-option').removeClass('disabled');
                    // Default to email if not already set
                    if (!$('#delivery_method').val()) {
                        $('#email-option').addClass('active');
                        $('#wa-option').removeClass('active');
                        $('#delivery_method').val('email');
                    }
                }
            }

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => closeModal(alertDiv.id), 5000);
                alertDiv.addEventListener('click', (e) => {
                    if (e.target.classList.contains('modal-overlay')) {
                        closeModal(alertDiv.id);
                    }
                });
                alertDiv.id = 'alert-' + Date.now();
            }

            // Modal close handling
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                }
            }

            // Form submission handling
            const recoveryForm = document.getElementById('recoveryForm');
            const submitBtn = document.getElementById('submit-btn');

            if (recoveryForm && submitBtn) {
                recoveryForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (recoveryForm.classList.contains('submitting')) return;
                    recoveryForm.classList.add('submitting');

                    const data = {
                        recovery_type: $('#recovery_type').val().trim(),
                        no_rekening: $('#no_rekening_hidden').val().trim(),
                        delivery_method: $('#delivery_method').val().trim(),
                        alasan: $('#alasan').val().trim(),
                        custom_alasan: $('#custom_alasan').val().trim()
                    };

                    // Client-side validation
                    let isValid = true;

                    if (!data.recovery_type) {
                        $('#recovery_type-error').text('Harap pilih jenis pemulihan.').addClass('show');
                        $('#recovery_type').focus();
                        isValid = false;
                    } else {
                        $('#recovery_type-error').removeClass('show').text('');
                    }

                    if (!data.no_rekening) {
                        $('#no_rekening-error').text('Nomor rekening harus diisi.').addClass('show');
                        $('#no_rekening').focus();
                        isValid = false;
                    } else if (!/^REK\d{6}$/.test(data.no_rekening)) {
                        $('#no_rekening-error').text('Nomor rekening harus berformat REK diikuti 6 digit angka.').addClass('show');
                        $('#no_rekening').focus();
                        isValid = false;
                    } else {
                        $('#no_rekening-error').removeClass('show').text('');
                    }

                    if (!data.delivery_method) {
                        $('#delivery_method-error').text('Harap pilih metode pengiriman.').addClass('show');
                        isValid = false;
                    } else if (data.recovery_type === 'email' && data.delivery_method !== 'whatsapp') {
                        $('#delivery_method-error').text('Pemulihan email hanya dapat dikirim melalui WhatsApp.').addClass('show');
                        isValid = false;
                    } else {
                        $('#delivery_method-error').removeClass('show').text('');
                    }

                    if (!data.alasan) {
                        $('#alasan-error').text('Harap pilih alasan pemulihan.').addClass('show');
                        $('#alasan').focus();
                        isValid = false;
                    } else if (data.alasan === 'Lainnya' && !data.custom_alasan) {
                        $('#custom_alasan-error').text('Harap masukkan alasan lainnya.').addClass('show');
                        $('#custom_alasan').focus();
                        isValid = false;
                    } else {
                        $('#alasan-error, #custom_alasan-error').removeClass('show').text('');
                    }

                    if (!isValid) {
                        recoveryForm.classList.remove('submitting');
                        return;
                    }

                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';

                    $.ajax({
                        url: window.location.href,
                        method: 'POST',
                        data: data,
                        dataType: 'json',
                        timeout: 10000,
                        success: (response) => {
                            submitBtn.classList.remove('loading');
                            submitBtn.innerHTML = '<span class="btn-content">Kirim Permintaan</span>';
                            recoveryForm.classList.remove('submitting');

                            if (response.success) {
                                showAlert(response.success, 'success');
                                setTimeout(() => {
                                    $('#recoveryForm')[0].reset();
                                    $('#custom_alasan_group').hide();
                                    $('#recovery_type').val('').focus();
                                    $('#no_rekening_hidden').val('');
                                    $('#alasan, #custom_alasan').val('');
                                    $('.method-option').removeClass('active disabled');
                                    $('#email-option').addClass('active');
                                    $('#wa-option').removeClass('active');
                                    $('#delivery_method').val('email');
                                    updateReasonOptions('');
                                    updateDeliveryMethod('');
                                }, 3000);
                            } else if (response.errors && response.errors.length) {
                                showAlert(response.errors.join(' '), 'error');
                            } else {
                                showAlert('Terjadi kesalahan tidak diketahui. Silakan coba lagi.', 'error');
                            }
                        },
                        error: (xhr, status, error) => {
                            submitBtn.classList.remove('loading');
                            submitBtn.innerHTML = '<span class="btn-content">Kirim Permintaan</span>';
                            recoveryForm.classList.remove('submitting');
                            let errorMessage = 'Gagal memproses permintaan. Periksa koneksi atau coba lagi.';
                            if (status === 'timeout') {
                                errorMessage = 'Permintaan terlalu lama. Silakan coba lagi.';
                            } else if (xhr.status === 500) {
                                errorMessage = 'Kesalahan server. Silakan coba lagi atau hubungi dukungan.';
                            }
                            showAlert(errorMessage, 'error');
                            console.error("AJAX error: status=" + status + ", error=" + error + ", response=" + xhr.responseText);
                        }
                    });
                });
            }

            // Handle session messages
            <?php if ($messages['success'] || !empty($messages['errors'])): ?>
                $(window).on('load', function() {
                    <?php if ($messages['success']): ?>
                        showAlert('<?php echo addslashes($messages['success']); ?>', 'success');
                    <?php elseif (!empty($messages['errors'])): ?>
                        showAlert('<?php echo addslashes(implode(' ', $messages['errors'])); ?>', 'error');
                    <?php endif; ?>
                });
            <?php endif; ?>

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Keyboard accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                    recoveryForm.dispatchEvent(new Event('submit'));
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });
        });
    </script>
</body>
</html>