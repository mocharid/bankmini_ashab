<?php
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

// Include PHPMailer for sending OTP
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../../vendor/autoload.php';

// Inisialisasi variabel
$token = filter_input(INPUT_GET, 'token', FILTER_UNSAFE_RAW) ?? '';
$token = trim(preg_replace('/[^a-f0-9]/i', '', $token)); // Sanitize to alphanumeric (hex) only
$type = filter_input(INPUT_GET, 'type', FILTER_UNSAFE_RAW) ?? '';
$type = trim(preg_replace('/[^a-z]/i', '', $type)); // Sanitize to letters only
$error_message = '';
$success_message = '';
$user_data = null;
$recovery_type = '';

// Function to generate OTP
function generateOTP($length = 6) {
    return str_pad(mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
}

// Function to send OTP email
function sendOTPEmail($email, $otp, $nama) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        // Recipients
        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email, $nama);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Kode OTP untuk Perubahan Email - SCHOBANK SYSTEM';
        $mail->Body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                    h2 { color: #1A237E; border-bottom: 2px solid #e8ecef; padding-bottom: 10px; }
                    .otp-code { font-size: 1.5em; color: #1A237E; letter-spacing: 5px; }
                    .expire-note { font-size: 0.9em; color: #666; margin-top: 20px; }
                    .footer { margin-top: 30px; font-size: 0.8em; color: #666; border-top: 1px solid #ddd; padding-top: 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2>Verifikasi Perubahan Email</h2>
                    <p>Halo, <strong>{$nama}</strong>!</p>
                    <p>Kode OTP Anda untuk verifikasi perubahan email adalah:</p>
                    <p class='otp-code'>{$otp}</p>
                    <p>Masukkan kode ini di halaman verifikasi untuk melanjutkan.</p>
                    <p class='expire-note'>Kode ini akan kadaluarsa dalam 10 menit.</p>
                    <p>Jika Anda tidak meminta perubahan ini, abaikan email ini atau hubungi administrator.</p>
                    <div class='footer'>
                        <p>Email ini dikirim otomatis oleh sistem, mohon tidak membalas email ini.</p>
                        <p>© " . date('Y') . " SCHOBANK - Semua hak dilindungi undang-undang.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        $mail->AltBody = strip_tags("Halo {$nama},\n\nKode OTP Anda untuk verifikasi perubahan email adalah: {$otp}\n\nKode ini berlaku selama 10 menit. Jika Anda tidak meminta perubahan ini, abaikan email ini.\n\n© " . date('Y') . " SCHOBANK");

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send OTP email to $email: {$mail->ErrorInfo}");
        return false;
    }
}

// Verifikasi token
if (empty($token)) {
    $error_message = "Token tidak valid atau telah kedaluarsa.";
} elseif (strlen($token) !== 64 || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    $error_message = "Format token tidak valid.";
} else {
    // Validate type
    $allowed_types = ['password', 'pin', 'username', 'email'];
    if (!in_array($type, $allowed_types)) {
        $error_message = "Jenis pemulihan tidak valid.";
    } else {
        // Cek token di database
        $query = "SELECT pr.*, u.nama, u.username, u.email, u.role, pr.recovery_type 
                  FROM password_reset pr
                  JOIN users u ON pr.user_id = u.id
                  WHERE pr.token = ? AND pr.expiry > NOW()";
        $stmt = $conn->prepare($query);
        
        if ($stmt === false) {
            error_log("Prepare failed: " . $conn->error);
            $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
        } else {
            $stmt->bind_param("s", $token);
            if (!$stmt->execute()) {
                error_log("Execute failed: " . $stmt->error);
                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
            } else {
                $result = $stmt->get_result();
                
                if ($result->num_rows === 0) {
                    error_log("Invalid or expired token: $token");
                    $error_message = "Token tidak valid atau telah kedaluarsa.";
                } else {
                    $user_data = $result->fetch_assoc();
                    $recovery_type = $user_data['recovery_type'] ?? 'password';
                    if ($recovery_type !== $type) {
                        $error_message = "Jenis pemulihan tidak cocok dengan token.";
                        $user_data = null;
                    }
                }
            }
            $stmt->close();
        }
    }
}

// Proses form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($user_data)) {
    $user_id = $user_data['user_id'];
    $recovery_type = $user_data['recovery_type'];
    
    if ($recovery_type === 'email') {
        // Step 1: Validate new email and send OTP
        $new_email = filter_input(INPUT_POST, 'new_value', FILTER_SANITIZE_EMAIL) ?? '';
        $confirm_email = filter_input(INPUT_POST, 'confirm_value', FILTER_SANITIZE_EMAIL) ?? '';
        
        if (empty($new_email) || empty($confirm_email)) {
            $error_message = "Semua field harus diisi.";
        } elseif ($new_email !== $confirm_email) {
            $error_message = "Konfirmasi email tidak cocok.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Format email tidak valid.";
        } else {
            // Check if email already exists (no duplicates allowed)
            $check_query = "SELECT id FROM users WHERE email = ? AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            if ($check_stmt === false) {
                error_log("Prepare failed for email check: " . $conn->error);
                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
            } else {
                $check_stmt->bind_param("si", $new_email, $user_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $error_message = "Email sudah digunakan. Silakan gunakan email lain.";
                } else {
                    // Check rate limit
                    $cooldown_query = "SELECT COUNT(*) as attempts 
                                      FROM reset_request_cooldown 
                                      WHERE user_id = ? AND method = 'email' 
                                      AND last_reset_request > NOW() - INTERVAL 1 HOUR";
                    $cooldown_stmt = $conn->prepare($cooldown_query);
                    if ($cooldown_stmt === false) {
                        error_log("Prepare failed for cooldown check: " . $conn->error);
                        $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                    } else {
                        $cooldown_stmt->bind_param("i", $user_id);
                        $cooldown_stmt->execute();
                        $cooldown_result = $cooldown_stmt->get_result()->fetch_assoc();
                        
                        if ($cooldown_result['attempts'] >= 3) {
                            $error_message = "Terlalu banyak percobaan. Silakan coba lagi dalam satu jam.";
                        } else {
                            // Insert or update cooldown record
                            $upsert_query = "INSERT INTO reset_request_cooldown (user_id, method, last_reset_request) 
                                            VALUES (?, 'email', NOW()) 
                                            ON DUPLICATE KEY UPDATE last_reset_request = NOW()";
                            $upsert_stmt = $conn->prepare($upsert_query);
                            if ($upsert_stmt === false) {
                                error_log("Prepare failed for cooldown upsert: " . $conn->error);
                                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                            } else {
                                $upsert_stmt->bind_param("i", $user_id);
                                if (!$upsert_stmt->execute()) {
                                    error_log("Failed to upsert cooldown for user_id $user_id: " . $upsert_stmt->error);
                                    $error_message = "Gagal mencatat percobaan. Silakan coba lagi.";
                                } else {
                                    // Generate and save OTP
                                    $otp = generateOTP();
                                    $otp_expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                                    
                                    // Update users table with OTP and expiry
                                    $otp_query = "UPDATE users SET otp = ?, otp_expiry = ? WHERE id = ?";
                                    $otp_stmt = $conn->prepare($otp_query);
                                    if ($otp_stmt === false) {
                                        error_log("Prepare failed for OTP update: " . $conn->error);
                                        $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                                    } else {
                                        $otp_stmt->bind_param("ssi", $otp, $otp_expiry, $user_id);
                                        if (!$otp_stmt->execute()) {
                                            error_log("Failed to save OTP for user_id $user_id: " . $otp_stmt->error);
                                            $error_message = "Gagal menyimpan OTP. Silakan coba lagi.";
                                        } else {
                                            // Send OTP to new email
                                            if (sendOTPEmail($new_email, $otp, $user_data['nama'])) {
                                                // Store new email in password_reset table
                                                $update_email_query = "UPDATE password_reset SET new_email = ? WHERE token = ?";
                                                $email_stmt = $conn->prepare($update_email_query);
                                                if ($email_stmt === false) {
                                                    error_log("Prepare failed for new_email update: " . $conn->error);
                                                    $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                                                } else {
                                                    $email_stmt->bind_param("ss", $new_email, $token);
                                                    if ($email_stmt->execute()) {
                                                        // Redirect to otp_email.php
                                                        header("Location: otp_email.php?token=" . urlencode($token));
                                                        exit();
                                                    } else {
                                                        error_log("Failed to update new_email in password_reset for token $token: " . $email_stmt->error);
                                                        $error_message = "Gagal menyimpan email baru. Silakan coba lagi.";
                                                    }
                                                }
                                            } else {
                                                $error_message = "Gagal mengirim OTP. Silakan coba lagi.";
                                            }
                                        }
                                        $otp_stmt->close();
                                    }
                                }
                                $upsert_stmt->close();
                            }
                        }
                        $cooldown_stmt->close();
                    }
                }
                $check_stmt->close();
            }
        }
    } else {
        // Handle non-email recovery types (password, pin, username)
        $new_value = trim($_POST['new_value'] ?? '');
        $confirm_value = trim($_POST['confirm_value'] ?? '');
        
        // Validasi input
        if (empty($new_value) || empty($confirm_value)) {
            $error_message = "Semua field harus diisi.";
        } elseif ($new_value !== $confirm_value) {
            $error_message = "Konfirmasi tidak cocok.";
        } else {
            $stmt = null;
            switch ($recovery_type) {
                case 'password':
                    if (strlen($new_value) < 6) {
                        $error_message = "Password harus minimal 6 karakter.";
                        break;
                    }
                    $hashed_password = hash('sha256', $new_value);
                    $update_query = "UPDATE users SET password = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    if ($stmt) {
                        $stmt->bind_param("si", $hashed_password, $user_id);
                    }
                    break;
                    
                case 'pin':
                    if (!preg_match('/^[0-9]{6}$/', $new_value)) {
                        $error_message = "PIN harus berupa 6 digit angka.";
                        break;
                    }
                    $update_query = "UPDATE users SET pin = ?, has_pin = 1 WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    if ($stmt) {
                        $stmt->bind_param("si", $new_value, $user_id);
                    }
                    break;
                    
                case 'username':
                    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $new_value)) {
                        $error_message = "Username harus 3-50 karakter, hanya huruf, angka, dan underscore.";
                        break;
                    }
                    $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
                    $check_stmt = $conn->prepare($check_query);
                    if ($check_stmt === false) {
                        error_log("Prepare failed for username check: " . $conn->error);
                        $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
                        break;
                    }
                    $check_stmt->bind_param("si", $new_value, $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $error_message = "Username sudah digunakan. Silakan pilih username lain.";
                        $check_stmt->close();
                        break;
                    }
                    $check_stmt->close();
                    
                    $update_query = "UPDATE users SET username = ? WHERE id = ?";
                    $stmt = $conn->prepare($update_query);
                    if ($stmt) {
                        $stmt->bind_param("si", $new_value, $user_id);
                    }
                    break;
            }
            
            // Eksekusi update jika tidak ada error
            if (empty($error_message) && $stmt) {
                if ($stmt->execute()) {
                    // Hapus token setelah reset berhasil
                    $delete_query = "DELETE FROM password_reset WHERE token = ?";
                    $delete_stmt = $conn->prepare($delete_query);
                    if ($delete_stmt) {
                        $delete_stmt->bind_param("s", $token);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                    } else {
                        error_log("Prepare failed for token deletion: " . $conn->error);
                    }
                    
                    // Log perubahan
                    $alasan = "Reset melalui link pemulihan";
                    $petugas_id = $user_id;
                    $check_petugas = $conn->prepare("SELECT id FROM users WHERE id = ?");
                    if ($check_petugas) {
                        $check_petugas->bind_param("i", $petugas_id);
                        $check_petugas->execute();
                        $petugas_result = $check_petugas->get_result();
                        
                        if ($petugas_result->num_rows > 0) {
                            $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan) 
                                         VALUES (?, ?, ?, ?, NOW(), ?)";
                            $log_stmt = $conn->prepare($log_query);
                            if ($log_stmt) {
                                $masked_value = ($recovery_type === 'password' || $recovery_type === 'pin') ? '********' : $new_value;
                                $log_stmt->bind_param("iisss", $petugas_id, $user_id, $recovery_type, $masked_value, $alasan);
                                $log_stmt->execute();
                                $log_stmt->close();
                            } else {
                                error_log("Prepare failed for log_aktivitas: " . $conn->error);
                            }
                        }
                        $check_petugas->close();
                    } else {
                        error_log("Prepare failed for petugas check: " . $conn->error);
                    }
                    
                    $success_message = ucfirst($recovery_type) . " berhasil diubah. Silakan login dengan " . $recovery_type . " baru Anda.";
                    $user_data = null;
                } else {
                    error_log("Failed to update $recovery_type for user_id $user_id: " . $stmt->error);
                    $error_message = "Gagal mengubah " . $recovery_type . ". Silakan coba lagi.";
                }
                $stmt->close();
            } elseif (!$stmt && empty($error_message)) {
                error_log("Prepare failed for $recovery_type update: " . $conn->error);
                $error_message = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
            }
        }
    }
}

// Fungsi helper
function getFieldLabel($type) {
    switch ($type) {
        case 'password': return 'Password';
        case 'pin': return 'PIN';
        case 'username': return 'Username';
        case 'email': return 'Email';
        default: return 'Nilai';
    }
}

function getFieldType($type) {
    switch ($type) {
        case 'password': return 'password';
        case 'pin': return 'password';
        case 'username': return 'text';
        case 'email': return 'email';
        default: return 'text';
    }
}

function getPlaceholder($type) {
    switch ($type) {
        case 'password': return 'Masukkan password baru';
        case 'pin': return 'Masukkan 6 digit PIN baru';
        case 'username': return 'Masukkan username baru';
        case 'email': return 'Masukkan email baru';
        default: return 'Masukkan nilai baru';
    }
}

function getIconClass($type) {
    switch ($type) {
        case 'password': return 'fas fa-lock';
        case 'pin': return 'fas fa-key';
        case 'username': return 'fas fa-user';
        case 'email': return 'fas fa-envelope';
        default: return 'fas fa-info-circle';
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset <?= ucfirst($recovery_type ?? 'Akun') ?> - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html, body {
            touch-action: manipulation;
            overscroll-behavior: none;
        }

        body {
            background: linear-gradient(180deg, #e6ecf8 0%, #f5f7fa 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 15px;
        }

        .reset-container {
            background: #ffffff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            margin-bottom: 2rem;
            padding: 1rem 0;
            border-bottom: 1px solid #e8ecef;
        }

        .logo {
            width: 100px;
            height: auto;
            transition: transform 0.3s ease;
        }

        .logo:hover {
            transform: scale(1.05);
        }

        .bank-name {
            font-size: 1.4rem;
            color: #1A237E;
            font-weight: 700;
            margin-top: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }

        .form-description {
            color: #4a5568;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            line-height: 1.4;
            font-weight: 400;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-group i.icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #4a5568;
            font-size: 1.1rem;
        }

        input {
            width: 100%;
            padding: 12px 45px;
            border: 1px solid #d1d9e6;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 400;
            transition: all 0.3s ease;
            background: #fbfdff;
        }

        input:focus {
            border-color: transparent;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 180, 216, 0.2);
            background: linear-gradient(90deg, #ffffff 0%, #f0faff 100%);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #4a5568;
        }

        .checkbox-group input {
            width: auto;
            margin-right: 8px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #1A237E 0%, #00B4D8 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        button i {
            margin-right: 6px;
        }

        button:hover {
            background: linear-gradient(90deg, #151c66 0%, #009bb8 100%);
            box-shadow: 0 4px 12px rgba(0, 180, 216, 0.3);
            transform: translateY(-1px);
            scale: 1.02;
        }

        button:active {
            background: #151c66;
            transform: translateY(0);
            scale: 1;
        }

        .error-message {
            background: #fef1f1;
            color: #c53030;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #feb2b2;
            box-shadow: 0 1px 6px rgba(254, 178, 178, 0.15);
        }

        .error-message.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .success-message {
            background: #e6fffa;
            color: #2c7a7b;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #b2f5ea;
            box-shadow: 0 1px 6px rgba(178, 245, 234, 0.15);
        }

        .success-message.fade-out {
            opacity: 0;
            transition: opacity 0.5s ease;
        }

        .back-to-login {
            text-align: center;
            margin-top: 1.5rem;
        }

        .back-to-login a {
            color: #1A237E;
            font-size: 0.9rem;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .back-to-login a i {
            margin-right: 5px;
        }

        .back-to-login a:hover {
            color: #00B4D8;
            text-decoration: underline;
            transform: scale(1.05);
        }

        .pin-strength {
            height: 5px;
            background: #e1e5ee;
            margin-top: 8px;
            border-radius: 5px;
            overflow: hidden;
        }

        .pin-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease;
        }

        .pin-message {
            font-size: 0.8rem;
            margin-top: 5px;
            color: #666;
        }

        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #ffffff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        button.loading .spinner {
            display: inline-block;
        }

        button.loading .button-content {
            display: none;
        }

        @media (max-width: 768px) {
            .reset-container {
                max-width: 400px;
                border-radius: 12px;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
                border: 1px solid #e8ecef;
                margin: 0 auto;
                padding: 2rem;
            }
        }

        @media (max-width: 480px) {
            .reset-container {
                padding: 1.5rem;
            }

            .logo {
                width: 80px;
            }

            .bank-name {
                font-size: 1.2rem;
            }

            input, button {
                font-size: 14px;
                padding: 10px 40px;
            }

            button {
                padding: 10px;
            }

            .form-description {
                font-size: 0.85rem;
            }

            .input-group i.icon {
                font-size: 1rem;
            }

            .error-message, .success-message {
                font-size: 0.85rem;
                padding: 0.6rem;
            }

            .back-to-login a {
                font-size: 0.85rem;
            }

            .pin-message {
                font-size: 0.75rem;
            }

            .spinner {
                width: 18px;
                height: 18px;
                border: 2px solid rgba(255, 255, 255, 0.3);
                border-top: 2px solid #ffffff;
            }
        }

        @media screen and (-webkit-min-device-pixel-ratio: 0) {
            select,
            textarea,
            input {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="logo-container">
            <img src="/bankmini/assets/images/lbank.png" alt="SCHOBANK Logo" class="logo">
            <div class="bank-name">SCHOBANK</div>
        </div>

        <div class="form-description">
            <?php if ($user_data): ?>
                Halo <strong><?= htmlspecialchars($user_data['nama']) ?></strong>, 
                silakan masukkan <?= strtolower(getFieldLabel($recovery_type)) ?> baru Anda di bawah ini.
            <?php else: ?>
                Atur ulang <?= strtolower(getFieldLabel($recovery_type)) ?> akun Anda. Pastikan data yang dimasukkan valid.
            <?php endif; ?>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="success-message" id="success-alert">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
            <div class="back-to-login">
                <a href="../login.php"><i class="fas fa-arrow-left"></i> Kembali ke Halaman Login</a>
            </div>
        <?php elseif ($user_data): ?>
            <!-- Reset Form (for email, password, pin, username) -->
            <form method="POST" id="reset-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF'] . '?token=' . urlencode($token) . '&type=' . urlencode($recovery_type)) ?>">
                <div class="input-group">
                    <i class="<?= getIconClass($recovery_type) ?> icon"></i>
                    <input 
                        type="<?= getFieldType($recovery_type) ?>" 
                        name="new_value" 
                        id="new_value" 
                        placeholder="<?= getPlaceholder($recovery_type) ?>" 
                        required
                        <?php if ($recovery_type === 'pin'): ?>
                            maxlength="6"
                            pattern="[0-9]{6}"
                            inputmode="numeric"
                        <?php endif; ?>
                    >
                    <?php if ($recovery_type === 'pin'): ?>
                        <div class="pin-strength">
                            <div class="pin-strength-bar" id="pinStrengthBar"></div>
                        </div>
                        <div class="pin-message" id="pinMessage"></div>
                    <?php endif; ?>
                </div>
                <div class="input-group">
                    <i class="<?= getIconClass($recovery_type) ?> icon"></i>
                    <input 
                        type="<?= getFieldType($recovery_type) ?>" 
                        name="confirm_value" 
                        id="confirm_value" 
                        placeholder="Konfirmasi <?= strtolower(getFieldLabel($recovery_type)) ?> baru" 
                        required
                        <?php if ($recovery_type === 'pin'): ?>
                            maxlength="6"
                            pattern="[0-9]{6}"
                            inputmode="numeric"
                        <?php endif; ?>
                    >
                </div>
                <?php if ($recovery_type === 'password' || $recovery_type === 'pin'): ?>
                    <div class="checkbox-group">
                        <input type="checkbox" id="show_password">
                        <label for="show_password">Tampilkan <?= strtolower(getFieldLabel($recovery_type)) ?></label>
                    </div>
                <?php endif; ?>
                <button type="submit" id="submit-button">
                    <span class="button-content">
                        <i class="fas fa-save"></i> 
                        <?php if ($recovery_type === 'email'): ?>
                            Kirim OTP
                        <?php else: ?>
                            Reset <?= getFieldLabel($recovery_type) ?>
                        <?php endif; ?>
                    </span>
                    <span class="spinner"></span>
                </button>
            </form>
            <div class="back-to-login">
                <a href="../login.php"><i class="fas fa-arrow-left"></i> Kembali ke Halaman Login</a>
            </div>
        <?php else: ?>
            <div class="error-message" id="error-alert">
                <i class="fas fa-exclamation-circle"></i>
                Link reset tidak valid atau telah kedaluarsa. Silakan hubungi administrator untuk mendapatkan link pemulihan baru.
            </div>
            <div class="back-to-login">
                <a href="../../index.php"><i class="fas fa-arrow-left"></i> Kembali ke Beranda</a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Prevent zoom
        document.addEventListener('wheel', (e) => {
            if (e.ctrlKey) {
                e.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '0')) {
                e.preventDefault();
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const errorAlert = document.getElementById('error-alert');
            const successAlert = document.getElementById('success-alert');
            const resetForm = document.getElementById('reset-form');
            const submitButton = document.getElementById('submit-button');
            const newValueInput = document.getElementById('new_value');
            const confirmValueInput = document.getElementById('confirm_value');
            const showPasswordCheckbox = document.getElementById('show_password');

            // Fade out messages
            if (errorAlert) {
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 3500);
            }

            if (successAlert) {
                setTimeout(() => {
                    successAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 3500);
            }

            // Toggle password/PIN visibility
            if (showPasswordCheckbox) {
                showPasswordCheckbox.addEventListener('change', function() {
                    const type = this.checked ? 'text' : '<?= getFieldType($recovery_type) ?>';
                    if (newValueInput) newValueInput.type = type;
                    if (confirmValueInput) confirmValueInput.type = type;
                });
            }

            <?php if ($recovery_type === 'pin'): ?>
                // PIN strength checker
                newValueInput.addEventListener('input', function() {
                    const pin = this.value;
                    let strength = 0;
                    let message = '';
                    
                    // Allow only numbers
                    this.value = pin.replace(/[^0-9]/g, '');
                    
                    if (pin.length === 0) {
                        strength = 0;
                        message = '';
                    } else if (pin.length < 6) {
                        strength = 25;
                        message = 'Terlalu pendek';
                        document.getElementById('pinStrengthBar').style.backgroundColor = '#ff4d4d';
                    } else {
                        // Check for repeated digits
                        const hasRepeatedDigits = /(\d)\1{2,}/.test(pin);
                        
                        // Check for sequential digits
                        const isSequential = 
                            /012|123|234|345|456|567|678|789/.test(pin) || 
                            /987|876|765|654|543|432|321|210/.test(pin);
                        
                        if (hasRepeatedDigits && isSequential) {
                            strength = 25;
                            message = 'PIN terlalu lemah';
                            document.getElementById('pinStrengthBar').style.backgroundColor = '#ff4d4d';
                        } else if (hasRepeatedDigits || isSequential) {
                            strength = 50;
                            message = 'PIN cukup lemah';
                            document.getElementById('pinStrengthBar').style.backgroundColor = '#ffaa00';
                        } else {
                            strength = 100;
                            message = 'PIN kuat';
                            document.getElementById('pinStrengthBar').style.backgroundColor = '#00cc44';
                        }
                    }
                    
                    document.getElementById('pinStrengthBar').style.width = strength + '%';
                    document.getElementById('pinMessage').textContent = message;
                    
                    // Also check confirm PIN if it has a value
                    if (confirmValueInput.value.length > 0) {
                        checkPinMatch();
                    }
                });
                
                // Check if PINs match
                confirmValueInput.addEventListener('input', function() {
                    // Allow only numbers
                    this.value = this.value.replace(/[^0-9]/g, '');
                    checkPinMatch();
                });
                
                function checkPinMatch() {
                    if (confirmValueInput.value !== newValueInput.value) {
                        confirmValueInput.style.borderColor = '#ff4d4d';
                        confirmValueInput.style.boxShadow = '0 0 0 3px rgba(255, 77, 77, 0.1)';
                    } else {
                        confirmValueInput.style.borderColor = '#00cc44';
                        confirmValueInput.style.boxShadow = '0 0 0 3px rgba(0, 204, 68, 0.1)';
                    }
                }
                
                // Allow only numeric input for PIN
                newValueInput.addEventListener('keypress', function(e) {
                    if (!/[0-9]/.test(e.key)) {
                        e.preventDefault();
                    }
                });
                confirmValueInput.addEventListener('keypress', function(e) {
                    if (!/[0-9]/.test(e.key)) {
                        e.preventDefault();
                    }
                });
            <?php endif; ?>

            // Handle reset form submission
            if (resetForm) {
                resetForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitButton.classList.add('loading');
                    submitButton.disabled = true;

                    // Client-side validation
                    if (!newValueInput.value || !confirmValueInput.value) {
                        showError('Semua field harus diisi.');
                        submitButton.classList.remove('loading');
                        submitButton.disabled = false;
                        return;
                    }
                    if (newValueInput.value !== confirmValueInput.value) {
                        showError('Konfirmasi tidak cocok.');
                        submitButton.classList.remove('loading');
                        submitButton.disabled = false;
                        return;
                    }
                    <?php if ($recovery_type === 'pin'): ?>
                        if (!/^[0-9]{6}$/.test(newValueInput.value)) {
                            showError('PIN harus berupa 6 digit angka.');
                            submitButton.classList.remove('loading');
                            submitButton.disabled = false;
                            return;
                        }
                    <?php endif; ?>
                    <?php if ($recovery_type === 'email'): ?>
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!emailRegex.test(newValueInput.value)) {
                            showError('Format email tidak valid.');
                            submitButton.classList.remove('loading');
                            submitButton.disabled = false;
                            return;
                        }
                    <?php endif; ?>
                    <?php if ($recovery_type === 'username'): ?>
                        if (!/^[a-zA-Z0-9_]{3,50}$/.test(newValueInput.value)) {
                            showError('Username harus 3-50 karakter, hanya huruf, angka, dan underscore.');
                            submitButton.classList.remove('loading');
                            submitButton.disabled = false;
                            return;
                        }
                    <?php endif; ?>
                    <?php if ($recovery_type === 'password'): ?>
                        if (newValueInput.value.length < 6) {
                            showError('Password harus minimal 6 karakter.');
                            submitButton.classList.remove('loading');
                            submitButton.disabled = false;
                            return;
                        }
                    <?php endif; ?>

                    // Submit form after delay
                    setTimeout(() => {
                        this.submit();
                    }, 1000);
                });
            }

            // Show error message
            function showError(message) {
                let errorAlert = document.getElementById('error-alert');
                if (!errorAlert) {
                    errorAlert = document.createElement('div');
                    errorAlert.className = 'error-message';
                    errorAlert.id = 'error-alert';
                    errorAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
                    document.querySelector('.form-description').after(errorAlert);
                } else {
                    errorAlert.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
                    errorAlert.style.display = 'flex';
                }
                setTimeout(() => {
                    errorAlert.classList.add('fade-out');
                }, 3000);
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                    errorAlert.classList.remove('fade-out');
                }, 3500);
            }
        });
    </script>
</body>
</html>