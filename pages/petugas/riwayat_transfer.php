<?php
session_start();
require_once '../../includes/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

// Connection handling
if (!isset($conn) && isset($koneksi)) {
    $conn = $koneksi;
} else if (!isset($conn) && !isset($koneksi)) {
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "bankmini";
    
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Set timezone to WIB
date_default_timezone_set('Asia/Jakarta');

// Get filter parameter - default to today's date
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : date('Y-m-d');
// Validate date format to prevent SQL injection
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $filter_date) || !strtotime($filter_date)) {
    $filter_date = date('Y-m-d');
}

// Pagination settings
$limit = 10; // Records per page
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Prepare the base query for counting total records
$count_sql = "SELECT COUNT(*) as total 
              FROM transaksi t
              JOIN rekening r_asal ON t.rekening_id = r_asal.id
              JOIN users u_asal ON r_asal.user_id = u_asal.id
              JOIN rekening r_tujuan ON t.rekening_tujuan_id = r_tujuan.id
              JOIN users u_tujuan ON r_tujuan.user_id = u_tujuan.id
              WHERE t.jenis_transaksi = 'transfer'
              AND t.petugas_id IS NOT NULL";

if ($role == 'siswa') {
    $count_sql .= " AND (r_asal.user_id = ? OR r_tujuan.user_id = ?)";
}

$count_sql .= " AND DATE(t.created_at) = ?";

$count_params = [];
$count_types = '';

if ($role == 'siswa') {
    array_push($count_params, $user_id, $user_id);
    $count_types = 'ii';
}

array_push($count_params, $filter_date);
$count_types .= 's';

$stmt_count = $conn->prepare($count_sql);
if (!$stmt_count) {
    error_log("Prepare failed: " . $conn->error);
    die("Error preparing query.");
}

if ($count_params) {
    $stmt_count->bind_param($count_types, ...$count_params);
}

if (!$stmt_count->execute()) {
    error_log("Execute failed: " . $stmt_count->error);
    die("Error executing query.");
}

$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();

$total_pages = ceil($total_records / $limit);

// Prepare the base query for fetching records
$sql = "SELECT t.*, 
        r_asal.no_rekening as rekening_asal, u_asal.nama as nama_asal,
        r_tujuan.no_rekening as rekening_tujuan, u_tujuan.nama as nama_tujuan
        FROM transaksi t
        JOIN rekening r_asal ON t.rekening_id = r_asal.id
        JOIN users u_asal ON r_asal.user_id = u_asal.id
        JOIN rekening r_tujuan ON t.rekening_tujuan_id = r_tujuan.id
        JOIN users u_tujuan ON r_tujuan.user_id = u_tujuan.id
        WHERE t.jenis_transaksi = 'transfer'
        AND t.petugas_id IS NOT NULL";

if ($role == 'siswa') {
    $sql .= " AND (r_asal.user_id = ? OR r_tujuan.user_id = ?)";
}

$sql .= " AND DATE(t.created_at) = ?";
$sql .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";

$params = [];
$types = '';

if ($role == 'siswa') {
    array_push($params, $user_id, $user_id);
    $types = 'ii';
}

array_push($params, $filter_date, $limit, $offset);
$types .= 'sii';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    die("Error preparing query.");
}

if ($params) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {
    error_log("Execute failed: " . $stmt->error);
    die("Error executing query.");
}

$result = $stmt->get_result();
$transfers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Riwayat Transfer oleh Petugas - SCHOBANK SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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

        .section-title {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
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

        .date-picker-container {
            position: relative;
        }

        input.date-picker, input.flatpickr-input, input.flatpickr-alt-input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            font-family: 'Poppins', sans-serif;
            background: white;
            cursor: pointer;
            color: var(--text-primary);
            height: 44px;
            line-height: 1.5;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        input.date-picker:focus, input.flatpickr-input:focus, input.flatpickr-alt-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        input.date-picker::placeholder, input.flatpickr-alt-input::placeholder {
            color: var(--text-secondary);
            opacity: 0.7;
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

        .reset-btn {
            background-color: var(--danger-color);
        }

        .reset-btn:hover {
            background-color: #d32f2f;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            min-width: 800px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        th {
            background-color: var(--primary-light);
            font-weight: 600;
            color: var(--primary-dark);
            position: sticky;
            top: 0;
        }

        tr:hover {
            background-color: var(--primary-light);
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            font-weight: 500;
            display: inline-block;
        }

        .status-approved {
            background-color: var(--primary-light);
            color: var(--secondary-color);
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-rejected {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .detail-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: var(--transition);
            text-decoration: none;
        }

        .detail-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .account-info {
            font-weight: 500;
            color: var(--primary-dark);
        }

        .account-name {
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            color: var(--text-secondary);
        }

        .amount {
            font-weight: 500;
            color: var(--text-primary);
            white-space: nowrap;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: 48px;
            color: #ccc;
            margin-bottom: 15px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .pagination-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
        }

        .pagination-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination-btn:active {
            transform: scale(0.95);
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Flatpickr Custom Styles */
        .flatpickr-calendar {
            font-family: 'Poppins', sans-serif;
            border-radius: 12px;
            box-shadow: var(--shadow-md);
            background: white;
            padding: 15px;
            width: 320px;
            border: 1px solid #e0e0e0;
            -webkit-font-smoothing: antialiased;
        }

        .flatpickr-calendar.animate {
            animation: popIn 0.3s ease-out;
        }

        @keyframes popIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .flatpickr-day {
            border-radius: 8px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            padding: 8px;
            height: 40px;
            line-height: 24px;
        }

        .flatpickr-day.selected,
        .flatpickr-day.selected:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .flatpickr-day.today {
            border: 2px solid var(--secondary-color);
            color: var(--secondary-color);
            background: transparent;
        }

        .flatpickr-day:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
            color: var(--primary-dark);
        }

        .flatpickr-day.disabled,
        .flatpickr-day.disabled:hover {
            background: #f5f5f5;
            color: #ccc;
            cursor: not-allowed;
        }

        .flatpickr-monthDropdown-months,
        .flatpickr-year {
            font-family: 'Poppins', sans-serif;
            color: var(--primary-dark);
            font-weight: 500;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
            background: transparent;
            border: none;
            padding: 8px;
            -webkit-appearance: none;
        }

        .flatpickr-monthDropdown-months:hover,
        .flatpickr-year:hover {
            background: var(--primary-light);
            border-radius: 5px;
        }

        .flatpickr-current-month {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
        }

        .flatpickr-prev-month,
        .flatpickr-next-month {
            color: var(--primary-color);
            padding: 12px;
            border-radius: 50%;
            transition: var(--transition);
        }

        .flatpickr-prev-month:hover,
        .flatpickr-next-month:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .flatpickr-weekdays {
            margin-bottom: 8px;
        }

        .flatpickr-weekday {
            color: var(--text-secondary);
            font-weight: 500;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .numInputWrapper {
            display: none;
        }

        .flatpickr-innerContainer {
            padding: 12px;
        }

        .flatpickr-rContainer {
            padding: 0;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 15px;
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .main-content {
                padding: 15px;
            }

            .deposit-card {
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

            button {
                width: 100%;
                justify-content: center;
            }

            th, td {
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .account-info, .account-name {
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
                max-width: 100px;
                display: block;
            }

            .flatpickr-calendar {
                width: 90vw;
                max-width: 300px;
                padding: 12px;
            }

            .flatpickr-day {
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
                height: 38px;
                line-height: 22px;
            }

            .flatpickr-monthDropdown-months,
            .flatpickr-year {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .pagination-btn {
                width: 100%;
                text-align: center;
            }

            input.date-picker, input.flatpickr-input, input.flatpickr-alt-input {
                height: 40px;
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
                padding: 10px 12px;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner h2 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .deposit-form {
                gap: 15px;
            }

            .empty-state {
                padding: 30px;
            }

            .empty-state i {
                font-size: 40px;
            }

            .flatpickr-calendar {
                width: 85vw;
                max-width: 280px;
            }

            .flatpickr-day {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                height: 36px;
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
            <h2>Riwayat Transfer oleh Petugas</h2>
            <p>Transaksi Transfer yang diproses oleh petugas</p>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-search"></i> Filter Riwayat</h3>
            <form method="GET" class="deposit-form">
                <div class="date-picker-container">
                    <label for="filter_date">Tanggal:</label>
                    <input type="text" name="filter_date" id="filter_date" class="date-picker" 
                           value="<?= htmlspecialchars($filter_date) ?>" placeholder="Pilih tanggal">
                </div>
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <button type="submit">
                        <i class="fas fa-search"></i>
                        <span>Cari</span>
                    </button>
                    <?php if ($filter_date != date('Y-m-d')): ?>
                        <button type="button" class="reset-btn" onclick="window.location.href='riwayat_transfer.php'">
                            <i class="fas fa-undo"></i>
                            <span>Reset</span>
                        </button>
                    <?php endif; ?>
                </div>
                <input type="hidden" name="page" value="1">
            </form>
        </div>

        <div class="deposit-card">
            <h3 class="section-title"><i class="fas fa-list"></i> Daftar Transaksi</h3>
            <?php if (empty($transfers)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Tidak ada transaksi transfer yang diproses petugas pada tanggal <?= date('d/m/Y', strtotime($filter_date)) ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>No. Transaksi</th>
                                <th>Tanggal</th>
                                <th>Dari</th>
                                <th>Ke</th>
                                <th>Jumlah</th>
                                <th>Status</th>
                                <?php if ($role != 'siswa'): ?>
                                    <th>Aksi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $row_number = $offset + 1;
                            foreach ($transfers as $transfer): 
                            ?>
                                <tr>
                                    <td><?= $row_number++ ?></td>
                                    <td><?= htmlspecialchars($transfer['no_transaksi']) ?></td>
                                    <td><?= date('d/m/Y H:i', strtotime($transfer['created_at'])) ?> WIB</td>
                                    <td>
                                        <div class="account-info"><?= htmlspecialchars($transfer['rekening_asal']) ?></div>
                                        <div class="account-name"><?= htmlspecialchars($transfer['nama_asal']) ?></div>
                                    </td>
                                    <td>
                                        <div class="account-info"><?= htmlspecialchars($transfer['rekening_tujuan']) ?></div>
                                        <div class="account-name"><?= htmlspecialchars($transfer['nama_tujuan']) ?></div>
                                    </td>
                                    <td class="amount">Rp <?= number_format($transfer['jumlah'], 2, ',', '.') ?></td>
                                    <td>
                                        <?php if ($transfer['status'] == 'approved'): ?>
                                            <span class="status-badge status-approved">Berhasil</span>
                                        <?php elseif ($transfer['status'] == 'pending'): ?>
                                            <span class="status-badge status-pending">Pending</span>
                                        <?php else: ?>
                                            <span class="status-badge status-rejected">Ditolak</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($role != 'siswa'): ?>
                                        <td>
                                            <a href="detail_transfer.php?id=<?= $transfer['id'] ?>" class="detail-btn">
                                                <i class="fas fa-eye"></i> Detail
                                            </a>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <a href="?filter_date=<?= htmlspecialchars($filter_date) ?>&page=<?= max(1, $page - 1) ?>" 
                           class="pagination-btn" style="display: <?= $page > 1 ? 'inline-flex' : 'none' ?>;">
                            <i class="fas fa-chevron-left"></i> Sebelumnya
                        </a>
                        <a href="?filter_date=<?= htmlspecialchars($filter_date) ?>&page=<?= min($total_pages, $page + 1) ?>" 
                           class="pagination-btn" style="display: <?= $page < $total_pages ? 'inline-flex' : 'none' ?>;">
                            Berikutnya <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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

        // Initialize Flatpickr
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Initializing Flatpickr for #filter_date');
            const fp = flatpickr("#filter_date", {
                dateFormat: "Y-m-d",
                defaultDate: "<?php echo htmlspecialchars($filter_date); ?>",
                maxDate: "today",
                animate: true,
                locale: {
                    firstDayOfWeek: 1 // Start week on Monday
                },
                altInput: true,
                altFormat: "d/m/Y",
                allowInput: false,
                clickOpens: true,
                wrap: false,
                onReady: function(selectedDates, dateStr, instance) {
                    console.log('Flatpickr ready:', dateStr);
                    instance.altInput.classList.add('date-picker', 'flatpickr-alt-input');
                    instance.altInput.placeholder = "Pilih tanggal";
                    instance.altInput.readOnly = true;
                },
                onOpen: function(selectedDates, dateStr, instance) {
                    console.log('Flatpickr opened');
                    instance.calendarContainer.classList.add('animate');
                },
                onClose: function(selectedDates, dateStr, instance) {
                    console.log('Flatpickr closed, selected:', dateStr);
                    instance.calendarContainer.classList.remove('animate');
                    if (selectedDates.length) {
                        instance.altInput.form.submit();
                    }
                },
                onError: function(error) {
                    console.error('Flatpickr error:', error);
                }
            });

            // Prevent manual typing in the visible input
            const altInput = document.querySelector('.date-picker');
            altInput.addEventListener('input', function(e) {
                console.log('Input event blocked on date-picker');
                e.preventDefault();
                this.blur();
            });

            // Add click to open calendar
            altInput.addEventListener('click', function() {
                console.log('Date picker clicked, opening calendar');
                fp.open();
            });
        });
    </script>
</body>
</html>