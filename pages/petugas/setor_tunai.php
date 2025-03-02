<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Check if user is logged in as teller
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Setor Tunai - SCHOBANK SYSTEM</title>
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

        /* Step indicator */
        .steps-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            padding: 0 20px;
        }

        .step-number {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: #666;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
            transition: var(--transition);
        }

        .step-text {
            font-size: 14px;
            color: #666;
            text-align: center;
            transition: var(--transition);
        }

        .step-connector {
            position: absolute;
            top: 17px;
            height: 3px;
            background-color: #e0e0e0;
            width: 100%;
            left: 50%;
            z-index: 0;
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
        }

        .step-item.active .step-text {
            color: var(--primary-color);
            font-weight: 500;
        }

        .step-item.completed .step-number {
            background-color: var(--secondary-color);
            color: white;
        }

        .step-item.completed .step-connector {
            background-color: var(--secondary-color);
        }

        .transaction-container {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .deposit-card, .account-details {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            flex: 1;
            min-width: 300px;
            max-width: 650px;
            transition: var(--transition);
        }

        .deposit-card:hover, .account-details:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .account-details {
            display: none;
        }

        .account-details.visible {
            display: block;
            animation: fadeIn 0.5s ease-in;
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

        .deposit-form {
            display: grid;
            gap: 20px;
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
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 15px;
            transition: var(--transition);
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
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 15px;
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

        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
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

        /* Loader animation for buttons */
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
            to {
                transform: rotate(360deg);
            }
        }

        /* Success animation styles */
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
            animation: fadeIn 0.5s forwards;
        }

        .success-modal {
            background-color: white;
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            max-width: 90%;
            width: 400px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(30px);
            opacity: 0;
        }

        .success-modal.animate {
            animation: slideUp 0.5s forwards;
        }

        @keyframes slideUp {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .success-icon {
            font-size: 60px;
            color: var(--secondary-color);
            margin-bottom: 20px;
            animation: scaleUp 0.5s 0.3s both;
        }

        @keyframes scaleUp {
            from {
                transform: scale(0.5);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 10px;
            font-size: 22px;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: 16px;
        }

        /* Receipt print animation */
        .receipt-animation {
            width: 150px;
            height: 30px;
            background-color: white;
            margin: 0 auto 20px;
            animation: printReceipt 2s ease-out forwards;
            transform-origin: center top;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        @keyframes printReceipt {
            0% {
                height: 0;
            }
            70% {
                height: 150px;
            }
            100% {
                height: 150px;
            }
        }

        /* Add for Button animation */
        .alert.hide {
            animation: fadeOut 0.5s forwards;
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* For highlighting changes */
        .highlight-change {
            background-color: #fef9c3;
            color: #854d0e;
            padding: 2px 5px;
            border-radius: 4px;
            display: inline-block;
            animation: pulse 2s ease-in-out 3;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(254, 249, 195, 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(254, 249, 195, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(254, 249, 195, 0);
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

            .deposit-card, .account-details {
                padding: 20px;
            }
            
            .steps-container {
                flex-direction: column;
                align-items: center;
                margin-bottom: 20px;
            }
            
            .step-item {
                flex-direction: row;
                width: 100%;
                margin-bottom: 10px;
            }
            
            .step-connector {
                display: none;
            }
            
            .step-number {
                margin-bottom: 0;
                margin-right: 10px;
            }

            button {
                width: 100%;
                justify-content: center;
            }
            
            .confirm-buttons {
                flex-direction: column;
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
            <h2><i class="fas fa-money-bill-wave"></i> Setor Tunai</h2>
        </div>
        
        <!-- Step indicators -->
        <div class="steps-container">
            <div class="step-item active" id="step1-indicator">
                <div class="step-number">1</div>
                <div class="step-text">Cek Rekening</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item" id="step2-indicator">
                <div class="step-number">2</div>
                <div class="step-text">Input Setoran</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item" id="step3-indicator">
                <div class="step-number">3</div>
                <div class="step-text">Konfirmasi</div>
            </div>
        </div>

        <div class="transaction-container">
            <!-- Step 1: Check Account Form -->
            <div class="deposit-card" id="checkAccountStep">
                <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
                <form id="cekRekening" class="deposit-form">
                    <div>
                        <label for="no_rekening">No Rekening:</label>
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="Masukkan nomor rekening" required autofocus>
                    </div>
                    
                    <button type="submit" id="cekButton">
                        <i class="fas fa-search"></i>
                        <span>Cek Rekening</span>
                    </button>
                </form>
            </div>

            <!-- Step 2: Account Details and Input Amount -->
            <div class="account-details" id="accountDetails">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value" id="displayNoRek">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik:</div>
                    <div class="detail-value" id="displayNama">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Saldo Sekarang:</div>
                    <div class="detail-value" id="displaySaldo">-</div>
                </div>
                
                <form id="formSetorTunai" class="deposit-form" style="margin-top: 20px;">
                    <div>
                        <label for="jumlah">Jumlah Setoran (Rp):</label>
                        <input type="number" id="jumlah" name="jumlah" placeholder="Masukkan jumlah setoran" min="1000" required>
                    </div>
                    
                    <div class="confirm-buttons">
                        <button type="button" id="confirmDeposit" class="btn-confirm">
                            <i class="fas fa-check-circle"></i>
                            <span>Konfirmasi Setoran</span>
                        </button>
                        
                        <button type="button" id="cancelDeposit" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Step 3: Confirmation View -->
            <div class="account-details" id="confirmationDetails">
                <h3 class="section-title"><i class="fas fa-receipt"></i> Konfirmasi Setoran</h3>
                
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening:</div>
                    <div class="detail-value" id="confirmNoRek">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik:</div>
                    <div class="detail-value" id="confirmNama">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Saldo Sekarang:</div>
                    <div class="detail-value" id="confirmSaldo">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Jumlah Setoran:</div>
                    <div class="detail-value" id="confirmJumlah">-</div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Saldo Setelah Setor:</div>
                    <div class="detail-value highlight-change" id="confirmSisaSaldo">-</div>
                </div>
                
                <div class="confirm-buttons">
                    <button type="button" id="processDeposit" class="btn-confirm">
                        <i class="fas fa-check-circle"></i>
                        <span>Proses Setoran</span>
                    </button>
                    
                    <button type="button" id="backToForm" class="btn-cancel">
                        <i class="fas fa-arrow-left"></i>
                        <span>Kembali</span>
                    </button>
                </div>
            </div>
        </div>

        <div id="alertContainer"></div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    const cekForm = document.getElementById('cekRekening');
    const depositForm = document.getElementById('formSetorTunai');
    const checkAccountStep = document.getElementById('checkAccountStep');
    const accountDetails = document.getElementById('accountDetails');
    const confirmationDetails = document.getElementById('confirmationDetails');
    const alertContainer = document.getElementById('alertContainer');
    
    // Step indicators
    const step1Indicator = document.getElementById('step1-indicator');
    const step2Indicator = document.getElementById('step2-indicator');
    const step3Indicator = document.getElementById('step3-indicator');
    
    const inputNoRek = document.getElementById('no_rekening');
    const inputJumlah = document.getElementById('jumlah');
    
    // Step 1 - Account checking elements
    const displayNoRek = document.getElementById('displayNoRek');
    const displayNama = document.getElementById('displayNama');
    const displaySaldo = document.getElementById('displaySaldo');
    const cekButton = document.getElementById('cekButton');
    
    // Step 2 - Confirmation elements
    const confirmBtn = document.getElementById('confirmDeposit');
    const cancelBtn = document.getElementById('cancelDeposit');
    
    // Step 3 - Final confirmation elements
    const confirmNoRek = document.getElementById('confirmNoRek');
    const confirmNama = document.getElementById('confirmNama');
    const confirmSaldo = document.getElementById('confirmSaldo');
    const confirmJumlah = document.getElementById('confirmJumlah');
    const confirmSisaSaldo = document.getElementById('confirmSisaSaldo');
    const processDepositBtn = document.getElementById('processDeposit');
    const backToFormBtn = document.getElementById('backToForm');
    
    // Function to update step indicators
    function updateStepIndicators(activeStep) {
        // Reset all steps
        step1Indicator.className = 'step-item';
        step2Indicator.className = 'step-item';
        step3Indicator.className = 'step-item';
        
        // Mark completed steps
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
    
    // Step 1: Handle account check
    cekForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const rekening = inputNoRek.value.trim();
        
        if (!rekening) {
            showAlert('Silakan masukkan nomor rekening', 'error');
            return;
        }
        
        // Add loading animation to button
        cekButton.classList.add('btn-loading');

        try {
            const response = await fetch('proses_setor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=cek&no_rekening=${rekening}`
            });

            const data = await response.json();
            
            // Remove loading animation
            cekButton.classList.remove('btn-loading');

            if (data.status === 'error') {
                showAlert(data.message, 'error');
                return;
            }

            // Display account details
            displayNoRek.textContent = data.no_rekening;
            displayNama.textContent = data.nama;
            displaySaldo.textContent = formatRupiah(parseFloat(data.saldo));
            
            // Hide Step 1, show Step 2
            checkAccountStep.style.display = 'none';
            accountDetails.classList.add('visible');
            
            // Focus on amount input
            inputJumlah.focus();
            
            // Update step indicators
            updateStepIndicators(2);
            
            // Show success notification
            showAlert(`Rekening atas nama ${data.nama} ditemukan`, 'success');

        } catch (error) {
            // Remove loading animation
            cekButton.classList.remove('btn-loading');
            showAlert('Terjadi kesalahan sistem', 'error');
        }
    });
    
    // Step 2: Handle deposit amount input and confirmation
    confirmBtn.addEventListener('click', function() {
        const jumlah = parseFloat(inputJumlah.value);
        
        if (!jumlah || jumlah < 1000) {
            showAlert('Jumlah setoran minimal Rp 1.000', 'error');
            inputJumlah.focus();
            return;
        }
        
        // Show confirmation page with all details
        const saldoSekarang = parseFloat(displaySaldo.textContent.replace(/[^\d]/g, ''));
        const saldoBaru = saldoSekarang + jumlah;
        
        confirmNoRek.textContent = displayNoRek.textContent;
        confirmNama.textContent = displayNama.textContent;
        confirmSaldo.textContent = formatRupiah(saldoSekarang);
        confirmJumlah.textContent = formatRupiah(jumlah);
        confirmSisaSaldo.textContent = formatRupiah(saldoBaru);
        
        // Hide Step 2, show Step 3
        accountDetails.classList.remove('visible');
        confirmationDetails.classList.add('visible');
        
        // Update step indicators
        updateStepIndicators(3);
    });
    
    // Step 3: Process deposit
    processDepositBtn.addEventListener('click', async function() {
        // Add loading animation
        processDepositBtn.classList.add('btn-loading');
        
        try {
            const rekening = inputNoRek.value.trim();
            const jumlah = parseFloat(inputJumlah.value);

            const response = await fetch('proses_setor.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=setor&no_rekening=${rekening}&jumlah=${jumlah}`
            });

            const data = await response.json();
            
            // Remove loading animation
            processDepositBtn.classList.remove('btn-loading');

            if (data.status === 'error') {
                showAlert(data.message, 'error');
                return;
            }

            // Show success message with thank you message and receipt animation
            showSuccessAnimation(data.message);
            
            // Reset all forms and hide details
            cekForm.reset();
            depositForm.reset();
            confirmationDetails.classList.remove('visible');
            accountDetails.classList.remove('visible');
            checkAccountStep.style.display = 'block';
            
            // Reset step indicators
            updateStepIndicators(1);

        } catch (error) {
            // Remove loading animation
            processDepositBtn.classList.remove('btn-loading');
            showAlert('Gagal memproses transaksi', 'error');
        }
    });
    
    // Handle going back from confirmation to deposit form
    backToFormBtn.addEventListener('click', function() {
        confirmationDetails.classList.remove('visible');
        accountDetails.classList.add('visible');
        
        // Update step indicators
        updateStepIndicators(2);
    });
    
    // Handle cancel
    cancelBtn.addEventListener('click', function() {
        cekForm.reset();
        depositForm.reset();
        accountDetails.classList.remove('visible');
        checkAccountStep.style.display = 'block';
        
        // Reset step indicators
        updateStepIndicators(1);
        
        // Focus on account number input
        inputNoRek.focus();
    });
    
    // Format currency
    function formatRupiah(amount) {
        return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
    }
    
    // Function to show success animation with thank you message and receipt animation
    function showSuccessAnimation(message) {
        // Create overlay for the success animation
        const overlay = document.createElement('div');
        overlay.className = 'success-overlay';
        
        overlay.innerHTML = `
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h3>Transaksi Berhasil!</h3>
                <p>${message}</p>
                <button type="button" class="btn-confirm" style="margin: 20px auto 0;" id="closeSuccessModalBtn">
                    <i class="fas fa-receipt"></i>
                    <span>Selesai</span>
                </button>
            </div>
        `;
        
        document.body.appendChild(overlay);
        
        // Add animation to the modal
        setTimeout(() => {
            const modal = overlay.querySelector('.success-modal');
            modal.classList.add('animate');
        }, 100);

        // Add event listener to the "Selesai" button
        const closeButton = overlay.querySelector('#closeSuccessModalBtn');
        closeButton.addEventListener('click', closeSuccessModal);
    }
    
    // Function to close success modal
    function closeSuccessModal() {
        const overlay = document.querySelector('.success-overlay');
        if (overlay) {
            overlay.style.animation = 'fadeOut 0.5s forwards';
            setTimeout(() => {
                overlay.remove();
            }, 500);
        }
    }
    
    // Show alert message
    function showAlert(message, type) {
        // Remove any existing alerts
        const existingAlerts = document.querySelectorAll('.alert');
        existingAlerts.forEach(alert => {
            alert.classList.add('hide');
            setTimeout(() => alert.remove(), 500);
        });
        
        // Create new alert
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        
        // Define icon based on type
        let icon = 'info-circle';
        if (type === 'success') icon = 'check-circle';
        if (type === 'error') icon = 'exclamation-circle';
        
        alertDiv.innerHTML = `
            <i class="fas fa-${icon}"></i>
            <span>${message}</span>
        `;
        
        alertContainer.appendChild(alertDiv);
        
        // Auto-remove alert after 5 seconds
        setTimeout(() => {
            alertDiv.classList.add('hide');
            setTimeout(() => alertDiv.remove(), 500);
        }, 5000);
    }
    
    // Set focus on account number field on page load
    inputNoRek.focus();
});
    </script>
</body>
</html>