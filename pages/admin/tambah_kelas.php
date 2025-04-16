<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

$username = $_SESSION['username'] ?? 'Petugas';

// Handle delete kelas request
if (isset($_POST['delete_id'])) {
    $id = intval($_POST['delete_id']);
    
    $check_query = "SELECT COUNT(*) as count FROM users WHERE kelas_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("i", $id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result()->fetch_assoc();
    
    if ($check_result['count'] > 0) {
        $error = "Tidak dapat menghapus kelas karena masih memiliki siswa!";
    } else {
        $delete_query = "DELETE FROM kelas WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("i", $id);
        
        if ($delete_stmt->execute()) {
            header('Location: tambah_kelas.php?delete_success=1');
            exit();
        } else {
            $error = "Gagal menghapus kelas: " . $conn->error;
        }
    }
}

// Handle edit kelas request
if (isset($_POST['edit_confirm'])) {
    $id = intval($_POST['edit_id']);
    $nama = trim($_POST['edit_nama']);
    $jurusan_id = intval($_POST['edit_jurusan']);
    
    if (empty($nama)) {
        $error = "Nama kelas tidak boleh kosong!";
    } elseif (strlen($nama) < 3) {
        $error = "Nama kelas harus minimal 3 karakter!";
    } else {
        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("sii", $nama, $jurusan_id, $id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        
        if ($result->num_rows > 0) {
            $error = "Kelas dengan nama tersebut sudah ada di jurusan ini!";
        } else {
            $update_query = "UPDATE kelas SET nama_kelas = ?, jurusan_id = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sii", $nama, $jurusan_id, $id);
            
            if ($update_stmt->execute()) {
                header('Location: tambah_kelas.php?edit_success=1');
                exit();
            } else {
                $error = "Gagal mengupdate kelas: " . $conn->error;
            }
        }
    }
}

// Handle form submission for new kelas
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_id']) && !isset($_POST['edit_confirm'])) {
    if (isset($_POST['confirm'])) {
        $nama_kelas = trim($_POST['nama_kelas']);
        $jurusan_id = intval($_POST['jurusan_id']);
        
        if (empty($nama_kelas)) {
            $error = "Nama kelas tidak boleh kosong!";
        } elseif (strlen($nama_kelas) < 3) {
            $error = "Nama kelas harus minimal 3 karakter!";
        } elseif (empty($jurusan_id)) {
            $error = "Silakan pilih jurusan!";
        } else {
            $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $nama_kelas, $jurusan_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = "Kelas sudah ada di jurusan ini!";
            } else {
                $query = "INSERT INTO kelas (nama_kelas, jurusan_id) VALUES (?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("si", $nama_kelas, $jurusan_id);
                
                if ($stmt->execute()) {
                    header('Location: tambah_kelas.php?success=1');
                    exit();
                } else {
                    $error = "Gagal menambahkan kelas: " . $conn->error;
                }
            }
        }
    } else {
        $nama_kelas = trim($_POST['nama_kelas']);
        $jurusan_id = intval($_POST['jurusan_id']);
        if (empty($nama_kelas)) {
            $error = "Nama kelas tidak boleh kosong!";
        } elseif (strlen($nama_kelas) < 3) {
            $error = "Nama kelas harus minimal 3 karakter!";
        } elseif (empty($jurusan_id)) {
            $error = "Silakan pilih jurusan!";
        } else {
            header('Location: tambah_kelas.php?confirm=1&nama=' . urlencode($nama_kelas) . '&jurusan_id=' . $jurusan_id);
            exit();
        }
    }
}

// Ambil data jurusan for dropdowns
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC";
$result_jurusan = $conn->query($query_jurusan);

// Fetch existing kelas with jurusan info and total students
$query_list = "SELECT j.nama_jurusan, k.id as kelas_id, k.nama_kelas, k.jurusan_id, COUNT(u.id) as total_siswa 
               FROM kelas k 
               LEFT JOIN jurusan j ON k.jurusan_id = j.id
               LEFT JOIN users u ON k.id = u.kelas_id 
               GROUP BY j.nama_jurusan, k.id 
               ORDER BY j.nama_jurusan ASC, k.nama_kelas ASC";
$result = $conn->query($query_list);

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
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
            transform: scale(1.02);
        }

        .btn {
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
            width: auto;
            max-width: 200px;
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
            padding: 12px 25px;
            border-radius: 10px;
            cursor: pointer;
            font-size: clamp(0.9rem, 2vw, 1rem);
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

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.5s ease-out;
            font-size: clamp(0.85rem, 1.8vw, 0.95rem);
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
            border-left: 5px solid #fecaca;
        }

        .alert-success {
            background-color: var(--primary-light);
            color: var(--primary-dark);
            border-left: 5px solid var(--primary-color);
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
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .jurusan-title {
            margin: 20px 0 15px;
            color: var(--primary-dark);
            font-size: clamp(1rem, 2vw, 1.1rem);
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
            padding: 5px 10px;
            border-radius: 12px;
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
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
            font-size: clamp(1.1rem, 2.5vw, 1.2rem);
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

        .modal-buttons {
            display: flex;
            gap: 10px;
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

        .modal-content-confirm {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .modal-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: var(--text-secondary);
        }

        .modal-value {
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }

        .loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: var(--text-secondary);
            animation: slideIn 0.5s ease-out;
        }

        .no-data i {
            font-size: clamp(2rem, 4vw, 2.5rem);
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

            .form-card, .jurusan-list {
                padding: 20px;
            }

            form {
                gap: 15px;
            }

            .btn {
                width: 100%;
                max-width: none;
                justify-content: center;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            th, td {
                padding: 10px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .action-buttons {
                flex-direction: column;
                gap: 5px;
            }

            .modal-content {
                margin: 15px;
                width: calc(100% - 30px);
                padding: 20px;
            }

            .modal-buttons {
                flex-direction: column;
            }

            .success-modal {
                width: 90%;
                padding: 30px;
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
                font-size: clamp(1rem, 2.5vw, 1.1rem);
            }

            .no-data i {
                font-size: clamp(1.8rem, 4vw, 2rem);
            }

            .success-modal h3 {
                font-size: clamp(1.2rem, 3vw, 1.4rem);
            }

            .success-modal p {
                font-size: clamp(0.9rem, 2.2vw, 1rem);
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

        <!-- Alerts -->
        <div id="alertContainer">
            <?php if (isset($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['edit_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Kelas berhasil diupdate!
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['delete_success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    Kelas berhasil dihapus!
                </div>
            <?php endif; ?>
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
                                $no = 1;
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
                                                        onclick="showEditModal(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars($row['nama_kelas'], ENT_QUOTES); ?>', <?php echo $row['jurusan_id']; ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-delete" 
                                                        onclick="deleteKelas(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars($row['nama_kelas'], ENT_QUOTES); ?>')">
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
                                echo htmlspecialchars($jurusan_result['nama_jurusan']);
                                ?>
                            </span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="nama_kelas" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <input type="hidden" name="jurusan_id" value="<?php echo htmlspecialchars($_GET['jurusan_id']); ?>">
                        <div class="modal-buttons">
                            <button type="submit" name="confirm" class="btn btn-confirm" id="confirm-btn">
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
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i>
                Edit Kelas
            </div>
            <form id="editForm" method="POST" action="" onsubmit="return showEditConfirmation()">
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
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_nama').value = nama;
            document.getElementById('edit_jurusan').value = jurusanId;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function showEditConfirmation() {
            const nama = document.getElementById('edit_nama').value.trim();
            const jurusanId = document.getElementById('edit_jurusan').value;
            if (nama.length < 3) {
                showAlert('Nama kelas harus minimal 3 karakter!', 'error');
                return false;
            }
            if (!jurusanId) {
                showAlert('Silakan pilih jurusan!', 'error');
                return false;
            }
            document.getElementById('editConfirmId').value = document.getElementById('edit_id').value;
            document.getElementById('editConfirmNama').textContent = nama;
            document.getElementById('editConfirmNamaInput').value = nama;
            document.getElementById('editConfirmJurusanInput').value = jurusanId;
            const jurusanText = document.querySelector(`#edit_jurusan option[value="${jurusanId}"]`).textContent.trim();
            document.getElementById('editConfirmJurusan').textContent = jurusanText;
            document.getElementById('editConfirmModal').style.display = 'flex';
            return false;
        }

        function hideEditConfirmModal() {
            document.getElementById('editConfirmModal').style.display = 'none';
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

        // Delete Kelas with Animation
        function deleteKelas(id, nama) {
            showDeleteModal(id, nama);
            const deleteForm = document.getElementById('deleteForm');
            const deleteBtn = document.getElementById('delete-submit-btn');
            
            deleteForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const row = document.getElementById(`row-${id}`);
                if (row) {
                    row.classList.add('deleting');
                    setTimeout(() => {
                        deleteForm.submit();
                    }, 500);
                } else {
                    deleteForm.submit();
                }
            }, { once: true });
        }

        // Show Alert Function
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

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Handle alert animations and other events
        document.addEventListener('DOMContentLoaded', () => {
            // Handle alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.classList.add('hide');
                    setTimeout(() => alert.remove(), 500);
                }, 5000);
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

            // Handle success modals
            const successModals = document.querySelectorAll('#successModal .success-modal, #editSuccessModal .success-modal, #deleteSuccessModal .success-modal');
            successModals.forEach(modal => {
                if (modal) {
                    for (let i = 0; i < 30; i++) {
                        const confetti = document.createElement('div');
                        const delay = Math.random() * 1;
                        const duration = Math.random() * 2 + 3;
                        confetti.className = 'confetti';
                        confetti.style.left = Math.random() * 100 + '%';
                        confetti.style.animationDelay = `${delay}s`;
                        confetti.style.animationDuration = `${duration}s`;
                        modal.appendChild(confetti);
                    }
                    setTimeout(() => {
                        const overlay = modal.closest('.success-overlay');
                        overlay.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                        setTimeout(() => {
                            overlay.remove();
                            window.location.href = 'tambah_kelas.php';
                        }, 500);
                    }, 2000);
                }
            });

            // Form submission handling
            const kelasForm = document.getElementById('kelas-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (kelasForm && submitBtn) {
                kelasForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama_kelas').value.trim();
                    const jurusan = document.getElementById('jurusan_id').value;
                    if (nama.length < 3) {
                        e.preventDefault();
                        showAlert('Nama kelas harus minimal 3 karakter!', 'error');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = 'Tambah Kelas';
                    } else if (!jurusan) {
                        e.preventDefault();
                        showAlert('Silakan pilih jurusan!', 'error');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = 'Tambah Kelas';
                    } else {
                        submitBtn.classList.add('loading');
                        submitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                    }
                });
            }

            // Confirm form handling
            const confirmForm = document.getElementById('confirm-form');
            const confirmBtn = document.getElementById('confirm-btn');
            
            if (confirmForm && confirmBtn) {
                confirmForm.addEventListener('submit', function() {
                    confirmBtn.classList.add('loading');
                    confirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
                    kelasForm.reset();
                });
            }

            // Edit form handling
            const editForm = document.getElementById('editForm');
            const editSubmitBtn = document.getElementById('edit-submit-btn');
            
            if (editForm && editSubmitBtn) {
                editForm.addEventListener('submit', function() {
                    editSubmitBtn.classList.add('loading');
                    editSubmitBtn.innerHTML = '<i class="fas fa-spinner"></i> Memproses...';
                });
            }

            // Edit confirm form handling
            const editConfirmForm = document.getElementById('editConfirmForm');
            const editConfirmBtn = document.getElementById('edit-confirm-btn');
            
            if (editConfirmForm && editConfirmBtn) {
                editConfirmForm.addEventListener('submit', function() {
                    editConfirmBtn.classList.add('loading');
                    editConfirmBtn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
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