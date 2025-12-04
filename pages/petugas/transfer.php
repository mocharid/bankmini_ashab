<?php
session_start();
require_once '../../includes/db_connection.php';
require '../../vendor/autoload.php';
require_once '../../includes/session_validator.php'; 

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$rekening = null;
$rekening_display = '';
if ($role == 'siswa') {
    $stmt = $conn->prepare("SELECT * FROM rekening WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rekening = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Format nomor rekening: 3 digit awal + *** + 2 digit akhir
    if ($rekening && isset($rekening['no_rekening'])) {
        $no_rek = $rekening['no_rekening'];
        $rekening_display = substr($no_rek, 0, 3) . '***' . substr($no_rek, -2);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transfer | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            user-select: none;
        }

        html {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            transition: background-color 0.3s ease;
            font-size: clamp(0.9rem, 2vw, 1rem);
            min-height: 100vh;
            touch-action: pan-y;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            transition: margin-left 0.3s ease;
            max-width: calc(100% - 280px);
            overflow-y: auto;
            min-height: 100vh;
        }
        
        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
        }

        /* Welcome Banner - Updated to match previous syntax */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 5px;
            margin-bottom: 35px;
            box-shadow: var(--shadow-md);
            animation: fadeIn 1s ease-in-out;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .welcome-banner .content {
            flex: 1;
        }
        
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
            font-weight: 400;
            opacity: 0.8;
        }
        
        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .form-section {
            background: white;
            border-radius: 5px;
            padding: 30px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 20px;
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
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        label i {
            color: var(--primary-color);
            font-size: 0.95em;
        }

        .search-wrapper {
            position: relative;
        }

        .search-input-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        input {
            width: 100%;
            padding: 12px 40px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
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
        }

        input[readonly] {
            background-color: #f8fafc;
            cursor: default;
            padding-right: 15px;
            letter-spacing: 1px;
            font-weight: 500;
        }

        .clear-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 5px;
            display: none;
            transition: var(--transition);
            z-index: 10;
        }

        .clear-btn:hover {
            color: var(--danger-color);
        }

        .clear-btn.show {
            display: block;
        }

        .search-results {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-top: 4px;
            box-shadow: var(--shadow-md);
            max-height: 250px;
            overflow-y: auto;
            z-index: 100;
            display: none;
        }

        .search-results.show {
            display: block;
        }

        .search-result-item {
            padding: 12px 15px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid #f3f4f6;
        }

        .search-result-item:last-child {
            border-bottom: none;
        }

        .search-result-item:hover {
            background-color: var(--bg-light);
        }

        .search-result-name {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .search-result-account {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 2px;
            letter-spacing: 1px;
        }

        .no-results {
            padding: 12px 15px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            text-align: center;
        }

        .btn-container {
            display: flex;
            justify-content: center;
            margin-top: 30px;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: var(--transition);
            min-width: 200px;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }

            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
                align-items: center;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }

            .form-section {
                padding: 20px 15px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
            
            .btn {
                width: 100%;
                max-width: 300px;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }
            
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.8vw, 1.3rem);
            }
            
            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }
            
            .form-section {
                padding: 15px;
            }
            
            .btn {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_petugas.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-exchange-alt"></i> Transfer Antar Rekening</h2>
                <p>Kirim uang antar rekening Schobank Student Digital Banking</p>
            </div>
        </div>

        <div class="form-section">
            <form id="transferForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="rekening_asal"><i class="fas fa-wallet"></i> Rekening Asal</label>
                        <?php if ($role == 'siswa' && $rekening): ?>
                            <input type="text" id="rekening_asal_display" value="<?= htmlspecialchars($rekening_display) ?>" readonly>
                            <input type="hidden" id="rekening_asal" name="rekening_asal" value="<?= htmlspecialchars($rekening['no_rekening']) ?>">
                        <?php else: ?>
                            <div class="search-wrapper">
                                <div class="search-input-container">
                                    <input type="text" id="rekening_asal_search" placeholder="Input nama atau nomor rekening asal" autocomplete="off">
                                    <button type="button" class="clear-btn" id="clear_asal">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                </div>
                                <div class="search-results" id="results_asal"></div>
                                <input type="hidden" id="rekening_asal" name="rekening_asal">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="rekening_tujuan"><i class="fas fa-user"></i> Rekening Tujuan</label>
                        <div class="search-wrapper">
                            <div class="search-input-container">
                                <input type="text" id="rekening_tujuan_search" placeholder="Input nama atau nomor rekening tujuan" autocomplete="off">
                                <button type="button" class="clear-btn" id="clear_tujuan">
                                    <i class="fas fa-times-circle"></i>
                                </button>
                            </div>
                            <div class="search-results" id="results_tujuan"></div>
                            <input type="hidden" id="rekening_tujuan" name="rekening_tujuan">
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="jumlah"><i class="fas fa-money-bill-wave"></i> Nominal Transfer</label>
                        <input type="text" id="jumlah" name="jumlah" required inputmode="numeric" placeholder="Input nominal transfer....">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="keterangan"><i class="fas fa-comment-alt"></i> Keterangan (Opsional)</label>
                        <input type="text" id="keterangan" name="keterangan" placeholder="Input keterangan transfer (opsional)">
                    </div>
                </div>

                <div class="btn-container">
                    <button type="button" class="btn" id="transferBtn">
                        <i class="fas fa-paper-plane"></i> Proses Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let searchTimeout;
        
        // Function to format account number: 3 digits + *** + 2 digits
        function formatAccountNumber(accountNumber) {
            if (!accountNumber || accountNumber.length < 5) return accountNumber;
            return accountNumber.substring(0, 3) + '***' + accountNumber.substring(accountNumber.length - 2);
        }
        
        function initSearch(searchInputId, resultsId, hiddenInputId, clearBtnId) {
            const searchInput = document.getElementById(searchInputId);
            const resultsDiv = document.getElementById(resultsId);
            const hiddenInput = document.getElementById(hiddenInputId);
            const clearBtn = document.getElementById(clearBtnId);
            
            if (!searchInput || !resultsDiv || !hiddenInput || !clearBtn) return;
            
            searchInput.addEventListener('input', async function() {
                const query = this.value.trim();
                
                if (query.length < 2) {
                    resultsDiv.classList.remove('show');
                    clearBtn.classList.remove('show');
                    hiddenInput.value = '';
                    return;
                }
                
                clearBtn.classList.add('show');
                clearTimeout(searchTimeout);
                
                searchTimeout = setTimeout(async () => {
                    const formData = new FormData();
                    formData.append('ajax_action', 'search_account');
                    formData.append('search', query);
                    
                    try {
                        const response = await fetch('proses_transfer.php', { method: 'POST', body: formData });
                        const result = await response.json();
                        
                        if (result.success && result.accounts.length > 0) {
                            resultsDiv.innerHTML = '';
                            result.accounts.forEach(account => {
                                const item = document.createElement('div');
                                item.className = 'search-result-item';
                                const displayNumber = formatAccountNumber(account.no_rekening);
                                item.innerHTML = `
                                    <div class="search-result-name">${account.nama}</div>
                                    <div class="search-result-account">${displayNumber}</div>
                                `;
                                item.addEventListener('click', () => {
                                    searchInput.value = `${account.nama} - ${displayNumber}`;
                                    hiddenInput.value = account.no_rekening;
                                    resultsDiv.classList.remove('show');
                                    clearBtn.classList.add('show');
                                });
                                resultsDiv.appendChild(item);
                            });
                            resultsDiv.classList.add('show');
                        } else {
                            resultsDiv.innerHTML = '<div class="no-results">Tidak ada hasil ditemukan</div>';
                            resultsDiv.classList.add('show');
                        }
                    } catch (error) {
                        console.error('Search error:', error);
                    }
                }, 300);
            });
            
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                hiddenInput.value = '';
                clearBtn.classList.remove('show');
                resultsDiv.classList.remove('show');
                searchInput.focus();
            });
            
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                    resultsDiv.classList.remove('show');
                }
            });
        }
        
        // Function to ask for PIN with retry logic
        async function askForPIN() {
            const { value: pin } = await Swal.fire({
                title: 'Masukkan PIN',
                input: 'password',
                inputLabel: 'PIN (6 digit)',
                inputPlaceholder: 'Masukkan PIN Anda',
                inputAttributes: {
                    maxlength: 6,
                    autocapitalize: 'off',
                    autocorrect: 'off'
                },
                showCancelButton: true,
                confirmButtonText: 'Verifikasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#6b7280',
                inputValidator: (value) => {
                    if (!value || value.length !== 6) {
                        return 'PIN harus 6 digit';
                    }
                }
            });
            
            return pin;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            document.addEventListener('click', function(e) {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            <?php if ($role != 'siswa' || !$rekening): ?>
            initSearch('rekening_asal_search', 'results_asal', 'rekening_asal', 'clear_asal');
            <?php endif; ?>
            
            initSearch('rekening_tujuan_search', 'results_tujuan', 'rekening_tujuan', 'clear_tujuan');
            
            const amountInput = document.getElementById('jumlah');
            if (amountInput) {
                amountInput.addEventListener('input', function(e) {
                    let value = this.value.replace(/[^0-9]/g, '');
                    this.value = value ? parseInt(value).toLocaleString('id-ID') : '';
                });
            }

            // Clear only amount and description
            function clearAmountOnly() {
                document.getElementById('jumlah').value = '';
                document.getElementById('keterangan').value = '';
            }

            // Clear entire form including accounts
            function clearForm() {
                document.getElementById('jumlah').value = '';
                document.getElementById('keterangan').value = '';
                
                const tujuanSearch = document.getElementById('rekening_tujuan_search');
                const tujuanHidden = document.getElementById('rekening_tujuan');
                const clearTujuan = document.getElementById('clear_tujuan');
                if (tujuanSearch) {
                    tujuanSearch.value = '';
                    tujuanHidden.value = '';
                    clearTujuan.classList.remove('show');
                }
                
                <?php if ($role != 'siswa' || !$rekening): ?>
                const asalSearch = document.getElementById('rekening_asal_search');
                const asalHidden = document.getElementById('rekening_asal');
                const clearAsal = document.getElementById('clear_asal');
                if (asalSearch) {
                    asalSearch.value = '';
                    asalHidden.value = '';
                    clearAsal.classList.remove('show');
                }
                <?php endif; ?>
            }

            document.getElementById('transferBtn').addEventListener('click', async function() {
                const rekeningAsal = document.querySelector('input[name="rekening_asal"]').value;
                const rekeningTujuan = document.querySelector('input[name="rekening_tujuan"]').value;
                const jumlah = document.getElementById('jumlah').value.replace(/[^0-9]/g, '');
                const keterangan = document.getElementById('keterangan').value.trim();
                
                if (!rekeningAsal || !rekeningTujuan || !jumlah) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Mohon lengkapi semua field yang wajib diisi',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }
                
                if (rekeningAsal === rekeningTujuan) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Rekening tujuan tidak boleh sama dengan rekening asal',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }
                
                if (parseInt(jumlah) < 1000) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Jumlah transfer minimal Rp 1.000',
                        confirmButtonColor: '#1e3a8a'
                    });
                    return;
                }

                Swal.fire({ 
                    title: 'Memeriksa saldo...', 
                    allowOutsideClick: false, 
                    didOpen: () => { Swal.showLoading(); } 
                });
                
                try {
                    const balanceFormData = new FormData();
                    balanceFormData.append('ajax_action', 'check_balance');
                    balanceFormData.append('rekening_asal', rekeningAsal);
                    balanceFormData.append('jumlah', jumlah);
                    
                    const balanceResponse = await fetch('proses_transfer.php', { method: 'POST', body: balanceFormData });
                    const balanceResult = await balanceResponse.json();
                    
                    if (!balanceResult.success) {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Saldo Tidak Mencukupi', 
                            text: balanceResult.message,
                            confirmButtonColor: '#1e3a8a'
                        });
                        // Only clear amount, keep accounts
                        clearAmountOnly();
                        return;
                    }
                    
                    // Close loading and ask for PIN
                    Swal.close();
                    
                    // PIN verification loop
                    let pinVerified = false;
                    let attemptCount = 0;
                    const maxAttempts = 3;
                    
                    while (!pinVerified && attemptCount < maxAttempts) {
                        const pin = await askForPIN();
                        
                        if (!pin) {
                            // User cancelled - reset attempts to 3
                            attemptCount = 0;
                            return;
                        }
                        
                        Swal.fire({ 
                            title: 'Memvalidasi PIN...', 
                            allowOutsideClick: false, 
                            didOpen: () => { Swal.showLoading(); } 
                        });
                        
                        const pinFormData = new FormData();
                        pinFormData.append('ajax_action', 'validate_pin');
                        pinFormData.append('rekening_asal', rekeningAsal);
                        pinFormData.append('pin', pin);
                        
                        const pinResponse = await fetch('proses_transfer.php', { method: 'POST', body: pinFormData });
                        const pinResult = await pinResponse.json();
                        
                        if (pinResult.success) {
                            pinVerified = true;
                            Swal.close();
                        } else {
                            attemptCount++;
                            Swal.close();
                            
                            if (attemptCount >= maxAttempts) {
                                await Swal.fire({ 
                                    icon: 'error', 
                                    title: 'PIN Salah', 
                                    text: 'Anda telah mencapai batas maksimal percobaan. Transaksi dibatalkan.',
                                    confirmButtonColor: '#1e3a8a'
                                });
                                clearForm();
                                return;
                            } else {
                                await Swal.fire({ 
                                    icon: 'error', 
                                    title: 'PIN Salah', 
                                    html: `${pinResult.message}<br><br>Sisa percobaan: <strong>${maxAttempts - attemptCount}</strong>`,
                                    confirmButtonText: 'Coba Lagi',
                                    confirmButtonColor: '#1e3a8a'
                                });
                            }
                        }
                    }
                    
                    if (!pinVerified) {
                        return;
                    }
                    
                    Swal.fire({ 
                        title: 'Memproses transfer...', 
                        allowOutsideClick: false, 
                        didOpen: () => { Swal.showLoading(); } 
                    });
                    
                    const transferFormData = new FormData();
                    transferFormData.append('ajax_action', 'process_transfer');
                    transferFormData.append('rekening_asal', rekeningAsal);
                    transferFormData.append('rekening_tujuan', rekeningTujuan);
                    transferFormData.append('jumlah', jumlah);
                    transferFormData.append('keterangan', keterangan);
                    
                    const transferResponse = await fetch('proses_transfer.php', { method: 'POST', body: transferFormData });
                    const transferResult = await transferResponse.json();
                    
                    if (transferResult.success) {
                        await Swal.fire({
                            icon: 'success',
                            title: 'Transfer Berhasil!',
                            text: 'Transaksi Anda telah berhasil diproses',
                            confirmButtonText: 'OK',
                            confirmButtonColor: '#1e3a8a'
                        });
                        clearForm();
                    } else {
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Gagal', 
                            text: transferResult.message,
                            confirmButtonColor: '#1e3a8a'
                        });
                        clearForm();
                    }
                    
                } catch (error) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Error', 
                        text: 'Terjadi kesalahan sistem',
                        confirmButtonColor: '#1e3a8a'
                    });
                    clearForm();
                }
            });
        });
    </script>
</body>
</html>
