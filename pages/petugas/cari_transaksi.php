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
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
        }

        .nav-left, .nav-right {
            flex: 0 0 40px;
            display: flex;
            align-items: center;
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
            box-sizing: border-box;
            overflow: hidden;
            aspect-ratio: 1/1;
            clip-path: circle(50%);
            transition: background 0.3s ease, margin-top 0.3s ease;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            margin-top: -2px;
        }

        .back-btn i {
            transform: none !important;
        }

        .top-nav h1 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            margin: 0 auto;
            text-align: center;
            flex: 1;
        }

        .main-content {
            flex: 1;
            padding: 15px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
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
            margin-bottom: 8px;
            font-size: clamp(1.3rem, 2.5vw, 1.6rem);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            position: relative;
            z-index: 1;
            opacity: 0.9;
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
        }

        .deposit-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .deposit-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .deposit-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
        }

        .form-group {
            width: 100%;
            max-width: 300px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        input[type="text"] {
            width: 100%;
            max-width: 300px;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: var(--transition);
            min-width: 120px;
            height: 40px;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        button:active {
            transform: scale(0.95);
        }

        button:disabled {
            background-color: var(--text-secondary);
            cursor: not-allowed;
        }

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading i,
        .btn-loading span {
            opacity: 0;
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
            animation: rotate 0.6s linear infinite;
        }

        @keyframes rotate {
            to { transform: rotate(360deg); }
        }

        .results-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
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
            padding: 8px 0;
            gap: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
            border-bottom: 1px solid #f0f0f0;
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

        .detail-divider {
            border-top: 2px dashed #e0e9f5;
            margin: 15px 0;
        }

        .detail-subheading {
            color: var(--primary-dark);
            font-size: clamp(0.95rem, 1.8vw, 1rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 10px 0;
            border-left: 3px solid var(--primary-color);
            padding-left: 8px;
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
            padding: 12px;
            border-radius: 8px;
            margin-top: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .alert-info {
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 4px solid #bae6fd;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #fecaca;
        }

        .section-title {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.15rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        /* Desktop (≥769px) */
        @media (min-width: 769px) {
            .detail-row {
                grid-template-columns: 1fr 2fr;
            }
            .form-group {
                max-width: 300px;
            }
            input[type="text"] {
                max-width: 300px;
            }
            button {
                min-width: 120px;
            }
            .back-btn {
                aspect-ratio: 1/1;
                clip-path: circle(50%);
            }
            .back-btn:hover {
                margin-top: -2px;
            }
            .top-nav h1 {
                margin: 0 auto;
                flex: 1;
            }
        }

        /* Tablet (481px–768px) */
        @media (min-width: 481px) and (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }
            .main-content {
                padding: 12px;
            }
            .welcome-banner {
                padding: 15px;
            }
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }
            .welcome-banner p {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }
            .deposit-card, .results-card {
                padding: 15px;
            }
            .deposit-form {
                gap: 12px;
            }
            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }
            .form-group {
                max-width: 250px;
            }
            .back-btn {
                width: 36px;
                height: 36px;
                padding: 8px;
                box-sizing: border-box;
                overflow: hidden;
            }
            .top-nav h1 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
                margin: 0 auto;
                flex: 1;
            }
            input[type="text"] {
                max-width: 250px;
            }
            button {
                min-width: 100px;
            }
        }

        /* Mobile (≤480px) */
        @media (max-width: 480px) {
            .top-nav {
                padding: 15px;
            }
            .main-content {
                padding: 10px;
            }
            .welcome-banner {
                padding: 12px;
            }
            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }
            .welcome-banner p {
                font-size: clamp(0.7rem, 1.5vw, 0.8rem);
            }
            .deposit-card, .results-card {
                padding: 12px;
            }
            .deposit-form {
                gap: 10px;
            }
            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }
            .section-title {
                font-size: clamp(0.9rem, 2vw, 1rem);
            }
            .form-group {
                max-width: 200px;
            }
            .back-btn {
                width: 36px;
                height: 36px;
                padding: 8px;
                box-sizing: border-box;
                overflow: hidden;
            }
            .top-nav h1 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
                margin: 0 auto;
                flex: 1;
            }
            input[type="text"] {
                max-width: 200px;
            }
            button {
                min-width: 100px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <div class="nav-left">
            <button class="back-btn" onclick="window.location.href='dashboard.php'">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <h1>SCHOBANK</h1>
        <div class="nav-right">
            <div style="width: 40px;"></div>
        </div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-search"></i> Cari Transaksi</h2>
            <p>Cari informasi transaksi berdasarkan nomor transaksi</p>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Transaksi</h3>
            <form id="searchForm" action="" method="GET" class="deposit-form">
                <div class="form-group">
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
                        j1.nama_jurusan AS jurusan_asal, 
                        k1.nama_kelas AS kelas_asal,
                        r2.no_rekening AS rekening_tujuan,
                        u2.nama AS nama_penerima,
                        j2.nama_jurusan AS jurusan_tujuan,
                        k2.nama_kelas AS kelas_tujuan,
                        pt.petugas1_nama,
                        pt.petugas2_nama,
                        up.nama AS petugas_nama
                      FROM 
                        transaksi t
                      JOIN 
                        rekening r1 ON t.rekening_id = r1.id
                      JOIN 
                        users u1 ON r1.user_id = u1.id
                      LEFT JOIN 
                        jurusan j1 ON u1.jurusan_id = j1.id
                      LEFT JOIN 
                        kelas k1 ON u1.kelas_id = k1.id
                      LEFT JOIN 
                        rekening r2 ON t.rekening_tujuan_id = r2.id
                      LEFT JOIN 
                        users u2 ON r2.user_id = u2.id
                      LEFT JOIN 
                        jurusan j2 ON u2.jurusan_id = j2.id
                      LEFT JOIN 
                        kelas k2 ON u2.kelas_id = k2.id
                      LEFT JOIN 
                        petugas_tugas pt ON DATE(t.created_at) = pt.tanggal
                      LEFT JOIN 
                        users up ON t.petugas_id = up.id
                      WHERE 
                        t.no_transaksi = '$no_transaksi'";
            
            $result = $conn->query($query);
            if (!$result) {
                error_log("Query failed: " . $conn->error);
                echo '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Error executing query. Please contact the administrator.</div>';
                exit;
            }

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
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
                // Map status
                $status_display = $row['status'];
                if ($row['status'] === 'approved') {
                    $status_display = 'Berhasil';
                } elseif ($row['status'] === 'pending') {
                    $status_display = 'Menunggu';
                } elseif ($row['status'] === 'rejected') {
                    $status_display = 'Ditolak';
                }
                // Combine petugas names from petugas_tugas
                $petugas_display = '';
                if (!empty($row['petugas1_nama']) && !empty($row['petugas2_nama'])) {
                    $petugas_display = htmlspecialchars($row['petugas1_nama']) . ' dan ' . htmlspecialchars($row['petugas2_nama']);
                } elseif (!empty($row['petugas1_nama'])) {
                    $petugas_display = htmlspecialchars($row['petugas1_nama']);
                } elseif (!empty($row['petugas2_nama'])) {
                    $petugas_display = htmlspecialchars($row['petugas2_nama']);
                } elseif (!empty($row['petugas_nama'])) {
                    $petugas_display = htmlspecialchars($row['petugas_nama']);
                } else {
                    $petugas_display = 'Tidak ada data petugas';
                }
                ?>
                <div class="results-card" id="resultsContainer">
                    <h3 class="section-title"><?php echo $transactionIcon; ?> Detail Transaksi</h3>
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
                        <div class="detail-value"><?php echo $petugas_display; ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($status_display); ?></div>
                    </div>
                    <div class="detail-divider"></div>
                    <div class="detail-subheading"><i class="fas fa-user-circle"></i> Rekening Sumber</div>
                    <div class="detail-row">
                        <div class="detail-label">No Rekening:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['rekening_asal']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Nama:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['nama_nasabah']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jurusan:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['jurusan_asal'] ?: '-'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Kelas:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['kelas_asal'] ?: '-'); ?></div>
                    </div>
                    <?php if ($row['jenis_transaksi'] === 'transfer' && !empty($row['rekening_tujuan_id'])) { ?>
                    <div class="detail-divider"></div>
                    <div class="detail-subheading"><i class="fas fa-user-circle"></i> Rekening Tujuan</div>
                    <div class="detail-row">
                        <div class="detail-label">No Rekening:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['rekening_tujuan']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Nama:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['nama_penerima']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jurusan:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['jurusan_tujuan'] ?: '-'); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Kelas:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['kelas_tujuan'] ?: '-'); ?></div>
                    </div>
                    <?php } ?>
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
                e.preventDefault();
                searchBtn.classList.add('btn-loading');
                searchBtn.disabled = true;
                setTimeout(() => {
                    searchBtn.classList.remove('btn-loading');
                    searchBtn.disabled = false;
                    searchForm.submit();
                }, 2000);
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