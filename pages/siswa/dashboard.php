<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Set zona waktu ke WIB (Waktu Indonesia Barat)
date_default_timezone_set('Asia/Jakarta');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user details including nama, jurusan, and kelas
$query = "SELECT u.nama, u.username, j.nama_jurusan, k.nama_kelas 
          FROM users u 
          LEFT JOIN jurusan j ON u.jurusan_id = j.id 
          LEFT JOIN kelas k ON u.kelas_id = k.id 
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();

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
    $rekening_id = $rekening_data['id']; // Pastikan ID rekening tersedia
} else {
    $saldo = 0;
    $no_rekening = 'N/A';
    $rekening_id = null; // Jika tidak ada data rekening
}

// Cek apakah pengguna sudah mengatur PIN
$query_pin = "SELECT pin FROM users WHERE id = ?";
$stmt_pin = $conn->prepare($query_pin);
$stmt_pin->bind_param("i", $user_id);
$stmt_pin->execute();
$result_pin = $stmt_pin->get_result();
$user_pin_data = $result_pin->fetch_assoc();
$has_pin = !empty($user_pin_data['pin']);

// Cek apakah pengguna sudah mengatur email
$query_email = "SELECT email FROM users WHERE id = ?";
$stmt_email = $conn->prepare($query_email);
$stmt_email->bind_param("i", $user_id);
$stmt_email->execute();
$result_email = $stmt_email->get_result();
$user_email_data = $result_email->fetch_assoc();
$has_email = !empty($user_email_data['email']);

// Pagination settings
$limit = 5; // Jumlah transaksi per halaman
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1; // Halaman saat ini
$offset = ($page - 1) * $limit; // Hitung offset

// Query untuk mengambil transaksi dengan pagination
$query = "SELECT t.no_transaksi, t.jenis_transaksi, t.jumlah, t.status, t.created_at, t.rekening_id, t.rekening_tujuan_id 
          FROM transaksi t 
          JOIN rekening r ON (t.rekening_id = r.id OR t.rekening_tujuan_id = r.id) 
          WHERE r.user_id = ? 
          ORDER BY t.created_at DESC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$transaksi_result = $stmt->get_result();

// Query untuk menghitung total transaksi
$query_total = "SELECT COUNT(*) as total 
                FROM transaksi t 
                JOIN rekening r ON (t.rekening_id = r.id OR t.rekening_tujuan_id = r.id) 
                WHERE r.user_id = ?";
$stmt_total = $conn->prepare($query_total);
$stmt_total->bind_param("i", $user_id);
$stmt_total->execute();
$total_result = $stmt_total->get_result();
$total_row = $total_result->fetch_assoc();
$total_transaksi = $total_row['total']; // Total jumlah transaksi
$total_pages = ceil($total_transaksi / $limit); // Total jumlah halaman

// Fetch unread notifications
$query = "SELECT * FROM notifications 
          WHERE user_id = ? AND is_read = 0 
          ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$notifications_result = $stmt->get_result();
$unread_notifications = $notifications_result->num_rows;

// Fungsi untuk mengonversi tanggal ke bahasa Indonesia
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    $hari = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];

    $tanggal = strtotime($tanggal);
    $hari_ini = $hari[date('l', $tanggal)];
    $tanggal_format = date('d', $tanggal);
    $bulan_ini = $bulan[date('n', $tanggal)];
    $tahun = date('Y', $tanggal);

    return "$hari_ini, $tanggal_format $bulan_ini $tahun";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Import modern, rounded computer-style fonts */
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&display=swap');
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');

/* Global Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Outfit', 'DM Sans', sans-serif;
}

body {
    background-color: #f5f7fa;
    color: #333;
    line-height: 1.6;
}

/* Header Styles */
.header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    padding: 1rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    border-bottom-left-radius: 16px;
    border-bottom-right-radius: 16px;
    position: relative;
    z-index: 100;
}

.header h1 {
    font-size: 1.7rem;
    font-weight: 600;
    letter-spacing: -0.5px;
    font-family: 'Poppins', sans-serif;
}

.menu {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.menu a {
    color: white;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.95rem;
    padding: 0.6rem 1.2rem;
    border-radius: 50px;
    transition: all 0.2s ease;
    font-weight: 500;
}

.menu a:hover {
    background-color: rgba(255, 255, 255, 0.15);
    transform: translateY(-2px);
}

/* Notification Styles */
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
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.7rem;
    font-weight: bold;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
}

.notification-dropdown-content {
    display: none;
    position: absolute;
    right: 0;
    background-color: white;
    min-width: 320px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    z-index: 100;
    border-radius: 16px;
    overflow: hidden;
    margin-top: 15px;
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid rgba(0, 0, 0, 0.05);
}

.notification-item {
    display: block;
    padding: 1rem 1.2rem;
    text-decoration: none;
    color: #333; /* Changed from #000 to a slightly softer black */
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s;
    background-color: white;
}

.notification-item.unread {
    background-color: #ebf5ff;
    color: #333; /* Ensure same text color for unread items */
}

.notification-item:hover {
    background-color: #f5f7fa;
}

.notification-message {
    font-size: 0.9rem;
    margin-bottom: 0.4rem;
    line-height: 1.4;
    color: #333; /* Explicitly set color instead of inheriting */
}

.notification-time {
    font-size: 0.75rem;
    color: #666; /* Keep the time text slightly lighter */
}

/* Welcome Banner Styles */
.welcome-banner {
    background: linear-gradient(135deg, #ffffff 0%, #f5f7fa 100%);
    padding: 2.5rem;
    margin: 1.5rem;
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
    font-size: 2.6rem;
    font-weight: 700;
    margin-bottom: 0.8rem;
    background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    font-family: 'Poppins', sans-serif;
    letter-spacing: -1px;
    line-height: 1.2;
}

.welcome-info, .date {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1.05rem;
    color: #555;
    margin-top: 0.6rem;
    font-weight: 500;
}

.welcome-info i, .date i {
    color: #1e3c72;
}

/* Card Menu Styles */
.card-menu {
    display: flex;
    justify-content: center;
    gap: 1.8rem;
    padding: 0 1.5rem 1.5rem;
    max-width: 1200px;
    margin: auto;
    flex-wrap: wrap;
}

.card {
    background-color: white;
    border-radius: 24px;
    padding: 2.2rem 1.8rem;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    text-align: center;
    cursor: pointer;
    border: 1px solid rgba(0, 0, 0, 0.03);
    position: relative;
    overflow: hidden;
    z-index: 1;
    flex: 1 1 280px;
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
    font-size: 2.8rem;
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
    width: 60px;
    height: 60px;
    background-color: rgba(30, 60, 114, 0.1);
    border-radius: 50%;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 1;
    transition: all 0.3s ease;
}

.card:hover .card-icon::after {
    width: 70px;
    height: 70px;
    background-color: rgba(30, 60, 114, 0.15);
}

.card h3 {
    font-size: 1.4rem;
    margin-bottom: 0.8rem;
    font-weight: 600;
    font-family: 'DM Sans', sans-serif;
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
    font-size: 1rem;
    color: #666;
    font-weight: 400;
    margin-top: 1rem;
}

/* Stats Container Styles */
.stats-container {
    padding: 0 1.5rem 1.5rem;
}

.stat-box {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: white;
    border-radius: 24px;
    padding: 2.5rem;
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
    width: 120px;
    height: 120px;
    bottom: -60px;
    right: 10%;
    animation: float 12s infinite ease-in-out;
}

.bubble-2 {
    width: 80px;
    height: 80px;
    top: 30px;
    right: 25%;
    animation: float 9s infinite ease-in-out 1s;
}

.bubble-3 {
    width: 50px;
    height: 50px;
    bottom: 60px;
    left: 15%;
    animation: float 10s infinite ease-in-out 2s;
}

.bubble-4 {
    width: 100px;
    height: 100px;
    top: -40px;
    left: 25%;
    animation: float 13s infinite ease-in-out 3s;
}

@keyframes float {
    0%, 100% {
        transform: translateY(0) scale(1);
    }
    50% {
        transform: translateY(-20px) scale(1.05);
    }
}

.stat-title {
    font-size: 1.2rem;
    margin-bottom: 1.2rem;
    font-weight: 500;
    position: relative;
    z-index: 1;
    font-family: 'DM Sans', sans-serif;
    letter-spacing: 0.5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stat-title .toggle-balance {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: white;
    width: 36px;
    height: 36px;
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
    position: relative;
    z-index: 1;
    transition: all 0.3s ease;
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
    font-size: 3.2rem;
    font-weight: 700;
    letter-spacing: -1px;
    font-family: 'Poppins', sans-serif;
    transition: all 0.3s ease;
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
    font-size: 1.5rem;
    font-weight: 600;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}

.currency {
    font-size: 1.8rem;
    margin-right: 0.6rem;
    opacity: 0.9;
    font-weight: 500;
}

.stat-info {
    display: flex;
    justify-content: center;
    position: relative;
    z-index: 1;
}

.rekening-info {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background-color: rgba(255, 255, 255, 0.2);
    padding: 0.8rem 1.4rem;
    border-radius: 50px;
    cursor: pointer;
    transition: all 0.2s;
    font-family: 'DM Sans', sans-serif;
    font-weight: 500;
    letter-spacing: 0.5px;
}

.rekening-info:hover {
    background-color: rgba(255, 255, 255, 0.3);
    transform: translateY(-3px);
}

.copy-icon {
    font-size: 0.9rem;
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
    padding: 0.5rem 0.8rem;
    font-size: 0.8rem;
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

/* Transactions Container Styles */
.transactions-container {
    background-color: white;
    border-radius: 20px;
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.03);
    margin: 0 1.5rem 1.5rem;
    overflow: hidden;
    border: 1px solid rgba(0, 0, 0, 0.03);
}

.transactions-header {
    padding: 1.2rem 1.8rem;
    border-bottom: 1px solid #eee;
}

.transactions-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: #333;
    font-family: 'DM Sans', sans-serif;
    letter-spacing: -0.5px;
}

.table-container {
    padding: 0.5rem 1.5rem;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
}

thead th {
    background-color: #f5f7fa;
    color: #444;
    font-weight: 600;
    text-align: left;
    padding: 1.2rem 1rem;
    font-size: 0.95rem;
    letter-spacing: 0.3px;
    font-family: 'DM Sans', sans-serif;
    border-radius: 8px;
}

tbody td {
    padding: 1.2rem 1rem;
    border-bottom: 1px solid #eee;
    font-size: 0.95rem;
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background-color: rgba(245, 247, 250, 0.7);
}

.amount-positive {
    color: #4caf50;
    font-weight: 600;
    letter-spacing: 0.3px;
    font-family: 'DM Sans', sans-serif;
}

.amount-negative {
    color: #f44336;
    font-weight: 600;
    letter-spacing: 0.3px;
    font-family: 'DM Sans', sans-serif;
}

.status-badge {
    display: inline-block;
    padding: 0.4rem 0.8rem;
    border-radius: 50px;
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.3px;
    font-family: 'DM Sans', sans-serif;
}

.status-sukses {
    background-color: #e8f5e9;
    color: #2e7d32;
}

.status-pending {
    background-color: #fff8e1;
    color: #ff8f00;
}

.status-gagal {
    background-color: #ffebee;
    color: #c62828;
}

/* Empty State Styles */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 4rem 1.5rem;
    color: #888;
}

.empty-state i {
    font-size: 3.5rem;
    margin-bottom: 1.2rem;
    opacity: 0.5;
    color: #1e3c72;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
}

/* Pagination Styles */
.pagination {
    display: flex;
    justify-content: center;
    gap: 0.6rem;
    padding: 1.8rem;
}

.pagination-link {
    display: inline-block;
    padding: 0.6rem 1rem;
    border-radius: 50px;
    text-decoration: none;
    color: #555;
    background-color: #f5f7fa;
    transition: all 0.2s;
    font-weight: 500;
    font-family: 'DM Sans', sans-serif;
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

/* Specific Transaction Row Styles */
.transaksi-setor {
    background-color: rgba(76, 175, 80, 0.05);
}

.transaksi-tarik {
    background-color: rgba(244, 67, 54, 0.05);
}

.transaksi-transfer {
    background-color: rgba(33, 150, 243, 0.05);
}

/* Responsive Styles */
@media (max-width: 768px) {
    .header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
        padding: 1.2rem;
    }

    .menu {
        width: 100%;
        justify-content: space-between;
    }

    .notification-dropdown-content {
        position: fixed;
        left: 0;
        right: 0;
        top: 140px;
        width: 90%;
        margin: 0 auto;
        max-height: 70vh;
        z-index: 999;
    }

    .welcome-banner {
        margin: 1rem;
        padding: 1.8rem;
    }

    .welcome-banner h2 {
        font-size: 2.2rem;
    }

    .card-menu {
        padding: 0 1rem 1rem;
        grid-template-columns: 1fr;
    }

    .stats-container {
        padding: 0 1rem 1rem;
    }

    .transactions-container {
        margin: 0 1rem 1rem;
    }

    .balance-box {
        font-size: 2.5rem;
    }

    .currency {
        font-size: 1.5rem;
    }

    table {
        min-width: 650px;
    }
}

/* Additional Effects */
@keyframes pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(30, 60, 114, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(30, 60, 114, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(30, 60, 114, 0);
    }
}

.rekening-info {
    animation: pulse 2s infinite;
}

@keyframes cardEntrance {
    from {
        opacity: 0;
        transform: translateY(25px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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
/* Styling untuk notifikasi PIN */
.pin-alert {
    margin: 15px;
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
    padding: 15px 20px;
}

.pin-alert i.fas.fa-exclamation-triangle {
    font-size: 24px;
    color: #ff9800;
    margin-right: 15px;
}

.pin-alert-text {
    flex: 1;
    color: #856404;
}

.pin-alert-button {
    display: inline-block;
    margin-left: 10px;
    padding: 5px 15px;
    background-color: #ffc107;
    color: #212529;
    border-radius: 5px;
    text-decoration: none;
    font-weight: bold;
    transition: background-color 0.3s;
}

.pin-alert-button:hover {
    background-color: #e0a800;
}

.pin-alert-close {
    background: none;
    border: none;
    color: #856404;
    cursor: pointer;
    font-size: 16px;
    padding: 0 10px;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.menu-title {
    text-align: center;
    font-size: 1.5rem;
    font-weight: 700;
    margin: 1.5rem 0;
    color: #1e3c72;
    font-family: 'Poppins', sans-serif;
    letter-spacing: -0.5px;
    background: linear-gradient(135deg, #1e3c72 0%, #4776c9 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}
</style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Dashboard Nasabah</h1>
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
                            <a href="#" class="notification-item <?= $row['is_read'] ? '' : 'unread' ?>">
                                <div class="notification-message"><?= htmlspecialchars($row['message']) ?></div>
                                <div class="notification-time">
                                    <?= date('d M Y H:i', strtotime($row['created_at'])) ?>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <a href="#" class="notification-item">
                            <div class="notification-message">Tidak ada notifikasi baru</div>
                        </a>
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
        <div class="date">
            <i class="far fa-calendar-alt"></i> <?= tanggal_indonesia(date('Y-m-d')) ?>
        </div>
    </div>
    <!-- Notifikasi PIN jika belum diatur -->
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

    <!-- Tambahkan judul "Menu Utama" di atas card-menu -->
    <h2 class="menu-title">Menu Utama</h2>

    <!-- Enhanced Card Menu -->
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
        <div class="card" onclick="window.location.href='transfer_pribadi.php'">
            <div class="card-icon">
                <i class="fas fa-paper-plane"></i>
            </div>
            <h3>Transfer Lokal</h3>
            <p>Kirim uang dengan cepat dan aman ke rekening Lokal</p>
        </div>
    </div>

    <!-- Enhanced Stats Container with Bubbles -->
    <div class="stats-container">
        <div class="stat-box">
            <!-- Animated bubbles -->
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
                    <span class="balance"><?= number_format($saldo, 0, ',', '.') ?></span>
                </div>
                <div class="blur-overlay">
                    <span>Klik untuk menampilkan</span>
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

    <!-- Enhanced Transactions Container -->
    <div class="transactions-container">
        <div class="transactions-header">
            <h3 class="transactions-title">Riwayat Transaksi Terakhir</h3>
        </div>
        
        <?php if ($transaksi_result->num_rows > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>No Transaksi</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $transaksi_result->fetch_assoc()): 
                        $jenis = strtolower($row['jenis_transaksi']);
                        $is_transfer_masuk = ($jenis == 'transfer' && $row['rekening_tujuan_id'] == $rekening_id);
                        $amount_class = ($jenis == 'setor' || $is_transfer_masuk) ? 'amount-positive' : 'amount-negative';
                        $row_class = 'transaksi-' . $jenis;
                    ?>
                        <tr class="<?= $row_class ?>">
                            <td><?= htmlspecialchars($row['no_transaksi']) ?></td>
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
                                    <?= ucfirst($row['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d M Y H:i', strtotime($row['created_at'])) ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <p>Belum ada riwayat transaksi.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tampilkan navigasi pagination -->
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="javascript:void(0);" onclick="loadPage(<?= $page - 1 ?>)" class="pagination-link">&laquo; Sebelumnya</a>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <a href="javascript:void(0);" onclick="loadPage(<?= $i ?>)" class="pagination-link <?= ($page == $i) ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="javascript:void(0);" onclick="loadPage(<?= $page + 1 ?>)" class="pagination-link">Selanjutnya &raquo;</a>
        <?php endif; ?>
    </div>
<script>
// Toggle saldo visibility
document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('toggleBalance');
    const balanceContainer = document.getElementById('balanceContainer');
    const eyeIcon = document.getElementById('eyeIcon');
    
    toggleBtn.addEventListener('click', function() {
        balanceContainer.classList.toggle('balance-hidden');
        
        // Toggle eye icon
        if (balanceContainer.classList.contains('balance-hidden')) {
            eyeIcon.classList.remove('fa-eye-slash');
            eyeIcon.classList.add('fa-eye');
        } else {
            eyeIcon.classList.remove('fa-eye');
            eyeIcon.classList.add('fa-eye-slash');
        }
    });
});

// Fungsi untuk menyalin nomor rekening
function copyToClipboard(text) {
    // Buat elemen input sementara
    const tempInput = document.createElement("input");
    tempInput.value = text;
    document.body.appendChild(tempInput);
    
    // Pilih teks dan salin
    tempInput.select();
    document.execCommand("copy");
    
    // Hapus elemen input sementara
    document.body.removeChild(tempInput);
    
    // Tampilkan pesan suksis
    const tooltip = document.getElementById("copy-tooltip");
    tooltip.innerHTML = "Tersalin!";
    
    // Kembalikan teks tooltip setelah 2 detik
    setTimeout(function() {
        tooltip.innerHTML = "Klik untuk menyalin";
    }, 2000);
}

// Fungsi untuk menandai notifikasi sebagai "dibaca"
function markNotificationsAsRead() {
    fetch('mark_notification_as_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
    })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Perbarui badge notifikasi
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.remove(); // Hapus badge karena semua notifikasi sudah dibaca
                }

                // Hapus kelas "unread" dari notifikasi yang sudah dibaca
                const unreadNotifications = document.querySelectorAll('.notification-item.unread');
                unreadNotifications.forEach(notification => {
                    notification.classList.remove('unread');
                });
            }
        });
}

// Fungsi untuk memeriksa notifikasi baru
function checkNewNotifications() {
    fetch('check_notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.unread > 0) {
                // Perbarui badge notifikasi
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.textContent = data.unread;
                } else {
                    const notificationLink = document.querySelector('.notification-toggle');
                    notificationLink.innerHTML += `<span class="notification-badge">${data.unread}</span>`;
                }

                // Tampilkan notifikasi baru
                const dropdownContent = document.querySelector('.notification-dropdown-content');
                dropdownContent.innerHTML = ''; // Kosongkan dropdown sebelum menambahkan notifikasi baru
                data.notifications.forEach(notification => {
                    const notificationItem = document.createElement('a');
                    notificationItem.href = '#';
                    notificationItem.className = notification.is_read ? 'notification-item' : 'notification-item unread';
                    notificationItem.innerHTML = `
                        <div class="notification-message" style="color: #333;">${notification.message}</div>
                        <div class="notification-time">${new Date(notification.created_at).toLocaleString()}</div>
                    `;
                    dropdownContent.appendChild(notificationItem);
                });
            } else {
                // Jika tidak ada notifikasi baru, pastikan badge dihapus
                const badge = document.querySelector('.notification-badge');
                if (badge) {
                    badge.remove();
                }
            }
        });
}

// Fungsi untuk membuka/menutup dropdown notifikasi
document.addEventListener('DOMContentLoaded', function () {
    const notificationToggle = document.querySelector('.notification-toggle');
    const notificationContent = document.querySelector('.notification-dropdown-content');

    // Buka/tutup dropdown saat ikon notifikasi diklik
    notificationToggle.addEventListener('click', function (e) {
        e.preventDefault(); // Mencegah perilaku default link
        const isOpen = notificationContent.style.display === 'block';
        notificationContent.style.display = isOpen ? 'none' : 'block';

        // Jika dropdown dibuka, tandai notifikasi sebagai "dibaca"
        if (!isOpen) {
            markNotificationsAsRead();
        }
    });

// Tutup dropdown saat scroll layar
window.addEventListener('scroll', function() {
    notificationContent.style.display = 'none';
});
    
    // Inisialisasi animasi card dengan delay
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.setProperty('--i', index + 1);
    });
});


// Periksa notifikasi baru setiap 10 detik
setInterval(checkNewNotifications, 10000);
        // Fungsi untuk memuat halaman dengan AJAX
        function loadPage(page) {
            const xhr = new XMLHttpRequest();
            const url = `?page=${page}`;

            xhr.open('GET', url, true);

            xhr.onload = function() {
                if (xhr.status === 200) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(xhr.responseText, 'text/html');

                    // Perbarui tabel dan pagination
                    document.querySelector('.table-container').innerHTML = doc.querySelector('.table-container').innerHTML;
                    document.querySelector('.pagination').innerHTML = doc.querySelector('.pagination').innerHTML;

                    // Perbarui URL tanpa reload halaman
                    history.pushState(null, '', url);
                }
            };

            xhr.send();
        }

        // Handle tombol "Back" dan "Forward" di browser
        window.addEventListener('popstate', function(event) {
            const page = new URL(window.location.href).searchParams.get('page') || 1;
            loadPage(page);
        });
    </script>
</script>
</body>
</html>