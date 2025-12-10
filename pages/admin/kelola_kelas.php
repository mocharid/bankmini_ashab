<?php
/**
 * Kelola Kelas - Final Version (Sesuai DB Baru + Konfirmasi Tambah + Dipisah Jurusan)
 * File: pages/admin/kelola_kelas.php
 */

// ============================================
// ADAPTIVE PATH & CONFIGURATION
// ============================================
$current_file = __FILE__;
$current_dir  = dirname($current_file);
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
if (!$project_root) $project_root = $current_dir;

if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', rtrim($project_root, '/'));
if (!defined('INCLUDES_PATH')) define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
if (!defined('ASSETS_PATH'))   define('ASSETS_PATH',   PROJECT_ROOT . '/assets');

function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname($script);
    $base_path = str_replace('\\', '/', $base_path);
    $base_path = preg_replace('#/pages.*$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) $base_path = '/' . ltrim($base_path, '/');
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) define('BASE_URL', rtrim(getBaseUrl(), '/'));
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
function setFlash($type, $msg) {
    $_SESSION['flash'][$type] = $msg;
}
function getFlash($type) {
    if (isset($_SESSION['flash'][$type])) {
        $msg = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $msg;
    }
    return null;
}

// Variabel form
$edit_id = null;
$nama_kelas = '';
$jurusan_id = '';
$tingkatan_kelas_id = '';

// Pagination
$items_per_page = 15;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// Total kelas
$total_res = $conn->query("SELECT COUNT(*) as total FROM kelas");
$total_rows = $total_res->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $items_per_page);

// ======================================== HANDLE POST ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && $_POST['token'] === $token) {

    // HAPUS (dari SweetAlert)
    if (isset($_POST['hapus_id'])) {
        $id = (int)$_POST['hapus_id'];

        // PERBAIKAN: Cek di siswa_profiles.kelas_id
        $cek = $conn->query("SELECT 1 FROM siswa_profiles WHERE kelas_id = $id LIMIT 1")->num_rows;

        if ($cek > 0) {
            setFlash('error', 'Tidak dapat menghapus kelas yang masih memiliki siswa!');
        } else {
            $conn->query("DELETE FROM kelas WHERE id = $id");
            setFlash('success', 'Kelas berhasil dihapus!');
        }
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        $token = $_SESSION['form_token'];
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['page']) ? '?page=' . $page : ''));
        exit;
    }

    // TAMBAH / EDIT
    $nama = trim($_POST['nama_kelas']);
    $jurusan = (int)$_POST['jurusan_id'];
    $tingkatan = (int)$_POST['tingkatan_id'];

    if (empty($nama) || strlen($nama) < 3) {
        setFlash('error', 'Nama kelas minimal 3 karakter!');
    } elseif (!$jurusan || !$tingkatan) {
        setFlash('error', 'Pilih jurusan dan tingkatan kelas!');
    } else {
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            $id = (int)$_POST['edit_id'];

            $old = $conn->prepare("SELECT nama_kelas, jurusan_id, tingkatan_kelas_id FROM kelas WHERE id = ?");
            $old->bind_param("i", $id);
            $old->execute();
            $old_row = $old->get_result()->fetch_assoc();

            if ($nama === $old_row['nama_kelas'] && $jurusan == $old_row['jurusan_id'] && $tingkatan == $old_row['tingkatan_kelas_id']) {
                setFlash('info', 'Tidak ada perubahan yang disimpan.');
            } else {
                $cek = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ? AND id != ?");
                $cek->bind_param("siii", $nama, $jurusan, $tingkatan, $id);
                $cek->execute();
                if ($cek->get_result()->num_rows > 0) {
                    setFlash('error', 'Kelas sudah ada di jurusan dan tingkatan ini!');
                } else {
                    $upd = $conn->prepare("UPDATE kelas SET nama_kelas = ?, jurusan_id = ?, tingkatan_kelas_id = ? WHERE id = ?");
                    $upd->bind_param("siii", $nama, $jurusan, $tingkatan, $id);
                    $upd->execute();
                    setFlash('success', 'Kelas berhasil diperbarui!');
                }
            }
        } else {
            $cek = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ? AND jurusan_id = ? AND tingkatan_kelas_id = ?");
            $cek->bind_param("sii", $nama, $jurusan, $tingkatan);
            $cek->execute();
            if ($cek->get_result()->num_rows > 0) {
                setFlash('error', 'Kelas sudah ada di jurusan dan tingkatan ini!');
            } else {
                $ins = $conn->prepare("INSERT INTO kelas (nama_kelas, jurusan_id, tingkatan_kelas_id) VALUES (?, ?, ?)");
                $ins->bind_param("sii", $nama, $jurusan, $tingkatan);
                $ins->execute();
                setFlash('success', 'Kelas berhasil ditambahkan!');
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
    $edit_id = (int)$_GET['edit'];
    $res = $conn->prepare("SELECT nama_kelas, jurusan_id, tingkatan_kelas_id FROM kelas WHERE id = ?");
    $res->bind_param("i", $edit_id);
    $res->execute();
    $row = $res->get_result()->fetch_assoc();
    if ($row) {
        $nama_kelas = $row['nama_kelas'];
        $jurusan_id = $row['jurusan_id'];
        $tingkatan_kelas_id = $row['tingkatan_kelas_id'];
    } else {
        setFlash('error', 'Kelas tidak ditemukan!');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ======================================== AMBIL DATA UNTUK DROPDOWN & TABEL (dipisah per jurusan) ========================================
$jurusan_res = $conn->query("SELECT id, nama_jurusan FROM jurusan ORDER BY nama_jurusan ASC");
$tingkatan_res = $conn->query("SELECT id, nama_tingkatan FROM tingkatan_kelas ORDER BY nama_tingkatan ASC");

$all_kelas = $conn->query("
    SELECT k.id, k.nama_kelas, j.nama_jurusan, t.nama_tingkatan 
    FROM kelas k 
    LEFT JOIN jurusan j ON k.jurusan_id = j.id 
    LEFT JOIN tingkatan_kelas t ON k.tingkatan_kelas_id = t.id 
    ORDER BY j.nama_jurusan ASC, t.nama_tingkatan ASC, k.nama_kelas ASC
");

$kelas_by_jurusan = [];
while ($row = $all_kelas->fetch_assoc()) {
    $jurusan = $row['nama_jurusan'] ?: 'Tidak Ada Jurusan';
    if (!isset($kelas_by_jurusan[$jurusan])) {
        $kelas_by_jurusan[$jurusan] = [];
    }
    $kelas_by_jurusan[$jurusan][] = $row;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kelola Kelas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary-color: #1e3a8a; --primary-dark: #1e1b4b;
            --secondary-color: #3b82f6; --bg-light: #f0f5ff;
            --text-primary: #333; --text-secondary: #666;
            --shadow-sm: 0 2px 10px rgba(0,0,0,0.05);
        }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background-color: var(--bg-light); color: var(--text-primary); display: flex; min-height: 100vh; overflow-x: hidden; }
        
        /* SIDEBAR OVERLAY FIX */
        body.sidebar-open { overflow: hidden; }
        body.sidebar-open::before {
            content: ''; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh;
            background: rgba(0,0,0,0.5); z-index: 998; backdrop-filter: blur(5px);
        }
        
        .main-content { flex: 1; margin-left: 280px; padding: 30px; max-width: calc(100% - 280px); position: relative; z-index: 1; }
        
        .welcome-banner { 
            background: linear-gradient(135deg, var(--primary-dark), var(--secondary-color)); 
            color: white; padding: 30px; border-radius: 8px; margin-bottom: 30px; 
            box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 20px; 
        }
        .welcome-banner h2 { font-size: 1.5rem; margin: 0; }
        .welcome-banner p { margin: 0; opacity: 0.9; }
        .menu-toggle { display: none; font-size: 1.5rem; cursor: pointer; align-self: center; margin-right: auto; }

        .form-card, .jadwal-list { background: white; border-radius: 8px; padding: 25px; box-shadow: var(--shadow-sm); margin-bottom: 30px; }
        .form-row { display: flex; gap: 20px; margin-bottom: 15px; }
        .form-group { flex: 1; display: flex; flex-direction: column; gap: 8px; }
        label { font-weight: 500; font-size: 0.9rem; color: var(--text-secondary); }

        input[type="text"], select {
            width: 100%; padding: 12px 15px; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 0.95rem; height: 48px; background: #fff;
        }
        input:focus, select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(30,58,138,0.1); outline: none; }

        .btn { 
            background: var(--primary-color); color: white; border: none; padding: 12px 25px; border-radius: 6px; 
            cursor: pointer; font-weight: 500; display: inline-flex; align-items: center; gap: 8px; 
            transition: 0.3s; text-decoration: none !important;
        }
        .btn:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-cancel { background: #e2e8f0; color: #475569; text-decoration: none !important; }
        .btn-cancel:hover { background: #cbd5e1; }

        .table-wrapper { overflow-x: auto; border-radius: 8px; border: 1px solid #f1f5f9; margin-top: 15px; }
        table { width: 100%; border-collapse: collapse; min-width: 600px; }
        th { background: #f8fafc; padding: 15px; text-align: left; font-weight: 600; color: var(--text-secondary); border-bottom: 2px solid #e2e8f0; }
        td { padding: 15px; border-bottom: 1px solid #f1f5f9; }

        .action-icon {
            font-size: 1.1rem; cursor: pointer; padding: 8px; border-radius: 6px; transition: 0.2s;
            width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none !important;
        }
        .action-icon.edit { background: var(--secondary-color); color: #fff; }
        .action-icon.delete { background: #ef4444; color: #fff; }
        .action-icon:hover { opacity: 0.9; transform: scale(1.1); }

        .jurusan-title {
            font-size: 1.2rem; font-weight: 600; color: var(--primary-dark); margin: 25px 0 10px 0; padding-bottom: 8px; border-bottom: 2px solid var(--primary-color);
        }

        a, a:hover, a:visited, a:active { text-decoration: none !important; }

        /* SweetAlert Custom Fixes */
        .swal2-input { height: 48px !important; margin: 10px auto !important; }
        .swal-date-wrapper { position: relative; width: 80%; margin: 10px auto; }
        .swal-date-wrapper input { width: 100% !important; box-sizing: border-box !important; margin: 0 !important; }
        .swal-date-wrapper i { right: 15px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; max-width: 100%; }
            .menu-toggle { display: block; }
            .form-row { flex-direction: column; }
            .welcome-banner { 
                flex-direction: row; 
                align-items: center; 
                gap: 15px; 
                padding: 20px; 
            }
            .welcome-banner h2 { 
                font-size: 1.2rem;
                margin: 0; 
            }
            .welcome-banner p { 
                font-size: 0.9rem;
                margin: 0; 
                opacity: 0.9; 
            }
            .form-row { flex-direction: column; gap: 15px; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div>
                <h2>Kelola Kelas</h2>
                <p>Tambah, edit, atau hapus data kelas sekolah</p>
            </div>
        </div>

        <!-- FORM TAMBAH / EDIT -->
        <div class="form-card">
            <form method="POST" id="kelasForm">
                <input type="hidden" name="token" value="<?= $token ?>">
                <?php if ($edit_id): ?>
                    <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Tingkatan Kelas</label>
                        <select name="tingkatan_id" id="tingkatanSelect" required>
                            <option value="">Pilih Tingkatan</option>
                            <?php while ($row = $tingkatan_res->fetch_assoc()): ?>
                                <option value="<?= $row['id'] ?>" <?= $tingkatan_kelas_id == $row['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['nama_tingkatan']) ?>
                                </option>
                            <?php endwhile; $tingkatan_res->data_seek(0); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Jurusan</label>
                        <select name="jurusan_id" required>
                            <option value="">Pilih Jurusan</option>
                            <?php while ($row = $jurusan_res->fetch_assoc()): ?>
                                <option value="<?= $row['id'] ?>" <?= $jurusan_id == $row['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($row['nama_jurusan']) ?>
                                </option>
                            <?php endwhile; $jurusan_res->data_seek(0); ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nama Kelas</label>
                        <input type="text" name="nama_kelas" id="namaKelasInput" value="<?= htmlspecialchars($nama_kelas) ?>" placeholder="Contoh: RPL-1" required maxlength="50">
                    </div>
                </div>

                <div style="display:flex; gap:12px; margin-top:10px;">
                    <button type="button" id="submitBtn" class="btn">
                        <?= $edit_id ? 'Update Kelas' : 'Tambah Kelas' ?>
                    </button>
                    <?php if ($edit_id): ?>
                        <a href="kelola_kelas.php" class="btn btn-cancel">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- DAFTAR KELAS (Dipisah per Jurusan) -->
        <div class="jadwal-list">
            <h3>Daftar Kelas</h3>

            <?php if (empty($kelas_by_jurusan)): ?>
                <div style="text-align:center; padding:40px; color:#999;">
                    Belum ada data kelas
                </div>
            <?php else: ?>
                <?php foreach ($kelas_by_jurusan as $jurusan => $kelas): ?>
                    <div class="jurusan-title"><?= htmlspecialchars($jurusan) ?></div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tingkatan</th>
                                    <th>Nama Kelas</th>
                                    <th style="width:120px; text-align:center;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kelas as $i => $k): ?>
                                    <tr>
                                        <td><?= $i + 1 ?></td>
                                        <td><?= htmlspecialchars($k['nama_tingkatan']) ?></td>
                                        <td><strong><?= htmlspecialchars($k['nama_kelas']) ?></strong></td>
                                        <td style="text-align:center;">
                                            <a href="?edit=<?= $k['id'] ?>" class="action-icon edit" title="Edit">
                                                <i class="fas fa-pencil-alt"></i>
                                            </a>
                                            <span class="action-icon delete" onclick="hapusKelas(<?= $k['id'] ?>, '<?= htmlspecialchars(addslashes($k['nama_kelas']), ENT_QUOTES) ?>')" title="Hapus">
                                                <i class="fas fa-trash-alt"></i>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // ============================================
        // FIXED SIDEBAR MOBILE BEHAVIOR
        // ============================================
        const menuToggle = document.getElementById('menuToggle');
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
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                if (body.classList.contains('sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }

        // Close sidebar on outside click
        document.addEventListener('click', function(e) {
            if (body.classList.contains('sidebar-open') && 
                !sidebar?.contains(e.target) && 
                !menuToggle?.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && body.classList.contains('sidebar-open')) {
                closeSidebar();
            }
        });

        // Tambah / Update dengan Konfirmasi (Tampilkan Tingkatan + Nama Kelas)
        document.getElementById('submitBtn').addEventListener('click', function(e) {
            e.preventDefault();
            const tingkatanSelect = document.getElementById('tingkatanSelect');
            const namaInput = document.getElementById('namaKelasInput');
            const tingkatanText = tingkatanSelect.options[tingkatanSelect.selectedIndex]?.text || '';
            const nama = namaInput.value.trim();

            if (!tingkatanText) {
                Swal.fire('Gagal', 'Pilih tingkatan kelas!', 'error');
                return;
            }
            if (!nama || nama.length < 3) {
                Swal.fire('Gagal', 'Nama kelas minimal 3 karakter!', 'error');
                return;
            }

            Swal.fire({
                title: <?= $edit_id ? "'Update Kelas?'" : "'Tambah Kelas Baru?'" ?>,
                html: `<strong style="font-size:1.4rem;">${tingkatanText} ${nama}</strong><br><small>Akan <?= $edit_id ? 'diperbarui' : 'ditambahkan' ?></small>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, <?= $edit_id ? 'Update' : 'Tambah' ?>',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#1e3a8a'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('kelasForm').submit();
                }
            });
        });

        // Hapus Kelas
        function hapusKelas(id, nama) {
            Swal.fire({
                title: 'Hapus Kelas?',
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