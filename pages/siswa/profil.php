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
$query = "SELECT u.username, u.nama, u.role, u.email, u.no_wa, u.tanggal_lahir, k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan, 
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
            $kelas_query = $conn->prepare("SELECT k.nama_kelas, tk.nama AS nama_tingkatan FROM kelas k JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id WHERE k.id = ?");
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

<!-- HTML remains unchanged as per your request -->
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
                :root {
    --primary-color: #0c4da2;
    --primary-dark: #0a2e5c;
    --primary-light: #e0e9f5;
    --secondary-color: #4caf50;
    --accent-color: #ff9800;
    --danger-color: #f44336;
    --text-primary: #1a1a1a;
    --text-secondary: #666;
    --bg-light: #f8faff;
    --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.08);
    --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.12);
    --transition: all 0.3s ease;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
}

html, body {
    touch-action: manipulation;
    -webkit-text-size-adjust: 100%;
    -webkit-user-select: none;
    user-select: none;
}

body {
    background: linear-gradient(135deg, var(--bg-light), #e8effd);
    color: var(--text-primary);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
    font-size: calc(0.95rem + 0.3vw);
}

.main-content {
    flex: 1;
    padding: 40px 20px;
    width: 100%;
    max-width: 1100px;
    margin: 0 auto;
    position: relative;
    z-index: 1;
}

.welcome-banner {
    background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 40px;
    text-align: center;
    position: relative;
    box-shadow: var(--shadow-md);
    overflow: hidden;
    animation: fadeInDown 0.8s ease-out;
    z-index: 2;
}

.welcome-banner h2 {
    font-size: calc(1.8rem + 0.4vw);
    font-weight: 700;
    margin-bottom: 12px;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.welcome-banner p {
    font-size: calc(1rem + 0.2vw);
    opacity: 0.9;
}

.cancel-btn {
    position: absolute;
    top: 20px;
    left: 20px;
    background: rgba(255, 255, 255, 0.15);
    color: white;
    border: none;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    cursor: pointer;
    font-size: calc(1.1rem + 0.3vw);
    transition: var(--transition);
    text-decoration: none;
}

.cancel-btn:hover {
    background: rgba(255, 255, 255, 0.25);
    transform: scale(1.1);
}

.profile-container {
    display: grid;
    grid-template-columns: 1fr;
    gap: 30px;
}

.profile-card {
    background: white;
    border-radius: 20px;
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
    overflow: hidden;
    animation: fadeInUp 0.6s ease-out;
    position: relative;
}

.profile-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-8px);
}

.profile-header {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
    color: white;
    padding: 20px 25px;
    font-size: calc(1.2rem + 0.3vw);
    font-weight: 600;
    display: flex;
    align-items: center;
}

.profile-header i {
    margin-right: 12px;
    font-size: calc(1.4rem + 0.3vw);
    line-height: 1;
}

.profile-info {
    padding: 25px;
}

.info-item {
    padding: 15px 0;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #f0f2f5;
    position: relative;
    transition: var(--transition);
}

.info-item:last-child {
    border-bottom: none;
}

.info-item:hover {
    background: var(--primary-light);
    border-radius: 10px;
}

.info-item i {
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--primary-light);
    color: var(--primary-color);
    border-radius: 12px;
    margin-right: 20px;
    font-size: calc(1.1rem + 0.2vw);
    flex-shrink: 0;
    line-height: 1;
}

.info-item .text-container {
    flex: 1;
    min-width: 0;
}

.info-item .label {
    font-size: calc(0.85rem + 0.1vw);
    color: var(--text-secondary);
    margin-bottom: 4px;
}

.info-item .value {
    font-size: calc(0.95rem + 0.2vw);
    font-weight: 500;
    color: var(--text-primary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.info-item .value.status-aktif {
    color: var(--secondary-color);
}

.info-item .value.status-dibekukan, .info-item .value.status-terblokir_sementara {
    color: var(--danger-color);
}

.info-item .edit-btn {
    position: absolute;
    right: 15px;
    background: var(--primary-light);
    color: var(--primary-color);
    border: 1px solid var(--primary-color);
    padding: 8px 12px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: calc(0.85rem + 0.1vw);
    transition: var(--transition);
    flex-shrink: 0;
}

.info-item .edit-btn i {
    margin: 0;
    background: none;
    width: auto;
    height: auto;
    font-size: calc(0.95rem + 0.1vw);
    line-height: 1;
}

.info-item .edit-btn:hover {
    background: var(--primary-color);
    color: white;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
}

.modal.active {
    display: block;
}

.modal-content {
    background: white;
    margin: 8% auto;
    padding: 30px;
    border-radius: 20px;
    box-shadow: var(--shadow-md);
    width: 90%;
    max-width: 550px;
    position: relative;
    animation: zoomIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes zoomIn {
    from {
        opacity: 0;
        transform: scale(0.7);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
}

.modal-header h3 {
    margin: 0;
    font-size: calc(1.3rem + 0.3vw);
    font-weight: 600;
    color: var(--primary-color);
    display: flex;
    align-items: center;
}

.modal-header h3 i {
    margin-right: 10px;
    font-size: calc(1.2rem + 0.3vw);
    line-height: 1;
}

.modal-header .close-modal {
    background: none;
    border: none;
    font-size: calc(1.5rem + 0.3vw);
    cursor: pointer;
    color: var(--text-secondary);
    transition: var(--transition);
    line-height: 1;
}

.modal-header .close-modal:hover {
    color: var(--primary-color);
}

.modal-body {
    margin-bottom: 25px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-group {
    margin-bottom: 25px;
    position: relative;
}

.form-group label {
    display: block;
    margin-bottom: 10px;
    font-size: calc(0.9rem + 0.2vw);
    font-weight: 500;
    color: var(--text-primary);
}

.form-group input, .form-group select {
    width: 100%;
    padding: 14px 20px;
    border: none;
    border-radius: 12px;
    font-size: calc(0.95rem + 0.2vw);
    background: #f5f7fa;
    transition: var(--transition);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.05);
}

.form-group select {
    appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 20px center;
    background-size: 18px;
    padding-right: 50px;
}

.form-group input:focus, .form-group select:focus {
    outline: none;
    background: white;
    box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.2);
}

.form-group small {
    display: block;
    margin-top: 8px;
    font-size: calc(0.75rem + 0.1vw);
    color: var(--text-secondary);
}

.form-group .password-toggle {
    position: absolute;
    top: 50%;
    right: 20px;
    transform: translateY(-50%);
    color: var(--text-secondary);
    cursor: pointer;
    font-size: calc(1rem + 0.2vw);
    transition: var(--transition);
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
}

.form-group .password-toggle:hover {
    color: var(--primary-color);
}

.btn {
    display: inline-block;
    padding: 14px 30px;
    border-radius: 12px;
    font-weight: 500;
    font-size: calc(0.95rem + 0.2vw);
    cursor: pointer;
    transition: var(--transition);
    border: none;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.btn-primary {
    background: var(--primary-color);
    color: white;
}

.btn-primary:hover {
    background: var(--primary-dark);
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(12, 77, 162, 0.3);
}

.btn-outline {
    background: transparent;
    color: var(--primary-color);
    border: 2px solid var(--primary-color);
}

.btn-outline:hover {
    background: var(--primary-light);
    transform: translateY(-3px);
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

.btn-loading {
    position: relative;
    pointer-events: none;
}

.btn-loading span {
    visibility: hidden;
}

.btn-loading::after {
    content: "";
    position: absolute;
    top: 50%;
    left: 50%;
    width: 24px;
    height: 24px;
    margin: -12px 0 0 -12px;
    border: 4px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: rotate 0.8s linear infinite;
}

.success-popup, .error-popup {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #d1fae5;
    color: #065f46;
    padding: 15px 25px;
    border-radius: 12px;
    box-shadow: var(--shadow-md);
    display: none;
    align-items: center;
    z-index: 600;
    max-width: 400px;
    width: 90%;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.error-popup {
    background: #fee2e2;
    color: #991b1b;
    border-left: 6px solid #f87171;
}

.success-popup i, .error-popup i {
    margin-right: 10px;
    font-size: calc(1.2rem + 0.2vw);
    line-height: 1;
    flex-shrink: 0;
}

.success-popup span, .error-popup span {
    font-size: calc(0.9rem + 0.2vw);
    line-height: 1.5;
}

@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-30px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes rotate {
    to { transform: rotate(360deg); }
}

@media (max-width: 768px) {
    .main-content {
        padding: 20px 10px;
    }

    .welcome-banner {
        padding: 30px 20px;
    }

    .welcome-banner h2 {
        font-size: calc(1.4rem + 0.4vw);
    }

    .welcome-banner p {
        font-size: calc(0.9rem + 0.2vw);
    }

    .cancel-btn {
        width: 40px;
        height: 40px;
        font-size: calc(1rem + 0.2vw);
    }

    .profile-header {
        font-size: calc(1rem + 0.3vw);
        padding: 15px 20px;
    }

    .profile-header i {
        font-size: calc(1.2rem + 0.3vw);
    }

    .profile-info {
        padding: 15px;
    }

    .info-item {
        padding: 10px 0;
    }

    .info-item i {
        width: 40px;
        height: 40px;
        margin-right: 15px;
        font-size: calc(1rem + 0.2vw);
    }

    .info-item .label {
        font-size: calc(0.75rem + 0.1vw);
    }

    .info-item .value {
        font-size: calc(0.85rem + 0.2vw);
    }

    .info-item .edit-btn {
        padding: 6px 10px;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
    }

    .info-item .edit-btn i {
        font-size: calc(0.85rem + 0.1vw);
    }

    .modal-content {
        margin: 20% auto;
        padding: 20px;
    }

    .modal-header h3 {
        font-size: calc(1.1rem + 0.3vw);
    }

    .modal-header h3 i {
        font-size: calc(1rem + 0.3vw);
    }

    .modal-header .close-modal {
        font-size: calc(1.3rem + 0.3vw);
    }

    .modal-footer {
        flex-direction: column;
    }

    .btn {
        width: 100%;
        padding: 12px 20px;
    }

    .form-group input, .form-group select {
        padding: 12px 15px;
        font-size: calc(0.85rem + 0.2vw);
    }

    .form-group .password-toggle {
        right: 15px;
        font-size: calc(0.9rem + 0.2vw);
        width: 20px;
        height: 20px;
    }

    .form-group select {
        background-position: right 15px center;
        background-size: 16px;
        padding-right: 40px;
    }

    .success-popup, .error-popup {
        max-width: 90%;
        padding: 12px 20px;
    }

    .success-popup i, .error-popup i {
        font-size: calc(1rem + 0.2vw);
    }

    .success-popup span, .error-popup span {
        font-size: calc(0.8rem + 0.2vw);
    }
}
    </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2>Profil Pengguna</h2>
            <p>Kelola informasi dan pengaturan akun Anda</p>
            <a href="dashboard.php" title="Kembali ke Dashboard">
                <span class="cancel-btn"><i class="fas fa-xmark"></i></span>
            </a>
        </div>

        <?php if ($message): ?>
            <div id="successPopup" class="success-popup">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div id="errorPopup" class="error-popup">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="profile-container">
            <div class="profile-card">
                <div class="profile-header">
                    <i class="fas fa-user-circle"></i> Informasi Pribadi
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <div class="text-container">
                            <div class="label">Nama Lengkap</div>
                            <div class="value"><?= htmlspecialchars($user['nama']) ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="text-container">
                            <div class="label">Tanggal Lahir</div>
                            <div class="value"><?= $user['tanggal_lahir'] ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : 'Belum diatur' ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="tanggal_lahir">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-graduation-cap"></i>
                        <div class="text-container">
                            <div class="label">Jurusan</div>
                            <div class="value"><?= htmlspecialchars($user['nama_jurusan']) ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <div class="text-container">
                            <div class="label">Tingkatan & Kelas</div>
                            <div class="value"><?= ($user['nama_tingkatan'] && $user['nama_kelas']) ? htmlspecialchars($user['nama_tingkatan'] . ' ' . $user['nama_kelas']) : 'Belum diatur' ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="tingkatan_kelas">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                </div>

                <div class="profile-header">
                    <i class="fas fa-cog"></i> Pengaturan Akun
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <i class="fas fa-user-tag"></i>
                        <div class="text-container">
                            <div class="label">Username</div>
                            <div class="value"><?= htmlspecialchars($user['username']) ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="username">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-lock"></i>
                        <div class="text-container">
                            <div class="label">Password</div>
                            <div class="value">••••••</div>
                        </div>
                        <button type="button" class="edit-btn" data-target="password">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-key"></i>
                        <div class="text-container">
                            <div class="label">PIN</div>
                            <div class="value"><?= $user['pin'] ? '••••••' : 'Belum diatur' ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="pin">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-envelope"></i>
                        <div class="text-container">
                            <div class="label">Email</div>
                            <div class="value"><?= $user['email'] ? htmlspecialchars($user['email']) : 'Belum diatur' ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="email">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fab fa-whatsapp"></i>
                        <div class="text-container">
                            <div class="label">Nomor WhatsApp</div>
                            <div class="value"><?= $user['no_wa'] ? htmlspecialchars($user['no_wa']) : 'Belum diatur' ?></div>
                        </div>
                        <button type="button" class="edit-btn" data-target="no_wa">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-user-shield"></i>
                        <div class="text-container">
                            <div class="label">Status Akun</div>
                            <div class="value status-<?= strtolower($account_status) ?>"><?= ucfirst(str_replace('_', ' ', $account_status)) ?></div>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="text-container">
                            <div class="label">Bergabung Sejak</div>
                            <div class="value"><?= getIndonesianMonth($user['tanggal_bergabung']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
                <div id="passwordErrorPopup" class="error-popup" style="display:none">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="passwordErrorMessage"></span>
                </div>
                <form method="POST" id="passwordForm">
                    <div class="form-group">
                        <label for="current_password">Password Saat Ini</label>
                        <input type="password" id="current_password" name="current_password" required placeholder="Password saat ini">
                        <i class="fas fa-eye password-toggle" data-target="current_password"></i>
                    </div>
                    <div class="form-group">
                        <label for="new_password">Password Baru</label>
                        <input type="password" id="new_password" name="new_password" required placeholder="Password baru">
                        <i class="fas fa-eye password-toggle" data-target="new_password"></i>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Konfirmasi Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password">
                        <i class="fas fa-eye password-toggle" data-target="confirm_password"></i>
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
                <div id="pinErrorPopup" class="error-popup" style="display:none">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="pinErrorMessage"></span>
                </div>
                <form method="POST" id="pinForm">
                    <?php if ($user['pin']): ?>
                    <div class="form-group">
                        <label for="current_pin">PIN Saat Ini</label>
                        <input type="number" id="current_pin" name="current_pin" required placeholder="PIN saat ini" maxlength="6" inputmode="numeric">
                        <i class="fas fa-eye password-toggle" data-target="current_pin"></i>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="new_pin">PIN Baru (6 digit)</label>
                        <input type="number" id="new_pin" name="new_pin" required placeholder="PIN baru" maxlength="6" inputmode="numeric">
                        <i class="fas fa-eye password-toggle" data-target="new_pin"></i>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Konfirmasi PIN</label>
                        <input type="number" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN" maxlength="6" inputmode="numeric">
                        <i class="fas fa-eye password-toggle" data-target="confirm_pin"></i>
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
                <div id="tingkatanKelasErrorPopup" class="error-popup" style="display:none">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="tingkatanKelasErrorMessage"></span>
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
    <div id="successPopup" class="success-popup">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($_SESSION['success_message']) ?></span>
    </div>
    <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <script>
        // Prevent zooming on mobile and desktop
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
                popup.querySelector('span').textContent = message;
                popup.style.display = 'flex';
                setTimeout(() => popup.style.opacity = '1', 10);
                setTimeout(() => {
                    popup.style.opacity = '0';
                    setTimeout(() => {
                        popup.style.display = 'none';
                        if (focusInputId) document.getElementById(focusInputId).focus();
                    }, 300);
                }, 3000);
            }

            // Reset form
            function resetForm(modalId) {
                const modal = document.getElementById(modalId);
                const form = modal.querySelector('form');
                form.reset();
                if (modalId === 'tingkatan_kelasModal') {
                    const tingkatanSelect = document.getElementById('new_tingkatan_kelas_id');
                    const kelasSelect = document.getElementById('new_kelas_id');
                    tingkatanSelect.value = '<?php echo $user['tingkatan_kelas_id']; ?>';
                    kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                    fetchKelas(tingkatanSelect.value);
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
                fetch(`get_kelas.php?tingkatan_kelas_id=${tingkatanId}&jurusan_id=<?php echo $user['jurusan_id']; ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.length === 0) {
                            kelasSelect.innerHTML = '<option value="">Tidak ada kelas ada</option>';
                            kelasSelect.disabled = true;
                        } else {
                            data.forEach(kelas => {
                                const option = new Option(kelas.nama_tingkatan + ' ' + kelas.nama_kelas, kelas.id);
                                kelasSelect.appendChild(option);
                            });
                            kelasSelect.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        kelasSelect.innerHTML = '<option value="">Tidak ada kelas ada</option>';
                        kelasSelect.disabled = true;
                        showErrorPopup('tingkatanKelas', 'Gagal memuat kelas!', 'new_kelas_id');
                    });
            }

            // Handle pop-ups
            document.querySelectorAll('.success-popup, .error-popup').forEach(popup => {
                popup.style.display = 'flex';
                setTimeout(() => popup.style.opacity = '1', 10);
                setTimeout(() => {
                    popup.style.opacity = '0';
                    setTimeout(() => popup.style.display = 'none', 300);
                }, 3000);
            });

            // Password/PIN toggle
            document.querySelectorAll('.password-toggle').forEach(toggle => {
                toggle.addEventListener('click', () => {
                    const input = document.getElementById(toggle.dataset.target);
                    input.type = input.type === 'number' || input.type === 'password' ? 'text' : input.type === 'text' ? 'number' : 'password';
                    toggle.classList.toggle('fa-eye');
                    toggle.classList.toggle('fa-eye-slash');
                    input.focus();
                });
            });

            // Modal handling
            document.querySelectorAll('.edit-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = document.getElementById(btn.dataset.target + 'Modal');
                    modal.style.display = 'block';
                    setTimeout(() => modal.classList.add('active'), 10);
                    resetForm(btn.dataset.target + 'Modal');
                    // FIX: Prevent auto-focus on inputs, focus modal container
                    modal.querySelector('.modal-content').focus();
                });
            });

            document.querySelectorAll('.close-modal').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    modal.classList.remove('active');
                    setTimeout(() => modal.style.display = 'none', 400);
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

            // Password form validation
            document.getElementById('passwordForm').addEventListener('submit', e => {
                const current = document.getElementById('current_password').value;
                const newPass = document.getElementById('new_password').value;
                const confirm = document.getElementById('confirm_password').value;
                const btn = document.getElementById('passwordBtn');
                if (!current || !newPass || !confirm) {
                    e.preventDefault();
                    showErrorPopup('password', 'Semua field harus diisi!', 'current_password');
                    btn.classList.remove('btn-loading');
                } else if (newPass.length < 6) {
                    e.preventDefault();
                    showErrorPopup('password', 'Password minimal 6 karakter!', 'new_password');
                    btn.classList.remove('btn-loading');
                } else if (newPass !== confirm) {
                    e.preventDefault();
                    showErrorPopup('password', 'Password tidak cocok!', 'confirm_password');
                    btn.classList.remove('btn-loading');
                } else {
                    btn.classList.add('btn-loading');
                }
            });

            // PIN form validation
            document.getElementById('pinForm').addEventListener('submit', e => {
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
                    showErrorPopup('pin', 'PIN tidak cocok!', 'confirm_pin');
                    btn.classList.remove('btn-loading');
                } else {
                    btn.classList.add('btn-loading');
                }
            });

            // Tingkatan & Kelas form validation
            document.getElementById('tingkatanKelasForm').addEventListener('submit', e => {
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

            // Form loading state
            ['usernameForm', 'emailForm', 'no_waForm', 'tanggalLahirForm', 'tingkatanKelasForm'].forEach(id => {
                const form = document.getElementById(id);
                if (form) {
                    form.addEventListener('submit', () => {
                        document.getElementById(id.replace('Form', 'Btn')).classList.add('btn-loading');
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
                        noWaInput.value = '08' + noWaInput.value;
                    }
                });
                noWaInput.addEventListener('keypress', (e) => {
                    if (!/[0-9]/.test(e.key)) {
                        e.preventDefault();
                    }
                });
            }

            // FIX: Date input validation to match mutasi.php
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

            // FIX: Prevent select auto-open on iOS
            ['new_tingkatan_kelas_id', 'new_kelas_id'].forEach(id => {
                const select = document.getElementById(id);
                if (select) {
                    select.addEventListener('touchstart', (e) => {
                        if (!select.hasFocus) {
                            e.preventDefault();
                            select.focus();
                        }
                    });
                }
            });

            // Fetch kelas on tingkatan change
            document.getElementById('new_tingkatan_kelas_id').addEventListener('change', e => {
                fetchKelas(e.target.value);
            });

            // Initialize kelas dropdown on page load
            if (document.getElementById('new_tingkatan_kelas_id').value) {
                fetchKelas(document.getElementById('new_tingEQId').value);
            }
        });
    </script>
</body>
</html>