<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Transaksi - SCHOBANK SYSTEM</title>

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

        .nav-brand i {
            font-size: 1.2rem;
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            position: relative;
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .search-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-dark));
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.3s ease;
        }

        .search-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-card:hover::after {
            transform: scaleX(1);
            transform-origin: left;
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            max-width: 600px;
        }

        .form-group {
            flex: 1;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
            transition: var(--transition);
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid #ddd;
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fcfcfc;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            background-color: white;
        }

        .form-group i {
            position: absolute;
            left: 1rem;
            top: 2.3rem;
            color: var(--text-secondary);
            transition: var(--transition);
        }

        .form-group input:focus + i {
            color: var(--primary-color);
        }

        .search-btn {
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
        }

        .search-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(12, 77, 162, 0.25);
        }

        /* Results Section */
        .results-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .transaction-details {
            display: grid;
            gap: 15px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
        }

        .transaction-details p {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid #eee;
            font-size: 16px;
        }

        .transaction-details p strong {
            color: var(--primary-color);
        }
        
        /* Transaction Info Styles */
        .transaction-info {
            margin-top: 20px;
            border-top: 2px dashed #e0e9f5;
            padding-top: 20px;
        }
        
        .transaction-info h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .account-info {
            background: #f0f7ff;
            border-radius: 8px;
            padding: 15px;
        }
        
        .account-box {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 15px;
        }
        
        .account-box:last-child {
            margin-bottom: 0;
        }
        
        .account-box h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 16px;
        }
        
        .transaction-icon {
            font-size: 1.5rem;
            color: var(--primary-color);
            background: var(--primary-light);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .transfer-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .arrow-container {
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            margin: 10px 0;
        }
        
        .transfer-arrow {
            color: var(--primary-color);
            font-size: 24px;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-deposit {
            background-color: #e6f4ea;
            color: #34a853;
        }
        
        .badge-withdraw {
            background-color: #fef2e0;
            color: #fbbc04;
        }
        
        .badge-transfer {
            background-color: #e8f0fe;
            color: #4285f4;
        }

        .alert {
            padding: 1.25rem;
            border-radius: 12px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: fadeIn 0.5s ease-out;
        }

        .alert-info {
            background: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
                position: sticky;
                top: 0;
                z-index: 100;
            }

            .nav-brand {
                font-size: 1.25rem;
            }

            .main-content {
                padding: 1rem;
            }

            .welcome-banner {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .welcome-banner h2 {
                font-size: 1.25rem;
            }

            .search-card,
            .results-card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
                border-radius: 12px;
            }

            .search-form {
                flex-direction: column;
                width: 100%;
                gap: 0.5rem;
            }

            .form-group {
                width: 100%;
            }

            .search-btn {
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
                padding: 1rem;
            }
            
            .transfer-details {
                grid-template-columns: 1fr;
            }
        }
        </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <i class="fas fa-university"></i>
            SCHOBANK
        </div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-banner slide-up">
            <h2>
                <i class="fas fa-search"></i>
                <span>Cari Transaksi</span>
            </h2>
        </div>

        <div class="search-card fade-in">
            <form action="" method="GET" class="search-form">
                <div class="form-group">
                    <label for="no_transaksi">No Transaksi:</label>
                    <input type="text" id="no_transaksi" name="no_transaksi" placeholder="Masukkan nomor transaksi" required>
                </div>
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i>
                    <span>Cari</span>
                </button>
            </form>
        </div>

        <?php
        if (isset($_GET['no_transaksi'])) {
            $no_transaksi = $_GET['no_transaksi'];
            
            // Secure against SQL injection
            $no_transaksi = $conn->real_escape_string($no_transaksi);
            
            // Query untuk mengambil semua jenis transaksi
            $query = "SELECT 
                        t.*, 
                        r1.no_rekening AS rekening_asal, 
                        u1.nama AS nama_nasabah, 
                        p.nama AS nama_petugas,
                        r2.no_rekening AS rekening_tujuan,
                        u2.nama AS nama_penerima
                      FROM 
                        transaksi t
                      JOIN 
                        rekening r1 ON t.rekening_id = r1.id
                      JOIN 
                        users u1 ON r1.user_id = u1.id
                      LEFT JOIN 
                        rekening r2 ON t.rekening_tujuan_id = r2.id
                      LEFT JOIN 
                        users u2 ON r2.user_id = u2.id
                      LEFT JOIN 
                        users p ON t.petugas_id = p.id
                      WHERE 
                        t.no_transaksi = '$no_transaksi'";
                      
            $result = $conn->query($query);

            echo '<div class="results-card">';
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                
                // Set badge class berdasarkan jenis transaksi
                $badgeClass = '';
                $transactionIcon = '';
                
                switch($row['jenis_transaksi']) {
                    case 'setor':
                        $badgeClass = 'badge-deposit';
                        $transactionIcon = '<i class="fas fa-arrow-circle-down"></i>';
                        break;
                    case 'tarik':
                        $badgeClass = 'badge-withdraw';
                        $transactionIcon = '<i class="fas fa-arrow-circle-up"></i>';
                        break;
                    case 'transfer':
                        $badgeClass = 'badge-transfer';
                        $transactionIcon = '<i class="fas fa-exchange-alt"></i>';
                        break;
                }
                
                // Tampilkan informasi dasar transaksi
                echo '<div class="transaction-details">
                    <p><strong>No Transaksi:</strong> <span>' . $row['no_transaksi'] . '</span></p>
                    <p><strong>Jenis Transaksi:</strong> <span><span class="badge ' . $badgeClass . '">' . strtoupper($row['jenis_transaksi']) . '</span></span></p>
                    <p><strong>Jumlah:</strong> <span>Rp ' . number_format($row['jumlah'], 0, ',', '.') . '</span></p>
                    <p><strong>Tanggal:</strong> <span>' . date('d/m/Y H:i', strtotime($row['created_at'])) . '</span></p>
                    <p><strong>Petugas:</strong> <span>' . ($row['nama_petugas'] ?? '-') . '</span></p>
                    <p><strong>Status:</strong> <span>' . strtoupper($row['status']) . '</span></p>
                </div>';
                
                echo '<div class="transaction-info">';
                
                // Tampilkan informasi berdasarkan jenis transaksi
                switch($row['jenis_transaksi']) {
                    case 'setor':
                        echo '<h3>' . $transactionIcon . ' Detail Setoran</h3>
                        <div class="account-info">
                            <div class="account-box">
                                <h4><i class="fas fa-user-circle"></i> Rekening Tujuan</h4>
                                <p><strong>No Rekening:</strong> ' . $row['rekening_asal'] . '</p>
                                <p><strong>Nama:</strong> ' . $row['nama_nasabah'] . '</p>
                                <p><strong>Keterangan:</strong> Setoran tunai ke rekening</p>
                            </div>
                        </div>';
                        break;
                    
                    case 'tarik':
                        echo '<h3>' . $transactionIcon . ' Detail Penarikan</h3>
                        <div class="account-info">
                            <div class="account-box">
                                <h4><i class="fas fa-user-circle"></i> Rekening Sumber</h4>
                                <p><strong>No Rekening:</strong> ' . $row['rekening_asal'] . '</p>
                                <p><strong>Nama:</strong> ' . $row['nama_nasabah'] . '</p>
                                <p><strong>Keterangan:</strong> Penarikan tunai dari rekening</p>
                            </div>
                        </div>';
                        break;
                    
                    case 'transfer':
                        echo '<h3>' . $transactionIcon . ' Detail Transfer</h3>
                        <div class="account-info">
                            <div class="transfer-details">
                                <div class="account-box">
                                    <h4><i class="fas fa-user-circle"></i> Rekening Pengirim</h4>
                                    <p><strong>No Rekening:</strong> ' . $row['rekening_asal'] . '</p>
                                    <p><strong>Nama:</strong> ' . $row['nama_nasabah'] . '</p>
                                </div>
                                <div class="account-box">
                                    <h4><i class="fas fa-user-circle"></i> Rekening Penerima</h4>
                                    <p><strong>No Rekening:</strong> ' . $row['rekening_tujuan'] . '</p>
                                    <p><strong>Nama:</strong> ' . $row['nama_penerima'] . '</p>
                                </div>
                            </div>
                        </div>';
                        break;
                }
                
                echo '</div>';
                
            } else {
                echo '<div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Transaksi tidak ditemukan.
                </div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Form handling
            $('form').on('submit', function(e) {
                const input = $('#no_transaksi');
                const value = input.val().trim();
                
                if (!value) {
                    e.preventDefault();
                    input.addClass('error');
                    setTimeout(() => input.removeClass('error'), 1000);
                    input.focus();
                    return false;
                }
            });
        });
    </script>
</body>
</html>