<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Pagination settings
$items_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Get total number of kelas for pagination
$total_query = "SELECT COUNT(*) as total FROM kelas";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// Handle delete kelas request
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    
    $check_query = "SELECT COUNT(*) as count FROM users WHERE kelas_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        header('Location: tambah_kelas.php?error=' . urlencode("Tidak dapat menghapus kelas karena masih memiliki siswa!"));
        exit();
    } else {
        $delete_query = "DELETE FROM kelas WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_kelas.php?delete_success=1');
            exit();
        } else {
            header('Location: tambah_kelas.php?error=' . urlencode("Gagal menghapus kelas: " . $conn->error));
            exit();
        }
    }
}

// Handle edit kelas request
if (isset($_POST['edit_confirm'])) {
    $id = intval($_POST['edit_id']);
    $nama = trim($_POST['edit_nama']);
    $jurusan_id = intval($_POST['edit_jurusan']);
    
    if (empty($nama)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama) < 3) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas harus minimal 3 karakter!"));
        exit();
    } elseif (empty($jurusan_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih jurusan!"));
        exit();
    } else {
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("sii", $nama, $jurusan_id, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            header('Location: tambah_kelas.php?error=' . urlencode("Kelas dengan nama tersebut sudah ada di jurusan ini!"));
            exit();
        } else {
            $update_query = "UPDATE kelas SET nama_kelas = ?, jurusan_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sii", $nama, $jurusan_id, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_kelas.php?edit_success=1');
                exit();
            } else {
                header('Location: tambah_kelas.php?error=' . urlencode("Gagal mengupdate kelas: " . $conn->error));
                exit();
            }
        }
    }
}

// Handle form submission for new kelas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_id']) && !isset($_POST['edit_confirm'])) {
    $nama_kelas = trim($_POST['nama_kelas']);
    $jurusan_id = intval($_POST['jurusan_id']);
    
    if (empty($nama_kelas)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama_kelas) < 3) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas harus minimal 3 karakter!"));
        exit();
    } elseif (empty($jurusan_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih jurusan!"));
        exit();
    }
    
    if (isset($_POST['confirm'])) {
        error_log("Confirming new kelas: nama_kelas=$nama_kelas, jurusan_id=$jurusan_id");
        
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama_kelas, $jurusan_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            header('Location: tambah_kelas.php?error=' . urlencode("Kelas sudah ada di jurusan ini!"));
            exit();
        } else {
            $query = "INSERT INTO kelas (nama_kelas, jurusan_id) VALUES (?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("si", $nama_kelas, $jurusan_id);
            
            if ($stmt->execute()) {
                error_log("Successfully added kelas: nama_kelas=$nama_kelas, jurusan_id=$jurusan_id");
                header('Location: tambah_kelas.php?success=1');
                exit();
            } else {
                error_log("Failed to add kelas: " . $stmt->error);
                header('Location: tambah_kelas.php?error=' . urlencode("Gagal menambahkan kelas: " . $stmt->error));
                exit();
            }
        }
    } else {
        error_log("Showing confirmation for kelas: nama_kelas=$nama_kelas, jurusan_id=$jurusan_id");
        header('Location: tambah_kelas.php?confirm=1&nama=' . urlencode($nama_kelas) . '&jurusan_id=' . $jurusan_id);
        exit();
    }
}

// Ambil data jurusan for dropdowns
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC";
$result_jurusan = $conn->query($query_jurusan);

// Fetch existing kelas with jurusan info and total students for the current page
$query_list = "SELECT j.nama_jurusan, k.id as kelas_id, k.nama_kelas, k.jurusan_id, COUNT(u.id) as total_siswa 
               FROM kelas k 
               LEFT JOIN jurusan j ON k.jurusan_id = j.id
               LEFT JOIN users u ON k.id = u.kelas_id 
               GROUP BY j.nama_jurusan, k.id 
               ORDER BY j.nama_jurusan ASC, k.nama_kelas ASC 
               LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query_list);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();

// Organize data by jurusan
$kelas_by_jurusan = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $jurusan = $row['nama_jurusan'];
        if (!isset($kelas_by_jurusan[$jurusan])) {
            $kelas_by_jurusan[$jurusan] = [];
        }
        $kelas_by_jurusan[$jurusan][] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tambah Kelas - SCHOBANK SYSTEM</title>
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
            background-color: white;
            transition: var(--transition);
            height: 40px;
            line-height: 1.5;
        }

        select {
            width: 100%;
            padding: 10px 36px 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: clamp(0.85rem, 1.5vw, 0.95rem);
            background-color: white;
            transition: var(--transition);
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            height: 40px;
            line-height: 1.5;
            cursor: pointer;
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.01);
        }

        @media not all and (min-resolution:.001dpcm) {
            @supports (-webkit-appearance:none) {
                select {
                    padding-top: 8px;
                    padding-bottom: 8px;
                    line-height: 1.5;
                }
            }
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

        .jurusan-title {
            margin: 15px 0 12px;
            color: var(--primary-dark);
            font-size: clamp(0.95rem, 1.8vw, 1rem);
            font-weight: 600;
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
            gap: 12px;
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

            input[type="text"],
            select {
                height: 36px;
                font-size: clamp(0.8rem, 1.5vw, 0.9rem);
                padding: 8px 12px;
            }

            select {
                padding-right: 36px;
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

            input[type="text"],
            select {
                height: 34px;
                font-size: clamp(0.75rem, 1.5vw, 0.85rem);
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
            <h2><i class="fas fa-plus-circle"></i> Tambah Kelas</h2>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="kelas-form">
                <div class="form-group">
                    <label for="jurusan_id">Jurusan</label>
                    <select id="jurusan_id" name="jurusan_id" required>
                        <option value="">Pilih Jurusan</option>
                        <?php 
                        $result_jurusan->data_seek(0);
                        while ($row = $result_jurusan->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="nama_kelas">Nama Kelas</label>
                    <input type="text" id="nama_kelas" name="nama_kelas" required 
                           placeholder="Masukkan nama kelas" value="<?php echo isset($_POST['nama_kelas']) ? htmlspecialchars($_POST['nama_kelas']) : ''; ?>">
                </div>
                <button type="submit" class="btn" id="submit-btn">
                    <i class="fas fa-plus"></i>
                    Tambah Kelas
                </button>
            </form>
        </div>

        <!-- Kelas List -->
        <div class="jurusan-list">
            <h3><i class="fas fa-list"></i> Daftar Kelas</h3>
            <?php if (!empty($kelas_by_jurusan)): ?>
                <?php foreach ($kelas_by_jurusan as $jurusan => $kelas): ?>
                    <h4 class="jurusan-title"><?php echo htmlspecialchars($jurusan); ?></h4>
                    <?php if (!empty($kelas)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Kelas</th>
                                    <th>Jumlah Siswa</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody id="kelas-table">
                                <?php 
                                $no = ($page - 1) * $items_per_page + 1;
                                foreach ($kelas as $row): 
                                ?>
                                    <tr id="row-<?php echo $row['kelas_id']; ?>">
                                        <td><?php echo $no++; ?></td>
                                        <td><?php echo htmlspecialchars($row['nama_kelas']); ?></td>
                                        <td>
                                            <span class="total-siswa">
                                                <?php echo $row['total_siswa']; ?> Siswa
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-edit" 
                                                        onclick="showEditModal(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kelas'])); ?>', <?php echo $row['jurusan_id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-delete" 
                                                        onclick="deleteKelas(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kelas'])); ?>')">
                                                    <i class="fas fa-trash"></i> Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-info-circle"></i>
                            <p>Tidak ada kelas untuk jurusan ini</p>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
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
                    <p>Belum ada data kelas</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Confirmation Modal for Adding Kelas -->
        <?php if (isset($_GET['confirm']) && isset($_GET['nama']) && isset($_GET['jurusan_id'])): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Konfirmasi Kelas Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Nama Kelas</span>
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['nama'])); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Jurusan</span>
                            <span class="modal-value">
                                <?php
                                $jurusan_id = intval($_GET['jurusan_id']);
                                $jurusan_query = "SELECT nama_jurusan FROM jurusan WHERE id = ?";
                                $jurusan_stmt = $conn->prepare($jurusan_query);
                                $jurusan_stmt->bind_param("i", $jurusan_id);
                                $jurusan_stmt->execute();
                                $jurusan_result = $jurusan_stmt->get_result()->fetch_assoc();
                                echo htmlspecialchars($jurusan_result['nama_jurusan'] ?? 'Unknown');
                                ?>
                            </span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama_kelas" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($_GET['jurusan_id']); ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">
                                <i class="fas fa-check"></i> Konfirmasi
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='tambah_kelas.php'">
                                <i class="fas fa-times"></i> Batal
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Adding Kelas -->
        <?php if (isset($_GET['success'])): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Kelas berhasil ditambahkan!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Updating Kelas -->
        <?php if (isset($_GET['edit_success'])): ?>
            <div class="success-overlay" id="editSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Kelas berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Deleting Kelas -->
        <?php if (isset($_GET['delete_success'])): ?>
            <div class="success-overlay" id="deleteSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>BERHASIL</h3>
                    <p>Kelas berhasil dihapus!</p>
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
                Edit Kelas
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edit_nama">Nama Kelas</label>
                    <input type="text" id="edit_nama" name="edit_nama" required>
                </div>
                <div class="form-group">
                    <label for="edit_jurusan">Jurusan</label>
                    <select id="edit_jurusan" name="edit_jurusan" required>
                        <option value="">Pilih Jurusan</option>
                        <?php 
                        $result_jurusan->data_seek(0);
                        while ($row = $result_jurusan->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $row['id']; ?>">
                                <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideEditModal()">Batal</button>
                    <button type="submit" class="btn btn-edit" id="edit-submit-btn">
                        <i class="fas fa-save"></i> Simpan
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
            <h3>Konfirmasi Edit Kelas</h3>
            <div class="modal-content-confirm">
                <div class="modal-row">
                    <span class="modal-label">Nama Kelas</span>
                    <span class="modal-value" id="editConfirmNama"></span>
                </div>
                <div class="modal-row">
                    <span class="modal-label">Jurusan</span>
                    <span class="modal-value" id="editConfirmJurusan"></span>
                </div>
            </div>
            <form action="" method="POST" id="editConfirmForm">
                <input type="hidden" name="edit_id" id="editConfirmId">
                <input type="hidden" name="edit_nama" id="editConfirmNamaInput">
                <input type="hidden" name="edit_jurusan" id="editConfirmJurusanInput">
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
                Hapus Kelas
            </div>
            <p>Apakah Anda yakin ingin menghapus kelas <strong id="delete_nama"></strong>?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="delete_id" id="delete_id">
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="hideDeleteModal()">Batal</button>
                    <button type="submit" class="btn btn-delete" id="delete-submit-btn">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Edit Modal Functions
        function showEditModal(id, nama, jurusanId) {
            console.log('showEditModal called with:', { id, nama, jurusanId });
            try {
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_nama').value = nama;
                document.getElementById('edit_jurusan').value = jurusanId;
                document.getElementById('editModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in showEditModal:', error);
                showErrorModal('Gagal membuka modal edit!');
            }
        }

        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function hideEditConfirmModal() {
            document.getElementById('editConfirmModal').style.display = 'none';
        }

        // Delete Modal Functions
        function deleteKelas(id, nama) {
            console.log('deleteKelas called with:', { id, nama });
            try {
                document.getElementById('delete_id').value = id;
                document.getElementById('delete_nama').textContent = nama;
                document.getElementById('deleteModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in deleteKelas:', error);
                showErrorModal('Gagal membuka modal hapus!');
            }
        }

        function hideDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
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
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
            if (event.target.classList.contains('success-overlay')) {
                event.target.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => {
                    event.target.remove();
                }, 500);
            }
        }

        // Handle modal animations and other events
        document.addEventListener('DOMContentLoaded', () => {
            // Handle success and error modals
            const modals = document.querySelectorAll('#successModal, #editSuccessModal, #deleteSuccessModal, #errorModal');
            modals.forEach(modal => {
                setTimeout(() => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                }, 3000);
            });

            // Form submission handling (Tambah Kelas)
            const kelasForm = document.getElementById('kelas-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (kelasForm && submitBtn) {
                kelasForm.addEventListener('submit', function(e) {
                    console.log('Submitting kelas-form');
                    const nama = document.getElementById('nama_kelas').value.trim();
                    const jurusan = document.getElementById('jurusan_id').value;
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama kelas harus minimal 3 karakter!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Tambah Kelas';
                    } else if (!jurusan) {
                        e.preventDefault();
                        showErrorModal('Silakan pilih jurusan!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<i class="fas fa-plus"></i> Tambah Kelas';
                    } else {
                        e.preventDefault();
                        submitBtn.classList.add('loading');
                        submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                        setTimeout(() => {
                            kelasForm.submit();
                        }, 1500);
                    }
                });
            }

            // Confirm form handling (Konfirmasi Tambah)
            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    console.log('Submitting confirm-form');
                    e.preventDefault();
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    setTimeout(() => {
                        confirmForm.submit();
                    }, 1500);
                });
            }

            // Edit form handling (Simpan Edit)
            const editForm = document.getElementById('editForm');
            const editSubmitBtn = document.getElementById('edit-submit-btn');
            
            if (editForm && editSubmitBtn) {
                editForm.addEventListener('submit', function(e) {
                    console.log('Submitting editForm');
                    e.preventDefault();
                    const nama = document.getElementById('edit_nama').value.trim();
                    const jurusanId = document.getElementById('edit_jurusan').value;
                    const id = document.getElementById('edit_id').value;
                    
                    if (nama.length < 3) {
                        showErrorModal('Nama kelas harus minimal 3 karakter!');
                        return;
                    }
                    if (!jurusanId) {
                        showErrorModal('Silakan pilih jurusan!');
                        return;
                    }
                    
                    // Populate confirmation modal
                    document.getElementById('editConfirmId').value = id;
                    document.getElementById('editConfirmNama').textContent = nama;
                    document.getElementById('editConfirmNamaInput').value = nama;
                    document.getElementById('editConfirmJurusanInput').value = jurusanId;
                    const jurusanText = document.querySelector(`#edit_jurusan option[value="${jurusanId}"]`).textContent.trim();
                    document.getElementById('editConfirmJurusan').textContent = jurusanText;
                    
                    // Show confirmation modal and hide edit modal
                    document.getElementById('editConfirmModal').style.display = 'flex';
                    document.getElementById('editModal').style.display = 'none';
                });
            }

            // Edit confirm form handling (Konfirmasi Edit)
            const editConfirmForm = document.getElementById('editConfirmForm');
            const editConfirmBtn = document.getElementById('edit-confirm-btn');
            
            if (editConfirmForm && editConfirmBtn) {
                editConfirmForm.addEventListener('submit', function(e) {
                    console.log('Submitting editConfirmForm');
                    e.preventDefault();
                    editConfirmBtn.classList.add('loading');
                    editConfirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    setTimeout(() => {
                        editConfirmForm.submit();
                    }, 1500);
                });
            }

            // Delete form handling
            const deleteForm = document.getElementById('deleteForm');
            const deleteSubmitBtn = document.getElementById('delete-submit-btn');
            
            if (deleteForm && deleteSubmitBtn) {
                deleteForm.addEventListener('submit', function(e) {
                    console.log('Submitting deleteForm');
                    e.preventDefault();
                    deleteSubmitBtn.classList.add('loading');
                    deleteSubmitBtn.innerHTML = '<i class="fas fa-spinner"></i> Menghapus...';
                    const id = document.getElementById('delete_id').value;
                    const row = document.getElementById(`row-${id}`);
                    if (row) {
                        row.classList.add('deleting');
                    }
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 1500);
                });
            }
        });
    </script>
</body>
</html>