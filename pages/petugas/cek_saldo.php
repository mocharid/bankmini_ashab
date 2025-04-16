<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Saldo - SCHOBANK SYSTEM</title>
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

        .deposit-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .deposit-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
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

        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
        }

        input[type="text"]:focus {
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

        .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
            animation: slideStep 0.5s ease-in-out;
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
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

        .balance-display {
            padding: 25px;
            background: linear-gradient(to right, #f8faff, #f0f5ff);
            border-radius: 12px;
            text-align: center;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.03);
            margin-top: 20px;
            position: relative;
            overflow: hidden;
        }

        .balance-display::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 0%, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0) 70%);
            pointer-events: none;
        }

        .balance-label {
            color: var(--text-secondary);
            font-size: clamp(1rem, 2vw, 1.1rem);
            margin-bottom: 15px;
            font-weight: 500;
        }

        .balance-amount {
            font-size: clamp(2rem, 4vw, 2.25rem);
            color: var(--primary-color);
            font-weight: 700;
            margin: 15px 0;
            position: relative;
            display: inline-block;
            padding: 8px 25px;
            border-radius: 50px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .balance-info {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
            margin-top: 15px;
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

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-error {
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
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
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .deposit-card,
            .results-card {
                padding: 20px;
                margin-bottom: 20px;
            }

            .deposit-form {
                gap: 15px;
            }

            .section-title {
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            input[type="text"] {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            button {
                padding: 10px 20px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                width: 100%;
                justify-content: center;
            }

            .detail-row {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
                grid-template-columns: 1fr 1.5fr;
            }

            .balance-label {
                font-size: clamp(0.9rem, 2vw, 1rem);
            }

            .balance-amount {
                font-size: clamp(1.75rem, 3.5vw, 2rem);
                padding: 6px 20px;
            }

            .balance-info {
                font-size: clamp(0.8rem, 1.8vw, 0.85rem);
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                margin-top: 15px;
            }
        }

        @media (max-width: 480px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(0.9rem, 2.5vw, 1.1rem);
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner {
                padding: 15px;
                margin-bottom: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 2vw, 0.85rem);
            }

            .deposit-card,
            .results-card {
                padding: 15px;
                margin-bottom: 15px;
            }

            .deposit-form {
                gap: 12px;
            }

            .section-title {
                font-size: clamp(0.9rem, 2.5vw, 1rem);
            }

            input[type="text"] {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            button {
                padding: 8px 15px;
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .detail-row {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .balance-label {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .balance-amount {
                font-size: clamp(1.5rem, 3vw, 1.75rem);
                padding: 5px 15px;
            }

            .balance-info {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }

            .alert {
                font-size: clamp(0.75rem, 1.8vw, 0.85rem);
                margin-top: 12px;
            }
        }
    </style>
</head>
<body>
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-arrow-left"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2><i class="fas fa-wallet"></i> Cek Saldo</h2>
            <p>Cek saldo rekening nasabah dengan mudah dan cepat</p>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Masukkan Nomor Rekening</h3>
            <form id="searchForm" action="" method="GET" class="deposit-form">
                <div>
                    <label for="no_rekening">No Rekening:</label>
                    <input type="text" id="no_rekening" name="no_rekening" placeholder="REK..." required autofocus
                           value="<?php echo isset($_GET['no_rekening']) ? htmlspecialchars($_GET['no_rekening']) : 'REK'; ?>">
                </div>
                <button type="submit" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cek Saldo</span>
                </button>
            </form>
        </div>

        <div id="alertContainer"></div>

        <?php
        if (isset($_GET['no_rekening'])) {
            $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
            $query = "SELECT users.nama, rekening.no_rekening, rekening.saldo, rekening.created_at 
                      FROM rekening 
                      JOIN users ON rekening.user_id = users.id 
                      WHERE rekening.no_rekening = '$no_rekening'";
            $result = $conn->query($query);

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                ?>
                <div class="results-card" id="resultsContainer">
                    <h3 class="section-title"><i class="fas fa-user-circle"></i> Detail Rekening</h3>
                    <div class="detail-row">
                        <div class="detail-label">Nama Nasabah:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['nama']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">No. Rekening:</div>
                        <div class="detail-value"><?php echo htmlspecialchars($row['no_rekening']); ?></div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Tanggal Pembukaan:</div>
                        <div class="detail-value"><?php echo date('d/m/Y', strtotime($row['created_at'] ?? 'now')); ?></div>
                    </div>
                    <div class="balance-display">
                        <div class="balance-label">Saldo Rekening Saat Ini</div>
                        <div class="balance-amount">Rp <?php echo number_format($row['saldo'], 2, ',', '.'); ?></div>
                        <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per <?php echo date('d/m/Y H:i'); ?> WIB</div>
                    </div>
                </div>
                <?php
            } else {
                ?>
                <div class="results-card" id="resultsContainer">
                    <div class="alert alert-error" id="alertNotFound">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>Rekening tidak ditemukan. Silakan periksa kembali nomor rekening.</span>
                    </div>
                </div>
                <?php
            }
        }
        ?>
    </div>

    <script>
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
            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const inputNoRek = document.getElementById('no_rekening');
            const alertContainer = document.getElementById('alertContainer');
            const prefix = "REK";

            // Initialize input with prefix
            if (!inputNoRek.value) {
                inputNoRek.value = prefix;
            }

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

            searchForm.addEventListener('submit', function(e) {
                const rekening = inputNoRek.value.trim();
                if (rekening === prefix) {
                    e.preventDefault();
                    showAlert('Silakan masukkan nomor rekening lengkap', 'error');
                    inputNoRek.classList.add('form-error');
                    setTimeout(() => inputNoRek.classList.remove('form-error'), 400);
                    inputNoRek.focus();
                    return;
                }
                searchBtn.classList.add('btn-loading');
                setTimeout(() => {
                    searchBtn.classList.remove('btn-loading');
                }, 800);
            });

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

            // Auto-hide and remove the "Rekening tidak ditemukan" alert and its container
            const alertNotFound = document.getElementById('alertNotFound');
            if (alertNotFound) {
                setTimeout(() => {
                    const resultsContainer = document.getElementById('resultsContainer');
                    alertNotFound.classList.add('hide');
                    setTimeout(() => {
                        if (resultsContainer) resultsContainer.remove();
                    }, 500);
                }, 5000);
            }

            inputNoRek.focus();
            if (inputNoRek.value === prefix) {
                inputNoRek.setSelectionRange(prefix.length, prefix.length);
            }

            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') searchBtn.click();
            });
        });
    </script>
</body>
</html>