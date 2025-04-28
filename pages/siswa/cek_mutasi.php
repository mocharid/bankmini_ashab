<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Fetch user name
$query = "SELECT nama FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$nama_pemilik = $user_data['nama'] ?? 'Pengguna';

// Fetch account details
$query = "SELECT id, no_rekening FROM rekening WHERE user_id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$rekening_data = $result->fetch_assoc();

if (!$rekening_data) {
    $error = "Rekening tidak ditemukan!";
} else {
    $rekening_id = $rekening_data['id'];
    $no_rekening = $rekening_data['no_rekening'];

    // Date filter handling
    $is_filtered = isset($_GET['start_date']) && isset($_GET['end_date']);
    $filter_type = $_GET['filter_type'] ?? 'custom';

    if ($filter_type === 'yesterday') {
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = $start_date;
    } elseif ($filter_type === 'last7days') {
        $start_date = date('Y-m-d', strtotime('-7 days'));
        $end_date = date('Y-m-d');
    } elseif ($filter_type === 'last30days') {
        $start_date = date('Y-m-d', strtotime('-30 days'));
        $end_date = date('Y-m-d');
    } else {
        $start_date = $is_filtered ? $_GET['start_date'] : date('Y-m-01');
        $end_date = $is_filtered ? $_GET['end_date'] : date('Y-m-d');
    }

    // Pagination handling
    $items_per_page = 10;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($current_page - 1) * $items_per_page;

    // Fetch transactions grouped by date
    $query = "SELECT 
                t.no_transaksi, 
                t.jenis_transaksi, 
                t.jumlah, 
                t.created_at, 
                t.rekening_id, 
                t.rekening_tujuan_id,
                r1.no_rekening as rekening_asal,
                r2.no_rekening as rekening_tujuan,
                u1.nama as nama_pengirim,
                u2.nama as nama_penerima,
                p.nama as petugas_nama,
                pt.petugas1_nama,
                pt.petugas2_nama,
                DATE(t.created_at) as transaction_date
              FROM transaksi t
              LEFT JOIN rekening r1 ON t.rekening_id = r1.id
              LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
              LEFT JOIN users u1 ON r1.user_id = u1.id
              LEFT JOIN users u2 ON r2.user_id = u2.id
              LEFT JOIN users p ON t.petugas_id = p.id
              LEFT JOIN petugas_tugas pt ON DATE(t.created_at) = pt.tanggal
              WHERE (r1.user_id = ? OR r2.user_id = ?)";
    
    $params = ["ii", $user_id, $user_id];
    if ($is_filtered || in_array($filter_type, ['yesterday', 'last7days', 'last30days'])) {
        $query .= " AND t.created_at BETWEEN ? AND ?";
        $params[0] .= "ss";
        $params[] = $start_date;
        $params[] = $end_date . ' 23:59:59';
    }
    
    $query .= " ORDER BY t.created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param(...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $transactions[$row['transaction_date']][] = $row;
    }

    // Pagination logic
    $total_dates = count($transactions);
    $total_pages = ceil($total_dates / $items_per_page);
    $transactions_paginated = array_slice($transactions, $offset, $items_per_page, true);
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Cek Mutasi - SCHOBANK SYSTEM</title>
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
            --success-color: #10b981;
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
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 15px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
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
        }

        .back-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .main-content {
            flex: 1;
            padding: 25px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
            animation: fadeInBanner 0.8s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 70%);
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
            margin-bottom: 12px;
            font-size: clamp(1.4rem, 2.6vw, 1.6rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .welcome-banner p {
            font-size: clamp(0.85rem, 1.7vw, 0.95rem);
            position: relative;
            z-index: 1;
            opacity: 0.95;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .welcome-banner .detail-row {
            display: flex;
            align-items: center;
        }

        .welcome-banner .detail-label {
            width: 110px;
            font-weight: 500;
            color: #ffffff;
        }

        .welcome-banner .detail-value {
            flex: 1;
            color: #ffffff;
        }

        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: flex-end;
            margin-bottom: 20px;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .filter-group input[type="date"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
            background: #f8faff;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            -webkit-user-select: text;
            user-select: text;
        }

        .filter-group input[type="date"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(12, 77, 162, 0.1);
        }

        .filter-group input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0.7;
            cursor: pointer;
            padding: 6px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z'/%3E%3C/svg%3E") no-repeat center;
            background-size: 16px;
        }

        .filter-buttons {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
        }

        .filter-presets {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            min-width: 120px;
            justify-content: center;
            position: relative;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-download {
            background-color: var(--secondary-color);
        }

        .btn-download:hover {
            background-color: var(--secondary-dark);
        }

        .btn-preset {
            background-color: #e0e0e0;
            color: var(--text-primary);
            min-width: 110px;
            padding: 10px 20px;
        }

        .btn-preset:hover {
            background-color: #d0d0d0;
        }

        .btn-preset.active {
            background-color: var(--primary-color);
            color: white;
        }

        .spinner {
            display: none;
            width: 14px;
            height: 14px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn.loading .spinner {
            display: block;
        }

        .btn.loading .btn-text {
            display: none;
        }

        .alert {
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .alert.hide {
            animation: slideOut 0.5s ease-out forwards;
        }

        @keyframes slideOut {
            from { transform: translateY(0); opacity: 1; }
            to { transform: translateY(-20px); opacity: 0; }
        }

        .alert-error {
            background-color: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #fecaca;
        }

        .transactions-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .transactions-container:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-3px);
        }

        .date-group {
            margin-bottom: 30px;
            padding-bottom: 20px;
        }

        .date-group-header {
            font-size: clamp(1.1rem, 2.2vw, 1.2rem);
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 12px;
            padding: 12px 20px;
            background: linear-gradient(90deg, var(--primary-light) 0%, #ffffff 100%);
            border-left: 4px solid var(--primary-color);
            border-radius: 6px;
            box-shadow: var(--shadow-sm);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .date-group-header:hover {
            transform: translateX(5px);
        }

        .date-group-header .date-text {
            font-weight: 600;
        }

        .date-group-header .transaction-count {
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            color: var(--text-secondary);
            font-weight: 500;
        }

        .table-container {
            position: relative;
            width: 100%;
            max-height: 400px;
            overflow-y: auto;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 6px;
        }

        table {
            width: 100%;
            min-width: 600px;
            border-collapse: collapse;
            table-layout: auto;
            user-select: none;
            -webkit-user-drag: none;
            -webkit-touch-callout: none;
        }

        table, th, td {
            -webkit-user-drag: none;
            user-drag: none;
            dragable: false;
        }

        th, td {
            padding: 12px;
            text-align: left;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            text-transform: uppercase;
        }

        td {
            color: var(--text-primary);
        }

        tr:nth-child(even) {
            background-color: #fafafa;
        }

        tr:hover {
            background-color: #f8faff;
        }

        .amount-debit, .amount-kredit {
            text-align: right;
            font-weight: 500;
        }

        .amount-debit {
            color: var(--success-color);
        }

        .amount-kredit {
            color: var(--danger-color);
        }

        .no-transaksi {
            border: none;
        }

        .detail-cell {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            position: relative;
        }

        .detail-cell .tooltip {
            visibility: hidden;
            width: 200px;
            background-color: #ffffff;
            color: var(--text-primary);
            text-align: left;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 10;
            top: 100%;
            left: 0;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            box-shadow: var(--shadow-md);
            border: 1px solid #ddd;
            transform: translateY(10px);
        }

        .detail-cell:hover .tooltip {
            visibility: visible;
            opacity: 1;
            transform: translateY(0);
        }

        .petugas-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .petugas-label {
            font-weight: 500;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .petugas-item {
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(1.3rem, 2.8vw, 1.6rem);
            color: #d1d5db;
            margin-bottom: 8px;
        }

        .no-data p {
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

        .pagination button, .pagination a {
            background-color: #e0e0e0;
            color: var(--text-primary);
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            font-weight: 500;
        }

        .pagination button:hover, .pagination a:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .pagination button:disabled {
            background-color: #f0f0f0;
            color: #aaa;
            cursor: not-allowed;
        }

        .pagination .active {
            background-color: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px 20px;
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .main-content {
                padding: 20px;
            }

            .welcome-banner {
                padding: 20px;
                margin-bottom: 20px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.4vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .welcome-banner .detail-label {
                width: 100px;
            }

            .filter-card {
                padding: 15px;
                gap: 15px;
                margin-bottom: 20px;
            }

            .filter-row {
                flex-direction: column;
                gap: 15px;
                margin-bottom: 15px;
            }

            .filter-group {
                min-width: 100%;
            }

            .filter-group label {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
                margin-bottom: 6px;
            }

            .filter-group input[type="date"] {
                padding: 8px 10px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .filter-buttons {
                flex-direction: row;
                gap: 12px;
                justify-content: space-between;
            }

            .filter-presets {
                gap: 12px;
                margin-bottom: 20px;
            }

            .btn {
                width: 48%;
                padding: 8px 20px;
                font-size: clamp(0.85rem, 1.7vw, 0.95rem);
                min-width: unset;
            }

            .btn-preset {
                width: 32%;
                padding: 8px 16px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .transactions-container {
                padding: 15px;
            }

            .date-group {
                margin-bottom: 25px;
                padding-bottom: 15px;
            }

            .date-group-header {
                font-size: clamp(1rem, 2vw, 1.1rem);
                padding: 10px 15px;
                margin-bottom: 10px;
            }

            .date-group-header .transaction-count {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            th, td {
                padding: 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .petugas-item {
                max-width: 120px;
            }

            .pagination {
                gap: 8px;
                margin-top: 15px;
            }

            .pagination button, .pagination a {
                padding: 6px 10px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .top-nav {
                padding: 10px 15px;
            }

            .main-content {
                padding: 15px;
            }

            .welcome-banner {
                padding: 15px;
                margin-bottom: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.2vw, 1.3rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .welcome-banner .detail-label {
                width: 90px;
            }

            .filter-card {
                padding: 12px;
                gap: 12px;
                margin-bottom: 15px;
            }

            .filter-row {
                gap: 12px;
                margin-bottom: 12px;
            }

            .filter-group label {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .filter-group input[type="date"] {
                padding: 7px 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .filter-buttons {
                gap: 10px;
            }

            .filter-presets {
                gap: 10px;
                margin-bottom: 15px;
            }

            .btn {
                width: 48%;
                padding: 7px 16px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .btn-preset {
                width: 31%;
                padding: 7px 12px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .transactions-container {
                padding: 12px;
            }

            .date-group {
                margin-bottom: 20px;
                padding-bottom: 12px;
            }

            .date-group-header {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
                padding: 8px 12px;
            }

            .no-data {
                padding: 15px;
            }

            .no-data i {
                font-size: clamp(1.2rem, 2.5vw, 1.5rem);
            }

            .pagination {
                gap: 6px;
                margin-top: 12px;
            }

            .pagination button, .pagination a {
                padding: 5px 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
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
        <div style="width: 36px;"></div>
    </nav>

    <div class="main-content">
        <div class="welcome-banner">
            <h2 style="font-family: 'Poppins', sans-serif; font-weight: 700;">
                <i class="fas fa-file-invoice-dollar"></i> Mutasi Rekening
            </h2>
            <p class="detail-row" style="font-family: 'Poppins', sans-serif; font-weight: 500;">
                <span class="detail-label">Nomor Rekening:</span>
                <span class="detail-value"><?= htmlspecialchars($no_rekening ?? 'N/A') ?></span>
            </p>
            <p class="detail-row" style="font-family: 'Poppins', sans-serif; font-weight: 500;">
                <span class="detail-label">Nama Pemilik:</span>
                <span class="detail-value"><?= htmlspecialchars($nama_pemilik) ?></span>
            </p>
        </div>

        <div id="alertContainer">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!isset($error)): ?>
            <div class="filter-card">
                <form id="filterForm" method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="start_date">Dari Tanggal</label>
                            <input type="date" id="start_date" name="start_date" 
                                   value="<?= htmlspecialchars($start_date ?? date('Y-m-01')) ?>" 
                                   required>
                        </div>
                        <div class="filter-group">
                            <label for="end_date">Sampai Tanggal</label>
                            <input type="date" id="end_date" name="end_date" 
                                   value="<?= htmlspecialchars($end_date ?? date('Y-m-d')) ?>" 
                                   required>
                        </div>
                    </div>
                    <div class="filter-presets">
                        <button type="button" class="btn btn-preset <?= $filter_type === 'yesterday' ? 'active' : '' ?>" 
                                onclick="setFilter('yesterday')">Kemarin</button>
                        <button type="button" class="btn btn-preset <?= $filter_type === 'last7days' ? 'active' : '' ?>" 
                                onclick="setFilter('last7days')">7 Hari</button>
                        <button type="button" class="btn btn-preset <?= $filter_type === 'last30days' ? 'active' : '' ?>" 
                                onclick="setFilter('last30days')">1 Bulan</button>
                    </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn" id="filterBtn">
                            <span class="spinner"></span>
                            <span class="btn-text"><i class="fas fa-filter"></i> Filter</span>
                        </button>
                        <button type="button" onclick="downloadMutasi()" class="btn btn-download" id="downloadBtn">
                            <span class="spinner"></span>
                            <span class="btn-text"><i class="fas fa-download"></i> Download</span>
                        </button>
                    </div>
                    <input type="hidden" name="filter_type" id="filter_type" value="<?= htmlspecialchars($filter_type) ?>">
                    <input type="hidden" name="page" id="page" value="<?= htmlspecialchars($current_page) ?>">
                </form>
            </div>

            <div class="transactions-container">
                <?php if (empty($transactions_paginated)): ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>Tidak ada transaksi dalam periode ini</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($transactions_paginated as $date => $daily_transactions): ?>
                        <div class="date-group">
                            <div class="date-group-header">
                                <span class="date-text"><?= date('d F Y', strtotime($date)) ?></span>
                                <span class="transaction-count"><?= count($daily_transactions) ?> Transaksi</span>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Jenis</th>
                                            <th>Debit</th>
                                            <th>Kredit</th>
                                            <th>No Transaksi</th>
                                            <th>Detail</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($daily_transactions as $transaksi): 
                                            $is_transfer_masuk = $transaksi['jenis_transaksi'] === 'transfer' && 
                                                                $transaksi['rekening_tujuan'] === $no_rekening;
                                            $debit_amount = ($transaksi['jenis_transaksi'] === 'setor' || $is_transfer_masuk) 
                                                          ? $transaksi['jumlah'] : 0;
                                            $kredit_amount = ($transaksi['jenis_transaksi'] === 'tarik' || 
                                                             ($transaksi['jenis_transaksi'] === 'transfer' && 
                                                              $transaksi['rekening_asal'] === $no_rekening)) 
                                                          ? $transaksi['jumlah'] : 0;
                                        ?>
                                            <tr>
                                                <td>
                                                    <?php if ($transaksi['jenis_transaksi'] === 'setor'): ?>
                                                        Debit
                                                    <?php elseif ($transaksi['jenis_transaksi'] === 'tarik'): ?>
                                                        Kredit
                                                    <?php elseif ($transaksi['jenis_transaksi'] === 'transfer'): ?>
                                                        <?php if ($transaksi['rekening_asal'] === $no_rekening): ?>
                                                            Transfer Keluar
                                                        <?php else: ?>
                                                            Transfer Masuk
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount-debit">
                                                    <?php if ($debit_amount > 0): ?>
                                                        Rp <?= number_format($debit_amount, 0, ',', '.') ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="amount-kredit">
                                                    <?php if ($kredit_amount > 0): ?>
                                                        Rp <?= number_format($kredit_amount, 0, ',', '.') ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td class="no-transaksi">
                                                    <?= htmlspecialchars($transaksi['no_transaksi'] ?? 'N/A') ?>
                                                </td>
                                                <td class="detail-cell">
                                                    <?php if ($transaksi['jenis_transaksi'] === 'setor' || $transaksi['jenis_transaksi'] === 'tarik'): ?>
                                                        <div class="petugas-list">
                                                            <span class="petugas-label">Oleh:</span>
                                                            <?php if ($transaksi['petugas1_nama']): ?>
                                                                <span class="petugas-item" title="<?= htmlspecialchars($transaksi['petugas1_nama']) ?>">
                                                                    <?= htmlspecialchars($transaksi['petugas1_nama']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="petugas-item">Tidak tersedia</span>
                                                            <?php endif; ?>
                                                            <?php if ($transaksi['petugas2_nama']): ?>
                                                                <span class="petugas-item" title="<?= htmlspecialchars($transaksi['petugas2_nama']) ?>">
                                                                    <?= htmlspecialchars($transaksi['petugas2_nama']) ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="petugas-item">Tidak tersedia</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="tooltip">
                                                            <?php if ($transaksi['petugas1_nama'] || $transaksi['petugas2_nama']): ?>
                                                                <?= $transaksi['petugas1_nama'] ? htmlspecialchars($transaksi['petugas1_nama']) . "<br>" : "" ?>
                                                                <?= $transaksi['petugas2_nama'] ? htmlspecialchars($transaksi['petugas2_nama']) : "" ?>
                                                            <?php else: ?>
                                                                Tidak ada petugas terkait
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php elseif ($transaksi['jenis_transaksi'] === 'transfer'): ?>
                                                        <?php if ($transaksi['rekening_asal'] === $no_rekening): ?>
                                                            Ke: <?= htmlspecialchars($transaksi['rekening_tujuan'] ?? 'N/A') ?> 
                                                            (<?= htmlspecialchars($transaksi['nama_penerima'] ?? 'N/A') ?>)
                                                        <?php else: ?>
                                                            Dari: <?= htmlspecialchars($transaksi['rekening_asal'] ?? 'N/A') ?> 
                                                            (<?= htmlspecialchars($transaksi['nama_pengirim'] ?? 'N/A') ?>)
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <button onclick="changePage(<?= $current_page - 1 ?>)" <?= $current_page <= 1 ? 'disabled' : '' ?>>
                                <i class="fas fa-chevron-left"></i> Sebelumnya
                            </button>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>&filter_type=<?= htmlspecialchars($filter_type) ?>&page=<?= $i ?>" 
                                   class="<?= $i === $current_page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <button onclick="changePage(<?= $current_page + 1 ?>)" <?= $current_page >= $total_pages ? 'disabled' : '' ?>>
                                Berikutnya <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
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

        function setFilter(type) {
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const filterTypeInput = document.getElementById('filter_type');
            const currentFilterType = filterTypeInput.value;
            const today = new Date();
            const formatDate = (date) => date.toISOString().split('T')[0];

            if (currentFilterType === type) {
                startDateInput.value = formatDate(new Date(today.getFullYear(), today.getMonth(), 1));
                endDateInput.value = formatDate(today);
                filterTypeInput.value = 'custom';
                document.querySelectorAll('.btn-preset').forEach(btn => btn.classList.remove('active'));
            } else {
                if (type === 'yesterday') {
                    const yesterday = new Date(today);
                    yesterday.setDate(today.getDate() - 1);
                    startDateInput.value = formatDate(yesterday);
                    endDateInput.value = formatDate(yesterday);
                } else if (type === 'last7days') {
                    const last7days = new Date(today);
                    last7days.setDate(today.getDate() - 7);
                    startDateInput.value = formatDate(last7days);
                    endDateInput.value = formatDate(today);
                } else if (type === 'last30days') {
                    const last30days = new Date(today);
                    last30days.setDate(today.getDate() - 30);
                    startDateInput.value = formatDate(last30days);
                    endDateInput.value = formatDate(today);
                }
                filterTypeInput.value = type;
                document.querySelectorAll('.btn-preset').forEach(btn => btn.classList.remove('active'));
                document.querySelector(`.btn-preset[onclick="setFilter('${type}')"]`).classList.add('active');
            }

            document.getElementById('page').value = 1; // Reset to page 1 on filter change
            document.getElementById('filterForm').submit();
        }

        function downloadMutasi() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (!startDate || !endDate) {
                showAlert('Silakan pilih tanggal untuk mengunduh', 'error');
                return;
            }

            const downloadBtn = document.getElementById('downloadBtn');
            downloadBtn.classList.add('loading');
            downloadBtn.disabled = true;
            
            window.open(`cetak_mutasi.php?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}`, '_blank');
            
            setTimeout(() => {
                downloadBtn.classList.remove('loading');
                downloadBtn.disabled = false;
            }, 1000);
        }

        function changePage(page) {
            if (page < 1 || page > <?= $total_pages ?>) return;
            document.getElementById('page').value = page;
            document.getElementById('filterForm').submit();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const filterForm = document.getElementById('filterForm');
            const filterBtn = document.getElementById('filterBtn');

            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;
                    
                    if (!startDate || !endDate) {
                        e.preventDefault();
                        showAlert('Silakan pilih tanggal yang valid', 'error');
                        return;
                    }
                    if (new Date(startDate) > new Date(endDate)) {
                        e.preventDefault();
                        showAlert('Tanggal awal tidak boleh lebih besar dari tanggal akhir', 'error');
                        return;
                    }
                    
                    filterBtn.classList.add('loading');
                    filterBtn.disabled = true;
                });
            }

            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
            });

            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', () => {
                    const today = new Date().toISOString().split('T')[0];
                    if (input.value > today) {
                        showAlert('Tanggal tidak boleh di masa depan!', 'error');
                        input.value = today;
                    }
                    document.getElementById('filter_type').value = 'custom';
                    document.getElementById('page').value = 1; // Reset to page 1 on date change
                    document.querySelectorAll('.btn-preset').forEach(btn => btn.classList.remove('active'));
                });
            });

            const tables = document.querySelectorAll('table');
            tables.forEach(table => {
                table.addEventListener('dragstart', (e) => e.preventDefault());
                table.addEventListener('touchmove', (e) => {
                    const touch = e.touches[0];
                    const deltaX = touch.clientX - (table.dataset.lastX || touch.clientX);
                    table.dataset.lastX = touch.clientX;
                    if (Math.abs(deltaX) > 0) {
                        e.preventDefault();
                    }
                }, { passive: false });
                table.addEventListener('touchend', () => {
                    delete table.dataset.lastX;
                });
            });
        });

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
    </script>
</body>
</html>