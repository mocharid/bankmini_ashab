<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cek Saldo - SCHOBANK SYSTEM</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
            --shadow-md: 0 5px 20px rgba(0, 0, 0, 0.1);
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
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navigation Styles */
        .top-nav {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .nav-brand i {
            font-size: 1.2rem;
        }

        .nav-buttons {
            display: flex;
            gap: 1rem;
        }

        .nav-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition);
            backdrop-filter: blur(5px);
        }

        .nav-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 2rem;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            margin-bottom: 2rem;
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
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.5rem;
            position: relative;
            z-index: 1;
        }

        /* Search Card */
        .search-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .search-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--primary-color), var(--primary-dark));
            transform: scaleX(0);
            transform-origin: right;
            transition: transform 0.3s ease;
        }

        .search-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-card:hover::after {
            transform: scaleX(1);
            transform-origin: left;
        }

        .search-form {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1.5rem;
            align-items: flex-end;
            max-width: 600px;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-secondary);
            font-weight: 500;
            transition: var(--transition);
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            border: 2px solid #ddd;
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: #fcfcfc;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            background-color: white;
        }

        .form-group i {
            position: absolute;
            left: 1rem;
            top: 2.3rem;
            color: var(--text-secondary);
            transition: var(--transition);
        }

        .form-group input:focus + i {
            color: var(--primary-color);
        }

        .search-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border: none;
            padding: 0.85rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            transition: var(--transition);
            font-weight: 500;
            box-shadow: 0 4px 10px rgba(12, 77, 162, 0.2);
            height: 50px;
            white-space: nowrap;
        }

        .search-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(12, 77, 162, 0.25);
        }

        /* Results Card */
        .results-card {
            background: white;
            border-radius: 15px;
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: fadeIn 0.5s ease-out;
        }
        
        .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Profile Info */
        .profile-info {
            padding: 1.5rem;
            background: var(--bg-light);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }

        .profile-info p {
            margin: 0.75rem 0;
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .profile-info p:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .profile-info p span:first-child {
            font-weight: 500;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-info p span:last-child {
            font-weight: 600;
            color: var(--text-primary);
        }

        /* Balance Display */
        .balance-display {
            padding: 2rem;
            background: linear-gradient(to right, #f8faff, #f0f5ff);
            border-radius: 12px;
            text-align: center;
            box-shadow: inset 0 2px 10px rgba(0,0,0,0.03);
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
            font-size: 1.1rem;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .balance-amount {
            font-size: 2.25rem;
            color: var(--primary-color);
            font-weight: 700;
            margin: 1rem 0;
            position: relative;
            display: inline-block;
            padding: 0.5rem 2rem;
            border-radius: 50px;
            background: white;
            box-shadow: var(--shadow-sm);
        }

        .balance-info {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 1rem;
        }

        /* Alert Styles */
        .alert {
            padding: 1.25rem;
            border-radius: 12px;
            margin-top: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: fadeIn 0.5s ease-out;
        }

        .alert-info {
            background: #e0f2fe;
            color: #0369a1;
            border-left: 5px solid #bae6fd;
        }

        .alert i {
            font-size: 1.25rem;
        }

        /* Loading Spinner */
        .loading {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(4px);
            justify-content: center;
            align-items: center;
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }

        .spinner-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(12, 77, 162, 0.1);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        .spinner-text {
            color: var(--primary-dark);
            font-weight: 500;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Floating Label */
        .floating-label {
            position: relative;
            width: 100%;
        }

        .floating-label input {
            width: 100%;
            padding: 1rem 1rem 1rem 2.75rem;
            border: 2px solid #ddd;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s;
            background-color: #fcfcfc;
        }

        .floating-label label {
            position: absolute;
            top: 50%;
            left: 2.75rem;
            transform: translateY(-50%);
            color: var(--text-secondary);
            transition: all 0.3s;
            pointer-events: none;
            font-size: 1rem;
        }

        .floating-label i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            transition: var(--transition);
        }

        .floating-label input:focus,
        .floating-label input:not(:placeholder-shown) {
            border-color: var(--primary-color);
            background-color: white;
            padding-top: 1.5rem;
            padding-bottom: 0.5rem;
        }

        .floating-label input:focus + label,
        .floating-label input:not(:placeholder-shown) + label {
            top: 25%;
            font-size: 0.75rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .floating-label input:focus ~ i {
            color: var(--primary-color);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .top-nav {
                padding: 0.75rem 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .welcome-banner, 
            .search-card,
            .results-card {
                padding: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .search-form {
                grid-template-columns: 1fr;
                width: 100%;
                gap: 1rem;
            }

            .search-btn {
                width: 100%;
                justify-content: center;
                margin-top: 0.5rem;
            }

            .balance-amount {
                font-size: 1.75rem;
                padding: 0.5rem 1.5rem;
            }
        }

        /* Form error animations */
        .form-error {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading" id="loadingSpinner">
        <div class="spinner-container">
            <div class="spinner"></div>
            <div class="spinner-text">Memuat data...</div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <i class="fas fa-university"></i>
            SCHOBANK
        </div>
        <div class="nav-buttons">
            <a href="dashboard.php" class="nav-btn">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-banner">
            <h2>
                <i class="fas fa-wallet"></i>
                <span>Cek Saldo Rekening</span>
            </h2>
        </div>

        <div class="search-card">
            <form id="searchForm" action="" method="GET" class="search-form">
                <div class="floating-label">
                    <input type="text" 
                           id="no_rekening" 
                           name="no_rekening" 
                           placeholder=" "
                           value="<?php echo isset($_GET['no_rekening']) ? htmlspecialchars($_GET['no_rekening']) : ''; ?>"
                           required>
                    <label for="no_rekening">Nomor Rekening</label>
                    <i class="fas fa-credit-card"></i>
                </div>
                <button type="submit" class="search-btn" id="searchBtn">
                    <i class="fas fa-search"></i>
                    <span>Cek Saldo</span>
                </button>
            </form>
        </div>

        <!-- Results Section -->
        <?php
        if (isset($_GET['no_rekening'])) {
            $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
            // Query untuk mengambil data nasabah beserta saldo
            $query = "SELECT users.nama, rekening.no_rekening, rekening.saldo, rekening.created_at 
                      FROM rekening 
                      JOIN users ON rekening.user_id = users.id 
                      WHERE rekening.no_rekening = '$no_rekening'";
            $result = $conn->query($query);

            echo '<div class="results-card" id="resultsContainer">'; // Tambahkan ID di sini
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                echo '<div class="profile-info">
                    <p><span><i class="fas fa-user"></i> Nama Nasabah</span> <span>' . htmlspecialchars($row['nama']) . '</span></p>
                    <p><span><i class="fas fa-credit-card"></i> No. Rekening</span> <span>' . htmlspecialchars($row['no_rekening']) . '</span></p>
                    <p><span><i class="fas fa-calendar-alt"></i> Tanggal Pembukaan</span> <span>' . date('d/m/Y', strtotime($row['created_at'] ?? 'now')) . '</span></p>
                </div>';
                
                echo '<div class="balance-display">
                    <div class="balance-label">Saldo Rekening Saat Ini</div>
                    <div class="balance-amount">Rp ' . number_format($row['saldo'], 2, ',', '.') . '</div>
                    <div class="balance-info"><i class="fas fa-info-circle"></i> Saldo diperbarui per ' . date('d/m/Y H:i') . ' WIB</div>
                </div>';
            } else {
                echo '<div class="alert alert-info" id="alertNotFound">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Rekening tidak ditemukan</strong><br>
                        Silakan periksa kembali nomor rekening yang dimasukkan.
                    </div>
                </div>';
            }
            echo '</div>'; // Tutup container results-card
        }
        ?>
    </div>

    <!-- JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
$(document).ready(function() {
    // Form handling
    $('#searchForm').on('submit', function(e) {
        const input = $('#no_rekening');
        const value = input.val().trim();
        
        if (!value) {
            e.preventDefault();
            input.addClass('form-error');
            setTimeout(() => input.removeClass('form-error'), 1000);
            input.focus();
            return false;
        }
        
        // Show loading spinner
        $('#loadingSpinner').css('display', 'flex');
        
        // Simulating a loading delay for better UX
        setTimeout(function() {
            $('#loadingSpinner').fadeOut(300);
        }, 800);
    });

    // Account number formatting
    $('#no_rekening').on('input', function() {
        this.value = this.value.toUpperCase().replace(/[^\dA-Z]/g, '');
        
        if (this.value.length > 20) {
            this.value = this.value.slice(0, 20);
        }
    });
    
    // Initialize input state for pre-filled values
    if ($('#no_rekening').val()) {
        $('#no_rekening').trigger('input');
    }
    
    // Remove loading on page load
    $(window).on('load', function() {
        $('#loadingSpinner').fadeOut(300);
    });

    // Auto-hide and remove the "Rekening tidak ditemukan" alert and its container after 5 seconds
    if ($('#alertNotFound').length) {
        setTimeout(function() {
            $('#alertNotFound').fadeOut(500, function() {
                $('#resultsContainer').remove(); // Hapus seluruh container notifikasi
            });
        }, 5000); // 5000 milliseconds = 5 seconds
    }
});
    </script>
</body>
</html>