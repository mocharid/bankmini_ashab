<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Initialize variables
$error = null;

// Pagination settings
$items_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Get total number of jurusan for pagination
$total_query = "SELECT COUNT(*) as total FROM jurusan";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// Handle delete request
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    
    // Check if jurusan has associated students
    $check_users_query = "SELECT COUNT(*) as count FROM users WHERE jurusan_id = ?";
    $check_users_stmt = $conn->prepare($check_users_query);
    $check_users_stmt->bind_param("i", $id);
    $check_users_stmt->execute();
    $check_users_result = $check_users_stmt->get_result()->fetch_assoc();
    
    // Check if jurusan has associated kelas
    $check_kelas_query = "SELECT COUNT(*) as count FROM kelas WHERE jurusan_id = ?";
    $check_kelas_stmt = $conn->prepare($check_kelas_query);
    $check_kelas_stmt->bind_param("i", $id);
    $check_kelas_stmt->execute();
    $check_kelas_result = $check_kelas_stmt->get_result()->fetch_assoc();
    
    if ($check_users_result['count'] > 0) {
        header('Location: tambah_jurusan.php?error=' . urlencode("Tidak dapat menghapus jurusan karena masih memiliki siswa!"));
        exit();
    } elseif ($check_kelas_result['count'] > 0) {
        header('Location: tambah_jurusan.php?error=' . urlencode("Tidak dapat menghapus jurusan karena masih memiliki kelas terkait!"));
        exit();
    } else {
        $delete_query = "DELETE FROM jurusan WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_jurusan.php?delete_success=1');
            exit();
        } else {
            header('Location: tambah_jurusan.php?error=' . urlencode("Gagal menghapus jurusan: " . $conn->error));
            exit();
        }
    }
}

// Handle edit request
if (isset($_POST['edit_confirm'])) {
    $id = intval($_POST['edit_id']);
    $nama = trim($_POST['edit_nama']);
    
    if (empty($nama)) {
        header('Location: tambah_jurusan.php?error=' . urlencode("Nama jurusan tidak boleh kosong!"));
        exit();
    } else {
        // Check for duplicate jurusan name
        $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            header('Location: tambah_jurusan.php?error=' . urlencode("Jurusan dengan nama tersebut sudah ada!"));
            exit();
        } else {
            $update_query = "UPDATE jurusan SET nama_jurusan = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $nama, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_jurusan.php?edit_success=1');
                exit();
            } else {
                header('Location: tambah_jurusan.php?error=' . urlencode("Gagal mengupdate jurusan: " . $conn->error));
                exit();
            }
        }
    }
}

// Handle form submission for new jurusan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_id']) && !isset($_POST['edit_confirm'])) {
    if (isset($_POST['confirm'])) {
        $nama_jurusan = trim($_POST['nama_jurusan']);
        
        if (empty($nama_jurusan)) {
            header('Location: tambah_jurusan.php?error=' . urlencode("Nama jurusan tidak boleh kosong!"));
            exit();
        } else {
            // Check for duplicate jurusan name
            $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("s", $nama_jurusan);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                header('Location: tambah_jurusan.php?error=' . urlencode("Jurusan sudah ada!"));
                exit();
            } else {
                $query = "INSERT INTO jurusan (nama_jurusan) VALUES (?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $nama_jurusan);
                
                if ($stmt->execute()) {
                    header('Location: tambah_jurusan.php?success=1');
                    exit();
                } else {
                    header('Location: tambah_jurusan.php?error=' . urlencode("Gagal menambahkan jurusan: " . $conn->error));
                    exit();
                }
            }
        }
    } else {
        $nama_jurusan = trim($_POST['nama_jurusan']);
        if (empty($nama_jurusan)) {
            header('Location: tambah_jurusan.php?error=' . urlencode("Nama jurusan tidak boleh kosong!"));
            exit();
        } else {
            // Redirect to show confirmation modal
            header('Location: tambah_jurusan.php?confirm=1&nama=' . urlencode($nama_jurusan));
            exit();
        }
    }
}

// Fetch existing jurusan with total students for the current page
$query_list = "SELECT j.*, COUNT(u.id) as total_siswa 
               FROM jurusan j 
               LEFT JOIN users u ON j.id = u.jurusan_id 
               GROUP BY j.id 
               ORDER BY j.nama_jurusan ASC 
               LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query_list);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tambah Jurusan - SCHOBANK SYSTEM</title>
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
            --setor-bg: #dcfce7;
            --tarik-bg: #fee2e2;
            --net-positive-bg: #dcfce7;
            --net-negative-bg: #fee2e2;
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
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
        }

        .top-nav {
            background: var(--primary-dark);
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: white;
            box-shadow: var(--shadow-sm);
            font-size: clamp(1.1rem, 2vw, 1.25rem);
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
            max-width: 1200px;
            margin: 0 auto;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
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
            margin-bottom: 8px;
            font-size: clamp(1.3rem, 2.5vw, 1.6rem);
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .form-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 25px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        .btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
            width: auto;
            max-width: 180px;
        }

        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .btn:active {
            transform: scale(0.95);
        }

        .btn-edit {
            background-color: var(--secondary-color);
        }

        .btn-edit:hover {
            background-color: var(--secondary-dark);
        }

        .btn-delete {
            background-color: var(--danger-color);
        }

        .btn-delete:hover {
            background-color: #d32f2f;
        }

        .btn-cancel {
            background-color: #f0f0f0;
            color: var(--text-secondary);
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            transition: var(--transition);
        }

        .btn-cancel:hover {
            background-color: #e0e0e0;
            transform: translateY(-2px);
        }

        .btn-confirm {
            background-color: var(--secondary-color);
        }

        .btn-confirm:hover {
            background-color: var(--secondary-dark);
        }

        .jurusan-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .jurusan-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .jurusan-list h3 {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.15rem);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            min-width: 70px;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary-light);
            color: var(--text-secondary);
            font-weight: 600;
        }

        tr {
            transition: opacity 0.3s ease;
        }

        tr.deleting {
            animation: fadeOutRow 0.5s ease forwards;
        }

        @keyframes fadeOutRow {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(-20px); }
        }

        tr:hover {
            background-color: #f8faff;
        }

        .total-siswa {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 4px 8px;
            border-radius: 10px;
            font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        .pagination a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            color: var(--text-primary);
            background-color: #f0f0f0;
            transition: var(--transition);
        }

        .pagination a:hover {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination .current-page {
            padding: 8px 12px;
            border-radius: 8px;
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }

        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: white;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: var(--shadow-md);
            animation: slideIn 0.5s ease-out;
        }

        .modal-title {
            font-size: clamp(1rem, 2vw, 1.15rem);
            color: var(--primary-dark);
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal p {
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .modal-buttons {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
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
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            max-width: 90%;
            width: 400px;
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
            font-size: clamp(3rem, 6vw, 4rem);
            color: var(--secondary-color);
            margin-bottom: 20px;
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
            margin-bottom: 12px;
            font-size: clamp(1.25rem, 2.5vw, 1.4rem);
            animation: slideUpText 0.5s ease-out 0.2s both;
            font-weight: 600;
        }

        .success-modal p {
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            margin-bottom: 20px;
            animation: slideUpText 0.5s ease-out 0.3s both;
            line-height: 1.5;
        }

        @keyframes slideUpText {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
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

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 15px;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1.5s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-data {
            text-align: center;
            padding: 25px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(1.8rem, 3.5vw, 2rem);
            color: #d1d5db;
            margin-bottom: 12px;
        }

        .no-data p {
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
        }

        @media (max-width: 768px) {
            .top-nav {
                padding: 12px;
                font-size: clamp(1rem, 2vw, 1.15rem);
            }

            .main-content {
                padding: 12px;
            }

            .welcome-banner h2 {
                font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            }

            .form-card, .jurusan-list {
                padding: 15px;
            }

            form {
                gap: 12px;
            }

            .btn {
                width: 100%;
                max-width: none;
                justify-content: center;
                padding: 8px 15px;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 8px 10px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
            }

            .action-buttons {
                flex-direction: column;
                gap: 4px;
            }

            .modal-content {
                margin: 12px;
                width: calc(100% - 24px);
                padding: 15px;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .success-modal {
                width: 90%;
                padding: 25px;
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
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            }

            .top-nav {
                padding: 10px;
            }

            .welcome-banner {
                padding: 15px;
            }

            .jurusan-list h3 {
                font-size: clamp(0.95rem, 2vw, 1.05rem);
            }

            .no-data i {
                font-size: clamp(1.6rem, 3.5vw, 1.8rem);
            }

            .success-modal h3 {
                font-size: clamp(1.15rem, 2.5vw, 1.25rem);
            }

            .success-modal p {
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
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
        <div style="width: 40px;"></div>
    </nav>

    <div class="main-content">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <h2><i class="fas fa-plus-circle"></i> Tambah Jurusan</h2>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="jurusan-form">
                <div class="form-group">
                    <label for="nama_jurusan">Nama Jurusan</label>
                    <input type="text" id="nama_jurusan" name="nama_jurusan" required 
                           placeholder="Masukkan nama jurusan">
                </div>
                <button type="submit" class="btn" id="submit-btn">
                    <i class="fas fa-plus"></i>
                    Tambah Jurusan
                </button>
            </form>
        </div>

        <!-- Jurusan List -->
        <div class="jurusan-list">
            <h3><i class="fas fa-list"></i> Daftar Jurusan</h3>
            <?php if ($result && $result->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Jurusan</th>
                            <th>Jumlah Siswa</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="jurusan-table">
                        <?php 
                        $no = ($page - 1) * $items_per_page + 1;
                        while ($row = $result->fetch_assoc()): 
                        ?>
                            <tr id="row-<?php echo $row['id']; ?>">
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($row['nama_jurusan']); ?></td>
                                <td>
                                    <span class="total-siswa">
                                        <?php echo $row['total_siswa']; ?> Siswa
                                    </span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-edit" 
                                                onclick="showEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_jurusan'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </button>
                                        <button type="button" class="btn btn-delete" 
                                                onclick="deleteJurusan(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['nama_jurusan'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-trash"></i>
                                            Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <!-- Pagination -->
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>">« Previous</a>
                    <?php else: ?>
                        <a class="disabled">« Previous</a>
                    <?php endif; ?>
                    <span class="current-page"><?php echo $page; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>">Next »</a>
                    <?php else: ?>
                        <a class="disabled">Next »</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada data jurusan</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Confirmation Modal for Adding Jurusan -->
        <?php if (isset($_GET['confirm']) && isset($_GET['nama'])): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Konfirmasi Jurusan Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Nama Jurusan</span>
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['nama'])); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama_jurusan" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='tambah_jurusan.php'">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Adding Jurusan -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jurusan berhasil ditambahkan!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Updating Jurusan -->
        <?php if (isset($_GET['edit_success'])): ?>
            <div class="success-overlay" id="editSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jurusan berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Deleting Jurusan -->
        <?php if (isset($_GET['delete_success'])): ?>
            <div class="success-overlay" id="deleteSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Jurusan berhasil dihapus!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if (isset($_GET['error'])): ?>
            <div class="success-overlay" id="errorModal">
                <div class="success-modal">
                    <div class="success-icon" style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>GAGAL</h3>
                    <p><?php echo htmlspecialchars(urldecode($_GET['error'])); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                Edit Jurusan
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edit_nama">Nama Jurusan</label>
                    <input type="text" id="edit_nama" name="edit_nama" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideEditModal()">Batal</button>
                    <button type="submit" class="btn btn-edit" id="edit-submit-btn" onclick="return showEditConfirmation()">
                        <i class="fas fa-save"></i>
                        Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Confirmation Modal -->
    <div class="success-overlay" id="editConfirmModal" style="display: none;">
        <div class="success-modal">
            <div class="success-icon">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <h3>Konfirmasi Edit Jurusan</h3>
            <div class="modal-content-confirm">
                <div class="modal-row">
                    <span class="modal-label">Nama Jurusan</span>
                    <span class="modal-value" id="editConfirmNama"></span>
                </div>
            </div>
            <form action="" method="POST" id="editConfirmForm">
                <input type="hidden" name="edit_id" id="editConfirmId">
                <input type="hidden" name="edit_nama" id="editConfirmNamaInput">
                <input type="hidden" name="edit_confirm" value="1">
                <div class="modal-buttons">
                    <button type="submit" class="btn btn-confirm" id="edit-confirm-btn">
                        <i class="fas fa-check"></i> Konfirmasi
                    </button>
                    <button type="button" class="btn btn-cancel" onclick="hideEditConfirmModal()">
                        <i class="fas fa-times"></i> Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-trash"></i>
                Hapus Jurusan
            </div>
            <p>Apakah Anda yakin ingin menghapus jurusan <strong id="delete_nama"></strong>?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Batal</button>
                    <button type="submit" class="btn btn-delete" id="delete-submit-btn">
                        <i class="fas fa-trash"></i>
                        Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Edit Modal Functions
        function showEditModal(id, nama) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function showEditConfirmation() {
            const nama = document.getElementById('edit_nama').value.trim();
            if (nama.length < 3) {
                showErrorModal('Nama jurusan harus minimal 3 karakter!');
                return false;
            }
            document.getElementById('editConfirmId').value = document.getElementById('edit_id').value;
            document.getElementById('editConfirmNama').textContent = nama;
            document.getElementById('editConfirmNamaInput').value = nama;
            document.getElementById('editConfirmModal').style.display = 'flex';
            document.getElementById('editModal').style.display = 'none';
            return false; // Prevent form submission
        }

        function hideEditConfirmModal() {
            document.getElementById('editConfirmModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'flex';
        }

        // Delete Modal Functions
        function showDeleteModal(id, nama) {
            document.getElementById('delete_id').value = id;
            document.getElementById('delete_nama').textContent = nama;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Delete Jurusan with Animation
        function deleteJurusan(id, nama) {
            showDeleteModal(id, nama);
            const deleteForm = document.getElementById('deleteForm');
            const deleteBtn = document.getElementById('delete-submit-btn');
            
            deleteForm.addEventListener('submit', function(e) {
                e.preventDefault();
                deleteBtn.classList.add('loading');
                deleteBtn.innerHTML = '<i class="fas fa-spinner"></i> Menghapus...';
                const row = document.getElementById(`row-${id}`);
                if (row) {
                    row.classList.add('deleting');
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 1500);
                } else {
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 1500);
                }
            }, { once: true });
        }

        // Show Error Modal Function
        function showErrorModal(message) {
            const modal = document.createElement('div');
            modal.className = 'success-overlay';
            modal.innerHTML = `
                <div class="success-modal">
                    <div class="success-icon" style="color: var(--danger-color);">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>GAGAL</h3>
                    <p>${message}</p>
                </div>
            `;
            document.body.appendChild(modal);
            setTimeout(() => {
                modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => modal.remove(), 500);
            }, 3000);
            modal.addEventListener('click', () => {
                modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => modal.remove(), 500);
            });
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Handle modal animations and other events
        document.addEventListener('DOMContentLoaded', () => {
            // Handle success and error modals
            const modals = document.querySelectorAll('#successModal, #editSuccessModal, #deleteSuccessModal, #errorModal');
            modals.forEach(modal => {
                setTimeout(() => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'tambah_jurusan.php';
                    }, 500);
                }, 3000);
                modal.addEventListener('click', () => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'tambah_jurusan.php';
                    }, 500);
                });

                // Add confetti
                const successModal = modal.querySelector('.success-modal');
                if (successModal) {
                    for (let i = 0; i < 30; i++) {
                        const confetti = document.createElement('div');
                        confetti.className = 'confetti';
                        confetti.style.left = Math.random() * 100 + '%';
                        confetti.style.animationDelay = Math.random() * 1 + 's';
                        confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                        successModal.appendChild(confetti);
                    }
                }
            });

            // Handle confirmation modal
            const confirmModal = document.querySelector('#confirmModal .success-modal');
            if (confirmModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    confirmModal.appendChild(confetti);
                }
            }

            // Handle edit confirmation modal
            const editConfirmModal = document.querySelector('#editConfirmModal .success-modal');
            if (editConfirmModal) {
                for (let i = 0; i < 30; i++) {
                    const confetti = document.createElement('div');
                    confetti.className = 'confetti';
                    confetti.style.left = Math.random() * 100 + '%';
                    confetti.style.animationDelay = Math.random() * 1 + 's';
                    confetti.style.animationDuration = (Math.random() * 2 + 3) + 's';
                    editConfirmModal.appendChild(confetti);
                }
            }

            // Form submission handling (Tambah Jurusan)
            const jurusanForm = document.getElementById('jurusan-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (jurusanForm && submitBtn) {
                jurusanForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama_jurusan').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama jurusan harus minimal 3 karakter!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Tambah Jurusan';
                    } else {
                        e.preventDefault();
                        submitBtn.classList.add('loading');
                        submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                        setTimeout(() => {
                            jurusanForm.submit();
                        }, 1500);
                    }
                });
            }

            // Confirm form handling (Konfirmasi Tambah)
            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    setTimeout(() => {
                        confirmForm.submit();
                        jurusanForm.reset(); // Clear form after successful submission
                    }, 1500);
                });
            }

            // Edit form handling (Simpan Edit)
            const editForm = document.getElementById('editForm');
            const editSubmitBtn = document.getElementById('edit-submit-btn');
            
            if (editForm && editSubmitBtn) {
                editForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('edit_nama').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama jurusan harus minimal 3 karakter!');
                        editSubmitBtn.classList.remove('loading');
                        editSubmitBtn.innerHTML = '<i class="fas fa-save"></i> Simpan';
                    }
                });
            }

            // Edit confirm form handling (Konfirmasi Edit)
            const editConfirmForm = document.getElementById('editConfirmForm');
            const editConfirmBtn = document.getElementById('edit-confirm-btn');
            
            if (editConfirmForm && editConfirmBtn) {
                editConfirmForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    editConfirmBtn.classList.add('loading');
                    editConfirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    setTimeout(() => {
                        editConfirmForm.submit();
                    }, 1500);
                });
            }
        });

        // Prevent pinch zooming
        document.addEventListener('touchstart', function(event) {
            if (event.touches.length > 1) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-tap zooming
        let lastTouchEnd = 0;
        document.addEventListener('touchend', function(event) {
            const now = (new Date()).getTime();
            if (now - lastTouchEnd <= 300) {
                event.preventDefault();
            }
            lastTouchEnd = now;
        }, { passive: false });

        // Prevent zoom on wheel with ctrl
        document.addEventListener('wheel', function(event) {
            if (event.ctrlKey) {
                event.preventDefault();
            }
        }, { passive: false });

        // Prevent double-click zooming
        document.addEventListener('dblclick', function(event) {
            event.preventDefault();
        }, { passive: false });
    </script>
</body>
</html>