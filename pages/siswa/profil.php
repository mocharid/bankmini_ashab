<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php'; // Sesuaikan path ke autoload Composer
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
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
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

// Template email tanpa logo (karena localhost)
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
            <!-- Header -->
            <tr>
                <td style='padding: 20px; border-bottom: 2px solid #0c4da2;'>
                    <table width='100%' border='0' cellpadding='0' cellspacing='0'>
                        <tr>
                            <td style='font-size: calc(1rem + 0.2vw); font-weight: bold; color: #0c4da2;'>
                                SCHOBANK
                            </td>
                            <td style='text-align: right;'>
                                <span style='font-size: calc(0.75rem + 0.1vw); color: #666666;'>SCHOBANK SYSTEM</span><br>
                                <span style='font-size: calc(0.65rem + 0.1vw); color: #999999;'>Tanggal: " . date('d M Y') . "</span>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <!-- Body -->
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
            <!-- Footer -->
            <tr>
                <td style='padding: 20px; background-color: #f9f9f9; border-top: 1px solid #e0e0e0; font-size: calc(0.75rem + 0.1vw); color: #666666; text-align: center;'>
                    <p style='margin: 0 0 10px 0;'>Hubungi kami di <a href='mailto:support@schobank.com' style='color: #0c4da2; text-decoration: none;'>support@schobank.com</a> jika ada pertanyaan.</p>
                    <p style='margin: 0;'>© " . date('Y') . " SCHOBANK. Hak cipta dilindungi.</p>
                    <p style='margin: 10px 0 0 0; font-size: calc(0.7rem + 0.1vw); color: #999999;'>Email ini dikirim secara otomatis. Mohon tidak membalas email ini.</p>
                </td>
            </tr>
        </table>
    </body>
    </html>
    ";
}

// Handle form submission
if (isset($_POST['update_username'])) {
    $new_username = trim($_POST['new_username']);

    if (empty($new_username)) {
        $error = "Username tidak boleh kosong!";
    } elseif ($new_username === $user['username']) {
        $error = "Username baru tidak boleh sama dengan username saat ini!";
    } else {
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $new_username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = "Username sudah digunakan oleh pengguna lain!";
        } else {
            $update_query = "UPDATE users SET username = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
            
            if ($update_stmt->execute()) {
                $message = "Username berhasil diubah!";
                // MODIFIED: Kirim email hanya jika email tersedia
                if (!empty($user['email'])) {
                    $email_title = "Pemberitahuan Perubahan Username";
                    $email_greeting = "Yth. {$user['nama']},";
                    $email_content = "Kami informasikan bahwa username akun SCHOBANK Anda telah berhasil diperbarui menjadi: <strong>{$new_username}</strong>.";
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . date('d M Y H:i:s') . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                    if (sendEmail($user['email'], $email_title, $email_body)) {
                        logout();
                    } else {
                        $error = "Gagal mengirim email notifikasi!";
                    }
                } else {
                    logout(); // Lanjutkan logout jika email kosong
                }
            } else {
                $error = "Gagal mengubah username!";
            }
        }
    }
} elseif (isset($_POST['update_password'])) {
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
            $is_sha256 = (strlen($stored_hash) == 64 && ctype_xdigit($stored_hash));
            $password_verified = $is_sha256 ? (hash('sha256', $current_password) === $stored_hash) : password_verify($current_password, $stored_hash);
            
            if ($password_verified) {
                $hashed_password = $is_sha256 ? hash('sha256', $new_password) : password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                
                if ($update_stmt->execute()) {
                    $message = "Password berhasil diubah! Anda dapat menggunakan password baru untuk login selanjutnya.";
                    // MODIFIED: Kirim email hanya jika email tersedia
                    if (!empty($user['email'])) {
                        $email_title = "Pemberitahuan Perubahan Password";
                        $email_greeting = "Yth. {$user['nama']},";
                        $email_content = "Kami informasikan bahwa password akun SCHOBANK Anda telah berhasil diperbarui.";
                        $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . date('d M Y H:i:s') . " WIB</strong></p>
                                           <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                        $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                        if (sendEmail($user['email'], $email_title, $email_body)) {
                            logout();
                        } else {
                            $error = "Gagal mengirim email notifikasi!";
                        }
                    } else {
                        logout(); // Lanjutkan logout jika email kosong
                    }
                } else {
                    $error = "Gagal mengubah password!";
                }
            } else {
                $error = "Password saat ini tidak valid!";
            }
        }
    }
} elseif (isset($_POST['update_pin'])) {
    $new_pin = $_POST['new_pin'];
    $confirm_pin = $_POST['confirm_pin'];

    if (empty($new_pin) || empty($confirm_pin)) {
        $error = "Semua field PIN harus diisi!";
    } elseif ($new_pin !== $confirm_pin) {
        $error = "PIN baru tidak cocok!";
    } elseif (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
        $error = "PIN harus terdiri dari 6 digit angka!";
    } else {
        if (!empty($user['pin'])) {
            if (!isset($_POST['current_pin']) || empty($_POST['current_pin'])) {
                $error = "PIN saat ini harus diisi!";
            } elseif ($_POST['current_pin'] !== $user['pin']) {
                $error = "PIN saat ini tidak valid!";
            } else {
                $update_query = "UPDATE users SET pin = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

                if ($update_stmt->execute()) {
                    $message = "PIN berhasil diubah!";
                    // MODIFIED: Kirim email hanya jika email tersedia
                    if (!empty($user['email'])) {
                        $email_title = "Pemberitahuan Perubahan PIN";
                        $email_greeting = "Yth. {$user['nama']},";
                        $email_content = "Kami informasikan bahwa PIN akun SCHOBANK Anda telah berhasil diperbarui.";
                        $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . date('d M Y H:i:s') . " WIB</strong></p>
                                           <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                        $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                        if (!sendEmail($user['email'], $email_title, $email_body)) {
                            $error = "Gagal mengirim email notifikasi!";
                        }
                    }
                    $user['pin'] = $new_pin;
                } else {
                    $error = "Gagal mengubah PIN!";
                }
            }
        } else {
            $update_query = "UPDATE users SET pin = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);

            if ($update_stmt->execute()) {
                $message = "PIN berhasil dibuat!";
                // MODIFIED: Kirim email hanya jika email tersedia
                if (!empty($user['email'])) {
                    $email_title = "Pemberitahuan Pembuatan PIN";
                    $email_greeting = "Yth. {$user['nama']},";
                    $email_content = "Kami informasikan bahwa PIN akun SCHOBANK Anda telah berhasil dibuat.";
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Pembuatan: <strong>" . date('d M Y H:i:s') . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan pembuatan ini, segera hubungi kami.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                    if (!sendEmail($user['email'], $email_title, $email_body)) {
                        $error = "Gagal mengirim email notifikasi!";
                    }
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
        $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "You've already got that email registered with another account!";
        } else {
            $otp = rand(100000, 999999);
            $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
            $update_query = "UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssi", $otp, $expiry, $_SESSION['user_id']);

            if ($update_stmt->execute()) {
                $email_title = "Verifikasi Perubahan Email";
                $email_greeting = "Yth. {$user['nama']},";
                $email_content = "Kode OTP untuk verifikasi perubahan email Anda adalah: <strong>{$otp}</strong>";
                $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Kode ini berlaku hingga: <strong>" . date('d M Y H:i:s', strtotime($expiry)) . " WIB</strong></p>
                                   <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak meminta perubahan ini, abaikan email ini atau hubungi kami.</p>";
                $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                if (sendEmail($email, $email_title, $email_body)) {
                    $_SESSION['new_email'] = $email;
                    header("Location: verify_otp.php");
                    exit();
                } else {
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
        $update_query = "UPDATE users SET kelas_id = ? WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ii", $new_kelas_id, $_SESSION['user_id']);
        
        if ($update_stmt->execute()) {
            $message = "Kelas berhasil diubah!";
            $user['kelas_id'] = $new_kelas_id;
            $kelas_query = "SELECT nama_kelas FROM kelas WHERE id = ?";
            $kelas_stmt = $conn->prepare($kelas_query);
            $kelas_stmt->bind_param("i", $new_kelas_id);
            $kelas_stmt->execute();
            $kelas_result = $kelas_stmt->get_result();
            $kelas_data = $kelas_result->fetch_assoc();
            $user['nama_kelas'] = $kelas_data['nama_kelas'];

            // MODIFIED: Kirim email hanya jika email tersedia
            if (!empty($user['email'])) {
                $email_title = "Pemberitahuan Perubahan Kelas";
                $email_greeting = "Yth. {$user['nama']},";
                $email_content = "Kami informasikan bahwa kelas Anda di SCHOBANK telah diperbarui menjadi: <strong>{$user['nama_kelas']}</strong>.";
                $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . date('d M Y H:i:s') . " WIB</strong></p>
                                   <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);

                if (!sendEmail($user['email'], $email_title, $email_body)) {
                    $error = "Gagal mengirim email notifikasi!";
                }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            touch-action: manipulation; /* Restricts pinch zooming */
            -webkit-text-size-adjust: 100%; /* Prevents text scaling */
            -webkit-user-select: none; /* Optional: Prevents text selection */
            user-select: none; /* Optional: Prevents text selection */
        }

        body {
            background: linear-gradient(135deg, var(--bg-light), #e8effd);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            font-size: calc(0.95rem + 0.3vw); /* Responsive base font */
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
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .welcome-banner p {
            font-size: calc(1rem + 0.2vw);
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
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
            z-index: 2;
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
            z-index: 3;
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
            position: relative;
        }

        .profile-header i {
            margin-right: 12px;
            font-size: calc(1.4rem + 0.3vw);
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
            transition: var(--transition);
            flex-shrink: 0;
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
        }

        .info-item .edit-btn:hover {
            background: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .info-item {
                padding: 10px 0;
                position: relative;
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
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
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
        }

        .badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: calc(0.8rem + 0.1vw);
            font-weight: 500;
            text-transform: uppercase;
            background: var(--primary-light);
            color: var(--primary-color);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
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

        .modal-content {
            background: white;
            margin: 8% auto;
            padding: 30px;
            border-radius: 20px;
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 550px;
            position: relative;
            z-index: 1001;
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
        }

        .modal-header .close-modal {
            background: none;
            border: none;
            font-size: calc(1.5rem + 0.3vw);
            cursor: pointer;
            color: var(--text-secondary);
            transition: var(--transition);
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

        .form-group .input-wrapper {
            position: relative;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 14px 20px;
            padding-left: 50px;
            border: none;
            border-radius: 12px;
            font-size: calc(0.95rem + 0.2vw);
            background: #f5f7fa;
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }

        .form-group select {
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 20px center;
            background-size: 18px;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.2);
        }

        .form-group i.input-icon {
            position: absolute;
            top: 50%;
            left: 20px;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: calc(1rem + 0.2vw);
            transition: var(--transition);
        }

        .form-group input:focus + i.input-icon, .form-group select:focus + i.input-icon {
            color: var(--primary-color);
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

        .btn i {
            margin-right: 10px;
            font-size: calc(1rem + 0.2vw);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .alert {
            padding: 18px 24px;
            border-radius: 12px;
            margin-bottom: 25px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 500;
            animation: slideInRight 0.5s ease-out;
            display: flex;
            align-items: center;
            max-width: 400px;
            box-shadow: var(--shadow-md);
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        .alert.hide {
            animation: slideOutRight 0.5s ease-in forwards;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 6px solid #34d399;
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 6px solid #f87171;
        }

        .alert i {
            margin-right: 15px;
            font-size: calc(1.3rem + 0.3vw);
        }

        .alert-content {
            flex: 1;
        }

        .alert-content p {
            margin: 0;
            font-size: calc(0.9rem + 0.2vw);
        }

        .alert .close-alert {
            background: none;
            border: none;
            color: inherit;
            font-size: calc(1rem + 0.2vw);
            cursor: pointer;
            margin-left: 15px;
            opacity: 0.7;
            transition: var(--transition);
        }

        .alert .close-alert:hover {
            opacity: 1;
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

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .forgot-pin-container {
            text-align: right;
            margin-top: 10px;
        }

        .forgot-pin-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: calc(0.85rem + 0.1vw);
            transition: var(--transition);
        }

        .forgot-pin-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .welcome-banner h2 {
                font-size: calc(1.4rem + 0.4vw);
            }

            .welcome-banner p {
                font-size: calc(0.9rem + 0.2vw);
            }

            .modal-content {
                margin: 20% auto;
                padding: 20px;
            }

            .btn {
                width: 100%;
            }

            .modal-footer {
                flex-direction: column;
            }
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
            <p>Kelola informasi dan pengaturan akun Anda dengan mudah</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-xmark"></i>
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
                        <div class="text-container">
                            <div class="label">Nama Lengkap</div>
                            <div class="value"><?= htmlspecialchars($user['nama']) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-user-tag"></i>
                        <div class="text-container">
                            <div class="label">Username</div>
                            <div class="value"><?= htmlspecialchars($user['username']) ?></div>
                        </div>
                        <button class="edit-btn" data-target="username">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>

                    <div class="info-item">
                        <i class="fas fa-lock"></i>
                        <div class="text-container">
                            <div class="label">Password</div>
                            <div class="value">••••••••</div>
                        </div>
                        <button class="edit-btn" data-target="password">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-envelope"></i>
                        <div class="text-container">
                            <div class="label">Email</div>
                            <div class="value">
                                <?= !empty($user['email']) ? htmlspecialchars($user['email']) : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-btn" data-target="email">
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
                            <div class="label">Kelas</div>
                            <div class="value"><?= htmlspecialchars($user['nama_kelas']) ?></div>
                        </div>
                        <button class="edit-btn" data-target="kelas">
                            <i class="fas fa-pen-to-square"></i>
                        </button>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-user-shield"></i>
                        <div class="text-container">
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="badge"><?= htmlspecialchars(ucfirst($user['role'])) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-calendar-alt"></i>
                        <div class="text-container">
                            <div class="label">Bergabung Sejak</div>
                            <div class="value"><?= date('d F Y', strtotime($user['tanggal_bergabung'])) ?></div>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <i class="fas fa-key"></i>
                        <div class="text-container">
                            <div class="label">PIN</div>
                            <div class="value">
                                <?= !empty($user['pin']) ? '••••••' : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-btn" data-target="pin">
                            <i class="fas fa-pen-to-square"></i>
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
                <button class="close-modal">×</button>
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
                <button class="close-modal">×</button>
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
                <button class="close-modal">×</button>
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
                <button class="close-modal">×</button>
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
                <button class="close-modal">×</button>
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
                        <div class="forgot-pin-container">
                            <a href="#" id="forgotPinLink" class="forgot-pin-link">Lupa PIN?</a>
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
                <h3><i class="fas fa-users"></i> Ubah Kelas</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="kelasForm">
                    <div class="form-group">
                        <label for="new_kelas_id">Kelas Baru</label>
                        <div class="input-wrapper">
                            <i class="fas fa-users input-icon"></i>
                            <select id="new_kelas_id" name="new_kelas_id" required>
                                <option value="">Pilih Kelas</option>
                                <?php
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
        // Prevent pinch zooming
        document.addEventListener('touchstart', function (event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-tap zooming
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function (event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            // Handle forgot PIN
            const forgotPinLink = document.getElementById('forgotPinLink');
            if (forgotPinLink) {
                forgotPinLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (confirm('Apakah Anda yakin ingin mereset PIN? Link reset akan dikirim ke email Anda.')) {
                        const originalText = forgotPinLink.textContent;
                        forgotPinLink.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
                        
                        fetch('send_pin_reset.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `user_id=<?= $_SESSION['user_id'] ?>`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === 'success') {
                                alert('Link reset PIN telah dikirim ke email Anda.');
                            } else {
                                alert('Gagal mengirim link reset: ' + data.message);
                            }
                        })
                        .catch(error => {
                            alert('Terjadi kesalahan: ' + error);
                        })
                        .finally(() => {
                            forgotPinLink.textContent = originalText;
                        });
                    }
                });
            }

            // Handle alerts
            function dismissAlert(alert) {
                alert.classList.add('hide');
                setTimeout(() => alert.remove(), 500);
            }

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => dismissAlert(alert), 5000);
            });

            // Password visibility toggle
            const toggles = document.querySelectorAll('.password-toggle');
            toggles.forEach(toggle => {
                toggle.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    if (input.type === 'password') {
                        input.type = 'text';
                        this.classList.remove('fa-eye');
                        this.classList.add('fa-eye-slash');
                    } else {
                        input.type = 'password';
                        this.classList.remove('fa-eye-slash');
                        this.classList.add('fa-eye');
                    }
                });
            });

            // Modal handling
            const editButtons = document.querySelectorAll('.edit-btn');
            const modals = document.querySelectorAll('.modal');
            const closeModalButtons = document.querySelectorAll('.close-modal');

            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const target = this.getAttribute('data-target');
                    const modal = document.getElementById(target + 'Modal');
                    if (modal) {
                        modal.style.display = 'block';
                        modal.style.zIndex = '1000';
                        document.body.style.overflow = 'hidden';
                        setTimeout(() => modal.classList.add('active'), 10);
                    }
                });
            });

            closeModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    modal.classList.remove('active');
                    setTimeout(() => {
                        modal.style.display = 'none';
                        document.body.style.overflow = 'auto';
                    }, 400);
                });
            });

            window.addEventListener('click', function(event) {
                modals.forEach(modal => {
                    if (event.target === modal) {
                        modal.classList.remove('active');
                        setTimeout(() => {
                            modal.style.display = 'none';
                            document.body.style.overflow = 'auto';
                        }, 400);
                    }
                });
            });

            // Form submission loading state
            const forms = [
                'usernameForm', 'passwordForm', 'pinForm', 
                'emailForm', 'otpForm', 'kelasForm'
            ];

            forms.forEach(formId => {
                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', function() {
                        const button = document.getElementById(formId.replace('Form', 'Btn'));
                        button.classList.add('btn-loading');
                    });
                }
            });

            // Intersection Observer for animations
            const animatedElements = document.querySelectorAll('.profile-card, .welcome-banner');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('animate');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.2 });

            animatedElements.forEach(element => observer.observe(element));
        });
    </script>
</body>
</html>