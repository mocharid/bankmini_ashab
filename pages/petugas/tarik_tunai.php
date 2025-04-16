<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

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
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --bg-light: #f8fafc;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 8px 20px rgba(0, 0, 0, 0.1);
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
        }

        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 50%;
            cursor: pointer;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: scale(1.1);
        }

        .back-btn:active {
            transform: scale(0.95);
        }

        .main-content {
            flex: 1;
            padding: 30px 20px;
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 40px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            border: 2px solid rgba(255, 255, 255, 0.2);
            animation: fadeInBanner 0.8s ease-out;
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
            transform: rotate(30deg);
            animation: shimmer 10s infinite linear;
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
            font-size: clamp(1.6rem, 3.5vw, 2rem);
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            position: relative;
            z-index: 1;
        }

        .welcome-banner p {
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            opacity: 0.9;
            font-weight: 400;
            position: relative;
            z-index: 1;
        }

        .steps-container {
            display: flex;
            justify-content: center;
            margin-bottom: 40px;
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
            padding: 0 25px;
            transition: var(--transition);
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e2e8f0;
            color: var(--text-secondary);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 12px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            transition: var(--transition);
        }

        .step-text {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            color: var(--text-secondary);
            text-align: center;
            font-weight: 500;
        }

        .step-connector {
            position: absolute;
            top: 20px;
            height: 4px;
            background-color: #e2e8f0;
            width: 100%;
            left: 50%;
            z-index: 0;
            transition: var(--transition);
        }

        .step-item.active .step-number {
            background-color: var(--primary-color);
            color: white;
            transform: scale(1.15);
            box-shadow: 0 0 10px rgba(12, 77, 162, 0.3);
        }

        .step-item.active .step-text {
            color: var(--primary-color);
            font-weight: 600;
        }

        .step-item.completed .step-number {
            background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
            color: white;
        }

        .step-item.completed .step-connector {
            background: linear-gradient(to right, var(--secondary-color), var(--secondary-dark));
        }

        .transaction-container {
            display: grid;
            gap: 30px;
            justify-content: center;
        }

        .deposit-card, .account-details {
            background: linear-gradient(145deg, #ffffff, #f7fafc);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-sm);
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
            gap: 25px;
        }

        label {
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.9rem, 1.8vw, 1rem);
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
            font-weight: 500;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
        }

        input[type="text"],
        input.currency {
            width: 100%;
            padding: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            transition: var(--transition);
            background: #fff;
        }

        input.currency {
            padding-left: 50px;
        }

        input[type="text"]:focus,
        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(12, 77, 162, 0.2);
            transform: scale(1.01);
        }

        .pin-group {
            text-align: center;
            margin: 30px 0;
        }

        .pin-container {
            display: flex;
            justify-content: center;
            gap: 12px;
        }

        .pin-container.error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .pin-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            transition: var(--transition);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 8px rgba(12, 77, 162, 0.2);
            transform: scale(1.05);
        }

        .pin-input.filled {
            border-color: var(--secondary-color);
            background: var(--primary-light);
            animation: bouncePin 0.3s ease;
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }

        button {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 12px;
            cursor: pointer;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        button:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
        }

        button:active {
            transform: scale(0.95);
        }

        .detail-row {
            display: grid;
            grid-template-columns: 1fr 2fr;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid #edf2f7;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            transition: var(--transition);
        }

        .detail-row:hover {
            background: var(--primary-light);
            border-radius: 8px;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--text-secondary);
            font-weight: 500;
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .confirm-buttons {
            display: flex;
            gap: 20px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn-confirm {
            background: var(--secondary-color);
        }

        .btn-confirm:hover {
            background: var(--secondary-dark);
        }

        .btn-cancel {
            background: var(--danger-color);
        }

        .btn-cancel:hover {
            background: #c53030;
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

        .modal-content {
            background: linear-gradient(145deg, #ffffff, #f0f4ff);
            border-radius: 20px;
            padding: 50px;
            text-align: center;
            max-width: 90%;
            width: 500px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
            border: 2px solid rgba(255, 255, 255, 0.3);
            position: relative;
            overflow: hidden;
            transform: scale(0.5);
            opacity: 0;
            animation: popInModal 0.7s ease-out forwards;
        }

        .modal-content.error {
            background: linear-gradient(145deg, #ffffff, #fee2e2);
        }

        .modal-content.info {
            background: linear-gradient(145deg, #ffffff, #e0f2fe);
        }

        @keyframes popInModal {
            0% { transform: scale(0.5); opacity: 0; }
            70% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-icon {
            font-size: clamp(4.5rem, 8vw, 5rem);
            margin-bottom: 30px;
            animation: bounceIn 0.6s ease-out;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.15));
        }

        .modal-icon.success {
            color: var(--secondary-color);
        }

        .modal-icon.error {
            color: var(--danger-color);
        }

        .modal-icon.info {
            color: var(--primary-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-content h3 {
            color: var(--primary-dark);
            margin-bottom: 15px;
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .modal-content.error h3 {
            color: var(--danger-color);
        }

        .modal-content.info h3 {
            color: var(--primary-color);
        }

        .modal-content p {
            color: var(--text-secondary);
            font-size: clamp(1rem, 2.2vw, 1.15rem);
            margin-bottom: 20px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.6;
        }

        .modal-content.error p {
            color: #b91c1c;
        }

        .modal-content.info p {
            color: #0369a1;
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
            margin-bottom: 25px;
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .highlight-change {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 4px 8px;
            border-radius: 6px;
            animation: pulse 2s ease-in-out 3;
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(224, 233, 245, 0.7); }
            70% { box-shadow: 0 0 0 12px rgba(224, 233, 245, 0); }
            100% { box-shadow: 0 0 0 0 rgba(224, 233, 245, 0); }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px 20px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .main-content {
                padding: 20px 15px;
            }

            .deposit-card, .account-details {
                padding: 25px;
            }

            .steps-container {
                flex-direction: column;
                align-items: center;
                margin-bottom: 30px;
            }

            .step-item {
                flex-direction: row;
                width: 100%;
                padding: 10px 0;
            }

            .step-connector {
                display: none;
            }

            .step-number {
                margin-right: 12px;
                margin-bottom: 0;
                width: 36px;
                height: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            .step-text {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            button {
                width: 100%;
                justify-content: center;
                padding: 14px;
            }

            .confirm-buttons {
                flex-direction: column;
                gap: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.4rem, 3vw, 1.8rem);
            }

            .welcome-banner p {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .section-title {
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .detail-row {
                font-size: clamp(0.9rem, 2vw, 1rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .pin-container {
                gap: 10px;
            }

            .pin-input {
                width: 46px;
                height: 46px;
                font-size: clamp(1.1rem, 2.5vw, 1.3rem);
            }

            .modal-content {
                width: 90%;
                padding: 40px;
            }
        }

        @media (min-width: 1200px) {
            .transaction-container {
                grid-template-columns: 1fr;
                max-width: 600px;
                margin: 0 auto;
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
        <div style="width: 44px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-money-bill-wave"></i> Tarik Tunai</h2>
            <p>Layanan penarikan tunai untuk nasabah secara cepat dan aman</p>
        </div>

        <div class="steps-container">
            <div class="step-item active" id="step1-indicator">
                <div class="step-number">1</div>
                <div class="step-text">Cek Rekening</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item" id="step2-indicator">
                <div class="step-number">2</div>
                <div class="step-text">Input Nominal</div>
                <div class="step-connector"></div>
            </div>
            <div class="step-item" id="step3-indicator">
                <div class="step-number">3</div>
                <div class="step-text">Verifikasi PIN</div>
                <div class="step-connector"></div>
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
                <form id="cekRekening" class="deposit-form">
                    <div>
                        <label for="no_rekening">Nomor Rekening</label>
                        <input type="text" id="no_rekening" name="no_rekening" placeholder="REK..." required autofocus>
                    </div>
                    <button type="submit" id="cekButton">
                        <i class="fas fa-search"></i>
                        <span>Cek Rekening</span>
                    </button>
                </form>
            </div>

            <!-- Step 2: Input Amount -->
            <div class="account-details" id="amountDetails">
                <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening</div>
                    <div class="detail-value" id="displayNoRek">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik</div>
                    <div class="detail-value" id="displayNama">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Saldo Sekarang</div>
                    <div class="detail-value" id="displaySaldo">-</div>
                </div>
                <form id="formPenarikan" class="deposit-form" style="margin-top: 25px;">
                    <div class="currency-input">
                        <label for="jumlah">Jumlah Penarikan</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="10000" required>
                    </div>
                    <div class="confirm-buttons">
                        <button type="button" id="confirmAmount" class="btn-confirm">
                            <i class="fas fa-check-circle"></i>
                            <span>Lanjutkan</span>
                        </button>
                        <button type="button" id="cancelAmount" class="btn-cancel">
                            <i class="fas fa-times-circle"></i>
                            <span>Batal</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 3: PIN Verification -->
            <div class="account-details" id="pinDetails">
                <h3 class="section-title"><i class="fas fa-lock"></i> Verifikasi PIN</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening</div>
                    <div class="detail-value" id="pinNoRek">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik</div>
                    <div class="detail-value" id="pinNama">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jumlah Penarikan</div>
                    <div class="detail-value" id="pinJumlah">-</div>
                </div>
                <form id="formPin" class="deposit-form" style="margin-top: 25px;">
                    <div class="pin-group">
                        <label>Masukkan PIN (6 Digit)</label>
                        <div class="pin-container">
                            <input type="text" class="pin-input" maxlength="1" data-index="1" pattern="[0-9]*" inputmode="numeric">
                            <input type="text" class="pin-input" maxlength="1" data-index="2" pattern="[0-9]*" inputmode="numeric">
                            <input type="text" class="pin-input" maxlength="1" data-index="3" pattern="[0-9]*" inputmode="numeric">
                            <input type="text" class="pin-input" maxlength="1" data-index="4" pattern="[0-9]*" inputmode="numeric">
                            <input type="text" class="pin-input" maxlength="1" data-index="5" pattern="[0-9]*" inputmode="numeric">
                            <input type="text" class="pin-input" maxlength="1" data-index="6" pattern="[0-9]*" inputmode="numeric">
                        </div>
                        <input type="hidden" id="pin">
                    </div>
                    <div class="confirm-buttons">
                        <button type="button" id="verifyPin" class="btn-confirm">
                            <i class="fas fa-lock"></i>
                            <span>Verifikasi</span>
                        </button>
                        <button type="button" id="backToAmount" class="btn-cancel">
                            <i class="fas fa-arrow-left"></i>
                            <span>Kembali</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Step 4: Confirmation -->
            <div class="account-details" id="confirmationDetails">
                <h3 class="section-title"><i class="fas fa-receipt"></i> Konfirmasi Penarikan</h3>
                <div class="detail-row">
                    <div class="detail-label">Nomor Rekening</div>
                    <div class="detail-value" id="confirmNoRek">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Nama Pemilik</div>
                    <div class="detail-value" id="confirmNama">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Saldo Sekarang</div>
                    <div class="detail-value" id="confirmSaldo">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Jumlah Penarikan</div>
                    <div class="detail-value" id="confirmJumlah">-</div>
                </div>
                <div class="detail-row">
                    <div class="detail-label">Saldo Setelah Tarik</div>
                    <div class="detail-value highlight-change" id="confirmSisa">-</div>
                </div>
                <div class="confirm-buttons">
                    <button type="button" id="processWithdrawal" class="btn-confirm">
                        <i class="fas fa-check-circle"></i>
                        <span>Proses Penarikan</span>
                    </button>
                    <button type="button" id="backToPin" class="btn-cancel">
                        <i class="fas fa-arrow-left"></i>
                        <span>Kembali</span>
                    </button>
                </div>
            </div>
        </div>
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
            const penarikanForm = document.getElementById('formPenarikan');
            const pinForm = document.getElementById('formPin');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const amountDetails = document.getElementById('amountDetails');
            const pinDetails = document.getElementById('pinDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');

            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');
            const step4Indicator = document.getElementById('step4-indicator');

            const inputNoRek = document.getElementById('no_rekening');
            const inputJumlah = document.getElementById('jumlah');
            const pinInputs = document.querySelectorAll('.pin-input');
            const pinContainer = document.querySelector('.pin-container');
            const hiddenPin = document.getElementById('pin');

            const cekButton = document.getElementById('cekButton');
            const confirmAmount = document.getElementById('confirmAmount');
            const cancelAmount = document.getElementById('cancelAmount');
            const verifyPin = document.getElementById('verifyPin');
            const backToAmount = document.getElementById('backToAmount');
            const processWithdrawal = document.getElementById('processWithdrawal');
            const backToPin = document.getElementById('backToPin');

            let accountData = {};
            const prefix = "REK";

            // Initialize input with prefix
            inputNoRek.value = prefix;

            // Restrict account number to numbers only
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

            // Format amount input with commas
            inputJumlah.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value === '') {
                    this.value = '';
                    return;
                }
                this.value = formatRupiahInput(value);
            });

            // PIN input handling
            pinInputs.forEach((input, index) => {
                input.addEventListener('input', function() {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.classList.add('filled');
                        this.dataset.originalValue = value;
                        this.value = '*';
                        if (index < 5) pinInputs[index + 1].focus();
                    } else {
                        this.classList.remove('filled');
                        this.dataset.originalValue = '';
                        this.value = '';
                    }
                    updateHiddenPin();
                });
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.dataset.originalValue && index > 0) {
                        pinInputs[index - 1].focus();
                        pinInputs[index - 1].classList.remove('filled');
                        pinInputs[index - 1].dataset.originalValue = '';
                        pinInputs[index - 1].value = '';
                        updateHiddenPin();
                    }
                });
                input.addEventListener('focus', function() {
                    if (this.classList.contains('filled')) {
                        this.value = this.dataset.originalValue || '';
                    }
                });
                input.addEventListener('blur', function() {
                    if (this.value && !this.classList.contains('filled')) {
                        let value = this.value.replace(/[^0-9]/g, '');
                        if (value) {
                            this.dataset.originalValue = value;
                            this.value = '*';
                            this.classList.add('filled');
                        }
                    }
                });
            });

            function updateStepIndicators(activeStep) {
                step1Indicator.className = 'step-item';
                step2Indicator.className = 'step-item';
                step3Indicator.className = 'step-item';
                step4Indicator.className = 'step-item';

                if (activeStep >= 1) {
                    step1Indicator.className = activeStep > 1 ? 'step-item completed' : 'step-item active';
                }
                if (activeStep >= 2) {
                    step2Indicator.className = activeStep > 2 ? 'step-item completed' : 'step-item active';
                }
                if (activeStep >= 3) {
                    step3Indicator.className = activeStep > 3 ? 'step-item completed' : 'step-item active';
                }
                if (activeStep === 4) {
                    step4Indicator.className = 'step-item active';
                }

                checkAccountStep.style.display = activeStep === 1 ? 'block' : 'none';
                amountDetails.classList.toggle('visible', activeStep === 2);
                pinDetails.classList.toggle('visible', activeStep === 3);
                confirmationDetails.classList.toggle('visible', activeStep === 4);
            }

            function updateHiddenPin() {
                hiddenPin.value = Array.from(pinInputs).map(i => i.dataset.originalValue || '').join('');
            }

            function clearPinInputs() {
                pinInputs.forEach(i => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                hiddenPin.value = '';
                pinInputs[0].focus();
            }

            function shakePinContainer() {
                pinContainer.classList.add('error');
                setTimeout(() => pinContainer.classList.remove('error'), 400);
            }

            cekForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const rekening = inputNoRek.value.trim();
                if (rekening === prefix) {
                    showModalAlert('Silakan masukkan nomor rekening lengkap', 'error');
                    return;
                }
                cekButton.classList.add('btn-loading');
                try {
                    const response = await fetch('proses_tarik.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=cek_rekening&no_rekening=${encodeURIComponent(rekening)}`
                    });
                    const data = await response.json();
                    cekButton.classList.remove('btn-loading');
                    if (data.status === 'error') {
                        showModalAlert(data.message, 'error');
                        return;
                    }
                    accountData = data;
                    document.getElementById('displayNoRek').textContent = data.no_rekening;
                    document.getElementById('displayNama').textContent = data.nama;
                    document.getElementById('displaySaldo').textContent = formatRupiah(data.saldo);
                    updateStepIndicators(2);
                    inputJumlah.focus();
                    showModalAlert(`Rekening atas nama ${data.nama} ditemukan`, 'success');
                } catch (error) {
                    cekButton.classList.remove('btn-loading');
                    showModalAlert('Terjadi kesalahan sistem', 'error');
                }
            });

            confirmAmount.addEventListener('click', function() {
                let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                jumlah = parseFloat(jumlah);
                if (!jumlah || jumlah < 10000) {
                    showModalAlert('Jumlah penarikan minimal Rp 10.000', 'error');
                    inputJumlah.focus();
                    return;
                }
                if (jumlah > accountData.saldo) {
                    showModalAlert('Saldo tidak mencukupi', 'error');
                    inputJumlah.focus();
                    return;
                }
                accountData.jumlah = jumlah;
                document.getElementById('pinNoRek').textContent = accountData.no_rekening;
                document.getElementById('pinNama').textContent = accountData.nama;
                document.getElementById('pinJumlah').textContent = formatRupiah(jumlah);
                clearPinInputs();
                updateStepIndicators(3);
            });

            cancelAmount.addEventListener('click', function() {
                cekForm.reset();
                penarikanForm.reset();
                inputNoRek.value = prefix;
                inputJumlah.value = '';
                amountDetails.classList.remove('visible');
                checkAccountStep.style.display = 'block';
                updateStepIndicators(1);
                inputNoRek.focus();
                inputNoRek.setSelectionRange(prefix.length, prefix.length);
            });

            verifyPin.addEventListener('click', async function() {
                const pin = hiddenPin.value;
                if (pin.length !== 6) {
                    shakePinContainer();
                    showModalAlert('PIN harus 6 digit', 'error');
                    return;
                }
                verifyPin.classList.add('btn-loading');
                try {
                    const response = await fetch('proses_tarik.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=verify_pin&user_id=${accountData.user_id}&pin=${pin}`
                    });
                    const data = await response.json();
                    verifyPin.classList.remove('btn-loading');
                    if (data.status === 'error') {
                        shakePinContainer();
                        clearPinInputs();
                        showModalAlert(data.message, 'error');
                        return;
                    }
                    document.getElementById('confirmNoRek').textContent = accountData.no_rekening;
                    document.getElementById('confirmNama').textContent = accountData.nama;
                    document.getElementById('confirmSaldo').textContent = formatRupiah(accountData.saldo);
                    document.getElementById('confirmJumlah').textContent = formatRupiah(accountData.jumlah);
                    document.getElementById('confirmSisa').textContent = formatRupiah(accountData.saldo - accountData.jumlah);
                    updateStepIndicators(4);
                } catch (error) {
                    verifyPin.classList.remove('btn-loading');
                    shakePinContainer();
                    clearPinInputs();
                    showModalAlert('Terjadi kesalahan sistem', 'error');
                }
            });

            backToAmount.addEventListener('click', function() {
                updateStepIndicators(2);
                inputJumlah.focus();
            });

            processWithdrawal.addEventListener('click', async function() {
                processWithdrawal.classList.add('btn-loading');
                try {
                    const response = await fetch('proses_tarik.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=tarik_tunai&no_rekening=${encodeURIComponent(accountData.no_rekening)}&jumlah=${accountData.jumlah}`
                    });
                    const data = await response.json();
                    processWithdrawal.classList.remove('btn-loading');
                    if (data.status === 'error') {
                        showModalAlert(data.message, 'error');
                        return;
                    }
                    showSuccessAnimation(data.message);
                    cekForm.reset();
                    penarikanForm.reset();
                    inputNoRek.value = prefix;
                    inputJumlah.value = '';
                    confirmationDetails.classList.remove('visible');
                    pinDetails.classList.remove('visible');
                    amountDetails.classList.remove('visible');
                    checkAccountStep.style.display = 'block';
                    updateStepIndicators(1);
                    accountData = {};
                    inputNoRek.focus();
                    inputNoRek.setSelectionRange(prefix.length, prefix.length);
                } catch (error) {
                    processWithdrawal.classList.remove('btn-loading');
                    showModalAlert('Gagal memproses transaksi', 'error');
                }
            });

            backToPin.addEventListener('click', function() {
                updateStepIndicators(3);
                clearPinInputs();
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

            function closeModal(overlay, modal) {
                overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                modal.style.animation = 'popInModal 0.7s ease-out reverse';
                setTimeout(() => overlay.remove(), 500);
            }

            function showSuccessAnimation(message) {
                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                overlay.innerHTML = `
                    <div class="modal-content success">
                        <div class="modal-icon success">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Penarikan Berhasil!</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.modal-content');
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    modal.appendChild(confetti);
                }

                overlay.addEventListener('click', () => closeModal(overlay, modal));
                setTimeout(() => closeModal(overlay, modal), 7000);
            }

            function showModalAlert(message, type) {
                const existingModals = document.querySelectorAll('.modal-overlay');
                existingModals.forEach(modal => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    const modalContent = modal.querySelector('.modal-content');
                    if (modalContent) {
                        modalContent.style.animation = 'popInModal 0.7s ease-out reverse';
                    }
                    setTimeout(() => modal.remove(), 500);
                });

                const overlay = document.createElement('div');
                overlay.className = 'modal-overlay';
                const title = type === 'success' ? 'Berhasil' : type === 'error' ? 'Kesalahan' : 'Informasi';
                overlay.innerHTML = `
                    <div class="modal-content ${type}">
                        <div class="modal-icon ${type}">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                        </div>
                        <h3>${title}</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(overlay);

                const modal = overlay.querySelector('.modal-content');
                overlay.addEventListener('click', () => closeModal(overlay, modal));
                setTimeout(() => closeModal(overlay, modal), 7000);
            }

            inputNoRek.focus();
            inputNoRek.setSelectionRange(prefix.length, prefix.length);

            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') cekButton.click();
            });
            inputJumlah.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') confirmAmount.click();
            });
            pinInputs.forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && hiddenPin.value.length === 6) verifyPin.click();
                });
            });
        });
    </script>
</body>
</html>