<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if the database connection exists
if (!isset($koneksi) || $koneksi === null) {
    // If the connection variable doesn't exist, create it
    $koneksi = new mysqli("localhost", "root", "", "mini_bank_sekolah");
    
    // Check connection
    if ($koneksi->connect_error) {
        die("Connection failed: " . $koneksi->connect_error);
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user details
$stmt = $koneksi->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get user's account if they're a student
$rekening = null;
if ($role == 'siswa') {
    $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rekening = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Process transfer form
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate form data
    $rekening_asal = $_POST['rekening_asal'] ?? '';
    $rekening_tujuan = $_POST['rekening_tujuan'] ?? '';
    $jumlah = $_POST['jumlah'] ?? 0;
    
    if (empty($rekening_asal) || empty($rekening_tujuan) || $jumlah <= 0) {
        $error = "Semua field harus diisi dengan benar.";
    } else if ($rekening_asal == $rekening_tujuan) {
        $error = "Nomor rekening tujuan tidak boleh sama dengan rekening asal.";
    } else {
        // Get rekening details
        $stmt = $koneksi->prepare("SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_asal);
        $stmt->execute();
        $rek_asal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        $stmt = $koneksi->prepare("SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id WHERE r.no_rekening = ?");
        $stmt->bind_param("s", $rekening_tujuan);
        $stmt->execute();
        $rek_tujuan = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Validate accounts
        if (!$rek_asal) {
            $error = "Rekening asal tidak ditemukan.";
        } else if (!$rek_tujuan) {
            $error = "Rekening tujuan tidak ditemukan.";
        } else if ($rek_asal['saldo'] < $jumlah) {
            $error = "Saldo tidak mencukupi untuk transfer.";
        } else {
            // Begin transaction
            $koneksi->begin_transaction();
            
            try {
                // Generate transaction number
                $no_transaksi = 'TRF' . date('YmdHis') . rand(100, 999);
                
                // Insert transaction record
                $stmt = $koneksi->prepare("INSERT INTO transaksi (no_transaksi, rekening_id, rekening_tujuan_id, jenis_transaksi, jumlah, petugas_id, status) VALUES (?, ?, ?, 'transfer', ?, ?, 'approved')");
                $stmt->bind_param("siidi", $no_transaksi, $rek_asal['id'], $rek_tujuan['id'], $jumlah, $user_id);
                $stmt->execute();
                $transaksi_id = $koneksi->insert_id;
                $stmt->close();
                
                // Update source account balance
                $saldo_asal_baru = $rek_asal['saldo'] - $jumlah;
                $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                $stmt->bind_param("di", $saldo_asal_baru, $rek_asal['id']);
                $stmt->execute();
                $stmt->close();
                
                // Update destination account balance
                $saldo_tujuan_baru = $rek_tujuan['saldo'] + $jumlah;
                $stmt = $koneksi->prepare("UPDATE rekening SET saldo = ? WHERE id = ?");
                $stmt->bind_param("di", $saldo_tujuan_baru, $rek_tujuan['id']);
                $stmt->execute();
                $stmt->close();
                
                // Create mutation record for source account
                $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                $jumlah_negative = -$jumlah; // negative for outgoing transfer
                $stmt->bind_param("iidd", $transaksi_id, $rek_asal['id'], $jumlah_negative, $saldo_asal_baru);
                $stmt->execute();
                $stmt->close();
                
                // Create mutation record for destination account
                $stmt = $koneksi->prepare("INSERT INTO mutasi (transaksi_id, rekening_id, jumlah, saldo_akhir) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("iidd", $transaksi_id, $rek_tujuan['id'], $jumlah, $saldo_tujuan_baru);
                $stmt->execute();
                $stmt->close();
                
                // Kirim notifikasi ke siswa pengirim
                $message_pengirim = "Transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " ke rekening " . $rek_tujuan['no_rekening'] . " berhasil. Saldo baru: Rp " . number_format($saldo_asal_baru, 0, ',', '.');
                $query_notifikasi_pengirim = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                $stmt_notifikasi_pengirim = $koneksi->prepare($query_notifikasi_pengirim);
                $stmt_notifikasi_pengirim->bind_param('is', $rek_asal['user_id'], $message_pengirim);
                $stmt_notifikasi_pengirim->execute();
                
                // Kirim notifikasi ke siswa penerima
                $message_penerima = "Anda menerima transfer sebesar Rp " . number_format($jumlah, 0, ',', '.') . " dari rekening " . $rek_asal['no_rekening'] . ". Saldo baru: Rp " . number_format($saldo_tujuan_baru, 0, ',', '.');
                $query_notifikasi_penerima = "INSERT INTO notifications (user_id, message) VALUES (?, ?)";
                $stmt_notifikasi_penerima = $koneksi->prepare($query_notifikasi_penerima);
                $stmt_notifikasi_penerima->bind_param('is', $rek_tujuan['user_id'], $message_penerima);
                $stmt_notifikasi_penerima->execute();
                
                $message = "Transfer berhasil dilakukan! Nomor transaksi: " . $no_transaksi;
                
                // Refresh the account data after transfer
                if ($role == 'siswa') {
                    $stmt = $koneksi->prepare("SELECT * FROM rekening WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $rekening = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }
                
                // Commit transaction
                $koneksi->commit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $koneksi->rollback();
                $error = "Transfer gagal: " . $e->getMessage();
            }
        }
    }
}

// Get all accounts for admin/officer selection
$accounts = [];
if ($role == 'admin' || $role == 'petugas') {
    $result = $koneksi->query("SELECT r.*, u.nama FROM rekening r JOIN users u ON r.user_id = u.id ORDER BY u.nama");
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
}

$username = $_SESSION['username'] ?? 'Pengguna';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer - SCHOBANK SYSTEM</title>
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

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-secondary);
        }

        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .input-group {
            display: flex;
            align-items: center;
        }

        .input-group-prepend {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-right: none;
            padding: 10px 15px;
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }

        .input-group .form-control {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: var(--transition);
            border: none;
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid #166534;
        }

        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #b91c1c;
        }

        small {
            display: block;
            margin-top: 5px;
            color: var(--text-secondary);
        }

        /* Styles for the search box */
        .search-box {
            position: relative;
            margin-bottom: 10px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 15px;
            padding-left: 40px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: var(--transition);
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        .search-box i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
        }

        /* Styles for the select2-like dropdown */
        .custom-select-wrapper {
            position: relative;
        }

        .custom-select {
            cursor: pointer;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 15px;
            background-color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-top: 5px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            z-index: 100;
            max-height: 300px;
            overflow-y: auto;
            display: none;
        }

        .custom-select-dropdown.active {
            display: block;
        }

        .custom-select-option {
            padding: 10px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .custom-select-option:hover {
            background-color: var(--primary-light);
        }

        .custom-select-option.selected {
            background-color: var(--primary-light);
        }

        .custom-select-no-results {
            padding: 10px 15px;
            color: var(--text-secondary);
            font-style: italic;
        }

        @media (max-width: 768px) {
            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: 24px;
            }

            .transaction-card {
                padding: 15px;
            }

            .btn {
                width: 100%;
                justify-content: center;
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
            <h2><i class="fas fa-paper-plane"></i> Transfer</h2>
            <p>Kirim uang ke rekening lain dengan mudah dan cepat</p>
            <a href="dashboard.php" class="cancel-btn" title="Kembali ke Dashboard">
                <i class="fas fa-arrow-left"></i>
            </a>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <?= $message ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <?= $error ?>
            </div>
        <?php endif; ?>
        
        <div class="transaction-card">
            <div class="card-body">
                <form method="POST" action="">
                    <?php if ($role == 'siswa' && $rekening): ?>
                        <div class="form-group">
                            <label>Rekening Asal:</label>
                            <input type="text" name="rekening_asal" value="<?= $rekening['no_rekening'] ?>" readonly class="form-control">
                            <small>Saldo: Rp <?= number_format($rekening['saldo'], 2, ',', '.') ?></small>
                        </div>
                    <?php else: ?>
                        <div class="form-group">
                            <label>Pilih Rekening Asal:</label>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-asal" placeholder="Cari rekening berdasarkan nomor atau nama" class="form-control">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-asal-select">
                                    <span>-- Pilih Rekening --</span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-asal-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= $account['no_rekening'] ?>"
                                             data-search="<?= $account['no_rekening'] ?> <?= $account['nama'] ?>">
                                            <?= $account['no_rekening'] ?> - <?= $account['nama'] ?> 
                                            (Rp <?= number_format($account['saldo'], 2, ',', '.') ?>)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_asal" id="rekening-asal-input" required>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label>Rekening Tujuan:</label>
                        <?php if ($role == 'siswa'): ?>
                            <input type="text" name="rekening_tujuan" class="form-control" required 
                                   placeholder="Masukkan nomor rekening tujuan">
                        <?php else: ?>
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" id="search-rekening-tujuan" placeholder="Cari rekening berdasarkan nomor atau nama" class="form-control">
                            </div>
                            <div class="custom-select-wrapper">
                                <div class="custom-select" id="rekening-tujuan-select">
                                    <span>-- Pilih Rekening --</span>
                                    <i class="fas fa-chevron-down"></i>
                                </div>
                                <div class="custom-select-dropdown" id="rekening-tujuan-dropdown">
                                    <div class="custom-select-option" data-value="">-- Pilih Rekening --</div>
                                    <?php foreach ($accounts as $account): ?>
                                        <div class="custom-select-option" 
                                             data-value="<?= $account['no_rekening'] ?>"
                                             data-search="<?= $account['no_rekening'] ?> <?= $account['nama'] ?>">
                                            <?= $account['no_rekening'] ?> - <?= $account['nama'] ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="rekening_tujuan" id="rekening-tujuan-input" required>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Jumlah Transfer:</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">Rp</span>
                            </div>
                            <input type="number" name="jumlah" class="form-control" required min="1000" step="1000"
                                   placeholder="Masukkan jumlah transfer">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i> Transfer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to initialize custom select dropdowns
            function initCustomSelect(selectId, dropdownId, inputId, searchId) {
                const selectElement = document.getElementById(selectId);
                const dropdownElement = document.getElementById(dropdownId);
                const inputElement = document.getElementById(inputId);
                const searchElement = document.getElementById(searchId);
                
                // Toggle dropdown when clicking on select
                selectElement.addEventListener('click', function() {
                    dropdownElement.classList.toggle('active');
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', function(e) {
                    if (!selectElement.contains(e.target) && !dropdownElement.contains(e.target) && 
                        !searchElement.contains(e.target)) {
                        dropdownElement.classList.remove('active');
                    }
                });
                
                // Handle option selection
                const options = dropdownElement.querySelectorAll('.custom-select-option');
                options.forEach(option => {
                    option.addEventListener('click', function() {
                        const value = this.getAttribute('data-value');
                        inputElement.value = value;
                        
                        // Update the display text
                        selectElement.querySelector('span').textContent = this.textContent;
                        
                        // Mark as selected
                        options.forEach(opt => opt.classList.remove('selected'));
                        this.classList.add('selected');
                        
                        // Close dropdown
                        dropdownElement.classList.remove('active');
                    });
                });
                
                // Handle search filtering
                searchElement.addEventListener('input', function() {
                    const searchValue = this.value.toLowerCase();
                    let hasResults = false;
                    
                    // Remove any existing "no results" message
                    const existingNoResults = dropdownElement.querySelector('.custom-select-no-results');
                    if (existingNoResults) {
                        dropdownElement.removeChild(existingNoResults);
                    }
                    
                    options.forEach(option => {
                        if (option.getAttribute('data-value') === '') return; // Skip the placeholder
                        
                        const searchText = option.getAttribute('data-search').toLowerCase();
                        if (searchText.includes(searchValue)) {
                            option.style.display = 'block';
                            hasResults = true;
                        } else {
                            option.style.display = 'none';
                        }
                    });
                    
                    // Show dropdown when searching
                    dropdownElement.classList.add('active');
                    
                    // Show "no results" message if no options match
                    if (!hasResults && searchValue !== '') {
                        const noResultsDiv = document.createElement('div');
                        noResultsDiv.className = 'custom-select-no-results';
                        noResultsDiv.textContent = 'Tidak ada rekening yang cocok dengan pencarian';
                        dropdownElement.appendChild(noResultsDiv);
                    }
                });
                
                // Focus on search input when dropdown opens
                selectElement.addEventListener('click', function() {
                    searchElement.focus();
                });
            }
            
            // Initialize both dropdowns if they exist
            if (document.getElementById('rekening-asal-select')) {
                initCustomSelect(
                    'rekening-asal-select', 
                    'rekening-asal-dropdown', 
                    'rekening-asal-input',
                    'search-rekening-asal'
                );
            }
            
            if (document.getElementById('rekening-tujuan-select')) {
                initCustomSelect(
                    'rekening-tujuan-select', 
                    'rekening-tujuan-dropdown', 
                    'rekening-tujuan-input',
                    'search-rekening-tujuan'
                );
            }
        });
    </script>
</body>
</html>