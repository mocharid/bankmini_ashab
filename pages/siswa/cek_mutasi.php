<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}
// Pastikan user adalah siswa
$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'siswa') {
    header("Location: ../../unauthorized.php");
    exit();
}
$user_id = $_SESSION['user_id'];
// Ambil info siswa dan rekening
$siswa_info = null;
$mutasi_data = [];
$error_message = '';
$success_message = '';
// Filter parameters
$filter_jenis = $_GET['jenis'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_start_date = $_GET['start_date'] ?? '';
$filter_end_date = $_GET['end_date'] ?? '';
$where_conditions = "WHERE rek.user_id = ? AND (t.jenis_transaksi != 'transaksi_qr' OR t.jenis_transaksi IS NULL OR t.jenis_transaksi IS NOT NULL)";
$params = [$user_id];
$types = "i";
if (!empty($filter_jenis)) {
    if ($filter_jenis === 'transfer_masuk') {
        $where_conditions .= " AND (t.jenis_transaksi IS NULL OR (t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_tujuan_id))";
    } elseif ($filter_jenis === 'transfer_keluar') {
        $where_conditions .= " AND t.jenis_transaksi = 'transfer' AND m.rekening_id = t.rekening_id";
    } else {
        $where_conditions .= " AND t.jenis_transaksi = ?";
        $params[] = $filter_jenis;
        $types .= "s";
    }
}
if (!empty($filter_status)) {
    $where_conditions .= " AND t.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}
if (!empty($filter_start_date)) {
    $where_conditions .= " AND m.created_at >= ?";
    $params[] = $filter_start_date . ' 00:00:00';
    $types .= "s";
}
if (!empty($filter_end_date)) {
    $where_conditions .= " AND m.created_at <= ?";
    $params[] = $filter_end_date . ' 23:59:59';
    $types .= "s";
}
$stmt = $conn->prepare("
    SELECT u.id, u.nama, u.nis_nisn, r.no_rekening, r.saldo, r.id as rekening_id
    FROM users u
    LEFT JOIN rekening r ON u.id = r.user_id
    WHERE u.id = ? AND u.role = 'siswa'
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$siswa_info = $result->fetch_assoc();
$stmt->close();
if (!$siswa_info) {
    $error_message = "Akun tidak ditemukan.";
} else {
    $user_rekening_id = $siswa_info['rekening_id'];
    
    // Ambil mutasi dengan filter, exclude QR, tanpa pagination
    $query = "
        SELECT m.id, m.jumlah, m.saldo_akhir, m.created_at, m.rekening_id,
               t.jenis_transaksi, t.no_transaksi, t.status, t.keterangan, t.rekening_id as transaksi_rekening_id, t.rekening_tujuan_id,
               u_petugas.nama as petugas_nama
        FROM mutasi m
        LEFT JOIN transaksi t ON m.transaksi_id = t.id
        LEFT JOIN rekening rek ON m.rekening_id = rek.id
        LEFT JOIN users u_petugas ON t.petugas_id = u_petugas.id
        $where_conditions
        ORDER BY m.created_at DESC
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $mutasi_data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Mutasi - SCHOBANK</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        html {
            scroll-padding-top: 70px;
            scroll-behavior: smooth;
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
        /* Sticky Header */
        .sticky-header {
            position: -webkit-sticky;
            position: sticky;
            top: 70px;
            left: 0;
            right: 0;
            background: var(--gray-50);
            z-index: 99;
            box-shadow: var(--shadow-sm);
            padding: 20px;
        }
        .sticky-header.stuck {
            box-shadow: var(--shadow-md);
        }
        /* Container */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 20px;
            background: var(--gray-50);
        }
        /* Page Header */
        .page-header {
            margin-bottom: 20px;
        }
        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 16px;
            letter-spacing: -0.5px;
        }
        /* Tabs */
        .tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        .tab {
            flex: 1;
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            background: var(--white);
            color: var(--gray-600);
            box-shadow: var(--shadow-sm);
        }
        .tab.active {
            background: var(--elegant-dark);
            color: var(--white);
        }
        /* Account & Filter Row */
        .account-filter-row {
            display: flex;
            gap: 12px;
            align-items: stretch;
            margin-bottom: 20px;
        }
        /* Account Info (Static, No Dropdown) */
        .account-info-box {
            flex: 1;
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 0 16px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            justify-content: center;
            height: 60px;
        }
        .account-label {
            font-size: 11px;
            color: var(--gray-600);
            margin-bottom: 4px;
            line-height: 1.2;
        }
        .account-number {
            font-size: 15px;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        /* Filter Button (Icon Only) */
        .filter-icon-btn {
            width: 60px;
            height: 60px;
            background: var(--white);
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }
        .filter-icon-btn:hover {
            background: var(--gray-50);
            border-color: var(--gray-300);
            box-shadow: var(--shadow-md);
        }
        .filter-icon-btn:active {
            transform: scale(0.98);
        }
        .filter-icon-btn i {
            font-size: 20px;
            color: var(--gray-700);
        }
        /* Filter Modal */
        .filter-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: flex-end;
        }
        .filter-modal.active {
            display: flex;
        }
        .filter-content {
            background: var(--white);
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            padding: 24px 20px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from {
                transform: translateY(100%);
            }
            to {
                transform: translateY(0);
            }
        }
        .filter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .filter-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }
        .close-btn {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--gray-100);
            color: var(--gray-600);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .close-btn:hover {
            background: var(--gray-200);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: block;
        }
        .form-control, .form-select {
            padding: 12px 14px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 15px;
            transition: all 0.2s ease;
            background: var(--white);
            color: var(--gray-900);
            width: 100%;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            height: 48px;
        }
        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23333' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 40px;
        }
        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--elegant-dark);
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.1);
        }
        .filter-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 24px;
        }
        .btn {
            padding: 14px 20px;
            border-radius: var(--radius);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            height: 48px;
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
        /* Transaction List - Grouped by Date */
        .date-group {
            margin-bottom: 24px;
        }
        .date-header {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 12px;
            padding-left: 4px;
        }
        .transaction-card {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        .transaction-card:hover {
            box-shadow: var(--shadow-md);
        }
        .transaction-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .transaction-left {
            flex: 1;
        }
        .transaction-type {
            font-size: 15px;
            font-weight: 600;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        .transaction-no {
            font-size: 11px;
            font-family: 'Courier New', monospace;
            color: var(--gray-500);
        }
        .transaction-right {
            text-align: right;
        }
        .transaction-amount {
            font-size: 16px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 4px;
        }
        .transaction-amount.debit {
            color: var(--danger);
        }
        .transaction-time {
            font-size: 11px;
            color: var(--gray-500);
        }
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        .empty-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-700);
        }
        .empty-text {
            font-size: 14px;
        }
        /* Bottom Navigation */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--gray-50);
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
                font-size: 20px;
            }
            .filter-content {
                max-height: 90vh;
            }
            .nav-logo {
                height: 38px;
                max-width: 140px;
            }
            .account-info-box {
                height: 56px;
            }
            .account-number {
                font-size: 14px;
            }
            .filter-icon-btn {
                width: 56px;
                height: 56px;
            }
        }
        @media (max-width: 480px) {
            .nav-logo {
                height: 32px;
                max-width: 120px;
            }
            .transaction-amount {
                font-size: 14px;
            }
            .transaction-type {
                font-size: 14px;
            }
            .account-info-box {
                height: 52px;
                padding: 0 12px;
            }
            .account-label {
                font-size: 10px;
            }
            .account-number {
                font-size: 13px;
            }
            .filter-icon-btn {
                width: 52px;
                height: 52px;
            }
            .filter-icon-btn i {
                font-size: 18px;
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

    <!-- Sticky Header -->
    <?php if ($siswa_info): ?>
        <div class="sticky-header" id="stickyHeader">
            <!-- Page Header -->
            <div class="page-header">
                <h1 class="page-title">Mutasi</h1>
            </div>
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active">Mutasi Transaksi</button>
                <button class="tab" onclick="window.location.href = 'e_statement.php'">e-Statement</button>
            </div>
            <!-- Account & Filter Row -->
            <div class="account-filter-row">
                <!-- Account Info (Static) -->
                <div class="account-info-box">
                    <div class="account-label">Sumber Rekening</div>
                    <div class="account-number"><?php echo htmlspecialchars($siswa_info['no_rekening']); ?></div>
                </div>
                <!-- Filter Button (Icon Only) -->
                <button class="filter-icon-btn" onclick="openFilterModal()">
                    <i class="fas fa-calendar-alt"></i>
                </button>
            </div>
        </div>

        <!-- Main Container untuk Transaksi (Scrollable) -->
        <div class="container">
            <!-- Transaction List -->
            <?php if (empty($mutasi_data)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <h3 class="empty-title">Belum Ada Transaksi</h3>
                    <p class="empty-text">Akun Anda belum memiliki riwayat transaksi</p>
                </div>
            <?php else: ?>
                <?php
                // Group transactions by date
                $grouped_data = [];
                foreach ($mutasi_data as $mutasi) {
                    $date = date('d M Y', strtotime($mutasi['created_at'] ?? 'now'));
                    if (!isset($grouped_data[$date])) {
                        $grouped_data[$date] = [];
                    }
                    $grouped_data[$date][] = $mutasi;
                }
                ?>
                <?php foreach ($grouped_data as $date => $transactions): ?>
                    <div class="date-group">
                        <div class="date-header"><?php echo $date; ?></div>
                        <?php foreach ($transactions as $mutasi): ?>
                            <?php
                            $is_debit = false;
                            $jenis_text = '';
                            if ($mutasi['jenis_transaksi'] === 'transfer') {
                                $is_debit = ($mutasi['rekening_id'] == $mutasi['transaksi_rekening_id']);
                                if ($is_debit) {
                                    $jenis_text = 'Transfer Keluar';
                                } else {
                                    $jenis_text = 'Transfer Masuk';
                                }
                            } elseif ($mutasi['jenis_transaksi'] === null) {
                                $jenis_text = 'Transfer Masuk';
                            } else {
                                $jenis_text = ucfirst(str_replace('_', ' ', $mutasi['jenis_transaksi']));
                                switch ($mutasi['jenis_transaksi']) {
                                    case 'setor':
                                        $is_debit = false;
                                        break;
                                    case 'tarik':
                                        $is_debit = true;
                                        break;
                                    default:
                                        $is_debit = true;
                                }
                            }
                            ?>
                            <div class="transaction-card">
                                <div class="transaction-header-row">
                                    <div class="transaction-left">
                                        <div class="transaction-type"><?php echo htmlspecialchars($jenis_text); ?></div>
                                        <div class="transaction-no"><?php echo htmlspecialchars($mutasi['no_transaksi'] ?? '-'); ?></div>
                                    </div>
                                    <div class="transaction-right">
                                        <div class="transaction-amount <?php echo $is_debit ? 'debit' : ''; ?>">
                                            <?php
                                            $sign = $is_debit ? '- ' : '';
                                            $jumlah = abs($mutasi['jumlah'] ?? 0);
                                            echo $sign . 'Rp' . number_format($jumlah, 0, ',', '.');
                                            ?>
                                        </div>
                                        <div class="transaction-time">
                                            <?php echo date('H:i', strtotime($mutasi['created_at'] ?? 'now')); ?> WIB
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Filter Modal -->
    <div class="filter-modal" id="filterModal" onclick="closeFilterModal(event)">
        <div class="filter-content" onclick="event.stopPropagation()">
            <div class="filter-header">
                <h3 class="filter-title">Filter Transaksi</h3>
                <button class="close-btn" onclick="closeFilterModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="GET">
                <div class="form-group">
                    <label class="form-label">Jenis Transaksi</label>
                    <select name="jenis" class="form-select">
                        <option value="">Semua Jenis</option>
                        <option value="setor" <?php echo ($filter_jenis === 'setor') ? 'selected' : ''; ?>>Setor</option>
                        <option value="tarik" <?php echo ($filter_jenis === 'tarik') ? 'selected' : ''; ?>>Tarik</option>
                        <option value="transfer" <?php echo ($filter_jenis === 'transfer') ? 'selected' : ''; ?>>Transfer</option>
                        <option value="transfer_masuk" <?php echo ($filter_jenis === 'transfer_masuk') ? 'selected' : ''; ?>>Transfer Masuk</option>
                        <option value="transfer_keluar" <?php echo ($filter_jenis === 'transfer_keluar') ? 'selected' : ''; ?>>Transfer Keluar</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">Semua Status</option>
                        <option value="approved" <?php echo ($filter_status === 'approved') ? 'selected' : ''; ?>>Berhasil</option>
                        <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Menunggu</option>
                        <option value="rejected" <?php echo ($filter_status === 'rejected') ? 'selected' : ''; ?>>Ditolak</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Mulai</label>
                    <input type="date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date); ?>" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Tanggal Akhir</label>
                    <input type="date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date); ?>" class="form-control">
                </div>
                <div class="filter-actions">
                    <a href="<?php echo htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Terapkan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <div class="bottom-nav" id="bottomNav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-home"></i>
            <span>Beranda</span>
        </a>
        <a href="cek_mutasi.php" class="nav-item active">
            <i class="fas fa-list-alt"></i>
            <span>Mutasi</span>
        </a>
        <a href="aktivitas.php" class="nav-item">
            <i class="fas fa-history"></i>
            <span>Aktivitas</span>
        </a>
        <a href="profil.php" class="nav-item">
            <i class="fas fa-user"></i>
            <span>Profil</span>
        </a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Detect Sticky Header
        let stickyHeader = document.getElementById('stickyHeader');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 70) {
                stickyHeader.classList.add('stuck');
            } else {
                stickyHeader.classList.remove('stuck');
            }
        });

        // Filter Modal Functions
        function openFilterModal() {
            document.getElementById('filterModal').classList.add('active');
        }
        function closeFilterModal(event) {
            if (!event || event.target.id === 'filterModal') {
                document.getElementById('filterModal').classList.remove('active');
            }
        }

        // Bottom Bar Auto Hide/Show on Scroll
        let scrollTimer;
        let lastScrollTop = 0;
        const bottomNav = document.getElementById('bottomNav');
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
    </script>
</body>
</html>
