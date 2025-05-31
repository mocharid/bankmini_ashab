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

// Get user information from session
$username = $_SESSION['username'] ?? 'Admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Validate and sanitize date inputs
$start_date = isset($_GET['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date']) 
    ? $_GET['start_date'] 
    : date('Y-m-01');
$end_date = isset($_GET['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date']) 
    ? $_GET['end_date'] 
    : date('Y-m-d');

// Ensure start_date <= end_date
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Query for summary totals
$total_query = "SELECT 
    COUNT(*) as total_transactions,
    SUM(CASE WHEN t.jenis_transaksi = 'setor' AND (t.status = 'approved' OR t.status IS NULL) THEN t.jumlah ELSE 0 END) as total_debit,
    SUM(CASE WHEN t.jenis_transaksi = 'tarik' AND t.status = 'approved' THEN t.jumlah ELSE 0 END) as total_kredit
    FROM transaksi t
    JOIN rekening r ON t.rekening_id = r.id
    JOIN users u ON r.user_id = u.id
    LEFT JOIN users p ON t.petugas_id = p.id
    LEFT JOIN kelas k ON u.kelas_id = k.id
    WHERE DATE(t.created_at) BETWEEN ? AND ?
    AND t.jenis_transaksi IN ('setor', 'tarik')
    AND (
        t.petugas_id IS NULL
        OR (t.petugas_id IS NOT NULL AND p.role = 'petugas')
    )";
try {
    $stmt_total = $conn->prepare($total_query);
    if (!$stmt_total) {
        error_log("Error preparing total query: " . $conn->error);
        die("Error preparing total query");
    }
    $stmt_total->bind_param("ss", $start_date, $end_date);
    $stmt_total->execute();
    $totals = $stmt_total->get_result()->fetch_assoc();
    $stmt_total->close();
    
    // Handle null values
    $totals['total_transactions'] = $totals['total_transactions'] ?? 0;
    $totals['total_debit'] = $totals['total_debit'] ?? 0;
    $totals['total_kredit'] = $totals['total_kredit'] ?? 0;
} catch (Exception $e) {
    error_log("Database error in rekap_transaksi.php: " . $e->getMessage());
    $totals = ['total_transactions' => 0, 'total_debit' => 0, 'total_kredit' => 0];
}

// Calculate net balance
$saldo_bersih = ($totals['total_debit'] ?? 0) - ($totals['total_kredit'] ?? 0);
$saldo_bersih = max(0, $saldo_bersih);

// Calculate total records for pagination
$total_records_query = "SELECT COUNT(*) as total 
                       FROM transaksi t
                       JOIN rekening r ON t.rekening_id = r.id
                       JOIN users u ON r.user_id = u.id
                       LEFT JOIN users p ON t.petugas_id = p.id
                       WHERE DATE(t.created_at) BETWEEN ? AND ?
                       AND t.jenis_transaksi IN ('setor', 'tarik')
                       AND (
                           t.petugas_id IS NULL
                           OR (t.petugas_id IS NOT NULL AND p.role = 'petugas')
                       )";
$stmt_total_records = $conn->prepare($total_records_query);
if (!$stmt_total_records) {
    error_log("Error preparing total records query: " . $conn->error);
    die("Error preparing total records query");
}
$stmt_total_records->bind_param("ss", $start_date, $end_date);
$stmt_total_records->execute();
$total_records = $stmt_total_records->get_result()->fetch_assoc()['total'];
$stmt_total_records->close();
$total_pages = ceil($total_records / $items_per_page);

// Fetch transactions with class and petugas information
try {
    $query = "SELECT 
        t.no_transaksi,
        t.jenis_transaksi,
        t.jumlah,
        t.created_at,
        u.nama AS nama_siswa,
        k.nama_kelas,
        tk.nama_tingkatan,
        p.nama AS nama_petugas
        FROM transaksi t 
        JOIN rekening r ON t.rekening_id = r.id 
        JOIN users u ON r.user_id = u.id 
        LEFT JOIN users p ON t.petugas_id = p.id 
        LEFT JOIN kelas k ON u.kelas_id = k.id 
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        WHERE DATE(t.created_at) BETWEEN ? AND ?
        AND t.jenis_transaksi IN ('setor', 'tarik') 
        AND (
            t.petugas_id IS NULL
            OR (t.petugas_id IS NOT NULL AND p.role = 'petugas')
        )
        AND (t.status = 'approved' OR t.status IS NULL)
        ORDER BY t.created_at DESC 
        LIMIT ? OFFSET ?";
    $stmt_trans = $conn->prepare($query);
    if (!$stmt_trans) {
        throw new Exception("Failed to prepare transaction query: " . $conn->error);
    }
    $stmt_trans->bind_param("ssii", $start_date, $end_date, $items_per_page, $offset);
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
}

// Function to format Rupiah
function formatRupiah($amount) {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Rekapitulasi Transaksi - SCHOBANK SYSTEM</title>
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
            --debit-bg: #d1fae5;
            --kredit-bg: #fee2e2;
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

        .filter-section {
            background: white;
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="date"] {
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
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-color: #fff;
            position: relative;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            display: block;
            width: 20px;
            height: 20px;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23666"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>') no-repeat center;
            background-size: 20px;
            cursor: pointer;
            opacity: 0.7;
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
        }

        input[type="date"]::-webkit-datetime-edit {
            padding-right: 30px;
        }

        input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
            transform: scale(1.02);
        }

        .tooltip {
            position: absolute;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.8rem);
            top: -30px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            white-space: nowrap;
        }

        .form-group:hover .tooltip {
            opacity: 1;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
        }

        .summary-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
            animation: slideIn 0.5s ease-out;
        }

        .summary-title {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 10px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 20px;
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
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
            border-radius: 10px;
            transition: var(--transition);
            animation: slideIn 0.5s ease-out;
        }

        .summary-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
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
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: var(--transition);
            animation: slideIn 0.5s ease-out;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
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
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
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
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
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

        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            table-layout: auto;
        }

        .transaction-table th, .transaction-table td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            border-bottom: 1px solid #eee;
            min-width: 100px;
        }

        .transaction-table th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            text-transform: uppercase;
        }

        .transaction-table td {
            background: white;
        }

        .transaction-table tr:hover {
            background-color: var(--bg-light);
        }

        .transaction-type {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
            text-align: center;
            min-width: 90px;
            display: inline-block;
        }

        .type-debit {
            background: var(--debit-bg);
            color: #047857;
        }

        .type-kredit {
            background: var(--kredit-bg);
            color: #b91c1c;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
            width: 100%;
        }

        .pagination-btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
            font-weight: 500;
            transition: var(--transition);
            width: 120px; /* Fixed width for desktop */
            text-align: center;
        }

        .pagination-btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        .pagination-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .empty-state i {
            font-size: clamp(2rem, 4vw, 2.2rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .empty-state p {
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.65);
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

        .success-modal, .error-modal {
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
        }

        .success-modal {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .error-modal {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .success-modal::before, .error-modal::before {
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

        .success-icon, .error-icon {
            font-size: clamp(3.8rem, 8vw, 4.8rem);
            margin: 0 auto 20px;
            animation: bounceIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
        }

        .success-icon {
            color: white;
        }

        .error-icon {
            color: white;
        }

        @keyframes bounceIn {
            0% { transform: scale(0); opacity: 0; }
            50% { transform: scale(1.25); }
            100% { transform: scale(1); opacity: 1; }
        }

        .success-modal h3, .error-modal h3 {
            color: white;
            margin: 0 0 20px;
            font-size: clamp(1.4rem, 3vw, 1.6rem);
            font-weight: 600;
            animation: slideUpText 0.5s ease-out 0.2s both;
        }

        .success-modal p, .error-modal p {
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

            .filter-form {
                flex-direction: column;
                gap: 15px;
            }

            .form-group {
                min-width: 100%;
            }

            .filter-buttons {
                flex-direction: column;
                width: 100%;
            }

            .summary-boxes {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .transaction-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .transaction-table th, .transaction-table td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                min-width: 80px;
            }

            .pagination {
                flex-direction: row;
                justify-content: space-between;
                gap: 10px;
                width: 100%;
            }

            .pagination-btn {
                padding: 10px 15px;
                font-size: clamp(0.85rem, 1.8vw, 0.95rem);
                width: auto; /* Remove fixed width */
                flex: 1; /* Equal width for buttons */
                max-width: 48%; /* Ensure buttons don't exceed half the container */
            }

            .success-modal, .error-modal {
                width: clamp(330px, 90vw, 440px);
                padding: clamp(25px, 5vw, 35px);
                margin: 15px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.5rem, 7vw, 4.5rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.3rem, 2.8vw, 1.5rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
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

            .summary-title {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .empty-state i {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            input[type="date"] {
                min-height: 40px;
            }

            .pagination {
                flex-direction: row;
                justify-content: space-between;
                gap: 8px;
                width: 100%;
            }

            .pagination-btn {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
                width: auto; /* Remove fixed width */
                flex: 1; /* Equal width for buttons */
                max-width: 48%; /* Ensure buttons don't exceed half the container */
            }

            .success-modal, .error-modal {
                width: clamp(310px, 92vw, 380px);
                padding: clamp(20px, 4vw, 30px);
                margin: 10px auto;
            }

            .success-icon, .error-icon {
                font-size: clamp(3.2rem, 6.5vw, 4rem);
            }

            .success-modal h3, .error-modal h3 {
                font-size: clamp(1.2rem, 2.7vw, 1.4rem);
            }

            .success-modal p, .error-modal p {
                font-size: clamp(0.85rem, 2.1vw, 0.95rem);
            }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Rekapitulasi Transaksi</h2>
            <p>Periode: <?= htmlspecialchars(date('d/m/Y', strtotime($start_date))) ?> - <?= htmlspecialchars(date('d/m/Y', strtotime($end_date))) ?></p>
        </div>

        <div id="alertContainer"></div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form id="filterForm" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Dari Tanggal</label>
                    <input type="date" id="start_date" name="start_date" 
                           value="<?= htmlspecialchars($start_date) ?>" required>
                    <span class="tooltip">Pilih tanggal mulai periode</span>
                </div>
                <div class="form-group">
                    <label for="end_date">Sampai Tanggal</label>
                    <input type="date" id="end_date" name="end_date" 
                           value="<?= htmlspecialchars($end_date) ?>" required>
                    <span class="tooltip">Pilih tanggal akhir periode</span>
                </div>
                <div class="filter-buttons">
                    <button type="submit" id="filterButton" class="btn">
                        <span class="btn-content"><i class="fas fa-filter"></i> Filter</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Summary Section -->
        <div class="summary-container">
            <h3 class="summary-title">Ringkasan Rekapitulasi Transaksi</h3>
            <div class="summary-boxes">
                <div class="summary-box">
                    <h3>Total Transaksi</h3>
                    <div class="amount" data-target="<?= $totals['total_transactions'] ?? 0 ?>"><?= $totals['total_transactions'] ?? 0 ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Debit</h3>
                    <div class="amount" data-target="<?= $totals['total_debit'] ?? 0 ?>"><?= formatRupiah($totals['total_debit'] ?? 0) ?></div>
                </div>
                <div class="summary-box">
                    <h3>Total Kredit</h3>
                    <div class="amount" data-target="<?= $totals['total_kredit'] ?? 0 ?>"><?= formatRupiah($totals['total_kredit'] ?? 0) ?></div>
                </div>
                <div class="stat-box balance">
                    <div class="stat-icon"><i class="fas fa-wallet"></i></div>
                    <div class="stat-content">
                        <div class="stat-title">Saldo Bersih</div>
                        <div class="stat-value" data-target="<?= $saldo_bersih ?>"><?= formatRupiah($saldo_bersih) ?></div>
                        <div class="stat-note">Debit - Kredit</div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button id="pdfButton" class="btn">
                    <span class="btn-content"><i class="fas fa-file-pdf"></i> Unduh PDF</span>
                </button>
            </div>

            <?php if ($totals['total_transactions'] > 0): ?>
                <table class="transaction-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Tanggal</th>
                            <th>No Transaksi</th>
                            <th>Nama Siswa</th>
                            <th>Kelas</th>
                            <th>Jenis</th>
                            <th>Jumlah</th>
                            <th>Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $index => $transaction): ?>
                            <?php
                            $typeClass = $transaction['jenis_transaksi'] === 'setor' ? 'type-debit' : 'type-kredit';
                            $displayType = $transaction['jenis_transaksi'] === 'setor' ? 'Debit' : 'Kredit';
                            $noUrut = (($current_page - 1) * $items_per_page) + $index + 1;
                            // Combine nama_tingkatan and nama_kelas
                            $nama_kelas = trim($transaction['nama_tingkatan'] ?? '') && trim($transaction['nama_kelas'] ?? '') 
                                ? htmlspecialchars($transaction['nama_tingkatan'] . ' ' . $transaction['nama_kelas'])
                                : 'N/A';
                            $nama_petugas = trim($transaction['nama_petugas'] ?? '') ?: 'Admin';
                            ?>
                            <tr>
                                <td><?= $noUrut ?></td>
                                <td><?= date('d/m/Y', strtotime($transaction['created_at'])) ?></td>
                                <td><?= htmlspecialchars($transaction['no_transaksi'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($transaction['nama_siswa'] ?? 'N/A') ?></td>
                                <td><?= $nama_kelas ?></td>
                                <td><span class="transaction-type <?= $typeClass ?>"><?= $displayType ?></span></td>
                                <td><?= formatRupiah($transaction['jumlah']) ?></td>
                                <td><?= htmlspecialchars($nama_petugas) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="pagination">
                    <button id="prev-page" class="pagination-btn" <?= $current_page <= 1 ? 'disabled' : '' ?>>Kembali</button>
                    <button id="next-page" class="pagination-btn" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>Lanjut</button>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <p>Tidak ada transaksi dalam periode ini.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Hidden input to pass total_transactions to JavaScript -->
        <input type="hidden" id="totalTransactions" value="<?= htmlspecialchars($totals['total_transactions']) ?>">
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

            // Count-up animation function
            function countUp(element, target, duration, isCurrency) {
                let start = 0;
                const stepTime = 50; // Update every 50ms
                const steps = Math.ceil(duration / stepTime);
                const increment = target / steps;
                let current = start;

                element.classList.add('counting');

                const updateCount = () => {
                    current += increment;
                    if (current >= target) {
                        current = target;
                        element.classList.remove('counting');
                        element.classList.add('finished');
                    }
                    if (isCurrency) {
                        element.textContent = 'Rp ' + Math.round(current).toLocaleString('id-ID', { minimumFractionDigits: 0 });
                    } else {
                        element.textContent = Math.round(current);
                    }
                    if (current < target) {
                        setTimeout(updateCount, stepTime);
                    }
                };

                updateCount();
            }

            // Apply count-up animation to summary amounts
            const amounts = document.querySelectorAll('.summary-box .amount, .stat-box .stat-value');
            amounts.forEach(amount => {
                const target = parseFloat(amount.getAttribute('data-target'));
                const isCurrency = amount.classList.contains('amount') && (
                    amount.parentElement.querySelector('h3').textContent.includes('Debit') || 
                    amount.parentElement.querySelector('h3').textContent.includes('Kredit')
                ) || amount.classList.contains('stat-value');
                countUp(amount, target, 1500, isCurrency); // 1.5s duration
            });

            // Alert function
            function showAlert(message, type) {
                const alertContainer = document.getElementById('alertContainer');
                const existingAlerts = alertContainer.querySelectorAll('.success-overlay');
                existingAlerts.forEach(alert => {
                    alert.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alert.remove(), 500);
                });
                const alertDiv = document.createElement('div');
                alertDiv.className = 'success-overlay';
                alertDiv.innerHTML = `
                    <div class="${type}-modal">
                        <div class="${type}-icon">
                            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                        </div>
                        <h3>${type === 'success' ? 'Berhasil' : 'Gagal'}</h3>
                        <p>${message}</p>
                    </div>
                `;
                alertContainer.appendChild(alertDiv);
                setTimeout(() => {
                    alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alertDiv.remove(), 500);
                }, 5000);
                alertDiv.addEventListener('click', () => {
                    alertDiv.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => alertDiv.remove(), 500);
                });
            }

            // Filter form handling
            const filterForm = document.getElementById('filterForm');
            const filterButton = document.getElementById('filterButton');
            const pdfButton = document.getElementById('pdfButton');
            const totalTransactions = parseInt(document.getElementById('totalTransactions').value);

            if (filterForm && filterButton) {
                filterForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    if (filterForm.classList.contains('submitting')) return;
                    filterForm.classList.add('submitting');

                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal yang valid', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        filterForm.classList.remove('submitting');
                        return;
                    }

                    filterButton.classList.add('loading');
                    filterButton.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';

                    const url = new URL(window.location);
                    url.searchParams.set('start_date', startDate);
                    url.searchParams.set('end_date', endDate);
                    url.searchParams.delete('page'); // Reset to first page
                    window.location.href = url.toString();
                });
            }

            // PDF download handling
            if (pdfButton) {
                pdfButton.addEventListener('click', function() {
                    if (pdfButton.classList.contains('loading')) return;

                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;

                    if (!startDate || !endDate) {
                        showAlert('Silakan pilih tanggal untuk mengunduh', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        showAlert('Tanggal awal tidak boleh lebih dari tanggal akhir', 'error');
                        return;
                    }
                    if (totalTransactions === 0) {
                        showAlert('Tidak ada transaksi untuk diunduh dalam periode ini', 'error');
                        return;
                    }

                    pdfButton.classList.add('loading');
                    pdfButton.innerHTML = '<span class="btn-content"><i class="fas fa-spinner"></i> Memproses...</span>';

                    const pdfWindow = window.open(`download_rekap_transaksi.php?start_date=${startDate}&end_date=${endDate}&format=pdf`, '_blank');
                    if (!pdfWindow) {
                        showAlert('Gagal membuka laporan. Pastikan popup tidak diblokir oleh browser.', 'error');
                    }

                    setTimeout(() => {
                        pdfButton.classList.remove('loading');
                        pdfButton.innerHTML = '<span class="btn-content"><i class="fas fa-file-pdf"></i> Unduh PDF</span>';
                    }, 1000);
                });
            }

            // Pagination handling
            const prevPageButton = document.getElementById('prev-page');
            const nextPageButton = document.getElementById('next-page');
            const currentPage = <?= $current_page ?>;
            const totalPages = <?= $total_pages ?>;

            if (prevPageButton) {
                prevPageButton.addEventListener('click', function() {
                    if (currentPage > 1) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage - 1);
                        window.location.href = url.toString();
                    }
                });
            }

            if (nextPageButton) {
                nextPageButton.addEventListener('click', function() {
                    if (currentPage < totalPages) {
                        const url = new URL(window.location);
                        url.searchParams.set('page', currentPage + 1);
                        window.location.href = url.toString();
                    }
                });
            }

            // Prevent text selection on double-click
            document.addEventListener('mousedown', function(e) {
                if (e.detail > 1) {
                    e.preventDefault();
                }
            });

            // Keyboard accessibility
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && document.activeElement.tagName !== 'BUTTON') {
                    filterForm.dispatchEvent(new Event('submit'));
                }
            });

            // Ensure responsive table scroll on mobile
            const table = document.querySelector('.transaction-table');
            if (table) {
                table.parentElement.style.overflowX = 'auto';
            }

            // Fix touch issues in Safari
            document.addEventListener('touchstart', function(e) {
                e.stopPropagation();
            }, { passive: true });
        });
    </script>
</body>
</html>