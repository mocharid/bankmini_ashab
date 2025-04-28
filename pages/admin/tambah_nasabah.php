<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);

$showConfirmation = false;
$formData = [];
$show_success_popup = false;
$error = null;
$show_error_popup = false;

function generateUsername($nama) {
    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nama));
    return $username;
}

function maskString($string, $visibleStart = 0, $visibleEnd = 0) {
    return '***';
}

function sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name, $no_wa = null) {
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
        $mail->addAddress($email, $nama);
        
        $mail->isHTML(true);
        $mail->Subject = 'Pembukaan Rekening Berhasil - SCHOBANK SYSTEM';
        
        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <div style='text-align: center; padding: 15px; background-color: #0a2e5c; color: white; border-radius: 8px 8px 0 0;'>
                <h2 style='margin: 0; font-size: 24px;'>SCHOBANK SYSTEM</h2>
            </div>
            <div style='padding: 20px;'>
                <h3 style='color: #0a2e5c; font-size: 20px; margin-bottom: 20px;'>Pembukaan Rekening Berhasil</h3>
                <p style='color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Yth. Bapak/Ibu {$nama},</p>
                <p style='color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Kami dengan senang hati menginformasikan bahwa rekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah rincian akun Anda:</p>
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                    <tr style='background-color: #f9f9f9;'>
                        <td style='padding: 10px; font-weight: bold; color: #333; width: 40%; border: 1px solid #e0e0e0;'>Nama</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$nama}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Nomor Rekening</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$no_rekening}</td>
                    </tr>
                    <tr style='background-color: #f9f9f9;'>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Username</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$username}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Password</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$password}</td>
                    </tr>
                    <tr style='background-color: #f9f9f9;'>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Jurusan</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$jurusan_name}</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Kelas</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>{$kelas_name}</td>
                    </tr>
                    <tr style='background-color: #f9f9f9;'>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>No WhatsApp</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>".($no_wa ? $no_wa : '-')."</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;'>Saldo Awal</td>
                        <td style='padding: 10px; color: #333; border: 1px solid #e0e0e0;'>Rp 0</td>
                    </tr>
                </table>
                <p style='color: #d32f2f; font-size: 14px; font-weight: bold; margin-bottom: 20px;'>Penting: Harap ubah kata sandi Anda setelah login pertama untuk menjaga keamanan akun Anda.</p>
                <p style='color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;'>Jika Anda memiliki pertanyaan, silakan hubungi tim kami melalui email atau telepon yang tersedia di situs resmi kami.</p>
                <p style='color: #333; font-size: 16px; line-height: 1.6;'>Hormat kami,<br>Tim SCHOBANK SYSTEM</p>
            </div>
            <div style='text-align: center; padding: 15px; background-color: #f5f5f5; border-radius: 0 0 8px 8px; color: #666; font-size: 12px;'>
                <p style='margin: 0;'>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
            </div>
        </div>";
        
        $mail->Body = $emailBody;
        $altBody = "Yth. Bapak/Ibu {$nama},\n\nKami dengan senang hati menginformasikan bahwa rekening Anda telah berhasil dibuat di SCHOBANK SYSTEM. Berikut adalah rincian akun Anda:\n\nNama: {$nama}\nNomor Rekening: {$no_rekening}\nUsername: {$username}\nPassword: {$password}\nJurusan: {$jurusan_name}\nKelas: {$kelas_name}\nNo WhatsApp: ".($no_wa ? $no_wa : '-')."\nSaldo Awal: Rp 0\n\nPenting: Harap ubah kata sandi Anda setelah login pertama untuk menjaga keamanan akun Anda.\n\nJika Anda memiliki pertanyaan, silakan hubungi tim kami.\n\nHormat kami,\nTim SCHOBANK SYSTEM\n\nEmail ini dibuat secara otomatis. Mohon tidak membalas email ini.";
        $mail->AltBody = $altBody;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['cancel'])) {
        header('Location: tambah_nasabah.php');
        exit();
    }

    if (isset($_POST['confirmed'])) {
        // Process confirmation
        $nama = trim($_POST['nama']);
        $username = $_POST['username'];
        $password = '12345';
        $password_hash = hash('sha256', $password);
        $jurusan_id = $_POST['jurusan_id'];
        $kelas_id = $_POST['kelas_id'];
        $no_rekening = $_POST['no_rekening'];
        $email = trim($_POST['email']);
        $no_wa = !empty($_POST['no_wa']) ? trim($_POST['no_wa']) : null;
        
        // Validate username uniqueness
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        if (!$check_stmt) {
            $error = "Error preparing username check: " . $conn->error;
            $show_error_popup = true;
        } else {
            $check_stmt->bind_param("s", $username);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $username = $username . rand(100, 999);
            }
            $check_stmt->close();
        }

        if (!$show_error_popup) {
            // Insert user
            $query = "INSERT INTO users (username, password, role, nama, jurusan_id, kelas_id, email, no_wa) 
                    VALUES (?, ?, 'siswa', ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($query);
            if (!$stmt) {
                $error = "Error preparing user insert: " . $conn->error;
                $show_error_popup = true;
            } else {
                $stmt->bind_param("sssisis", $username, $password_hash, $nama, $jurusan_id, $kelas_id, $email, $no_wa);
                if ($stmt->execute()) {
                    $user_id = $conn->insert_id;
                    // Insert rekening
                    $query_rekening = "INSERT INTO rekening (no_rekening, user_id, saldo) VALUES (?, ?, 0)";
                    $stmt_rekening = $conn->prepare($query_rekening);
                    if (!$stmt_rekening) {
                        $error = "Error preparing rekening insert: " . $conn->error;
                        $show_error_popup = true;
                    } else {
                        $stmt_rekening->bind_param("si", $no_rekening, $user_id);
                        if ($stmt_rekening->execute()) {
                            // Fetch jurusan and kelas names
                            $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
                            $stmt_jurusan = $conn->prepare($query_jurusan_name);
                            $stmt_jurusan->bind_param("i", $jurusan_id);
                            $stmt_jurusan->execute();
                            $result_jurusan_name = $stmt_jurusan->get_result();
                            $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
                            
                            $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                            $stmt_kelas = $conn->prepare($query_kelas_name);
                            $stmt_kelas->bind_param("i", $kelas_id);
                            $stmt_kelas->execute();
                            $result_kelas_name = $stmt_kelas->get_result();
                            $kelas_name = $result_kelas_name->fetch_assoc()['nama_kelas'];
                            
                            // Send email
                            $email_sent = sendEmailConfirmation($email, $nama, $username, $no_rekening, $password, $jurusan_name, $kelas_name, $no_wa);
                            
                            $show_success_popup = true;
                            header('refresh:3;url=tambah_nasabah.php');
                        } else {
                            $error = "Error saat membuat rekening: " . $stmt_rekening->error;
                            $show_error_popup = true;
                        }
                        $stmt_rekening->close();
                    }
                } else {
                    $error = "Error saat menambah user: " . $stmt->error;
                    $show_error_popup = true;
                }
                $stmt->close();
            }
        }
        // Clear POST data to prevent reprocessing
        $_POST = [];
    } else {
        // Initial form submission
        $showConfirmation = true;
        $nama = trim($_POST['nama']);
        $username = generateUsername($nama);
        $email = trim($_POST['email']);
        $no_wa = !empty($_POST['no_wa']) ? trim($_POST['no_wa']) : null;
        $jurusan_id = isset($_POST['jurusan_id']) ? $_POST['jurusan_id'] : '';
        $kelas_id = isset($_POST['kelas_id']) ? $_POST['kelas_id'] : '';
        
        // Validate inputs
        if (strlen($nama) < 3) {
            $error = "Nama harus minimal 3 karakter!";
            $showConfirmation = false;
            $show_error_popup = true;
        } elseif (empty($email)) {
            $error = "Email wajib diisi!";
            $showConfirmation = false;
            $show_error_popup = true;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format email tidak valid!";
            $showConfirmation = false;
            $show_error_popup = true;
        } elseif ($no_wa && !preg_match('/^\+?\d{10,15}$/', $no_wa)) {
            $error = "Nomor WhatsApp tidak valid!";
            $showConfirmation = false;
            $show_error_popup = true;
        } elseif (empty($jurusan_id)) {
            $error = "Jurusan wajib dipilih!";
            $showConfirmation = false;
            $show_error_popup = true;
        } elseif (empty($kelas_id)) {
            $error = "Kelas wajib dipilih!";
            $showConfirmation = false;
            $show_error_popup = true;
        } else {
            // Validate email uniqueness
            $check_email_query = "SELECT id FROM users WHERE email = ?";
            $check_email_stmt = $conn->prepare($check_email_query);
            $check_email_stmt->bind_param("s", $email);
            $check_email_stmt->execute();
            if ($check_email_stmt->get_result()->num_rows > 0) {
                $error = "Email sudah digunakan! Silakan gunakan email lain.";
                $showConfirmation = false;
                $show_error_popup = true;
            }
            // Validate WhatsApp number uniqueness (if provided)
            elseif ($no_wa) {
                $check_wa_query = "SELECT id FROM users WHERE no_wa = ?";
                $check_wa_stmt = $conn->prepare($check_wa_query);
                $check_wa_stmt->bind_param("s", $no_wa);
                $check_wa_stmt->execute();
                if ($check_wa_stmt->get_result()->num_rows > 0) {
                    $error = "Nomor WhatsApp sudah digunakan! Silakan gunakan nomor lain.";
                    $showConfirmation = false;
                    $show_error_popup = true;
                }
                $check_wa_stmt->close();
            }
            $check_email_stmt->close();

            if (!$show_error_popup) {
                // Validate username uniqueness
                $check_query = "SELECT id FROM users WHERE username = ?";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("s", $username);
                $check_stmt->execute();
                if ($check_stmt->get_result()->num_rows > 0) {
                    $username = $username . rand(100, 999);
                }
                $check_stmt->close();
                
                $no_rekening = 'REK' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                
                $formData = [
                    'nama' => $nama,
                    'username' => $username,
                    'password' => '12345',
                    'jurusan_id' => $jurusan_id,
                    'kelas_id' => $kelas_id,
                    'no_rekening' => $no_rekening,
                    'email' => $email,
                    'no_wa' => $no_wa
                ];
                
                // Fetch jurusan and kelas names
                $query_jurusan_name = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
                $stmt_jurusan = $conn->prepare($query_jurusan_name);
                if (!$stmt_jurusan) {
                    $error = "Error preparing jurusan query: " . $conn->error;
                    $showConfirmation = false;
                    $show_error_popup = true;
                } else {
                    $stmt_jurusan->bind_param("i", $jurusan_id);
                    $stmt_jurusan->execute();
                    $result_jurusan_name = $stmt_jurusan->get_result();
                    if ($result_jurusan_name->num_rows === 0) {
                        $error = "Jurusan tidak ditemukan!";
                        $showConfirmation = false;
                        $show_error_popup = true;
                    } else {
                        $jurusan_name = $result_jurusan_name->fetch_assoc()['nama_jurusan'];
                        
                        $query_kelas_name = "SELECT nama_kelas FROM kelas WHERE id = ?";
                        $stmt_kelas = $conn->prepare($query_kelas_name);
                        if (!$stmt_kelas) {
                            $error = "Error preparing kelas query: " . $conn->error;
                            $showConfirmation = false;
                            $show_error_popup = true;
                        } else {
                            $stmt_kelas->bind_param("i", $kelas_id);
                            $stmt_kelas->execute();
                            $result_kelas_name = $stmt_kelas->get_result();
                            if ($result_kelas_name->num_rows === 0) {
                                $error = "Kelas tidak ditemukan!";
                                $showConfirmation = false;
                                $show_error_popup = true;
                            } else {
                                $kelas_name = $result_kelas_name->fetch_assoc()['nama_kelas'];
                            }
                            $stmt_kelas->close();
                        }
                    }
                    $stmt_jurusan->close();
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tambah Nasabah - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .deposit-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .deposit-form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input, select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        select {
            appearance: none;
            background: white;
            position: relative;
        }

        .select-wrapper {
            position: relative;
        }

        .select-wrapper::after {
            content: '\f078';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            color: var(--text-secondary);
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
            padding: 14px 30px;
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: var(--danger-color);
            padding: 14px 30px;
        }

        .btn-cancel:hover {
            background-color: #d32f2f;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.4s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            background: linear-gradient(145deg, #ffffff 0%, #f5f8ff 100%);
            border-radius: 24px;
            padding: clamp(30px, 6vw, 40px);
            text-align: center;
            max-width: 95%;
            width: clamp(360px, 80vw, 520px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            margin: 20px auto;
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        .error-modal {
            background: linear-gradient(145deg, #ffffff 0%, #fff0f0 100%);
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .error-icon {
            color: var(--danger-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: var(--primary-dark);
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.3vw, 1.05rem);
            margin: 0 0 25px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content {
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.9rem, 2.2vw, 1rem);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: clamp(0.9rem, 2.2vw, 1rem);
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 5rem;
        }

        .loading-spinner {
            font-size: 1.5rem;
            color: var(--primary-color);
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .deposit-card {
                padding: 20px;
            }

            .deposit-form {
                gap: 15px;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .success-modal, .error-modal {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .success-modal, .error-modal {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }

            .modal-content {
                padding: 12px;
                gap: 12px;
            }

            .modal-row {
                padding: 10px 0;
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-user-plus"></i> Tambah Nasabah</h2>
            <p>Layanan pembukaan rekening untuk siswa baru</p>
        </div>

        <!-- Input Form -->
        <?php if (!$showConfirmation && !$show_success_popup && !$show_error_popup): ?>
            <div class="deposit-card">
                <form action="" method="POST" id="nasabahForm" class="deposit-form">
                    <div class="form-group">
                        <label for="nama">Nama Lengkap</label>
                        <input type="text" id="nama" name="nama" required value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>" placeholder="Masukkan nama lengkap">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" placeholder="Masukkan email">
                    </div>
                    <div class="form-group">
                        <label for="no_wa">Nomor WhatsApp (Opsional)</label>
                        <input type="tel" id="no_wa" name="no_wa" value="<?php echo isset($_POST['no_wa']) ? htmlspecialchars($_POST['no_wa']) : ''; ?>" placeholder="Masukkan nomor WhatsApp">
                    </div>
                    <div class="form-group">
                        <label for="jurusan_id">Jurusan</label>
                        <div class="select-wrapper">
                            <select id="jurusan_id" name="jurusan_id" required onchange="getKelasByJurusan(this.value)">
                                <option value="">Pilih Jurusan</option>
                                <?php 
                                if($result_jurusan->num_rows > 0) {
                                    $result_jurusan->data_seek(0);
                                }
                                while ($row = $result_jurusan->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="kelas_id">Kelas</label>
                        <div class="select-wrapper">
                            <select id="kelas_id" name="kelas_id" required>
                                <option value="">Pilih Kelas</option>
                            </select>
                        </div>
                        <div id="kelas-loading" class="loading" style="display: none;">
                            <div class="loading-spinner"><i class="fas fa-spinner"></i></div>
                        </div>
                    </div>
                    <button type="submit" class="btn" id="submit-btn">
                        <i class="fas fa-user-plus"></i> Lanjutkan
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Confirmation Modal -->
        <?php if ($showConfirmation && !$show_success_popup && !$show_error_popup): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Konfirmasi Data Nasabah</h3>
                    <div class="modal-content">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['nama']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value"><?php echo maskString($formData['username']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Password</span>
                            <span class="modal-value"><?php echo maskString($formData['password']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Email</span>
                            <span class="modal-value"><?php echo htmlspecialchars($formData['email']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No WhatsApp</span>
                            <span class="modal-value"><?php echo $formData['no_wa'] ? htmlspecialchars($formData['no_wa']) : '-'; ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($jurusan_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($kelas_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo maskString($formData['no_rekening']); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars($formData['nama']); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($formData['username']); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($formData['jurusan_id']); ?>">
                        <input type="hidden" name="kelas_id" value="<?php echo htmlspecialchars($formData['kelas_id']); ?>">
                        <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($formData['no_rekening']); ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($formData['email']); ?>">
                        <input type="hidden" name="no_wa" value="<?php echo htmlspecialchars($formData['no_wa'] ?? ''); ?>">
                        <input type="hidden" name="confirmed" value="1">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="submit" name="cancel" class="btn btn-cancel">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if ($show_success_popup): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Pembukaan Rekening Berhasil</h3>
                    <div class="modal-content">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($nama); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value"><?php echo maskString($username); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Email</span>
                            <span class="modal-value"><?php echo htmlspecialchars($email); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No WhatsApp</span>
                            <span class="modal-value"><?php echo $no_wa ? htmlspecialchars($no_wa) : '-'; ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars($jurusan_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars($kelas_name); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo maskString($no_rekening); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if ($show_error_popup): ?>
            <div class="success-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Kesalahan</h3>
                    <p><?php echo htmlspecialchars($error); ?></p>
                    <div class="modal-buttons">
                        <button class="btn btn-cancel" onclick="window.location.href='tambah_nasabah.php'">
                            <i class="fas fa-times"></i> Tutup
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            // Auto-close success modal after 3 seconds
            const successModal = document.querySelector('#successModal');
            if (successModal) {
                setTimeout(() => {
                    const overlay = document.querySelector('#successModal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 3000);
            }

            // Form submission handling
            const nasabahForm = document.getElementById('nasabahForm');
            const submitBtn = document.getElementById('submit-btn');
            
            if (nasabahForm && submitBtn) {
                nasabahForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorPopup('Nama harus minimal 3 karakter!');
                        document.getElementById('nama').focus();
                        return;
                    }

                    const email = document.getElementById('email').value.trim();
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailPattern.test(email)) {
                        e.preventDefault();
                        showErrorPopup('Format email tidak valid!');
                        document.getElementById('email').focus();
                        return;
                    }

                    const no_wa = document.getElementById('no_wa').value.trim();
                    if (no_wa && !/^\+?\d{10,15}$/.test(no_wa)) {
                        e.preventDefault();
                        showErrorPopup('Nomor WhatsApp tidak valid!');
                        document.getElementById('no_wa').focus();
                        return;
                    }

                    submitBtn.disabled = true;
                    submitBtn.classList.add('loading');
                    submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    if (e.submitter.name === 'confirm') {
                        confirmBtn.disabled = true;
                        confirmBtn.classList.add('loading');
                        confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    }
                });
            }

            // Function to show error popup
            function showErrorPopup(message) {
                const existingModal = document.querySelector('#errorModal');
                if (existingModal) existingModal.remove();

                const overlay = document.createElement('div');
                overlay.className = 'success-overlay';
                overlay.id = 'errorModal';
                overlay.innerHTML = `
                    <div class="error-modal">
                        <div class="error-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h3>Kesalahan</h3>
                        <p>${message}</p>
                        <div class="modal-buttons">
                            <button class="btn btn-cancel" onclick="this.closest('.success-overlay').remove()">
                                <i class="fas fa-times"></i> Tutup
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(overlay);
                setTimeout(() => {
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }

            // AJAX for kelas
            function getKelasByJurusan(jurusan_id) {
                if (jurusan_id) {
                    const kelasLoading = document.getElementById('kelas-loading');
                    const kelasSelect = document.getElementById('kelas_id');
                    kelasLoading.style.display = 'flex';
                    kelasSelect.style.display = 'none';
                    
                    $.ajax({
                        url: 'get_kelas_by_jurusan.php',
                        type: 'GET',
                        data: { jurusan_id: jurusan_id },
                        success: function(response) {
                            kelasSelect.innerHTML = response;
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                        },
                        error: function(xhr, status, error) {
                            console.error('Error:', error);
                            showErrorPopup('Terjadi kesalahan saat mengambil data kelas!');
                            kelasLoading.style.display = 'none';
                            kelasSelect.style.display = 'block';
                        }
                    });
                } else {
                    document.getElementById('kelas_id').innerHTML = '<option value="">Pilih Kelas</option>';
                }
            }

            // Initialize kelas if jurusan is selected
            <?php if (isset($_POST['jurusan_id']) && !$showConfirmation && !$show_error_popup): ?>
                getKelasByJurusan(<?php echo $_POST['jurusan_id']; ?>);
            <?php endif; ?>

            // Attach event listener for jurusan
            const jurusanSelect = document.getElementById('jurusan_id');
            if (jurusanSelect) {
                jurusanSelect.addEventListener('change', function() {
                    getKelasByJurusan(this.value);
                });
            }
        });
    </script>
</body>
</html>