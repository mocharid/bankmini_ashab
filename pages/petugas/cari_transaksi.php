<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';

// Flag for error popup
$show_error_popup = false;
$error_message = '';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cari Transaksi - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --bg-light: #f7fafc;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --error-color: #e74c3c;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
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

        .form-section {
            background: var(--bg-table);
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

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .error-message {
            color: var(--error-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '\f110';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .results-card {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            padding: 12px 15px;
            gap: 8px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row:hover {
            background: #f1f5f9;
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
            border-top: 2px dashed var(--border-color);
            margin: 15px 0;
        }

        .detail-subheading {
            color: var(--primary-dark);
            font-size: clamp(0.95rem, 1.8vw, 1rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 15px 0;
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

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.4s ease-in-out forwards;
            cursor: pointer;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal, .error-modal {
            position: relative;
            text-align: center;
            width: clamp(280px, 70vw, 360px);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            border-radius: 12px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.8);
            opacity: 0;
            animation: popInModal 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: pointer;
            overflow: hidden;
        }

        .error-modal {
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
        }

        .success-modal::before, .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.8); opacity: 0; }
            80% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon, .error-icon {
            font-size: clamp(2rem, 4.5vw, 2.5rem);
            margin: 0 auto 10px;
            color: white;
            animation: bounceIn 0.5s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 8px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .success-modal p, .error-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            line-height: 1.5;
            padding: 0 10px;
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

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .success-modal, .error-modal {
                width: clamp(260px, 80vw, 340px);
                padding: clamp(12px, 3vw, 15px);
            }

            .success-icon, .error-icon {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
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

            input {
                min-height: 40px;
            }

            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                padding: 8px 10px;
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .success-modal, .error-modal {
                width: clamp(240px, 85vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
            }

            .success-icon, .error-icon {
                font-size: clamp(1.6rem, 3.5vw, 1.8rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            <h2> Cari Transaksi</h2>
            <p>Cari informasi transaksi berdasarkan nomor transaksi</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form id="searchForm" action="" method="GET" class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="no_transaksi">No Transaksi</label>
                        <div class="input-container">
                            <input type="text" id="no_transaksi" name="no_transaksi" placeholder="Masukan No Transaksi" required autofocus
                                   value="<?php echo isset($_GET['no_transaksi']) && preg_match('/^[0-9A-Z]+$/', $_GET['no_transaksi']) ? htmlspecialchars($_GET['no_transaksi']) : ''; ?>">
                        </div>
                        <span class="error-message" id="no_transaksi-error"></span>
                    </div>
                </div>
                <input type="hidden" name="form_submitted" value="1">
                <div class="form-buttons">
                    <button type="submit" class="btn" id="searchBtn">
                        <span class="btn-content"><i class="fas fa-search"></i> Cari</span>
                    </button>
                </div>
            </form>
        </div>

        <div id="results">
            <?php
            if (isset($_GET['form_submitted']) && isset($_GET['no_transaksi']) && !empty($_GET['no_transaksi'])) {
                $no_transaksi = $conn->real_escape_string($_GET['no_transaksi']);

                // Validate transaction number format
                if (!preg_match('/^[0-9A-Z]+$/', $no_transaksi)) {
                    $show_error_popup = true;
                    $error_message = 'No Transaksi Tidak Valid. Format: Angka atau huruf kapital';
                } else {
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
                        $show_error_popup = true;
                        $error_message = 'Error saat memeriksa transaksi: ' . htmlspecialchars($conn->error);
                    } elseif ($result->num_rows === 0) {
                        $show_error_popup = true;
                        $error_message = 'No Transaksi Tidak Ditemukan';
                    } else {
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
                        $status_display = $row['status'] === 'approved' ? 'Berhasil' : ($row['status'] === 'pending' ? 'Menunggu' : 'Ditolak');
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
                        <div class="results-card">
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
                    }
                }
            }
            ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
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

            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const inputNoTransaksi = document.getElementById('no_transaksi');
            const alertContainer = document.getElementById('alertContainer');
            const results = document.getElementById('results');

            // Clear query string on page load/refresh to prevent resubmission
            if (window.location.search) {
                history.replaceState(null, '', window.location.pathname);
            }

            // Initialize transaction input
            if (!inputNoTransaksi.value) {
                inputNoTransaksi.value = '';
            }

            // Handle transaction input
            inputNoTransaksi.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9A-Z]/g, '');
                this.value = value;
                document.getElementById('no_transaksi-error').classList.remove('show');
            });

            inputNoTransaksi.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9A-Z]/g, '');
                this.value = pastedData;
            });

            // Search form handling
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (searchForm.classList.contains('submitting')) return;
                searchForm.classList.add('submitting');

                const transaksi = inputNoTransaksi.value.trim();
                if (!transaksi || !/^[0-9A-Z]+$/.test(transaksi)) {
                    showAlert('No Transaksi Tidak Valid. Format: Angka atau huruf kapital', 'error');
                    inputNoTransaksi.classList.add('form-error');
                    setTimeout(() => inputNoTransaksi.classList.remove('form-error'), 400);
                    inputNoTransaksi.value = '';
                    inputNoTransaksi.focus();
                    document.getElementById('no_transaksi-error').classList.add('show');
                    document.getElementById('no_transaksi-error').textContent = 'No Transaksi Tidak Valid. Format: Angka atau huruf kapital';
                    searchForm.classList.remove('submitting');
                    return;
                }

                searchBtn.classList.add('loading');
                searchBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
                setTimeout(() => {
                    searchForm.submit();
                }, 1000);
            });

            // Show error popup if there's an error
            <?php if ($show_error_popup): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($error_message); ?>', 'error');
                    document.getElementById('no_transaksi').value = '';
                }, 500);
            <?php endif; ?>

            // Alert function
            function showAlert(message, type) {
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 400);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                alertDiv.id = 'alert-' + Date.now();
                alertDiv.addEventListener('click', () => {
                    closeModal(alertDiv.id);
                });
                setTimeout(() => closeModal(alertDiv.id), 5000);
            }

            // Modal close handling
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 400);
                }
            }

            // Animate results
            const resultRows = document.querySelectorAll('.detail-row');
            if (resultRows.length > 0) {
                results.style.display = 'none';
                setTimeout(() => {
                    results.style.display = 'block';
                    resultRows.forEach((row, index) => {
                        row.style.opacity = '0';
                        row.style.transform = 'translateY(10px)';
                        setTimeout(() => {
                            row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                            row.style.opacity = '1';
                            row.style.transform = 'translateY(0)';
                        }, index * 80);
                    });
                }, 100);
            }

            // Enter key support
            inputNoTransaksi.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchBtn.click();
                }
            });

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });

            // Focus input
            inputNoTransaksi.focus();
        });
    </script>
</body>
</html>