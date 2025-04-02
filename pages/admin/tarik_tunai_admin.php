<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Cek role admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Admin';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Tarik Tunai - Admin | SCHOBANK SYSTEM</title>
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
            --warning-color: #ffc107;
            --text-primary: #333;
            --text-secondary: #666;
            --bg-light: #f8faff;
            --shadow-sm: 0 2px 10px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 5px 15px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
            --border-radius: 12px;
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
        
        .header-container {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px 25px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            overflow: hidden;
        }
        
        .header-content {
            flex: 1;
        }
        
        .header-container::before {
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
        
        .header-container h2 {
            font-size: 24px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }
        
        .header-container p {
            font-size: 14px;
            opacity: 0.9;
            position: relative;
        }

        .back-button {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition);
            margin-left: 15px;
            flex-shrink: 0;
            position: relative;
        }

        .back-button:hover {
            background-color: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .transaction-container {
            display: flex;
            gap: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .transaction-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            min-width: 300px;
            max-width: 450px;
            width: 100%;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .transaction-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--primary-dark));
        }

        .transaction-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .step-container {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            position: relative;
        }

        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            width: 25%;
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
            transition: var(--transition);
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
        input[type="number"],
        input[type="password"] {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: 16px;
            transition: var(--transition);
            letter-spacing: 0.5px;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="password"]:focus {
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

        .btn-warning {
            background-color: var(--warning-color);
            color: #000;
        }

        .btn-warning:hover {
            background-color: #e0a800;
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

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border-left: 5px solid #fde68a;
        }

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

        .pin-container {
            display: flex;
            justify-content: space-between;
            margin: 20px 0;
        }

        .pin-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            border: 2px solid #ddd;
            border-radius: 8px;
            margin: 0 5px;
            transition: var(--transition);
        }

        .pin-input:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 15px;
            }

            .header-container {
                padding: 15px;
                flex-direction: column;
                align-items: flex-start;
            }

            .back-button {
                position: absolute;
                top: 15px;
                right: 15px;
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

            .pin-container {
                justify-content: center;
            }

            .pin-input {
                width: 40px;
                height: 50px;
                font-size: 20px;
                margin: 0 3px;
            }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .shake {
            animation: shake 0.5s;
            border-color: var(--danger-color) !important;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="header-container">
            <div class="header-content">
                <h2><i class="fas fa-money-bill-wave"></i> Tarik Tunai - Admin</h2>
                <p>Proses penarikan tunai nasabah</p>
            </div>
            <a href="dashboard.php" class="back-button ripple">
                <i class="fas fa-arrow-left"></i>
            </a>
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
                        <div class="step-label">Verifikasi PIN</div>
                    </div>
                    <div class="step" id="step4">
                        <div class="step-number">4</div>
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
                        <button type="button" id="btnNextToPin" class="ripple">
                            <span class="btn-text">Lanjutkan <i class="fas fa-arrow-right"></i></span>
                        </button>
                    </div>
                </div>

                <!-- Form 3: PIN Verification -->
                <div class="input-form" id="form3">
                    <h3 style="margin-bottom: 20px; color: var(--primary-dark);">Verifikasi PIN Nasabah</h3>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nomor Rekening:</div>
                        <div class="detail-value" id="pin-norek">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value" id="pin-nama">-</div>
                    </div>
                    
                    <div class="detail-row">
                        <div class="detail-label">Jumlah Penarikan:</div>
                        <div class="detail-value" id="pin-jumlah" style="color: var(--danger-color);">-</div>
                    </div>
                    
                    <div class="input-group" style="margin-top: 20px;">
                        <label for="pin">Masukkan PIN Nasabah (6 digit):</label>
                        <div class="pin-container">
                            <input type="password" class="pin-input" maxlength="1" data-index="1" autocomplete="off">
                            <input type="password" class="pin-input" maxlength="1" data-index="2" autocomplete="off">
                            <input type="password" class="pin-input" maxlength="1" data-index="3" autocomplete="off">
                            <input type="password" class="pin-input" maxlength="1" data-index="4" autocomplete="off">
                            <input type="password" class="pin-input" maxlength="1" data-index="5" autocomplete="off">
                            <input type="password" class="pin-input" maxlength="1" data-index="6" autocomplete="off">
                        </div>
                        <input type="hidden" id="pin" name="pin">
                    </div>
                    
                    <div class="btn-actions">
                        <button type="button" id="btnBackToAmount" class="btn-back ripple">
                            <span class="btn-text"><i class="fas fa-arrow-left"></i> Kembali</span>
                        </button>
                        <button type="button" id="btnVerifyPin" class="ripple">
                            <span class="btn-text"><i class="fas fa-lock"></i> Verifikasi PIN</span>
                        </button>
                    </div>
                </div>

                <!-- Form 4: Confirmation -->
                <div class="input-form" id="form4">
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
                        <button type="button" id="btnBackToPin" class="btn-back ripple">
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
            <div class="button-container" style="margin-top: 20px;">
                <button type="button" id="btnPrintReceipt" class="btn-warning ripple" style="width: auto; padding: 10px 20px;">
                    <span class="btn-text"><i class="fas fa-print"></i> Cetak Struk</span>
                </button>
                <button type="button" id="btnNewTransaction" class="ripple" style="width: auto; padding: 10px 20px; margin-top: 10px;">
                    <span class="btn-text"><i class="fas fa-redo"></i> Transaksi Baru</span>
                </button>
            </div>
        </div>
    </div>

    <script>
document.addEventListener('DOMContentLoaded', function() {
    // Elements
    const form1 = document.getElementById('form1');
    const form2 = document.getElementById('form2');
    const form3 = document.getElementById('form3');
    const form4 = document.getElementById('form4');
    
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const step4 = document.getElementById('step4');
    
    const inputNoRek = document.getElementById('no_rekening');
    const inputJumlah = document.getElementById('jumlah_penarikan');
    const pinInputs = document.querySelectorAll('.pin-input');
    const hiddenPin = document.getElementById('pin');
    
    const btnNextToAmount = document.getElementById('btnNextToAmount');
    const btnBackToRekening = document.getElementById('btnBackToRekening');
    const btnNextToPin = document.getElementById('btnNextToPin');
    const btnBackToAmount = document.getElementById('btnBackToAmount');
    const btnVerifyPin = document.getElementById('btnVerifyPin');
    const btnBackToPin = document.getElementById('btnBackToPin');
    const btnConfirmWithdrawal = document.getElementById('btnConfirmWithdrawal');
    const btnPrintReceipt = document.getElementById('btnPrintReceipt');
    const btnNewTransaction = document.getElementById('btnNewTransaction');
    
    const summaryNoRek = document.getElementById('summary-norek');
    const summaryNama = document.getElementById('summary-nama');
    const summarySaldo = document.getElementById('summary-saldo');
    
    const pinNoRek = document.getElementById('pin-norek');
    const pinNama = document.getElementById('pin-nama');
    const pinJumlah = document.getElementById('pin-jumlah');
    
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
        user_id: 0,
        email: '',
        has_pin: false
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
    
    // PIN input handling
    pinInputs.forEach((input, index) => {
        input.addEventListener('input', function() {
            // Auto focus to next input
            if (this.value.length === 1 && index < pinInputs.length - 1) {
                pinInputs[index + 1].focus();
            }
            
            // Update hidden PIN field
            updateHiddenPin();
        });
        
        input.addEventListener('keydown', function(e) {
            // Handle backspace to move to previous input
            if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
                pinInputs[index - 1].focus();
            }
        });
    });
    
    function updateHiddenPin() {
        let pin = '';
        pinInputs.forEach(input => {
            pin += input.value;
        });
        hiddenPin.value = pin;
    }
    
    function clearPinInputs() {
        pinInputs.forEach(input => {
            input.value = '';
        });
        hiddenPin.value = '';
        pinInputs[0].focus();
    }
    
    // Step 1: Check No Rekening
    btnNextToAmount.addEventListener('click', function() {
        const rekening = inputNoRek.value.trim();
        
        if (!rekening) {
            showAlert('Silakan masukkan nomor rekening nasabah', 'error');
            return;
        }
        
        // Start loading animation
        btnNextToAmount.classList.add('btn-loading');
        
        // Make AJAX request to check account
        fetch('proses_tarik_admin.php', {
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
            accountData.email = data.email || '';
            accountData.has_pin = data.has_pin || false;
            
            // Display in summary
            summaryNoRek.textContent = data.no_rekening;
            summaryNama.textContent = data.nama;
            summarySaldo.textContent = formatRupiah(data.saldo);
            
            // Check if user has PIN
            if (!accountData.has_pin) {
                showAlert('Nasabah belum mengatur PIN, tidak dapat melakukan penarikan', 'error');
                return;
            }
            
            // Move to step 2
            step1.classList.remove('active');
            step1.classList.add('completed');
            step2.classList.add('active');
            
            form1.classList.remove('active');
            form2.classList.add('active');
            
            // Focus on amount input
            inputJumlah.focus();
        })
        .catch(error => {
            btnNextToAmount.classList.remove('btn-loading');
            showAlert('Terjadi kesalahan saat memverifikasi rekening', 'error');
            console.error(error);
        });
    });
    
    // Back to Step 1
    btnBackToRekening.addEventListener('click', function() {
        step2.classList.remove('active');
        step1.classList.remove('completed');
        step1.classList.add('active');
        
        form2.classList.remove('active');
        form1.classList.add('active');
        
        // Focus on rekening input
        inputNoRek.focus();
    });
    
    // Step 2: Verify Amount
    btnNextToPin.addEventListener('click', function() {
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
        btnNextToPin.classList.add('btn-loading');
        
        // Update account data
        accountData.jumlah = jumlah;
        
        // Display in PIN verification form
        pinNoRek.textContent = accountData.noRekening;
        pinNama.textContent = accountData.nama;
        pinJumlah.textContent = formatRupiah(accountData.jumlah);
        
        // Remove loading animation
        btnNextToPin.classList.remove('btn-loading');
        
        // Move to step 3
        step2.classList.remove('active');
        step2.classList.add('completed');
        step3.classList.add('active');
        
        form2.classList.remove('active');
        form3.classList.add('active');
        
        // Clear and focus on first PIN input
        clearPinInputs();
    });
    
    // Back to Step 2
    btnBackToAmount.addEventListener('click', function() {
        step3.classList.remove('active');
        step2.classList.remove('completed');
        step2.classList.add('active');
        
        form3.classList.remove('active');
        form2.classList.add('active');
        
        // Focus on amount input
        inputJumlah.focus();
    });
    
    // Step 3: Verify PIN
    btnVerifyPin.addEventListener('click', function() {
        const pin = hiddenPin.value;
        
        if (pin.length !== 6) {
            showAlert('PIN harus terdiri dari 6 digit', 'error');
            pinInputs.forEach(input => {
                input.classList.add('shake');
                setTimeout(() => input.classList.remove('shake'), 500);
            });
            return;
        }
        
        // Pastikan accountData.user_id ada dan valid
        if (!accountData.user_id || accountData.user_id <= 0) {
            showAlert('Data nasabah tidak valid, silakan ulangi proses', 'error');
            return;
        }

        // Start loading animation
        btnVerifyPin.classList.add('btn-loading');
        
        // Verify PIN via AJAX
        fetch('proses_tarik.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=verify_pin&user_id=${accountData.user_id}&pin=${pin}`
        })
        .then(response => response.json())
        .then(data => {
            // Remove loading animation
            btnVerifyPin.classList.remove('btn-loading');
            
            if (data.status === 'error') {
                showAlert(data.message, 'error');
                pinInputs.forEach(input => {
                    input.classList.add('shake');
                    setTimeout(() => input.classList.remove('shake'), 500);
                });
                clearPinInputs();
                return;
            }
            
            // Display in confirmation
            confirmNoRek.textContent = accountData.noRekening;
            confirmNama.textContent = accountData.nama;
            confirmSaldo.textContent = formatRupiah(accountData.saldo);
            confirmJumlah.textContent = formatRupiah(accountData.jumlah);
            confirmSisa.textContent = formatRupiah(accountData.saldo - accountData.jumlah);
            
            // Move to step 4
            step3.classList.remove('active');
            step3.classList.add('completed');
            step4.classList.add('active');
            
            form3.classList.remove('active');
            form4.classList.add('active');
        })
        .catch(error => {
            btnVerifyPin.classList.remove('btn-loading');
            showAlert('Terjadi kesalahan saat memverifikasi PIN', 'error');
            console.error(error);
        });
    });
    
    // Back to Step 3
    btnBackToPin.addEventListener('click', function() {
        step4.classList.remove('active');
        step3.classList.remove('completed');
        step3.classList.add('active');
        
        form4.classList.remove('active');
        form3.classList.add('active');
        
        // Focus on first PIN input
        pinInputs[0].focus();
    });
    
    // Step 4: Process Withdrawal
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
        })
        .catch(error => {
            btnConfirmWithdrawal.classList.remove('btn-loading');
            showAlert('Saldo Petugas Tidak Mencukupi Untuk Penarikan', 'error');
            console.error(error);
        });
    });
    
    // Print Receipt button
    btnPrintReceipt.addEventListener('click', function() {
        // In a real application, this would generate a printable receipt
        alert('Fitur cetak struk akan membuka jendela cetak. Di sini hanya simulasi.');
        window.print();
    });
    
    // New Transaction button
    btnNewTransaction.addEventListener('click', function() {
        // Reset the form and go back to step 1
        successOverlay.classList.remove('show');
        
        // Reset all forms
        inputNoRek.value = '';
        inputJumlah.value = '';
        clearPinInputs();
        
        // Reset account data
        accountData = {
            noRekening: '',
            nama: '',
            saldo: 0,
            jumlah: 0,
            rekening_id: 0,
            user_id: 0,
            email: '',
            has_pin: false
        };
        
        // Reset steps
        step4.classList.remove('active');
        step3.classList.remove('completed', 'active');
        step2.classList.remove('completed', 'active');
        step1.classList.remove('completed');
        step1.classList.add('active');
        
        // Reset forms
        form4.classList.remove('active');
        form3.classList.remove('active');
        form2.classList.remove('active');
        form1.classList.add('active');
        
        // Focus on rekening input
        inputNoRek.focus();
    });
    
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
        // Remove existing alerts
        while (alertContainer.firstChild) {
            alertContainer.removeChild(alertContainer.firstChild);
        }
        
        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        
        let icon = '';
        if (type === 'error') icon = '<i class="fas fa-exclamation-circle"></i>';
        else if (type === 'success') icon = '<i class="fas fa-check-circle"></i>';
        else if (type === 'info') icon = '<i class="fas fa-info-circle"></i>';
        else if (type === 'warning') icon = '<i class="fas fa-exclamation-triangle"></i>';
        
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
    inputNoRek.addEventListener('keyup', function(event) {
        if (event.key === 'Enter') {
            btnNextToAmount.click();
        }
    });
    
    inputJumlah.addEventListener('keyup', function(event) {
        if (event.key === 'Enter') {
            btnNextToPin.click();
        }
    });
    
    pinInputs.forEach(input => {
        input.addEventListener('keyup', function(event) {
            if (event.key === 'Enter') {
                btnVerifyPin.click();
            }
        });
    });
    
    // Close success overlay on click
    successOverlay.addEventListener('click', function(e) {
        if (e.target === successOverlay) {
            successOverlay.classList.remove('show');
        }
    });
    
    // Prevent click propagation from modal to overlay
    const successModal = document.querySelector('.success-modal');
    if (successModal) {
        successModal.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }
});
    </script>
</body>
</html>