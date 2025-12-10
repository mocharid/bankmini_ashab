<?php
/**
 * Kontrol Akses Petugas - FINAL & 100% RAPIH (Style Konsisten)
 * File: pages/admin/kontrol_akses_petugas.php
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

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin'])) {
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

// Buat tabel system_settings jika belum ada
$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Cek status blokir petugas
$is_blocked = 0;
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'petugas_blocked' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $is_blocked = (int)$res->fetch_assoc()['setting_value'];
}

// Jadwal petugas hari ini
$today = date('Y-m-d');
$jadwal_query = "SELECT ps.petugas1_id, ps.petugas2_id, u1.nama AS nama1, u2.nama AS nama2
                 FROM petugas_shift ps
                 LEFT JOIN users u1 ON ps.petugas1_id = u1.id
                 LEFT JOIN users u2 ON ps.petugas2_id = u2.id
                 WHERE ps.tanggal = ? LIMIT 1";
$stmt = $conn->prepare($jadwal_query);
$stmt->bind_param("s", $today);
$stmt->execute();
$jadwal_res = $stmt->get_result();
$jadwal_today = $jadwal_res->num_rows > 0 ? $jadwal_res->fetch_assoc() : null;
$stmt->close();

// Handle toggle blokir
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && $_POST['token'] === $token) {
    $new_status = $is_blocked ? 0 : 1;

    $check = $conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'petugas_blocked' LIMIT 1");
    if ($check->num_rows > 0) {
        $upd = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'petugas_blocked'");
        $upd->bind_param("i", $new_status);
        $upd->execute();
    } else {
        $ins = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('petugas_blocked', ?)");
        $ins->bind_param("i", $new_status);
        $ins->execute();
    }

    setFlash('success', $new_status ? 'Dashboard petugas berhasil diblokir!' : 'Dashboard petugas berhasil diaktifkan!');
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
    $token = $_SESSION['form_token'];

    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <title>Kontrol Akses Petugas | MY Schobank</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        .form-card, .jadwal-list { background: white; border-radius: 8px; padding: 30px; box-shadow: var(--shadow-sm); margin-bottom: 30px; }
        .card-title { font-size: 1.3rem; font-weight: 600; color: var(--primary-dark); margin-bottom: 25px; display: flex; align-items: center; gap: 12px; }

        .status-container {
            text-align: center; padding: 40px 20px; background: #f8fafc; border-radius: 12px; border: 2px dashed #e2e8f0;
        }
        .status-badge {
            display: inline-block; padding: 12px 40px; border-radius: 50px; font-size: 1.3rem; font-weight: 600;
            background: <?= $is_blocked ? '#fee2e2' : '#d1fae5' ?>; color: <?= $is_blocked ? '#dc2626' : '#059669' ?>;
            margin-bottom: 20px;
        }
        .status-desc {
            font-size: 1rem; color: var(--text-secondary); line-height: 1.7; max-width: 700px; margin: 0 auto 30px;
        }

        .toggle-btn {
            background: <?= $is_blocked ? '#ef4444' : '#10b981' ?>; color: white; border: none; padding: 16px 50px;
            border-radius: 50px; font-size: 1.1rem; font-weight: 600; cursor: pointer; transition: 0.3s;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .toggle-btn:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }

        .jadwal-card {
            background: white; border-radius: 12px; padding: 25px; box-shadow: var(--shadow-sm); border: 1px solid #f1f5f9;
        }
        .jadwal-title {
            font-size: 1.3rem; font-weight: 600; color: var(--primary-dark); margin-bottom: 20px; text-align: center;
        }
        .jadwal-info {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;
        }
        .jadwal-item {
            background: #f8fafc; padding: 20px; border-radius: 10px; text-align: center;
        }
        .jadwal-item strong { color: var(--primary-color); font-size: 1.1rem; }

        .no-jadwal {
            text-align: center; padding: 50px 20px; color: #999;
        }
        .no-jadwal i { font-size: 4rem; color: #cbd5e1; margin-bottom: 20px; }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; max-width: 100%; }
            .menu-toggle { display: block; }
            .welcome-banner { flex-direction: row; gap: 15px; padding: 20px; }
            .form-card, .jadwal-list { padding: 20px; }
            .jadwal-info { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include INCLUDES_PATH . '/sidebar_admin.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="welcome-banner">
            <span class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></span>
            <div>
                <h2><i class="fas fa-user-shield"></i> Kontrol Akses Petugas</h2>
                <p>Kontrol akses dashboard petugas bank mini</p>
            </div>
        </div>

        <!-- STATUS BLOKIR -->
        <div class="form-card">
            <div class="card-title">
                <i class="fas fa-toggle-on"></i> Status Dashboard Petugas
            </div>
            <div class="status-container">
                <div class="status-badge">
                    <?= $is_blocked ? 'DIBLOKIR' : 'AKTIF' ?>
                </div>
                <p class="status-desc">
                    <?= $is_blocked 
                        ? 'Dashboard petugas saat ini <strong>diblokir</strong>. Semua aktivitas login dan transaksi petugas dinonaktifkan.'
                        : 'Dashboard petugas <strong>aktif</strong>. Petugas dapat melakukan transaksi dan absensi sesuai jadwal.'
                    ?>
                </p>

                <form method="POST" style="display:inline;">
                    <input type="hidden" name="token" value="<?= $token ?>">
                    <button type="submit" class="toggle-btn">
                        <i class="fas <?= $is_blocked ? 'fa-unlock' : 'fa-lock' ?>"></i>
                        <?= $is_blocked ? 'Aktifkan Dashboard' : 'Blokir Dashboard' ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- JADWAL HARI INI -->
        <div class="jadwal-list">
            <div class="card-title">
                <i class="fas fa-calendar-check"></i> Jadwal Petugas Hari Ini
            </div>

            <?php if ($jadwal_today): ?>
                <div class="jadwal-card">
                    <div class="jadwal-title"><?= date('l, d F Y') ?></div>
                    <div class="jadwal-info">
                        <div class="jadwal-item">
                            <p>Petugas 1 (Kelas 10)</p>
                            <strong><?= htmlspecialchars($jadwal_today['nama1'] ?? 'Tidak ada') ?></strong>
                        </div>
                        <div class="jadwal-item">
                            <p>Petugas 2 (Kelas 11)</p>
                            <strong><?= htmlspecialchars($jadwal_today['nama2'] ?? 'Tidak ada') ?></strong>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="no-jadwal">
                    <i class="fas fa-calendar-times"></i>
                    <p><strong>Tidak ada jadwal petugas untuk hari ini</strong></p>
                    <p style="margin-top:10px; font-size:0.95rem;">Silakan tambahkan jadwal petugas terlebih dahulu.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Sidebar
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const body = document.body;

        if (menuToggle) {
            menuToggle.addEventListener('click', e => {
                e.stopPropagation();
                body.classList.toggle('sidebar-open');
                sidebar?.classList.toggle('active');
            });
        }
        document.addEventListener('click', e => {
            if (body.classList.contains('sidebar-open') && !sidebar?.contains(e.target) && !menuToggle?.contains(e.target)) {
                body.classList.remove('sidebar-open');
                sidebar?.classList.remove('active');
            }
        });

        // Flash Messages
        <?php if ($msg = getFlash('success')): ?>
            Swal.fire('Berhasil!', '<?= addslashes($msg) ?>', 'success');
        <?php endif; ?>
        <?php if ($msg = getFlash('error')): ?>
            Swal.fire('Gagal', '<?= addslashes($msg) ?>', 'error');
        <?php endif; ?>
    </script>
</body>
</html>