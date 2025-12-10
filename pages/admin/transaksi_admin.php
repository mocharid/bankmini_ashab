<?php
/**
 * Transaksi Admin - Final Rapih Version (NO ICONS)
 * File: pages/admin/transaksi_admin.php
 */

// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
if (!$project_root) $project_root = $current_dir;

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH')) define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) define('BASE_URL', rtrim(getBaseUrl(), '/'));
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// LOGIC & DATABASE
// ============================================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Validate session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Get user information from session
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Ambil data transaksi berdasarkan filter tanggal
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Pagination settings
$items_per_page = 15;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Build WHERE clause based on date filter
$date_where = '';
$date_params = [];
$date_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $date_where = " AND DATE(t.created_at) BETWEEN ? AND ?";
    $date_params = [$start_date, $end_date];
    $date_types = 'ss';
}

// Calculate summary statistics
$total_query = "SELECT
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    JOIN siswa_profiles sp ON u.id = sp.user_id
    LEFT JOIN kelas k ON sp.kelas_id = k.id
    WHERE t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NULL" . $date_where;

$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    error_log("Error preparing total query: " . $conn->error);
    die("Error preparing total query");
}

if (!empty($date_params)) {
    $stmt_total->bind_param($date_types, ...$date_params);
}
$stmt_total->execute();
$totals = $stmt_total->get_result()->fetch_assoc();
$stmt_total->close();

$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_debit'] = (float)($totals['total_debit'] ?? 0);
$totals['total_kredit'] = (float)($totals['total_kredit'] ?? 0);

// Debug logging for totals
error_log("Total Debit: " . $totals['total_debit'] . ", Total Kredit: " . $totals['total_kredit']);

// Calculate net balance
$saldo_bersih = $totals['total_debit'] - $totals['total_kredit'];
error_log("Saldo Bersih: " . $saldo_bersih);

// Calculate total records for pagination
$total_records_query = "SELECT COUNT(*) as total
                       FROM transaksi t
                       JOIN rekening r ON t.rekening_id = r.id
                       JOIN users u ON r.user_id = u.id
                       JOIN siswa_profiles sp ON u.id = sp.user_id
                       WHERE t.jenis_transaksi IN ('setor', 'tarik')
                       AND t.petugas_id IS NULL" . $date_where;

$stmt_total_records = $conn->prepare($total_records_query);
if (!$stmt_total_records) {
    error_log("Error preparing total records query: " . $conn->error);
    die("Error preparing total records query");
}

if (!empty($date_params)) {
    $stmt_total_records->bind_param($date_types, ...$date_params);
}
$stmt_total_records->execute();
$total_records = $stmt_total_records->get_result()->fetch_assoc()['total'];
$stmt_total_records->close();

$total_pages = ceil($total_records / $items_per_page);

// Fetch transactions with class information and no_rekening
try {
    $query = "SELECT
        t.no_transaksi,
        r.no_rekening,
        t.jenis_transaksi,
        t.jumlah,
        t.created_at,
        t.status,
        u.nama AS nama_siswa,
        k.nama_kelas,
        tk.nama_tingkatan
        FROM transaksi t
        JOIN rekening r ON t.rekening_id = r.id
        JOIN users u ON r.user_id = u.id
        JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        WHERE t.jenis_transaksi IN ('setor', 'tarik')
        AND t.petugas_id IS NULL" . $date_where . "
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?";

    $stmt_trans = $conn->prepare($query);
    if (!$stmt_trans) {
        throw new Exception("Failed to prepare transaction query: " . $conn->error);
    }

    if (!empty($date_params)) {
        $all_params = array_merge($date_params, [$items_per_page, $offset]);
        $all_types = $date_types . 'ii';
        $stmt_trans->bind_param($all_types, ...$all_params);
    } else {
        $stmt_trans->bind_param('ii', $items_per_page, $offset);
    }
    
    $stmt_trans->execute();
    $result = $stmt_trans->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    $stmt_trans->close();
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
    $error_message = 'Gagal mengambil data transaksi: ' . $e->getMessage();
    $show_error_modal = true;
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Function to mask account number
function maskAccountNumber($accountNumber) {
    $length = strlen($accountNumber);
    if ($length <= 5) {
        return $accountNumber;
    }
    $first3 = substr($accountNumber, 0, 3);
    $last2 = substr($accountNumber, -2);
    $maskLength = $length - 5;
    $mask = str_repeat('*', $maskLength);
    return $first3 . $mask . $last2;
}

// Function to format date in Indonesian
function formatTanggalIndonesia($date) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $day = date('d', strtotime($date));
    $month = (int)date('m', strtotime($date));
    $year = date('Y', strtotime($date));
    return $day . ' ' . $bulan[$month] . ' ' . $year;
}

// Format periode untuk display
$periode_display = 'Semua Data';
if (!empty($start_date) && !empty($end_date)) {
    $periode_display = formatTanggalIndonesia($start_date) . ' - ' . formatTanggalIndonesia($end_date);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Transaksi Admin | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a; --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6; --bg-light: #f0f5ff;
            --text-primary: #333; --text-secondary: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-primary); display: flex; min-height: 100vh; overflow-x: hidden; }
        
        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open { overflow: hidden; }
        body.sidebar-open::before {
            content: ''; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5); z-index: 998; backdrop-filter: blur(5px);
        }
        
        .main-content { flex: 1; margin-left: 280px; padding: 30px; max-width: calc(100% - 280px); position: relative; z-index: 1; }
        
        /* Banner */
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color)); 
            color: white; 
            padding: 30px; 
            border-radius: 8px; 
            margin-bottom: 30px; 
            box-shadow: var(--shadow-sm); 
            display: flex; 
            align-items: center; 
            gap: 20px; 
        }
        .welcome-banner h2 { font-size: 1.5rem; margin: 0; }
        .welcome-banner p { margin: 0; opacity: 0.9; }
        .menu-toggle { 
            display: none; 
            font-size: 1.5rem; 
            cursor: pointer; 
            align-self: center;
            margin-right: auto;
        }

        /* Form & Container */
        .form-card, .jadwal-list { background: white; border-radius: 8px; padding: 25px; box-shadow: var(--shadow-sm); margin-bottom: 30px; }
        .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .form-group { flex: 1; display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); }

        /* INPUT STYLING */
        input[type="text"], input[type="date"], select {
            width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 0.95rem; transition: all 0.3s; background: #fff;
            height: 48px;
        }
        input:focus, select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1); outline: none; }
        
        /* Date Picker Wrapper */
        .date-input-wrapper { position: relative; width: 100%; }
        
        /* Input Date Specifics */
        input[type="date"] {
            appearance: none; -webkit-appearance: none;
            position: relative; padding-right: 40px;
        }
        
        /* HIDE Native Calendar Icon */
        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            width: 100%; height: 100%;
            opacity: 0; cursor: pointer; z-index: 2;
        }
        
        /* Custom Icon */
        .calendar-icon {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            color: var(--primary-color); font-size: 1.1rem; z-index: 1; pointer-events: none;
        }

        /* Button */
        .btn { background: var(--primary-color); color: white; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; text-decoration: none; }
        .btn:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-cancel { background: #e2e8f0; color: #475569; }
        .btn-cancel:hover { background: #cbd5e1; }
        
        /* Table */
        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #f1f5f9; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-weight: 600; color: var(--text-secondary); border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); }
        
        .action-buttons button { padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; margin-right: 5px; color: white; }
        .btn-edit { background: var(--secondary-color); }
        .btn-delete { background: #ef4444; }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; max-width: 100%; }
            .menu-toggle { display: block; }
            .welcome-banner { 
                flex-direction: row; 
                align-items: center; 
                gap: 15px; 
                padding: 20px; 
            }
            .welcome-banner h2 { 
                font-size: 1.2rem;
                margin: 0; 
            }
            .welcome-banner p { 
                font-size: 0.9rem;
                margin: 0; 
                opacity: 0.9; 
            }
            .form-row { flex-direction: column; gap: 15px; }
        }

        /* SweetAlert Custom Fixes */
        .swal2-input { height: 48px !important; margin: 10px auto !important; }
        .swal-date-wrapper { position: relative; width: 80%; margin: 10px auto; }
        .swal-date-wrapper input { width: 100% !important; box-sizing: border-box !important; margin: 0 !important; }
        .swal-date-wrapper i { right: 15px; }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div>
                <h2>Data Transaksi Admin</h2>
                <p>Lihat dan kelola data transaksi admin secara terpusat</p>
            </div>
        </div>

        <!-- CARD FILTER -->
        <div class="form-card">
            <form method="GET" action="">
                <div class="form-row">
                    <div class="form-group">
                        <label for="start_date">Dari Tanggal</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Sampai Tanggal</label>
                        <div class="date-input-wrapper">
                            <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>">
                            <i class="fas fa-calendar-alt calendar-icon"></i>
                        </div>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn">Terapkan</button>
                    <button type="button" id="resetBtn" class="btn btn-cancel">Reset</button>
                </div>
            </form>
        </div>
        
        <!-- CARD TABEL -->
        <div class="jadwal-list">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3>Daftar Transaksi Administrator</h3>
                <a id="pdfLink" class="btn" style="padding: 8px 15px; font-size: 0.9rem;">
                    Cetak PDF
                </a>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>No Transaksi</th>
                            <th>Nama Siswa</th>
                            <th>No Rekening</th>
                            <th>Kelas</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="8" style="text-align:center; padding:30px; color:#999;">Belum ada data transaksi.</td></tr>
                        <?php else: ?>
                            <?php $no = $offset + 1; ?>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= formatTanggalIndonesia($transaction['created_at']) ?></td>
                                    <td><?= htmlspecialchars($transaction['no_transaksi']) ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_siswa']) ?></td>
                                    <td><?= htmlspecialchars(maskAccountNumber($transaction['no_rekening'])) ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_tingkatan'] . ' ' . $transaction['nama_kelas']) ?></td>
                                    <td><?= ucfirst(htmlspecialchars($transaction['jenis_transaksi'])) ?></td>
                                    <td><?= formatRupiah($transaction['jumlah']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div style="margin-top:20px; display:flex; justify-content:center; gap:5px;">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                    <a href="?page=<?= $i ?><?= !empty($start_date) ? '&start_date='.urlencode($start_date) : '' ?><?= !empty($end_date) ? '&end_date='.urlencode($end_date) : '' ?>" class="btn" style="padding:8px 12px; <?= $i==$current_page ? 'background:var(--primary-dark);' : 'opacity:0.7;' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        const mainContent = document.getElementById('mainContent');

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
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function(e) {
            if (body.classList.contains('sidebar-open') && 
                !sidebar?.contains(e.target) && 
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Prevent body scroll when sidebar open (mobile)
        let scrollTop = 0;
        body.addEventListener('scroll', function() {
            if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
                scrollTop = body.scrollTop;
            }
        }, { passive: true });

        // ============================================
        // FORM FUNCTIONALITY
        // ============================================
        document.getElementById('resetBtn').addEventListener('click', () => {
            window.location.href = '<?= BASE_URL ?>/pages/admin/transaksi_admin.php';
        });

        const pdfLink = document.getElementById('pdfLink');
        const totalRecords = <?= $total_records ?>;

        pdfLink.addEventListener('click', function(e) {
            e.preventDefault();
            if (totalRecords === 0) {
                Swal.fire('Tidak ada data', 'Tidak ada transaksi untuk diunduh', 'warning');
                return;
            }
            
            Swal.fire({
                title: 'Sedang memproses...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            setTimeout(() => {
                Swal.close();

                let url = '<?= BASE_URL ?>/pages/admin/download_laporan_admin.php?format=pdf';
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                if (startDate && endDate) {
                    url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                }
                
                window.open(url, '_blank');
            }, 2000);
        });
    </script>
</body>
</html>