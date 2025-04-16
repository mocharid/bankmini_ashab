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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
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

        .steps-container {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            animation: slideInSteps 0.5s ease-out;
        }

        @keyframes slideInSteps {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            padding: 0 20px;
            transition: var(--transition);
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
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .step-text {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
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
            transition: var(--transition);
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.1);
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
            animation: slideStep 0.5s ease-in-out;
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
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
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        input[type="text"],
        input.currency {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
        }

        input.currency {
            padding-left: 45px !important;
        }

        input[type="text"]:focus,
        input.currency:focus {
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

        .confirm-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
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

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
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

        .success-overlay {
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
            animation: fadeInOverlay 0.5s ease-in-out forwards;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .success-modal {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            max-width: 90%;
            width: 450px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-icon {
            font-size: clamp(4rem, 8vw, 4.5rem);
            color: var(--secondary-color);
            margin-bottom: 25px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            animation: slideUpText 0.5s ease-out 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2.2vw, 1.1rem);
            margin-bottom: 25px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confetti {
            position: absolute;
            width: 12px;
            height: 12px;
            opacity: 0.8;
            animation: confettiFall 4s ease-out forwards;
            transform-origin: center;
        }

        .confetti:nth-child(odd) {
            background: var(--accent-color);
        }

        .confetti:nth-child(even) {
            background: var(--secondary-color);
        }

        @keyframes confettiFall {
            0% { transform: translateY(-150%) rotate(0deg); opacity: 0.8; }
            50% { opacity: 1; }
            100% { transform: translateY(300%) rotate(1080deg); opacity: 0; }
        }

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .highlight-change {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            padding: 2px 5px;
            border-radius: 4px;
            display: inline-block;
            animation: pulse 2s ease-in-out 3;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(224, 233, 245, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(224, 233, 245, 0); }
            100% { box-shadow: 0 0 0 0 rgba(224, 233, 245, 0); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
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
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .step-text {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
            }

            button {
                width: 100%;
                justify-content: center;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }
            
            .confirm-buttons {
                flex-direction: column;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
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
            <h2><i class="fas fa-money-bill-wave"></i> Setor Tunai</h2>
            <p>Layanan setor tunai untuk nasabah</p>
        </div>
        
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
            <div class="deposit-card" id="checkAccountStep">
                <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
                <form id="cekRekening" class="deposit-form">
                    <div>
                        <label for="no_rekening">No Rekening:</label>
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="REK..." required autofocus>
                    </div>
                    
                    <button type="submit" id="cekButton">
                        <i class="fas fa-search"></i>
                        <span>Cek Rekening</span>
                    </button>
                </form>
            </div>

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
                    <div class="currency-input">
                        <label for="jumlah">Jumlah Setoran (Rp):</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="10000" required>
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
        // Prevent pinch-to-zoom and double-tap zoom
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
            const cekForm = document.getElementById('cekRekening');
            const depositForm = document.getElementById('formSetorTunai');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const accountDetails = document.getElementById('accountDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');
            const alertContainer = document.getElementById('alertContainer');
            
            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');
            
            const inputNoRek = document.getElementById('no_rekening');
            const inputJumlah = document.getElementById('jumlah');
            
            const displayNoRek = document.getElementById('displayNoRek');
            const displayNama = document.getElementById('displayNama');
            const displaySaldo = document.getElementById('displaySaldo');
            const cekButton = document.getElementById('cekButton');
            
            const confirmBtn = document.getElementById('confirmDeposit');
            const cancelBtn = document.getElementById('cancelDeposit');
            
            const confirmNoRek = document.getElementById('confirmNoRek');
            const confirmNama = document.getElementById('confirmNama');
            const confirmSaldo = document.getElementById('confirmSaldo');
            const confirmJumlah = document.getElementById('confirmJumlah');
            const confirmSisaSaldo = document.getElementById('confirmSisaSaldo');
            const processDepositBtn = document.getElementById('processDeposit');
            const backToFormBtn = document.getElementById('backToForm');
            
            const prefix = "REK";

            inputNoRek.value = prefix;

            inputNoRek.addEventListener('input', function(e) {
                let value = this.value;
                if (!value.startsWith(prefix)) {
                    this.value = prefix + value.replace(prefix, '');
                }
                let userInput = value.slice(prefix.length).replace(/[^0-9]/g, '');
                this.value = prefix + userInput;
            });

            inputNoRek.addEventListener('keydown', function(e) {
                let cursorPos = this.selectionStart;
                if ((e.key === 'Backspace' || e.key === 'Delete') && cursorPos <= prefix.length) {
                    e.preventDefault();
                }
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                let currentValue = this.value.slice(prefix.length);
                let newValue = prefix + (currentValue + pastedData);
                this.value = newValue;
            });

            inputNoRek.addEventListener('focus', function() {
                if (this.value === prefix) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputNoRek.addEventListener('click', function(e) {
                if (this.selectionStart < prefix.length) {
                    this.setSelectionRange(prefix.length, prefix.length);
                }
            });

            inputJumlah.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value === '') {
                    this.value = '';
                    return;
                }
                this.value = formatRupiahInput(value);
            });

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

                checkAccountStep.style.display = activeStep === 1 ? 'block' : 'none';
                accountDetails.classList.toggle('visible', activeStep === 2);
                confirmationDetails.classList.toggle('visible', activeStep === 3);
            }
            
            cekForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const rekening = inputNoRek.value.trim();
                
                if (rekening === prefix) {
                    showAlert('Silakan masukkan nomor rekening lengkap', 'error');
                    return;
                }
                
                cekButton.classList.add('btn-loading');

                try {
                    const response = await fetch('proses_setor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=cek&no_rekening=${encodeURIComponent(rekening)}`
                    });

                    const data = await response.json();
                    
                    cekButton.classList.remove('btn-loading');

                    if (data.status === 'error') {
                        showAlert(data.message, 'error');
                        return;
                    }

                    displayNoRek.textContent = data.no_rekening;
                    displayNama.textContent = data.nama;
                    displaySaldo.textContent = formatRupiah(parseFloat(data.saldo));
                    
                    checkAccountStep.style.display = 'none';
                    accountDetails.classList.add('visible');
                    
                    inputJumlah.focus();
                    updateStepIndicators(2);
                    showAlert(`Rekening atas nama ${data.nama} ditemukan`, 'success');

                } catch (error) {
                    cekButton.classList.remove('btn-loading');
                    showAlert('Terjadi kesalahan sistem', 'error');
                }
            });
            
            confirmBtn.addEventListener('click', function() {
                let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                jumlah = parseFloat(jumlah);
                
                if (!jumlah || jumlah < 1000) {
                    showAlert('Jumlah setoran minimal Rp 1.000', 'error');
                    inputJumlah.focus();
                    return;
                }
                
                const saldoSekarang = parseFloat(displaySaldo.textContent.replace(/[^\d]/g, ''));
                const saldoBaru = saldoSekarang + jumlah;
                
                confirmNoRek.textContent = displayNoRek.textContent;
                confirmNama.textContent = displayNama.textContent;
                confirmSaldo.textContent = formatRupiah(saldoSekarang);
                confirmJumlah.textContent = formatRupiah(jumlah);
                confirmSisaSaldo.textContent = formatRupiah(saldoBaru);
                
                accountDetails.classList.remove('visible');
                confirmationDetails.classList.add('visible');
                
                updateStepIndicators(3);
            });
            
            processDepositBtn.addEventListener('click', async function() {
                processDepositBtn.classList.add('btn-loading');
                
                try {
                    const rekening = confirmNoRek.textContent;
                    let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                    jumlah = parseFloat(jumlah);

                    const response = await fetch('proses_setor.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=setor&no_rekening=${encodeURIComponent(rekening)}&jumlah=${jumlah}`
                    });

                    const data = await response.json();
                    
                    processDepositBtn.classList.remove('btn-loading');

                    if (data.status === 'error') {
                        showAlert(data.message, 'error');
                        return;
                    }

                    showSuccessAnimation(data.message);
                    cekForm.reset();
                    depositForm.reset();
                    inputNoRek.value = prefix;
                    inputJumlah.value = '';
                    confirmationDetails.classList.remove('visible');
                    accountDetails.classList.remove('visible');
                    checkAccountStep.style.display = 'block';
                    updateStepIndicators(1);
                    inputNoRek.focus();
                    inputNoRek.setSelectionRange(prefix.length, prefix.length);

                } catch (error) {
                    processDepositBtn.classList.remove('btn-loading');
                    showAlert('Gagal memproses transaksi', 'error');
                }
            });
            
            backToFormBtn.addEventListener('click', function() {
                confirmationDetails.classList.remove('visible');
                accountDetails.classList.add('visible');
                updateStepIndicators(2);
                inputJumlah.focus();
            });
            
            cancelBtn.addEventListener('click', function() {
                cekForm.reset();
                depositForm.reset();
                inputNoRek.value = prefix;
                inputJumlah.value = '';
                accountDetails.classList.remove('visible');
                checkAccountStep.style.display = 'block';
                updateStepIndicators(1);
                inputNoRek.focus();
                inputNoRek.setSelectionRange(prefix.length, prefix.length);
            });
            
            function formatRupiah(amount) {
                return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
            }

            function formatRupiahInput(value) {
                value = value.replace(/^0+/, '');
                if (!value) return '';
                let number = parseInt(value);
                return new Intl.NumberFormat('id-ID').format(number);
            }
            
            function showSuccessAnimation(message) {
                const overlay = document.createElement('div');
                overlay.className = 'success-overlay';
                overlay.innerHTML = `
                    <div class="success-modal">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Setor Tunai Berhasil!</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.success-modal');
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    modal.appendChild(confetti);
                }

                setTimeout(() => {
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }
            
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
            
            inputNoRek.focus();
            inputNoRek.setSelectionRange(prefix.length, prefix.length);

            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') cekButton.click();
            });

            inputJumlah.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') confirmBtn.click();
            });
        });
    </script>
</body>
</html>