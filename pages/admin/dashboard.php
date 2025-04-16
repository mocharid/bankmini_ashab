<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

$admin_id = $_SESSION['user_id'] ?? 0;

// Fungsi untuk mengubah nama hari ke Bahasa Indonesia
function getHariIndonesia($date) {
    $hari = date('l', strtotime($date));
    $hariIndo = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu'
    ];
    return $hariIndo[$hari];
}

// Fungsi untuk format tanggal Indonesia
function formatTanggalIndonesia($date) {
    $bulan = [
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember'
    ];
    $dateObj = new DateTime($date);
    $day = $dateObj->format('d');
    $month = $bulan[$dateObj->format('F')];
    $year = $dateObj->format('Y');
    return "$day $month $year";
}

// Get total students count
$query = "SELECT COUNT(id) as total_siswa FROM users WHERE role = 'siswa'";
$result = $conn->query($query);
$total_siswa = $result->fetch_assoc()['total_siswa'] ?? 0;

// Get total saldo (net setoran minus total transfers)
$query = "SELECT (
            COALESCE((
                SELECT SUM(net_setoran)
                FROM (
                    SELECT DATE(created_at) as tanggal,
                           SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                           SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                    FROM transaksi
                    WHERE status = 'approved' AND DATE(created_at) < CURDATE()
                    GROUP BY DATE(created_at)
                ) as daily_net
            ), 0) -
            COALESCE((
                SELECT SUM(jumlah)
                FROM saldo_transfers
                WHERE admin_id = ?
            ), 0)
          ) as total_saldo";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$total_saldo = $result->fetch_assoc()['total_saldo'] ?? 0;

// Get today's transactions for Net Setoran Harian
$query_setor = "SELECT SUM(jumlah) as total_setor 
                FROM transaksi 
                WHERE jenis_transaksi = 'setor' 
                AND DATE(created_at) = CURDATE() 
                AND status = 'approved'";
$result_setor = $conn->query($query_setor);
$total_setor = $result_setor->fetch_assoc()['total_setor'] ?? 0;

$query_tarik = "SELECT SUM(jumlah) as total_tarik 
                FROM transaksi 
                WHERE jenis_transaksi = 'tarik' 
                AND DATE(created_at) = CURDATE() 
                AND status = 'approved'";
$result_tarik = $conn->query($query_tarik);
$total_tarik = $result_tarik->fetch_assoc()['total_tarik'] ?? 0;

$saldo_harian = $total_setor - $total_tarik;

// Get total transferred amount to officers
$query = "SELECT SUM(jumlah) as saldo_transferred 
          FROM saldo_transfers 
          WHERE admin_id = ? 
          AND tanggal = CURDATE()";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$saldo_transferred = $result->fetch_assoc()['saldo_transferred'] ?? 0;

$saldo_bersih = ($total_setor - $total_tarik) - $saldo_transferred;

// Get net setoran for last 7 days (for pop-up)
$query_net_setoran = "SELECT DATE(created_at) as tanggal,
                            SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) -
                            SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as net_setoran
                      FROM transaksi
                      WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'approved'
                      GROUP BY DATE(created_at)
                      ORDER BY DATE(created_at) DESC";
$result_net_setoran = $conn->query($query_net_setoran);
$net_setoran_data = [];
while ($row = $result_net_setoran->fetch_assoc()) {
    $net_setoran_data[$row['tanggal']] = $row['net_setoran'];
}

// Complete 7-day data with zeros for missing dates
$seven_days = [];
for ($i = 0; $i <= 6; $i++) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $seven_days[] = [
        'tanggal' => $date,
        'net_setoran' => isset($net_setoran_data[$date]) ? $net_setoran_data[$date] : 0
    ];
}
$net_setoran_data = array_reverse($seven_days);

// Query untuk data chart (7 hari terakhir)
$query_chart = "SELECT DATE(created_at) as tanggal, COUNT(id) as jumlah_transaksi 
                FROM transaksi 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
                GROUP BY DATE(created_at) 
                ORDER BY DATE(created_at) ASC";
$result_chart = $conn->query($query_chart);
$chart_data = $result_chart->fetch_all(MYSQLI_ASSOC);

// Query untuk data siswa per kelas berdasarkan jurusan
$query_students = "SELECT j.nama_jurusan, k.nama_kelas, COUNT(u.id) as jumlah_siswa 
                   FROM jurusan j 
                   LEFT JOIN kelas k ON j.id = k.jurusan_id 
                   LEFT JOIN users u ON k.id = u.kelas_id 
                   WHERE u.role = 'siswa' OR u.id IS NULL 
                   GROUP BY j.id, k.id 
                   ORDER BY j.nama_jurusan, k.nama_kelas";
$result_students = $conn->query($query_students);

// Organize data by jurusan
$students_by_jurusan = [];
while ($row = $result_students->fetch_assoc()) {
    $jurusan = $row['nama_jurusan'];
    if (!isset($students_by_jurusan[$jurusan])) {
        $students_by_jurusan[$jurusan] = ['kelas_list' => []];
    }
    $students_by_jurusan[$jurusan]['kelas_list'][] = [
        'kelas' => $row['nama_kelas'] ?: 'Tidak ada kelas',
        'jumlah_siswa' => $row['jumlah_siswa']
    ];
}

// Query untuk riwayat transfer saldo ke petugas (7 hari terakhir)
$query_transfers = "SELECT tanggal, SUM(jumlah) as total_transfer
                    FROM saldo_transfers
                    WHERE admin_id = ? AND tanggal >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    GROUP BY tanggal
                    ORDER BY tanggal DESC";
$stmt = $conn->prepare($query_transfers);
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result_transfers = $stmt->get_result();
$transfer_data = [];
while ($row = $result_transfers->fetch_assoc()) {
    $transfer_data[$row['tanggal']] = $row['total_transfer'];
}

// Complete 7-day transfer data with zeros for missing dates
$transfer_seven_days = [];
for ($i = 0; $i <= 6; $i++) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $transfer_seven_days[] = [
        'tanggal' => $date,
        'total_transfer' => isset($transfer_data[$date]) ? $transfer_data[$date] : 0
    ];
}
$transfer_data = array_reverse($transfer_seven_days);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            touch-action: pan-y;
        }
        
        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
            display: flex;
            transition: background-color 0.3s ease;
        }

        /* Sidebar Utama */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #0a2e5c 0%, #154785 100%);
            color: white;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            z-index: 100;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease-in-out;
        }

        /* Sidebar Header (Fixed) */
        .sidebar-header {
            padding: 25px 20px;
            background: #0a2e5c;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            text-align: center;
        }

        .sidebar-header .bank-name {
            font-size: 26px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 0;
            color: white;
        }

        /* Sidebar Content (Scrollable) */
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        /* Sidebar Footer (Fixed) */
        .sidebar-footer {
            background: #0a2e5c;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            bottom: 0;
            z-index: 101;
            padding: 5px 0;
        }

        /* Scrollbar Styling */
        .sidebar-content::-webkit-scrollbar,
        .popup-content::-webkit-scrollbar,
        .saldo-content::-webkit-scrollbar,
        .transfer-content::-webkit-scrollbar {
            width: 8px;
        }

        .sidebar-content::-webkit-scrollbar-track,
        .popup-content::-webkit-scrollbar-track,
        .saldo-content::-webkit-scrollbar-track,
        .transfer-content::-webkit-scrollbar-track {
            background: #f8fafc;
            border-radius: 4px;
        }

        .sidebar-content::-webkit-scrollbar-thumb,
        .popup-content::-webkit-scrollbar-thumb,
        .saldo-content::-webkit-scrollbar-thumb,
        .transfer-content::-webkit-scrollbar-thumb {
            background: #bfdbfe;
            border-radius: 4px;
            border: 2px solid #f8fafc;
        }

        .sidebar-content::-webkit-scrollbar-thumb:hover,
        .popup-content::-webkit-scrollbar-thumb:hover,
        .saldo-content::-webkit-scrollbar-thumb:hover,
        .transfer-content::-webkit-scrollbar-thumb:hover {
            background: #93c5fd;
        }

        /* Menu Items */
        .sidebar-menu {
            padding: 10px 0;
        }

        .menu-label {
            padding: 15px 25px 10px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 600;
            margin-top: 10px;
        }

        .menu-item {
            position: relative;
            margin: 5px 0;
        }

        .menu-item a {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .menu-item a:hover, .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #38bdf8;
            color: white;
        }

        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        /* Dropdown Menu */
        .dropdown-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 25px;
            width: 100%;
            text-align: left;
            background: none;
            color: rgba(255, 255, 255, 0.85);
            border: none;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }

        .dropdown-btn:hover, .dropdown-btn.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: #38bdf8;
            color: white;
        }

        .dropdown-btn .menu-icon {
            display: flex;
            align-items: center;
        }

        .dropdown-btn .menu-icon i:first-child {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .dropdown-btn .arrow {
            transition: transform 0.3s ease;
            font-size: 14px;
        }

        .dropdown-btn.active .arrow {
            transform: rotate(180deg);
        }

        .dropdown-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.15);
        }

        .dropdown-container.show {
            max-height: 300px;
        }

        .dropdown-container a {
            padding: 12px 20px 12px 60px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 14px;
            border-left: 4px solid transparent;
        }

        .dropdown-container a:hover, .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: #38bdf8;
            color: white;
        }

        /* Tombol Logout (Warna Merah) */
        .logout-btn {
            color: #ff3b3b;
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: all 0.3s ease;
            max-width: calc(100% - 280px);
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #0a2e5c 0%, #2563eb 100%);
            color: white;
            padding: 30px;
            border-radius: 18px;
            margin-bottom: 35px;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.15);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            animation: fadeIn 1s ease-in-out;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .welcome-banner .date {
            font-size: 15px;
            opacity: 0.8;
            display: flex;
            align-items: center;
        }

        .welcome-banner .date i {
            margin-right: 8px;
        }

        /* Summary Section */
        .summary-section {
            padding: 10px 0 30px;
        }

        .summary-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }

        .summary-header h2 {
            font-size: 24px;
            font-weight: 600;
            color: #0a2e5c;
            margin-right: 15px;
            position: relative;
        }

        .summary-header h2::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 0;
            width: 40px;
            height: 3px;
            background: #3498db;
            border-radius: 2px;
        }

        /* Stats Grid Layout */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 25px;
            margin-bottom: 35px;
        }

        .stat-box {
            background: white;
            border-radius: 16px;
            padding: 25px;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideIn 0.5s ease-in-out;
            cursor: pointer;
        }

        .stat-box::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, rgba(59, 130, 246, 0.8), rgba(59, 130, 246, 0.3));
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.1);
        }

        .stat-icon {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            color: white;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .stat-box:hover .stat-icon {
            transform: scale(1.1);
        }

        .stat-title {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 10px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1a365d;
            letter-spacing: 0.5px;
        }

        .stat-trend {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #64748b;
            margin-top: auto;
            padding-top: 10px;
        }

        .stat-trend i {
            margin-right: 8px;
        }

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
            animation: fadeIn 1s ease-in-out;
        }

        .chart-title {
            font-size: 20px;
            font-weight: 600;
            color: #0a2e5c;
            margin-bottom: 20px;
        }

        /* Pop-up Styles */
        .popup-overlay, .saldo-popup, .transfer-popup {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            z-index: 2000;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }

        .popup-overlay.active, .saldo-popup.active, .transfer-popup.active {
            display: block;
            opacity: 1;
        }

        .popup-content, .saldo-content, .transfer-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.95);
            background: white;
            border-radius: 12px;
            padding: 25px;
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
            animation: popupScaleIn 0.3s ease-out forwards;
            cursor: default;
        }

        .popup-content h2, .saldo-content h2, .transfer-content h2 {
            font-size: 24px;
            font-weight: 700;
            color: #0a2e5c;
            margin-bottom: 20px;
            text-align: center;
            letter-spacing: 0.3px;
        }

        /* Jurusan Card */
        .jurusan-card {
            margin-bottom: 10px;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .jurusan-card:hover {
            transform: scale(1.015);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .jurusan-header {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            color: white;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            border-radius: 8px 8px 8px 8px;
            height: 48px;
            transition: background 0.2s ease;
        }

        .jurusan-header.active {
            border-radius: 8px 8px 0 0;
        }

        .jurusan-header:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .jurusan-header .logo {
            font-size: 22px;
            margin-right: 10px;
            color: #e0f2fe;
            transition: transform 0.2s ease;
        }

        .jurusan-header:hover .logo {
            transform: scale(1.15);
        }

        .jurusan-header .arrow {
            font-size: 14px;
            color: #e0f2fe;
            margin-right: 10px;
            transition: transform 0.3s ease;
        }

        .jurusan-header.active .arrow {
            transform: rotate(180deg);
        }

        .kelas-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: #f8fafc;
            border: 1px solid #bfdbfe;
            border-top: none;
            border-radius: 0 0 8px 8px;
        }

        .kelas-content.show {
            max-height: 400px;
            padding: 15px;
        }

        .kelas-card {
            background: #eff6ff;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border-left: 3px solid #2563eb;
        }

        .kelas-card:last-child {
            margin-bottom: 0;
        }

        .kelas-card:hover {
            transform: scale(1.01);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            border-left-color: #3b82f6;
        }

        .kelas-card .logo {
            font-size: 18px;
            margin-right: 10px;
            color: #2563eb;
            transition: transform 0.2s ease;
        }

        .kelas-card:hover .logo {
            transform: scale(1.15);
        }

        .kelas-card .kelas-name {
            font-size: 15px;
            color: #1e3a8a;
            flex: 1;
        }

        .kelas-card .jumlah-siswa {
            font-size: 15px;
            font-weight: 600;
            color: #2563eb;
        }

        /* Tanggal Card (Saldo and Transfer) */
        .tanggal-card {
            margin-bottom: 10px;
            border-radius: 8px;
            overflow: hidden;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .tanggal-card:hover {
            transform: scale(1.015);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .tanggal-header {
            padding: 12px 15px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            color: white;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            border-radius: 8px 8px 8px 8px;
            height: 48px;
            transition: background 0.2s ease;
        }

        .tanggal-header.active {
            border-radius: 8px 8px 0 0;
        }

        .tanggal-header:hover {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
        }

        .tanggal-header .logo {
            font-size: 22px;
            margin-right: 10px;
            color: #e0f2fe;
            transition: transform 0.2s ease;
        }

        .tanggal-header:hover .logo {
            transform: scale(1.15);
        }

        .tanggal-header .arrow {
            font-size: 14px;
            color: #e0f2fe;
            margin-right: 10px;
            transition: transform 0.3s ease;
        }

        .tanggal-header.active .arrow {
            transform: rotate(180deg);
        }

        .setoran-content, .transfer-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-top: none;
            border-radius: 0 0 8px 8px;
        }

        .setoran-content.show, .transfer-content.show {
            max-height: 150px;
            padding: 15px;
        }

        .setoran-value, .transfer-value {
            font-size: 16px;
            font-weight: 600;
            color: #1e3a8a;
            text-align: center;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            color: #64748b;
            font-size: 15px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .menu-toggle:hover {
            transform: scale(1.1);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @keyframes popupScaleIn {
            from {
                transform: translate(-50%, -50%) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translate(-50%, -50%) scale(1);
                opacity: 1;
            }
        }

        /* Responsive Design for Tablets (max-width: 768px) */
        @media (max-width: 768px) {
            .menu-toggle {
                display: flex;
                position: absolute;
                top: 50%;
                left: 20px;
                transform: translateY(-50%);
                z-index: 1000;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.active {
                transform: translateX(0);
                z-index: 999;
            }

            body.sidebar-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
                transition: opacity 0.3s ease;
                opacity: 1;
            }

            body:not(.sidebar-active)::before {
                opacity: 0;
                pointer-events: none;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
                transition: opacity 0.3s ease;
            }

            body.sidebar-active .main-content {
                opacity: 0.3;
                pointer-events: none;
            }

            /* Welcome Banner */
            .welcome-banner {
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 6px 15px rgba(37, 99, 235, 0.2);
                position: relative;
                display: flex;
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            .welcome-banner .content {
                flex: 1;
                padding-left: 50px;
            }

            .welcome-banner h2 {
                font-size: 22px;
                font-weight: 700;
                margin-bottom: 8px;
            }

            .welcome-banner .date {
                font-size: 12px;
                font-weight: 500;
            }

            /* Pop-up Adjustments */
            .popup-content, .saldo-content, .transfer-content {
                width: 95%;
                max-width: 500px;
                padding: 20px;
            }

            .popup-content h2, .saldo-content h2, .transfer-content h2 {
                font-size: 20px;
                margin-bottom: 15px;
            }

            .jurusan-header, .tanggal-header {
                font-size: 15px;
                padding: 10px 12px;
                height: 44px;
            }

            .jurusan-header .logo, .tanggal-header .logo {
                font-size: 20px;
                margin-right: 8px;
            }

            .jurusan-header .arrow, .tanggal-header .arrow {
                font-size: 13px;
                margin-right: 8px;
            }

            .kelas-content.show {
                padding: 12px;
            }

            .kelas-card {
                padding: 10px;
                margin-bottom: 6px;
            }

            .kelas-card .logo {
                font-size: 16px;
                margin-right: 8px;
            }

            .kelas-card .kelas-name,
            .kelas-card .jumlah-siswa {
                font-size: 14px;
            }

            .setoran-content.show, .transfer-content.show {
                padding: 12px;
            }

            .setoran-value, .transfer-value {
                font-size: 14px;
            }

            /* Summary Section */
            .summary-header h2 {
                font-size: 20px;
            }

            .summary-header h2::after {
                width: 30px;
                height: 2px;
            }

            .stats-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stat-box {
                padding: 18px;
                border-radius: 12px;
                box-shadow: 0 6px 15px rgba(0, 0, 0, 0.06);
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 22px;
                border-radius: 10px;
            }

            .stat-title {
                font-size: 13px;
                font-weight: 500;
            }

            .stat-value {
                font-size: 22px;
                font-weight: 700;
            }

            .stat-trend {
                font-size: 12px;
            }

            /* Chart Container */
            .chart-container {
                padding: 15px;
                border-radius: 12px;
            }

            .chart-title {
                font-size: 18px;
                margin-bottom: 15px;
            }

            #transactionChart {
                max-height: 200px;
            }
        }

        /* Responsive Design for Small Phones (max-width: 480px) */
        @media (max-width: 480px) {
            .menu-toggle {
                font-size: 20px;
                left: 15px;
                top: 50%;
                transform: translateY(-50%);
            }

            .sidebar {
                width: 240px;
            }

            .sidebar-header .bank-name {
                font-size: 22px;
            }

            .menu-item a, .dropdown-btn {
                padding: 12px 20px;
                font-size: 14px;
            }

            .dropdown-container a {
                padding: 10px 15px 10px 50px;
                font-size: 13px;
            }

            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
                border-radius: 10px;
            }

            .welcome-banner .content {
                padding-left: 45px;
            }

            .welcome-banner h2 {
                font-size: 18px;
            }

            .welcome-banner .date {
                font-size: 11px;
            }

            .popup-content, .saldo-content, .transfer-content {
                width: 95%;
                max-width: 340px;
                padding: 15px;
            }

            .popup-content h2, .saldo-content h2, .transfer-content h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }

            .jurusan-card, .tanggal-card {
                margin-bottom: 8px;
            }

            .jurusan-header, .tanggal-header {
                font-size: 14px;
                padding: 8px 10px;
                height: 40px;
            }

            .jurusan-header .logo, .tanggal-header .logo {
                font-size: 18px;
                margin-right: 6px;
            }

            .jurusan-header .arrow, .tanggal-header .arrow {
                font-size: 12px;
                margin-right: 6px;
            }

            .kelas-content.show {
                padding: 10px;
            }

            .kelas-card {
                padding: 8px;
                margin-bottom: 5px;
            }

            .kelas-card .logo {
                font-size: 14px;
                margin-right: 6px;
            }

            .kelas-card .kelas-name,
            .kelas-card .jumlah-siswa {
                font-size: 13px;
            }

            .setoran-content.show, .transfer-content.show {
                padding: 10px;
            }

            .setoran-value, .transfer-value {
                font-size: 13px;
            }

            .summary-header h2 {
                font-size: 18px;
            }

            .summary-header h2::after {
                width: 25px;
            }

            .stat-box {
                padding: 15px;
                border-radius: 10px;
            }

            .stat-icon {
                width: 42px;
                height: 42px;
                font-size: 20px;
            }

            .stat-title {
                font-size: 12px;
            }

            .stat-value {
                font-size: 20px;
            }

            .stat-trend {
                font-size: 11px;
            }

            .chart-container {
                padding: 12px;
            }

            .chart-title {
                font-size: 16px;
            }

            #transactionChart {
                max-height: 180px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1 class="bank-name">SCHOBANK</h1>
        </div>
        
        <div class="sidebar-content">
            <div class="sidebar-menu">
                <div class="menu-label">Menu Utama</div>
                <div class="menu-item">
                    <a href="dashboard.php" class="active">
                        <i class="fas fa-home"></i> Dashboard
                    </a>
                </div>
                <div class="menu-item">
                    <button class="dropdown-btn" id="dataDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-database"></i> Tambah Data
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="dataDropdownContainer">
                        <a href="tambah_jurusan.php">
                            <i class="fas fa-graduation-cap"></i> Tambah Jurusan
                        </a>
                        <a href="tambah_kelas.php">
                            <i class="fas fa-chalkboard"></i> Tambah Kelas
                        </a>
                        <a href="tambah_nasabah.php">
                            <i class="fas fa-user-plus"></i> Tambah Siswa
                        </a>
                        <a href="tambah_petugas.php">
                            <i class="fas fa-user-shield"></i> Tambah Petugas
                        </a>
                        <a href="buat_jadwal.php">
                            <i class="fas fa-user-cog"></i> Tambah Jadwal Petugas
                        </a>
                    </div>
                </div>

                <div class="menu-item">
                    <button class="dropdown-btn" id="rekapDropdown">
                        <div class="menu-icon">
                            <i class="fas fa-file-alt"></i> Rekap Data
                        </div>
                        <i class="fas fa-chevron-down arrow"></i>
                    </button>
                    <div class="dropdown-container" id="rekapDropdownContainer">
                        <a href="laporan.php">
                            <i class="fas fa-file-alt"></i> Rekap Transaksi Petugas
                        </a>
                        <a href="rekap_absen.php">
                            <i class="fas fa-user-check"></i> Rekap Absen Petugas
                        </a>
                        <a href="data_siswa.php">
                            <i class="fas fa-id-card"></i> Rekap Data Siswa
                        </a>
                    </div>
                </div>

                <div class="menu-item">
                    <a href="pemulihan_akun.php">
                        <i class="fas fa-user-lock"></i> Pemulihan Akun Siswa
                    </a>
                </div>

                <div class="menu-label">Manajemen Saldo</div>
                <div class="menu-item">
                    <a href="tambah_saldo_bersih.php">
                        <i class="fas fa-hand-holding-usd"></i> Transfer Saldo ke Petugas
                    </a>
                </div>

                <div class="menu-label">Pengaturan</div>
                <div class="menu-item">
                    <a href="pengaturan.php">
                        <i class="fas fa-cog"></i> Pengaturan
                    </a>
                </div>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <div class="menu-item">
                <a href="../../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </div>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Selamat Datang, Admin!</h2>
                <div class="date">
                    <i class="far fa-calendar-alt"></i> <?= getHariIndonesia(date('Y-m-d')) . ', ' . formatTanggalIndonesia(date('Y-m-d')) ?>
                </div>
            </div>
        </div>

        <div class="summary-section">
            <div class="summary-header">
                <h2>Ringkasan Data</h2>
            </div>
            
            <div class="stats-container">
                <div class="stat-box students">
                    <div class="stat-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Siswa</div>
                        <div class="stat-value"><?= number_format($total_siswa) ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-user-graduate"></i>
                            <span>Siswa Aktif</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box balance">
                    <div class="stat-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Total Saldo</div>
                        <div class="stat-value">Rp <?= number_format($total_saldo, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-chart-line"></i>
                            <span>Total Dana Nasabah</span>
                        </div>
                    </div>
                </div>
                
                <div class="stat-box setor">
                    <div class="stat-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Net Setoran Harian</div>
                        <div class="stat-value">Rp <?= number_format($saldo_harian, 0, ',', '.') ?></div>
                        <div class="stat-trend">
                            <i class="fas fa-calendar-day"></i>
                            <span>Net Setoran Hari Ini</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="chart-container">
            <h3 class="chart-title">Transaksi 7 Hari Terakhir</h3>
            <canvas id="transactionChart"></canvas>
        </div>

        <!-- Pop-up for Students Breakdown -->
        <div class="popup-overlay" id="studentsPopup">
            <div class="popup-content">
                <h2>Rincian Siswa per Kelas</h2>
                <?php if (empty($students_by_jurusan)): ?>
                    <div class="empty-state">Tidak ada data siswa tersedia.</div>
                <?php else: ?>
                    <?php foreach ($students_by_jurusan as $jurusan => $data): ?>
                        <div class="jurusan-card">
                            <div class="jurusan-header">
                                <div>
                                    <i class="fas fa-graduation-cap logo"></i>
                                    <span><?= htmlspecialchars($jurusan) ?></span>
                                </div>
                                <i class="fas fa-chevron-down arrow"></i>
                            </div>
                            <div class="kelas-content">
                                <?php foreach ($data['kelas_list'] as $kelas): ?>
                                    <div class="kelas-card">
                                        <i class="fas fa-chalkboard logo"></i>
                                        <span class="kelas-name"><?= htmlspecialchars($kelas['kelas']) ?></span>
                                        <span class="jumlah-siswa"><?= $kelas['jumlah_siswa'] ?> Siswa</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pop-up for Net Setoran Breakdown -->
        <div class="saldo-popup" id="saldoPopup">
            <div class="saldo-content">
                <h2>Rincian Net Setoran 7 Hari</h2>
                <?php if (empty($net_setoran_data)): ?>
                    <div class="empty-state">Tidak ada data setoran tersedia.</div>
                <?php else: ?>
                    <?php foreach ($net_setoran_data as $data): ?>
                        <div class="tanggal-card">
                            <div class="tanggal-header">
                                <div>
                                    <i class="fas fa-wallet logo"></i>
                                    <span><?= formatTanggalIndonesia($data['tanggal']) ?></span>
                                </div>
                                <i class="fas fa-chevron-down arrow"></i>
                            </div>
                            <div class="setoran-content">
                                <div class="setoran-value">Rp <?= number_format($data['net_setoran'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pop-up for Transfer Breakdown -->
        <div class="transfer-popup" id="transferPopup">
            <div class="transfer-content">
                <h2>Rincian Transfer Saldo 7 Hari</h2>
                <?php if (empty($transfer_data)): ?>
                    <div class="empty-state">Tidak ada data transfer tersedia.</div>
                <?php else: ?>
                    <?php foreach ($transfer_data as $data): ?>
                        <div class="tanggal-card">
                            <div class="tanggal-header">
                                <div>
                                    <i class="fas fa-hand-holding-usd logo"></i>
                                    <span><?= formatTanggalIndonesia($data['tanggal']) ?></span>
                                </div>
                                <i class="fas fa-chevron-down arrow"></i>
                            </div>
                            <div class="transfer-content">
                                <div class="transfer-value">Rp <?= number_format($data['total_transfer'], 0, ',', '.') ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Chart.js configuration
            const ctx = document.getElementById('transactionChart').getContext('2d');
            const transactionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_column($chart_data, 'tanggal')) ?>,
                    datasets: [{
                        label: 'Jumlah Transaksi',
                        data: <?= json_encode(array_column($chart_data, 'jumlah_transaksi')) ?>,
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 3,
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBackgroundColor: 'rgba(75, 192, 192, 1)',
                        pointBorderColor: '#fff',
                        pointStyle: 'circle',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Jumlah Transaksi'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Tanggal'
                            }
                        }
                    },
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: window.innerWidth <= 768 ? 1.5 : 2.5,
                    animation: {
                        duration: 1000,
                        easing: 'easeInOutQuad'
                    }
                }
            });

            // Sidebar toggle for mobile
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', function(e) {
                if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Dropdown functionality (only one open at a time)
            const dropdownButtons = document.querySelectorAll('.dropdown-btn');
            dropdownButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const dropdownContainer = this.nextElementSibling;
                    const isActive = this.classList.contains('active');

                    dropdownButtons.forEach(btn => {
                        const container = btn.nextElementSibling;
                        btn.classList.remove('active');
                        container.classList.remove('show');
                    });

                    if (!isActive) {
                        this.classList.add('active');
                        dropdownContainer.classList.add('show');
                    }
                });
            });

            document.addEventListener('click', function(e) {
                dropdownButtons.forEach(button => {
                    const dropdownContainer = button.nextElementSibling;
                    if (!button.contains(e.target)) {
                        button.classList.remove('active');
                        dropdownContainer.classList.remove('show');
                    }
                });
            });

            // Students Pop-up functionality
            const studentsBox = document.querySelector('.stat-box.students');
            const studentsPopup = document.getElementById('studentsPopup');
            if (studentsBox && studentsPopup) {
                studentsBox.addEventListener('click', function(e) {
                    e.stopPropagation();
                    studentsPopup.classList.add('active');
                    document.body.classList.add('popup-active');
                });

                studentsPopup.addEventListener('click', function(e) {
                    if (e.target === studentsPopup) {
                        studentsPopup.classList.remove('active');
                        document.body.classList.remove('popup-active');
                    }
                });

                const popupContent = document.querySelector('.popup-content');
                if (popupContent) {
                    popupContent.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
            }

            // Saldo Pop-up functionality
            const saldoBox = document.querySelector('.stat-box.setor');
            const saldoPopup = document.getElementById('saldoPopup');
            if (saldoBox && saldoPopup) {
                saldoBox.addEventListener('click', function(e) {
                    e.stopPropagation();
                    saldoPopup.classList.add('active');
                    document.body.classList.add('popup-active');
                });

                saldoPopup.addEventListener('click', function(e) {
                    if (e.target === saldoPopup) {
                        saldoPopup.classList.remove('active');
                        document.body.classList.remove('popup-active');
                    }
                });

                const saldoContent = document.querySelector('.saldo-content');
                if (saldoContent) {
                    saldoContent.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
            }

            // Transfer Pop-up functionality
            const transferBox = document.querySelector('.stat-box.clean-balance');
            const transferPopup = document.getElementById('transferPopup');
            if (transferBox && transferPopup) {
                transferBox.addEventListener('click', function(e) {
                    e.stopPropagation();
                    transferPopup.classList.add('active');
                    document.body.classList.add('popup-active');
                });

                transferPopup.addEventListener('click', function(e) {
                    if (e.target === transferPopup) {
                        transferPopup.classList.remove('active');
                        document.body.classList.remove('popup-active');
                    }
                });

                const transferContent = document.querySelector('.transfer-content');
                if (transferContent) {
                    transferContent.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
            }

            // Jurusan card toggle (single-open)
            const jurusanHeaders = document.querySelectorAll('.jurusan-header');
            jurusanHeaders.forEach(header => {
                header.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const jurusanCard = this.parentElement;
                    const kelasContent = this.nextElementSibling;
                    const isActive = jurusanCard.classList.contains('active');

                    document.querySelectorAll('.jurusan-card').forEach(card => {
                        card.classList.remove('active');
                        card.querySelector('.kelas-content').classList.remove('show');
                        card.querySelector('.jurusan-header').classList.remove('active');
                    });

                    if (!isActive) {
                        jurusanCard.classList.add('active');
                        kelasContent.classList.add('show');
                        this.classList.add('active');
                    }
                });
            });

            // Tanggal card toggle (single-open for saldo and transfer)
            const tanggalHeaders = document.querySelectorAll('.tanggal-header');
            tanggalHeaders.forEach(header => {
                header.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const tanggalCard = this.parentElement;
                    const content = this.nextElementSibling;
                    const isActive = tanggalCard.classList.contains('active');

                    tanggalCard.parentElement.querySelectorAll('.tanggal-card').forEach(card => {
                        card.classList.remove('active');
                        card.querySelector('.setoran-content, .transfer-content').classList.remove('show');
                        card.querySelector('.tanggal-header').classList.remove('active');
                    });

                    if (!isActive) {
                        tanggalCard.classList.add('active');
                        content.classList.add('show');
                        this.classList.add('active');
                    }
                });
            });
        });
    </script>
</body>
</html>