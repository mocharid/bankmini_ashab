<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cari Transaksi - SCHOBANK SYSTEM</title>
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
            -webkit-text-size-adjust: none;
            zoom: 1;
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

        .deposit-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .deposit-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .deposit-form {
            display: grid;
            gap: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        button {
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
            width: fit-content;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
            animation: slideStep 0.5s ease-in-out;
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
            gap: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
            text-align: left;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            text-align: left;
        }

        .transaction-info {
            margin-top: 20px;
            border-top: 2px dashed #e0e9f5;
            padding-top: 20px;
        }

        .transaction-info h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .account-info {
            background: #f0f7ff;
            border-radius: 12px;
            padding: 15px;
        }

        .account-box {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 15px;
        }

        .account-box:last-child {
            margin-bottom: 0;
        }

        .account-box h4 {
            color: var(--primary-dark);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(0.95rem, 2vw, 1rem);
        }

        .account-box p {
            font-size: clamp(0.9rem, 2vw, 0.95rem);
            margin: 5px 0;
        }

        .transfer-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
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
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-info {
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading span {
            visibility: hidden;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: rotate 0.8s linear infinite;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .deposit-card,
            .results-card {
                padding: 20px;
                margin-bottom: 20px;
            }

            .deposit-form {
                gap: 15px;
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            input[type="text"] {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            button {
                padding: 10px 20px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .transaction-info h3 {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .account-box h4 {
                font-size: clamp(0.9rem, 2vw, 0.95rem);
            }

            .account-box p {
                font-size: clamp(0.85rem, 2vw, 0.9rem);
            }

            .transfer-details {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .badge {
                font-size: clamp(0.7rem, 1.5vw, 0.75rem);
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                margin-top: 15px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner {
                padding: 15px;
                margin-bottom: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 2vw, 0.85rem);
            }

            .deposit-card,
            .results-card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .deposit-form {
                gap: 12px;
            }

            .section-title {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            input[type="text"] {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            button {
                padding: 8px 15px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .detail-row {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .transaction-info h3 {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            .account-box h4 {
                font-size: clamp(0.85rem, 2vw, 0.9rem);
            }

            .account-box p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }

            .badge {
                font-size: clamp(0.65rem, 1.5vw, 0.7rem);
            }

            .alert {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
                margin-top: 12px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-search"></i> Cari Transaksi</h2>
            <p>Cari informasi transaksi berdasarkan nomor transaksi</p>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Transaksi</h3>
            <form id="searchForm" action="" method="GET" class="deposit-form">
                <div>
                    <label for="no_transaksi">No Transaksi:</label>
                    <input type="text" id="no_transaksi" name="no_transaksi" placeholder="TRX..." required autofocus
                           value="<?php echo isset($_GET['no_transaksi']) ? htmlspecialchars($_GET['no_transaksi']) : 'TRX'; ?>">
                </div>
                <button type="submit" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cari</span>
                </button>
            </form>
        </div>

        <div id="alertContainer"></div>

        <?php
        if (isset($_GET['no_transaksi'])) {
            $no_transaksi = $conn->real_escape_string($_GET['no_transaksi']);
            $query = "SELECT 
                        t.*, 
                        r1.no_rekening AS rekening_asal, 
                        u1.nama AS nama_nasabah, 
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
                      WHERE 
                        t.no_transaksi = '$no_transaksi'";
            $result = $conn->query($query);

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $transaksi_date = date('Y-m-d', strtotime($row['created_at']));
                $query_petugas = "SELECT petugas1_nama, petugas2_nama FROM petugas_tugas WHERE tanggal = '$transaksi_date'";
                $result_petugas = $conn->query($query_petugas);
                
                $petugas_info = $result_petugas && $result_petugas->num_rows > 0
                    ? $result_petugas->fetch_assoc()['petugas1_nama'] . ' dan ' . $result_petugas->fetch_assoc()['petugas2_nama']
                    : 'Tidak ada data petugas';

                $badgeClass = '';
                $transactionIcon = '';
                switch ($row['jenis_transaksi']) {
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
                ?>
                <div class="results-card" id="resultsContainer">
                    <h3 class="section-title"><i class="fas fa-receipt"></i> Detail Transaksi</h3>
                    <div class="detail-row">
                        <div class="detail-label">No Transaksi:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['no_transaksi']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jenis Transaksi:</div>
                        <div class="detail-value"><span class="badge <?php echo $badgeClass; ?>"><?php echo strtoupper($row['jenis_transaksi']); ?></span></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jumlah:</div>
                        <div class="detail-value">Rp <?php echo number_format($row['jumlah'], 0, ',', '.'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Tanggal:</div>
                        <div class="detail-value"><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Petugas:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($petugas_info); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value"><?php echo strtoupper($row['status']); ?></div>
                    </div>

                    <div class="transaction-info">
                        <?php
                        switch ($row['jenis_transaksi']) {
                            case 'setor':
                                ?>
                                <h3><?php echo $transactionIcon; ?> Detail Setoran</h3>
                                <div class="account-info">
                                    <div class="account-box">
                                        <h4><i class="fas fa-user-circle"></i> Rekening Tujuan</h4>
                                        <p><strong>No Rekening:</strong> <?php echo htmlspecialchars($row['rekening_asal']); ?></p>
                                        <p><strong>Nama:</strong> <?php echo htmlspecialchars($row['nama_nasabah']); ?></p>
                                        <p><strong>Keterangan:</strong> Setoran tunai ke rekening</p>
                                    </div>
                                </div>
                                <?php
                                break;
                            case 'tarik':
                                ?>
                                <h3><?php echo $transactionIcon; ?> Detail Penarikan</h3>
                                <div class="account-info">
                                    <div class="account-box">
                                        <h4><i class="fas fa-user-circle"></i> Rekening Sumber</h4>
                                        <p><strong>No Rekening:</strong> <?php echo htmlspecialchars($row['rekening_asal']); ?></p>
                                        <p><strong>Nama:</strong> <?php echo htmlspecialchars($row['nama_nasabah']); ?></p>
                                        <p><strong>Keterangan:</strong> Penarikan tunai dari rekening</p>
                                    </div>
                                </div>
                                <?php
                                break;
                            case 'transfer':
                                ?>
                                <h3><?php echo $transactionIcon; ?> Detail Transfer</h3>
                                <div class="account-info">
                                    <div class="transfer-details">
                                        <div class="account-box">
                                            <h4><i class="fas fa-user-circle"></i> Rekening Pengirim</h4>
                                            <p><strong>No Rekening:</strong> <?php echo htmlspecialchars($row['rekening_asal']); ?></p>
                                            <p><strong>Nama:</strong> <?php echo htmlspecialchars($row['nama_nasabah']); ?></p>
                                        </div>
                                        <div class="account-box">
                                            <h4><i class="fas fa-user-circle"></i> Rekening Penerima</h4>
                                            <p><strong>No Rekening:</strong> <?php echo htmlspecialchars($row['rekening_tujuan']); ?></p>
                                            <p><strong>Nama:</strong> <?php echo htmlspecialchars($row['nama_penerima']); ?></p>
                                        </div>
                                    </div>
                                </div>
                                <?php
                                break;
                        }
                        ?>
                    </div>
                </div>
                <?php
            } else {
                ?>
                <div class="results-card" id="resultsContainer">
                    <div class="alert alert-error" id="alertNotFound">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Transaksi tidak ditemukan. Silakan periksa kembali nomor transaksi.</span>
                    </div>
                </div>
                <?php
            }
        }
        ?>
    </div>

    <script>
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('gesturestart', function(event) {
            event.preventDefault();
        });

        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        document.addEventListener('DOMContentLoaded', function() {
            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const inputNoTransaksi = document.getElementById('no_transaksi');
            const alertContainer = document.getElementById('alertContainer');
            const prefix = "TRX";

            // Initialize input with prefix
            if (!inputNoTransaksi.value) {
                inputNoTransaksi.value = prefix;
            }

            // Restrict transaction number to alphanumeric
            inputNoTransaksi.addEventListener('input', function(e) {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    this.value = prefix + value.replace(prefix, '');
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9A-Z]/g, '');
                this.value = prefix + userInput;
            });

            inputNoTransaksi.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoTransaksi.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9A-Z]/g, '');
                let currentValue = this.value.slice(prefix.length);
                let newValue = prefix + (currentValue + pastedData);
                this.value = newValue;
            });

            inputNoTransaksi.addEventListener('focus', function() {
                if (this.value === prefix) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoTransaksi.addEventListener('click', function(e) {
                if (this.selectionStart < prefix.length) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            searchForm.addEventListener('submit', function(e) {
                const transaksi = inputNoTransaksi.value.trim();
                if (transaksi === prefix) {
                    e.preventDefault();
                    showAlert('Silakan masukkan nomor transaksi lengkap', 'error');
                    inputNoTransaksi.classList.add('form-error');
                    setTimeout(() => inputNoTransaksi.classList.remove('form-error'), 400);
                    inputNoTransaksi.focus();
                    return;
                }
                searchBtn.classList.add('btn-loading');
                setTimeout(() => {
                    searchBtn.classList.remove('btn-loading');
                }, 800);
            });

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

            // Auto-hide and remove the "Transaksi tidak ditemukan" alert and its container
            const alertNotFound = document.getElementById('alertNotFound');
            if (alertNotFound) {
                setTimeout(() => {
                    const resultsContainer = document.getElementById('resultsContainer');
                    alertNotFound.classList.add('hide');
                    setTimeout(() => {
                        if (resultsContainer) resultsContainer.remove();
                    }, 500);
                }, 5000);
            }

            inputNoTransaksi.focus();
            if (inputNoTransaksi.value === prefix) {
                inputNoTransaksi.setSelectionRange(prefix.length, prefix.length);
            }

            inputNoTransaksi.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') searchBtn.click();
            });
        });
    </script>
</body>
</html>