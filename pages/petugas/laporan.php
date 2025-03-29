<?php
// Set timezone first to ensure all date functions use the correct time
date_default_timezone_set('Asia/Jakarta'); // Adjust to your local timezone if needed

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Get today's date
$today = date('Y-m-d');

// Pagination settings
$limit = 10; // Number of records per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Calculate summary statistics for today only - Exclude transfers
$total_query = "SELECT 
    COUNT(id) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi 
    WHERE DATE(created_at) = ? 
    AND jenis_transaksi != 'transfer'"; // Hapus transfer dari query

$stmt = $conn->prepare($total_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();

// Get total number of records for pagination
$total_records_query = "SELECT COUNT(*) as total FROM transaksi WHERE DATE(created_at) = ? AND jenis_transaksi != 'transfer'";
$stmt_total = $conn->prepare($total_records_query);
$stmt_total->bind_param("s", $today);
$stmt_total->execute();
$total_records = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Laporan - SCHOBANK SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Consolas', 'Courier New', monospace;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
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
            background: linear-gradient(135deg, #0a2e5c 0%, #154785 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(10, 46, 92, 0.15);
            position: relative;
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .back-btn {
            position: absolute;
            top: 25px;
            right: 25px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: background-color 0.3s ease;
            text-decoration: none;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .report-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-box {
            background: #f8fafc;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }

        .summary-box:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .summary-box h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-box .amount {
            color: #0a2e5c;
            font-size: 20px;
            font-weight: bold;
        }

        .amount-total {
            color: #0a2e5c;
            font-weight: bold;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .download-btn {
            background: #0a2e5c;
            color: white;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            transition: background-color 0.3s ease;
        }

        .download-btn:hover {
            background-color: #154785;
        }

        .table-responsive {
            overflow-x: auto;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            background: white;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background-color: #f8fafc;
            color: #0a2e5c;
            font-weight: 600;
            white-space: nowrap;
        }

        tr:hover {
            background-color: #f8fafc;
        }

        .transaction-type {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
        }

        .type-setoran {
            background: #dcfce7;
            color: #166534;
        }

        .type-penarikan {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert {
            text-align: center;
            padding: 40px 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
            font-family: 'Consolas', 'Courier New', monospace;
            letter-spacing: 0.5px;
            font-size: 16px;
            margin: 20px 0;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination-btn {
            background: #0a2e5c;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            text-decoration: none;
            transition: background-color 0.3s ease;
        }

        .pagination-btn:hover {
            background: #154785;
        }

        .no-data-container {
            width: 100%;
            text-align: center;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: 20px;
                margin-right: 40px;
            }

            .back-btn {
                top: 20px;
                right: 20px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .download-btn {
                width: 100%;
                justify-content: center;
            }

            th, td {
                padding: 10px;
                font-size: 14px;
            }

            .summary-boxes {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-chart-bar"></i> Laporan Harian</h2>
            <p>Transaksi pada <?= date('d/m/Y') ?></p>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-times"></i>
            </a>
        </div>

        <div class="report-card">
            <div class="summary-boxes">
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Setoran</h3>
                    <div class="amount">Rp <?= number_format($totals['total_setoran'] ?? 0, 0, ',', '.') ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Penarikan</h3>
                    <div class="amount">Rp <?= number_format($totals['total_penarikan'] ?? 0, 0, ',', '.') ?></div>
                </div>
            </div>

            <div class="action-buttons">
                <a href="download_laporan.php?date=<?= $today ?>&format=pdf" target="_blank" class="download-btn">
                    <i class="fas fa-file-pdf"></i>
                    Download PDF
                </a>
                <a href="download_laporan.php?date=<?= $today ?>&format=excel" target="_blank" class="download-btn">
                    <i class="fas fa-file-excel"></i>
                    Download Excel
                </a>
            </div>

            <!-- Table Container -->
            <div class="table-responsive" id="transaction-table">
                <!-- Data akan diisi oleh JavaScript -->
            </div>

            <!-- Pagination -->
            <div class="pagination">
                <button id="prev-page" class="pagination-btn" style="display: none;">Back</button>
                <button id="next-page" class="pagination-btn" style="display: none;">Next</button>
            </div>
        </div>
    </div>

    <script>
        let currentPage = 1;
        const totalPages = <?= $total_pages ?>;

        // Function to fetch transactions via AJAX
        async function fetchTransactions(page) {
            try {
                const response = await fetch(`fetch_transactions.php?page=${page}`);
                const transactions = await response.json();
                updateTable(transactions);
                updatePagination(page);
            } catch (error) {
                console.error('Error fetching transactions:', error);
            }
        }

        // Function to update the table with new data
        function updateTable(transactions) {
            const tableContainer = document.getElementById('transaction-table');
            
            if (transactions.length > 0) {
                let tableHTML = `
                    <table>
                        <thead>
                            <tr>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                            </tr>
                        </thead>
                        <tbody>`;

                transactions.forEach(transaction => {
                    const typeClass = transaction.jenis_transaksi === 'setor' ? 'type-setoran' : 'type-penarikan';
                    const displayType = transaction.jenis_transaksi === 'setor' ? 'setoran' : 'penarikan';
                    tableHTML += `
                        <tr>
                            <td>${transaction.no_transaksi}</td>
                            <td>${transaction.nama_siswa}</td>
                            <td><span class="transaction-type ${typeClass}">${displayType}</span></td>
                            <td class="amount-total">Rp ${new Intl.NumberFormat('id-ID').format(transaction.jumlah)}</td>
                            <td>${new Date(transaction.created_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}</td>
                        </tr>`;
                });

                tableHTML += `</tbody></table>`;
                tableContainer.innerHTML = tableHTML;
            } else {
                tableContainer.innerHTML = `
                    <div class="alert">
                        <i class="fas fa-info-circle"></i>
                        [ TIDAK ADA TRANSAKSI HARI INI ]
                    </div>`;
            }
        }

        // Function to update pagination buttons
        function updatePagination(page) {
            const prevButton = document.getElementById('prev-page');
            const nextButton = document.getElementById('next-page');

            // Show/hide "Back" button
            if (page > 1) {
                prevButton.style.display = 'inline-block';
            } else {
                prevButton.style.display = 'none';
            }

            // Show/hide "Next" button
            if (page < totalPages) {
                nextButton.style.display = 'inline-block';
            } else {
                nextButton.style.display = 'none';
            }
        }

        // Event listeners for pagination buttons
        document.getElementById('prev-page').addEventListener('click', () => {
            if (currentPage > 1) {
                currentPage--;
                fetchTransactions(currentPage);
            }
        });

        document.getElementById('next-page').addEventListener('click', () => {
            if (currentPage < totalPages) {
                currentPage++;
                fetchTransactions(currentPage);
            }
        });

        // Load initial data
        fetchTransactions(currentPage);
    </script>
</body>
</html>