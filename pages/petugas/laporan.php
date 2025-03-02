<?php
// Set timezone first to ensure all date functions use the correct time
date_default_timezone_set('Asia/Jakarta'); // Adjust to your local timezone if needed

require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Get today's date
$today = date('Y-m-d');

// Calculate summary statistics for today only - Exclude private transfers by students
$total_query = "SELECT 
    COUNT(id) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' THEN jumlah ELSE 0 END) as total_setoran,
    SUM(CASE WHEN jenis_transaksi = 'tarik' THEN jumlah ELSE 0 END) as total_penarikan,
    SUM(CASE WHEN jenis_transaksi = 'transfer' AND petugas_id IS NOT NULL THEN jumlah ELSE 0 END) as total_transfer
    FROM transaksi 
    WHERE DATE(created_at) = ? 
    AND (jenis_transaksi != 'transfer' OR (jenis_transaksi = 'transfer' AND petugas_id IS NOT NULL))";

$stmt = $conn->prepare($total_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f0f5ff;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-nav {
            background: #0a2e5c;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            transition: background-color 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.2);
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
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
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
        
        .type-transfer {
            background: #e0f2fe;
            color: #0369a1;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
            }

            .nav-buttons {
                gap: 10px;
            }

            .main-content {
                padding: 15px;
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
    <nav class="top-nav">
        <h1>SCHOBANK</h1>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>
    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-chart-bar"></i> Laporan Harian</h2>
            <p>Transaksi pada <?= date('d/m/Y') ?></p>
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
                <div class="summary-box">
                    <h3>Total Transfer</h3>
                    <div class="amount">Rp <?= number_format($totals['total_transfer'] ?? 0, 0, ',', '.') ?></div>
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

            <?php
            // Get today's transactions with proper user information but exclude private transfers (where petugas_id IS NULL)
            $query = "SELECT t.*, 
                      u1.nama as nama_siswa,
                      u2.nama as nama_penerima,
                      r2.no_rekening as rekening_tujuan 
                      FROM transaksi t 
                      JOIN rekening r1 ON t.rekening_id = r1.id
                      JOIN users u1 ON r1.user_id = u1.id
                      LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
                      LEFT JOIN users u2 ON r2.user_id = u2.id
                      WHERE DATE(t.created_at) = ?
                      AND (t.jenis_transaksi != 'transfer' OR (t.jenis_transaksi = 'transfer' AND t.petugas_id IS NOT NULL))
                      ORDER BY t.created_at DESC";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $today);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                echo '<div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                                <th>Waktu</th>
                                <th>Penerima</th>
                            </tr>
                        </thead>
                        <tbody>';
                
                while ($row = $result->fetch_assoc()) {
                    // Determine transaction type class and display text
                    if ($row['jenis_transaksi'] == 'setor') {
                        $typeClass = 'type-setoran';
                        $displayType = 'setoran';
                    } elseif ($row['jenis_transaksi'] == 'tarik') {
                        $typeClass = 'type-penarikan';
                        $displayType = 'penarikan';
                    } else {
                        $typeClass = 'type-transfer';
                        $displayType = 'transfer';
                    }
                    
                    echo "<tr>
                        <td>{$row['no_transaksi']}</td>
                        <td>{$row['nama_siswa']}</td>
                        <td><span class='transaction-type {$typeClass}'>{$displayType}</span></td>
                        <td class='amount-total'>Rp " . number_format($row['jumlah'], 0, ',', '.') . "</td>
                        <td>" . date('H:i', strtotime($row['created_at'])) . "</td>
                        <td>" . ($row['jenis_transaksi'] == 'transfer' ? $row['nama_penerima'] . " (" . $row['rekening_tujuan'] . ")" : "-") . "</td>
                    </tr>";
                }
                echo '</tbody></table>
                </div>';
            } else {
                echo '<div class="alert">
                    <i class="fas fa-info-circle"></i>
                    Tidak ada transaksi hari ini.
                </div>';
            }
            ?>
        </div>
    </div>
</body>
</html>