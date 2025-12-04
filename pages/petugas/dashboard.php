<?php
// dashboard.php
require_once '../../includes/auth.php';
require_once '../../includes/session_validator.php';
require_once '../../includes/db_connection.php';
$username = $_SESSION['username'] ?? 'Petugas';
$petugas_id = $_SESSION['user_id'] ?? 0;
date_default_timezone_set('Asia/Jakarta');
// Check petugas block status
$query_block = "SELECT is_blocked FROM petugas_block_status WHERE id = 1";
$result_block = $conn->query($query_block);
$is_blocked = $result_block->fetch_assoc()['is_blocked'] ?? 0;
// Get today's officer schedule
$query_schedule = "SELECT pt.*,
                   u1.nama AS petugas1_nama,
                   u2.nama AS petugas2_nama
                   FROM petugas_tugas pt
                   LEFT JOIN users u1 ON pt.petugas1_id = u1.id
                   LEFT JOIN users u2 ON pt.petugas2_id = u2.id
                   WHERE pt.tanggal = CURDATE()";
$stmt_schedule = $conn->prepare($query_schedule);
$stmt_schedule->execute();
$result_schedule = $stmt_schedule->get_result();
$schedule = $result_schedule->fetch_assoc();
$petugas1_nama = $schedule ? $schedule['petugas1_nama'] : 'Tidak Ada';
$petugas2_nama = $schedule ? $schedule['petugas2_nama'] : 'Tidak Ada';
// Check attendance
$at_least_one_checked_in = false;
$petugas1_checked_in = false;
$petugas2_checked_in = false;
$petugas1_active = false;
$petugas2_active = false;
if ($schedule) {
    $query_attendance = "SELECT * FROM absensi
                        WHERE tanggal = CURDATE()
                        AND ((petugas_type = 'petugas1' AND petugas1_status = 'hadir')
                             OR (petugas_type = 'petugas2' AND petugas2_status = 'hadir'))";
    $stmt_attendance = $conn->prepare($query_attendance);
    $stmt_attendance->execute();
    $result_attendance = $stmt_attendance->get_result();
    $attendance_records = $result_attendance->fetch_all(MYSQLI_ASSOC);
   
    foreach ($attendance_records as $record) {
        if ($record['petugas_type'] === 'petugas1' && $record['petugas1_status'] === 'hadir') {
            $petugas1_checked_in = true;
        }
        if ($record['petugas_type'] === 'petugas2' && $record['petugas2_status'] === 'hadir') {
            $petugas2_checked_in = true;
        }
    }
   
    $at_least_one_checked_in = $petugas1_checked_in || $petugas2_checked_in;
}
$at_least_one_active = false;
if ($schedule) {
    $query_active = "SELECT * FROM absensi
                    WHERE tanggal = CURDATE()
                    AND ((petugas_type = 'petugas1' AND petugas1_status = 'hadir' AND waktu_keluar IS NULL)
                         OR (petugas_type = 'petugas2' AND petugas2_status = 'hadir' AND waktu_keluar IS NULL))";
    $stmt_active = $conn->prepare($query_active);
    $stmt_active->execute();
    $result_active = $stmt_active->get_result();
    $active_records = $result_active->fetch_all(MYSQLI_ASSOC);
   
    foreach ($active_records as $record) {
        if ($record['petugas_type'] === 'petugas1' && $record['petugas1_status'] === 'hadir' && is_null($record['waktu_keluar'])) {
            $petugas1_active = true;
        }
        if ($record['petugas_type'] === 'petugas2' && $record['petugas2_status'] === 'hadir' && is_null($record['waktu_keluar'])) {
            $petugas2_active = true;
        }
    }
   
    $at_least_one_active = $petugas1_active || $petugas2_active;
}
// Transaction data
$total_transaksi = 0;
$uang_masuk = 0;
$uang_keluar = 0;
$saldo_bersih = 0;
$query = "SELECT COUNT(t.id) as total_transaksi
          FROM transaksi t
          INNER JOIN users u ON t.petugas_id = u.id
          WHERE DATE(t.created_at) = CURDATE()
          AND t.jenis_transaksi IN ('setor', 'tarik')
          AND t.status = 'approved'
          AND u.role = 'petugas'";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$total_transaksi = $result->fetch_assoc()['total_transaksi'] ?? 0;
$query = "SELECT COALESCE(SUM(t.jumlah), 0) as uang_masuk
          FROM transaksi t
          INNER JOIN users u ON t.petugas_id = u.id
          WHERE t.jenis_transaksi = 'setor'
          AND DATE(t.created_at) = CURDATE()
          AND t.status = 'approved'
          AND u.role = 'petugas'";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$uang_masuk = $result->fetch_assoc()['uang_masuk'] ?? 0;
$query = "SELECT COALESCE(SUM(t.jumlah), 0) as uang_keluar
          FROM transaksi t
          INNER JOIN users u ON t.petugas_id = u.id
          WHERE t.jenis_transaksi = 'tarik'
          AND DATE(t.created_at) = CURDATE()
          AND t.status = 'approved'
          AND u.role = 'petugas'";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
$uang_keluar = $result->fetch_assoc()['uang_keluar'] ?? 0;
$saldo_bersih = $uang_masuk - $uang_keluar;
// Chart data - Weekly
$chart_labels = [];
$chart_setor = [];
$chart_tarik = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d M', strtotime($date));
   
    $query_daily = "SELECT
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'setor' THEN t.jumlah ELSE 0 END), 0) as setor,
        COALESCE(SUM(CASE WHEN t.jenis_transaksi = 'tarik' THEN t.jumlah ELSE 0 END), 0) as tarik
    FROM transaksi t
    INNER JOIN users u ON t.petugas_id = u.id
    WHERE DATE(t.created_at) = '$date'
        AND t.status = 'approved'
        AND u.role = 'petugas'";
   
    $result_daily = $conn->query($query_daily);
    if ($result_daily && $row = $result_daily->fetch_assoc()) {
        $chart_setor[] = (float)$row['setor'];
        $chart_tarik[] = (float)$row['tarik'];
    } else {
        $chart_setor[] = 0;
        $chart_tarik[] = 0;
    }
}
// Check if chart has data
$has_weekly_data = array_sum($chart_setor) > 0 || array_sum($chart_tarik) > 0;
// Recent transactions
$query_recent = "SELECT t.*,
                 u.nama as siswa_nama,
                 k.nama_kelas,
                 j.nama_jurusan,
                 tk.nama_tingkatan,
                 p.nama as petugas_nama,
                 r.no_rekening
                 FROM transaksi t
                 LEFT JOIN rekening r ON t.rekening_id = r.id
                 LEFT JOIN users u ON r.user_id = u.id
                 LEFT JOIN kelas k ON u.kelas_id = k.id
                 LEFT JOIN jurusan j ON k.jurusan_id = j.id
                 LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                 INNER JOIN users p ON t.petugas_id = p.id
                 WHERE DATE(t.created_at) = CURDATE()
                 AND t.status = 'approved'
                 AND t.jenis_transaksi IN ('setor', 'tarik')
                 AND p.role = 'petugas'
                 ORDER BY t.created_at DESC
                 LIMIT 5";
$stmt_recent = $conn->prepare($query_recent);
$stmt_recent->execute();
$result_recent = $stmt_recent->get_result();
$recent_transactions = $result_recent->fetch_all(MYSQLI_ASSOC);
function tanggal_indonesia($tanggal) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $hari = [
        'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'
    ];
    $tanggal = strtotime($tanggal);
    $hari_ini = $hari[date('l', $tanggal)];
    $tanggal_format = date('d', $tanggal);
    $bulan_ini = $bulan[date('n', $tanggal)];
    $tahun = date('Y', $tanggal);
    return "$hari_ini, $tanggal_format $bulan_ini $tahun";
}
function format_waktu($datetime) {
    return date('H:i', strtotime($datetime));
}
function format_kelas($tingkatan, $nama_kelas, $jurusan) {
    if ($tingkatan && $nama_kelas && $jurusan) {
        return "$tingkatan $nama_kelas $jurusan";
    } elseif ($tingkatan && $nama_kelas) {
        return "$tingkatan $nama_kelas";
    } elseif ($nama_kelas) {
        return $nama_kelas;
    }
    return 'Belum Ada Kelas';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Dashboard | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --success-color: #3b82f6;
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;
            --bg-light: #f8fafc;
            --bg-white: #ffffff;
            --border-light: #e5e7eb;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
        html {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }
        body {
            background: #f9fafb;
            color: var(--text-primary);
            display: flex;
            font-size: 0.95rem;
            line-height: 1.6;
            min-height: 100vh;
        }
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            position: fixed;
            height: 100%;
            display: flex;
            flex-direction: column;
            z-index: 100;
            box-shadow: 5px 0 20px rgba(0, 0, 0, 0.15);
            transition: transform 0.3s ease-in-out;
        }
        .sidebar-header {
            padding: 20px;
            background: var(--primary-dark);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            top: 0;
            z-index: 101;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sidebar-header .bank-name {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin: 0;
            color: white;
            text-transform: uppercase;
            text-align: center;
        }
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding-top: 10px;
            padding-bottom: 10px;
        }
        .sidebar-footer {
            background: var(--primary-dark);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            position: sticky;
            bottom: 0;
            z-index: 101;
            padding: 5px 0;
        }
        .sidebar-content::-webkit-scrollbar { width: 8px; }
        .sidebar-content::-webkit-scrollbar-track { background: var(--scrollbar-track); border-radius: 4px; }
        .sidebar-content::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); border-radius: 4px; border: 2px solid var(--scrollbar-track); }
        .sidebar-content::-webkit-scrollbar-thumb:hover { background: var(--scrollbar-thumb-hover); }
        .sidebar-menu { padding: 10px 0; }
        .menu-label {
            padding: 15px 25px 10px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.6);
            font-weight: 500;
            margin-top: 10px;
        }
        .menu-item { position: relative; margin: 5px 0; }
        .menu-item a {
            display: flex;
            align-items: center;
            padding: 14px 25px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
            font-size: 0.9rem;
        }
        .menu-item a:hover, .menu-item a.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }
        .menu-item a.disabled {
            pointer-events: none;
            color: #ccc;
            cursor: not-allowed;
        }
        .menu-item i {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
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
            font-size: 0.9rem;
            transition: var(--transition);
            border-left: 4px solid transparent;
            font-weight: 400;
        }
        .dropdown-btn:hover, .dropdown-btn.active {
            background-color: rgba(255, 255, 255, 0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }
        .dropdown-btn .menu-icon { display: flex; align-items: center; }
        .dropdown-btn .menu-icon i:first-child {
            margin-right: 12px;
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }
        .dropdown-btn .arrow { transition: transform 0.3s ease; font-size: 0.8rem; }
        .dropdown-btn.active .arrow { transform: rotate(180deg); }
        .dropdown-container {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(0, 0, 0, 0.15);
        }
        .dropdown-container.show { max-height: 300px; }
        .dropdown-container a {
            padding: 12px 20px 12px 60px;
            display: flex;
            align-items: center;
            color: rgba(255, 255, 255, 0.75);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.85rem;
            border-left: 4px solid transparent;
        }
        .dropdown-container a:hover, .dropdown-container a.active {
            background-color: rgba(255, 255, 255, 0.08);
            border-left-color: var(--secondary-color);
            color: white;
        }
        .logout-btn { color: var(--danger-color); font-weight: 500; }
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 0;
            transition: var(--transition);
            max-width: calc(100% - 280px);
            overflow-y: auto;
            min-height: 100vh;
            background: #f9fafb;
        }
        .top-navbar {
            background: var(--bg-white);
            border-bottom: 1px solid var(--border-light);
            padding: 0 30px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 90;
            box-shadow: var(--shadow-sm);
        }
        .navbar-left { display: flex; align-items: center; gap: 20px; }
        .menu-toggle {
            display: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            cursor: pointer;
            font-size: 1.1rem;
            transition: var(--transition);
            align-items: center;
            justify-content: center;
        }
        .menu-toggle:hover { background: var(--bg-light); color: var(--text-primary); }
        .page-title-text {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        .navbar-right { display: flex; align-items: center; gap: 24px; }
        .navbar-time { display: flex; align-items: center; gap: 8px; }
        .navbar-time i { color: var(--text-muted); font-size: 0.95rem; }
        .time-text { font-size: 0.9rem; font-weight: 500; color: var(--text-secondary); }
        .navbar-divider { width: 1px; height: 28px; background: var(--border-light); }
        .officers-container { display: flex; align-items: center; gap: 16px; }
        .officer-profile {
            display: flex;
            align-items: center;
            gap: 10px;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
        }
        .officer-profile:hover .officer-name { color: var(--text-primary); }
        .officer-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            font-size: 1.1rem;
            transition: var(--transition);
            position: relative;
        }
        .officer-profile.active .officer-icon { background: #dbeafe; color: var(--success-color); }
        .officer-info { display: flex; flex-direction: column; gap: 2px; }
        .officer-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
            line-height: 1.2;
            transition: var(--transition);
        }
        .officer-profile.active .officer-name { color: var(--text-primary); font-weight: 600; }
        .officer-badge {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 500;
        }
        .officer-profile.active .officer-badge { color: var(--success-color); }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #d1d5db;
            position: absolute;
            top: 0;
            right: 0;
            border: 2px solid white;
        }
        .officer-profile.active .status-dot {
            background: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
            animation: pulse 2s infinite;
        }
        .content-wrapper {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        /* Alert Message */
        .alert-message {
            background: var(--bg-white);
            border-radius: 8px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 14px;
            color: var(--text-primary);
            font-size: 0.9rem;
            border: 1px solid var(--border-light);
        }
        .alert-message i {
            color: var(--text-muted);
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .summary-section { padding: 0 0 30px; }
        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 16px;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-box {
            background: var(--bg-white);
            border-radius: 8px;
            padding: 24px;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            display: flex;
            flex-direction: column;
        }
        .stat-box:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            margin-bottom: 16px;
            color: white;
            background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
        }
        .stat-title { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
        .stat-value { font-size: 1.75rem; font-weight: 700; margin-bottom: 12px; color: var(--text-primary); font-family: 'Poppins', sans-serif; }
        .stat-trend { display: flex; align-items: center; font-size: 0.8rem; color: var(--text-muted); margin-top: auto; gap: 6px; }
        .stat-trend i { font-size: 0.75rem; }
        .charts-section { margin-bottom: 30px; }
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .chart-card {
            background: var(--bg-white);
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
            position: relative;
        }
        .chart-header { margin-bottom: 16px; }
        .chart-title { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 4px; }
        .chart-subtitle { font-size: 0.8rem; color: var(--text-muted); }
        .chart-wrapper {
            position: relative;
            width: 100%;
            height: 300px;
        }
        .chart-wrapper canvas {
            width: 100% !important;
            height: 100% !important;
        }
        .chart-empty-state {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        .chart-empty-state i {
            font-size: 3.5rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .chart-empty-state p {
            font-size: 0.95rem;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .chart-empty-state small {
            font-size: 0.8rem;
            opacity: 0.7;
        }
        /* ===== TRANSACTION LIST IN CARD ===== */
        .transaction-list {
            display: flex;
            flex-direction: column;
            gap: 0;
            margin-top: 8px;
            max-height: 300px;
            overflow-y: auto;
        }
        .transaction-list::-webkit-scrollbar { width: 6px; }
        .transaction-list::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .transaction-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .transaction-list::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
        .transaction-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .transaction-item:last-child {
            border-bottom: none;
        }
        .transaction-info {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            color: white;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        .transaction-icon.setor {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
       
        .transaction-icon.tarik {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .transaction-details {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .transaction-name {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            margin-bottom: 0;
            font-family: 'Poppins', sans-serif;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .transaction-meta {
            font-size: 0.7rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .transaction-type-badge {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--text-muted);
        }
        .transaction-type-badge.setor {
            color: #2563eb;
        }
        .transaction-type-badge.tarik {
            color: #dc2626;
        }
        .transaction-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            flex-shrink: 0;
            margin-left: 12px;
        }
        .transaction-amount {
            font-weight: 700;
            font-size: 0.9rem;
            font-family: 'Poppins', monospace;
            white-space: nowrap;
        }
        .transaction-amount.setor { color: var(--success-color); }
        .transaction-amount.tarik { color: var(--danger-color); }
        .transaction-time {
            font-size: 0.65rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 3px;
        }
        .transaction-time i {
            font-size: 0.6rem;
        }
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.2;
        }
       
        .empty-state p {
            font-size: 0.85rem;
            font-weight: 500;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        @media (min-width: 1200px) {
            .chart-wrapper { height: 320px; }
            .transaction-list { max-height: 320px; }
        }
        @media (max-width: 1024px) {
            .charts-grid { grid-template-columns: 1fr; }
            .chart-wrapper { height: 280px; }
            .transaction-list { max-height: 280px; }
            .content-wrapper { padding: 25px; }
        }
        @media (max-width: 768px) {
            .menu-toggle { display: flex; }
            .sidebar { transform: translateX(-100%); width: 260px; }
            .sidebar.active { transform: translateX(0); z-index: 999; }
            body.sidebar-active::before {
                content: '';
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                z-index: 998;
                opacity: 1;
            }
            body:not(.sidebar-active)::before { opacity: 0; pointer-events: none; }
            .main-content { margin-left: 0; max-width: 100%; }
            body.sidebar-active .main-content { opacity: 0.3; pointer-events: none; }
            .top-navbar { padding: 0 15px; }
            .page-title-text { font-size: 1.1rem; }
            .officer-info { display: none; }
            .navbar-divider { display: none; }
            .navbar-time { display: none; }
            .officers-container { gap: 12px; }
            .content-wrapper { padding: 20px 15px; }
            .stats-container { grid-template-columns: 1fr; gap: 16px; }
            .charts-grid { grid-template-columns: 1fr; gap: 16px; }
            .chart-wrapper { height: 260px; }
            .transaction-list { max-height: 260px; }
            .chart-card { padding: 18px; }
            .chart-title { font-size: 0.95rem; }
            .chart-subtitle { font-size: 0.75rem; }
            .stat-box { padding: 20px; }
            .transaction-item { padding: 12px 0; }
        }
        @media (max-width: 640px) {
            .transaction-right {
                margin-left: 10px;
            }
        }
        @media (max-width: 480px) {
            .page-title-text { display: none; }
            .content-wrapper { padding: 15px 10px; }
            .chart-wrapper { height: 220px; }
            .transaction-list { max-height: 220px; }
            .chart-card { padding: 16px; }
            .chart-title { font-size: 0.9rem; }
            .chart-subtitle { font-size: 0.7rem; }
            .stats-container { gap: 12px; }
            .stat-box { padding: 18px; }
            .stat-icon { width: 42px; height: 42px; font-size: 1.2rem; }
            .stat-value { font-size: 1.5rem; }
            .section-title { font-size: 1rem; }
            .transaction-icon { width: 36px; height: 36px; font-size: 0.9rem; }
            .transaction-name { font-size: 0.8rem; }
            .transaction-meta { font-size: 0.65rem; }
            .transaction-amount { font-size: 0.85rem; }
            .transaction-right {
                margin-left: 8px;
            }
        }
        @media (max-width: 360px) {
            .chart-wrapper { height: 200px; }
            .transaction-list { max-height: 200px; }
            .chart-card { padding: 14px; }
            .stat-box { padding: 16px; }
            .stat-value { font-size: 1.3rem; }
            .transaction-icon { width: 34px; height: 34px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_petugas.php'; ?>
    <div class="main-content" id="mainContent">
        <div class="top-navbar">
            <div class="navbar-left">
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="page-title-text">Dashboard</h1>
            </div>
            <div class="navbar-right">
                <div class="navbar-time">
                    <i class="far fa-clock"></i>
                    <span class="time-text" id="currentTime"></span>
                </div>
                <div class="navbar-divider"></div>
                <div class="officers-container">
                    <div class="officer-profile <?= $petugas1_checked_in && $petugas1_active ? 'active' : '' ?>">
                        <div class="officer-icon">
                            <i class="fas fa-user"></i>
                            <?php if ($petugas1_checked_in && $petugas1_active): ?>
                            <span class="status-dot"></span>
                            <?php endif; ?>
                        </div>
                        <div class="officer-info">
                            <span class="officer-name"><?= htmlspecialchars($petugas1_nama) ?></span>
                            <span class="officer-badge">Petugas 1</span>
                        </div>
                    </div>
                    <div class="officer-profile <?= $petugas2_checked_in && $petugas2_active ? 'active' : '' ?>">
                        <div class="officer-icon">
                            <i class="fas fa-user"></i>
                            <?php if ($petugas2_checked_in && $petugas2_active): ?>
                            <span class="status-dot"></span>
                            <?php endif; ?>
                        </div>
                        <div class="officer-info">
                            <span class="officer-name"><?= htmlspecialchars($petugas2_nama) ?></span>
                            <span class="officer-badge">Petugas 2</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="content-wrapper">
            <?php if ($is_blocked): ?>
                <div class="alert-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Semua layanan teller telah ditutup sementara. Silakan hubungi admin untuk informasi lebih lanjut.</span>
                </div>
            <?php elseif ($schedule && !$at_least_one_checked_in): ?>
                <div class="alert-message">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>Silakan melakukan absensi terlebih dahulu untuk memulai layanan.</span>
                </div>
            <?php elseif ($schedule && !$at_least_one_active): ?>
                <div class="alert-message">
                    <i class="fas fa-check-circle"></i>
                    <span>Terima kasih atas dedikasi Anda hari ini. Sampai jumpa besok!</span>
                </div>
            <?php endif; ?>
            <div class="summary-section">
                <h2 class="section-title">Ikhtisar Operasional Harian</h2>
               
                <div class="stats-container">
                    <div class="stat-box transactions">
                        <div class="stat-icon"><i class="fas fa-exchange-alt"></i></div>
                        <div class="stat-content">
                            <div class="stat-title">Jumlah Transaksi</div>
                            <div class="stat-value counter" data-target="<?= $total_transaksi ?>">0</div>
                            <div class="stat-trend">
                                <i class="fas fa-clock"></i>
                                <span>Total Hari Ini</span>
                            </div>
                        </div>
                    </div>
                   
                    <div class="stat-box income">
                        <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                        <div class="stat-content">
                            <div class="stat-title">Dana Masuk</div>
                            <div class="stat-value counter" data-target="<?= $uang_masuk ?>" data-prefix="Rp ">0</div>
                            <div class="stat-trend">
                                <i class="fas fa-arrow-circle-down"></i>
                                <span>Total Simpanan</span>
                            </div>
                        </div>
                    </div>
                   
                    <div class="stat-box expense">
                        <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
                        <div class="stat-content">
                            <div class="stat-title">Dana Keluar</div>
                            <div class="stat-value counter" data-target="<?= $uang_keluar ?>" data-prefix="Rp ">0</div>
                            <div class="stat-trend">
                                <i class="fas fa-arrow-circle-up"></i>
                                <span>Total Penarikan</span>
                            </div>
                        </div>
                    </div>
                   
                    <div class="stat-box balance">
                        <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                        <div class="stat-content">
                            <div class="stat-title">Saldo Operasional</div>
                            <div class="stat-value counter" data-target="<?= $saldo_bersih ?>" data-prefix="Rp ">0</div>
                            <div class="stat-trend">
                                <i class="fas fa-chart-line"></i>
                                <span>Selisih Kas Harian</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="charts-section">
                <h2 class="section-title">Laporan Pergerakan Dana</h2>
               
                <div class="charts-grid">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Grafik Mingguan</h3>
                            <p class="chart-subtitle">Pergerakan dana 7 hari terakhir</p>
                        </div>
                        <div class="chart-wrapper">
                            <?php if ($has_weekly_data): ?>
                                <canvas id="weeklyChart"></canvas>
                            <?php else: ?>
                                <div class="chart-empty-state">
                                    <i class="fas fa-chart-line"></i>
                                    <p>Tidak Ada Data</p>
                                    <small>Belum ada aktivitas transaksi</small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3 class="chart-title">Mutasi Rekening Terbaru</h3>
                            <p class="chart-subtitle">5 transaksi terakhir hari ini</p>
                        </div>
                       
                        <div class="transaction-list">
                            <?php if (count($recent_transactions) > 0): ?>
                                <?php foreach ($recent_transactions as $trans): ?>
                                    <div class="transaction-item">
                                        <div class="transaction-info">
                                            <div class="transaction-icon <?= $trans['jenis_transaksi'] ?>">
                                                <i class="fas fa-<?= $trans['jenis_transaksi'] == 'setor' ? 'arrow-down' : 'arrow-up' ?>"></i>
                                            </div>
                                            <div class="transaction-details">
                                                <div class="transaction-name"><?= htmlspecialchars($trans['siswa_nama']) ?></div>
                                                <div class="transaction-meta">
                                                    <span class="transaction-type-badge <?= $trans['jenis_transaksi'] ?>">
                                                        <?= $trans['jenis_transaksi'] == 'setor' ? 'Kredit' : 'Debit' ?>
                                                    </span>
                                                    <span>•</span>
                                                    <span><?= htmlspecialchars(format_kelas($trans['nama_tingkatan'], $trans['nama_kelas'], $trans['nama_jurusan'])) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="transaction-right">
                                            <div class="transaction-amount <?= $trans['jenis_transaksi'] ?>">
                                                <?= $trans['jenis_transaksi'] == 'setor' ? '+' : '-' ?>Rp <?= number_format($trans['jumlah'], 0, ',', '.') ?>
                                            </div>
                                            <div class="transaction-time">
                                                <i class="far fa-clock"></i> <?= format_waktu($trans['created_at']) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p>Belum ada mutasi hari ini</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function updateClock() {
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const seconds = String(now.getSeconds()).padStart(2, '0');
                const timeEl = document.getElementById('currentTime');
                if (timeEl) {
                    timeEl.textContent = `${hours}:${minutes}:${seconds}`;
                }
            }
            updateClock();
            setInterval(updateClock, 1000);
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
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            const dropdownButtons = document.querySelectorAll('.dropdown-btn');
            dropdownButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (this.classList.contains('disabled')) return;
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
            const counters = document.querySelectorAll('.counter');
            counters.forEach(counter => {
                const updateCount = () => {
                    const target = +counter.getAttribute('data-target');
                    const prefix = counter.getAttribute('data-prefix') || '';
                    const count = +counter.innerText.replace(/[^0-9]/g, '') || 0;
                    const increment = target / 100;
                    if (count < target) {
                        let newCount = Math.ceil(count + increment);
                        if (newCount > target) newCount = target;
                        counter.innerText = prefix + newCount.toLocaleString('id-ID');
                        setTimeout(updateCount, 20);
                    } else {
                        counter.innerText = prefix + target.toLocaleString('id-ID');
                    }
                };
                updateCount();
            });
            try {
                if (typeof Chart === 'undefined') {
                    console.error('Chart.js not loaded');
                    return;
                }
                const weeklyCanvas = document.getElementById('weeklyChart');
                if (weeklyCanvas) {
                    const weeklyCtx = weeklyCanvas.getContext('2d');
                    const weeklyChart = new Chart(weeklyCtx, {
                        type: 'line',
                        data: {
                            labels: <?php echo json_encode($chart_labels); ?>,
                            datasets: [
                                {
                                    label: 'Simpanan (Kredit)',
                                    data: <?php echo json_encode($chart_setor); ?>,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                    borderWidth: 2,
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Penarikan (Debit)',
                                    data: <?php echo json_encode($chart_tarik); ?>,
                                    borderColor: '#ef4444',
                                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                    borderWidth: 2,
                                    fill: true,
                                    tension: 0.4
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: true, position: 'top' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': Rp ' + context.parsed.y.toLocaleString('id-ID');
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return 'Rp ' + value.toLocaleString('id-ID');
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error creating charts:', error);
            }
        });
    </script>
</body>
</html>