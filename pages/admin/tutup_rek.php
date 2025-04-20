<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check for Composer autoloader (for PHPMailer)
$autoload_path = '../../vendor/autoload.php';
$phpmailer_available = false;
if (file_exists($autoload_path)) {
    require_once $autoload_path;
    $phpmailer_available = true;
} else {
    error_log("Composer autoloader not found at $autoload_path. Email sending will be disabled.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Session check
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = false;
$nasabah = null;
$email_status = '';

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success = true;
    $email_status = $_SESSION['email_status'] ?? '';
    unset($_SESSION['success_message'], $_SESSION['email_status']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
if (isset($_SESSION['nasabah'])) {
    $nasabah = $_SESSION['nasabah'];
    unset($_SESSION['nasabah']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $no_rekening = $_POST['no_rekening'] ?? '';
    
    // Validate account number (must be REK + 6 digits)
    if (empty($no_rekening) || $no_rekening === 'REK' || !preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
        $_SESSION['error_message'] = 'Nomor rekening harus terdiri dari prefix REK diikuti 6 digit angka.';
        header('Location: tutup_rek.php');
        exit();
    }

    // Fetch account details
    $query = "SELECT r.id AS rekening_id, r.saldo, r.user_id, u.nama, u.username, u.email, u.jurusan_id, u.kelas_id, 
                     j.nama_jurusan, k.nama_kelas, r.no_rekening 
              FROM rekening r 
              JOIN users u ON r.user_id = u.id 
              LEFT JOIN jurusan j ON u.jurusan_id = j.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              WHERE r.no_rekening = ?";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        $_SESSION['error_message'] = 'Gagal mempersiapkan query pencarian rekening: ' . $conn->error;
        error_log("Prepare failed for account lookup: " . $conn->error);
        header('Location: tutup_rek.php');
        exit();
    }

    $stmt->bind_param("s", $no_rekening);
    if (!$stmt->execute()) {
        $_SESSION['error_message'] = 'Gagal menjalankan query pencarian rekening: ' . $stmt->error;
        error_log("Execute failed for account lookup: " . $stmt->error);
        header('Location: tutup_rek.php');
        exit();
    }

    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $nasabah = $result->fetch_assoc();
        $_SESSION['nasabah'] = $nasabah; // Store in session for display after redirect

        // Handle account and user deletion
        if (isset($_POST['confirm'])) {
            $rekening_id = $nasabah['rekening_id'];
            $user_id = $nasabah['user_id'];
            $admin_id = $_SESSION['user_id'];
            $saldo = $nasabah['saldo'];
            $email = $nasabah['email'];

            // Validate input data
            if (!is_numeric($rekening_id) || !is_numeric($user_id) || !is_numeric($admin_id)) {
                $_SESSION['error_message'] = 'Data tidak valid: ID rekening, pengguna, atau admin tidak valid.';
                error_log("Invalid input data: rekening_id=$rekening_id, user_id=$user_id, admin_id=$admin_id");
                header('Location: tutup_rek.php');
                exit();
            } elseif ($saldo > 0) {
                $_SESSION['error_message'] = 'Penghapusan rekening tidak dapat dilakukan karena masih terdapat sisa saldo Rp ' . number_format($saldo, 0, ',', '.') . '. Harap kosongkan saldo terlebih dahulu.';
                header('Location: tutup_rek.php');
                exit();
            }

            $conn->begin_transaction();
            try {
                // // Log deletion to file
                // $log_message = date('Y-m-d H:i:s') . " - Account deletion: no_rekening=$no_rekening, user_id=$user_id, admin_id=$admin_id, reason=" . ($_POST['reason'] ?? 'No reason provided') . "\n";
                // file_put_contents('deletion_log.txt', $log_message, FILE_APPEND);

                // 1. Delete related mutasi
                $query = "DELETE m FROM mutasi m 
                         JOIN transaksi t ON m.transaksi_id = t.id 
                         WHERE t.rekening_id = ? OR t.rekening_tujuan_id = ?";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed for mutasi deletion: " . $conn->error);
                }
                $stmt->bind_param("ii", $rekening_id, $rekening_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed for mutasi deletion: " . $stmt->error);
                }
                $stmt->close();

                // 2. Delete related transaksi
                $query = "DELETE FROM transaksi WHERE rekening_id = ? OR rekening_tujuan_id = ?";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed for transaksi deletion: " . $conn->error);
                }
                $stmt->bind_param("ii", $rekening_id, $rekening_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed for transaksi deletion: " . $stmt->error);
                }
                $stmt->close();

                // 3. Delete rekening
                $query = "DELETE FROM rekening WHERE id = ?";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed for rekening deletion: " . $conn->error);
                }
                $stmt->bind_param("i", $rekening_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed for rekening deletion: " . $stmt->error);
                }
                $stmt->close();

                // 4. Delete related user data
                $tables = [
                    'active_sessions' => ['column' => 'user_id', 'id' => $user_id],
                    'password_reset' => ['column' => 'user_id', 'id' => $user_id],
                    'absensi' => ['column' => 'user_id', 'id' => $user_id],
                    'notifications' => ['column' => 'user_id', 'id' => $user_id],
                    'log_aktivitas' => ['column' => 'siswa_id', 'id' => $user_id],
                    'saldo_transfers' => ['column' => 'petugas_id', 'id' => $user_id],
                ];

                foreach ($tables as $table => $info) {
                    // Verify table exists
                    $result = $conn->query("SHOW TABLES LIKE '$table'");
                    if ($result->num_rows === 0) {
                        error_log("Table $table does not exist. Skipping deletion.");
                        continue;
                    }

                    $query = "DELETE FROM $table WHERE {$info['column']} = ?";
                    $stmt = $conn->prepare($query);
                    if (!$stmt) {
                        throw new Exception("Prepare failed for $table deletion: " . $conn->error);
                    }
                    $stmt->bind_param("i", $info['id']);
                    if (!$stmt->execute()) {
                        throw new Exception("Execute failed for $table deletion: " . $stmt->error);
                    }
                    $stmt->close();
                }

                // 5. Delete user
                $query = "DELETE FROM users WHERE id = ?";
                $stmt = $conn->prepare($query);
                if (!$stmt) {
                    throw new Exception("Prepare failed for users deletion: " . $conn->error);
                }
                $stmt->bind_param("i", $user_id);
                if (!$stmt->execute()) {
                    throw new Exception("Execute failed for users deletion: " . $stmt->error);
                }
                $stmt->close();

                // 6. Send email notification
                if ($phpmailer_available && !empty($email)) {
                    if (sendEmailNotification($email, $nasabah['nama'], $no_rekening, $saldo)) {
                        $email_status = 'success';
                    } else {
                        $email_status = 'failed';
                        error_log("Email sending failed for $email");
                    }
                } else {
                    $email_status = $phpmailer_available ? 'no_email' : 'phpmailer_unavailable';
                    if (!$phpmailer_available) {
                        error_log("PHPMailer not available for sending email.");
                    }
                }

                $conn->commit();
                $_SESSION['success_message'] = true;
                $_SESSION['email_status'] = $email_status;
                unset($_SESSION['nasabah']); // Clear nasabah data
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_message'] = 'Gagal menutup rekening dan menghapus pengguna: ' . $e->getMessage();
                error_log("Account and user deletion error: " . $e->getMessage());
            }
            header('Location: tutup_rek.php');
            exit();
        } else {
            // Redirect after successful search
            header('Location: tutup_rek.php');
            exit();
        }
    } else {
        $_SESSION['error_message'] = 'Nomor rekening tidak ditemukan.';
        header('Location: tutup_rek.php');
        exit();
    }
    $stmt->close();
}

function sendEmailNotification($email, $nama, $no_rekening, $saldo) {
    global $phpmailer_available;
    if (!$phpmailer_available) {
        error_log("PHPMailer not available. Skipping email sending.");
        return false;
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'mocharid.ip@gmail.com';
        $mail->Password = 'spjs plkg ktuu lcxh'; // Ensure this is a valid App Password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        
        $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@schobank.com', 'No Reply');
        
        $mail->isHTML(true);
        $mail->Subject = 'Penutupan Rekening - SCHOBANK SYSTEM';
        
        $saldo_text = $saldo > 0 ? "Sisa saldo Rp " . number_format($saldo, 0, ',', '.') . " akan dibayarkan sesuai prosedur." : "Tidak ada sisa saldo.";
        
        $emailBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <div style='text-align: center; padding: 15px; background-color: #f5f5f5; border-radius: 8px 8px 0 0;'>
                <h2 style='color: #0a2e5c; margin: 0; font-size: 24px;'>SCHOBANK SYSTEM</h2>
            </div>
            <div style='padding: 20px;'>
                <p style='font-size: 16px; color: #333; margin-bottom: 20px;'>Yth. Bapak/Ibu <strong>{$nama}</strong>,</p>
                <p style='font-size: 14px; color: #333; line-height: 1.6; margin-bottom: 20px;'>
                    Kami menginformasikan bahwa rekening Anda dengan nomor <strong>{$no_rekening}</strong> telah ditutup dan akun Anda telah dihapus dari sistem SCHOBANK SYSTEM. Tindakan ini bersifat permanen dan tidak dapat dikembalikan.
                </p>
                <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                    <tr>
                        <td style='padding: 8px; font-size: 14px; color: #333; width: 40%;'>Nomor Rekening</td>
                        <td style='padding: 8px; font-size: 14px; color: #333;'>: {$no_rekening}</td>
                    </tr>
                    <tr>
                        <td style='padding: 8px; font-size: 14px; color: #333;'>Status Saldo</td>
                        <td style='padding: 8px; font-size: 14px; color: #333;'>: {$saldo_text}</td>
                    </tr>
                </table>
                <p style='font-size: 14px; color: #333; line-height: 1.6; margin-bottom: 20px;'>
                    Jika Anda memiliki pertanyaan atau memerlukan informasi lebih lanjut, silakan hubungi tim kami melalui kanal resmi SCHOBANK SYSTEM.
                </p>
                <p style='font-size: 14px; color: #333; margin-bottom: 20px;'>Hormat kami,<br><strong>Tim SCHOBANK SYSTEM</strong></p>
            </div>
            <div style='text-align: center; padding: 15px; background-color: #f5f5f5; border-radius: 0 0 8px 8px; font-size: 12px; color: #777;'>
                <p style='margin: 0;'>Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                <p style='margin: 0;'>© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
            </div>
        </div>";
        
        $mail->Body = $emailBody;
        $mail->AltBody = strip_tags("Yth. Bapak/Ibu {$nama},\n\nKami menginformasikan bahwa rekening Anda dengan nomor {$no_rekening} telah ditutup dan akun Anda telah dihapus dari sistem SCHOBANK SYSTEM. Tindakan ini bersifat permanen dan tidak dapat dikembalikan.\n\nNomor Rekening: {$no_rekening}\nStatus Saldo: {$saldo_text}\n\nJika Anda memiliki pertanyaan atau memerlukan informasi lebih lanjut, silakan hubungi tim kami melalui kanal resmi SCHOBANK SYSTEM.\n\nHormat kami,\nTim SCHOBANK SYSTEM\n\n© " . date('Y') . " SCHOBANK SYSTEM. Hak cipta dilindungi.");
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed for $email: " . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tutup Rekening dan Hapus Pengguna - SCHOBANK SYSTEM</title>
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
            -webkit-text-size-adjust: none;
            zoom: 1;
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
            transition: var(--transition);
        }

        .deposit-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .deposit-form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="tel"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
        }

        input[type="tel"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        button {
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
            width: fit-content;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        .btn-danger {
            background-color: var(--danger-color);
        }

        .btn-danger:hover {
            background-color: #d32f2f;
        }

        .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
            animation: slideStep 0.5s ease-in-out;
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
            gap: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: left;
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
            animation: fadeInModal 0.3s ease-in;
        }

        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: var(--shadow-md);
            width: 90%;
            max-width: 500px;
            position: relative;
            text-align: center;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-header.error {
            background: linear-gradient(135deg, #b91c1c 0%, var(--danger-color) 100%);
        }

        .modal-header.warning {
            background: linear-gradient(135deg, #e65100 0%, var(--accent-color) 100%);
        }

        .modal-header h3 {
            margin: 0;
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            padding: 25px;
            margin-bottom: 20px;
        }

        .modal-body i {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .modal-body i.success {
            color: #34d399;
        }

        .modal-body i.error {
            color: var(--danger-color);
        }

        .modal-body i.warning {
            color: var(--accent-color);
        }

        .modal-body h3 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .modal-body p {
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-secondary);
        }

        .modal-footer {
            padding: 0 25px 25px;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading > * {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "....";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            animation: pulseDots 2s infinite;
        }

        @keyframes pulseDots {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            resize: vertical;
            -webkit-text-size-adjust: none;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .deposit-card,
            .results-card {
                padding: 20px;
                margin-bottom: 20px;
            }

            .deposit-form {
                gap: 15px;
                justify-items: center;
            }

            .deposit-form button#searchBtn {
                margin: 0 auto;
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            input[type="tel"],
            textarea {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            button {
                padding: 10px 20px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                width: fit-content;
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .modal-content {
                width: 95%;
                padding: 0;
            }

            .modal-header {
                padding: 15px;
            }

            .modal-header h3 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }

            .modal-body {
                padding: 20px;
            }

            .modal-body h3 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .modal-body p {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .modal-footer {
                padding: 0 20px 20px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner {
                padding: 15px;
                margin-bottom: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 2vw, 0.85rem);
            }

            .deposit-card,
            .results-card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .deposit-form {
                gap: 12px;
            }

            .section-title {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            input[type="tel"],
            textarea {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            button {
                padding: 8px 15px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .detail-row {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .modal-header {
                padding: 12px;
            }

            .modal-header h3 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .modal-body {
                padding: 15px;
            }

            .modal-body h3 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .modal-body p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .modal-footer {
                padding: 0 15px 15px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Tutup Rekening dan Hapus Pengguna</h2>
            <p>Nonaktifkan rekening dan hapus pengguna beserta semua data terkait dari sistem</p>
        </div>

        <?php if (!$phpmailer_available): ?>
            <div id="alertContainer">
                <div class="modal show">
                    <div class="modal-content">
                        <div class="modal-header error">
                            <h3><i class="fas fa-exclamation-circle"></i> PHPMailer Tidak Tersedia</h3>
                        </div>
                        <div class="modal-body">
                            <i class="fas fa-exclamation-circle error"></i>
                            <h3>PHPMailer Tidak Tersedia</h3>
                            <p>Email konfirmasi penutupan rekening tidak akan dikirim. Silakan instal Composer dan PHPMailer.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="modal-close-btn">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Cari Rekening</h3>
            <form id="searchForm" action="" method="POST" class="deposit-form">
                <div>
                    <label for="no_rekening">Nomor Rekening:</label>
                    <input type="tel" id="no_rekening" name="no_rekening" inputmode="numeric" placeholder="REK123456" required autofocus value="REK">
                </div>
                <button type="submit" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cek Rekening</span>
                </button>
            </form>
        </div>

        <?php if ($nasabah !== null): ?>
            <div class="results-card">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Pengguna</h3>
                <div class="detail-row">
                    <div class="detail-label">Nama:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['no_rekening'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Email:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['email'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama_jurusan'] ?? '-'); ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value"><?php echo htmlspecialchars($nasabah['nama_kelas'] ?? '-'); ?></div>
                </div>
                <br>

                <form id="closeAccountForm" action="" method="POST" class="deposit-form">
                    <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($nasabah['no_rekening']); ?>">
                    <input type="hidden" name="confirm" value="1">
                    <div>
                        <label for="reason">Alasan Penghapusan:</label>
                        <textarea id="reason" name="reason" rows="4" placeholder="Masukkan alasan penghapusan pengguna dan rekening" required></textarea>
                    </div>
                    <button type="button" class="btn-danger" id="confirmBtn" onclick="confirmDeletion(<?php echo $nasabah['saldo']; ?>)">
                        <i class="fas fa-trash-alt"></i>
                        <span>Konfirmasi Penghapusan</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <div id="successModal" class="modal<?php if ($success) echo ' show'; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-check-circle"></i> Penghapusan Berhasil</h3>
                </div>
                <div class="modal-body">
                    <i class="fas fa-check-circle success"></i>
                    <h3>Pengguna dan Rekening Berhasil Dihapus</h3>
                    <p>Pengguna, rekening, dan semua data terkait telah dihapus dari sistem.
                    <?php if ($email_status === 'success'): ?>
                        <br>Email konfirmasi telah dikirim.
                    <?php elseif ($email_status === 'failed'): ?>
                        <br>Gagal mengirim email konfirmasi. Silakan hubungi pengguna secara manual.
                    <?php elseif ($email_status === 'no_email'): ?>
                        <br>Tidak ada email pengguna untuk dikirim.
                    <?php elseif ($email_status === 'phpmailer_unavailable'): ?>
                        <br>PHPMailer tidak tersedia, email tidak dikirim.
                    <?php endif; ?>
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-close-btn">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Modal -->
        <div id="errorModal" class="modal<?php if (!empty($error)) echo ' show'; ?>">
            <div class="modal-content">
                <div class="modal-header error">
                    <h3><i class="fas fa-exclamation-circle"></i> Kesalahan</h3>
                </div>
                <div class="modal-body">
                    <i class="fas fa-exclamation-circle error"></i>
                    <h3>Kesalahan</h3>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="modal-close-btn">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchForm = document.getElementById('searchForm');
            const closeAccountForm = document.getElementById('closeAccountForm');
            const searchBtn = document.getElementById('searchBtn');
            const confirmBtn = document.getElementById('confirmBtn');
            const inputNoRek = document.getElementById('no_rekening');
            const successModal = document.getElementById('successModal');
            const errorModal = document.getElementById('errorModal');
            const modalCloseBtns = document.querySelectorAll('.modal-close-btn');
            const prefix = "REK";

            // Initialize account number input with prefix
            if (!inputNoRek.value) {
                inputNoRek.value = prefix;
            }

            // Restrict account number to exactly 6 digits after REK
            inputNoRek.addEventListener('input', function(e) {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    this.value = prefix;
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + userInput;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                let userInput = this.value.slice(prefix.length);
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
                if (userInput.length >= 6 && e.key.match(/[0-9]/) && cursorPos > prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                this.value = prefix + pastedData;
            });

            inputNoRek.addEventListener('focus', function() {
                if (this.value === prefix) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoRek.addEventListener('click', function(e) {
                if (this.selectionStart < prefix.length) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            // Form submission handling for search
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    const rekening = inputNoRek.value.trim();
                    if (rekening === prefix || rekening.length !== prefix.length + 6) {
                        e.preventDefault();
                        showModal('errorModal', 'Nomor rekening harus terdiri dari REK diikuti 6 digit angka', 'Kesalahan');
                        inputNoRek.classList.add('form-error');
                        setTimeout(() => inputNoRek.classList.remove('form-error'), 400);
                        inputNoRek.focus();
                        return;
                    }
                    // Apply loading animation
                    searchBtn.classList.add('btn-loading');
                    // Simulate processing with a 1.5-second delay
                    setTimeout(() => {
                        searchBtn.classList.remove('btn-loading');
                    }, 1500);
                });
            }

            // Modal handling
            function showModal(modalId, message, title) {
                const modal = document.getElementById(modalId);
                if (!modal) return;

                const modalBody = modal.querySelector('.modal-body');
                const modalTitle = modal.querySelector('.modal-header h3');
                const modalMessage = modalBody.querySelector('p');
                modalTitle.innerHTML = `<i class="fas fa-${modalId === 'errorModal' ? 'exclamation-circle' : 'check-circle'}"></i> ${title}`;
                modalMessage.textContent = message;

                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            // Close modals on button click or outside click
            modalCloseBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const modal = btn.closest('.modal');
                    modal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                    if (modal.id === 'successModal') {
                        window.location.href = 'tutup_rek.php';
                    }
                });
            });

            [successModal, errorModal].forEach(modal => {
                if (modal) {
                    modal.addEventListener('click', function(event) {
                        if (event.target === modal) {
                            modal.classList.remove('show');
                            document.body.style.overflow = 'auto';
                            if (modal.id === 'successModal') {
                                window.location.href = 'tutup_rek.php';
                            }
                        }
                    });
                }
            });

            // Handle existing modals on page load
            if (successModal && successModal.classList.contains('show')) {
                document.body.style.overflow = 'hidden';
                setTimeout(() => {
                    successModal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                    window.location.href = 'tutup_rek.php';
                }, 5000);
            }

            if (errorModal && errorModal.classList.contains('show')) {
                document.body.style.overflow = 'hidden';
                setTimeout(() => {
                    errorModal.classList.remove('show');
                    document.body.style.overflow = 'auto';
                }, 5000);
            }

            // Focus input and handle Enter key
            inputNoRek.focus();
            if (inputNoRek.value === prefix) {
                inputNoRek.setSelectionRange(prefix.length, prefix.length);
            }

            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && searchForm) searchBtn.click();
            });

            // Deletion confirmation handling
            window.confirmDeletion = function(saldo) {
                console.log('confirmDeletion called with saldo:', saldo);
                const reason = document.getElementById('reason').value.trim();
                if (!reason) {
                    showModal('errorModal', 'Alasan penghapusan harus diisi.', 'Kesalahan');
                    return;
                }

                if (saldo > 0) {
                    showModal('errorModal', `Penghapusan gagal karena masih terdapat sisa saldo. Harap kosongkan saldo terlebih dahulu.`, 'Kesalahan');
                    return;
                }

                // Apply loading animation to confirmBtn
                confirmBtn.classList.add('btn-loading');
                setTimeout(() => {
                    confirmBtn.classList.remove('btn-loading');
                    // Show confirmation modal
                    const confirmModal = document.createElement('div');
                    confirmModal.className = 'modal show';
                    confirmModal.id = 'confirmModal';
                    confirmModal.innerHTML = `
                        <div class="modal-content">
                            <div class="modal-header warning">
                                <h3><i class="fas fa-exclamation-triangle"></i> Konfirmasi Penghapusan</h3>
                            </div>
                            <div class="modal-body">
                                <i class="fas fa-exclamation-triangle warning"></i>
                                <h3>Konfirmasi Penghapusan</h3>
                                <p>PERINGATAN: Anda akan menghapus pengguna, rekening, dan semua data terkait secara permanen. Apakah Anda yakin ingin melanjutkan?</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="modal-close-btn" onclick="closeConfirmModal()">
                                    <i class="fas fa-xmark"></i> Batal
                                </button>
                                <button type="button" class="btn-danger" id="submitDeletionBtn">
                                    <i class="fas fa-trash-alt"></i>
                                    <span>Ya, Hapus</span>
                                </button>
                            </div>
                        </div>
                    `;
                    document.body.appendChild(confirmModal);
                    document.body.style.overflow = 'hidden';

                    // Add event listener for the submit button
                    document.getElementById('submitDeletionBtn').addEventListener('click', function() {
                        console.log('Ya, Hapus clicked');
                        this.classList.add('btn-loading'); // Apply loading animation
                        submitDeletion();
                    });
                }, 1500); // 1.5-second loading animation
            };

            // Close confirmation modal
            window.closeConfirmModal = function() {
                const confirmModal = document.getElementById('confirmModal');
                if (confirmModal) {
                    confirmModal.remove();
                    document.body.style.overflow = 'auto';
                }
            };

            // Submit deletion form
            window.submitDeletion = function() {
                console.log('submitDeletion called');
                if (closeAccountForm) {
                    closeAccountForm.submit(); // Form submission triggers server-side processing
                } else {
                    console.error('closeAccountForm not found');
                    showModal('errorModal', 'Formulir tidak ditemukan. Silakan coba lagi.', 'Kesalahan');
                }
            };
        });
    </script>
</body>
</html>