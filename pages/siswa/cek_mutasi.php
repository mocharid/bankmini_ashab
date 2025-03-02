<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'Pengguna';

// Get user's rekening
$query = "SELECT id, no_rekening FROM rekening WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$rekening_data = $result->fetch_assoc();

if (!$rekening_data) {
    $error = "Rekening tidak ditemukan!";
} else {
    $rekening_id = $rekening_data['id'];
    $no_rekening = $rekening_data['no_rekening'];

    // Get all transactions (both incoming and outgoing)
    $query = "SELECT 
                t.*,
                u_petugas.nama as petugas_nama,
                r_sender.no_rekening as rekening_pengirim,
                r_receiver.no_rekening as rekening_penerima,
                CASE 
                    WHEN t.rekening_id = ? THEN 'keluar'
                    WHEN t.rekening_tujuan_id = ? THEN 'masuk'
                    ELSE t.jenis_transaksi
                END as arah_transaksi
              FROM transaksi t 
              LEFT JOIN users u_petugas ON t.petugas_id = u_petugas.id
              LEFT JOIN rekening r_sender ON t.rekening_id = r_sender.id
              LEFT JOIN rekening r_receiver ON t.rekening_tujuan_id = r_receiver.id
              WHERE (t.rekening_id = ? OR t.rekening_tujuan_id = ?)
              ORDER BY t.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiii", $rekening_id, $rekening_id, $rekening_id, $rekening_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // Save transactions to array
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Cek Mutasi - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
            --info-color: #2196F3;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
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
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            text-align: center;
            position: relative;
            box-shadow: var(--shadow-md);
            overflow: hidden;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 8s infinite linear;
        }

        @keyframes shimmer {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }

        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: 16px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .cancel-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            text-decoration: none;
            z-index: 2;
        }

        .cancel-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: rotate(90deg);
        }

        .transaction-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            margin-bottom: 30px;
        }

        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .date-filter {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 14px;
            color: var(--text-secondary);
        }

        .date-filter input[type="date"] {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: var(--transition);
        }

        .date-filter input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .filter-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            height: 37px;
            transition: var(--transition);
        }

        .filter-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .tab-container {
            margin-top: 20px;
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            background: #f1f5f9;
            border-radius: 8px;
            overflow: hidden;
        }

        .tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-secondary);
            border-bottom: 3px solid transparent;
            transition: var(--transition);
        }

        .tab.active {
            background: white;
            color: var(--primary-color);
            border-bottom: 3px solid var(--primary-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: var(--primary-light);
            font-weight: 600;
            color: var(--primary-dark);
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-approved {
            background-color: #dcfce7;
            color: #166534;
        }

        .status-rejected {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .transaction-type {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .type-masuk {
            color: var(--secondary-color);
        }

        .type-keluar {
            color: var(--danger-color);
        }

        .type-setor {
            color: var(--secondary-color);
        }

        .type-tarik {
            color: var(--danger-color);
        }

        .type-transfer {
            color: var(--info-color);
        }

        .transfer-details {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 3px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .date-filter {
                flex-direction: column;
                gap: 10px;
            }

            .filter-group {
                width: 100%;
            }

            .date-filter input[type="date"] {
                width: 100%;
            }

            .filter-btn {
                width: 100%;
                justify-content: center;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <?php if (file_exists('../../includes/header.php')) {
        include '../../includes/header.php';
    } ?>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-history"></i> Cek Mutasi</h2>
            <p>Riwayat transaksi rekening Anda</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= $error ?>
            </div>
        <?php else: ?>
            <div class="transaction-card">
                <div class="date-filter">
                    <div class="filter-group">
                        <label for="start_date">Dari Tanggal:</label>
                        <input type="date" id="start_date" name="start_date" required 
                               value="<?= date('Y-m-d', strtotime('-1 month')) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">Sampai Tanggal:</label>
                        <input type="date" id="end_date" name="end_date" required
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <button type="button" id="filterButton" class="filter-btn">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <button type="button" id="printButton" class="filter-btn" style="background-color: #555;">
                        <i class="fas fa-print"></i>
                        Cetak
                    </button>
                </div>

                <div class="tab-container">
                    <div class="tabs">
                        <div class="tab active" data-tab="all">Semua Transaksi</div>
                        <div class="tab" data-tab="transfer">Transfer</div>
                        <div class="tab" data-tab="masuk">Dana Masuk</div>
                        <div class="tab" data-tab="keluar">Dana Keluar</div>
                    </div>
                </div>

                <div id="filteredResults">
                    <!-- Hasil filter akan ditampilkan di sini -->
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const transactions = <?= isset($transactions) ? json_encode($transactions) : '[]' ?>;
        const userRekeningId = <?= isset($rekening_id) ? $rekening_id : 'null' ?>;
        const userNoRekening = "<?= isset($no_rekening) ? $no_rekening : '' ?>";
        let currentTab = 'all';

        // Fungsi untuk memfilter transaksi berdasarkan tanggal dan tab
        function filterTransactions(startDate, endDate, tabType) {
            const filtered = transactions.filter(transaction => {
                const transactionDate = new Date(transaction.created_at);
                const isInDateRange = transactionDate >= new Date(startDate) && 
                                      transactionDate <= new Date(endDate + 'T23:59:59');
                
                if (!isInDateRange) return false;
                
                if (tabType === 'all') return true;
                
                if (tabType === 'transfer') {
                    return transaction.jenis_transaksi === 'transfer' && 
                           transaction.rekening_id === userRekeningId;
                }
                
                if (tabType === 'masuk') {
                    return (transaction.jenis_transaksi === 'setor') || 
                           (transaction.jenis_transaksi === 'transfer' && 
                            transaction.rekening_tujuan_id === userRekeningId);
                }
                
                if (tabType === 'keluar') {
                    return (transaction.jenis_transaksi === 'tarik') || 
                           (transaction.jenis_transaksi === 'transfer' && 
                            transaction.rekening_id === userRekeningId);
                }
                
                return true;
            });
            
            return filtered;
        }

        // Fungsi untuk menampilkan hasil filter
        function displayFilteredResults(filteredTransactions) {
            const resultsContainer = document.getElementById('filteredResults');
            if (filteredTransactions.length > 0) {
                let html = `
                    <table>
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>No Transaksi</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Detail</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                filteredTransactions.forEach(transaction => {
                    // Determine transaction type display
                    let typeClass = 'type-transfer';
                    let typeIcon = 'exchange-alt';
                    let typeText = 'Transfer';
                    
                    if (transaction.jenis_transaksi === 'setor') {
                        typeClass = 'type-setor';
                        typeIcon = 'arrow-up';
                        typeText = 'Setor';
                    } else if (transaction.jenis_transaksi === 'tarik') {
                        typeClass = 'type-tarik';
                        typeIcon = 'arrow-down';
                        typeText = 'Tarik';
                    } else if (transaction.jenis_transaksi === 'transfer') {
                        if (transaction.rekening_id === userRekeningId) {
                            typeClass = 'type-keluar';
                            typeIcon = 'paper-plane';
                            typeText = 'TF Keluar';
                        } else {
                            typeClass = 'type-masuk';
                            typeIcon = 'download';
                            typeText = 'TF Masuk';
                        }
                    }

                    // Build transfer details content
                    let transferDetails = '';
                    if (transaction.jenis_transaksi === 'transfer') {
                        if (transaction.rekening_id === userRekeningId) {
                            transferDetails = `<div class="transfer-details">Ke: ${transaction.rekening_penerima}</div>`;
                        } else {
                            transferDetails = `<div class="transfer-details">Dari: ${transaction.rekening_pengirim}</div>`;
                        }
                    } else if (transaction.petugas_nama) {
                        transferDetails = `<div class="transfer-details">Petugas: ${transaction.petugas_nama}</div>`;
                    }

                    html += `
                        <tr>
                            <td>${new Date(transaction.created_at).toLocaleString()}</td>
                            <td>${transaction.no_transaksi}</td>
                            <td>
                                <div class="transaction-type ${typeClass}">
                                    <i class="fas fa-${typeIcon}"></i>
                                    ${typeText}
                                </div>
                            </td>
                            <td>Rp ${parseFloat(transaction.jumlah).toLocaleString()}</td>
                            <td>${transferDetails}</td>
                            <td>
                                <span class="status-badge status-${transaction.status}">
                                    ${transaction.status}
                                </span>
                            </td>
                        </tr>
                    `;
                });

                html += `
                        </tbody>
                    </table>
                `;
                resultsContainer.innerHTML = html;
            } else {
                resultsContainer.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <p>Tidak ada transaksi pada rentang tanggal yang dipilih</p>
                    </div>
                `;
            }
        }

        // Function to apply filter based on current tab and date range
        function applyFilters() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const filtered = filterTransactions(startDate, endDate, currentTab);
            displayFilteredResults(filtered);
        }

        // Set up tab switching
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentTab = tab.dataset.tab;
                applyFilters();
            });
        });

        // Event listener for filter button
        document.getElementById('filterButton').addEventListener('click', applyFilters);

        // Event listener for print button
        document.getElementById('printButton').addEventListener('click', () => {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            window.open(`cetak_mutasi.php?start_date=${startDate}&end_date=${endDate}&tab=${currentTab}`, '_blank');
        });

        // Initial load
        window.addEventListener('DOMContentLoaded', applyFilters);
    </script>
</body>
</html>