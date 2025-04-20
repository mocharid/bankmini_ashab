<?php
// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Include authentication and database connection
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Validate session
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Get user information from session
$username = $_SESSION['username'] ?? 'Petugas';
$user_id = $_SESSION['user_id'] ?? 0;

// Get today's date
$today = date('Y-m-d');

// Pagination settings
$limit = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Calculate summary statistics for today
$total_query = "SELECT 
    COUNT(id) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi 
    WHERE DATE(created_at) = ? 
    AND jenis_transaksi != 'transfer'
    AND petugas_id IS NOT NULL";

$stmt = $conn->prepare($total_query);
if (!$stmt) {
    error_log("Error preparing total query: " . $conn->error);
    die("Error preparing total query");
}
$stmt->bind_param("s", $today);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Calculate net balance
$saldo_bersih = ($totals['total_setoran'] ?? 0) - ($totals['total_penarikan'] ?? 0);
$saldo_bersih = max(0, $saldo_bersih);

// Calculate total records for pagination
$total_records_query = "SELECT COUNT(*) as total 
                       FROM transaksi 
                       WHERE DATE(created_at) = ? 
                       AND jenis_transaksi != 'transfer'
                       AND petugas_id IS NOT NULL";
$stmt_total = $conn->prepare($total_records_query);
if (!$stmt_total) {
    error_log("Error preparing total records query: " . $conn->error);
    die("Error preparing total records query");
}
$stmt_total->bind_param("s", $today);
$stmt_total->execute();
$total_records = $stmt_total->get_result()->fetch_assoc()['total'];
$stmt_total->close();
$total_pages = ceil($total_records / $limit);

// Fetch transactions directly
try {
    $query = "SELECT 
        t.no_transaksi,
        t.jenis_transaksi,
        t.jumlah,
        t.created_at,
        u.nama AS nama_siswa
        FROM transaksi t 
        JOIN rekening r ON t.rekening_id = r.id 
        JOIN users u ON r.user_id = u.id 
        WHERE DATE(t.created_at) = ? 
        AND t.jenis_transaksi != 'transfer' 
        AND t.petugas_id IS NOT NULL 
        AND (t.status = 'approved' OR t.status IS NULL)
        ORDER BY t.created_at DESC 
        LIMIT ? OFFSET ?";
    $stmt_trans = $conn->prepare($query);
    if (!$stmt_trans) {
        throw new Exception("Failed to prepare transaction query: " . $conn->error);
    }
    $stmt_trans->bind_param("sii", $today, $limit, $offset);
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
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Laporan - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #1e88e5;
            --secondary-dark: #1565c0;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            transition: var(--transition);
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 20px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .report-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-box {
            background: var(--primary-light);
            padding: 20px;
            border-radius: 10px;
            transition: var(--transition);
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
        }

        .stat-box {
            background: var(--primary-light);
            padding: 20px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
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
        }

        .stat-box .stat-note {
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .download-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            text-decoration: none;
        }

        .download-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .download-btn.btn-loading {
            pointer-events: none;
            position: relative;
        }

        .download-btn.btn-loading span,
        .download-btn.btn-loading i {
            visibility: hidden;
        }

        .download-btn.btn-loading::after {
            content: '. . .';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-weight: bold;
            animation: dots 1.5s infinite;
        }

        @keyframes dots {
            0% { content: '. . .'; }
            33% { content: '.. .'; }
            66% { content: '...'; }
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 10px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            font-weight: 600;
            text-transform: uppercase;
        }

        td {
            color: var(--text-primary);
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .transaction-type {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            min-width: 90px;
            display: inline-block;
        }

        .type-setoran {
            background: #e0f2fe;
            color: #0369a1;
        }

        .type-penarikan {
            background: #fee2e2;
            color: #b91c1c;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert.alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #f87171;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
        }

        .pagination-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
        }

        .pagination-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        th:nth-child(1), td:nth-child(1) { width: 10%; }
        th:nth-child(2), td:nth-child(2) { width: 20%; }
        th:nth-child(3), td:nth-child(3) { width: 30%; }
        th:nth-child(4), td:nth-child(4) { width: 15%; }
        th:nth-child(5), td:nth-child(5) { width: 15%; }
        th:nth-child(6), td:nth-child(6) { width: 10%; }

        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            .action-buttons { flex-direction: column; }
            .download-btn { width: 100%; justify-content: center; }
            .summary-boxes { grid-template-columns: 1fr; }
            th, td { font-size: clamp(0.75rem, 1.6vw, 0.85rem); padding: 10px; }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <a href="dashboard.php" class="back-btn"><i class="fas fa-xmark"></i></a>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-chart-bar"></i> Laporan Harian</h2>
            <p>Transaksi pada <?= date('d/m/Y') ?></p>
        </div>

        <div class="report-card">
            <h3 class="section-title"><i class="fas fa-list"></i> Ringkasan Transaksi</h3>
            <div class="summary-boxes">
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Setoran</h3>
                    <div class="amount"><?= formatRupiah($totals['total_setoran'] ?? 0) ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Penarikan</h3>
                    <div class="amount"><?= formatRupiah($totals['total_penarikan'] ?? 0) ?></div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value"><?= formatRupiah($saldo_bersih) ?></div>
                        <div class="stat-note">Setoran - Penarikan</div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <a href="download_laporan.php?date=<?= $today ?>&format=pdf" class="download-btn" id="pdf-btn">
                    <i class="fas fa-file-pdf"></i><span>Unduh PDF</span>
                </a>
            </div>

            <div class="table-responsive" id="transaction-table">
                <?php if (empty($transactions)): ?>
                    <div class="alert"><i class="fas fa-info-circle"></i><span>TIDAK ADA TRANSAKSI HARI INI</span></div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $index => $transaction): ?>
                                <?php
                                $typeClass = $transaction['jenis_transaksi'] === 'setor' ? 'type-setoran' : 'type-penarikan';
                                $displayType = $transaction['jenis_transaksi'] === 'setor' ? 'Setoran' : 'Penarikan';
                                $noUrut = (($page - 1) * $limit) + $index + 1;
                                ?>
                                <tr>
                                    <td><?= $noUrut ?></td>
                                    <td><?= htmlspecialchars($transaction['no_transaksi'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_siswa'] ?? 'N/A') ?></td>
                                    <td><span class="transaction-type <?= $typeClass ?>"><?= $displayType ?></span></td>
                                    <td class="amount-total"><?= formatRupiah($transaction['jumlah']) ?></td>
                                    <td><?= date('H:i', strtotime($transaction['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="pagination">
                <button id="prev-page" class="pagination-btn" style="display: <?= $page > 1 ? 'inline-block' : 'none' ?>;">Kembali</button>
                <button id="next-page" class="pagination-btn" style="display: <?= $page < $total_pages ? 'inline-block' : 'none' ?>;">Lanjut</button>
            </div>
        </div>
    </div>

    <script>
        const totalPages = <?= $total_pages ?>;

        // Update pagination on click
        document.getElementById('prev-page').addEventListener('click', () => {
            if (<?= $page ?> > 1) {
                window.location.href = `laporan.php?page=<?= $page - 1 ?>`;
            }
        });

        document.getElementById('next-page').addEventListener('click', () => {
            if (<?= $page ?> < totalPages) {
                window.location.href = `laporan.php?page=<?= $page + 1 ?>`;
            }
        });

        // Handle PDF download with loading animation
        document.getElementById('pdf-btn').addEventListener('click', (e) => {
            const button = document.getElementById('pdf-btn');
            button.classList.add('btn-loading');
            // Allow browser to handle the download directly
            setTimeout(() => button.classList.remove('btn-loading'), 2000); // Reset loading state after 2s
        });
    </script>
</body>
</html>