<?php
/**
 * Kelola Jurusan - Final Version (Tambah & Hapus Pakai Konfirmasi SweetAlert2)
 * File: pages/admin/kelola_jurusan.php
 */

// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

if (basename($current_dir) === 'pages') {
    $project_root = dirname($current_dir);
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
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';

date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'petugas'])) {
    header('Location: ' . BASE_URL . '/pages/login.php');
    exit();
}

// CSRF Token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Flash Messages
function setFlash($type, $msg)
{
    $_SESSION['flash'][$type] = $msg;
}
function getFlash($type)
{
    if (isset($_SESSION['flash'][$type])) {
        $msg = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $msg;
    }
    return null;
}

// Variabel form
$edit_id = null;
$nama_jurusan = '';

// Pagination
$items_per_page = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// Total jurusan
$total_res = $conn->query("SELECT COUNT(*) as total FROM jurusan");
$total_rows = $total_res->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// ======================================== HANDLE POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && $_POST['token'] === $token) {

    // HAPUS (dari SweetAlert)
    if (isset($_POST['hapus_id'])) {
        $id = (int) $_POST['hapus_id'];

        $cek1 = $conn->query("SELECT 1 FROM siswa_profiles WHERE jurusan_id = $id LIMIT 1")->num_rows;
        $cek2 = $conn->query("SELECT 1 FROM kelas WHERE jurusan_id = $id LIMIT 1")->num_rows;

        if ($cek1 || $cek2) {
            setFlash('error', 'Tidak dapat menghapus jurusan yang masih digunakan!');
        } else {
            $conn->query("DELETE FROM jurusan WHERE id = $id");
            setFlash('success', 'Jurusan berhasil dihapus!');
        }
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        $token = $_SESSION['form_token'];
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['page']) ? '?page=' . $page : ''));
        exit;
    }

    // TAMBAH / EDIT
    $nama = trim($_POST['nama_jurusan']);

    if (empty($nama) || strlen($nama) < 3) {
        setFlash('error', 'Nama jurusan minimal 3 karakter!');
    } else {
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            $id = (int) $_POST['edit_id'];

            $old = $conn->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
            $old->bind_param("i", $id);
            $old->execute();
            $old_name = $old->get_result()->fetch_assoc()['nama_jurusan'] ?? '';

            if ($nama === $old_name) {
                setFlash('info', 'Tidak ada perubahan yang disimpan.');
            } else {
                $cek = $conn->prepare("SELECT id FROM jurusan WHERE nama_jurusan = ? AND id != ?");
                $cek->bind_param("si", $nama, $id);
                $cek->execute();
                if ($cek->get_result()->num_rows > 0) {
                    setFlash('error', 'Nama jurusan sudah ada!');
                } else {
                    $upd = $conn->prepare("UPDATE jurusan SET nama_jurusan = ? WHERE id = ?");
                    $upd->bind_param("si", $nama, $id);
                    $upd->execute();
                    setFlash('success', 'Jurusan berhasil diperbarui!');
                }
            }
        } else {
            $cek = $conn->prepare("SELECT id FROM jurusan WHERE nama_jurusan = ?");
            $cek->bind_param("s", $nama);
            $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                setFlash('error', 'Jurusan sudah ada!');
            } else {
                $ins = $conn->prepare("INSERT INTO jurusan (nama_jurusan) VALUES (?)");
                $ins->bind_param("s", $nama);
                $ins->execute();
                setFlash('success', 'Jurusan berhasil ditambahkan!');
            }
        }
    }

    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['form_token'];

    header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['page']) ? '?page=' . $page : ''));
    exit;
}

// ======================================== HANDLE EDIT (GET) ========================================
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $res = $conn->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
    $res->bind_param("i", $edit_id);
    $res->execute();
    $row = $res->get_result()->fetch_assoc();
    if ($row) {
        $nama_jurusan = $row['nama_jurusan'];
    } else {
        setFlash('error', 'Jurusan tidak ditemukan!');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ======================================== AMBIL DATA UNTUK TABEL ========================================
$stmt = $conn->prepare("SELECT id, nama_jurusan FROM jurusan ORDER BY nama_jurusan ASC LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$res = $stmt->get_result();
$jurusan_list = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kelola Jurusan | KASDIG</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
        .jadwal-list {
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

        input[type="text"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius);
            font-size: 0.95rem;
            height: 48px;
            background: #fff;
            color: var(--gray-800);
            transition: all 0.2s;
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
            text-decoration: none !important;
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-cancel {
            background: white;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
        }

        .btn-action {
            background: linear-gradient(135deg, var(--gray-600) 0%, var(--gray-500) 100%);
            color: white;
        }

        .btn-action:hover {
            background: linear-gradient(135deg, var(--gray-700) 0%, var(--gray-600) 100%);
        }

        /* Data Table */
        .table-wrapper {
            max-height: 500px;
            overflow-y: auto;
        }

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
        }

        .page-link:hover {
            background: var(--gray-100);
        }

        .page-link.active {
            background: var(--gray-600);
            color: white;
            border-color: var(--gray-600);
        }

        a,
        a:hover,
        a:visited,
        a:active {
            text-decoration: none !important;
        }

        /* SweetAlert Custom Fixes */
        .swal2-input {
            height: 48px !important;
            margin: 10px auto !important;
        }

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
            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .card-body {
                padding: 1rem;
            }

            .card-header {
                flex-wrap: wrap;
                gap: 0.5rem;
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
                <h1 class="page-title">Kelola Jurusan</h1>
                <p class="page-subtitle">Tambah, edit, atau hapus data jurusan sekolah</p>
            </div>
        </div>

        <!-- Card Tambah / Edit Jurusan -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-<?= $edit_id ? 'edit' : 'plus' ?>"></i>
                </div>
                <div class="card-header-text">
                    <h3><?= $edit_id ? 'Edit Jurusan' : 'Tambah Jurusan' ?></h3>
                    <p>Kelola data jurusan sekolah</p>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" id="jurusanForm">
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <?php if ($edit_id): ?>
                        <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Nama Jurusan</label>
                            <input type="text" id="namaInput" name="nama_jurusan"
                                value="<?= htmlspecialchars($nama_jurusan) ?>"
                                placeholder="Contoh: Rekayasa Perangkat Lunak" required maxlength="100">
                        </div>
                    </div>

                    <div style="display:flex; gap:12px; margin-top:10px;">
                        <button type="button" id="submitBtn" class="btn">
                            <i class="fas fa-save"></i> <?= $edit_id ? 'Update Jurusan' : 'Tambah Jurusan' ?>
                        </button>
                        <?php if ($edit_id): ?>
                            <a href="kelola_jurusan.php" class="btn btn-cancel">Batal</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card Daftar Jurusan -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="card-header-text">
                    <h3>Daftar Jurusan</h3>
                    <p>Semua jurusan yang tersedia</p>
                </div>
            </div>

            <div class="table-wrapper" style="padding-top: 1.5rem; background: var(--gray-100);">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Nama Jurusan</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($jurusan_list)): ?>
                            <tr>
                                <td colspan="3" style="text-align:center; padding:40px; color:#999;">
                                    Belum ada data jurusan
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($jurusan_list as $i => $j): ?>
                                <tr>
                                    <td><?= $offset + $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($j['nama_jurusan']) ?></strong></td>
                                    <td>
                                        <div class="actions">
                                            <a href="?edit=<?= $j['id'] ?>" class="btn btn-action">
                                                <i class="fas fa-pencil-alt"></i> Edit
                                            </a>
                                            <button type="button" class="btn btn-action"
                                                onclick="hapusJurusan(<?= $j['id'] ?>, '<?= htmlspecialchars(addslashes($j['nama_jurusan']), ENT_QUOTES) ?>')">
                                                <i class="fas fa-trash-alt"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?>" class="page-link <?php echo $page == $i ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
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

        function openSidebar() {
            body.classList.add('sidebar-open');
            if (sidebar) sidebar.classList.add('active');
        }

        function closeSidebar() {
            body.classList.remove('sidebar-open');
            if (sidebar) sidebar.classList.remove('active');
        }

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

        document.addEventListener('click', function (e) {
            if (body.classList.contains('sidebar-open') &&
                !sidebar?.contains(e.target) &&
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Tambah / Update dengan Konfirmasi
        document.getElementById('submitBtn').addEventListener('click', function (e) {
            e.preventDefault();
            const input = document.getElementById('namaInput');
            const nama = input.value.trim();

            if (!nama || nama.length < 3) {
                Swal.fire('Gagal', 'Nama jurusan minimal 3 karakter!', 'error');
                return;
            }

            Swal.fire({
                title: <?= $edit_id ? "'Update Jurusan?'" : "'Tambah Jurusan?'" ?>,
                text: `"${nama}" akan <?= $edit_id ? 'diperbarui' : 'ditambahkan' ?>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, <?= $edit_id ? 'Update' : 'Tambah' ?>',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#334155'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('jurusanForm').submit();
                }
            });
        });

        // Hapus Jurusan
        function hapusJurusan(id, nama) {
            Swal.fire({
                title: 'Hapus Jurusan?',
                text: `"${nama}" akan dihapus permanen`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="token" value="<?= $token ?>">
                        <input type="hidden" name="hapus_id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Flash Messages
        <?php if ($msg = getFlash('success')): ?>
            Swal.fire('Berhasil', '<?= addslashes($msg) ?>', 'success');
        <?php endif; ?>
        <?php if ($msg = getFlash('error')): ?>
            Swal.fire('Gagal', '<?= addslashes($msg) ?>', 'error');
        <?php endif; ?>
        <?php if ($msg = getFlash('info')): ?>
            Swal.fire('Info', '<?= addslashes($msg) ?>', 'info');
        <?php endif; ?>
    </script>
</body>

</html>