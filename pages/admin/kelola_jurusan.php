<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: login.php");
    exit;
}

// Initialize variables
$error_message = '';
$success = '';
$nama_jurusan = '';
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

// Get total number of jurusan for pagination
$total_query = "SELECT COUNT(*) as total FROM jurusan";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && $_POST['token'] === $_SESSION['form_token']) {
    $post_handled = true;

    if (isset($_POST['delete_id'])) {
        // Handle Delete Request
        $delete_id = intval($_POST['delete_id']);
        
        // Check if jurusan has associated students
        $check_users_query = "SELECT COUNT(*) as count FROM users WHERE jurusan_id = ?";
        $check_users_stmt = $conn->prepare($check_users_query);
        $check_users_stmt->bind_param("i", $delete_id);
        $check_users_stmt->execute();
        $check_users_result = $check_users_stmt->get_result()->fetch_assoc();
        
        // Check if jurusan has associated kelas
        $check_kelas_query = "SELECT COUNT(*) as count FROM kelas WHERE jurusan_id = ?";
        $check_kelas_stmt = $conn->prepare($check_kelas_query);
        $check_kelas_stmt->bind_param("i", $delete_id);
        $check_kelas_stmt->execute();
        $check_kelas_result = $check_kelas_stmt->get_result()->fetch_assoc();
        
        if ($check_users_result['count'] > 0) {
            $error_message = "Tidak dapat menghapus jurusan karena masih memiliki siswa!";
            $show_error_modal = true;
        } elseif ($check_kelas_result['count'] > 0) {
            $error_message = "Tidak dapat menghapus jurusan karena masih memiliki kelas terkait!";
            $show_error_modal = true;
        } else {
            $conn->begin_transaction();
            try {
                $delete_query = "DELETE FROM jurusan WHERE id = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("i", $delete_id);
                $delete_stmt->execute();
                
                $conn->commit();
                $_SESSION['form_token'] = bin2hex(random_bytes(32));
                header('Location: kelola_jurusan.php?delete_success=1');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Gagal menghapus jurusan: " . $e->getMessage();
                $show_error_modal = true;
            }
        }
    } elseif (isset($_POST['edit_confirm'])) {
        // Handle Edit Submission
        $id = (int)$_POST['edit_id'];
        $nama = trim($_POST['edit_nama']);
        
        if (empty($nama)) {
            $error_message = "Nama jurusan tidak boleh kosong!";
            $show_error_modal = true;
        } elseif (strlen($nama) < 3) {
            $error_message = "Nama jurusan harus minimal 3 karakter!";
            $show_error_modal = true;
        } else {
            // Check for duplicate jurusan name
            $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ? AND id != ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("si", $nama, $id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error_message = "Jurusan dengan nama tersebut sudah ada!";
                $show_error_modal = true;
            } else {
                $conn->begin_transaction();
                try {
                    $update_query = "UPDATE jurusan SET nama_jurusan = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("si", $nama, $id);
                    $update_stmt->execute();
                    
                    $conn->commit();
                    $_SESSION['form_token'] = bin2hex(random_bytes(32));
                    header('Location: kelola_jurusan.php?edit_success=1');
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Gagal mengupdate jurusan: " . $e->getMessage();
                    $show_error_modal = true;
                }
            }
        }
        if ($show_error_modal) {
            $nama_jurusan = $nama;
        }
    } else {
        // Handle Form Submission (Add)
        $form_id = isset($_POST['id']) ? (int)$_POST['id'] : null;
        $nama_jurusan_input = trim($_POST['nama_jurusan']);
        
        if (isset($_POST['confirm'])) {
            if (empty($nama_jurusan_input)) {
                $error_message = "Nama jurusan tidak boleh kosong!";
                $show_error_modal = true;
            } elseif (strlen($nama_jurusan_input) < 3) {
                $error_message = "Nama jurusan harus minimal 3 karakter!";
                $show_error_modal = true;
            } else {
                $conn->begin_transaction();
                try {
                    if ($form_id) {
                        $error_message = "Operasi tidak valid!";
                        $show_error_modal = true;
                    } else {
                        $check_query = "SELECT id FROM jurusan WHERE nama_jurusan = ?";
                        $check_stmt = $conn->prepare($check_query);
                        $check_stmt->bind_param("s", $nama_jurusan_input);
                        $check_stmt->execute();
                        $result_check = $check_stmt->get_result();

                        if ($result_check->num_rows > 0) {
                            $error_message = "Jurusan sudah ada!";
                            $show_error_modal = true;
                            throw new Exception($error_message);
                        }

                        $query = "INSERT INTO jurusan (nama_jurusan) VALUES (?)";
                        $stmt = $conn->prepare($query);
                        $stmt->bind_param("s", $nama_jurusan_input);
                        $stmt->execute();

                        $conn->commit();
                        $_SESSION['form_token'] = bin2hex(random_bytes(32));
                        header('Location: kelola_jurusan.php?success=1');
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
                $nama_jurusan = $nama_jurusan_input;
            }
        } else {
            if (empty($nama_jurusan_input)) {
                $error_message = "Nama jurusan tidak boleh kosong!";
                $show_error_modal = true;
            } elseif (strlen($nama_jurusan_input) < 3) {
                $error_message = "Nama jurusan harus minimal 3 karakter!";
                $show_error_modal = true;
            } else {
                if (!$show_error_modal) {
                    header('Location: kelola_jurusan.php?confirm=1&nama=' . urlencode($nama_jurusan_input));
                    exit;
                }
            }
            if ($show_error_modal) {
                $nama_jurusan = $nama_jurusan_input;
            }
        }
    }
}

// Handle Edit Request (only if no POST handled)
if (!$post_handled && isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $query = "SELECT * FROM jurusan WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nama_jurusan = $row['nama_jurusan'];
    } else {
        $error_message = "Jurusan tidak ditemukan!";
        $show_error_modal = true;
    }
}

// Fetch existing jurusan for the current page
$query_list = "SELECT * FROM jurusan ORDER BY nama_jurusan ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query_list);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();

$jurusan = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $jurusan[] = $row;
    }
}

date_default_timezone_set('Asia/Jakarta');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <title>Kelola Data Jurusan | MY Schobank</title>
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

        .form-card, .jurusan-list {
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

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        input[type="text"] {
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

        input[type="text"]:focus {
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
            transition: var(--transition);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%);
        }

        /* UKURAN BUTTON YANG DIPERBAIKI */
        .btn-edit, .btn-delete {
            width: auto;
            min-width: 70px;
            padding: 8px 12px;
            font-size: 0.8rem;
            margin: 0;
        }

        .btn-cancel {
            width: auto;
            min-width: 70px;
            padding: 8px 12px;
            font-size: 0.8rem;
            background: #f0f0f0;
            color: var(--text-secondary);
            margin: 0;
        }

        .btn-edit {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%);
        }

        .btn-delete {
            background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%);
        }

        .jurusan-list h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
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
            min-width: 500px;
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

            .form-card, .jurusan-list { padding: 20px; }

            .btn { width: 100%; max-width: 300px; }

            /* Button Size untuk Mobile - DIPERBAIKI */
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
                min-width: 450px;
                display: table;
            }

            th, td { 
                padding: 10px 12px; 
                font-size: 0.85rem; 
            }

            /* iOS specific fixes for inputs */
            input[type="text"] {
                padding: 12px 15px !important;
                font-size: 16px; /* Prevents zoom on iOS */
                -webkit-appearance: none !important;
                appearance: none !important;
            }
        }

        @media (max-width: 480px) {
            .welcome-banner { padding: 15px; }
            .form-card, .jurusan-list { padding: 15px; }

            /* Button Size untuk Extra Small Mobile - DIPERBAIKI */
            .btn-edit, .btn-delete { 
                padding: 8px 15px; 
                min-width: 80px; 
                font-size: 0.8rem; 
            }

            table {
                min-width: 500px;
            }

            th, td {
                padding: 8px 10px;
                font-size: 0.8rem;
            }

            input[type="text"] {
                padding: 10px 12px !important;
                font-size: 16px !important;
            }
        }

        /* Custom SweetAlert Input Styling */
        .swal2-input {
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
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </span>
            <div class="content">
                <h2>Kelola Data Jurusan</h2>
                <p>Kelola data jurusan yang tersedia </p>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="jurusan-form">
                <input type="hidden" name="token" value="<?php echo $token; ?>">
                <input type="hidden" name="id" value="<?php echo $id; ?>">
                <div class="form-group">
                    <label for="nama_jurusan">Nama Jurusan</label>
                    <input type="text" id="nama_jurusan" name="nama_jurusan" value="<?php echo htmlspecialchars($nama_jurusan); ?>" placeholder="Masukkan nama jurusan" required>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-plus-circle"></i> <?php echo $id ? 'Update Jurusan' : 'Tambah'; ?></span>
                    </button>
                    <?php if ($id): ?>
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='kelola_jurusan.php'">
                            <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Jurusan List -->
        <div class="jurusan-list">
            <h3><i class="fas fa-list"></i> Daftar Jurusan</h3>
            <?php if (!empty($jurusan)): ?>
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
                                <th style="width: 60%;">Nama Jurusan</th>
                                <th style="width: 30%;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="jurusan-table">
                            <?php 
                            $no = ($page - 1) * $items_per_page + 1;
                            foreach ($jurusan as $row): 
                            ?>
                                <tr id="row-<?php echo $row['id']; ?>">
                                    <td><?php echo $no++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($row['nama_jurusan']); ?></strong></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-edit" 
                                                    onclick="editJurusan(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_jurusan']), ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-delete" 
                                                    onclick="deleteJurusan(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['nama_jurusan']), ENT_QUOTES); ?>')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
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
                    <p>Belum ada data jurusan</p>
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

            // === Edit Jurusan (Dengan SweetAlert Rapih) ===
            window.editJurusan = function(id, nama) {
                Swal.fire({
                    title: '<i class="fas fa-edit" style="color:#1e3a8a;"></i> Edit Jurusan',
                    html: `
                        <div style="text-align:center; margin-bottom:15px; padding: 0 20px;">
                            <div style="margin-bottom:15px; max-width: 400px; margin-left: auto; margin-right: auto;">
                                <label style="font-weight:500; color:#555; font-size:0.95rem; display:block; margin-bottom:5px; text-align: left;">Nama Jurusan</label>
                                <input id="swal-input-nama" class="swal2-input" placeholder="Masukkan nama jurusan" value="${nama}" style="width:100% !important; max-width:none !important; margin:10px 0 !important;">
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
                        const input = document.getElementById('swal-input-nama');
                        input.focus();
                        input.setSelectionRange(nama.length, nama.length);
                    },
                    preConfirm: () => {
                        const input = document.getElementById('swal-input-nama');
                        const value = input.value.trim();
                        if (!value) {
                            Swal.showValidationMessage('Nama jurusan tidak boleh kosong!');
                            return false;
                        }
                        if (value.length < 3) {
                            Swal.showValidationMessage('Minimal 3 karakter!');
                            return false;
                        }
                        return value;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="token" value="<?php echo $token; ?>">
                            <input type="hidden" name="edit_id" value="${id}">
                            <input type="hidden" name="edit_nama" value="${result.value}">
                            <input type="hidden" name="edit_confirm" value="1">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };

            // === Hapus Jurusan ===
            window.deleteJurusan = function(id, nama) {
                Swal.fire({
                    title: 'Hapus Jurusan?',
                    html: `Apakah Anda yakin ingin menghapus jurusan <strong>"${nama}"</strong>?`,
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

            // === Form Tambah Jurusan ===
            const jurusanForm = document.getElementById('jurusan-form');
            if (jurusanForm) {
                jurusanForm.addEventListener('submit', function(e) {
                    const nama = document.getElementById('nama_jurusan').value.trim();
                    if (!nama || nama.length < 3) {
                        e.preventDefault();
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: 'Nama jurusan harus diisi dan minimal 3 karakter!',
                            confirmButtonColor: '#1e3a8a'
                        });
                    }
                });
            }

            // === SweetAlert2 Auto Show dari URL ===
            <?php if (isset($_GET['confirm']) && isset($_GET['nama']) && !$show_error_modal): ?>
            Swal.fire({
                title: 'KONFIRMASI JURUSAN BARU',
                html: `<div style="font-size: 1.8rem; text-align: center; margin-top: 10px; font-weight: bold;"><?php echo htmlspecialchars(urldecode($_GET['nama'])); ?></div>`,
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
                        <input type="hidden" name="nama_jurusan" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <input type="hidden" name="confirm" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    window.location.href = 'kelola_jurusan.php';
                }
            });
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Jurusan berhasil ditambahkan!',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            <?php if (isset($_GET['edit_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Jurusan berhasil diupdate!',
                confirmButtonColor: '#1e3a8a'
            });
            <?php endif; ?>

            <?php if (isset($_GET['delete_success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Berhasil!',
                text: 'Jurusan berhasil dihapus!',
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

            // Input hanya huruf & spasi
            document.querySelectorAll('#nama_jurusan').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^A-Za-z\s]/g, '');
                    if (this.value.length > 50) this.value = this.value.substring(0, 50);
                });
            });
        });
    </script>
</body>
</html>