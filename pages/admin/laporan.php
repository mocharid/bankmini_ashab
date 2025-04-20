<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Ambil data transaksi berdasarkan filter tanggal
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Query untuk menghitung total
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_penarikan
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users p ON t.petugas_id = p.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NOT NULL";
$stmt_total = $conn->prepare($total_query);
$stmt_total->bind_param("ss", $start_date, $end_date);
$stmt_total->execute();
$totals_result = $stmt_total->get_result();
$totals = $totals_result->fetch_assoc();
$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_setoran'] = $totals['total_setoran'] ?? 0;
$totals['total_penarikan'] = $totals['total_penarikan'] ?? 0;
$totals['total_net'] = $totals['total_setoran'] - $totals['total_penarikan'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Laporan Transaksi - SCHOBANK SYSTEM</title>
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
            --setor-bg: #dcfce7;
            --tarik-bg: #fee2e2;
            --net-positive-bg: #dcfce7;
            --net-negative-bg: #fee2e2;
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
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="date"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
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

        .summary-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-title {
            background: var(--primary-dark);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .summary-table th, .summary-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: center;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .summary-table th {
            background: #f0f4ff;
            font-weight: 600;
            color: var(--text-primary);
        }

        .summary-table td {
            background: white;
        }

        .summary-table .net-positive {
            background: var(--net-positive-bg);
            color: #2e7d32;
        }

        .summary-table .net-negative {
            background: var(--net-negative-bg);
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
            background-color: #e8f5e9;
            color: #2e7d32;
            border-left: 5px solid #81c784;
        }

        .loading .btn {
            pointer-events: none;
            background-color: #cccccc;
        }

        .loading .btn i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
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

            .summary-container {
                padding: 20px;
            }

            .summary-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .summary-table th, .summary-table td {
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

            .summary-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-chart-line"></i> Laporan Transaksi</h2>
            <p>Periode: <?= htmlspecialchars(date('d/m/Y', strtotime($start_date))) ?> - <?= htmlspecialchars(date('d/m/Y', strtotime($end_date))) ?></p>
        </div>

        <div id="alertContainer"></div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($start_date) ?>" required>
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>" required>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" id="printButton" class="btn btn-print">
                        <i class="fas fa-print"></i> Cetak
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Section -->
        <div class="summary-container">
            <h3 class="summary-title">Ringkasan Transaksi</h3>
            <?php if ($totals['total_transactions'] > 0): ?>
                <table class="summary-table">
                    <thead>
                        <tr>
                            <th>Total Transaksi</th>
                            <th>Total Setoran</th>
                            <th>Total Penarikan</th>
                            <th>Total Bersih</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($totals['total_transactions']) ?></td>
                            <td>Rp <?= number_format($totals['total_setoran'], 0, ',', '.') ?></td>
                            <td>Rp <?= number_format($totals['total_penarikan'], 0, ',', '.') ?></td>
                            <td class="<?= $totals['total_net'] >= 0 ? 'net-positive' : 'net-negative' ?>">
                                Rp <?= number_format($totals['total_net'], 0, ',', '.') ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>Tidak ada transaksi dalam periode ini</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming
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

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.alert');
                existingAlerts.forEach(alert => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type}`;
                let icon = type === 'success' ? 'check-circle' : 'exclamation-circle';
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

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const printButton = document.getElementById('printButton');

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal yang valid', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }

                    filterButton.classList.add('loading');
                    filterButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';

                    const url = new URL(window.location);
                    url.searchParams.set('start_date', startDate);
                    url.searchParams.set('end_date', endDate);
                    window.location.href = url.toString();
                });
            }

            if (printButton) {
                printButton.addEventListener('click', function() {
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal untuk mencetak', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }

                    printButton.classList.add('loading');
                    printButton.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';

                    window.open(`download_laporan.php?start_date=${startDate}&end_date=${endDate}&format=pdf`, '_blank');
                    
                    // Reset button state after a short delay
                    setTimeout(() => {
                        printButton.classList.remove('loading');
                        printButton.innerHTML = '<i class="fas fa-print"></i> Cetak';
                    }, 1000);
                });
            }
        });
    </script>
</body>
</html>