<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to admin only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../../pages/login.php?error=' . urlencode('Silakan login sebagai admin terlebih dahulu!'));
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Function to generate unique username
function generateUsername($nama, $conn) {
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

// Function to send email confirmation (same as before - keeping it complete)
function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $tingkatan_name, $kelas_name, $tipe_lama = 'Fisik', $tipe_baru = 'Digital') {
    if (empty($email)) {
        error_log("Skipping email sending as email is empty");
        return ['success' => true, 'duration' => 0];
    }
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'myschobank@gmail.com';
        $mail->Password = 'xpni zzju utfu mkth';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('myschobank@gmail.com', 'MY SCHOBANK');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
        
        $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
        $mail->MessageID = '<' . $unique_id . '>';
        $mail->addCustomHeader('X-Mailer', 'MY-SCHOBANK-System-v1.0');
        $mail->addCustomHeader('X-Priority', '1');
        $mail->addCustomHeader('Importance', 'High');
        
        $mail->isHTML(true);
        $mail->Subject = '[MY SCHOBANK] Perubahan Tipe Rekening - ' . date('Y-m-d H:i:s') . ' WIB';
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF'], 2);
        $login_link = $protocol . "://" . $host . $path . "/login.php";
        
        $bulan = [
            'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
            'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
            'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
        ];
        
        $tanggal_perubahan = date('d M Y H:i:s');
        foreach ($bulan as $en => $id) {
            $tanggal_perubahan = str_replace($en, $id, $tanggal_perubahan);
        }
        
        $kelas_lengkap = trim($tingkatan_name . ' ' . $kelas_name);
        
        $emailBody = "
        <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 5px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);'>
            <div style='background: linear-gradient(135deg, #1e293b 0%, #334155 100%); padding: 40px 30px; text-align: center; color: #ffffff;'>
                <h1 style='font-size: 28px; font-weight: 700; margin: 0 0 10px; letter-spacing: 0.5px;'>MY SCHOBANK</h1>
                <p style='font-size: 18px; font-weight: 500; margin: 0; opacity: 0.9;'>Perubahan Tipe Rekening Berhasil</p>
            </div>
            
            <div style='padding: 40px 30px;'>
                <h2 style='color: #1e293b; font-size: 22px; font-weight: 600; margin-bottom: 25px; text-align: center;'>Halo, {$nama}</h2>
                <p style='color: #475569; font-size: 16px; line-height: 1.7; margin-bottom: 30px; text-align: center;'>Tipe rekening Anda telah berhasil diubah. Berikut informasi detail rekening Anda:</p>
                
                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; padding: 25px; margin-bottom: 30px;'>
                    <h3 style='color: #1e293b; font-size: 16px; font-weight: 600; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;'>Informasi Rekening</h3>
                    <table style='width: 100%; font-size: 15px; color: #334155; border-collapse: collapse;'>
                        <tr style='border-bottom: 1px solid #e2e8f0;'>
                            <td style='padding: 12px 0; font-weight: 600; width: 45%; vertical-align: top;'>Nomor Rekening</td>
                            <td style='padding: 12px 0; text-align: right; font-family: monospace; font-size: 16px; font-weight: 700; color: #1e293b;'>{$no_rekening}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #e2e8f0;'>
                            <td style='padding: 12px 0; font-weight: 600; vertical-align: top;'>Nama Lengkap</td>
                            <td style='padding: 12px 0; text-align: right;'>{$nama}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #e2e8f0;'>
                            <td style='padding: 12px 0; font-weight: 600; vertical-align: top;'>Kelas</td>
                            <td style='padding: 12px 0; text-align: right;'>{$kelas_lengkap}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #e2e8f0;'>
                            <td style='padding: 12px 0; font-weight: 600; vertical-align: top;'>Jurusan</td>
                            <td style='padding: 12px 0; text-align: right;'>{$jurusan_name}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 0; font-weight: 600; vertical-align: top;'>Jenis Rekening</td>
                            <td style='padding: 12px 0; text-align: right;'>
                                <span style='color: #dc2626; font-weight: 600;'>{$tipe_lama}</span> 
                                <span style='margin: 0 5px;'>→</span> 
                                <span style='color: #10b981; font-weight: 600;'>{$tipe_baru}</span>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div style='background: #dbeafe; border: 2px solid #3b82f6; border-radius: 5px; padding: 25px; margin-bottom: 30px;'>
                    <h3 style='color: #1e40af; font-size: 16px; font-weight: 600; margin-bottom: 15px; text-align: center;'>
                        <span style='font-size: 20px;'>🔐</span> Kredensial Login
                    </h3>
                    <table style='width: 100%; font-size: 15px; color: #1e40af; border-collapse: collapse;'>
                        <tr style='border-bottom: 1px solid #93c5fd;'>
                            <td style='padding: 12px 0; font-weight: 600; width: 40%;'>Username</td>
                            <td style='padding: 12px 0; text-align: right; font-family: monospace; font-size: 16px; font-weight: 700;'>{$username}</td>
                        </tr>
                        <tr>
                            <td style='padding: 12px 0; font-weight: 600;'>Password Sementara</td>
                            <td style='padding: 12px 0; text-align: right; font-family: monospace; font-size: 16px; font-weight: 700; color: #dc2626;'>{$password}</td>
                        </tr>
                    </table>
                </div>
                
                <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 5px; padding: 20px; margin-bottom: 30px;'>
                    <p style='color: #dc2626; font-size: 16px; font-weight: 600; margin: 0 0 12px; text-align: center;'>
                        <span style='font-size: 20px;'>⚠️</span> Penting: Langkah Keamanan
                    </p>
                    <ul style='color: #991b1b; font-size: 14px; line-height: 1.8; margin: 0; padding-left: 25px;'>
                        <li>Segera ganti password sementara setelah login pertama kali</li>
                        <li>Atur PIN transaksi untuk keamanan tambahan</li>
                        <li>Jangan bagikan kredensial login kepada siapa pun</li>
                    </ul>
                </div>
                
                <div style='text-align: center; margin: 40px 0;'>
                    <a href='{$login_link}' style='display: inline-block; background: linear-gradient(135deg, #1e293b 0%, #334155 100%); color: #ffffff; padding: 15px 35px; border-radius: 5px; text-decoration: none; font-size: 16px; font-weight: 600; letter-spacing: 0.5px; box-shadow: 0 4px 14px rgba(30, 41, 59, 0.3);'>Masuk ke Akun Saya</a>
                </div>
                
                <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 40px 0;'>
                
                <p style='color: #64748b; font-size: 14px; line-height: 1.6; margin-bottom: 15px; text-align: center;'>Butuh bantuan? Hubungi tim dukungan kami:</p>
                <p style='color: #334155; font-size: 14px; line-height: 1.6; margin-bottom: 10px; text-align: center;'>
                    Email: <a href='mailto:myschobank@gmail.com' style='color: #1e293b; text-decoration: none; font-weight: 600;'>myschobank@gmail.com</a>
                </p>
                <p style='color: #64748b; font-size: 12px; margin: 0; text-align: center;'>
                    Tanggal Perubahan: {$tanggal_perubahan} WIB
                </p>
            </div>
            
            <div style='background: #f1f5f9; padding: 20px; text-align: center; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 5px;'>© " . date('Y') . " MY SCHOBANK. Semua hak dilindungi.</p>
                <p style='margin: 0; font-size: 12px;'>Pesan ini dikirim secara otomatis. Mohon tidak membalas.</p>
            </div>
        </div>";
        
        $mail->Body = $emailBody;
        
        $altBody = "MY SCHOBANK - Perubahan Tipe Rekening\n\nHalo {$nama},\n\nTipe rekening Anda telah berhasil diubah.\n\nInformasi Rekening:\n- Nomor Rekening: {$no_rekening}\n- Nama: {$nama}\n- Kelas: {$kelas_lengkap}\n- Jurusan: {$jurusan_name}\n- Jenis Rekening: {$tipe_lama} → {$tipe_baru}\n\nKredensial Login:\n- Username: {$username}\n- Password Sementara: {$password}\n\nPENTING:\n- Segera ganti password setelah login pertama kali\n- Atur PIN transaksi untuk keamanan tambahan\n- Jangan bagikan kredensial kepada siapa pun\n\nMasuk ke akun: {$login_link}\nHubungi: myschobank@gmail.com\n\nTanggal Perubahan: {$tanggal_perubahan} WIB\n\n© " . date('Y') . " MY SCHOBANK\nPesan otomatis - Mohon tidak membalas.";
        $mail->AltBody = $altBody;
        
        if ($mail->send()) {
            return ['success' => true];
        }
    } catch (Exception $e) {
        error_log("Gagal mengirim email: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
    return ['success' => false];
}

// Handle AJAX fetch user by no_rekening
if (isset($_GET['action']) && $_GET['action'] === 'fetch_user' && isset($_GET['no_rekening'])) {
    header('Content-Type: application/json');
    $no_rekening = trim($_GET['no_rekening']);
    
    if (empty($no_rekening)) {
        echo json_encode(['error' => 'Nomor rekening tidak boleh kosong!']);
        exit;
    }
    
    $query = "SELECT u.*, r.no_rekening, j.nama_jurusan, t.nama_tingkatan, k.nama_kelas 
              FROM users u 
              JOIN rekening r ON u.id = r.user_id 
              LEFT JOIN jurusan j ON u.jurusan_id = j.id 
              LEFT JOIN kelas k ON u.kelas_id = k.id 
              LEFT JOIN tingkatan_kelas t ON k.tingkatan_kelas_id = t.id 
              WHERE r.no_rekening = ? AND u.role = 'siswa'";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $no_rekening);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['error' => 'Nasabah dengan nomor rekening tersebut tidak ditemukan!']);
    } else {
        $user = $result->fetch_assoc();
        echo json_encode([
            'success' => true,
            'user' => $user,
            'current_tipe' => $user['email_status'] === 'terisi' ? 'digital' : 'fisik'
        ]);
    }
    $stmt->close();
    exit;
}

// Initialize variables and handle form submission (same as before)
$errors = [];
$success = '';
$selected_user = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $no_rekening = trim($_POST['no_rekening'] ?? '');
    $new_tipe = trim($_POST['new_tipe_rekening'] ?? '');
    $new_email = trim($_POST['new_email'] ?? '');
    $user_id = (int)($_POST['user_id'] ?? 0);
    
    if (empty($no_rekening)) {
        $errors['no_rekening'] = "Nomor rekening wajib diisi!";
    }
    
    if (empty($user_id)) {
        $errors['user_id'] = "Nasabah tidak valid!";
    }
    
    if (!in_array($new_tipe, ['digital', 'fisik'])) {
        $errors['new_tipe_rekening'] = "Tipe rekening tidak valid!";
    }
    
    if ($new_tipe === 'digital' && empty($new_email)) {
        $errors['new_email'] = "Email wajib diisi untuk digital!";
    }
    
    if ($new_tipe === 'digital' && !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors['new_email'] = "Format email tidak valid!";
    }
    
    if (!$errors) {
        $query_user = "SELECT u.*, r.no_rekening, j.nama_jurusan, t.nama_tingkatan, k.nama_kelas 
                       FROM users u 
                       JOIN rekening r ON u.id = r.user_id 
                       LEFT JOIN jurusan j ON u.jurusan_id = j.id 
                       LEFT JOIN kelas k ON u.kelas_id = k.id 
                       LEFT JOIN tingkatan_kelas t ON k.tingkatan_kelas_id = t.id 
                       WHERE u.id = ? AND u.role = 'siswa'";
        $stmt_user = $conn->prepare($query_user);
        $stmt_user->bind_param("i", $user_id);
        $stmt_user->execute();
        $result_user = $stmt_user->get_result();
        
        if ($result_user->num_rows === 0) {
            $errors['general'] = "Nasabah tidak ditemukan!";
        } else {
            $selected_user = $result_user->fetch_assoc();
            $current_tipe = $selected_user['email_status'] === 'terisi' ? 'digital' : 'fisik';
            
            if ($new_tipe === $current_tipe) {
                $errors['general'] = "Tipe rekening sama dengan saat ini! Silakan pilih tipe yang berbeda.";
            } else {
                $conn->begin_transaction();
                try {
                    $password = '12345';
                    $email_status = $new_tipe === 'digital' ? 'terisi' : 'kosong';
                    $update_email = $new_tipe === 'digital' ? $new_email : null;
                    
                    $new_username = null;
                    if ($new_tipe === 'digital') {
                        $new_username = generateUsername($selected_user['nama'], $conn);
                    }
                    
                    if ($new_tipe === 'digital') {
                        $query_update = "UPDATE users SET email = ?, email_status = ?, username = ?, password = SHA2(?, 256) WHERE id = ?";
                        $stmt_update = $conn->prepare($query_update);
                        if (!$stmt_update) {
                            throw new Exception("Gagal menyiapkan update: " . $conn->error);
                        }
                        $stmt_update->bind_param("ssssi", $update_email, $email_status, $new_username, $password, $user_id);
                    } else {
                        $query_update = "UPDATE users SET email = NULL, email_status = ?, username = NULL, password = NULL WHERE id = ?";
                        $stmt_update = $conn->prepare($query_update);
                        if (!$stmt_update) {
                            throw new Exception("Gagal menyiapkan update: " . $conn->error);
                        }
                        $stmt_update->bind_param("si", $email_status, $user_id);
                    }
                    
                    if (!$stmt_update->execute()) {
                        throw new Exception("Gagal update tipe: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                    
                    $tipe_display = $new_tipe === 'digital' ? 'Digital' : 'Fisik';
                    $tipe_lama_display = $current_tipe === 'digital' ? 'Digital' : 'Fisik';
                    
                    if ($new_tipe === 'digital') {
                        sendEmailConfirmation(
                            $update_email,
                            $selected_user['nama'],
                            $new_username,
                            $selected_user['no_rekening'],
                            $password,
                            $selected_user['nama_jurusan'],
                            $selected_user['nama_tingkatan'],
                            $selected_user['nama_kelas'],
                            $tipe_lama_display,
                            $tipe_display
                        );
                    }
                    
                    $conn->commit();
                    $_SESSION['form_token'] = bin2hex(random_bytes(32));
                    
                    if ($new_tipe === 'digital') {
                        $success = "Tipe rekening berhasil diubah menjadi {$tipe_display}! Kredensial login telah dikirim ke email.";
                    } else {
                        $success = "Tipe rekening berhasil diubah menjadi {$tipe_display}! Email, username, dan password telah dihapus.";
                    }
                    
                    $_SESSION['reset_form'] = true;
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Update tipe gagal: " . $e->getMessage());
                    $errors['general'] = "Gagal mengubah tipe: " . $e->getMessage();
                }
            }
        }
        $stmt_user->close();
    }
}

$should_reset = false;
if (isset($_SESSION['reset_form']) && $_SESSION['reset_form']) {
    $should_reset = true;
    unset($_SESSION['reset_form']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ubah Jenis Rekening | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
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
            display: flex;
            min-height: 100vh;
        }
        
        .main-content { 
            flex: 1; 
            margin-left: 280px; 
            padding: clamp(15px, 4vw, 30px);
            max-width: calc(100% - 280px);
            width: 100%;
        }
        
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%); 
            color: white; 
            padding: clamp(20px, 4vw, 30px);
            border-radius: 5px;
            margin-bottom: clamp(20px, 4vw, 35px);
            box-shadow: var(--shadow-md); 
            display: flex; 
            align-items: center; 
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .welcome-banner .content {
            flex: 1;
            min-width: 200px;
        }
        
        .welcome-banner h2 { 
            margin-bottom: 8px;
            font-size: clamp(1.2rem, 3.5vw, 1.6rem);
            font-weight: 600; 
            display: flex; 
            align-items: center; 
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .welcome-banner p { 
            font-size: clamp(0.8rem, 2vw, 0.9rem);
            font-weight: 400; 
            opacity: 0.9;
            line-height: 1.5;
        }
        
        .menu-toggle { 
            font-size: clamp(1.3rem, 3vw, 1.5rem);
            cursor: pointer; 
            color: white; 
            display: none; 
            align-self: center;
            padding: 8px;
            -webkit-tap-highlight-color: transparent;
        }
        
        .form-card { 
            background: white; 
            border-radius: 5px;
            padding: clamp(20px, 4vw, 25px);
            box-shadow: var(--shadow-sm); 
            margin-bottom: clamp(20px, 4vw, 35px);
        }
        
        .form-group { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
            margin-bottom: 15px; 
        }
        
        label { 
            font-weight: 500; 
            color: var(--text-secondary); 
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        input[type="text"], input[type="email"] { 
            width: 100%; 
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 15px);
            border: 1px solid #ddd; 
            border-radius: 5px;
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            transition: var(--transition);
        }
        
        input:focus { 
            outline: none; 
            border-color: var(--primary-color); 
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1); 
        }
        
        .error-message { 
            color: var(--danger-color); 
            font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            margin-top: 4px; 
        }
        
        .details-wrapper {
            display: none;
            gap: clamp(15px, 3vw, 20px);
            margin: clamp(15px, 3vw, 20px) 0;
        }
        
        .details-wrapper.show {
            display: grid;
            grid-template-columns: 1fr 1fr;
        }
        
        .user-details, .new-tipe-section { 
            background: #f8fafc; 
            border: 1px solid #e2e8f0; 
            border-radius: 5px;
            padding: clamp(15px, 3vw, 20px);
        }
        
        .user-details h3, .new-tipe-section h3 { 
            color: var(--primary-color); 
            margin-bottom: clamp(12px, 2.5vw, 15px);
            font-size: clamp(0.95rem, 2.2vw, 1.05rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .detail-row { 
            display: flex; 
            justify-content: space-between;
            align-items: flex-start;
            padding: clamp(6px, 1.5vw, 8px) 0;
            border-bottom: 1px solid #e2e8f0; 
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            gap: 10px;
        }
        
        .detail-row:last-child { border-bottom: none; }
        
        .detail-row .label { 
            color: var(--text-secondary); 
            font-weight: 500;
            flex-shrink: 0;
        }
        
        .detail-row .value { 
            color: var(--text-primary); 
            font-weight: 600; 
            text-align: right;
            word-break: break-word;
        }
        
        .tipe-badge {
            display: inline-block;
            padding: clamp(3px, 1vw, 4px) clamp(10px, 2vw, 12px);
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            font-weight: 600;
        }
        
        .tipe-badge.digital {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .tipe-badge.fisik {
            background: #fef3c7;
            color: #92400e;
        }
        
        .new-tipe-display {
            background: white;
            border: 2px solid var(--primary-color);
            border-radius: 5px;
            padding: clamp(12px, 2.5vw, 15px);
            text-align: center;
            margin: clamp(12px, 2.5vw, 15px) 0;
        }
        
        .new-tipe-display .tipe-badge {
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            padding: clamp(6px, 1.5vw, 8px) clamp(16px, 3vw, 20px);
        }
        
        .email-input-group {
            display: none;
            margin-top: clamp(12px, 2.5vw, 15px);
        }
        
        .email-input-group.show {
            display: block;
        }
        
        .btn { 
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%); 
            color: white; 
            border: none; 
            padding: clamp(10px, 2vw, 12px) clamp(20px, 4vw, 25px);
            border-radius: 5px;
            cursor: pointer; 
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 500; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            gap: 8px; 
            width: 100%;
            max-width: 200px;
            margin: clamp(15px, 3vw, 20px) auto;
            transition: var(--transition);
            -webkit-tap-highlight-color: transparent;
        }
        
        .btn:hover { 
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%); 
            transform: translateY(-2px); 
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn:disabled { 
            opacity: 0.6; 
            cursor: not-allowed;
            transform: none;
        }
        
        @media (max-width: 1024px) and (min-width: 769px) {
            .main-content {
                margin-left: 250px;
                max-width: calc(100% - 250px);
            }
        }
        
        @media (max-width: 768px) {
            .main-content { 
                margin-left: 0; 
                padding: 15px;
                max-width: 100%;
            }
            
            .menu-toggle { 
                display: block; 
            }
            
            .details-wrapper.show {
                grid-template-columns: 1fr;
            }
            
            .detail-row { 
                flex-direction: column;
                gap: 4px;
                align-items: flex-start;
            }
            
            .detail-row .value { 
                text-align: left;
                width: 100%;
            }
            
            .btn {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
            }
            
            .welcome-banner h2 {
                font-size: 1.1rem;
            }
            
            .form-card {
                padding: 15px;
            }
            
            .user-details, .new-tipe-section {
                padding: 12px;
            }
            
            input[type="text"], input[type="email"] {
                font-size: 16px;
            }
        }
        
        @media (max-width: 374px) {
            .welcome-banner h2 {
                font-size: 1rem;
            }
            
            .tipe-badge {
                font-size: 0.7rem;
                padding: 2px 8px;
            }
        }
        
        @media (max-height: 500px) and (orientation: landscape) {
            .welcome-banner {
                padding: 12px 20px;
                margin-bottom: 15px;
            }
            
            .form-card {
                padding: 15px;
            }
        }
        
        @media (hover: none) and (pointer: coarse) {
            input[type="text"], 
            input[type="email"],
            .btn {
                min-height: 44px;
            }
            
            .menu-toggle {
                min-width: 44px;
                min-height: 44px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2><i class="fas fa-exchange-alt"></i> Ubah Tipe Rekening</h2>
                <p>Ubah tipe rekening nasabah dari Digital ke Fisik atau sebaliknya</p>
            </div>
        </div>
        
        <div class="form-card">
            <form action="" method="POST" id="ubahForm">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" id="user_id" name="user_id" value="">
                <input type="hidden" id="new_tipe_rekening" name="new_tipe_rekening" value="">
                
                <div class="form-group">
                    <label for="no_rekening"><i class="fas fa-hashtag"></i> Nomor Rekening</label>
                    <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan nomor rekening" value="" required autocomplete="off">
                    <span class="error-message" id="no-rekening-error"></span>
                </div>
                
                <div id="details-wrapper" class="details-wrapper">
                    <div class="user-details">
                        <h3><i class="fas fa-user-circle"></i> Detail Nasabah</h3>
                        <div id="detail-content"></div>
                    </div>
                    
                    <div class="new-tipe-section">
                        <h3><i class="fas fa-arrow-right"></i> Jenis Rekening Baru</h3>
                        <div class="new-tipe-display">
                            <div id="new-tipe-badge"></div>
                        </div>
                        
                        <div id="email-input-group" class="email-input-group">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="new_email"><i class="fas fa-envelope"></i> Email Baru (Wajib)</label>
                                <input type="email" id="new_email" name="new_email" placeholder="Masukkan email valid" disabled autocomplete="off">
                                <span class="error-message" id="email-error"></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="submit" id="submit-btn" class="btn" disabled>
                    <span><i class="fas fa-save"></i> Ubah Tipe</span>
                </button>
            </form>
        </div>
    </div>
    
    <script>
        let currentTipe = null;
        let searchTimeout = null;
        
        function resetForm() {
            $('#no_rekening').val('');
            $('#user_id').val('');
            $('#new_tipe_rekening').val('');
            $('#new_email').val('');
            $('#details-wrapper').removeClass('show');
            $('#email-input-group').removeClass('show');
            $('#new_email').prop('disabled', true).prop('required', false);
            $('#submit-btn').prop('disabled', true);
            $('#no-rekening-error').text('');
            $('#email-error').text('');
            currentTipe = null;
            
            setTimeout(() => {
                $('#no_rekening').focus();
            }, 300);
        }
        
        <?php if ($should_reset): ?>
        $(document).ready(function() {
            resetForm();
        });
        <?php endif; ?>
        
        <?php if ($success): ?>
        Swal.fire({ 
            icon: 'success', 
            title: 'Berhasil!', 
            text: '<?php echo addslashes($success); ?>', 
            confirmButtonColor: '#1e3a8a',
            timer: 4000,
            timerProgressBar: true
        }).then(() => {
            resetForm();
        });
        <?php endif; ?>
        
        <?php if (isset($errors['general'])): ?>
        Swal.fire({ 
            icon: 'error', 
            title: 'Gagal!', 
            html: '<?php echo addslashes($errors['general']); ?>', 
            confirmButtonColor: '#e74c3c'
        }).then(() => {
            resetForm();
        });
        <?php endif; ?>
        
        // Debounced search untuk menunggu user selesai mengetik
        $('#no_rekening').on('input', function() {
            const noRek = $(this).val().trim();
            const detailsWrapper = $('#details-wrapper');
            const emailInputGroup = $('#email-input-group');
            const emailInput = $('#new_email');
            const submitBtn = $('#submit-btn');
            
            // Clear previous timeout
            if (searchTimeout) {
                clearTimeout(searchTimeout);
            }
            
            // Reset jika input kosong atau terlalu pendek
            if (noRek.length < 8) {
                detailsWrapper.removeClass('show');
                emailInputGroup.removeClass('show');
                emailInput.prop('disabled', true).prop('required', false).val('');
                submitBtn.prop('disabled', true);
                $('#no-rekening-error').text('');
                $('#user_id').val('');
                $('#new_tipe_rekening').val('');
                return;
            }
            
            // Set timeout 800ms setelah user berhenti mengetik
            searchTimeout = setTimeout(function() {
                $.ajax({
                    url: '?action=fetch_user&no_rekening=' + encodeURIComponent(noRek),
                    type: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            const user = data.user;
                            currentTipe = data.current_tipe;
                            $('#user_id').val(user.id);
                            
                            let detailHtml = '';
                            
                            detailHtml += `<div class="detail-row"><span class="label">No. Rekening:</span><span class="value">${user.no_rekening}</span></div>`;
                            detailHtml += `<div class="detail-row"><span class="label">Nama:</span><span class="value">${user.nama}</span></div>`;
                            detailHtml += `<div class="detail-row"><span class="label">Kelas:</span><span class="value">${user.nama_tingkatan} ${user.nama_kelas}</span></div>`;
                            detailHtml += `<div class="detail-row"><span class="label">Jurusan:</span><span class="value">${user.nama_jurusan || '-'}</span></div>`;
                            
                            if (currentTipe === 'digital') {
                                detailHtml += `<div class="detail-row"><span class="label">Email:</span><span class="value">${user.email || '-'}</span></div>`;
                            }
                            
                            const tipeBadge = currentTipe === 'digital' 
                                ? '<span class="tipe-badge digital">Digital</span>' 
                                : '<span class="tipe-badge fisik">Fisik</span>';
                            detailHtml += `<div class="detail-row"><span class="label">Jenis Rekening:</span><span class="value">${tipeBadge}</span></div>`;
                            
                            $('#detail-content').html(detailHtml);
                            
                            const oppositeTipe = currentTipe === 'digital' ? 'fisik' : 'digital';
                            $('#new_tipe_rekening').val(oppositeTipe);
                            
                            const newTipeBadge = oppositeTipe === 'digital' 
                                ? '<span class="tipe-badge digital">Digital (Online)</span>' 
                                : '<span class="tipe-badge fisik">Fisik (Offline)</span>';
                            $('#new-tipe-badge').html(newTipeBadge);
                            
                            detailsWrapper.addClass('show');
                            
                            if (oppositeTipe === 'digital') {
                                emailInputGroup.addClass('show');
                                emailInput.prop('disabled', false).prop('required', true);
                                submitBtn.prop('disabled', true);
                            } else {
                                emailInputGroup.removeClass('show');
                                emailInput.prop('disabled', true).prop('required', false).val('');
                                submitBtn.prop('disabled', false);
                            }
                            
                            $('#no-rekening-error').text('');
                        } else {
                            detailsWrapper.removeClass('show');
                            emailInputGroup.removeClass('show');
                            emailInput.prop('disabled', true).prop('required', false).val('');
                            submitBtn.prop('disabled', true);
                            $('#no-rekening-error').text('');
                            
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Nasabah Tidak Ditemukan', 
                                text: data.error,
                                confirmButtonColor: '#e74c3c',
                                footer: '<small>Pastikan nomor rekening sudah benar</small>'
                            }).then(() => {
                                resetForm();
                            });
                        }
                    },
                    error: function() {
                        detailsWrapper.removeClass('show');
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Kesalahan Server', 
                            text: 'Gagal memuat data nasabah. Silakan coba lagi.',
                            confirmButtonColor: '#e74c3c'
                        }).then(() => {
                            resetForm();
                        });
                    }
                });
            }, 800); // Tunggu 800ms setelah user berhenti mengetik
        });
        
        $('#new_email').on('input', function() {
            const email = $(this).val().trim();
            const submitBtn = $('#submit-btn');
            
            if (email.length > 0 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                submitBtn.prop('disabled', false);
            } else {
                submitBtn.prop('disabled', true);
            }
        });
        
        document.addEventListener('DOMContentLoaded', () => {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }
            
            document.addEventListener('click', (e) => {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            
            $('#ubahForm').on('submit', function(e) {
                e.preventDefault();
                
                const noRek = $('#no_rekening').val().trim();
                const tipe = $('#new_tipe_rekening').val();
                const email = $('#new_email').val().trim();
                
                if (!noRek || !$('#user_id').val()) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Data Tidak Lengkap', 
                        text: 'Masukkan nomor rekening yang valid!',
                        confirmButtonColor: '#e74c3c'
                    }).then(() => {
                        resetForm();
                    });
                    return;
                }
                
                if (!tipe) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Tipe Belum Dipilih', 
                        text: 'Pilih tipe rekening baru!',
                        confirmButtonColor: '#e74c3c'
                    });
                    return;
                }
                
                if (tipe === 'digital' && !email) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Email Wajib Diisi', 
                        text: 'Email wajib untuk rekening digital!',
                        confirmButtonColor: '#e74c3c'
                    }).then(() => {
                        $('#new_email').focus();
                    });
                    return;
                }
                
                if (tipe === 'digital' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Email Tidak Valid', 
                        text: 'Format email tidak sesuai!',
                        confirmButtonColor: '#e74c3c',
                        footer: '<small>Contoh: nama@domain.com</small>'
                    }).then(() => {
                        $('#new_email').focus();
                    });
                    return;
                }
                
                const nama = $('#detail-content').find('.detail-row').eq(1).find('.value').text();
                
                let confirmMessage = '';
                if (tipe === 'digital') {
                    confirmMessage = `
                        <div style='text-align: left; padding: 10px;'>
                            <p style='margin-bottom: 15px;'>Ubah tipe rekening <strong>${nama}</strong> (${noRek}) menjadi <strong>Digital</strong>?</p>
                            <div style='background: #dbeafe; border-left: 4px solid #3b82f6; padding: 12px; border-radius: 5px;'>
                                <p style='margin: 0; font-size: 0.9rem;'><strong>📝 Informasi:</strong></p>
                                <ul style='margin: 5px 0 0 20px; font-size: 0.85rem; line-height: 1.6;'>
                                    <li>Kredensial login akan digenerate otomatis</li>
                                    <li>Kredensial akan dikirim ke email: <strong>${email}</strong></li>
                                </ul>
                            </div>
                        </div>
                    `;
                } else {
                    confirmMessage = `
                        <div style='text-align: left; padding: 10px;'>
                            <p style='margin-bottom: 15px;'>Ubah tipe rekening <strong>${nama}</strong> (${noRek}) menjadi <strong>Fisik</strong>?</p>
                            <div style='background: #fef2f2; border-left: 4px solid #ef4444; padding: 12px; border-radius: 5px;'>
                                <p style='margin: 0; font-size: 0.9rem;'><strong>⚠️ Peringatan:</strong></p>
                                <ul style='margin: 5px 0 0 20px; font-size: 0.85rem; line-height: 1.6;'>
                                    <li>Email akan dihapus dari sistem</li>
                                    <li>Username akan dihapus</li>
                                    <li>Password akan dihapus</li>
                                    <li>Nasabah tidak bisa login online</li>
                                </ul>
                            </div>
                        </div>
                    `;
                }
                
                Swal.fire({
                    title: 'Konfirmasi Perubahan',
                    html: confirmMessage,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Ya, Ubah Sekarang!',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#6b7280',
                    width: window.innerWidth < 600 ? '95%' : '600px'
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({
                            title: 'Memproses...',
                            html: 'Sedang mengubah tipe rekening, mohon tunggu sebentar.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });
                        
                        $('#submit-btn').html('<span><i class="fas fa-spinner fa-spin"></i> Memproses...</span>').prop('disabled', true);
                        $('#ubahForm')[0].submit();
                    }
                });
            });
        });
    </script>
</body>
</html>
