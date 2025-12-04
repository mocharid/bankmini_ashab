<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
date_default_timezone_set('Asia/Jakarta');
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
// Check if account is frozen or blocked due to PIN attempts
$query = "SELECT is_frozen, failed_pin_attempts, pin_block_until FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$stmt->close();
if ($user_data['is_frozen']) {
    $error = "Akun Anda sedang dinonaktifkan. Anda tidak dapat melakukan transfer. Silakan hubungi admin untuk bantuan!";
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Transfer - SCHOBANK</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --secondary: #00C2FF;
            --success: #00D084;
            --warning: #FFB020;
            --danger: #FF3B30;
            --dark: #1C1C1E;
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 80px;
        }
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--white);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            text-decoration: none;
        }
        .nav-btn:hover {
            background: var(--white);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
            transform: scale(1.05);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
        }
        .error-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 40px;
            margin: 20px auto;
            max-width: 500px;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        .error-icon {
            font-size: 64px;
            color: var(--danger);
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
        }
        .error-text {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 24px;
            line-height: 1.8;
        }
        .btn {
            padding: 12px 24px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }
        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover {
            background: var(--gray-300);
        }
        .btn-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            .error-card {
                padding: 24px;
                margin: 10px;
            }
            .error-icon {
                font-size: 48px;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="/schobank/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Transfer</h1>
            <p class="page-subtitle">Akun Dinonaktifkan</p>
        </div>
        <div class="error-card">
            <div class="error-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h2 class="error-title">Akun Dinonaktifkan</h2>
            <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
            <div class="btn-actions">
                <button class="btn btn-primary" onclick="Swal.fire({title: 'Hubungi Admin', text: 'Silakan hubungi administrator melalui email, telepon, atau datang langsung ke kantor untuk mendapatkan bantuan mengaktifkan kembali akun Anda.', icon: 'info', confirmButtonText: 'OK'})">
                    <i class="fas fa-headset"></i> Hubungi Admin
                </button>
                <a href="dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
    <?php
    exit();
}
// Check if account is blocked due to PIN attempts
$current_time = date('Y-m-d H:i:s');
if ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] > $current_time) {
    $block_until = date('d/m/Y H:i:s', strtotime($user_data['pin_block_until']));
    $error = "Akun Anda diblokir karena terlalu banyak PIN salah. Coba lagi setelah $block_until.";
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Transfer - SCHOBANK</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --secondary: #00C2FF;
            --success: #00D084;
            --warning: #FFB020;
            --danger: #FF3B30;
            --dark: #1C1C1E;
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 80px;
        }
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--white);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
            text-decoration: none;
        }
        .nav-btn:hover {
            background: var(--white);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
            transform: scale(1.05);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .page-header {
            margin-bottom: 24px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
        }
        .error-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 40px;
            margin: 20px auto;
            max-width: 500px;
            text-align: center;
            box-shadow: var(--shadow-md);
        }
        .error-icon {
            font-size: 64px;
            color: var(--danger);
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
        }
        .error-text {
            font-size: 14px;
            color: var(--gray-600);
            margin-bottom: 24px;
            line-height: 1.8;
        }
        .btn {
            padding: 12px 24px;
            border-radius: var(--radius);
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        .btn-secondary:hover {
            background: var(--gray-300);
        }
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            .error-card {
                padding: 24px;
                margin: 10px;
            }
            .error-icon {
                font-size: 48px;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="/schobank/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Transfer</h1>
            <p class="page-subtitle">Akun Diblokir Sementara</p>
        </div>
        <div class="error-card">
            <div class="error-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h2 class="error-title">Akunmu Diblokir Sementara</h2>
            <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
            <a href="dashboard.php" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
    <?php
    exit();
}
// Reset failed attempts if block has expired
if ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] <= $current_time) {
    $query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    $user_data['failed_pin_attempts'] = 0;
    $user_data['pin_block_until'] = null;
}
// Function to format date with Indonesian month names
function getIndonesianMonth($date) {
    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $months[(int)$dateObj->format('m')];
    $year = $dateObj->format('Y');
    return "$day $month $year";
}
// Function to send email with modern template
function sendTransferEmail($mail, $email, $nama, $no_rekening, $id_transaksi, $no_transaksi, $jumlah, $data_pihak_lain, $keterangan, $is_sender = true) {
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    try {
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearReplyTos();
       
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'myschobank@gmail.com';
        $mail->Password = 'xpni zzju utfu mkth';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
       
        $mail->setFrom('myschobank@gmail.com', 'Schobank Student Digital Banking');
        $mail->addAddress($email, $nama);
        $mail->addReplyTo('no-reply@myschobank.com', 'No Reply');
       
        $unique_id = uniqid('myschobank_', true) . '@myschobank.com';
        $mail->MessageID = '<' . $unique_id . '>';
       
        $mail->addCustomHeader('X-Transaction-ID', $id_transaksi);
        $mail->addCustomHeader('X-Reference-Number', $no_transaksi);
       
        $header_path = $_SERVER['DOCUMENT_ROOT'] . '/schobank/assets/images/header.png';
        if (file_exists($header_path)) {
            $mail->addEmbeddedImage($header_path, 'header_img', 'header.png');
        }
        $bulan = [
            'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
            'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
            'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
        ];
        $tanggal_transaksi = date('d M Y H:i:s');
        foreach ($bulan as $en => $id) {
            $tanggal_transaksi = str_replace($en, $id, $tanggal_transaksi);
        }
        $jurusan = $data_pihak_lain['jurusan'] ?? '-';
        $kelas = $data_pihak_lain['kelas'] ?? '-';
        $nama_pihak_lain = $data_pihak_lain['nama'] ?? '-';
        $no_rek_pihak_lain = $data_pihak_lain['no_rekening'] ?? '-';
        if ($is_sender) {
            $subject = "Bukti Transfer {$no_transaksi}";
            $greeting_text = "Kami informasikan bahwa transaksi transfer dari rekening Anda telah berhasil diproses. Berikut rincian transaksi:";
           
            $keterangan_row = !empty($keterangan) ? "
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Keterangan</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>" . htmlspecialchars($keterangan) . "</td>
                </tr>" : "";
            $transaction_details = "
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>ID Transaksi</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>{$id_transaksi}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nomor Referensi</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>{$no_transaksi}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Rekening Pengirim</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$no_rekening}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nama Pengirim</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$nama}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Rekening Tujuan</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$no_rek_pihak_lain}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nama Penerima</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$nama_pihak_lain}</td>
                </tr>
                {$keterangan_row}
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Jumlah Transfer</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>Rp " . number_format($jumlah, 0, ',', '.') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500;'>Tanggal Transaksi</td>
                    <td style='padding: 8px 0; text-align: right;'>{$tanggal_transaksi} WIB</td>
                </tr>";
           
            $info_text = "Terima kasih telah menggunakan layanan Schobank Student Digital Banking. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi petugas kami.";
           
        } else {
            $subject = "Pemberitahuan Transfer Masuk {$no_transaksi}";
            $greeting_text = "Kami informasikan bahwa rekening Anda telah menerima transfer dana. Berikut rincian transaksi:";
           
            $keterangan_row = !empty($keterangan) ? "
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Keterangan</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>" . htmlspecialchars($keterangan) . "</td>
                </tr>" : "";
            $transaction_details = "
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>ID Transaksi</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>{$id_transaksi}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nomor Referensi</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>{$no_transaksi}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Rekening Pengirim</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$no_rek_pihak_lain}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nama Pengirim</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$nama_pihak_lain}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Rekening Penerima</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$no_rekening}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Nama Penerima</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$nama}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Jurusan</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$jurusan}</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Kelas</td>
                    <td style='padding: 8px 0; text-align: right; border-bottom: 1px solid #e2e8f0;'>{$kelas}</td>
                </tr>
                {$keterangan_row}
                <tr>
                    <td style='padding: 8px 0; font-weight: 500; border-bottom: 1px solid #e2e8f0;'>Jumlah Diterima</td>
                    <td style='padding: 8px 0; text-align: right; font-weight: 700; color: #333333; border-bottom: 1px solid #e2e8f0;'>Rp " . number_format($jumlah, 0, ',', '.') . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; font-weight: 500;'>Tanggal Transaksi</td>
                    <td style='padding: 8px 0; text-align: right;'>{$tanggal_transaksi} WIB</td>
                </tr>";
           
            $info_text = "Terima kasih telah menggunakan layanan Schobank Student Digital Banking. Untuk informasi lebih lanjut, silakan kunjungi halaman akun Anda atau hubungi petugas kami.";
        }
        $message_email = "
        <div style='font-family: Poppins, sans-serif; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);'>
            <div style='background: #ffffff; padding: 30px 20px; text-align: center; position: relative;'>
                <div style='position: relative; display: inline-block;'>
                    <img src='cid:header_img' alt='Schobank Student Digital Banking' style='max-width: 450px; width: 100%; height: auto; display: block; margin: 0 auto;' />
                </div>
            </div>
           
            <div style='background: #ffffff; padding: 30px;'>
                <h3 style='color: #1e3a8a; font-size: 20px; font-weight: 600; margin-bottom: 20px;'>Halo, {$nama}</h3>
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px; text-align: justify;'>{$greeting_text}</p>
               
                <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-bottom: 20px;'>
                    <table style='width: 100%; font-size: 15px; color: #333333; border-collapse: collapse;'>
                        {$transaction_details}
                    </table>
                </div>
               
                <p style='color: #333333; font-size: 16px; line-height: 1.6; margin-bottom: 20px; text-align: justify;'>{$info_text}</p>
               
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='mailto:myschobank@gmail.com' style='display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1e3a8a 100%); color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500;'>Hubungi Kami</a>
                </div>
            </div>
           
            <div style='background: #f0f5ff; padding: 20px; text-align: center; font-size: 12px; color: #666666; border-top: 1px solid #e2e8f0;'>
                <p style='margin: 0 0 5px;'>© " . date('Y') . " Schobank Student Digital Banking. Semua hak dilindungi.</p>
                <p style='margin: 0 0 5px;'>Email ini dikirim secara otomatis. Mohon tidak membalas email ini.</p>
                <p style='margin: 0; color: #999999;'>ID Transaksi: {$id_transaksi} | Ref: {$no_transaksi}</p>
            </div>
        </div>";
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $message_email;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</tr>', '</td>'], ["\n", "\n", " "], $message_email));
        if ($mail->send()) {
            $mail->smtpClose();
            return true;
        } else {
            throw new Exception('Email gagal dikirim: ' . $mail->ErrorInfo);
        }
       
    } catch (Exception $e) {
        error_log("Mail error for transaction {$id_transaksi}: " . $e->getMessage());
        return false;
    }
}
// Fetch account details
$query = "SELECT r.id, r.saldo, r.no_rekening
          FROM rekening r
          WHERE r.user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$rekening_data = $result->fetch_assoc();
if ($rekening_data) {
    $saldo = $rekening_data['saldo'];
    $no_rekening = $rekening_data['no_rekening'];
    $rekening_id = $rekening_data['id'];
} else {
    $saldo = 0;
    $no_rekening = 'N/A';
    $rekening_id = null;
}
// Check daily transfer limit
$today = date('Y-m-d');
$query = "SELECT SUM(jumlah) as total_transfer
          FROM transaksi
          WHERE rekening_id = ?
          AND jenis_transaksi = 'transfer'
          AND DATE(created_at) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("is", $rekening_id, $today);
$stmt->execute();
$result = $stmt->get_result();
$transfer_data = $result->fetch_assoc();
$daily_transfer_total = $transfer_data['total_transfer'] ?? 0;
$daily_limit = 500000;
$remaining_limit = $daily_limit - $daily_transfer_total;
// Initialize session variables
$current_step = isset($_SESSION['current_step']) ? $_SESSION['current_step'] : 1;
$sub_step = isset($_SESSION['sub_step']) ? $_SESSION['sub_step'] : 1;
$tujuan_data = isset($_SESSION['tujuan_data']) ? $_SESSION['tujuan_data'] : null;
$input_jumlah = "";
$input_keterangan = "";
$transfer_amount = null;
$transfer_rekening = null;
$transfer_name = null;
$no_transaksi = null;
$error = null;
$success = null;
// Initialize session token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Reset session only if explicitly canceled
if (!isset($_SESSION['show_popup']) || $_SESSION['show_popup'] === false) {
    if ($current_step == 5) {
        $current_step = 1;
        $_SESSION['current_step'] = 1;
        $sub_step = 1;
        $_SESSION['sub_step'] = 1;
    }
}
function resetSessionAndPinAttempts($conn, $user_id) {
    $query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
   
    unset($_SESSION['current_step']);
    unset($_SESSION['sub_step']);
    unset($_SESSION['tujuan_data']);
    unset($_SESSION['transfer_amount']);
    unset($_SESSION['keterangan']);
    unset($_SESSION['keterangan_temp']);
    unset($_SESSION['no_transaksi']);
    unset($_SESSION['transfer_rekening']);
    unset($_SESSION['transfer_name']);
    unset($_SESSION['show_popup']);
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['form_token']) && $_POST['form_token'] === $_SESSION['form_token']) {
    $action = $_POST['action'];
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
   
    switch ($action) {
        case 'verify_account':
            $rekening_tujuan = trim($_POST['rekening_tujuan']);
           
            if (empty($rekening_tujuan)) {
                $error = "Nomor rekening tujuan harus diisi!";
            } elseif ($rekening_tujuan == $no_rekening) {
                $error = "Tidak dapat transfer ke rekening sendiri!";
            } elseif (!preg_match('/^[0-9]{8}$/', $rekening_tujuan)) {
                $error = "Nomor rekening harus berupa 8 digit angka!";
            } else {
                $query = "SELECT r.id, r.user_id, r.no_rekening, u.nama, u.email, u.is_frozen, u.jurusan_id, u.kelas_id,
                          j.nama_jurusan, k.nama_kelas, tk.nama_tingkatan
                          FROM rekening r
                          JOIN users u ON r.user_id = u.id
                          LEFT JOIN jurusan j ON u.jurusan_id = j.id
                          LEFT JOIN kelas k ON u.kelas_id = k.id
                          LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                          WHERE r.no_rekening = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $rekening_tujuan);
                $stmt->execute();
                $result = $stmt->get_result();
                $tujuan_data = $result->fetch_assoc();
               
                if (!$tujuan_data) {
                    $error = "Nomor rekening tujuan tidak ditemukan!";
                } elseif ($tujuan_data['is_frozen']) {
                    $error = "Rekening tujuan sedang dibekukan dan tidak dapat menerima transfer!";
                } else {
                    $_SESSION['tujuan_data'] = $tujuan_data;
                    $_SESSION['current_step'] = 2;
                    $current_step = 2;
                    $sub_step = 1;
                }
            }
            break;
           
        case 'input_amount':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
            $_SESSION['current_step'] = 3;
            $current_step = 3;
            $sub_step = 1;
            $_SESSION['sub_step'] = 1;
            break;
           
        case 'check_amount':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            $jumlah = floatval(str_replace(',', '.', str_replace('.', '', $_POST['jumlah'])));
            $input_jumlah = $_POST['jumlah'];
            $input_keterangan = trim($_POST['keterangan'] ?? '');
           
            if ($jumlah <= 0) {
                $error = "Jumlah transfer harus lebih dari 0!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah < 1) {
                $error = "Jumlah transfer minimal Rp 1!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah > $saldo) {
                $error = "Saldo tidak mencukupi!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } elseif ($jumlah > $remaining_limit) {
                $error = "Melebihi batas transfer harian Rp 500.000!";
                $_SESSION['sub_step'] = 1;
                $sub_step = 1;
                break;
            } else {
                $_SESSION['transfer_amount_temp'] = $jumlah;
                $_SESSION['keterangan_temp'] = $input_keterangan;
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
            }
            break;
           
        case 'confirm_pin':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data']) || !isset($_SESSION['transfer_amount_temp'])) {
                $error = "Data transaksi tidak lengkap. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            $pin = trim($_POST['pin']);
            if (empty($pin)) {
                $error = "PIN harus diisi!";
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }
           
            $query = "SELECT pin, email, nama, failed_pin_attempts, pin_block_until
                      FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data_pin = $result->fetch_assoc();
           
            if (!$user_data_pin) {
                $error = "Pengguna tidak ditemukan!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            if ($user_data_pin['pin_block_until'] !== null && $user_data_pin['pin_block_until'] > date('Y-m-d H:i:s')) {
                $block_until = date('d/m/Y H:i:s', strtotime($user_data_pin['pin_block_until']));
                $error = "Akun Anda diblokir karena terlalu banyak PIN salah. Coba lagi setelah $block_until.";
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }
           
            $hashed_pin = hash('sha256', $pin);
            if ($user_data_pin['pin'] !== $hashed_pin) {
                $new_attempts = $user_data_pin['failed_pin_attempts'] + 1;
                if ($new_attempts >= 3) {
                    $block_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $query = "UPDATE users SET failed_pin_attempts = ?, pin_block_until = ? WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("isi", $new_attempts, $block_until, $user_id);
                    $stmt->execute();
                    $error = "PIN salah! Akun Anda diblokir selama 1 jam karena 3 kali PIN salah.";
                } else {
                    $query = "UPDATE users SET failed_pin_attempts = ? WHERE id = ?";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("ii", $new_attempts, $user_id);
                    $stmt->execute();
                    $error = "PIN yang Anda masukkan salah! Percobaan tersisa: " . (3 - $new_attempts);
                }
                $_SESSION['sub_step'] = 2;
                $sub_step = 2;
                break;
            }
           
            $query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
           
            $_SESSION['transfer_amount'] = $_SESSION['transfer_amount_temp'];
            $_SESSION['keterangan'] = $_SESSION['keterangan_temp'] ?? '';
            unset($_SESSION['transfer_amount_temp'], $_SESSION['keterangan_temp']);
            $_SESSION['current_step'] = 4;
            $current_step = 4;
            $_SESSION['sub_step'] = 1;
            break;
           
        case 'process_transfer':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data']) || !isset($_SESSION['transfer_amount'])) {
                $error = "Data transaksi tidak lengkap. Silakan mulai ulang transaksi.";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            $query = "SELECT email, nama FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();
            $stmt->close();
           
            if (!$user_data) {
                $error = "Data pengguna tidak ditemukan!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            $tujuan_data = $_SESSION['tujuan_data'];
            $rekening_tujuan_id = $tujuan_data['id'];
            $rekening_tujuan = $tujuan_data['no_rekening'];
            $rekening_tujuan_nama = $tujuan_data['nama'];
            $rekening_tujuan_user_id = $tujuan_data['user_id'];
            $rekening_tujuan_email = $tujuan_data['email'] ?? '';
            $jumlah = floatval($_SESSION['transfer_amount']);
            $keterangan_transfer = $_SESSION['keterangan'] ?? '';
           
            if (empty($rekening_tujuan_user_id)) {
                $error = "ID pengguna tujuan tidak valid!";
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
           
            $query_saldo_tujuan = "SELECT saldo FROM rekening WHERE id = ?";
            $stmt_saldo_tujuan = $conn->prepare($query_saldo_tujuan);
            $stmt_saldo_tujuan->bind_param("i", $rekening_tujuan_id);
            $stmt_saldo_tujuan->execute();
            $result_saldo_tujuan = $stmt_saldo_tujuan->get_result();
            $saldo_tujuan_data = $result_saldo_tujuan->fetch_assoc();
            $saldo_tujuan_awal = $saldo_tujuan_data['saldo'] ?? 0;
            $stmt_saldo_tujuan->close();
           
            $saldo_akhir_penerima = $saldo_tujuan_awal + $jumlah;
            $saldo_akhir_pengirim = $saldo - $jumlah;
           
            try {
                $conn->begin_transaction();
               
                do {
                    $date_prefix = date('ymd');
                    $random_8digit = sprintf('%08d', mt_rand(10000000, 99999999));
                    $id_transaksi = $date_prefix . $random_8digit;
                   
                    $check_id_query = "SELECT id FROM transaksi WHERE id_transaksi = ?";
                    $check_id_stmt = $conn->prepare($check_id_query);
                    $check_id_stmt->bind_param('s', $id_transaksi);
                    $check_id_stmt->execute();
                    $check_id_result = $check_id_stmt->get_result();
                    $id_exists = $check_id_result->num_rows > 0;
                    $check_id_stmt->close();
                } while ($id_exists);
               
                do {
                    $date_prefix = date('ymd');
                    $random_6digit = sprintf('%06d', mt_rand(100000, 999999));
                    $no_transaksi = 'TRXTFM' . $date_prefix . $random_6digit;
                   
                    $check_query = "SELECT id FROM transaksi WHERE no_transaksi = ?";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bind_param('s', $no_transaksi);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $exists = $check_result->num_rows > 0;
                    $check_stmt->close();
                } while ($exists);
               
                $query = "INSERT INTO transaksi (id_transaksi, no_transaksi, rekening_id, jenis_transaksi, jumlah, rekening_tujuan_id, keterangan, status, created_at)
                          VALUES (?, ?, ?, 'transfer', ?, ?, ?, 'approved', NOW())";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssidis", $id_transaksi, $no_transaksi, $rekening_id, $jumlah, $rekening_tujuan_id, $keterangan_transfer);
                $stmt->execute();
                $transaksi_id = $stmt->insert_id;
                $stmt->close();
               
                $query = "UPDATE rekening SET saldo = saldo - ? WHERE id = ? AND saldo >= ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("did", $jumlah, $rekening_id, $jumlah);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Saldo tidak mencukupi atau rekening tidak valid!");
                }
                $stmt->close();
               
                $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("di", $jumlah, $rekening_tujuan_id);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Gagal memperbarui saldo penerima!");
                }
                $stmt->close();
               
                $query_mutasi_pengirim = "INSERT INTO mutasi (rekening_id, transaksi_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mutasi_pengirim = $conn->prepare($query_mutasi_pengirim);
                $stmt_mutasi_pengirim->bind_param("iidd", $rekening_id, $transaksi_id, $jumlah, $saldo_akhir_pengirim);
                $stmt_mutasi_pengirim->execute();
                $stmt_mutasi_pengirim->close();
               
                $query_mutasi_penerima = "INSERT INTO mutasi (rekening_id, transaksi_id, jumlah, saldo_akhir, created_at) VALUES (?, ?, ?, ?, NOW())";
                $stmt_mutasi_penerima = $conn->prepare($query_mutasi_penerima);
                $stmt_mutasi_penerima->bind_param("iidd", $rekening_tujuan_id, $transaksi_id, $jumlah, $saldo_akhir_penerima);
                $stmt_mutasi_penerima->execute();
                $stmt_mutasi_penerima->close();
               
                $message_pengirim = "Yey, kamu berhasil transfer Rp " . number_format($jumlah, 0, '.', '.') . " ke $rekening_tujuan_nama! Cek riwayat transaksimu sekarang!";
                $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
                $stmt_notifikasi_pengirim = $conn->prepare($query_notifikasi_pengirim);
                $stmt_notifikasi_pengirim->bind_param("is", $user_id, $message_pengirim);
                $stmt_notifikasi_pengirim->execute();
                $stmt_notifikasi_pengirim->close();
               
                $message_penerima = "Yey, kamu menerima transfer Rp " . number_format($jumlah, 0, '.', '.') . " dari $user_data[nama]! Cek saldo mu sekarang!";
                $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())";
                $stmt_notifikasi_penerima = $conn->prepare($query_notifikasi_penerima);
                $stmt_notifikasi_penerima->bind_param("is", $rekening_tujuan_user_id, $message_penerima);
                $stmt_notifikasi_penerima->execute();
                $stmt_notifikasi_penerima->close();
               
                $conn->commit();
               
                $mail = new PHPMailer(true);
               
                $jurusan_tujuan = $tujuan_data['nama_jurusan'] ?? '-';
                $kelas_tujuan = ($tujuan_data['nama_tingkatan'] ?? '') . ' ' . ($tujuan_data['nama_kelas'] ?? '');
               
                $data_penerima = [
                    'nama' => $rekening_tujuan_nama,
                    'no_rekening' => $rekening_tujuan,
                    'jurusan' => $jurusan_tujuan,
                    'kelas' => trim($kelas_tujuan)
                ];
               
                $data_pengirim = [
                    'nama' => $user_data['nama'],
                    'no_rekening' => $no_rekening,
                    'jurusan' => '-',
                    'kelas' => '-'
                ];
               
                try {
                    sendTransferEmail($mail, $user_data['email'] ?? '', $user_data['nama'], $no_rekening, $id_transaksi, $no_transaksi, $jumlah, $data_penerima, $keterangan_transfer, true);
                } catch (Exception $e) {
                    error_log("Failed to send email to sender: " . $e->getMessage());
                }
               
                try {
                    sendTransferEmail($mail, $rekening_tujuan_email, $rekening_tujuan_nama, $rekening_tujuan, $id_transaksi, $no_transaksi, $jumlah, $data_pengirim, $keterangan_transfer, false);
                } catch (Exception $e) {
                    error_log("Failed to send email to recipient: " . $e->getMessage());
                }
               
                $_SESSION['no_transaksi'] = $no_transaksi;
                $_SESSION['transfer_amount'] = $jumlah;
                $_SESSION['transfer_rekening'] = $rekening_tujuan;
                $_SESSION['transfer_name'] = $rekening_tujuan_nama;
                $_SESSION['show_popup'] = true;
               
                unset($_SESSION['tujuan_data']);
                unset($_SESSION['keterangan']);
               
                $_SESSION['current_step'] = 1;
                $_SESSION['sub_step'] = 1;
               
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Gagal memproses transfer: " . $e->getMessage();
                $_SESSION['current_step'] = 4;
                $current_step = 4;
            }
            break;
           
        case 'cancel':
            resetSessionAndPinAttempts($conn, $user_id);
            header("Location: transfer_pribadi.php");
            exit();
           
        case 'cancel_to_dashboard':
            resetSessionAndPinAttempts($conn, $user_id);
            header("Location: dashboard.php");
            exit();
    }
}
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, '.', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Transfer - SCHOBANK</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --secondary: #00C2FF;
            --success: #00D084;
            --warning: #FFB020;
            --danger: #FF3B30;
            --dark: #1C1C1E;
            --elegant-dark: #2c3e50;
            --elegant-gray: #434343;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --white: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 6px 15px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            padding-top: 70px;
            padding-bottom: 80px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        /* Top Navigation */
        .top-nav {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-bottom: none;
            padding: 8px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 100;
            height: 70px;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            height: 100%;
        }
        .nav-logo {
            height: 45px;
            width: auto;
            max-width: 180px;
            object-fit: contain;
            object-position: left center;
            display: block;
        }
        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: var(--white);
            color: var(--gray-700);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            flex-shrink: 0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
        }
        .nav-btn:hover {
            background: var(--white);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
            transform: scale(1.05);
        }
        .nav-btn:active {
            transform: scale(0.95);
        }
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            flex: 1;
        }
        /* Page Header */
        .page-header {
            margin-bottom: 32px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .page-subtitle {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 400;
        }
        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 32px;
            gap: 8px;
        }
        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0 12px;
            flex: 1;
            max-width: 120px;
        }
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--gray-200);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 16px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
        }
        .step-item.active .step-number {
            background: var(--elegant-dark);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(44, 62, 80, 0.3);
        }
        .step-item.completed .step-number {
            background: var(--success);
            color: var(--white);
        }
        .step-text {
            font-size: 12px;
            color: var(--gray-600);
            text-align: center;
            font-weight: 500;
        }
        .step-item.active .step-text {
            color: var(--elegant-dark);
            font-weight: 600;
        }
        /* Transfer Card - TRANSPARENT/SEAMLESS */
        .transfer-card {
            background: transparent;
            border-radius: 0;
            padding: 32px 20px;
            margin-bottom: 24px;
            box-shadow: none;
        }
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 24px;
            text-align: center;
        }
        /* Form Elements */
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
            text-align: center;
        }
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 16px;
            transition: all 0.2s ease;
            background: var(--white);
            color: var(--gray-900);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
        }
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        /* PIN Input */
        .pin-container {
            display: flex;
            justify-content: center;
            gap: 12px;
            margin: 24px 0;
        }
        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            transition: all 0.2s ease;
            background: var(--white);
        }
        .pin-input:focus {
            outline: none;
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
        }
        /* Detail Row */
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid var(--gray-200);
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-size: 14px;
            color: var(--gray-600);
            font-weight: 500;
        }
        .detail-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-900);
            text-align: right;
        }
        /* Buttons */
        .btn {
            padding: 12px 24px;
            border-radius: var(--radius);
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary {
            background: var(--elegant-dark);
            color: var(--white);
        }
        .btn-primary:hover {
            background: var(--elegant-gray);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .btn-danger {
            background: var(--danger);
            color: var(--white);
        }
        .btn-danger:hover {
            background: #e32e24;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        /* Button Actions - 2 Columns for Multiple Buttons */
        .btn-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }
        /* Single Button - Full Width Center */
        .btn-actions-single {
            display: flex;
            justify-content: center;
            margin-top: 24px;
        }
        .btn-actions-single .btn {
            min-width: 200px;
        }
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
            border-top: none;
            padding: 12px 20px 20px;
            display: flex;
            justify-content: space-around;
            z-index: 100;
            transition: transform 0.3s ease, opacity 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        .bottom-nav.hidden {
            transform: translateY(100%);
            opacity: 0;
        }
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            color: var(--gray-400);
            transition: all 0.2s ease;
            padding: 8px 16px;
        }
        .nav-item i {
            font-size: 22px;
            transition: color 0.2s ease;
        }
        .nav-item span {
            font-size: 11px;
            font-weight: 600;
            transition: color 0.2s ease;
        }
        .nav-item:hover i,
        .nav-item:hover span,
        .nav-item.active i,
        .nav-item.active span {
            color: var(--elegant-dark);
        }
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 16px;
            }
            .page-title {
                font-size: 22px;
            }
            .transfer-card {
                padding: 24px 16px;
            }
            .step-indicator {
                gap: 4px;
            }
            .step-item {
                padding: 0 8px;
            }
            .step-number {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }
            .step-text {
                font-size: 10px;
            }
            .pin-container {
                gap: 8px;
            }
            .pin-input {
                width: 45px;
                height: 45px;
                font-size: 20px;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
            .detail-row {
                flex-direction: column;
                gap: 4px;
            }
            .detail-value {
                text-align: left;
            }
        }
        @media (max-width: 480px) {
            .nav-logo {
                height: 32px;
                max-width: 120px;
            }
            .nav-btn {
                width: 36px;
                height: 36px;
            }
            .pin-input {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
        }
        /* Keyboard handling for mobile */
        @media (max-width: 768px) {
            body.keyboard-open {
                padding-bottom: 0;
            }
            body.keyboard-open .bottom-nav {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="/schobank/assets/images/header.png" alt="SCHOBANK" class="nav-logo">
        </div>
    </nav>
    <!-- Main Container -->
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">Transfer Antar Schobank</h1>
            <p class="page-subtitle">Kirim dana ke rekening Schobank teman kamu dengan mudah disini!</p>
        </div>
        <?php if ($error): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: '<?php echo addslashes($error); ?>',
                    confirmButtonText: 'OK'
                }).then(() => {
                    const rekeningInput = document.getElementById('rekening_tujuan');
                    if (rekeningInput && <?php echo $current_step; ?> === 1) {
                        rekeningInput.value = '';
                        rekeningInput.focus();
                    }
                    const pinInputs = document.querySelectorAll('.pin-input');
                    if (pinInputs.length > 0 && <?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 2) {
                        pinInputs.forEach(input => {
                            input.value = '';
                            input.dataset.value = '';
                        });
                        const hiddenPin = document.getElementById('pin');
                        if (hiddenPin) hiddenPin.value = '';
                        if (pinInputs[0]) pinInputs[0].focus();
                    }
                    const jumlahInput = document.getElementById('jumlah');
                    if (jumlahInput && <?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 1) {
                        jumlahInput.value = '';
                        jumlahInput.focus();
                    }
                });
            </script>
        <?php endif; ?>
        <?php if (isset($_SESSION['show_popup']) && $_SESSION['show_popup'] === true): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: 'Transfer Berhasil!',
                    text: 'Dana telah dikirim. Cek email Anda untuk bukti transfer.',
                    confirmButtonText: 'OK'
                }).then(() => {
                    window.location.href = 'struk_transaksi_transfer.php?no_transaksi=<?php echo htmlspecialchars($_SESSION['no_transaksi']); ?>';
                });
            </script>
            <?php unset($_SESSION['show_popup']); ?>
        <?php endif; ?>
        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-item <?php echo $current_step >= 1 ? ($current_step == 1 ? 'active' : 'completed') : ''; ?>">
                <div class="step-number">1</div>
                <div class="step-text">Cek Rekening</div>
            </div>
            <div class="step-item <?php echo $current_step >= 2 ? ($current_step == 2 ? 'active' : 'completed') : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-text">Detail</div>
            </div>
            <div class="step-item <?php echo $current_step >= 3 ? ($current_step == 3 ? 'active' : 'completed') : ''; ?>">
                <div class="step-number">3</div>
                <div class="step-text">Nominal & PIN</div>
            </div>
            <div class="step-item <?php echo $current_step >= 4 ? 'active' : ''; ?>">
                <div class="step-number">4</div>
                <div class="step-text">Konfirmasi</div>
            </div>
        </div>
        <!-- Step 1: Check Account - SINGLE BUTTON -->
        <?php if ($current_step == 1): ?>
        <div class="transfer-card">
            <h3 class="section-title">Masukkan Nomor Rekening Tujuan</h3>
            <form method="POST" action="" id="cekRekening">
                <input type="hidden" name="action" value="verify_account">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                <div class="form-group">
                    <label for="rekening_tujuan" class="form-label">No Rekening (8 Digit)</label>
                    <input type="text" class="form-control" id="rekening_tujuan" name="rekening_tujuan" placeholder="Input No rekening..." required autofocus inputmode="numeric" maxlength="8">
                </div>
                <div class="btn-actions-single">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Cek Rekening
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <!-- Step 2: Recipient Details -->
        <?php if ($current_step == 2): ?>
        <div class="transfer-card">
            <h3 class="section-title">Detail Penerima</h3>
            <?php if (isset($_SESSION['tujuan_data'])) $tujuan_data = $_SESSION['tujuan_data']; ?>
            <div class="detail-row">
                <span class="detail-label">Nomor Rekening:</span>
                <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['no_rekening'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Nama Penerima:</span>
                <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Jurusan:</span>
                <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama_jurusan'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Kelas:</span>
                <span class="detail-value"><?php echo htmlspecialchars(($tujuan_data['nama_tingkatan'] ?? '') . ' ' . ($tujuan_data['nama_kelas'] ?? '')); ?></span>
            </div>
            <form method="POST" action="" id="proceedForm2" style="display: inline;">
                <input type="hidden" name="action" value="input_amount">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
            </form>
            <form method="POST" action="" id="cancelForm2" style="display: inline;">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
            </form>
            <div class="btn-actions">
                <button type="submit" form="proceedForm2" class="btn btn-primary">
                    Lanjut
                </button>
                <button type="submit" form="cancelForm2" class="btn btn-danger">
                    Batal
                </button>
            </div>
        </div>
        <?php endif; ?>
        <!-- Step 3: Input Amount and PIN -->
        <?php if ($current_step == 3): ?>
        <div class="transfer-card">
            <h3 class="section-title">Nominal & PIN</h3>
            <?php if ($sub_step == 1): ?>
                <form method="POST" action="" id="amountForm">
                    <input type="hidden" name="action" value="check_amount">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    <div class="form-group">
                        <label for="jumlah" class="form-label">Jumlah Transfer</label>
                        <input type="text" class="form-control" id="jumlah" name="jumlah" placeholder="Minimal Rp 1" required value="<?php echo htmlspecialchars($input_jumlah); ?>" inputmode="numeric">
                    </div>
                    <div class="form-group">
                        <label for="keterangan" class="form-label">Keterangan (Opsional)</label>
                        <textarea class="form-control" id="keterangan" name="keterangan" placeholder="Masukkan keterangan transfer..."><?php echo htmlspecialchars($input_keterangan); ?></textarea>
                    </div>
                </form>
                <form method="POST" action="" id="cancelForm3a">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <div class="btn-actions">
                    <button type="submit" form="amountForm" class="btn btn-primary">
                        Cek Saldo
                    </button>
                    <button type="submit" form="cancelForm3a" class="btn btn-danger">
                      Batal
                    </button>
                </div>
            <?php elseif ($sub_step == 2): ?>
                <div class="form-group">
                    <label class="form-label">Jumlah Transfer</label>
                    <div class="detail-row">
                        <span class="detail-label">Nominal:</span>
                        <span class="detail-value"><?php echo formatRupiah($_SESSION['transfer_amount_temp'] ?? 0); ?></span>
                    </div>
                </div>
                <?php if (!empty($_SESSION['keterangan_temp'])): ?>
                <div class="form-group">
                    <label class="form-label">Keterangan</label>
                    <div class="detail-row">
                        <span class="detail-label">Catatan:</span>
                        <span class="detail-value"><?php echo htmlspecialchars($_SESSION['keterangan_temp']); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <form method="POST" action="" id="pinForm">
                    <input type="hidden" name="action" value="confirm_pin">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                    <div class="form-group">
                        <label class="form-label">Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" data-index="0" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="1" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="2" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="3" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="4" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="5" inputmode="numeric" autocomplete="off">
                        </div>
                        <input type="hidden" id="pin" name="pin">
                    </div>
                </form>
                <form method="POST" action="" id="cancelForm3b">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
                </form>
                <div class="btn-actions">
                    <button type="submit" form="pinForm" class="btn btn-primary">
                        Lanjut
                    </button>
                    <button type="submit" form="cancelForm3b" class="btn btn-danger">
                         Batal
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- Step 4: Confirmation -->
        <?php if ($current_step == 4): ?>
        <div class="transfer-card">
            <h3 class="section-title">Konfirmasi Transfer</h3>
            <?php if (isset($_SESSION['tujuan_data'])) $tujuan_data = $_SESSION['tujuan_data']; ?>
            <div class="detail-row">
                <span class="detail-label">Rekening Asal:</span>
                <span class="detail-value"><?php echo htmlspecialchars($no_rekening); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Rekening Tujuan:</span>
                <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['no_rekening'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Nama Penerima:</span>
                <span class="detail-value"><?php echo htmlspecialchars($tujuan_data['nama'] ?? '-'); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Jumlah Transfer:</span>
                <span class="detail-value"><?php echo formatRupiah($_SESSION['transfer_amount'] ?? 0); ?></span>
            </div>
            <?php if (!empty($_SESSION['keterangan'])): ?>
            <div class="detail-row">
                <span class="detail-label">Keterangan:</span>
                <span class="detail-value"><?php echo htmlspecialchars($_SESSION['keterangan']); ?></span>
            </div>
            <?php endif; ?>
            <div class="detail-row">
                <span class="detail-label">Biaya:</span>
                <span class="detail-value">Rp 0</span>
            </div>
            <form method="POST" action="" id="processForm">
                <input type="hidden" name="action" value="process_transfer">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
            </form>
            <form method="POST" action="" id="cancelForm4">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
            </form>
            <div class="btn-actions">
                <button type="submit" form="processForm" class="btn btn-primary">
                   Proses Transfer
                </button>
                <button type="submit" form="cancelForm4" class="btn btn-danger">
                     Batal
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- Bottom Navigation -->
    <div class="bottom-nav" id="bottomNav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Beranda</span>
        </a>
        <a href="transfer_pribadi.php" class="nav-item active">
            <i class="fas fa-paper-plane"></i>
            <span>Transfer</span>
        </a>
        <a href="cek_mutasi.php" class="nav-item">
            <i class="fas fa-list-alt"></i>
            <span>Mutasi</span>
        </a>
        <a href="profil.php" class="nav-item">
            <i class="fas fa-user"></i>
            <span>Profil</span>
        </a>
    </div>
    <!-- Global Cancel Form -->
    <form id="globalCancelForm" method="POST" action="" style="display: none;">
        <input type="hidden" name="action" value="cancel_to_dashboard">
        <input type="hidden" name="form_token" value="<?php echo htmlspecialchars($_SESSION['form_token']); ?>">
    </form>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const rekeningInput = document.getElementById('rekening_tujuan');
            const jumlahInput = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const hiddenPin = document.getElementById('pin');
            const bottomNav = document.getElementById('bottomNav');
            let isSubmitting = false;
            let scrollTimer;
            let lastScrollTop = 0;
            // Bottom nav auto hide/show
            window.addEventListener('scroll', () => {
                const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
               
                if (currentScroll > lastScrollTop || currentScroll < lastScrollTop) {
                    bottomNav.classList.add('hidden');
                }
               
                lastScrollTop = currentScroll <= 0 ? 0 : currentScroll;
                clearTimeout(scrollTimer);
                scrollTimer = setTimeout(() => {
                    bottomNav.classList.remove('hidden');
                }, 500);
            }, { passive: true });
            // Mobile keyboard handling
            let initialHeight = window.innerHeight;
            function handleKeyboard() {
                const currentHeight = window.innerHeight;
                if (currentHeight < initialHeight * 0.9) {
                    document.body.classList.add('keyboard-open');
                } else {
                    document.body.classList.remove('keyboard-open');
                }
            }
            window.addEventListener('resize', handleKeyboard);
            // Rekening input validation
            if (rekeningInput) {
                rekeningInput.addEventListener('input', function(e) {
                    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8);
                });
                rekeningInput.addEventListener('keydown', function(e) {
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
            }
            // Jumlah input formatting
            if (jumlahInput) {
                jumlahInput.addEventListener('input', function(e) {
                    let value = e.target.value.replace(/[^0-9]/g, '');
                    if (value) {
                        e.target.value = new Intl.NumberFormat('id-ID').format(parseInt(value));
                    } else {
                        e.target.value = '';
                    }
                });
            }
            // PIN inputs handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function() {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.value = '*';
                        this.dataset.value = value;
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        this.value = '';
                        this.dataset.value = '';
                    }
                    updatePin();
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.dataset.value && index > 0) {
                        pinInputs[index - 1].focus();
                    }
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
                input.addEventListener('focus', function() {
                    if (this.value === '*' && this.dataset.value) {
                        this.value = this.dataset.value;
                    }
                });
                input.addEventListener('blur', function() {
                    if (this.value !== '*' && this.dataset.value) {
                        this.value = '*';
                    }
                });
            });
            function updatePin() {
                if (hiddenPin) {
                    hiddenPin.value = Array.from(pinInputs).map(i => i.dataset.value || '').join('');
                }
            }
            // Form submissions with loading
            const cekForm = document.getElementById('cekRekening');
            if (cekForm) {
                cekForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    const rekening = rekeningInput.value.trim();
                    if (rekening.length !== 8 || !/^\d{8}$/.test(rekening)) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Nomor rekening harus 8 digit angka!',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            rekeningInput.value = '';
                            rekeningInput.focus();
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memverifikasi Rekening...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 2000);
                });
            }
            const amountForm = document.getElementById('amountForm');
            if (amountForm) {
                amountForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    let jumlah = jumlahInput.value.replace(/[^0-9]/g, '');
                    jumlah = parseInt(jumlah);
                    if (!jumlah || jumlah < 1) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'Jumlah transfer minimal Rp 1',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            jumlahInput.focus();
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Mengecek Saldo...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1500);
                });
            }
            const pinForm = document.getElementById('pinForm');
            if (pinForm) {
                pinForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    let pin = hiddenPin.value;
                    if (pin.length !== 6) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'PIN harus 6 digit',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            pinInputs[0].focus();
                            pinInputs.forEach(input => {
                                input.value = '';
                                input.dataset.value = '';
                            });
                            hiddenPin.value = '';
                        });
                        return;
                    }
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memverifikasi PIN...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1500);
                });
            }
            const processForm = document.getElementById('processForm');
            if (processForm) {
                processForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (isSubmitting) return;
                    isSubmitting = true;
                    Swal.fire({
                        title: 'Memproses Transfer',
                        text: 'Mohon tunggu sebentar...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    setTimeout(() => {
                        this.submit();
                    }, 1000);
                });
            }
            // Focus on relevant input
            if (<?php echo $current_step; ?> === 1 && rekeningInput) {
                rekeningInput.focus();
            } else if (<?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 1 && jumlahInput) {
                jumlahInput.focus();
            } else if (<?php echo $current_step; ?> === 3 && <?php echo $sub_step; ?> === 2 && pinInputs[0]) {
                pinInputs[0].focus();
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
?>