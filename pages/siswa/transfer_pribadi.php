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
    <title>Transfer - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset default styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Body styles */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f5f5;
            color: #333;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Top navigation (banner) */
        .top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #0a2e5c;
            padding: 12px 16px;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .top-nav h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .back-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .back-btn:hover {
            opacity: 0.8;
        }

        .back-btn i {
            font-size: 24px;
        }

        /* Error container */
        .error-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 20px;
            margin-top: 70px; /* Account for fixed nav */
            text-align: center;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .error-icon {
            font-size: 64px;
            color: #d32f2f;
            margin-bottom: 20px;
        }

        .error-container h2 {
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-container h2 i {
            color: #d32f2f;
        }

        .error-container p {
            font-size: 16px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        /* Contact button */
        .contact-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background-color: #0a2e5c;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: 500;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        .contact-btn:hover {
            background-color: #083a7a;
        }

        .contact-btn:active {
            transform: scale(0.98);
        }

        .contact-btn i {
            font-size: 18px;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .top-nav h1 {
                font-size: 18px;
            }

            .back-btn {
                font-size: 20px;
            }

            .back-btn i {
                font-size: 20px;
            }

            .error-icon {
                font-size: 48px;
            }

            .error-container h2 {
                font-size: 20px;
            }

            .error-container p {
                font-size: 14px;
            }

            .contact-btn {
                padding: 10px 20px;
                font-size: 14px;
            }

            .contact-btn i {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-times"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>
    <div class="error-container">
        <i class="fas fa-lock error-icon"></i>
        <h2><i class="fas fa-exclamation-triangle"></i> Akun Dinonaktifkan</h2>
        <p><?php echo htmlspecialchars($error); ?></p>
        <button class="contact-btn" onclick="window.location.href='contact_admin.php'">
            <i class="fas fa-headset"></i> Hubungi Admin
        </button>
    </div>
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
    <title>Transfer - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Reset default styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Body styles */
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f5f5;
            color: #333;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Top navigation (banner) */
        .top-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background-color: #0a2e5c;
            padding: 12px 16px;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .top-nav h1 {
            font-size: 20px;
            font-weight: 600;
            margin: 0;
        }

        .back-btn {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .back-btn:hover {
            opacity: 0.8;
        }

        .back-btn i {
            font-size: 24px;
        }

        /* Error container */
        .error-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1;
            padding: 20px;
            margin-top: 70px; /* Account for fixed nav */
            text-align: center;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }

        .error-icon {
            font-size: 64px;
            color: #d32f2f;
            margin-bottom: 20px;
        }

        .error-container h2 {
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-container h2 i {
            color: #d32f2f;
        }

        .error-container p {
            font-size: 16px;
            color: #666;
            line-height: 1.5;
        }

        /* Responsive adjustments */
        @media (max-width: 480px) {
            .top-nav h1 {
                font-size: 18px;
            }

            .back-btn {
                font-size: 20px;
            }

            .back-btn i {
                font-size: 20px;
            }

            .error-icon {
                font-size: 48px;
            }

            .error-container h2 {
                font-size: 20px;
            }

            .error-container p {
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-times"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>
    <div class="error-container">
        <i class="fas fa-lock error-icon"></i>
        <h2><i class="fas fa-exclamation-triangle"></i> Akun Diblokir</h2>
        <p><?php echo htmlspecialchars($error); ?></p>
    </div>
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
$tujuan_data = isset($_SESSION['tujuan_data']) ? $_SESSION['tujuan_data'] : null;
$input_jumlah = "";
$transfer_amount = null;
$transfer_rekening = null;
$transfer_name = null;
$no_transaksi = null;
$error = null;
$success = null;

// Initialize session token for form validation
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// Reset popup state only after closing popup
if (!isset($_SESSION['show_popup']) || $_SESSION['show_popup'] === false) {
    if ($current_step == 5) {
        $current_step = 1;
        $_SESSION['current_step'] = 1;
        unset($_SESSION['no_transaksi']);
    }
}

function resetSessionAndPinAttempts($conn, $user_id) {
    // Reset failed PIN attempts in the database
    $query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
    error_log("PIN attempts reset to 0 for user_id $user_id");

    // Clear session data
    unset($_SESSION['current_step']);
    unset($_SESSION['tujuan_data']);
    unset($_SESSION['transfer_amount']);
    unset($_SESSION['no_transaksi']);
    unset($_SESSION['show_popup']);
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['form_token']) && $_POST['form_token'] === $_SESSION['form_token']) {
    $action = $_POST['action'];
    // Generate new token for next submission
    $_SESSION['form_token'] = bin2hex(random_bytes(32));

    switch ($action) {
        case 'verify_account':
            $rekening_tujuan = trim($_POST['rekening_tujuan']);
            if (empty($rekening_tujuan) || $rekening_tujuan === 'REK') {
                $error = "Nomor rekening tujuan harus diisi!";
            } elseif ($rekening_tujuan == $no_rekening) {
                $error = "Tidak dapat transfer ke rekening sendiri!";
            } elseif (!preg_match('/^REK[0-9]{1,6}$/', $rekening_tujuan)) {
                $error = "Nomor rekening harus berupa REK diikuti 1-6 digit angka!";
            } else {
                $query = "SELECT r.id, r.user_id, r.no_rekening, u.nama, u.email, u.is_frozen, u.jurusan_id, u.kelas_id, 
                          j.nama_jurusan, k.nama_kelas
                          FROM rekening r 
                          JOIN users u ON r.user_id = u.id 
                          LEFT JOIN jurusan j ON u.jurusan_id = j.id
                          LEFT JOIN kelas k ON u.kelas_id = k.id
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
                }
            }
            break;

        case 'input_amount':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                error_log("Error in input_amount: tujuan_data not set for user_id $user_id");
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
            $_SESSION['current_step'] = 3;
            $current_step = 3;
            error_log("Transition to Step 3 successful for user_id $user_id");
            break;

        case 'confirm_transfer':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data'])) {
                $error = "Data rekening tujuan hilang. Silakan mulai ulang transaksi.";
                error_log("Error in confirm_transfer: tujuan_data not set for user_id $user_id");
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            $pin = trim($_POST['pin']);
            $jumlah = floatval(str_replace(',', '.', str_replace('.', '', $_POST['jumlah'])));
            $input_jumlah = $_POST['jumlah'];

            if (empty($pin)) {
                $error = "PIN harus diisi!";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            }

            if ($jumlah <= 0) {
                $error = "Jumlah transfer harus lebih dari 0!";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            } elseif ($jumlah < 1000) {
                $error = "Jumlah transfer minimal Rp 1.000!";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            } elseif ($jumlah > $saldo) {
                $error = "Saldo tidak mencukupi!";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            } elseif ($jumlah > $remaining_limit) {
                $error = "Melebihi batas transfer harian Rp 500.000!";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            }

            $query = "SELECT pin, email, nama, failed_pin_attempts, pin_block_until 
                      FROM users WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_data = $result->fetch_assoc();

            if (!$user_data) {
                $error = "Pengguna tidak ditemukan!";
                error_log("Error in confirm_transfer: User not found for user_id $user_id");
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }

            // Re-check block status
            if ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] > $current_time) {
                $block_until = date('d/m/Y H:i:s', strtotime($user_data['pin_block_until']));
                $error = "Akun Anda diblokir karena terlalu banyak PIN salah. Coba lagi setelah $block_until.";
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                break;
            }

            if ($user_data['pin'] !== $pin) {
                $new_attempts = $user_data['failed_pin_attempts'] + 1;
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
                $_SESSION['current_step'] = 3;
                $current_step = 3;
                error_log("PIN validation failed for user_id $user_id, attempts: $new_attempts");
                break;
            }

            // PIN is correct, reset failed attempts
            $query = "UPDATE users SET failed_pin_attempts = 0, pin_block_until = NULL WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            error_log("PIN validated successfully for user_id $user_id, resetting failed_pin_attempts to 0");

            $_SESSION['transfer_amount'] = $jumlah;
            $_SESSION['current_step'] = 4;
            $current_step = 4;
            error_log("PIN verified, transition to Step 4 successful for user_id $user_id");
            break;

        case 'process_transfer':
            if (!isset($_SESSION['tujuan_data']) || empty($_SESSION['tujuan_data']) || !isset($_SESSION['transfer_amount'])) {
                $error = "Data transaksi tidak lengkap. Silakan mulai ulang transaksi.";
                error_log("Error in process_transfer: tujuan_data or transfer_amount not set for user_id $user_id");
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

            if (!$user_data) {
                $error = "Data pengguna tidak ditemukan!";
                error_log("Error in process_transfer: User data not found for user_id $user_id");
                resetSessionAndPinAttempts($conn, $user_id);
                $_SESSION['current_step'] = 1;
                $current_step = 1;
                break;
            }
            error_log("User data fetched for user_id $user_id: email={$user_data['email']}, nama={$user_data['nama']}");

            $tujuan_data = $_SESSION['tujuan_data'];
            $rekening_tujuan_id = $tujuan_data['id'];
            $rekening_tujuan = $tujuan_data['no_rekening'];
            $rekening_tujuan_nama = $tujuan_data['nama'];
            $rekening_tujuan_user_id = $tujuan_data['user_id'];
            $rekening_tujuan_email = $tujuan_data['email'];
            $jumlah = $_SESSION['transfer_amount'];

            try {
                $conn->begin_transaction();
                $no_transaksi = 'TF' . date('YmdHis') . rand(1000, 9999);

                $query = "INSERT INTO transaksi (no_transaksi, rekening_id, jenis_transaksi, jumlah, rekening_tujuan_id, status, created_at) 
                          VALUES (?, ?, 'transfer', ?, ?, 'approved', NOW())";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sidi", $no_transaksi, $rekening_id, $jumlah, $rekening_tujuan_id);
                $stmt->execute();

                $query = "UPDATE rekening SET saldo = saldo - ? WHERE id = ? AND saldo >= ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("did", $jumlah, $rekening_id, $jumlah);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Saldo tidak mencukupi atau rekening tidak valid!");
                }

                $query = "UPDATE rekening SET saldo = saldo + ? WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("di", $jumlah, $rekening_tujuan_id);
                $stmt->execute();
                if ($stmt->affected_rows == 0) {
                    throw new Exception("Gagal memperbarui saldo penerima!");
                }

                $query = "SELECT saldo FROM rekening WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $rekening_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $updated_rekening = $result->fetch_assoc();
                $saldo_baru_pengirim = $updated_rekening['saldo'];

                $query = "SELECT saldo FROM rekening WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $rekening_tujuan_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $updated_rekening_tujuan = $result->fetch_assoc();
                $saldo_baru_penerima = $updated_rekening_tujuan['saldo'];

                $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rekening_tujuan . " atas nama " . $rekening_tujuan_nama . " berhasil. Saldo baru: Rp " . number_format($saldo_baru_pengirim, 0, ',', '.');
                $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                $stmt_notifikasi_pengirim = $conn->prepare($query_notifikasi_pengirim);
                $stmt_notifikasi_pengirim->bind_param('is', $user_id, $message_pengirim);
                $stmt_notifikasi_pengirim->execute();

                $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $no_rekening . ". Saldo baru: Rp " . number_format($saldo_baru_penerima, 0, ',', '.');
                $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                $stmt_notifikasi_penerima = $conn->prepare($query_notifikasi_penerima);
                $stmt_notifikasi_penerima->bind_param('is', $rekening_tujuan_user_id, $message_penerima);
                $stmt_notifikasi_penerima->execute();

                $conn->commit();

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
                    $mail->addAddress($user_data['email'], $user_data['nama']);
                    $mail->isHTML(true);
                    $mail->Subject = 'Bukti Transfer SCHOBANK - ' . $no_transaksi;
                    $mail->Body = '
                    <div style="font-family: Poppins, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                        <div style="text-align: center; padding: 15px; background-color: #0a2e5c; color: white; border-radius: 8px 8px 0 0;">
                            <h2 style="margin: 0; font-size: 24px;">SCHOBANK SYSTEM</h2>
                        </div>
                        <div style="padding: 20px;">
                            <h3 style="color: #0a2e5c; font-size: 20px; margin-bottom: 20px;">Bukti Transfer Elektronik</h3>
                            <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Yth. Bapak/Ibu ' . htmlspecialchars($user_data['nama']) . ',</p>
                            <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Kami dengan senang hati menginformasikan bahwa transfer Anda telah berhasil dilakukan. Berikut adalah rincian transaksi Anda:</p>
                            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                <tr style="background-color: #f9f9f9;">
                                    <td style="padding: 10px; font-weight: bold; color: #333; width: 40%; border: 1px solid #e0e0e0;">No. Transaksi</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($no_transaksi) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Waktu Transaksi</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . date('d/m/Y H:i:s') . '</td>
                                </tr>
                                <tr style="background-color: #f9f9f9;">
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Rekening Sumber</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($no_rekening) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Nama Pengirim</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($user_data['nama']) . '</td>
                                </tr>
                                <tr style="background-color: #f9f9f9;">
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Rekening Tujuan</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($rekening_tujuan) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Nama Penerima</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($rekening_tujuan_nama) . '</td>
                                </tr>
                                <tr style="background-color: #f9f9f9;">
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Jumlah Transfer</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">Rp ' . number_format($jumlah, 0, ',', '.') . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Biaya</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">Rp 0,-</td>
                                </tr>
                                <tr style="background-color: #f9f9f9;">
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Status</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">BERHASIL</td>
                                </tr>
                                <tr>
                                    <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Saldo Akhir</td>
                                    <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">Rp ' . number_format($saldo_baru_pengirim, 0, ',', '.') . '</td>
                                </tr>
                            </table>
                            <p style="color: #d32f2f; font-size: 14px; font-weight: bold; margin-bottom: 20px;">Penting: Jangan membagikan informasi rekening dan PIN Anda kepada siapapun. SCHOBANK tidak pernah meminta informasi rahasia melalui email atau telepon.</p>
                            <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Jika Anda memiliki pertanyaan, silakan hubungi tim kami melalui email atau telepon yang tersedia di situs resmi kami.</p>
                            <p style="color: #333; font-size: 16px; line-height: 1.6;">Hormat kami,<br>Tim SCHOBANK SYSTEM</p>
                        </div>
                        <div style="text-align: center; padding: 15px; background-color: #f5f5f5; border-radius: 0 0 8px 8px; color: #666; font-size: 12px;">
                            <p style="margin: 0;">Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                            <p style="margin: 0;">© ' . date('Y') . ' SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
                        </div>
                    </div>
                    ';
                    $mail->AltBody = "BUKTI TRANSFER SCHOBANK\n\n" .
                                    "No. Transaksi: $no_transaksi\n" .
                                    "Tanggal: " . date('d/m/Y H:i:s') . "\n" .
                                    "Rekening Asal: $no_rekening\n" .
                                    "Nama Pengirim: " . $user_data['nama'] . "\n" .
                                    "Rekening Tujuan: $rekening_tujuan\n" .
                                    "Nama Penerima: $rekening_tujuan_nama\n" .
                                    "Jumlah: Rp " . number_format($jumlah, 0, ',', '.') . "\n" .
                                    "Biaya: Rp 0,-\n" .
                                    "Status: BERHASIL\n" .
                                    "Saldo Akhir: Rp " . number_format($saldo_baru_pengirim, 0, ',', '.') . "\n\n" .
                                    "Penting: Jangan membagikan informasi rekening dan PIN Anda kepada siapapun.\n\n" .
                                    "Terima kasih telah menggunakan layanan SCHOBANK SYSTEM";
                    $mail->send();

                    if (!empty($rekening_tujuan_email)) {
                        $mail->clearAddresses();
                        $mail->addAddress($rekening_tujuan_email, $rekening_tujuan_nama);
                        $mail->Subject = 'Pemberitahuan Transfer Masuk - SCHOBANK';
                        $mail->Body = '
                        <div style="font-family: Poppins, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;">
                            <div style="text-align: center; padding: 15px; background-color: #0a2e5c; color: white; border-radius: 8px 8px 0 0;">
                                <h2 style="margin: 0; font-size: 24px;">SCHOBANK SYSTEM</h2>
                            </div>
                            <div style="padding: 20px;">
                                <h3 style="color: #0a2e5c; font-size: 20px; margin-bottom: 20px;">Pemberitahuan Transfer Masuk</h3>
                                <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Yth. Bapak/Ibu ' . htmlspecialchars($rekening_tujuan_nama) . ',</p>
                                <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Kami menginformasikan bahwa Anda telah menerima transfer dana. Berikut adalah rincian transaksi:</p>
                                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                                    <tr style="background-color: #f9f9f9;">
                                        <td style="padding: 10px; font-weight: bold; color: #333; width: 40%; border: 1px solid #e0e0e0;">No. Transaksi</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($no_transaksi) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Waktu Diterima</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . date('d/m/Y H:i:s') . '</td>
                                    </tr>
                                    <tr style="background-color: #f9f9f9;">
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Dari Rekening</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($no_rekening) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Nama Pengirim</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($user_data['nama']) . '</td>
                                    </tr>
                                    <tr style="background-color: #f9f9f9;">
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Rekening Tujuan</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">' . htmlspecialchars($rekening_tujuan) . '</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Jumlah Diterima</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">Rp ' . number_format($jumlah, 0, ',', '.') . '</td>
                                    </tr>
                                    <tr style="background-color: #f9f9f9;">
                                        <td style="padding: 10px; font-weight: bold; color: #333; border: 1px solid #e0e0e0;">Saldo Akhir</td>
                                        <td style="padding: 10px; color: #333; border: 1px solid #e0e0e0;">Rp ' . number_format($saldo_baru_penerima, 0, ',', '.') . '</td>
                                    </tr>
                                </table>
                                <p style="color: #d32f2f; font-size: 14px; font-weight: bold; margin-bottom: 20px;">Penting: Jika Anda tidak mengenali transaksi ini, silakan hubungi layanan pelanggan kami segera.</p>
                                <p style="color: #333; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">Jika Anda memiliki pertanyaan, silakan hubungi tim kami melalui email atau telepon yang tersedia di situs resmi kami.</p>
                                <p style="color: #333; font-size: 16px; line-height: 1.6;">Hormat kami,<br>Tim SCHOBANK SYSTEM</p>
                            </div>
                            <div style="text-align: center; padding: 15px; background-color: #f5f5f5; border-radius: 0 0 8px 8px; color: #666; font-size: 12px;">
                                <p style="margin: 0;">Email ini dibuat secara otomatis. Mohon tidak membalas email ini.</p>
                                <p style="margin: 0;">© ' . date('Y') . ' SCHOBANK SYSTEM. Hak cipta dilindungi.</p>
                            </div>
                        </div>
                        ';
                        $mail->AltBody = "PEMBERITAHUAN TRANSFER MASUK\n\n" .
                                        "Tanggal: " . date('d/m/Y H:i:s') . "\n" .
                                        "No. Transaksi: $no_transaksi\n" .
                                        "Dari Rekening: $no_rekening\n" .
                                        "Nama Pengirim: " . $user_data['nama'] . "\n" .
                                        "Rekening Tujuan: $rekening_tujuan\n" .
                                        "Jumlah: Rp " . number_format($jumlah, 0, ',', '.') . "\n" .
                                        "Saldo Anda saat ini: Rp " . number_format($saldo_baru_penerima, 0, ',', '.') . "\n\n" .
                                        "Penting: Jika Anda tidak mengenali transaksi ini, silakan hubungi layanan pelanggan kami.\n\n" .
                                        "Terima kasih telah menggunakan layanan SCHOBANK SYSTEM";
                        $mail->send();
                    }
                } catch (Exception $e) {
                    error_log('Email tidak dapat dikirim. Mailer Error: ' . $mail->ErrorInfo);
                }

                $success = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rekening_tujuan . " atas nama " . $rekening_tujuan_nama . " berhasil.";
                $transfer_amount = $jumlah;
                $transfer_rekening = $rekening_tujuan;
                $transfer_name = $rekening_tujuan_nama;
                $_SESSION['no_transaksi'] = $no_transaksi;
                $_SESSION['current_step'] = 5;
                $_SESSION['show_popup'] = true;
                $current_step = 5;

                error_log("Transfer successful for user_id $user_id, no_transaksi: $no_transaksi, moving to Step 5 for popup");

                unset($_SESSION['tujuan_data']);
                unset($_SESSION['transfer_amount']);
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Gagal memproses transfer: " . $e->getMessage();
                error_log("Transfer error for user_id $user_id: " . $e->getMessage());
                $_SESSION['current_step'] = 4;
                $current_step = 4;
            }
            break;

        case 'cancel':
            resetSessionAndPinAttempts($conn, $user_id);
            header("Location: dashboard.php");
            exit();

        case 'cancel_confirmation':
            $_SESSION['current_step'] = 3;
            $current_step = 3;
            break;

        case 'close_popup':
            resetSessionAndPinAttempts($conn, $user_id);
            $_SESSION['show_popup'] = false;
            $_SESSION['current_step'] = 1;
            unset($_SESSION['no_transaksi']);
            header("Location: transfer_pribadi.php");
            exit();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Transfer - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
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
            padding: 15px 20px;
            display: flex;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            transition: var(--transition);
            position: relative;
        }

        .top-nav h1 {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            margin: 0;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            line-height: 1;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            min-width: 40px;
            min-height: 40px;
            padding: 0;
            transition: var(--transition);
            line-height: 40px;
            aspect-ratio: 1/1;
            z-index: 1;
            margin-right: 10px;
            align-self: flex-start;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px) scale(1.05);
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
            animation: fadeInBanner 0.8s cubic-bezier(0.4, 0, 0.2, 1);
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

        .steps-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            animation: slideInSteps 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideInSteps {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            padding: 0 20px;
            transition: var(--transition);
        }

        .step-number {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #666;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
            transition: var(--transition);
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .step-text {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            color: #666;
            text-align: center;
            transition: var(--transition);
        }

        .step-connector {
            position: absolute;
            top: 17px;
            height: 3px;
            background-color: #e0e0e0;
            width: 100%;
            left: 50%;
            z-index: 0;
            transition: var(--transition);
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }

        .step-item.active .step-text {
            color: var(--primary-color);
            font-weight: 500;
        }

        .step-item.completed .step-number {
            background-color: var(--secondary-color);
            color: white;
        }

        .step-item.completed .step-connector {
            background-color: var(--secondary-color);
        }

        .transaction-container {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .transfer-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            flex: 1;
            min-width: 300px;
            max-width: 650px;
            transition: var(--transition);
        }

        .transfer-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .saldo-details {
            display: none;
        }

        .saldo-details.visible {
            display: block;
            animation: slideStep 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .transfer-form {
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

        .currency-input {
            position: relative;
        }

        .currency-prefix {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        input[type="text"],
        input.currency {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
            caret-color: var(--primary-color);
        }

        input.currency {
            padding-left: 45px !important;
        }

        input[type="text"]:focus,
        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        .pin-group {
            text-align: center;
            margin: 30px 0;
        }

        .pin-container {
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .pin-container.error {
            animation: shake 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            border: 1px solid #ddd;
            border-radius: 10px;
            background: white;
            transition: var(--transition);
            caret-color: var(--primary-color);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--primary-light);
            animation: bouncePin 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        button {
            position: relative;
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.4s, transform 0.4s, opacity 0.4s;
            min-width: 120px;
            min-height: 44px;
            line-height: 1;
            box-sizing: border-box;
            overflow: hidden;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            opacity: 0.8;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
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

        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .btn-cancel {
            background-color: var(--danger-color);
        }

        .btn-cancel:hover {
            background-color: #d32f2f;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            opacity: 1;
            transition: opacity 0.5s ease-out;
        }

        .alert.hide {
            opacity: 0;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-info {
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
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

        .btn-loading {
            position: relative;
            pointer-events: none;
            background-color: var(--primary-color);
        }

        .btn-loading span,
        .btn-loading i {
            opacity: 0;
            transition: opacity 0.2s ease;
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
            display: block;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

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
            animation: fadeInOverlay 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: pointer;
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
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
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
            animation: slideUpText 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content-confirm {
            margin: 20px 0;
            padding: 15px;
            background: #fafafa;
            border-radius: 10px;
            border: 1px solid #eee;
            animation: slideUpText 0.5s cubic-bezier(0.4, 0, 0.2, 1) 0.4s both;
        }

        .modal-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-row:hover {
            background: var(--primary-light);
        }

        .modal-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
            padding-right: 10px;
        }

        .modal-value {
            color: var(--text-primary);
            font-weight: 600;
            text-align: left;
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
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

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px 15px;
            }

            .top-nav h1 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .back-btn {
                width: 36px;
                height: 36px;
                min-width: 36px;
                min-height: 36px;
                line-height: 36px;
                padding: 0;
                aspect-ratio: 1/1;
                margin-right: 8px;
            }

            .main-content {
                padding: 15px;
            }

            .transfer-card {
                padding: 20px;
            }

            .steps-container {
                flex-direction: column;
                align-items: center;
                margin-bottom: 20px;
            }

            .step-item {
                flex-direction: row;
                width: 100%;
                margin-bottom: 10px;
            }

            .step-connector {
                display: none;
            }

            .step-number {
                margin-bottom: 0;
                margin-right: 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .step-text {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            }

            button {
                width: 100%;
                min-width: 100%;
                justify-content: center;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .confirm-buttons {
                flex-direction: column;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pin-container {
                gap: 8px;
            }

            .pin-input {
                width: 45px;
                height: 45px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }

            .modal-content-confirm {
                padding: 10px;
            }

            .modal-row {
                grid-template-columns: 1fr 1fr;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                padding: 8px 0;
            }

            .modal-label, .modal-value {
                padding: 0 5px;
            }
        }

        @media (max-width: 480px) {
            .back-btn {
                width: 32px;
                height: 32px;
                min-width: 32px;
                min-height: 32px;
                line-height: 32px;
                padding: 0;
                aspect-ratio: 1/1;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" id="xmarkButton" aria-label="Kembali">
            <i class="fas fa-times"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-exchange-alt"></i> Transfer Lokal</h2>
            <p>Kirim dana ke rekening lain dengan cepat dan aman</p>
        </div>

        <div class="steps-container">
            <div class="step-item <?= $current_step >= 1 ? 'active' : '' ?>" id="step1-indicator">
                <div class="step-number">1</div>
                <div class="step-text">Cek Rekening</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item <?= $current_step >= 2 ? ($current_step > 2 ? 'completed' : 'active') : '' ?>" id="step2-indicator">
                <div class="step-number">2</div>
                <div class="step-text">Detail Penerima</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item <?= $current_step >= 3 ? ($current_step > 3 ? 'completed' : 'active') : '' ?>" id="step3-indicator">
                <div class="step-number">3</div>
                <div class="step-text">Nominal & PIN</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item <?= $current_step >= 4 ? 'active' : '' ?>" id="step4-indicator">
                <div class="step-number">4</div>
                <div class="step-text">Konfirmasi</div>
            </div>
        </div>

        <div class="transaction-container">
            <!-- Step 1: Check Account -->
            <div class="transfer-card" id="checkAccountStep" style="display: <?= $current_step == 1 ? 'block' : 'none' ?>">
                <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening Tujuan</h3>
                <div id="alertContainer"></div>
                <form id="cekRekening" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="verify_account">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
                    <div>
                        <label for="rekening_tujuan">No Rekening:</label>
                        <input type="text" id="rekening_tujuan" name="rekening_tujuan" placeholder="REK123456" required autofocus inputmode="numeric">
                    </div>
                    <button type="submit" id="cekButton" class="btn-confirm">
                        <i class="fas fa-search"></i>
                        <span>Cek Rekening</span>
                    </button>
                </form>
            </div>

            <!-- Step 2: Recipient Details -->
            <div class="transfer-card saldo-details <?= $current_step == 2 ? 'visible' : '' ?>" id="recipientDetails" style="display: <?= $current_step == 2 ? 'block' : 'none' ?>">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Penerima</h3>
                <div id="alertContainerStep2"></div>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['no_rekening']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Penerima:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['nama']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value"><?= isset($tujuan_data) && $tujuan_data['nama_jurusan'] ? htmlspecialchars($tujuan_data['nama_jurusan']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value"><?= isset($tujuan_data) && $tujuan_data['nama_kelas'] ? htmlspecialchars($tujuan_data['nama_kelas']) : '-' ?></div>
                </div>
                <form id="formNext" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="input_amount">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
                    <div class="confirm-buttons">
                        <button type="submit" id="nextButton" class="btn-confirm">
                            <i class="fas fa-arrow-right"></i>
                            <span>Lanjut</span>
                        </button>
                        <button type="button" id="cancelDetails" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 3: Input Amount and PIN -->
            <div class="transfer-card saldo-details <?= $current_step == 3 ? 'visible' : '' ?>" id="amountPinDetails" style="display: <?= $current_step == 3 ? 'block' : 'none' ?>">
                <h3 class="section-title"><i class="fas fa-money-bill-wave"></i> Nominal & PIN</h3>
                <div id="alertContainerStep3"></div>
                <form id="formTransfer" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="confirm_transfer">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
                    <div class="currency-input">
                        <label for="jumlah">Jumlah Transfer (Rp):</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="1,000" required inputmode="numeric" value="<?= htmlspecialchars($input_jumlah) ?>">
                    </div>
                    <div class="pin-group">
                        <label>Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" data-index="1" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="2" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="3" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="4" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="5" inputmode="numeric" autocomplete="off">
                            <input type="text" class="pin-input" maxlength="1" data-index="6" inputmode="numeric" autocomplete="off">
                        </div>
                        <input type="hidden" id="pin" name="pin">
                    </div>
                    <div class="confirm-buttons">
                        <button type="submit" id="confirmTransfer" class="btn-confirm">
                            <i class="fas fa-check-circle"></i>
                            <span>Lanjut</span>
                        </button>
                        <button type="button" id="cancelTransfer" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 4: Confirmation -->
            <div class="transfer-card saldo-details <?= $current_step == 4 ? 'visible' : '' ?>" id="confirmationDetails" style="display: <?= $current_step == 4 ? 'block' : 'none' ?>">
                <h3 class="section-title"><i class="fas fa-check-circle"></i> Konfirmasi Transfer</h3>
                <div id="alertContainerStep4"></div>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['no_rekening']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Penerima:</div>
                    <div class="detail-value"><?= isset($tujuan_data) ? htmlspecialchars($tujuan_data['nama']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value"><?= isset($tujuan_data) && $tujuan_data['nama_jurusan'] ? htmlspecialchars($tujuan_data['nama_jurusan']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value"><?= isset($tujuan_data) && $tujuan_data['nama_kelas'] ? htmlspecialchars($tujuan_data['nama_kelas']) : '-' ?></div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jumlah Transfer:</div>
                    <div class="detail-value"><?= isset($_SESSION['transfer_amount']) ? formatRupiah($_SESSION['transfer_amount']) : '-' ?></div>
                </div>
                <form id="formProcess" class="transfer-form" method="POST" action="">
                    <input type="hidden" name="action" value="process_transfer">
                    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token']) ?>">
                    <div class="confirm-buttons">
                        <button type="submit" id="processButton" class="btn-confirm">
                            <i class="fas fa-check-circle"></i>
                            <span>Proses</span>
                        </button>
                        <button type="button" id="cancelConfirmation" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Step 5: Bukti Transfer Popup -->
        <?php if ($current_step == 5 && isset($_SESSION['show_popup']) && $_SESSION['show_popup'] === true): ?>
        <div class="success-overlay" id="confirmationPopup" style="display: flex;">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>TRANSFER BERHASIL</h3>
                <div class="modal-content-confirm">
                    <div class="modal-row">
                        <span class="modal-label">No. Transaksi</span>
                        <span class="modal-value"><?php echo isset($_SESSION['no_transaksi']) ? htmlspecialchars($_SESSION['no_transaksi']) : '-'; ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Waktu Transaksi</span>
                        <span class="modal-value"><?php echo date('d/m/Y H:i:s'); ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Rekening Sumber</span>
                        <span class="modal-value"><?php echo htmlspecialchars($no_rekening); ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Rekening Tujuan</span>
                        <span class="modal-value"><?php echo htmlspecialchars($transfer_rekening); ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Nama Penerima</span>
                        <span class="modal-value"><?php echo htmlspecialchars($transfer_name); ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Jumlah Transfer</span>
                        <span class="modal-value"><?php echo formatRupiah($transfer_amount); ?></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-label">Status</span>
                        <span class="modal-value">BERHASIL</span>
                    </div>
                </div>
                <p>Bukti Transaksi ini Sah dikeluarkan oleh SCHOBANK SYSTEM.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Prevent pinch-to-zoom and double-tap zoom
        document.addEventListener('touchstart', (event) => {
            if (event.touches.length > 1) event.preventDefault();
        }, { passive: false });

        document.addEventListener('gesturestart', (event) => event.preventDefault());
        document.addEventListener('wheel', (event) => {
            if (event.ctrlKey) event.preventDefault();
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', () => {
            // DOM Elements
            const cekForm = document.getElementById('cekRekening');
            const nextForm = document.getElementById('formNext');
            const transferForm = document.getElementById('formTransfer');
            const processForm = document.getElementById('formProcess');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const recipientDetails = document.getElementById('recipientDetails');
            const amountPinDetails = document.getElementById('amountPinDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');
            const confirmationPopup = document.getElementById('confirmationPopup');
            const alertContainer = document.getElementById('alertContainer');
            const alertContainerStep2 = document.getElementById('alertContainerStep2');
            const alertContainerStep3 = document.getElementById('alertContainerStep3');
            const alertContainerStep4 = document.getElementById('alertContainerStep4');
            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');
            const step4Indicator = document.getElementById('step4-indicator');
            const inputNoRek = document.getElementById('rekening_tujuan');
            const inputJumlah = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const pinContainer = document.querySelector('.pin-container');
            const hiddenPin = document.getElementById('pin');
            const cekButton = document.getElementById('cekButton');
            const nextButton = document.getElementById('nextButton');
            const confirmTransfer = document.getElementById('confirmTransfer');
            const processButton = document.getElementById('processButton');
            const cancelDetails = document.getElementById('cancelDetails');
            const cancelTransfer = document.getElementById('cancelTransfer');
            const cancelConfirmation = document.getElementById('cancelConfirmation');
            const xmarkButton = document.getElementById('xmarkButton');

            const prefix = 'REK';
            let isSubmitting = false;

            // Initialize input with prefix and clear PIN inputs
            if (inputNoRek) inputNoRek.value = prefix;
            clearPinInputs();

            // Debounce utility
            const debounce = (func, wait) => {
                let timeout;
                return (...args) => {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            };

            // Account number input handling
            if (inputNoRek) {
                inputNoRek.addEventListener('input', debounce((e) => {
                    let value = e.target.value;
                    if (!value.startsWith(prefix)) {
                        value = prefix + value.replace(prefix, '');
                    }
                    let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '').slice(0, 6);
                    e.target.value = prefix + userInput;
                }, 100));

                inputNoRek.addEventListener('keydown', (e) => {
                    const cursorPos = e.target.selectionStart;
                    if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                        e.preventDefault();
                    }
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });

                inputNoRek.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                    e.target.value = prefix + pastedData;
                });

                inputNoRek.addEventListener('focus', () => {
                    if (inputNoRek.value === prefix) {
                        inputNoRek.setSelectionRange(prefix.length, prefix.length);
                    }
                });

                inputNoRek.addEventListener('click', (e) => {
                    if (e.target.selectionStart < prefix.length) {
                        e.target.setSelectionRange(prefix.length, prefix.length);
                    }
                });
            }

            // Amount input formatting
            if (inputJumlah) {
                inputJumlah.addEventListener('input', debounce((e) => {
                    let value = e.target.value.replace(/[^0-9]/g, '');
                    if (value === '') {
                        e.target.value = '';
                        return;
                    }
                    e.target.value = formatRupiahInput(value);
                }, 100));

                inputJumlah.addEventListener('keydown', (e) => {
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });
            }

            // PIN input handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', () => {
                    const value = input.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        input.classList.add('filled');
                        input.dataset.originalValue = value;
                        input.value = '*';
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        input.classList.remove('filled');
                        input.dataset.originalValue = '';
                        input.value = '';
                    }
                    updateHiddenPin();
                });

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !input.dataset.originalValue && index > 0) {
                        pinInputs[index - 1].focus();
                        pinInputs[index - 1].classList.remove('filled');
                        pinInputs[index - 1].dataset.originalValue = '';
                        pinInputs[index - 1].value = '';
                        updateHiddenPin();
                    }
                    if (!/[0-9]/.test(e.key) && !['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(e.key)) {
                        e.preventDefault();
                    }
                });

                input.addEventListener('focus', () => {
                    if (input.classList.contains('filled')) {
                        input.value = input.dataset.originalValue || '';
                    }
                });

                input.addEventListener('blur', () => {
                    if (input.value && !input.classList.contains('filled')) {
                        const value = input.value.replace(/[^0-9]/g, '');
                        if (value) {
                            input.dataset.originalValue = value;
                            input.value = '*';
                            input.classList.add('filled');
                        }
                    }
                });
            });

            // Update step indicators
            const updateStepIndicators = (activeStep) => {
                step1Indicator.className = 'step-item';
                step2Indicator.className = 'step-item';
                step3Indicator.className = 'step-item';
                step4Indicator.className = 'step-item';

                if (activeStep >= 1) step1Indicator.className = activeStep === 1 ? 'step-item active' : 'step-item completed';
                if (activeStep >= 2) step2Indicator.className = activeStep === 2 ? 'step-item active' : 'step-item completed';
                if (activeStep >= 3) step3Indicator.className = activeStep === 3 ? 'step-item active' : 'step-item completed';
                if (activeStep >= 4) step4Indicator.className = activeStep === 4 ? 'step-item active' : 'step-item completed';

                checkAccountStep.style.display = activeStep === 1 ? 'block' : 'none';
                recipientDetails.style.display = activeStep === 2 ? 'block' : 'none';
                amountPinDetails.style.display = activeStep === 3 ? 'block' : 'none';
                confirmationDetails.style.display = activeStep === 4 ? 'block' : 'none';
                recipientDetails.classList.toggle('visible', activeStep === 2);
                amountPinDetails.classList.toggle('visible', activeStep === 3);
                confirmationDetails.classList.toggle('visible', activeStep === 4);
            };

            // Update hidden PIN
            const updateHiddenPin = () => {
                hiddenPin.value = Array.from(pinInputs)
                    .map((i) => i.dataset.originalValue || '')
                    .join('');
            };

            // Clear PIN inputs
            function clearPinInputs() {
                pinInputs.forEach((i) => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                hiddenPin.value = '';
                if (pinInputs[0]) pinInputs[0].focus();
            }

            // Shake PIN container
            const shakePinContainer = () => {
                pinContainer.classList.add('error');
                setTimeout(() => pinContainer.classList.remove('error'), 400);
            };

            // Reset form and redirect to dashboard
            const resetForm = () => {
                if (inputNoRek) inputNoRek.value = prefix;
                if (inputJumlah) inputJumlah.value = '';
                clearPinInputs();
                updateStepIndicators(1);
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'cancel';
                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = 'form_token';
                tokenInput.value = '<?= htmlspecialchars($_SESSION['form_token']) ?>';
                form.appendChild(actionInput);
                form.appendChild(tokenInput);
                document.body.appendChild(form);
                form.submit();
            };

            // Close popup and reset session
            const closePopup = () => {
                console.log('Closing popup and resetting session');
                if (confirmationPopup) {
                    confirmationPopup.style.display = 'none';
                } else {
                    console.warn('confirmationPopup not found during closePopup');
                }
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'close_popup';
                const tokenInput = document.createElement('input');
                tokenInput.type = 'hidden';
                tokenInput.name = 'form_token';
                tokenInput.value = '<?= htmlspecialchars($_SESSION['form_token']) ?>';
                form.appendChild(actionInput);
                form.appendChild(tokenInput);
                document.body.appendChild(form);
                form.submit();
            };

            // Form submission for checking account
            if (cekForm) {
                cekForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (isSubmitting) {
                        console.log('Submit dibatalkan: isSubmitting sudah true');
                        return;
                    }
                    const rekening = inputNoRek.value.trim();
                    if (rekening === prefix) {
                        showAlert('Silakan masukkan nomor rekening lengkap', 'error', 1);
                        return;
                    }
                    if (!/REK[0-9]{1,6}$/.test(rekening)) {
                        showAlert('Nomor rekening harus berupa REK diikuti 1-6 digit angka', 'error', 1);
                        return;
                    }
                    isSubmitting = true;
                    cekButton.classList.add('btn-loading');
                    cekButton.querySelector('i').style.display = 'none';
                    cekButton.querySelector('span').style.display = 'none';
                    console.log('Submitting cekRekening form');
                    cekForm.submit();
                });
            }

            // Form submission for next step
            if (nextForm) {
                nextForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (isSubmitting) {
                        console.log('Submit dibatalkan: isSubmitting sudah true');
                        return;
                    }
                    isSubmitting = true;
                    nextButton.classList.add('btn-loading');
                    nextButton.querySelector('i').style.display = 'none';
                    nextButton.querySelector('span').style.display = 'none';
                    console.log('Submitting formNext');
                    nextForm.submit();
                });
            }

            // Form submission for transfer confirmation
            if (transferForm) {
                transferForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (isSubmitting) {
                        console.log('Submit dibatalkan: isSubmitting sudah true');
                        return;
                    }
                    let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                    jumlah = parseFloat(jumlah);
                    const pin = hiddenPin.value;

                    if (!jumlah || jumlah < 1000) {
                        showAlert('Jumlah transfer minimal Rp 1,000', 'error', 3);
                        inputJumlah.focus();
                        return;
                    }
                    if (jumlah > <?= $saldo ?>) {
                        showAlert('Saldo tidak mencukupi', 'error', 3);
                        inputJumlah.focus();
                        return;
                    }
                    if (jumlah > <?= $remaining_limit ?>) {
                        showAlert('Melebihi batas transfer harian Rp 500,000', 'error', 3);
                        inputJumlah.focus();
                        return;
                    }
                    if (pin.length !== 6) {
                        shakePinContainer();
                        showAlert('PIN harus 6 digit', 'error', 3);
                        return;
                    }

                    isSubmitting = true;
                    confirmTransfer.classList.add('btn-loading');
                    confirmTransfer.querySelector('i').style.display = 'none';
                    confirmTransfer.querySelector('span').style.display = 'none';
                    console.log('Submitting transferForm with jumlah:', jumlah, 'pin:', pin);
                    transferForm.submit();
                });
            }

            // Form submission for processing transfer
            if (processForm) {
                processForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    if (isSubmitting) {
                        console.log('Submit dibatalkan: isSubmitting sudah true');
                        return;
                    }
                    isSubmitting = true;
                    processButton.classList.add('btn-loading');
                    processButton.querySelector('i').style.display = 'none';
                    processButton.querySelector('span').style.display = 'none';
                    console.log('Submitting processForm');
                    processForm.submit();
                });
            }

            if (cancelDetails) {
                cancelDetails.addEventListener('click', () => {
                    if (isSubmitting) return;
                    resetForm();
                });
            }

            if (cancelTransfer) {
                cancelTransfer.addEventListener('click', () => {
                    if (isSubmitting) return;
                    resetForm();
                });
            }

            if (cancelConfirmation) {
                cancelConfirmation.addEventListener('click', () => {
                    if (isSubmitting) return;
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'cancel_confirmation';
                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'form_token';
                    tokenInput.value = '<?= htmlspecialchars($_SESSION['form_token']) ?>';
                    form.appendChild(actionInput);
                    form.appendChild(tokenInput);
                    document.body.appendChild(form);
                    form.submit();
                });
            }

            if (xmarkButton) {
                xmarkButton.addEventListener('click', () => {
                    if (isSubmitting) return;
                    resetForm();
                });
            }

            if (confirmationPopup) {
                confirmationPopup.addEventListener('click', () => {
                    closePopup();
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && confirmationPopup && confirmationPopup.style.display === 'flex') {
                        closePopup();
                    }
                });
            } else {
                console.warn('confirmationPopup element not found on page load');
            }

            const formatRupiahInput = (value) => {
                value = value.replace(/^0+/, '');
                if (!value) return '';
                const number = parseInt(value);
                return new Intl.NumberFormat('id-ID').format(number);
            };

            const showAlert = (message, type, step) => {
                const container = step === 1 ? alertContainer :
                                 step === 2 ? alertContainerStep2 :
                                 step === 3 ? alertContainerStep3 :
                                 step === 4 ? alertContainerStep4 : null;
                if (!container) return;
                const alert = document.createElement('div');
                alert.className = `alert alert-${type}`;
                alert.innerHTML = `<i class="fas fa-${type === 'error' ? 'exclamation-circle' : 'check-circle'}"></i> ${message}`;
                container.innerHTML = '';
                container.appendChild(alert);
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 3000);
            };

            updateStepIndicators(<?= $current_step ?>);

            window.addEventListener('load', () => {
                isSubmitting = false;
                const buttons = [cekButton, nextButton, confirmTransfer, processButton];
                buttons.forEach(button => {
                    if (button) {
                        button.classList.remove('btn-loading');
                        button.querySelector('i').style.display = 'inline';
                        button.querySelector('span').style.display = 'inline';
                    }
                });
                clearPinInputs();
            });

            <?php if ($error): ?>
                showAlert('<?= htmlspecialchars($error) ?>', 'error', <?= $current_step ?>);
                <?php if ($current_step == 3): ?>
                    shakePinContainer();
                    clearPinInputs();
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($success): ?>
                showAlert('<?= htmlspecialchars($success) ?>', 'success', <?= $current_step ?>);
            <?php endif; ?>

            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>

<?php
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
$conn->close();
?>