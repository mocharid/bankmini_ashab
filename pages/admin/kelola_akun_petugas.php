<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// Restrict access to non-siswa users
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: login.php");
    exit;
}

$username = $_SESSION['username'] ?? 'Petugas';

// CSRF Token Generation
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['csrf_token'];

// Initialize variables
$error = '';
$success = '';

// Store plain passwords temporarily in session
if (!isset($_SESSION['temp_passwords'])) {
    $_SESSION['temp_passwords'] = [];
}

// Pagination settings
$items_per_page = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $items_per_page;

// Get total number of petugas for pagination
$total_query = "SELECT COUNT(*) as total FROM users WHERE role = 'petugas'";
$total_result = $conn->query($total_query);
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Location: kelola_akun_petugas.php?error=' . urlencode('Invalid CSRF token.'));
        exit;
    }

    // Add Petugas
    if (isset($_POST['confirm'])) {
        $nama = trim($_POST['nama']);
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);
        $username = trim($_POST['username']);
        $password = $_POST['password'];

        if (empty($nama) || empty($petugas1_nama) || empty($petugas2_nama) || empty($username) || empty($password)) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Semua field harus diisi.'));
            exit;
        } elseif (strlen($nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Kode petugas harus minimal 3 karakter!'));
            exit;
        } elseif (strlen($petugas1_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 1 harus minimal 3 karakter!'));
            exit;
        } elseif (strlen($petugas2_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 2 harus minimal 3 karakter!'));
            exit;
        } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Username hanya boleh berisi huruf, angka, titik, dan underscore.'));
            exit;
        } elseif (strlen($password) < 8) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Password harus minimal 8 karakter!'));
            exit;
        }

        $conn->begin_transaction();
        try {
            // Insert user account
            $query = "INSERT INTO users (username, password, role, nama) VALUES (?, SHA2(?, 256), 'petugas', ?)";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $username, $password, $nama);
            $stmt->execute();
            $new_id = $stmt->insert_id;

            // Insert into petugas_info table
            $query_info = "INSERT INTO petugas_info (user_id, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bind_param("iss", $new_id, $petugas1_nama, $petugas2_nama);
            $stmt_info->execute();

            // Store password temporarily
            $_SESSION['temp_passwords'][$new_id] = $password;

            $conn->commit();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: kelola_akun_petugas.php?success=1');
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Terjadi kesalahan saat menambahkan petugas: ' . $e->getMessage()));
            exit;
        }
    }
    // Initial Form Submission
    elseif (!isset($_POST['edit_confirm']) && !isset($_POST['delete_id'])) {
        $petugas1_nama = trim($_POST['petugas1_nama']);
        $petugas2_nama = trim($_POST['petugas2_nama']);

        if (empty($petugas1_nama) || empty($petugas2_nama)) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Semua field harus diisi.'));
            exit;
        } elseif (strlen($petugas1_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 1 harus minimal 3 karakter!'));
            exit;
        } elseif (strlen($petugas2_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 2 harus minimal 3 karakter!'));
            exit;
        }

        // Generate kode otomatis
        $next_number = 1;
        while (true) {
            $kode_suffix = str_pad($next_number, 2, '0', STR_PAD_LEFT);
            $nama = 'MSB' . $kode_suffix;

            $query_check = "SELECT COUNT(*) FROM users WHERE nama = ? AND role = 'petugas'";
            $stmt_check = $conn->prepare($query_check);
            $stmt_check->bind_param("s", $nama);
            $stmt_check->execute();
            $stmt_check->bind_result($count);
            $stmt_check->fetch();
            $stmt_check->close();

            if ($count == 0) {
                break;
            }
            $next_number++;
        }

        // Generate base username
        $nama_lower = strtolower($nama);
        $base_username = preg_replace('/[^a-z0-9._]/', '', $nama_lower);

        // Check if base username exists
        $query = "SELECT COUNT(*) FROM users WHERE username = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $base_username);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            $randomNumber = rand(100, 999);
            $username = $base_username . $randomNumber;
            
            // Check again
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count > 0) {
                header('Location: kelola_akun_petugas.php?error=' . urlencode('Username sudah ada. Silakan coba lagi.'));
                exit;
            }
        } else {
            $username = $base_username;
        }

        $password = "12345678";
        header('Location: kelola_akun_petugas.php?confirm=1&nama=' . urlencode($nama) . '&petugas1_nama=' . urlencode($petugas1_nama) . '&petugas2_nama=' . urlencode($petugas2_nama) . '&username=' . urlencode($username) . '&password=' . urlencode($password));
        exit;
    }
    // Edit Petugas
    elseif (isset($_POST['edit_confirm'])) {
        $id = (int)$_POST['edit_id'];
        $nama = trim($_POST['edit_nama']);
        $petugas1_nama = trim($_POST['edit_petugas1_nama']);
        $petugas2_nama = trim($_POST['edit_petugas2_nama']);
        $username = trim($_POST['edit_username']);
        $password = trim($_POST['edit_password'] ?? '');

        if (empty($nama) || empty($petugas1_nama) || empty($petugas2_nama) || empty($username)) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Semua field harus diisi.'));
            exit;
        } elseif (strlen($nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Kode petugas harus minimal 3 karakter!'));
            exit;
        } elseif (strlen($petugas1_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 1 harus minimal 3 karakter!'));
            exit;
        } elseif (strlen($petugas2_nama) < 3) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Nama Petugas 2 harus minimal 3 karakter!'));
            exit;
        } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username)) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Username hanya boleh berisi huruf, angka, titik, dan underscore.'));
            exit;
        } elseif (!empty($password) && strlen($password) < 8) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Password baru harus minimal 8 karakter!'));
            exit;
        }

        $query = "SELECT COUNT(*) FROM users WHERE username = ? AND id != ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $username, $id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count > 0) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Username sudah digunakan oleh akun lain.'));
            exit;
        }

        $query = "SELECT id FROM users WHERE id = ? AND role = 'petugas'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $result->free();
            $stmt->close();
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Petugas tidak ditemukan atau bukan petugas.'));
            exit;
        }
        $result->free();
        $stmt->close();

        $conn->begin_transaction();
        try {
            if (empty($password)) {
                $query = "UPDATE users SET username = ? WHERE id = ? AND role = 'petugas'";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("si", $username, $id);
            } else {
                $query = "UPDATE users SET username = ?, password = SHA2(?, 256) WHERE id = ? AND role = 'petugas'";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("ssi", $username, $password, $id);
                $_SESSION['temp_passwords'][$id] = $password;
            }
            $stmt->execute();

            // Update petugas_info
            $query_check_info = "SELECT id FROM petugas_info WHERE user_id = ?";
            $stmt_check = $conn->prepare($query_check_info);
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $query_info = "UPDATE petugas_info SET petugas1_nama = ?, petugas2_nama = ? WHERE user_id = ?";
                $stmt_info = $conn->prepare($query_info);
                $stmt_info->bind_param("ssi", $petugas1_nama, $petugas2_nama, $id);
            } else {
                $query_info = "INSERT INTO petugas_info (user_id, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
                $stmt_info = $conn->prepare($query_info);
                $stmt_info->bind_param("iss", $id, $petugas1_nama, $petugas2_nama);
            }
            $stmt_info->execute();

            if ($stmt->affected_rows > 0 || $stmt_info->affected_rows > 0) {
                $conn->commit();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: kelola_akun_petugas.php?edit_success=1');
                exit;
            } else {
                $conn->rollback();
                header('Location: kelola_akun_petugas.php?error=' . urlencode('Tidak ada perubahan dilakukan.'));
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Terjadi kesalahan saat memperbarui petugas: ' . $e->getMessage()));
            exit;
        }
    }
    // Delete Petugas
    elseif (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        
        // Check relations (transaksi, absensi, jadwal)
        $relations = [
            "transaksi" => ["petugas_id", "transaksi terkait"],
            "absensi" => ["user_id", "data absensi terkait"],
        ];

        foreach ($relations as $table => $info) {
            $query = "SELECT COUNT(*) FROM $table WHERE {$info[0]} = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();

            if ($count > 0) {
                header('Location: kelola_akun_petugas.php?error=' . urlencode("Petugas tidak dapat dihapus karena memiliki {$info[1]}."));
                exit;
            }
        }

        // Check jadwal separately due to OR condition
        $query = "SELECT COUNT(*) FROM petugas_tugas WHERE petugas1_id = ? OR petugas2_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ii", $id, $id);
        $stmt->execute();
        $stmt->bind_result($jadwal_count);
        $stmt->fetch();
        $stmt->close();

        if ($jadwal_count > 0) {
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Petugas tidak dapat dihapus karena memiliki jadwal terkait.'));
            exit;
        }

        $conn->begin_transaction();
        try {
            $query_info = "DELETE FROM petugas_info WHERE user_id = ?";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bind_param("i", $id);
            $stmt_info->execute();

            $query = "DELETE FROM users WHERE id = ? AND role = 'petugas'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                unset($_SESSION['temp_passwords'][$id]);
                $conn->commit();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: kelola_akun_petugas.php?delete_success=1');
                exit;
            } else {
                $conn->rollback();
                header('Location: kelola_akun_petugas.php?error=' . urlencode('Petugas tidak ditemukan.'));
                exit;
            }
        } catch (Exception $e) {
            $conn->rollback();
            header('Location: kelola_akun_petugas.php?error=' . urlencode('Terjadi kesalahan saat menghapus petugas: ' . $e->getMessage()));
            exit;
        }
    }
}

// Fetch petugas
$query = "SELECT u.id, u.nama, u.username, pi.petugas1_nama, pi.petugas2_nama
          FROM users u 
          LEFT JOIN petugas_info pi ON u.id = pi.user_id
          WHERE u.role = 'petugas' 
          ORDER BY u.nama ASC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$result = $stmt->get_result();
$petugas_list = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $petugas_list[] = $row;
    }
    $result->free();
}
$stmt->close();

date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="/schobank/assets/images/tab.png">
    <title>Kelola Data Akun Petugas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .welcome-banner .content { flex: 1; }
        .welcome-banner h2 {
            margin-bottom: 10px;
            font-size: 1.6rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .welcome-banner p { font-size: 0.9rem; font-weight: 400; opacity: 0.8; }

        .menu-toggle {
            font-size: 1.5rem;
            cursor: pointer;
            color: white;
            flex-shrink: 0;
            display: none;
            align-self: center;
        }

        .form-card, .petugas-list {
            background: white;
            border-radius: 5px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 35px;
            animation: slideIn 0.5s ease-in-out;
        }

        form { display: flex; flex-direction: column; gap: 20px; }
        .form-row { display: flex; gap: 20px; flex: 1; }
        .form-group { display: flex; flex-direction: column; gap: 8px; flex: 1; }

        label { font-weight: 500; color: var(--text-secondary); font-size: 0.9rem; }

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
            user-select: text;
            -webkit-user-select: text;
            -webkit-appearance: none;
            appearance: none;
            background: white;
            text-transform: uppercase;
        }

        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(12, 77, 162, 0.1);
        }
        input::placeholder { text-transform: none; }

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
        .btn:hover { background: linear-gradient(135deg, var(--secondary-dark) 0%, var(--primary-dark) 100%); }

        .btn-edit, .btn-delete {
            width: auto;
            min-width: 70px;
            padding: 8px 12px;
            font-size: 0.8rem;
        }
        .btn-edit { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--secondary-color) 100%); }
        .btn-delete { background: linear-gradient(135deg, var(--danger-color) 0%, #d32f2f 100%); }

        .petugas-list h3 {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-dark);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

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
            min-width: 800px;
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
        tr:hover { background-color: var(--bg-light); }

        .action-buttons { display: flex; gap: 5px; justify-content: flex-start; }

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
        .pagination a:not(.disabled) { background-color: var(--primary-color); color: white; }
        .pagination a:hover:not(.disabled) { background-color: var(--primary-dark); }
        .pagination .current-page {
            font-weight: 500; font-size: 1rem; min-width: 35px;
            display: flex; align-items: center; justify-content: center;
        }
        .pagination a.disabled { color: var(--text-secondary); background-color: #e0e0e0; cursor: not-allowed; pointer-events: none; }

        .no-data {
            text-align: center;
            padding: 20px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            background: #f8fafc;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
        }
        .no-data i { font-size: 2rem; color: #d1d5db; margin-bottom: 15px; }

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

        .password-hint {
            font-size: 0.85rem;
            color: #666;
            margin-top: -10px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .password-hint i { color: var(--primary-color); }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; max-width: 100%; padding: 15px; }
            .menu-toggle { display: block; }
            .welcome-banner { padding: 20px; }
            .welcome-banner h2 { font-size: 1.4rem; }
            .form-card, .petugas-list { padding: 20px; }
            .btn { width: 100%; max-width: 300px; }
            .form-row { flex-direction: column; gap: 15px; }
            .scroll-hint { display: block; }
            .table-wrapper {
                overflow-x: auto !important;
                -webkit-overflow-scrolling: touch !important;
                display: block;
                border: 1px solid #ddd;
                border-radius: 5px;
            }
            table { min-width: 850px; display: table; }
            input[type="text"], input[type="password"] { font-size: 16px; }
        }

        /* Shared SweetAlert Styles for Edit */
        .detail-container {
            padding: 20px;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-radius: 5px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            max-width: 400px;
            margin: 0 auto;
        }
        .detail-header {
            text-align: center; margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        .detail-header h3 { color: var(--primary-color); font-size: 1.1rem; font-weight: 600; margin: 0; }
        .detail-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem;
        }
        .detail-label { font-weight: 600; color: var(--text-primary); flex: 1; text-align: left; }
        .detail-value { flex: 1; display: flex; align-items: center; justify-content: flex-end; }
        .swal2-input { margin: 0 !important; width: 100% !important; }
    </style>
</head>
<body>
    <?php include '../../includes/sidebar_admin.php'; ?>
    <div class="main-content" id="mainContent">
        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div class="content">
                <h2>Kelola Data Akun Petugas</h2>
                <p>Kelola data akun petugas untuk sistem</p>
            </div>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="petugas-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($token); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="petugas1_nama">Nama Petugas 1 ( Kelas 10 )</label>
                        <input type="text" id="petugas1_nama" name="petugas1_nama" required placeholder="Masukkan nama petugas 1" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="petugas2_nama">Nama Petugas 2 ( Kelas 11 )</label>
                        <input type="text" id="petugas2_nama" name="petugas2_nama" required placeholder="Masukkan nama petugas 2" maxlength="50">
                    </div>
                </div>

                <!-- Added Password Hint -->
                <div class="password-hint">
                    <i class="fas fa-info-circle"></i>
                    <span>Default password akun baru adalah <strong>12345678</strong></span>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="submit" class="btn" id="submit-btn">
                        <span class="btn-content"><i class="fas fa-plus-circle"></i> Tambah</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- Petugas List -->
        <div class="petugas-list">
            <h3><i class="fas fa-list"></i> Daftar Akun Petugas</h3>
            <?php if (!empty($petugas_list)): ?>
                <div class="scroll-hint"><i class="fas fa-hand-pointer"></i> Geser tabel ke kanan untuk melihat lebih banyak</div>
                
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 5%;">No</th>
                                <th style="width: 25%;">Kode Petugas</th>
                                <th style="width: 25%;">Petugas 1 ( Kelas 10 )</th>
                                <th style="width: 25%;">Petugas 2 ( Kelas 11 )</th>
                                <th style="width: 20%;">Kelola Akun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = ($page - 1) * $items_per_page + 1;
                            foreach ($petugas_list as $petugas): 
                            ?>
                                <tr id="row-<?php echo $petugas['id']; ?>">
                                    <td><?php echo $no++; ?></td>
                                    <td><?php echo htmlspecialchars($petugas['nama']); ?></td>
                                    <td><?php echo htmlspecialchars($petugas['petugas1_nama'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($petugas['petugas2_nama'] ?? '-'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-edit"
                                                    onclick="editPetugas(<?php echo $petugas['id']; ?>, '<?php echo htmlspecialchars(addslashes($petugas['nama']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($petugas['username']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($petugas['petugas1_nama'] ?? ''), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($petugas['petugas2_nama'] ?? ''), ENT_QUOTES); ?>')">
                                                <i class="fas fa-edit"></i> EDIT AKUN
                                            </button>
                                            <button class="btn btn-delete"
                                                    onclick="deletePetugas(<?php echo $petugas['id']; ?>, '<?php echo htmlspecialchars(addslashes($petugas['nama']), ENT_QUOTES); ?>')">
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
                <div class="no-data"><i class="fas fa-info-circle"></i><p>Belum ada data petugas</p></div>
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

            function setupUppercaseInput(inputSelector) {
                const inputs = document.querySelectorAll(inputSelector);
                inputs.forEach(input => {
                    input.addEventListener('input', function() {
                        this.value = this.value.toUpperCase();
                    });
                });
            }
            setupUppercaseInput('#petugas1_nama, #petugas2_nama');

            // === Edit Petugas ===
            window.editPetugas = function(id, nama, username, petugas1_nama, petugas2_nama) {
                Swal.fire({
                    title: 'Edit Petugas',
                    html: `
                        <div class="detail-container">
                            <div class="detail-header">
                                <h3>Edit Akun Petugas</h3>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Kode Petugas</span>
                                <div class="detail-value">
                                    <input id="swal-nama" class="swal2-input" value="${nama}" readonly style="background: #f0f5ff; color: #666;">
                                </div>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Nama Petugas 1</span>
                                <div class="detail-value">
                                    <input id="swal-petugas1" class="swal2-input" value="${petugas1_nama}" placeholder="Nama petugas 1" maxlength="50">
                                </div>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Nama Petugas 2</span>
                                <div class="detail-value">
                                    <input id="swal-petugas2" class="swal2-input" value="${petugas2_nama}" placeholder="Nama petugas 2" maxlength="50">
                                </div>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Username</span>
                                <div class="detail-value">
                                    <input id="swal-username" class="swal2-input" value="${username}" placeholder="Username">
                                </div>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Password Baru</span>
                                <div class="detail-value">
                                    <input id="swal-password" class="swal2-input" type="password" placeholder="Min 8 karakter (opsional)">
                                </div>
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
                    didOpen: () => {
                        const input = document.getElementById('swal-petugas1');
                        input.focus();
                        setupUppercaseInput('#swal-petugas1, #swal-petugas2');
                    },
                    preConfirm: () => {
                        const petugas1Input = document.getElementById('swal-petugas1');
                        const petugas2Input = document.getElementById('swal-petugas2');
                        const usernameInput = document.getElementById('swal-username');
                        const passwordInput = document.getElementById('swal-password');
                        const valuePetugas1 = petugas1Input.value.trim();
                        const valuePetugas2 = petugas2Input.value.trim();
                        const valueUsername = usernameInput.value.trim();
                        const valuePassword = passwordInput.value.trim();

                        if (!valuePetugas1 || valuePetugas1.length < 3) {
                            Swal.showValidationMessage('Nama Petugas 1 minimal 3 karakter!');
                            return false;
                        }
                        if (!valuePetugas2 || valuePetugas2.length < 3) {
                            Swal.showValidationMessage('Nama Petugas 2 minimal 3 karakter!');
                            return false;
                        }
                        if (!valueUsername || !/^[a-zA-Z0-9._]+$/.test(valueUsername)) {
                            Swal.showValidationMessage('Username invalid!');
                            return false;
                        }
                        if (valuePassword && valuePassword.length < 8) {
                            Swal.showValidationMessage('Password baru minimal 8 karakter!');
                            return false;
                        }
                        return { nama: nama, petugas1: valuePetugas1, petugas2: valuePetugas2, username: valueUsername, password: valuePassword };
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const { nama, petugas1, petugas2, username, password } = result.value;
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = '';
                        form.innerHTML = `
                            <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                            <input type="hidden" name="edit_id" value="${id}">
                            <input type="hidden" name="edit_nama" value="${nama}">
                            <input type="hidden" name="edit_petugas1_nama" value="${petugas1}">
                            <input type="hidden" name="edit_petugas2_nama" value="${petugas2}">
                            <input type="hidden" name="edit_username" value="${username}">
                            <input type="hidden" name="edit_password" value="${password}">
                            <input type="hidden" name="edit_confirm" value="1">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };

            // === Hapus Petugas ===
            window.deletePetugas = function(id, nama) {
                Swal.fire({
                    title: 'Hapus Petugas?',
                    html: `Apakah Anda yakin ingin menghapus petugas <strong>"${nama}"</strong>?`,
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
                            <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                            <input type="hidden" name="delete_id" value="${id}">
                        `;
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            };

            // Input validations
            const petugasForm = document.getElementById('petugas-form');
            if (petugasForm) {
                petugasForm.addEventListener('submit', function(e) {
                    const petugas1 = document.getElementById('petugas1_nama').value.trim();
                    const petugas2 = document.getElementById('petugas2_nama').value.trim();
                    if (!petugas1 || petugas1.length < 3 || !petugas2 || petugas2.length < 3) {
                        e.preventDefault();
                        Swal.fire({ icon: 'error', title: 'Gagal', text: 'Nama Petugas minimal 3 karakter!', confirmButtonColor: '#1e3a8a' });
                    }
                });
            }

            document.querySelectorAll('#petugas1_nama, #petugas2_nama').forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.replace(/[^A-Za-z0-9\s._]/g, '');
                    if (this.value.length > 50) this.value = this.value.substring(0, 50);
                });
            });

            // SweetAlert Auto Show
            <?php if (isset($_GET['confirm']) && isset($_GET['nama']) && isset($_GET['petugas1_nama'])): ?>
            Swal.fire({
                title: 'KONFIRMASI AKUN PETUGAS BARU',
                html: `
                    <div style="text-align: center; font-weight: bold; font-size: 1.1rem; margin-bottom: 10px;">
                        KODE PETUGAS: <?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>
                    </div>
                    <div style="text-align: center; color: #555;">
                        Dengan Petugas <strong><?php echo htmlspecialchars(urldecode($_GET['petugas1_nama'])); ?></strong> DAN <strong><?php echo htmlspecialchars(urldecode($_GET['petugas2_nama'])); ?></strong>
                    </div>
                `,
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
                        <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars(urldecode($_GET['nama'])); ?>">
                        <input type="hidden" name="petugas1_nama" value="<?php echo htmlspecialchars(urldecode($_GET['petugas1_nama'])); ?>">
                        <input type="hidden" name="petugas2_nama" value="<?php echo htmlspecialchars(urldecode($_GET['petugas2_nama'])); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars(urldecode($_GET['username'])); ?>">
                        <input type="hidden" name="password" value="<?php echo htmlspecialchars(urldecode($_GET['password'])); ?>">
                        <input type="hidden" name="confirm" value="1">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    window.location.href = 'kelola_akun_petugas.php';
                }
            });
            <?php endif; ?>

            <?php if (isset($_GET['success'])): ?>
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Akun petugas berhasil ditambahkan!', confirmButtonColor: '#1e3a8a' });
            <?php endif; ?>

            <?php if (isset($_GET['edit_success'])): ?>
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Petugas berhasil diupdate!', confirmButtonColor: '#1e3a8a' });
            <?php endif; ?>

            <?php if (isset($_GET['delete_success'])): ?>
            Swal.fire({ icon: 'success', title: 'Berhasil!', text: 'Petugas berhasil dihapus!', confirmButtonColor: '#1e3a8a' });
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
            Swal.fire({ icon: 'error', title: 'Gagal', text: '<?php echo addslashes(urldecode($_GET['error'])); ?>', confirmButtonColor: '#1e3a8a' });
            <?php endif; ?>
        });
    </script>
<?php
$conn->close();
?>
</body>
</html>
