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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Mutasi - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.css">
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

        .search-card, .filter-section, .results-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            transition: var(--transition);
        }

        .search-card:hover, .filter-section:hover, .results-card:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .search-form, .filter-form {
            display: grid;
            gap: 20px;
        }

        .filter-section {
            display: none;
        }

        .filter-section.visible {
            display: block;
            animation: slideStep 0.5s ease-in-out;
        }

        @keyframes slideStep {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .date-inputs {
            display: grid;
            gap: 20px;
            grid-template-columns: 1fr 1fr;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="text"], input[type="tel"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            -webkit-text-size-adjust: none;
            background: white;
        }

        input[type="text"]:focus, input[type="tel"]:focus {
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

        .filter-btn {
            background-color: var(--accent-color);
        }

        .filter-btn:hover {
            background-color: #e08600;
        }

        .reset-btn {
            background-color: var(--danger-color);
        }

        .reset-btn:hover {
            background-color: #d32f2f;
        }

        .filter-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            padding: 10px 15px;
            background: var(--primary-light);
            border-radius: 10px;
            cursor: pointer;
            width: fit-content;
            color: var(--primary-dark);
            font-weight: 500;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
        }

        .filter-toggle:hover {
            background: #d0ddef;
            transform: translateY(-2px);
        }

        .table-responsive {
            overflow-x: auto;
            margin: 0 -15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background: var(--primary-light);
            font-weight: 600;
            color: var(--primary-dark);
        }

        tbody tr {
            transition: var(--transition);
        }

        tbody tr:hover {
            background: var(--primary-light);
        }

        .amount {
            font-weight: 600;
        }

        .positive {
            color: var(--secondary-color);
        }

        .negative {
            color: var(--danger-color);
        }

        .results-count {
            margin-bottom: 15px;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
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
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-primary);
            background: var(--primary-light);
            transition: var(--transition);
            cursor: pointer;
        }

        .pagination a:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .pagination .active {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }

        .pagination .disabled {
            background: #eee;
            color: #aaa;
            cursor: not-allowed;
            pointer-events: none;
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

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
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
            min-width: 100px;
        }

        .btn-loading .btn-content {
            visibility: hidden;
        }

        .btn-loading::after {
            content: ". . .";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            animation: dots 1.5s infinite;
            white-space: nowrap;
        }

        @keyframes dots {
            0% { content: "."; }
            33% { content: ". ."; }
            66% { content: ". . ."; }
            100% { content: "."; }
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

        /* Flatpickr Custom Theme */
        .flatpickr-calendar {
            background: white;
            border-radius: 10px;
            box-shadow: var(--shadow-md);
            font-family: 'Poppins', sans-serif;
        }

        .flatpickr-day.selected, .flatpickr-day.startRange, .flatpickr-day.endRange {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .flatpickr-day.today {
            border-color: var(--secondary-color);
        }

        .flatpickr-monthDropdown-months, .flatpickr-year {
            font-weight: 500;
            color: var(--primary-dark);
        }

        .flatpickr-current-month {
            color: var(--primary-dark);
        }

        .flatpickr-day:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
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

            .search-card, .filter-section, .results-card {
                padding: 20px;
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

            .search-form, .filter-form {
                gap: 15px;
            }

            .date-inputs {
                grid-template-columns: 1fr;
            }

            input[type="text"], input[type="tel"] {
                padding: 10px 12px;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            button {
                width: 100%;
                justify-content: center;
                font-size: clamp(0.85rem, 2vw, 0.95rem);
            }

            th, td {
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .alert {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                flex-wrap: wrap;
                gap: 8px;
            }

            .pagination a, .pagination span {
                padding: 6px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .success-modal {
                width: 90%;
                padding: 30px;
            }

            .success-icon {
                font-size: clamp(3.5rem, 7vw, 4rem);
            }

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2vw, 1rem);
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
            <h2><i class="fas fa-list-alt"></i> Cek Mutasi Rekening</h2>
            <p>Lihat riwayat transaksi rekening dengan mudah</p>
        </div>

        <div class="search-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Cari Mutasi</h3>
            <form id="searchForm" action="" method="GET" class="search-form">
                <div>
                    <label for="no_rekening">No Rekening:</label>
                    <input type="tel" id="no_rekening" name="no_rekening" placeholder="REK..." 
                           inputmode="numeric"
                           value="<?php echo isset($_GET['no_rekening']) ? htmlspecialchars($_GET['no_rekening']) : ''; ?>" required>
                </div>
                <button type="submit" id="searchBtn">
                    <span class="btn-content">
                        <i class="fas fa-search"></i>
                        <span>Cari</span>
                    </span>
                </button>
            </form>
        </div>

        <?php if (isset($_GET['no_rekening']) && !empty($_GET['no_rekening'])): ?>
            <div class="filter-toggle" id="filterToggle">
                <i class="fas fa-filter"></i>
                <span>Filter Berdasarkan Tanggal</span>
            </div>

            <div class="filter-section" id="filterSection" <?php echo (isset($_GET['start_date']) || isset($_GET['end_date'])) ? 'style="display: block;"' : ''; ?>>
                <h3 class="section-title"><i class="fas fa-calendar-alt"></i> Filter Tanggal</h3>
                <form id="filterForm" action="" method="GET" class="filter-form">
                    <input type="hidden" name="no_rekening" value="<?php echo htmlspecialchars($_GET['no_rekening']); ?>">
                    <div>
                        <label>Rentang Tanggal:</label>
                        <div class="date-inputs">
                            <div>
                                <input type="text" id="start_date" name="start_date" placeholder="Tanggal Awal"
                                       value="<?php echo isset($_GET['start_date']) ? htmlspecialchars($_GET['start_date']) : ''; ?>" 
                                       class="datepicker" readonly>
                            </div>
                            <div>
                                <input type="text" id="end_date" name="end_date" placeholder="Tanggal Akhir"
                                       value="<?php echo isset($_GET['end_date']) ? htmlspecialchars($_GET['end_date']) : ''; ?>" 
                                       class="datepicker" readonly>
                            </div>
                        </div>
                    </div>
                    <div style="display: flex; gap: 15px;">
                        <button type="submit" class="filter-btn" id="filterBtn">
                            <span class="btn-content">
                                <i class="fas fa-filter"></i>
                                <span>Terapkan Filter</span>
                            </span>
                        </button>
                        <button type="button" class="reset-btn" id="resetBtn">
                            <span class="btn-content">
                                <i class="fas fa-undo"></i>
                                <span>Reset</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <div id="results">
            <?php
            if (isset($_GET['no_rekening'])) {
                $no_rekening = $conn->real_escape_string($_GET['no_rekening']);
                
                $where_clause = "r.no_rekening = '$no_rekening'";
                
                if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                    $start_date = $conn->real_escape_string($_GET['start_date']);
                    $start_date = date('Y-m-d', strtotime($start_date));
                    $where_clause .= " AND DATE(m.created_at) >= '$start_date'";
                }
                
                if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                    $end_date = $conn->real_escape_string($_GET['end_date']);
                    $end_date = date('Y-m-d', strtotime($end_date)) . ' 23:59:59';
                    $where_clause .= " AND m.created_at <= '$end_date'";
                }
                
                // Count total records for pagination
                $count_query = "SELECT COUNT(*) as total 
                               FROM mutasi m 
                               JOIN rekening r ON m.rekening_id = r.id 
                               WHERE $where_clause";
                $count_result = $conn->query($count_query);
                $total_rows = $count_result ? $count_result->fetch_assoc()['total'] : 0;
                $total_pages = ceil($total_rows / $per_page);

                // Fetch paginated records
                $query = "SELECT m.*, r.no_rekening 
                         FROM mutasi m 
                         JOIN rekening r ON m.rekening_id = r.id 
                         WHERE $where_clause 
                         ORDER BY m.created_at DESC 
                         LIMIT $offset, $per_page";
                $result = $conn->query($query);

                echo '<div class="results-card">';
                
                if (isset($_GET['start_date']) && !empty($_GET['start_date']) || isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                    echo '<div class="alert alert-info" style="margin-bottom: 15px;">
                        <i class="fas fa-info-circle"></i>
                        <span>Menampilkan hasil filter ';
                    if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
                        echo 'dari ' . date('d/m/Y', strtotime($_GET['start_date']));
                    }
                    if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                        echo ' sampai ' . date('d/m/Y', strtotime($_GET['end_date']));
                    }
                    echo '</span>
                    </div>';
                }
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="results-count">Menampilkan ' . $result->num_rows . ' dari ' . $total_rows . ' transaksi</div>';
                    echo '<div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Jumlah</th>
                                    <th>Saldo Akhir</th>
                                    <th>Tanggal</th>
                                </tr>
                            </thead>
                            <tbody>';
                    
                    $counter = $offset + 1;
                    while ($row = $result->fetch_assoc()) {
                        $amountClass = $row['jumlah'] < 0 ? 'negative' : 'positive';
                        $amountPrefix = $row['jumlah'] < 0 ? '-' : '+';
                        echo "<tr>
                            <td>{$counter}</td>
                            <td class='amount {$amountClass}'>{$amountPrefix}Rp " . number_format(abs($row['jumlah']), 0, ',', '.') . "</td>
                            <td class='amount'>Rp " . number_format($row['saldo_akhir'], 0, ',', '.') . "</td>
                            <td>" . date('d/m/Y H:i', strtotime($row['created_at'])) . "</td>
                        </tr>";
                        $counter++;
                    }
                    echo '</tbody></table>';

                    // Pagination links
                    if ($total_pages > 1) {
                        echo '<div class="pagination">';
                        $base_url = '?no_rekening=' . urlencode($no_rekening);
                        if (isset($_GET['start_date'])) {
                            $base_url .= '&start_date=' . urlencode($_GET['start_date']);
                        }
                        if (isset($_GET['end_date'])) {
                            $base_url .= '&end_date=' . urlencode($_GET['end_date']);
                        }

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
                } else {
                    echo '<div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <span>Tidak ada mutasi untuk rekening ini';
                    if (isset($_GET['start_date']) && !empty($_GET['start_date']) || isset($_GET['end_date']) && !empty($_GET['end_date'])) {
                        echo ' dengan filter yang diterapkan';
                    }
                    echo '.</span>
                    </div>';
                }
                echo '</div>';
            }
            ?>
        </div>

        <div id="alertContainer"></div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/flatpickr.min.js"></script>
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
            const searchForm = document.getElementById('searchForm');
            const filterForm = document.getElementById('filterForm');
            const searchBtn = document.getElementById('searchBtn');
            const filterBtn = document.getElementById('filterBtn');
            const resetBtn = document.getElementById('resetBtn');
            const filterToggle = document.getElementById('filterToggle');
            const filterSection = document.getElementById('filterSection');
            const inputNoRek = document.getElementById('no_rekening');
            const alertContainer = document.getElementById('alertContainer');
            const results = document.getElementById('results');
            const prefix = "REK";

            // Initialize Flatpickr with mobile support
            flatpickr(".datepicker", {
                dateFormat: "d/m/Y",
                enableMobile: true,
                locale: { firstDayOfWeek: 1 },
                wrap: false,
                clickOpens: true
            });

            // Initialize rekening input
            if (!inputNoRek.value) {
                inputNoRek.value = prefix;
            }

            // Restrict rekening input to numbers and enforce prefix
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

            // Filter toggle
            filterToggle?.addEventListener('click', function() {
                if (filterSection.style.display === 'block') {
                    filterSection.style.display = 'none';
                    this.querySelector('i').className = 'fas fa-filter';
                    this.querySelector('span').textContent = 'Filter Berdasarkan Tanggal';
                } else {
                    filterSection.style.display = 'block';
                    filterSection.classList.add('visible');
                    this.querySelector('i').className = 'fas fa-times';
                    this.querySelector('span').textContent = 'Tutup Filter';
                }
            });

            // Search form handling
            searchForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const rekening = inputNoRek.value.trim();
                if (rekening === prefix || rekening.length <= prefix.length) {
                    showAlert('Masukkan nomor rekening yang valid (REK diikuti angka)', 'error');
                    inputNoRek.focus();
                    return;
                }
                searchBtn.classList.add('btn-loading');
                setTimeout(() => {
                    searchForm.submit();
                }, 1000); // Increased delay for visibility of dots
            });

            // Filter form handling
            filterForm?.addEventListener('submit', function(e) {
                e.preventDefault();
                filterBtn.classList.add('btn-loading');
                setTimeout(() => {
                    filterForm.submit();
                }, 1000);
            });

            // Reset button handling
            resetBtn?.addEventListener('click', function() {
                resetBtn.classList.add('btn-loading');
                setTimeout(() => {
                    window.location.href = window.location.pathname + '?no_rekening=' + 
                        encodeURIComponent(document.querySelector('input[name="no_rekening"]').value);
                }, 1000);
            });

            // Show success animation only on initial search (no pagination or filters)
            <?php if (
                isset($_GET['no_rekening']) && 
                $result && 
                $result->num_rows > 0 && 
                !isset($_GET['page']) && 
                !isset($_GET['start_date']) && 
                !isset($_GET['end_date'])
            ): ?>
                showSuccessAnimation('Mutasi rekening berhasil ditemukan');
            <?php endif; ?>

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

            function showSuccessAnimation(message) {
                const overlay = document.createElement('div');
                overlay.className = 'success-overlay';
                overlay.innerHTML = `
                    <div class="success-modal">
                        <div class="success-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h3>Pencarian Berhasil!</h3>
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

                overlay.addEventListener('click', () => {
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                });

                setTimeout(() => {
                    overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    modal.style.animation = 'popInModal 0.7s ease-out reverse';
                    setTimeout(() => overlay.remove(), 500);
                }, 5000);
            }

            // Animate results
            if (results.children.length > 0) {
                results.style.display = 'none';
                setTimeout(() => {
                    results.style.display = 'block';
                    results.classList.add('visible');
                }, 100);
            }

            // Enter key support
            inputNoRek.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') searchBtn.click();
            });
        });
    </script>
</body>
</html>