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
    $tingkatan_kelas_id = intval($_POST['edit_tingkatan']);
    
    if (empty($nama)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama) < 3) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas harus minimal 3 karakter!"));
        exit();
    } elseif (empty($jurusan_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih jurusan!"));
        exit();
    } elseif (empty($tingkatan_kelas_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih tingkatan kelas!"));
        exit();
    } else {
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("siii", $nama, $jurusan_id, $tingkatan_kelas_id, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            header('Location: tambah_kelas.php?error=' . urlencode("Kelas dengan nama tersebut sudah ada di jurusan dan tingkatan ini!"));
            exit();
        } else {
            $update_query = "UPDATE kelas SET nama_kelas = ?, jurusan_id = ?, tingkatan_kelas_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("siii", $nama, $jurusan_id, $tingkatan_kelas_id, $id);
            
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
    $tingkatan_kelas_id = intval($_POST['tingkatan_id']);
    
    if (empty($nama_kelas)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama_kelas) < 3) {
        header('Location: tambah_kelas.php?error=' . urlencode("Nama kelas harus minimal 3 karakter!"));
        exit();
    } elseif (empty($jurusan_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih jurusan!"));
        exit();
    } elseif (empty($tingkatan_kelas_id)) {
        header('Location: tambah_kelas.php?error=' . urlencode("Silakan pilih tingkatan kelas!"));
        exit();
    }

    // Check for duplicate kelas
    $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("sii", $nama_kelas, $jurusan_id, $tingkatan_kelas_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        header('Location: tambah_kelas.php?error=' . urlencode("Kelas sudah ada di jurusan dan tingkatan ini!"));
        exit();
    }

    if (!isset($_POST['confirm'])) {
        // Show confirmation modal
        header('Location: tambah_kelas.php?confirm=1&nama=' . urlencode($nama_kelas) . '&jurusan_id=' . $jurusan_id . '&tingkatan_id=' . $tingkatan_kelas_id);
        exit();
    }

    // Process confirmed submission
    $query = "INSERT INTO kelas (nama_kelas, jurusan_id, tingkatan_kelas_id) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sii", $nama_kelas, $jurusan_id, $tingkatan_kelas_id);
    
    if ($stmt->execute()) {
        header('Location: tambah_kelas.php?success=1');
        exit();
    } else {
        header('Location: tambah_kelas.php?error=' . urlencode("Gagal menambahkan kelas: " . $stmt->error));
        exit();
    }
}

// Fetch jurusan and tingkatan kelas for dropdowns
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC";
$result_jurusan = $conn->query($query_jurusan);

$query_tingkatan = "SELECT * FROM tingkatan_kelas ORDER BY nama_tingkatan ASC";
$result_tingkatan = $conn->query($query_tingkatan);

// Fetch existing kelas with jurusan and tingkatan info
$query_list = "SELECT j.nama_jurusan, tk.nama_tingkatan, k.id as kelas_id, k.nama_kelas, k.jurusan_id, k.tingkatan_kelas_id, COUNT(u.id) as total_siswa 
               FROM kelas k 
               LEFT JOIN jurusan j ON k.jurusan_id = j.id
               LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
               LEFT JOIN users u ON k.id = u.kelas_id 
               GROUP BY k.id 
               ORDER BY j.nama_jurusan ASC, tk.nama_tingkatan ASC, k.nama_kelas ASC 
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
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kelola Kelas - SCHOBANK SYSTEM</title>
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

        .form-card {
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

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        input[type="text"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: white;
        }

        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            cursor: pointer;
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
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
            width: 200px;
            margin: 0 auto;
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

        .btn-edit {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            padding: 8px 12px;
            width: auto;
            min-width: 70px;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
            padding: 8px 12px;
            width: auto;
            min-width: 70px;
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #d32f2f 0%, var(--danger-color) 100%);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: var(--text-secondary);
            padding: 12px 25px;
        }

        .btn-cancel:hover {
            background: #e0e0e0;
            transform: translateY(-2px);
        }

        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            padding: 12px 25px;
        }

        .btn-confirm:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-content {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
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

        .jurusan-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .jurusan-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .jurusan-list h3 {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .jurusan-title {
            margin: 15px 0 12px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.15rem);
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
            min-width: 80px;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--bg-light);
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
            background-color: var(--bg-light);
        }

        .total-siswa {
            background: var(--bg-light);
            color: var(--primary-dark);
            padding: 6px 10px;
            border-radius: 12px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-start;
        }

        .action-buttons .btn {
            margin: 0;
            padding: 6px 10px;
            min-width: auto;
            width: auto;
            font-size: 0.85rem;
        }

        .action-buttons .btn i {
            margin-right: 4px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
        }

        .pagination a {
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 10px;
            color: var(--text-primary);
            background-color: #f0f0f0;
            transition: var(--transition);
        }

        .pagination a:hover {
            background-color: var(--bg-light);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .pagination .current-page {
            padding: 10px 15px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
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
            padding: 25px;
            border-radius: 15px;
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-md);
            animation: slideIn 0.5s ease-out;
        }

        .modal-title {
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal p {
            font-size: clamp(0.9rem, 2vw, 1rem);
            color: var(--text-secondary);
            margin-bottom: 20px;
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

        .success-icon, .error-icon {
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

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 14px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 25px;
        }

        .modal-row {
            padding: 12px 0;
            text-align: center;
        }

        .modal-value {
            font-weight: 600;
            color: white;
            font-size: clamp(0.9rem, 2.2vw, 1rem);
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .modal-buttons .btn {
            width: 140px;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(2rem, 4vw, 2.2rem);
            color: #d1d5db;
            margin-bottom: 15px;
        }

        .no-data p {
            font-size: clamp(0.9rem, 2vw, 1rem);
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

            .form-card, .jurusan-list {
                padding: 20px;
            }

            form {
                gap: 15px;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .btn-edit, .btn-delete {
                padding: 6px 10px;
                min-width: 65px;
                font-size: 0.8rem;
            }

            .action-buttons {
                gap: 5px;
                justify-content: flex-start;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .modal-content {
                margin: 15px;
                width: calc(100% - 30px);
                padding: 20px;
            }

            .modal-buttons {
                flex-direction: row;
                flex-wrap: wrap;
                gap: 10px;
            }

            .modal-buttons .btn {
                width: 140px;
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

            .pagination {
                gap: 8px;
            }

            .pagination a, .pagination .current-page {
                padding: 8px 12px;
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

            .jurusan-list h3 {
                font-size: clamp(1rem, 2.5vw, 1.2rem);
            }

            .no-data i {
                font-size: clamp(1.8rem, 3.5vw, 2rem);
            }

            .btn-edit, .btn-delete {
                padding: 5px 8px;
                min-width: 60px;
                font-size: 0.75rem;
            }

            .action-buttons {
                gap: 4px;
                justify-content: flex-start;
            }

            .action-buttons .btn i {
                margin-right: 4px;
                font-size: 0.7rem;
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

            .modal-content-confirm {
                padding: 12px;
                gap: 12px;
            }

            .modal-row {
                padding: 10px 0;
            }

            .modal-buttons .btn {
                width: 120px;
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
            <h2>Kelola Kelas</h2>
            <p>Kelola data kelas untuk sistem SCHOBANK</p>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="kelas-form">
                <div class="form-group">
                    <label for="tingkatan_id">Tingkatan Kelas</label>
                    <select id="tingkatan_id" name="tingkatan_id" required>
                        <option value="">Pilih Tingkatan</option>
                        <?php 
                        $result_tingkatan->data_seek(0);
                        while ($row = $result_tingkatan->fetch_assoc()): 
                        ?>
                            <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['tingkatan_id']) && $_POST['tingkatan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
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
                    <span class="btn-content"><i class="fas fa-plus"></i> Tambah</span>
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
                                    <th>Tingkatan</th>
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
                                        <td><?php echo htmlspecialchars($row['nama_tingkatan']); ?></td>
                                        <td><?php echo htmlspecialchars($row['nama_kelas']); ?></td>
                                        <td>
                                            <span class="total-siswa"><?php echo $row['total_siswa']; ?> Siswa</span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-edit" 
                                                        onclick="showEditModal(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kelas'])); ?>', <?php echo $row['jurusan_id']; ?>, <?php echo $row['tingkatan_kelas_id']; ?>)">
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
                        <a href="?page=<?php echo $page - 1; ?>">Previous</a>
                    <?php else: ?>
                        <a class="disabled">Previous</a>
                    <?php endif; ?>
                    <span class="current-page"><?php echo $page; ?></span>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>">Next</a>
                    <?php else: ?>
                        <a class="disabled">Next</a>
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
        <?php if (isset($_GET['confirm']) && isset($_GET['nama']) && isset($_GET['jurusan_id']) && isset($_GET['tingkatan_id'])): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Konfirmasi Kelas Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['nama'])); ?></span>
                        </div>
                        <div class="modal-row">
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
                        <div class="modal-row">
                            <span class="modal-value">
                                <?php
                                $tingkatan_kelas_id = intval($_GET['tingkatan_id']);
                                $tingk_query = "SELECT nama_tingkatan FROM tingkatan_kelas WHERE id = ?";
                                $tingk_stmt = $conn->prepare($tingk_query);
                                $tingk_stmt->bind_param("i", $tingkatan_kelas_id);
                                $tingk_stmt->execute();
                                $tingk_result = $tingk_stmt->get_result()->fetch_assoc();
                                echo htmlspecialchars($tingk_result['nama_tingkatan'] ?? 'Unknown');
                                ?>
                            </span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama_kelas" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($_GET['jurusan_id']); ?>">
                        <input type="hidden" name="tingkatan_id" value="<?php echo htmlspecialchars($_GET['tingkatan_id']); ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='tambah_kelas.php'">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
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
                    <h3>Berhasil</h3>
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
                    <h3>Berhasil</h3>
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
                    <h3>Berhasil</h3>
                    <p>Kelas berhasil dihapus!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if (isset($_GET['error'])): ?>
            <div class="success-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars(urldecode($_GET['error'])); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-edit"></i> Edit Kelas
                </div>
                <form id="editForm" method="POST" action="">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="form-group">
                        <label for="edit_tingkatan">Tingkatan Kelas</label>
                        <select id="edit_tingkatan" name="edit_tingkatan" required>
                            <option value="">Pilih Tingkatan</option>
                            <?php 
                            $result_tingkatan->data_seek(0);
                            while ($row = $result_tingkatan->fetch_assoc()): 
                            ?>
                                <option value="<?php echo $row['id']; ?>">
                                    <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
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
                    <div class="form-group">
                        <label for="edit_nama">Nama Kelas</label>
                        <input type="text" id="edit_nama" name="edit_nama" required>
                    </div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideEditModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn btn-edit" id="edit-submit-btn" onclick="return showEditConfirmation()">
                            <span class="btn-content"><i class="fas fa-save"></i> Simpan</span>
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
                        <span class="modal-value" id="editConfirmTingkatan"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-value" id="editConfirmJurusan"></span>
                    </div>
                    <div class="modal-row">
                        <span class="modal-value" id="editConfirmNama"></span>
                    </div>
                </div>
                <form action="" method="POST" id="editConfirmForm">
                    <input type="hidden" name="edit_id" id="edit-form-id">
                    <input type="hidden" name="edit_tingkatan" id="edit-form-tingkatan-id">
                    <input type="hidden" name="edit_jurusan" id="edit-form-jurusan-id">
                    <input type="hidden" name="edit_nama" id="edit-form-nama">
                    <input type="hidden" name="edit_confirm" value="1">
                    <div class="modal-buttons">
                        <button type="submit" class="btn btn-confirm" id="edit-confirm-btn">
                            <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="hideEditConfirmModal()">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-trash"></i> Hapus Kelas
                </div>
                <p>Apakah Anda yakin ingin menghapus kelas <strong id="delete_nama"></strong>?</p>
                <form id="deleteForm" method="POST" action="">
                    <input type="hidden" name="delete_id" id="delete_id">
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideDeleteModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn btn-delete" id="delete-submit-btn">
                            <span class="btn-content"><i class="fas fa-trash"></i> Hapus</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Edit Modal Functions
        function showEditModal(id, nama, jurusanId, tingkatanKelasId) {
            console.log('showEditModal called with:', { id, nama, jurusanId, tingkatanKelasId });
            try {
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_nama').value = nama;
                document.getElementById('edit_jurusan').value = jurusanId;
                document.getElementById('edit_tingkatan').value = tingkatanKelasId;
                document.getElementById('editModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in showEditModal:', error);
                showErrorModal('Gagal membuka modal edit!');
            }
        }

        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function showEditConfirmation() {
            const tingkId = document.getElementById('edit_tingkatan').value;
            const jurusanId = document.getElementById('edit_jurusan').value;
            const nama = document.getElementById('edit_nama').value.trim();
            
            if (!tingkId) {
                showErrorModal('Silakan pilih tingkatan!');
                return false;
            }
            if (!jurusanId) {
                showErrorModal('Silakan pilih jurusan!');
                return false;
            }
            if (nama.length < 3) {
                showErrorModal('Nama kelas harus minimal 3 karakter!');
                return false;
            }
            
            document.getElementById('edit-form-id').value = document.getElementById('edit_id').value;
            document.getElementById('edit-form-tingkatan-id').value = tingkId;
            document.getElementById('edit-form-jurusan-id').value = jurusanId;
            document.getElementById('edit-form-nama').value = nama;
            
            const tingkText = document.querySelector(`#edit_tingkatan option[value="${tingkId}"]`).textContent.trim();
            document.getElementById('editConfirmTingkatan').textContent = tingkText;
            const jurusanText = document.querySelector(`#edit_jurusan option[value="${jurusanId}"]`).textContent.trim();
            document.getElementById('editConfirmJurusan').textContent = jurusanText;
            document.getElementById('editConfirmNama').textContent = nama;
            
            document.getElementById('editConfirmModal').style.display = 'flex';
            document.getElementById('editModal').style.display = 'none';
            return false;
        }

        function hideEditConfirmModal() {
            document.getElementById('editConfirmModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'flex';
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
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
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
                        window.location.href = 'tambah_kelas.php';
                    }, 500);
                }, 3000);
                modal.addEventListener('click', () => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'tambah_kelas.php';
                    }, 500);
                });
            });

            // Form submission handling (Tambah Kelas)
            const kelasForm = document.getElementById('kelas-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (kelasForm && submitBtn) {
                kelasForm.addEventListener('submit', function(e) {
                    console.log('Submitting kelas-form');
                    const nama = document.getElementById('nama_kelas').value.trim();
                    const jurusan = document.getElementById('jurusan_id').value;
                    const tingkatan = document.getElementById('tingkatan_id').value;
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama kelas harus minimal 3 karakter!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-plus"></i> Tambah</span>';
                    } else if (!jurusan) {
                        e.preventDefault();
                        showErrorModal('Silakan pilih jurusan!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-plus"></i> Tambah</span>';
                    } else if (!tingkatan) {
                        e.preventDefault();
                        showErrorModal('Silakan pilih tingkatan kelas!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-plus"></i> Tambah</span>';
                    } else {
                        e.preventDefault();
                        submitBtn.classList.add('loading');
                        setTimeout(() => {
                            kelasForm.submit();
                        }, 300);
                    }
                });
            }

            // Confirm Form Handling (Konfirmasi Tambah)
            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function(e) {
                    console.log('Submitting confirm-form');
                    e.preventDefault();
                    confirmBtn.classList.add('loading');
                    setTimeout(() => {
                        confirmForm.submit();
                    }, 300);
                });
            }

            // Edit Form handling (Simpan Edit)
            const editForm = document.getElementById('editForm');
            const editSubmitBtn = document.getElementById('edit-submit-btn');
            
            if (editForm && editSubmitBtn) {
                editForm.addEventListener('submit', function(e) {
                    console.log('Submitting editForm');
                    const nama = document.getElementById('edit_nama').value.trim();
                    const jurusanId = document.getElementById('edit_jurusan').value;
                    const tingkId = document.getElementById('edit_tingkatan').value;
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama kelas harus minimal 3 karakter!');
                        editSubmitBtn.classList.remove('loading');
                    } else if (!jurusanId) {
                        e.preventDefault();
                        showErrorModal('Silakan pilih jurusan!');
                        editSubmitBtn.classList.remove('loading');
                    } else if (!tingkId) {
                        e.preventDefault();
                        showErrorModal('Silakan pilih tingkatan!');
                        editSubmitBtn.classList.remove('loading');
                    }
                });
            }

            // Edit Confirm Form handling (Konfirmasi Edit)
            const editConfirmForm = document.getElementById('editConfirmForm');
            const editConfirmBtn = document.getElementById('edit-confirm-btn');
            
            if (editConfirmForm && editConfirmBtn) {
                editConfirmForm.addEventListener('submit', function(e) {
                    console.log('Submitting editConfirmForm');
                    e.preventDefault();
                    editConfirmBtn.classList.add('loading');
                    setTimeout(() => {
                        editConfirmForm.submit();
                    }, 300);
                });
            }

            // Delete Form Handling
            const deleteForm = document.getElementById('deleteForm');
            const deleteSubmitBtn = document.getElementById('delete-submit-btn');
            
            if (deleteForm && deleteSubmitBtn) {
                deleteForm.addEventListener('submit', function(e) {
                    console.log('Submitting deleteForm');
                    e.preventDefault();
                    deleteSubmitBtn.classList.add('loading');
                    const id = document.getElementById('delete_id').value;
                    const row = document.getElementById(`row-${id}`);
                    if (row) {
                        row.classList.add('deleting');
                    }
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 300);
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

        // Input validation
        const namaKelasInputs = document.querySelectorAll('#nama_kelas, #edit_nama');
        namaKelasInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^A-Za-z0-9\s-]/g, '');
                if (this.value.length > 50) {
                    this.value = this.value.substring(0, 50);
                    showErrorModal('Nama kelas tidak boleh lebih dari 50 karakter!');
                }
            });
        });

        // Handle Window Resize for Responsive Modals
        window.addEventListener('resize', function() {
            const modals = document.querySelectorAll('.modal, .success-overlay');
            modals.forEach(modal => {
                if (modal.style.display === 'flex') {
                    modal.style.height = window.innerHeight + 'px';
                }
            });
        });

        // Initialize Modal Heights
        document.addEventListener('DOMContentLoaded', function() {
            const modals = document.querySelectorAll('.modal, .success-overlay');
            modals.forEach(modal => {
                modal.style.height = window.innerHeight + 'px';
            });
        });
    </script>
</body>
</html>