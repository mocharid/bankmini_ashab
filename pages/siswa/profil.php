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

// Fetch user data with edit tracking
$query = "SELECT u.username, u.nama, u.role, u.email, u.no_wa, u.tanggal_lahir, u.jenis_kelamin, u.alamat_lengkap, u.nis_nisn,
          k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan, r.no_rekening, r.saldo, u.created_at AS tanggal_bergabung,
          u.pin, u.jurusan_id, u.kelas_id, u.is_frozen, u.pin_block_until, k.tingkatan_kelas_id,
          u.tanggal_lahir_edited, u.jenis_kelamin_edited, u.nis_nisn_edited
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

// Determine account status function
function getAccountStatus($user_data, $current_time) {
    if ($user_data['is_frozen']) {
        return 'dibekukan';
    }
    if ($user_data['pin_block_until'] && $user_data['pin_block_until'] > $current_time) {
        return 'terblokir-sementara';
    }
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
        $mail->Username = 'myschobank@gmail.com';
        $mail->Password = 'xpni zzju utfu mkth';
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
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Insert notification function
function insertNotification($conn, $user_id, $message) {
    $query = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}

// Get Indonesian month name function
function getIndonesianMonth($date) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return date('d', strtotime($date)) . ' ' . $months[(int)date('m', strtotime($date))] . ' ' . date('Y', strtotime($date));
}

// Email template function
function getEmailTemplate($title, $greeting, $content, $additionalInfo = '') {
    return "<!DOCTYPE html>
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

// Validate date function
function validateDate($date) {
    $inputDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$inputDate) {
        return false;
    }
    $minDate = new DateTime('1950-01-01');
    $currentDate = new DateTime();
    return ($inputDate >= $minDate && $inputDate <= $currentDate);
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
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_username'])) {
        $new_username = trim($_POST['new_username']);
       
        if (empty($new_username)) {
            header("Location: profil.php?error=" . urlencode("Username tidak boleh kosong!"));
            exit();
        }
       
        if ($new_username == $user['username']) {
            header("Location: profil.php?error=" . urlencode("Username baru tidak boleh sama!"));
            exit();
        }
       
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->bind_param("si", $new_username, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("Username sudah digunakan!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $update->bind_param("si", $new_username, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "Username kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Username",
                    "Yth. {$user['nama']},",
                    "Username akun SCHOBANK Anda telah diperbarui menjadi <strong>{$new_username}</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Username", $email_body);
            }
            session_destroy();
            header("Location: ../../pages/login.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah username!"));
        exit();
       
    } elseif (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
       
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            header("Location: profil.php?error=" . urlencode("Semua field password harus diisi!"));
            exit();
        }
       
        if ($new_password != $confirm_password) {
            header("Location: profil.php?error=" . urlencode("Password baru tidak cocok!"));
            exit();
        }
       
        if (strlen($new_password) < 8) {
            header("Location: profil.php?error=" . urlencode("Password minimal 8 karakter!"));
            exit();
        }
       
        if (!preg_match('/[A-Za-z]/', $new_password)) {
            header("Location: profil.php?error=" . urlencode("Password harus mengandung huruf!"));
            exit();
        }
       
        $verify = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $verify->bind_param("i", $_SESSION['user_id']);
        $verify->execute();
        $user_data = $verify->get_result()->fetch_assoc();
       
        if (!$user_data || !password_verify($current_password, $user_data['password'])) {
            header("Location: profil.php?error=" . urlencode("Password saat ini salah!"));
            exit();
        }
       
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed_password, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "Password kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Password",
                    "Yth. {$user['nama']},",
                    "Password akun SCHOBANK Anda telah diperbarui.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Password", $email_body);
            }
            session_destroy();
            header("Location: ../../pages/login.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah password!"));
        exit();
       
    } elseif (isset($_POST['update_pin'])) {
        $new_pin = $_POST['new_pin'];
        $confirm_pin = $_POST['confirm_pin'];
       
        if (empty($new_pin) || empty($confirm_pin)) {
            header("Location: profil.php?error=" . urlencode("Semua field PIN harus diisi!"));
            exit();
        }
       
        if ($new_pin != $confirm_pin) {
            header("Location: profil.php?error=" . urlencode("PIN baru tidak cocok!"));
            exit();
        }
       
        if (strlen($new_pin) != 6 || !ctype_digit($new_pin)) {
            header("Location: profil.php?error=" . urlencode("PIN harus 6 digit angka!"));
            exit();
        }
       
        $hashed_new_pin = hash('sha256', $new_pin);
       
        if ($user['pin']) {
            $current_pin = $_POST['current_pin'] ?? '';
            if (empty($current_pin) || strlen($current_pin) != 6 || !ctype_digit($current_pin)) {
                header("Location: profil.php?error=" . urlencode("PIN saat ini tidak valid!"));
                exit();
            }
           
            $hashed_current_pin = hash('sha256', $current_pin);
            if ($hashed_current_pin != $user['pin']) {
                header("Location: profil.php?error=" . urlencode("PIN saat ini salah!"));
                exit();
            }
        }
       
        $update = $conn->prepare("UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?");
        $update->bind_param("si", $hashed_new_pin, $_SESSION['user_id']);
        if ($update->execute()) {
            $action = $user['pin'] ? 'diubah' : 'dibuat';
            $notif_message = "PIN kamu telah {$action} pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    $user['pin'] ? "Pemberitahuan Perubahan PIN" : "Pemberitahuan Pembuatan PIN",
                    "Yth. {$user['nama']},",
                    $user['pin'] ? "PIN akun SCHOBANK Anda telah diperbarui." : "PIN akun SCHOBANK Anda telah dibuat.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], $user['pin'] ? "Perubahan PIN" : "Pembuatan PIN", $email_body);
            }
            $_SESSION['success_message'] = $user['pin'] ? "PIN berhasil diubah!" : "PIN berhasil dibuat!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah PIN!"));
        exit();
       
    } elseif (isset($_POST['update_email'])) {
        $email = trim($_POST['email']);
       
        if (empty($email)) {
            header("Location: profil.php?error=" . urlencode("Email tidak boleh kosong!"));
            exit();
        }
       
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: profil.php?error=" . urlencode("Format email tidak valid!"));
            exit();
        }
       
        if ($email == $user['email']) {
            header("Location: profil.php?error=" . urlencode("Email sama dengan email saat ini!"));
            exit();
        }
       
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("Email sudah digunakan oleh pengguna lain!"));
            exit();
        }
       
        $otp_data = generateAndStoreOTP($conn, $_SESSION['user_id']);
        if ($otp_data) {
            $email_body = getEmailTemplate(
                "Verifikasi Perubahan Email",
                "Yth. {$user['nama']},",
                "Kode OTP untuk verifikasi perubahan email Anda adalah <strong>{$otp_data['otp']}</strong>",
                "<span style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Kode ini berlaku hingga <strong>" . getIndonesianMonth($otp_data['expiry']) . ", " . date('H:i:s', strtotime($otp_data['expiry'])) . " WIB</strong></span>
                <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak meminta perubahan ini, abaikan pesan ini.</p>"
            );
           
            if (sendEmail($email, "Verifikasi Email", $email_body)) {
                $_SESSION['new_email'] = $email;
                $_SESSION['otp_type'] = 'email';
                header("Location: ../../pages/siswa/verify_otp.php");
                exit();
            }
            error_log("Failed to send OTP email to $email");
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengirim OTP!"));
        exit();
       
    } elseif (isset($_POST['update_alamat'])) {
        $alamat = trim($_POST['alamat_lengkap']);
       
        if (empty($alamat)) {
            header("Location: profil.php?error=" . urlencode("Alamat tidak boleh kosong!"));
            exit();
        }
       
        if ($alamat == $user['alamat_lengkap']) {
            header("Location: profil.php?error=" . urlencode("Alamat sama dengan saat ini!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET alamat_lengkap = ? WHERE id = ?");
        $update->bind_param("si", $alamat, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "Alamat kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Alamat",
                    "Yth. {$user['nama']},",
                    "Alamat lengkap Anda telah diperbarui.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Alamat Baru: <strong>" . htmlspecialchars($alamat) . "</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Alamat", $email_body);
            }
            $_SESSION['success_message'] = "Alamat berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah alamat!"));
        exit();
       
    } elseif (isset($_POST['update_tanggal_lahir'])) {
        // Check if already edited
        if ($user['tanggal_lahir_edited'] == 1) {
            header("Location: profil.php?error=" . urlencode("Tanggal lahir hanya bisa diubah sekali!"));
            exit();
        }
        
        $tanggal_lahir = trim($_POST['tanggal_lahir']);
       
        if (empty($tanggal_lahir)) {
            header("Location: profil.php?error=" . urlencode("Tanggal lahir tidak boleh kosong!"));
            exit();
        }
       
        if (!validateDate($tanggal_lahir)) {
            header("Location: profil.php?error=" . urlencode("Format tanggal tidak valid!"));
            exit();
        }
       
        if ($tanggal_lahir == $user['tanggal_lahir']) {
            header("Location: profil.php?error=" . urlencode("Tanggal lahir sama dengan saat ini!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET tanggal_lahir = ?, tanggal_lahir_edited = 1 WHERE id = ?");
        $update->bind_param("si", $tanggal_lahir, $_SESSION['user_id']);
        if ($update->execute()) {
            $formatted_date = date('d/m/Y', strtotime($tanggal_lahir));
            $notif_message = "Tanggal lahir kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Tanggal Lahir",
                    "Yth. {$user['nama']},",
                    "Tanggal lahir Anda telah diperbarui menjadi <strong>" . getIndonesianMonth($tanggal_lahir) . "</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Tanggal Lahir", $email_body);
            }
            $_SESSION['success_message'] = "Tanggal lahir berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah tanggal lahir!"));
        exit();
       
    } elseif (isset($_POST['update_jenis_kelamin'])) {
        // Check if already edited
        if ($user['jenis_kelamin_edited'] == 1) {
            header("Location: profil.php?error=" . urlencode("Jenis kelamin hanya bisa diubah sekali!"));
            exit();
        }
        
        $jenis_kelamin = trim($_POST['jenis_kelamin']);
       
        if (empty($jenis_kelamin) || !in_array($jenis_kelamin, ['L', 'P'])) {
            header("Location: profil.php?error=" . urlencode("Jenis kelamin tidak valid!"));
            exit();
        }
       
        if ($jenis_kelamin == $user['jenis_kelamin']) {
            header("Location: profil.php?error=" . urlencode("Jenis kelamin sama dengan saat ini!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET jenis_kelamin = ?, jenis_kelamin_edited = 1 WHERE id = ?");
        $update->bind_param("si", $jenis_kelamin, $_SESSION['user_id']);
        if ($update->execute()) {
            $jk_text = $jenis_kelamin == 'L' ? 'Laki-laki' : 'Perempuan';
            $notif_message = "Jenis kelamin kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Jenis Kelamin",
                    "Yth. {$user['nama']},",
                    "Jenis kelamin Anda telah diperbarui menjadi <strong>{$jk_text}</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Jenis Kelamin", $email_body);
            }
            $_SESSION['success_message'] = "Jenis kelamin berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah jenis kelamin!"));
        exit();
        
    } elseif (isset($_POST['update_nisn'])) {
        // Check if already edited
        if ($user['nis_nisn_edited'] == 1) {
            header("Location: profil.php?error=" . urlencode("NIS/NISN hanya bisa diubah sekali!"));
            exit();
        }
        
        $nisn = trim($_POST['nis_nisn']);
       
        if (empty($nisn)) {
            header("Location: profil.php?error=" . urlencode("NIS/NISN tidak boleh kosong!"));
            exit();
        }
       
        if ($nisn == $user['nis_nisn']) {
            header("Location: profil.php?error=" . urlencode("NIS/NISN sama dengan saat ini!"));
            exit();
        }
       
        $check = $conn->prepare("SELECT id FROM users WHERE nis_nisn = ? AND id != ?");
        $check->bind_param("si", $nisn, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("NIS/NISN sudah digunakan oleh pengguna lain!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET nis_nisn = ?, nis_nisn_edited = 1 WHERE id = ?");
        $update->bind_param("si", $nisn, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "NIS/NISN kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan NIS/NISN",
                    "Yth. {$user['nama']},",
                    "NIS/NISN Anda telah diperbarui menjadi <strong>" . htmlspecialchars($nisn) . "</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan NIS/NISN", $email_body);
            }
            $_SESSION['success_message'] = "NIS/NISN berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah NIS/NISN!"));
        exit();
        
    } elseif (isset($_POST['update_kelas'])) {
        $new_kelas_id = $_POST['new_kelas_id'];
       
        if (empty($new_kelas_id)) {
            header("Location: profil.php?error=" . urlencode("Kelas harus dipilih!"));
            exit();
        }
       
        if ($new_kelas_id == $user['kelas_id']) {
            header("Location: profil.php?error=" . urlencode("Kelas baru tidak boleh sama!"));
            exit();
        }
       
        $check = $conn->prepare("SELECT id FROM kelas WHERE id = ? AND jurusan_id = ?");
        $check->bind_param("ii", $new_kelas_id, $user['jurusan_id']);
        $check->execute();
        if ($check->get_result()->num_rows == 0) {
            header("Location: profil.php?error=" . urlencode("Kelas tidak valid!"));
            exit();
        }
       
        $update = $conn->prepare("UPDATE users SET kelas_id = ? WHERE id = ?");
        $update->bind_param("ii", $new_kelas_id, $_SESSION['user_id']);
        if ($update->execute()) {
            $kelas_query = $conn->prepare("SELECT k.nama_kelas, tk.nama_tingkatan AS nama_tingkatan
                                           FROM kelas k
                                           JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                                           WHERE k.id = ?");
            $kelas_query->bind_param("i", $new_kelas_id);
            $kelas_query->execute();
            $kelas_data = $kelas_query->get_result()->fetch_assoc();
           
            $notif_message = "Kelas kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);
           
            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Kelas",
                    "Yth. {$user['nama']},",
                    "Kelas Anda telah diperbarui menjadi <strong>{$kelas_data['nama_tingkatan']} {$kelas_data['nama_kelas']}</strong>.",
                    "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . ", " . date('H:i:s') . " WIB</strong></p>
                    <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>"
                );
                sendEmail($user['email'], "Perubahan Kelas", $email_body);
            }
            $_SESSION['success_message'] = "Kelas berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah kelas!"));
        exit();
       
    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header("Location: ../../pages/login.php");
        exit();
    }
}

function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, '.', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Profil - SCHOBANK</title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --success: #00D084;
            --danger: #FF3B30;
            --warning: #FFB020;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 80px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        
        /* iOS Date Input Fixes */
        input[type="date"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            font-size: 16px !important; /* Prevent zoom on iOS */
            min-height: 44px; /* iOS tap target */
        }
        
        /* iOS Safari specific */
        @supports (-webkit-touch-callout: none) {
            input[type="date"] {
                display: block;
                position: relative;
                padding: 12px 16px;
                background: var(--white);
                border: 1px solid var(--gray-200);
                border-radius: var(--radius);
                font-family: 'Poppins', sans-serif;
            }
            
            input[type="date"]::-webkit-date-and-time-value {
                text-align: left;
            }
            
            input[type="date"]::-webkit-calendar-picker-indicator {
                position: absolute;
                right: 12px;
                opacity: 0.6;
            }
        }
        
        /* Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        
        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--white);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            text-decoration: none;
        }
        
        .nav-btn:hover {
            background: var(--white);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
            transform: scale(1.05);
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }
        
        /* Page Header */
        .page-header {
            margin-bottom: 32px;
            text-align: center;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: var(--elegant-dark);
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        
        .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
        }
        
        /* Profile Container */
        .profile-container {
            background: transparent;
            border-radius: 0;
            margin-bottom: 24px;
        }
        
        .section {
            padding: 0;
            margin-bottom: 32px;
        }
        
        .section-title {
            background: transparent;
            color: var(--gray-900);
            padding: 0 0 12px 0;
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid var(--gray-200);
            text-transform: none;
            letter-spacing: -0.3px;
            margin-bottom: 16px;
        }
        
        .section-title i {
            font-size: 18px;
            color: var(--elegant-dark);
            flex-shrink: 0;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-200);
            transition: background-color 0.2s ease;
            cursor: pointer;
            position: relative;
            background: var(--white);
            border-radius: var(--radius);
            margin-bottom: 8px;
        }
        
        .menu-item:last-child {
            border-bottom: none;
        }
        
        .menu-item:hover {
            background: var(--gray-50);
        }
        
        .menu-item.logout-item {
            background: #fee2e2;
            cursor: pointer;
        }
        
        .menu-item.logout-item:hover {
            background: #fecaca;
        }
        
        .menu-icon {
            width: 40px;
            height: 40px;
            background: var(--gray-100);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 16px;
            flex-shrink: 0;
        }
        
        .menu-icon i {
            color: var(--gray-600);
            font-size: 16px;
        }
        
        .menu-content {
            flex: 1;
            min-width: 0;
            overflow: hidden;
        }
        
        .menu-label {
            font-size: 12px;
            color: var(--gray-500);
            margin-bottom: 2px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        .menu-value {
            font-size: 14px;
            color: var(--gray-900);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            word-break: break-word;
            flex-wrap: wrap;
        }
        
        .menu-value.status-aktif {
            color: var(--success);
        }
        
        .menu-value.status-dibekukan,
        .menu-value.status-terblokir-sementara {
            color: var(--danger);
        }
        
        .menu-value.copyable {
            cursor: pointer;
            transition: opacity 0.2s ease;
        }
        
        .menu-value.copyable:hover {
            opacity: 0.8;
        }
        
        .menu-arrow {
            color: var(--gray-500);
            font-size: 14px;
            transition: color 0.2s ease;
            flex-shrink: 0;
            margin-left: 8px;
        }
        
        .menu-item:hover .menu-arrow {
            color: var(--gray-900);
        }
        
        .menu-arrow.hidden {
            display: none;
        }
        
        .status-badge {
            background: var(--success);
            color: var(--white);
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge.dibekukan,
        .status-badge.terblokir-sementara {
            background: var(--danger);
        }
        
        .copy-icon {
            font-size: 14px;
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        
        .menu-value:hover .copy-icon {
            opacity: 1;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal.active {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--white);
            margin: 1rem;
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 420px;
            position: relative;
            animation: zoomIn 0.3s ease-out;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        @keyframes zoomIn {
            from {
                opacity: 0;
                transform: scale(0.95);
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
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            flex: 1;
        }
        
        .modal-header h3 i {
            margin-right: 8px;
            font-size: 16px;
            color: var(--gray-600);
        }
        
        .modal-header .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray-500);
            padding: 0;
            line-height: 1;
            flex-shrink: 0;
        }
        
        .modal-header .close-modal:hover {
            color: var(--gray-900);
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }
        
        .modal-footer-single {
            display: flex;
            justify-content: center;
            padding-top: 12px;
            border-top: 1px solid var(--gray-200);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 14px;
            transition: all 0.2s ease;
            background: var(--white);
            font-family: 'Poppins', sans-serif;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
            outline: none;
        }
        
        .form-group select {
            appearance: none;
            background: var(--white) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'></polyline></svg>") no-repeat right 12px center / 12px 12px;
            padding-right: 40px;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group small {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            color: var(--gray-500);
        }
        
        .form-group .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 8px;
        }
        
        .form-group .checkbox-container input[type="checkbox"] {
            width: 16px;
            height: 16px;
            margin-right: 8px;
            accent-color: var(--elegant-dark);
        }
        
        .form-group .checkbox-container label {
            font-size: 13px;
            color: var(--gray-600);
            cursor: pointer;
            margin: 0;
            font-weight: 400;
        }
        
        .password-requirements {
            margin-top: 8px;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            font-size: 12px;
            color: var(--gray-600);
            margin-bottom: 4px;
        }
        
        .requirement-icon {
            margin-right: 8px;
            font-size: 10px;
            transition: color 0.2s ease;
            width: 12px;
            text-align: center;
        }
        
        .requirement-fulfilled .requirement-icon {
            color: var(--success);
        }
        
        .requirement-unfulfilled .requirement-icon {
            color: var(--danger);
        }
        
        .pin-match-label {
            font-size: 12px;
            margin-top: 4px;
            text-align: center;
            transition: color 0.3s ease;
            font-weight: 600;
        }
        
        .pin-match-unmatched {
            color: var(--danger);
        }
        
        .pin-match-matched {
            color: var(--success);
        }
        
        /* Buttons */
        .btn {
            padding: 12px 20px;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            min-width: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        
        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }
        
        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-1px);
        }
        
        .btn-primary.btn-loading span {
            display: none;
        }
        
        .btn-primary.btn-loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--white);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.8s linear infinite;
            margin-left: 0;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .btn-outline {
            background: transparent;
            color: var(--elegant-dark);
            border: 1px solid var(--gray-300);
        }
        
        .btn-outline:hover {
            background: var(--gray-100);
            transform: translateY(-1px);
        }
        
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-top: none;
            padding: 12px 20px 20px;
            display: flex;
            justify-content: space-around;
            z-index: 100;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray-400);
            transition: all 0.2s ease;
            padding: 8px 16px;
        }
        
        .nav-item i {
            font-size: 22px;
            transition: color 0.2s ease;
        }
        
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        
        .nav-item:hover i,
        .nav-item:hover span,
        .nav-item.active i,
        .nav-item.active span {
            color: var(--elegant-dark);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            
            .menu-item {
                padding: 14px 16px;
            }
            
            .section-title {
                padding: 0 0 10px 0;
            }
            
            .menu-icon {
                width: 36px;
                height: 36px;
                margin-right: 12px;
            }
            
            .modal-content {
                margin: 1rem;
                width: calc(100% - 2rem);
                max-width: none;
            }
            
            .profile-avatar {
                width: 64px;
                height: 64px;
                font-size: 24px;
            }
            
            .page-title {
                font-size: 20px;
            }
            
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="/schobank/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>

    <!-- Main Container -->
    <div class="container">
        <?php if ($error): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '<?php echo addslashes($error); ?>',
                    confirmButtonText: 'OK'
                });
            </script>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success_message'])): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: '<?php echo addslashes($_SESSION['success_message']); ?>',
                    confirmButtonText: 'OK'
                });
            </script>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: '<?php echo addslashes($message); ?>',
                    confirmButtonText: 'OK'
                });
            </script>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div class="profile-avatar">
                <?php
                $name = htmlspecialchars($user['nama']);
                $name_parts = explode(' ', $name);
                $initials = '';
                foreach ($name_parts as $part) {
                    if (!empty($part)) {
                        $initials .= strtoupper($part[0]);
                        if (strlen($initials) >= 2) break;
                    }
                }
                echo $initials;
                ?>
            </div>
            <h1 class="page-title"><?php echo htmlspecialchars($user['nama']); ?></h1>
            <p class="page-subtitle"><?php echo htmlspecialchars($user['nama_jurusan']); ?></p>
        </div>

        <div class="profile-container">
            <!-- Personal Information Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-user"></i> Informasi Pribadi
                </div>
               
                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Tanggal Lahir</div>
                        <div class="menu-value"><?php echo $user['tanggal_lahir'] ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : 'Belum diatur'; ?></div>
                    </div>
                    <?php if ($user['tanggal_lahir_edited'] != 1): ?>
                        <i class="fas fa-edit menu-arrow" data-target="tanggalLahirModal"></i>
                    <?php endif; ?>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-venus-mars"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Jenis Kelamin</div>
                        <div class="menu-value"><?php echo $user['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($user['jenis_kelamin'] == 'P' ? 'Perempuan' : 'Belum diatur'); ?></div>
                    </div>
                    <?php if ($user['jenis_kelamin_edited'] != 1): ?>
                        <i class="fas fa-edit menu-arrow" data-target="jenisKelaminModal"></i>
                    <?php endif; ?>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">NIS/NISN</div>
                        <div class="menu-value"><?php echo htmlspecialchars($user['nis_nisn'] ?? 'Belum diatur'); ?></div>
                    </div>
                    <?php if ($user['nis_nisn_edited'] != 1): ?>
                        <i class="fas fa-edit menu-arrow" data-target="nisnModal"></i>
                    <?php endif; ?>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Alamat Lengkap</div>
                        <div class="menu-value"><?php echo htmlspecialchars($user['alamat_lengkap'] ?? 'Belum diatur'); ?></div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="alamatModal"></i>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Tingkatan Kelas</div>
                        <div class="menu-value"><?php echo $user['nama_tingkatan'] && $user['nama_kelas'] ? htmlspecialchars($user['nama_tingkatan'] . ' ' . $user['nama_kelas']) : 'Belum diatur'; ?></div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="tingkatankelasModal"></i>
                </div>
            </div>

            <!-- Rekening Information Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-credit-card"></i> Informasi Rekening
                </div>
               
                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-university"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Nomor Rekening</div>
                        <div class="menu-value copyable" title="Click to copy" data-copy="<?php echo $user['no_rekening']; ?>">
                            <span><?php echo $user['no_rekening'] ? htmlspecialchars($user['no_rekening']) : 'Belum diatur'; ?></span>
                            <?php if ($user['no_rekening']): ?>
                                <i class="fas fa-copy copy-icon"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Saldo Tersedia</div>
                        <div class="menu-value"><?php echo formatRupiah($user['saldo'] ?? 0); ?></div>
                    </div>
                </div>
            </div>

            <!-- Account Settings Section -->
            <div class="section">
                <div class="section-title">
                    <i class="fas fa-cog"></i> Pengaturan Akun
                </div>
               
                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-at"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Username</div>
                        <div class="menu-value copyable" title="Click to copy" data-copy="<?php echo htmlspecialchars($user['username']); ?>">
                            <span><?php echo htmlspecialchars($user['username']); ?></span>
                            <i class="fas fa-copy copy-icon"></i>
                        </div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="usernameModal"></i>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Password</div>
                        <div class="menu-value">••••••••</div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="passwordModal"></i>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-fingerprint"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">PIN</div>
                        <div class="menu-value"><?php echo $user['pin'] ? '••••••' : 'Belum diatur'; ?></div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="pinModal"></i>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Email</div>
                        <div class="menu-value"><?php echo $user['email'] ? htmlspecialchars($user['email']) : 'Belum diatur'; ?></div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="emailModal"></i>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Status Akun</div>
                        <div class="menu-value">
                            <span class="status-badge <?php echo $account_status; ?>">
                                <?php echo ucfirst(str_replace('-', ' ', $account_status)); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Bergabung Sejak</div>
                        <div class="menu-value"><?php echo getIndonesianMonth($user['tanggal_bergabung']); ?></div>
                    </div>
                </div>

                <!-- Logout Item -->
                <form method="POST" id="logoutForm" style="display: none;">
                    <input type="hidden" name="logout">
                </form>
                <div class="menu-item logout-item" onclick="confirmLogout()">
                    <div class="menu-icon">
                        <i class="fas fa-sign-out-alt" style="color: var(--danger);"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Logout</div>
                        <div class="menu-value" style="color: var(--danger);">Keluar dari akun</div>
                    </div>
                    <i class="fas fa-chevron-right menu-arrow" style="color: var(--danger);"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Beranda</span>
        </a>
        <a href="cek_mutasi.php" class="nav-item">
            <i class="fas fa-list-alt"></i>
            <span>Mutasi</span>
        </a>
        <a href="aktivitas.php" class="nav-item">
            <i class="fas fa-history"></i>
            <span>Aktivitas</span>
        </a>
        <a href="profil.php" class="nav-item active">
            <i class="fas fa-user"></i>
            <span>Profil</span>
        </a>
    </div>

    <!-- Modal Username -->
    <div id="usernameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Ubah Username</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="usernameForm">
                    <div class="form-group">
                        <label for="new_username">Username Baru</label>
                        <input type="text" id="new_username" name="new_username" required placeholder="Masukkan username baru">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_username" class="btn btn-primary" id="usernameBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Email -->
    <div id="emailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Ubah Email</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="emailForm">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required placeholder="Masukkan email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_email" class="btn btn-primary" id="emailBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Password -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Ubah Password</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
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
                        <div class="password-requirements" id="passwordRequirements">
                            <div class="requirement-item requirement-unfulfilled">
                                <i class="fas fa-times requirement-icon"></i>
                                <span>Minimal 8 karakter</span>
                            </div>
                            <div class="requirement-item requirement-unfulfilled">
                                <i class="fas fa-times requirement-icon"></i>
                                <span>Mengandung huruf</span>
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
                        <div class="pin-match-label pin-match-unmatched" id="confirmMatchLabel" style="display:none;">Tidak cocok</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_password" class="btn btn-primary" id="passwordBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal PIN -->
    <div id="pinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> <?php echo $user['pin'] ? 'Ubah' : 'Buat'; ?> PIN</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
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
                        <div class="password-requirements" id="pinRequirements">
                            <div class="requirement-item requirement-unfulfilled">
                                <i class="fas fa-times requirement-icon"></i>
                                <span>Minimal 6 digit angka</span>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Konfirmasi PIN</label>
                        <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN" maxlength="6" inputmode="numeric">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_confirm_pin" data-target="confirm_pin">
                            <label for="show_confirm_pin">Tampilkan</label>
                        </div>
                        <div class="pin-match-label pin-match-unmatched" id="pinMatchLabel" style="display:none;">Tidak cocok</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_pin" class="btn btn-primary" id="pinBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Alamat -->
    <div id="alamatModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-marker-alt"></i> Ubah Alamat</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="alamatForm">
                    <div class="form-group">
                        <label for="alamat_lengkap">Alamat Lengkap</label>
                        <textarea id="alamat_lengkap" name="alamat_lengkap" required rows="3" placeholder="Masukkan alamat lengkap"><?php echo htmlspecialchars($user['alamat_lengkap'] ?? ''); ?></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_alamat" class="btn btn-primary" id="alamatBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Tanggal Lahir -->
    <div id="tanggalLahirModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-alt"></i> Ubah Tanggal Lahir</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="tanggalLahirForm">
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir</label>
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir" required value="<?php echo htmlspecialchars($user['tanggal_lahir'] ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" min="1950-01-01">
                        <small>Pilih tanggal lahir Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_tanggal_lahir" class="btn btn-primary" id="tanggalLahirBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Jenis Kelamin -->
    <div id="jenisKelaminModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-venus-mars"></i> Ubah Jenis Kelamin</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="jenisKelaminForm">
                    <div class="form-group">
                        <label for="jenis_kelamin">Jenis Kelamin</label>
                        <select id="jenis_kelamin" name="jenis_kelamin" required>
                            <option value="">Pilih Jenis Kelamin</option>
                            <option value="L" <?php echo ($user['jenis_kelamin'] == 'L') ? 'selected' : ''; ?>>Laki-laki</option>
                            <option value="P" <?php echo ($user['jenis_kelamin'] == 'P') ? 'selected' : ''; ?>>Perempuan</option>
                        </select>
                        <small>Pilih jenis kelamin Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_jenis_kelamin" class="btn btn-primary" id="jenisKelaminBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal NISN -->
    <div id="nisnModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-id-card"></i> Ubah NIS/NISN</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="nisnForm">
                    <div class="form-group">
                        <label for="nis_nisn">NIS/NISN</label>
                        <input type="text" id="nis_nisn" name="nis_nisn" required placeholder="Masukkan NIS/NISN" value="<?php echo htmlspecialchars($user['nis_nisn'] ?? ''); ?>">
                        <small>Masukkan NIS atau NISN Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_nisn" class="btn btn-primary" id="nisnBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Tingkatan Kelas -->
    <div id="tingkatankelasModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-users"></i> Ubah Tingkatan Kelas</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p style="color: var(--gray-600); font-size: 13px; margin-bottom: 16px; font-style: italic;">Fitur ini hanya untuk ketika naik kelas atau pindah kelas.</p>
                <form method="POST" id="tingkatanKelasForm">
                    <div class="form-group">
                        <label for="new_tingkatan_kelas_id">Tingkatan Kelas</label>
                        <select id="new_tingkatan_kelas_id" name="new_tingkatan_kelas_id" required>
                            <option value="">Pilih Tingkatan</option>
                            <?php
                            $tingkatan_query = "SELECT id, nama_tingkatan FROM tingkatan_kelas ORDER BY nama_tingkatan";
                            $tingkatan_result = $conn->query($tingkatan_query);
                            while ($tingkatan = $tingkatan_result->fetch_assoc()) {
                                $selected = ($tingkatan['id'] == $user['tingkatan_kelas_id']) ? 'selected' : '';
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
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Logout confirmation
        function confirmLogout() {
            Swal.fire({
                title: 'Logout',
                text: 'Apakah Anda yakin ingin keluar?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#FF3B30',
                cancelButtonColor: '#6B7280',
                confirmButtonText: 'Ya, Logout',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('logoutForm').submit();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Copy functionality
            document.querySelectorAll('.copyable').forEach(el => {
                el.addEventListener('click', (e) => {
                    if (e.target.classList.contains('copy-icon')) {
                        e.stopPropagation();
                    }
                    const text = el.dataset.copy || el.querySelector('span')?.textContent;
                    if (text && text != 'Belum diatur') {
                        navigator.clipboard.writeText(text).then(() => {
                            const original = el.querySelector('span')?.textContent;
                            if (original) {
                                el.querySelector('span').textContent = 'Copied!';
                                setTimeout(() => {
                                    el.querySelector('span').textContent = original;
                                }, 2000);
                            }
                        }).catch(err => {
                            console.error('Failed to copy: ', err);
                        });
                    }
                });
            });

            // Reset form function
            function resetForm(modalId) {
                const modal = document.getElementById(modalId);
                const form = modal.querySelector('form');
                if (form) {
                    form.reset();
                }
               
                if (modalId === 'tingkatankelasModal') {
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
               
                if (modalId === 'alamatModal') {
                    const alamatInput = document.getElementById('alamat_lengkap');
                    if (alamatInput) {
                        alamatInput.value = '<?php echo htmlspecialchars($user['alamat_lengkap'] ?? ''); ?>';
                    }
                }
               
                if (modalId === 'tanggalLahirModal') {
                    const tanggalInput = document.getElementById('tanggal_lahir');
                    if (tanggalInput) {
                        tanggalInput.value = '<?php echo htmlspecialchars($user['tanggal_lahir'] ?? ''); ?>';
                    }
                }
               
                if (modalId === 'nisnModal') {
                    const nisnInput = document.getElementById('nis_nisn');
                    if (nisnInput) {
                        nisnInput.value = '<?php echo htmlspecialchars($user['nis_nisn'] ?? ''); ?>';
                    }
                }
                
                if (modalId === 'jenisKelaminModal') {
                    const jkSelect = document.getElementById('jenis_kelamin');
                    if (jkSelect) {
                        jkSelect.value = '<?php echo htmlspecialchars($user['jenis_kelamin'] ?? ''); ?>';
                    }
                }
               
                modal.querySelectorAll('.checkbox-container input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                    const input = document.getElementById(checkbox.dataset.target);
                    if (input) {
                        input.type = 'password';
                    }
                });
               
                if (modalId === 'passwordModal') {
                    const requirements = document.getElementById('passwordRequirements').querySelectorAll('.requirement-item');
                    requirements.forEach(item => {
                        item.className = 'requirement-item requirement-unfulfilled';
                        item.querySelector('.requirement-icon').className = 'fas fa-times requirement-icon';
                    });
                    const confirmMatchLabel = document.getElementById('confirmMatchLabel');
                    if (confirmMatchLabel) {
                        confirmMatchLabel.style.display = 'none';
                        confirmMatchLabel.className = 'pin-match-label pin-match-unmatched';
                        confirmMatchLabel.textContent = 'Tidak cocok';
                    }
                }
               
                if (modalId === 'pinModal') {
                    const requirements = document.getElementById('pinRequirements').querySelectorAll('.requirement-item');
                    requirements.forEach(item => {
                        item.className = 'requirement-item requirement-unfulfilled';
                        item.querySelector('.requirement-icon').className = 'fas fa-times requirement-icon';
                    });
                    const pinMatchLabel = document.getElementById('pinMatchLabel');
                    if (pinMatchLabel) {
                        pinMatchLabel.style.display = 'none';
                        pinMatchLabel.className = 'pin-match-label pin-match-unmatched';
                        pinMatchLabel.textContent = 'Tidak cocok';
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
               
                fetch(`get_kelas.php?tingkatan_kelas_id=${tingkatanId}&jurusan_id=<?php echo $user['jurusan_id'] ?? ''; ?>`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.length === 0) {
                            kelasSelect.innerHTML = '<option value="">Tidak ada kelas tersedia</option>';
                            kelasSelect.disabled = true;
                        } else {
                            data.forEach(kelas => {
                                const option = new Option(`${kelas.nama_tingkatan} ${kelas.nama_kelas}`, kelas.id);
                                kelasSelect.appendChild(option);
                            });
                            kelasSelect.disabled = false;
                            kelasSelect.value = '<?php echo $user['kelas_id'] ?? ''; ?>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching kelas:', error);
                        kelasSelect.innerHTML = '<option value="">Gagal memuat kelas</option>';
                        kelasSelect.disabled = true;
                    });
            }

            // Checkbox show/hide functionality
            document.querySelectorAll('.checkbox-container input[type="checkbox"]').forEach(checkbox => {
                checkbox.addEventListener('change', () => {
                    const input = document.getElementById(checkbox.dataset.target);
                    if (input) {
                        input.type = checkbox.checked ? 'text' : 'password';
                    }
                });
            });

            // Modal handling
            document.querySelectorAll('.menu-arrow[data-target]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modalId = btn.dataset.target;
                    const modal = document.getElementById(modalId);
                    if (modal) {
                        modal.style.display = 'flex';
                        modal.classList.add('active');
                        resetForm(modalId);
                    }
                });
            });

            document.querySelectorAll('.close-modal').forEach(btn => {
                btn.addEventListener('click', () => {
                    const modal = btn.closest('.modal');
                    if (modal) {
                        modal.classList.remove('active');
                        modal.style.display = 'none';
                    }
                });
            });

            window.addEventListener('click', (e) => {
                if (e.target.classList.contains('modal')) {
                    e.target.classList.remove('active');
                    e.target.style.display = 'none';
                }
            });

            // Password requirements check
            const newPasswordInput = document.getElementById('new_password');
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', () => {
                    const password = newPasswordInput.value;
                    const requirements = document.getElementById('passwordRequirements').querySelectorAll('.requirement-item');
                    const lengthReq = requirements[0];
                    const letterReq = requirements[1];
                   
                    if (password.length >= 8) {
                        lengthReq.className = 'requirement-item requirement-fulfilled';
                        lengthReq.querySelector('.requirement-icon').className = 'fas fa-check requirement-icon';
                    } else {
                        lengthReq.className = 'requirement-item requirement-unfulfilled';
                        lengthReq.querySelector('.requirement-icon').className = 'fas fa-times requirement-icon';
                    }
                   
                    if (/[A-Za-z]/.test(password)) {
                        letterReq.className = 'requirement-item requirement-fulfilled';
                        letterReq.querySelector('.requirement-icon').className = 'fas fa-check requirement-icon';
                    } else {
                        letterReq.className = 'requirement-item requirement-unfulfilled';
                        letterReq.querySelector('.requirement-icon').className = 'fas fa-times requirement-icon';
                    }
                   
                    const confirmPass = document.getElementById('confirm_password').value;
                    const confirmMatchLabel = document.getElementById('confirmMatchLabel');
                    if (password && confirmPass) {
                        confirmMatchLabel.style.display = 'block';
                        if (password === confirmPass) {
                            confirmMatchLabel.className = 'pin-match-label pin-match-matched';
                            confirmMatchLabel.textContent = 'Cocok';
                        } else {
                            confirmMatchLabel.className = 'pin-match-label pin-match-unmatched';
                            confirmMatchLabel.textContent = 'Tidak cocok';
                        }
                    } else {
                        confirmMatchLabel.style.display = 'none';
                    }
                });
            }

            // PIN requirements check
            const newPinInput = document.getElementById('new_pin');
            if (newPinInput) {
                newPinInput.addEventListener('input', () => {
                    const pin = newPinInput.value.replace(/[^0-9]/g, '');
                    newPinInput.value = pin.slice(0, 6);
                   
                    const requirements = document.getElementById('pinRequirements').querySelectorAll('.requirement-item');
                    const lengthReq = requirements[0];
                   
                    if (pin.length === 6) {
                        lengthReq.className = 'requirement-item requirement-fulfilled';
                        lengthReq.querySelector('.requirement-icon').className = 'fas fa-check requirement-icon';
                    } else {
                        lengthReq.className = 'requirement-item requirement-unfulfilled';
                        lengthReq.querySelector('.requirement-icon').className = 'fas fa-times requirement-icon';
                    }
                   
                    const confirmPin = document.getElementById('confirm_pin').value;
                    const pinMatchLabel = document.getElementById('pinMatchLabel');
                    if (pin && confirmPin) {
                        pinMatchLabel.style.display = 'block';
                        if (pin === confirmPin) {
                            pinMatchLabel.className = 'pin-match-label pin-match-matched';
                            pinMatchLabel.textContent = 'Cocok';
                        } else {
                            pinMatchLabel.className = 'pin-match-label pin-match-unmatched';
                            pinMatchLabel.textContent = 'Tidak cocok';
                        }
                    } else {
                        pinMatchLabel.style.display = 'none';
                    }
                });
            }

            // PIN confirm match
            const confirmPinInput = document.getElementById('confirm_pin');
            if (confirmPinInput) {
                confirmPinInput.addEventListener('input', () => {
                    const confirmPin = confirmPinInput.value.replace(/[^0-9]/g, '');
                    confirmPinInput.value = confirmPin.slice(0, 6);
                   
                    const newPin = document.getElementById('new_pin').value;
                    const pinMatchLabel = document.getElementById('pinMatchLabel');
                    if (newPin && confirmPin) {
                        pinMatchLabel.style.display = 'block';
                        if (newPin === confirmPin) {
                            pinMatchLabel.className = 'pin-match-label pin-match-matched';
                            pinMatchLabel.textContent = 'Cocok';
                        } else {
                            pinMatchLabel.className = 'pin-match-label pin-match-unmatched';
                            pinMatchLabel.textContent = 'Tidak cocok';
                        }
                    } else {
                        pinMatchLabel.style.display = 'none';
                    }
                });
            }

            // Confirm password match
            const confirmPasswordInput = document.getElementById('confirm_password');
            if (confirmPasswordInput) {
                confirmPasswordInput.addEventListener('input', (e) => {
                    const newPass = document.getElementById('new_password').value;
                    const confirmPass = confirmPasswordInput.value;
                    const confirmMatchLabel = document.getElementById('confirmMatchLabel');
                    if (newPass && confirmPass) {
                        confirmMatchLabel.style.display = 'block';
                        if (newPass === confirmPass) {
                            confirmMatchLabel.className = 'pin-match-label pin-match-matched';
                            confirmMatchLabel.textContent = 'Cocok';
                        } else {
                            confirmMatchLabel.className = 'pin-match-label pin-match-unmatched';
                            confirmMatchLabel.textContent = 'Tidak cocok';
                        }
                    } else {
                        confirmMatchLabel.style.display = 'none';
                    }
                });
            }

            // Form validations
            ['passwordForm', 'pinForm', 'tingkatanKelasForm', 'usernameForm', 'emailForm', 'alamatForm', 'tanggalLahirForm', 'nisnForm', 'jenisKelaminForm'].forEach(id => {
                const form = document.getElementById(id);
                if (form) {
                    form.addEventListener('submit', () => {
                        const btn = document.getElementById(id.replace('Form', 'Btn'));
                        if (btn) {
                            btn.classList.add('btn-loading');
                        }
                    });
                }
            });

            // Input validations for PIN fields
            ['current_pin', 'new_pin', 'confirm_pin'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', () => {
                        input.value = input.value.replace(/[^0-9]/g, '').slice(0, 6);
                    });
                }
            });

            // Tingkatan change
            const tingkatanSelect = document.getElementById('new_tingkatan_kelas_id');
            if (tingkatanSelect) {
                tingkatanSelect.addEventListener('change', (e) => {
                    const kelasSelect = document.getElementById('new_kelas_id');
                    kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                    if (e.target.value) {
                        fetchKelas(e.target.value);
                    } else {
                        kelasSelect.disabled = true;
                    }
                });
               
                if (tingkatanSelect.value) {
                    fetchKelas(tingkatanSelect.value);
                }
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>
