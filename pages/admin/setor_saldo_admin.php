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
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            text-align: center;
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
            justify-content: center;
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

        .combined-container {
            background: white;
            border-radius: 15px;
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

        .steps-section {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            position: relative;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex: 1;
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

        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 24px;
            left: calc(50% + 24px);
            width: calc(100% - 48px);
            height: 6px;
            background: repeating-linear-gradient(
                to right,
                #e0e0e0 0,
                #e0e0e0 8px,
                transparent 8px,
                transparent 16px
            );
            border-radius: 3px;
            transition: background 0.5s ease;
            z-index: 0;
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

        .step-item.completed::after {
            background: linear-gradient(90deg, var(--secondary-color), var(--primary-dark));
            animation: fillLine 0.5s ease forwards;
        }

        @keyframes fillLine {
            from { width: 0; }
            to { width: calc(100% - 48px); }
        }

        .deposit-card, .account-details {
            background: #f9fafb;
            border-radius: 10px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            width: 100%;
            max-width: 600px;
            margin: 0 auto;
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
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
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

        .rek-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: nowrap;
            max-width: 400px;
            margin: 0 auto;
            visibility: visible;
            overflow: visible;
        }

        .rek-prefix {
            font-size: clamp(1rem, 2vw, 1.1rem);
            color: var(--text-secondary);
            font-weight: 500;
            margin-right: 10px;
            flex-shrink: 0;
            line-height: 48px;
        }

        .rek-input {
            width: 48px;
            height: 48px;
            text-align: center;
            font-size: clamp(1rem, 2vw, 1.1rem);
            border: 1px solid #ddd;
            border-radius: 10px;
            background: #fff;
            transition: var(--transition);
            line-height: 48px;
            display: inline-block;
            visibility: visible;
            -webkit-appearance: none;
            -moz-appearance: textfield;
            appearance: none;
        }

        .rek-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.05);
        }

        .rek-input.filled {
            border-color: var(--secondary-color);
            background: rgba(59, 130, 246, 0.05);
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

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 10px;
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            position: absolute;
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

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            animation: fadeInOverlay 0.5s ease-in-out forwards;
            cursor: pointer;
        }

        .modal-overlay.show {
            display: flex;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            position: relative;
            text-align: center;
            width: clamp(350px, 85vw, 450px);
            border-radius: 15px;
            padding: clamp(30px, 5vw, 40px);
            box-shadow: var(--shadow-md);
            transform: scale(0.7);
            opacity: 0;
            animation: popInModal 0.7s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            overflow: hidden;
            cursor: default;
        }

        .modal-content.success {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .modal-content.error {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .modal-content::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.7); opacity: 0; }
            80% { transform: scale(1.03); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            color: white;
        }

        .success-icon, .error-icon {
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .modal-content h3 {
            color: white;
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .modal-content p {
            color: white;
            font-size: clamp(0.95rem, 2.3vw, 1.05rem);
            margin: 0 0 25px;
            line-height: 1.6;
            animation: slideUpText 0.5s ease-out 0.3s both;
        }

        @keyframes slideUpText {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .combined-container {
                padding: 15px;
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

            .step-item:not(:last-child)::after {
                display: none;
            }

            .step-number {
                width: 36px;
                height: 36px;
                margin-right: 10px;
                margin-bottom: 0;
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .step-text {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
                text-align: left;
                max-width: none;
                margin-bottom: 0;
            }

            .deposit-card, .account-details {
                padding: 15px;
                max-width: 100%;
            }

            .form-group {
                max-width: 100%;
            }

            .rek-container {
                gap: 5px;
                padding: 0 5px;
            }

            .rek-input {
                width: 36px;
                height: 36px;
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                line-height: 36px;
            }

            .rek-prefix {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                line-height: 36px;
            }

            .btn {
                width: var(--button-width);
                height: var(--button-height);
                font-size: clamp(0.8rem, 1.7vw, 0.9rem);
            }

            .modal-content {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px;
            }

            .modal-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .modal-content h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .modal-content p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
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

            .top-nav {
                padding: 10px;
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .rek-container {
                gap: 5px;
                padding: 0 5px;
            }

            .rek-input {
                width: 36px;
                height: 36px;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
                line-height: 36px;
            }

            .rek-prefix {
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
                line-height: 36px;
            }

            .btn {
                width: var(--button-width);
                height: var(--button-height);
                font-size: clamp(0.75rem, 1.6vw, 0.85rem);
            }

            .modal-content {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px;
            }

            .modal-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .modal-content h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .modal-content p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }

            .confirm-buttons {
                gap: 6px;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" id="backBtn">
            <span class="btn-content"><i class="fas fa-xmark"></i></span>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2>Setor Saldo Siswa</h2>
            <p>Layanan penyetoran saldo untuk siswa secara cepat dan aman</p>
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

            <div class="transaction-container">
                <!-- Step 1: Check Account -->
                <div class="deposit-card" id="checkAccountStep">
                    <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
                    <form id="cekRekening" class="deposit-form" novalidate>
                        <div class="form-group">
                            <label for="no_rekening">No Rekening:</label>
                            <div class="rek-container">
                                <span class="rek-prefix">REK</span>
                                <input type="text" class="rek-input" maxlength="1" data-index="1" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 1 of account number">
                                <input type="text" class="rek-input" maxlength="1" data-index="2" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 2 of account number">
                                <input type="text" class="rek-input" maxlength="1" data-index="3" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 3 of account number">
                                <input type="text" class="rek-input" maxlength="1" data-index="4" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 4 of account number">
                                <input type="text" class="rek-input" maxlength="1" data-index="5" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 5 of account number">
                                <input type="text" class="rek-input" maxlength="1" data-index="6" inputmode="numeric" pattern="[0-9]*" aria-label="Digit 6 of account number">
                            </div>
                            <input type="hidden" id="no_rekening" name="no_rekening">
                        </div>
                        <button type="submit" id="cekButton" class="btn">
                            <span class="btn-content">Cek Rekening</span>
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
                    <form id="formPenyetoran" class="deposit-form">
                        <div class="form-group currency-input">
                            <label for="jumlah">Jumlah Penyetoran (Rp):</label>
                            <span class="currency-prefix">Rp</span>
                            <input type="text" id="jumlah" name="jumlah" class="currency" placeholder="1000" inputmode="numeric" pattern="[0-9]*" required>
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
                        <button type="button" id="processDeposit" class="btn btn-confirm">
                            <span class="btn-content">Proses</span>
                        </button>
                        <button type="button" id="backToAmount" class="btn btn-cancel">
                            <span class="btn-content">Kembali</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="modalOverlay">
        <div class="modal-content" id="modalContent">
            <i class="fas modal-icon" id="modalIcon"></i>
            <h3 id="modalTitle"></h3>
            <p id="modalMessage"></p>
        </div>
    </div>

    <script>
        // Prevent pinch-to-zoom and double-tap zoom
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

            const modalOverlay = document.getElementById('modalOverlay');
            const modalContent = document.getElementById('modalContent');
            const modalIcon = document.getElementById('modalIcon');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');

            let accountData = {};
            let depositAmount = 0;
            const prefix = "REK";

            // Ensure rek-container and inputs are visible
            rekContainer.style.display = 'flex';
            rekContainer.style.visibility = 'visible';
            rekInputs.forEach(input => {
                input.style.display = 'inline-block';
                input.style.visibility = 'visible';
                // Force text visibility on input
                input.addEventListener('input', function() {
                    this.style.color = 'var(--text-primary)';
                    this.style.fontSize = window.innerWidth <= 480 ? '0.9rem' : window.innerWidth <= 768 ? '0.95rem' : '1rem';
                });
            });

            // Modal Handling
            function showModal(title, message, isSuccess) {
                modalTitle.textContent = title;
                modalMessage.textContent = message;
                modalIcon.className = `fas modal-icon ${isSuccess ? 'fa-check-circle success-icon' : 'fa-exclamation-circle error-icon'}`;
                modalContent.className = `modal-content ${isSuccess ? 'success' : 'error'}`;
                modalOverlay.classList.add('show');
                modalOverlay.focus();
                // Auto-close modal after 2 seconds
                setTimeout(() => {
                    closeModal();
                    if (isSuccess) {
                        resetForm();
                    }
                }, 2000);
            }

            function closeModal() {
                modalOverlay.classList.remove('show');
            }

            // Close modal when clicking anywhere
            modalOverlay.addEventListener('click', function() {
                closeModal();
            });

            // Prevent clicks on modal content from closing the modal
            modalContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // Back Button Handler
            backBtn.addEventListener('click', function() {
                window.location.href = 'dashboard.php';
            });

            // Function to clear account number form
            function clearRekForm() {
                rekInputs.forEach(input => {
                    input.value = '';
                    input.classList.remove('filled');
                    input.dataset.originalValue = '';
                });
                hiddenNoRek.value = '';
            }

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

            function updateHiddenNoRek() {
                let noRek = prefix;
                rekInputs.forEach(input => {
                    noRek += input.dataset.originalValue || '';
                });
                hiddenNoRek.value = noRek;
            }

            // Amount Input Handling
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
                if (activeStep >= 3) {
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
                penyetoranForm.reset();
                clearRekForm();
                accountData = {};
                depositAmount = 0;
                showStep(1);
            }

            // Step 1: Check Account
            cekForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (hiddenNoRek.value.length !== 9 || !hiddenNoRek.value.startsWith('REK')) {
                    rekContainer.classList.add('error');
                    setTimeout(() => rekContainer.classList.remove('error'), 400);
                    showModal('Error', 'Nomor rekening harus 6 digit dengan prefix REK.', false);
                    clearRekForm();
                    return;
                }

                cekButton.classList.add('loading');
                fetch('proses_setor_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=cek_rekening&no_rekening=${encodeURIComponent(hiddenNoRek.value)}`
                })
                .then(response => response.json())
                .then(data => {
                    cekButton.classList.remove('loading');
                    if (data.status === 'success') {
                        accountData = data;
                        document.getElementById('displayNoRek').textContent = hiddenNoRek.value;
                        document.getElementById('displayNama').textContent = accountData.nama;
                        document.getElementById('displayJurusan').textContent = accountData.jurusan;
                        document.getElementById('displayKelas').textContent = accountData.kelas;
                        showStep(2);
                        inputJumlah.focus();
                    } else {
                        showModal('Error', data.message || 'Rekening tidak ditemukan.', false);
                        clearRekForm();
                    }
                })
                .catch(error => {
                    cekButton.classList.remove('loading');
                    showModal('Error', 'Terjadi kesalahan saat memeriksa rekening.', false);
                    clearRekForm();
                });
            });

            // Step 2: Input Amount
            confirmAmount.addEventListener('click', function() {
                confirmAmount.classList.add('loading');
                let amount = parseInt(inputJumlah.value.replace(/[^0-9]/g, '')) || 0;
                if (amount < 1000) {
                    confirmAmount.classList.remove('loading');
                    showModal('Error', 'Jumlah penyetoran minimal Rp 1.000.', false);
                    inputJumlah.focus();
                    return;
                }
                if (amount > 99999999) {
                    confirmAmount.classList.remove('loading');
                    showModal('Error', 'Jumlah penyetoran maksimal Rp 99.999.999.', false);
                    inputJumlah.focus();
                    return;
                }

                depositAmount = amount;
                document.getElementById('confirmNoRek').textContent = hiddenNoRek.value;
                document.getElementById('confirmNama').textContent = accountData.nama;
                document.getElementById('confirmJurusan').textContent = accountData.jurusan;
                document.getElementById('confirmKelas').textContent = accountData.kelas;
                document.getElementById('confirmJumlah').textContent = formatRupiah(amount);
                confirmAmount.classList.remove('loading');
                showStep(3);
            });

            cancelAmount.addEventListener('click', function() {
                cancelAmount.classList.add('loading');
                setTimeout(() => {
                    cancelAmount.classList.remove('loading');
                    resetForm();
                }, 500);
            });

            // Step 3: Process Deposit
            processDeposit.addEventListener('click', function() {
                processDeposit.classList.add('loading');
                fetch('proses_setor_admin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=setor_saldo&no_rekening=${encodeURIComponent(hiddenNoRek.value)}&jumlah=${depositAmount}`
                })
                .then(response => response.json())
                .then(data => {
                    processDeposit.classList.remove('loading');
                    if (data.status === 'success') {
                        showModal('Sukses', 'Penyetoran berhasil diproses.', true);
                    } else {
                        showModal('Error', data.message || 'Gagal memproses penyetoran.', false);
                    }
                })
                .catch(error => {
                    processDeposit.classList.remove('loading');
                    showModal('Error', 'Terjadi kesalahan saat memproses penyetoran.', false);
                });
            });

            backToAmount.addEventListener('click', function() {
                backToAmount.classList.add('loading');
                setTimeout(() => {
                    backToAmount.classList.remove('loading');
                    showStep(2);
                    inputJumlah.focus();
                }, 500);
            });
        });
    </script>
</body>
</html>