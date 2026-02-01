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
    <title>Laporan Transaksi Harian | KASDIG</title>
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-50: #f9fafb;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            backdrop-filter: blur(5px);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100% - 280px);
            position: relative;
            z-index: 1;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
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
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        /* Cards */
        .card,
        .form-card,
        .jadwal-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
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

        .card-header-text {
            flex: 1;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Button */
        .btn {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        /* Summary Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }

        .summary-item {
            background: var(--gray-50);
            padding: 1.25rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            text-align: center;
            transition: all 0.2s;
        }

        .summary-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--gray-300);
        }

        .summary-item h4 {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-bottom: 0.5rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .summary-item p {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .text-success {
            color: #166534;
        }

        .text-danger {
            color: #991b1b;
        }

        /* Data Table */


        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tr:hover {
            background: var(--gray-100) !important;
        }

        /* Pagination */
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
            cursor: default;
        }

        .page-link.page-arrow {
            padding: 0.5rem 0.75rem;
        }

        .page-link.disabled {
            color: var(--gray-300);
            cursor: not-allowed;
            background: var(--gray-50);
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-hamburger {
                display: flex;
            }

            .page-title-section {
                padding: 1.25rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        /* SweetAlert Fix */
        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Laporan Transaksi Harian</h1>
                <p class="page-subtitle">Pantau transaksi harian Anda secara real-time</p>
            </div>
        </div>

        <!-- Card Ringkasan Transaksi -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-header-text">
                    <h3>Ringkasan Hari Ini</h3>
                    <p>Statistik transaksi petugas pada <?= date('d/m/Y') ?></p>
                </div>
            </div>

            <div class="summary-grid">
                <div class="summary-item">
                    <h4>Total Transaksi</h4>
                    <p data-target="<?= $totals['total_transactions'] ?? 0 ?>"><?= $totals['total_transactions'] ?? 0 ?>
                    </p>
                </div>
                <div class="summary-item">
                    <h4>Total Setor</h4>
                    <p class="text-success"><?= formatRupiah($totals['total_debit'] ?? 0) ?></p>
                </div>
                <div class="summary-item">
                    <h4>Total Tarik</h4>
                    <p class="text-danger"><?= formatRupiah($totals['total_kredit'] ?? 0) ?></p>
                </div>
                <div class="summary-item">
                    <h4>Saldo Bersih</h4>
                    <p><?= formatRupiah($saldo_bersih) ?></p>
                </div>
            </div>
        </div>

        <!-- Card Tabel Transaksi -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="card-header-text">
                    <h3>Riwayat Transaksi</h3>
                    <p>Daftar transaksi yang Anda proses hari ini</p>
                </div>
                <button id="pdfButton" class="btn btn-pdf">
                    <i class="fas fa-file-pdf"></i> Unduh PDF
                </button>
            </div>

            <div class="card-body">
                <?php if ($totals['total_transactions'] > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Waktu (WIB)</th>
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
                                    $displayType = $transaction['jenis_transaksi'] === 'setor' ? 'Setor' : 'Tarik';
                                    $typeColor = $transaction['jenis_transaksi'] === 'setor' ? 'text-success' : 'text-danger';
                                    $noUrut = (($page - 1) * $limit) + $index + 1;
                                    $nama_kelas = trim($transaction['nama_kelas'] ?? '') ?: 'N/A';
                                    ?>
                                    <tr>
                                        <td><?= $noUrut ?></td>
                                        <td><?= date('H:i', strtotime($transaction['created_at'])) ?></td>
                                        <td><?= htmlspecialchars($transaction['no_transaksi'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($transaction['nama_siswa'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($nama_kelas) ?></td>
                                        <td><span class="<?= $typeColor ?>" style="font-weight: 600;"><?= $displayType ?></span>
                                        </td>
                                        <td style="font-weight: 500;"><?= formatRupiah($transaction['jumlah']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <!-- Previous -->
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>" class="page-link page-arrow">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link page-arrow disabled">
                                    <i class="fas fa-chevron-left"></i>
                                </span>
                            <?php endif; ?>

                            <!-- Current -->
                            <span class="page-link active"><?= $page ?></span>

                            <!-- Next -->
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>" class="page-link page-arrow">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="page-link page-arrow disabled">
                                    <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div style="text-align: center; padding: 3rem; color: var(--gray-500);">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                        <p>Belum ada transaksi hari ini.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <input type="hidden" id="totalTransactions" value="<?= htmlspecialchars($totals['total_transactions']) ?>">
    </div> <!-- Close main-content -->

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('touchstart', function (event) {
                if (event.touches.length > 1) {
                    event.preventDefault();
                }
            }, { passive: false });

            let lastTouchEnd = 0;
            document.addEventListener('touchend', function (event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, { passive: false });

            document.addEventListener('wheel', function (event) {
                if (event.ctrlKey) {
                    event.preventDefault();
                }
            }, { passive: false });

            document.addEventListener('dblclick', function (event) {
                event.preventDefault();
            }, { passive: false });

            // ============================================
            // FIXED SIDEBAR MOBILE BEHAVIOR
            // ============================================
            const menuToggle = document.getElementById('pageHamburgerBtn'); // Changed ID to match Admin
            const sidebar = document.getElementById('sidebar');
            const body = document.body;

            function openSidebar() {
                body.classList.add('sidebar-open');
                if (sidebar) sidebar.classList.add('active');
            }

            function closeSidebar() {
                body.classList.remove('sidebar-open');
                if (sidebar) sidebar.classList.remove('active');
            }

            // Toggle Sidebar
            if (menuToggle) {
                menuToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (body.classList.contains('sidebar-open')) {
                        closeSidebar();
                    } else {
                        openSidebar();
                    }
                });
            }

            // Close sidebar on outside click
            document.addEventListener('click', function (e) {
                if (body.classList.contains('sidebar-open') &&
                    !sidebar?.contains(e.target) &&
                    !menuToggle?.contains(e.target)) {
                    closeSidebar();
                }
            });

            // Close sidebar on escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                    closeSidebar();
                }
            });

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
                prevPageButton.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (currentPage > 1 && !prevPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage - 1);
                        window.location.href = url.toString();
                    }
                });
            }

            if (nextPageButton) {
                nextPageButton.addEventListener('click', function (e) {
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
                pdfButton.addEventListener('click', function (e) {
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

                    Swal.fire({
                        title: 'Sedang memproses...',
                        html: 'Mohon tunggu sebentar...',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    setTimeout(() => {
                        window.location.href = `download_laporan.php?date=<?= $today ?>&format=pdf`;
                        // Tutup loading setelah delay singkat untuk memberi waktu browser memulai download
                        setTimeout(() => {
                            Swal.close();
                        }, 1000);
                    }, 1000);
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