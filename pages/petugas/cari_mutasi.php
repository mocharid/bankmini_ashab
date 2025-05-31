<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['petugas', 'admin'])) {
    header('Location: ../login.php');
    exit();
}

$username = $_SESSION['username'] ?? 'Petugas';

// Pagination settings
$per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $per_page;

// Flag for error popup
$show_error_popup = false;
$error_message = '';
$mutations_found = false; // Flag to track if mutations are found
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Mutasi - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1e3a8a;
            --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6;
            --text-primary: #2d3748;
            --text-secondary: #4a5568;
            --bg-light: #f7fafc;
            --bg-table: #ffffff;
            --border-color: #e2e8f0;
            --error-color: #e74c3c;
            --success-color: #15803d;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
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

        .top-nav h1 {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
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
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
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

        .form-section {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .form-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }

        .form-column {
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input {
            width: 100%;
            padding: 12px 15px 12px 60px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
        }

        input[type="date"] {
            padding: 12px 15px 12px 15px;
        }

        input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .input-container {
            position: relative;
            width: 100%;
        }

        .input-prefix {
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            pointer-events: none;
            z-index: 2;
            display: inline-block;
            line-height: 1;
        }

        .error-message {
            color: var(--error-color);
            font-size: clamp(0.8rem, 1.5vw, 0.85rem);
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            position: relative;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-color) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .btn:active {
            transform: scale(0.95);
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
            font-size: clamp(0.9rem, 2vw, 1rem);
            position: absolute;
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .form-buttons {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            width: 100%;
        }

        .results-card {
            background: var(--bg-table);
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .results-card:hover {
            box-shadow: var(--shadow-md);
        }

        .account-info-card {
            background: #f1f5f9;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            animation: fadeInCard 0.8s ease-out;
            transition: var(--transition);
        }

        .account-info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .account-info-card .info-text {
            flex: 1;
        }

        .account-info-card h3 {
            font-size: clamp(1.4rem, 2.8vw, 1.6rem);
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: 0.02em;
            color: var(--text-primary);
        }

        .account-info-card p {
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            font-weight: 400;
            color: var(--text-secondary);
        }

        @keyframes fadeInCard {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .table-responsive {
            overflow-x: auto;
            margin: 0;
            border-radius: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-table);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 400;
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
            vertical-align: middle;
        }

        th {
            background: var(--primary-dark);
            color: white;
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            text-transform: capitalize;
        }

        td {
            background: var(--bg-table);
            font-weight: 400;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .trans-type {
            font-weight: 400;
            text-transform: capitalize;
            color: var(--text-secondary);
        }

        .amount.credit {
            color: var(--success-color);
            text-align: right;
            font-weight: 400;
        }

        .amount.debit {
            color: var(--error-color);
            text-align: right;
            font-weight: 400;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        tbody tr:hover {
            background: #f1f5f9;
        }

        .results-count {
            margin-bottom: 15px;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-style: italic;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .pagination a, .pagination span {
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            color: var(--text-primary);
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            transition: var(--transition);
            cursor: pointer;
        }

        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .pagination .active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            font-weight: 500;
        }

        .pagination .disabled {
            background: #f7fafc;
            color: #a0aec0;
            border-color: #e2e8f0;
            cursor: not-allowed;
            pointer-events: none;
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
            animation: fadeInOverlay 0.4s ease-in-out forwards;
            cursor: pointer;
        }

        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes fadeOutOverlay {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        .error-modal {
            position: relative;
            text-align: center;
            width: clamp(280px, 70vw, 360px);
            background: linear-gradient(135deg, var(--error-color) 0%, #c0392b 100%);
            border-radius: 12px;
            padding: clamp(15px, 3vw, 20px);
            box-shadow: var(--shadow-md);
            transform: scale(0.8);
            opacity: 0;
            animation: popInModal 0.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            cursor: pointer;
            overflow: hidden;
        }

        .error-modal::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(255, 255, 255, 0.2) 0%, rgba(255, 255, 255, 0) 70%);
            pointer-events: none;
        }

        @keyframes popInModal {
            0% { transform: scale(0.8); opacity: 0; }
            80% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(1); opacity: 1; }
        }

        .error-icon {
            font-size: clamp(2rem, 4.5vw, 2.5rem);
            margin: 0 auto 10px;
            color: white;
            animation: bounceIn 0.5s ease-out;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); opacity: 1; }
        }

        .error-modal h3 {
            color: white;
            margin: 0 0 8px;
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .error-modal p {
            color: white;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            margin: 0;
            line-height: 1.5;
            padding: 0 10px;
        }

        .date-header {
            background: var(--primary-dark);
            color: white;
            padding: 8px 12px;
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-radius: 8px;
            margin: 8px 0;
            text-align: center;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.3rem, 3vw, 1.6rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.9rem);
            }

            .form-container {
                flex-direction: column;
            }

            .form-column {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            th, td {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .table-responsive {
                margin: 0;
            }

            .pagination {
                flex-wrap: wrap;
                gap: 8px;
            }

            .pagination a, .pagination span {
                padding: 6px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .error-modal {
                width: clamp(260px, 80vw, 340px);
                padding: clamp(12px, 3vw, 15px);
            }

            .error-icon {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .error-modal h3 {
                font-size: clamp(0.9rem, 1.9vw, 1rem);
            }

            .error-modal p {
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .account-info-card {
                padding: 15px;
            }

            .account-info-card h3 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }

            .account-info-card p {
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .date-header {
                padding: 6px 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 20px;
            }

            input {
                min-height: 40px;
            }

            th, td {
                padding: 6px 8px;
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }

            .error-modal {
                width: clamp(240px, 85vw, 320px);
                padding: clamp(10px, 2.5vw, 12px);
            }

            .error-icon {
                font-size: clamp(1.6rem, 3.5vw, 1.8rem);
            }

            .error-modal h3 {
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            }

            .error-modal p {
                font-size: clamp(0.7rem, 1.6vw, 0.8rem);
            }

            .account-info-card h3 {
                font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            }

            .account-info-card p {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .date-header {
                padding: 6px 8px;
                font-size: clamp(0.75rem, 1.7vw, 0.85rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
            <h2>Cek Mutasi Rekening</h2>
            <p>Lihat riwayat transaksi rekening dalam 30 hari terakhir dengan mudah</p>
        </div>

        <div id="alertContainer"></div>

        <div class="form-section">
            <form id="searchForm" action="" method="GET" class="form-container">
                <div class="form-column">
                    <div class="form-group">
                        <label for="no_rekening">Nomor Rekening</label>
                        <div class="input-container">
                            <span class="input-prefix">REK</span>
                            <input type="text" id="no_rekening" class="with-prefix" inputmode="numeric" pattern="[0-9]*" required placeholder="6 digit angka" maxlength="6" autocomplete="off" value="<?php echo isset($_GET['no_rekening']) && preg_match('/^REK[0-9]{6}$/', $_GET['no_rekening']) ? substr(htmlspecialchars($_GET['no_rekening']), 3) : ''; ?>">
                            <input type="hidden" id="no_rekening_hidden" name="no_rekening" value="<?php echo isset($_GET['no_rekening']) ? htmlspecialchars($_GET['no_rekening']) : ''; ?>">
                        </div>
                        <span class="error-message" id="no_rekening-error"></span>
                    </div>
                    <?php if ($mutations_found): ?>
                    <div class="form-group">
                        <label for="start_date">Tanggal Mulai</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : ''; ?>">
                        <span class="error-message" id="start_date-error"></span>
                    </div>
                    <div class="form-group">
                        <label for="end_date">Tanggal Selesai</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : ''; ?>">
                        <span class="error-message" id="end_date-error"></span>
                    </div>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="form_submitted" value="1">
                <div class="form-buttons">
                    <button type="submit" class="btn" id="searchBtn">
                        <span class="btn-content"><i class="fas fa-search"></i> Cari</span>
                    </button>
                </div>
            </form>
        </div>

        <div id="results">
            <?php
            if (isset($_GET['form_submitted']) && isset($_GET['no_rekening']) && !empty($_GET['no_rekening'])) {
                $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
                $start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : null;
                $end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : null;

                // Validate account number format
                if (!preg_match('/^REK[0-9]{6}$/', $no_rekening)) {
                    $show_error_popup = true;
                    $error_message = 'No Rekening Tidak Valid. Format: REK diikuti 6 digit angka';
                } elseif ($start_date && $end_date && strtotime($start_date) > strtotime($end_date)) {
                    $show_error_popup = true;
                    $error_message = 'Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai';
                } elseif ($start_date && strtotime($start_date) > time()) {
                    $show_error_popup = true;
                    $error_message = 'Tanggal Mulai tidak boleh di masa depan';
                } elseif ($end_date && strtotime($end_date) > time()) {
                    $show_error_popup = true;
                    $error_message = 'Tanggal Selesai tidak boleh di masa depan';
                } else {
                    // Check if account exists
                    $check_query = "SELECT r.*, u.nama 
                                   FROM rekening r 
                                   JOIN users u ON r.user_id = u.id 
                                   WHERE r.no_rekening = '$no_rekening'";
                    $check_result = $conn->query($check_query);

                    if (!$check_result) {
                        $show_error_popup = true;
                        $error_message = 'Error saat memeriksa rekening: ' . htmlspecialchars($conn->error);
                    } elseif ($check_result->num_rows === 0) {
                        $show_error_popup = true;
                        $error_message = 'No Rekening Tidak Ditemukan';
                    } else {
                        $account = $check_result->fetch_assoc();
                        $where_clause = "r.no_rekening = '$no_rekening'";
                        
                        // Apply date range filter if provided
                        if ($start_date && $end_date) {
                            $where_clause .= " AND DATE(m.created_at) BETWEEN '$start_date' AND '$end_date'";
                        } else {
                            $where_clause .= " AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
                        }
                        
                        // Count total records for pagination
                        $count_query = "SELECT COUNT(*) as total 
                                       FROM mutasi m 
                                       JOIN rekening r ON m.rekening_id = r.id 
                                       WHERE $where_clause";
                        $count_result = $conn->query($count_query);
                        $total_rows = $count_result ? $count_result->fetch_assoc()['total'] : 0;
                        $total_pages = ceil($total_rows / $per_page);

                        // Fetch paginated records with transaction type
                        $query = "SELECT m.*, r.no_rekening, DATE(m.created_at) as trans_date, t.jenis_transaksi
                                 FROM mutasi m 
                                 JOIN rekening r ON m.rekening_id = r.id 
                                 LEFT JOIN transaksi t ON m.transaksi_id = t.id 
                                 WHERE $where_clause 
                                 ORDER BY m.created_at DESC 
                                 LIMIT $offset, $per_page";
                        $result = $conn->query($query);

                        echo '<div class="results-card">';
                        echo '<div class="account-info-card">';
                        echo '<div class="info-text">';
                        echo '<h3>No Rekening: ' . htmlspecialchars($no_rekening) . '</h3>';
                        echo '<p>Pemilik: ' . htmlspecialchars($account['nama']) . '</p>';
                        echo '</div>';
                        echo '</div>';
                        
                        if ($result && $result->num_rows > 0) {
                            $mutations_found = true; // Set flag to show date inputs
                            echo '<div class="results-count">Menampilkan ' . $result->num_rows . ' dari ' . $total_rows . ' transaksi';
                            if ($start_date && $end_date) {
                                echo ' (dari ' . date('d/m/Y', strtotime($start_date)) . ' hingga ' . date('d/m/Y', strtotime($end_date)) . ')';
                            } else {
                                echo ' (30 hari terakhir)';
                            }
                            echo '</div>';
                            echo '<div class="table-responsive">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>No</th>
                                                <th>Jenis</th>
                                                <th>Jumlah</th>
                                                <th>Waktu</th>
                                            </tr>
                                        </thead>
                                        <tbody>';
                            
                            $current_date = '';
                            $counter = 1;
                            while ($row = $result->fetch_assoc()) {
                                $trans_date = date('d/m/Y', strtotime($row['trans_date']));
                                if ($trans_date !== $current_date) {
                                    echo '<tr><td colspan="4" class="date-header">' . $trans_date . '</td></tr>';
                                    $current_date = $trans_date;
                                    $counter = 1;
                                }
                                $transType = $row['jenis_transaksi'] ?? 'Lainnya';
                                $amountClass = $row['jumlah'] >= 0 ? 'credit' : 'debit';
                                echo "<tr>
                                        <td>$counter</td>
                                        <td class='trans-type'>" . htmlspecialchars(ucfirst($transType)) . "</td>
                                        <td class='amount $amountClass'>Rp " . number_format(abs($row['jumlah']), 2, ',', '.') . "</td>
                                        <td>" . date('H:i', strtotime($row['created_at'])) . "</td>
                                      </tr>";
                                $counter++;
                            }
                            echo '</tbody></table>';

                            // Pagination links
                            if ($total_pages > 1) {
                                echo '<div class="pagination">';
                                $base_url = '?no_rekening=' . urlencode($no_rekening) . '&form_submitted=1';
                                if ($start_date) $base_url .= '&start_date=' . urlencode($start_date);
                                if ($end_date) $base_url .= '&end_date=' . urlencode($end_date);

                                // Previous
                                if ($page > 1) {
                                    echo '<a href="' . $base_url . '&page=' . ($page - 1) . '"><i class="fas fa-chevron-left"></i></a>';
                                } else {
                                    echo '<span class="disabled"><i class="fas fa-chevron-left"></i></span>';
                                }

                                // Current page
                                echo '<span class="active">' . $page . '</span>';

                                // Next
                                if ($page < $total_pages) {
                                    echo '<a href="' . $base_url . '&page=' . ($page + 1) . '"><i class="fas fa-chevron-right"></i></a>';
                                } else {
                                    echo '<span class="disabled"><i class="fas fa-chevron-right"></i></span>';
                                }
                                echo '</div>';
                            }
                            echo '</div>'; // Close table-responsive
                        } else {
                            $show_error_popup = true;
                            $error_message = 'Tidak ada mutasi untuk rekening ini';
                            if ($start_date && $end_date) {
                                $error_message .= ' pada periode ' . date('d/m/Y', strtotime($start_date)) . ' hingga ' . date('d/m/Y', strtotime($end_date));
                            } else {
                                $error_message .= ' dalam 30 hari terakhir';
                            }
                        }
                        echo '</div>'; // Close results-card
                    }
                }
            }
            ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Prevent zooming and double-tap issues
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

            const searchForm = document.getElementById('searchForm');
            const searchBtn = document.getElementById('searchBtn');
            const inputNoRek = document.getElementById('no_rekening');
            const hiddenNoRek = document.getElementById('no_rekening_hidden');
            const alertContainer = document.getElementById('alertContainer');
            const prefix = "REK";

            // Initialize inputs
            if (!inputNoRek.value) {
                inputNoRek.value = '';
                hiddenNoRek.value = '';
            }

            // Handle rekening input
            inputNoRek.addEventListener('input', function() {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value.length > 6) value = value.slice(0, 6);
                this.value = value;
                hiddenNoRek.value = value ? prefix + value : '';
                document.getElementById('no_rekening-error').classList.remove('show');
            });

            inputNoRek.addEventListener('paste', function(e) {
                e.preventDefault();
                let pastedData = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '').slice(0, 6);
                this.value = pastedData;
                hiddenNoRek.value = pastedData ? prefix + pastedData : '';
            });

            inputNoRek.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.selectionStart <= 0) {
                    e.preventDefault();
                }
            });

            // Search form handling
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (searchForm.classList.contains('submitting')) return;
                searchForm.classList.add('submitting');

                const rekening = hiddenNoRek.value.trim();
                const startDate = document.getElementById('start_date');
                const endDate = document.getElementById('end_date');
                const start = startDate ? startDate.value : '';
                const end = endDate ? endDate.value : '';

                // Validate account number
                if (!rekening || rekening.length !== 9 || !/^REK[0-9]{6}$/.test(rekening)) {
                    showAlert('No Rekening Tidak Valid. Format: REK diikuti 6 digit angka', 'error');
                    inputNoRek.focus();
                    document.getElementById('no_rekening-error').classList.add('show');
                    document.getElementById('no_rekening-error').textContent = 'No Rekening Tidak Valid. Format: REK diikuti 6 digit angka';
                    inputNoRek.value = '';
                    hiddenNoRek.value = '';
                    if (startDate) startDate.value = '';
                    if (endDate) endDate.value = '';
                    searchForm.classList.remove('submitting');
                    return;
                }

                // Validate date range if date inputs exist
                if (startDate && endDate && start && end) {
                    const startTime = new Date(start).getTime();
                    const endTime = new Date(end).getTime();
                    const now = new Date().setHours(0, 0, 0, 0);

                    if (startTime > endTime) {
                        showAlert('Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai', 'error');
                        startDate.focus();
                        document.getElementById('start_date-error').classList.add('show');
                        document.getElementById('start_date-error').textContent = 'Tanggal Mulai tidak boleh lebih besar dari Tanggal Selesai';
                        searchForm.classList.remove('submitting');
                        return;
                    }
                    if (startTime > now) {
                        showAlert('Tanggal Mulai tidak boleh di masa depan', 'error');
                        startDate.focus();
                        document.getElementById('start_date-error').classList.add('show');
                        document.getElementById('start_date-error').textContent = 'Tanggal Mulai tidak boleh di masa depan';
                        searchForm.classList.remove('submitting');
                        return;
                    }
                    if (endTime > now) {
                        showAlert('Tanggal Selesai tidak boleh di masa depan', 'error');
                        endDate.focus();
                        document.getElementById('end_date-error').classList.add('show');
                        document.getElementById('end_date-error').textContent = 'Tanggal Selesai tidak boleh di masa depan';
                        searchForm.classList.remove('submitting');
                        return;
                    }
                } else if (start || end) {
                    showAlert('Harap masukkan kedua tanggal (Mulai dan Selesai) atau kosongkan keduanya', 'error');
                    (start ? endDate : startDate).focus();
                    document.getElementById(start ? 'end_date-error' : 'start_date-error').classList.add('show');
                    document.getElementById(start ? 'end_date-error' : 'start_date-error').textContent = 'Harap masukkan kedua tanggal';
                    searchForm.classList.remove('submitting');
                    return;
                }

                searchBtn.classList.add('loading');
                searchBtn.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';
                setTimeout(() => {
                    searchForm.submit();
                }, 1000);
            });

            // Show error popup
            <?php if ($show_error_popup): ?>
                setTimeout(() => {
                    showAlert('<?php echo addslashes($error_message); ?>', 'error');
                    document.getElementById('no_rekening').value = '';
                    document.getElementById('no_rekening_hidden').value = '';
                    const startDate = document.getElementById('start_date');
                    const endDate = document.getElementById('end_date');
                    if (startDate) startDate.value = '';
                    if (endDate) endDate.value = '';
                }, 500);
            <?php endif; ?>

            // Alert function
            function showAlert(message, type) {
                const existingAlerts = alertContainer.querySelectorAll('.modal-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 400);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'modal-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-exclamation-circle"></i>
                        </div>
                        <h3>Gagal</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                alertDiv.id = 'alert-' + Date.now();
                alertDiv.addEventListener('click', () => {
                    closeModal(alertDiv.id);
                });
                setTimeout(() => closeModal(alertDiv.id), 5000);
            }

            // Modal close handling
            function closeModal(modalId) {
                const modal = document.getElementById(modalId);
                if (modal) {
                    modal.style.animation = 'fadeOutOverlay 0.4s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 400);
                }
            }

            // Animate results
            const accountCard = document.querySelector('.account-info-card');
            if (accountCard) {
                accountCard.style.opacity = '0';
                accountCard.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    accountCard.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    accountCard.style.opacity = '1';
                    accountCard.style.transform = 'translateY(0)';
                }, 100);
            }

            const tableRows = document.querySelectorAll('tbody tr:not(.date-header)');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.transform = 'translateY(10px)';
                setTimeout(() => {
                    row.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                }, index * 80);
            });

            // Enter key support
            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchBtn.click();
                }
            });

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });
        });
    </script>
</body>
</html>