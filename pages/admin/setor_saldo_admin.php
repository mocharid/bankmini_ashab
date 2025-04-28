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
    <title>Setor Saldo Siswa - SCHOBANK SYSTEM</title>
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
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2vw, 1.25rem);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .top-nav h1 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            margin: 0;
            flex-grow: 0;
            text-align: center;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            transition: var(--transition);
            position: absolute;
            left: 20px;
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
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
            max-width: 100%;
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

        .steps-container {
            display: flex;
            justify-content: center;
            align-items: center;
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            animation: slideInSteps 0.5s ease-out;
            max-width: 800px;
            width: 100%;
            margin: 0 auto;
        }

        @keyframes slideInSteps {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
            max-width: 150px;
            position: relative;
            z-index: 1;
            padding: 10px;
            transition: var(--transition);
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e0e0e0;
            color: var(--text-secondary);
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: clamp(0.9rem, 1.8vw, 1rem);
            transition: var(--transition);
        }

        .step-text {
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            color: var(--text-secondary);
            text-align: center;
            line-height: 1.2;
            margin-bottom: 8px;
            transition: var(--transition);
        }

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            bottom: 0;
            right: -50%;
            width: 100%;
            height: 4px;
            background-color: #e0e0e0;
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

        .step-item.completed::after {
            background-color: var(--secondary-color);
        }

        .transaction-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            justify-items: center;
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
        }

        .deposit-card, .account-details {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            width: 100%;
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
            gap: 15px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
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
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
        }

        input.currency {
            padding-left: 40px;
        }

        input[type="text"]:focus,
        input.currency:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        .rek-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            flex-wrap: nowrap;
            max-width: 300px;
            margin: 0 auto;
            visibility: visible;
            overflow: visible;
        }

        .rek-prefix {
            font-size: clamp(1rem, 2vw, 1.1rem);
            color: var(--text-secondary);
            font-weight: 500;
            margin-right: 8px;
            flex-shrink: 0;
        }

        .rek-input {
            width: 40px;
            height: 40px;
            text-align: center;
            font-size: clamp(1rem, 2vw, 1.1rem);
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #fff;
            transition: var(--transition);
            line-height: 40px;
            display: inline-block;
            visibility: visible;
        }

        .rek-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.05);
        }

        .rek-input.filled {
            border-color: var(--secondary-color);
            background: var(--primary-light);
            animation: bouncePin 0.3s ease;
        }

        .rek-container.error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        @keyframes bouncePin {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
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
            width: 100%;
            max-width: 200px;
            margin: 0 auto;
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
            padding: 10px 0;
            gap: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
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
            gap: 12px;
            margin-top: 15px;
            justify-content: center;
            flex-wrap: wrap;
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

        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading i,
        .btn-loading span {
            opacity: 0;
        }

        .btn-loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            border: 2px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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

        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            max-width: 90%;
            width: 400px;
            box-shadow: var(--shadow-md);
            position: relative;
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.4s ease-out forwards;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            70% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            animation: bounceIn 0.4s ease-out;
        }

        .success-icon {
            color: var(--secondary-color);
        }

        .error-icon {
            color: var(--danger-color);
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.15); }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-content h3 {
            color: var(--primary-dark);
            margin-bottom: 12px;
            font-size: clamp(1.1rem, 2vw, 1.25rem);
            font-weight: 600;
            animation: slideUpText 0.4s ease-out 0.1s both;
        }

        .modal-content p {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            line-height: 1.5;
            animation: slideUpText 0.4s ease-out 0.2s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .section-title {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.15rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (min-width: 769px) {
            .steps-container {
                flex-direction: row;
                justify-content: space-between;
            }

            .step-item {
                max-width: 200px;
            }

            .step-item:not(:last-child)::after {
                bottom: 0;
                right: -50%;
                width: 100%;
            }

            .transaction-container {
                grid-template-columns: 1fr;
                max-width: 600px;
            }

            .confirm-buttons {
                flex-direction: row;
            }

            button {
                width: auto;
                max-width: 200px;
            }
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(1rem, 2vw, 1.15rem);
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

            .steps-container {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
                max-width: 100%;
            }

            .step-item {
                flex-direction: row;
                align-items: center;
                width: 100%;
                max-width: none;
                margin-bottom: 16px;
                padding: 5px 0;
            }

            .step-item:not(:last-child)::after {
                display: none;
            }

            .step-number {
                width: 32px;
                height: 32px;
                margin-right: 10px;
                margin-bottom: 0;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .step-text {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
                text-align: left;
                max-width: none;
                margin-bottom: 0;
            }

            .transaction-container {
                max-width: 100%;
            }

            .deposit-card, .account-details {
                padding: 15px;
            }

            .rek-container {
                gap: 8px;
                max-width: 320px;
                flex-wrap: nowrap;
                justify-content: center;
                align-items: center;
                visibility: visible;
                overflow: visible;
            }

            .rek-input {
                width: 42px;
                height: 42px;
                font-size: clamp(1rem, 2vw, 1.1rem);
                display: inline-block;
                visibility: visible;
                line-height: 42px;
            }

            .rek-prefix {
                font-size: clamp(1rem, 2vw, 1.1rem);
                margin-right: 8px;
            }

            .modal-content {
                width: 90%;
                padding: 15px;
            }

            .confirm-buttons {
                flex-direction: column;
            }

            button {
                width: 100%;
                max-width: none;
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .top-nav {
                padding: 10px;
            }

            .section-title {
                font-size: clamp(0.95rem, 2vw, 1.05rem);
            }

            .detail-row {
                grid-template-columns: 1fr 1.5fr;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .rek-container {
                max-width: 280px;
                gap: 6px;
                flex-wrap: nowrap;
                justify-content: center;
                align-items: center;
                visibility: visible;
                overflow: visible;
            }

            .rek-input {
                width: 38px;
                height: 38px;
                font-size: clamp(0.95rem, 1.8vw, 1rem);
                display: inline-block;
                visibility: visible;
                line-height: 38px;
            }

            .rek-prefix {
                font-size: clamp(0.95rem, 1.8vw, 1rem);
                margin-right: 6px;
            }

            .modal-content {
                width: 95%;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" id="backBtn">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-money-bill-wave"></i> Setor Saldo Siswa</h2>
            <p>Layanan penyetoran saldo untuk siswa secara cepat dan aman</p>
        </div>

        <div class="steps-container">
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

        <div class="transaction-container">
            <!-- Step 1: Check Account -->
            <div class="deposit-card" id="checkAccountStep">
                <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
                <form id="cekRekening" class="deposit-form" novalidate>
                    <div>
                        <label for="no_rekening">No Rekening:</label>
                        <div class="rek-container">
                            <span class="rek-prefix">REK</span>
                            <input type="text" class="rek-input" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="rek-input" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="rek-input" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="rek-input" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="rek-input" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]*">
                            <input type="text" class="rek-input" maxlength="1" data-index="6" inputmode="numeric" pattern="[0-9]*">
                        </div>
                        <input type="hidden" id="no_rekening" name="no_rekening">
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
                <form id="formPenyetoran" class="deposit-form" style="margin-top: 20px;">
                    <div class="currency-input">
                        <label for="jumlah">Jumlah Penyetoran (Rp):</label>
                        <span class="currency-prefix">Rp</span>
                        <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="10000" inputmode="numeric" pattern="[0-9]*" required>
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

            <!-- Step 3: Confirmation -->
            <div class="account-details" id="confirmationDetails">
                <h3 class="section-title"><i class="fas fa-receipt"></i> Konfirmasi Penyetoran</h3>
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
                    <div class="detail-label">Jumlah Penyetoran:</div>
                    <div class="detail-value" id="confirmJumlah">-</div>
                </div>
                <div class="confirm-buttons">
                    <button type="button" id="processDeposit" class="btn-confirm">
                        <i class="fas fa-check-circle"></i>
                        <span>Proses Penyetoran</span>
                    </button>
                    <button type="button" id="backToAmount" class="btn-cancel">
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
            const penyetoranForm = document.getElementById('formPenyetoran');
            const checkAccountStep = document.getElementById('checkAccountStep');
            const amountDetails = document.getElementById('amountDetails');
            const confirmationDetails = document.getElementById('confirmationDetails');

            const step1Indicator = document.getElementById('step1-indicator');
            const step2Indicator = document.getElementById('step2-indicator');
            const step3Indicator = document.getElementById('step3-indicator');

            const inputJumlah = document.getElementById('jumlah');
            const rekInputs = document.querySelectorAll('.rek-input');
            const rekContainer = document.querySelector('.rek-container');
            const hiddenNoRek = document.getElementById('no_rekening');

            const cekButton = document.getElementById('cekButton');
            const confirmAmount = document.getElementById('confirmAmount');
            const cancelAmount = document.getElementById('cancelAmount');
            const processDeposit = document.getElementById('processDeposit');
            const backToAmount = document.getElementById('backToAmount');
            const backBtn = document.getElementById('backBtn');

            let accountData = {};
            const prefix = "REK";

            // Ensure rek-container is visible and inputs are properly sized
            rekContainer.style.display = 'flex';
            rekContainer.style.visibility = 'visible';
            rekInputs.forEach(input => {
                input.style.display = 'inline-block';
                input.style.visibility = 'visible';
            });

            // Back Button Handler
            backBtn.addEventListener('click', function() {
                window.location.href = 'dashboard.php';
            });

            // Account Number Input Handling
            rekInputs.forEach((input, index) => {
                input.addEventListener('input', function(e) {
                    let value = this.value.replace(/[^0-9]/g, '');
                    if (value.length === 1) {
                        this.classList.add('filled');
                        this.dataset.originalValue = value;
                        this.value = value;
                        updateHiddenNoRek();
                        if (index < 5) {
                            rekInputs[index + 1].focus();
                        } else if (hiddenNoRek.value.length === 9) {
                            cekButton.click();
                        }
                    } else {
                        this.classList.remove('filled');
                        this.dataset.originalValue = '';
                        this.value = '';
                        updateHiddenNoRek();
                    }
                });

                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Backspace' && !this.dataset.originalValue && index > 0) {
                        rekInputs[index - 1].focus();
                        rekInputs[index - 1].classList.remove('filled');
                        rekInputs[index - 1].dataset.originalValue = '';
                        rekInputs[index - 1].value = '';
                        updateHiddenNoRek();
                    }
                });

                input.addEventListener('paste', function(e) {
                    e.preventDefault();
                    let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                    rekInputs.forEach((inp, i) => {
                        inp.value = '';
                        inp.classList.remove('filled');
                        inp.dataset.originalValue = '';
                    });
                    for (let i = 0; i < 6 && i < pastedData.length; i++) {
                        rekInputs[i].value = pastedData[i];
                        rekInputs[i].dataset.originalValue = pastedData[i];
                        rekInputs[i].classList.add('filled');
                    }
                    updateHiddenNoRek();
                    if (pastedData.length > 0) {
                        rekInputs[Math.min(pastedData.length - 1, 5)].focus();
                    }
                    if (hiddenNoRek.value.length === 9) {
                        cekButton.click();
                    }
                });
            });

            // Amount Input Handling
            inputJumlah.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value === '') {
                    this.value = '';
                    return;
                }
                this.value = formatRupiahInput(value);
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

                checkAccountStep.style.display = activeStep === 1 ? 'block' : 'none';
                amountDetails.classList.toggle('visible', activeStep === 2);
                confirmationDetails.classList.toggle('visible', activeStep === 3);
            }

            function updateHiddenNoRek() {
                const digits = Array.from(rekInputs).map(i => i.dataset.originalValue || '').join('');
                hiddenNoRek.value = digits.length === 6 ? prefix + digits : '';
            }

            function clearRekInputs() {
                rekInputs.forEach(i => {
                    i.value = '';
                    i.classList.remove('filled');
                    i.dataset.originalValue = '';
                });
                hiddenNoRek.value = '';
                rekInputs[0].focus();
            }

            function shakeRekContainer() {
                rekContainer.classList.add('error');
                setTimeout(() => rekContainer.classList.remove('error'), 400);
            }

            function startLoading(button) {
                button.classList.add('btn-loading');
            }

            function stopLoading(button, callback) {
                setTimeout(() => {
                    button.classList.remove('btn-loading');
                    if (callback) callback();
                }, 500);
            }

            function showModal(message, type, redirect = false) {
                const modal = document.createElement('div');
                modal.className = 'modal-overlay';
                modal.innerHTML = `
                    <div class="modal-content">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'times-circle'} modal-icon ${type}-icon"></i>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                document.body.appendChild(modal);
                setTimeout(() => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        modal.remove();
                        if (redirect) {
                            window.location.href = 'dashboard.php';
                        }
                    }, 500);
                }, 2000);
            }

            cekForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const rekening = hiddenNoRek.value;
                if (!rekening || rekening.length !== 9 || !/REK[0-9]{6}/.test(rekening)) {
                    showModal('Nomor rekening harus diawali REK diikuti 6 digit angka', 'error');
                    shakeRekContainer();
                    rekInputs[0].focus();
                    return;
                }
                startLoading(cekButton);
                fetch('proses_setor_admin.php', {
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
                    stopLoading(cekButton, () => {
                        if (data.status === 'error') {
                            showModal(data.message, 'error');
                            clearRekInputs();
                            return;
                        }
                        accountData = data;
                        document.getElementById('displayNoRek').textContent = data.no_rekening;
                        document.getElementById('displayNama').textContent = data.nama;
                        document.getElementById('displayJurusan').textContent = data.jurusan;
                        document.getElementById('displayKelas').textContent = data.kelas;
                        updateStepIndicators(2);
                        inputJumlah.focus();
                    });
                })
                .catch(error => {
                    stopLoading(cekButton);
                    showModal('Terjadi kesalahan sistem: ' + error.message, 'error');
                    clearRekInputs();
                });
            });

            confirmAmount.addEventListener('click', function() {
                startLoading(confirmAmount);
                let jumlah = inputJumlah.value.replace(/[^0-9]/g, '');
                jumlah = parseFloat(jumlah);
                if (isNaN(jumlah) || jumlah < 1000) {
                    stopLoading(confirmAmount);
                    showModal('Jumlah penyetoran minimal Rp 10.000', 'error');
                    inputJumlah.focus();
                    return;
                }
                if (jumlah > 99999999.99) {
                    stopLoading(confirmAmount);
                    showModal('Jumlah penyetoran maksimal Rp 99.999.999,99', 'error');
                    inputJumlah.focus();
                    return;
                }
                accountData.jumlah = jumlah;
                document.getElementById('confirmNoRek').textContent = accountData.no_rekening;
                document.getElementById('confirmNama').textContent = accountData.nama;
                document.getElementById('confirmJurusan').textContent = accountData.jurusan;
                document.getElementById('confirmKelas').textContent = accountData.kelas;
                document.getElementById('confirmJumlah').textContent = formatRupiah(jumlah);
                updateStepIndicators(3);
                stopLoading(confirmAmount);
            });

            cancelAmount.addEventListener('click', function() {
                startLoading(cancelAmount);
                stopLoading(cancelAmount, () => {
                    cekForm.reset();
                    penyetoranForm.reset();
                    clearRekInputs();
                    inputJumlah.value = '';
                    amountDetails.classList.remove('visible');
                    checkAccountStep.style.display = 'block';
                    updateStepIndicators(1);
                    rekInputs[0].focus();
                });
            });

            processDeposit.addEventListener('click', function() {
                startLoading(processDeposit);
                fetch('proses_setor_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=setor_saldo&no_rekening=${encodeURIComponent(accountData.no_rekening)}&jumlah=${accountData.jumlah}`
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    stopLoading(processDeposit, () => {
                        if (data.status === 'error') {
                            showModal(data.message, 'error');
                            return;
                        }
                        if (data.email_status === 'failed') {
                            showModal('Transaksi berhasil, tetapi gagal mengirim bukti transaksi ke email', 'error');
                            return;
                        }
                        showModal(data.message, 'success', true);
                        cekForm.reset();
                        penyetoranForm.reset();
                        clearRekInputs();
                        inputJumlah.value = '';
                        confirmationDetails.classList.remove('visible');
                        amountDetails.classList.remove('visible');
                        checkAccountStep.style.display = 'block';
                        updateStepIndicators(1);
                        accountData = {};
                        rekInputs[0].focus();
                    });
                })
                .catch(error => {
                    stopLoading(processDeposit);
                    showModal('Gagal memproses transaksi: ' + error.message, 'error');
                });
            });

            backToAmount.addEventListener('click', function() {
                startLoading(backToAmount);
                stopLoading(backToAmount, () => {
                    updateStepIndicators(2);
                    inputJumlah.focus();
                });
            });
        });
    </script>
</body>
</html>