<?php
/**
 * Laporan Transaksi Harian - Adaptive Path Version
 * File: pages/petugas/laporan_transaksi_harian.php
 *
 * Compatible with:
 * - Local: schobank/pages/petugas/laporan_transaksi_harian.php
 * - Hosting: public_html/pages/petugas/laporan_transaksi_harian.php
 */
// ============================================
// ERROR HANDLING & TIMEZONE
// ============================================
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');
// ============================================
// ADAPTIVE PATH DETECTION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;
// Strategy 1: jika di folder 'pages' atau 'petugas'
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
}
// Strategy 2: cek includes/ di parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 3: cek includes/ di current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 4: naik max 5 level cari includes/
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
// Fallback: pakai current dir
if (!$project_root) {
    $project_root = $current_dir;
}
// ============================================
// DEFINE PATH CONSTANTS
// ============================================
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}
if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', PROJECT_ROOT . '/vendor');
}
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}
// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// ============================================
// LOAD REQUIRED FILES
// ============================================
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
// ============================================
// DETECT BASE URL FOR ASSETS
// ============================================
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim(dirname($script_name), '/'));
// Deteksi base path (schobank atau public_html)
$base_path = '';
if (in_array('schobank', $path_parts)) {
    $base_path = '/schobank';
} elseif (in_array('public_html', $path_parts)) {
    $base_path = '';
}
$base_url = $protocol . '://' . $host . $base_path;
// ============================================
// GET DATA FROM PROSES
// ============================================
$data = require_once 'proses_laporan_transaksi_harian.php';

$username = $data['username'];
$user_id = $data['user_id'];
$today = $data['today'];
$page = $data['page'];
$limit = $data['limit'];
$offset = $data['offset'];
$totals = $data['totals'];
$total_records = $data['total_records'];
$total_pages = $data['total_pages'];
$transactions = $data['transactions'];
$saldo_bersih = $data['saldo_bersih'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Laporan Transaksi Harian | MY Schobank</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --kredit-bg: #fee2e2;
            --button-width: 140px;
            --button-height: 44px;
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

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            touch-action: pan-y;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            transition: margin-left 0.3s ease;
            overflow-y: auto;
            min-height: 100vh;
        }

        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }

        /* Welcome Banner - Updated to match previous syntax */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .welcome-banner .content {
            flex: 1;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
            margin: 0;
        }

        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .summary-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }

        .summary-title {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-box {
            background: var(--bg-light);
            padding: 20px;
            border-radius: 5px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-box h3 {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .summary-box .amount {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
            display: inline-block;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }

        .summary-box .amount.counting {
            transform: scale(1.1);
            opacity: 0.8;
        }

        .summary-box .amount.finished {
            transform: scale(1);
            opacity: 1;
        }

        .stat-box {
            background: var(--bg-light);
            padding: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.5s ease-out;
        }

        .stat-box .stat-icon {
            font-size: 2rem;
            color: var(--primary-dark);
        }

        .stat-box .stat-content {
            flex: 1;
        }

        .stat-box .stat-title {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
        }

        .stat-box .stat-value {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
            display: inline-block;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }

        .stat-box .stat-value.counting {
            transform: scale(1.1);
            opacity: 0.8;
        }

        .stat-box .stat-value.finished {
            transform: scale(1);
            opacity: 1;
        }

        .stat-box .stat-note {
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
        }

        .action-buttons {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 15px;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            width: var(--button-width);
            height: var(--button-height);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        .btn:active {
            transform: scale(0.95);
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            position: relative;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
            min-width: 800px;
        }

        .transaction-table th, .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }

        .transaction-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .transaction-table td {
            background: white;
        }

        .transaction-table tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }

        .transaction-type {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            min-width: 90px;
            display: inline-block;
            color: var(--text-primary);
        }

        .type-debit {
            color: #047857;
            font-weight: 600;
        }

        .type-kredit {
            color: #b91c1c;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            width: 100%;
        }

        .current-page {
            font-weight: 600;
            color: var(--primary-dark);
            min-width: 50px;
            text-align: center;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
        }

        .pagination-btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(1rem, 2.2vw, 1.1rem);
            font-weight: 500;
            transition: var(--transition);
            width: auto;
            height: var(--button-height);
            padding: 0 16px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pagination-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .pagination-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.03) 0%, rgba(59, 130, 246, 0.03) 100%);
            border-radius: 5px;
            border: 2px dashed rgba(30, 58, 138, 0.2);
            animation: fadeIn 0.6s ease-out;
            margin: 20px 0;
        }

        .empty-state-icon {
            font-size: clamp(3.5rem, 6vw, 4.5rem);
            color: var(--primary-color);
            margin-bottom: 25px;
            opacity: 0.4;
            display: block;
        }

        .empty-state-title {
            color: var(--primary-dark);
            font-size: clamp(1.3rem, 2.8vw, 1.6rem);
            font-weight: 600;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }

        .empty-state-message {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2vw, 1.1rem);
            font-weight: 400;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto 25px;
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }

            .summary-container {
                padding: 20px;
                border-radius: 5px;
            }

            .action-buttons {
                justify-content: center;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .summary-boxes {
                grid-template-columns: 1fr;
            }

            .table-container {
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
                touch-action: pan-x pan-y;
                max-height: none;
            }

            .transaction-table {
                min-width: 900px;
            }

            .transaction-table th, .transaction-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                gap: 5px;
            }

            .pagination-btn {
                padding: 8px 12px;
                min-width: 40px;
                font-size: clamp(1rem, 2.2vw, 1.1rem);
            }

            .current-page {
                font-size: clamp(0.95rem, 2vw, 1rem);
                min-width: 45px;
            }

            .empty-state {
                padding: 40px 20px;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.8vw, 1.3rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }

            .summary-container {
                padding: 15px;
            }

            .pagination-btn {
                padding: 6px 10px;
                min-width: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            .current-page {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                min-width: 40px;
            }

            .transaction-table {
                min-width: 1000px;
            }

            .table-container {
                touch-action: pan-x pan-y;
                overflow-y: visible;
            }

            .empty-state {
                padding: 35px 15px;
            }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-file-invoice"></i> Laporan Transaksi Petugas</h2>
                <p>Transaksi pada <?= date('d/m/Y') ?></p>
            </div>
        </div>

        <!-- Summary Section -->
        <div class="summary-container">
            <h3 class="summary-title"><i class="fas fa-chart-line"></i> Ringkasan Transaksi</h3>
            <div class="summary-boxes">
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount non-currency" data-target="<?= $totals['total_transactions'] ?? 0 ?>"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Setor</h3>
                    <div class="amount" data-target="<?= $totals['total_debit'] ?? 0 ?>" data-currency="true"><?= formatRupiah($totals['total_debit'] ?? 0) ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Tarik</h3>
                    <div class="amount" data-target="<?= $totals['total_kredit'] ?? 0 ?>" data-currency="true"><?= formatRupiah($totals['total_kredit'] ?? 0) ?></div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value" data-target="<?= $saldo_bersih ?>" data-currency="true"><?= formatRupiah($saldo_bersih) ?></div>
                        <div class="stat-note">Setor - Tarik</div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button id="pdfButton" class="btn">
                    <i class="fas fa-file-pdf"></i> Unduh PDF
                </button>
            </div>

            <?php if ($totals['total_transactions'] > 0): ?>
                <div class="table-container">
                    <table class="transaction-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $index => $transaction): ?>
                                <?php
                                $typeClass = $transaction['jenis_transaksi'] === 'setor' ? 'type-debit' : 'type-kredit';
                                $displayType = $transaction['jenis_transaksi'] === 'setor' ? 'Setor' : 'Tarik';
                                $noUrut = (($page - 1) * $limit) + $index + 1;
                                $nama_kelas = trim($transaction['nama_kelas'] ?? '') ?: 'N/A';
                                ?>
                                <tr>
                                    <td><?= $noUrut ?></td>
                                    <td><?= date('d/m/Y', strtotime($transaction['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($transaction['no_transaksi'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_siswa'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($nama_kelas) ?></td>
                                    <td><span class="transaction-type <?= $typeClass ?>"><?= $displayType ?></span></td>
                                    <td><?= formatRupiah($transaction['jumlah']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_records > 10): ?>
                    <div class="pagination">
                        <button id="prev-page" class="pagination-btn" <?= $page <= 1 ? 'disabled' : '' ?>>&lt;</button>
                        <span class="current-page"><?= $page ?></span>
                        <button id="next-page" class="pagination-btn" <?= $page >= $total_pages ? 'disabled' : '' ?>>&gt;</button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox empty-state-icon"></i>
                    <h3 class="empty-state-title">Tidak Ada Data Transaksi</h3>
                    <p class="empty-state-message">
                        Tidak ditemukan transaksi petugas hari ini. Silakan lakukan transaksi terlebih dahulu.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" id="totalTransactions" value="<?= htmlspecialchars($totals['total_transactions']) ?>">
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('touchstart', function(event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function(event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function(event) {
                event.preventDefault();
            }, { passive: false });

            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            if (menuToggle && sidebar && mainContent) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });

                document.addEventListener('click', function(e) {
                    if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }

            function countUp(element, target, duration, isCurrency) {
                let start = 0;
                const stepTime = 50;
                const steps = Math.ceil(duration / stepTime);
                const increment = target / steps;
                let current = start;

                element.classList.add('counting');

                const updateCount = () => {
                    current += increment;
                    if ((increment > 0 && current >= target) || (increment < 0 && current <= target)) {
                        current = target;
                        element.classList.remove('counting');
                        element.classList.add('finished');
                    }

                    if (isCurrency) {
                        const formatted = Math.round(current).toLocaleString('id-ID', { minimumFractionDigits: 0 });
                        element.textContent = (current < 0 ? '-' : '') + 'Rp ' + formatted.replace('-', '');
                    } else {
                        element.textContent = Math.round(current).toLocaleString('id-ID');
                    }

                    if ((increment > 0 && current < target) || (increment < 0 && current > target)) {
                        setTimeout(updateCount, stepTime);
                    }
                };

                updateCount();
            }

            const amounts = document.querySelectorAll('.summary-box .amount, .stat-box .stat-value');
            amounts.forEach(amount => {
                const target = parseFloat(amount.getAttribute('data-target'));
                const isCurrency = amount.getAttribute('data-currency') === 'true';
                countUp(amount, target, 1500, isCurrency);
            });

            const prevPageButton = document.getElementById('prev-page');
            const nextPageButton = document.getElementById('next-page');
            const currentPage = <?= $page ?>;
            const totalPages = <?= $total_pages ?>;

            if (prevPageButton) {
                prevPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage > 1 && !prevPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage - 1);
                        window.location.href = url.toString();
                    }
                });
            }

            if (nextPageButton) {
                nextPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage < totalPages && !nextPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage + 1);
                        window.location.href = url.toString();
                    }
                });
            }

            const pdfButton = document.getElementById('pdfButton');
            const totalTransactions = parseInt(document.getElementById('totalTransactions').value);

            if (pdfButton) {
                pdfButton.addEventListener('click', function(e) {
                    e.preventDefault();

                    if (totalTransactions === 0) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Tidak ada transaksi untuk diunduh',
                            confirmButtonColor: '#1e3a8a'
                        });
                        return;
                    }

                    const pdfWindow = window.open(`download_laporan.php?date=<?= $today ?>&format=pdf`, '_blank');

                    if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed === 'undefined') {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Gagal membuka PDF. Pastikan popup tidak diblokir oleh browser.',
                            confirmButtonColor: '#1e3a8a'
                        });
                    }
                });
            }

            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                tableContainer.style.overflowX = 'auto';
                tableContainer.style.overflowY = 'visible';
            }

            let lastWindowWidth = window.innerWidth;
            window.addEventListener('resize', () => {
                if (window.innerWidth !== lastWindowWidth) {
                    lastWindowWidth = window.innerWidth;
                    if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                    if (tableContainer) {
                        tableContainer.style.overflowX = 'auto';
                        tableContainer.style.overflowY = 'visible';
                    }
                }
            });

            document.addEventListener('mousedown', (e) => {
                if (!e.target.matches('input, select, textarea')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>