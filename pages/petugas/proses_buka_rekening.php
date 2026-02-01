<?php
/**
 * PROSES BUKA REKENING - DB BARU NORMALIZED + EMAIL STYLE ASLI + PIN BCRYPT
 * File: pages/petugas/proses_buka_rekening.php
 */

// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/petugas' folder
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 3: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 4: Search upward for includes/ folder (max 5 levels)
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

// Fallback: Use current directory
if (!$project_root) {
    $project_root = $current_dir;
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}

// ============================================
// INCLUDE & NAMESPACE
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once VENDOR_PATH . '/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

date_default_timezone_set('Asia/Jakarta');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Hanya PETUGAS
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'petugas') {
    header('Location: ' . PROJECT_ROOT . '/pages/login.php?error=' . urlencode('Silakan login sebagai petugas terlebih dahulu!'));
    exit();
}

// CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['token']) || $_POST['token'] !== ($_SESSION['form_token'] ?? '')) {
        header('Location: buka_rekening.php?error=' . urlencode('Token tidak valid!'));
        exit();
    }
} else {
    header('Location: buka_rekening.php');
    exit();
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function generateUsername($conn, $length = 8)
{
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lowercase = 'abcdefghjkmnpqrstuvwxyz';
    $numbers = '23456789';
    $symbols = '@#$%&*';

    $check_query = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_query);
    if (!$check_stmt) {
        error_log("Error preparing username check: " . $conn->error);
        // Fallback: generate tanpa check
        $allChars = $uppercase . $lowercase . $numbers;
        $username = '';
        for ($i = 0; $i < $length; $i++) {
            $username .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        return $username;
    }

    $maxAttempts = 50;
    $attempts = 0;

    do {
        // Generate username random dengan campuran karakter
        $username = '';
        $username .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $username .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $username .= $numbers[random_int(0, strlen($numbers) - 1)];
        $username .= $symbols[random_int(0, strlen($symbols) - 1)];

        // Tambah karakter random untuk sisa panjang
        $allChars = $uppercase . $lowercase . $numbers . $symbols;
        for ($i = 4; $i < $length; $i++) {
            $username .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        // Acak urutan karakter
        $username = str_shuffle($username);

        // Check apakah sudah ada di database
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $result = $check_stmt->get_result();

        if ($result->num_rows === 0) {
            break;
        }

        $attempts++;
    } while ($attempts < $maxAttempts);

    $check_stmt->close();
    return $username;
}

function generateRandomPassword($length = 6)
{
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lowercase = 'abcdefghjkmnpqrstuvwxyz';
    $numbers = '23456789';
    $symbols = '@#$%&*';

    // Pastikan ada minimal 1 dari setiap jenis
    $password = '';
    $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
    $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
    $password .= $numbers[random_int(0, strlen($numbers) - 1)];
    $password .= $symbols[random_int(0, strlen($symbols) - 1)];

    // Tambah karakter random untuk sisa panjang
    $allChars = $uppercase . $lowercase . $numbers . $symbols;
    for ($i = 4; $i < $length; $i++) {
        $password .= $allChars[random_int(0, strlen($allChars) - 1)];
    }

    // Acak urutan karakter
    $password = str_shuffle($password);

    return $password;
}

function checkUniqueField($field, $value, $conn, $table = 'users')
{
    if (empty($value)) {
        return null;
    }
    $field = preg_replace('/[^a-zA-Z0-9_]/', '', $field);
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);

    $query = "SELECT id FROM $table WHERE $field = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing unique check for $field: " . $conn->error);
        return "Terjadi kesalahan server.";
    }
    $stmt->bind_param("s", $value);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists ? ucfirst(str_replace('_', ' ', $field)) . " sudah digunakan!" : null;
}

// EMAIL FUNCTION - STYLE IDENTIK
function sendEmailConfirmation(
    $email,
    $nama,
    $username,
    $no_rekening,
    $password,
    $jurusan_name,
    $tingkatan_name,
    $kelas_name,
    $alamat_lengkap = null,
    $nis_nisn = null,
    $jenis_kelamin = null,
    $tanggal_lahir = null
) {
    if (empty($email)) {
        error_log("Skipping email sending as email is empty");
        return ['success' => true, 'duration' => 0];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'mail.kasdig.web.id';
        $mail->SMTPAuth = true;
        $mail->Username = 'noreply@kasdig.web.id';
        $mail->Password = 'BtRjT4wP8qeTL5M';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;
        $mail->Timeout = 30;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('noreply@kasdig.web.id', 'KASDIG');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('noreply@kasdig.web.id', 'KASDIG');

        $unique_id = uniqid('kasdig_', true) . '@kasdig.web.id';
        $mail->MessageID = '<' . $unique_id . '>';
        $mail->addCustomHeader('X-Mailer', 'KASDIG-System-v1.0');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');

        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $base_path = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
        $base_path = rtrim(str_replace('\\', '/', $base_path), '/');
        $login_link = $protocol . "://" . $host . $base_path . "/pages/login.php";

        $kelas_lengkap = trim($tingkatan_name . ' ' . $kelas_name);
        $alamat_display = $alamat_lengkap ?: 'Tidak diisi';
        $nis_display = $nis_nisn ?: 'Tidak diisi';
        $jenis_kelamin_display = $jenis_kelamin ? ($jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan') : 'Tidak diisi';
        $tanggal_lahir_display = $tanggal_lahir ? date('d F Y', strtotime($tanggal_lahir)) : 'Tidak diisi';
        $tanggal_pembukaan = date('d F Y H:i:s') . ' WIB';

        $emailBody = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Pembukaan Rekening Berhasil</h2>
            <p>Halo <strong>{$nama}</strong>,</p>
            <p>Rekening Anda telah berhasil dibuka. Terima kasih telah mempercayakan layanan perbankan digital kami.</p>
            <div style='margin: 30px 0; border-top: 1px solid #eeeeee; border-bottom: 1px solid #eeeeee; padding: 20px 0;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Detail Akun</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr><td style='padding: 5px 0; width: 140px; color: #666;'>No. Rekening</td><td style='font-weight: bold;'>{$no_rekening}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Nama Lengkap</td><td>{$nama}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Jenis Kelamin</td><td>{$jenis_kelamin_display}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Tanggal Lahir</td><td>{$tanggal_lahir_display}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Alamat Lengkap</td><td>{$alamat_display}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>NIS/NISN</td><td>{$nis_display}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Kelas/Jurusan</td><td>{$kelas_lengkap} - {$jurusan_name}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Tanggal Aktivasi</td><td>{$tanggal_pembukaan}</td></tr>
                </table>
            </div>
            <div style='margin-bottom: 30px;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Kredensial Login</h3>
                <p style='margin-bottom: 5px;'>Gunakan data berikut untuk masuk ke aplikasi:</p>
                <table style='width: 100%; background-color: #f9fafb; border-radius: 6px; padding: 15px;'>
                    <tr><td style='padding: 5px 0; width: 100px; color: #666;'>Username</td><td style='font-family: monospace; font-size: 16px; font-weight: bold;'>{$username}</td></tr>
                    <tr><td style='padding: 5px 0; color: #666;'>Password</td><td style='font-family: monospace; font-size: 16px; font-weight: bold; color: #dc2626;'>{$password}</td></tr>
                </table>
                <p style='font-size: 13px; color: #666; margin-top: 10px;'>*Harap segera ganti password Anda setelah login pertama kali demi keamanan.</p>
            </div>
            <div style='text-align: left; margin-bottom: 40px;'>
                <a href='{$login_link}' style='background-color: #1e3a8a; color: white; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;'>Login Sekarang</a>
            </div>
            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem KASDIG.<br>
                Jika Anda memiliki pertanyaan, silakan hubungi petugas sekolah.
            </p>
        </div>";

        $mail->Body = $emailBody;

        $labels = [
            'Nama Lengkap' => $nama,
            'Jenis Kelamin' => $jenis_kelamin_display,
            'Tanggal Lahir' => $tanggal_lahir_display,
            'Alamat Lengkap' => $alamat_display,
            'NIS/NISN' => $nis_display,
            'Nomor Rekening' => $no_rekening,
            'Username' => $username,
            'Kata Sandi Sementara' => $password,
            'Jurusan' => $jurusan_name,
            'Kelas' => $kelas_lengkap,
            'Tanggal Aktivasi' => $tanggal_pembukaan,
            'Saldo Awal' => 'Rp 0'
        ];

        $max_label_length = max(array_map('strlen', array_keys($labels)));
        $max_label_length = max($max_label_length, 20);
        $text_rows = [];
        foreach ($labels as $label => $value) {
            $text_rows[] = str_pad($label, $max_label_length, ' ') . " : " . $value;
        }
        $text_rows[] = "\nPENTING: Segera ganti kata sandi sementara dan atur PIN transaksi setelah login pertama.";
        $text_rows[] = "Masuk ke akun: {$login_link}";
        $text_rows[] = "Hubungi dukungan: info.schobank@schobank.web.id";
        $text_rows[] = "\nHormat kami,\nTim KASDIG";
        $text_rows[] = "\nPesan otomatis, mohon tidak membalas.";

        $mail->AltBody = implode("\n", $text_rows);

        error_log("Mengirim email ke: $email");
        $start_time = microtime(true);
        if ($mail->send()) {
            $end_time = microtime(true);
            $duration = round(($end_time - $start_time) * 1000);
            error_log("Email berhasil dikirim ke: $email (Durasi: {$duration}ms)");
            return ['success' => true, 'duration' => $duration];
        }

        throw new Exception($mail->ErrorInfo);
    } catch (Exception $e) {
        error_log("Gagal mengirim email ke $email: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    } finally {
        if (isset($mail)) {
            $mail->smtpClose();
        }
    }
}

// ============================================
// MAIN PROCESS
// ============================================
error_log("Data POST diterima: " . print_r($_POST, true));

if (isset($_POST['cancel'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    header('Location: buka_rekening.php');
    exit();
}

$nama = trim($_POST['nama'] ?? '');
$tipe_rekening = 'digital'; // Semua rekening adalah digital
$email = trim($_POST['email'] ?? '') ?: null;
$alamat_lengkap = trim($_POST['alamat_lengkap'] ?? '') ?: null;
$nis_nisn = trim($_POST['nis_nisn'] ?? '') ?: null;
$jenis_kelamin_raw = trim($_POST['jenis_kelamin'] ?? '') ?: null;
$tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '') ?: null;
$jurusan_id = (int) ($_POST['jurusan_id'] ?? 0);
$tingkatan_kelas_id = (int) ($_POST['tingkatan_kelas_id'] ?? 0);
$kelas_id = (int) ($_POST['kelas_id'] ?? 0);

$errors = [];

// NORMALISASI jenis_kelamin ke ENUM('L','P')
$jenis_kelamin = '';
if ($jenis_kelamin_raw === 'L' || $jenis_kelamin_raw === 'P') {
    $jenis_kelamin = $jenis_kelamin_raw;
}

// VALIDASI
if (empty($nama) || strlen($nama) < 3) {
    $errors['nama'] = "Nama harus minimal 3 karakter!";
}
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = "Format email tidak valid!";
}
// Email wajib untuk semua rekening
if (empty($email)) {
    $errors['email'] = "Email wajib diisi!";
}
if ($jurusan_id <= 0) {
    $errors['jurusan_id'] = "Jurusan wajib dipilih!";
}
if ($tingkatan_kelas_id <= 0) {
    $errors['tingkatan_kelas_id'] = "Tingkatan kelas wajib dipilih!";
}
if ($kelas_id <= 0) {
    $errors['kelas_id'] = "Kelas wajib dipilih!";
}



// Unique email (hanya jika ada)
if (!$errors && !empty($email) && ($error = checkUniqueField('email', $email, $conn))) {
    $errors['email'] = $error;
}

if ($errors) {
    $error_msg = implode('; ', array_values($errors));
    header('Location: buka_rekening.php?error=' . urlencode($error_msg));
    exit();
}

// ========================================
// SUBMIT KEDUA (confirmed) -> INSERT DB 4 TABEL
// ========================================
if (isset($_POST['confirmed'])) {
    $username = $_POST['username'];
    $no_rekening = $_POST['no_rekening'];
    $password_plain = $_POST['password'];
    $jurusan_name = $_POST['jurusan_name'];
    $tingkatan_name = $_POST['tingkatan_name'];
    $kelas_name = $_POST['kelas_name'];

    $conn->begin_transaction();
    try {
        // 1. INSERT users
        $hashed_password = $password_plain ? password_hash($password_plain, PASSWORD_BCRYPT) : null;
        $email_status = $email ? 'terisi' : 'kosong';

        $query_user = "INSERT INTO users (username, password, role, nama, email, email_status)
                       VALUES (?, ?, 'siswa', ?, ?, ?)";
        $stmt_user = $conn->prepare($query_user);
        if (!$stmt_user) {
            throw new Exception("Gagal menyiapkan users: " . $conn->error);
        }
        $stmt_user->bind_param("sssss", $username, $hashed_password, $nama, $email, $email_status);
        if (!$stmt_user->execute()) {
            throw new Exception("Gagal insert users: " . $stmt_user->error);
        }
        $user_id = $conn->insert_id;
        $stmt_user->close();

        // 2. INSERT siswa_profiles (nama, jk, tgl lahir, alamat, nis/nisn, jurusan, kelas)
        $query_profile = "INSERT INTO siswa_profiles 
                            (user_id, nis_nisn, alamat_lengkap, jenis_kelamin, tanggal_lahir, jurusan_id, kelas_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_profile = $conn->prepare($query_profile);
        if (!$stmt_profile) {
            throw new Exception("Gagal menyiapkan siswa_profiles: " . $conn->error);
        }

        $jk_param = $jenis_kelamin ?: null;
        $tgl_param = $tanggal_lahir ?: null;   // sudah divalidasi YYYY-MM-DD
        $nis_param = $nis_nisn ?: null;
        $alamat_param = $alamat_lengkap ?: null;

        $stmt_profile->bind_param(
            "issssii",
            $user_id,
            $nis_param,
            $alamat_param,
            $jk_param,
            $tgl_param,
            $jurusan_id,
            $kelas_id
        );
        if (!$stmt_profile->execute()) {
            throw new Exception("Gagal insert siswa_profiles: " . $stmt_profile->error);
        }
        $stmt_profile->close();



        // 4. INSERT rekening
        $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0.00)";
        $stmt_rekening = $conn->prepare($query_rekening);
        if (!$stmt_rekening) {
            throw new Exception("Gagal menyiapkan rekening: " . $conn->error);
        }
        $stmt_rekening->bind_param("si", $no_rekening, $user_id);
        if (!$stmt_rekening->execute()) {
            throw new Exception("Gagal insert rekening: " . $stmt_rekening->error);
        }
        $stmt_rekening->close();

        // 4. Email konfirmasi
        if (!empty($email)) {
            $email_result = sendEmailConfirmation(
                $email,
                $nama,
                $username,
                $no_rekening,
                $password_plain,
                $jurusan_name,
                $tingkatan_name,
                $kelas_name,
                $alamat_lengkap,
                $nis_nisn,
                $jenis_kelamin,
                $tanggal_lahir
            );
            if (!$email_result['success']) {
                error_log("Peringatan: Gagal mengirim email ke: $email, tapi data tersimpan");
            }
        }

        $conn->commit();
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        header("Location: buka_rekening.php?success=1");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Transaksi gagal: " . $e->getMessage());
        header('Location: buka_rekening.php?error=' . urlencode("Gagal menambah nasabah: " . $e->getMessage()));
        exit();
    }
}

// ========================================
// SUBMIT PERTAMA: GENERATE DATA KONFIRMASI
// ========================================
$username = generateUsername($conn);

// Generate no rekening unik
$jurusan_kode = sprintf('%02d', $jurusan_id);
$no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
$counter = 1;
while (checkUniqueField('no_rekening', $no_rekening, $conn, 'rekening') && $counter <= 10) {
    $no_rekening = $jurusan_kode . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    $counter++;
}

// Nama referensi
$query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
$stmt_jurusan = $conn->prepare($query_jurusan_name);
$stmt_jurusan->bind_param("i", $jurusan_id);
$stmt_jurusan->execute();
$result_jurusan_name = $stmt_jurusan->get_result();
$jurusan_name = $result_jurusan_name->num_rows > 0 ? $result_jurusan_name->fetch_assoc()['nama_jurusan'] : '';
$stmt_jurusan->close();

$query_tingkatan_name = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
$stmt_tingkatan = $conn->prepare($query_tingkatan_name);
$stmt_tingkatan->bind_param("i", $tingkatan_kelas_id);
$stmt_tingkatan->execute();
$result_tingkatan_name = $stmt_tingkatan->get_result();
$tingkatan_name = $result_tingkatan_name->num_rows > 0 ? $result_tingkatan_name->fetch_assoc()['nama_tingkatan'] : '';
$stmt_tingkatan->close();

$query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
$stmt_kelas = $conn->prepare($query_kelas_name);
$stmt_kelas->bind_param("i", $kelas_id);
$stmt_kelas->execute();
$result_kelas_name = $stmt_kelas->get_result();
$kelas_name = $result_kelas_name->num_rows > 0 ? $result_kelas_name->fetch_assoc()['nama_kelas'] : '';
$stmt_kelas->close();

$formData = [
    'nama' => $nama,
    'username' => $username,
    'password' => generateRandomPassword(),
    'jurusan_id' => $jurusan_id,
    'tingkatan_kelas_id' => $tingkatan_kelas_id,
    'kelas_id' => $kelas_id,
    'no_rekening' => $no_rekening,
    'email' => $email,
    'tipe_rekening' => $tipe_rekening,
    'alamat_lengkap' => $alamat_lengkap,
    'nis_nisn' => $nis_nisn,
    'jenis_kelamin' => $jenis_kelamin,
    'tanggal_lahir' => $tanggal_lahir,
    'jurusan_name' => $jurusan_name,
    'tingkatan_name' => $tingkatan_name,
    'kelas_name' => $kelas_name
];

$encoded_data = base64_encode(json_encode($formData));
$_SESSION['form_token'] = bin2hex(random_bytes(32));
header('Location: buka_rekening.php?confirm=1&data=' . urlencode($encoded_data));
exit();
?>