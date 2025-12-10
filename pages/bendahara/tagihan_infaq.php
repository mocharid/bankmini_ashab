<?php
/**
 * Buat Tagihan Infaq Siswa + Filter + Pagination
 * File: pages/bendahara/tagihan_infaq.php
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('Asia/Jakarta');

// ADAPTIVE PATH
$current_file = __FILE__;
$current_dir  = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'bendahara') {
    $project_root = dirname(dirname($current_dir));
} elseif (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
} elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
} elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
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
if (!$project_root) {
    $project_root = $current_dir;
}

if (!defined('PROJECT_ROOT'))  define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', PROJECT_ROOT . '/includes');

// SESSION & AUTH
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!file_exists(INCLUDES_PATH . '/db_connection.php')) {
    die('File db_connection.php tidak ditemukan.');
}
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'bendahara') {
    header('Location: ../login.php');
    exit();
}

$user_id        = $_SESSION['user_id'];
$nama_bendahara = $_SESSION['nama'];

$errors  = [];
$success = '';

// ===== MASTER FILTER =====
// Jenis infaq aktif
$jenis_infaq = [];
$res = $conn->query("
    SELECT id, nama_infaq, harga_default
    FROM jenis_infaq
    WHERE is_active = 1
    ORDER BY nama_infaq ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $jenis_infaq[] = $row;
    }
}

// Tingkatan_kelas (untuk filter)
$tingkatan_list = [];
$res = $conn->query("SELECT id, nama_tingkatan FROM tingkatan_kelas ORDER BY nama_tingkatan ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $tingkatan_list[] = $row;
    }
}

// Jurusan
$jurusan_list = [];
$res = $conn->query("SELECT id, nama_jurusan FROM jurusan ORDER BY nama_jurusan ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $jurusan_list[] = $row;
    }
}

// Kelas (untuk dropdown filter)
$kelas_list = [];
$res = $conn->query("
    SELECT k.id, k.nama_kelas, tk.nama_tingkatan, j.nama_jurusan
    FROM kelas k
    LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
    LEFT JOIN jurusan j ON k.jurusan_id = j.id
    ORDER BY tk.nama_tingkatan ASC, k.nama_kelas ASC
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $kelas_list[] = $row;
    }
}

// Baca filter dari GET
$selected_jenis_id     = isset($_GET['jenis_infaq_id']) ? (int)$_GET['jenis_infaq_id'] : 0;
$selected_tingkatan_id = isset($_GET['tingkatan_id']) ? (int)$_GET['tingkatan_id'] : 0;
$selected_kelas_id     = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : 0;
$selected_jurusan_id   = isset($_GET['jurusan_id']) ? (int)$_GET['jurusan_id'] : 0;
$selected_tahun        = isset($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$selected_bulan        = isset($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('m');
$search_nama           = isset($_GET['search_nama']) ? trim($_GET['search_nama']) : '';
$page                  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit                 = 10;
$offset                = ($page - 1) * $limit;

// Jika jenis_infaq belum dipilih, auto pilih pertama
if ($selected_jenis_id == 0 && !empty($jenis_infaq)) {
    $selected_jenis_id = (int)$jenis_infaq[0]['id'];
}

// ===== HANDLE CREATE TAGIHAN (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_tagihan') {
        $jenis_id        = (int)($_POST['jenis_infaq_id'] ?? 0);
        $tahun           = (int)($_POST['tahun'] ?? 0);
        $bulan           = (int)($_POST['bulan'] ?? 0);
        $jatuh_tempo     = $_POST['tanggal_jatuh_tempo'] ?? '';
        $tingkatan_id    = (int)($_POST['tingkatan_id'] ?? 0);
        $kelas_id        = (int)($_POST['kelas_id'] ?? 0);
        $jurusan_id      = (int)($_POST['jurusan_id'] ?? 0);
        $search_nama_post= trim($_POST['search_nama'] ?? '');

        if ($jenis_id <= 0) {
            $errors[] = 'Jenis infaq tidak valid.';
        }
        if ($tahun < 2000 || $tahun > 2100) {
            $errors[] = 'Tahun tidak valid.';
        }
        if ($bulan < 1 || $bulan > 12) {
            $errors[] = 'Bulan tidak valid.';
        }
        if (!DateTime::createFromFormat('Y-m-d', $jatuh_tempo)) {
            $errors[] = 'Tanggal jatuh tempo tidak valid.';
        }

        if (empty($errors)) {
            // Ambil harga default
            $harga_default = 0;
            foreach ($jenis_infaq as $ji) {
                if ($ji['id'] == $jenis_id) {
                    $harga_default = (float)$ji['harga_default'];
                    break;
                }
            }

            // Query siswa eligible (status bayar, punya rekening, belum ada tagihan periode ini)
            $siswa_sql = "
                SELECT 
                    u.id AS user_id,
                    r.id AS rekening_id,
                    COALESCE(sis.harga_khusus, ?) AS jumlah
                FROM users u
                JOIN siswa_profiles sp ON u.id = sp.user_id
                JOIN rekening r ON u.id = r.user_id
                LEFT JOIN kelas k ON sp.kelas_id = k.id
                LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
                LEFT JOIN jurusan jur ON sp.jurusan_id = jur.id
                LEFT JOIN siswa_infaq_setting sis ON u.id = sis.user_id AND sis.jenis_infaq_id = ?
                LEFT JOIN tagihan_infaq ti ON u.id = ti.user_id AND ti.jenis_infaq_id = ? AND ti.tahun = ? AND ti.bulan = ?
                WHERE u.role = 'siswa' 
                  AND (sis.status_bayar = 'bayar' OR sis.status_bayar IS NULL)
                  AND ti.id IS NULL
            ";

            $params = [$harga_default, $jenis_id, $jenis_id, $tahun, $bulan];
            $types  = "diiii";

            if ($tingkatan_id > 0) {
                $siswa_sql .= " AND tk.id = ? ";
                $params[] = $tingkatan_id;
                $types .= "i";
            }
            if ($kelas_id > 0) {
                $siswa_sql .= " AND k.id = ? ";
                $params[] = $kelas_id;
                $types .= "i";
            }
            if ($jurusan_id > 0) {
                $siswa_sql .= " AND jur.id = ? ";
                $params[] = $jurusan_id;
                $types .= "i";
            }
            if ($search_nama_post !== '') {
                $siswa_sql .= " AND u.nama LIKE ? ";
                $params[] = "%" . $search_nama_post . "%";
                $types .= "s";
            }

            $stmt_siswa = $conn->prepare($siswa_sql);
            $stmt_siswa->bind_param($types, ...$params);
            $stmt_siswa->execute();
            $res_siswa = $stmt_siswa->get_result();

            $created_count = 0;
            $conn->begin_transaction();
            try {
                $stmt_ins = $conn->prepare("
                    INSERT INTO tagihan_infaq 
                        (tahun, bulan, jenis_infaq_id, user_id, rekening_id, jumlah, status, tanggal_jatuh_tempo, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'menunggu', ?, ?)
                ");

                while ($row = $res_siswa->fetch_assoc()) {
                    $jumlah = (float)$row['jumlah'];
                    if ($jumlah <= 0) continue; // Skip jika jumlah 0

                    $stmt_ins->bind_param("iiiiidsi", 
                        $tahun, $bulan, $jenis_id, $row['user_id'], $row['rekening_id'], $jumlah, $jatuh_tempo, $user_id
                    );
                    $stmt_ins->execute();
                    $created_count++;
                }

                $conn->commit();
                if ($created_count > 0) {
                    $success = $created_count . ' tagihan infaq berhasil dibuat.';
                } else {
                    $success = 'Tidak ada tagihan baru yang dibuat (mungkin sudah ada atau tidak ada siswa eligible).';
                }
            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Terjadi kesalahan saat membuat tagihan: ' . $e->getMessage();
            }
            $stmt_siswa->close();
        }
    }
}

// ===== COUNT TOTAL TAGIHAN UNTUK PAGINATION =====
$total_tagihan = 0;
if ($selected_jenis_id > 0) {
    $count_sql = "
        SELECT COUNT(ti.id) as total
        FROM tagihan_infaq ti
        JOIN users u ON ti.user_id = u.id
        JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        LEFT JOIN jurusan jur ON sp.jurusan_id = jur.id
        WHERE ti.jenis_infaq_id = ?
    ";

    $count_params = [$selected_jenis_id];
    $count_types  = "i";

    if ($selected_tingkatan_id > 0) {
        $count_sql .= " AND tk.id = ? ";
        $count_params[] = $selected_tingkatan_id;
        $count_types .= "i";
    }
    if ($selected_kelas_id > 0) {
        $count_sql .= " AND k.id = ? ";
        $count_params[] = $selected_kelas_id;
        $count_types .= "i";
    }
    if ($selected_jurusan_id > 0) {
        $count_sql .= " AND jur.id = ? ";
        $count_params[] = $selected_jurusan_id;
        $count_types .= "i";
    }
    if ($search_nama !== '') {
        $count_sql .= " AND u.nama LIKE ? ";
        $count_params[] = "%" . $search_nama . "%";
        $count_types .= "s";
    }
    if ($selected_tahun > 0) {
        $count_sql .= " AND ti.tahun = ? ";
        $count_params[] = $selected_tahun;
        $count_types .= "i";
    }
    if ($selected_bulan > 0) {
        $count_sql .= " AND ti.bulan = ? ";
        $count_params[] = $selected_bulan;
        $count_types .= "i";
    }

    $count_stmt = $conn->prepare($count_sql);
    if ($count_params) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_res = $count_stmt->get_result();
    $count_row = $count_res->fetch_assoc();
    $total_tagihan = (int)$count_row['total'];
    $count_stmt->close();

    $total_pages = ceil($total_tagihan / $limit);
}

// ===== AMBIL DATA TAGIHAN + FILTER + PAGINATION =====
$tagihan_data = [];
if ($selected_jenis_id > 0 && $total_tagihan > 0) {
    $sql = "
        SELECT 
            ti.id,
            ti.no_tagihan,
            ti.tahun,
            ti.bulan,
            ti.jumlah,
            ti.status,
            ti.tanggal_jatuh_tempo,
            ti.tanggal_bayar,
            u.id AS user_id,
            u.nama,
            sp.nis_nisn,
            k.nama_kelas,
            tk.nama_tingkatan,
            jur.nama_jurusan,
            CONCAT_WS(' ', tk.nama_tingkatan, k.nama_kelas) AS kelas_lengkap
        FROM tagihan_infaq ti
        JOIN users u ON ti.user_id = u.id
        JOIN siswa_profiles sp ON u.id = sp.user_id
        LEFT JOIN kelas k ON sp.kelas_id = k.id
        LEFT JOIN tingkatan_kelas tk ON k.tingkatan_kelas_id = tk.id
        LEFT JOIN jurusan jur ON sp.jurusan_id = jur.id
        WHERE ti.jenis_infaq_id = ?
    ";

    $params = [$selected_jenis_id];
    $types  = "i";

    if ($selected_tingkatan_id > 0) {
        $sql .= " AND tk.id = ? ";
        $params[] = $selected_tingkatan_id;
        $types .= "i";
    }
    if ($selected_kelas_id > 0) {
        $sql .= " AND k.id = ? ";
        $params[] = $selected_kelas_id;
        $types .= "i";
    }
    if ($selected_jurusan_id > 0) {
        $sql .= " AND jur.id = ? ";
        $params[] = $selected_jurusan_id;
        $types .= "i";
    }
    if ($search_nama !== '') {
        $sql .= " AND u.nama LIKE ? ";
        $params[] = "%" . $search_nama . "%";
        $types .= "s";
    }
    if ($selected_tahun > 0) {
        $sql .= " AND ti.tahun = ? ";
        $params[] = $selected_tahun;
        $types .= "i";
    }
    if ($selected_bulan > 0) {
        $sql .= " AND ti.bulan = ? ";
        $params[] = $selected_bulan;
        $types .= "i";
    }

    $sql .= " ORDER BY ti.tahun DESC, ti.bulan DESC, u.nama ASC LIMIT ? OFFSET ?";

    $params[] = $limit;
    $params[] = $offset;
    $types   .= "ii";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tagihan_data[] = $row;
    }
    $stmt->close();
}

$protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$path_parts  = explode('/', trim(dirname($script_name), '/'));
$base_path   = in_array('schobank', $path_parts) ? '/schobank' : '';
$base_url    = $protocol . '://' . $host . $base_path;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="format-detection" content="telephone=no">
    <link rel="icon" type="image/png" href="<?php echo $base_url; ?>/assets/images/tab.png">
    <title>Tagihan Infaq Siswa | SchoBank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
            --shadow-lg: 0 10px 15px rgba(0,0,0,0.1);
            --radius-sm: 6px;
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 3rem;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        html { font-size:16px; }
        body {
            font-family: var(--font-sans);
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            min-height: 100vh;
        }
        /* NAVBAR */
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: #fff;
            border-bottom: 1px solid var(--gray-200);
            box-shadow: var(--shadow-sm);
        }
        .navbar-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 var(--space-lg);
            display:flex;
            align-items:center;
            justify-content:space-between;
            height:64px;
        }
        .navbar-brand {
            font-size:1.25rem;
            font-weight:700;
            color:var(--primary);
            text-decoration:none;
            letter-spacing:.02em;
        }
        .navbar-toggle{
            display:none;
            background:none;
            border:none;
            font-size:1.5rem;
            color:var(--gray-700);
            cursor:pointer;
            padding:var(--space-xs);
        }
        .navbar-menu{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            list-style:none;
        }
        .navbar-menu a{
            display:flex;
            align-items:center;
            gap:var(--space-xs);
            padding:var(--space-xs) var(--space-md);
            color:var(--gray-600);
            text-decoration:none;
            font-size:0.9rem;
            font-weight:500;
            border-radius:var(--radius);
            transition:color .2s ease;
        }
        .navbar-menu a i{
            font-size:0.95rem;
        }
        .navbar-menu a:hover,
        .navbar-menu a.active{
            color:var(--primary);
        }
        .navbar-user{
            display:flex;
            align-items:center;
            gap:var(--space-md);
        }
        .user-info{display:flex;align-items:center;gap:var(--space-sm);}
        .user-avatar{
            width:32px;height:32px;border-radius:50%;
            background:var(--primary);
            display:flex;align-items:center;justify-content:center;
            color:#fff;font-weight:600;font-size:0.85rem;
        }
        .user-details{display:flex;flex-direction:column;}
        .user-name{font-size:0.875rem;font-weight:600;color:var(--gray-900);}
        .user-role{font-size:0.75rem;color:var(--gray-500);}
        .btn-logout{
            padding:var(--space-xs) var(--space-sm);
            background:var(--danger);color:#fff;border:none;
            border-radius:var(--radius);
            font-size:1.2rem;
            cursor:pointer;
            transition:all .2s ease;
        }
        .btn-logout:hover{background:#dc2626;transform:translateY(-1px);}
        /* MAIN */
        .main-container{
            max-width:1400px;
            margin:0 auto;
            padding:var(--space-xl) var(--space-lg);
        }
        .page-header{margin-bottom:var(--space-xl);}
        .page-title{font-size:1.75rem;font-weight:700;margin-bottom:var(--space-xs);}
        .page-subtitle{font-size:0.95rem;color:var(--gray-600);}
        /* CARDS */
        .card{
            background:#fff;
            border-radius:var(--radius-lg);
            padding:var(--space-lg);
            border:1px solid var(--gray-200);
            box-shadow:var(--shadow-sm);
            transition:all .3s ease;
        }
        .card:hover{box-shadow:var(--shadow-md);}
        .card-header{
            margin-bottom:var(--space-lg);
            padding-bottom:var(--space-md);
            border-bottom:1px solid var(--gray-100);
        }
        .card-title{
            font-size:1.1rem;font-weight:600;color:var(--gray-900);
            display:flex;align-items:center;gap:var(--space-xs);
            margin-bottom:var(--space-xs);
        }
        .card-title i{font-size:1rem;color:var(--primary);}
        .card-subtitle{font-size:0.875rem;color:var(--gray-500);}
        /* ALERTS */
        .alert{
            padding:var(--space-md);
            border-radius:var(--radius);
            margin-bottom:var(--space-lg);
            border-left:4px solid;
        }
        .alert-success{
            background:var(--gray-50);
            color:var(--success);
            border-color:var(--success);
        }
        .alert-error{
            background:#fef2f2;
            color:#dc2626;
            border-color:#ef4444;
        }
        /* FORM */
        .form-group{margin-bottom:var(--space-md);}
        .form-label{
            display:block;
            font-size:0.875rem;
            font-weight:500;
            margin-bottom:var(--space-xs);
            color:var(--gray-700);
        }
        .form-input,.form-select{
            width:100%;
            border:1px solid var(--gray-300);
            border-radius:var(--radius);
            padding:var(--space-sm) var(--space-md);
            font-size:0.9rem;
            transition:all .2s ease;
            background:#fff;
        }
        .form-input:focus,
        .form-select:focus{
            outline:none;
            border-color:var(--primary);
            box-shadow:0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-hint{
            font-size:0.8rem;
            color:var(--gray-500);
            margin-top:var(--space-xs);
        }
        .form-row{
            display:flex;
            gap:var(--space-md);
            flex-wrap:wrap;
        }
        .form-row > .form-group{flex:1 1 200px;}
        /* BUTTONS */
        .btn{
            border:none;
            border-radius:var(--radius);
            padding:var(--space-sm) var(--space-md);
            font-size:0.875rem;
            font-weight:500;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:var(--space-xs);
            text-decoration:none;
            transition:all .2s ease;
        }
        .btn-primary{
            background:var(--primary);
            color:#fff;
        }
        .btn-primary:hover{background:var(--primary-dark);transform:translateY(-1px);}
        .btn-secondary{
            background:var(--gray-100);
            color:var(--gray-700);
        }
        .btn-secondary:hover{background:var(--gray-200);}
        .form-actions{
            display:flex;
            gap:var(--space-md);
            justify-content:flex-end;
            margin-top:var(--space-lg);
        }
        /* TABLE */
        .table-responsive{overflow-x:auto;margin-top:var(--space-md);}
        .data-table{
            width:100%;
            border-collapse:collapse;
            font-size:0.85rem;
        }
        .data-table thead tr{border-bottom:2px solid var(--gray-200);}
        .data-table th{
            text-align:left;
            padding:var(--space-sm) var(--space-md);
            font-weight:600;
            font-size:0.8rem;
            color:var(--gray-600);
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        .data-table td{
            padding:var(--space-sm) var(--space-md);
            border-bottom:1px solid var(--gray-100);
        }
        .data-table tbody tr:hover{background:var(--gray-50);}
        .siswa-name{font-weight:500;}
        .nis{font-size:0.75rem;color:var(--gray-500);}
        .input-mini{
            width:120px;
            padding:var(--space-xs) var(--space-sm);
            font-size:0.85rem;
        }
        .select-mini{
            width:150px;
            padding:var(--space-xs) var(--space-sm);
            font-size:0.85rem;
        }
        /* PAGINATION */
        .pagination{
            display:flex;
            justify-content:center;
            gap:var(--space-sm);
            margin-top:var(--space-lg);
        }
        .page-link{
            padding:var(--space-xs) var(--space-sm);
            border:1px solid var(--gray-300);
            border-radius:var(--radius);
            text-decoration:none;
            color:var(--gray-700);
            font-size:0.85rem;
        }
        .page-link:hover{
            background:var(--primary);
            color:#fff;
        }
        .page-link.active{
            background:var(--primary);
            color:#fff;
        }
        /* EMPTY STATE */
        .empty-state{
            text-align:center;
            padding:var(--space-2xl);
            color:var(--gray-500);
        }
        .empty-state i{font-size:3rem;margin-bottom:var(--space-md);opacity:0.5;}
        /* RESPONSIVE */
        @media (max-width:1024px){
            .navbar-menu{display:none;}
        }
        @media (max-width:768px){
            .navbar-container{padding:0 var(--space-md);}
            .navbar-toggle{display:block;}
            .navbar-menu{
                position:fixed;
                top:64px;left:0;right:0;
                background:#fff;
                flex-direction:column;
                padding:var(--space-md);
                box-shadow:var(--shadow-lg);
                border-top:1px solid var(--gray-200);
                max-height:0;overflow:hidden;
                transition:max-height .3s ease;
            }
            .navbar-menu.active{display:flex;max-height:500px;}
            .navbar-menu a{width:100%;padding:var(--space-md);}
            .user-details{display:none;}
            .main-container{padding:var(--space-lg) var(--space-md);}
            .page-title{font-size:1.5rem;}
            .data-table th,.data-table td{padding:var(--space-xs) var(--space-sm);}
            .input-mini{width:100%;}
            .select-mini{width:100%;}
        }
    </style>
</head>
<body>
    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="navbar-container">
            <a href="dashboard.php" class="navbar-brand">SchoBank</a>
            <button class="navbar-toggle" id="navbarToggle">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="navbar-menu" id="navbarMenu">
                <li><a href="dashboard.php"><i class="fas fa-home"></i> Dashboard</a></li>
                <li><a href="kelola_infaq.php"><i class="fas fa-cog"></i> Kelola Infaq</a></li>
                <li><a href="status_siswa.php"><i class="fas fa-users"></i> Status Infaq Siswa</a></li>
                <li><a href="tagihan_infaq.php" class="active"><i class="fas fa-plus-circle"></i> Buat Tagihan</a></li>
                <li><a href="laporan_infaq.php"><i class="fas fa-chart-line"></i> Laporan</a></li>
            </ul>
            <div class="navbar-user">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($nama_bendahara, 0, 1)); ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($nama_bendahara); ?></div>
                        <div class="user-role">Bendahara</div>
                    </div>
                </div>
                <button class="btn-logout" onclick="logout()" title="Keluar">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        </div>
    </nav>
    <!-- MAIN -->
    <main class="main-container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-plus-circle"></i> Buat Tagihan Infaq
            </h1>
            <p class="page-subtitle">
                Buat tagihan infaq untuk siswa dengan status bayar. Tagihan otomatis hanya untuk siswa eligible.
            </p>
        </div>
        <section class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-filter"></i> Form Buat Tagihan
                </div>
                <div class="card-subtitle">
                    Pilih jenis infaq, periode, dan filter siswa untuk membuat tagihan.
                </div>
            </div>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <?php foreach ($errors as $e): ?>
                        <div><?php echo htmlspecialchars($e); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($success): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="create_tagihan">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="jenis_infaq_id">Jenis Infaq</label>
                        <select id="jenis_infaq_id" name="jenis_infaq_id" class="form-select" required>
                            <?php foreach ($jenis_infaq as $ji): ?>
                                <option value="<?php echo $ji['id']; ?>" <?php echo $selected_jenis_id == $ji['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ji['nama_infaq']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tahun">Tahun</label>
                        <input type="number" id="tahun" name="tahun" class="form-input" value="<?php echo $selected_tahun; ?>" min="2000" max="2100" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bulan">Bulan</label>
                        <select id="bulan" name="bulan" class="form-select" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $selected_bulan == $m ? 'selected' : ''; ?>>
                                    <?php echo str_pad($m, 2, '0', STR_PAD_LEFT) . ' - ' . date('F', mktime(0,0,0,$m,1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tanggal_jatuh_tempo">Tanggal Jatuh Tempo</label>
                        <input type="date" id="tanggal_jatuh_tempo" name="tanggal_jatuh_tempo" class="form-input" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="tingkatan_id">Tingkatan</label>
                        <select id="tingkatan_id" name="tingkatan_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($tingkatan_list as $tl): ?>
                                <option value="<?php echo $tl['id']; ?>" <?php echo $selected_tingkatan_id == $tl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tl['nama_tingkatan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="kelas_id">Kelas</label>
                        <select id="kelas_id" name="kelas_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($kelas_list as $kl): ?>
                                <option value="<?php echo $kl['id']; ?>" <?php echo $selected_kelas_id == $kl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($kl['nama_tingkatan'] . ' ' . $kl['nama_kelas']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="jurusan_id">Jurusan</label>
                        <select id="jurusan_id" name="jurusan_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($jurusan_list as $jl): ?>
                                <option value="<?php echo $jl['id']; ?>" <?php echo $selected_jurusan_id == $jl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($jl['nama_jurusan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="search_nama">Cari Nama</label>
                        <input type="text" id="search_nama" name="search_nama" class="form-input" value="<?php echo htmlspecialchars($search_nama); ?>" placeholder="Nama siswa">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Buat Tagihan
                    </button>
                    <a href="tagihan_infaq.php" class="btn btn-secondary">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </form>
        </section>
        <section class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Daftar Tagihan
                </div>
                <div class="card-subtitle">
                    Total: <?php echo $total_tagihan; ?> tagihan
                </div>
            </div>
            <form method="get" autocomplete="off">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="jenis_infaq_id">Jenis Infaq</label>
                        <select id="jenis_infaq_id" name="jenis_infaq_id" class="form-select">
                            <?php foreach ($jenis_infaq as $ji): ?>
                                <option value="<?php echo $ji['id']; ?>" <?php echo $selected_jenis_id == $ji['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ji['nama_infaq']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tahun">Tahun</label>
                        <input type="number" id="tahun" name="tahun" class="form-input" value="<?php echo $selected_tahun; ?>" min="2000" max="2100">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="bulan">Bulan</label>
                        <select id="bulan" name="bulan" class="form-select">
                            <option value="">Semua</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $selected_bulan == $m ? 'selected' : ''; ?>>
                                    <?php echo str_pad($m, 2, '0', STR_PAD_LEFT) . ' - ' . date('F', mktime(0,0,0,$m,1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="tingkatan_id">Tingkatan</label>
                        <select id="tingkatan_id" name="tingkatan_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($tingkatan_list as $tl): ?>
                                <option value="<?php echo $tl['id']; ?>" <?php echo $selected_tingkatan_id == $tl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tl['nama_tingkatan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="kelas_id">Kelas</label>
                        <select id="kelas_id" name="kelas_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($kelas_list as $kl): ?>
                                <option value="<?php echo $kl['id']; ?>" <?php echo $selected_kelas_id == $kl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($kl['nama_tingkatan'] . ' ' . $kl['nama_kelas']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="jurusan_id">Jurusan</label>
                        <select id="jurusan_id" name="jurusan_id" class="form-select">
                            <option value="">Semua</option>
                            <?php foreach ($jurusan_list as $jl): ?>
                                <option value="<?php echo $jl['id']; ?>" <?php echo $selected_jurusan_id == $jl['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($jl['nama_jurusan']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="search_nama">Cari Nama</label>
                        <input type="text" id="search_nama" name="search_nama" class="form-input" value="<?php echo htmlspecialchars($search_nama); ?>" placeholder="Nama siswa">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="tagihan_infaq.php" class="btn btn-secondary">
                        <i class="fas fa-rotate-left"></i> Reset
                    </a>
                </div>
            </form>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No Tagihan</th>
                            <th>Periode</th>
                            <th>Siswa</th>
                            <th>Kelas</th>
                            <th>Jurusan</th>
                            <th>Jumlah</th>
                            <th>Status</th>
                            <th>Jatuh Tempo</th>
                            <th>Tanggal Bayar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tagihan_data)): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-search"></i>
                                    <p>Tidak ada data.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tagihan_data as $td): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($td['no_tagihan']); ?></td>
                                    <td><?php echo $td['tahun'] . '-' . str_pad($td['bulan'], 2, '0', STR_PAD_LEFT); ?></td>
                                    <td>
                                        <span class="siswa-name"><?php echo htmlspecialchars($td['nama']); ?></span><br>
                                        <span class="nis">NIS: <?php echo htmlspecialchars($td['nis_nisn'] ?? '-'); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($td['kelas_lengkap'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($td['nama_jurusan'] ?? '-'); ?></td>
                                    <td>Rp <?php echo number_format($td['jumlah'], 0, ',', '.'); ?></td>
                                    <td><?php echo ucfirst($td['status']); ?></td>
                                    <td><?php echo date('d-m-Y', strtotime($td['tanggal_jatuh_tempo'])); ?></td>
                                    <td><?php echo $td['tanggal_bayar'] ? date('d-m-Y H:i', strtotime($td['tanggal_bayar'])) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
                    <?php endif; ?>
                    <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>" class="page-link <?php echo $p == $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        $(function() {
            $('#navbarToggle').on('click', function() {
                $('#navbarMenu').toggleClass('active');
                $(this).find('i').toggleClass('fa-bars fa-times');
            });
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.navbar-container').length) {
                    $('#navbarMenu').removeClass('active');
                    $('#navbarToggle i').removeClass('fa-times').addClass('fa-bars');
                }
            });
        });
        function logout() {
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Yakin keluar?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../logout.php';
                }
            });
        }
    </script>
</body>
</html>