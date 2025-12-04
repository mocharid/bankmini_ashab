<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}
$username = $_SESSION['username'] ?? 'Admin';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tarik | MY Schobank</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
            touch-action: pan-y;
        }

        html, body {
            width: 100%;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-text-size-adjust: 100%;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
        }

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
            font-size: 1.6rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            font-size: 0.9rem;
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

        .combined-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-out;
            display: flex;
            flex-direction: column;
            gap: 30px;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        .steps-section {
            display: flex;
            justify-content: center;
            gap: 80px;
            position: relative;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: none;
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

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
            display: block;
            width: 100%;
        }

        .single-rek-input, .single-pin-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            text-align: center;
            font-family: 'Courier New', monospace;
            letter-spacing: 2px;
            -webkit-appearance: none;
        }

        .single-rek-input:focus, .single-pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .single-rek-input[aria-invalid="true"], .single-pin-input[aria-invalid="true"] {
            border-color: var(--danger-color);
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

        input.currency {
            width: 100%;
            padding: 12px 40px;
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

        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        input[aria-invalid="true"] {
            border-color: var(--danger-color);
        }

        .error-message {
            color: var(--danger-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
            text-align: center;
        }

        .error-message.show {
            display: block;
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
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:focus {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
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
            background: #f0f0f0;
            color: var(--text-secondary);
        }

        .btn-cancel:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }

        .btn.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn.loading .btn-content {
            visibility: hidden;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 768px) {
            .main-content { 
                margin-left: 0; 
                padding: 15px; 
                max-width: 100%; 
            }

            body.sidebar-active .main-content { 
                opacity: 0.3; 
                pointer-events: none; 
            }

            .menu-toggle { 
                display: block; 
            }

            .welcome-banner { 
                padding: 20px; 
                border-radius: 5px;
                align-items: center;
            }

            .welcome-banner h2 { 
                font-size: 1.4rem; 
            }

            .welcome-banner p { 
                font-size: 0.85rem; 
            }

            .combined-container { 
                padding: 15px; 
                border-radius: 5px;
                max-width: 100%; 
            }

            .steps-section { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 15px; 
            }

            .step-item { 
                flex-direction: row; 
                align-items: center; 
                width: 100%; 
                margin-bottom: 16px; 
                padding: 5px 0; 
            }

            .step-number { 
                width: 36px; 
                height: 36px; 
                margin-right: 10px; 
                margin-bottom: 0; 
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                border-radius: 50%;
            }

            .step-text { 
                font-size: clamp(0.75rem, 1.5vw, 0.85rem); 
                text-align: left; 
                max-width: none; 
                margin-bottom: 0; 
            }

            .deposit-card, .account-details { 
                padding: 15px; 
                border-radius: 5px;
                max-width: 100%; 
            }

            .form-group { 
                max-width: 100%; 
            }

            .btn { 
                width: var(--button-width); 
                height: var(--button-height); 
                font-size: clamp(0.8rem, 1.7vw, 0.9rem);
                border-radius: 5px;
            }

            .confirm-buttons { 
                gap: 8px; 
                max-width: 100%; 
            }
        }

        @media (max-width: 480px) {
            body { 
                font-size: clamp(0.85rem, 2vw, 0.95rem); 
            }

            .welcome-banner { 
                padding: 15px; 
                border-radius: 5px;
            }

            .welcome-banner h2 { 
                font-size: clamp(1.2rem, 2.5vw, 1.3rem); 
            }

            .welcome-banner p { 
                font-size: clamp(0.75rem, 1.8vw, 0.8rem); 
            }

            .section-title { 
                font-size: clamp(1rem, 2.5vw, 1.2rem); 
            }

            .detail-row { 
                font-size: clamp(0.85rem, 2vw, 0.95rem); 
            }

            .btn { 
                width: var(--button-width); 
                height: var(--button-height); 
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
                border-radius: 5px;
            }

            .confirm-buttons { 
                gap: 6px; 
                max-width: 100%; 
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2><i class="fas fa-minus-circle"></i> Tarik Saldo Siswa</h2>
                <p>Layanan penarikan saldo untuk siswa secara cepat dan aman</p>
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
                    <div class="step-text">Verifikasi PIN</div>
                </div>
                <div class="step-item" id="step4-indicator">
                    <div class="step-number">4</div>
                    <div class="step-text">Konfirmasi</div>
                </div>
            </div>

            <div class="transaction-container">
                <!-- Step 1: Check Account -->
                <div class="deposit-card" id="checkAccountStep">
                    <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
                    <form id="cekRekening" class="deposit-form" novalidate>
                        <div class="form-group">
                            <label for="no_rekening">No Rekening (8 Digit):</label>
                            <input type="text" id="no_rekening" class="single-rek-input" maxlength="8" inputmode="numeric" pattern="[0-9]*" placeholder="00000000" required aria-describedby="rek-error">
                            <span class="error-message" id="rek-error"></span>
                        </div>
                        <button type="submit" id="cekButton" class="btn btn-confirm">
                            <span class="btn-content"><i class="fas fa-search"></i> Cek Rekening</span>
                        </button>
                    </form>
                </div>

                <!-- Step 2: Input Amount -->
                <div class="account-details" id="amountDetails">
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
                        <div class="detail-label">Jurusan:</div>
                        <div class="detail-value" id="displayJurusan">-</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Kelas:</div>
                        <div class="detail-value" id="displayKelas">-</div>
                    </div>
                    <form id="formPenyetoran" class="deposit-form" novalidate>
                        <div class="form-group currency-input">
                            <label for="jumlah">Jumlah Penarikan (Rp):</label>
                            <span class="currency-prefix">Rp</span>
                            <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="Masukan Jumlah Penarikan" inputmode="numeric" required aria-describedby="jumlah-error">
                            <span class="error-message" id="jumlah-error"></span>
                        </div>
                        <div class="confirm-buttons">
                            <button type="button" id="confirmAmount" class="btn btn-confirm">
                                <span class="btn-content"><i class="fas fa-arrow-right"></i> Lanjutkan</span>
                            </button>
                            <button type="button" id="cancelAmount" class="btn btn-cancel">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 3: PIN Verification -->
                <div class="account-details" id="pinDetails">
                    <h3 class="section-title"><i class="fas fa-lock"></i> Verifikasi PIN</h3>
                    <div class="detail-row">
                        <div class="detail-label">Nomor Rekening:</div>
                        <div class="detail-value" id="pinNoRek">-</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Nama Pemilik:</div>
                        <div class="detail-value" id="pinNama">-</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jumlah Penarikan:</div>
                        <div class="detail-value" id="pinJumlah">-</div>
                    </div>
                    <form id="formPin" class="deposit-form" novalidate>
                        <div class="form-group">
                            <label for="pin">Masukkan PIN (6 Digit):</label>
                            <input type="password" id="pin" class="single-pin-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" placeholder="••••••" required aria-describedby="pin-error">
                            <span class="error-message" id="pin-error"></span>
                        </div>
                        <div class="confirm-buttons">
                            <button type="button" id="verifyPin" class="btn btn-confirm">
                                <span class="btn-content"><i class="fas fa-lock-open"></i> Verifikasi</span>
                            </button>
                            <button type="button" id="backToAmount" class="btn btn-cancel">
                                <span class="btn-content"><i class="fas fa-arrow-left"></i> Kembali</span>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Step 4: Confirmation -->
                <div class="account-details" id="confirmationDetails">
                    <h3 class="section-title"><i class="fas fa-receipt"></i> Konfirmasi Penarikan</h3>
                    <div class="detail-row">
                        <div class="detail-label">Nomor Rekening:</div>
                        <div class="detail-value" id="confirmNoRek">-</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Nama Pemilik:</div>
                        <div class="detail-value" id="confirmNama">-</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Jumlah Penarikan:</div>
                        <div class="detail-value" id="confirmJumlah">-</div>
                    </div>
                    <div class="confirm-buttons">
                        <button type="button" id="processDeposit" class="btn btn-confirm">
                            <span class="btn-content"><i class="fas fa-check"></i> Proses</span>
                        </button>
                        <button type="button" id="backToPin" class="btn btn-cancel">
                            <span class="btn-content"><i class="fas fa-arrow-left"></i> Kembali</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const cekForm = document.getElementById('cekRekening');
            const penyetoranForm = document.getElementById('formPenyetoran');
            const pinForm = document.getElementById('formPin');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const amountDetails = document.getElementById('amountDetails');
            const pinDetails = document.getElementById('pinDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');

            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');
            const step4Indicator = document.getElementById('step4-indicator');

            const inputRekening = document.getElementById('no_rekening');
            const rekError = document.getElementById('rek-error');
            const inputJumlah = document.getElementById('jumlah');
            const jumlahError = document.getElementById('jumlah-error');
            const inputPin = document.getElementById('pin');
            const pinError = document.getElementById('pin-error');

            const cekButton = document.getElementById('cekButton');
            const confirmAmount = document.getElementById('confirmAmount');
            const cancelAmount = document.getElementById('cancelAmount');
            const verifyPin = document.getElementById('verifyPin');
            const backToAmount = document.getElementById('backToAmount');
            const processDeposit = document.getElementById('processDeposit');
            const backToPin = document.getElementById('backToPin');
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

            let accountData = {};
            let depositAmount = 0;
            let pinAttempts = 0;
            let isPinLocked = false;
            const MAX_ATTEMPTS = 3;

            // Sidebar toggle
            if (menuToggle && sidebar && mainContent) {
                menuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });

                document.addEventListener('click', function(e) {
                    if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }

            function formatRupiahInput(value) {
                value = parseInt(value) || 0;
                return value.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            }

            function formatRupiah(value) {
                return 'Rp ' + value.toLocaleString('id-ID', { minimumFractionDigits: 0 });
            }

            function updateStepIndicators(activeStep) {
                [step1Indicator, step2Indicator, step3Indicator, step4Indicator].forEach((el, i) => {
                    el.className = 'step-item';
                    if (i + 1 < activeStep) el.classList.add('completed');
                    if (i + 1 === activeStep) el.classList.add('active');
                });
            }

            function showStep(step) {
                checkAccountStep.style.display = step === 1 ? 'block' : 'none';
                amountDetails.className = `account-details ${step === 2 ? 'visible' : ''}`;
                pinDetails.className = `account-details ${step === 3 ? 'visible' : ''}`;
                confirmationDetails.className = `account-details ${step === 4 ? 'visible' : ''}`;
                updateStepIndicators(step);

                if (step === 3 && !isPinLocked) {
                    const remaining = MAX_ATTEMPTS - pinAttempts;
                    pinError.textContent = remaining < MAX_ATTEMPTS ? `Sisa ${remaining} kesempatan.` : '';
                    pinError.classList.toggle('show', remaining < MAX_ATTEMPTS);
                }
            }

            function resetForm() {
                cekForm.reset();
                penyetoranForm.reset();
                pinForm.reset();
                inputRekening.value = '';
                inputJumlah.value = '';
                inputPin.value = '';
                rekError.classList.remove('show');
                jumlahError.classList.remove('show');
                pinError.classList.remove('show');
                inputRekening.removeAttribute('aria-invalid');
                inputJumlah.removeAttribute('aria-invalid');
                inputPin.removeAttribute('aria-invalid');
                accountData = {};
                depositAmount = 0;
                pinAttempts = 0;
                isPinLocked = false;
                document.querySelectorAll('.detail-value').forEach(el => el.textContent = '-');
                showStep(1);
                inputRekening.focus();
            }

            // === PERBAIKAN: Hapus error saat input di-focus atau diisi ===
            function clearErrorOnInput(input, errorElement) {
                input.addEventListener('focus', function() {
                    this.removeAttribute('aria-invalid');
                    errorElement.classList.remove('show');
                    errorElement.textContent = '';
                });
                input.addEventListener('input', function() {
                    this.removeAttribute('aria-invalid');
                    errorElement.classList.remove('show');
                    errorElement.textContent = '';
                });
            }

            clearErrorOnInput(inputRekening, rekError);
            clearErrorOnInput(inputJumlah, jumlahError);
            clearErrorOnInput(inputPin, pinError);

            // Input hanya angka
            inputRekening.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 8);
            });

            inputPin.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            });

            // === PERBAIKAN: Handle Enter key untuk input nominal (jumlah) ===
            inputJumlah.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    confirmAmount.click();
                }
            });

            // === PERBAIKAN: Handle Enter key untuk input PIN ===
            inputPin.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    verifyPin.click();
                }
            });

            // Step 1: Cek Rekening
            cekForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const noRek = inputRekening.value.trim();
                if (noRek.length !== 8) {
                    inputRekening.setAttribute('aria-invalid', 'true');
                    rekError.textContent = 'Nomor rekening harus 8 digit.';
                    rekError.classList.add('show');
                    return;
                }
                
                Swal.fire({
                    title: 'Mengecek rekening...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });
                
                setTimeout(() => {
                    fetch('proses_tarik_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=cek_rekening&no_rekening=${encodeURIComponent(noRek)}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        Swal.close();
                        if (data.status === 'success') {
                            accountData = data;
                            pinAttempts = 0;
                            isPinLocked = false;
                            document.getElementById('displayNoRek').textContent = noRek;
                            document.getElementById('displayNama').textContent = accountData.nama;
                            document.getElementById('displayJurusan').textContent = accountData.jurusan;
                            document.getElementById('displayKelas').textContent = accountData.kelas;
                            showStep(2);
                            inputJumlah.focus();
                        } else {
                            inputRekening.value = '';
                            inputRekening.focus();
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Tidak Ditemukan', 
                                text: data.message || 'Rekening tidak ditemukan.' 
                            });
                        }
                    })
                    .catch(() => {
                        Swal.close();
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: 'Kesalahan jaringan.' 
                        });
                    });
                }, 2000);
            });

            // Format Rupiah
            inputJumlah.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value.length > 8) value = value.slice(0, 8);
                this.value = value === '' ? '' : formatRupiahInput(value);
            });

            // Step 2: Lanjutkan
            confirmAmount.addEventListener('click', function() {
                const btn = this;
                const amount = parseInt(inputJumlah.value.replace(/[^0-9]/g, '')) || 0;
                
                if (amount < 1 || amount > 99999999) {
                    inputJumlah.setAttribute('aria-invalid', 'true');
                    jumlahError.textContent = amount < 1 ? 'Minimal Rp 1.' : 'Maksimal Rp 99.999.999.';
                    jumlahError.classList.add('show');
                    return;
                }

                btn.classList.add('loading');
                btn.disabled = true;

                Swal.fire({
                    title: 'Mengecek saldo...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });

                setTimeout(() => {
                    fetch('proses_tarik_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=check_balance&no_rekening=${inputRekening.value}&jumlah=${amount}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        Swal.close();
                        btn.classList.remove('loading');
                        btn.disabled = false;

                        if (data.status === 'success') {
                            depositAmount = amount;
                            document.getElementById('pinNoRek').textContent = inputRekening.value;
                            document.getElementById('pinNama').textContent = accountData.nama;
                            document.getElementById('pinJumlah').textContent = formatRupiah(amount);
                            showStep(3);
                            inputPin.focus();
                        } else {
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Saldo Tidak Cukup', 
                                text: data.message 
                            });
                        }
                    })
                    .catch(() => {
                        Swal.close();
                        btn.classList.remove('loading');
                        btn.disabled = false;
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: 'Gagal memeriksa saldo.' 
                        });
                    });
                }, 2000);
            });

            cancelAmount.addEventListener('click', resetForm);

            // Step 3: Verifikasi PIN
            verifyPin.addEventListener('click', function() {
                if (isPinLocked) {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Akun Terkunci', 
                        text: 'Terlalu banyak percobaan salah. Silakan mulai dari awal.' 
                    });
                    return;
                }

                const pin = inputPin.value;
                if (pin.length !== 6) {
                    inputPin.setAttribute('aria-invalid', 'true');
                    pinError.textContent = 'PIN harus 6 digit.';
                    pinError.classList.add('show');
                    return;
                }

                // === PERBAIKAN: Tambahkan loading spinner SweetAlert2 untuk verifikasi PIN dengan delay 3 detik ===
                Swal.fire({
                    title: 'Verifikasi PIN...',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => Swal.showLoading()
                });

                setTimeout(() => {
                    fetch('proses_tarik_admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=verify_pin&user_id=${accountData.user_id}&pin=${pin}`
                    })
                    .then(res => res.json())
                    .then(data => {
                        Swal.close();
                        if (data.status === 'success') {
                            document.getElementById('confirmNoRek').textContent = inputRekening.value;
                            document.getElementById('confirmNama').textContent = accountData.nama;
                            document.getElementById('confirmJumlah').textContent = formatRupiah(depositAmount);
                            showStep(4);
                        } else {
                            pinAttempts++;
                            const remaining = MAX_ATTEMPTS - pinAttempts;
                            inputPin.value = '';
                            inputPin.focus();
                            
                            if (remaining <= 0) {
                                isPinLocked = true;
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Akun Terkunci',
                                    text: 'PIN salah 3 kali. Transaksi dibatalkan.'
                                }).then(resetForm);
                            } else {
                                pinError.textContent = `PIN salah. Sisa ${remaining} kesempatan.`;
                                pinError.classList.add('show');
                                Swal.fire({
                                    icon: 'error',
                                    title: 'PIN Salah',
                                    text: `Sisa ${remaining} kesempatan.`,
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            }
                        }
                    })
                    .catch(() => {
                        Swal.close();
                        Swal.fire({ 
                            icon: 'error', 
                            title: 'Error', 
                            text: 'Gagal memverifikasi PIN.' 
                        });
                    });
                }, 3000);
            });

            backToAmount.addEventListener('click', () => { 
                showStep(2); 
                inputJumlah.focus(); 
            });

            // Step 4: Proses Penarikan
            processDeposit.addEventListener('click', function() {
                const noRek = inputRekening.value;
                const nama = accountData.nama;
                const jumlah = formatRupiah(depositAmount);

                Swal.fire({
                    title: 'Konfirmasi Penarikan',
                    html: `
                        <div style="text-align:left; font-size:0.95rem; margin:15px 0;">
                            <div style="background: #f8fafc; border-radius: 5px; padding: 15px; margin-bottom: 10px;">
                                <div style="display: table; width: 100%; border-collapse: collapse;">
                                    <div style="display: table-row;">
                                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">No Rekening:</div>
                                        <div style="display: table-cell; padding: 5px 0; text-align: right;"><strong>${noRek}</strong></div>
                                    </div>
                                    <div style="display: table-row;">
                                        <div style="display: table-cell; padding: 5px 0; width: 120px; font-weight: bold;">Nama:</div>
                                        <div style="display: table-cell; padding: 5px 0; text-align: right;">${nama}</div>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align: center; margin-top: 20px; padding: 15px;">
                                <div style="font-weight: bold; font-size: 1.4rem; margin-bottom: 5px;">Jumlah Penarikan</div>
                                <div style="font-weight: bold; font-size: 1.6rem;">${jumlah}</div>
                            </div>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-check"></i> Proses Sekarang',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#e74c3c',
                    width: window.innerWidth < 600 ? '95%' : '600px'
                }).then(result => {
                    if (result.isConfirmed) {
                        Swal.fire({ 
                            title: 'Memproses...', 
                            allowOutsideClick: false, 
                            showConfirmButton: false, 
                            didOpen: () => Swal.showLoading() 
                        });
                        
                        fetch('proses_tarik_admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `action=tarik_saldo&no_rekening=${noRek}&jumlah=${depositAmount}&petugas_id=<?php echo $_SESSION['user_id']; ?>`
                        })
                        .then(res => res.json())
                        .then(data => {
                            Swal.close();
                            if (data.status === 'success') {
                                Swal.fire({ 
                                    icon: 'success', 
                                    title: 'Sukses!', 
                                    text: 'Penarikan berhasil diproses.', 
                                    confirmButtonColor: '#1e3a8a',
                                    timer: 3000,
                                    timerProgressBar: true
                                }).then(resetForm);
                            } else {
                                Swal.fire({ 
                                    icon: 'error', 
                                    title: 'Gagal!', 
                                    text: data.message || 'Gagal memproses penarikan.', 
                                    confirmButtonColor: '#1e3a8a' 
                                });
                            }
                        })
                        .catch(() => {
                            Swal.close();
                            Swal.fire({ 
                                icon: 'error', 
                                title: 'Error', 
                                text: 'Terjadi kesalahan saat memproses penarikan.', 
                                confirmButtonColor: '#1e3a8a' 
                            });
                        });
                    }
                });
            });

            backToPin.addEventListener('click', () => { 
                showStep(3); 
                inputPin.focus(); 
            });

            inputRekening.focus();
        });
    </script>
</body>
</html>
