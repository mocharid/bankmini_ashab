<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Ambil nama pengguna
$query = "SELECT nama FROM users WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user_data = $result->fetch_assoc();
$nama_pemilik = $user_data['nama'] ?? 'Pengguna';

// Ambil rekening pengguna
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

    // Pagination
    $records_per_page = 5;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $records_per_page;

    // Filter
    $is_filtered = isset($_GET['start_date']) && isset($_GET['end_date']) && isset($_GET['tab']);
    $start_date = $is_filtered ? $_GET['start_date'] : null;
    $end_date = $is_filtered ? $_GET['end_date'] : null;
    $transaction_type = $is_filtered ? $_GET['tab'] : 'all';

    // Query transaksi (with pagination)
    $query = "SELECT 
                t.no_transaksi, 
                t.jenis_transaksi, 
                t.jumlah, 
                t.status, 
                t.created_at, 
                t.rekening_id, 
                t.rekening_tujuan_id,
                r1.no_rekening as rekening_asal,
                r2.no_rekening as rekening_tujuan,
                u1.nama as nama_pengirim,
                u2.nama as nama_penerima,
                p.nama as petugas_nama,
                pt.petugas1_nama,
                pt.petugas2_nama
              FROM transaksi t
              LEFT JOIN rekening r1 ON t.rekening_id = r1.id
              LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
              LEFT JOIN users u1 ON r1.user_id = u1.id
              LEFT JOIN users u2 ON r2.user_id = u2.id
              LEFT JOIN users p ON t.petugas_id = p.id
              LEFT JOIN petugas_tugas pt ON DATE(t.created_at) = pt.tanggal
              WHERE (r1.user_id = ? OR r2.user_id = ?)";
    
    $params = ["ii", $user_id, $user_id];
    if ($is_filtered) {
        $query .= " AND t.created_at BETWEEN ? AND ?";
        $params[0] .= "ss";
        $params[] = $start_date;
        $params[] = $end_date . ' 23:59:59';
        
        if ($transaction_type !== 'all') {
            $query .= " AND t.jenis_transaksi = ?";
            $params[0] .= "s";
            $params[] = $transaction_type;
        }
    }
    
    $query .= " ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
    $params[0] .= "ii";
    $params[] = $records_per_page;
    $params[] = $offset;

    $stmt = $conn->prepare($query);
    $stmt->bind_param(...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = $result->fetch_all(MYSQLI_ASSOC);

    // Log transaksi untuk debug
    error_log("Cek Mutasi Transactions (Page $page): " . print_r($transactions, true));

    // Hitung total transaksi untuk pagination
    $query_count = "SELECT COUNT(*) as total 
                    FROM transaksi t
                    LEFT JOIN rekening r1 ON t.rekening_id = r1.id
                    LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
                    WHERE (r1.user_id = ? OR r2.user_id = ?)";
    $count_params = ["ii", $user_id, $user_id];
    if ($is_filtered) {
        $query_count .= " AND t.created_at BETWEEN ? AND ?";
        $count_params[0] .= "ss";
        $count_params[] = $start_date;
        $count_params[] = $end_date . ' 23:59:59';
        
        if ($transaction_type !== 'all') {
            $query_count .= " AND t.jenis_transaksi = ?";
            $count_params[0] .= "s";
            $count_params[] = $transaction_type;
        }
    }

    $stmt_count = $conn->prepare($query_count);
    $stmt_count->bind_param(...$count_params);
    $stmt_count->execute();
    $total_result = $stmt_count->get_result();
    $total_transactions = $total_result->fetch_assoc()['total'];
    $total_pages = max(1, ceil($total_transactions / $records_per_page));

    // Hitung ringkasan total (tidak terpengaruh filter jenis transaksi)
    $query_summary = "SELECT 
                        t.jenis_transaksi, 
                        t.jumlah, 
                        r1.no_rekening as rekening_asal,
                        r2.no_rekening as rekening_tujuan
                      FROM transaksi t
                      LEFT JOIN rekening r1 ON t.rekening_id = r1.id
                      LEFT JOIN rekening r2 ON t.rekening_tujuan_id = r2.id
                      WHERE (r1.user_id = ? OR r2.user_id = ?)";
    $summary_params = ["ii", $user_id, $user_id];
    if ($is_filtered) {
        $query_summary .= " AND t.created_at BETWEEN ? AND ?";
        $summary_params[0] .= "ss";
        $summary_params[] = $start_date;
        $summary_params[] = $end_date . ' 23:59:59';
    }

    $stmt_summary = $conn->prepare($query_summary);
    $stmt_summary->bind_param(...$summary_params);
    $stmt_summary->execute();
    $summary_result = $stmt_summary->get_result();

    $total_setor = 0;
    $total_tarik = 0;
    $total_transfer_masuk = 0;
    $total_transfer_keluar = 0;

    while ($row = $summary_result->fetch_assoc()) {
        if ($row['jenis_transaksi'] === 'setor') {
            $total_setor += $row['jumlah'];
        } elseif ($row['jenis_transaksi'] === 'tarik') {
            $total_tarik += $row['jumlah'];
        } elseif ($row['jenis_transaksi'] === 'transfer') {
            if ($row['rekening_tujuan'] === $no_rekening) {
                $total_transfer_masuk += $row['jumlah'];
            } elseif ($row['rekening_asal'] === $no_rekening) {
                $total_transfer_keluar += $row['jumlah'];
            }
        }
    }
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
            padding: 12px 20px;
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
            padding: 15px;
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 20px;
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
            font-size: clamp(1.5rem, 2.8vw, 1.8rem);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .welcome-banner p {
            font-size: clamp(0.9rem, 1.8vw, 1rem);
            position: relative;
            z-index: 1;
            opacity: 0.95;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .welcome-banner p i {
            font-size: clamp(1rem, 2vw, 1.1rem);
            color: rgba(255, 255, 255, 0.8);
        }

        .welcome-banner p strong {
            font-weight: 500;
            color: #ffffff;
        }

        /* Filter Card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 20px;
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
            min-width: 160px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .filter-group input[type="date"],
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            transition: var(--transition);
            background: #f8faff;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            -webkit-user-select: text;
            user-select: text;
        }

        .filter-group input[type="date"]:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(12, 77, 162, 0.1);
        }

        .filter-group input[type="date"] {
            position: relative;
        }

        .filter-group input[type="date"]::-webkit-calendar-picker-indicator {
            opacity: 0.7;
            cursor: pointer;
            padding: 8px;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23333' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4H1z'/%3E%3C/svg%3E") no-repeat center;
            background-size: 16px;
        }

        .filter-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23333' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 30px;
        }

        .filter-buttons {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            min-width: 120px;
            justify-content: center;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-filter {
            background-color: var(--primary-color);
        }

        .btn-filter:hover {
            background-color: var(--primary-dark);
        }

        .btn-print {
            background-color: var(--secondary-color);
        }

        .btn-print:hover {
            background-color: var(--secondary-dark);
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
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

        .transactions-container h3 {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.1rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            justify-content: space-between;
        }

        .summary-card {
            flex: 1;
            min-width: 200px;
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            animation: slideInCard 0.5s ease-out forwards;
            cursor: pointer;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
        }

        .summary-card.setoran {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .summary-card.penarikan {
            background: linear-gradient(135deg, #f44336 0%, #b91c1c 100%);
            color: white;
        }

        .summary-card.transfer-masuk {
            background: linear-gradient(135deg, #1e88e5 0%, #1565c0 100%);
            color: white;
        }

        .summary-card.transfer-keluar {
            background: linear-gradient(135deg, #ff9800 0%, #f57c00 100%);
            color: white;
        }

        .summary-card .icon {
            font-size: clamp(1.3rem, 2.5vw, 1.5rem);
            margin-bottom: 8px;
            opacity: 0.9;
        }

        .summary-card .label {
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            font-weight: 500;
            opacity: 0.9;
        }

        .summary-card .amount {
            font-size: clamp(1rem, 2vw, 1.1rem);
            font-weight: 600;
            margin-top: 6px;
        }

        @keyframes slideInCard {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .summary-card .tooltip {
            visibility: hidden;
            width: 180px;
            background-color: var(--primary-dark);
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
        }

        .summary-card:hover .tooltip {
            visibility: visible;
            opacity: 1;
        }

        .table-container {
            position: relative;
            width: 100%;
            overflow-x: auto; /* Horizontal scroll for wide tables on small screens */
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: auto;
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
        }

        td {
            color: var(--text-primary);
        }

        tr:hover {
            background-color: #f8faff;
        }

        .no-column {
            width: 60px;
            text-align: center;
        }

        .no-transaksi-cell {
            min-width: 100px;
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .detail-cell {
            min-width: 120px;
            max-width: 250px;
            white-space: normal;
            word-wrap: break-word;
            position: relative;
        }

        .detail-cell .tooltip {
            visibility: hidden;
            width: 220px;
            background-color: #ffffff;
            color: var(--text-primary);
            text-align: left;
            border-radius: 8px;
            padding: 10px;
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
            gap: 5px;
        }

        .petugas-label {
            font-weight: 500;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .petugas-item {
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }

        .amount-positive {
            color: var(--success-color);
            font-weight: 600;
        }

        .amount-negative {
            color: var(--danger-color);
            font-weight: 600;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            font-weight: 500;
            display: inline-block;
        }

        .status-approved {
            background-color: #e0f2fe;
            color: #0369a1;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-rejected {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            font-size: clamp(0.8rem, 1.6vw, 0.9rem);
        }

        .pagination a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            color: var(--text-primary);
            background-color: #f0f0f0;
            transition: var(--transition);
        }

        .pagination a:hover:not(.disabled) {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }

        .pagination .current-page {
            padding: 8px 12px;
            border-radius: 8px;
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(1.5rem, 3vw, 1.8rem);
            color: #d1d5db;
            margin-bottom: 10px;
        }

        .no-data p {
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        /* Mobile-Specific Styles */
        @media (max-width: 768px) {
            .top-nav {
                padding: 10px;
                font-size: clamp(1rem, 2vw, 1.1rem);
            }

            .main-content {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.4vw, 1.4rem);
            }

            .welcome-banner p {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .filter-card {
                padding: 15px;
                gap: 15px;
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

            .filter-group input[type="date"],
            .filter-group select {
                padding: 10px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .filter-buttons {
                flex-direction: row;
                gap: 15px;
                justify-content: space-between;
                padding-top: 10px;
            }

            .btn {
                width: 48%;
                padding: 12px;
                font-size: clamp(0.85rem, 1.7vw, 0.95rem);
                min-width: unset;
            }

            .transactions-container {
                padding: 15px;
            }

            .summary-container {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .summary-card {
                min-width: unset;
                width: 100%;
                padding: 15px;
                border-radius: 10px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                text-align: center;
                height: 100px; /* Rectangular height */
            }

            .summary-card .icon {
                font-size: clamp(1.2rem, 2.4vw, 1.3rem);
            }

            .summary-card .label {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .summary-card .amount {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            th, td {
                padding: 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .no-column {
                width: 50px;
            }

            .no-transaksi-cell {
                min-width: 80px;
                max-width: 120px;
            }

            .detail-cell {
                min-width: 100px;
                max-width: 150px;
            }

            .pagination {
                gap: 6px;
            }

            .pagination a, .pagination .current-page {
                padding: 6px 10px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }
        }

        @media (max-width: 480px) {
            body {
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .top-nav {
                padding: 8px;
            }

            .welcome-banner {
                padding: 12px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.1rem, 2.2vw, 1.3rem);
            }

            .welcome-banner p {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .filter-card {
                padding: 12px;
                gap: 12px;
            }

            .filter-row {
                gap: 12px;
                margin-bottom: 12px;
            }

            .filter-group label {
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .filter-group input[type="date"],
            .filter-group select {
                padding: 8px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .filter-buttons {
                gap: 10px;
                padding-top: 8px;
            }

            .btn {
                width: 48%;
                padding: 10px;
                font-size: clamp(0.8rem, 1.6vw, 0.9rem);
            }

            .transactions-container h3 {
                font-size: clamp(0.9rem, 1.8vw, 1rem);
            }

            .no-data i {
                font-size: clamp(1.2rem, 2.5vw, 1.5rem);
            }

            .summary-container {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .summary-card {
                padding: 12px;
                height: 90px; /* Slightly smaller for very small screens */
            }

            .summary-card .icon {
                font-size: clamp(1rem, 2vw, 1.2rem);
            }

            .summary-card .label {
                font-size: clamp(0.7rem, 1.4vw, 0.8rem);
            }

            .summary-card .amount {
                font-size: clamp(0.85rem, 1.7vw, 0.95rem);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <nav class="top-nav">
        <button class="back-btn" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-xmark"></i>
        </button>
        <h1>SCHOBANK</h1>
        <div style="width: 36px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2>Mutasi Rekening</h2>
            <p><i></i> <strong>Nomor Rekening:</strong> <?= htmlspecialchars($no_rekening ?? 'N/A') ?></p>
            <p><i></i> <strong>Nama Pemilik:</strong> <?= htmlspecialchars($nama_pemilik) ?></p>
        </div>

        <!-- Alerts -->
        <div id="alertContainer">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!isset($error)): ?>
            <!-- Filter Section -->
            <div class="filter-card">
                <form id="filterForm" method="GET" action="">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="start_date">Dari Tanggal</label>
                            <input type="date" id="start_date" name="start_date" 
                                   value="<?= htmlspecialchars($start_date ?? date('Y-m-d', strtotime('-1 month'))) ?>" 
                                   required>
                        </div>
                        <div class="filter-group">
                            <label for="end_date">Sampai Tanggal</label>
                            <input type="date" id="end_date" name="end_date" 
                                   value="<?= htmlspecialchars($end_date ?? date('Y-m-d')) ?>" 
                                   required>
                        </div>
                        <div class="filter-group">
                            <label for="transaction_type">Jenis Transaksi</label>
                            <select id="transaction_type" name="tab">
                                <option value="all" <?= $transaction_type === 'all' ? 'selected' : '' ?>>Semua Jenis Transaksi</option>
                                <option value="setor" <?= $transaction_type === 'setor' ? 'selected' : '' ?>>Setoran</option>
                                <option value="tarik" <?= $transaction_type === 'tarik' ? 'selected' : '' ?>>Penarikan</option>
                                <option value="transfer" <?= $transaction_type === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <button type="submit" class="btn btn-filter"><i class="fas fa-filter"></i> Filter</button>
                        <button type="button" onclick="printMutasi()" class="btn btn-print"><i class="fas fa-print"></i> Cetak</button>
                    </div>
                </form>
            </div>

            <!-- Transactions Section -->
            <div class="transactions-container">
                <h3><i class="fas fa-table"></i> Daftar Transaksi</h3>
                <div class="summary-container">
                    <div class="summary-card setoran">
                        <i class="fas fa-arrow-down icon"></i>
                        <div class="label">Total Setoran</div>
                        <div class="amount">Rp <?= number_format($total_setor, 0, ',', '.') ?></div>
                        <span class="tooltip">Jumlah total setoran dalam periode ini</span>
                    </div>
                    <div class="summary-card penarikan">
                        <i class="fas fa-arrow-up icon"></i>
                        <div class="label">Total Penarikan</div>
                        <div class="amount">Rp <?= number_format($total_tarik, 0, ',', '.') ?></div>
                        <span class="tooltip">Jumlah total penarikan dalam periode ini</span>
                    </div>
                    <div class="summary-card transfer-masuk">
                        <i class="fas fa-download icon"></i>
                        <div class="label">Total Transfer Masuk</div>
                        <div class="amount">Rp <?= number_format($total_transfer_masuk, 0, ',', '.') ?></div>
                        <span class="tooltip">Jumlah total transfer masuk dalam periode ini</span>
                    </div>
                    <div class="summary-card transfer-keluar">
                        <i class="fas fa-paper-plane icon"></i>
                        <div class="label">Total Transfer Keluar</div>
                        <div class="amount">Rp <?= number_format($total_transfer_keluar, 0, ',', '.') ?></div>
                        <span class="tooltip">Jumlah total transfer keluar dalam periode ini</span>
                    </div>
                </div>
                <?php if (empty($transactions)): ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>Tidak ada transaksi dalam periode ini</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th class="no-column">No</th>
                                    <th>Tanggal</th>
                                    <th>Jenis</th>
                                    <th>Jumlah</th>
                                    <th>No Transaksi</th>
                                    <th>Detail</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $index => $transaksi): 
                                    $row_number = ($page - 1) * $records_per_page + $index + 1;
                                    $is_transfer_masuk = $transaksi['jenis_transaksi'] === 'transfer' && 
                                                        $transaksi['rekening_tujuan'] === $no_rekening;
                                    $amount_class = ($transaksi['jenis_transaksi'] === 'setor' || $is_transfer_masuk) 
                                                  ? 'amount-positive' 
                                                  : 'amount-negative';
                                    $amount_prefix = ($transaksi['jenis_transaksi'] === 'setor' || $is_transfer_masuk) 
                                                  ? '+' 
                                                  : '-';
                                ?>
                                    <tr>
                                        <td class="no-column"><?= $row_number ?></td>
                                        <td><?= date('d M Y H:i', strtotime($transaksi['created_at'])) ?></td>
                                        <td>
                                            <?php if ($transaksi['jenis_transaksi'] === 'setor'): ?>
                                                <i class="fas fa-arrow-down amount-positive mr-2"></i> Setor
                                            <?php elseif ($transaksi['jenis_transaksi'] === 'tarik'): ?>
                                                <i class="fas fa-arrow-up amount-negative mr-2"></i> Tarik
                                            <?php elseif ($transaksi['jenis_transaksi'] === 'transfer'): ?>
                                                <?php if ($transaksi['rekening_asal'] === $no_rekening): ?>
                                                    <i class="fas fa-paper-plane amount-negative mr-2"></i> Transfer
                                                <?php else: ?>
                                                    <i class="fas fa-download amount-positive mr-2"></i> Transfer
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="<?= $amount_class ?>">
                                            <?= $amount_prefix ?> Rp <?= number_format($transaksi['jumlah'], 0, ',', '.') ?>
                                        </td>
                                        <td class="no-transaksi-cell">
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
                                        <td>
                                            <span class="status-badge status-<?= htmlspecialchars($transaksi['status']) ?>">
                                                <?= $transaksi['status'] === 'approved' ? 'Berhasil' : 
                                                    ($transaksi['status'] === 'pending' ? 'Menunggu' : 'Gagal') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="pagination">
                        <?php
                        $pagination_params = $is_filtered ? "&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&tab=" . urlencode($transaction_type) : "";
                        ?>
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?><?= $pagination_params ?>" class="pagination-link">« Previous</a>
                        <?php else: ?>
                            <a class="pagination-link disabled">« Previous</a>
                        <?php endif; ?>
                        <span class="current-page"><?= $page ?></span>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?><?= $pagination_params ?>" class="pagination-link">Next »</a>
                        <?php else: ?>
                            <a class="pagination-link disabled">Next »</a>
                        <?php endif; ?>
                    </div>
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

        function printMutasi() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            const transactionType = document.getElementById('transaction_type').value;
            if (!startDate || !endDate) {
                showAlert('Silakan pilih tanggal untuk mencetak', 'error');
                return;
            }
            window.open(`cetak_mutasi.php?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}&tab=${encodeURIComponent(transactionType)}`, '_blank');
        }

        document.addEventListener('DOMContentLoaded', () => {
            const filterForm = document.getElementById('filterForm');
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const startDate = document.getElementById('start_date').value;
                    const endDate = document.getElementById('end_date').value;
                    if (!startDate || !endDate) {
                        e.preventDefault();
                        showAlert('Silakan pilih tanggal yang valid', 'error');
                    } else if (new Date(startDate) > new Date(endDate)) {
                        e.preventDefault();
                        showAlert('Tanggal awal tidak boleh lebih besar dari tanggal akhir', 'error');
                    }
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
                });
            });
        });

        // Cegah zoom
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