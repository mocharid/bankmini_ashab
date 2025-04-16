<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user's name from users table
$query = "SELECT nama FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$nama_pemilik = $user_data['nama'] ?? 'Pengguna';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            -webkit-text-size-adjust: none;
            -webkit-user-select: none;
            user-select: none;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-size: clamp(0.9rem, 2vw, 1rem);
            -webkit-text-size-adjust: none;
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
            animation: fadeInBanner 0.8s ease-out;
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
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes fadeInBanner {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .filter-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .date-group {
            display: flex;
            gap: 15px;
            flex: 1;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .filter-group input[type="date"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .filter-group input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
            align-self: flex-end;
        }

        .btn {
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
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-print {
            background-color: var(--secondary-color);
        }

        .btn-print:hover {
            background-color: var(--secondary-dark);
        }

        .dropdown-container {
            margin-bottom: 30px;
        }

        .dropdown-container h3 {
            margin-bottom: 10px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dropdown {
            width: 100%;
            max-width: 300px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            background: white;
            color: var(--text-primary);
            cursor: pointer;
            transition: var(--transition);
        }

        .dropdown:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .transactions-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .transactions-container:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .transactions-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        td {
            border-bottom: 1px solid #eee;
            color: var(--text-primary);
        }

        .amount-positive {
            color: #10b981;
            font-weight: 600;
        }

        .amount-negative {
            color: var(--danger-color);
            font-weight: 600;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            font-weight: 500;
        }

        .status-sukses {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-gagal {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.5rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 15px;
            padding: 20px 0;
        }

        .pagination-link {
            background-color: var(--primary-light);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            transition: var(--transition);
        }

        .pagination-link:hover:not(.disabled) {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination-link.disabled {
            background-color: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .filter-section {
                flex-direction: column;
                padding: 20px;
            }

            .date-group {
                flex-direction: column;
                gap: 10px;
                width: 100%;
            }

            .filter-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .dropdown {
                max-width: 100%;
            }

            .transactions-container {
                padding: 20px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .transactions-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>Cek Mutasi</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-receipt"></i> Riwayat Transaksi</h2>
            <p>Nomor Rekening: <?= htmlspecialchars($no_rekening ?? 'N/A') ?></p>
            <p>Nama: <?= htmlspecialchars($nama_pemilik) ?></p>
        </div>

        <div id="alertContainer"></div>

        <?php if (isset($error)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    showAlert('<?= htmlspecialchars($error) ?>', 'error');
                });
            </script>
        <?php else: ?>
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="date-group">
                    <div class="filter-group">
                        <label for="start_date">Dari Tanggal</label>
                        <input type="date" id="start_date" name="start_date" 
                               value="<?= date('Y-m-d', strtotime('-1 month')) ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">Sampai Tanggal</label>
                        <input type="date" id="end_date" name="end_date" 
                               value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="filter-buttons">
                    <button id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button id="printButton" class="btn btn-print">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </div>

            <!-- Dropdown -->
            <div class="dropdown-container">
                <h3><i class="fas fa-list"></i> Pilih Jenis Transaksi</h3>
                <select id="transactionType" class="dropdown">
                    <option value="all">Semua Jenis Transaksi</option>
                    <option value="transfer">Transaksi Transfer</option>
                    <option value="masuk">Transaksi Dana Masuk</option>
                    <option value="keluar">Transaksi Dana Keluar</option>
                </select>
            </div>

            <!-- Transactions Table -->
            <div class="transactions-container">
                <h3 class="transactions-title"><i class="fas fa-table"></i> Daftar Transaksi</h3>
                <div id="filteredResults">
                    <!-- Hasil filter akan ditampilkan di sini -->
                </div>
                <div class="pagination">
                    <a href="#" id="prevPage" class="pagination-link">« Sebelumnya</a>
                    <a href="#" id="nextPage" class="pagination-link">Selanjutnya »</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
const transactions = <?= isset($transactions) ? json_encode($transactions) : '[]' ?>;
const userRekeningId = <?= isset($rekening_id) ? $rekening_id : 'null' ?>;
const userNoRekening = "<?= isset($no_rekening) ? htmlspecialchars($no_rekening) : '' ?>";
let currentTab = 'all';
let currentPage = 1;
const recordsPerPage = 10;
let filteredData = [];

const prevPageBtn = document.getElementById('prevPage');
const nextPageBtn = document.getElementById('nextPage');
const transactionTypeDropdown = document.getElementById('transactionType');
const alertContainer = document.getElementById('alertContainer');

// Prevent pinch zooming
document.addEventListener('touchstart', function(event) {
    if (event.touches.length > 1) {
        event.preventDefault();
    }
}, { passive: false });

// Prevent double-tap zooming
let lastTouchEnd = 0;
document.addEventListener('touchend', function(event) {
    const now = (new Date()).getTime();
    if (now - lastTouchEnd <= 300) {
        event.preventDefault();
    }
    lastTouchEnd = now;
}, { passive: false });

// Prevent zoom on wheel with ctrl
document.addEventListener('wheel', function(event) {
    if (event.ctrlKey) {
        event.preventDefault();
    }
}, { passive: false });

function showAlert(message, type) {
    const existingAlerts = document.querySelectorAll('.alert');
    existingAlerts.forEach(alert => {
        alert.classList.add('hide');
        setTimeout(() => alert.remove(), 500);
    });
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    let icon = 'info-circle';
    if (type === 'success') icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    alertDiv.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
    `;
    alertContainer.appendChild(alertDiv);
    setTimeout(() => {
        alertDiv.classList.add('hide');
        setTimeout(() => alertDiv.remove(), 500);
    }, 5000);
}

function filterTransactions(startDate, endDate, tabType) {
    if (!startDate || !endDate) {
        showAlert('Silakan pilih tanggal yang valid', 'error');
        return [];
    }
    return transactions.filter(transaction => {
        const transactionDate = new Date(transaction.created_at);
        const isInDateRange = transactionDate >= new Date(startDate) && 
                              transactionDate <= new Date(endDate + 'T23:59:59');
        
        if (!isInDateRange) return false;
        
        if (tabType === 'all') return true;
        if (tabType === 'transfer') return transaction.jenis_transaksi === 'transfer';
        if (tabType === 'masuk') {
            return transaction.jenis_transaksi === 'setor' || 
                   (transaction.jenis_transaksi === 'transfer' && transaction.rekening_tujuan_id === userRekeningId);
        }
        if (tabType === 'keluar') {
            return transaction.jenis_transaksi === 'tarik' || 
                   (transaction.jenis_transaksi === 'transfer' && transaction.rekening_id === userRekeningId);
        }
        return true;
    });
}

function displayFilteredResults(filteredTransactions) {
    filteredData = filteredTransactions;
    currentPage = 1;
    updatePaginationUI();
    displayTransactions(getPaginatedData());
}

function getPaginatedData() {
    const startIndex = (currentPage - 1) * recordsPerPage;
    const endIndex = startIndex + recordsPerPage;
    return filteredData.slice(startIndex, endIndex);
}

function displayTransactions(transactions) {
    const resultsContainer = document.getElementById('filteredResults');
    if (transactions.length > 0) {
        let html = `
            <table>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Detail</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
        `;

        transactions.forEach(transaction => {
            let typeText = '';
            let amountClass = '';
            let amountPrefix = '';
            let detail = '';

            if (transaction.jenis_transaksi === 'setor') {
                typeText = '<i class="fas fa-arrow-down"></i> Setor';
                amountClass = 'amount-positive';
                amountPrefix = '+';
                detail = transaction.petugas_nama ? `Oleh: ${transaction.petugas_nama}` : 'Sistem';
            } else if (transaction.jenis_transaksi === 'tarik') {
                typeText = '<i class="fas fa-arrow-up"></i> Tarik';
                amountClass = 'amount-negative';
                amountPrefix = '-';
                detail = transaction.petugas_nama ? `Oleh: ${transaction.petugas_nama}` : 'Sistem';
            } else if (transaction.jenis_transaksi === 'transfer') {
                if (transaction.rekening_id === userRekeningId) {
                    typeText = '<i class="fas fa-paper-plane"></i> Transfer Keluar';
                    amountClass = 'amount-negative';
                    amountPrefix = '-';
                    detail = `Ke: ${transaction.rekening_penerima || 'N/A'}`;
                } else {
                    typeText = '<i class="fas fa-download"></i> Transfer Masuk';
                    amountClass = 'amount-positive';
                    amountPrefix = '+';
                    detail = `Dari: ${transaction.rekening_pengirim || 'N/A'}`;
                }
            }

            html += `
                <tr>
                    <td>${new Date(transaction.created_at).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
                    <td>${typeText}</td>
                    <td class="${amountClass}">${amountPrefix} Rp ${parseFloat(transaction.jumlah).toLocaleString('id-ID')}</td>
                    <td>${detail}<br><small>${transaction.no_transaksi || 'N/A'}</small></td>
                    <td><span class="status-badge status-${transaction.status.toLowerCase()}">${transaction.status}</span></td>
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
                <i class="fas fa-receipt"></i>
                <p>Tidak ada transaksi dalam periode ini</p>
            </div>
        `;
    }
}

function updatePaginationUI() {
    const totalPages = Math.ceil(filteredData.length / recordsPerPage) || 1;
    prevPageBtn.classList.toggle('disabled', currentPage <= 1);
    nextPageBtn.classList.toggle('disabled', currentPage >= totalPages);
}

function applyFilters() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const filtered = filterTransactions(startDate, endDate, currentTab);
    displayFilteredResults(filtered);
}

function changePage(direction) {
    const totalPages = Math.ceil(filteredData.length / recordsPerPage) || 1;
    if (direction === 'prev' && currentPage > 1) {
        currentPage--;
    } else if (direction === 'next' && currentPage < totalPages) {
        currentPage++;
    }
    updatePaginationUI();
    displayTransactions(getPaginatedData());
}

if (prevPageBtn) {
    prevPageBtn.addEventListener('click', (e) => {
        e.preventDefault();
        changePage('prev');
    });
}

if (nextPageBtn) {
    nextPageBtn.addEventListener('click', (e) => {
        e.preventDefault();
        changePage('next');
    });
}

if (transactionTypeDropdown) {
    transactionTypeDropdown.addEventListener('change', () => {
        currentTab = transactionTypeDropdown.value;
        applyFilters();
    });
}

if (document.getElementById('filterButton')) {
    document.getElementById('filterButton').addEventListener('click', applyFilters);
}

if (document.getElementById('printButton')) {
    document.getElementById('printButton').addEventListener('click', () => {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        if (!startDate || !endDate) {
            showAlert('Silakan pilih tanggal untuk mencetak', 'error');
            return;
        }
        window.open(`cetak_mutasi.php?start_date=${startDate}&end_date=${endDate}&tab=${currentTab}`, '_blank');
    });
}

window.addEventListener('DOMContentLoaded', () => {
    if (userRekeningId !== null) {
        applyFilters();
    }
});
    </script>
</body>
</html>