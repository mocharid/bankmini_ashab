<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Set zona waktu ke WIB
date_default_timezone_set('Asia/Jakarta');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Function to determine account status
function getAccountStatus($user_data, $current_time) {
    if ($user_data['is_frozen']) {
        return 'dibekukan';
    } elseif ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] > $current_time) {
        return 'terblokir_sementara';
    } else {
        return 'aktif';
    }
}

// Fetch user details including status fields
$query = "SELECT u.nama, u.username, u.is_frozen, u.pin_block_until, j.nama_jurusan, k.nama_kelas 
          FROM users u 
          LEFT JOIN jurusan j ON u.jurusan_id = j.id 
          LEFT JOIN kelas k ON u.kelas_id = k.id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

// Determine account status
$current_time = date('Y-m-d H:i:s');
$account_status = getAccountStatus($user_data, $current_time);

// Fetch balance and rekening ID
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

// Cek PIN
$query_pin = "SELECT pin FROM users WHERE id = ?";
$stmt_pin = $conn->prepare($query_pin);
$stmt_pin->bind_param("i", $user_id);
$stmt_pin->execute();
$result_pin = $stmt_pin->get_result();
$user_pin_data = $result_pin->fetch_assoc();
$has_pin = !empty($user_pin_data['pin']);

// Cek email
$query_email = "SELECT email FROM users WHERE id = ?";
$stmt_email = $conn->prepare($query_email);
$stmt_email->bind_param("i", $user_id);
$stmt_email->execute();
$result_email = $stmt_email->get_result();
$user_email_data = $result_email->fetch_assoc();
$has_email = !empty($user_email_data['email']);

// Pagination settings
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Fetch transactions
$query = "SELECT 
            t.no_transaksi, 
            t.jenis_transaksi, 
            t.jumlah, 
            t.status, 
            t.created_at, 
            t.rekening_id, 
            t.rekening_tujuan_id,
            r1.no_rekening as rekening_asal,
            r2.no_rekening as rekening_tujuan,
            u1.nama as nama_pengirim,
            u2.nama as nama_penerima,
            p.nama as petugas_nama,
            DATE(t.created_at) as transaksi_tanggal
          FROM transaksi t
          LEFT JOIN rekening r1 ON t.rekening_id = r1.id
          LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
          LEFT JOIN users u1 ON r1.user_id = u1.id
          LEFT JOIN users u2 ON r2.user_id = u2.id
          LEFT JOIN users p ON t.petugas_id = p.id
          WHERE r1.user_id = ? OR r2.user_id = ?
          ORDER BY t.created_at DESC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("iiii", $user_id, $user_id, $limit, $offset);
$stmt->execute();
$transaksi_result = $stmt->get_result();

// Total transactions
$query_total = "SELECT COUNT(*) as total 
                FROM transaksi t 
                JOIN rekening r ON (t.rekening_id = r.id OR t.rekening_tujuan_id = r.id) 
                WHERE r.user_id = ?";
$stmt_total = $conn->prepare($query_total);
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_result = $stmt_total->get_result();
$total_row = $total_result->fetch_assoc();
$total_transaksi = $total_row['total'];
$total_pages = ceil($total_transaksi / $limit);

// Fetch notifications (all notifications)
$query = "SELECT * FROM notifications 
          WHERE user_id = ? 
          ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications_result = $stmt->get_result();

// Count unread notifications
$query_unread = "SELECT COUNT(*) as unread 
                 FROM notifications 
                 WHERE user_id = ? AND is_read = 0";
$stmt_unread = $conn->prepare($query_unread);
$stmt_unread->bind_param("i", $user_id);
$stmt_unread->execute();
$unread_result = $stmt_unread->get_result();
$unread_row = $unread_result->fetch_assoc();
$unread_notifications = $unread_row['unread'];

// Fungsi tanggal Indonesia
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 
        6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 
        11 => 'November', 12 => 'Desember'
    ];
    $hari = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 
        'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 
        'Saturday' => 'Sabtu'
    ];
    $tanggal = strtotime($tanggal);
    $hari_ini = $hari[date('l', $tanggal)];
    $tanggal_format = date('d', $tanggal);
    $bulan_ini = $bulan[date('n', $tanggal)];
    $tahun = date('Y', $tanggal);
    return "$hari_ini, $tanggal_format $bulan_ini $tahun";
}

// Fungsi detail transaksi
function tampilkanDetailTransaksi($transaksi, $conn) {
    $detail = '';
    if ($transaksi['jenis_transaksi'] == 'transfer') {
        if ($transaksi['rekening_tujuan_id'] == $GLOBALS['rekening_id']) {
            $detail = '<div class="transaction-detail">
                          <p><strong>Dari:</strong> '.htmlspecialchars($transaksi['nama_pengirim']).' ('.htmlspecialchars($transaksi['rekening_asal']).')</p>
                          <p><strong>Ke:</strong> Anda ('.htmlspecialchars($transaksi['rekening_tujuan']).')</p>
                       </div>';
        } else {
            $detail = '<div class="transaction-detail">
                          <p><strong>Dari:</strong> Anda ('.htmlspecialchars($transaksi['rekening_asal']).')</p>
                          <p><strong>Ke:</strong> '.htmlspecialchars($transaksi['nama_penerima']).' ('.htmlspecialchars($transaksi['rekening_tujuan']).')</p>
                       </div>';
        }
    } elseif ($transaksi['jenis_transaksi'] == 'setor' || $transaksi['jenis_transaksi'] == 'tarik') {
        $query = "SELECT petugas1_nama, petugas2_nama FROM petugas_tugas WHERE tanggal = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $transaksi['transaksi_tanggal']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $petugas_data = $result->fetch_assoc();
            $petugas1 = htmlspecialchars($petugas_data['petugas1_nama']);
            $petugas2 = htmlspecialchars($petugas_data['petugas2_nama']);
            $detail = '<div class="transaction-detail">
                          <p><strong>Ditangani oleh Warna : <span class="warna">Merah</span></strong> '.$petugas1.' dan '.$petugas2.'</p>
                       </div>';
        } else {
            $detail = '<div class="transaction-detail">
                          <p><strong>Ditangani oleh:</strong> '.($transaksi['petugas_nama'] ? htmlspecialchars($transaksi['petugas_nama']) : 'Sistem').'</p>
                       </div>';
        }
    }
    return $detail;
}

// Quotes penyemangat
$quotes = [
    "Tabungan kecil hari ini, langkah besar untuk masa depanmu!",
    "Setiap rupiah yang kamu simpan adalah investasi untuk impianmu.",
    "Menabung adalah cara terbaik untuk menghargai kerja kerasmu.",
    "Jangan tunda menabung, masa depanmu menunggu!",
    "Uang yang disimpan hari ini adalah senyuman di hari esok.",
    "Kebiasaan menabung membawa kebebasan finansial.",
    "Simpan sedikit hari ini, nikmati banyak di masa depan."
];
$day_index = date('w');
$daily_quote = $quotes[$day_index];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
                @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap');
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', 'DM Sans', 'Poppins', sans-serif;
        }

        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            font-size: clamp(14px, 2.5vw, 16px);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            overscroll-behavior: none;
        }

        .wrapper {
            flex: 1;
            padding-bottom: clamp(2rem, 4vw, 2.5rem);
        }

        :root {
            --font-size-xs: clamp(12px, 2vw, 14px);
            --font-size-sm: clamp(14px, 2.5vw, 16px);
            --font-size-md: clamp(16px, 3vw, 18px);
            --font-size-lg: clamp(18px, 3.5vw, 22px);
            --font-size-xl: clamp(24px, 4vw, 28px);
            --font-size-xxl: clamp(28px, 5vw, 36px);
        }

        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: clamp(1rem, 3vw, 1.5rem) clamp(1.5rem, 4vw, 2rem);
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 16px;
            position: relative;
            z-index: 100;
            margin-bottom: clamp(1.5rem, 3vw, 2rem);
        }

        .header h1 {
            font-size: var(--font-size-xl);
            font-weight: 600;
            letter-spacing: -0.5px;
        }

        .menu {
            display: flex;
            align-items: center;
            gap: clamp(1rem, 2vw, 1.5rem);
        }

        .menu a {
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: var(--font-size-sm);
            padding: clamp(0.5rem, 1.5vw, 0.6rem) clamp(1rem, 2vw, 1.2rem);
            border-radius: 50px;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .menu a:not(.notification-toggle):hover {
            background-color: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .notification-dropdown {
            position: relative;
        }

        .notification-toggle {
            position: relative;
            white-space: nowrap;
        }

        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: #ff5252;
            color: white;
            border-radius: 50%;
            width: clamp(18px, 2.5vw, 22px);
            height: clamp(18px, 2.5vw, 22px);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--font-size-xs);
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .notification-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background-color: white;
            min-width: clamp(280px, 40vw, 320px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 100;
            border-radius: 16px;
            overflow: hidden;
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid rgba(0, 0, 0, 0.05);
            -webkit-overflow-scrolling: touch;
        }

        .notification-item {
            display: block;
            padding: clamp(0.8rem, 2vw, 1rem) clamp(1rem, 2.5vw, 1.2rem);
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s, transform 0.3s ease;
            background-color: #ffffff;
            position: relative;
            overflow: hidden;
        }

        .notification-item.unread {
            background-color: #ebf5ff;
        }

        .notification-item:hover {
            background-color: #f5f7fa;
        }

        .notification-message {
            font-size: var(--font-size-sm);
            margin-bottom: 0.4rem;
            line-height: 1.4;
            color: #333;
        }

        .notification-time {
            font-size: var(--font-size-xs);
            color: #666;
        }

        .swipe-delete {
            position: absolute;
            right: 0;
            top: 0;
            height: 100%;
            width: clamp(60px, 15vw, 80px);
            background-color: #ef4444;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            font-size: var(--font-size-sm);
            cursor: pointer;
            border-radius: 0 8px 8px 0;
        }

        .notification-item.swiping .swipe-delete {
            transform: translateX(0);
        }

        .welcome-banner {
            background: linear-gradient(135deg, #ffffff 0%, #f5f7fa 100%);
            padding: clamp(1.5rem, 4vw, 2.5rem);
            margin: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 3vw, 1.5rem);
            border-radius: 24px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='%23bbdefb' fill-opacity='0.1' fill-rule='evenodd'/%3E%3C/svg%3E");
            opacity: 0.5;
            pointer-events: none;
        }

        .welcome-banner h2 {
            font-size: var(--font-size-xxl);
            font-weight: 700;
            margin-bottom: 0.8rem;
            background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -1px;
            line-height: 1.2;
        }

        .welcome-info, .date {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: var(--font-size-md);
            color: #555;
            margin-top: 0.6rem;
            font-weight: 500;
        }

        .welcome-info i, .date i {
            color: #1e3c72;
        }

        .quote-container {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            padding: clamp(1rem, 2.5vw, 1.5rem);
            margin: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 3vw, 1.5rem);
            border-radius: 16px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.05);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .quote-container::before {
            content: '“';
            position: absolute;
            top: 10px;
            left: 20px;
            font-size: var(--font-size-xxl);
            color: rgba(30, 60, 114, 0.2);
        }

        .quote-container::after {
            content: '”';
            position: absolute;
            bottom: 10px;
            right: 20px;
            font-size: var(--font-size-xxl);
            color: rgba(30, 60, 114, 0.2);
        }

        .quote-text {
            font-size: var(--font-size-md);
            font-weight: 500;
            color: #1e3c72;
            font-style: italic;
            line-height: 1.4;
            position: relative;
            z-index: 1;
        }

        .card-menu {
            display: flex;
            justify-content: center;
            gap: clamp(1rem, 3vw, 1.8rem);
            padding: clamp(1rem, 3vw, 1.5rem);
            max-width: 1200px;
            margin: clamp(1.5rem, 3vw, 2rem) auto;
            flex-wrap: wrap;
        }

        .card {
            background-color: white;
            border-radius: 24px;
            padding: clamp(1.5rem, 3.5vw, 2.2rem) clamp(1.2rem, 3vw, 1.8rem);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-align: center;
            cursor: pointer;
            border: 1px solid rgba(0, 0, 0, 0.03);
            position: relative;
            overflow: hidden;
            flex: 1 1 clamp(250px, 30vw, 280px);
        }

        .card.disabled {
            background-color: #f5f5f5;
            cursor: not-allowed;
            opacity: 0.7;
        }

        .card.disabled:hover {
            transform: none;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
        }

        .card.disabled .card-icon i {
            background: none;
            -webkit-text-fill-color: #666;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(30, 60, 114, 0.1) 0%, rgba(42, 82, 152, 0.05) 100%);
            transform: scaleX(0);
            transform-origin: 0 50%;
            transition: transform 0.5s ease-out;
            z-index: -1;
        }

        .card:hover {
            transform: translateY(-12px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            border-color: rgba(30, 60, 114, 0.1);
        }

        .card:hover::before {
            transform: scaleX(1);
        }

        .card-icon {
            margin-bottom: 1.5rem;
            position: relative;
            display: inline-block;
        }

        .card i {
            font-size: clamp(2rem, 5vw, 2.8rem);
            color: #1e3c72;
            background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            z-index: 2;
        }

        .card-icon::after {
            content: '';
            position: absolute;
            width: clamp(50px, 10vw, 60px);
            height: clamp(50px, 10vw, 60px);
            background-color: rgba(30, 60, 114, 0.1);
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1;
            transition: all 0.3s ease;
        }

        .card:hover .card-icon::after {
            width: clamp(60px, 12vw, 70px);
            height: clamp(60px, 12vw, 70px);
            background-color: rgba(30, 60, 114, 0.15);
        }

        .card h3 {
            font-size: var(--font-size-lg);
            margin-bottom: 0.8rem;
            font-weight: 600;
            letter-spacing: -0.5px;
            position: relative;
            display: inline-block;
        }

        .card h3::after {
            content: '';
            position: absolute;
            width: 40%;
            height: 3px;
            background: linear-gradient(90deg, transparent, #1e3c72, transparent);
            bottom: -8px;
            left: 30%;
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .card:hover h3::after {
            width: 80%;
            left: 10%;
        }

        .card p {
            font-size: var(--font-size-sm);
            color: #666;
            font-weight: 400;
            margin-top: 1rem;
        }

        .stats-container {
            padding: clamp(1rem, 3vw, 1.5rem);
            margin: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 3vw, 1.5rem);
        }

        .stat-box {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-radius: 24px;
            padding: clamp(1.5rem, 4vw, 2.5rem);
            position: relative;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(42, 82, 152, 0.2);
        }

        .bubble-1, .bubble-2, .bubble-3, .bubble-4 {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
        }

        .bubble-1 {
            width: clamp(80px, 15vw, 120px);
            height: clamp(80px, 15vw, 120px);
            bottom: -60px;
            right: 10%;
            animation: float 12s infinite ease-in-out;
        }

        .bubble-2 {
            width: clamp(60px, 10vw, 80px);
            height: clamp(60px, 10vw, 80px);
            top: 30px;
            right: 25%;
            animation: float 9s infinite ease-in-out 1s;
        }

        .bubble-3 {
            width: clamp(40px, 8vw, 50px);
            height: clamp(40px, 8vw, 50px);
            bottom: 60px;
            left: 15%;
            animation: float 10s infinite ease-in-out 2s;
        }

        .bubble-4 {
            width: clamp(70px, 12vw, 100px);
            height: clamp(70px, 12vw, 100px);
            top: -40px;
            left: 25%;
            animation: float 13s infinite ease-in-out 3s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-20px) scale(1.05); }
        }

        .stat-title {
            font-size: var(--font-size-md);
            margin-bottom: 1.2rem;
            font-weight: 500;
            z-index: 1;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-title .toggle-balance {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: clamp(30px, 6vw, 36px);
            height: clamp(30px, 6vw, 36px);
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .stat-title .toggle-balance:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .balance-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.8rem;
            z-index: 1;
            transition: all 0.3s ease;
            flex-direction: column;
            align-items: center;
        }

        .balance-hidden .balance-box {
            filter: blur(8px);
        }

        .balance-hidden .blur-overlay {
            opacity: 1;
        }

        .balance-box {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--font-size-xxl);
            font-weight: 700;
            letter-spacing: -1px;
            transition: all 0.3s ease;
        }

        .balance {
            display: inline-block;
            width: clamp(150px, 25vw, 200px);
            text-align: center;
        }

        .blur-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: var(--font-size-lg);
            font-weight: 600;
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }

        .currency {
            font-size: var(--font-size-xl);
            margin-right: 0.05rem;
            opacity: 0.9;
            font-weight: 500;
        }

        .stat-info {
            display: flex;
            justify-content: center;
            z-index: 1;
        }

        .rekening-info {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background-color: rgba(255, 255, 255, 0.2);
            padding: clamp(0.6rem, 1.5vw, 0.8rem) clamp(1rem, 2vw, 1.4rem);
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        .rekening-info:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-3px);
        }

        .copy-icon {
            font-size: var(--font-size-sm);
        }

        .tooltip {
            position: relative;
        }

        .tooltiptext {
            visibility: hidden;
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            text-align: center;
            border-radius: 8px;
            padding: clamp(0.4rem, 1vw, 0.5rem) clamp(0.6rem, 1.5vw, 0.8rem);
            font-size: var(--font-size-xs);
            font-weight: 500;
            letter-spacing: 0.3px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        .transactions-container {
            width: clamp(90%, 95vw, 95%);
            max-width: 1200px;
            margin: clamp(1.5rem, 3vw, 2rem) auto;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            background-color: #fff;
            overflow: hidden;
        }

        .transactions-header {
            background-color: #f5f5f5;
            padding: clamp(12px, 2.5vw, 15px) clamp(15px, 3vw, 20px);
            border-bottom: 1px solid #eaeaea;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .transactions-title {
            margin: 0;
            font-size: var(--font-size-lg);
            color: #333;
            font-weight: 600;
        }

        .toggle-transactions {
            background: #1e3c72;
            color: white;
            border: none;
            padding: clamp(6px, 1.5vw, 8px) clamp(12px, 2.5vw, 15px);
            border-radius: 50px;
            cursor: pointer;
            font-size: var(--font-size-sm);
            transition: background-color 0.3s ease;
        }

        .toggle-transactions:hover {
            background-color: #2a5298;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            padding: clamp(10px, 2vw, 15px);
            display: none;
        }

        .table-container.active {
            display: block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: clamp(10px, 2vw, 15px) 0;
        }

        thead th {
            background-color: #f9f9f9;
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 15px);
            text-align: left;
            font-weight: 600;
            color: #555;
            border-bottom: 2px solid #eaeaea;
            font-size: var(--font-size-sm);
        }

        tbody td {
            padding: clamp(10px, 2vw, 12px) clamp(12px, 2.5vw, 15px);
            border-bottom: 1px solid #eaeaea;
            color: #333;
            font-size: var(--font-size-sm);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background-color: rgba(245, 247, 250, 0.7);
        }

        .amount-positive {
            color: #10b981;
            font-weight: 600;
        }

        .amount-negative {
            color: #ef4444;
            font-weight: 600;
        }

        .status-badge {
            padding: clamp(3px, 1vw, 4px) clamp(6px, 1.5vw, 8px);
            border-radius: 4px;
            font-size: var(--font-size-xs);
            font-weight: 600;
        }

        .status-sukses {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-gagal {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .empty-state {
            text-align: center;
            padding: clamp(30px, 5vw, 40px) clamp(15px, 3vw, 20px);
        }

        .empty-state i {
            display: block;
            font-size: clamp(36px, 8vw, 48px);
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .empty-state p {
            margin: 0;
            color: #6b7280;
            font-size: var(--font-size-md);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: clamp(0.4rem, 1vw, 0.6rem);
            padding: clamp(1.2rem, 3vw, 1.8rem);
            display: none;
            margin: clamp(1.5rem, 3vw, 2rem) auto;
        }

        .pagination.active {
            display: flex;
        }

        .pagination-link {
            display: inline-block;
            padding: clamp(0.5rem, 1.5vw, 0.6rem) clamp(0.8rem, 2vw, 1rem);
            border-radius: 50px;
            text-decoration: none;
            color: #555;
            background-color: #f5f7fa;
            transition: all 0.2s;
            font-weight: 500;
            font-size: var(--font-size-sm);
        }

        .pagination-link:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .pagination-link.active {
            background-color: #1e3c72;
            color: white;
            box-shadow: 0 4px 10px rgba(30, 60, 114, 0.3);
        }

        .transaksi-setor {
            background-color: rgba(236, 253, 245, 0.4);
        }

        .transaksi-tarik {
            background-color: rgba(254, 242, 242, 0.4);
        }

        .transaksi-transfer {
            background-color: rgba(239, 246, 255, 0.4);
        }

        .pin-alert {
            margin: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 3vw, 1.5rem);
            padding: 0;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background-color: #fff3cd;
            border-left: 5px solid #ffc107;
            overflow: hidden;
            animation: fadeIn 0.5s;
        }

        .pin-alert-content {
            display: flex;
            align-items: center;
            padding: clamp(12px, 2.5vw, 15px) clamp(15px, 3vw, 20px);
        }

        .pin-alert i.fas.fa-exclamation-triangle {
            font-size: clamp(20px, 4vw, 24px);
            color: #ff9800;
            margin-right: 15px;
        }

        .pin-alert-text {
            flex: 1;
            color: #856404;
            font-size: var(--font-size-sm);
        }

        .pin-alert-button {
            display: inline-block;
            margin-left: 10px;
            padding: clamp(4px, 1vw, 5px) clamp(12px, 2vw, 15px);
            background-color: #ffc107;
            color: #212529;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s;
            font-size: var(--font-size-sm);
        }

        .pin-alert-button:hover {
            background-color: #e0a800;
        }

        .pin-alert-close {
            background: none;
            border: none;
            color: #856404;
            cursor: pointer;
            font-size: clamp(14px, 2.5vw, 16px);
            padding: 0 10px;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .menu-title {
            text-align: center;
            font-size: var(--font-size-xl);
            font-weight: 700;
            margin: clamp(1.5rem, 4vw, 2rem) clamp(1rem, 3vw, 1.5rem);
            color: #1e3c72;
            letter-spacing: -0.8px;
            background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .menu-title::after {
            content: '';
            display: block;
            width: clamp(80px, 15vw, 100px);
            height: 4px;
            background: linear-gradient(90deg, transparent, #1e3c72, transparent);
            border-radius: 2px;
            margin: 0.5rem auto;
        }

        .transaction-detail {
            padding: clamp(8px, 2vw, 10px);
            background-color: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            margin-top: 8px;
            font-size: var(--font-size-sm);
        }

        .transaction-detail p {
            margin: 5px 0;
            color: #555;
        }

        .detail-toggle {
            padding: clamp(5px, 1.5vw, 6px) clamp(10px, 2vw, 12px);
            background-color: #1e3c72;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: var(--font-size-sm);
            transition: background-color 0.2s;
        }

        .detail-toggle:hover {
            background-color: #2a5298;
        }

        .hidden-detail {
            display: none;
        }

        .footer {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            text-align: center;
            padding: clamp(1rem, 3vw, 1.5rem);
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
            font-size: var(--font-size-sm);
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
            margin-top: clamp(2rem, 4vw, 2.5rem);
        }

        .footer::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            clip-path: circle(20% at 10% 10%);
            opacity: 0.3;
        }

        .footer::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            clip-path: circle(15% at 90% 90%);
            opacity: 0.3;
        }

        .footer span {
            position: relative;
            z-index: 1;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 1024px) {
            .card-menu {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }

            .notification-dropdown-content {
                min-width: clamp(250px, 80vw, 300px);
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: clamp(1rem, 2.5vw, 1.2rem);
                margin-bottom: clamp(1rem, 2vw, 1.5rem);
            }

            .menu {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .notification-dropdown-content {
                position: fixed;
                left: 50%;
                transform: translateX(-50%);
                top: clamp(120px, 20vw, 140px);
                width: clamp(80%, 90vw, 90%);
                max-height: 300px;
                z-index: 999;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            .welcome-banner {
                margin: clamp(1rem, 2vw, 1.5rem);
                padding: clamp(1.2rem, 3vw, 1.8rem);
            }

            .welcome-banner h2 {
                font-size: var(--font-size-xl);
            }

            .card-menu {
                padding: clamp(0.8rem, 2vw, 1rem);
                grid-template-columns: 1fr;
                margin: clamp(1rem, 2vw, 1.5rem) auto;
            }

            .stats-container {
                padding: clamp(0.8rem, 2vw, 1rem);
                margin: clamp(1rem, 2vw, 1.5rem);
            }

            .transactions-container {
                width: clamp(88%, 92vw, 92%);
                margin: clamp(1rem, 2vw, 1.5rem) auto;
            }

            .balance-box {
                font-size: var(--font-size-xl);
            }

            .currency {
                font-size: var(--font-size-lg);
                margin-right: 0.05rem;
            }

            table {
                min-width: clamp(600px, 100vw, 650px);
            }

            thead th, tbody td {
                padding: clamp(8px, 1.5vw, 10px);
                font-size: var(--font-size-xs);
            }

            .status-badge {
                padding: clamp(2px, 0.8vw, 3px) clamp(4px, 1vw, 6px);
                font-size: calc(var(--font-size-xs) * 0.9);
            }
        }

        @media (max-width: 576px) {
            .header h1 {
                font-size: var(--font-size-lg);
            }

            .menu a {
                font-size: var(--font-size-xs);
                padding: clamp(0.4rem, 1vw, 0.5rem) clamp(0.8rem, 1.5vw, 1rem);
            }

            .welcome-banner h2 {
                font-size: var(--font-size-lg);
            }

            .welcome-info, .date {
                font-size: var(--font-size-sm);
            }

            .card {
                flex: 1 1 100%;
            }

            .card h3 {
                font-size: var(--font-size-md);
            }

            .card p {
                font-size: var(--font-size-xs);
            }

            .transactions-container {
                width: clamp(85%, 90vw, 90%);
                margin: clamp(1rem, 2vw, 1.5rem) auto;
            }

            .transactions-title {
                font-size: var(--font-size-md);
            }

            thead th, tbody td {
                padding: clamp(6px, 1.2vw, 8px) clamp(4px, 1vw, 6px);
                font-size: calc(var(--font-size-xs) * 0.95);
            }

            .table-container {
                padding: clamp(8px, 1.5vw, 10px);
            }

            .status-badge {
                font-size: calc(var(--font-size-xs) * 0.85);
                padding: clamp(2px, 0.5vw, 3px) clamp(3px, 0.8vw, 4px);
            }

            .notification-dropdown-content {
                width: clamp(85%, 95vw, 95%);
                top: clamp(100px, 18vw, 120px);
                max-height: 300px;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            }

            .notification-message {
                font-size: var(--font-size-xs);
            }

            .notification-time {
                font-size: calc(var(--font-size-xs) * 0.9);
            }

            .swipe-delete {
                width: clamp(50px, 12vw, 60px);
                font-size: var(--font-size-xs);
            }
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(30, 60, 114, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(30, 60, 114, 0); }
            100% { box-shadow: 0 0 0 0 rgba(30, 60, 114, 0); }
        }

        .rekening-info {
            animation: pulse 2s infinite;
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(25px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            animation: cardEntrance 0.5s ease forwards;
            animation-delay: calc(0.1s * var(--i, 0));
        }

        .card:nth-child(1) { --i: 1; }
        .card:nth-child(2) { --i: 2; }
        .card:nth-child(3) { --i: 3; }

        .header, .welcome-banner, .stats-container, .transactions-container {
            transition: all 0.3s ease;
        }
        /* CSS untuk status akun */
        .status-aktif {
            color: #28a745;
            font-weight: 500;
        }
        .status-dibekukan {
            color: #dc3545;
            font-weight: 500;
        }
        .status-terblokir_sementara {
            color: #ffc107;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Header -->
        <div class="header">
            <h1>Rekening Digital by SchoBank</h1>
            <div class="menu">
                <div class="notification-dropdown">
                    <a href="#" class="notification-toggle">
                        <i class="fas fa-bell"></i> Notifikasi
                        <?php if ($unread_notifications > 0): ?>
                            <span class="notification-badge"><?= $unread_notifications ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="notification-dropdown-content">
                        <?php if ($notifications_result->num_rows > 0): ?>
                            <?php while ($row = $notifications_result->fetch_assoc()): ?>
                                <div class="notification-item <?= $row['is_read'] ? '' : 'unread' ?>" data-id="<?= $row['id'] ?>">
                                    <a href="#" class="notification-link">
                                        <div class="notification-message"><?= htmlspecialchars($row['message']) ?></div>
                                        <div class="notification-time">
                                            <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                        </div>
                                    </a>
                                    <button class="swipe-delete">Hapus</button>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="notification-item">
                                <div class="notification-message">Tidak ada notifikasi baru</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="../../logout.php">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Hai, <?= htmlspecialchars($user_data['nama']) ?>!</h2>
            <div class="welcome-info">
                <i class="fas fa-user-graduate"></i> <?= htmlspecialchars($user_data['nama_kelas'] . ' - ' . $user_data['nama_jurusan']) ?>
            </div>
            <div class="welcome-info">
                <i class="fas fa-shield-alt"></i> Status Akun: 
                <span class="status-<?= strtolower($account_status) ?>">
                    <?= ucfirst(str_replace('_', ' ', $account_status)) ?>
                </span>
            </div>
            <div class="date">
                <i class="far fa-calendar-alt"></i> <?= tanggal_indonesia(date('Y-m-d')) ?>
            </div>
        </div>

        <!-- Quote Container -->
        <div class="quote-container">
            <div class="quote-text"><?= htmlspecialchars($daily_quote) ?></div>
        </div>

        <!-- PIN Alert -->
        <?php if (!$has_pin): ?>
        <div class="pin-alert">
            <div class="pin-alert-content">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="pin-alert-text">
                    <strong>Perhatian!</strong> Anda belum mengatur PIN keamanan.  
                    <a href="profil.php#account-tab" class="pin-alert-button">Atur PIN Sekarang</a>
                </div>
                <button class="pin-alert-close" onclick="this.parentElement.parentElement.style.display='none';">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$has_email): ?>
        <div class="pin-alert">
            <div class="pin-alert-content">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="pin-alert-text">
                    <strong>Perhatian!</strong> Anda belum menambahkan email.  
                    <a href="profil.php#account-tab" class="pin-alert-button">Tambahkan Email Sekarang</a>
                </div>
                <button class="pin-alert-close" onclick="this.parentElement.parentElement.style.display='none';">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Menu Utama -->
        <h2 class="menu-title">Menu Utama</h2>

        <!-- Card Menu -->
        <div class="card-menu">
            <div class="card" onclick="window.location.href='profil.php'">
                <div class="card-icon">
                    <i class="fas fa-user-cog"></i>
                </div>
                <h3>Profil</h3>
                <p>Kelola informasi akun Anda dengan mudah dan aman</p>
            </div>
            <div class="card" onclick="window.location.href='cek_mutasi.php'">
                <div class="card-icon">
                    <i class="fas fa-list-alt"></i>
                </div>
                <h3>Cek Mutasi</h3>
                <p>Pantau dan lihat semua riwayat transaksi terbaru Anda</p>
            </div>
            <div class="card <?php echo !$has_pin ? 'disabled' : ''; ?>" 
                 <?php echo $has_pin ? 'onclick="window.location.href=\'transfer_pribadi.php\'"' : 'onclick="showPinAlert()" title="Harap atur PIN terlebih dahulu"'; ?>>
                <div class="card-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <h3>Transfer Lokal</h3>
                <p>Kirim uang dengan cepat dan aman ke rekening Lokal</p>
            </div>
        </div>

        <!-- Stats Container -->
        <div class="stats-container">
            <div class="stat-box">
                <div class="bubble-1"></div>
                <div class="bubble-2"></div>
                <div class="bubble-3"></div>
                <div class="bubble-4"></div>
                <div class="stat-title">
                    Saldo Anda
                    <button class="toggle-balance" id="toggleBalance" title="Sembunyikan/Tampilkan Saldo">
                        <i class="fas fa-eye-slash" id="eyeIcon"></i>
                    </button>
                </div>
                <div class="balance-container" id="balanceContainer">
                    <div class="balance-box">
                        <span class="currency">Rp</span>
                        <span class="balance" id="balanceCounter"><?= number_format($saldo, 0, ',', '.') ?></span>
                    </div>
                    <div class="blur-overlay">
                        <span>Rp.*****</span>
                    </div>
                </div>
                <div class="stat-info">
                    <div class="rekening-info tooltip" onclick="copyToClipboard('<?= $no_rekening ?>')">
                        <i class="fas fa-credit-card"></i>
                        <span id="rekening-text">No. Rekening: <?= $no_rekening ?></span>
                        <i class="fas fa-copy copy-icon"></i>
                        <span class="tooltiptext" id="copy-tooltip">Klik untuk menyalin</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Container -->
        <div class="transactions-container">
            <div class="transactions-header">
                <h3 class="transactions-title">Riwayat Transaksi Terakhir</h3>
                <button class="toggle-transactions" id="toggleTransactions">Sembunyikan</button>
            </div>
            <div class="table-container active" id="tableContainer">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>No Transaksi</th>
                            <th>Tanggal</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transaksi_result->num_rows > 0): ?>
                            <?php 
                                $items_per_page = 5; // 5 item per halaman
                                $nomor_urut = ($page - 1) * $items_per_page + 1; 
                            ?>
                            <?php while ($row = $transaksi_result->fetch_assoc()): 
                                $jenis = strtolower($row['jenis_transaksi']);
                                $is_transfer_masuk = ($jenis == 'transfer' && $row['rekening_tujuan_id'] == $rekening_id);
                                $amount_class = ($jenis == 'setor' || $is_transfer_masuk) ? 'amount-positive' : 'amount-negative';
                                $row_class = 'transaksi-' . $jenis;
                            ?>
                                <tr class="<?= $row_class ?>">
                                    <td class="nomor-urut"><?= $nomor_urut++ ?></td>
                                    <td><?= htmlspecialchars($row['no_transaksi']) ?></td>
                                    <td><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                                    <td>
                                        <?php if ($jenis == 'setor'): ?>
                                            <i class="fas fa-arrow-down text-green-600 mr-2"></i> Setor
                                        <?php elseif ($jenis == 'tarik'): ?>
                                            <i class="fas fa-arrow-up text-red-600 mr-2"></i> Tarik
                                        <?php elseif ($jenis == 'transfer'): ?>
                                            <?php if ($is_transfer_masuk): ?>
                                                <i class="fas fa-arrow-down text-green-600 mr-2"></i> Transfer Masuk
                                            <?php else: ?>
                                                <i class="fas fa-arrow-up text-red-600 mr-2"></i> Transfer Keluar
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?= $amount_class ?>">
                                        <?= ($jenis == 'setor' || $is_transfer_masuk) ? '+' : '-' ?> Rp <?= number_format($row['jumlah'], 0, ',', '.') ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= strtolower($row['status']) ?>">
                                            <?php 
                                                $status_display = ($row['status'] === 'approved') ? 'Berhasil' : ucfirst($row['status']);
                                                echo $status_display;
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-receipt"></i>
                                    <p>Belum ada riwayat transaksi.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="pagination active">
            <?php if ($page > 1): ?>
                <a href="javascript:void(0);" onclick="loadPage(<?= $page - 1 ?>)" class="pagination-link">« Sebelumnya</a>
            <?php endif; ?>
            <?php if ($page < $total_pages): ?>
                <a href="javascript:void(0);" onclick="loadPage(<?= $page + 1 ?>)" class="pagination-link">Selanjutnya »</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <span>Kerja Praktek - Universitas Suryakancana Cianjur 2025</span>
    </div>

    <script>
        // Toggle saldo visibility
        document.addEventListener('DOMContentLoaded', function() {
            const toggleBtn = document.getElementById('toggleBalance');
            const balanceContainer = document.getElementById('balanceContainer');
            const eyeIcon = document.getElementById('eyeIcon');
            
            toggleBtn.addEventListener('click', function() {
                balanceContainer.classList.toggle('balance-hidden');
                eyeIcon.classList.toggle('fa-eye-slash');
                eyeIcon.classList.toggle('fa-eye');
            });

            // Animate balance counter
            const balanceElement = document.getElementById('balanceCounter');
            const finalBalance = parseInt(balanceElement.textContent.replace(/[,.]/g, '')) || 0;
            let currentBalance = 0;
            const increment = finalBalance / 100;
            const duration = 2000;
            const stepTime = duration / 100;

            function updateBalance() {
                if (currentBalance < finalBalance) {
                    currentBalance += increment;
                    if (currentBalance > finalBalance) currentBalance = finalBalance;
                    balanceElement.textContent = Math.floor(currentBalance).toLocaleString('id-ID');
                    setTimeout(updateBalance, stepTime);
                } else {
                    balanceElement.textContent = finalBalance.toLocaleString('id-ID');
                }
            }
            updateBalance();

            // Toggle transactions visibility
            const toggleTransactionsBtn = document.getElementById('toggleTransactions');
            const tableContainer = document.getElementById('tableContainer');
            const pagination = document.querySelector('.pagination');
            
            toggleTransactionsBtn.addEventListener('click', function() {
                tableContainer.classList.toggle('active');
                pagination.classList.toggle('active');
                toggleTransactionsBtn.textContent = tableContainer.classList.contains('active') ? 'Sembunyikan' : 'Tampilkan';
            });
        });

        // Copy rekening number
        function copyToClipboard(text) {
            const tempInput = document.createElement("input");
            tempInput.value = text;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand("copy");
            document.body.removeChild(tempInput);
            const tooltip = document.getElementById("copy-tooltip");
            tooltip.innerHTML = "Tersalin!";
            setTimeout(() => tooltip.innerHTML = "Klik untuk menyalin", 2000);
        }

        // Show PIN alert
        function showPinAlert() {
            alert("Harap atur PIN terlebih dahulu di menu Profil sebelum menggunakan fitur Transfer Lokal.");
        }

        // Close dropdown on scroll
        window.addEventListener('scroll', () => {
            const dropdownContent = document.querySelector('.notification-dropdown-content');
            if (dropdownContent.style.display === 'block') {
                dropdownContent.style.display = 'none';
            }
        });

        // Initialize swipe to delete and click to read
        function initializeSwipe() {
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                let startX, currentX;
                let isSwiping = false;
                let isDragging = false;

                // Touch events
                item.addEventListener('touchstart', (e) => {
                    startX = e.touches[0].clientX;
                    isSwiping = true;
                    item.classList.add('swiping');
                }, { passive: true });

                item.addEventListener('touchmove', (e) => {
                    if (!isSwiping) return;
                    currentX = e.touches[0].clientX;
                    const diff = startX - currentX;
                    if (diff > 0) {
                        item.style.transform = `translateX(-${Math.min(diff, 80)}px)`;
                    }
                }, { passive: true });

                item.addEventListener('touchend', () => {
                    isSwiping = false;
                    item.classList.remove('swiping');
                    const diff = startX - currentX;
                    if (diff > 50) {
                        item.style.transform = 'translateX(-80px)';
                        item.querySelector('.swipe-delete').style.transform = 'translateX(0)';
                    } else {
                        item.style.transform = 'translateX(0)';
                        item.querySelector('.swipe-delete').style.transform = 'translateX(100%)';
                    }
                });

                // Mouse events
                item.addEventListener('mousedown', (e) => {
                    startX = e.clientX;
                    isDragging = true;
                    item.classList.add('swiping');
                });

                item.addEventListener('mousemove', (e) => {
                    if (!isDragging) return;
                    currentX = e.clientX;
                    const diff = startX - currentX;
                    if (diff > 0) {
                        item.style.transform = `translateX(-${Math.min(diff, 80)}px)`;
                    }
                });

                item.addEventListener('mouseup', () => {
                    isDragging = false;
                    item.classList.remove('swiping');
                    const diff = startX - currentX;
                    if (diff > 50) {
                        item.style.transform = 'translateX(-80px)';
                        item.querySelector('.swipe-delete').style.transform = 'translateX(0)';
                    } else {
                        item.style.transform = 'translateX(0)';
                        item.querySelector('.swipe-delete').style.transform = 'translateX(100%)';
                    }
                });

                item.addEventListener('mouseleave', () => {
                    if (isDragging) {
                        isDragging = false;
                        item.classList.remove('swiping');
                        item.style.transform = 'translateX(0)';
                        item.querySelector('.swipe-delete').style.transform = 'translateX(100%)';
                    }
                });

                // Delete button
                const deleteButton = item.querySelector('.swipe-delete');
                deleteButton.addEventListener('click', (e) => {
                    e.preventDefault();
                    deleteNotification(item.dataset.id, item);
                });

                // Click to mark as read
                const notificationLink = item.querySelector('.notification-link');
                notificationLink.addEventListener('click', (e) => {
                    e.preventDefault();
                    if (item.classList.contains('unread')) {
                        markNotificationAsRead(item.dataset.id, item);
                    }
                });
            });
        }

        // Mark notification as read
        function markNotificationAsRead(notificationId, element) {
            fetch('mark_notification_as_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(notificationId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    element.classList.remove('unread');
                    updateNotificationBadge();
                } else {
                    console.error('Failed to mark notification as read:', data.message);
                }
            })
            .catch(error => console.error('Error marking notification:', error));
        }

        // Delete notification
        function deleteNotification(notificationId, element) {
            fetch('delete_notification.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + encodeURIComponent(notificationId)
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    element.style.transition = 'transform 0.3s ease, opacity 0.3s ease';
                    element.style.transform = 'translateX(-100%)';
                    element.style.opacity = '0';
                    setTimeout(() => {
                        element.remove();
                        updateNotificationBadge();
                        const dropdownContent = document.querySelector('.notification-dropdown-content');
                        if (dropdownContent.querySelectorAll('.notification-item').length === 0) {
                            dropdownContent.innerHTML = '<div class="notification-item"><div class="notification-message">Tidak ada notifikasi baru</div></div>';
                        }
                    }, 300);
                } else {
                    console.error('Failed to delete notification:', data.message);
                }
            })
            .catch(error => console.error('Error deleting notification:', error));
        }

        // Update notification badge
        function updateNotificationBadge() {
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.querySelector('.notification-badge');
                    if (data.unread > 0) {
                        if (badge) {
                            badge.textContent = data.unread;
                        } else {
                            const notificationLink = document.querySelector('.notification-toggle');
                            notificationLink.insertAdjacentHTML('beforeend', `<span class="notification-badge">${data.unread}</span>`);
                        }
                    } else {
                        if (badge) badge.remove();
                    }
                })
                .catch(error => console.error('Error updating badge:', error));
        }

        // Check for new notifications
        function checkNewNotifications() {
            fetch('check_notifications.php')
                .then(response => response.json())
                .then(data => {
                    const dropdownContent = document.querySelector('.notification-dropdown-content');
                    if (data.notifications.length > 0) {
                        dropdownContent.innerHTML = '';
                        data.notifications.forEach(notification => {
                            const notificationItem = document.createElement('div');
                            notificationItem.className = `notification-item ${notification.is_read ? '' : 'unread'}`;
                            notificationItem.dataset.id = notification.id;
                            notificationItem.innerHTML = `
                                <a href="#" class="notification-link">
                                    <div class="notification-message">${notification.message}</div>
                                    <div class="notification-time">${new Date(notification.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                                </a>
                                <button class="swipe-delete">Hapus</button>
                            `;
                            dropdownContent.appendChild(notificationItem);
                        });
                        initializeSwipe();
                        updateNotificationBadge();
                    } else {
                        dropdownContent.innerHTML = '<div class="notification-item"><div class="notification-message">Tidak ada notifikasi baru</div></div>';
                        const badge = document.querySelector('.notification-badge');
                        if (badge) badge.remove();
                    }
                })
                .catch(error => console.error('Error checking notifications:', error));
        }

        // Toggle notification dropdown
        document.querySelector('.notification-toggle').addEventListener('click', (e) => {
            e.preventDefault();
            const dropdownContent = document.querySelector('.notification-dropdown-content');
            dropdownContent.style.display = dropdownContent.style.display === 'block' ? 'none' : 'block';
            if (dropdownContent.style.display === 'block') {
                checkNewNotifications();
            }
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdown = document.querySelector('.notification-dropdown');
            const dropdownContent = document.querySelector('.notification-dropdown-content');
            if (!dropdown.contains(e.target) && dropdownContent.style.display === 'block') {
                dropdownContent.style.display = 'none';
            }
        });

        // Periodically check for new notifications
        setInterval(checkNewNotifications, 60000);

        // Initialize swipe on page load
        initializeSwipe();

        // Load page for pagination
        function loadPage(page) {
            window.location.href = `dashboard.php?page=${page}`;
        }

        // Detail toggle for transactions
        document.querySelectorAll('.detail-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const detailRow = this.closest('tr').nextElementSibling;
                if (detailRow && detailRow.classList.contains('detail-row')) {
                    detailRow.classList.toggle('hidden-detail');
                    this.textContent = detailRow.classList.contains('hidden-detail') ? 'Lihat Detail' : 'Sembunyikan';
                }
            });
        });
    </script>
</body>
</html>