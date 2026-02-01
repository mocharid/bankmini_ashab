<?php
/**
 * Bendahara Dashboard - Adaptive Path Version (Normalized DB) - Redesigned
 * File: pages/bendahara/dashboard.php
 * Features: Modern layout, responsive design, ongoing bills, recent transactions
 */

// ============================================
// ADAPTIVE PATH & INIT
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename(dirname($current_dir)) === 'pages') {
    $project_root = dirname(dirname($current_dir));
} else {
    $project_root = dirname(dirname($current_dir));
}

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

$username = $_SESSION['username'] ?? 'Bendahara';
$bendahara_id = $_SESSION['user_id'] ?? 0;
$nama_bendahara = $_SESSION['nama'] ?? 'Bendahara';
date_default_timezone_set('Asia/Jakarta');

// ============================================
// DATA FETCHING
// ============================================

// 1. Saldo Rekening Bendahara
$saldo_bendahara = 0;
$no_rekening_bendahara = '-';
$stmt = $conn->prepare("SELECT no_rekening, saldo FROM rekening WHERE user_id = ?");
$stmt->bind_param("i", $bendahara_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $saldo_bendahara = $row['saldo'];
    $no_rekening_bendahara = $row['no_rekening'];
}

// 2. Transaksi Hari Ini
$income_today = 0;
$trx_today_count = 0;
$stmt = $conn->prepare("SELECT COUNT(*) as cnt, SUM(nominal_bayar) as total FROM pembayaran_transaksi WHERE DATE(tanggal_bayar) = CURDATE()");
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $income_today = $row['total'] ?? 0;
    $trx_today_count = $row['cnt'] ?? 0;
}

// 3. Tagihan Berjalan (Active Items stats)
$active_bills_count = 0;
$ongoing_bills_data = [];
$stmt = $conn->prepare("
    SELECT 
        pi.nama_item,
        COUNT(pt.id) as total_assigned,
        SUM(CASE WHEN pt.status = 'sudah_bayar' THEN 1 ELSE 0 END) as total_paid,
        SUM(CASE WHEN pt.status = 'belum_bayar' THEN 1 ELSE 0 END) as total_unpaid,
        SUM(CASE WHEN pt.status = 'sudah_bayar' THEN pt.nominal ELSE 0 END) as paid_amount,
        SUM(pt.nominal) as target_amount
    FROM pembayaran_item pi
    LEFT JOIN pembayaran_tagihan pt ON pi.id = pt.item_id
    WHERE pi.is_aktif = 1
    GROUP BY pi.id
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $active_bills_count++;
    $row['percentage'] = $row['total_assigned'] > 0 ? round(($row['total_paid'] / $row['total_assigned']) * 100) : 0;
    $ongoing_bills_data[] = $row;
}

// 4. Total Siswa Menunggak (Global)
$total_students_unpaid = 0;
$stmt = $conn->prepare("SELECT COUNT(DISTINCT siswa_id) as cnt FROM pembayaran_tagihan WHERE status = 'belum_bayar'");
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
    $total_students_unpaid = $row['cnt'];
}

// 5. Recent Transactions
$recent_transactions = [];
$stmt = $conn->prepare("
    SELECT ptr.*, u.nama as nama_siswa, pi.nama_item 
    FROM pembayaran_transaksi ptr
    JOIN users u ON ptr.siswa_id = u.id
    JOIN pembayaran_tagihan pt ON ptr.tagihan_id = pt.id
    JOIN pembayaran_item pi ON pt.item_id = pi.id
    ORDER BY ptr.tanggal_bayar DESC, ptr.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $recent_transactions[] = $row;
}

// Date Display
$bulan_indonesia = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$hari_ini = date('d') . ' ' . $bulan_indonesia[(int) date('n')] . ' ' . date('Y');

// Base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'];
$base_url .= in_array('schobank', explode('/', $_SERVER['REQUEST_URI'])) ? '/schobank' : '';
if (!defined('ASSETS_URL'))
    define('ASSETS_URL', $base_url . '/assets');

?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Bendahara | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 12px;
            --radius-lg: 16px;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            color: var(--gray-800);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 50%, #f0f9ff 100%);
            padding: 1.5rem 0;
            margin: -2rem -2rem 1.5rem -2rem;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-title-content {
            flex: 1;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
            margin-right: 0.5rem;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        .page-hamburger:active {
            transform: scale(0.95);
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
            padding: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0.25rem 0 0 0;
            padding: 0;
            text-indent: 0;
        }

        .date-display {
            font-size: 0.9rem;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray-100);
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius);
            background: linear-gradient(135deg, var(--gray-500) 0%, var(--gray-600) 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.875rem;
            color: var(--gray-500);
            font-weight: 500;
        }

        .stat-desc {
            font-size: 0.75rem;
            color: var(--gray-400);
            margin-top: 0.5rem;
        }

        .stat-note {
            font-size: 0.7rem;
            color: var(--gray-400);
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px dashed var(--gray-200);
            line-height: 1.4;
            font-style: italic;
        }

        .stat-card.success .stat-icon,
        .stat-card.warning .stat-icon,
        .stat-card.danger .stat-icon {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
        }

        /* Section Cards */
        .section-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-100);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }

        .card-header {
            background: linear-gradient(to right, var(--gray-50), white);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header-left {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            margin: 0;
            color: var(--gray-800);
        }

        .card-header-desc {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Ongoing Bills List */
        .bills-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .bill-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: var(--gray-50);
            padding: 0.65rem 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-100);
            transition: all 0.2s ease;
        }

        .bill-item:hover {
            background: white;
            border-color: var(--gray-200);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        }

        .bill-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: linear-gradient(135deg, var(--gray-500) 0%, var(--gray-600) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .bill-content {
            flex: 1;
            min-width: 0;
        }

        .bill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.35rem;
        }

        .bill-name {
            font-weight: 600;
            font-size: 0.8rem;
            color: var(--gray-800);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bill-percentage {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--gray-600);
            background: var(--gray-100);
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            flex-shrink: 0;
        }

        .bill-progress-bar {
            height: 4px;
            background: var(--gray-200);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 0.35rem;
        }

        .bill-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success) 0%, #34d399 100%);
            border-radius: 2px;
            transition: width 0.5s ease;
        }

        .bill-stats {
            display: flex;
            gap: 0.75rem;
            font-size: 0.65rem;
        }

        .bill-stat {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .bill-stat i {
            font-size: 0.55rem;
        }

        .bill-stat.paid {
            color: var(--success);
        }

        .bill-stat.unpaid {
            color: var(--danger);
        }

        .bill-stat.total {
            color: var(--gray-500);
        }

        /* View More Button */
        .view-more-wrapper {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px dashed var(--gray-200);
            text-align: center;
        }

        .btn-view-more {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.5rem;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border: none;
            border-radius: 50px;
            color: white;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            text-decoration: none;
        }

        .btn-view-more:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(100, 116, 139, 0.3);
        }

        .btn-view-more i {
            font-size: 0.75rem;
        }


        /* Transaction Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .table th {
            text-align: left;
            padding: 0.5rem 0.75rem;
            background: var(--gray-50);
            color: var(--gray-600);
            font-weight: 600;
            border-bottom: 1px solid var(--gray-200);
            text-transform: uppercase;
            font-size: 0.65rem;
            letter-spacing: 0.05em;
        }

        .table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .table tbody tr:hover {
            background-color: var(--gray-50);
        }

        .btn-link {
            font-size: 0.85rem;
            text-decoration: none;
            color: var(--gray-500);
            font-weight: 500;
        }

        .btn-link:hover {
            color: var(--gray-700);
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-400);
        }

        /* Transaction List Styles */
        .transaction-list {
            display: flex;
            flex-direction: column;
        }

        .transaction-item {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 1rem 1.25rem;
            background: white;
            border-bottom: 1px solid var(--gray-100);
            transition: background 0.15s ease;
        }

        .transaction-item:last-child {
            border-bottom: none;
        }

        .transaction-item:hover {
            background: var(--gray-50);
        }

        .transaction-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-600);
            font-weight: 600;
            font-size: 0.85rem;
            flex-shrink: 0;
            text-transform: uppercase;
        }

        .transaction-details {
            flex: 1;
            min-width: 0;
        }

        .transaction-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--gray-800);
            margin-bottom: 0.15rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .transaction-desc {
            font-size: 0.75rem;
            color: var(--gray-400);
        }

        .transaction-right {
            text-align: right;
            flex-shrink: 0;
        }

        .transaction-amount {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--success);
        }

        .transaction-time {
            font-size: 0.7rem;
            color: var(--gray-400);
            margin-top: 0.15rem;
        }

        .transaction-empty {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--gray-400);
        }

        .transaction-empty i {
            font-size: 2.5rem;
            margin-bottom: 0.75rem;
            opacity: 0.4;
        }

        .transaction-empty p {
            font-size: 0.85rem;
            margin: 0;
        }

        .btn-see-all {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.875rem;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-100);
            color: var(--gray-500);
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.15s ease;
        }

        .btn-see-all:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .btn-see-all i {
            font-size: 0.7rem;
        }

        @media (max-width: 1024px) {
            body {
                padding-top: 0 !important;
                margin-top: 0 !important;
            }

            .main-content {
                margin-left: 0;
                margin-top: 0;
                padding: 0 !important;
                padding-top: 0 !important;
            }

            .page-hamburger {
                display: flex;
                margin-left: 0.5rem;
            }

            .page-title-section {
                margin: 0;
                padding: 1rem;
                flex-direction: row;
                align-items: center;
                gap: 0.75rem;
            }

            .page-title-content {
                flex: 1;
            }

            .page-title,
            .page-subtitle {
                margin-left: 0;
                padding-left: 0;
            }

            .date-display {
                display: none;
            }

            .section-grid {
                grid-template-columns: 1fr;
                padding: 0 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 0 1rem;
                margin-bottom: 1rem;
            }

            .bills-grid {
                grid-template-columns: 1fr;
            }

            /* Hide mobile-navbar from sidebar, use page-hamburger instead */
            .mobile-navbar {
                display: none !important;
            }

            .page-subtitle {
                margin-left: 0;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 768px) {
            .page-title {
                font-size: 1.25rem;
            }

            .stat-value {
                font-size: 1.25rem;
            }

            .table th,
            .table td {
                padding: 0.5rem;
                font-size: 0.7rem;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 0.75rem;
                padding-top: 70px;
            }

            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
                margin-left: 0;
            }

            .date-display {
                font-size: 0.8rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .card-body {
                padding: 1rem;
            }

            .card-header {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>

    <?php include __DIR__ . '/sidebar_bendahara.php'; ?>

    <div class="main-content">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Dashboard Bendahara</h1>
                <p class="page-subtitle">Ringkasan keuangan dan aktivitas pembayaran sekolah</p>
            </div>
            <div class="date-display">
                <i class="fas fa-calendar-check"></i> <?php echo $hari_ini; ?>
            </div>
        </div>

        <!-- Top Stats -->
        <div class="stats-grid">
            <!-- Saldo -->
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                <div class="stat-value">Rp <?php echo number_format($saldo_bendahara, 0, ',', '.'); ?></div>
                <div class="stat-label">Saldo Saat Ini</div>
                <div class="stat-desc"><?php echo $no_rekening_bendahara; ?></div>
                <div class="stat-note">Silahkan lakukan penarikan di Admin Bank Mini sekolah dengan nomor rekening
                    <?php echo $no_rekening_bendahara; ?>
                </div>
            </div>

            <!-- Income Today -->
            <div class="stat-card success">
                <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
                <div class="stat-value">Rp <?php echo number_format($income_today, 0, ',', '.'); ?></div>
                <div class="stat-label">Pemasukan Hari Ini</div>
                <div class="stat-desc"><?php echo $trx_today_count; ?> Transaksi</div>
            </div>

            <!-- Active Bills -->
            <div class="stat-card warning">
                <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
                <div class="stat-value"><?php echo $active_bills_count; ?></div>
                <div class="stat-label">Tagihan Berjalan</div>
                <div class="stat-desc">Jenis tagihan aktif</div>
            </div>
        </div>

        <div class="section-grid">
            <!-- Left Column: Tagihan Berjalan -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <div class="card-header-icon"><i class="fas fa-list-ul"></i></div>
                        <div>
                            <h3>Status Tagihan Berjalan</h3>
                            <p class="card-header-desc">Progress pembayaran per item</p>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($ongoing_bills_data)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <p>Belum ada tagihan aktif.</p>
                        </div>
                    <?php else: ?>
                        <?php
                        $total_bills = count($ongoing_bills_data);
                        $initial_show = 4;
                        $has_more = $total_bills > $initial_show;
                        ?>
                        <div class="bills-list" id="billsList">
                            <?php
                            $count = 0;
                            foreach ($ongoing_bills_data as $index => $bill):
                                if ($count >= $initial_show)
                                    break;
                                $count++;
                                ?>
                                <div class="bill-item">
                                    <div class="bill-icon">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </div>
                                    <div class="bill-content">
                                        <div class="bill-header">
                                            <div class="bill-name"><?php echo htmlspecialchars($bill['nama_item']); ?></div>
                                            <span class="bill-percentage"><?php echo $bill['percentage']; ?>%</span>
                                        </div>
                                        <div class="bill-progress-bar">
                                            <div class="bill-progress-fill" style="width: <?php echo $bill['percentage']; ?>%;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($has_more): ?>
                            <div class="view-more-wrapper">
                                <a href="daftar_tagihan.php" class="btn-view-more">
                                    <i class="fas fa-list"></i>
                                    <span>Lihat Semua Tagihan</span>
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column: Recent Transactions -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-left">
                        <div class="card-header-icon"><i class="fas fa-clock-rotate-left"></i></div>
                        <div>
                            <h3>Transaksi Terakhir</h3>
                            <p class="card-header-desc">Pembayaran terbaru masuk</p>
                        </div>
                    </div>
                </div>
                <?php if (empty($recent_transactions)): ?>
                    <div class="transaction-empty">
                        <i class="fas fa-receipt"></i>
                        <p>Belum ada transaksi hari ini</p>
                    </div>
                <?php else: ?>
                    <div class="transaction-list">
                        <?php foreach ($recent_transactions as $t):
                            $initials = strtoupper(substr($t['nama_siswa'], 0, 2));
                            ?>
                            <div class="transaction-item">
                                <div class="transaction-avatar">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="transaction-details">
                                    <div class="transaction-name"><?php echo htmlspecialchars($t['nama_siswa']); ?></div>
                                    <div class="transaction-desc"><?php echo htmlspecialchars($t['nama_item']); ?></div>
                                </div>
                                <div class="transaction-right">
                                    <div class="transaction-amount">+Rp
                                        <?php echo number_format($t['nominal_bayar'], 0, ',', '.'); ?>
                                    </div>
                                    <div class="transaction-time"><?php echo date('H:i', strtotime($t['created_at'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a href="riwayat_pembayaran.php" class="btn-see-all">
                        Lihat Semua Transaksi
                        <i class="fas fa-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        // Page Hamburger Button functionality
        document.addEventListener('DOMContentLoaded', function () {
            var pageHamburgerBtn = document.getElementById('pageHamburgerBtn');

            if (pageHamburgerBtn) {
                pageHamburgerBtn.addEventListener('click', function () {
                    var sidebar = document.getElementById('sidebar');
                    var overlay = document.getElementById('sidebarOverlay');

                    if (sidebar && overlay) {
                        sidebar.classList.add('active');
                        overlay.classList.add('active');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }
        });
    </script>

    <!-- Session Auto-Logout -->
    <script>
        window.sessionConfig = {
            timeout: <?php echo $_SESSION['session_timeout'] ?? 300; ?>,
            logoutUrl: '<?= $base_url ?>/logout.php?reason=timeout'
        };
    </script>
    <script src="<?= ASSETS_URL ?>/js/session_auto_logout.js"></script>

</body>

</html>