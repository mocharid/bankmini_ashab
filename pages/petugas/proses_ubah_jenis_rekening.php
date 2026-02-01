<?php
/**
 * Proses Ubah Jenis Rekening - Adaptive Path Version
 * File: pages/petugas/proses_ubah_jenis_rekening.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/proses_ubah_jenis_rekening.php
 * - Hosting: public_html/pages/petugas/proses_ubah_jenis_rekening.php
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
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
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';
require_once VENDOR_PATH . '/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set JSON header
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Akses tidak diizinkan']);
    exit();
}

// Function to generate unique username
function generateUsername($nama, $conn)
{
    $base_username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    $username = $base_username;
    $counter = 1;

    $check_query = "SELECT id FROM users WHERE username = ?";
    $check_stmt = $conn->prepare($check_query);

    if (!$check_stmt) {
        error_log("Error preparing username check: " . $conn->error);
        return $base_username . rand(100, 999);
    }

    $check_stmt->bind_param("s", $username);
    $check_stmt->execute();
    $result = $check_stmt->get_result();

    while ($result->num_rows > 0) {
        $username = $base_username . $counter;
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $counter++;
    }

    $check_stmt->close();
    return $username;
}

// Function to send email confirmation  
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $tipe_lama = 'Fisik', $tipe_baru = 'Digital')
{
    if (empty($email)) {
        error_log("Skipping email sending as email is empty");
        return ['success' => true];
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
        $mail->Subject = 'Perubahan Jenis Rekening';

        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname(dirname($_SERVER['PHP_SELF']));
        $login_link = $protocol . "://" . $host . $path . "/login.php";

        $kelas_lengkap = trim($tingkatan_name . ' ' . $kelas_name);

        // Template Email Sederhana & Rapi
        $emailBody = "
        <div style='font-family: Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333333; line-height: 1.6;'>
            
            <h2 style='color: #1e3a8a; margin-bottom: 20px; font-size: 24px;'>Perubahan Jenis Rekening Berhasil</h2>
            
            <p>Halo <strong>{$nama}</strong>,</p>
            
            <p>Permintaan perubahan jenis rekening Anda telah kami proses. Saat ini status rekening Anda adalah <strong>{$tipe_baru}</strong>.</p>
            
            <div style='margin: 30px 0; border-top: 1px solid #eeeeee; border-bottom: 1px solid #eeeeee; padding: 20px 0;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Detail Akun</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 5px 0; width: 140px; color: #666;'>No. Rekening</td>
                        <td style='font-weight: bold;'>{$no_rekening}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Kelas/Jurusan</td>
                        <td>{$kelas_lengkap} - {$jurusan_name}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Jenis Rekening</td>
                        <td>{$tipe_lama} &rarr; <strong style='color: #10b981;'>{$tipe_baru}</strong></td>
                    </tr>
                </table>
            </div>

            <div style='margin-bottom: 30px;'>
                <h3 style='font-size: 18px; margin-top: 0; color: #444;'>Kredensial Login</h3>
                <p style='margin-bottom: 5px;'>Gunakan data berikut untuk masuk ke aplikasi:</p>
                <table style='width: 100%; background-color: #f9fafb; border-radius: 6px; padding: 15px;'>
                    <tr>
                        <td style='padding: 5px 0; width: 100px; color: #666;'>Username</td>
                        <td style='font-family: monospace; font-size: 16px; font-weight: bold;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #666;'>Password</td>
                        <td style='font-family: monospace; font-size: 16px; font-weight: bold; color: #dc2626;'>{$password}</td>
                    </tr>
                </table>
                <p style='font-size: 13px; color: #666; margin-top: 10px;'>*Harap segera ganti password Anda setelah login pertama kali demi keamanan.</p>
            </div>

            <div style='text-align: left; margin-bottom: 40px;'>
                <a href='{$login_link}' style='background-color: #1e3a8a; color: white; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;'>Login Sekarang</a>
            </div>

            <hr style='border: none; border-top: 1px solid #eeeeee; margin: 30px 0;'>
            
            <p style='font-size: 12px; color: #999;'>
                Ini adalah pesan otomatis dari sistem KASDIG.<br>
                Jika Anda tidak merasa melakukan perubahan ini, silakan hubungi petugas sekolah.
            </p>
        </div>";

        $mail->Body = $emailBody;
        $mail->AltBody = "Halo {$nama}, Jenis rekening Anda berhasil diubah menjadi {$tipe_baru}. Login dengan Username: {$username} dan Password: {$password}. Segera ganti password Anda.";

        if ($mail->send()) {
            return ['success' => true];
        }
    } catch (Exception $e) {
        error_log("Gagal mengirim email: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
    return ['success' => false];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    $received_token = trim($_POST['token'] ?? '');
    $session_token = $_SESSION['form_token'] ?? '';

    if (empty($received_token) || empty($session_token)) {
        echo json_encode(['success' => false, 'message' => 'Token tidak ditemukan! Refresh halaman dan coba lagi.']);
        exit();
    }

    if ($received_token !== $session_token) {
        echo json_encode(['success' => false, 'message' => 'Token tidak valid! Session expired. Refresh halaman.']);
        exit();
    }

    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $new_tipe = trim($_POST['new_tipe_rekening'] ?? '');
    $user_id = (int) ($_POST['user_id'] ?? 0);

    // Get verification inputs based on type
    $email_siswa = trim($_POST['email_siswa'] ?? ''); // For fisik → digital
    $pin_input = trim($_POST['pin_input'] ?? ''); // For fisik → digital
    $password_input = trim($_POST['password_input'] ?? ''); // For digital → fisik

    // Basic validation
    if (empty($no_rekening) || empty($user_id) || !in_array($new_tipe, ['digital', 'fisik'])) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap!']);
        exit();
    }

    // Get user data
    $query_user = "SELECT u.*, r.no_rekening, j.nama_jurusan, tk.nama_tingkatan, k.nama_kelas, sp.*, us.pin, us.has_pin 
                   FROM users u 
                   JOIN rekening r ON u.id = r.user_id 
                   JOIN siswa_profiles sp ON u.id = sp.user_id 
                   JOIN user_security us ON u.id = us.user_id 
                   LEFT JOIN jurusan j ON sp.jurusan_id = j.id 
                   LEFT JOIN kelas k ON sp.kelas_id = k.id 
                   LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id 
                   WHERE u.id = ? AND u.role = 'siswa'";
    $stmt_user = $conn->prepare($query_user);

    if (!$stmt_user) {
        error_log("Prepare failed: " . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Gagal memuat data siswa!']);
        exit();
    }

    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Nasabah tidak ditemukan!']);
        $stmt_user->close();
        exit();
    }

    $selected_user = $result_user->fetch_assoc();
    $stmt_user->close();

    $current_tipe = $selected_user['email_status'] === 'terisi' ? 'digital' : 'fisik';

    if ($new_tipe === $current_tipe) {
        echo json_encode(['success' => false, 'message' => 'Jenis rekening sudah sama dengan saat ini!']);
        exit();
    }

    // Verify based on direction
    if ($new_tipe === 'fisik') {
        // Digital → Fisik: verify PIN (same as fisik → digital)
        if (empty($pin_input)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN wajib diisi untuk verifikasi!'
            ]);
            exit();
        }

        if (strlen($pin_input) !== 6 || !ctype_digit($pin_input)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN harus 6 digit angka!'
            ]);
            exit();
        }

        // Check if user has PIN
        if (!$selected_user['has_pin'] || $selected_user['has_pin'] == 0) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'Siswa belum memiliki PIN! Silakan atur PIN terlebih dahulu.'
            ]);
            exit();
        }

        if (empty($selected_user['pin'])) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN siswa belum terdaftar!'
            ]);
            exit();
        }

        if (!password_verify($pin_input, $selected_user['pin'])) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN siswa salah! Verifikasi gagal.'
            ]);
            exit();
        }
    } else {
        // Fisik → Digital: verify PIN + validate email
        if (empty($email_siswa)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'Email wajib diisi!'
            ]);
            exit();
        }

        if (!filter_var($email_siswa, FILTER_VALIDATE_EMAIL)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'Format email tidak valid!'
            ]);
            exit();
        }

        // Check if email already exists
        $check_email_query = "SELECT id FROM users WHERE email = ? AND id != ?";
        $check_email_stmt = $conn->prepare($check_email_query);
        $check_email_stmt->bind_param("si", $email_siswa, $user_id);
        $check_email_stmt->execute();
        $email_exists = $check_email_stmt->get_result()->num_rows > 0;
        $check_email_stmt->close();

        if ($email_exists) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'Email sudah digunakan oleh pengguna lain!'
            ]);
            exit();
        }

        if (empty($pin_input)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN wajib diisi untuk verifikasi!'
            ]);
            exit();
        }

        if (strlen($pin_input) !== 6 || !ctype_digit($pin_input)) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN harus 6 digit angka!'
            ]);
            exit();
        }

        // Check if user has PIN
        if (!$selected_user['has_pin'] || $selected_user['has_pin'] == 0) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'Siswa belum memiliki PIN! Silakan atur PIN terlebih dahulu.'
            ]);
            exit();
        }

        if (empty($selected_user['pin'])) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN siswa belum terdaftar!'
            ]);
            exit();
        }

        if (!password_verify($pin_input, $selected_user['pin'])) {
            echo json_encode([
                'success' => false,
                'error_type' => 'verification',
                'message' => 'PIN siswa salah! Verifikasi gagal.'
            ]);
            exit();
        }
    }

    // Process update
    $conn->begin_transaction();
    try {
        $password = '12345'; // Default password
        $email_status = $new_tipe === 'digital' ? 'terisi' : 'kosong';

        $new_username = null;
        $new_email = null;

        if ($new_tipe === 'digital') {
            // Generate username for fisik → digital, use provided email
            $new_username = generateUsername($selected_user['nama'], $conn);
            $new_email = $email_siswa; // Use user-provided email

            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $query_update = "UPDATE users SET email = ?, email_status = ?, username = ?, password = ? WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);

            if (!$stmt_update) {
                throw new Exception("Gagal menyiapkan update: " . $conn->error);
            }

            $stmt_update->bind_param("ssssi", $new_email, $email_status, $new_username, $hashed_password, $user_id);
        } else {
            // Digital → Fisik: clear email, username, password
            $query_update = "UPDATE users SET email = NULL, email_status = ?, username = NULL, password = NULL WHERE id = ?";
            $stmt_update = $conn->prepare($query_update);

            if (!$stmt_update) {
                throw new Exception("Gagal menyiapkan update: " . $conn->error);
            }

            $stmt_update->bind_param("si", $email_status, $user_id);
        }

        if (!$stmt_update->execute()) {
            throw new Exception("Gagal update jenis: " . $stmt_update->error);
        }
        $stmt_update->close();

        $tipe_display = $new_tipe === 'digital' ? 'Digital' : 'Fisik';
        $tipe_lama_display = $current_tipe === 'digital' ? 'Digital' : 'Fisik';

        // Send email for fisik → digital
        if ($new_tipe === 'digital') {
            $email_result = sendEmailConfirmation(
                $new_email,
                $selected_user['nama'],
                $new_username,
                $selected_user['no_rekening'],
                $password,
                $selected_user['nama_jurusan'] ?: 'N/A',
                $selected_user['nama_tingkatan'] ?: 'N/A',
                $selected_user['nama_kelas'] ?: 'N/A',
                $tipe_lama_display,
                $tipe_display
            );

            if (!$email_result['success']) {
                error_log("Email sending failed but continuing: " . ($email_result['error'] ?? 'Unknown error'));
            }
        }

        $conn->commit();

        if ($new_tipe === 'digital') {
            $success_msg = "Jenis rekening berhasil diubah menjadi {$tipe_display}! Kredensial login telah dikirim ke email {$new_email}.";
        } else {
            $success_msg = "Jenis rekening berhasil diubah menjadi {$tipe_display}! Email, username, dan password telah dihapus dari sistem.";
        }

        // Generate NEW token only after successful commit
        $new_token = bin2hex(random_bytes(32));
        $_SESSION['form_token'] = $new_token;

        echo json_encode([
            'success' => true,
            'message' => $success_msg,
            'new_token' => $new_token // Send new token to frontend
        ]);
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Update jenis gagal: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Gagal mengubah jenis: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Invalid request
echo json_encode(['success' => false, 'message' => 'Request tidak valid! Metode harus POST dengan token yang valid.']);
exit();
?>