<?php
session_start();
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['transaksi_id'])) {
    header('Location: dashboard.php');
    exit();
}

$transaksi_id = $_SESSION['transaksi_id'];
unset($_SESSION['transaksi_id']); // Remove from session after use

// Get transaction data with JOIN to rekening and users tables
$query = "SELECT t.*, r.no_rekening, u.nama as nama_nasabah, p.nama as nama_petugas 
          FROM transaksi t 
          JOIN rekening r ON t.rekening_id = r.id 
          JOIN users u ON r.user_id = u.id 
          JOIN users p ON t.petugas_id = p.id 
          WHERE t.id = ?";
          
$stmt = $conn->prepare($query);
$stmt->bind_param('i', $transaksi_id);
$stmt->execute();
$result = $stmt->get_result();
$transaksi = $result->fetch_assoc();

if (!$transaksi) {
    header('Location: dashboard.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bukti Transaksi - SCHOBANK SYSTEM</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #0c4da2;
            --primary-dark: #0a2e5c;
            --primary-light: #e0e9f5;
            --secondary-color: #4caf50;
            --accent-color: #ff9800;
            --danger-color: #f44336;
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
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navigation Styles */
        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
            backdrop-filter: blur(5px);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
        }

        /* Receipt Card */
        .receipt-card {
            background: white;
            border-radius: 15px;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            overflow: hidden;
            position: relative;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .receipt-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 1.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .receipt-header::before {
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

        .receipt-header h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .receipt-header p {
            opacity: 0.8;
            font-size: 0.9rem;
        }

        .receipt-body {
            padding: 2rem;
        }

        .transaction-details {
            position: relative;
        }

        .transaction-details::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 95%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M50 50 L80 80 L50 50 L20 80 L50 50 L80 20 L50 50 L20 20 L50 50' stroke='%230c4da2' stroke-width='0.5' fill='none' opacity='0.03'/%3E%3C/svg%3E");
            z-index: 0;
            pointer-events: none;
        }

        .detail-row {
            display: flex;
            padding: 1rem;
            border-bottom: 1px dashed #eee;
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .detail-row:hover {
            background-color: var(--primary-light);
            transform: translateX(5px);
        }

        .detail-label {
            flex: 1;
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            flex: 2;
            font-weight: 600;
            color: var(--text-primary);
            text-align: right;
        }

        .transaction-id {
            font-family: 'Courier New', Courier, monospace;
            letter-spacing: 1px;
            background: #f8f9fa;
            padding: 5px 10px;
            border-radius: 5px;
            border-left: 3px solid var(--primary-color);
        }

        .status {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 50px;
            font-weight: 500;
            text-align: center;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
        }

        .status.pending {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .status.approved {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .status.rejected {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Receipt Footer */
        .receipt-footer {
            padding: 1.5rem;
            background-color: #f8f9fa;
            border-top: 1px solid #eee;
            text-align: center;
        }

        .receipt-footer p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .receipt-footer p:last-child {
            margin-bottom: 0;
        }

        .receipt-barcode {
            margin: 1.5rem auto;
            padding: 0.5rem;
            background: white;
            border-radius: 5px;
            width: fit-content;
            border: 1px solid #eee;
        }

        .barcode-line {
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .barcode-line span {
            display: inline-block;
            height: 100%;
            width: 2px;
            margin: 0 1px;
            background-color: #333;
            transform: scaleY(0.8);
        }

        .barcode-line span:nth-child(even) {
            height: 65%;
        }

        .barcode-line span:nth-child(5n) {
            height: 85%;
        }

        .barcode-number {
            margin-top: 5px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.8rem;
            letter-spacing: 5px;
            color: #333;
        }

        /* Paper Cut Effect */
        .paper-cut {
            height: 20px;
            background-image: linear-gradient(45deg, transparent 33.333%, white 33.333%, white 66.667%, transparent 66.667%), linear-gradient(-45deg, transparent 33.333%, white 33.333%, white 66.667%, transparent 66.667%);
            background-size: 20px 40px;
            margin: 0 auto;
            margin-top: -1px;
            margin-bottom: -1px;
            position: relative;
            z-index: 1;
        }

        /* Button Styles */
        .button-container {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            padding: 0.85rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(12, 77, 162, 0.2);
            text-decoration: none;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(12, 77, 162, 0.25);
        }

        .btn-accent {
            background: linear-gradient(135deg, var(--accent-color), #e67e00);
        }

        .btn-accent:hover {
            background: linear-gradient(135deg, #e67e00, var(--accent-color));
        }

        /* Animation */
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .pulse {
            animation: pulse 2s infinite;
        }

        /* Footer */
        .page-footer {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            text-align: center;
            padding: 1.5rem;
            margin-top: auto;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
            }

            .nav-brand {
                font-size: 1.25rem;
            }

            .main-content {
                padding: 1rem;
            }

            .receipt-header h2 {
                font-size: 1.25rem;
            }

            .receipt-body {
                padding: 1.5rem;
            }

            .button-container {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
            }

            .top-nav, 
            .button-container,
            .page-footer,
            .no-print {
                display: none;
            }

            .main-content {
                padding: 0;
                margin: 0;
                width: 100%;
                max-width: none;
            }

            .receipt-card {
                box-shadow: none;
                margin: 0;
                border-radius: 0;
            }

            .receipt-body {
                padding: 1.5rem;
            }

            .detail-row:hover {
                background-color: transparent;
                transform: none;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="top-nav no-print">
        <div class="nav-brand">
            <i class="fas fa-university"></i>
            LITTLEBANK
        </div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-arrow-left"></i>
                <span>Kembali</span>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="receipt-card">
            <div class="receipt-header">
                <h2><i class="fas fa-receipt"></i> Bukti Transaksi</h2>
                <p>LITTLEBANK - Sistem Mini Bank Sekolah</p>
            </div>

            <div class="paper-cut"></div>
            
            <div class="receipt-body">
                <div class="transaction-details">
                    <div class="detail-row">
                        <div class="detail-label">No Transaksi:</div>
                        <div class="detail-value transaction-id"><?php echo htmlspecialchars($transaksi['no_transaksi']); ?></div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">No Rekening:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($transaksi['no_rekening']); ?></div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($transaksi['nama_nasabah']); ?></div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Jenis Transaksi:</div>
                        <div class="detail-value">
                            <?php 
                            $jenis = strtoupper(htmlspecialchars($transaksi['jenis_transaksi']));
                            $icon = ($jenis == 'SETOR') ? 'fa-arrow-up' : 'fa-arrow-down';
                            $color = ($jenis == 'SETOR') ? 'var(--secondary-color)' : 'var(--danger-color)';
                            echo "<span style='color: $color'><i class='fas $icon'></i> $jenis</span>";
                            ?>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Jumlah:</div>
                        <div class="detail-value">
                            <span style="font-size: 1.2rem; font-weight: 700;">
                                Rp <?php echo number_format($transaksi['jumlah'], 0, ',', '.'); ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Tanggal:</div>
                        <div class="detail-value">
                            <?php 
                            $tanggal = date('d/m/Y', strtotime($transaksi['created_at']));
                            $waktu = date('H:i:s', strtotime($transaksi['created_at']));
                            echo "$tanggal <span style='opacity: 0.7; font-size: 0.9rem;'>$waktu</span>";
                            ?>
                        </div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Petugas:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($transaksi['nama_petugas']); ?></div>
                    </div>

                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value">
                            <span class="status <?php echo htmlspecialchars($transaksi['status']); ?>">
                                <?php 
                                $status = strtoupper(htmlspecialchars($transaksi['status']));
                                if ($status == 'APPROVED') echo '<i class="fas fa-check-circle"></i> ';
                                else if ($status == 'PENDING') echo '<i class="fas fa-clock"></i> ';
                                else if ($status == 'REJECTED') echo '<i class="fas fa-times-circle"></i> ';
                                echo $status;
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="receipt-footer">
                <div class="receipt-barcode">
                    <div class="barcode-line">
                        <?php 
                        // Generate fake barcode lines
                        for ($i = 0; $i < 40; $i++) {
                            echo '<span></span>';
                        }
                        ?>
                    </div>
                    <div class="barcode-number">
                        <?php echo substr(preg_replace('/[^0-9]/', '', $transaksi['no_transaksi']), 0, 12); ?>
                    </div>
                </div>
                
                <p><strong>Terima Kasih Atas Transaksi Anda</strong></p>
                <p>Simpan bukti ini sebagai referensi transaksi Anda</p>
                <p style="font-size: 0.8rem; opacity: 0.7;">
                    <?php echo date('d F Y - H:i:s', strtotime($transaksi['created_at'])); ?>
                </p>
            </div>
        </div>

        <div class="button-container no-print">
            <button onclick="window.print()" class="btn pulse">
                <i class="fas fa-print"></i>
                Cetak Bukti
            </button>
            <a href="dashboard.php" class="btn btn-accent">
                <i class="fas fa-home"></i>
                Kembali ke Dashboard
            </a>
        </div>
    </div>

    <!-- Footer -->
    <footer class="page-footer no-print">
        <p>&copy; <?php echo date('Y'); ?> LITTLEBANK. All rights reserved.</p>
    </footer>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Animation for detail rows on hover
            $('.detail-row').hover(
                function() {
                    $(this).find('.detail-label').css('color', 'var(--primary-color)');
                },
                function() {
                    $(this).find('.detail-label').css('color', 'var(--text-secondary)');
                }
            );
            
            // Stop pulse animation after 10 seconds
            setTimeout(function() {
                $('.pulse').removeClass('pulse');
            }, 10000);
        });
    </script>
</body>
</html>