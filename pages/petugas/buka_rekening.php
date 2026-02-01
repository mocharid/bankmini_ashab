<?php
// ================= PATH ADAPTIF =================
$current_file = __FILE__;
$current_dir = dirname($current_file);
$project_root = null;

// Strategy 1: Check if we're in 'pages/petugas' folder
if (basename($current_dir) === 'petugas') {
    $project_root = dirname(dirname($current_dir));
}
// Strategy 2: Check if includes/ exists in current dir
elseif (is_dir($current_dir . '/includes')) {
    $project_root = $current_dir;
}
// Strategy 3: Check if includes/ exists in parent
elseif (is_dir(dirname($current_dir) . '/includes')) {
    $project_root = dirname($current_dir);
}
// Strategy 4: Search upward for includes/ folder (max 5 levels)
else {
    $temp_dir = $current_dir;
    for ($i = 0; $i < 5; $i++) {
        $temp_dir = dirname($temp_dir);
        if (is_dir($temp_dir . '/includes')) {
            $project_root = $temp_dir;
            break;
        }
    }
}

// Fallback: Use current directory
if (!$project_root) {
    $project_root = $current_dir;
}

if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', rtrim($project_root, '/'));
}

if (!defined('INCLUDES_PATH')) {
    define('INCLUDES_PATH', PROJECT_ROOT . '/includes');
}

if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', PROJECT_ROOT . '/assets');
}

// ================= BASE URL =================
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $script = $_SERVER['SCRIPT_NAME'];
    $base_path = dirname(dirname($script));
    $base_path = preg_replace('#/pages(/[^/]+)?$#', '', $base_path);
    if ($base_path !== '/' && !empty($base_path)) {
        $base_path = '/' . ltrim($base_path, '/');
    }
    return $protocol . $host . $base_path;
}
if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim(getBaseUrl(), '/'));
}
if (!defined('ASSETS_URL')) {
    define('ASSETS_URL', BASE_URL . '/assets');
}

// ================= INCLUDE ASLI =================
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/db_connection.php';
require_once INCLUDES_PATH . '/session_validator.php';

// Start session if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict access to petugas only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'petugas') {
    header('Location: ' . PROJECT_ROOT . '/pages/login.php?error=' . urlencode('Silakan login sebagai petugas terlebih dahulu!'));
    exit();
}

// CSRF token
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['form_token'];

// Fetch jurusan data
$query_jurusan = "SELECT * FROM jurusan";
$result_jurusan = $conn->query($query_jurusan);
if (!$result_jurusan) {
    error_log("Error fetching jurusan: " . $conn->error);
    die("Terjadi kesalahan saat mengambil data jurusan.");
}

// Fetch tingkatan kelas data
$query_tingkatan = "SELECT * FROM tingkatan_kelas";
$result_tingkatan = $conn->query($query_tingkatan);
if (!$result_tingkatan) {
    error_log("Error fetching tingkatan kelas: " . $conn->error);
    die("Terjadi kesalahan saat mengambil data tingkatan kelas.");
}

// Handle confirmation page data
$confirmData = null;
if (isset($_GET['confirm']) && $_GET['confirm'] == '1' && isset($_GET['data'])) {
    $decoded = base64_decode($_GET['data']);
    if ($decoded) {
        $confirmData = json_decode($decoded, true);
    }
}

// Handle errors from URL for form display
$errors = [];
if (isset($_GET['error'])) {
    $errors['general'] = urldecode($_GET['error']);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Buka Rekening | KASDIG</title>
    <link rel="icon" type="image/png" href="<?= ASSETS_URL ?>/images/tab.png">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- SweetAlert2 CDN -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --gray-50: #f9fafb;
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
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body {
            background-color: var(--bg-light);
            color: var(--text-primary);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
        }

        /* Sidebar State */
        body.sidebar-open {
            overflow: hidden;
        }

        /* Mobile Overlay for Sidebar */
        body.sidebar-active::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            transition: opacity 0.3s ease;
            opacity: 1;
        }

        body:not(.sidebar-active):not(.sidebar-open)::before {
            opacity: 0;
            pointer-events: none;
        }

        /* Layout */
        .main-content {
            margin-left: 280px;
            padding: 2rem;
            flex: 1;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
            width: calc(100% - 280px);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1rem;
            }
        }

        /* Page Title */
        .page-title-section {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%);
            padding: 1.5rem 2rem;
            margin: -2rem -2rem 1.5rem -2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .page-title-content h1 {
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
        .card {
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

        /* Form Controls */
        .form-group {
            margin-bottom: 1.25rem;
            position: relative;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-secondary);
        }

        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="date"],
        select {
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

        input:focus,
        select:focus {
            border-color: var(--gray-600);
            box-shadow: 0 0 0 2px rgba(71, 85, 105, 0.1);
            outline: none;
        }

        input[type="date"] {
            padding-right: 40px;
            cursor: pointer;
        }

        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23334155' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .form-row.form-row-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .form-row .form-group.wide {
            grid-column: span 1;
        }

        @media (max-width: 768px) {

            .form-row,
            .form-row.form-row-3 {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }

        .error-message {
            color: #ef4444;
            font-size: 0.85rem;
            margin-top: 4px;
            display: none;
        }

        .error-message.show {
            display: block;
        }

        .pin-match-indicator {
            font-size: 0.8rem;
            margin-top: 4px;
            font-weight: 500;
        }

        .pin-match-indicator.match {
            color: #10b981;
        }

        .pin-match-indicator.mismatch {
            color: #ef4444;
        }

        .email-note {
            font-size: 0.8rem;
            color: var(--gray-500);
            font-style: italic;
            margin-top: 4px;
            display: block;
        }

        /* Buttons */
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
            width: fit-content;
            min-width: 150px;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--gray-800) 0%, var(--gray-700) 100%);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn.loading {
            opacity: 0.7;
            pointer-events: none;
        }



        /* Responsive */
        @media (max-width: 1024px) {
            .page-hamburger {
                display: block;
            }

            .page-title-section {
                padding: 1rem 1.5rem;
                margin: -1rem -1rem 1.5rem -1rem;
            }
        }
    </style>
</head>

<body>
    <?php include INCLUDES_PATH . '/sidebar_petugas.php'; ?>
    <div class="main-content" id="mainContent">
        <!-- Page Title Section -->
        <div class="page-title-section">
            <button class="page-hamburger" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <div class="page-title-content">
                <h1>Buka Rekening</h1>
                <p class="page-subtitle">Formulir pendaftaran nasabah baru</p>
            </div>
        </div>

        <?php if ($confirmData): ?>
            <!-- CONFIRMATION PAGE -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="card-header-text">
                        <h3>Konfirmasi Data Nasabah</h3>
                        <p>Periksa kembali data sebelum menyimpan</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="confirmation-data">
                        <div class="data-row">
                            <span class="data-label">Nama Lengkap</span>
                            <span class="data-value">
                                <?= htmlspecialchars($confirmData['nama'] ?? '-') ?>
                            </span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">No. Rekening</span>
                            <span class="data-value"
                                style="font-weight: 600; color: var(--gray-800); font-family: monospace; font-size: 1.1rem;">
                                <?= htmlspecialchars($confirmData['no_rekening'] ?? '-') ?>
                            </span>
                        </div>

                        <?php if (!empty($confirmData['email'])): ?>
                            <div class="data-row">
                                <span class="data-label">Email</span>
                                <span class="data-value">
                                    <?= htmlspecialchars($confirmData['email']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($confirmData['jenis_kelamin'])): ?>
                            <div class="data-row">
                                <span class="data-label">Jenis Kelamin</span>
                                <span class="data-value">
                                    <?= $confirmData['jenis_kelamin'] === 'L' ? 'Laki-laki' : 'Perempuan' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($confirmData['tanggal_lahir'])): ?>
                            <div class="data-row">
                                <span class="data-label">Tanggal Lahir</span>
                                <span class="data-value">
                                    <?= date('d M Y', strtotime($confirmData['tanggal_lahir'])) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($confirmData['alamat_lengkap'])): ?>
                            <div class="data-row">
                                <span class="data-label">Alamat</span>
                                <span class="data-value">
                                    <?= htmlspecialchars($confirmData['alamat_lengkap']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($confirmData['nis_nisn'])): ?>
                            <div class="data-row">
                                <span class="data-label">NIS/NISN</span>
                                <span class="data-value">
                                    <?= htmlspecialchars($confirmData['nis_nisn']) ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="data-row">
                            <span class="data-label">Jurusan</span>
                            <span class="data-value">
                                <?= htmlspecialchars($confirmData['jurusan_name'] ?? '-') ?>
                            </span>
                        </div>
                        <div class="data-row">
                            <span class="data-label">Kelas</span>
                            <span class="data-value">
                                <?= htmlspecialchars(($confirmData['tingkatan_name'] ?? '') . ' ' . ($confirmData['kelas_name'] ?? '')) ?>
                        </div>
                    </div>

                    <p style="margin-top: 1rem; font-size: 0.875rem; color: var(--gray-500); font-style: italic;">
                        <i class="fas fa-info-circle" style="margin-right: 0.5rem;"></i>
                        Username dan password akan dikirim ke email yang telah diinput.
                    </p>

                    <div class="confirmation-actions" style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <form action="proses_buka_rekening.php" method="POST" style="display: inline;" id="confirmForm">
                            <input type="hidden" name="token" value="<?= $token ?>">
                            <input type="hidden" name="confirmed" value="1">
                            <input type="hidden" name="nama" value="<?= htmlspecialchars($confirmData['nama'] ?? '') ?>">
                            <input type="hidden" name="username"
                                value="<?= htmlspecialchars($confirmData['username'] ?? '') ?>">
                            <input type="hidden" name="no_rekening"
                                value="<?= htmlspecialchars($confirmData['no_rekening'] ?? '') ?>">
                            <input type="hidden" name="email" value="<?= htmlspecialchars($confirmData['email'] ?? '') ?>">
                            <input type="hidden" name="tipe_rekening"
                                value="<?= htmlspecialchars($confirmData['tipe_rekening'] ?? '') ?>">
                            <input type="hidden" name="password"
                                value="<?= htmlspecialchars($confirmData['password'] ?? '') ?>">
                            <input type="hidden" name="alamat_lengkap"
                                value="<?= htmlspecialchars($confirmData['alamat_lengkap'] ?? '') ?>">
                            <input type="hidden" name="nis_nisn"
                                value="<?= htmlspecialchars($confirmData['nis_nisn'] ?? '') ?>">
                            <input type="hidden" name="jenis_kelamin"
                                value="<?= htmlspecialchars($confirmData['jenis_kelamin'] ?? '') ?>">
                            <input type="hidden" name="tanggal_lahir"
                                value="<?= htmlspecialchars($confirmData['tanggal_lahir'] ?? '') ?>">
                            <input type="hidden" name="jurusan_id"
                                value="<?= htmlspecialchars($confirmData['jurusan_id'] ?? '') ?>">
                            <input type="hidden" name="tingkatan_kelas_id"
                                value="<?= htmlspecialchars($confirmData['tingkatan_kelas_id'] ?? '') ?>">
                            <input type="hidden" name="kelas_id"
                                value="<?= htmlspecialchars($confirmData['kelas_id'] ?? '') ?>">
                            <input type="hidden" name="jurusan_name"
                                value="<?= htmlspecialchars($confirmData['jurusan_name'] ?? '') ?>">
                            <input type="hidden" name="tingkatan_name"
                                value="<?= htmlspecialchars($confirmData['tingkatan_name'] ?? '') ?>">
                            <input type="hidden" name="kelas_name"
                                value="<?= htmlspecialchars($confirmData['kelas_name'] ?? '') ?>">
                            <button type="submit" class="btn" id="confirm-btn">
                                <span class="btn-content"><i class="fas fa-check-circle"></i> Simpan Data</span>
                            </button>
                        </form>
                        <form action="proses_buka_rekening.php" method="POST" style="display: inline;" id="cancelForm">
                            <input type="hidden" name="token" value="<?= $token ?>">
                            <input type="hidden" name="cancel" value="1">
                            <button type="submit" class="btn">
                                <span class="btn-content"><i class="fas fa-times-circle"></i> Batal</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <style>
                .confirmation-data {
                    border: 1px solid var(--gray-200);
                    border-radius: var(--radius);
                    overflow: hidden;
                }

                .data-row {
                    display: flex;
                    padding: 0.75rem 1rem;
                    border-bottom: 1px solid var(--gray-100);
                }

                .data-row:last-child {
                    border-bottom: none;
                }

                .data-row:nth-child(even) {
                    background: var(--gray-50);
                }

                .data-label {
                    width: 150px;
                    flex-shrink: 0;
                    font-weight: 500;
                    color: var(--gray-600);
                }

                .data-value {
                    flex: 1;
                    color: var(--gray-800);
                }

                @media (max-width: 768px) {
                    .data-row {
                        flex-direction: column;
                        gap: 0.25rem;
                    }

                    .data-label {
                        width: 100%;
                        font-size: 0.85rem;
                    }

                    .confirmation-actions {
                        flex-direction: column;
                    }

                    .confirmation-actions .btn {
                        width: 100%;
                    }
                }
            </style>
        <?php else: ?>
            <!-- REGISTRATION FORM -->
            <div class="card">
                <div class="card-header">
                    <div class="card-header-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="card-header-text">
                        <h3>Form Pendaftaran</h3>
                        <p>Lengkapi data diri nasabah untuk membuka rekening</p>
                    </div>
                </div>
                <div class="card-body">
                    <form action="proses_buka_rekening.php" method="POST" id="nasabahForm">
                        <input type="hidden" name="token" value="<?php echo $token; ?>">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="nama">Nama Lengkap <span
                                        style="color: #ef4444;">*</span></label>
                                <input type="text" id="nama" name="nama" required
                                    value="<?php echo htmlspecialchars($_POST['nama'] ?? ($_GET['nama'] ?? '')); ?>"
                                    placeholder="Masukkan nama lengkap"
                                    aria-invalid="<?php echo isset($errors['nama']) ? 'true' : 'false'; ?>">
                                <span class="error-message <?php echo isset($errors['nama']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['nama'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="email">Email <span style="color: #ef4444;">*</span></label>
                                <input type="email" id="email" name="email" required
                                    value="<?php echo htmlspecialchars($_POST['email'] ?? ($_GET['email'] ?? '')); ?>"
                                    placeholder="Masukkan email valid"
                                    aria-invalid="<?php echo isset($errors['email']) ? 'true' : 'false'; ?>">
                                <span class="email-note">Username dan password akan dikirim ke email ini</span>
                                <span class="error-message <?php echo isset($errors['email']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['email'] ?? ''); ?>
                                </span>
                            </div>
                        </div>
                        <input type="hidden" name="tipe_rekening" value="digital">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label" for="jenis_kelamin">Jenis Kelamin</label>
                                <select id="jenis_kelamin" name="jenis_kelamin"
                                    aria-invalid="<?php echo isset($errors['jenis_kelamin']) ? 'true' : 'false'; ?>">
                                    <option value="">Pilih Jenis Kelamin</option>
                                    <option value="L" <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] === 'L') || (isset($_GET['jenis_kelamin']) && $_GET['jenis_kelamin'] === 'L') ? 'selected' : ''; ?>>Laki-laki</option>
                                    <option value="P" <?php echo (isset($_POST['jenis_kelamin']) && $_POST['jenis_kelamin'] === 'P') || (isset($_GET['jenis_kelamin']) && $_GET['jenis_kelamin'] === 'P') ? 'selected' : ''; ?>>Perempuan</option>
                                </select>
                                <span class="error-message <?php echo isset($errors['jenis_kelamin']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['jenis_kelamin'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tanggal_lahir">Tanggal Lahir</label>
                                <input type="date" id="tanggal_lahir" name="tanggal_lahir"
                                    value="<?php echo htmlspecialchars($_POST['tanggal_lahir'] ?? ($_GET['tanggal_lahir'] ?? '')); ?>"
                                    placeholder="Pilih tanggal lahir"
                                    aria-invalid="<?php echo isset($errors['tanggal_lahir']) ? 'true' : 'false'; ?>">
                                <span class="error-message <?php echo isset($errors['tanggal_lahir']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['tanggal_lahir'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="alamat_lengkap">Alamat Lengkap</label>
                                <input type="text" id="alamat_lengkap" name="alamat_lengkap"
                                    value="<?php echo htmlspecialchars($_POST['alamat_lengkap'] ?? ($_GET['alamat_lengkap'] ?? '')); ?>"
                                    placeholder="Masukkan alamat lengkap jika ada">
                                <span class="error-message <?php echo isset($errors['alamat_lengkap']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['alamat_lengkap'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="nis_nisn">NIS/NISN (Opsional)</label>
                                <input type="text" id="nis_nisn" name="nis_nisn"
                                    value="<?php echo htmlspecialchars($_POST['nis_nisn'] ?? ($_GET['nis_nisn'] ?? '')); ?>"
                                    placeholder="Masukkan NIS atau NISN jika ada">
                            </div>
                        </div>

                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label class="form-label" for="jurusan_id">Jurusan <span
                                        style="color: #ef4444;">*</span></label>
                                <select id="jurusan_id" name="jurusan_id" required onchange="updateKelas()"
                                    aria-invalid="<?php echo isset($errors['jurusan_id']) ? 'true' : 'false'; ?>">
                                    <option value="">Pilih Jurusan</option>
                                    <?php
                                    if ($result_jurusan && $result_jurusan->num_rows > 0) {
                                        $result_jurusan->data_seek(0);
                                        while ($row = $result_jurusan->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['jurusan_id']) && $_POST['jurusan_id'] == $row['id']) || (isset($_GET['jurusan_id']) && $_GET['jurusan_id'] == $row['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['nama_jurusan']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php } else { ?>
                                        <option value="">Tidak ada jurusan tersedia</option>
                                    <?php } ?>
                                </select>
                                <span class="error-message <?php echo isset($errors['jurusan_id']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['jurusan_id'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="tingkatan_kelas_id">Tingkatan <span
                                        style="color: #ef4444;">*</span></label>
                                <select id="tingkatan_kelas_id" name="tingkatan_kelas_id" required onchange="updateKelas()"
                                    aria-invalid="<?php echo isset($errors['tingkatan_kelas_id']) ? 'true' : 'false'; ?>">
                                    <option value="">Pilih Tingkatan</option>
                                    <?php
                                    if ($result_tingkatan && $result_tingkatan->num_rows > 0) {
                                        $result_tingkatan->data_seek(0);
                                        while ($row = $result_tingkatan->fetch_assoc()):
                                            ?>
                                            <option value="<?php echo $row['id']; ?>" <?php echo (isset($_POST['tingkatan_kelas_id']) && $_POST['tingkatan_kelas_id'] == $row['id']) || (isset($_GET['tingkatan_kelas_id']) && $_GET['tingkatan_kelas_id'] == $row['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($row['nama_tingkatan']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    <?php } else { ?>
                                        <option value="">Tidak ada tingkatan tersedia</option>
                                    <?php } ?>
                                </select>
                                <span
                                    class="error-message <?php echo isset($errors['tingkatan_kelas_id']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['tingkatan_kelas_id'] ?? ''); ?>
                                </span>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="kelas_id">Kelas <span
                                        style="color: #ef4444;">*</span></label>
                                <select id="kelas_id" name="kelas_id" required
                                    aria-invalid="<?php echo isset($errors['kelas_id']) ? 'true' : 'false'; ?>">
                                    <option value="">Pilih Kelas</option>
                                </select>
                                <span id="kelas_id-error"
                                    class="error-message <?php echo isset($errors['kelas_id']) ? 'show' : ''; ?>">
                                    <?php echo htmlspecialchars($errors['kelas_id'] ?? ''); ?>
                                </span>
                            </div>
                        </div>

                        <button type="submit" id="submit-btn" class="btn">
                            <span class="btn-content"><i class="fas fa-plus-circle"></i> Tambah Nasabah</span>
                        </button>

                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        // Function to convert to Title Case
        function toTitleCase(str) {
            return str.replace(/\b\w/g, function (char) {
                return char.toUpperCase();
            }).replace(/\B\w/g, function (char) {
                return char.toLowerCase();
            });
        }

        function updateKelas() {
            const jurusanId = document.getElementById('jurusan_id').value;
            const tingkatanId = document.getElementById('tingkatan_kelas_id').value;
            const kelasSelect = document.getElementById('kelas_id');
            const kelasError = document.getElementById('kelas_id-error');

            if (!jurusanId || !tingkatanId) {
                kelasSelect.innerHTML = '<option value="">Pilih Kelas</option>';
                kelasError.textContent = '';
                kelasError.classList.remove('show');
                return;
            }

            $.ajax({
                url: '<?php echo BASE_URL; ?>/pages/petugas/get_kelas_by_jurusan_tingkatan.php',
                type: 'GET',
                data: { jurusan_id: jurusanId, tingkatan_kelas_id: tingkatanId },
                dataType: 'json',
                success: function (response) {
                    let options = '<option value="">Pilih Kelas</option>';
                    if (response.success && response.data.length > 0) {
                        response.data.forEach(function (kelas) {
                            options += `<option value="${kelas.id}">${kelas.nama_kelas}</option>`;
                        });
                        kelasSelect.innerHTML = options;

                        // Retain selected value if present (from PHP POST/GET)
                        const selectedKelas = "<?php echo $_POST['kelas_id'] ?? ($_GET['kelas_id'] ?? ''); ?>";
                        if (selectedKelas) {
                            kelasSelect.value = selectedKelas;
                        }

                    } else {
                        kelasSelect.innerHTML = '<option value="">Tidak ada kelas tersedia</option>';
                    }
                },
                error: function () {
                    kelasSelect.innerHTML = '<option value="">Error memuat kelas</option>';
                }
            });
        }

        $(document).ready(function () {
            // Show success message
            <?php if (isset($_GET['success'])): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Nasabah baru berhasil ditambahkan.'
                });
            <?php endif; ?>

            // Show error message
            <?php if (isset($_GET['error'])): ?>
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal!',
                    text: '<?= addslashes(urldecode($_GET['error'])) ?>'
                });
            <?php endif; ?>

            // Sidebar Toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.getElementById('sidebar');

            if (menuToggle && sidebar) {
                menuToggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    sidebar.classList.toggle('active');
                    document.body.classList.toggle('sidebar-active');
                });
            }

            // Close sidebar when clicking outside
            document.addEventListener('click', function (e) {
                if (sidebar && sidebar.classList.contains('active') && !sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Nama Title Case
            $('#nama').on('input', function () {
                var start = this.selectionStart;
                var end = this.selectionEnd;
                this.value = toTitleCase(this.value);
                this.setSelectionRange(start, end);
            });


            // Trigger kelas update if values exist
            if ($('#jurusan_id').val() && $('#tingkatan_kelas_id').val()) {
                updateKelas();
            }

            // Submit Button Loading State with 2-3 second spinner
            $('#nasabahForm').on('submit', function (e) {
                e.preventDefault();
                const form = this;

                if (form.checkValidity()) {
                    // Show loading spinner on button
                    const submitBtn = $('#submit-btn');
                    submitBtn.addClass('loading');
                    submitBtn.find('.btn-content').html('<i class="fas fa-spinner fa-spin"></i> Memproses...');

                    // Wait 2-3 seconds (using 2.5 seconds) then submit
                    setTimeout(function () {
                        form.submit();
                    }, 2500);
                } else {
                    form.reportValidity();
                }
            });

            // Confirm Form Loading State with 2-3 second spinner
            $('#confirmForm').on('submit', function (e) {
                e.preventDefault();
                const form = this;

                // Show loading spinner on button
                const confirmBtn = $('#confirm-btn');
                confirmBtn.addClass('loading');
                confirmBtn.find('.btn-content').html('<i class="fas fa-spinner fa-spin"></i> Menyimpan...');

                // Wait 2-3 seconds (using 2.5 seconds) then submit
                setTimeout(function () {
                    form.submit();
                }, 2500);
            });
        });
    </script>
</body>

</html>