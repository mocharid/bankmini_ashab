<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'Petugas';
$error_message = '';
$success = '';
$nama_kelas = '';
$jurusan_id = '';
$tingkatan_kelas_id = '';
$id = null;
$show_error_modal = false;
$post_handled = false;

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Pagination settings
$items_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Get total number of kelas for pagination
$total_query = "SELECT COUNT(*) as total FROM kelas";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $post_handled = true;
    
    if (isset($_POST['delete_id'])) {
        // Handle Delete Request
        $delete_id = intval($_POST['delete_id']);
        
        $check_query = "SELECT COUNT(*) as count FROM users WHERE kelas_id = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("i", $delete_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result()->fetch_assoc();
        
        if ($check_result['count'] > 0) {
            $error_message = "Tidak dapat menghapus kelas karena masih memiliki siswa!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                $delete_query = "DELETE FROM kelas WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $delete_id);
                $delete_stmt->execute();
                
                $conn->commit();
                $_SESSION['form_token'] = bin2hex(random_bytes(32));
                header('Location: kelola_kelas.php?delete_success=1');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Gagal menghapus kelas: " . $e->getMessage();
                $show_error_modal = true;
            }
        }
    } elseif (isset($_POST['edit_confirm'])) {
        // Handle Edit Submission
        $id = intval($_POST['edit_id']);
        $nama = trim($_POST['edit_nama']);
        $jurusan_id = intval($_POST['edit_jurusan']);
        $tingkatan_kelas_id = intval($_POST['edit_tingkatan']);
        
        if (empty($nama)) {
            $error_message = "Nama kelas tidak boleh kosong!";
            $show_error_modal = true;
        } elseif (strlen($nama) < 3) {
            $error_message = "Nama kelas harus minimal 3 karakter!";
            $show_error_modal = true;
        } elseif (empty($jurusan_id)) {
            $error_message = "Silakan pilih jurusan!";
            $show_error_modal = true;
        } elseif (empty($tingkatan_kelas_id)) {
            $error_message = "Silakan pilih tingkatan kelas!";
            $show_error_modal = true;
        } else {
            $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ? AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("siii", $nama, $jurusan_id, $tingkatan_kelas_id, $id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Kelas dengan nama tersebut sudah ada di jurusan dan tingkatan ini!";
                $show_error_modal = true;
            } else {
                $conn->begin_transaction();
                try {
                    $update_query = "UPDATE kelas SET nama_kelas = ?, jurusan_id = ?, tingkatan_kelas_id = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("siii", $nama, $jurusan_id, $tingkatan_kelas_id, $id);
                    $update_stmt->execute();
                    
                    $conn->commit();
                    $_SESSION['form_token'] = bin2hex(random_bytes(32));
                    header('Location: kelola_kelas.php?edit_success=1');
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Gagal mengupdate kelas: " . $e->getMessage();
                    $show_error_modal = true;
                }
            }
        }
        if ($show_error_modal) {
            $nama_kelas = $nama;
            $jurusan_id = $_POST['edit_jurusan'];
            $tingkatan_kelas_id = $_POST['edit_tingkatan'];
        }
    } else {
        // Handle Form Submission (Add)
        $form_id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $nama_kelas_input = trim($_POST['nama_kelas']);
        $jurusan_id_input = intval($_POST['jurusan_id']);
        $tingkatan_kelas_id_input = intval($_POST['tingkatan_id']);
        
        if (isset($_POST['confirm'])) {
            if (empty($nama_kelas_input)) {
                $error_message = "Nama kelas tidak boleh kosong!";
                $show_error_modal = true;
            } elseif (strlen($nama_kelas_input) < 3) {
                $error_message = "Nama kelas harus minimal 3 karakter!";
                $show_error_modal = true;
            } elseif (empty($jurusan_id_input)) {
                $error_message = "Silakan pilih jurusan!";
                $show_error_modal = true;
            } elseif (empty($tingkatan_kelas_id_input)) {
                $error_message = "Silakan pilih tingkatan kelas!";
                $show_error_modal = true;
            } else {
                $conn->begin_transaction();
                try {
                    if ($form_id) {
                        $error_message = "Operasi tidak valid!";
                        $show_error_modal = true;
                    } else {
                        $check_query = "SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ?";
                        $check_stmt = $conn->prepare($check_query);
                        $check_stmt->bind_param("sii", $nama_kelas_input, $jurusan_id_input, $tingkatan_kelas_id_input);
                        $check_stmt->execute();
                        $result_check = $check_stmt->get_result();
                        
                        if ($result_check->num_rows > 0) {
                            $error_message = "Kelas sudah ada di jurusan dan tingkatan ini!";
                            $show_error_modal = true;
                            throw new Exception($error_message);
                        }
                        
                        $query = "INSERT INTO kelas (nama_kelas, jurusan_id, tingkatan_kelas_id) VALUES (?, ?, ?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("sii", $nama_kelas_input, $jurusan_id_input, $tingkatan_kelas_id_input);
                        $stmt->execute();
                        
                        $conn->commit();
                        $_SESSION['form_token'] = bin2hex(random_bytes(32));
                        header('Location: kelola_kelas.php?success=1');
                        exit;
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    if (!$show_error_modal) {
                        $error_message = $e->getMessage();
                        $show_error_modal = true;
                    }
                }
            }
            if ($show_error_modal) {
                $nama_kelas = $nama_kelas_input;
                $jurusan_id = $jurusan_id_input;
                $tingkatan_kelas_id = $tingkatan_kelas_id_input;
            }
        } else {
            if (empty($nama_kelas_input)) {
                $error_message = "Nama kelas tidak boleh kosong!";
                $show_error_modal = true;
            } elseif (strlen($nama_kelas_input) < 3) {
                $error_message = "Nama kelas harus minimal 3 karakter!";
                $show_error_modal = true;
            } elseif (empty($jurusan_id_input)) {
                $error_message = "Silakan pilih jurusan!";
                $show_error_modal = true;
            } elseif (empty($tingkatan_kelas_id_input)) {
                $error_message = "Silakan pilih tingkatan kelas!";
                $show_error_modal = true;
            } else {
                if (!$show_error_modal) {
                    header('Location: kelola_kelas.php?confirm=1&nama=' . urlencode($nama_kelas_input) . '&jurusan_id=' . $jurusan_id_input . '&tingkatan_id=' . $tingkatan_kelas_id_input);
                    exit;
                }
            }
            if ($show_error_modal) {
                $nama_kelas = $nama_kelas_input;
                $jurusan_id = $jurusan_id_input;
                $tingkatan_kelas_id = $tingkatan_kelas_id_input;
            }
        }
    }
}

// Handle Edit Request (only if no POST handled)
if (!$post_handled && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $query = "SELECT * FROM kelas WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nama_kelas = $row['nama_kelas'];
        $jurusan_id = $row['jurusan_id'];
        $tingkatan_kelas_id = $row['tingkatan_kelas_id'];
    } else {
        $error_message = "Kelas tidak ditemukan!";
        $show_error_modal = true;
    }
}

// Fetch jurusan and tingkatan kelas for dropdowns
$query_jurusan = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC";
$result_jurusan = $conn->query($query_jurusan);

$query_tingkatan = "SELECT * FROM tingkatan_kelas ORDER BY nama_tingkatan ASC";
$result_tingkatan = $conn->query($query_tingkatan);

// Fetch existing kelas with jurusan and tingkatan info
$query_list = "SELECT j.nama_jurusan, tk.nama_tingkatan, k.id as kelas_id, k.nama_kelas, k.jurusan_id, k.tingkatan_kelas_id
               FROM kelas k
               LEFT JOIN jurusan j ON k.jurusan_id = j.id
               LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
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

// === PREPARE MAPS FOR JAVASCRIPT ===
$jurusan_map = [];
$result_jurusan->data_seek(0);
while ($row = $result_jurusan->fetch_assoc()) {
    $jurusan_map[$row['id']] = $row['nama_jurusan'];
}

$tingkatan_map = [];
$result_tingkatan->data_seek(0);
while ($row = $result_tingkatan->fetch_assoc()) {
    $tingkatan_map[$row['id']] = $row['nama_tingkatan'];
}

date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <title>Kelola Data Kelas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- SweetAlert2 CDN -->
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
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            max-width: calc(100% - 280px);
            overflow-x: hidden;
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
            font-size: 1.6rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-banner p {
            font-size: 0.9rem;
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

        .form-card, .kelas-list {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }

        form {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .form-row {
            display: flex;
            gap: 20px;
            flex: 1;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        input[type="text"],
        select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
            user-select: text;
            -webkit-user-select: text;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background: white;
        }

        select {
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
        }

        .btn {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 200px;
            margin: 0 auto;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        .btn-edit, .btn-delete, .btn-confirm, .btn-cancel {
            width: auto;
            min-width: 70px;
            padding: 8px 12px;
            font-size: 0.8rem;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .btn-cancel {
            background: #f0f0f0;
            color: var(--text-secondary);
        }

        .btn-confirm {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .kelas-list h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .jurusan-title {
            margin: 15px 0 12px;
            color: var(--primary-dark);
            font-size: 1.15rem;
            font-weight: 600;
        }

        /* Table Wrapper - KUNCI UNTUK SCROLL HORIZONTAL */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid #eee;
        }

        table {
            width: 100%;
            min-width: 700px;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            font-size: 0.9rem;
            border-bottom: 1px solid #eee;
            white-space: nowrap;
        }

        th {
            background: var(--bg-light);
            color: var(--text-secondary);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        tr:hover {
            background-color: var(--bg-light);
        }

        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: flex-start;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 25px;
        }

        .pagination a {
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 5px;
            transition: var(--transition);
            font-size: 1rem;
            min-width: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }

        .pagination a:not(.disabled) {
            background-color: var(--primary-color);
            color: white;
        }

        .pagination a:hover:not(.disabled) {
            background-color: var(--primary-dark);
        }

        .pagination .current-page {
            font-weight: 500;
            font-size: 1rem;
            min-width: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .pagination a.disabled {
            color: var(--text-secondary);
            background-color: #e0e0e0;
            cursor: not-allowed;
            pointer-events: none;
        }

        .no-data {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            background: #f8fafc;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
        }

        .no-data i {
            font-size: 2rem;
            color: #d1d5db;
            margin-bottom: 15px;
        }

        /* Scroll Hint untuk Mobile */
        .scroll-hint {
            display: none;
            text-align: center;
            padding: 8px;
            background: #fff3cd;
            color: #856404;
            border-radius: 5px 5px 0 0;
            font-size: 0.85rem;
            border: 1px solid #ffeeba;
            border-bottom: none;
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
                max-width: 100%;
                padding: 15px;
            }

            .menu-toggle { display: block; }

            .welcome-banner {
                padding: 20px;
                border-radius: 5px;
            }

            .welcome-banner h2 { font-size: 1.4rem; }
            .welcome-banner p { font-size: 0.85rem; }

            .form-card, .kelas-list { padding: 20px; }

            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .btn { width: 100%; max-width: 300px; }

            .btn-edit, .btn-delete { 
                padding: 10px 20px; 
                min-width: 100px; 
                font-size: 0.9rem; 
                width: auto;
            }

            /* Tampilkan scroll hint di mobile */
            .scroll-hint {
                display: block;
            }

            /* Pastikan table wrapper bisa di scroll */
            .table-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                display: block;
                border: 1px solid #ddd;
                border-radius: 5px;
            }

            table {
                min-width: 750px;
                display: table;
            }

            th, td { 
                padding: 10px 12px; 
                font-size: 0.85rem; 
            }

            /* iOS specific fixes for inputs */
            input[type="text"], select {
                padding: 12px 15px !important;
                font-size: 16px; /* Prevents zoom on iOS */
                -webkit-appearance: none !important;
                appearance: none !important;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner { padding: 15px; }
            .form-card, .kelas-list { padding: 15px; }

            .btn-edit, .btn-delete { 
                padding: 8px 15px; 
                min-width: 80px; 
                font-size: 0.8rem; 
            }

            table {
                min-width: 800px;
            }

            th, td {
                padding: 8px 10px;
                font-size: 0.8rem;
            }

            input[type="text"], select {
                padding: 10px 12px !important;
                font-size: 16px !important;
            }
        }

        /* Custom SweetAlert Input Styling */
        .swal2-input, .swal2-select {
            width: 100% !important;
            max-width: 400px !important;
            padding: 12px 16px !important;
            border: 1px solid #ddd !important;
            border-radius: 5px !important;
            font-size: 16px !important;
            margin: 10px auto !important;
            box-sizing: border-box !important;
            text-align: left !important;
            -webkit-appearance: none !important;
            appearance: none !important;
        }

        .swal2-input:focus, .swal2-select:focus {
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
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2> Kelola Data Kelas</h2>
                <p>Kelola data kelas yang tersedia</p>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="kelas-form">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="tingkatan_id">Tingkatan Kelas</label>
                        <select id="tingkatan_id" name="tingkatan_id" required>
                            <option value="">Pilih Tingkatan</option>
                            <?php
                            $result_tingkatan->data_seek(0);
                            while ($row = $result_tingkatan->fetch_assoc()):
                            ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo $tingkatan_kelas_id == $row['id'] ? 'selected' : ''; ?>>
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
                                <option value="<?php echo $row['id']; ?>" <?php echo $jurusan_id == $row['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="nama_kelas">Nama Kelas</label>
                        <input type="text" id="nama_kelas" name="nama_kelas" value="<?php echo htmlspecialchars($nama_kelas); ?>" required
                               placeholder="Contoh: RPL-1" maxlength="50">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-plus-circle"></i> <?php echo $id ? 'Update Kelas' : 'Tambah'; ?></span>
                    </button>
                    <?php if ($id): ?>
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='kelola_kelas.php'">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Kelas List -->
        <div class="kelas-list">
            <h3><i class="fas fa-list"></i> Daftar Kelas</h3>
            
            <?php if (!empty($kelas_by_jurusan)): ?>
                <?php foreach ($kelas_by_jurusan as $jurusan => $kelas): ?>
                    <h4 class="jurusan-title"><?php echo htmlspecialchars($jurusan); ?></h4>
                    
                    <?php if (!empty($kelas)): ?>
                        <!-- Scroll Hint untuk Mobile -->
                        <div class="scroll-hint">
                            <i class="fas fa-hand-pointer"></i> Geser tabel ke kanan untuk melihat lebih banyak
                        </div>
                        
                        <!-- Table Wrapper - PENTING untuk scroll horizontal -->
                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">No</th>
                                        <th style="width: 20%;">Tingkatan</th>
                                        <th style="width: 50%;">Nama Kelas</th>
                                        <th style="width: 20%;">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $no = ($page - 1) * $items_per_page + 1;
                                    foreach ($kelas as $row):
                                    ?>
                                        <tr id="row-<?php echo $row['kelas_id']; ?>">
                                            <td><?php echo $no++; ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_tingkatan']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($row['nama_kelas']); ?></strong></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-edit"
                                                            onclick="editKelas(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kelas']), ENT_QUOTES); ?>', <?php echo $row['jurusan_id']; ?>, <?php echo $row['tingkatan_kelas_id']; ?>)">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button class="btn btn-delete"
                                                            onclick="deleteKelas(<?php echo $row['kelas_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_kelas']), ENT_QUOTES); ?>')">
                                                        <i class="fas fa-trash"></i> Hapus
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <p>Tidak ada kelas untuk jurusan ini</p>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>">&lt;</a>
                    <?php else: ?>
                        <a class="disabled">&lt;</a>
                    <?php endif; ?>
                    
                    <span class="current-page"><?php echo $page; ?></span>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>">&gt;</a>
                    <?php else: ?>
                        <a class="disabled">&gt;</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-data">
                    <i class="fas fa-info-circle"></i>
                    <p>Belum ada data kelas</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');
            
            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', e => {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
                
                document.addEventListener('click', e => {
                    if (sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                        sidebar.classList.remove('active');
                        document.body.classList.remove('sidebar-active');
                    }
                });
            }

            // === MAPS FROM PHP ===
            const jurusanMap = <?php echo json_encode($jurusan_map); ?>;
            const tingkatanMap = <?php echo json_encode($tingkatan_map); ?>;

            // === Edit Kelas (Dengan SweetAlert Rapih) ===
            window.editKelas = function(id, nama, jurusanId, tingkatanId) {
                Swal.fire({
                    title: '<i class="fas fa-edit" style="color:#1e3a8a;"></i> Edit Kelas',
                    html: `
                        <div style="text-align:center; margin-bottom:15px; padding: 0 20px;">
                            <div style="margin-bottom:15px; max-width: 400px; margin-left: auto; margin-right: auto;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px; text-align: left;">Tingkatan Kelas</label>
                                <select id="swal-tingkatan" class="swal2-select" style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                                    ${Object.entries(tingkatanMap).map(([key, value]) => `<option value="${key}" ${key == tingkatanId ? 'selected' : ''}>${value}</option>`).join('')}
                                </select>
                            </div>
                            <div style="margin-bottom:15px; max-width: 400px; margin-left: auto; margin-right: auto;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px; text-align: left;">Jurusan</label>
                                <select id="swal-jurusan" class="swal2-select" style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                                    ${Object.entries(jurusanMap).map(([key, value]) => `<option value="${key}" ${key == jurusanId ? 'selected' : ''}>${value}</option>`).join('')}
                                </select>
                            </div>
                            <div style="margin-bottom:15px; max-width: 400px; margin-left: auto; margin-right: auto;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px; text-align: left;">Nama Kelas</label>
                                <input id="swal-nama" class="swal2-input" value="${nama}" placeholder="Contoh: RPL-1" maxlength="50" style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
                            </div>
                        </div>
                    `,
                    focusConfirm: false,
                    showCancelButton: true,
                    confirmButtonText: '<i class="fas fa-save"></i> Simpan',
                    cancelButtonText: '<i class="fas fa-times"></i> Batal',
                    confirmButtonColor: '#1e3a8a',
                    cancelButtonColor: '#e74c3c',
                    width: '500px',
                    customClass: {
                        popup: 'animated fadeIn faster'
                    },
                    didOpen: () => {
                        const input = document.getElementById('swal-nama');
                        input.focus();
                        input.setSelectionRange(nama.length, nama.length);
                    },
                    preConfirm: () => {
                        const namaInput = document.getElementById('swal-nama');
                        const jurusanSelect = document.getElementById('swal-jurusan');
                        const tingkatanSelect = document.getElementById('swal-tingkatan');
                        
                        const valueNama = namaInput.value.trim();
                        const valueJurusan = jurusanSelect.value;
                        const valueTingkatan = tingkatanSelect.value;

                        if (!valueNama) {
                            Swal.showValidationMessage('Nama kelas tidak boleh kosong!');
                            return false;
                        }
                        if (valueNama.length < 3) {
                            Swal.showValidationMessage('Nama kelas minimal 3 karakter!');
                            return false;
                        }
                        if (!valueJurusan) {
                            Swal.showValidationMessage('Pilih jurusan!');
                            return false;
                        }
                        if (!valueTingkatan) {
                            Swal.showValidationMessage('Pilih tingkatan!');
                            return false;
                        }
                        
                        return { nama: valueNama, jurusan: valueJurusan, tingkatan: valueTingkatan };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const { nama, jurusan, tingkatan } = result.value;
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="edit_id" value="${id}">
                            <input type="hidden" name="edit_nama" value="${nama}">
                            <input type="hidden" name="edit_jurusan" value="${jurusan}">
                            <input type="hidden" name="edit_tingkatan" value="${tingkatan}">
                            <input type="hidden" name="edit_confirm" value="1">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };

            // === Hapus Kelas ===
            window.deleteKelas = function(id, nama) {
                Swal.fire({
                    title: 'Hapus Kelas?',
                    html: `Apakah Anda yakin ingin menghapus kelas <strong>"${nama}"</strong>?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Hapus',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#e74c3c',
                    cancelButtonColor: '#1e3a8a',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="delete_id" value="${id}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };

            // === Form Tambah Kelas ===
            const kelasForm = document.getElementById('kelas-form');
            if (kelasForm) {
                kelasForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama_kelas').value.trim();
                    const jurusan = document.getElementById('jurusan_id').value;
                    const tingkatan = document.getElementById('tingkatan_id').value;

                    if (!nama || nama.length < 3) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Nama kelas harus diisi dan minimal 3 karakter!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    } else if (!jurusan || !tingkatan) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Silakan pilih jurusan dan tingkatan kelas!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    }
                });
            }

            // === SweetAlert2 Auto Show dari URL ===
            <?php if (isset($_GET['confirm']) && isset($_GET['nama']) && isset($_GET['jurusan_id']) && isset($_GET['tingkatan_id']) && !$show_error_modal): ?>
            const namaKelasConfirm = '<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>';
            const jurusanIdConfirm = <?php echo intval($_GET['jurusan_id']); ?>;
            const tingkatanIdConfirm = <?php echo intval($_GET['tingkatan_id']); ?>;
            const tingkatanNameConfirm = tingkatanMap[tingkatanIdConfirm] || '';
            
            Swal.fire({
                title: 'KONFIRMASI KELAS BARU',
                html: `<div style="font-size: 1.8rem; text-align: center; margin-top: 10px; font-weight: bold;">${tingkatanNameConfirm} ${namaKelasConfirm}</div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Konfirmasi',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a',
                cancelButtonColor: '#e74c3c'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    form.innerHTML = `
                        <input type="hidden" name="token" value="<?php echo $token; ?>">
                        <input type="hidden" name="nama_kelas" value="${namaKelasConfirm}">
                        <input type="hidden" name="jurusan_id" value="${jurusanIdConfirm}">
                        <input type="hidden" name="tingkatan_id" value="${tingkatanIdConfirm}">
                        <input type="hidden" name="confirm" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    window.location.href = 'kelola_kelas.php';
                }
            });
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Kelas berhasil ditambahkan!',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            <?php if (isset($_GET['edit_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Kelas berhasil diupdate!',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            <?php if (isset($_GET['delete_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Kelas berhasil dihapus!',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            <?php if ($show_error_modal): ?>
            Swal.fire({
                icon: 'error',
                title: 'Gagal',
                text: '<?php echo addslashes($error_message); ?>',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            // Input hanya huruf, angka, spasi, dan tanda minus
            document.querySelectorAll('#nama_kelas').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^A-Za-z0-9\s-]/g, '');
                    if (this.value.length > 50) this.value = this.value.substring(0, 50);
                });
            });
        });
    </script>
</body>
</html>