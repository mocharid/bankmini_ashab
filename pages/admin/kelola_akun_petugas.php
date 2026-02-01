<?php
/**
 * Kelola Akun Petugas - Final Rapih Version
 * File: pages/admin/kelola_akun_petugas.php
 */

// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'admin') {
    $project_root = dirname(dirname($current_dir));
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}
if (!$project_root)
    $project_root = $current_dir;

if (!defined('PROJECT_ROOT'))
    define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH'))
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');

function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path))
        $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL'))
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
define('ASSETS_URL', BASE_URL . '/assets');

// ============================================
// LOGIC & DATABASE
// ============================================
session_start();
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] === 'siswa') {
    header("Location: " . BASE_URL . "/pages/login.php");
    exit;
}

// ============================================
// PRG PATTERN - POST/REDIRECT/GET
// ============================================
if (!isset($_SESSION['flash_messages'])) {
    $_SESSION['flash_messages'] = [];
}

function setFlash($type, $message)
{
    $_SESSION['flash_messages'][$type] = $message;
}

function getFlash($type)
{
    if (isset($_SESSION['flash_messages'][$type])) {
        $message = $_SESSION['flash_messages'][$type];
        unset($_SESSION['flash_messages'][$type]);
        return $message;
    }
    return null;
}

// Function to convert to Title Case
function toTitleCase($string)
{
    return mb_convert_case(mb_strtolower(trim($string)), MB_CASE_TITLE, "UTF-8");
}

// ============================================
// CSRF PROTECTION
// ============================================
if (!isset($_SESSION['csrf_token']))
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['csrf_token'];

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

// ============================================
// HANDLE REQUESTS (POST ONLY - PRG Pattern)
// ============================================

// Handle Delete
if (isset($_POST['delete_id']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $id = (int) $_POST['delete_id'];

    // Check relations
    $relations = [
        "transaksi" => ["petugas_id", "transaksi terkait"],
        "petugas_status" => ["petugas_id", "data absensi terkait"],
    ];

    foreach ($relations as $table => $info) {
        $query = "SELECT COUNT(*) as count FROM $table WHERE {$info[0]} = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];
        $stmt->close();

        if ($count > 0) {
            setFlash('error', "Petugas tidak dapat dihapus karena memiliki {$info[1]}.");
            header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
            exit;
        }
    }

    // Check jadwal
    $query = "SELECT COUNT(*) as count FROM petugas_shift WHERE petugas1_id = ? OR petugas2_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $id, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $jadwal_count = $result->fetch_assoc()['count'];
    $stmt->close();

    if ($jadwal_count > 0) {
        setFlash('error', 'Petugas tidak dapat dihapus karena memiliki jadwal terkait.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    $conn->begin_transaction();
    try {
        $query_info = "DELETE FROM petugas_profiles WHERE user_id = ?";
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
            setFlash('success', 'Petugas berhasil dihapus!');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
            exit;
        } else {
            $conn->rollback();
            setFlash('error', 'Petugas tidak ditemukan.');
            header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
            exit;
        }
    } catch (Exception $e) {
        $conn->rollback();
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }
}

// Handle Edit Confirm
if (isset($_POST['edit_confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $id = (int) $_POST['edit_id'];
    $nama = strtoupper(trim($_POST['edit_nama']));
    $petugas1_nama = toTitleCase($_POST['edit_petugas1_nama']);
    $petugas2_nama = toTitleCase($_POST['edit_petugas2_nama']);
    $username_edit = trim($_POST['edit_username']);
    $password = trim($_POST['edit_password'] ?? '');

    if (empty($nama) || empty($petugas1_nama) || empty($petugas2_nama) || empty($username_edit)) {
        setFlash('error', 'Semua field harus diisi.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } elseif (strlen($nama) < 3 || strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
        setFlash('error', 'Semua field minimal 3 karakter!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } elseif (!preg_match('/^[a-zA-Z0-9._]+$/', $username_edit)) {
        setFlash('error', 'Username hanya boleh berisi huruf, angka, titik, dan underscore.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } elseif (!empty($password) && strlen($password) < 8) {
        setFlash('error', 'Password baru harus minimal 8 karakter!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    // Check duplicates
    $query_check_nama = "SELECT COUNT(*) as count FROM users WHERE nama = ? AND id != ? AND role = 'petugas'";
    $stmt_check_nama = $conn->prepare($query_check_nama);
    $stmt_check_nama->bind_param("si", $nama, $id);
    $stmt_check_nama->execute();
    $result = $stmt_check_nama->get_result();
    if ($result->fetch_assoc()['count'] > 0) {
        setFlash('error', 'Kode petugas sudah digunakan oleh akun lain.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    $query = "SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $username_edit, $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->fetch_assoc()['count'] > 0) {
        setFlash('error', 'Username sudah digunakan oleh akun lain.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    $conn->begin_transaction();
    try {
        if (empty($password)) {
            $query = "UPDATE users SET username = ?, nama = ? WHERE id = ? AND role = 'petugas'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("ssi", $username_edit, $nama, $id);
        } else {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $query = "UPDATE users SET username = ?, nama = ?, password = ? WHERE id = ? AND role = 'petugas'";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sssi", $username_edit, $nama, $hashed_password, $id);
            $_SESSION['temp_passwords'][$id] = $password;
        }
        $stmt->execute();

        // Update petugas_profiles
        $query_check_info = "SELECT id FROM petugas_profiles WHERE user_id = ?";
        $stmt_check = $conn->prepare($query_check_info);
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();

        if ($result_check->num_rows > 0) {
            $query_info = "UPDATE petugas_profiles SET petugas1_nama = ?, petugas2_nama = ? WHERE user_id = ?";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bind_param("ssi", $petugas1_nama, $petugas2_nama, $id);
        } else {
            $query_info = "INSERT INTO petugas_profiles (user_id, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bind_param("iss", $id, $petugas1_nama, $petugas2_nama);
        }
        $stmt_info->execute();

        $conn->commit();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setFlash('success', 'Petugas berhasil diupdate!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }
}

// Handle Add Confirm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['token'] === $_SESSION['csrf_token']) {
    $nama = strtoupper(trim($_POST['nama']));
    $petugas1_nama = toTitleCase($_POST['petugas1_nama']);
    $petugas2_nama = toTitleCase($_POST['petugas2_nama']);
    $username_input = trim($_POST['username']);
    $password = $_POST['password'];

    $conn->begin_transaction();
    try {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        $query = "INSERT INTO users (username, password, role, nama) VALUES (?, ?, 'petugas', ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sss", $username_input, $hashed_password, $nama);
        $stmt->execute();
        $new_id = $stmt->insert_id;

        $query_info = "INSERT INTO petugas_profiles (user_id, petugas1_nama, petugas2_nama) VALUES (?, ?, ?)";
        $stmt_info = $conn->prepare($query_info);
        $stmt_info->bind_param("iss", $new_id, $petugas1_nama, $petugas2_nama);
        $stmt_info->execute();

        $_SESSION['temp_passwords'][$new_id] = $password;

        $conn->commit();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        setFlash('success', 'Akun petugas berhasil ditambahkan!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        setFlash('error', 'Terjadi kesalahan: ' . $e->getMessage());
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }
}

// Handle Form Validation (Validation Phase - Show Confirm Modal)
$show_confirm_modal = false;
$confirm_data = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id']) && !isset($_POST['edit_confirm']) && !isset($_POST['confirm'])) {
    $nama = strtoupper(trim($_POST['nama']));
    $petugas1_nama = toTitleCase($_POST['petugas1_nama']);
    $petugas2_nama = toTitleCase($_POST['petugas2_nama']);

    if (empty($nama) || empty($petugas1_nama) || empty($petugas2_nama)) {
        setFlash('error', 'Semua field harus diisi.');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    } elseif (strlen($nama) < 3 || strlen($petugas1_nama) < 3 || strlen($petugas2_nama) < 3) {
        setFlash('error', 'Semua field minimal 3 karakter!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    // Check if kode already exists
    $query_check = "SELECT COUNT(*) as count FROM users WHERE nama = ? AND role = 'petugas'";
    $stmt_check = $conn->prepare($query_check);
    $stmt_check->bind_param("s", $nama);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    if ($result->fetch_assoc()['count'] > 0) {
        setFlash('error', 'Kode petugas sudah digunakan!');
        header('Location: ' . BASE_URL . '/pages/admin/kelola_akun_petugas.php');
        exit;
    }

    // Generate username
    $nama_lower = strtolower($nama);
    $base_username = preg_replace('/[^a-z0-9._]/', '', $nama_lower);

    $query = "SELECT COUNT(*) as count FROM users WHERE username = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $base_username);
    $stmt->execute();
    $result = $stmt->get_result();
    $count_username = $result->fetch_assoc()['count'];

    if ($count_username > 0) {
        $randomNumber = rand(100, 999);
        $username_generated = $base_username . $randomNumber;
    } else {
        $username_generated = $base_username;
    }

    $password = "12345678";

    $confirm_data = [
        'nama' => $nama,
        'petugas1_nama' => $petugas1_nama,
        'petugas2_nama' => $petugas2_nama,
        'username' => $username_generated,
        'password' => $password
    ];
    $show_confirm_modal = true;
}

// Get Flash Messages
$success_message = getFlash('success');
$error_message = getFlash('error');

// ============================================
// DATA FETCHING (GET Only)
// ============================================
// Search Filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_query = "";
$search_params = [];
$search_types = "";

if (!empty($search)) {
    $search_query = " AND (u.username LIKE ? OR pp.petugas1_nama LIKE ? OR pp.petugas2_nama LIKE ?)";
    $search_term = "%" . $search . "%";
    $search_params = [$search_term, $search_term, $search_term];
    $search_types = "sss";
}

// Get total number of petugas for pagination with search
$total_query = "SELECT COUNT(*) as total 
                FROM users u 
                LEFT JOIN petugas_profiles pp ON u.id = pp.user_id
                WHERE u.role = 'petugas'" . $search_query;
$stmt_total = $conn->prepare($total_query);
if (!empty($search_params)) {
    $stmt_total->bind_param($search_types, ...$search_params);
}
$stmt_total->execute();
$total_rows = $stmt_total->get_result()->fetch_assoc()['total'];
$stmt_total->close();

$total_pages = ceil($total_rows / $items_per_page);

// Fetch Data with Search
$query = "SELECT u.id, u.nama, u.username, pp.petugas1_nama, pp.petugas2_nama
          FROM users u 
          LEFT JOIN petugas_profiles pp ON u.id = pp.user_id
          WHERE u.role = 'petugas'" . $search_query . "
          ORDER BY u.id ASC 
          LIMIT ? OFFSET ?";
$stmt = $conn->prepare($query);

if (!empty($search_params)) {
    $all_params = array_merge($search_params, [$items_per_page, $offset]);
    $all_types = $search_types . "ii";
    $stmt->bind_param($all_types, ...$all_params);
} else {
    $stmt->bind_param("ii", $items_per_page, $offset);
}

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
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kelola Akun Petugas | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            --primary-color: var(--gray-800);
            --primary-dark: var(--gray-900);
            --secondary-color: var(--gray-600);

            --bg-light: #f8fafc;
            --text-primary: var(--gray-800);
            --text-secondary: var(--gray-500);
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --radius: 0.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open {
            overflow: hidden;
        }

        body.sidebar-open::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
            backdrop-filter: blur(5px);
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 2rem;
            max-width: calc(100% - 280px);
            position: relative;
            z-index: 1;
        }

        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content {
            flex: 1;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .page-subtitle {
            color: var(--gray-500);
            font-size: 0.9rem;
            margin: 0;
        }

        .page-hamburger {
            display: none;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 8px;
            background: transparent;
            color: var(--gray-700);
            font-size: 1.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            align-items: center;
            justify-content: center;
        }

        .page-hamburger:hover {
            background: rgba(0, 0, 0, 0.05);
            color: var(--gray-800);
        }

        /* Cards */
        .card,
        .form-card,
        .petugas-list {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .card-header {
            background: var(--gray-100);
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card-header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            border-radius: var(--radius);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1rem;
        }

        .card-header-text {
            flex: 1;
        }

        .card-header-text h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--gray-800);
            margin: 0;
        }

        .card-header-text p {
            font-size: 0.85rem;
            color: var(--gray-500);
            margin: 0;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 1.25rem 1.5rem;
            background: var(--gray-100);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        label {
            font-weight: 500;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        /* INPUT STYLING */
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
            height: 48px;
            color: var(--gray-800);
        }

        input:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        /* Button */
        .btn {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius);
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
            text-decoration: none;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-action {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            color: white;
        }

        .btn-action:hover {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
        }

        .btn-pdf {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
        }

        .btn-pdf:hover {
            background: linear-gradient(135deg, #b91c1c 0%, #991b1b 100%);
        }

        /* Data Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .data-table thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .data-table th {
            background: var(--gray-100);
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 2px solid var(--gray-200);
            font-weight: 600;
            color: var(--gray-500);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .data-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: middle;
        }

        .data-table tbody tr {
            background: white;
        }

        .data-table tr:hover {
            background: var(--gray-100) !important;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .actions .btn {
            padding: 8px 16px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .actions .btn i {
            margin-right: 4px;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            gap: 0.5rem;
        }

        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            text-decoration: none;
            color: var(--gray-600);
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .page-link:hover:not(.disabled):not(.active) {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
            cursor: default;
        }

        .page-link.page-arrow {
            padding: 0.5rem 0.75rem;
        }

        .page-link.disabled {
            color: var(--gray-300);
            cursor: not-allowed;
            background: var(--gray-50);
            pointer-events: none;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
                max-width: 100%;
            }

            .page-title-section {
                padding: 1rem;
                margin: -1rem -1rem 1rem -1rem;
            }

            .page-hamburger {
                display: flex;
            }
        }

        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
                padding: 1rem;
            }

            .filter-group {
                width: 100%;
                min-width: 100%;
            }

            .filter-bar .btn {
                width: 100%;
            }

            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .card-body {
                padding: 1rem;
            }

            .data-table {
                min-width: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions {
                flex-direction: row;
                flex-wrap: nowrap;
                gap: 4px;
            }

            .actions .btn {
                padding: 6px 12px;
                font-size: 0.75rem;
                white-space: nowrap;
            }

            .actions .btn i {
                margin-right: 2px;
            }

            .pagination {
                flex-wrap: wrap;
                padding: 1rem;
            }

            .page-link {
                padding: 0.4rem 0.75rem;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .page-title-section {
                margin: -0.75rem -0.75rem 0.75rem -0.75rem;
                padding: 0.75rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
            }
        }

        /* SweetAlert Custom */
        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="pageHamburgerBtn" aria-label="Toggle Menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1 class="page-title">Kelola Data Akun Petugas</h1>
                <p class="page-subtitle">Kelola data akun petugas untuk sistem bank mini sekolah</p>
            </div>
        </div>

        <!-- Card Tambah Petugas -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div class="card-header-text">
                    <h3>Tambah Petugas</h3>
                    <p>Tambahkan akun petugas baru</p>
                </div>
            </div>
            <div class="card-body">
                <form action="" method="POST" id="petugasForm">
                    <input type="hidden" name="token" value="<?= $token ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Kode Petugas <span style="color: red;">*</span></label>
                            <input type="text" id="nama" name="nama" required placeholder="Contoh: MSB01"
                                maxlength="50">
                        </div>
                        <div class="form-group">
                            <label>Nama Petugas 1 <span style="color: red;">*</span></label>
                            <input type="text" id="petugas1_nama" name="petugas1_nama" required
                                placeholder="Masukan Nama" maxlength="50">
                        </div>
                        <div class="form-group">
                            <label>Nama Petugas 2 <span style="color: red;">*</span></label>
                            <input type="text" id="petugas2_nama" name="petugas2_nama" required
                                placeholder="Masukan Nama" maxlength="50">
                        </div>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Daftar Petugas -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-header-text">
                    <h3>Daftar Petugas</h3>
                    <p>Kelola akun petugas bank mini</p>
                </div>

                <!-- Search Form -->
                <form method="GET" action="" style="display: flex; gap: 8px; margin-right: 10px;">
                    <input type="text" name="search" placeholder="Cari nama/username..."
                        value="<?= htmlspecialchars($search) ?>"
                        style="padding: 0.5rem; border: 1px solid var(--gray-300); border-radius: var(--radius); width: 200px; height: 38px;">
                    <button type="submit" class="btn" style="padding: 0.5rem 1rem; font-size: 0.9rem; height: 38px;">
                        <i class="fas fa-search"></i>
                    </button>
                    <!-- Reset Button if searching -->
                    <?php if (!empty($search)): ?>
                        <a href="kelola_akun_petugas.php" class="btn btn-cancel"
                            style="padding: 0.5rem 1rem; font-size: 0.9rem; height: 38px; text-decoration:none;">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>

                <a href="download_akun_petugas.php?format=pdf<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                    class="btn btn-pdf" id="btnCetakPDF">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </a>
            </div>

            <div style="overflow-x: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Kode Petugas</th>
                            <th>Nama Petugas 1</th>
                            <th>Nama Petugas 2</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($petugas_list)): ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:30px; color:#999;">Belum ada data petugas.
                                </td>
                            </tr>
                        <?php else:
                            $no = $offset + 1;
                            foreach ($petugas_list as $row):
                                ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><span
                                            style="font-weight:500; color:var(--primary-color);"><?= htmlspecialchars($row['nama']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['petugas1_nama'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($row['petugas2_nama'] ?? '-') ?></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-action"
                                                onclick="editPetugas(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['username']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['petugas1_nama'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($row['petugas2_nama'] ?? ''), ENT_QUOTES) ?>')">
                                                <i class="fas fa-pencil-alt"></i> Edit
                                            </button>
                                            <button class="btn btn-action"
                                                onclick="hapusPetugas(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['nama']), ENT_QUOTES) ?>')">
                                                <i class="fas fa-trash-alt"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <!-- Previous button -->
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="page-link page-arrow">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-left"></i>
                        </span>
                    <?php endif; ?>

                    <!-- Current page indicator -->
                    <span class="page-link active"><?= $page ?></span>

                    <!-- Next button -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="page-link page-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="page-link page-arrow disabled">
                            <i class="fas fa-chevron-right"></i>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('pageHamburgerBtn');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;
        const mainContent = document.getElementById('mainContent');

        function openSidebar() {
            body.classList.add('sidebar-open');
            if (sidebar) sidebar.classList.add('active');
        }

        function closeSidebar() {
            body.classList.remove('sidebar-open');
            if (sidebar) sidebar.classList.remove('active');
        }

        // Toggle Sidebar
        if (menuToggle) {
            menuToggle.addEventListener('click', function (e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function (e) {
            if (body.classList.contains('sidebar-open') &&
                !sidebar?.contains(e.target) &&
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Prevent body scroll when sidebar open (mobile)
        let scrollTop = 0;
        body.addEventListener('scroll', function () {
            if (window.innerWidth <= 768 && body.classList.contains('sidebar-open')) {
                scrollTop = body.scrollTop;
            }
        }, { passive: true });

        // ============================================
        // AUTO FORMAT INPUT
        // ============================================
        function toTitleCase(str) {
            return str.toLowerCase().split(' ').map(function (word) {
                return word.charAt(0).toUpperCase() + word.slice(1);
            }).join(' ');
        }

        const namaInput = document.getElementById('nama');
        if (namaInput) {
            namaInput.addEventListener('input', function (e) {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = this.value.toUpperCase();
                this.setSelectionRange(start, end);
            });
        }

        const petugas1Input = document.getElementById('petugas1_nama');
        const petugas2Input = document.getElementById('petugas2_nama');

        if (petugas1Input) {
            petugas1Input.addEventListener('input', function (e) {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = toTitleCase(this.value);
                this.setSelectionRange(start, end);
            });
        }

        if (petugas2Input) {
            petugas2Input.addEventListener('input', function (e) {
                const start = this.selectionStart;
                const end = this.selectionEnd;
                this.value = toTitleCase(this.value);
                this.setSelectionRange(start, end);
            });
        }

        // PDF Export Loading
        const btnPdf = document.getElementById('btnCetakPDF');
        if (btnPdf) {
            btnPdf.addEventListener('click', function (e) {
                e.preventDefault();
                Swal.fire({
                    title: 'Sedang memproses...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });

                setTimeout(() => {
                    window.location.href = this.getAttribute('href');
                    // Beri jeda sedikit agar loading spinner terlihat sebelum browser memulai download
                    setTimeout(() => Swal.close(), 1000);
                }, 1000);
            });
        }

        // ============================================
        // MODAL FUNCTIONS
        // ============================================
        window.editPetugas = function (id, nama, username, petugas1_nama, petugas2_nama) {
            Swal.fire({
                title: 'Edit Petugas',
                html: `
                    <input type="text" id="swal-nama" value="${nama}" class="swal2-input" placeholder="Kode Petugas" maxlength="50">
                    <input type="text" id="swal-petugas1" value="${petugas1_nama}" class="swal2-input" placeholder="Nama Petugas 1" maxlength="50">
                    <input type="text" id="swal-petugas2" value="${petugas2_nama}" class="swal2-input" placeholder="Nama Petugas 2" maxlength="50">
                    <input type="text" id="swal-username" value="${username}" class="swal2-input" placeholder="Username">
                    <input type="password" id="swal-password" class="swal2-input" placeholder="Password Baru (min 8 karakter, opsional)">
                `,
                didOpen: () => {
                    const swalNama = document.getElementById('swal-nama');
                    const swalPetugas1 = document.getElementById('swal-petugas1');
                    const swalPetugas2 = document.getElementById('swal-petugas2');

                    swalNama.addEventListener('input', function (e) {
                        const start = this.selectionStart;
                        const end = this.selectionEnd;
                        this.value = this.value.toUpperCase();
                        this.setSelectionRange(start, end);
                    });

                    swalPetugas1.addEventListener('input', function (e) {
                        const start = this.selectionStart;
                        const end = this.selectionEnd;
                        this.value = toTitleCase(this.value);
                        this.setSelectionRange(start, end);
                    });

                    swalPetugas2.addEventListener('input', function (e) {
                        const start = this.selectionStart;
                        const end = this.selectionEnd;
                        this.value = toTitleCase(this.value);
                        this.setSelectionRange(start, end);
                    });
                },
                showCancelButton: true,
                confirmButtonText: 'Simpan',
                confirmButtonColor: '#1e3a8a',
                preConfirm: () => {
                    const valueNama = document.getElementById('swal-nama').value.trim();
                    const valuePetugas1 = document.getElementById('swal-petugas1').value.trim();
                    const valuePetugas2 = document.getElementById('swal-petugas2').value.trim();
                    const valueUsername = document.getElementById('swal-username').value.trim();
                    const valuePassword = document.getElementById('swal-password').value.trim();

                    if (!valueNama || valueNama.length < 3) {
                        Swal.showValidationMessage('Kode petugas minimal 3 karakter!');
                        return false;
                    }
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
                    return { nama: valueNama, petugas1_nama: valuePetugas1, petugas2_nama: valuePetugas2, username: valueUsername, password: valuePassword };
                }
            }).then((res) => {
                if (res.isConfirmed) {
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.innerHTML = `
                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="edit_confirm" value="1">
                        <input type="hidden" name="edit_id" value="${id}">
                        <input type="hidden" name="edit_nama" value="${res.value.nama}">
                        <input type="hidden" name="edit_petugas1_nama" value="${res.value.petugas1_nama}">
                        <input type="hidden" name="edit_petugas2_nama" value="${res.value.petugas2_nama}">
                        <input type="hidden" name="edit_username" value="${res.value.username}">
                        <input type="hidden" name="edit_password" value="${res.value.password}">`;
                    document.body.appendChild(f);
                    f.submit();
                }
            });
        }

        window.hapusPetugas = function (id, nama) {
            Swal.fire({
                title: 'Hapus Petugas?',
                text: `Hapus petugas "${nama}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Ya, Hapus'
            }).then((res) => {
                if (res.isConfirmed) {
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.innerHTML = `
                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="delete_id" value="${id}">`;
                    document.body.appendChild(f);
                    f.submit();
                }
            });
        }

        // ============================================
        // CONFIRM MODAL
        // ============================================
        <?php if ($show_confirm_modal): ?>
            Swal.fire({
                title: 'Konfirmasi',
                html: `Simpan akun petugas <b><?= htmlspecialchars($confirm_data['nama']) ?></b>?<br>
                   <small>Petugas: <?= htmlspecialchars($confirm_data['petugas1_nama']) ?> & <?= htmlspecialchars($confirm_data['petugas2_nama']) ?></small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                confirmButtonColor: '#1e3a8a'
            }).then((res) => {
                if (res.isConfirmed) {
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.innerHTML = `
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <input type="hidden" name="confirm" value="1">
                    <input type="hidden" name="nama" value="<?= $confirm_data['nama'] ?>">
                    <input type="hidden" name="petugas1_nama" value="<?= $confirm_data['petugas1_nama'] ?>">
                    <input type="hidden" name="petugas2_nama" value="<?= $confirm_data['petugas2_nama'] ?>">
                    <input type="hidden" name="username" value="<?= $confirm_data['username'] ?>">
                    <input type="hidden" name="password" value="<?= $confirm_data['password'] ?>">`;
                    document.body.appendChild(f);
                    f.submit();
                } else {
                    document.getElementById('nama').value = '';
                    document.getElementById('petugas1_nama').value = '';
                    document.getElementById('petugas2_nama').value = '';
                }
            });
        <?php endif; ?>

        // Show Flash Messages
        <?php if ($success_message): ?>
            Swal.fire('Berhasil', '<?= $success_message ?>', 'success');
        <?php endif; ?>

        <?php if ($error_message): ?>
            Swal.fire('Gagal', '<?= $error_message ?>', 'error');
        <?php endif; ?>
    </script>
</body>

</html>
<?php
$conn->close();
?>