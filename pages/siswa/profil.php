<?php
session_start();
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Initialize message variables
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Fetch user data
$query = "SELECT u.username, u.nama, u.role, u.email, u.no_wa, u.tanggal_lahir, k.nama_kelas, nama_tingkatan, j.nama_jurusan, 
          r.no_rekening, r.saldo, u.created_at AS tanggal_bergabung, u.pin, u.jurusan_id, u.kelas_id, u.is_frozen, u.pin_block_until, k.tingkatan_kelas_id
          FROM users u 
          LEFT JOIN rekening r ON u.id = r.user_id 
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    die("Data pengguna tidak ditemukan.");
}

// Determine account status
function getAccountStatus($user_data, $current_time) {
    if ($user_data['is_frozen']) return 'dibekukan';
    if ($user_data['pin_block_until'] && $user_data['pin_block_until'] > $current_time) return 'terblokir_sementara';
    return 'aktif';
}
$current_time = date('Y-m-d H:i:s');
$account_status = getAccountStatus($user, $current_time);

// Send email function
function sendEmail($email, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'schobanksystem@gmail.com';
        $mail->Password = 'dgry fzmc mfrd hzzq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('schobanksystem@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

// Send WhatsApp message via Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $api_token = 'dCjq3fJVf9p2DAfVDVED';
    if (substr($phone_number, 0, 1) === '0') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => ['target' => $phone_number, 'message' => $message],
        CURLOPT_HTTPHEADER => ["Authorization: $api_token"],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) {
        error_log("WhatsApp sending failed: $err");
        return false;
    }
    $result = json_decode($response, true);
    return isset($result['status']) && $result['status'] === true;
}

// Get Indonesian month name
function getIndonesianMonth($date) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return date('d', strtotime($date)) . ' ' . $months[(int)date('m', strtotime($date))] . ' ' . date('Y', strtotime($date));
}

// Email template
function getEmailTemplate($title, $greeting, $content, $additionalInfo = '') {
    return "
    <!DOCTYPE html>
    <html lang='id'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>{$title}</title>
    </head>
    <body style='margin: 0; padding: 0; background-color: #f5f5f5; font-family: Helvetica, Arial, sans-serif; color: #333333;'>
        <table align='center' border='0' cellpadding='0' cellspacing='0' width='100%' style='max-width: 600px; background-color: #ffffff; margin: 40px auto; border: 1px solid #e0e0e0;'>
            <tr>
                <td style='padding: 20px; border-bottom: 2px solid #0c4da2;'>
                    <table width='100%' border='0' cellpadding='0' cellspacing='0'>
                        <tr>
                            <td style='font-size: calc(1rem + 0.2vw); font-weight: bold; color: #0c4da2;'>SCHOBANK</td>
                            <td style='text-align: right;'>
                                <span style='font-size: calc(0.75rem + 0.1vw); color: #666666;'>SCHOBANK SYSTEM</span><br>
                                <span style='font-size: calc(0.65rem + 0.1vw); color: #999999;'>Tanggal: " . getIndonesianMonth(date('Y-m-d')) . "</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <tr>
                <td style='padding: 30px 20px;'>
                <h2 style='font-size: calc(1.2rem + 0.3vw); color: #0c4da2; margin: 0 0 15px 0; font-weight: bold;'>{$title}</h2>
                    <p style='font-size: calc(0.85rem + 0.2vw); line-height: 1.5; margin: 0 0 20px 0;'>{$greeting}</p>
                    <table border='0' cellpadding='0' cellspacing='0' width='100%' style='border: 1px solid #e0e0e0; padding: 15px; background-color: #fafafa;'>
                        <tr>
                            <td style='font-size: calc(0.85rem + 0.2vw); color: #333333;'>{$content}</td>
                        </tr>
                    </table>
                    {$additionalInfo}
                </td>
            </tr>
            <tr>
                <td style='padding: 20px; background-color: #f9f9f9; border-top: 1px solid #e0e0e0; font-size: calc(0.75rem + 0.1vw); color: #666666; text-align: center;'>
                    <p style='margin: 0 0 10px 0;'>Hubungi kami di <a href='mailto:support@schobank.com' style='color: #0c4da2; text-decoration: none;'>support@schobank.com</a> jika ada pertanyaan.</p>
                    <p style='margin: 0;'>© " . date('Y') . " SCHOBANK. Hak cipta dilindungi.</p>
                    <p style='margin: 10px 0 0 0; font-size: calc(0.7rem + 0.1vw); color: #999999;'>Pesan ini dikirim secara otomatis. Mohon tidak membalas pesan ini.</p>
                </td>
            </tr>
        </table>
    </body>
    </html>";
}

// Validate date
function validateDate($date) {
    $inputDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$inputDate) return false;
    $minDate = new DateTime('2000-01-01');
    $currentDate = new DateTime();
    return $inputDate >= $minDate && $inputDate <= $currentDate;
}

// Handle OTP generation and storage
function generateAndStoreOTP($conn, $user_id) {
    $otp = rand(100000, 999999);
    $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $update = $conn->prepare("UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?");
    $update->bind_param("ssi", $otp, $expiry, $user_id);
    if ($update->execute()) {
        return ['otp' => $otp, 'expiry' => $expiry];
    }
    error_log("Failed to store OTP for user $user_id");
    return false;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_username'])) {
        $new_username = trim($_POST['new_username']);
        if (empty($new_username)) {
            header("Location: profil.php?error=" . urlencode("Username tidak boleh kosong!"));
            exit;
        }
        if ($new_username === $user['username']) {
            header("Location: profil.php?error=" . urlencode("Username baru tidak boleh sama!"));
            exit;
        }
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $new_username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("Username sudah digunakan!"));
            exit;
        }
        $update = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $update->bind_param("si", $new_username, $_SESSION['user_id']);
        if ($update->execute()) {
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Username",
                    "Yth. {$user['nama']},",
                    "Username akun SCHOBANK Anda telah diperbarui menjadi: <strong>$new_username</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                if (!sendEmail($user['email'], "Perubahan Username", $email_body)) {
                    header("Location: profil.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                    exit;
                }
            }
            session_destroy();
            header("Location: /schobank/pages/login.php");
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah username!"));
        exit;
    } elseif (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            header("Location: profil.php?error=" . urlencode("Semua field password harus diisi!"));
            exit;
        }
        if ($new_password !== $confirm_password) {
            header("Location: profil.php?error=" . urlencode("Password baru tidak cocok!"));
            exit;
        }
        if (strlen($new_password) < 6) {
            header("Location: profil.php?error=" . urlencode("Password minimal 6 karakter!"));
            exit;
        }
        $verify = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $verify->bind_param("i", $_SESSION['user_id']);
        $verify->execute();
        $user_data = $verify->get_result()->fetch_assoc();
        if (!$user_data || !password_verify($current_password, $user_data['password'])) {
            header("Location: profil.php?error=" . urlencode("Password saat ini salah!"));
            exit;
        }
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed_password, $_SESSION['user_id']);
        if ($update->execute()) {
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Password",
                    "Yth. {$user['nama']},",
                    "Password akun SCHOBANK Anda telah diperbarui.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                if (!sendEmail($user['email'], "Perubahan Password", $email_body)) {
                    header("Location: profil.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                    exit;
                }
            }
            session_destroy();
            header("Location: /schobank/pages/login.php");
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah password!"));
        exit;
    } elseif (isset($_POST['update_pin'])) {
        $new_pin = $_POST['new_pin'];
        $confirm_pin = $_POST['confirm_pin'];
        if (empty($new_pin) || empty($confirm_pin)) {
            header("Location: profil.php?error=" . urlencode("Semua field PIN harus diisi!"));
            exit;
        }
        if ($new_pin !== $confirm_pin) {
            header("Location: profil.php?error=" . urlencode("PIN baru tidak cocok!"));
            exit;
        }
        if (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
            header("Location: profil.php?error=" . urlencode("PIN harus 6 digit angka!"));
            exit;
        }
        if ($user['pin']) {
            $current_pin = $_POST['current_pin'] ?? '';
            if (empty($current_pin) || $current_pin !== $user['pin']) {
                header("Location: profil.php?error=" . urlencode("PIN saat ini salah!"));
                exit;
            }
        }
        $update = $conn->prepare("UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?");
        $update->bind_param("si", $new_pin, $_SESSION['user_id']);
        if ($update->execute()) {
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    $user['pin'] ? "Pemberitahuan Perubahan PIN" : "Pemberitahuan Pembuatan PIN",
                    "Yth. {$user['nama']},",
                    $user['pin'] ? "PIN akun SCHOBANK Anda telah diperbarui." : "PIN akun SCHOBANK Anda telah dibuat.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                if (!sendEmail($user['email'], $user['pin'] ? "Perubahan PIN" : "Pembuatan PIN", $email_body)) {
                    header("Location: profil.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                    exit;
                }
            }
            $_SESSION['success_message'] = $user['pin'] ? "PIN berhasil diubah!" : "PIN berhasil dibuat!";
            header("Location: profil.php");
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah PIN!"));
        exit;
    } elseif (isset($_POST['update_email'])) {
        $email = trim($_POST['email']);
        if (empty($email)) {
            header("Location: profil.php?error=" . urlencode("Email tidak boleh kosong!"));
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: profil.php?error=" . urlencode("Format email tidak valid!"));
            exit;
        }
        if ($email === $user['email']) {
            header("Location: profil.php?error=" . urlencode("Email sama dengan email saat ini!"));
            exit;
        }
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("Email sudah digunakan oleh pengguna lain!"));
            exit;
        }
        $otp_data = generateAndStoreOTP($conn, $_SESSION['user_id']);
        if ($otp_data) {
            $email_body = getEmailTemplate(
                "Verifikasi Perubahan Email",
                "Yth. {$user['nama']},",
                "Kode OTP untuk verifikasi perubahan email Anda adalah: <strong>{$otp_data['otp']}</strong>",
                "<span style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Kode ini berlaku hingga: <strong>" . getIndonesianMonth($otp_data['expiry']) . " " . date('H:i:s', strtotime($otp_data['expiry'])) . " WIB</strong></span>
                <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak meminta perubahan ini, abaikan pesan ini.</p>"
            );
            if (sendEmail($email, "Verifikasi Email", $email_body)) {
                $_SESSION['new_email'] = $email;
                $_SESSION['otp_type'] = 'email';
                header("Location: /schobank/pages/siswa/verify_otp.php");
                exit;
            }
            error_log("Failed to send OTP email to $email");
            header("Location: profil.php?error=" . urlencode("Gagal mengirim OTP!"));
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal menyimpan OTP!"));
        exit;
    } elseif (isset($_POST['update_kelas'])) {
        $new_kelas_id = $_POST['new_kelas_id'];
        if (empty($new_kelas_id)) {
            header("Location: profil.php?error=" . urlencode("Kelas harus dipilih!"));
            exit;
        }
        if ($new_kelas_id == $user['kelas_id']) {
            header("Location: profil.php?error=" . urlencode("Kelas baru tidak boleh sama!"));
            exit;
        }
        $check = $conn->prepare("SELECT id FROM kelas WHERE id = ? AND jurusan_id = ?");
        $check->bind_param("ii", $new_kelas_id, $user['jurusan_id']);
        $check->execute();
        if ($check->get_result()->num_rows == 0) {
            header("Location: profil.php?error=");
            exit;
        }
        $update = $conn->prepare("UPDATE users SET kelas_id = ? WHERE id = ?");
        $update->bind_param("ii", $new_kelas_id, $_SESSION['user_id']);
        if ($update->execute()) {
            $kelas_query = $conn->prepare("SELECT k.nama_kelas, nama_tingkatan AS nama_tingkatan FROM kelas k JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id WHERE k.id = ?");
            $kelas_query->bind_param("i", $new_kelas_id);
            $kelas_query->execute();
            $kelas_data = $kelas_query->get_result()->fetch_assoc();
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Kelas",
                    "Yth. {$user['nama']},",
                    "Kelas Anda telah diperbarui menjadi: <strong>{$kelas_data['nama_tingkatan']} {$kelas_data['nama_kelas']}</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                if (!sendEmail($user['email'], "Perubahan Kelas", $email_body)) {
                    header("Location: profil.php?error=" . urlencode("Gagal mengirim email!"));
                    exit;
                }
            }
            $_SESSION['success_message'] = "Kelas berhasil diubah!";
            header("Location: profil.php");
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah kelas!"));
        exit;
    } elseif (isset($_POST['update_no_wa'])) {
        $new_no_wa = trim($_POST['no_wa']);
        $requires_otp = false;
        $otp_target = null;
        if (!empty($new_no_wa)) {
            if (!preg_match('/^08[0-9]{8,11}$/', $new_no_wa)) {
                header("Location: profil.php?error=" . urlencode("Nomor WhatsApp tidak valid!"));
                exit;
            }
            if ($new_no_wa === $user['no_wa']) {
                header("Location: profil.php?error=" . urlencode("Nomor WhatsApp sama dengan nomor saat ini!"));
                exit;
            }
            $check = $conn->prepare("SELECT id FROM users WHERE no_wa = ? AND id != ?");
            $check->bind_param("si", $new_no_wa, $_SESSION['user_id']);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                header("Location: profil.php?error=" . urlencode("Nomor WhatsApp sudah digunakan oleh pengguna lain!"));
                exit;
            }
            $requires_otp = true;
            $otp_target = $new_no_wa;
        } else {
            if (empty($user['no_wa'])) {
                header("Location: profil.php?error=" . urlencode("Nomor WhatsApp sudah kosong!"));
                exit;
            }
            $requires_otp = true;
            $otp_target = $user['no_wa'];
        }
        if ($requires_otp && $otp_target) {
            $otp_data = generateAndStoreOTP($conn, $_SESSION['user_id']);
            if ($otp_data) {
                $otp_message = "Yth. {$user['nama']},\nKode OTP untuk verifikasi perubahan nomor Anda adalah: *{$otp_data['otp']}*\nBerlaku hingga: " . getIndonesianMonth($otp_data['expiry']) . " " . date('H:i:s', strtotime($otp_data['expiry'])) . " WIB\nJika Anda tidak meminta ini, abaikan pesan.";
                if (sendWhatsAppMessage($otp_target, $otp_message)) {
                    $_SESSION['new_no_wa'] = $new_no_wa;
                    $_SESSION['otp_type'] = 'no_wa';
                    header("Location: /schobank/pages/siswa/verify_otp.php");
                    exit;
                }
                error_log("Failed to send OTP WhatsApp to $otp_target");
                header("Location: profil.php?error=" . urlencode("Gagal mengirim OTP!"));
                exit;
            }
            header("Location: profil.php?error=" . urlencode("Gagal menyimpan OTP!"));
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal memproses nomor WhatsApp!"));
        exit;
    } elseif (isset($_POST['update_tanggal_lahir'])) {
        $tanggal_lahir = trim($_POST['tanggal_lahir']);
        if (empty($tanggal_lahir)) {
            header("Location: profil.php?error=" . urlencode("Tanggal lahir tidak boleh kosong!"));
            exit;
        }
        if (!validateDate($tanggal_lahir)) {
            header("Location: profil.php?error=" . urlencode("Tanggal lahir harus antara 2000 dan hari ini!"));
            exit;
        }
        $update = $conn->prepare("UPDATE users SET tanggal_lahir = ? WHERE id = ?");
        $update->bind_param("si", $tanggal_lahir, $_SESSION['user_id']);
        if ($update->execute()) {
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Tanggal Lahir",
                    "Yth. {$user['nama']},",
                    "Tanggal lahir Anda telah diperbarui: <strong>" . date('d/m/Y', strtotime($tanggal_lahir)) . "</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                if (!sendEmail($user['email'], "Perubahan Tanggal Lahir", $email_body)) {
                    header("Location: profil.php?error=" . urlencode("Gagal mengirim email!"));
                    exit;
                }
            }
            $_SESSION['success_message'] = "Tanggal lahir berhasil diubah!";
            header("Location: profil.php");
            exit;
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah tanggal lahir!"));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Profil Pengguna - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f8fafc;
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }

        .container {
            max-width: 420px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 8px 32px rgba(30, 60, 114, 0.12);
            overflow: hidden;
            border: 1px solid #e5e7eb;
        }

        .profile-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 32px 30px;
            text-align: center;
            position: relative;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 70% 30%, rgba(255,255,255,0.15) 0%, transparent 60%);
        }

        .profile-avatar {
            width: 75px;
            height: 75px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 2;
            border: 2px solid rgba(255, 255, 255, 0.3);
            font-size: 24px;
            font-weight: 700;
            color: white;
            text-transform: uppercase;
        }

        .profile-name {
            color: white;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
            position: relative;
            z-index: 2;
        }

        .profile-status {
            color: rgba(255, 255, 255, 0.85);
            font-size: 14px;
            font-weight: 500;
            position: relative;
            z-index: 2;
        }

        .section {
            padding: 0;
        }

        .section-title {
            background: #1e3c72;
            color: white;
            padding: 16px 30px;
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            letter-spacing: 0.3px;
        }

        .section-title i {
            font-size: 16px;
            opacity: 0.9;
        }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 18px 30px;
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
            cursor: pointer;
            background: #ffffff;
        }

        .menu-item:last-child {
            border-bottom: none;
        }

        .menu-item:hover {
            background: #f8fafc;
        }

        .menu-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 18px;
            box-shadow: 0 4px 16px rgba(30, 60, 114, 0.25);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .menu-item:hover .menu-icon {
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(30, 60, 114, 0.35);
        }

        .menu-icon i {
            color: white;
            font-size: 18px;
        }

        .menu-content {
            flex: 1;
            min-width: 0;
        }

        .menu-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
            font-weight: 500;
        }

        .menu-value {
            font-size: 15px;
            color: #1e293b;
            font-weight: 600;
            line-height: 1.3;
        }

        .menu-value.status-aktif {
            color: #059669;
        }

        .menu-value.status-dibekukan, .menu-value.status-terblokir_sementara {
            color: #ef4444;
        }

        .menu-arrow {
            color: #94a3b8;
            font-size: 14px;
            transition: all 0.2s ease;
            margin-left: 8px;
        }

        .menu-item:hover .menu-arrow {
            color: #1e3c72;
            transform: translateX(2px);
        }

        .status-badge {
            background: #059669;
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .section-divider {
            height: 1px;
            background: #e5e7eb;
            margin: 0;
        }

        .floating-action {
            position: fixed;
            bottom: 25px;
            right: 25px;
            width: 56px;
            height: 56px;
            background: #1e3c72;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 20px rgba(30, 60, 114, 0.25);
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 1000;
        }

        .floating-action:hover {
            transform: scale(1.1);
            background: #2a5298;
            box-shadow: 0 6px 25px rgba(30, 60, 114, 0.35);
        }

        .floating-action i {
            color: white;
            font-size: 20px;
        }

        /* Enhanced Popup Styling */
        .success-overlay, .error-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-out forwards;
            backdrop-filter: blur(8px);
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .success-popup, .error-popup {
            position: relative;
            text-align: center;
            width: clamp(300px, 80vw, 400px);
            border-radius: 24px;
            padding: clamp(30px, 6vw, 40px);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            transform: translateY(20px);
            opacity: 0;
            animation: slideInPopup 0.5s cubic-bezier(0.68, -0.55, 0.27, 1.55) 0.2s forwards;
            overflow: hidden;
            background: white;
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .success-popup {
            background: linear-gradient(145deg, #22c55e 0%, #15803d 100%);
        }

        .error-popup {
            background: linear-gradient(145deg, #ef4444 0%, #b91c1c 100%);
        }

        .success-popup::before, .error-popup::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.25) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes slideInPopup {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .success-popup i, .error-popup i {
            font-size: clamp(3rem, 7vw, 4rem);
            margin-bottom: 20px;
            color: white;
            filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.2));
            animation: iconPulse 1s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .success-popup span, .error-popup span {
            color: white;
            font-size: clamp(0.95rem, 2.5vw, 1.1rem);
            font-weight: 600;
            line-height: 1.5;
            display: block;
            animation: fadeInText 0.6s ease-out 0.3s both;
        }

        @keyframes fadeInText {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Modal Styling */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.65);
            backdrop-filter: blur(8px);
        }

        .modal.active {
            display: block;
        }

        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: zoomIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.8); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: calc(1.2rem + 0.3vw);
            font-weight: 600;
            color: #1e40af;
            display: flex;
            align-items: center;
        }

        .modal-header h3 i {
            margin-right: 8px;
            font-size: calc(1.1rem + 0.3vw);
        }

        .modal-header .close-modal {
            background: none;
            border: none;
            font-size: calc(1.4rem + 0.3vw);
            cursor: pointer;
            color: #6b7280;
            transition: all 0.3s ease;
        }

        .modal-header .close-modal:hover {
            color: #1e40af;
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: calc(0.85rem + 0.2vw);
            font-weight: 500;
            color: #111827;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: none;
            border-radius: 10px;
            font-size: calc(0.85rem + 0.2vw);
            background: #f3f4f6;
            transition: all 0.3s ease;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
            line-height: 1.5;
        }

        .form-group select {
            appearance: none;
            background: #f3f4f6 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="%231e40af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>') no-repeat right 16px center;
            padding-right: 40px;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.2);
        }

        .form-group input[type="date"] {
            background: #f3f4f6 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%231e40af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>') no-repeat right 16px center;
            padding-right: 40px;
        }

        .form-group input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0;
            width: 40px;
            height: 100%;
            position: absolute;
            right: 0;
            cursor: pointer;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            font-size: calc(0.7rem + 0.1vw);
            color: #6b7280;
        }

        .form-group .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 8px;
        }

        .form-group .checkbox-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 8px;
            cursor: pointer;
            accent-color: #1e40af;
        }

        .form-group .checkbox-container label {
            font-size: calc(0.8rem + 0.2vw);
            color: #6b7280;
            cursor: pointer;
        }

        .password-criteria {
            margin-top: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .password-criteria .criterion {
            display: flex;
            align-items: center;
            font-size: calc(0.65rem + 0.2vw);
            color: #6b7280;
        }

        .password-criteria .criterion input[type="checkbox"] {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid #6b7280;
            border-radius: 4px;
            margin-right: 10px;
            position: relative;
            cursor: default;
            flex-shrink: 0;
            background: white;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .password-criteria .criterion input[type="checkbox"]:checked {
            border-color: #22c55e;
            background: #22c55e;
        }

        .password-criteria .criterion input[type="checkbox"]:checked::after {
            content: '\f00c';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: 12px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .btn {
            display: inline-block;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 500;
            font-size: calc(0.9rem + 0.2vw);
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            text-align: center;
            position: relative;
            overflow: hidden;
            min-width: 100px; /* Added to maintain button size */
            min-height: 44px; /* Added to maintain button size */
        }

        .btn-primary {
            background: #1e40af;
            color: white;
        }

        .btn-primary:hover {
            background: #172554;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(29, 78, 216, 0.3);
        }

        .btn-primary.btn-loading {
            pointer-events: none;
        }

        .btn-primary.btn-loading span {
            display: none;
        }

        .btn-primary.btn-loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #ffffff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        @keyframes spin {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }

        .btn-outline {
            background: transparent;
            color: #1e40af;
            border: 2px solid #1e40af;
            min-width: 100px; /* Added to maintain button size */
            min-height: 44px; /* Added to maintain button size */
        }

        .btn-outline:hover {
            background: #dbeafe;
            transform: translateY(-2px);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        @media (max-width: 480px) {
            .container {
                margin: 10px;
                border-radius: 15px;
            }
            
            body {
                padding: 10px;
            }

            .modal-content {
                margin: 15% auto;
                padding: 20px;
                border-radius: 12px;
            }

            .modal-header h3 {
                font-size: calc(1rem + 0.3vw);
            }

            .modal-header h3 i {
                font-size: calc(0.95rem + 0.3vw);
            }

            .modal-header .close-modal {
                font-size: calc(1.2rem + 0.3vw);
            }

            .modal-footer {
                flex-direction: column;
                gap: 8px;
            }

            .btn {
                width: 100%;
                padding: 10px 16px;
                font-size: calc(0.85rem + 0.2vw);
                min-width: 100%; /* Adjusted for mobile */
                min-height: 40px; /* Adjusted for mobile */
            }

            .form-group input, .form-group select {
                padding: 10px 14px;
                font-size: calc(0.8rem + 0.2vw);
            }

            .success-popup, .error-popup {
                width: clamp(280px, 92vw, 350px);
                padding: clamp(20px, 5vw, 30px);
                border-radius: 20px;
            }

            .success-popup i, .error-popup i {
                font-size: clamp(2.5rem, 6vw, 3.2rem);
                margin-bottom: 15px;
            }

            .success-popup span, .error-popup span {
                font-size: clamp(0.85rem, 2.2vw, 0.95rem);
                margin-bottom: 15px;
            }

            .password-criteria .criterion {
                font-size: calc(0.6rem + 0.2vw);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-avatar">
                <?php
                // Generate initials from the user's name
                $name = htmlspecialchars($user['nama']);
                $name_parts = explode(' ', $name);
                $initials = '';
                foreach ($name_parts as $part) {
                    if (!empty($part)) {
                        $initials .= strtoupper($part[0]);
                    }
                    if (strlen($initials) >= 2) break;
                }
                echo $initials;
                ?>
            </div>
            <div class="profile-name"><?= htmlspecialchars($user['nama']) ?></div>
            <div class="profile-status"><?= htmlspecialchars($user['nama_jurusan']) ?></div>
        </div>

        <!-- Personal Information Section -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-info-circle"></i>
                Informasi Pribadi
            </div>
            
            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-id-card"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Nama Lengkap</div>
                    <div class="menu-value"><?= htmlspecialchars($user['nama']) ?></div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Tanggal Lahir</div>
                    <div class="menu-value"><?= $user['tanggal_lahir'] ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : 'Belum diatur' ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="tanggal_lahir"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-palette"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Jurusan</div>
                    <div class="menu-value"><?= htmlspecialchars($user['nama_jurusan']) ?></div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-school"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Tingkatan & Kelas</div>
                    <div class="menu-value"><?= ($user['nama_tingkatan'] && $user['nama_kelas']) ? htmlspecialchars($user['nama_tingkatan'] . ' ' . $user['nama_kelas']) : 'Belum diatur' ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="tingkatan_kelas"></i>
            </div>
        </div>

        <div class="section-divider"></div>

        <!-- Account Settings Section -->
        <div class="section">
            <div class="section-title">
                <i class="fas fa-cog"></i>
                Pengaturan Akun
            </div>
            
            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-at"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Username</div>
                    <div class="menu-value"><?= htmlspecialchars($user['username']) ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="username"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Password</div>
                    <div class="menu-value">••••••</div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="password"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-fingerprint"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">PIN</div>
                    <div class="menu-value"><?= $user['pin'] ? '••••••' : 'Belum diatur' ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="pin"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Email</div>
                    <div class="menu-value"><?= $user['email'] ? htmlspecialchars($user['email']) : 'Belum diatur' ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="email"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fab fa-whatsapp"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Nomor WhatsApp</div>
                    <div class="menu-value"><?= $user['no_wa'] ? htmlspecialchars($user['no_wa']) : 'Belum diatur' ?></div>
                </div>
                <i class="fas fa-edit menu-arrow" data-target="no_wa"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Status Akun</div>
                    <div class="menu-value">
                        <span class="status-badge"><?= ucfirst(str_replace('_', ' ', $account_status)) ?></span>
                    </div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </div>

            <div class="menu-item">
                <div class="menu-icon">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="menu-content">
                    <div class="menu-label">Bergabung Sejak</div>
                    <div class="menu-value"><?= getIndonesianMonth($user['tanggal_bergabung']) ?></div>
                </div>
                <i class="fas fa-chevron-right menu-arrow"></i>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <a href="dashboard.php" title="Kembali ke Dashboard">
        <div class="floating-action">
            <i class="fas fa-xmark"></i>
        </div>
    </a>

    <!-- Modal: Username -->
    <div id="usernameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Ubah Username</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="usernameForm">
                    <div class="form-group">
                        <label for="new_username">Username Baru</label>
                        <input type="text" id="new_username" name="new_username" required placeholder="Masukkan username baru">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_username" class="btn btn-primary" id="usernameBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Email -->
    <div id="emailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Ubah Email</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="emailForm">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required placeholder="Masukkan email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_email" class="btn btn-primary" id="emailBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: WhatsApp Number -->
    <div id="no_waModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fab fa-whatsapp"></i> Ubah Nomor WhatsApp</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="no_waForm">
                    <div class="form-group">
                        <label for="no_wa">Nomor WhatsApp</label>
                        <input type="text" id="no_wa" name="no_wa" placeholder="Contoh: 081234567890" value="<?= htmlspecialchars($user['no_wa'] ?? '') ?>" 
                               pattern="^08[0-9]{8,11}$" maxlength="13" inputmode="numeric">
                        <small>Mulai dengan 08, 10-13 digit. Kosongkan untuk menghapus.</small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_no_wa" class="btn btn-primary" id="no_waBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Tanggal Lahir -->
    <div id="tanggal_lahirModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-day"></i> Ubah Tanggal Lahir</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="tanggalLahirForm">
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir" required 
                               value="<?= $user['tanggal_lahir'] ? htmlspecialchars($user['tanggal_lahir']) : '' ?>"
                               min="2000-01-01" max="<?= date('Y-m-d') ?>">
                        <small>Pilih tanggal (1 Jan 2000 - <?= date('d/m/Y') ?>).</small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_tanggal_lahir" class="btn btn-primary" id="tanggalLahirBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Password -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Ubah Password</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div id="passwordErrorPopup" class="error-overlay" style="display:none">
                    <div class="error-popup">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id=".NODEBUG_MESSAGE"></span>
                    </div>
                </div>
                <form method="POST" id="passwordForm">
                    <div class="form-group">
                        <label for="current_password">Password Saat Ini</label>
                        <input type="password" id="current_password" name="current_password" required placeholder="Password saat ini">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_current_password" data-target="current_password">
                            <label for="show_current_password">Tampilkan</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_password">Password Baru</label>
                        <input type="password" id="new_password" name="new_password" required placeholder="Password baru">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_new_password" data-target="new_password">
                            <label for="show_new_password">Tampilkan</label>
                        </div>
                        <div class="password-criteria">
                            <div class="criterion">
                                <input type="checkbox" id="lengthCriteria" disabled>
                                <label for="lengthCriteria">Minimal 8 karakter</label>
                            </div>
                            <div class="criterion">
                                <input type="checkbox" id="letterCriteria" disabled>
                                <label for="letterCriteria">Mengandung huruf</label>
                            </div>
                            <div class="criterion">
                                <input type="checkbox" id="numberCriteria" disabled>
                                <label for="numberCriteria">Mengandung angka</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Konfirmasi Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_confirm_password" data-target="confirm_password">
                            <label for="show_confirm_password">Tampilkan</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_password" class="btn btn-primary" id="passwordBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: PIN -->
    <div id="pinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Buat/Ubah PIN</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div id="pinErrorPopup" class="error-overlay" style="display:none">
                    <div class="error-popup">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="pinErrorMessage"></span>
                    </div>
                </div>
                <form method="POST" id="pinForm">
                    <?php if ($user['pin']): ?>
                    <div class="form-group">
                        <label for="current_pin">PIN Saat Ini</label>
                        <input type="password" id="current_pin" name="current_pin" required placeholder="PIN saat ini" maxlength="6" inputmode="numeric">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_current_pin" data-target="current_pin">
                            <label for="show_current_pin">Tampilkan</label>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="new_pin">PIN Baru (6 digit)</label>
                        <input type="password" id="new_pin" name="new_pin" required placeholder="PIN baru" maxlength="6" inputmode="numeric">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_new_pin" data-target="new_pin">
                            <label for="show_new_pin">Tampilkan</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Konfirmasi PIN</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN" maxlength="6" inputmode="numeric">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_confirm_pin" data-target="confirm_pin">
                            <label for="show_confirm_pin">Tampilkan</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_pin" class="btn btn-primary" id="pinBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Tingkatan & Kelas -->
    <div id="tingkatan_kelasModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-users"></i> Ubah Tingkatan & Kelas</h3>
                <button type="button" class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div id="tingkatanKelasErrorPopup" class="error-overlay" style="display:none">
                    <div class="error-popup">
                        <i class="fas fa-exclamation-circle"></i>
                        <span id="tingkatanKelasErrorMessage"></span>
                    </div>
                </div>
                <form method="POST" id="tingkatanKelasForm">
                    <div class="form-group">
                        <label for="new_tingkatan_kelas_id">Tingkatan Kelas</label>
                        <select id="new_tingkatan_kelas_id" name="new_tingkatan_kelas_id" required>
                            <option value="">Pilih Tingkatan</option>
                            <?php
                            $tingkatan_query = "SELECT id, nama_tingkatan FROM tingkatan_kelas ORDER BY nama_tingkatan";
                            $tingkatan_result = $conn->query($tingkatan_query);
                            while ($tingkatan = $tingkatan_result->fetch_assoc()) {
                                $selected = $tingkatan['id'] == $user['tingkatan_kelas_id'] ? 'selected' : '';
                                echo "<option value='{$tingkatan['id']}' $selected>" . htmlspecialchars($tingkatan['nama_tingkatan']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="new_kelas_id">Kelas</label>
                        <select id="new_kelas_id" name="new_kelas_id" required>
                            <option value="">Pilih Kelas</option>
                        </select>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_kelas" class="btn btn-primary" id="tingkatanKelasBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal"><span>Batal</span></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Popup -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="success-overlay" id="successPopup">
        <div class="success-popup">
            <i class="fas fa-check-circle"></i>
            <span><?= htmlspecialchars($_SESSION['success_message']) ?></span>
        </div>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Success/Error Popups from PHP variables -->
    <?php if ($message): ?>
        <div class="success-overlay" id="successPopup">
            <div class="success-popup">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="error-overlay" id="errorPopup">
            <div class="error-popup">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <script>
document.addEventListener('touchstart', function(event) {
    if (event.touches.length > 1) {
        event.preventDefault();
    }
}, { passive: false });

document.addEventListener('gesturestart', function(event) {
    event.preventDefault();
});

document.addEventListener('wheel', function(event) {
    if (event.ctrlKey) {
        event.preventDefault();
    }
}, { passive: false });

document.addEventListener('keydown', function(event) {
    if (event.ctrlKey && (event.key === '+' || event.key === '-' || event.key === '0')) {
        event.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Show error popup in modal
    function showErrorPopup(modalId, message, focusInputId) {
        const popup = document.getElementById(modalId + 'ErrorPopup');
        if (popup) {
            popup.querySelector('span').textContent = message;
            popup.style.display = 'flex';
            popup.style.animation = 'fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            setTimeout(() => {
                popup.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                setTimeout(() => {
                    popup.style.display = 'none';
                    popup.style.animation = '';
                    if (focusInputId) document.getElementById(focusInputId).focus();
                }, 400);
            }, 3000);
        }
    }

    // Show success/error popup
    function showPopup(popupId) {
        const popup = document.getElementById(popupId);
        if (popup) {
            popup.style.display = 'flex';
            popup.style.animation = 'fadeInOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
            setTimeout(() => {
                popup.style.animation = 'fadeOutOverlay 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards';
                setTimeout(() => {
                    popup.style.display = 'none';
                    popup.style.animation = '';
                }, 400);
            }, 3000);
        }
    }

    // Reset form
    function resetForm(modalId) {
        const modal = document.getElementById(modalId);
        const form = modal.querySelector('form');
        if (form) {
            form.reset();
            if (modalId === 'tingkatan_kelasModal') {
                const tingkatanSelect = document.getElementById('new_tingkatan_kelas_id');
                const kelasSelect = document.getElementById('new_kelas_id');
                tingkatanSelect.value = '<?php echo $user['tingkatan_kelas_id'] ?? ''; ?>';
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                if (tingkatanSelect.value) {
                    fetchKelas(tingkatanSelect.value);
                } else {
                    kelasSelect.disabled = true;
                }
            }
            // Reset checkbox states and input types
            modal.querySelectorAll('.checkbox-container input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
                const input = document.getElementById(checkbox.dataset.target);
                if (input) input.type = 'password';
            });
            // Reset password criteria checkboxes
            if (modalId === 'passwordModal') {
                ['lengthCriteria', 'letterCriteria', 'numberCriteria'].forEach(id => {
                    const checkbox = document.getElementById(id);
                    if (checkbox) checkbox.checked = false;
                });
            }
        }
    }

    // Fetch kelas based on tingkatan_kelas_id
    function fetchKelas(tingkatanId) {
        const kelasSelect = document.getElementById('new_kelas_id');
        kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
        if (!tingkatanId) {
            kelasSelect.disabled = true;
            return;
        }
        fetch(`get_kelas.php?tingkatan_kelas_id=${tingkatanId}&jurusan_id=${<?php echo $user['jurusan_id'] ?? ''; ?>}`)
            .then(response => {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(data => {
                if (data.length === 0) {
                    kelasSelect.innerHTML = '<option value="">Tidak ada kelas tersedia</option>';
                    kelasSelect.disabled = true;
                } else {
                    data.forEach(kelas => {
                        const option = new Option(kelas.nama_tingkatan + ' ' + kelas.nama_kelas, kelas.id);
                        kelasSelect.appendChild(option);
                    });
                    kelasSelect.disabled = false;
                    // Set selected kelas if available
                    kelasSelect.value = '<?php echo $user['kelas_id'] ?? ''; ?>';
                }
            })
            .catch(error => {
                console.error('Error fetching kelas:', error);
                kelasSelect.innerHTML = '<option value="">Gagal memuat kelas</option>';
                kelasSelect.disabled = true;
                showErrorPopup('tingkatanKelas', 'Gagal memuat kelas!', 'new_kelas_id');
            });
    }

    // Handle pop-ups
    document.querySelectorAll('.success-overlay, .error-overlay').forEach(popup => {
        if (popup.id === 'successPopup' || popup.id === 'errorPopup') {
            showPopup(popup.id);
        }
    });

    // Checkbox show/hide functionality
    document.querySelectorAll('.checkbox-container input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const input = document.getElementById(checkbox.dataset.target);
            if (input) {
                input.type = checkbox.checked ? 'text' : 'password';
                input.focus();
            }
        });
    });

    // Modal handling for edit buttons
    document.querySelectorAll('.menu-arrow[data-target]').forEach(btn => {
        btn.addEventListener('click', () => {
            const modalId = btn.dataset.target + 'Modal';
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'block';
                setTimeout(() => modal.classList.add('active'), 10);
                resetForm(modalId);
                modal.querySelector('.modal-content').focus();
            }
        });
    });

    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            const modal = btn.closest('.modal');
            if (modal) {
                modal.classList.remove('active');
                setTimeout(() => modal.style.display = 'none', 400);
            }
        });
    });

    window.addEventListener('click', e => {
        document.querySelectorAll('.modal').forEach(modal => {
            if (e.target === modal) {
                modal.classList.remove('active');
                setTimeout(() => modal.style.display = 'none', 400);
            }
        });
    });

    // Password strength validation
    const newPasswordInput = document.getElementById('new_password');
    if (newPasswordInput) {
        newPasswordInput.addEventListener('input', () => {
            const password = newPasswordInput.value;
            document.getElementById('lengthCriteria').checked = password.length >= 8;
            document.getElementById('letterCriteria').checked = /[A-Za-z]/.test(password);
            document.getElementById('numberCriteria').checked = /\d/.test(password);
        });
    }

    // Password form validation
    const passwordForm = document.getElementById('passwordForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', e => {
            const current = document.getElementById('current_password').value;
            const newPass = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            const btn = document.getElementById('passwordBtn');
            const passwordRegex = /^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/;

            if (!current || !newPass || !confirm) {
                e.preventDefault();
                showErrorPopup('password', 'Semua field harus diisi!', 'current_password');
                btn.classList.remove('btn-loading');
            } else if (!passwordRegex.test(newPass)) {
                e.preventDefault();
                showErrorPopup('password', 'Password minimal 8 karakter, harus mengandung huruf dan angka!', 'new_password');
                btn.classList.remove('btn-loading');
            } else if (newPass !== confirm) {
                e.preventDefault();
                showErrorPopup('password', 'Password baru dan konfirmasi tidak cocok!', 'confirm_password');
                btn.classList.remove('btn-loading');
            } else {
                btn.classList.add('btn-loading');
            }
        });
    }

    // PIN form validation
    const pinForm = document.getElementById('pinForm');
    if (pinForm) {
        pinForm.addEventListener('submit', e => {
            const current = document.getElementById('current_pin')?.value;
            const newPin = document.getElementById('new_pin').value;
            const confirm = document.getElementById('confirm_pin').value;
            const btn = document.getElementById('pinBtn');
            if (document.getElementById('current_pin') && !current) {
                e.preventDefault();
                showErrorPopup('pin', 'PIN saat ini harus diisi!', 'current_pin');
                btn.classList.remove('btn-loading');
            } else if (!newPin || !confirm) {
                e.preventDefault();
                showErrorPopup('pin', 'Semua field harus diisi!', 'new_pin');
                btn.classList.remove('btn-loading');
            } else if (!/^\d{6}$/.test(newPin)) {
                e.preventDefault();
                showErrorPopup('pin', 'PIN harus 6 digit angka!', 'new_pin');
                btn.classList.remove('btn-loading');
            } else if (newPin !== confirm) {
                e.preventDefault();
                showErrorPopup('pin', 'PIN baru dan konfirmasi tidak cocok!', 'confirm_pin');
                btn.classList.remove('btn-loading');
            } else {
                btn.classList.add('btn-loading');
            }
        });
    }

    // Tingkatan & Kelas form validation
    const tingkatanKelasForm = document.getElementById('tingkatanKelasForm');
    if (tingkatanKelasForm) {
        tingkatanKelasForm.addEventListener('submit', e => {
            const tingkatan = document.getElementById('new_tingkatan_kelas_id').value;
            const kelas = document.getElementById('new_kelas_id').value;
            const btn = document.getElementById('tingkatanKelasBtn');
            if (!tingkatan) {
                e.preventDefault();
                showErrorPopup('tingkatanKelas', 'Pilih tingkatan!', 'new_tingkatan_kelas_id');
                btn.classList.remove('btn-loading');
            } else if (!kelas) {
                e.preventDefault();
                showErrorPopup('tingkatanKelas', 'Pilih kelas!', 'new_kelas_id');
                btn.classList.remove('btn-loading');
            } else {
                btn.classList.add('btn-loading');
            }
        });
    }

    // Form loading state
    ['usernameForm', 'emailForm', 'no_waForm', 'tanggalLahirForm', 'tingkatanKelasForm'].forEach(id => {
        const form = document.getElementById(id);
        if (form) {
            form.addEventListener('submit', () => {
                const btn = document.getElementById(id.replace('Form', 'Btn'));
                if (btn) btn.classList.add('btn-loading');
            });
        }
    });

    // PIN input validation
    ['current_pin', 'new_pin', 'confirm_pin'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', () => {
                input.value = input.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }
    });

    // WhatsApp input validation
    const noWaInput = document.getElementById('no_wa');
    if (noWaInput) {
        noWaInput.addEventListener('input', () => {
            noWaInput.value = noWaInput.value.replace(/[^0-9]/g, '').slice(0, 13);
            if (noWaInput.value && !noWaInput.value.startsWith('08')) {
                noWaInput.value = '08' + noWaInput.value.slice(0, 11);
            }
        });
        noWaInput.addEventListener('keypress', (e) => {
            if (!/[0-9]/.test(e.key)) {
                e.preventDefault();
            }
        });
    }

    // Date input validation
    const tanggalLahirInput = document.getElementById('tanggal_lahir');
    if (tanggalLahirInput) {
        tanggalLahirInput.addEventListener('change', () => {
            const today = new Date().toISOString().split('T')[0];
            if (tanggalLahirInput.value > today) {
                showErrorPopup('tanggalLahir', 'Tanggal lahir tidak boleh di masa depan!', 'tanggal_lahir');
                tanggalLahirInput.value = '';
            } else if (tanggalLahirInput.value < '2000-01-01') {
                showErrorPopup('tanggalLahir', 'Tanggal lahir harus minimal 1 Jan 2000!', 'tanggal_lahir');
                tanggalLahirInput.value = '';
            }
        });
    }

    // Prevent select auto-open on iOS
    ['new_tingkatan_kelas_id', 'new_kelas_id'].forEach(id => {
        const select = document.getElementById(id);
        if (select) {
            select.addEventListener('touchstart', (e) => {
                if (!document.activeElement === select) {
                    e.preventDefault();
                    select.focus();
                }
            });
        }
    });

    // Fetch kelas on tingkatan change
    const tingkatanSelect = document.getElementById('new_tingkatan_kelas_id');
    const kelasSelect = document.getElementById('new_kelas_id');
    if (tingkatanSelect && kelasSelect) {
        tingkatanSelect.addEventListener('change', e => {
            kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
            if (e.target.value) {
                fetchKelas(e.target.value);
            } else {
                kelasSelect.disabled = true;
            }
        });
    }

    // Initialize kelas dropdown on page load
    if (tingkatanSelect && tingkatanSelect.value) {
        fetchKelas(tingkatanSelect.value);
    }
});
    </script>
</body>
</html>