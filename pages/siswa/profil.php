<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
date_default_timezone_set('Asia/Jakarta');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Inisialisasi variabel pesan
$message = '';
$error = '';

// Ambil pesan dari query string setelah redirect
if (isset($_GET['message'])) {
    $message = htmlspecialchars($_GET['message']);
}
if (isset($_GET['error'])) {
    $error = htmlspecialchars($_GET['error']);
}

// Ambil data pengguna
$query = "SELECT u.username, u.nama, u.role, u.email, u.no_wa, u.tanggal_lahir, k.nama_kelas, j.nama_jurusan, 
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
        error_log("Gagal mengirim email: " . $mail->ErrorInfo);
        return false;
    }
}

// Fungsi untuk mengirim pesan WhatsApp menggunakan Fonnte API
function sendWhatsAppMessage($phone_number, $message) {
    $api_token = 'dCjq3fJVf9p2DAfVDVED';
    $curl = curl_init();
    if (substr($phone_number, 0, 2) === '08') {
        $phone_number = '62' . substr($phone_number, 1);
    } elseif (substr($phone_number, 0, 1) === '0') {
        $phone_number = '62' . substr($phone_number, 1);
    }
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'target' => $phone_number,
            'message' => $message
        ),
        CURLOPT_HTTPHEADER => array(
            "Authorization: $api_token"
        ),
    ));
    
    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    
    if ($err) {
        error_log("Gagal mengirim pesan WhatsApp: " . $err);
        return false;
    }
    
    $result = json_decode($response, true);
    if (isset($result['status']) && $result['status'] === true) {
        return true;
    } else {
        error_log("Respons Fonnte API: " . $response);
        return false;
    }
}

// Fungsi untuk mendapatkan nama bulan dalam bahasa Indonesia
function getIndonesianMonth($date) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $day = date('d', strtotime($date));
    $month = $months[(int)date('m', strtotime($date))];
    $year = date('Y', strtotime($date));
    return "$day $month $year";
}

// Template email
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
    </html>
    ";
}

// Fungsi untuk memvalidasi format tanggal (dd/mm/yyyy)
function validateDateFormat($date) {
    if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
        return false;
    }
    $day = $matches[1];
    $month = $matches[2];
    $year = $matches[3];
    if ($day < 1 || $day > 31 || $month < 1 || $month > 12 || $year < 1900 || $year > date('Y')) {
        return false;
    }
    $inputDate = DateTime::createFromFormat('d/m/Y', $date);
    $currentDate = new DateTime();
    if ($inputDate > $currentDate) {
        return false;
    }
    return checkdate($month, $day, $year);
}

// Tangani pengiriman form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_username'])) {
        $new_username = trim($_POST['new_username']);
        if (empty($new_username)) {
            header("Location: profile.php?error=" . urlencode("Username tidak boleh kosong!"));
            exit();
        } elseif ($new_username === $user['username']) {
            header("Location: profile.php?error=" . urlencode("Username baru tidak boleh sama dengan username saat ini!"));
            exit();
        } else {
            $check_query = "SELECT id FROM users WHERE username = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("s", $new_username);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                header("Location: profile.php?error=" . urlencode("Username sudah digunakan!"));
                exit();
            } else {
                $update_query = "UPDATE users SET username = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $new_username, $_SESSION['user_id']);
                if ($update_stmt->execute()) {
                    if (!empty($user['email'])) {
                        $email_title = "Pemberitahuan Perubahan Username";
                        $email_greeting = "Yth. {$user['nama']},";
                        $email_content = "Username akun SCHOBANK Anda telah diperbarui menjadi: <strong>{$new_username}</strong>.";
                        $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                                           <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                        $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                        if (!sendEmail($user['email'], $email_title, $email_body)) {
                            header("Location: profile.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                            exit();
                        }
                    }
                    logout();
                } else {
                    header("Location: profile.php?error=" . urlencode("Gagal mengubah username!"));
                    exit();
                }
            }
        }
    } elseif (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            header("Location: profile.php?error=" . urlencode("Semua field password harus diisi!"));
            exit();
        } elseif ($new_password !== $confirm_password) {
            header("Location: profile.php?error=" . urlencode("Password baru tidak cocok!"));
            exit();
        } elseif (strlen($new_password) < 6) {
            header("Location: profile.php?error=" . urlencode("Password baru minimal 6 karakter!"));
            exit();
        } else {
            $verify_query = "SELECT password FROM users WHERE id = ?";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bind_param("i", $_SESSION['user_id']);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            $user_data = $verify_result->fetch_assoc();
            if (!$user_data || !password_verify($current_password, $user_data['password'])) {
                header("Location: profile.php?error=" . urlencode("Password saat ini tidak valid!"));
                exit();
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
                if ($update_stmt->execute()) {
                    if (!empty($user['email'])) {
                        $email_title = "Pemberitahuan Perubahan Password";
                        $email_greeting = "Yth. {$user['nama']},";
                        $email_content = "Password akun SCHOBANK Anda telah diperbarui.";
                        $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                                           <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                        $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                        if (!sendEmail($user['email'], $email_title, $email_body)) {
                            header("Location: profile.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                            exit();
                        }
                    }
                    logout();
                } else {
                    header("Location: profile.php?error=" . urlencode("Gagal mengubah password!"));
                    exit();
                }
            }
        }
    } elseif (isset($_POST['update_pin'])) {
        $new_pin = $_POST['new_pin'];
        $confirm_pin = $_POST['confirm_pin'];
        if (empty($new_pin) || empty($confirm_pin)) {
            header("Location: profile.php?error=" . urlencode("Semua field PIN harus diisi!"));
            exit();
        } elseif ($new_pin !== $confirm_pin) {
            header("Location: profile.php?error=" . urlencode("PIN baru tidak cocok!"));
            exit();
        } elseif (strlen($new_pin) !== 6 || !ctype_digit($new_pin)) {
            header("Location: profile.php?error=" . urlencode("PIN harus 6 digit angka!"));
            exit();
        } else {
            if (!empty($user['pin'])) {
                if (!isset($_POST['current_pin']) || empty($_POST['current_pin'])) {
                    header("Location: profile.php?error=" . urlencode("PIN saat ini harus diisi!"));
                    exit();
                } elseif ($_POST['current_pin'] !== $user['pin']) {
                    header("Location: profile.php?error=" . urlencode("PIN saat ini tidak valid!"));
                    exit();
                }
            }
            $update_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $new_pin, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                if (!empty($user['email'])) {
                    $email_title = !empty($user['pin']) ? "Pemberitahuan Perubahan PIN" : "Pemberitahuan Pembuatan PIN";
                    $email_greeting = "Yth. {$user['nama']},";
                    $email_content = !empty($user['pin']) ? "PIN akun SCHOBANK Anda telah diperbarui." : "PIN akun SCHOBANK Anda telah dibuat.";
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                    if (!sendEmail($user['email'], $email_title, $email_body)) {
                        header("Location: profile.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                        exit();
                    }
                }
                header("Location: profile.php?message=" . urlencode(!empty($user['pin']) ? "PIN berhasil diubah!" : "PIN berhasil dibuat!"));
                exit();
            } else {
                header("Location: profile.php?error=" . urlencode("Gagal mengubah PIN!"));
                exit();
            }
        }
    } elseif (isset($_POST['update_email'])) {
        $email = trim($_POST['email']);
        if (empty($email)) {
            header("Location: profile.php?error=" . urlencode("Email tidak boleh kosong!"));
            exit();
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            header("Location: profile.php?error=" . urlencode("Format email tidak valid!"));
            exit();
        } else {
            $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $email, $_SESSION['user_id']);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                header("Location: profile.php?error=" . urlencode("Email sudah terdaftar!"));
                exit();
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
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Kode ini berlaku hingga: <strong>" . getIndonesianMonth(date('Y-m-d', strtotime($expiry))) . " " . date('H:i:s', strtotime($expiry)) . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak meminta perubahan ini, abaikan pesan ini.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                    if (sendEmail($email, $email_title, $email_body)) {
                        $_SESSION['new_email'] = $email;
                        header("Location: verify_otp.php");
                        exit();
                    } else {
                        header("Location: profile.php?error=" . urlencode("Gagal mengirim OTP!"));
                        exit();
                    }
                } else {
                    header("Location: profile.php?error=" . urlencode("Gagal menyimpan OTP!"));
                    exit();
                }
            }
        }
    } elseif (isset($_POST['update_kelas'])) {
        $new_kelas_id = $_POST['new_kelas_id'];
        if (empty($new_kelas_id)) {
            header("Location: profile.php?error=" . urlencode("Kelas harus dipilih!"));
            exit();
        } elseif ($new_kelas_id == $user['kelas_id']) {
            header("Location: profile.php?error=" . urlencode("Kelas baru tidak boleh sama!"));
            exit();
        } else {
            $update_query = "UPDATE users SET kelas_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ii", $new_kelas_id, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                $kelas_query = "SELECT nama_kelas FROM kelas WHERE id = ?";
                $kelas_stmt = $conn->prepare($kelas_query);
                $kelas_stmt->bind_param("i", $new_kelas_id);
                $kelas_stmt->execute();
                $kelas_result = $kelas_stmt->get_result();
                $kelas_data = $kelas_result->fetch_assoc();
                $nama_kelas = $kelas_data['nama_kelas'];
                if (!empty($user['email'])) {
                    $email_title = "Pemberitahuan Perubahan Kelas";
                    $email_greeting = "Yth. {$user['nama']},";
                    $email_content = "Kelas Anda telah diperbarui menjadi: <strong>{$nama_kelas}</strong>.";
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                    if (!sendEmail($user['email'], $email_title, $email_body)) {
                        header("Location: profile.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                        exit();
                    }
                }
                header("Location: profile.php?message=" . urlencode("Kelas berhasil diubah!"));
                exit();
            } else {
                header("Location: profile.php?error=" . urlencode("Gagal mengubah kelas!"));
                exit();
            }
        }
    } elseif (isset($_POST['update_no_wa'])) {
        $no_wa = trim($_POST['no_wa']);
        $requires_otp = false;
        $otp_target_number = '';
        if (!empty($no_wa)) {
            if (!preg_match('/^08[0-9]{8,11}$/', $no_wa)) {
                header("Location: profile.php?error=" . urlencode("Nomor WhatsApp tidak valid!"));
                exit();
            } else {
                $check_query = "SELECT id FROM users WHERE no_wa = ? AND id != ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("si", $no_wa, $_SESSION['user_id']);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result->num_rows > 0) {
                    header("Location: profile.php?error=" . urlencode("Nomor WhatsApp sudah terdaftar!"));
                    exit();
                } else {
                    $requires_otp = true;
                    $otp_target_number = $no_wa;
                }
            }
        } else {
            if (empty($user['no_wa'])) {
                header("Location: profile.php?error=" . urlencode("Nomor WhatsApp sudah kosong!"));
                exit();
            } else {
                $requires_otp = true;
                $otp_target_number = $user['no_wa'];
            }
        }
        if ($requires_otp) {
            if (empty($otp_target_number)) {
                header("Location: profile.php?error=" . urlencode("Nomor WhatsApp diperlukan untuk OTP!"));
                exit();
            } else {
                $otp = rand(100000, 999999);
                $expiry = date('Y-m-d H:i:s', strtotime('+5 minutes'));
                $update_query = "UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ssi", $otp, $expiry, $_SESSION['user_id']);
                if ($update_stmt->execute()) {
                    $otp_message = "Yth. {$user['nama']},\nKode OTP untuk verifikasi perubahan nomor WhatsApp Anda adalah: *{$otp}*\nKode ini berlaku hingga: " . getIndonesianMonth(date('Y-m-d', strtotime('+5 minutes'))) . " " . date('H:i:s', strtotime('+5 minutes')) . " WIB\nJika Anda tidak meminta perubahan ini, abaikan pesan ini.";
                    if (sendWhatsAppMessage($otp_target_number, $otp_message)) {
                        $_SESSION['new_no_wa'] = $no_wa;
                        header("Location: verify_otp.php");
                        exit();
                    } else {
                        header("Location: profile.php?error=" . urlencode("Gagal mengirim OTP ke WhatsApp!"));
                        exit();
                    }
                } else {
                    header("Location: profile.php?error=" . urlencode("Gagal menyimpan OTP!"));
                    exit();
                }
            }
        }
    } elseif (isset($_POST['update_tanggal_lahir'])) {
        $tanggal_lahir = trim($_POST['tanggal_lahir']);
        if (empty($tanggal_lahir)) {
            header("Location: profile.php?error=" . urlencode("Tanggal lahir tidak boleh kosong!"));
            exit();
        } elseif (!validateDateFormat($tanggal_lahir)) {
            header("Location: profile.php?error=" . urlencode("Format tanggal lahir tidak valid! Gunakan dd/mm/yyyy."));
            exit();
        } else {
            $date_parts = explode('/', $tanggal_lahir);
            $db_date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
            $update_query = "UPDATE users SET tanggal_lahir = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $db_date, $_SESSION['user_id']);
            if ($update_stmt->execute()) {
                if (!empty($user['email'])) {
                    $email_title = "Pemberitahuan Perubahan Tanggal Lahir";
                    $email_greeting = "Yth. {$user['nama']},";
                    $email_content = "Tanggal lahir Anda telah diperbarui menjadi: <strong>{$tanggal_lahir}</strong>.";
                    $email_additional = "<p style='font-size: calc(0.8rem + 0.1vw); color: #666666; margin: 20px 0 0 0;'>Tanggal Perubahan: <strong>" . getIndonesianMonth(date('Y-m-d')) . " " . date('H:i:s') . " WIB</strong></p>
                                       <p style='font-size: calc(0.8rem + 0.1vw); color: #666666;'>Jika Anda tidak melakukan perubahan ini, segera hubungi kami.</p>";
                    $email_body = getEmailTemplate($email_title, $email_greeting, $email_content, $email_additional);
                    if (!sendEmail($user['email'], $email_title, $email_body)) {
                        header("Location: profile.php?error=" . urlencode("Gagal mengirim email notifikasi!"));
                        exit();
                    }
                }
                header("Location: profile.php?message=" . urlencode("Tanggal lahir berhasil diubah!"));
                exit();
            } else {
                header("Location: profile.php?error=" . urlencode("Gagal mengubah tanggal lahir!"));
                exit();
            }
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
    <div class="main-content">
        <div class="welcome-banner">
            <h2>Profil Pengguna</h2>
            <p>Kelola informasi dan pengaturan akun Anda</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-xmark"></i>
            </a>
        </div>

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
                        <i class="fas fa-calendar-day"></i>
                        <div class="text-container">
                            <div class="label">Tanggal Lahir</div>
                            <div class="value">
                                <?= !empty($user['tanggal_lahir']) ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-btn" data-target="tanggal_lahir">
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
                    <div class="info-item">
                        <i class="fab fa-whatsapp"></i>
                        <div class="text-container">
                            <div class="label">Nomor WhatsApp</div>
                            <div class="value">
                                <?= !empty($user['no_wa']) ? htmlspecialchars($user['no_wa']) : 'Belum diatur' ?>
                            </div>
                        </div>
                        <button class="edit-btn" data-target="no_wa">
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

    <!-- Modal untuk Edit Username -->
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
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit Email -->
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
                            <input type="email" id="email" name="email" required placeholder="Masukkan email" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_email" class="btn btn-primary" id="emailBtn">
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit Nomor WhatsApp -->
    <div id="no_waModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fab fa-whatsapp"></i> Ubah Nomor WhatsApp</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="no_waForm">
                    <div class="form-group">
                        <label for="no_wa">Nomor WhatsApp</label>
                        <div class="input-wrapper">
                            <i class="fab fa-whatsapp input-icon"></i>
                            <input type="text" id="no_wa" name="no_wa" placeholder="Masukkan nomor WhatsApp (contoh: 081234567890)" 
                                   value="<?= htmlspecialchars($user['no_wa'] ?? '') ?>" pattern="^08[0-9]{8,11}$" maxlength="13">
                        </div>
                        <small style="font-size: calc(0.75rem + 0.1vw); color: var(--text-secondary);">
                            Mulai dengan 08, 10-13 digit. Kosongkan untuk menghapus.
                        </small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_no_wa" class="btn btn-primary" id="no_waBtn">
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit Tanggal Lahir -->
    <div id="tanggal_lahirModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-day"></i> Ubah Tanggal Lahir</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="" id="tanggalLahirForm">
                    <div class="form-group">
                        <label for="tanggal_lahir">Tanggal Lahir (dd/mm/yyyy)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-calendar-day input-icon"></i>
                            <input type="text" id="tanggal_lahir" name="tanggal_lahir" required 
                                   placeholder="Masukkan tanggal lahir (contoh: 31/12/2003)" 
                                   pattern="\d{2}/\d{2}/\d{4}" 
                                   value="<?= !empty($user['tanggal_lahir']) ? date('d/m/Y', strtotime($user['tanggal_lahir'])) : '' ?>">
                        </div>
                        <small style="font-size: calc(0.75rem + 0.1vw); color: var(--text-secondary);">
                            Format: dd/mm/yyyy (contoh: 31/12/2003).
                        </small>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" name="update_tanggal_lahir" class="btn btn-primary" id="tanggalLahirBtn">
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit Password -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Ubah Password</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div id="passwordErrorPopup" class="error-popup" style="display: none;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="passwordErrorMessage"></span>
                </div>
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
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit PIN -->
    <div id="pinModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-key"></i> Buat/Ubah PIN</h3>
                <button class="close-modal">×</button>
            </div>
            <div class="modal-body">
                <div id="pinErrorPopup" class="error-popup" style="display: none;">
                    <i class="fas fa-exclamation-circle"></i>
                    <span id="pinErrorMessage"></span>
                </div>
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
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal untuk Edit Kelas -->
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
                                $kelas_query = "SELECT k.id, k.nama_kelas FROM kelas k WHERE k.jurusan_id = ? ORDER BY k.nama_kelas";
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
                            <span>Simpan</span>
                        </button>
                        <button type="button" class="btn btn-outline close-modal">
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Fungsi untuk menampilkan pop-up error
            function showErrorPopup(modalId, message, focusInputId) {
                const popup = document.getElementById(modalId + 'ErrorPopup');
                const messageSpan = document.getElementById(modalId + 'ErrorMessage');
                messageSpan.textContent = message;
                popup.style.display = 'flex';
                setTimeout(() => {
                    popup.style.opacity = '1';
                }, 10);
                setTimeout(() => {
                    popup.style.opacity = '0';
                    setTimeout(() => {
                        popup.style.display = 'none';
                        if (focusInputId) {
                            document.getElementById(focusInputId).focus();
                        }
                    }, 300);
                }, 3000);
            }

            // Fungsi untuk mereset form
            function resetForm(modalId) {
                const modal = document.getElementById(modalId);
                const form = modal.querySelector('form');
                form.reset();
            }

            // Tangani alert
            function dismissAlert(alert) {
                alert.classList.add('hide');
                setTimeout(() => alert.remove(), 500);
            }

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => dismissAlert(alert), 5000);
            });

            // Toggle visibilitas password
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
                    input.focus();
                });
            });

            // Penanganan modal
            const editButtons = document.querySelectorAll('.edit-btn');
            const modals = document.querySelectorAll('.modal');
            const closeModalButtons = document.querySelectorAll('.close-modal');

            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const target = this.getAttribute('data-target');
                    const modal = document.getElementById(target + 'Modal');
                    modal.style.display = 'block';
                    setTimeout(() => modal.classList.add('active'), 10);
                    resetForm(target + 'Modal');
                    const firstInput = modal.querySelector('input, select');
                    if (firstInput) firstInput.focus();
                });
            });

            closeModalButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    modal.classList.remove('active');
                    setTimeout(() => modal.style.display = 'none', 400);
                });
            });

            window.addEventListener('click', function(event) {
                modals.forEach(modal => {
                    if (event.target === modal) {
                        modal.classList.remove('active');
                        setTimeout(() => modal.style.display = 'none', 400);
                    }
                });
            });

            // Validasi form password
            const passwordForm = document.getElementById('passwordForm');
            passwordForm.addEventListener('submit', function(e) {
                const currentPassword = document.getElementById('current_password').value;
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const submitButton = document.getElementById('passwordBtn');

                if (!currentPassword || !newPassword || !confirmPassword) {
                    e.preventDefault();
                    showErrorPopup('password', 'Semua field password harus diisi!', 'current_password');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                if (newPassword.length < 6) {
                    e.preventDefault();
                    showErrorPopup('password', 'Password baru minimal 6 karakter!', 'new_password');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    showErrorPopup('password', 'Konfirmasi password tidak cocok!', 'confirm_password');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                submitButton.classList.add('btn-loading');
            });

            // Validasi form PIN
            const pinForm = document.getElementById('pinForm');
            pinForm.addEventListener('submit', function(e) {
                const currentPin = document.getElementById('current_pin') ? document.getElementById('current_pin').value : '';
                const newPin = document.getElementById('new_pin').value;
                const confirmPin = document.getElementById('confirm_pin').value;
                const submitButton = document.getElementById('pinBtn');

                if (document.getElementById('current_pin') && !currentPin) {
                    e.preventDefault();
                    showErrorPopup('pin', 'PIN saat ini harus diisi!', 'current_pin');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                if (!newPin || !confirmPin) {
                    e.preventDefault();
                    showErrorPopup('pin', 'Semua field PIN harus diisi!', 'new_pin');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                if (!/^\d{6}$/.test(newPin)) {
                    e.preventDefault();
                    showErrorPopup('pin', 'PIN harus 6 digit angka!', 'new_pin');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                if (newPin !== confirmPin) {
                    e.preventDefault();
                    showErrorPopup('pin', 'Konfirmasi PIN tidak cocok!', 'confirm_pin');
                    submitButton.classList.remove('btn-loading');
                    return;
                }
                submitButton.classList.add('btn-loading');
            });

            // Status loading saat submit form
            const forms = ['usernameForm', 'emailForm', 'kelasForm', 'no_waForm', 'tanggalLahirForm'];
            forms.forEach(formId => {
                const form = document.getElementById(formId);
                if (form) {
                    form.addEventListener('submit', function() {
                        const button = document.getElementById(formId.replace('Form', 'Btn'));
                        button.classList.add('btn-loading');
                    });
                }
            });

            // Validasi input PIN
            ['current_pin', 'new_pin', 'confirm_pin'].forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', function() {
                        this.value = this.value.replace(/[^0-9]/g, '');
                        if (this.value.length > 6) this.value = this.value.slice(0, 6);
                    });
                }
            });
        });
    </script>
</body>
</html>