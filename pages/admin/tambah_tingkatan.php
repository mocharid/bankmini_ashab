<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Handle add tingkatan kelas request
if (isset($_POST['nama_tingkatan']) && !isset($_POST['delete_tingkatan_id']) && !isset($_POST['edit_tingkatan_id']) && !isset($_POST['edit_tingkatan_confirm'])) {
    $nama_tingkatan = trim($_POST['nama_tingkatan']);
    
    if (empty($nama_tingkatan)) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Nama tingkatan kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama_tingkatan) < 1) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Nama tingkatan kelas harus minimal 1 karakter!"));
        exit();
    }

    // Check for duplicate tingkatan name
    $check_query = "SELECT id FROM tingkatan_kelas WHERE nama_tingkatan = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $nama_tingkatan);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows > 0) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Tingkatan kelas sudah ada!"));
        exit();
    }

    if (!isset($_POST['confirm_tingkatan'])) {
        // Show confirmation modal
        header('Location: tambah_tingkatan.php?confirm_tingkatan=1&nama_tingkatan=' . urlencode($nama_tingkatan));
        exit();
    }

    // Process confirmed submission
    $query = "INSERT INTO tingkatan_kelas (nama_tingkatan) VALUES (?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $nama_tingkatan);
    
    if ($stmt->execute()) {
        header('Location: tambah_tingkatan.php?success_tingkatan=1');
        exit();
    } else {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Gagal menambahkan tingkatan kelas: " . $stmt->error));
        exit();
    }
}

// Handle edit tingkatan kelas request
if (isset($_POST['edit_tingkatan_confirm'])) {
    $id = intval($_POST['edit_tingkatan_id']);
    $nama = trim($_POST['edit_nama_tingkatan']);
    
    if (empty($nama)) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Nama tingkatan kelas tidak boleh kosong!"));
        exit();
    } elseif (strlen($nama) < 1) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Nama tingkatan kelas harus minimal 1 karakter!"));
        exit();
    } else {
        $check_query = "SELECT id FROM tingkatan_kelas WHERE nama_tingkatan = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $nama, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            header('Location: tambah_tingkatan.php?error=' . urlencode("Tingkatan kelas dengan nama tersebut sudah ada!"));
            exit();
        } else {
            $update_query = "UPDATE tingkatan_kelas SET nama_tingkatan = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("si", $nama, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_tingkatan.php?edit_tingkatan_success=1');
                exit();
            } else {
                header('Location: tambah_tingkatan.php?error=' . urlencode("Gagal mengupdate tingkatan kelas: " . $conn->error));
                exit();
            }
        }
    }
}

// Handle delete tingkatan kelas request
if (isset($_POST['delete_tingkatan_id'])) {
    $id = intval($_POST['delete_tingkatan_id']);
    
    // Check if tingkatan is used in kelas
    $check_query = "SELECT COUNT(*) as count FROM kelas WHERE tingkatan_kelas_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        header('Location: tambah_tingkatan.php?error=' . urlencode("Tidak dapat menghapus tingkatan kelas karena masih digunakan di kelas!"));
        exit();
    } else {
        $delete_query = "DELETE FROM tingkatan_kelas WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_tingkatan.php?delete_tingkatan_success=1');
            exit();
        } else {
            header('Location: tambah_tingkatan.php?error=' . urlencode("Gagal menghapus tingkatan kelas: " . $conn->error));
            exit();
        }
    }
}

// Fetch existing tingkatan kelas with total kelas
$query_tingkatan_list = "SELECT tk.id, tk.nama_tingkatan, COUNT(k.id) as total_kelas 
                         FROM tingkatan_kelas tk 
                         LEFT JOIN kelas k ON tk.id = k.tingkatan_kelas_id 
                         GROUP BY tk.id 
                         ORDER BY tk.nama_tingkatan ASC";
$result_tingkatan_list = $conn->query($query_tingkatan_list);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/schobank/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kelola Tingkatan Kelas - SCHOBANK SYSTEM</title>
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
            padding: 15px 20px;
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

        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            background-color: white;
        }

        input[type="text"]:focus {
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

        .tingkatan-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .tingkatan-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .tingkatan-list h3 {
            margin-bottom: 20px;
            color: var(--primary-dark);
            font-size: clamp(1.2rem, 2.5vw, 1.4rem);
            display: flex;
            align-items: center;
            gap: 10px;
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

        .total-kelas {
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

            .form-card, .tingkatan-list {
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

            .tingkatan-list h3 {
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
                margin-right: 3px;
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
            <h2>Kelola Tingkatan Kelas</h2>
            <p>Kelola Tingkatan Kelas Untuk Sistem SCHOBANK</p>
        </div>

        <!-- Tingkatan Kelas Form Section -->
        <div class="form-card">
            <h3>Tambah Tingkatan Kelas</h3>
            <form action="" method="POST" id="tingkatan-form">
                <div class="form-group">
                    <label for="nama_tingkatan">Nama Tingkatan</label>
                    <input type="text" id="nama_tingkatan" name="nama_tingkatan" required 
                           placeholder="Masukkan nama tingkatan (misal: X, XI, XII)" 
                           value="<?php echo isset($_POST['nama_tingkatan']) ? htmlspecialchars($_POST['nama_tingkatan']) : ''; ?>">
                </div>
                <button type="submit" class="btn" id="submit-tingkatan-btn">
                    <span class="btn-content"><i class="fas fa-plus"></i> Tambah</span>
                </button>
            </form>
        </div>

        <!-- Tingkatan Kelas List -->
        <div class="tingkatan-list">
            <h3><i class="fas fa-list"></i> Daftar Tingkatan Kelas</h3>
            <?php if ($result_tingkatan_list && $result_tingkatan_list->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Tingkatan</th>
                            <th>Jumlah Kelas</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="tingkatan-table">
                        <?php 
                        $no = 1;
                        while ($row = $result_tingkatan_list->fetch_assoc()): 
                        ?>
                            <tr id="tingkatan-row-<?php echo $row['id']; ?>">
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($row['nama_tingkatan']); ?></td>
                                <td>
                                    <span class="total-kelas"><?php echo $row['total_kelas']; ?> Kelas</span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-edit" 
                                                onclick="showEditTingkatanModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_tingkatan'])); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button class="btn btn-delete" 
                                                onclick="deleteTingkatan(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_tingkatan'])); ?>')">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada data tingkatan kelas</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Confirmation Modal for Adding Tingkatan Kelas -->
        <?php if (isset($_GET['confirm_tingkatan']) && isset($_GET['nama_tingkatan'])): ?>
            <div class="success-overlay" id="confirmTingkatanModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-layer-group"></i>
                    </div>
                    <h3>Konfirmasi Tingkatan Kelas Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-value"><?php echo htmlspecialchars(urldecode($_GET['nama_tingkatan'])); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-tingkatan-form">
                        <input type="hidden" name="nama_tingkatan" value="<?php echo htmlspecialchars(urldecode($_GET['nama_tingkatan'])); ?>">
                        <input type="hidden" name="confirm_tingkatan" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-tingkatan-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='tambah_tingkatan.php'">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Adding Tingkatan Kelas -->
        <?php if (isset($_GET['success_tingkatan'])): ?>
            <div class="success-overlay" id="successTingkatanModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Tingkatan kelas berhasil ditambahkan!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Updating Tingkatan Kelas -->
        <?php if (isset($_GET['edit_tingkatan_success'])): ?>
            <div class="success-overlay" id="editTingkatanSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Tingkatan kelas berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Deleting Tingkatan Kelas -->
        <?php if (isset($_GET['delete_tingkatan_success'])): ?>
            <div class="success-overlay" id="deleteTingkatanSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Tingkatan kelas berhasil dihapus!</p>
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

        <!-- Edit Tingkatan Modal -->
        <div id="editTingkatanModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-edit"></i> Edit Tingkatan Kelas
                </div>
                <form id="editTingkatanForm" method="POST" action="">
                    <input type="hidden" name="edit_tingkatan_id" id="edit_tingkatan_id">
                    <div class="form-group">
                        <label for="edit_nama_tingkatan">Nama Tingkatan</label>
                        <input type="text" id="edit_nama_tingkatan" name="edit_nama_tingkatan" required>
                    </div>
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideEditTingkatanModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn btn-edit" id="edit-tingkatan-submit-btn" onclick="return showEditTingkatanConfirmation()">
                            <span class="btn-content"><i class="fas fa-save"></i> Simpan</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Tingkatan Confirmation Modal -->
        <div class="success-overlay" id="editTingkatanConfirmModal" style="display: none;">
            <div class="success-modal">
                <div class="success-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <h3>Konfirmasi Edit Tingkatan Kelas</h3>
                <div class="modal-content-confirm">
                    <div class="modal-row">
                        <span class="modal-value" id="editTingkatanConfirmNama"></span>
                    </div>
                </div>
                <form action="" method="POST" id="editTingkatanConfirmForm">
                    <input type="hidden" name="edit_tingkatan_id" id="editTingkatanConfirmId">
                    <input type="hidden" name="edit_nama_tingkatan" id="editTingkatanConfirmNamaInput">
                    <input type="hidden" name="edit_tingkatan_confirm" value="1">
                    <div class="modal-buttons">
                        <button type="submit" class="btn btn-confirm" id="edit-ting-confirm-btn">
                            <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                        </button>
                        <button type="button" class="btn btn-cancel" onclick="hideEditTingkatanConfirmModal()">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Tingkatan Modal -->
        <div id="deleteTingkatanModal" class="modal">
            <div class="modal-content">
                <div class="modal-title">
                    <i class="fas fa-trash"></i> Hapus Tingkatan Kelas
                </div>
                <p>Apakah Anda yakin ingin menghapus tingkatan kelas <strong id="delete_tingkatan_nama"></strong>?</p>
                <form id="deleteTingkatanForm" method="POST" action="">
                    <input type="hidden" name="delete_tingkatan_id" id="delete_tingkatan_id">
                    <div class="modal-buttons">
                        <button type="button" class="btn btn-cancel" onclick="hideDeleteTingkatanModal()">
                            <span class="btn-content">Batal</span>
                        </button>
                        <button type="submit" class="btn btn-delete" id="delete-tingkatan-submit-btn">
                            <span class="btn-content"><i class="fas fa-trash"></i> Hapus</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Tingkatan Kelas Modal Functions
        function showEditTingkatanModal(id, nama) {
            console.log('showEditTingkatanModal called with:', { id, nama });
            try {
                document.getElementById('edit_tingkatan_id').value = id;
                document.getElementById('edit_nama_tingkatan').value = nama;
                document.getElementById('editTingkatanModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in showEditTingkatanModal:', error);
                showErrorModal('Gagal membuka modal edit tingkatan!');
            }
        }

        function hideEditTingkatanModal() {
            document.getElementById('editTingkatanModal').style.display = 'none';
        }

        function showEditTingkatanConfirmation() {
            const nama = document.getElementById('edit_nama_tingkatan').value.trim();
            if (nama.length < 1) {
                showErrorModal('Nama tingkatan kelas harus minimal 1 karakter!');
                return false;
            }
            document.getElementById('editTingkatanConfirmId').value = document.getElementById('edit_tingkatan_id').value;
            document.getElementById('editTingkatanConfirmNama').textContent = nama;
            document.getElementById('editTingkatanConfirmNamaInput').value = nama;
            document.getElementById('editTingkatanConfirmModal').style.display = 'flex';
            document.getElementById('editTingkatanModal').style.display = 'none';
            return false;
        }

        function hideEditTingkatanConfirmModal() {
            document.getElementById('editTingkatanConfirmModal').style.display = 'none';
            document.getElementById('editTingkatanModal').style.display = 'flex';
        }

        function deleteTingkatan(id, nama) {
            console.log('deleteTingkatan called with:', { id, nama });
            try {
                document.getElementById('delete_tingkatan_id').value = id;
                document.getElementById('delete_tingkatan_nama').textContent = nama;
                document.getElementById('deleteTingkatanModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in deleteTingkatan:', error);
                showErrorModal('Gagal membuka modal hapus tingkatan!');
            }
        }

        function hideDeleteTingkatanModal() {
            document.getElementById('deleteTingkatanModal').style.display = 'none';
        }

        // Error Modal Function
        function showErrorModal(message) {
            const errorModal = document.createElement('div');
            errorModal.className = 'success-overlay';
            errorModal.id = 'dynamicErrorModal';
            errorModal.innerHTML = `
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p>${message}</p>
                </div>
            `;
            document.body.appendChild(errorModal);
            setTimeout(() => {
                errorModal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => errorModal.remove(), 500);
            }, 3000);
        }

        // Handle Form Submission with Loading State
        document.getElementById('tingkatan-form')?.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submit-tingkatan-btn');
            submitBtn.classList.add('loading');
            submitBtn.disabled = true;
        });

        document.getElementById('confirm-tingkatan-form')?.addEventListener('submit', function(e) {
            const confirmBtn = document.getElementById('confirm-tingkatan-btn');
            confirmBtn.classList.add('loading');
            confirmBtn.disabled = true;
        });

        document.getElementById('editTingkatanForm')?.addEventListener('submit', function(e) {
            const editBtn = document.getElementById('edit-tingkatan-submit-btn');
            editBtn.classList.add('loading');
            editBtn.disabled = true;
        });

        document.getElementById('editTingkatanConfirmForm')?.addEventListener('submit', function(e) {
            const confirmBtn = document.getElementById('edit-ting-confirm-btn');
            confirmBtn.classList.add('loading');
            confirmBtn.disabled = true;
        });

        document.getElementById('deleteTingkatanForm')?.addEventListener('submit', function(e) {
            const deleteBtn = document.getElementById('delete-tingkatan-submit-btn');
            const row = document.getElementById(`tingkatan-row-${document.getElementById('delete_tingkatan_id').value}`);
            if (row) row.classList.add('deleting');
            deleteBtn.classList.add('loading');
            deleteBtn.disabled = true;
        });

        // Auto-close Success/Error Modals
        const successModals = [
            'successTingkatanModal', 'editTingkatanSuccessModal', 'deleteTingkatanSuccessModal'
        ];
        successModals.forEach(modalId => {
            const modal = document.getElementById(modalId);
            if (modal) {
                setTimeout(() => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => modal.remove(), 500);
                    window.history.replaceState({}, document.title, 'tambah_tingkatan.php');
                }, 3000);
            }
        });

        const errorModal = document.getElementById('errorModal');
        if (errorModal) {
            setTimeout(() => {
                errorModal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                setTimeout(() => errorModal.remove(), 500);
                window.history.replaceState({}, document.title, 'tambah_tingkatan.php');
            }, 3000);
        }

        // Prevent Double Form Submission
        const forms = [
            'tingkatan-form', 'confirm-tingkatan-form',
            'editTingkatanForm', 'editTingkatanConfirmForm', 'deleteTingkatanForm'
        ];
        forms.forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.addEventListener('submit', function() {
                    const submitButtons = form.querySelectorAll('button[type="submit"]');
                    submitButtons.forEach(btn => {
                        btn.disabled = true;
                        btn.classList.add('loading');
                    });
                    setTimeout(() => {
                        submitButtons.forEach(btn => {
                            btn.disabled = false;
                            btn.classList.remove('loading');
                        });
                    }, 5000);
                });
            }
        });

        // Input Validation on Keyup
        document.getElementById('nama_tingkatan')?.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^A-Za-z0-9\s-]/g, '');
            if (this.value.length > 50) {
                this.value = this.value.substring(0, 50);
                showErrorModal('Nama tingkatan kelas tidak boleh lebih dari 50 karakter!');
            }
        });

        document.getElementById('edit_nama_tingkatan')?.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^A-Za-z0-9\s-]/g, '');
            if (this.value.length > 50) {
                this.value = this.value.substring(0, 50);
                showErrorModal('Nama tingkatan kelas tidak boleh lebih dari 50 karakter!');
            }
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