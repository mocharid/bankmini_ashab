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

// Function to determine account status (kept for logic but not displayed)
function getAccountStatus($user_data, $current_time) {
    if ($user_data['is_frozen']) {
        return 'dibekukan';
    } elseif ($user_data['pin_block_until'] !== null && $user_data['pin_block_until'] > $current_time) {
        return 'terblokir_sementara';
    } else {
        return 'aktif';
    }
}

// Function to determine card level and style based on balance
function getCardLevelAndStyle($saldo) {
    if ($saldo < 100000) {
        return [
            'level' => 'Tunas',
            'gradient' => 'linear-gradient(135deg, #2E5BBA 0%, #1e3c72 100%)',
            'fallback' => 'linear-gradient(135deg, #2E5BBA 0%, #1e3c72 30%, #2a5298 60%, #1c4591 85%, #1a3c78 100%)'
        ];
    } elseif ($saldo >= 100000 && $saldo <= 250000) {
        return [
            'level' => 'Tumbuh',
            'gradient' => 'linear-gradient(135deg, #4B5E6A 0%, #2F3E46 100%)',
            'fallback' => 'linear-gradient(135deg, #4B5E6A 0%, #2F3E46 30%, #3A4A56 60%, #2F3E46 85%, #263238 100%)'
        ];
    } else {
        return [
            'level' => 'Cakrawala',
            'gradient' => 'linear-gradient(135deg, #1C2526 0%, #434C5E 100%)',
            'fallback' => 'linear-gradient(135deg, #1C2526 0%, #434C5E 30%, #2E3440 60%, #1C2526 85%, #0D1B2A 100%)'
        ];
    }
}

// Fetch user details
$query = "SELECT u.nama, u.username, u.is_frozen, u.pin_block_until, u.email, u.pin 
          FROM users u 
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

// Determine card level and style
$card_info = getCardLevelAndStyle($saldo);

// Cek PIN
$has_pin = !empty($user_data['pin']);

// Cek email
$has_email = !empty($user_data['email']);

// Fetch notifications
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

// Fetch recent transactions (last 5, including all types)
$recent_transactions = [];
if ($rekening_id) {
    $query = "SELECT 
                t.jenis_transaksi, 
                t.jumlah, 
                t.created_at, 
                t.status, 
                t.rekening_tujuan_id, 
                t.rekening_id,
                r1.no_rekening as rekening_asal,
                r2.no_rekening as rekening_tujuan,
                u1.nama as nama_pengirim,
                u2.nama as nama_penerima
              FROM transaksi t
              LEFT JOIN rekening r1 ON t.rekening_id = r1.id
              LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
              LEFT JOIN users u1 ON r1.user_id = u1.id
              LEFT JOIN users u2 ON r2.user_id = u2.id
              WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
              ORDER BY t.created_at DESC
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $rekening_id, $rekening_id);
    $stmt->execute();
    $recent_transactions_result = $stmt->get_result();
    while ($row = $recent_transactions_result->fetch_assoc()) {
        // Map status to Indonesian terms
        if ($row['status'] == 'approved') {
            $row['status'] = 'berhasil';
        } elseif ($row['status'] == 'pending') {
            $row['status'] = 'menunggu';
        } elseif ($row['status'] == 'failed') {
            $row['status'] = 'gagal';
        }
        $recent_transactions[] = $row;
    }
}

// Quotes penyemangat
$quotes = [
    "Belajar dan menabung, kunci sukses masa depanmu!",
    "Setiap tabungan adalah langkah menuju mimpimu!",
    "Simpan sedikit hari ini, wujudkan impian besar besok!",
    "Keberhasilan dimulai dari kebiasaan menabung!",
    "Tabunganmu adalah investasi untuk masa depan cerah!",
    "Menabung itu keren, seperti superhero keuangan!",
    "Simpan sekarang, tersenyum di masa depan!"
];
$day_index = date('w');
$daily_quote = $quotes[$day_index];

// Fungsi untuk format bulan dalam bahasa Indonesia
function formatIndonesianDate($date) {
    $months = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = (int)$dateObj->format('m');
    $hour = $dateObj->format('H:i');
    return "$day {$months[$month]}, $hour";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
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

        @media (max-width: 768px) {
            .wrapper {
                padding-bottom: clamp(5rem, 10vw, 6rem);
            }
        }

        :root {
            --font-size-xs: clamp(12px, 2vw, 14px);
            --font-size-sm: clamp(14px, 2.5vw, 16px);
            --font-size-md: clamp(16px, 3vw, 18px);
            --font-size-lg: clamp(18px, 3.5vw, 22px);
            --font-size-xl: clamp(24px, 4vw, 28px);
            --font-size-xxl: clamp(28px, 5vw, 36px);
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
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

        .card-wrapper {
            position: relative;
            width: clamp(320px, 45vw, 400px);
            height: clamp(200px, 28vw, 250px);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            background: #fff;
            margin: clamp(1.5rem, 3vw, 2rem) auto;
        }

        .card-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .card-info-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            padding: 25px;
            color: white;
            background: rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bank-name {
            font-size: 20px;
            font-weight: bold;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .card-type-badge {
            background: rgba(255,255,255,0.3);
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .account-number {
            font-size: 22px;
            font-weight: 600;
            letter-spacing: 3px;
            margin: 20px 0;
            font-family: 'Courier New', monospace;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .card-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
        }

        .holder-info {
            flex: 1;
        }

        .info-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        }

        .holder-name {
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .balance-section {
            text-align: right;
            position: relative;
        }

        .balance-amount {
            font-size: 18px;
            font-weight: 800;
            color: #FFD700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.7);
        }

        .card-fallback {
            background: <?php echo $card_info['fallback']; ?>;
            width: 100%;
            height: 100%;
            display: none;
            position: relative;
            overflow: hidden;
        }

        .card-fallback::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 300'%3E%3Cg opacity='0.1' fill='white'%3E%3Cpath d='M50 50h40v60h-40z'/%3E%3Cpath d='M100 50h40v60h-40z'/%3E%3Cpath d='M150 50h40v60h-40z'/%3E%3Cpath d='M200 50h40v60h-40z'/%3E%3Cpath d='M250 50h40v60h-40z'/%3E%3Cpath d='M300 50h40v60h-40z'/%3E%3Cpath d='M75 120h40v60h-40z'/%3E%3Cpath d='M125 120h40v60h-40z'/%3E%3Cpath d='M175 120h40v60h-40z'/%3E%3Cpath d='M225 120h40v60h-40z'/%3E%3Cpath d='M275 120h40v60h-40z'/%3E%3Cpath d='M100 190h40v60h-40z'/%3E%3Cpath d='M150 190h40v60h-40z'/%3E%3Cpath d='M200 190h40v60h-40z'/%3E%3Cpath d='M250 190h40v60h-40z'/%3E%3C/g%3E%3C/svg%3E"),
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 400 300'%3E%3Cg opacity='0.08' fill='white'%3E%3Cpath d='M320 80c0-20 16-36 36-36s36 16 36 36-16 36-36 36-36-16-36-36z'/%3E%3Cpath d='M10 200c0-15 12-27 27-27s27 12 27 27-12 27-27 27-27-12-27-27z'/%3E%3Cpath d='M150 30l15 30h30l-25 20 10 30-30-20-30 20 10-30-25-20h30z'/%3E%3C/g%3E%3C/svg%3E"),
                radial-gradient(circle at 20% 80%, rgba(255,255,255,0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255,255,255,0.08) 0%, transparent 50%);
            background-size: 120px 90px, 150px 120px, 200px 200px, 250px 250px;
            background-position: -10px -10px, 250px 150px, 0% 100%, 100% 0%;
            background-repeat: no-repeat;
        }

        .card-fallback::after {
            content: '';
            position: absolute;
            top: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cg opacity='0.15' fill='white'%3E%3Cpath d='M20 20h60v10H20z'/%3E%3Cpath d='M20 35h40v5H20z'/%3E%3Cpath d='M20 45h50v5H20z'/%3E%3Cpath d='M20 55h35v5H20z'/%3E%3Cpath d='M15 70c0-8 6-14 14-14h42c8 0 14 6 14 14v15H15z'/%3E%3Cpath d='M40 25c0-8 6-14 14-14s14 6 14 14c0 4-2 8-5 10v5c0 2-2 4-4 4h-10c-2 0-4-2-4-4v-5c-3-2-5-6-5-10z'/%3E%3C/g%3E%3C/svg%3E");
            background-size: contain;
            background-repeat: no-repeat;
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

        .bottom-nav {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            padding: clamp(0.8rem, 2vw, 1rem);
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .bottom-nav-container {
            display: flex;
            justify-content: space-around;
            align-items: center;
            max-width: 600px;
            margin: 0 auto;
        }

        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #1e3c72;
            text-decoration: none;
            font-size: var(--font-size-sm);
            padding: clamp(0.6rem, 1.5vw, 0.8rem);
            border-radius: 12px;
            transition: all 0.3s ease;
            position: relative;
        }

        .bottom-nav-item i {
            font-size: clamp(1.4rem, 3.5vw, 1.8rem);
            margin-bottom: 0.4rem;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .bottom-nav-item span {
            font-weight: 500;
        }

        .bottom-nav-item:hover {
            background-color: rgba(30, 60, 114, 0.1);
            transform: translateY(-2px);
        }

        .bottom-nav-item:hover i {
            transform: scale(1.2);
        }

        .bottom-nav-item.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .bottom-nav-item.disabled:hover {
            background-color: transparent;
            transform: none;
        }

        .bottom-nav-item.disabled:hover i {
            transform: none;
        }

        .bottom-nav-item::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, #1e3c72, #4776c9);
            border-radius: 2px;
            transition: width 0.3s ease;
        }

        .bottom-nav-item:hover::after {
            width: 60%;
        }

        .recent-transactions {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            margin: clamp(1.5rem, 3vw, 2rem) clamp(1rem, 3vw, 1.5rem);
            padding: clamp(1rem, 2vw, 1.5rem);
            position: relative;
            overflow: hidden;
            margin-bottom: clamp(2rem, 4vw, 2.5rem);
        }

        .recent-transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .recent-transactions-header h3 {
            font-size: var(--font-size-lg);
            font-weight: 600;
            color: #333;
        }

        .recent-transactions-header a {
            font-size: var(--font-size-sm);
            color: #1e3c72;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .recent-transactions-header a:hover {
            color: #4776c9;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            flex-shrink: 0;
        }

        .transaction-icon.in {
            background-color: #e6f4ea;
        }

        .transaction-icon.out {
            background-color: #fee2e2;
        }

        .transaction-icon i {
            font-size: 1.2rem;
        }

        .transaction-icon.in i {
            color: #28a745;
        }

        .transaction-icon.out i {
            color: #dc3545;
        }

        .transaction-details {
            flex: 1;
            min-width: 0;
        }

        .transaction-description {
            font-size: var(--font-size-sm);
            font-weight: 500;
            color: #333;
            margin-bottom: 0.3rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .transaction-time {
            font-size: var(--font-size-xs);
            color: #666;
        }

        .transaction-amount {
            font-size: var(--font-size-sm);
            font-weight: 600;
            white-space: nowrap;
        }

        .transaction-amount.in {
            color: #28a745;
        }

        .transaction-amount.out {
            color: #dc3545;
        }

        .transaction-status {
            font-size: var(--font-size-xs);
            padding: 0.3rem 0.8rem;
            border-radius: 12px;
            margin-left: 1rem;
        }

        .transaction-status.success {
            background-color: #e6f4ea;
            color: #28a745;
        }

        .transaction-status.pending {
            background-color: #fff3cd;
            color: #856404;
        }

        @media (max-width: 768px) {
            .bottom-nav {
                display: block;
            }

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

            .card-wrapper {
                width: clamp(280px, 80vw, 360px);
                height: clamp(180px, 50vw, 225px);
            }

            .card-menu {
                padding: clamp(0.8rem, 2vw, 1rem);
                margin: clamp(1rem, 2vw, 1.5rem) auto;
            }

            .recent-transactions {
                margin: clamp(1rem, 2vw, 1.5rem);
                padding: clamp(0.8rem, 1.5vw, 1rem);
                margin-bottom: clamp(2rem, 4vw, 2.5rem);
            }

            .transaction-description {
                font-size: var(--font-size-xs);
            }

            .transaction-time {
                font-size: calc(var(--font-size-xs) * 0.9);
            }

            .transaction-amount {
                font-size: var(--font-size-xs);
            }

            .transaction-status {
                font-size: calc(var(--font-size-xs) * 0.9);
                padding: 0.2rem 0.6rem;
            }
        }

        @media (min-width: 769px) {
            .bottom-nav {
                display: none !important;
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

            .card-wrapper {
                width: clamp(260px, 90vw, 340px);
                height: clamp(160px, 55vw, 200px);
            }

            .bank-name {
                font-size: 18px;
            }

            .account-number {
                font-size: 20px;
            }

            .holder-name {
                font-size: 14px;
            }

            .balance-amount {
                font-size: 16px;
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

        .header, .card-wrapper, .recent-transactions {
            transition: all 0.3s ease;
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
                                            <?= formatIndonesianDate($row['created_at']) ?>
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

        <!-- Card Wrapper -->
        <div class="card-wrapper">
            <img src="/bankmini/assets/images/card.png" 
                 alt="SchoBank Card" 
                 class="card-image"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <div class="card-fallback" style="display: none;"></div>
            <div class="card-info-overlay">
                <div class="card-header">
                    <h2 class="bank-name">SchoBank</h2>
                    <span class="card-type-badge"><?php echo htmlspecialchars($card_info['level']); ?></span>
                </div>
                <div class="account-number">
                    <?= htmlspecialchars($no_rekening) ?>
                </div>
                <div class="card-footer">
                    <div class="holder-info">
                        <div class="info-label">Pemilik</div>
                        <div class="holder-name"><?= htmlspecialchars(strtoupper($user_data['nama'])) ?></div>
                    </div>
                    <div class="balance-section" id="balanceContainer">
                        <div class="info-label">Saldo</div>
                        <div class="balance-amount" id="balanceCounter"><?= number_format($saldo, 0, ',', '.') ?></div>
                    </div>
                </div>
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
                    <strong>Perhatian!</strong> Kamu belum mengatur PIN keamanan.  
                    <a href="profil.php#account-tab" class="pin-alert-button">Atur PIN Sekarang</a>
                </div>
                <button class="pin-alert-close" onclick="this.parentElement.parentElement.style.display='none';">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <?php endif; ?>

        <!-- Email Alert -->
        <?php if (!$has_email): ?>
        <div class="pin-alert">
            <div class="pin-alert-content">
                <i class="fas fa-exclamation-triangle"></i>
                <div class="pin-alert-text">
                    <strong>Perhatian!</strong> Kamu belum menambahkan email.  
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
                <p>Kelola informasi akunmu dengan mudah dan aman</p>
            </div>
            <div class="card" onclick="window.location.href='cek_mutasi.php'">
                <div class="card-icon">
                    <i class="fas fa-list-alt"></i>
                </div>
                <h3>Cek Mutasi</h3>
                <p>Lihat semua riwayat transaksimu dengan mudah</p>
            </div>
            <div class="card <?php echo !$has_pin ? 'disabled' : ''; ?>" 
                 <?php echo $has_pin ? 'onclick="window.location.href=\'transfer_pribadi.php\'"' : 'onclick="showPinAlert()" title="Harap atur PIN terlebih dahulu"'; ?>>
                <div class="card-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
                <h3>Transfer Lokal</h3>
                <p>Kirim uang dengan cepat ke teman kamu</p>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="recent-transactions">
            <div class="recent-transactions-header">
                <h3>Aktivitas Keuangan Kamu</h3>
                <a href="cek_mutasi.php">Lihat Semua</a>
            </div>
            <?php if (count($recent_transactions) > 0): ?>
                <?php foreach ($recent_transactions as $transaction): ?>
                    <?php
                        $is_incoming = ($transaction['jenis_transaksi'] == 'setor' || 
                                       ($transaction['jenis_transaksi'] == 'transfer' && 
                                        $transaction['rekening_tujuan'] == $no_rekening));
                        $description = '';
                        $icon_class = $is_incoming ? 'in' : 'out';
                        $amount_class = $is_incoming ? 'in' : 'out';
                        $amount_prefix = $is_incoming ? '+' : '-';
                        $icon = $is_incoming ? 'fa-arrow-down' : 'fa-arrow-up';

                        if ($transaction['jenis_transaksi'] == 'setor') {
                            $description = 'Setoran Tunai Tabungan';
                        } elseif ($transaction['jenis_transaksi'] == 'tarik') {
                            $description = 'Penarikan Tunai Saldo';
                        } elseif ($transaction['jenis_transaksi'] == 'transfer') {
                            if ($transaction['rekening_asal'] == $no_rekening) {
                                $description = 'Transfer Keluar ke ' . htmlspecialchars($transaction['nama_penerima'] ?? 'N/A');
                            } else {
                                $description = 'Transfer Masuk dari ' . htmlspecialchars($transaction['nama_pengirim'] ?? 'N/A');
                            }
                        }
                    ?>
                    <div class="transaction-item">
                        <div class="transaction-icon <?php echo $icon_class; ?>">
                            <i class="fas <?php echo $icon; ?>"></i>
                        </div>
                        <div class="transaction-details">
                            <div class="transaction-description"><?php echo $description; ?></div>
                            <div class="transaction-time">
                                <?php echo formatIndonesianDate($transaction['created_at']); ?>
                            </div>
                        </div>
                        <div class="transaction-amount <?php echo $amount_class; ?>">
                            <?php echo $amount_prefix . ' Rp ' . number_format($transaction['jumlah'], 0, ',', '.'); ?>
                        </div>
                        <span class="transaction-status <?php echo strtolower($transaction['status']); ?>">
                            <?php echo ucfirst($transaction['status']); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="transaction-item">
                    <div class="transaction-details">
                        <div class="transaction-description">Belum ada transaksi</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bottom Navigation Bar -->
        <div class="bottom-nav">
            <div class="bottom-nav-container">
                <a href="profil.php" class="bottom-nav-item">
                    <i class="fas fa-user-cog"></i>
                    <span>Profil</span>
                </a>
                <a href="cek_mutasi.php" class="bottom-nav-item">
                    <i class="fas fa-list-alt"></i>
                    <span>Mutasi</span>
                </a>
                <a href="<?php echo $has_pin ? 'transfer_pribadi.php' : 'javascript:void(0);'; ?>" 
                   class="bottom-nav-item <?php echo !$has_pin ? 'disabled' : ''; ?>" 
                   <?php echo !$has_pin ? 'onclick="showPinAlert()"' : ''; ?>>
                    <i class="fas fa-paper-plane"></i>
                    <span>Transfer</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Prevent pinch-to-zoom and double-tap zoom on mobile
        document.addEventListener('touchmove', function (event) {
            if (event.scale !== 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Add CSS to prevent double-tap zoom
        const style = document.createElement('style');
        style.textContent = `
            html, body {
                touch-action: pan-x pan-y;
            }
        `;
        document.head.appendChild(style);

        // Animate balance counter
        document.addEventListener('DOMContentLoaded', function() {
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
        });

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
                    element.remove();
                    updateNotificationBadge();
                    const notificationItems = document.querySelectorAll('.notification-item');
                    if (notificationItems.length === 0) {
                        const dropdownContent = document.querySelector('.notification-dropdown-content');
                        dropdownContent.innerHTML = `
                            <div class="notification-item">
                                <div class="notification-message">Tidak ada notifikasi baru</div>
                            </div>
                        `;
                    }
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
                        badge.textContent = data.unread;
                        badge.style.display = 'flex';
                    } else {
                        badge.style.display = 'none';
                    }
                })
                .catch(error => console.error('Error updating notification badge:', error));
        }

        // Toggle notification dropdown
        document.querySelector('.notification-toggle').addEventListener('click', (e) => {
            e.preventDefault();
            const dropdownContent = document.querySelector('.notification-dropdown-content');
            dropdownContent.style.display = dropdownContent.style.display === 'block' ? 'none' : 'block';
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdown = document.querySelector('.notification-dropdown');
            const dropdownContent = document.querySelector('.notification-dropdown-content');
            if (!dropdown.contains(e.target) && dropdownContent.style.display === 'block') {
                dropdownContent.style.display = 'none';
            }
        });

        // Prevent dropdown from closing when clicking inside
        document.querySelector('.notification-dropdown-content').addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Initialize swipe on page load
        initializeSwipe();

        // Lazy load images
        document.addEventListener('DOMContentLoaded', () => {
            const images = document.querySelectorAll('img');
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            img.src = img.dataset.src || img.src;
                            observer.unobserve(img);
                        }
                    });
                });
                images.forEach(img => observer.observe(img));
            } else {
                images.forEach(img => img.src = img.dataset.src || img.src);
            }
        });

        // Optimize scroll performance
        let isScrolling;
        window.addEventListener('scroll', () => {
            clearTimeout(isScrolling);
            isScrolling = setTimeout(() => {
                // Add any scroll-related optimizations here
            }, 150);
        }, { passive: true });

        // Prevent text selection on double-click
        document.addEventListener('mousedown', (e) => {
            if (e.detail > 1) {
                e.preventDefault();
            }
        }, { passive: false });

        // Keyboard accessibility
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const dropdownContent = document.querySelector('.notification-dropdown-content');
                if (dropdownContent.style.display === 'block') {
                    dropdownContent.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>