<?php
require_once '../../includes/auth.php';
require_once '../../includes/db_connection.php';

// CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize variables
$error = '';
$success = '';
$show_success_popup = false;
$success_nama = '';
$success_username = '';
$success_password = '';
$show_confirm_popup = false;

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
        $error = 'Invalid CSRF token.';
    } else {
        // Add Petugas
        if (isset($_POST['confirm'])) {
            $nama = trim($_POST['nama']);
            $username = trim($_POST['username']);
            $password = $_POST['password'];

            if (empty($nama) || empty($username) || empty($password)) {
                $error = 'Semua field harus diisi.';
            } else {
                $query = "INSERT INTO users (username, password, role, nama) VALUES (?, SHA2(?, 256), 'petugas', ?)";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("sss", $username, $password, $nama);

                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    // Store the plain password in session
                    $_SESSION['temp_passwords'][$new_id] = $password;
                    $success = 'Akun berhasil dibuat!';
                    $show_success_popup = true;
                    $success_nama = $nama;
                    $success_username = $username;
                    $success_password = $password;
                    $stmt->close();
                    header('Location: tambah_petugas.php?success=1&nama=' . urlencode($nama) . '&username=' . urlencode($username) . '&password=' . urlencode($password));
                    exit;
                } else {
                    $error = 'Terjadi kesalahan saat menambahkan petugas: ' . $conn->error;
                }
                $stmt->close();
            }
        }
        // Initial Form Submission
        elseif (isset($_POST['nama']) && !isset($_POST['edit_confirm']) && !isset($_POST['delete_id'])) {
            $nama = trim($_POST['nama']);
            if (empty($nama)) {
                $error = 'Nama tidak boleh kosong.';
            } elseif (strlen($nama) < 3) {
                $error = 'Nama harus minimal 3 karakter!';
            } else {
                $username = generateUsername($nama);
                $query = "SELECT COUNT(*) FROM users WHERE username = ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();

                if ($count > 0) {
                    $error = 'Username sudah ada. Silakan coba lagi.';
                } else {
                    $password = "12345678";
                    $show_confirm_popup = true;
                }
            }
        }
        // Edit Petugas
        elseif (isset($_POST['edit_confirm'])) {
            $id = (int)$_POST['edit_id'];
            $nama = trim($_POST['edit_nama']);
            $username = trim($_POST['edit_username']);
            $password = isset($_POST['edit_password']) ? trim($_POST['edit_password']) : '';

            if (empty($nama) || empty($username)) {
                $error = 'Nama dan username harus diisi.';
                header('Location: tambah_petugas.php?error=' . urlencode($error));
                exit;
            } elseif (strlen($nama) < 3) {
                $error = 'Nama harus minimal 3 karakter!';
                header('Location: tambah_petugas.php?error=' . urlencode($error));
                exit;
            } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
                $error = 'Username hanya boleh berisi huruf dan angka.';
                header('Location: tambah_petugas.php?error=' . urlencode($error));
                exit;
            } elseif (!empty($password) && strlen($password) < 8) {
                $error = 'Password baru harus minimal 8 karakter!';
                header('Location: tambah_petugas.php?error=' . urlencode($error));
                exit;
            } else {
                // Check if username is unique (excluding current user)
                $query = "SELECT COUNT(*) FROM users WHERE username = ? AND id != ?";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("si", $username, $id);
                $stmt->execute();
                $stmt->bind_result($count);
                $stmt->fetch();
                $stmt->close();

                if ($count > 0) {
                    $error = 'Username sudah digunakan oleh akun lain.';
                    header('Location: tambah_petugas.php?error=' . urlencode($error));
                    exit;
                } else {
                    // Verify the user exists and is a petugas
                    $query = "SELECT id FROM users WHERE id = ? AND role = 'petugas'";
                    $stmt = $conn->prepare($query);
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows === 0) {
                        $error = 'Petugas tidak ditemukan atau bukan petugas.';
                        $result->free();
                        $stmt->close();
                        header('Location: tambah_petugas.php?error=' . urlencode($error));
                        exit;
                    } else {
                        $result->free();
                        $stmt->close();
                        // Update the user
                        if (empty($password)) {
                            // Update without changing the password
                            $query = "UPDATE users SET nama = ?, username = ? WHERE id = ? AND role = 'petugas'";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("ssi", $nama, $username, $id);
                        } else {
                            // Update including the new password
                            $query = "UPDATE users SET nama = ?, username = ?, password = SHA2(?, 256) WHERE id = ? AND role = 'petugas'";
                            $stmt = $conn->prepare($query);
                            $stmt->bind_param("sssi", $nama, $username, $password, $id);
                            // Store the new plain password in session
                            $_SESSION['temp_passwords'][$id] = $password;
                        }

                        if ($stmt->execute()) {
                            if ($stmt->affected_rows > 0) {
                                $stmt->close();
                                header('Location: tambah_petugas.php?edit_success=1');
                                exit;
                            } else {
                                $error = 'Tidak ada perubahan dilakukan.';
                                $stmt->close();
                                header('Location: tambah_petugas.php?error=' . urlencode($error));
                                exit;
                            }
                        } else {
                            $error = 'Terjadi kesalahan saat memperbarui petugas: ' . $stmt->error;
                            $stmt->close();
                            header('Location: tambah_petugas.php?error=' . urlencode($error));
                            exit;
                        }
                    }
                }
            }
        }
        // Delete Petugas
        elseif (isset($_POST['delete_id'])) {
            $id = (int)$_POST['delete_id'];

            // Check for dependencies in related tables
            $query = "SELECT COUNT(*) FROM transaksi WHERE petugas_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->bind_result($transaksi_count);
            $stmt->fetch();
            $stmt->close();

            if ($transaksi_count > 0) {
                header('Location: tambah_petugas.php?error=' . urlencode("Petugas tidak dapat dihapus karena memiliki transaksi terkait."));
                exit;
            } else {
                // Proceed with deletion
                $query = "DELETE FROM users WHERE id = ? AND role = 'petugas'";
                $stmt = $conn->prepare($query);
                $stmt->bind_param("i", $id);

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        // Remove the password from session
                        unset($_SESSION['temp_passwords'][$id]);
                        $stmt->close();
                        header('Location: tambah_petugas.php?delete_success=1');
                        exit;
                    } else {
                        $error = 'Petugas tidak ditemukan.';
                        $stmt->close();
                        header('Location: tambah_petugas.php?error=' . urlencode($error));
                        exit;
                    }
                } else {
                    $error = 'Terjadi kesalahan saat menghapus petugas: ' . $stmt->error;
                    $stmt->close();
                    header('Location: tambah_petugas.php?error=' . urlencode($error));
                    exit;
                }
            }
        }
    }
}

// Check for success query parameter
if (isset($_GET['success'])) {
    $show_success_popup = true;
    $success_nama = isset($_GET['nama']) ? urldecode($_GET['nama']) : '';
    $success_username = isset($_GET['username']) ? urldecode($_GET['username']) : '';
    $success_password = isset($_GET['password']) ? urldecode($_GET['password']) : '';
    $success = 'Akun berhasil dibuat!';
}

// Fetch all petugas for the current page
$query = "SELECT id, nama, username FROM users WHERE role = 'petugas' ORDER BY nama ASC LIMIT ? OFFSET ?";
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

function generateUsername($nama) {
    $nama = strtolower(str_replace(' ', '', $nama));
    $randomNumber = rand(100, 999);
    return $nama . $randomNumber;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="/bankmini/assets/images/lbank.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Tambah Petugas - SCHOBANK SYSTEM</title>
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

        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-size: clamp(0.9rem, 2vw, 1rem);
            transition: var(--transition);
            user-select: text;
            -webkit-user-select: text;
        }

        input[type="text"]:focus, input[type="password"]:focus {
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

        .petugas-list {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }

        .petugas-list:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-5px);
        }

        .petugas-list h3 {
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

        .success-icon {
            color: white;
        }

        .error-icon {
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .modal-row:last-child {
            border-bottom: none;
        }

        .modal-label {
            font-weight: 500;
            color: white;
            font-size: clamp(0.9rem, 2.2vw, 1rem);
        }

        .modal-value {
            font-weight: 600;
            color: white;
            font-size: clamp(0.9rem, 2.2vw, 1rem);
            text-align: right;
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

            .form-card, .petugas-list {
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

            input[type="text"], input[type="password"] {
                padding: 10px 12px;
                font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            }

            .modal-label, .modal-value {
                font-size: clamp(0.85rem, 2vw, 0.95rem);
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

            .petugas-list h3 {
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

            .modal-label, .modal-value {
                font-size: clamp(0.8rem, 1.9vw, 0.9rem);
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
            <h2>Tambah Petugas</h2>
            <p>Kelola data petugas untuk sistem SCHOBANK</p>
        </div>

        <!-- Form Section -->
        <div class="form-card">
            <form action="" method="POST" id="petugas-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="form-group">
                    <label for="nama">Nama Lengkap Petugas</label>
                    <input type="text" id="nama" name="nama" required placeholder="Masukkan nama lengkap" value="<?php echo isset($_POST['nama']) ? htmlspecialchars($_POST['nama']) : ''; ?>">
                </div>
                <button type="submit" class="btn" id="submit-btn">
                    <span class="btn-content"></i> Tambah </span>
                </button>
            </form>
        </div>

        <!-- Petugas List -->
        <div class="petugas-list">
            <h3><i class="fas fa-list"></i> Daftar Akun Petugas</h3>
            <?php if (!empty($petugas_list)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="petugas-table">
                        <?php 
                        $no = ($page - 1) * $items_per_page + 1;
                        foreach ($petugas_list as $petugas): 
                        ?>
                            <tr id="row-<?php echo $petugas['id']; ?>">
                                <td><?php echo $no++; ?></td>
                                <td><?php echo htmlspecialchars($petugas['nama']); ?></td>
                                <td><?php echo htmlspecialchars($petugas['username']); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-edit" 
                                                onclick="showEditModal(<?php echo $petugas['id']; ?>, '<?php echo htmlspecialchars(addslashes($petugas['nama'])); ?>', '<?php echo htmlspecialchars(addslashes($petugas['username'])); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-delete" 
                                                onclick="deletePetugas(<?php echo $petugas['id']; ?>, '<?php echo htmlspecialchars(addslashes($petugas['nama'])); ?>')">
                                            <i class="fas fa-trash"></i> Hapus
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
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
                    <p>Belum ada data petugas</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Confirmation Modal for Adding Petugas -->
        <?php if ($show_confirm_popup): ?>
            <div class="success-overlay" id="confirmModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3>Konfirmasi Petugas Baru</h3>
                    <div class="modal-content-confirm">
                        <div class="modal-row">
                            <span class="modal-label">Nama</span>
                            <span class="modal-value"><?php echo htmlspecialchars($nama); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Username</span>
                            <span class="modal-value"><?php echo htmlspecialchars($username); ?></span>
                        </div>
                        <div class="modal-row">
                            <span class="modal-label">Password</span>
                            <span class="modal-value"><?php echo htmlspecialchars($password); ?></span>
                        </div>
                    </div>
                    <form action="" method="POST" id="confirm-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="nama" value="<?php echo htmlspecialchars($nama); ?>">
                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($username); ?>">
                        <input type="hidden" name="password" value="<?php echo htmlspecialchars($password); ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="modal-buttons">
                            <button type="submit" class="btn btn-confirm" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check"></i> Konfirmasi</span>
                            </button>
                            <button type="button" class="btn btn-cancel" onclick="window.location.href='tambah_petugas.php'">
                                <span class="btn-content"><i class="fas fa-times"></i> Batal</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Adding Petugas -->
        <?php if ($show_success_popup): ?>
            <div class="success-overlay" id="successModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Akun berhasil dibuat!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Updating Petugas -->
        <?php if (isset($_GET['edit_success'])): ?>
            <div class="success-overlay" id="editSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Petugas berhasil diupdate!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Modal for Deleting Petugas -->
        <?php if (isset($_GET['delete_success'])): ?>
            <div class="success-overlay" id="deleteSuccessModal">
                <div class="success-modal">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3>Berhasil</h3>
                    <p>Petugas berhasil dihapus!</p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Modal -->
        <?php if (!empty($error) || isset($_GET['error'])): ?>
            <div class="success-overlay" id="errorModal">
                <div class="error-modal">
                    <div class="error-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h3>Gagal</h3>
                    <p><?php echo htmlspecialchars(!empty($error) ? $error : urldecode($_GET['error'])); ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-title">
                <i class="fas fa-edit"></i> Edit Petugas
            </div>
            <form id="editForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group">
                    <label for="edit_nama">Nama Lengkap</label>
                    <input type="text" id="edit_nama" name="edit_nama" required>
                </div>
                <div class="form-group">
                    <label for="edit_username">Username</label>
                    <input type="text" id="edit_username" name="edit_username" required>
                </div>
                <div class="form-group">
                    <label for="edit_password">Password Baru (opsional)</label>
                    <input type="password" id="edit_password" name="edit_password" placeholder="Masukkan password baru (min 8 karakter)">
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
                <i class="fas fa-user-edit"></i>
            </div>
            <h3>Konfirmasi Edit Petugas</h3>
            <div class="modal-content-confirm">
                <div class="modal-row">
                    <span class="modal-label">Nama</span>
                    <span class="modal-value" id="editConfirmNama"></span>
                </div>
                <div class="modal-row">
                    <span class="modal-label">Username</span>
                    <span class="modal-value" id="editConfirmUsername"></span>
                </div>
                <div class="modal-row" id="editConfirmPasswordRow" style="display: none;">
                    <span class="modal-label">Password Baru</span>
                    <span class="modal-value" id="editConfirmPassword"></span>
                </div>
            </div>
            <form action="" method="POST" id="editConfirmForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="edit_id" id="editConfirmId">
                <input type="hidden" name="edit_nama" id="editConfirmNamaInput">
                <input type="hidden" name="edit_username" id="editConfirmUsernameInput">
                <input type="hidden" name="edit_password" id="editConfirmPasswordInput">
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
                <i class="fas fa-trash"></i> Hapus Petugas
            </div>
            <p>Apakah Anda yakin ingin menghapus petugas <strong id="delete_nama"></strong>?</p>
            <form id="deleteForm" method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
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

    <script>
        // Edit Modal Functions
        function showEditModal(id, nama, username) {
            console.log('showEditModal called with:', { id, nama, username });
            try {
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_nama').value = nama;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_password').value = '';
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
            const nama = document.getElementById('edit_nama').value.trim();
            const username = document.getElementById('edit_username').value.trim();
            const password = document.getElementById('edit_password').value.trim();

            if (nama.length < 3) {
                showErrorModal('Nama harus minimal 3 karakter!');
                return false;
            }
            if (!username) {
                showErrorModal('Username tidak boleh kosong!');
                return false;
            }
            if (!/^[a-zA-Z0-9]+$/.test(username)) {
                showErrorModal('Username hanya boleh berisi huruf dan angka!');
                return false;
            }
            if (password && password.length < 8) {
                showErrorModal('Password baru harus minimal 8 karakter!');
                return false;
            }

            document.getElementById('editConfirmId').value = document.getElementById('edit_id').value;
            document.getElementById('editConfirmNama').textContent = nama;
            document.getElementById('editConfirmUsername').textContent = username;
            document.getElementById('editConfirmNamaInput').value = nama;
            document.getElementById('editConfirmUsernameInput').value = username;

            if (password) {
                document.getElementById('editConfirmPassword').textContent = password;
                document.getElementById('editConfirmPasswordInput').value = password;
                document.getElementById('editConfirmPasswordRow').style.display = 'flex';
            } else {
                document.getElementById('editConfirmPasswordInput').value = '';
                document.getElementById('editConfirmPasswordRow').style.display = 'none';
            }

            document.getElementById('editConfirmModal').style.display = 'flex';
            document.getElementById('editModal').style.display = 'none';
            return false;
        }

        function hideEditConfirmModal() {
            document.getElementById('editConfirmModal').style.display = 'none';
            document.getElementById('editModal').style.display = 'flex';
        }

        // Delete Modal Functions
        function deletePetugas(id, nama) {
            console.log('deletePetugas called with:', { id, nama });
            try {
                document.getElementById('delete_id').value = id;
                document.getElementById('delete_nama').textContent = nama;
                document.getElementById('deleteModal').style.display = 'flex';
            } catch (error) {
                console.error('Error in deletePetugas:', error);
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
                        window.location.href = 'tambah_petugas.php';
                    }, 500);
                }, 3000);
                modal.addEventListener('click', () => {
                    modal.style.animation = 'fadeOutOverlay 0.5s ease-in-out forwards';
                    setTimeout(() => {
                        modal.remove();
                        window.location.href = 'tambah_petugas.php';
                    }, 500);
                });
            });

            // Form submission handling (Tambah Petugas)
            const petugasForm = document.getElementById('petugas-form');
            const submitBtn = document.getElementById('submit-btn');
            
            if (petugasForm && submitBtn) {
                petugasForm.addEventListener('submit', function(e) {
                    console.log('Submitting petugas-form');
                    const nama = document.getElementById('nama').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama harus minimal 3 karakter!');
                        submitBtn.classList.remove('loading');
                        submitBtn.innerHTML = '<span class="btn-content"><i class="fas fa-plus"></i> Tambah Petugas</span>';
                    } else {
                        e.preventDefault();
                        submitBtn.classList.add('loading');
                        setTimeout(() => {
                            petugasForm.submit();
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
                    const nama = document.getElementById('edit_nama').value.trim();
                    const username = document.getElementById('edit_username').value.trim();
                    const password = document.getElementById('edit_password').value.trim();
                    if (nama.length < 3) {
                        e.preventDefault();
                        showErrorModal('Nama harus minimal 3 karakter!');
                        editSubmitBtn.classList.remove('loading');
                    } else if (!username) {
                        e.preventDefault();
                        showErrorModal('Username tidak boleh kosong!');
                        editSubmitBtn.classList.remove('loading');
                    } else if (!/^[a-zA-Z0-9]+$/.test(username)) {
                        e.preventDefault();
                        showErrorModal('Username hanya boleh berisi huruf dan angka!');
                        editSubmitBtn.classList.remove('loading');
                    } else if (password && password.length < 8) {
                        e.preventDefault();
                        showErrorModal('Password baru harus minimal 8 karakter!');
                        editSubmitBtn.classList.remove('loading');
                    }
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
<?php
// Close database connection
$conn->close();
?>
</body>
</html>