<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php'; // Sesuaikan path ke autoload Composer
date_default_timezone_set('Asia/Jakarta'); // Set timezone ke WIB

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = '';
$error = '';

// Ambil data user lengkap dan rekening
$query = "SELECT u.username, u.nama, u.role, u.email, k.nama_kelas, j.nama_jurusan, 
          r.no_rekening, r.saldo, u.created_at as tanggal_bergabung, u.pin, u.jurusan_id, u.kelas_id
          FROM users u 
          LEFT JOIN rekening r ON u.id = r.user_id 
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("Data pengguna tidak ditemukan.");
}

// Fungsi untuk mengirim email
function sendEmail($email, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Ganti dengan SMTP host Anda
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com'; // Ganti dengan email pengirim
        $mail->Password = 'spjs plkg ktuu lcxh'; // Ganti dengan password email pengirim
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail error: " . $mail->ErrorInfo);
        return false;
    }
}

// Handle form submission
if (isset($_POST['update_username'])) {
    $new_username = trim($_POST['new_username']);

    if (empty($new_username)) {
        $error = "Username tidak boleh kosong!";
    } elseif ($new_username === $user['username']) {
        $error = "Username baru tidak boleh sama dengan username saat ini!";
    } else {
        // Cek apakah username sudah digunakan oleh pengguna lain
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $new_username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Username sudah digunakan oleh pengguna lain!";
        } else {
            // Jika username tersedia, lakukan update
            $update_query = "UPDATE users SET username = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
            
            if ($update_stmt->execute()) {
                $message = "Username berhasil diubah!";

                // Template email untuk perubahan username
                $subject = "Username Berhasil Diubah - SCHOBANK SYSTEM";
                $email_body = "
                <!DOCTYPE html>
                <html lang='id'>
                <head>
                    <meta charset='UTF-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                    <title>Username Berhasil Diubah</title>
                    <style>
                        body { font-family: 'Poppins', sans-serif; background-color: #f8faff; color: #333; margin: 0; padding: 0; }
                        .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 15px; overflow: hidden; box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1); }
                        .email-header { background: linear-gradient(135deg, #0c4da2 0%, #0a2e5c 100%); color: white; padding: 30px; text-align: center; }
                        .email-header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                        .email-body { padding: 30px; color: #666; }
                        .email-body h2 { font-size: 20px; color: #0c4da2; margin-top: 0; }
                        .email-body p { font-size: 16px; line-height: 1.6; }
                        .email-footer { background-color: #f1f5f9; padding: 20px; text-align: center; font-size: 14px; color: #666; }
                        .email-footer a { color: #0c4da2; text-decoration: none; }
                        .btn { display: inline-block; padding: 12px 25px; background-color: #0c4da2; color: white; text-decoration: none; border-radius: 10px; font-size: 16px; margin-top: 20px; transition: background-color 0.3s ease; }
                        .btn:hover { background-color: #0a2e5c; }
                        .highlight { background-color: #e0e9f5; padding: 10px; border-radius: 10px; font-weight: 500; color: #0c4da2; margin: 20px 0; }
                    </style>
                </head>
                <body>
                    <div class='email-container'>
                        <div class='email-header'>
                            <h1>SCHOBANK SYSTEM</h1>
                        </div>
                        <div class='email-body'>
                            <h2>Username Berhasil Diubah</h2>
                            <p>Halo, <strong>{$user['nama']}</strong>!</p>
                            <p>Username akun SCHOBANK Anda telah berhasil diubah menjadi:</p>
                            <div class='highlight'>{$new_username}</div>
                            <p>Perubahan ini dilakukan pada <strong>" . date('d M Y H:i:s') . " WIB</strong>.</p>
                            <p>Jika Anda tidak melakukan perubahan ini, segera hubungi administrator kami.</p>
                        </div>
                        <div class='email-footer'>
                            <p>&copy; " . date('Y') . " SCHOBANK. Semua hak dilindungi undang-undang.</p>
                        </div>
                    </div>
                </body>
                </html>
                ";

                // Kirim email
                if (sendEmail($user['email'], $subject, $email_body)) {
                    logout();
                } else {
                    $error = "Gagal mengirim email notifikasi!";
                }
            } else {
                $error = "Gagal mengubah username!";
            }
        }
    }
} elseif (isset($_POST['update_password'])) {
    // Password update code
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "Semua field password harus diisi!";
    } elseif ($new_password !== $confirm_password) {
        $error = "Password baru tidak cocok!";
    } elseif (strlen($new_password) < 6) {
        $error = "Password baru minimal 6 karakter!";
    } else {
        // Get current password from database
        $verify_query = "SELECT password FROM users WHERE id = ?";
        $verify_stmt = $conn->prepare($verify_query);
        $verify_stmt->bind_param("i", $_SESSION['user_id']);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $user_data = $verify_result->fetch_assoc();
        
        if (!$user_data || !isset($user_data['password'])) {
            $error = "Tidak dapat memverifikasi password, silakan coba lagi nanti.";
        } else {
            $stored_hash = $user_data['password'];
            
            // Check if the hash is a SHA-256 hash (64 characters in length)
            $is_sha256 = (strlen($stored_hash) == 64 && ctype_xdigit($stored_hash));
            
            // Verify the password based on hash type
            $password_verified = false;
            
            if ($is_sha256) {
                // For SHA-256 hashed passwords
                $password_verified = (hash('sha256', $current_password) === $stored_hash);
            } else {
                // For passwords hashed with password_hash()
                $password_verified = password_verify($current_password, $stored_hash);
            }
            
            if ($password_verified) {
                // Use consistent hashing method - if original was SHA-256, use SHA-256 for new password too
                if ($is_sha256) {
                    $hashed_password = hash('sha256', $new_password);
                } else {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                }
                
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                
                if ($update_stmt->execute()) {
                    $message = "Password berhasil diubah! Anda dapat menggunakan password baru untuk login selanjutnya.";

                    // Kirim email notifikasi
                    $subject = "Password Berhasil Diubah - SCHOBANK SYSTEM";
                    $message_email = "
                    <html>
                    <body>
                        <h2>Password Berhasil Diubah</h2>
                        <p>Halo, <strong>{$user['nama']}</strong>!</p>
                        <p>Password akun SCHOBANK Anda telah berhasil diubah pada " . date('d M Y H:i:s') . " WIB.</p>
                        <p>Jika Anda tidak melakukan perubahan ini, segera hubungi administrator.</p>
                        <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
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
                        $mail->addAddress($user['email'], $user['nama']);

                        $mail->isHTML(true);
                        $mail->Subject = $subject;
                        $mail->Body = $message_email;
                        $mail->AltBody = strip_tags($message_email);

                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Mail error: " . $mail->ErrorInfo);
                    }

                    logout();
                } else {
                    $error = "Gagal mengubah password!";
                }
            } else {
                $error = "Password saat ini tidak valid!";
            }
        }
    }
} elseif (isset($_POST['update_pin'])) {
    // PIN update code
    $new_pin = $_POST['new_pin'];
    $confirm_pin = $_POST['confirm_pin'];

    if (empty($new_pin) || empty($confirm_pin)) {
        $error = "Semua field PIN harus diisi!";
    } elseif ($new_pin !== $confirm_pin) {
        $error = "PIN baru tidak cocok!";
    } elseif (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
        $error = "PIN harus terdiri dari 6 digit angka!";
    } else {
        // Verify current PIN first if user already has a PIN
        if (!empty($user['pin'])) {
            // Check if current_pin exists in POST
            if (!isset($_POST['current_pin']) || empty($_POST['current_pin'])) {
                $error = "PIN saat ini harus diisi!";
            } elseif ($_POST['current_pin'] !== $user['pin']) {
                $error = "PIN saat ini tidak valid!";
            } else {
                // Current PIN is correct, proceed with update
                $update_query = "UPDATE users SET pin = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

                if ($update_stmt->execute()) {
                    $message = "PIN berhasil diubah!";

                    // Kirim email notifikasi
                    $subject = "PIN Berhasil Diubah - SCHOBANK SYSTEM";
                    $message_email = "
                    <html>
                    <body>
                        <h2>PIN Berhasil Diubah</h2>
                        <p>Halo, <strong>{$user['nama']}</strong>!</p>
                        <p>PIN akun SCHOBANK Anda telah berhasil diubah pada " . date('d M Y H:i:s') . " WIB.</p>
                        <p>Jika Anda tidak melakukan perubahan ini, segera hubungi administrator.</p>
                        <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
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
                        $mail->addAddress($user['email'], $user['nama']);

                        $mail->isHTML(true);
                        $mail->Subject = $subject;
                        $mail->Body = $message_email;
                        $mail->AltBody = strip_tags($message_email);

                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Mail error: " . $mail->ErrorInfo);
                    }

                    $user['pin'] = $new_pin;
                } else {
                    $error = "Gagal mengubah PIN!";
                }
            }
        } else {
            // User doesn't have a PIN yet, allow creating new PIN without verification
            $update_query = "UPDATE users SET pin = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

            if ($update_stmt->execute()) {
                $message = "PIN berhasil dibuat!";

                // Kirim email notifikasi
                $subject = "PIN Berhasil Dibuat - SCHOBANK SYSTEM";
                $message_email = "
                <html>
                <body>
                    <h2>PIN Berhasil Dibuat</h2>
                    <p>Halo, <strong>{$user['nama']}</strong>!</p>
                    <p>PIN akun SCHOBANK Anda telah berhasil dibuat pada " . date('d M Y H:i:s') . " WIB.</p>
                    <p>Jika Anda tidak melakukan perubahan ini, segera hubungi administrator.</p>
                    <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
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
                    $mail->addAddress($user['email'], $user['nama']);

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body = $message_email;
                    $mail->AltBody = strip_tags($message_email);

                    $mail->send();
                } catch (Exception $e) {
                    error_log("Mail error: " . $mail->ErrorInfo);
                }

                $user['pin'] = $new_pin;
            } else {
                $error = "Gagal membuat PIN!";
            }
        }
    }
} elseif (isset($_POST['update_email'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Email tidak boleh kosong!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Format email tidak valid!";
    } else {
        // Cek apakah email sudah digunakan oleh pengguna lain
        $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Email sudah digunakan oleh pengguna lain!";
        } else {
            // Generate OTP
            $otp = rand(100000, 999999);
            $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes')); // OTP berlaku 5 menit

            // Simpan OTP ke database
            $update_query = "UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssi", $otp, $expiry, $_SESSION['user_id']);

            if ($update_stmt->execute()) {
                // Kirim OTP ke email
                try {
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
                    $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
                    $mail->addAddress($email, $user['nama']);

                    // Konten email
                    $mail->isHTML(true);
                    $mail->Subject = "Verifikasi Email - SCHOBANK SYSTEM";
                    $mail->Body = "
                        <html>
                        <body>
                            <h2>Verifikasi Email</h2>
                            <p>Halo, <strong>{$user['nama']}</strong>!</p>
                            <p>Kode OTP Anda adalah: <strong>{$otp}</strong>.</p>
                            <p>Kode ini berlaku hingga " . date('d M Y H:i:s', strtotime($expiry)) . " WIB.</p>
                            <p>Jika Anda tidak melakukan permintaan ini, abaikan email ini.</p>
                            <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                        </body>
                        </html>
                    ";

                    // Kirim email
                    $mail->send();
                    $_SESSION['new_email'] = $email; // Simpan email baru di session
                    header("Location: verify_otp.php"); // Redirect ke halaman verifikasi OTP
                    exit();
                } catch (Exception $e) {
                    error_log("Mail error: " . $mail->ErrorInfo); // Log error
                    $error = "Gagal mengirim OTP. Silakan coba lagi nanti.";
                }
            } else {
                $error = "Gagal menyimpan OTP. Silakan coba lagi.";
            }
        }
    }
} elseif (isset($_POST['update_kelas'])) {
    $new_kelas_id = $_POST['new_kelas_id'];
    
    if (empty($new_kelas_id)) {
        $error = "Kelas harus dipilih!";
    } elseif ($new_kelas_id == $user['kelas_id']) {
        $error = "Kelas baru tidak boleh sama dengan kelas saat ini!";
    } else {
        // Update kelas
        $update_query = "UPDATE users SET kelas_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ii", $new_kelas_id, $_SESSION['user_id']);
        
        if ($update_stmt->execute()) {
            $message = "Kelas berhasil diubah!";
            
            // Update user data
            $user['kelas_id'] = $new_kelas_id;
            
            // Get new class name
            $kelas_query = "SELECT nama_kelas FROM kelas WHERE id = ?";
            $kelas_stmt = $conn->prepare($kelas_query);
            $kelas_stmt->bind_param("i", $new_kelas_id);
            $kelas_stmt->execute();
            $kelas_result = $kelas_stmt->get_result();
            $kelas_data = $kelas_result->fetch_assoc();
            $user['nama_kelas'] = $kelas_data['nama_kelas'];
            
            // Kirim email notifikasi
            $subject = "Perubahan Kelas - SCHOBANK SYSTEM";
            $message_email = "
            <html>
            <body>
                <h2>Kelas Berhasil Diubah</h2>
                <p>Halo, <strong>{$user['nama']}</strong>!</p>
                <p>Kelas Anda telah berhasil diubah menjadi:</p>
                <p><strong>{$user['nama_kelas']}</strong></p>
                <p>Perubahan ini dilakukan pada " . date('d M Y H:i:s') . " WIB.</p>
                <p>Jika Anda tidak melakukan perubahan ini, segera hubungi administrator.</p>
                <p>&copy; " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
            </body>
            </html>
            ";
            
            if (sendEmail($user['email'], $subject, $message_email)) {
                $message .= " Notifikasi telah dikirim ke email Anda.";
            } else {
                $error = "Gagal mengirim email notifikasi!";
            }
        } else {
            $error = "Gagal mengubah kelas!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Profil Pengguna - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
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
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            text-decoration: none;
            z-index: 2;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .profile-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .profile-card, .edit-form-container {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            overflow: hidden;
        }

        .profile-card:hover, .edit-form-container:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .profile-header, .edit-form-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 15px 20px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .profile-header i, .edit-form-header i {
            margin-right: 10px;
            font-size: 20px;
        }

        .edit-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .edit-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .profile-info {
            padding: 20px;
        }

        .info-item {
            padding: 12px 0;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #eee;
            position: relative;
        }

        .info-item:last-child {
            border-bottom: none;
        }

        .info-item i {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary-color);
            border-radius: 10px;
            margin-right: 15px;
            font-size: 16px;
        }

        .info-item .label {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 3px;
        }

        .info-item .value {
            font-size: 16px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .info-item .edit-icon {
            position: absolute;
            right: 10px;
            background: var(--primary-light);
            color: var(--primary-color);
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
        }

        .info-item .edit-icon:hover {
            background: var(--primary-color);
            color: white;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            background: var(--primary-light);
            color: var(--primary-color);
        }

        .edit-form-content {
            padding: 20px;
            display: none;
        }

        .edit-form-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group:last-child {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            padding-left: 40px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            transition: var(--transition);
        }

        .form-group i.input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 16px;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .form-group .password-toggle {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
        }

        .form-group .password-toggle:hover {
            color: var(--primary-color);
        }

        .btn {
            display: inline-block;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 500;
            font-size: 15px;
            cursor: pointer;
            transition: var(--transition);
            border: none;
            text-align: center;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-light);
        }

        .btn-container {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn i {
            margin-right: 8px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            animation: slideIn 0.5s ease-out;
            display: flex;
            align-items: center;
            max-width: 350px;
            box-shadow: var(--shadow-md);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .alert.hide {
            animation: slideOut 0.5s ease-in forwards;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 5px solid #34d399;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 5px solid #f87171;
        }

        .alert i {
            margin-right: 15px;
            font-size: 20px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-content p {
            margin: 0;
        }

        .alert .close-alert {
            background: none;
            border: none;
            color: inherit;
            font-size: 16px;
            cursor: pointer;
            margin-left: 15px;
            opacity: 0.7;
            transition: var(--transition);
        }

        .alert .close-alert:hover {
            opacity: 1;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* Loader animation for buttons */
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
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .btn-container {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
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
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 20px;
            border-radius: 15px;
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 500px;
            position: relative;
            animation: fadeIn 0.3s ease-in;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
        }

        .modal-header .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
        }

        .modal-header .close-modal:hover {
            color: var(--primary-color);
        }

        .modal-body {
            margin-bottom: 20px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
                /* CSS untuk dropdown kelas */
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            background-color: white;
            color: #333;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 10px center;
            background-size: 15px;
            transition: border-color 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: #0c4da2;
            box-shadow: 0 0 0 2px rgba(12, 77, 162, 0.2);
        }

        /* Style untuk opsi dropdown */
        .form-control option {
            padding: 10px;
            background-color: white;
            color: #333;
        }

        /* Style untuk hover option */
        .form-control option:hover {
            background-color: #f0f5ff;
        }

        /* Style untuk selected option */
        .form-control option:checked {
            background-color: #e0e9f5;
            color: #0c4da2;
            font-weight: 500;
        }

        /* Style untuk disabled option */
        .form-control option:disabled {
            color: #999;
            background-color: #f5f5f5;
        }

        /* Style untuk grup optgroup */
        .form-control optgroup {
            font-weight: 600;
            color: #0c4da2;
            background-color: #f0f5ff;
            padding: 5px;
        }

        /* Style untuk option dalam optgroup */
        .form-control optgroup option {
            padding-left: 20px;
            font-weight: normal;
            color: #333;
        }
        </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>
    
    <div class="main-content">
        <div class="welcome-banner">
            <h2>Profil Pengguna</h2>
            <p>Kelola informasi dan pengaturan profil Anda</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <!-- Alerts -->
        <?php if ($message): ?>
            <div id="successAlert" class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($message) ?></p>
                </div>
                <button class="close-alert" onclick="dismissAlert(this.parentElement)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div id="errorAlert" class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <div class="alert-content">
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
                <button class="close-alert" onclick="dismissAlert(this.parentElement)">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        <?php endif; ?>

        <div class="profile-container">
            <!-- Informasi Pribadi -->
            <div class="profile-card">
                <div class="profile-header">
                    <div>
                        <i class="fas fa-user-circle"></i> Informasi Pribadi
                    </div>
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <i class="fas fa-user"></i>
                        <div>
                            <div class="label">Nama Lengkap</div>
                            <div class="value"><?= htmlspecialchars($user['nama']) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-user-tag"></i>
                        <div>
                            <div class="label">Username</div>
                            <div class="value"><?= htmlspecialchars($user['username']) ?></div>
                        </div>
                        <button class="edit-icon" data-target="username">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>

                    <div class="info-item">
                        <i class="fas fa-lock"></i>
                        <div>
                            <div class="label">Password</div>
                            <div class="value">••••••••</div>
                        </div>
                        <button class="edit-icon" data-target="password">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-envelope"></i>
                        <div>
                            <div class="label">Email</div>
                            <div class="value">
                                <?= !empty($user['email']) ? htmlspecialchars($user['email']) : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-icon" data-target="email">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-graduation-cap"></i>
                        <div>
                            <div class="label">Jurusan</div>
                            <div class="value"><?= htmlspecialchars($user['nama_jurusan']) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-users"></i>
                        <div>
                            <div class="label">Kelas</div>
                            <div class="value"><?= htmlspecialchars($user['nama_kelas']) ?></div>
                        </div>
                        <button class="edit-icon" data-target="kelas">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-user-shield"></i>
                        <div>
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="badge"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div>
                            <div class="label">Bergabung Sejak</div>
                            <div class="value"><?= date('d F Y', strtotime($user['tanggal_bergabung'])) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-key"></i>
                        <div>
                            <div class="label">PIN</div>
                            <div class="value">
                                <?= !empty($user['pin']) ? '••••••' : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-icon" data-target="pin">
                            <i class="fas fa-pen"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for Edit Username -->
    <div id="usernameModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Ubah Username</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="usernameForm">
                    <div class="form-group">
                        <label for="new_username">Username Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" id="new_username" name="new_username" required placeholder="Masukkan username baru">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_username" class="btn btn-primary" id="usernameBtn">
                            <i class="fas fa-save"></i> <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Edit Email -->
    <div id="emailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-envelope"></i> Ubah Email</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="emailForm">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <div class="input-wrapper">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" id="email" name="email" required placeholder="Masukkan email" value="<?= !empty($user['email']) ? htmlspecialchars($user['email']) : '' ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_email" class="btn btn-primary" id="emailBtn">
                            <i class="fas fa-save"></i> <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Verify OTP -->
    <div id="otpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-shield-alt"></i> Verifikasi OTP</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="otpForm">
                    <div class="form-group">
                        <label for="otp">Kode OTP</label>
                        <div class="input-wrapper">
                            <i class="fas fa-shield-alt input-icon"></i>
                            <input type="text" id="otp" name="otp" required placeholder="Masukkan kode OTP">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="verify_otp" class="btn btn-primary" id="otpBtn">
                            <i class="fas fa-check"></i> <span>Verifikasi</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Edit Password -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Ubah Password</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="passwordForm">
                    <div class="form-group">
                        <label for="current_password">Password Saat Ini</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="current_password" name="current_password" required placeholder="Password saat ini">
                            <i class="fas fa-eye password-toggle" data-target="current_password"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_password">Password Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="new_password" name="new_password" required placeholder="Masukkan password baru">
                            <i class="fas fa-eye password-toggle" data-target="new_password"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Konfirmasi Password Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-check-circle input-icon"></i>
                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password baru">
                            <i class="fas fa-eye password-toggle" data-target="confirm_password"></i>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_password" class="btn btn-primary" id="passwordBtn">
                            <i class="fas fa-save"></i> <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Edit PIN -->
    <div id="pinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Buat/Ubah PIN</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="pinForm">
                    <?php if (!empty($user['pin'])): ?>
                    <div class="form-group">
                        <label for="current_pin">PIN Saat Ini</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key input-icon"></i>
                            <input type="password" id="current_pin" name="current_pin" required placeholder="Masukkan PIN saat ini" maxlength="6">
                            <i class="fas fa-eye password-toggle" data-target="current_pin"></i>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="new_pin">PIN Baru (6 digit angka)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" id="new_pin" name="new_pin" required placeholder="Masukkan PIN baru" maxlength="6">
                            <i class="fas fa-eye password-toggle" data-target="new_pin"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_pin">Konfirmasi PIN Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-check-circle input-icon"></i>
                            <input type="password" id="confirm_pin" name="confirm_pin" required placeholder="Konfirmasi PIN baru" maxlength="6">
                            <i class="fas fa-eye password-toggle" data-target="confirm_pin"></i>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_pin" class="btn btn-primary" id="pinBtn">
                            <i class="fas fa-save"></i> <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Edit Kelas -->
    <div id="kelasModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-users-class"></i> Ubah Kelas</h3>
                <button class="close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="kelasForm">
                    <div class="form-group">
                        <label for="new_kelas_id">Kelas Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-users-class input-icon"></i>
                            <select id="new_kelas_id" name="new_kelas_id" required class="form-control">
                                <option value="">Pilih Kelas</option>
                                <?php
                                // Get classes for the current major
                                $kelas_query = "SELECT k.id, k.nama_kelas FROM kelas k 
                                               WHERE k.jurusan_id = ? 
                                               ORDER BY k.nama_kelas";
                                $kelas_stmt = $conn->prepare($kelas_query);
                                $kelas_stmt->bind_param("i", $user['jurusan_id']);
                                $kelas_stmt->execute();
                                $kelas_result = $kelas_stmt->get_result();
                                
                                while ($kelas = $kelas_result->fetch_assoc()) {
                                    $selected = ($kelas['id'] == $user['kelas_id']) ? 'selected' : '';
                                    echo '<option value="' . $kelas['id'] . '" ' . $selected . '>' . htmlspecialchars($kelas['nama_kelas']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_kelas" class="btn btn-primary" id="kelasBtn">
                            <i class="fas fa-save"></i> <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <i class="fas fa-times"></i> <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Handle alerts
        function dismissAlert(alert) {
            alert.classList.add('hide');
            setTimeout(() => {
                alert.remove();
            }, 500);
        }

        // Auto dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    dismissAlert(alert);
                }, 5000);
            });

            // Redirect to login page after successful update
            if (window.location.href.indexOf('success') > -1) {
                setTimeout(() => {
                    window.location.href = '../../login.php';
                }, 3000);
            }

            // Toggle password visibility
            const toggles = document.querySelectorAll('.password-toggle');
            toggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    
                    if (passwordInput.type === 'password') {
                        passwordInput.type = 'text';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    } else {
                        passwordInput.type = 'password';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    }
                });
            });

            // Handle modal open and close
            const editIcons = document.querySelectorAll('.edit-icon');
            const modals = document.querySelectorAll('.modal');
            const closeModalButtons = document.querySelectorAll('.close-modal');

            // Open modal when edit icon is clicked
            editIcons.forEach(icon => {
                icon.addEventListener('click', function() {
                    const target = this.getAttribute('data-target');
                    const modal = document.getElementById(target + 'Modal');
                    if (modal) {
                        modal.style.display = 'block';
                    }
                });
            });

            // Close modal when close button is clicked
            closeModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal) {
                        modal.style.display = 'none';
                    }
                });
            });

            // Close modal when clicking outside the modal
            window.addEventListener('click', function(event) {
                modals.forEach(modal => {
                    if (event.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            });

            // Form submission with loading animation
            document.getElementById('usernameForm').addEventListener('submit', function() {
                const button = document.getElementById('usernameBtn');
                button.classList.add('btn-loading');
            });

            document.getElementById('passwordForm').addEventListener('submit', function() {
                const button = document.getElementById('passwordBtn');
                button.classList.add('btn-loading');
            });
            
            document.getElementById('pinForm').addEventListener('submit', function() {
                const button = document.getElementById('pinBtn');
                button.classList.add('btn-loading');
            });

            document.getElementById('emailForm').addEventListener('submit', function() {
                const button = document.getElementById('emailBtn');
                button.classList.add('btn-loading');
            });

            document.getElementById('otpForm').addEventListener('submit', function() {
                const button = document.getElementById('otpBtn');
                button.classList.add('btn-loading');
            });

            document.getElementById('kelasForm').addEventListener('submit', function() {
                const button = document.getElementById('kelasBtn');
                button.classList.add('btn-loading');
            });
        });
    </script>
</body>
</html>