<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Cek role petugas
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tarik Tunai - SCHOBANK SYSTEM</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
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

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
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
            transition: var(--transition);
        }

        .nav-btn:hover {
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
            margin-bottom: 10px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
        }

        .transaction-container {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .transaction-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            min-width: 300px;
            max-width: 450px;
            width: 100%;
            transition: var(--transition);
        }

        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .step-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            width: 33.33%;
        }

        .step-number {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #757575;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            z-index: 2;
        }

        .step-line {
            position: absolute;
            top: 15px;
            width: 100%;
            height: 2px;
            background-color: #e0e0e0;
            z-index: 1;
        }

        .step:first-child .step-line {
            left: 50%;
            width: 50%;
        }

        .step:last-child .step-line {
            width: 50%;
            right: 50%;
        }

        .step.active .step-number {
            background-color: var(--primary-color);
            color: white;
        }

        .step.completed .step-number {
            background-color: var(--secondary-color);
            color: white;
        }

        .step.active .step-line, .step.completed .step-line {
            background-color: var(--primary-color);
        }

        .step-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-align: center;
        }

        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step.completed .step-label {
            color: var(--secondary-color);
            font-weight: 600;
        }

        .input-form {
            display: none;
            animation: fadeIn 0.4s ease-in;
        }

        .input-form.active {
            display: block;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .input-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"],
        input[type="number"] {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            letter-spacing: 0.5px;
        }

        input[type="text"]:focus,
        input[type="number"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        button:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn-back {
            background-color: #7b7b7b;
            margin-right: 10px;
        }

        .btn-back:hover {
            background-color: #5a5a5a;
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: #3d8b40;
        }

        .btn-cancel {
            background-color: var(--danger-color);
        }

        .btn-cancel:hover {
            background-color: #d32f2f;
        }

        .btn-actions {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .detail-row {
            display: flex;
            border-bottom: 1px solid #eee;
            padding: 12px 0;
        }

        .detail-row:last-child {
            border-bottom: none;
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
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-top: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .alert-info {
            background-color: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 5px solid #bbf7d0;
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 5px solid #fecaca;
        }

        /* Button loading animation */
        .btn-loading {
            position: relative;
        }

        .btn-loading .btn-text {
            visibility: hidden;
        }

        .btn-loading::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            z-index: 2;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Ripple effect */
        .ripple {
            position: relative;
            overflow: hidden;
        }

        .ripple:after {
            content: "";
            display: block;
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, #fff 10%, transparent 10.01%);
            background-repeat: no-repeat;
            background-position: 50%;
            transform: scale(10, 10);
            opacity: 0;
            transition: transform .3s, opacity 0.8s;
        }

        .ripple:active:after {
            transform: scale(0, 0);
            opacity: 0.3;
            transition: 0s;
        }

        /* Success animation */
        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.5s;
        }

        .success-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .success-modal {
            background-color: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            max-width: 90%;
            width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(30px);
            opacity: 0;
            transition: all 0.5s;
        }

        .success-overlay.show .success-modal {
            transform: translateY(0);
            opacity: 1;
        }

        .success-icon {
            position: relative;
            width: 80px;
            height: 80px;
            margin: 0 auto 25px;
        }

        .success-icon .circle {
            position: absolute;
            width: 80px;
            height: 80px;
            background: #dcfce7;
            border-radius: 50%;
            top: 0;
            left: 0;
            transform: scale(0);
            animation: circle-anim 0.5s 0.2s forwards;
        }

        @keyframes circle-anim {
            to {
                transform: scale(1);
            }
        }

        .success-icon .checkmark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: var(--secondary-color);
            font-size: 40px;
            opacity: 0;
            animation: check-anim 0.5s 0.6s forwards;
        }

        @keyframes check-anim {
            to {
                opacity: 1;
            }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: 24px;
            transform: translateY(20px);
            opacity: 0;
            animation: text-anim 0.5s 0.8s forwards;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: 16px;
            transform: translateY(20px);
            opacity: 0;
            animation: text-anim 0.5s 1s forwards;
        }

        @keyframes text-anim {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Shimmer loading effect for account details */
        .shimmer-loading {
            background: #f6f7f8;
            background-image: linear-gradient(
                to right,
                #f6f7f8 0%,
                #edeef1 20%,
                #f6f7f8 40%,
                #f6f7f8 100%
            );
            background-repeat: no-repeat;
            background-size: 800px 104px;
            display: inline-block;
            position: relative;
            animation-duration: 1.5s;
            animation-fill-mode: forwards;
            animation-iteration-count: infinite;
            animation-name: shimmer;
            animation-timing-function: linear;
            border-radius: 4px;
        }

        .shimmer-line {
            height: 16px;
            margin-bottom: 8px;
            width: 100%;
        }

        .shimmer-line.short {
            width: 60%;
        }

        @keyframes shimmer {
            0% {
                background-position: -468px 0;
            }
            100% {
                background-position: 468px 0;
            }
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

            .transaction-card {
                padding: 20px;
            }

            .btn-actions {
                flex-direction: column;
            }

            .btn-back {
                margin-right: 0;
                margin-bottom: 10px;
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
            <h2><i class="fas fa-money-bill-wave"></i> Tarik Tunai</h2>
        </div>

        <div class="transaction-container">
            <div class="transaction-card">
                <!-- Step Progress -->
                <div class="step-container">
                    <div class="step active" id="step1">
                        <div class="step-number">1</div>
                        <div class="step-line"></div>
                        <div class="step-label">No. Rekening</div>
                    </div>
                    <div class="step" id="step2">
                        <div class="step-number">2</div>
                        <div class="step-line"></div>
                        <div class="step-label">Nominal</div>
                    </div>
                    <div class="step" id="step3">
                        <div class="step-number">3</div>
                        <div class="step-line"></div>
                        <div class="step-label">Konfirmasi</div>
                    </div>
                </div>

                <!-- Form 1: No Rekening -->
                <div class="input-form active" id="form1">
                    <h3 style="margin-bottom: 20px; color: var(--primary-dark);">Masukkan Rekening Nasabah</h3>
                    <div class="input-group">
                        <label for="no_rekening">Nomor Rekening:</label>
                        <input type="text" id="no_rekening" placeholder="Masukkan nomor rekening nasabah" required>
                    </div>
                    <button type="button" id="btnNextToAmount" class="ripple">
                        <span class="btn-text">Lanjutkan <i class="fas fa-arrow-right"></i></span>
                    </button>
                </div>

                <!-- Form 2: Amount -->
                <div class="input-form" id="form2">
                    <h3 style="margin-bottom: 20px; color: var(--primary-dark);">Masukkan Jumlah Penarikan</h3>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nomor Rekening:</div>
                        <div class="detail-value" id="summary-norek">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value" id="summary-nama">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Saldo Saat Ini:</div>
                        <div class="detail-value" id="summary-saldo">-</div>
                    </div>
                    
                    <div class="input-group" style="margin-top: 20px;">
                        <label for="jumlah_penarikan">Jumlah Penarikan (Rp):</label>
                        <input type="number" id="jumlah_penarikan" placeholder="Masukkan jumlah penarikan" min="10000" required>
                    </div>
                    
                    <div class="btn-actions">
                        <button type="button" id="btnBackToRekening" class="btn-back ripple">
                            <span class="btn-text"><i class="fas fa-arrow-left"></i> Kembali</span>
                        </button>
                        <button type="button" id="btnNextToConfirm" class="ripple">
                            <span class="btn-text">Cek & Konfirmasi <i class="fas fa-arrow-right"></i></span>
                        </button>
                    </div>
                </div>

                <!-- Form 3: Confirmation -->
                <div class="input-form" id="form3">
                    <h3 style="margin-bottom: 20px; color: var(--primary-dark);">Konfirmasi Penarikan</h3>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nomor Rekening:</div>
                        <div class="detail-value" id="confirm-norek">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value" id="confirm-nama">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Saldo Awal:</div>
                        <div class="detail-value" id="confirm-saldo">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Jumlah Penarikan:</div>
                        <div class="detail-value" id="confirm-jumlah" style="color: var(--danger-color);">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Saldo Akhir:</div>
                        <div class="detail-value" id="confirm-sisa" style="font-weight: 700;">-</div>
                    </div>
                    
                    <div class="btn-actions">
                        <button type="button" id="btnBackToAmount" class="btn-back ripple">
                            <span class="btn-text"><i class="fas fa-arrow-left"></i> Kembali</span>
                        </button>
                        <button type="button" id="btnConfirmWithdrawal" class="btn-confirm ripple">
                            <span class="btn-text"><i class="fas fa-check-circle"></i> Proses Penarikan</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="alertContainer"></div>
    </div>

    <!-- Success Animation Overlay -->
    <div class="success-overlay" id="successOverlay">
        <div class="success-modal">
            <div class="success-icon">
                <div class="circle"></div>
                <i class="fas fa-check checkmark"></i>
            </div>
            <h3>Transaksi Berhasil!</h3>
            <p id="successMessage">Penarikan tunai telah berhasil diproses.</p>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const form1 = document.getElementById('form1');
    const form2 = document.getElementById('form2');
    const form3 = document.getElementById('form3');
    
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    
    const inputNoRek = document.getElementById('no_rekening');
    const inputJumlah = document.getElementById('jumlah_penarikan');
    
    const btnNextToAmount = document.getElementById('btnNextToAmount');
    const btnBackToRekening = document.getElementById('btnBackToRekening');
    const btnNextToConfirm = document.getElementById('btnNextToConfirm');
    const btnBackToAmount = document.getElementById('btnBackToAmount');
    const btnConfirmWithdrawal = document.getElementById('btnConfirmWithdrawal');
    
    const summaryNoRek = document.getElementById('summary-norek');
    const summaryNama = document.getElementById('summary-nama');
    const summarySaldo = document.getElementById('summary-saldo');
    
    const confirmNoRek = document.getElementById('confirm-norek');
    const confirmNama = document.getElementById('confirm-nama');
    const confirmSaldo = document.getElementById('confirm-saldo');
    const confirmJumlah = document.getElementById('confirm-jumlah');
    const confirmSisa = document.getElementById('confirm-sisa');
    
    const alertContainer = document.getElementById('alertContainer');
    const successOverlay = document.getElementById('successOverlay');
    const successMessage = document.getElementById('successMessage');
    
    // Store data across steps
    let accountData = {
        noRekening: '',
        nama: '',
        saldo: 0,
        jumlah: 0,
        rekening_id: 0,
        user_id: 0
    };
    
    // Add ripple effect to all buttons
    document.querySelectorAll('.ripple').forEach(button => {
        button.addEventListener('click', function(e) {
            const x = e.clientX - e.target.getBoundingClientRect().left;
            const y = e.clientY - e.target.getBoundingClientRect().top;
            
            const ripple = document.createElement('span');
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });
    });
    
    // Step 1: Check No Rekening - DB CONNECTION VERSION
    if (btnNextToAmount) {
        btnNextToAmount.addEventListener('click', function() {
            const rekening = inputNoRek.value.trim();
            
            if (!rekening) {
                showAlert('Silakan masukkan nomor rekening nasabah', 'error');
                return;
            }
            
            // Start loading animation
            btnNextToAmount.classList.add('btn-loading');
            
            // Make AJAX request to check account
            fetch('proses_tarik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=cek_rekening&no_rekening=${rekening}`
            })
            .then(response => response.json())
            .then(data => {
                // Remove loading animation
                btnNextToAmount.classList.remove('btn-loading');
                
                if (data.status === 'error') {
                    showAlert(data.message, 'error');
                    return;
                }
                
                // Store account data
                accountData.noRekening = data.no_rekening;
                accountData.nama = data.nama;
                accountData.saldo = parseFloat(data.saldo);
                accountData.rekening_id = data.rekening_id;
                accountData.user_id = data.user_id;
                
                // Display in summary
                summaryNoRek.textContent = data.no_rekening;
                summaryNama.textContent = data.nama;
                summarySaldo.textContent = formatRupiah(data.saldo);
                
                // Move to step 2
                step1.classList.remove('active');
                step1.classList.add('completed');
                step2.classList.add('active');
                
                form1.classList.remove('active');
                form2.classList.add('active');
            })
            .catch(error => {
                btnNextToAmount.classList.remove('btn-loading');
                showAlert('Terjadi kesalahan saat memverifikasi rekening', 'error');
                console.error(error);
            });
        });
    } else {
        console.error("Button 'btnNextToAmount' not found!");
    }
    
    // Back to Step 1
    if (btnBackToRekening) {
        btnBackToRekening.addEventListener('click', function() {
            step2.classList.remove('active');
            step1.classList.remove('completed');
            step1.classList.add('active');
            
            form2.classList.remove('active');
            form1.classList.add('active');
        });
    }
    
    // Step 2: Verify Amount
    if (btnNextToConfirm) {
    btnNextToConfirm.addEventListener('click', function() {
        const jumlah = parseFloat(inputJumlah.value);
        
        if (!jumlah || isNaN(jumlah)) {
            showAlert('Silakan masukkan jumlah penarikan', 'error');
            return;
        }
        
        if (jumlah < 10000) {
            showAlert('Jumlah penarikan minimal Rp 10.000', 'error');
            return;
        }
        
        if (jumlah > accountData.saldo) {
            showAlert('Saldo tidak mencukupi untuk melakukan penarikan sebesar ' + formatRupiah(jumlah), 'error');
            return;
        }
            
            // Start loading animation
            btnNextToConfirm.classList.add('btn-loading');
            
            // Update account data
            accountData.jumlah = jumlah;
            
            // Display in confirmation
            confirmNoRek.textContent = accountData.noRekening;
            confirmNama.textContent = accountData.nama;
            confirmSaldo.textContent = formatRupiah(accountData.saldo);
            confirmJumlah.textContent = formatRupiah(accountData.jumlah);
            confirmSisa.textContent = formatRupiah(accountData.saldo - accountData.jumlah);
            
            // Remove loading animation
            btnNextToConfirm.classList.remove('btn-loading');
            
            // Move to step 3
            step2.classList.remove('active');
            step2.classList.add('completed');
            step3.classList.add('active');
            
            form2.classList.remove('active');
            form3.classList.add('active');
        });
    }
    
    // Back to Step 2
    if (btnBackToAmount) {
        btnBackToAmount.addEventListener('click', function() {
            step3.classList.remove('active');
            step2.classList.remove('completed');
            step2.classList.add('active');
            
            form3.classList.remove('active');
            form2.classList.add('active');
        });
    }
    
    // Step 3: Process Withdrawal
    if (btnConfirmWithdrawal) {
        btnConfirmWithdrawal.addEventListener('click', function() {
            // Start loading animation
            btnConfirmWithdrawal.classList.add('btn-loading');
            
            fetch('proses_tarik.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=tarik_tunai&no_rekening=${accountData.noRekening}&jumlah=${accountData.jumlah}`
            })
            .then(response => response.json())
            .then(data => {
                // Remove loading animation
                btnConfirmWithdrawal.classList.remove('btn-loading');
                
                if (data.status === 'error') {
                    showAlert(data.message, 'error');
                    return;
                }
                
                // Show success animation
                successMessage.textContent = `Penarikan tunai sebesar ${formatRupiah(accountData.jumlah)} telah berhasil diproses.`;
                successOverlay.classList.add('show');
                
                // Redirect after 3 seconds
                setTimeout(() => {
                    window.location.href = 'dashboard.php';
                }, 3000);
            })
            .catch(error => {
                btnConfirmWithdrawal.classList.remove('btn-loading');
                showAlert('Terjadi kesalahan saat memproses penarikan', 'error');
                console.error(error);
            });
        });
    }
    
    // Helper function to format currency
    function formatRupiah(number) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            minimumFractionDigits: 0
        }).format(number);
    }
    
    // Function to show alerts
    function showAlert(message, type) {
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        
        let icon = '';
        if (type === 'error') icon = '<i class="fas fa-exclamation-circle"></i>';
        else if (type === 'success') icon = '<i class="fas fa-check-circle"></i>';
        else if (type === 'info') icon = '<i class="fas fa-info-circle"></i>';
        
        alert.innerHTML = `${icon} ${message}`;
        
        alertContainer.appendChild(alert);
        
        // Remove alert after 5 seconds
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-20px)';
            setTimeout(() => {
                alertContainer.removeChild(alert);
            }, 300);
        }, 5000);
    }
    
    // Enter key functionality
    if (inputNoRek) {
        inputNoRek.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                btnNextToAmount.click();
            }
        });
    }
    
    if (inputJumlah) {
        inputJumlah.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                btnNextToConfirm.click();
            }
        });
    }
    
    // Close success overlay on click
    if (successOverlay) {
        successOverlay.addEventListener('click', function() {
            successOverlay.classList.remove('show');
        });
        
        // Prevent click propagation from modal to overlay
        const successModal = document.querySelector('.success-modal');
        if (successModal) {
            successModal.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
    }
});
    </script>
</body>
</html>