<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../../index.php");
    exit();
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['confirm'])) {
    $siswa_id = intval($_POST['siswa_id']);
    $jenis_pemulihan = $_POST['jenis_pemulihan'];
    
    // Get student details with proper rekening information
    $query = "SELECT u.*, j.nama_jurusan, k.nama_kelas, r.no_rekening as no_rekening_siswa
              FROM users u
              LEFT JOIN jurusan j ON u.jurusan_id = j.id
              LEFT JOIN kelas k ON u.kelas_id = k.id
              LEFT JOIN rekening r ON u.id = r.user_id
              WHERE u.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $siswa = $result->fetch_assoc();
    
    if (!$siswa || empty($siswa['no_rekening_siswa'])) {
        $_SESSION['no_rekening_popup'] = true;
        header("Location: pemulihan_akun.php");
        exit();
    }
    
    if (empty($siswa['email'])) {
        $_SESSION['no_email_popup'] = true;
        header("Location: pemulihan_akun.php");
        exit();
    }
    
    // Generate unique token
    $token = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour', time()));
    
    // Insert into password_reset table
    $query = "INSERT INTO password_reset (user_id, token, expiry, recovery_type) VALUES (?, ?, ?, ?) 
              ON DUPLICATE KEY UPDATE token = VALUES(token), expiry = VALUES(expiry), recovery_type = VALUES(recovery_type)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isss", $siswa_id, $token, $expiry, $jenis_pemulihan);
    
    if ($stmt->execute()) {
        // Send email with reset link
        $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/bankmini/pages/siswa/reset_akun.php?token=$token";
        
        // Email template
        $email_template = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background-color: #0a2e5c; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background-color: #f9f9f9; }
                    .button { 
                        display: inline-block; 
                        background-color: #0a2e5c; 
                        color: white; 
                        padding: 10px 20px; 
                        text-decoration: none; 
                        border-radius: 5px; 
                        margin: 20px 0;
                    }
                    .footer { 
                        margin-top: 20px; 
                        padding: 10px; 
                        text-align: center; 
                        font-size: 12px; 
                        color: #777;
                    }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>SCHOBANK SYSTEM</h2>
                    </div>
                    <div class='content'>
                        <p>Halo ".htmlspecialchars($siswa['nama']).",</p>
                        <p>Anda menerima email ini karena ada permintaan untuk pemulihan ".getPemulihanType($jenis_pemulihan)." akun Anda.</p>
                        <p>Silakan klik tombol di bawah ini untuk melanjutkan proses pemulihan:</p>
                        <a href='$reset_link' class='button'>Pulihkan ".getPemulihanType($jenis_pemulihan)."</a>
                        <p>Link ini akan kadaluarsa dalam 1 jam. Jika Anda tidak meminta pemulihan ini, silakan abaikan email ini.</p>
                    </div>
                    <div class='footer'>
                        <p>© ".date('Y')." SCHOBANK SYSTEM. Semua hak dilindungi.</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        // Send email using PHPMailer
        require '../../vendor/autoload.php';
        
        $mail = new PHPMailer(true);
        
        try {
            // SMTP configuration
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'mocharid.ip@gmail.com';
            $mail->Password = 'spjs plkg ktuu lcxh';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';

            $mail->setFrom('mocharid.ip@gmail.com', 'SCHOBANK SYSTEM');
            $mail->addAddress($siswa['email'], $siswa['nama']);
            $mail->addBCC('admin@schoolbank.com');
            
            $mail->isHTML(true);
            $mail->Subject = "Pemulihan " . getPemulihanType($jenis_pemulihan) . " - SCHOBANK SYSTEM";
            $mail->Body = $email_template;
            $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $email_template));
            
            if ($mail->send()) {
                // Log activity
                $nilai_baru = "Reset link sent: " . $token;
                $alasan = "Permintaan pemulihan oleh admin";
                
                $log_query = "INSERT INTO log_aktivitas (petugas_id, siswa_id, jenis_pemulihan, nilai_baru, waktu, alasan) 
                             VALUES (?, ?, ?, ?, NOW(), ?)";
                $log_stmt = $conn->prepare($log_query);
                $log_stmt->bind_param("iisss", $_SESSION['user_id'], $siswa_id, $jenis_pemulihan, $nilai_baru, $alasan);
                $log_stmt->execute();
                
                $_SESSION['success'] = "Link pemulihan telah dikirim ke email siswa.";
                $_SESSION['show_success_popup'] = true;
            } else {
                $_SESSION['error'] = "Gagal mengirim email pemulihan.";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Error saat mengirim email: " . $mail->ErrorInfo;
        }
    } else {
        $_SESSION['error'] = "Gagal membuat token pemulihan.";
    }
    
    header("Location: pemulihan_akun.php");
    exit();
}

function getPemulihanType($type) {
    switch ($type) {
        case 'password': return 'Password';
        case 'pin': return 'PIN';
        case 'username': return 'Username';
        case 'email': return 'Email';
        default: return 'Akun';
    }
}

// Pagination for recovery logs
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Count total records
$count_query = "SELECT COUNT(*) as total FROM log_aktivitas";
$count_result = $conn->query($count_query);
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $per_page);

// Fetch recovery logs with pagination and proper rekening information
$log_query = "SELECT l.*, s.nama as siswa_nama, r.no_rekening
              FROM log_aktivitas l
              JOIN users s ON l.siswa_id = s.id
              LEFT JOIN rekening r ON s.id = r.user_id
              ORDER BY l.waktu DESC
              LIMIT $offset, $per_page";
$log_result = $conn->query($log_query);

// Fetch students with proper rekening information
$query = "SELECT u.id, u.nama, r.no_rekening, j.nama_jurusan, k.nama_kelas 
          FROM users u
          LEFT JOIN jurusan j ON u.jurusan_id = j.id
          LEFT JOIN kelas k ON u.kelas_id = k.id
          LEFT JOIN rekening r ON u.id = r.user_id
          WHERE u.role = 'siswa' 
          ORDER BY u.nama";
$result = $conn->query($query);
$students = $result->fetch_all(MYSQLI_ASSOC);

// Handle form submission for confirmation
$show_confirm_popup = false;
$selected_siswa = null;
$selected_jenis = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['confirm'])) {
    $siswa_id = intval($_POST['siswa_id']);
    $jenis_pemulihan = $_POST['jenis_pemulihan'];
    
    if (empty($siswa_id) || empty($jenis_pemulihan)) {
        $_SESSION['error'] = "Harap lengkapi semua kolom.";
    } else {
        $show_confirm_popup = true;
        $selected_siswa = array_filter($students, function($student) use ($siswa_id) {
            return $student['id'] == $siswa_id;
        });
        $selected_siswa = reset($selected_siswa);
        $selected_jenis = $jenis_pemulihan;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pemulihan Akun Siswa - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .form-card {
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

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: flex;
            gap: 20px;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        select, input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #fff;
        }

        select:focus, input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        button[type="submit"] {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            align-self: flex-start;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .jurusan-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .jurusan-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .jurusan-list h3 {
            background: var(--primary-dark);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .toggle-container {
            margin-bottom: 15px;
            display: flex;
            justify-content: flex-end;
        }

        .table-container {
            max-height: 1500px;
            opacity: 1;
            transform: translateY(0);
            transition: max-height 0.6s ease-in-out, opacity 0.6s ease-in-out, transform 0.6s ease-in-out;
            overflow: hidden;
        }

        .table-container.hidden {
            max-height: 0;
            opacity: 0;
            transform: translateY(-20px);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: #f8faff;
        }

        .badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
        }

        .badge-password {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-pin {
            background: #f3e8ff;
            color: #6b21a8;
        }

        .badge-username {
            background: #f0fdf4;
            color: #166534;
        }

        .badge-email {
            background: #ffedd5;
            color: #9a3412;
        }

        .no-data {
            color: var(--text-secondary);
            padding: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            text-align: center;
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(2rem, 4vw, 2.5rem);
            color: #d1d5db;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 5px;
        }

        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .pagination a {
            color: var(--primary-color);
            border: 1px solid #ddd;
            text-decoration: none;
        }

        .pagination a:hover {
            background-color: var(--primary-light);
        }

        .pagination .active {
            background-color: var(--primary-color);
            color: white;
            border: 1px solid var(--primary-color);
        }

        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
        }

        /* Select2 Custom Styles */
        .select2-container {
            width: 100% !important;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .select2-container--default .select2-selection--single {
            border: 1px solid #ddd;
            border-radius: 10px;
            height: 46px;
            padding: 0 15px;
            background-color: #fff;
            transition: var(--transition);
            display: flex;
            align-items: center;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        .select2-container--default .select2-selection--single:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: var(--text-primary);
            line-height: 44px;
            padding-right: 30px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: var(--text-secondary);
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 46px;
            width: 30px;
            position: absolute;
            top: 0;
            right: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            display: none; /* Hide default arrow */
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow::after {
            content: '';
            display: block;
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid var(--text-secondary);
            transition: var(--transition);
        }

        .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow::after {
            border-top: none;
            border-bottom: 6px solid var(--primary-color);
        }

        .select2-container--default .select2-dropdown {
            border: 1px solid #ddd;
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            background-color: #fff;
            margin-top: 5px;
            z-index: 1051;
        }

        .select2-container--default .select2-results__option {
            padding: 10px 15px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-primary);
            transition: var(--transition);
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .select2-container--default .select2-results__option[aria-selected="true"] {
            background-color: var(--primary-color);
            color: white;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            margin: 10px;
            width: calc(100% - 20px);
            -webkit-appearance: none;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(12, 77, 162, 0.1);
        }

        .select2-container--default .select2-selection__clear {
            color: var(--danger-color);
            font-size: 1.2rem;
            margin-right: 10px;
            cursor: pointer;
        }

        /* Ensure consistency in Safari */
        select::-webkit-outer-spin-button,
        select::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        select {
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
        }

        /* Modal Styles */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .warning-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .warning-icon {
            color: var(--danger-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            animation: slideUpText 0.5s ease-out 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
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

            .form-card, .jurusan-list {
                padding: 20px;
            }

            form {
                gap: 15px;
            }

            button[type="submit"] {
                width: 100%;
                justify-content: center;
            }

            .table-responsive {
                overflow-x: auto;
            }

            table {
                min-width: 600px;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }

            .toggle-container {
                justify-content: center;
            }

            .select2-container--default .select2-selection--single {
                height: 42px;
            }

            .select2-container--default .select2-selection--single .select2-selection__rendered {
                line-height: 40px;
            }

            .select2-container--default .select2-selection--single .select2-selection__arrow {
                height: 42px;
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

            .jurusan-list h3 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .no-data i {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
            }
        }
    </style>
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
        <div class="welcome-banner">
            <h2><i class="fas fa-user-shield"></i> Pemulihan Akun Siswa</h2>
        </div>

        <div id="alertContainer">
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-card">
            <form action="" method="POST" id="pemulihan-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="siswa_id">Pilih Siswa:</label>
                        <select id="siswa_id" name="siswa_id" class="select2" required>
                            <option value="">-- Pilih Siswa --</option>
                            <?php foreach ($students as $row): ?>
                                <option value="<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['nama']); ?> 
                                    (<?php echo htmlspecialchars($row['no_rekening'] ?? 'Belum memiliki rekening'); ?>)
                                    - <?php echo htmlspecialchars($row['nama_jurusan'] ?? 'N/A'); ?> 
                                    <?php echo htmlspecialchars($row['nama_kelas'] ?? 'N/A'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="jenis_pemulihan">Jenis Pemulihan:</label>
                        <select id="jenis_pemulihan" name="jenis_pemulihan" required>
                            <option value="">-- Pilih Jenis --</option>
                            <option value="password">Password</option>
                            <option value="pin">PIN</option>
                            <option value="username">Username</option>
                            <option value="email">Email</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" id="submitBtn" class="btn">
                    <i class="fas fa-paper-plane"></i>
                    Kirim Link Pemulihan
                </button>
            </form>
        </div>

        <div class="jurusan-list">
            <h3>Riwayat Pemulihan Akun</h3>
            <div class="toggle-container">
                <button id="toggleTableButton" class="btn" title="Sembunyikan Tabel">
                    <i class="fas fa-eye-slash"></i> Sembunyikan Tabel
                </button>
            </div>
            <div id="tableContainer" class="table-container">
                <?php if ($log_result && $log_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Waktu</th>
                                    <th>Siswa</th>
                                    <th>No Rekening</th>
                                    <th>Jenis</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $no = $offset + 1;
                                while ($row = $log_result->fetch_assoc()): 
                                    $badge_class = 'badge-' . $row['jenis_pemulihan'];
                                ?>
                                    <tr>
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo date('d M Y H:i', strtotime($row['waktu'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['siswa_nama']); ?></td>
                                        <td><?php echo htmlspecialchars($row['no_rekening'] ?? 'Belum memiliki rekening'); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($row['jenis_pemulihan']); ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>" title="Previous Page"><i class="fas fa-chevron-left"></i></a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                        <?php endif; ?>
                        
                        <span class="page-info"><?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>" title="Next Page"><i class="fas fa-chevron-right"></i></a>
                        <?php else: ?>
                            <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i>
                        Belum ada riwayat pemulihan
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <?php if ($show_confirm_popup): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <h3>Konfirmasi Pemulihan Akun</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Nama Siswa</span>
                            <span class="modal-value"><?php echo htmlspecialchars($selected_siswa['nama']); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">No Rekening</span>
                            <span class="modal-value"><?php echo htmlspecialchars($selected_siswa['no_rekening'] ?? 'Belum memiliki rekening'); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jenis Pemulihan</span>
                            <span class="modal-value"><?php echo ucfirst($selected_jenis); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="siswa_id" value="<?php echo $selected_siswa['id']; ?>">
                        <input type="hidden" name="jenis_pemulihan" value="<?php echo $selected_jenis; ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn-cancel" onclick="window.history.back();">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal -->
        <?php if (isset($_SESSION['show_success_popup']) && $_SESSION['show_success_popup']): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Link pemulihan telah dikirim ke email siswa!</p>
                </div>
            </div>
            <?php unset($_SESSION['show_success_popup']); ?>
        <?php endif; ?>

        <!-- No Rekening Modal -->
        <?php if (isset($_SESSION['no_rekening_popup']) && $_SESSION['no_rekening_popup']): ?>
            <div class="success-overlay" id="noRekeningModal">
                <div class="success-modal">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Peringatan</h3>
                    <p>Siswa belum memiliki rekening. Silakan tambahkan rekening terlebih dahulu.</p>
                </div>
            </div>
            <?php unset($_SESSION['no_rekening_popup']); ?>
        <?php endif; ?>

        <!-- No Email Modal -->
        <?php if (isset($_SESSION['no_email_popup']) && $_SESSION['no_email_popup']): ?>
            <div class="success-overlay" id="noEmailModal">
                <div class="success-modal">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Peringatan</h3>
                    <p>Siswa belum memiliki email yang valid. Silakan tambahkan email terlebih dahulu.</p>
                </div>
            </div>
            <?php unset($_SESSION['no_email_popup']); ?>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({
                placeholder: "Cari siswa...",
                allowClear: true,
                width: '100%'
            });

            // Auto-refresh the page every 60 seconds to update logs
            setTimeout(function() {
                window.location.reload();
            }, 60000);

            const pemulihanForm = document.getElementById('pemulihan-form');
            const submitBtn = document.getElementById('submitBtn');

            if (pemulihanForm && submitBtn) {
                pemulihanForm.addEventListener('submit', function(e) {
                    const siswaId = document.getElementById('siswa_id').value;
                    const jenisPemulihan = document.getElementById('jenis_pemulihan').value;

                    if (!siswaId || !jenisPemulihan) {
                        e.preventDefault();
                        showAlert('Harap lengkapi semua kolom!', 'error');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Kirim Link Pemulihan';
                    } else {
                        submitBtn.classList.add('loading');
                        submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                    }
                });
            }

            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function() {
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Mengirim...';
                });
            }

            // Toggle table visibility
            const toggleTableButton = document.getElementById('toggleTableButton');
            const tableContainer = document.getElementById('tableContainer');
            if (toggleTableButton && tableContainer) {
                toggleTableButton.addEventListener('click', function() {
                    tableContainer.classList.toggle('hidden');
                    const isHidden = tableContainer.classList.contains('hidden');
                    toggleTableButton.innerHTML = `<i class="fas fa-eye${isHidden ? '' : '-slash'}"></i> ${isHidden ? 'Tampilkan' : 'Sembunyikan'} Tabel`;
                    toggleTableButton.title = isHidden ? 'Tampilkan Tabel' : 'Sembunyikan Tabel';
                });
            }
        });

        // Show Alert Function
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const existingAlerts = document.querySelectorAll('.alert');
            existingAlerts.forEach(alert => {
                alert.classList.add('hide');
                setTimeout(() => alert.remove(), 500);
            });
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type == 'error') icon = 'exclamation-circle';
            alertDiv.innerHTML = `
                <i class="fas fa-${icon}"></i>
                <span>${message}</span>
            `;
            alertContainer.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.classList.add('hide');
                setTimeout(() => alertDiv.remove(), 500);
            }, 5000);
        }

        // Handle alert animations and confetti
        document.addEventListener('DOMContentLoaded', () => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            const confirmModal = document.querySelector('#confirmModal .success-modal');
            if (confirmModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    confirmModal.appendChild(confetti);
                }
            }

            const successModal = document.querySelector('#successModal .success-modal');
            if (successModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    successModal.appendChild(confetti);
                }
                setTimeout(() => {
                    const overlay = document.querySelector('#successModal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 2000);
            }

            const noRekeningModal = document.querySelector('#noRekeningModal .success-modal');
            if (noRekeningModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    noRekeningModal.appendChild(confetti);
                }
                setTimeout(() => {
                    const overlay = document.querySelector('#noRekeningModal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 2000);
            }

            const noEmailModal = document.querySelector('#noEmailModal .success-modal');
            if (noEmailModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    noEmailModal.appendChild(confetti);
                }
                setTimeout(() => {
                    const overlay = document.querySelector('#noEmailModal');
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => overlay.remove(), 500);
                }, 2000);
            }
        });

        // Prevent pinch zooming
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-tap zooming
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        // Prevent zoom on wheel with ctrl
        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-click zooming
        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });
    </script>
</body>
</html>