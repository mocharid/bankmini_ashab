<?php
/**
 * Profile Page - Adaptive Path Version (BCRYPT + Normalized DB)
 * File: pages/siswa/profil.php
 *
 * Compatible with:
 * - Local: localhost/schobank/pages/siswa/profil.php
 * - Hosting: domain.com/pages/siswa/profil.php
 */
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages/siswa'
if (basename($current_dir) === 'siswa' && basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 3: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 4: naik max 5 level cari includes/
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
// Fallback: pakai current dir
if (!$project_root) {
    $project_root = $current_dir;
}
// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// DEFINE WEB BASE URL (for browser access)
// ============================================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];

    // Remove filename and get directory
    $base_path = dirname($script);

    // Remove '/pages/siswa' if exists
    $base_path = preg_replace('#/pages/siswa$#', '', $base_path);

    // Ensure base_path starts with /
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }

    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}
// Asset URLs for browser
define('ASSETS_URL', BASE_URL . '/assets');
// ============================================
// START SESSION & LOAD DB + VENDOR
// ============================================
session_start();
require_once INCLUDES_PATH . '/db_connection.php';
require_once PROJECT_ROOT . '/vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// Initialize message variables
$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
// ============================================
// FETCH USER DATA WITH NORMALIZED DB
// ============================================
$query = "SELECT
    u.username, u.nama, u.role, u.email, u.created_at AS tanggal_bergabung,
    sp.nis_nisn, sp.nis_nisn_edited, sp.tanggal_lahir, sp.tanggal_lahir_edited,
    sp.jenis_kelamin, sp.jenis_kelamin_edited, sp.alamat_lengkap, sp.jurusan_id, sp.kelas_id, sp.avatar,
    us.pin, us.has_pin, us.is_frozen, us.pin_block_until,
    k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan,
    r.no_rekening, r.saldo
FROM users u
LEFT JOIN siswa_profiles sp ON u.id = sp.user_id
LEFT JOIN user_security us ON u.id = us.user_id
LEFT JOIN rekening r ON u.id = r.user_id
LEFT JOIN kelas k ON sp.kelas_id = k.id
LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
LEFT JOIN jurusan j ON sp.jurusan_id = j.id
WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) {
    die("Data pengguna tidak ditemukan.");
}
// Determine account status function
function getAccountStatus($user_data, $current_time)
{
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
function sendEmail($email, $subject, $body)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'mail.kasdig.web.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@kasdig.web.id';
        $mail->Password = 'BtRjT4wP8qeTL5M';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('noreply@kasdig.web.id', 'KASDIG SYSTEM');
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
function insertNotification($conn, $user_id, $message)
{
    $query = "INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}
// Get Indonesian month name function
function getIndonesianMonth($date)
{
    $months = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];
    return date('d', strtotime($date)) . ' ' . $months[(int) date('m', strtotime($date))] . ' ' . date('Y', strtotime($date));
}
// Email template function (Updated to match proses_setor style)
function getEmailTemplate($title, $content, $additionalInfo = '')
{
    return "
<div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
  
    <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>{$title}</h2>
  
    <p style='margin-bottom: 20px;'>{$content}</p>
  
    {$additionalInfo}
  
    <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
  
    <p style='font-size: 12px; color: #999;'>
        Ini adalah pesan otomatis dari sistem KASDIG.<br>
        Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
    </p>
</div>";
}
// Validate date function
function validateDate($date)
{
    $inputDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$inputDate) {
        return false;
    }
    $minDate = new DateTime('1950-01-01');
    $currentDate = new DateTime();
    return ($inputDate >= $minDate && $inputDate <= $currentDate);
}
// Handle OTP generation and storage (in siswa_profiles)
function generateAndStoreOTP($conn, $user_id)
{
    $otp = rand(100000, 999999);
    $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
    $update = $conn->prepare("UPDATE siswa_profiles SET otp = ?, otp_expiry = ? WHERE user_id = ?");
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

        // Check if user has email for OTP verification
        if (empty($user['email'])) {
            header("Location: profil.php?error=" . urlencode("Anda harus memiliki email untuk mengubah username!"));
            exit();
        }

        $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->bind_param("si", $new_username, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("Username sudah digunakan!"));
            exit();
        }

        // Generate and send OTP to current email
        $otp_data = generateAndStoreOTP($conn, $_SESSION['user_id']);
        if ($otp_data) {
            $email_body = getEmailTemplate(
                "Verifikasi Perubahan Username",
                "Berikut OTP kamu:<br><br><div style='text-align:center; font-size:32px; font-weight:bold; letter-spacing:8px; color:#1e3a8a; margin:20px 0;'>{$otp_data['otp']}</div><br>Kode ini berlaku hingga " . getIndonesianMonth($otp_data['expiry']) . ", " . date('H:i:s', strtotime($otp_data['expiry'])) . " WIB.<br>Jika kamu tidak melakukan ini, abaikan pesan ini."
            );

            if (sendEmail($user['email'], "Verifikasi Perubahan Username", $email_body)) {
                $_SESSION['new_username'] = $new_username;
                $_SESSION['otp_type'] = 'username';
                header("Location: " . BASE_URL . "/pages/siswa/verify_otp.php");
                exit();
            }
            error_log("Failed to send OTP email to {$user['email']}");
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengirim OTP!"));
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

        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->bind_param("si", $hashed_password, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "Password kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);

            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Password",
                    "Password kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
                );
                sendEmail($user['email'], "Perubahan Password", $email_body);
            }
            session_destroy();
            header("Location: " . BASE_URL . "/pages/login.php");
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

        $hashed_new_pin = password_hash($new_pin, PASSWORD_BCRYPT);

        if ($user['pin']) {
            $current_pin = $_POST['current_pin'] ?? '';
            if (empty($current_pin) || strlen($current_pin) != 6 || !ctype_digit($current_pin)) {
                header("Location: profil.php?error=" . urlencode("PIN saat ini tidak valid!"));
                exit();
            }

            if (!password_verify($current_pin, $user['pin'])) {
                header("Location: profil.php?error=" . urlencode("PIN saat ini salah!"));
                exit();
            }
        }

        $update = $conn->prepare("UPDATE user_security SET pin = ?, has_pin = 1 WHERE user_id = ?");
        $update->bind_param("si", $hashed_new_pin, $_SESSION['user_id']);
        $update->execute();
        if ($update->affected_rows > 0) {
            // Updated successfully
        } else {
            // No row affected, likely no security row exists - insert new
            $insert = $conn->prepare("INSERT INTO user_security (user_id, pin, has_pin) VALUES (?, ?, 1)");
            $insert->bind_param("is", $_SESSION['user_id'], $hashed_new_pin);
            if (!$insert->execute()) {
                header("Location: profil.php?error=" . urlencode("Gagal mengubah PIN!"));
                exit();
            }
        }
        $action = $user['pin'] ? 'diubah' : 'dibuat';
        $notif_message = "PIN kamu telah {$action} pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
        insertNotification($conn, $_SESSION['user_id'], $notif_message);

        if ($user['email']) {
            $email_body = getEmailTemplate(
                $user['pin'] ? "Pemberitahuan Perubahan PIN" : "Pemberitahuan Pembuatan PIN",
                "PIN kamu telah {$action} pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
            );
            sendEmail($user['email'], $user['pin'] ? "Perubahan PIN" : "Pembuatan PIN", $email_body);
        }
        $_SESSION['success_message'] = $user['pin'] ? "PIN berhasil diubah!" : "PIN berhasil dibuat!";
        header("Location: profil.php");
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
                "Berikut OTP kamu:<br><br><div style='text-align:center; font-size:32px; font-weight:bold; letter-spacing:8px; color:#1e3a8a; margin:20px 0;'>{$otp_data['otp']}</div><br>Kode ini berlaku hingga " . getIndonesianMonth($otp_data['expiry']) . ", " . date('H:i:s', strtotime($otp_data['expiry'])) . " WIB.<br>Jika kamu tidak melakukan ini, abaikan pesan ini."
            );

            if (sendEmail($email, "Verifikasi Email", $email_body)) {
                $_SESSION['new_email'] = $email;
                $_SESSION['otp_type'] = 'email';
                header("Location: " . BASE_URL . "/pages/siswa/verify_otp.php");
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

        $update = $conn->prepare("UPDATE siswa_profiles SET alamat_lengkap = ? WHERE user_id = ?");
        $update->bind_param("si", $alamat, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "Alamat kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);

            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Alamat",
                    "Alamat lengkap kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
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

        $update = $conn->prepare("UPDATE siswa_profiles SET tanggal_lahir = ?, tanggal_lahir_edited = 1 WHERE user_id = ?");
        $update->bind_param("si", $tanggal_lahir, $_SESSION['user_id']);
        if ($update->execute()) {
            $formatted_date = date('d/m/Y', strtotime($tanggal_lahir));
            $notif_message = "Tanggal lahir kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);

            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Tanggal Lahir",
                    "Tanggal lahir kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
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

        $update = $conn->prepare("UPDATE siswa_profiles SET jenis_kelamin = ?, jenis_kelamin_edited = 1 WHERE user_id = ?");
        $update->bind_param("si", $jenis_kelamin, $_SESSION['user_id']);
        if ($update->execute()) {
            $jk_text = $jenis_kelamin == 'L' ? 'Laki-laki' : 'Perempuan';
            $notif_message = "Jenis kelamin kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);

            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan Jenis Kelamin",
                    "Jenis kelamin kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
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

        $check = $conn->prepare("SELECT id FROM siswa_profiles WHERE nis_nisn = ? AND user_id != ?");
        $check->bind_param("si", $nisn, $_SESSION['user_id']);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            header("Location: profil.php?error=" . urlencode("NIS/NISN sudah digunakan oleh pengguna lain!"));
            exit();
        }

        $update = $conn->prepare("UPDATE siswa_profiles SET nis_nisn = ?, nis_nisn_edited = 1 WHERE user_id = ?");
        $update->bind_param("si", $nisn, $_SESSION['user_id']);
        if ($update->execute()) {
            $notif_message = "NIS/NISN kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami.";
            insertNotification($conn, $_SESSION['user_id'], $notif_message);

            if ($user['email']) {
                $email_body = getEmailTemplate(
                    "Pemberitahuan Perubahan NIS/NISN",
                    "NIS/NISN kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
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

        $update = $conn->prepare("UPDATE siswa_profiles SET kelas_id = ? WHERE user_id = ?");
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
                    "Kelas kamu telah diubah pada " . date('d/m/Y H:i') . " WIB. Jika kamu tidak melakukan ini hubungi kami."
                );
                sendEmail($user['email'], "Perubahan Kelas", $email_body);
            }
            $_SESSION['success_message'] = "Kelas berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah kelas!"));
        exit();

    } elseif (isset($_POST['update_avatar'])) {
        $avatar = trim($_POST['avatar']);

        // Avatar styles with level requirements
        $avatar_levels = [
            'initials' => 'BRONZE',      // Initials avatar - Free for all
            'lorelei' => 'BRONZE',       // Free
            'notionists' => 'BRONZE',    // Free
            'shapes' => 'SILVER',
            'thumbs' => 'SILVER',
            'personas' => 'GOLD',
            'adventurer' => 'GOLD',
            'micah' => 'PLATINUM',
            'big-smile' => 'PLATINUM'
        ];

        // Get user level based on saldo
        $saldo = $user['saldo'] ?? 0;
        if ($saldo <= 50000) {
            $user_level = 'BRONZE';
        } elseif ($saldo <= 200000) {
            $user_level = 'SILVER';
        } elseif ($saldo <= 500000) {
            $user_level = 'GOLD';
        } else {
            $user_level = 'PLATINUM';
        }

        // Level hierarchy for comparison
        $level_order = ['BRONZE' => 1, 'SILVER' => 2, 'GOLD' => 3, 'PLATINUM' => 4];

        if (!isset($avatar_levels[$avatar])) {
            header("Location: profil.php?error=" . urlencode("Avatar tidak valid!"));
            exit();
        }

        $required_level = $avatar_levels[$avatar];
        if ($level_order[$user_level] < $level_order[$required_level]) {
            header("Location: profil.php?error=" . urlencode("Avatar ini terkunci! Kamu perlu level $required_level."));
            exit();
        }

        $update = $conn->prepare("UPDATE siswa_profiles SET avatar = ? WHERE user_id = ?");
        $update->bind_param("si", $avatar, $_SESSION['user_id']);
        if ($update->execute()) {
            $_SESSION['success_message'] = "Avatar berhasil diubah!";
            header("Location: profil.php");
            exit();
        }
        header("Location: profil.php?error=" . urlencode("Gagal mengubah avatar!"));
        exit();

    } elseif (isset($_POST['logout'])) {
        session_destroy();
        header("Location: " . BASE_URL . "/pages/login.php");
        exit();
    }
}

// Get user level function
function getUserLevel($saldo)
{
    if ($saldo <= 50000)
        return 'BRONZE';
    if ($saldo <= 200000)
        return 'SILVER';
    if ($saldo <= 500000)
        return 'GOLD';
    return 'PLATINUM';
}

function formatRupiah($amount)
{
    return 'Rp ' . number_format($amount, 0, '.', '.');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <title>Profil - KASDIG</title>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <meta name="theme-color" content="#2c3e50">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            /* Dark Elegant Theme - Matching Transfer Page */
            --primary: #2c3e50;
            --primary-dark: #1a252f;
            --primary-light: #34495e;
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
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --radius-full: 9999px;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 0;
            padding-bottom: 100px;
            min-height: 100vh;
        }

        /* iOS Date Input Fixes */
        input[type="date"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            font-size: 16px !important;
            /* Prevent zoom on iOS */
            min-height: 44px;
            /* iOS tap target */
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

        /* ============================================ */
        /* HEADER / TOP NAVIGATION - DARK THEME */
        /* ============================================ */
        .top-header {
            background: linear-gradient(to bottom, #2c3e50 0%, #2c3e50 50%, #3d5166 65%, #5a7089 78%, #8fa3b8 88%, #c5d1dc 94%, #f8fafc 100%);
            padding: 20px 20px 140px;
            position: relative;
            overflow: hidden;
            border-radius: 0 0 5px 5px;
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-info {
            color: var(--white);
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .page-subtitle {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        .header-btn {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            backdrop-filter: blur(10px);
            text-decoration: none;
        }

        .header-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }

        .header-btn:active {
            transform: scale(0.95);
        }

        .header-btn i {
            font-size: 18px;
        }

        /* Profile Avatar in Header */
        .profile-header-info {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            margin-top: 8px;
        }

        .profile-avatar {
            width: 96px;
            height: 96px;
            background: rgba(255, 255, 255, 0.15);
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
            backdrop-filter: blur(10px);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .profile-avatar:hover {
            transform: scale(1.05);
            border-color: rgba(255, 255, 255, 0.5);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar .edit-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            font-size: 10px;
            padding: 4px 0;
            text-transform: none;
            font-weight: 500;
        }

        /* Avatar Initials Style */
        .profile-avatar .avatar-initials {
            font-size: 36px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
        }

        .avatar-initials-option {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%) !important;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-initials-preview {
            font-size: 24px;
            font-weight: 700;
            color: var(--white);
            text-transform: uppercase;
        }

        /* Avatar Picker Grid */
        .avatar-picker-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 15px;
        }

        .avatar-option {
            aspect-ratio: 1;
            border-radius: 50%;
            border: 3px solid var(--gray-200);
            cursor: pointer;
            transition: all 0.2s ease;
            overflow: hidden;
            background: var(--gray-100);
        }

        .avatar-option:hover {
            border-color: var(--primary);
            transform: scale(1.05);
        }

        .avatar-option.selected {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.2);
        }

        .avatar-option img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .avatar-style-name {
            text-align: center;
            font-size: 10px;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Locked Avatar Styles */
        .avatar-option.locked {
            filter: grayscale(100%);
            opacity: 0.5;
            cursor: not-allowed;
            position: relative;
        }

        .avatar-option.locked:hover {
            transform: none;
            border-color: var(--gray-200);
        }

        .avatar-option .lock-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 20px;
            color: var(--gray-600);
            background: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-level-badge {
            font-size: 8px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-bronze {
            background: #CD7F32;
            color: white;
        }

        .badge-silver {
            background: #C0C0C0;
            color: #333;
        }

        .badge-gold {
            background: #FFD700;
            color: #333;
        }

        .badge-platinum {
            background: linear-gradient(135deg, #E5E4E2, #B4B4B4);
            color: #333;
        }

        .profile-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--white);
            margin-bottom: 4px;
        }

        .profile-class {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 400;
        }

        /* ============================================ */
        /* MAIN CONTAINER */
        /* ============================================ */
        .main-container {
            padding: 0 20px;
            margin-top: -60px;
            position: relative;
            z-index: 10;
        }

        /* Legacy container support */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }

        /* Profile Card - White card overlapping header */
        .profile-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .section {
            padding: 0;
            margin-bottom: 0;
        }

        .section:last-child {
            margin-bottom: 0;
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
            padding: 14px 0;
            border-bottom: 1px solid var(--gray-100);
            transition: background-color 0.2s ease;
            cursor: pointer;
            position: relative;
            background: transparent;
        }

        .menu-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .menu-item:first-of-type {
            padding-top: 0;
        }

        .menu-item:hover {
            background: var(--gray-50);
        }

        .menu-item.logout-item {
            background: transparent;
            cursor: pointer;
        }

        .menu-item.logout-item:hover {
            background: var(--gray-50);
        }

        .menu-icon {
            display: none;
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
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
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

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding-bottom: 110px;
            }

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
                width: 80px;
                height: 80px;
                font-size: 28px;
            }

            .page-title {
                font-size: 20px;
            }

            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding-bottom: 115px;
            }
        }
    </style>
</head>

<body>
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

    <!-- Dark Header -->
    <header class="top-header">
        <div class="header-content">
            <div class="header-top">
            </div>
            <!-- Profile Info in Header -->
            <div class="profile-header-info">
                <?php
                $avatar_style = $user['avatar'] ?? 'lorelei';
                $avatar_seed = urlencode($user['nama']);
                // Get initials for avatar (first name + second/middle name if exists)
                $name_parts = explode(' ', $user['nama']);
                $initials = strtoupper(substr($name_parts[0], 0, 1));
                if (count($name_parts) > 1) {
                    // Always use second name (middle name), not last name
                    $initials .= strtoupper(substr($name_parts[1], 0, 1));
                }
                ?>
                <div class="profile-avatar" onclick="openModal('avatarModal')">
                    <?php if ($avatar_style === 'initials'): ?>
                        <span class="avatar-initials"><?php echo $initials; ?></span>
                    <?php else: ?>
                        <?php $avatar_url = "https://api.dicebear.com/7.x/{$avatar_style}/svg?seed={$avatar_seed}"; ?>
                        <img src="<?php echo $avatar_url; ?>" alt="Avatar">
                    <?php endif; ?>
                    <div class="edit-overlay">Ubah</div>
                </div>
                <div class="profile-name"><?php echo htmlspecialchars($user['nama']); ?></div>
                <div class="profile-class"><?php echo htmlspecialchars($user['nama_jurusan']); ?></div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Personal Information Card -->
        <div class="profile-card">
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
                        <div class="menu-value">
                            <?php echo $user['tanggal_lahir'] ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : 'Belum diatur'; ?>
                        </div>
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
                        <div class="menu-value">
                            <?php echo $user['jenis_kelamin'] == 'L' ? 'Laki-laki' : ($user['jenis_kelamin'] == 'P' ? 'Perempuan' : 'Belum diatur'); ?>
                        </div>
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
                        <div class="menu-value"><?php echo htmlspecialchars($user['nis_nisn'] ?? 'Belum diatur'); ?>
                        </div>
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
                        <div class="menu-value">
                            <?php echo htmlspecialchars($user['alamat_lengkap'] ?? 'Belum diatur'); ?>
                        </div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="alamatModal"></i>
                </div>
                <div class="menu-item">
                    <div class="menu-icon">
                        <i class="fas fa-school"></i>
                    </div>
                    <div class="menu-content">
                        <div class="menu-label">Tingkatan Kelas</div>
                        <div class="menu-value">
                            <?php echo $user['nama_tingkatan'] && $user['nama_kelas'] ? htmlspecialchars($user['nama_tingkatan'] . ' ' . $user['nama_kelas']) : 'Belum diatur'; ?>
                        </div>
                    </div>
                    <i class="fas fa-edit menu-arrow" data-target="tingkatankelasModal"></i>
                </div>
            </div>
        </div>

        <!-- Rekening Information Card -->
        <div class="profile-card">
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
                        <div class="menu-value copyable" title="Click to copy"
                            data-copy="<?php echo $user['no_rekening']; ?>">
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
        </div>

        <!-- Account Settings Card -->
        <div class="profile-card">
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
                        <div class="menu-value copyable" title="Click to copy"
                            data-copy="<?php echo htmlspecialchars($user['username']); ?>">
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
                        <div class="menu-value">
                            <?php echo $user['email'] ? htmlspecialchars($user['email']) : 'Belum diatur'; ?>
                        </div>
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
            </div>
        </div>

        <!-- Logout Card -->
        <div class="profile-card">
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
    <!-- Bottom Navigation - Included from separate file -->
    <?php include 'bottom_navbar.php'; ?>
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
                        <input type="text" id="new_username" name="new_username" required
                            placeholder="Masukkan username baru">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_username" class="btn btn-primary"
                            id="usernameBtn"><span>Simpan</span></button>
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
                        <input type="email" id="email" name="email" required placeholder="Masukkan email"
                            value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_email" class="btn btn-primary"
                            id="emailBtn"><span>Simpan</span></button>
                        <button type="button" class="btn btn-outline close-modal">Batal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- Modal Avatar Picker -->
    <div id="avatarModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-circle"></i> Pilih Avatar</h3>
                <button type="button" class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <?php
                $user_saldo = $user['saldo'] ?? 0;
                $user_level = getUserLevel($user_saldo);
                $level_order = ['BRONZE' => 1, 'SILVER' => 2, 'GOLD' => 3, 'PLATINUM' => 4];
                $user_level_num = $level_order[$user_level];
                ?>
                <p style="font-size: 13px; color: var(--gray-600); margin-bottom: 10px;">
                    Level kamu: <span
                        class="avatar-level-badge badge-<?php echo strtolower($user_level); ?>"><?php echo $user_level; ?></span>
                </p>
                <p style="font-size: 11px; color: var(--gray-400); margin-bottom: 15px;">🔒 Avatar terkunci akan terbuka
                    saat naik level</p>
                <form method="POST" id="avatarForm">
                    <input type="hidden" name="avatar" id="selectedAvatar"
                        value="<?php echo htmlspecialchars($user['avatar'] ?? 'lorelei'); ?>">
                    <div class="avatar-picker-grid">
                        <?php
                        // Add initials option first (first name + second/middle name if exists)
                        $current_avatar = $user['avatar'] ?? 'lorelei';
                        $avatar_seed = urlencode($user['nama']);
                        $name_parts = explode(' ', $user['nama']);
                        $initials = strtoupper(substr($name_parts[0], 0, 1));
                        if (count($name_parts) > 1) {
                            // Always use second name (middle name), not last name
                            $initials .= strtoupper(substr($name_parts[1], 0, 1));
                        }
                        $initials_selected = ($current_avatar === 'initials') ? 'selected' : '';
                        ?>
                        <!-- Initials Avatar Option -->
                        <div class="avatar-option-wrapper">
                            <div class="avatar-option avatar-initials-option <?php echo $initials_selected; ?>"
                                data-style="initials" onclick="selectAvatar('initials')" title="Inisial">
                                <span class="avatar-initials-preview"><?php echo $initials; ?></span>
                            </div>
                            <div class="avatar-style-name">
                                Inisial
                                <span class="avatar-level-badge badge-bronze">BRONZE</span>
                            </div>
                        </div>
                        <?php
                        $avatar_styles = [
                            'lorelei' => ['name' => 'Pelajar', 'level' => 'BRONZE'],
                            'notionists' => ['name' => 'Rajin', 'level' => 'BRONZE'],
                            'shapes' => ['name' => 'Bintang', 'level' => 'SILVER'],
                            'thumbs' => ['name' => 'Juara', 'level' => 'SILVER'],
                            'personas' => ['name' => 'Elit', 'level' => 'GOLD'],
                            'adventurer' => ['name' => 'Premium', 'level' => 'GOLD'],
                            'micah' => ['name' => 'Master', 'level' => 'PLATINUM'],
                            'big-smile' => ['name' => 'Legend', 'level' => 'PLATINUM']
                        ];
                        $current_avatar = $user['avatar'] ?? 'lorelei';
                        $avatar_seed = urlencode($user['nama']);
                        foreach ($avatar_styles as $style => $info):
                            $url = "https://api.dicebear.com/7.x/{$style}/svg?seed={$avatar_seed}";
                            $required_level = $info['level'];
                            $required_level_num = $level_order[$required_level];
                            $is_locked = $user_level_num < $required_level_num;
                            $selected = ($style === $current_avatar && !$is_locked) ? 'selected' : '';
                            $locked_class = $is_locked ? 'locked' : '';
                            $onclick = $is_locked ? '' : "onclick=\"selectAvatar('$style')\"";
                            ?>
                            <div class="avatar-option-wrapper">
                                <div class="avatar-option <?php echo $selected; ?> <?php echo $locked_class; ?>"
                                    data-style="<?php echo $style; ?>" <?php echo $onclick; ?>
                                    title="<?php echo $is_locked ? 'Level ' . $required_level . ' diperlukan' : $info['name']; ?>">
                                    <img src="<?php echo $url; ?>" alt="<?php echo $info['name']; ?>">
                                    <?php if ($is_locked): ?>
                                        <div class="lock-icon"><i class="fas fa-lock"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="avatar-style-name">
                                    <?php echo $info['name']; ?>
                                    <span
                                        class="avatar-level-badge badge-<?php echo strtolower($required_level); ?>"><?php echo $required_level; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="modal-footer" style="margin-top: 20px;">
                        <button type="submit" name="update_avatar" class="btn btn-primary"
                            id="avatarBtn"><span>Simpan</span></button>
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
                        <input type="password" id="current_password" name="current_password" required
                            placeholder="Password saat ini">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_current_password" data-target="current_password">
                            <label for="show_current_password">Tampilkan</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_password">Password Baru</label>
                        <input type="password" id="new_password" name="new_password" required
                            placeholder="Password baru">
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
                        <input type="password" id="confirm_password" name="confirm_password" required
                            placeholder="Konfirmasi password">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_confirm_password" data-target="confirm_password">
                            <label for="show_confirm_password">Tampilkan</label>
                        </div>
                        <div class="pin-match-label pin-match-unmatched" id="confirmMatchLabel" style="display:none;">
                            Tidak cocok</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_password" class="btn btn-primary"
                            id="passwordBtn"><span>Simpan</span></button>
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
                            <input type="password" id="current_pin" name="current_pin" required placeholder="PIN saat ini"
                                maxlength="6" inputmode="numeric">
                            <div class="checkbox-container">
                                <input type="checkbox" id="show_current_pin" data-target="current_pin">
                                <label for="show_current_pin">Tampilkan</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="new_pin">PIN Baru (6 digit)</label>
                        <input type="password" id="new_pin" name="new_pin" required placeholder="PIN baru" maxlength="6"
                            inputmode="numeric">
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
                        <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN"
                            maxlength="6" inputmode="numeric">
                        <div class="checkbox-container">
                            <input type="checkbox" id="show_confirm_pin" data-target="confirm_pin">
                            <label for="show_confirm_pin">Tampilkan</label>
                        </div>
                        <div class="pin-match-label pin-match-unmatched" id="pinMatchLabel" style="display:none;">Tidak
                            cocok</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_pin" class="btn btn-primary"
                            id="pinBtn"><span>Simpan</span></button>
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
                        <textarea id="alamat_lengkap" name="alamat_lengkap" required rows="3"
                            placeholder="Masukkan alamat lengkap"><?php echo htmlspecialchars($user['alamat_lengkap'] ?? ''); ?></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_alamat" class="btn btn-primary"
                            id="alamatBtn"><span>Simpan</span></button>
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
                        <input type="date" id="tanggal_lahir" name="tanggal_lahir" required
                            value="<?php echo htmlspecialchars($user['tanggal_lahir'] ?? ''); ?>"
                            max="<?php echo date('Y-m-d'); ?>" min="1950-01-01">
                        <small>Pilih tanggal lahir Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_tanggal_lahir" class="btn btn-primary"
                            id="tanggalLahirBtn"><span>Simpan</span></button>
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
                            <option value="L" <?php echo ($user['jenis_kelamin'] == 'L') ? 'selected' : ''; ?>>Laki-laki
                            </option>
                            <option value="P" <?php echo ($user['jenis_kelamin'] == 'P') ? 'selected' : ''; ?>>Perempuan
                            </option>
                        </select>
                        <small>Pilih jenis kelamin Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_jenis_kelamin" class="btn btn-primary"
                            id="jenisKelaminBtn"><span>Simpan</span></button>
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
                        <input type="text" id="nis_nisn" name="nis_nisn" required placeholder="Masukkan NIS/NISN"
                            value="<?php echo htmlspecialchars($user['nis_nisn'] ?? ''); ?>">
                        <small>Masukkan NIS atau NISN Anda. <strong>Hanya bisa diubah sekali.</strong></small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_nisn" class="btn btn-primary"
                            id="nisnBtn"><span>Simpan</span></button>
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
                <p style="color: var(--gray-600); font-size: 13px; margin-bottom: 16px; font-style: italic;">Fitur
                    ini
                    hanya untuk ketika naik kelas atau pindah kelas.</p>
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
                        <button type="submit" name="update_kelas" class="btn btn-primary"
                            id="tingkatanKelasBtn"><span>Simpan</span></button>
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
        // Global functions for onclick handlers
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                modal.classList.add('active');
            }
        }
        function selectAvatar(style) {
            document.getElementById('selectedAvatar').value = style;
            document.querySelectorAll('.avatar-option').forEach(opt => {
                opt.classList.remove('selected');
                if (opt.dataset.style === style) {
                    opt.classList.add('selected');
                }
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
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

            // Auto-open PIN modal if open_pin parameter is true
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('open_pin') === 'true') {
                const pinModal = document.getElementById('pinModal');
                if (pinModal) {
                    pinModal.style.display = 'flex';
                    pinModal.classList.add('active');
                }
            }
        });
    </script>
</body>

</html>
<?php
$conn->close();
?>