<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/session_validator.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Setor | MY Schobank</title>
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --secondary-dark: #2563eb;
            --accent-color: #f59e0b;
            --danger-color: #e74c3c;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f0f5ff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --button-width: 140px;
            --button-height: 44px;
            --scrollbar-track: #1e1b4b;
            --scrollbar-thumb: #3b82f6;
            --scrollbar-thumb-hover: #60a5fa;
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

        .combined-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-md);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
            display: flex;
            flex-direction: column;
            gap: 30px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        /* DESKTOP: JARAK STEP LEBIH KECIL */
        .steps-section {
            display: flex;
            justify-content: center;
            gap: 15px;
            position: relative;
            max-width: 500px;
            margin: 0 auto;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 0 0 auto;
            position: relative;
            z-index: 1;
            padding: 10px;
            transition: var(--transition);
        }

        .step-number {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: var(--text-secondary);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
        }

        .step-text {
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.2;
            transition: var(--transition);
            white-space: nowrap;
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.15);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(30, 58, 138, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(30, 58, 138, 0); }
            100% { box-shadow: 0 0 0 0 rgba(30, 58, 138, 0); }
        }

        .step-item.active .step-text {
            color: var(--primary-color);
            font-weight: 500;
        }

        .step-item.completed .step-number {
            background-color: var(--secondary-color);
            color: white;
        }

        .deposit-card, .account-details {
            border-radius: 5px;
            padding: 25px;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
            transition: var(--transition);
        }

        .account-details {
            display: none;
        }

        .account-details.visible {
            display: block;
            animation: slideIn 0.5s ease-out;
        }

        .deposit-form {
            display: flex;
            flex-direction: column;
            gap: 25px;
            align-items: center;
            justify-content: center;
        }

        .form-group {
            width: 100%;
            max-width: 400px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            text-align: center;
        }

        .currency-input {
            position: relative;
        }

        .currency-prefix {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
            font-size: clamp(0.9rem, 1.8vw, 1rem);
        }

        input[type="text"],
        input.currency {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
            text-align: center;
        }

        input.currency {
            padding-left: 40px;
        }

        input[type="text"]:focus,
        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            width: var(--button-width);
            height: var(--button-height);
            margin: 0;
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .btn-confirm:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-cancel {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .btn-cancel:hover {
            background: linear-gradient(135deg, #d32f2f 0%, var(--danger-color) 100%);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: rgba(59, 130, 246, 0.05);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .confirm-buttons {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin: 25px auto 0;
            width: 100%;
            max-width: 310px;
            box-sizing: border-box;
        }

        .section-title {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        /* Responsive Design for Tablets */
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

            .combined-container {
                padding: 15px;
                max-width: 100%;
            }

            .steps-section {
                gap: 10px;
                max-width: 100%;
                justify-content: center;
                padding: 0 10px;
            }

            .step-item {
                flex-direction: column;
                align-items: center;
                padding: 5px;
            }

            .step-number {
                width: 40px;
                height: 40px;
                margin-bottom: 8px;
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .step-text {
                font-size: clamp(0.7rem, 1.5vw, 0.8rem);
                white-space: nowrap;
            }
        }

        /* Responsive Design for Small Phones */
        @media (max-width: 480px) {
            .main-content {
                padding: 10px;
            }

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

            .steps-section {
                gap: 8px;
                padding: 0 5px;
            }

            .step-item {
                padding: 3px;
            }

            .step-number {
                width: 36px;
                height: 36px;
                margin-bottom: 6px;
                font-size: 0.85rem;
            }

            .step-text {
                font-size: 0.7rem;
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
                <h2><i class="fas fa-hand-holding-usd"></i> Setor Tunai</h2>
                <p>Layanan untuk nasabah menabung</p>
            </div>
        </div>

        <div class="combined-container">
            <div class="steps-section">
                <div class="step-item active" id="step1-indicator">
                    <div class="step-number">1</div>
                    <div class="step-text">Cek Rekening</div>
                </div>
                <div class="step-item" id="step2-indicator">
                    <div class="step-number">2</div>
                    <div class="step-text">Input Nominal</div>
                </div>
                <div class="step-item" id="step3-indicator">
                    <div class="step-number">3</div>
                    <div class="step-text">Konfirmasi</div>
                </div>
            </div>

            <div class="deposit-card" id="checkAccountStep">
                <h3 class="section-title">Masukkan Nomor Rekening</h3>
                <form id="cekRekening" class="deposit-form" novalidate>
                    <div class="form-group">
                        <label for="no_rekening">No Rekening (8 Digit):</label>
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Input nomor rekening" maxlength="8" inputmode="numeric" pattern="[0-9]*" required>
                    </div>
                    <button type="submit" id="cekButton" class="btn">
                        <span class="btn-content">Cek Rekening</span>
                    </button>
                </form>
            </div>

            <div class="account-details" id="amountDetails">
                <h3 class="section-title">Detail Rekening</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value" id="displayNoRek">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik:</div>
                    <div class="detail-value" id="displayNama">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value" id="displayJurusan">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value" id="displayKelas">-</div>
                </div>
                <form id="formSetoran" class="deposit-form">
                    <div class="form-group currency-input">
                        <label for="jumlah">Jumlah Setoran (Rp):</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="Input nominal Setor...." inputmode="numeric" pattern="[0-9]*" required>
                    </div>
                    <div class="confirm-buttons">
                        <button type="button" id="confirmAmount" class="btn btn-confirm">
                            <span class="btn-content">Lanjutkan</span>
                        </button>
                        <button type="button" id="cancelAmount" class="btn btn-cancel">
                            <span class="btn-content">Batal</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="account-details" id="confirmationDetails">
                <h3 class="section-title">Konfirmasi Setoran</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value" id="confirmNoRek">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik:</div>
                    <div class="detail-value" id="confirmNama">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jurusan:</div>
                    <div class="detail-value" id="confirmJurusan">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Kelas:</div>
                    <div class="detail-value" id="confirmKelas">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jumlah Setoran:</div>
                    <div class="detail-value" id="confirmJumlah">-</div>
                </div>
                <div class="confirm-buttons">
                    <button type="button" id="processDeposit" class="btn btn-confirm">
                        <span class="btn-content">Proses Setoran</span>
                    </button>
                    <button type="button" id="backToAmount" class="btn btn-cancel">
                        <span class="btn-content">Kembali</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Prevention scripts
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

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && (e.key === '+' || e.key === '-' || e.key === '=')) {
                e.preventDefault();
            }
        });

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

            const cekForm = document.getElementById('cekRekening');
            const setoranForm = document.getElementById('formSetoran');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const amountDetails = document.getElementById('amountDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');

            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');

            const inputNoRek = document.getElementById('no_rekening');
            const inputJumlah = document.getElementById('jumlah');

            const cekButton = document.getElementById('cekButton');
            const confirmAmount = document.getElementById('confirmAmount');
            const cancelAmount = document.getElementById('cancelAmount');
            const processDeposit = document.getElementById('processDeposit');
            const backToAmount = document.getElementById('backToAmount');

            let accountData = {};

            inputNoRek.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value.length > 8) {
                    value = value.slice(0, 8);
                }
                this.value = value;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    cekButton.click();
                }
            });

            inputJumlah.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value.length > 8) {
                    value = value.slice(0, 8);
                }
                if (value === '') {
                    this.value = '';
                    return;
                }
                this.value = formatRupiahInput(value);
            });

            inputJumlah.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmAmount.click();
                }
            });

            function formatRupiahInput(value) {
                value = parseInt(value) || 0;
                return value.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            }

            function formatRupiah(value) {
                return 'Rp ' + value.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            }

            function updateStepIndicators(activeStep) {
                step1Indicator.className = 'step-item';
                step2Indicator.className = 'step-item';
                step3Indicator.className = 'step-item';

                if (activeStep >= 1) {
                    step1Indicator.className = activeStep > 1 ? 'step-item completed' : 'step-item active';
                }
                if (activeStep >= 2) {
                    step2Indicator.className = activeStep > 2 ? 'step-item completed' : 'step-item active';
                }
                if (activeStep === 3) {
                    step3Indicator.className = 'step-item active';
                }
            }

            function showStep(step) {
                checkAccountStep.style.display = step === 1 ? 'block' : 'none';
                amountDetails.className = `account-details ${step === 2 ? 'visible' : ''}`;
                confirmationDetails.className = `account-details ${step === 3 ? 'visible' : ''}`;
                updateStepIndicators(step);
            }

            function resetForm() {
                cekForm.reset();
                setoranForm.reset();
                inputNoRek.value = '';
                inputJumlah.value = '';
                showStep(1);
                accountData = {};
            }

            cekForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const rekening = inputNoRek.value.trim();
                
                if (!rekening || rekening.length !== 8 || !/^[0-9]{8}$/.test(rekening)) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Nomor Rekening Tidak Valid',
                        text: 'Nomor rekening harus terdiri dari 8 digit angka',
                        confirmButtonColor: '#1e3a8a'
                    });
                    inputNoRek.focus();
                    return;
                }

                Swal.fire({
                    title: 'Memproses...',
                    html: 'Sedang memeriksa nomor rekening',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('proses_setor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cek_rekening&no_rekening=${encodeURIComponent(rekening)}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    setTimeout(() => {
                        Swal.close();
                        if (data.status === 'error') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal',
                                text: data.message,
                                confirmButtonColor: '#1e3a8a'
                            });
                            inputNoRek.value = '';
                            inputNoRek.focus();
                            return;
                        }
                        accountData = data;
                        document.getElementById('displayNoRek').textContent = data.no_rekening;
                        document.getElementById('displayNama').textContent = data.nama;
                        document.getElementById('displayJurusan').textContent = data.jurusan;
                        document.getElementById('displayKelas').textContent = data.kelas;
                        showStep(2);
                        inputJumlah.focus();
                    }, 3000);
                })
                .catch(error => {
                    setTimeout(() => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Kesalahan Sistem',
                            text: 'Terjadi kesalahan: ' + error.message,
                            confirmButtonColor: '#1e3a8a'
                        });
                        inputNoRek.value = '';
                        inputNoRek.focus();
                    }, 3000);
                });
            });

            confirmAmount.addEventListener('click', function() {
                let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                jumlah = parseFloat(jumlah);
                
                if (isNaN(jumlah) || jumlah < 1) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Jumlah Tidak Valid',
                        text: 'Jumlah setoran minimal Rp 1',
                        confirmButtonColor: '#1e3a8a'
                    });
                    inputJumlah.focus();
                    return;
                }
                
                if (jumlah > 99999999) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Jumlah Terlalu Besar',
                        text: 'Jumlah setoran maksimal Rp 99.999.999',
                        confirmButtonColor: '#1e3a8a'
                    });
                    inputJumlah.focus();
                    return;
                }

                accountData.jumlah = jumlah;
                document.getElementById('confirmNoRek').textContent = accountData.no_rekening;
                document.getElementById('confirmNama').textContent = accountData.nama;
                document.getElementById('confirmJurusan').textContent = accountData.jurusan;
                document.getElementById('confirmKelas').textContent = accountData.kelas;
                document.getElementById('confirmJumlah').textContent = formatRupiah(jumlah);
                showStep(3);
            });

            cancelAmount.addEventListener('click', function() {
                Swal.fire({
                    title: 'Batalkan Transaksi?',
                    text: 'Anda yakin ingin membatalkan transaksi ini?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Batalkan',
                    cancelButtonText: 'Tidak',
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#1e3a8a',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        resetForm();
                    }
                });
            });

            processDeposit.addEventListener('click', function() {
                Swal.fire({
                    title: 'Memproses Transaksi...',
                    html: 'Mohon tunggu, sedang memproses setoran',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                fetch('proses_setor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=setor_tunai&no_rekening=${encodeURIComponent(accountData.no_rekening)}&jumlah=${accountData.jumlah}&petugas_id=${encodeURIComponent(<?php echo $_SESSION['user_id']; ?>)}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    setTimeout(() => {
                        Swal.close();
                        if (data.status === 'error') {
                            Swal.fire({
                                icon: 'error',
                                title: 'Transaksi Gagal',
                                text: data.message,
                                confirmButtonColor: '#1e3a8a'
                            });
                            return;
                        }
                        if (data.email_status === 'failed') {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Transaksi Berhasil',
                                text: 'Transaksi berhasil, tetapi gagal mengirim bukti transaksi ke email',
                                confirmButtonColor: '#1e3a8a'
                            }).then(() => {
                                resetForm();
                            });
                            return;
                        }
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: data.message,
                            confirmButtonColor: '#1e3a8a',
                            timer: 2000
                        }).then(() => {
                            resetForm();
                        });
                    }, 3000);
                })
                .catch(error => {
                    setTimeout(() => {
                        Swal.close();
                        Swal.fire({
                            icon: 'error',
                            title: 'Kesalahan',
                            text: 'Gagal memproses transaksi: ' + error.message,
                            confirmButtonColor: '#1e3a8a'
                        });
                    }, 3000);
                });
            });

            backToAmount.addEventListener('click', function() {
                showStep(2);
                inputJumlah.focus();
            });
        });
    </script>
</body>
</html>
