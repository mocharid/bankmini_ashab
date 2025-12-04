<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Set timezone to Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Validate session and role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Get user information from session
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Ambil data transaksi berdasarkan filter tanggal - DEFAULT BULAN INI
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Pagination settings
$items_per_page = 15;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Build WHERE clause based on date filter
$date_where = '';
$date_params = [];
$date_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $date_where = " AND DATE(t.created_at) BETWEEN ? AND ?";
    $date_params = [$start_date, $end_date];
    $date_types = 'ss';
}

// Calculate summary statistics
$total_query = "SELECT
    COUNT(*) as total_transactions,
    SUM(CASE WHEN jenis_transaksi = 'setor' AND (status = 'approved' OR status IS NULL) THEN jumlah ELSE 0 END) as total_debit,
    SUM(CASE WHEN jenis_transaksi = 'tarik' AND status = 'approved' THEN jumlah ELSE 0 END) as total_kredit
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN kelas k ON u.kelas_id = k.id
    WHERE t.jenis_transaksi IN ('setor', 'tarik')
    AND t.petugas_id IS NULL" . $date_where;

$stmt_total = $conn->prepare($total_query);
if (!$stmt_total) {
    error_log("Error preparing total query: " . $conn->error);
    die("Error preparing total query");
}

if (!empty($date_params)) {
    $stmt_total->bind_param($date_types, ...$date_params);
}
$stmt_total->execute();
$totals = $stmt_total->get_result()->fetch_assoc();
$stmt_total->close();

$totals['total_transactions'] = $totals['total_transactions'] ?? 0;
$totals['total_debit'] = (float)($totals['total_debit'] ?? 0);
$totals['total_kredit'] = (float)($totals['total_kredit'] ?? 0);

// Debug logging for totals
error_log("Total Debit: " . $totals['total_debit'] . ", Total Kredit: " . $totals['total_kredit']);

// Calculate net balance
$saldo_bersih = $totals['total_debit'] - $totals['total_kredit'];
error_log("Saldo Bersih: " . $saldo_bersih);

// Calculate total records for pagination
$total_records_query = "SELECT COUNT(*) as total
                       FROM transaksi t
                       JOIN rekening r ON t.rekening_id = r.id
                       JOIN users u ON r.user_id = u.id
                       WHERE t.jenis_transaksi IN ('setor', 'tarik')
                       AND t.petugas_id IS NULL" . $date_where;

$stmt_total_records = $conn->prepare($total_records_query);
if (!$stmt_total_records) {
    error_log("Error preparing total records query: " . $conn->error);
    die("Error preparing total records query");
}

if (!empty($date_params)) {
    $stmt_total_records->bind_param($date_types, ...$date_params);
}
$stmt_total_records->execute();
$total_records = $stmt_total_records->get_result()->fetch_assoc()['total'];
$stmt_total_records->close();

$total_pages = ceil($total_records / $items_per_page);

// Fetch transactions with class information and no_rekening
try {
    $query = "SELECT
        t.no_transaksi,
        r.no_rekening,
        t.jenis_transaksi,
        t.jumlah,
        t.created_at,
        t.status,
        u.nama AS nama_siswa,
        k.nama_kelas,
        tk.nama_tingkatan
        FROM transaksi t
        JOIN rekening r ON t.rekening_id = r.id
        JOIN users u ON r.user_id = u.id
        LEFT JOIN kelas k ON u.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        WHERE t.jenis_transaksi IN ('setor', 'tarik')
        AND t.petugas_id IS NULL" . $date_where . "
        ORDER BY t.created_at DESC
        LIMIT ? OFFSET ?";

    $stmt_trans = $conn->prepare($query);
    if (!$stmt_trans) {
        throw new Exception("Failed to prepare transaction query: " . $conn->error);
    }

    if (!empty($date_params)) {
        $all_params = array_merge($date_params, [$items_per_page, $offset]);
        $all_types = $date_types . 'ii';
        $stmt_trans->bind_param($all_types, ...$all_params);
    } else {
        $stmt_trans->bind_param('ii', $items_per_page, $offset);
    }
    
    $stmt_trans->execute();
    $result = $stmt_trans->get_result();

    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    $stmt_trans->close();
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
    $error_message = 'Gagal mengambil data transaksi: ' . $e->getMessage();
    $show_error_modal = true;
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

// Function to mask account number
function maskAccountNumber($accountNumber) {
    $length = strlen($accountNumber);
    if ($length <= 5) {
        return $accountNumber; // Too short to mask
    }
    $first3 = substr($accountNumber, 0, 3);
    $last2 = substr($accountNumber, -2);
    $maskLength = $length - 5;
    $mask = str_repeat('*', $maskLength);
    return $first3 . $mask . $last2;
}

// Format periode untuk display
$periode_display = 'Semua Data';
if (!empty($start_date) && !empty($end_date)) {
    $periode_display = date('d/m/Y', strtotime($start_date)) . ' - ' . date('d/m/Y', strtotime($end_date));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <title>Transaksi Admin | MY Schobank</title>
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
            --debit-bg: #d1fae5;
            --kredit-bg: #fee2e2;
            --pending-bg: #fef3c7;
            --button-width: 140px;
            --button-height: 44px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-user-select: none;
            -ms-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
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
            touch-action: pan-y;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
        }

        body.sidebar-active .main-content {
            opacity: 0.3;
            pointer-events: none;
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
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            font-size: clamp(0.85rem, 2vw, 0.9rem);
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

        .filter-section, .summary-container {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }

        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
        }

        .form-group {
            flex: 1;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            position: relative;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.9rem);
        }

        .date-input-wrapper {
            position: relative;
            width: 100%;
        }

        input[type="date"] {
            width: 100%;
            padding: 12px 50px 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            line-height: 1.5;
            min-height: 44px;
            transition: var(--transition);
            -webkit-user-select: text;
            user-select: text;
            background-color: #fff;
            text-align: left;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            color: var(--text-primary);
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            cursor: pointer;
            opacity: 0;
            z-index: 2;
        }

        input[type="date"]::before {
            content: attr(data-placeholder);
            color: #999;
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        input[type="date"]:focus::before,
        input[type="date"]:valid::before {
            display: none;
        }

        .calendar-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #666;
            font-size: 18px;
            z-index: 1;
        }

        input[type="date"]::-webkit-datetime-edit {
            padding-right: 0;
            text-align: left;
            padding-left: 0;
        }

        input[type="date"]::-webkit-datetime-edit-fields-wrapper {
            text-align: left;
            padding: 0;
        }

        input[type="date"]::-webkit-datetime-edit-text {
            padding: 0 3px;
        }

        input[type="date"]::-webkit-datetime-edit-month-field,
        input[type="date"]::-webkit-datetime-edit-day-field,
        input[type="date"]::-webkit-datetime-edit-year-field {
            padding: 0 3px;
        }

        input[type="date"]:focus {
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
            text-align: left;
        }

        .error-message.show {
            display: block;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .summary-title {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .summary-boxes {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .summary-box {
            background: var(--bg-light);
            padding: 20px;
            border-radius: 5px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-box h3 {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 10px;
            font-weight: 500;
        }

        .summary-box .amount {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
            display: inline-block;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }

        .summary-box .amount.counting {
            transform: scale(1.1);
            opacity: 0.8;
        }

        .summary-box .amount.finished {
            transform: scale(1);
            opacity: 1;
        }

        .stat-box {
            background: var(--bg-light);
            padding: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: slideIn 0.5s ease-out;
        }

        .stat-box .stat-icon {
            font-size: 2rem;
            color: var(--primary-dark);
        }

        .stat-box .stat-content {
            flex: 1;
        }

        .stat-box .stat-title {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
        }

        .stat-box .stat-value {
            color: var(--primary-dark);
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            font-weight: 600;
            display: inline-block;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }

        .stat-box .stat-value.counting {
            transform: scale(1.1);
            opacity: 0.8;
        }

        .stat-box .stat-value.finished {
            transform: scale(1);
            opacity: 1;
        }

        .stat-box .stat-note {
            color: var(--text-secondary);
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
        }

        .action-buttons {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 15px;
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
            gap: 8px;
            transition: var(--transition);
            width: var(--button-width);
            height: var(--button-height);
            position: relative;
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

        .btn-secondary {
            background: #f0f0f0;
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            margin-bottom: 20px;
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            -webkit-overflow-scrolling: touch;
            touch-action: pan-x pan-y;
            position: relative;
        }

        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 5px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
            background: white;
            min-width: 800px;
        }

        .transaction-table th, .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }

        .transaction-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .transaction-table td {
            background: white;
        }

        .transaction-table tr:hover {
            background-color: rgba(59, 130, 246, 0.05);
        }

        .no-rekening {
            color: var(--text-primary);
            text-decoration: none;
            pointer-events: none;
            -webkit-touch-callout: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            cursor: default;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }

        .transaction-type {
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            min-width: 90px;
            display: inline-block;
            color: var(--text-primary);
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 20px;
            width: 100%;
        }

        .current-page {
            font-weight: 600;
            color: var(--primary-dark);
            min-width: 50px;
            text-align: center;
            font-size: clamp(0.95rem, 2vw, 1.05rem);
        }

        .pagination-btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: clamp(1rem, 2.2vw, 1.1rem);
            font-weight: 500;
            transition: var(--transition);
            width: auto;
            height: var(--button-height);
            padding: 0 16px;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pagination-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .pagination-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
            pointer-events: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 30px;
            background: linear-gradient(135deg, rgba(30, 58, 138, 0.03) 0%, rgba(59, 130, 246, 0.03) 100%);
            border-radius: 5px;
            border: 2px dashed rgba(30, 58, 138, 0.2);
            animation: fadeIn 0.6s ease-out;
            margin: 20px 0;
        }

        .empty-state-icon {
            font-size: clamp(3.5rem, 6vw, 4.5rem);
            color: var(--primary-color);
            margin-bottom: 25px;
            opacity: 0.4;
            display: block;
        }

        .empty-state-title {
            color: var(--primary-dark);
            font-size: clamp(1.3rem, 2.8vw, 1.6rem);
            font-weight: 600;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }

        .empty-state-message {
            color: var(--text-secondary);
            font-size: clamp(0.95rem, 2vw, 1.1rem);
            font-weight: 400;
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto 25px;
        }

        .empty-state-details {
            background: white;
            padding: 20px 25px;
            border-radius: 5px;
            box-shadow: var(--shadow-sm);
            max-width: 500px;
            margin: 0 auto;
            text-align: left;
        }

        .empty-state-details-title {
            font-size: clamp(0.9rem, 1.8vw, 1rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .empty-state-details-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .empty-state-details-list li {
            padding: 8px 0;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .empty-state-details-list li i {
            color: var(--primary-color);
            margin-top: 3px;
            font-size: 0.85rem;
        }

        .scroll-indicator {
            display: none;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
                max-width: 100%;
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
                font-size: clamp(1.3rem, 3vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 2vw, 0.85rem);
            }

            .filter-section, .summary-container {
                padding: 20px;
                border-radius: 5px;
            }

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: row;
                width: 100%;
                gap: 10px;
            }

            .filter-buttons .btn {
                flex: 1;
                width: auto;
                min-width: 0;
            }

            .action-buttons {
                justify-content: center;
            }

            .action-buttons .btn {
                width: 100%;
            }

            .summary-boxes {
                grid-template-columns: 1fr;
            }

            .scroll-indicator {
                display: none;
            }

            .table-container {
                overflow-x: auto;
                overflow-y: visible;
                -webkit-overflow-scrolling: touch;
                touch-action: pan-x pan-y;
                max-height: none;
                border-radius: 5px;
            }

            .transaction-table {
                min-width: 900px;
            }

            .transaction-table th, .transaction-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .pagination {
                gap: 5px;
            }

            .pagination-btn {
                padding: 8px 12px;
                min-width: 40px;
                font-size: clamp(1rem, 2.2vw, 1.1rem);
                border-radius: 5px;
            }

            .current-page {
                font-size: clamp(0.95rem, 2vw, 1rem);
                min-width: 45px;
            }

            .empty-state {
                padding: 40px 20px;
                border-radius: 5px;
            }

            .empty-state-details {
                text-align: left;
                padding: 18px 20px;
                border-radius: 5px;
            }
        }

        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }

            .filter-buttons {
                flex-direction: row;
                width: auto;
            }

            .filter-buttons .btn {
                width: var(--button-width);
                flex: 0 0 auto;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner {
                padding: 15px;
                border-radius: 5px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.8vw, 1.2rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 1.8vw, 0.8rem);
            }

            .filter-section, .summary-container {
                padding: 15px;
            }

            .filter-buttons {
                flex-direction: row;
                gap: 8px;
            }

            .filter-buttons .btn {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                padding: 10px 8px;
                flex: 1;
                border-radius: 5px;
            }

            .pagination-btn {
                padding: 6px 10px;
                min-width: 36px;
                font-size: clamp(0.9rem, 2vw, 1rem);
                border-radius: 5px;
            }

            .current-page {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                min-width: 40px;
            }

            .transaction-table {
                min-width: 1000px;
            }

            .table-container {
                touch-action: pan-x pan-y;
                overflow-y: visible;
                border-radius: 5px;
            }

            .empty-state {
                padding: 35px 15px;
                border-radius: 5px;
            }
        }

        .swal2-input {
            width: 100% !important;
            max-width: 400px !important;
            padding: 12px 16px !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 1rem !important;
            margin: 10px auto !important;
            box-sizing: border-box !important;
            text-align: left !important;
        }

        .swal2-input:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.15) !important;
        }

        .swal2-popup .swal2-title {
            font-size: 1.5rem !important;
            font-weight: 600 !important;
        }

        .swal2-html-container {
            font-size: 1rem !important;
            margin: 15px 0 !important;
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
                <h2><i class="fas fa-file-invoice-dollar"></i> Laporan Transaksi Admin</h2>
                <p>Periode: <?= htmlspecialchars($periode_display) ?></p>
            </div>
        </div>

        <div class="filter-section">
            <form id="filterForm" class="filter-form" method="GET" action="" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token); ?>">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" aria-describedby="start_date-error" data-placeholder="Pilih tanggal">
                        <i class="fas fa-calendar-alt calendar-icon"></i>
                    </div>
                    <span class="error-message" id="start_date-error"></span>
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <div class="date-input-wrapper">
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" aria-describedby="end_date-error" data-placeholder="Pilih tanggal">
                        <i class="fas fa-calendar-alt calendar-icon"></i>
                    </div>
                    <span class="error-message" id="end_date-error"></span>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" id="resetButton" class="btn btn-secondary">
                        <i class="fas fa-undo"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <div class="summary-container">
            <h3 class="summary-title"><i class="fas fa-chart-line"></i> Ringkasan Transaksi Admin</h3>
            <div class="summary-boxes">
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount non-currency" data-target="<?= $totals['total_transactions'] ?? 0 ?>"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Debit</h3>
                    <div class="amount" data-target="<?= $totals['total_debit'] ?? 0 ?>" data-currency="true"><?= formatRupiah($totals['total_debit'] ?? 0) ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Kredit</h3>
                    <div class="amount" data-target="<?= $totals['total_kredit'] ?? 0 ?>" data-currency="true"><?= formatRupiah($totals['total_kredit'] ?? 0) ?></div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value" data-target="<?= $saldo_bersih ?>" data-currency="true"><?= formatRupiah($saldo_bersih) ?></div>
                        <div class="stat-note">Debit - Kredit</div>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <button id="pdfButton" class="btn">
                    <i class="fas fa-file-pdf"></i> Unduh PDF
                </button>
            </div>

            <?php if ($total_records > 0): ?>
                <div class="table-container">
                    <table class="transaction-table">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>No Transaksi</th>
                                <th>Nama Siswa</th>
                                <th>No Rekening</th>
                                <th>Kelas</th>
                                <th>Jenis</th>
                                <th>Jumlah</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $index => $transaction): ?>
                                <?php
                                $typeClass = $transaction['jenis_transaksi'] === 'setor' ? 'type-debit' : 'type-kredit';
                                $displayType = $transaction['jenis_transaksi'] === 'setor' ? 'Setor' : 'Tarik';
                                $noUrut = (($current_page - 1) * $items_per_page) + $index + 1;
                                $nama_kelas = trim($transaction['nama_tingkatan'] ?? '') && trim($transaction['nama_kelas'] ?? '')
                                    ? htmlspecialchars($transaction['nama_tingkatan'] . ' ' . $transaction['nama_kelas'])
                                    : 'N/A';
                                $maskedAccount = maskAccountNumber($transaction['no_rekening'] ?? 'N/A');
                                ?>
                                <tr>
                                    <td><?= $noUrut ?></td>
                                    <td><?= date('d/m/Y', strtotime($transaction['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($transaction['no_transaksi'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($transaction['nama_siswa'] ?? 'N/A') ?></td>
                                    <td><span class="no-rekening"><?= htmlspecialchars($maskedAccount) ?></span></td>
                                    <td><?= $nama_kelas ?></td>
                                    <td><span class="transaction-type <?= $typeClass ?>"><?= $displayType ?></span></td>
                                    <td><?= formatRupiah($transaction['jumlah']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_records > 15): ?>
                    <div class="pagination">
                        <button id="prev-page" class="pagination-btn" <?= $current_page <= 1 ? 'disabled' : '' ?>>&lt;</button>
                        <span class="current-page"><?= $current_page ?></span>
                        <button id="next-page" class="pagination-btn" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>&gt;</button>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-inbox empty-state-icon"></i>
                    <h3 class="empty-state-title">Tidak Ada Data Transaksi</h3>
                    <p class="empty-state-message">
                        Tidak ditemukan transaksi admin dalam periode yang dipilih. Silakan periksa filter tanggal atau coba periode lain.
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <input type="hidden" id="totalTransactions" value="<?= htmlspecialchars($total_records) ?>">
        <input type="hidden" id="hasDateFilter" value="<?= !empty($start_date) && !empty($end_date) ? '1' : '0' ?>">

        <?php if (isset($show_error_modal) && $show_error_modal): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: '<?= htmlspecialchars($error_message); ?>',
                    confirmButtonColor: '#1e3a8a'
                });
            </script>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
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

            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');

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

            function showAlert(title, message, type = 'error') {
                Swal.fire({
                    icon: type,
                    title: title,
                    text: message,
                    confirmButtonColor: '#1e3a8a'
                });
            }

            function countUp(element, target, duration, isCurrency) {
                let start = 0;
                const stepTime = 50;
                const steps = Math.ceil(duration / stepTime);
                const increment = target / steps;
                let current = start;

                element.classList.add('counting');

                const updateCount = () => {
                    current += increment;
                    if ((increment > 0 && current >= target) || (increment < 0 && current <= target)) {
                        current = target;
                        element.classList.remove('counting');
                        element.classList.add('finished');
                    }

                    if (isCurrency) {
                        const formatted = Math.round(current).toLocaleString('id-ID', { minimumFractionDigits: 0 });
                        element.textContent = (current < 0 ? '-' : '') + 'Rp ' + formatted.replace('-', '');
                    } else {
                        element.textContent = Math.round(current).toLocaleString('id-ID');
                    }

                    if ((increment > 0 && current < target) || (increment < 0 && current > target)) {
                        setTimeout(updateCount, stepTime);
                    }
                };

                updateCount();
            }

            const amounts = document.querySelectorAll('.summary-box .amount, .stat-box .stat-value');
            amounts.forEach(amount => {
                const target = parseFloat(amount.getAttribute('data-target'));
                const isCurrency = amount.getAttribute('data-currency') === 'true';
                countUp(amount, target, 1500, isCurrency);
            });

            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const startDateError = document.getElementById('start_date-error');
            const endDateError = document.getElementById('end_date-error');

            let isFilterProcessing = false;

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    if (isFilterProcessing) return;

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    startDateInput.setAttribute('aria-invalid', 'false');
                    endDateInput.setAttribute('aria-invalid', 'false');
                    startDateError.textContent = '';
                    endDateError.textContent = '';
                    startDateError.classList.remove('show');
                    endDateError.classList.remove('show');

                    if (!startDate && !endDate) {
                        const url = new URL(window.location);
                        url.searchParams.delete('start_date');
                        url.searchParams.delete('end_date');
                        url.searchParams.delete('page');
                        window.location.href = url.toString();
                        return;
                    }

                    if ((startDate && !endDate) || (!startDate && endDate)) {
                        if (!startDate) {
                            startDateInput.setAttribute('aria-invalid', 'true');
                            startDateError.textContent = 'Silakan pilih tanggal awal';
                            startDateError.classList.add('show');
                        }
                        if (!endDate) {
                            endDateInput.setAttribute('aria-invalid', 'true');
                            endDateError.textContent = 'Silakan pilih tanggal akhir';
                            endDateError.classList.add('show');
                        }
                        showAlert('Error', 'Silakan lengkapi kedua tanggal');
                        return;
                    }

                    if (new Date(startDate) > new Date(endDate)) {
                        startDateInput.setAttribute('aria-invalid', 'true');
                        endDateInput.setAttribute('aria-invalid', 'true');
                        startDateError.textContent = 'Tanggal awal tidak boleh lebih dari tanggal akhir';
                        endDateError.textContent = 'Tanggal awal tidak boleh lebih dari tanggal akhir';
                        startDateError.classList.add('show');
                        endDateError.classList.add('show');
                        showAlert('Error', 'Tanggal awal tidak boleh lebih dari tanggal akhir');
                        return;
                    }

                    isFilterProcessing = true;
                    Swal.fire({
                        title: 'Memproses Filter',
                        html: 'Sedang memuat data transaksi...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });

                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.set('start_date', startDate);
                        url.searchParams.set('end_date', endDate);
                        url.searchParams.delete('page');
                        window.location.href = url.toString();
                    }, 800);
                });

                filterForm.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter' && !e.target.matches('textarea')) {
                        e.preventDefault();
                        if (!isFilterProcessing) {
                            filterButton.click();
                        }
                    }
                });
            }

            const resetButton = document.getElementById('resetButton');
            if (resetButton) {
                resetButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    const url = new URL(window.location);
                    url.searchParams.delete('start_date');
                    url.searchParams.delete('end_date');
                    url.searchParams.delete('page');
                    window.location.href = url.toString();
                });
            }

            const pdfButton = document.getElementById('pdfButton');
            const totalRecords = parseInt(document.getElementById('totalTransactions').value);

            if (pdfButton) {
                pdfButton.addEventListener('click', function(e) {
                    e.preventDefault();

                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;

                    if (totalRecords === 0) {
                        showAlert('Error', 'Tidak ada transaksi untuk diunduh');
                        return;
                    }

                    let url = `download_laporan_admin.php?format=pdf`;
                    
                    if (startDate && endDate) {
                        if (new Date(startDate) > new Date(endDate)) {
                            showAlert('Error', 'Tanggal awal tidak boleh lebih dari tanggal akhir');
                            return;
                        }
                        url += `&start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`;
                    }

                    const pdfWindow = window.open(url, '_blank');

                    if (!pdfWindow || pdfWindow.closed || typeof pdfWindow.closed === 'undefined') {
                        showAlert('Error', 'Gagal membuka PDF. Pastikan popup tidak diblokir oleh browser.');
                    }
                });
            }

            const prevPageButton = document.getElementById('prev-page');
            const nextPageButton = document.getElementById('next-page');
            const currentPage = <?= $current_page ?>;
            const totalPages = <?= $total_pages ?>;

            if (prevPageButton) {
                prevPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage > 1 && !prevPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage - 1);
                        window.location.href = url.toString();
                    }
                });
            }

            if (nextPageButton) {
                nextPageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (currentPage < totalPages && !nextPageButton.disabled) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage + 1);
                        window.location.href = url.toString();
                    }
                });
            }

            const tableContainer = document.querySelector('.table-container');
            if (tableContainer) {
                tableContainer.style.overflowX = 'auto';
                tableContainer.style.overflowY = 'visible';

                let startX = 0;
                let startY = 0;
                let isScrollingHorizontally = false;

                tableContainer.addEventListener('touchstart', function(e) {
                    startX = e.touches[0].clientX;
                    startY = e.touches[0].clientY;
                    isScrollingHorizontally = false;
                }, { passive: true });

                tableContainer.addEventListener('touchmove', function(e) {
                    const currentX = e.touches[0].clientX;
                    const currentY = e.touches[0].clientY;
                    const diffX = Math.abs(currentX - startX);
                    const diffY = Math.abs(currentY - startY);

                    if (diffX > diffY && diffX > 10) {
                        isScrollingHorizontally = true;
                    } else if (diffY > diffX && diffY > 10) {
                        isScrollingHorizontally = false;
                    }
                }, { passive: false });

                tableContainer.addEventListener('touchend', function() {
                    isScrollingHorizontally = false;
                }, { passive: true });
            }

            let lastWindowWidth = window.innerWidth;
            window.addEventListener('resize', () => {
                if (window.innerWidth !== lastWindowWidth) {
                    lastWindowWidth = window.innerWidth;
                    if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                    if (tableContainer) {
                        tableContainer.style.overflowX = 'auto';
                        tableContainer.style.overflowY = 'visible';
                    }
                }
            });

            document.addEventListener('mousedown', (e) => {
                if (!e.target.matches('input, select, textarea')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>
